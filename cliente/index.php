<?php
session_start();

// Verificar si el usuario está logueado y es cliente
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'cliente') {
    header('Location: ../public/index.php');
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Obtener información del cliente actual
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$cliente = $stmt->fetch();

// Obtener dispositivos asignados específicamente a este cliente
$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.nombre,
        d.codigo,
        d.tipo,
        d.protocolo,
        d.ubicacion,
        d.estado,
        d.fecha_instalacion
    FROM dispositivos d
    WHERE d.cliente_id = ?
    ORDER BY d.nombre
");
$stmt->execute([$_SESSION['user_id']]);
$dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verificar si tiene dispositivos
$tiene_dispositivos = !empty($dispositivos);

// Array para almacenar códigos MQTT de dispositivos
$codigos_dispositivos = [];
foreach($dispositivos as $dispositivo) {
    if(!empty($dispositivo['codigo'])) {
        $codigos_dispositivos[] = $dispositivo['codigo'];
    }
}

// Parámetros de tiempo por defecto (último día)
$rango_seleccionado = $_GET['rango'] ?? 'dia';
$intervalos = [
    'hora' => '1 HOUR',
    'dia' => '1 DAY',
    'semana' => '1 WEEK',
    'mes' => '1 MONTH',
    'ano' => '1 YEAR',
    '2anos' => '2 YEAR'
];

$intervalo_sql = $intervalos[$rango_seleccionado] ?? '1 DAY';

// Obtener estadísticas según el rango seleccionado
if ($tiene_dispositivos && !empty($codigos_dispositivos)) {
    $placeholders = str_repeat('?,', count($codigos_dispositivos)-1) . '?';
    
    // Estadísticas del rango seleccionado
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_datos,
            COUNT(DISTINCT dispositivo_id) as total_dispositivos,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio,
            MIN(timestamp) as primera_lectura,
            MAX(timestamp) as ultima_lectura,
            MIN(valor) as temp_minima,
            MAX(valor) as temp_maxima,
            MIN(CASE WHEN sensor = 'humedad' THEN valor END) as hum_minima,
            MAX(CASE WHEN sensor = 'humedad' THEN valor END) as hum_maxima
        FROM mqtt_data 
        WHERE dispositivo_id IN ($placeholders)
        AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo_sql)
    ");
    $stmt->execute($codigos_dispositivos);
    $estadisticas = $stmt->fetch();
    
    // Verificar estado en línea de cada dispositivo (últimos 5 minutos)
    $dispositivos_con_estado = [];
    foreach($dispositivos as $dispositivo) {
        if(!empty($dispositivo['codigo'])) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as lecturas_recientes
                FROM mqtt_data 
                WHERE dispositivo_id = ?
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->execute([$dispositivo['codigo']]);
            $result = $stmt->fetch();
            
            $dispositivo['en_linea'] = ($result['lecturas_recientes'] > 0);
            $dispositivos_con_estado[] = $dispositivo;
        } else {
            $dispositivo['en_linea'] = false;
            $dispositivos_con_estado[] = $dispositivo;
        }
    }
    $dispositivos = $dispositivos_con_estado;
} else {
    // Valores por defecto si no tiene dispositivos
    $estadisticas = [
        'total_datos' => 0,
        'total_dispositivos' => 0,
        'temp_promedio' => 0,
        'hum_promedio' => 0,
        'temp_minima' => 0,
        'temp_maxima' => 0,
        'hum_minima' => 0,
        'hum_maxima' => 0,
        'primera_lectura' => null,
        'ultima_lectura' => null
    ];
}

// Determinar el agrupamiento según el rango de tiempo
$agrupamiento = 'MINUTE'; // Por defecto
$formato_fecha = '%H:%i'; // Formato por defecto para hora:minuto

switch ($rango_seleccionado) {
    case 'hora':
        $agrupamiento = 'MINUTE';
        $formato_fecha = '%H:%i';
        $limite = 60; // 60 minutos
        break;
    case 'dia':
        $agrupamiento = 'HOUR';
        $formato_fecha = '%H:00';
        $limite = 24; // 24 horas
        break;
    case 'semana':
        $agrupamiento = 'DAY';
        $formato_fecha = '%Y-%m-%d';
        $limite = 7; // 7 días
        break;
    case 'mes':
        $agrupamiento = 'DAY';
        $formato_fecha = '%Y-%m-%d';
        $limite = 30; // 30 días
        break;
    case 'ano':
        $agrupamiento = 'MONTH';
        $formato_fecha = '%Y-%m';
        $limite = 12; // 12 meses
        break;
    case '2anos':
        $agrupamiento = 'MONTH';
        $formato_fecha = '%Y-%m';
        $limite = 24; // 24 meses
        break;
}

// Obtener datos para gráficas según el rango seleccionado
$datos_grafica = [];
if ($tiene_dispositivos && !empty($codigos_dispositivos)) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '$formato_fecha') as periodo,
            AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temperatura,
            AVG(CASE WHEN sensor = 'humedad' THEN valor END) as humedad,
            MIN(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_minima,
            MAX(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_maxima,
            MIN(CASE WHEN sensor = 'humedad' THEN valor END) as hum_minima,
            MAX(CASE WHEN sensor = 'humedad' THEN valor END) as hum_maxima,
            COUNT(*) as lecturas
        FROM mqtt_data 
        WHERE dispositivo_id IN ($placeholders)
        AND timestamp >= DATE_SUB(NOW(), INTERVAL $intervalo_sql)
        GROUP BY DATE_FORMAT(timestamp, '$formato_fecha')
        ORDER BY timestamp
        LIMIT $limite
    ");
    $stmt->execute($codigos_dispositivos);
    $datos_grafica = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener últimas lecturas de todos los dispositivos del cliente
if ($tiene_dispositivos && !empty($codigos_dispositivos)) {
    $stmt = $pdo->prepare("
        SELECT 
            m.dispositivo_id,
            m.sensor,
            m.valor,
            m.timestamp,
            d.nombre as dispositivo_nombre,
            d.tipo as dispositivo_tipo
        FROM mqtt_data m
        LEFT JOIN dispositivos d ON m.dispositivo_id = d.codigo
        WHERE m.dispositivo_id IN ($placeholders)
        AND d.cliente_id = ?
        ORDER BY m.timestamp DESC 
        LIMIT 20
    ");
    $stmt->execute(array_merge($codigos_dispositivos, [$_SESSION['user_id']]));
    $ultimas_lecturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $ultimas_lecturas = [];
}

// Colores para los dispositivos
$colors = [
    '#3b82f6',  // Azul
    '#10b981',  // Verde
    '#f59e0b',  // Amarillo
    '#ef4444',  // Rojo
    '#8b5cf6',  // Morado
    '#06b6d4',  // Cyan
    '#ec4899',  // Rosa
    '#84cc16',  // Verde Lima
];

// Generar colores para cada dispositivo
$colores_dispositivos = [];
foreach ($dispositivos as $index => $dispositivo) {
    $colores_dispositivos[$dispositivo['id']] = $colors[$index % count($colors)];
}

// Preparar datos para las gráficas
$grafica_labels = [];
$grafica_temperatura = [];
$grafica_humedad = [];
$grafica_temp_min = [];
$grafica_temp_max = [];
$grafica_hum_min = [];
$grafica_hum_max = [];

foreach ($datos_grafica as $dato) {
    $grafica_labels[] = $dato['periodo'];
    $grafica_temperatura[] = $dato['temperatura'] !== null ? (float)$dato['temperatura'] : null;
    $grafica_humedad[] = $dato['humedad'] !== null ? (float)$dato['humedad'] : null;
    $grafica_temp_min[] = $dato['temp_minima'] !== null ? (float)$dato['temp_minima'] : null;
    $grafica_temp_max[] = $dato['temp_maxima'] !== null ? (float)$dato['temp_maxima'] : null;
    $grafica_hum_min[] = $dato['hum_minima'] !== null ? (float)$dato['hum_minima'] : null;
    $grafica_hum_max[] = $dato['hum_maxima'] !== null ? (float)$dato['hum_maxima'] : null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Cliente - Sistema IoT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --cliente-primary: #3b82f6;
            --cliente-secondary: #1d4ed8;
            --cliente-success: #10b981;
            --cliente-warning: #f59e0b;
            --cliente-danger: #ef4444;
            --cliente-light: #f8fafc;
            --cliente-dark: #1e293b;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--cliente-dark);
        }
        
        .cliente-header {
            background: linear-gradient(135deg, var(--cliente-primary), var(--cliente-secondary));
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
            pointer-events: none;
        }

        .cliente-header .container {
            position: relative;
            z-index: 1;
        }
        
        .welcome-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-left: 5px solid var(--cliente-primary);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease;
        }
        
        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--cliente-primary), transparent);
            border-radius: 0 0 0 100%;
            opacity: 0.1;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            height: 100%;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--cliente-primary);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        
        .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            margin: 0.5rem 0;
            background: linear-gradient(135deg, var(--cliente-primary), var(--cliente-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .device-card {
            transition: all 0.3s ease;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            border: none;
            height: 100%;
            background: white;
        }
        
        .device-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .device-card-header {
            padding: 1.25rem;
            border-bottom: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .device-status-online {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #10b981;
            animation: pulse 2s infinite;
            box-shadow: 0 0 8px #10b981;
        }
        
        .device-status-offline {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ef4444;
            box-shadow: 0 0 8px #ef4444;
        }
        
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        .chart-container {
            height: 400px;
            position: relative;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .nav-cliente {
            background: white;
            border-radius: 12px;
            padding: 0.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .nav-cliente .nav-link {
            color: #64748b;
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .nav-cliente .nav-link:hover {
            background: #f1f5f9;
            color: var(--cliente-primary);
        }
        
        .nav-cliente .nav-link.active {
            background: linear-gradient(135deg, var(--cliente-primary), var(--cliente-secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .time-selector {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .time-range-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: #64748b;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .time-range-btn:hover {
            background: #f1f5f9;
            border-color: var(--cliente-primary);
            color: var(--cliente-primary);
        }
        
        .time-range-btn.active {
            background: var(--cliente-primary);
            border-color: var(--cliente-primary);
            color: white;
        }
        
        .data-table {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            background: white;
        }
        
        .data-table thead th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #475569;
            padding: 1rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tbody tr {
            transition: background-color 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .footer-cliente {
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 2rem 0;
            margin-top: 3rem;
            color: #64748b;
            text-align: center;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--cliente-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .sensor-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .sensor-temp {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .sensor-hum {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .last-update {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .protocol-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .protocol-mqtt {
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            color: white;
        }
        
        .protocol-http {
            background: linear-gradient(135deg, #0984e3, #74b9ff);
            color: white;
        }
        
        .chart-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .rango-actual {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 600;
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
                        <div class="user-avatar">
                            <?= strtoupper(substr($cliente['full_name'] ?? $cliente['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <h1 class="mb-1 h3 fw-bold">Panel del Cliente IoT</h1>
                            <p class="mb-0 opacity-90">
                                <i class="bi bi-person-fill me-1"></i>
                                Bienvenido, <strong><?= htmlspecialchars($cliente['full_name'] ?? $cliente['username']) ?></strong>
                                <span class="mx-2">•</span>
                                <i class="bi bi-shield-check me-1"></i>
                                ID: CL-<?= str_pad($_SESSION['user_id'], 4, '0', STR_PAD_LEFT) ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <div class="d-flex flex-column align-items-end gap-2">
                        <div class="d-flex gap-2">
                            <a href="../logout.php" class="btn btn-outline-light btn-sm">
                                <i class="bi bi-box-arrow-right me-1"></i> Cerrar Sesión
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Mensaje de bienvenida -->
        <div class="welcome-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-3 text-dark fw-bold">
                        <i class="bi bi-house-door-fill me-2" style="color: var(--cliente-primary);"></i>
                        ¡Bienvenido a tu Centro de Control IoT!
                    </h3>
                    <p class="text-muted mb-0">
                        Aquí puedes monitorear tus dispositivos IoT y visualizar los datos históricos 
                        de temperatura y humedad. Selecciona el rango de tiempo que deseas analizar.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <?php if ($tiene_dispositivos): ?>
                    <div class="alert alert-success d-inline-flex align-items-center mb-0">
                        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                        <div>
                            <strong class="d-block fs-4"><?= count($dispositivos) ?></strong>
                            <small class="d-block">Dispositivos activos</small>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning d-inline-flex align-items-center mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div>
                            <strong class="d-block">Sin dispositivos</strong>
                            <small class="d-block">Contacta con soporte técnico</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Selector de rango de tiempo -->
        <div class="time-selector">
            <h5 class="mb-3">
                <i class="bi bi-calendar-range me-2" style="color: var(--cliente-primary);"></i>
                Selecciona el Rango de Tiempo
            </h5>
            <div class="d-flex flex-wrap gap-2">
                <a href="?rango=hora" class="time-range-btn <?= $rango_seleccionado == 'hora' ? 'active' : '' ?>">
                    <i class="bi bi-clock me-1"></i> Última Hora
                </a>
                <a href="?rango=dia" class="time-range-btn <?= $rango_seleccionado == 'dia' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-day me-1"></i> Último Día
                </a>
                <a href="?rango=semana" class="time-range-btn <?= $rango_seleccionado == 'semana' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-week me-1"></i> Última Semana
                </a>
                <a href="?rango=mes" class="time-range-btn <?= $rango_seleccionado == 'mes' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-month me-1"></i> Último Mes
                </a>
                <a href="?rango=ano" class="time-range-btn <?= $rango_seleccionado == 'ano' ? 'active' : '' ?>">
                    <i class="bi bi-calendar me-1"></i> Último Año
                </a>
                <a href="?rango=2anos" class="time-range-btn <?= $rango_seleccionado == '2anos' ? 'active' : '' ?>">
                    <i class="bi bi-calendar2 me-1"></i> Últimos 2 Años
                </a>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Datos tomados cada 30 segundos. Rango actual: 
                    <strong>
                        <?php 
                        switch($rango_seleccionado) {
                            case 'hora': echo 'Última hora'; break;
                            case 'dia': echo 'Último día'; break;
                            case 'semana': echo 'Última semana'; break;
                            case 'mes': echo 'Último mes'; break;
                            case 'ano': echo 'Último año'; break;
                            case '2anos': echo 'Últimos 2 años'; break;
                            default: echo 'Último día';
                        }
                        ?>
                    </strong>
                </small>
            </div>
        </div>

        <?php if (!$tiene_dispositivos): ?>
        <!-- Estado vacío -->
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-cpu"></i>
            </div>
            <h4 class="mb-3">No tienes dispositivos asignados</h4>
            <p class="text-muted mb-4">
                Actualmente no hay dispositivos asignados a tu cuenta. 
                Contacta con el servicio técnico para que te asignen dispositivos IoT.
            </p>
            <a href="../tecnico/gestionar_clientes.php" class="btn btn-primary">
                <i class="bi bi-headset me-1"></i> Contactar Soporte
            </a>
        </div>
        <?php else: ?>

        <!-- Estadísticas según el rango -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--cliente-primary);">
                        <i class="bi bi-cpu-fill"></i>
                    </div>
                    <div class="stat-value"><?= $estadisticas['total_dispositivos'] ?></div>
                    <div class="stat-label">Dispositivos Activos</div>
                    <small class="text-muted d-block mt-2">
                        <?php 
                        switch($rango_seleccionado) {
                            case 'hora': echo 'Última hora'; break;
                            case 'dia': echo 'Último día'; break;
                            case 'semana': echo 'Última semana'; break;
                            case 'mes': echo 'Último mes'; break;
                            case 'ano': echo 'Último año'; break;
                            case '2anos': echo 'Últimos 2 años'; break;
                        }
                        ?>
                    </small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--cliente-danger);">
                        <i class="bi bi-thermometer-half"></i>
                    </div>
                    <div class="stat-value">
                        <?= $estadisticas['temp_promedio'] ? number_format($estadisticas['temp_promedio'], 1) . '°C' : '--' ?>
                    </div>
                    <div class="stat-label">Temp. Promedio</div>
                    <small class="text-muted d-block mt-2">
                        Rango: <?= $estadisticas['temp_minima'] ? number_format($estadisticas['temp_minima'], 1) . '°' : '--' ?> 
                        - <?= $estadisticas['temp_maxima'] ? number_format($estadisticas['temp_maxima'], 1) . '°' : '--' ?>
                    </small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--cliente-primary);">
                        <i class="bi bi-droplet-half"></i>
                    </div>
                    <div class="stat-value">
                        <?= $estadisticas['hum_promedio'] ? number_format($estadisticas['hum_promedio'], 1) . '%' : '--' ?>
                    </div>
                    <div class="stat-label">Hum. Promedio</div>
                    <small class="text-muted d-block mt-2">
                        Rango: <?= $estadisticas['hum_minima'] ? number_format($estadisticas['hum_minima'], 1) . '%' : '--' ?> 
                        - <?= $estadisticas['hum_maxima'] ? number_format($estadisticas['hum_maxima'], 1) . '%' : '--' ?>
                    </small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--cliente-warning);">
                        <i class="bi bi-database-fill"></i>
                    </div>
                    <div class="stat-value"><?= number_format($estadisticas['total_datos']) ?></div>
                    <div class="stat-label">Lecturas Totales</div>
                    <small class="text-muted d-block mt-2">
                        <?php if ($estadisticas['primera_lectura']): ?>
                        Desde: <?= date('d/m H:i', strtotime($estadisticas['primera_lectura'])) ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Gráficas principales -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="chart-container">
                    <div class="chart-controls">
                        <h5>
                            <i class="bi bi-thermometer-half me-2" style="color: var(--cliente-danger);"></i>
                            Temperatura - Histórico
                        </h5>
                        <div class="rango-actual">
                            <?php 
                            $rango_texto = [
                                'hora' => 'Última hora (por minuto)',
                                'dia' => 'Último día (por hora)',
                                'semana' => 'Última semana (por día)',
                                'mes' => 'Último mes (por día)',
                                'ano' => 'Último año (por mes)',
                                '2anos' => 'Últimos 2 años (por mes)'
                            ];
                            echo $rango_texto[$rango_seleccionado] ?? 'Último día (por hora)';
                            ?>
                        </div>
                    </div>
                    <canvas id="tempChart"></canvas>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="chart-container">
                    <div class="chart-controls">
                        <h5>
                            <i class="bi bi-droplet-half me-2" style="color: var(--cliente-primary);"></i>
                            Humedad - Histórico
                        </h5>
                        <div class="rango-actual">
                            <?php echo $rango_texto[$rango_seleccionado] ?? 'Último día (por hora)'; ?>
                        </div>
                    </div>
                    <canvas id="humChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Dispositivos principales -->
        <div class="row mb-4">
            <div class="col-md-12 mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">
                        <i class="bi bi-cpu me-2" style="color: var(--cliente-primary);"></i>
                        Mis Dispositivos
                    </h4>
                    <span class="badge bg-light text-dark">
                        <?= count($dispositivos) ?> dispositivo<?= count($dispositivos) !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
            
            <?php foreach ($dispositivos as $index => $dispositivo): 
                $color = $colores_dispositivos[$dispositivo['id']] ?? '#6c757d';
                $en_linea = $dispositivo['en_linea'] ?? false;
            ?>
            <div class="col-md-4 mb-3">
                <div class="device-card">
                    <div class="card h-100">
                        <div class="device-card-header" style="background-color: <?= $color ?>; color: white;">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-cpu me-2"></i>
                                <span class="device-name"><?= htmlspecialchars($dispositivo['nombre']) ?></span>
                                <span class="protocol-badge protocol-<?= strtolower($dispositivo['protocolo']) ?>">
                                    <?= $dispositivo['protocolo'] ?>
                                </span>
                            </div>
                            <div class="<?= $en_linea ? 'device-status-online' : 'device-status-offline' ?>" 
                                 title="<?= $en_linea ? 'En línea' : 'Offline' ?>">
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <small class="text-muted d-block">
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($dispositivo['ubicacion']) ?>
                                </small>
                                <small class="text-muted d-block">
                                    <i class="bi bi-tag"></i> <?= htmlspecialchars($dispositivo['tipo']) ?>
                                </small>
                                <?php if(!empty($dispositivo['codigo'])): ?>
                                <small class="text-muted d-block">
                                    <i class="bi bi-hash"></i> <?= htmlspecialchars($dispositivo['codigo']) ?>
                                </small>
                                <?php endif; ?>
                                <?php if(!empty($dispositivo['fecha_instalacion'])): ?>
                                <small class="text-muted d-block">
                                    <i class="bi bi-calendar"></i> Instalado: <?= date('d/m/Y', strtotime($dispositivo['fecha_instalacion'])) ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-3">
                                <a href="ver_dispositivo.php?id=<?= $dispositivo['id'] ?>&rango=<?= $rango_seleccionado ?>" 
                                   class="btn btn-sm w-100" style="background-color: <?= $color ?>; color: white;">
                                    <i class="bi bi-graph-up me-1"></i> Ver Datos Históricos
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Últimas lecturas -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-clock-history me-2"></i>
                                Últimas Lecturas
                            </h5>
                            <span class="badge bg-info">
                                <i class="bi bi-clock me-1"></i>
                                <?= count($ultimas_lecturas) ?> lecturas recientes
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table data-table">
                                <thead>
                                    <tr>
                                        <th>Dispositivo</th>
                                        <th>Sensor</th>
                                        <th>Valor</th>
                                        <th>Fecha/Hora</th>
                                        <th>Hace</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ultimas_lecturas)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No hay lecturas recientes
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($ultimas_lecturas as $lectura): 
                                            $tiempo = time() - strtotime($lectura['timestamp']);
                                            $color_dispositivo = '#6c757d';
                                            foreach ($dispositivos as $disp) {
                                                if ($disp['codigo'] == $lectura['dispositivo_id']) {
                                                    $color_dispositivo = $colores_dispositivos[$disp['id']] ?? '#6c757d';
                                                    break;
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div style="width: 12px; height: 12px; background-color: <?= $color_dispositivo ?>; border-radius: 50%; margin-right: 8px;"></div>
                                                    <?= htmlspecialchars($lectura['dispositivo_nombre'] ?? $lectura['dispositivo_id']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (strpos(strtolower($lectura['sensor']), 'temp') !== false): ?>
                                                <span class="sensor-badge sensor-temp">
                                                    <i class="bi bi-thermometer-half me-1"></i>
                                                    <?= htmlspecialchars($lectura['sensor']) ?>
                                                </span>
                                                <?php elseif (strpos(strtolower($lectura['sensor']), 'hum') !== false): ?>
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
                                            <td>
                                                <?= date('H:i:s', strtotime($lectura['timestamp'])) ?>
                                                <br>
                                                <small class="last-update">
                                                    <?= date('d/m/Y', strtotime($lectura['timestamp'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if($tiempo < 60): ?>
                                                <span class="badge bg-success">
                                                    <?= $tiempo ?> seg
                                                </span>
                                                <?php elseif($tiempo < 3600): ?>
                                                <span class="badge bg-warning">
                                                    <?= floor($tiempo/60) ?> min
                                                </span>
                                                <?php elseif($tiempo < 86400): ?>
                                                <span class="badge bg-info">
                                                    <?= floor($tiempo/3600) ?> h
                                                </span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?= floor($tiempo/86400) ?> d
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
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer del panel -->
        <footer class="footer-cliente">
            <div class="container">
                <p class="mb-2">
                    <i class="bi bi-shield-check me-1"></i>
                    Panel de Cliente - Sistema IoT © <?= date('Y') ?>
                </p>
                <small class="text-muted">
                    Último acceso: <?= date('d/m/Y H:i:s') ?>
                    <?php if ($tiene_dispositivos && !empty($estadisticas['ultima_lectura'])): ?>
                    • Última lectura: <?= date('H:i:s', strtotime($estadisticas['ultima_lectura'])) ?>
                    <?php endif; ?>
                    <span id="data-info" class="ms-2">
                        • Datos agrupados por: 
                        <?php 
                        switch($agrupamiento) {
                            case 'MINUTE': echo 'minuto'; break;
                            case 'HOUR': echo 'hora'; break;
                            case 'DAY': echo 'día'; break;
                            case 'MONTH': echo 'mes'; break;
                        }
                        ?>
                    </span>
                </small>
            </div>
        </footer>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Variables globales
    let tempChart = null;
    let humChart = null;
    
    // Datos para las gráficas
    const graficaData = {
        labels: <?= json_encode($grafica_labels) ?>,
        temperatura: <?= json_encode($grafica_temperatura) ?>,
        humedad: <?= json_encode($grafica_humedad) ?>,
        tempMin: <?= json_encode($grafica_temp_min) ?>,
        tempMax: <?= json_encode($grafica_temp_max) ?>,
        humMin: <?= json_encode($grafica_hum_min) ?>,
        humMax: <?= json_encode($grafica_hum_max) ?>
    };
    
    // Obtener el rango seleccionado
    const rangoSeleccionado = '<?= $rango_seleccionado ?>';
    
    // Configurar opciones de gráfica según el rango
    function getChartOptions(chartType) {
        const esTemperatura = chartType === 'temperature';
        const labelY = esTemperatura ? 'Temperatura (°C)' : 'Humedad (%)';
        const color = esTemperatura ? '#ef4444' : '#3b82f6';
        
        let xAxisConfig = {
            grid: {
                display: true,
                color: 'rgba(0,0,0,0.05)'
            },
            title: {
                display: true,
                text: 'Tiempo'
            }
        };
        
        // Configurar ticks del eje X según el rango
        switch(rangoSeleccionado) {
            case 'hora':
                xAxisConfig.ticks = {
                    maxRotation: 45,
                    minRotation: 45,
                    callback: function(value, index) {
                        // Mostrar cada 5 minutos
                        return index % 5 === 0 ? this.getLabelForValue(value) : '';
                    }
                };
                break;
            case 'dia':
                xAxisConfig.ticks = {
                    maxRotation: 45,
                    minRotation: 45,
                    callback: function(value, index) {
                        // Mostrar cada 4 horas
                        return index % 4 === 0 ? this.getLabelForValue(value) : '';
                    }
                };
                break;
            case 'semana':
            case 'mes':
                xAxisConfig.ticks = {
                    maxRotation: 45,
                    minRotation: 45,
                    callback: function(value, index) {
                        // Mostrar cada 2 días para semana, cada 5 días para mes
                        const modulo = rangoSeleccionado === 'semana' ? 2 : 5;
                        return index % modulo === 0 ? this.getLabelForValue(value) : '';
                    }
                };
                break;
            case 'ano':
            case '2anos':
                xAxisConfig.ticks = {
                    maxRotation: 45,
                    minRotation: 45,
                    callback: function(value, index) {
                        // Mostrar cada mes
                        return index % 1 === 0 ? this.getLabelForValue(value) : '';
                    }
                };
                break;
        }
        
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
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
                                label += esTemperatura ? '°C' : '%';
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: xAxisConfig,
                y: {
                    beginAtZero: !esTemperatura,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    title: {
                        display: true,
                        text: labelY
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        };
    }
    
    // Función para inicializar gráfica de temperatura
    function inicializarGraficaTemperatura() {
        const ctx = document.getElementById('tempChart')?.getContext('2d');
        if (ctx) {
            // Destruir gráfica existente
            if (tempChart) {
                tempChart.destroy();
            }
            
            const datasets = [];
            
            // Dataset para temperatura promedio
            datasets.push({
                label: 'Temperatura Promedio',
                data: graficaData.temperatura,
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 3,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#ef4444',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            });
            
            // Dataset para temperatura mínima (solo si hay datos)
            if (graficaData.tempMin.some(val => val !== null)) {
                datasets.push({
                    label: 'Temperatura Mínima',
                    data: graficaData.tempMin,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 2,
                    pointHoverRadius: 4
                });
            }
            
            // Dataset para temperatura máxima (solo si hay datos)
            if (graficaData.tempMax.some(val => val !== null)) {
                datasets.push({
                    label: 'Temperatura Máxima',
                    data: graficaData.tempMax,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 2,
                    pointHoverRadius: 4
                });
            }
            
            tempChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: graficaData.labels,
                    datasets: datasets
                },
                options: getChartOptions('temperature')
            });
        }
    }
    
    // Función para inicializar gráfica de humedad
    function inicializarGraficaHumedad() {
        const ctx = document.getElementById('humChart')?.getContext('2d');
        if (ctx) {
            // Destruir gráfica existente
            if (humChart) {
                humChart.destroy();
            }
            
            const datasets = [];
            
            // Dataset para humedad promedio
            datasets.push({
                label: 'Humedad Promedio',
                data: graficaData.humedad,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 3,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            });
            
            // Dataset para humedad mínima (solo si hay datos)
            if (graficaData.humMin.some(val => val !== null)) {
                datasets.push({
                    label: 'Humedad Mínima',
                    data: graficaData.humMin,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 2,
                    pointHoverRadius: 4
                });
            }
            
            // Dataset para humedad máxima (solo si hay datos)
            if (graficaData.humMax.some(val => val !== null)) {
                datasets.push({
                    label: 'Humedad Máxima',
                    data: graficaData.humMax,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 2,
                    pointHoverRadius: 4
                });
            }
            
            humChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: graficaData.labels,
                    datasets: datasets
                },
                options: getChartOptions('humidity')
            });
        }
    }
    
    // Función para exportar gráficas
    function exportChart(chartType) {
        const chart = chartType === 'temperature' ? tempChart : humChart;
        if (!chart) return;
        
        const link = document.createElement('a');
        link.download = `grafica_${chartType}_${rangoSeleccionado}_${new Date().toISOString().slice(0,10)}.png`;
        link.href = chart.toBase64Image();
        link.click();
    }
    
    // Inicializar gráficas cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        inicializarGraficaTemperatura();
        inicializarGraficaHumedad();
        
        // Agregar botones de exportación si hay gráficas
        if (tempChart) {
            const tempControls = document.querySelector('#tempChart').closest('.chart-container').querySelector('.chart-controls');
            if (tempControls) {
                const exportBtn = document.createElement('button');
                exportBtn.className = 'btn btn-sm btn-outline-secondary';
                exportBtn.innerHTML = '<i class="bi bi-download me-1"></i> Exportar';
                exportBtn.onclick = () => exportChart('temperature');
                tempControls.appendChild(exportBtn);
            }
        }
        
        if (humChart) {
            const humControls = document.querySelector('#humChart').closest('.chart-container').querySelector('.chart-controls');
            if (humControls) {
                const exportBtn = document.createElement('button');
                exportBtn.className = 'btn btn-sm btn-outline-secondary';
                exportBtn.innerHTML = '<i class="bi bi-download me-1"></i> Exportar';
                exportBtn.onclick = () => exportChart('humidity');
                humControls.appendChild(exportBtn);
            }
        }
        
        // Efecto de hover en tarjetas
        document.querySelectorAll('.device-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Efecto de hover en botones de rango de tiempo
        document.querySelectorAll('.time-range-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                if (!this.classList.contains('active')) {
                    this.style.transform = 'translateY(-2px)';
                }
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    });
    
    // Actualizar automáticamente la página cada 5 minutos para datos frescos
    setTimeout(function() {
        location.reload();
    }, 5 * 60 * 1000); // 5 minutos
    
    // Mostrar hora actual en tiempo real
    function updateCurrentTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('es-ES');
        
        // Buscar o crear elemento de hora
        let timeElement = document.getElementById('current-time');
        if (!timeElement) {
            timeElement = document.createElement('small');
            timeElement.id = 'current-time';
            timeElement.className = 'text-muted ms-2';
            document.querySelector('.footer-cliente .container').appendChild(timeElement);
        }
        
        timeElement.innerHTML = `<i class="bi bi-clock"></i> ${timeString}`;
        
        // Actualizar cada segundo
        setTimeout(updateCurrentTime, 1000);
    }
    
    // Iniciar reloj
    updateCurrentTime();
    </script>
</body>
</html>