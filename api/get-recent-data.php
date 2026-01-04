<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$minutes = isset($_GET['minutes']) ? (int)$_GET['minutes'] : 2;

// Obtener todos los dispositivos únicos
$stmt = $pdo->query("SELECT DISTINCT dispositivo_id FROM mqtt_data ORDER BY dispositivo_id");
$dispositivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$response = ['dispositivos' => []];

foreach ($dispositivos as $dispositivo) {
    $stmt = $pdo->prepare("
        SELECT 
            sensor,
            valor,
            DATE_FORMAT(timestamp, '%H:%i:%s') as hora
        FROM mqtt_data 
        WHERE dispositivo_id = ? 
            AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY timestamp DESC
        LIMIT 10
    ");
    
    $stmt->execute([$dispositivo, $minutes]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['dispositivos'][$dispositivo] = $data;
}

echo json_encode($response);
?>