<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Obtener los últimos 5 registros
$stmt = $pdo->query("
    SELECT * FROM mqtt_data 
    ORDER BY timestamp DESC 
    LIMIT 5
");

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $data,
    'timestamp' => date('Y-m-d H:i:s')
]);