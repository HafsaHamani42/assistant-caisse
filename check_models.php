<?php
require_once 'config.php';

echo "<h1>🤖 Modèles Groq Disponibles (03/11/2025)</h1>";

$ch = curl_init('https://api.groq.com/openai/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . GROQ_API_KEY,
        'Content-Type: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    
    echo "<h2>✅ Modèles actifs :</h2>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #667eea; color: white;'>";
    echo "<th>ID du Modèle</th><th>Propriétaire</th><th>Créé le</th><th>Action</th>";
    echo "</tr>";
    
    $activeModels = [];
    foreach ($data['data'] as $model) {
        if (isset($model['active']) && $model['active']) {
            $activeModels[] = $model['id'];
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($model['id']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($model['owned_by'] ?? 'N/A') . "</td>";
            echo "<td>" . date('Y-m-d', $model['created'] ?? 0) . "</td>";
            echo "<td><button onclick=\"copyModel('" . htmlspecialchars($model['id']) . "')\">📋 Copier</button></td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    
    echo "<br><h2>💡 Modèles recommandés pour votre chatbot :</h2>";
    echo "<ul>";
    
    // Suggestions basées sur les patterns communs
    $recommendations = [
        'llama-3.3-70b-versatile',
        'llama-3.2-90b-text-preview',
        'llama3-70b-8192',
        'mixtral-8x7b-32768',
        'gemma2-9b-it'
    ];
    
    foreach ($recommendations as $rec) {
        if (in_array($rec, $activeModels)) {
            echo "<li>✅ <strong>$rec</strong> (Disponible)</li>";
        }
    }
    echo "</ul>";
    
    echo "<br><h2>🔧 Pour mettre à jour votre .env :</h2>";
    echo "<p>Copiez un des modèles ci-dessus et modifiez cette ligne dans votre <code>.env</code> :</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo "GROQ_MODEL=<strong style='color: #667eea;'>[ID_DU_MODELE]</strong>";
    echo "</pre>";
    
} else {
    echo "<p style='color: red;'>❌ Erreur lors de la récupération des modèles (Code HTTP: $httpCode)</p>";
    echo "<pre style='background: #fee; padding: 10px;'>$response</pre>";
}
?>

<script>
function copyModel(modelId) {
    navigator.clipboard.writeText(modelId).then(() => {
        alert('✅ Modèle copié : ' + modelId);
    });
}
</script>

<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding: 20px;
        background: #f8f9fa;
    }
    table {
        background: white;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    button {
        background: #667eea;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 5px;
        cursor: pointer;
    }
    button:hover {
        background: #5568d3;
    }
</style>