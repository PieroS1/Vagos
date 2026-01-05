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
$dispositivo_id = $input['dispositivo_id'] ?? 0;
$codigo = $input['codigo'] ?? '';
$rango = $input['rango'] ?? '30s';
$action = $input['action'] ?? 'get_realtime_data';

// Verificar que el dispositivo pertenece al cliente
$stmt = $pdo->prepare("SELECT id FROM dispositivos WHERE id = ? AND cliente_id = ?");
$stmt->execute([$dispositivo_id, $_SESSION['user_id']]);
$dispositivo = $stmt->fetch();

if(!$dispositivo || empty($codigo)) {
    echo json_encode(['success' => false, 'error' => 'Dispositivo no encontrado']);
    exit();
}

// Determinar intervalo según el rango
switch($rango) {
    case '30s':
        $intervalo = '30 SECOND';
        $agrupamiento = 'SECOND(timestamp)';
        $formato = '%H:%i:%s';
        $limite = 30;
        break;
    case '5min':
        $intervalo = '5 MINUTE';
        $agrupamiento = 'SECOND(timestamp)';
        $formato = '%H:%i:%s';
        $limite = 60; // 5 minutos * 12 por minuto
        break;
    case '1h':
        $intervalo = '1 HOUR';
        $agrupamiento = 'MINUTE(timestamp)';
        $formato = '%H:%i';
        $limite = 60;
        break;
    case '24h':
        $intervalo = '24 HOUR';
        $agrupamiento = 'HOUR(timestamp)';
        $formato = '%H:00';
        $limite = 24;
        break;
    default:
        $intervalo = '30 SECOND';
        $agrupamiento = 'SECOND(timestamp)';
        $formato = '%H:%i:%s';
        $limite = 30;
}

$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'rango' => $rango
];

// Obtener datos para gráfica
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(timestamp, '$formato') as periodo,
        MAX(timestamp) as timestamp,
        AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
        AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
    FROM mqtt_data 
    WHERE dispositivo_id = ?
    AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
    GROUP BY $agrupamiento, DATE_FORMAT(timestamp, '$formato')
    ORDER BY timestamp DESC
    LIMIT $limite
");
$stmt->execute([$codigo]);
$datos_grafica = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ordenar ascendente para gráfica
$datos_grafica = array_reverse($datos_grafica);

$response['grafica'] = $datos_grafica;

// Obtener últimas lecturas
$stmt = $pdo->prepare("
    SELECT 
        sensor,
        valor,
        timestamp,
        DATE_FORMAT(timestamp, '%H:%i:%s') as hora_completa
    FROM mqtt_data 
    WHERE dispositivo_id = ?
    AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
    ORDER BY timestamp DESC
    LIMIT 20
");
$stmt->execute([$codigo]);
$ultimas_lecturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$response['lecturas'] = $ultimas_lecturas;

// Obtener valores actuales de cada sensor
$stmt = $pdo->prepare("
    SELECT DISTINCT sensor, valor
    FROM mqtt_data 
    WHERE dispositivo_id = ?
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ORDER BY timestamp DESC
");
$stmt->execute([$codigo]);
$sensores_actuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$response['sensores'] = $sensores_actuales;

// Obtener estadísticas del rango
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_lecturas,
        AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
        AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio,
        MIN(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_minima,
        MAX(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_maxima,
        MIN(CASE WHEN sensor = 'humedad' THEN valor END) as hum_minima,
        MAX(CASE WHEN sensor = 'humedad' THEN valor END) as hum_maxima,
        MAX(timestamp) as ultima_lectura
    FROM mqtt_data 
    WHERE dispositivo_id = ?
    AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
");
$stmt->execute([$codigo]);
$estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);

$response['estadisticas'] = $estadisticas;

header('Content-Type: application/json');
echo json_encode($response);
?>