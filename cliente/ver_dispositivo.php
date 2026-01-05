<?php
session_start();

// Verificar si el usuario está logueado y es cliente
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'cliente') {
    header('Location: ../public/index.php');
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Obtener ID del dispositivo
$dispositivo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($dispositivo_id <= 0) {
    header('Location: index.php');
    exit();
}

// Obtener información del cliente actual
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$cliente = $stmt->fetch();

// Obtener información del dispositivo y verificar que pertenece al cliente
$stmt = $pdo->prepare("
    SELECT 
        d.*,
        u.full_name as cliente_nombre
    FROM dispositivos d
    LEFT JOIN users u ON d.cliente_id = u.id
    WHERE d.id = ? 
    AND d.cliente_id = ?
");
$stmt->execute([$dispositivo_id, $_SESSION['user_id']]);
$dispositivo = $stmt->fetch();

// Verificar que el dispositivo existe y pertenece al cliente
if(!$dispositivo) {
    header('Location: index.php?error=dispositivo_no_encontrado');
    exit();
}

// Parámetros de tiempo (últimos 30 segundos)
$intervalo = '30 SECOND';

// Obtener estadísticas del dispositivo (últimos 30 segundos)
$estadisticas = [];
if(!empty($dispositivo['codigo'])) {
    // Estadísticas de los últimos 30 segundos
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lecturas,
            MIN(timestamp) as primera_lectura,
            MAX(timestamp) as ultima_lectura,
            COUNT(DISTINCT sensor) as total_sensores,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio,
            MIN(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_minima,
            MAX(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_maxima,
            MIN(CASE WHEN sensor = 'humedad' THEN valor END) as hum_minima,
            MAX(CASE WHEN sensor = 'humedad' THEN valor END) as hum_maxima,
            STDDEV(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_desviacion,
            STDDEV(CASE WHEN sensor = 'humedad' THEN valor END) as hum_desviacion
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $estadisticas_30s = $stmt->fetch();
    
    // Estadísticas de los últimos 5 minutos para contexto
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lecturas_5min,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio_5min,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio_5min
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $estadisticas_5min = $stmt->fetch();
    
    // Lista de sensores disponibles
    $stmt = $pdo->prepare("
        SELECT DISTINCT sensor
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        ORDER BY sensor
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $sensores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Obtener últimas lecturas (últimos 30 segundos)
    $stmt = $pdo->prepare("
        SELECT 
            sensor,
            valor,
            timestamp,
            DATE_FORMAT(timestamp, '%H:%i:%s') as hora_completa,
            DATE_FORMAT(timestamp, '%H:%i') as hora_minuto,
            DATE_FORMAT(timestamp, '%d/%m/%Y') as fecha
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
        ORDER BY timestamp DESC
        LIMIT 20
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $ultimas_lecturas = $stmt->fetchAll();
    
    // Verificar si está en línea (últimos 30 segundos)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as lecturas_recientes
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $result = $stmt->fetch();
    $en_linea = ($result['lecturas_recientes'] > 0);
} else {
    $estadisticas_30s = [];
    $estadisticas_5min = [];
    $sensores = [];
    $ultimas_lecturas = [];
    $en_linea = false;
}

// Obtener datos para gráficas de los últimos 30 segundos (detallado por segundo)
$datos_grafica_tiempo_real = [];
if(!empty($dispositivo['codigo'])) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '%H:%i:%s') as segundo,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo)
        GROUP BY DATE_FORMAT(timestamp, '%H:%i:%s')
        ORDER BY timestamp
    ");
    $stmt->execute([$dispositivo['codigo']]);
    $datos_grafica_tiempo_real = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay datos en 30 segundos, buscar en 5 minutos
    if(empty($datos_grafica_tiempo_real)) {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(timestamp, '%H:%i:%s') as segundo,
                AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
                AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad
            FROM mqtt_data 
            WHERE dispositivo_id = ?
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            GROUP BY DATE_FORMAT(timestamp, '%H:%i:%s')
            ORDER BY timestamp DESC
            LIMIT 30
        ");
        $stmt->execute([$dispositivo['codigo']]);
        $datos_grafica_tiempo_real = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $datos_grafica_tiempo_real = array_reverse($datos_grafica_tiempo_real);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalles del Dispositivo - Sistema IoT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.0/dist/chartjs-plugin-zoom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #1d4ed8;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark-color);
        }
        
        .cliente-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .cliente-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.3;
        }
        
        .breadcrumb-custom {
            background: transparent;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .device-header-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            border-left: 5px solid var(--primary-color);
            position: relative;
            overflow: hidden;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .status-online {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            animation: pulse 2s infinite;
        }
        
        .status-offline {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border-top: 4px solid var(--primary-color);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            height: 450px;
            position: relative;
        }
        
        .chart-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            display: flex;
            gap: 10px;
        }
        
        .chart-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--light-color);
            border: 1px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--dark-color);
        }
        
        .chart-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .sensor-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin: 0.25rem;
        }
        
        .sensor-temp {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .sensor-hum {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .real-time-badge {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            animation: pulse 2s infinite;
        }
        
        .time-range-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .time-range-btn {
            padding: 8px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: #64748b;
            font-weight: 500;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .time-range-btn:hover {
            background: #f1f5f9;
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .time-range-btn.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            padding: 1rem;
            text-align: left;
            color: #475569;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        .data-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background-color 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .data-table tbody td {
            padding: 1rem;
            color: #475569;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        
        .no-data-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .tooltip-custom {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: #64748b;
        }
        
        .refresh-indicator .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 350px;
            }
            
            .chart-controls {
                top: 10px;
                right: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Header del Cliente -->
    <header class="cliente-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 40px; height: 40px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary-color);">
                            <i class="bi bi-cpu fs-5"></i>
                        </div>
                        <div>
                            <h1 class="mb-1 h3 fw-bold">Detalles del Dispositivo</h1>
                            <p class="mb-0 opacity-90">
                                <i class="bi bi-person-fill me-1"></i>
                                <?= htmlspecialchars($cliente['full_name'] ?? $cliente['username']) ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="breadcrumb-custom">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <li class="breadcrumb-item"><a href="dispositivos.php"><i class="bi bi-cpu"></i> Mis Dispositivos</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($dispositivo['nombre']) ?></li>
            </ol>
        </nav>

        <!-- Header del dispositivo -->
        <div class="device-header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white;">
                            <i class="bi bi-cpu fs-3"></i>
                        </div>
                        <div>
                            <h2 class="mb-2"><?= htmlspecialchars($dispositivo['nombre']) ?></h2>
                            <div class="d-flex align-items-center gap-3">
                                <span class="status-badge <?= $en_linea ? 'status-online' : 'status-offline' ?>">
                                    <i class="bi bi-<?= $en_linea ? 'wifi' : 'wifi-off' ?>"></i>
                                    <?= $en_linea ? 'En línea' : 'Offline' ?>
                                </span>
                                <span class="real-time-badge">
                                    <i class="bi bi-lightning-charge-fill"></i>
                                    Datos en Tiempo Real (30s)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <div class="refresh-indicator">
                        <span class="dot"></span>
                        Actualizando cada 5 segundos
                    </div>
                </div>
            </div>
        </div>

        <?php if(empty($dispositivo['codigo']) || empty($sensores)): ?>
        <!-- Sin datos -->
        <div class="no-data">
            <div class="no-data-icon">
                <i class="bi bi-database-slash"></i>
            </div>
            <h4 class="mb-3">Sin datos disponibles</h4>
            <p class="text-muted mb-4">
                Este dispositivo no tiene datos registrados o no tiene código MQTT configurado.
            </p>
        </div>
        <?php else: ?>

        <!-- Estadísticas en tiempo real -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="fs-1 mb-2" style="color: #dc2626;">
                    <i class="bi bi-thermometer-half"></i>
                </div>
                <div class="stat-value fs-3 fw-bold">
                    <?= $estadisticas_30s['temp_promedio'] ? number_format($estadisticas_30s['temp_promedio'], 1) . '°C' : '--' ?>
                </div>
                <div class="stat-label">Temp. Actual</div>
                <small class="text-muted d-block mt-2">
                    <?php if($estadisticas_30s['temp_minima'] && $estadisticas_30s['temp_maxima']): ?>
                    Rango: <?= number_format($estadisticas_30s['temp_minima'], 1) ?>° - <?= number_format($estadisticas_30s['temp_maxima'], 1) ?>°
                    <?php endif; ?>
                </small>
            </div>
            
            <div class="stat-card">
                <div class="fs-1 mb-2" style="color: #2563eb;">
                    <i class="bi bi-droplet-half"></i>
                </div>
                <div class="stat-value fs-3 fw-bold">
                    <?= $estadisticas_30s['hum_promedio'] ? number_format($estadisticas_30s['hum_promedio'], 1) . '%' : '--' ?>
                </div>
                <div class="stat-label">Hum. Actual</div>
                <small class="text-muted d-block mt-2">
                    <?php if($estadisticas_30s['hum_minima'] && $estadisticas_30s['hum_maxima']): ?>
                    Rango: <?= number_format($estadisticas_30s['hum_minima'], 1) ?>% - <?= number_format($estadisticas_30s['hum_maxima'], 1) ?>%
                    <?php endif; ?>
                </small>
            </div>
            
            <div class="stat-card">
                <div class="fs-1 mb-2" style="color: #10b981;">
                    <i class="bi bi-database"></i>
                </div>
                <div class="stat-value fs-3 fw-bold">
                    <?= number_format($estadisticas_30s['total_lecturas'] ?? 0) ?>
                </div>
                <div class="stat-label">Lecturas (30s)</div>
                <small class="text-muted d-block mt-2">
                    <?php if($estadisticas_5min['total_lecturas_5min'] ?? 0): ?>
                    <?= number_format($estadisticas_5min['total_lecturas_5min']) ?> en 5 min
                    <?php endif; ?>
                </small>
            </div>
            
            <div class="stat-card">
                <div class="fs-1 mb-2" style="color: #8b5cf6;">
                    <i class="bi bi-speedometer2"></i>
                </div>
                <div class="stat-value fs-3 fw-bold">
                    <?= count($sensores) ?>
                </div>
                <div class="stat-label">Sensores</div>
                <small class="text-muted d-block mt-2">
                    Último: <?= $estadisticas_30s['ultima_lectura'] ? date('H:i:s', strtotime($estadisticas_30s['ultima_lectura'])) : '--' ?>
                </small>
            </div>
        </div>

        <!-- Gráfica interactiva principal -->
        <div class="chart-container">
            <div class="chart-controls">
                <button class="chart-btn" title="Zoom in" onclick="zoomChart('in')">
                    <i class="bi bi-zoom-in"></i>
                </button>
                <button class="chart-btn" title="Zoom out" onclick="zoomChart('out')">
                    <i class="bi bi-zoom-out"></i>
                </button>
                <button class="chart-btn" title="Reset zoom" onclick="resetZoom()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="chart-btn" title="Exportar gráfica" onclick="exportChart()">
                    <i class="bi bi-download"></i>
                </button>
            </div>
            <h5 class="mb-3">
                <i class="bi bi-graph-up me-2"></i>
                Monitoreo en Tiempo Real (Últimos 30 segundos)
            </h5>
            <canvas id="realTimeChart"></canvas>
        </div>

        <!-- Selector de rangos de tiempo adicionales -->
        <div class="time-range-selector">
            <span class="text-muted me-2">Ver histórico:</span>
            <button class="time-range-btn" onclick="cambiarRango('30s')" id="btn-30s">30 segundos</button>
            <button class="time-range-btn" onclick="cambiarRango('5min')" id="btn-5min">5 minutos</button>
            <button class="time-range-btn" onclick="cambiarRango('1h')" id="btn-1h">1 hora</button>
            <button class="time-range-btn" onclick="cambiarRango('24h')" id="btn-24h">24 horas</button>
        </div>

        <!-- Sensores detectados -->
        <div class="table-container">
            <h5 class="mb-3">
                <i class="bi bi-sensors me-2"></i>
                Sensores Detectados en Tiempo Real
            </h5>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach($sensores as $sensor): 
                    $badge_class = 'bg-secondary';
                    if(strpos(strtolower($sensor), 'temp') !== false) {
                        $badge_class = 'sensor-temp';
                    } elseif(strpos(strtolower($sensor), 'hum') !== false) {
                        $badge_class = 'sensor-hum';
                    }
                ?>
                <span class="sensor-badge <?= $badge_class ?>" id="sensor-<?= htmlspecialchars($sensor) ?>">
                    <i class="bi bi-<?= $badge_class == 'sensor-temp' ? 'thermometer-half' : ($badge_class == 'sensor-hum' ? 'droplet-half' : 'cpu') ?> me-1"></i>
                    <?= htmlspecialchars($sensor) ?>
                    <span class="badge bg-dark ms-1" id="valor-<?= htmlspecialchars($sensor) ?>">--</span>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Últimas lecturas en tiempo real -->
        <div class="table-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-clock-history me-2"></i>
                    Últimas Lecturas (30 segundos)
                </h5>
                <button class="btn btn-sm btn-outline-primary" onclick="actualizarDatos()">
                    <i class="bi bi-arrow-clockwise"></i> Actualizar
                </button>
            </div>
            <div class="table-responsive">
                <table class="table data-table">
                    <thead>
                        <tr>
                            <th>Sensor</th>
                            <th>Valor</th>
                            <th>Hora Exacta</th>
                            <th>Hace</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="lecturasTable">
                        <?php if(empty($ultimas_lecturas)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No hay lecturas en los últimos 30 segundos
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach($ultimas_lecturas as $lectura): 
                                $tiempo = time() - strtotime($lectura['timestamp']);
                            ?>
                            <tr>
                                <td>
                                    <?php if(strpos(strtolower($lectura['sensor']), 'temp') !== false): ?>
                                    <span class="sensor-badge sensor-temp">
                                        <i class="bi bi-thermometer-half me-1"></i>
                                        <?= htmlspecialchars($lectura['sensor']) ?>
                                    </span>
                                    <?php elseif(strpos(strtolower($lectura['sensor']), 'hum') !== false): ?>
                                    <span class="sensor-badge sensor-hum">
                                        <i class="bi bi-droplet-half me-1"></i>
                                        <?= htmlspecialchars($lectura['sensor']) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-cpu me-1"></i>
                                        <?= htmlspecialchars($lectura['sensor']) ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>
                                        <?= number_format($lectura['valor'], 2) ?>
                                        <?= (strpos(strtolower($lectura['sensor']), 'temp') !== false) ? '°C' : 
                                            ((strpos(strtolower($lectura['sensor']), 'hum') !== false) ? '%' : '') ?>
                                    </strong>
                                </td>
                                <td><?= $lectura['hora_completa'] ?></td>
                                <td>
                                    <?php if($tiempo < 60): ?>
                                    <span class="badge bg-success">
                                        <?= $tiempo ?> seg
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">
                                        <?= floor($tiempo/60) ?> min
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($tiempo < 10): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-lightning-charge-fill me-1"></i> En vivo
                                    </span>
                                    <?php elseif($tiempo < 30): ?>
                                    <span class="badge bg-info">
                                        <i class="bi bi-check-circle me-1"></i> Reciente
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-warning">
                                        <i class="bi bi-clock me-1"></i> Antiguo
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Botones de acción -->
        <div class="d-flex gap-2 mt-4">
            <a href="index.php" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>
                Volver a Mis Dispositivos
            </a>
            
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Variables globales
    let realTimeChart = null;
    let autoRefreshInterval = null;
    let currentRange = '30s';
    let dispositivoId = <?= $dispositivo_id ?>;
    
    // Datos iniciales para gráfica
    const initialChartData = <?= json_encode($datos_grafica_tiempo_real) ?>;
    
    // Configuración inicial del gráfico
    const chartConfig = {
        type: 'line',
        data: {
            datasets: []
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += context.parsed.y.toFixed(2);
                                label += context.dataset.label.includes('Temperatura') ? '°C' : '%';
                            }
                            return label;
                        }
                    }
                },
                zoom: {
                    zoom: {
                        wheel: {
                            enabled: true,
                        },
                        pinch: {
                            enabled: true
                        },
                        mode: 'x',
                    },
                    pan: {
                        enabled: true,
                        mode: 'x',
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'second',
                        displayFormats: {
                            second: 'HH:mm:ss'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Tiempo'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                },
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: 'Valores'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                }
            },
            animation: {
                duration: 1000
            }
        }
    };
    
    // Función para inicializar la gráfica
    function initChart() {
        const ctx = document.getElementById('realTimeChart')?.getContext('2d');
        if (!ctx) return;
        
        // Preparar datos iniciales
        const temperatureData = [];
        const humidityData = [];
        
        initialChartData.forEach(item => {
            const time = new Date();
            time.setHours(...item.segundo.split(':'));
            
            temperatureData.push({
                x: time,
                y: item.temperatura || null
            });
            
            humidityData.push({
                x: time,
                y: item.humedad || null
            });
        });
        
        chartConfig.data.datasets = [
            {
                label: 'Temperatura (°C)',
                data: temperatureData,
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220, 38, 38, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#dc2626',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            },
            {
                label: 'Humedad (%)',
                data: humidityData,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            }
        ];
        
        realTimeChart = new Chart(ctx, chartConfig);
        
        // Activar botón de 30 segundos por defecto
        document.getElementById('btn-30s').classList.add('active');
    }
    
    // Función para actualizar datos en tiempo real
    async function updateRealTimeData() {
        try {
            const response = await fetch('actualizar_datos_dispositivo.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    dispositivo_id: dispositivoId,
                    codigo: '<?= $dispositivo['codigo'] ?>',
                    rango: currentRange,
                    action: 'get_realtime_data'
                })
            });
            
            if (!response.ok) throw new Error('Error en la respuesta');
            
            const data = await response.json();
            
            if (data.success && realTimeChart) {
                // Actualizar gráfica
                if (data.grafica) {
                    updateChart(data.grafica);
                }
                
                // Actualizar tabla de lecturas
                if (data.lecturas) {
                    updateLecturasTable(data.lecturas);
                }
                
                // Actualizar valores de sensores
                if (data.sensores) {
                    updateSensorValues(data.sensores);
                }
                
                // Actualizar estadísticas
                if (data.estadisticas) {
                    updateStatistics(data.estadisticas);
                }
                
                // Actualizar indicador de última actualización
                updateLastUpdateTime();
            }
        } catch (error) {
            console.error('Error al actualizar datos:', error);
        }
    }
    
    // Función para actualizar la gráfica
    function updateChart(graficaData) {
        if (!realTimeChart || !graficaData) return;
        
        const temperatureData = [];
        const humidityData = [];
        
        graficaData.forEach(item => {
            const time = new Date(item.timestamp || item.segundo);
            
            if (item.temperatura !== undefined) {
                temperatureData.push({
                    x: time,
                    y: item.temperatura
                });
            }
            
            if (item.humedad !== undefined) {
                humidityData.push({
                    x: time,
                    y: item.humedad
                });
            }
        });
        
        realTimeChart.data.datasets[0].data = temperatureData;
        realTimeChart.data.datasets[1].data = humidityData;
        
        // Actualizar límites del eje X según el rango
        updateTimeAxis();
        
        realTimeChart.update('none');
    }
    
    // Función para actualizar límites del eje de tiempo
    function updateTimeAxis() {
        if (!realTimeChart) return;
        
        const now = new Date();
        let minTime = new Date(now);
        
        switch(currentRange) {
            case '30s':
                minTime.setSeconds(minTime.getSeconds() - 30);
                realTimeChart.options.scales.x.time.unit = 'second';
                break;
            case '5min':
                minTime.setMinutes(minTime.getMinutes() - 5);
                realTimeChart.options.scales.x.time.unit = 'minute';
                break;
            case '1h':
                minTime.setHours(minTime.getHours() - 1);
                realTimeChart.options.scales.x.time.unit = 'minute';
                break;
            case '24h':
                minTime.setHours(minTime.getHours() - 24);
                realTimeChart.options.scales.x.time.unit = 'hour';
                break;
        }
        
        realTimeChart.options.scales.x.min = minTime;
        realTimeChart.options.scales.x.max = now;
    }
    
    // Función para actualizar tabla de lecturas
    function updateLecturasTable(lecturas) {
        const tbody = document.getElementById('lecturasTable');
        if (!tbody || !lecturas) return;
        
        tbody.innerHTML = '';
        
        if (lecturas.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        No hay lecturas en los últimos 30 segundos
                    </td>
                </tr>
            `;
            return;
        }
        
        lecturas.forEach(lectura => {
            const tiempo = Math.floor((Date.now() - new Date(lectura.timestamp).getTime()) / 1000);
            
            // Determinar clase del sensor
            const sensorClass = lectura.sensor.toLowerCase().includes('temp') ? 'sensor-temp' :
                              lectura.sensor.toLowerCase().includes('hum') ? 'sensor-hum' : 'bg-secondary';
            
            const sensorIcon = lectura.sensor.toLowerCase().includes('temp') ? 'thermometer-half' :
                             lectura.sensor.toLowerCase().includes('hum') ? 'droplet-half' : 'cpu';
            
            const unidad = lectura.sensor.toLowerCase().includes('temp') ? '°C' :
                          lectura.sensor.toLowerCase().includes('hum') ? '%' : '';
            
            // Determinar estado
            let estadoHTML = '';
            let estadoClass = '';
            
            if (tiempo < 10) {
                estadoHTML = '<i class="bi bi-lightning-charge-fill me-1"></i> En vivo';
                estadoClass = 'bg-success';
            } else if (tiempo < 30) {
                estadoHTML = '<i class="bi bi-check-circle me-1"></i> Reciente';
                estadoClass = 'bg-info';
            } else {
                estadoHTML = '<i class="bi bi-clock me-1"></i> Antiguo';
                estadoClass = 'bg-warning';
            }
            
            const tiempoHTML = tiempo < 60 ? 
                `<span class="badge bg-success">${tiempo} seg</span>` :
                `<span class="badge bg-secondary">${Math.floor(tiempo/60)} min</span>`;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <span class="sensor-badge ${sensorClass}">
                        <i class="bi bi-${sensorIcon} me-1"></i>
                        ${lectura.sensor}
                    </span>
                </td>
                <td>
                    <strong>${parseFloat(lectura.valor).toFixed(2)}${unidad}</strong>
                </td>
                <td>${new Date(lectura.timestamp).toLocaleTimeString('es-ES')}</td>
                <td>${tiempoHTML}</td>
                <td>
                    <span class="badge ${estadoClass}">
                        ${estadoHTML}
                    </span>
                </td>
            `;
            
            // Efecto de highlight para lecturas nuevas
            row.style.backgroundColor = '#f0f9ff';
            setTimeout(() => {
                row.style.backgroundColor = '';
            }, 1000);
            
            tbody.appendChild(row);
        });
    }
    
    // Función para actualizar valores de sensores
    function updateSensorValues(sensores) {
        sensores.forEach(sensor => {
            const element = document.getElementById(`valor-${sensor.nombre}`);
            if (element) {
                const valor = parseFloat(sensor.valor).toFixed(2);
                const unidad = sensor.nombre.toLowerCase().includes('temp') ? '°C' :
                              sensor.nombre.toLowerCase().includes('hum') ? '%' : '';
                element.textContent = `${valor}${unidad}`;
                
                // Animación de cambio
                element.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 300);
            }
        });
    }
    
    // Función para actualizar estadísticas
    function updateStatistics(estadisticas) {
        // Actualizar temperatura
        const tempElement = document.querySelector('.stat-card:nth-child(1) .stat-value');
        if (tempElement && estadisticas.temp_promedio !== undefined) {
            const newTemp = estadisticas.temp_promedio ? 
                estadisticas.temp_promedio.toFixed(1) + '°C' : '--';
            if (tempElement.textContent !== newTemp) {
                tempElement.textContent = newTemp;
                tempElement.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    tempElement.style.transform = 'scale(1)';
                }, 300);
            }
        }
        
        // Actualizar humedad
        const humElement = document.querySelector('.stat-card:nth-child(2) .stat-value');
        if (humElement && estadisticas.hum_promedio !== undefined) {
            const newHum = estadisticas.hum_promedio ? 
                estadisticas.hum_promedio.toFixed(1) + '%' : '--';
            if (humElement.textContent !== newHum) {
                humElement.textContent = newHum;
                humElement.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    humElement.style.transform = 'scale(1)';
                }, 300);
            }
        }
        
        // Actualizar lecturas
        const lecturasElement = document.querySelector('.stat-card:nth-child(3) .stat-value');
        if (lecturasElement && estadisticas.total_lecturas !== undefined) {
            lecturasElement.textContent = estadisticas.total_lecturas;
        }
    }
    
    // Función para actualizar indicador de última actualización
    function updateLastUpdateTime() {
        const now = new Date();
        document.querySelector('.refresh-indicator').innerHTML = `
            <span class="dot"></span>
            Última actualización: ${now.toLocaleTimeString('es-ES')}
        `;
    }
    
    // Función para cambiar el rango de tiempo
    async function cambiarRango(rango) {
        currentRange = rango;
        
        // Actualizar botones activos
        document.querySelectorAll('.time-range-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.getElementById(`btn-${rango}`).classList.add('active');
        
        // Actualizar datos con el nuevo rango
        await updateRealTimeData();
    }
    
    // Funciones de zoom
    function zoomChart(direction) {
        if (!realTimeChart) return;
        
        const zoom = realTimeChart.options.plugins.zoom.zoom;
        const scale = direction === 'in' ? 0.8 : 1.25;
        
        // Zoom programático
        const xScale = realTimeChart.scales.x;
        const pixelRange = xScale.max - xScale.min;
        const centerPixel = (xScale.max + xScale.min) / 2;
        
        realTimeChart.options.scales.x.min = centerPixel - (pixelRange * scale) / 2;
        realTimeChart.options.scales.x.max = centerPixel + (pixelRange * scale) / 2;
        
        realTimeChart.update();
    }
    
    function resetZoom() {
        if (!realTimeChart) return;
        
        // Restablecer límites del eje X según el rango actual
        updateTimeAxis();
        realTimeChart.update();
    }
    
    // Función para exportar la gráfica
    function exportChart() {
        if (!realTimeChart) return;
        
        const link = document.createElement('a');
        link.download = `grafica_tiempo_real_${dispositivoId}_${new Date().toISOString().slice(0,19).replace(/:/g, '-')}.png`;
        link.href = realTimeChart.toBase64Image();
        link.click();
    }
    
    // Función para actualizar datos manualmente
    function actualizarDatos() {
        const btn = document.querySelector('[onclick="actualizarDatos()"]');
        const originalHTML = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span>';
        
        updateRealTimeData().finally(() => {
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Actualizar';
            }, 500);
        });
    }
    
    // Iniciar auto-refresh cada 5 segundos
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
        
        // Primera actualización inmediata
        setTimeout(updateRealTimeData, 1000);
        
        // Luego cada 5 segundos
        autoRefreshInterval = setInterval(() => {
            updateRealTimeData();
        }, 5000);
    }
    
    // Detectar cuando la pestaña se vuelve visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && autoRefreshInterval) {
            updateRealTimeData();
        }
    });
    
    // Inicializar cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        initChart();
        
        <?php if(!empty($dispositivo['codigo']) && !empty($sensores)): ?>
        startAutoRefresh();
        <?php endif; ?>
        
        updateLastUpdateTime();
    });
    </script>
</body>
</html>