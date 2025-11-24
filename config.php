<?php
/**
 * config.php - Configuration sécurisée avec .env
 */

// Chargement du fichier .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Ignorer les commentaires et lignes invalides
        if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Retirer les guillemets si présents
        $value = trim($value, '"\'');
        
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Définition des constantes avec valeurs par défaut
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'projetmed');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

// Timezone
date_default_timezone_set(getenv('TIMEZONE') ?: 'Africa/Casablanca');

/**
 * Connexion PDO sécurisée
 */
function getDatabaseConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Connexion DB échouée : " . $e->getMessage());
    }
}

/**
 * Appel Groq API avec gestion d'erreurs améliorée
 */
function callGroqAPI($messages, $temp = 0.3) {
    $data = [
        'model' => GROQ_MODEL,
        'messages' => $messages,
        'temperature' => $temp,
        'max_tokens' => 2000
    ];

    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    // Gestion des erreurs cURL
    if ($curlErrno) {
        throw new Exception("Erreur de connexion (#$curlErrno): $curlError");
    }

    // Gestion du code HTTP 0
    if ($httpCode === 0) {
        throw new Exception("Impossible de contacter l'API Groq. Vérifiez votre connexion Internet.");
    }

    // Gestion des erreurs HTTP
    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $message = $err['error']['message'] ?? 'Erreur inconnue';
        throw new Exception("Erreur API Groq (HTTP $httpCode): $message");
    }

    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? '';
}

/**
 * Vérification de configuration
 */
function checkConfiguration() {
    $errors = [];
    
    // Vérifier la base de données
    if (empty(DB_NAME)) {
        $errors[] = "⚠️ Veuillez configurer DB_NAME dans .env";
    }
    
    // Vérifier la clé Groq
    if (empty(GROQ_API_KEY)) {
        $errors[] = "⚠️ Clé GROQ_API_KEY manquante dans .env";
    } elseif (strpos(GROQ_API_KEY, 'gsk_') !== 0) {
        $errors[] = "⚠️ Clé Groq invalide (doit commencer par 'gsk_')";
    }
    
    // Tester la connexion DB
    try {
        getDatabaseConnection();
    } catch (Exception $e) {
        $errors[] = "❌ Erreur de connexion DB : " . $e->getMessage();
    }
    
    return $errors;
}