<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Obtener datos de las últimas 24 horas
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(timestamp, '%H:%i') as hora,
        AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
        AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
    FROM mqtt_data 
    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(timestamp), MINUTE(timestamp)
    ORDER BY timestamp
");

$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$response = [
    'labels' => [],
    'temperatura' => [],
    'humedad' => []
];

foreach ($data as $row) {
    $response['labels'][] = $row['hora'];
    $response['temperatura'][] = $row['temperatura'] ?? 0;
    $response['humedad'][] = $row['humedad'] ?? 0;
}

echo json_encode($response);