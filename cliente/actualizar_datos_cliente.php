<?php
session_start();

// Verificar si el usuario está logueado y es cliente
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'cliente') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
$cliente_id = $_SESSION['user_id'];
$action = $input['action'] ?? 'get_realtime_data';

// Obtener dispositivos del cliente
$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.nombre,
        d.codigo,
        d.tipo,
        d.protocolo,
        d.ubicacion
    FROM dispositivos d
    WHERE d.cliente_id = ?
");
$stmt->execute([$cliente_id]);
$dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Array para almacenar códigos MQTT de dispositivos
$codigos_dispositivos = [];
foreach($dispositivos as $dispositivo) {
    if(!empty($dispositivo['codigo'])) {
        $codigos_dispositivos[] = $dispositivo['codigo'];
    }
}

$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'action' => $action
];

if (!empty($codigos_dispositivos)) {
    // Preparar placeholders para la consulta IN
    $placeholders = str_repeat('?,', count($codigos_dispositivos)-1) . '?';
    
    // Estadísticas de los últimos 30 segundos
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_datos,
            COUNT(DISTINCT dispositivo_id) as total_dispositivos,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio,
            MAX(timestamp) as ultima_lectura
        FROM mqtt_data 
        WHERE dispositivo_id IN ($placeholders)
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ");
    $stmt->execute($codigos_dispositivos);
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $response['estadisticas'] = $estadisticas;
    
    // Datos para gráfica en tiempo real (últimos 30 segundos)
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '%H:%i:%s') as segundo,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
        FROM mqtt_data 
        WHERE dispositivo_id IN ($placeholders)
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        GROUP BY DATE_FORMAT(timestamp, '%H:%i:%s')
        ORDER BY timestamp
        LIMIT 30
    ");
    $stmt->execute($codigos_dispositivos);
    $datos_grafica = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar datos para gráfica
    $labels = [];
    $temperaturas = [];
    $humedades = [];
    
    foreach ($datos_grafica as $dato) {
        $labels[] = $dato['segundo'];
        $temperaturas[] = $dato['temperatura'] !== null ? (float)$dato['temperatura'] : null;
        $humedades[] = $dato['humedad'] !== null ? (float)$dato['humedad'] : null;
    }
    
    $response['graficas_tiempo_real'] = [
        'labels' => $labels,
        'temperatura' => $temperaturas,
        'humedad' => $humedades
    ];
    
    // Datos para gráfica de 5 minutos
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '%H:%i') as minuto_segundo,
            FLOOR(SECOND(timestamp)/10)*10 as segmento,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
        FROM mqtt_data 
        WHERE dispositivo_id IN ($placeholders)
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY DATE_FORMAT(timestamp, '%H:%i'), FLOOR(SECOND(timestamp)/10)
        ORDER BY timestamp
        LIMIT 30
    ");
    $stmt->execute($codigos_dispositivos);
    $datos_5min = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar datos para gráfica de 5 minutos
    $labels_5min = [];
    $temperaturas_5min = [];
    $humedades_5min = [];
    
    foreach ($datos_5min as $dato) {
        $label = $dato['minuto_segundo'] . ':' . str_pad($dato['segmento'], 2, '0', STR_PAD_LEFT);
        $labels_5min[] = $label;
        $temperaturas_5min[] = $dato['temperatura'] !== null ? (float)$dato['temperatura'] : null;
        $humedades_5min[] = $dato['humedad'] !== null ? (float)$dato['humedad'] : null;
    }
    
    $response['graficas_5min'] = [
        'labels' => $labels_5min,
        'temperatura' => $temperaturas_5min,
        'humedad' => $humedades_5min
    ];
    
    // Últimas lecturas (últimos 30 segundos)
    $stmt = $pdo->prepare("
        SELECT 
            m.dispositivo_id,
            m.sensor,
            m.valor,
            m.timestamp,
            d.nombre as dispositivo_nombre
        FROM mqtt_data m
        LEFT JOIN dispositivos d ON m.dispositivo_id = d.codigo
        WHERE m.dispositivo_id IN ($placeholders)
        AND d.cliente_id = ?
        AND m.timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ORDER BY m.timestamp DESC 
        LIMIT 20
    ");
    $stmt->execute(array_merge($codigos_dispositivos, [$cliente_id]));
    $ultimas_lecturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['ultimas_lecturas'] = $ultimas_lecturas;
    
    // Datos detallados por dispositivo
    $dispositivos_detallados = [];
    foreach($dispositivos as $dispositivo) {
        if(!empty($dispositivo['codigo'])) {
            $stmt = $pdo->prepare("
                SELECT 
                    AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
                    AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio,
                    MAX(timestamp) as ultima_lectura,
                    COUNT(*) as total_lecturas
                FROM mqtt_data 
                WHERE dispositivo_id = ?
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
            ");
            $stmt->execute([$dispositivo['codigo']]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verificar si está en línea
            $en_linea = !empty($datos['ultima_lectura']);
            
            $dispositivos_detallados[] = [
                'id' => $dispositivo['id'],
                'nombre' => $dispositivo['nombre'],
                'temp_promedio' => $datos['temp_promedio'] ? (float)$datos['temp_promedio'] : null,
                'hum_promedio' => $datos['hum_promedio'] ? (float)$datos['hum_promedio'] : null,
                'ultima_lectura' => $datos['ultima_lectura'],
                'total_lecturas' => (int)$datos['total_lecturas'],
                'en_linea' => $en_linea
            ];
        }
    }
    
    $response['dispositivos'] = $dispositivos_detallados;
} else {
    $response['estadisticas'] = [
        'total_datos' => 0,
        'total_dispositivos' => 0,
        'temp_promedio' => null,
        'hum_promedio' => null,
        'ultima_lectura' => null
    ];
    $response['graficas_tiempo_real'] = ['labels' => [], 'temperatura' => [], 'humedad' => []];
    $response['graficas_5min'] = ['labels' => [], 'temperatura' => [], 'humedad' => []];
    $response['ultimas_lecturas'] = [];
    $response['dispositivos'] = [];
}

header('Content-Type: application/json');
echo json_encode($response);
?>