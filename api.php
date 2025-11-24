<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

// MODE DEBUG : Activez pour voir les détails
define('DEBUG_MODE', true);

// Vérification config
$configErrors = checkConfiguration();
if (!empty($configErrors)) {
    echo json_encode(['success' => false, 'error' => implode("\n", $configErrors)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'error' => 'Message vide']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $analysis = analyzeQuestionWithGroq($userMessage, $pdo);

    // DEBUG : Log de l'analyse
    if (DEBUG_MODE) {
        debugLog("=== ANALYSE DE LA QUESTION ===");
        debugLog("Question: " . $userMessage);
        debugLog("Needs query: " . ($analysis['needs_query'] ? 'OUI' : 'NON'));
        if ($analysis['needs_query']) {
            debugLog("SQL généré: " . $analysis['sql_query']);
        } else {
            debugLog("Réponse directe: " . $analysis['response']);
        }
    }

    if ($analysis['needs_query']) {
        if (!isSafeQuery($analysis['sql_query'])) {
            throw new Exception("Requête non autorisée pour des raisons de sécurité.");
        }
        
        $results = executeQuery($pdo, $analysis['sql_query']);
        
        // DEBUG : Log des résultats
        if (DEBUG_MODE) {
            debugLog("Nombre de résultats: " . count($results));
            if (!empty($results)) {
                debugLog("Premier résultat: " . json_encode($results[0]));
            }
        }
        
        if (empty($results)) {
            // Essayer de diagnostiquer pourquoi il n'y a pas de résultats
            $diagnostic = diagnoseProblem($pdo, $analysis['sql_query']);
            $response = "Aucun résultat trouvé.<br><br>";
            $response .= "<small style='color: #666;'>🔍 Diagnostic : " . $diagnostic . "</small>";
        } else {
            $response = formatResponseWithGroq($userMessage, $results, $analysis);
        }
    } else {
        $response = $analysis['response'];
    }

    echo json_encode(['success' => true, 'response' => $response]);

} catch (Exception $e) {
    if (DEBUG_MODE) {
        debugLog("ERREUR: " . $e->getMessage());
        debugLog("Trace: " . $e->getTraceAsString());
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Fonction de debug
 */
function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/debug.log', "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Diagnostique pourquoi une requête retourne vide
 */
function diagnoseProblem($pdo, $sql) {
    try {
        // Extraire la table principale
        if (preg_match('/FROM\s+(\w+)/i', $sql, $matches)) {
            $table = $matches[1];
            
            // Compter les lignes dans la table
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM $table");
            $count = $stmt->fetch()['total'];
            
            if ($count == 0) {
                return "La table '$table' est vide (0 lignes)";
            }
            
            // Vérifier si c'est un problème de filtre WHERE
            if (stripos($sql, 'WHERE') !== false) {
                $simpleQuery = preg_replace('/WHERE.*/i', '', $sql) . " LIMIT 10";
                $stmt = $pdo->query($simpleQuery);
                $results = $stmt->fetchAll();
                if (!empty($results)) {
                    return "La table contient $count lignes, mais les filtres WHERE ne correspondent à aucune donnée";
                }
            }
            
            return "La table '$table' contient $count lignes, mais la requête ne retourne rien";
        }
        
        return "Impossible d'analyser la requête";
        
    } catch (Exception $e) {
        return "Erreur diagnostic: " . $e->getMessage();
    }
}

/**
 * Vérifie si la requête SQL est sûre
 */
function isSafeQuery($sql) {
    $sql = trim($sql);
    $lower = strtolower($sql);

    // 1. Doit commencer par SELECT
    if (!preg_match('/^\s*select\b/i', $sql)) return false;

    // 2. Mots interdits
    $forbidden = ['insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate', 'union.*select', 'into\s+outfile', 'load\s+data'];
    foreach ($forbidden as $word) {
        if (preg_match('/\b' . $word . '\b/i', $lower)) return false;
    }

    // 3. Tables autorisées
    $allowedTables = [
        'cash_up', 'customers', 'customer_packages', 'customer_points', 'devis_numbers', 'expenses',
        'expense_categories', 'fcm_tokens', 'inventories', 'invoices', 'items',
        'item_categories', 'item_kits', 'item_kit_items', 'item_quantities', 'jobs',
        'legal_entities', 'migrations', 'mobile_versions', 'model_has_permissions', 'model_has_roles',
        'notifications', 'payment_type', 'people', 'permissions', 'personal_access_tokens', 'receipts',
        'receivings', 'receivings_items', 'refunds', 'returned_items', 'roles', 'role_has_permissions',
        'sales', 'sales_items', 'sales_payments', 'sales_returns', 'sessions', 'stock_location', 'supplier',
        'transfers', 'users'
    ];

    preg_match_all('/\b(from|join)\s+([a-z_0-9]+)/i', $lower, $matches);
    $tables = $matches[2] ?? [];

    foreach ($tables as $table) {
        if (!in_array($table, $allowedTables)) {
            return false;
        }
    }

    return true;
}

/**
 * Analyse avec Groq - VERSION CORRIGÉE
 */
function analyzeQuestionWithGroq($question, $pdo) {
    // Récupérer quelques exemples de données réelles pour aider Groq
    $itemCount = $pdo->query("SELECT COUNT(*) as c FROM items")->fetch()['c'];
    $salesCount = $pdo->query("SELECT COUNT(*) as c FROM sales")->fetch()['c'];
    $customerCount = $pdo->query("SELECT COUNT(*) as c FROM customers")->fetch()['c'];
    
    $systemPrompt = "Tu es un expert SQL pour un système de caisse.

STATISTIQUES DE LA BASE :
- Items : $itemCount produits
- Sales : $salesCount ventes
- Customers : $customerCount clients

TABLES PRINCIPALES :
- items: id, name, unit_price, stock_type, deleted (0=actif, 1=supprimé), category_id
- sales: id, sale_time, customer_id, location_id, user_id
- sales_items: sale_id, item_id, quantity_purchased, item_unit_price
- customers: id, person_id, deleted
- people: first_name, last_name, phone_number, email

RÈGLES CRITIQUES :
1. Réponds UNIQUEMENT avec du JSON pur
2. Format : {\"needs_query\": true, \"sql_query\": \"SELECT...\", \"explanation\": \"...\"}
3. Toujours ajouter WHERE deleted = 0 pour items et customers
4. LIMIT 50 obligatoire sur toutes les requêtes
5. Pour 'aujourd'hui' : WHERE DATE(sale_time) = CURDATE()
6. Pour compter : SELECT COUNT(*) as total
7. Pour produits les plus vendus : JOIN sales_items, GROUP BY, ORDER BY SUM() DESC

EXEMPLES CONCRETS :
Question: \"Liste des produits\"
{\"needs_query\":true,\"sql_query\":\"SELECT id, name, unit_price FROM items WHERE deleted = 0 LIMIT 50\",\"explanation\":\"Liste des produits actifs\"}

Question: \"Ventes aujourd'hui\"
{\"needs_query\":true,\"sql_query\":\"SELECT s.id, s.sale_time, SUM(si.quantity_purchased * si.item_unit_price) as total FROM sales s JOIN sales_items si ON s.id = si.sale_id WHERE DATE(s.sale_time) = CURDATE() GROUP BY s.id LIMIT 50\",\"explanation\":\"Ventes du jour avec total\"}

Question: \"Produit le plus acheté\"
{\"needs_query\":true,\"sql_query\":\"SELECT i.name, SUM(si.quantity_purchased) as total_vendu FROM sales_items si JOIN items i ON si.item_id = i.id GROUP BY si.item_id, i.name ORDER BY total_vendu DESC LIMIT 1\",\"explanation\":\"Produit avec le plus grand volume de ventes\"}

Question: \"Bonjour\"
{\"needs_query\":false,\"response\":\"Bonjour ! Je peux vous aider avec vos données de ventes, produits et clients.\"}";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $question]
    ];

    $response = callGroqAPI($messages, 0.1);

    // Nettoyer la réponse
    $response = trim($response);
    $response = preg_replace('/^```json\s*/i', '', $response);
    $response = preg_replace('/\s*```$/i', '', $response);
    
    if (($firstBrace = strpos($response, '{')) !== false) {
        $response = substr($response, $firstBrace);
    }
    
    if (($lastBrace = strrpos($response, '}')) !== false) {
        $response = substr($response, 0, $lastBrace + 1);
    }

    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erreur parsing JSON: " . json_last_error_msg());
    }

    if (!isset($result['needs_query'])) {
        throw new Exception("'needs_query' manquant dans la réponse");
    }

    return $result;
}

/**
 * Formatage final
 */
function formatResponseWithGroq($question, $results, $analysis) {
    if (empty($results)) {
        return "Aucun résultat trouvé.";
    }

    $limited = array_slice($results, 0, 20);
    $total = count($results);

    $systemPrompt = "Présente les données en HTML clair :
- Tableaux avec <table>, <th>, <td>
- Montants : X.XX DH
- Dates : JJ/MM/AAAA
- Émojis pertinents
- Concis et professionnel
- PAS de markdown, SEULEMENT du HTML";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Question: $question\n\nDonnées:\n" . json_encode($limited, JSON_PRETTY_PRINT)]
    ];

    $response = callGroqAPI($messages, 0.2);

    if ($total > 20) {
        $response .= "\n\n<small>Affichage limité : 20/$total résultats</small>";
    }

    return $response;
}

/**
 * Exécution sécurisée
 */
function executeQuery($pdo, $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}