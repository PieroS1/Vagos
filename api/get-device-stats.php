<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if (!isset($_GET['device'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Device ID required']);
    exit();
}

$device_id = $_GET['device'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_datos,
            SUM(CASE WHEN sensor = 'temperatura' THEN 1 ELSE 0 END) as datos_temp,
            SUM(CASE WHEN sensor = 'humedad' THEN 1 ELSE 0 END) as datos_hum,
            MIN(timestamp) as primer_dato,
            MAX(timestamp) as ultimo_dato,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as promedio_temp,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as promedio_hum,
            MIN(CASE WHEN sensor = 'temperatura' THEN valor END) as min_temp,
            MAX(CASE WHEN sensor = 'temperatura' THEN valor END) as max_temp,
            MIN(CASE WHEN sensor = 'humedad' THEN valor END) as min_hum,
            MAX(CASE WHEN sensor = 'humedad' THEN valor END) as max_hum
        FROM mqtt_data 
        WHERE dispositivo_id = ?
    ");
    
    $stmt->execute([$device_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        $stats = [
            'total_datos' => 0,
            'datos_temp' => 0,
            'datos_hum' => 0,
            'primer_dato' => 'N/A',
            'ultimo_dato' => 'N/A',
            'promedio_temp' => 0,
            'promedio_hum' => 0,
            'min_temp' => 0,
            'max_temp' => 0,
            'min_hum' => 0,
            'max_hum' => 0
        ];
    } else {
        // Formatear fechas
        if ($stats['primer_dato']) {
            $stats['primer_dato'] = date('d/m/Y H:i', strtotime($stats['primer_dato']));
        }
        if ($stats['ultimo_dato']) {
            $stats['ultimo_dato'] = date('d/m/Y H:i', strtotime($stats['ultimo_dato']));
        }
        
        // Formatear números
        $stats['promedio_temp'] = $stats['promedio_temp'] ? number_format($stats['promedio_temp'], 1) : 0;
        $stats['promedio_hum'] = $stats['promedio_hum'] ? number_format($stats['promedio_hum'], 1) : 0;
        $stats['min_temp'] = $stats['min_temp'] ? number_format($stats['min_temp'], 1) : 0;
        $stats['max_temp'] = $stats['max_temp'] ? number_format($stats['max_temp'], 1) : 0;
        $stats['min_hum'] = $stats['min_hum'] ? number_format($stats['min_hum'], 1) : 0;
        $stats['max_hum'] = $stats['max_hum'] ? number_format($stats['max_hum'], 1) : 0;
    }
    
    echo json_encode($stats);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>