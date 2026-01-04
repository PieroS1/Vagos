<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Obtener todos los dispositivos únicos
$stmt = $pdo->query("SELECT DISTINCT dispositivo_id FROM mqtt_data ORDER BY dispositivo_id");
$dispositivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Verificar si se piden datos separados
$separated = isset($_GET['separated']) && $_GET['separated'] == 'true';

// Configuración de intervalo (30 segundos)
$intervalo_segundos = 30;
$minutos_a_mostrar = 30; // Mostrar últimos 30 minutos
$puntos_totales = ($minutos_a_mostrar * 60) / $intervalo_segundos; // 60 puntos

$response = [
    'labels' => [],
    'temperatura' => [],
    'humedad' => [],
    'dispositivos' => $dispositivos
];

// Generar etiquetas de tiempo para los últimos 30 minutos cada 30 segundos
$now = time();
for ($i = $puntos_totales - 1; $i >= 0; $i--) {
    $timestamp = $now - ($i * $intervalo_segundos);
    $response['labels'][] = date('H:i:s', $timestamp);
}

if ($separated) {
    // Para cada dispositivo, obtener datos de los últimos 30 minutos
    foreach ($dispositivos as $index => $dispositivo) {
        // Inicializar arrays para este dispositivo
        $tempArray = array_fill(0, $puntos_totales, null);
        $humArray = array_fill(0, $puntos_totales, null);
        
        // Obtener datos de temperatura de los últimos 30 minutos
        $stmt = $pdo->prepare("
            SELECT 
                UNIX_TIMESTAMP(timestamp) as timestamp_unix,
                valor
            FROM mqtt_data 
            WHERE dispositivo_id = ? 
                AND sensor = 'temperatura'
                AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$dispositivo, $minutos_a_mostrar]);
        $tempData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener datos de humedad de los últimos 30 minutos
        $stmt = $pdo->prepare("
            SELECT 
                UNIX_TIMESTAMP(timestamp) as timestamp_unix,
                valor
            FROM mqtt_data 
            WHERE dispositivo_id = ? 
                AND sensor = 'humedad'
                AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$dispositivo, $minutos_a_mostrar]);
        $humData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Procesar datos de temperatura
        foreach ($tempData as $dato) {
            $dato_time = (int)$dato['timestamp_unix'];
            
            // Encontrar el intervalo más cercano (ventana de 30 segundos)
            for ($j = 0; $j < $puntos_totales; $j++) {
                $intervalo_time = $now - (($puntos_totales - 1 - $j) * $intervalo_segundos);
                
                // Si el dato está dentro del rango de ±15 segundos del intervalo
                if (abs($dato_time - $intervalo_time) <= 15) {
                    $tempArray[$j] = (float)$dato['valor'];
                    break;
                }
            }
        }
        
        // Procesar datos de humedad
        foreach ($humData as $dato) {
            $dato_time = (int)$dato['timestamp_unix'];
            
            // Encontrar el intervalo más cercano
            for ($j = 0; $j < $puntos_totales; $j++) {
                $intervalo_time = $now - (($puntos_totales - 1 - $j) * $intervalo_segundos);
                
                if (abs($dato_time - $intervalo_time) <= 15) {
                    $humArray[$j] = (float)$dato['valor'];
                    break;
                }
            }
        }
        
        $response['temperatura'][] = $tempArray;
        $response['humedad'][] = $humArray;
    }
} else {
    // Versión simplificada para datos combinados
    $stmt = $pdo->prepare("
        SELECT 
            UNIX_TIMESTAMP(timestamp) as timestamp_unix,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
        FROM mqtt_data 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        GROUP BY FLOOR(UNIX_TIMESTAMP(timestamp) / ?)
        ORDER BY timestamp_unix
    ");
    
    $stmt->execute([$minutos_a_mostrar, $intervalo_segundos]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Inicializar arrays
    $tempArray = array_fill(0, $puntos_totales, null);
    $humArray = array_fill(0, $puntos_totales, null);
    
    // Procesar datos
    foreach ($data as $row) {
        $dato_time = (int)$row['timestamp_unix'];
        
        // Encontrar el intervalo correspondiente
        for ($j = 0; $j < $puntos_totales; $j++) {
            $intervalo_time = $now - (($puntos_totales - 1 - $j) * $intervalo_segundos);
            
            if (abs($dato_time - $intervalo_time) <= 15) {
                if ($row['temperatura'] !== null) {
                    $tempArray[$j] = (float)$row['temperatura'];
                }
                if ($row['humedad'] !== null) {
                    $humArray[$j] = (float)$row['humedad'];
                }
                break;
            }
        }
    }
    
    $response['temperatura'][] = $tempArray;
    $response['humedad'][] = $humArray;
}

echo json_encode($response);
?>