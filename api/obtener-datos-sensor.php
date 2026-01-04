<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$dispositivo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$horas = isset($_GET['horas']) ? intval($_GET['horas']) : 24;

if($dispositivo_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

// Obtener código del dispositivo
$stmt = $pdo->prepare("SELECT codigo FROM dispositivos WHERE id = ?");
$stmt->execute([$dispositivo_id]);
$dispositivo = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$dispositivo) {
    echo json_encode(['success' => false, 'message' => 'Dispositivo no encontrado']);
    exit;
}

$codigo = $dispositivo['codigo'];

// Obtener datos del sensor
$stmt = $pdo->prepare("
    SELECT 
        sensor,
        valor,
        DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') as timestamp
    FROM mqtt_data 
    WHERE dispositivo_id = ? 
    AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ORDER BY timestamp ASC
");
$stmt->execute([$codigo, $horas]);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'dispositivo_id' => $dispositivo_id,
    'codigo' => $codigo,
    'horas' => $horas,
    'total_datos' => count($datos),
    'datos' => $datos
]);
?>