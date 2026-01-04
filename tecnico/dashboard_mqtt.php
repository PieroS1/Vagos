<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tecnico') {
    header('Location: /iot-system/public/index.php');
    exit();
}
require_once __DIR__ . '/../config/db.php';

// Procesar eliminación de dispositivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_dispositivo'])) {
    $dispositivo_id = $_POST['dispositivo_id'];
    $delete_reason = $_POST['delete_reason'] ?? '';
    
    try {
        // Contar datos antes de eliminar
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ?");
        $stmt->execute([$dispositivo_id]);
        $total_datos = $stmt->fetchColumn();
        
        // Iniciar transacción
        $pdo->beginTransaction();
        
        // Eliminar datos MQTT del dispositivo
        $stmt = $pdo->prepare("DELETE FROM mqtt_data WHERE dispositivo_id = ?");
        $stmt->execute([$dispositivo_id]);
        
        // Registrar en logs (si la tabla existe)
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_eliminacion (
                    dispositivo_id,
                    usuario_id,
                    fecha_eliminacion,
                    motivo,
                    datos_eliminados,
                    ip_address,
                    user_agent
                ) VALUES (?, ?, NOW(), ?, ?, ?, ?)
            ");
            $stmt->execute([
                $dispositivo_id,
                $_SESSION['user_id'],
                $delete_reason,
                $total_datos,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Si la tabla no existe, continuar sin error
            error_log("Tabla logs_eliminacion no existe: " . $e->getMessage());
        }
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "✅ Dispositivo '{$dispositivo_id}' eliminado exitosamente. Se eliminaron {$total_datos} registros.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "❌ Error al eliminar el dispositivo: " . $e->getMessage();
    }
}

// Obtener todos los dispositivos únicos
$stmt = $pdo->query("SELECT DISTINCT dispositivo_id FROM mqtt_data ORDER BY dispositivo_id");
$dispositivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Colores para cada dispositivo
$colors = [
    'rgb(255, 99, 132)',   // Rojo
    'rgb(54, 162, 235)',   // Azul
    'rgb(255, 205, 86)',   // Amarillo
    'rgb(75, 192, 192)',   // Verde azulado
    'rgb(153, 102, 255)',  // Morado
    'rgb(255, 159, 64)',   // Naranja
    'rgb(201, 203, 207)',  // Gris
    'rgb(220, 53, 69)',    // Rojo Bootstrap
    'rgb(40, 167, 69)',    // Verde Bootstrap
    'rgb(23, 162, 184)',   // Cyan Bootstrap
];

// Obtener estadísticas globales
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_datos,
        COUNT(DISTINCT dispositivo_id) as total_dispositivos,
        COUNT(DISTINCT DATE(timestamp)) as total_dias,
        MIN(timestamp) as fecha_inicio,
        MAX(timestamp) as fecha_fin,
        AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
        AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio
    FROM mqtt_data
");
$global_stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard MQTT - Sistema IoT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --info-color: #118ab2;
        }
        
        .sensor-card { 
            transition: all 0.3s ease;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: none;
        }
        .sensor-card:hover { 
            transform: translateY(-8px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .badge-online { 
            background: linear-gradient(135deg, #06d6a0, #04b486);
            color: white;
        }
        .badge-offline { 
            background: linear-gradient(135deg, #ef476f, #d90429);
            color: white;
        }
        .device-badge {
            font-size: 0.8em;
            margin: 2px;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .device-badge:hover {
            transform: scale(1.05);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            background: white;
            border-radius: 10px;
            padding: 15px;
        }
        .chart-container-sm {
            position: relative;
            height: 180px;
            width: 100%;
        }
        .time-filter .btn {
            border-radius: 20px;
            margin: 2px;
            padding: 5px 15px;
            font-size: 0.85rem;
        }
        .time-filter .btn.active {
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .action-buttons {
            position: absolute;
            top: 15px;
            right: 15px;
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 10;
        }
        .sensor-card:hover .action-buttons {
            opacity: 1;
        }
        .modal-danger .modal-header {
            background: linear-gradient(135deg, #ef476f, #d90429);
            color: white;
            border-radius: 0;
        }
        .modal-danger .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .btn-delete {
            position: relative;
            overflow: hidden;
            border-radius: 8px;
            font-weight: 500;
            padding: 8px 20px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .stat-card.temp { border-left-color: var(--danger-color); }
        .stat-card.hum { border-left-color: var(--info-color); }
        .stat-card.device { border-left-color: var(--success-color); }
        .stat-card.data { border-left-color: var(--warning-color); }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
            color: #2b2d42;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .custom-alert {
            border-radius: 10px;
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .device-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0;
            padding: 15px;
            margin-bottom: 0;
        }
        
        .data-table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .data-table thead th {
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
            color: #495057;
            padding: 12px 15px;
        }
        
        .data-table tbody tr {
            transition: background 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .nav-tabs .nav-link {
            border-radius: 8px 8px 0 0;
            font-weight: 500;
            padding: 10px 20px;
        }
        
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #4361ee, #3a56d4);
            color: white;
            border-color: #4361ee;
        }
        
        .real-time-badge {
            animation: pulse 2s infinite;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.8rem;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(67, 97, 238, 0); }
            100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #4361ee;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        
        .search-box {
            border-radius: 25px;
            padding-left: 40px;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .search-box:focus {
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 10;
        }
        
        .export-btn {
            background: linear-gradient(135deg, #06d6a0, #04b486);
            border: none;
            color: white;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(6, 214, 160, 0.3);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php 
    $current_page = 'dashboard';
    include '../core/header.php'; 
    ?>

    <div class="container-fluid mt-4">
        <!-- Mensajes de éxito/error -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show custom-alert" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show custom-alert" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $_SESSION['error_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); endif; ?>

        <!-- Header con título y búsqueda -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-2"><i class="bi bi-speedometer2 me-2"></i>Dashboard MQTT - Monitoreo IoT</h2>
                        <div class="d-flex align-items-center">
                            <span class="real-time-badge bg-primary me-3">
                                <i class="bi bi-clock me-1"></i>Tiempo Real (30s)
                            </span>
                            <div>
                                <small class="text-muted">
                                    <i class="bi bi-cpu me-1"></i><?= count($dispositivos) ?> dispositivos activos
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="position-relative me-3">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="form-control search-box" id="searchDevices" 
                                   placeholder="Buscar dispositivo..." style="width: 250px;">
                        </div>
                        <button class="btn btn-outline-danger me-2" data-bs-toggle="modal" data-bs-target="#gestionDispositivosModal">
                            <i class="bi bi-sliders"></i> Gestionar
                        </button>
                        <button class="btn export-btn" onclick="exportAllData()">
                            <i class="bi bi-download me-1"></i> Exportar
                        </button>
                    </div>
                </div>
                
                <!-- Badges de dispositivos -->
                <div class="mb-3 p-3 bg-light rounded">
                    <h6 class="mb-2"><i class="bi bi-tags me-2"></i>Dispositivos Conectados:</h6>
                    <div id="deviceBadges">
                        <?php foreach ($dispositivos as $index => $dispositivo): ?>
                            <span class="badge device-badge" 
                                  style="background-color: <?= $colors[$index % count($colors)] ?>" 
                                  data-device="<?= htmlspecialchars($dispositivo) ?>">
                                <?= htmlspecialchars($dispositivo) ?>
                                <span class="badge bg-dark ms-1" id="badge-count-<?= md5($dispositivo) ?>">
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
                                    $stmt->execute([$dispositivo]);
                                    echo $stmt->fetchColumn();
                                    ?>
                                </span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadísticas principales -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card device">
                    <div class="stat-icon text-success">
                        <i class="bi bi-cpu"></i>
                    </div>
                    <div class="stat-label">Dispositivos Activos</div>
                    <div class="stat-value"><?= count($dispositivos) ?></div>
                    <small class="text-muted">Última hora</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card temp">
                    <div class="stat-icon text-danger">
                        <i class="bi bi-thermometer-sun"></i>
                    </div>
                    <div class="stat-label">Temp. Promedio</div>
                    <div class="stat-value"><?= $global_stats['temp_promedio'] ? number_format($global_stats['temp_promedio'], 1) . '°C' : '--' ?></div>
                    <small class="text-muted">Global</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card hum">
                    <div class="stat-icon text-info">
                        <i class="bi bi-moisture"></i>
                    </div>
                    <div class="stat-label">Hum. Promedio</div>
                    <div class="stat-value"><?= $global_stats['hum_promedio'] ? number_format($global_stats['hum_promedio'], 1) . '%' : '--' ?></div>
                    <small class="text-muted">Global</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card data">
                    <div class="stat-icon text-warning">
                        <i class="bi bi-database"></i>
                    </div>
                    <div class="stat-label">Total Datos</div>
                    <div class="stat-value"><?= number_format($global_stats['total_datos']) ?></div>
                    <small class="text-muted">
                        <?= $global_stats['total_dias'] ?> días
                    </small>
                </div>
            </div>
        </div>

        <!-- Selector de intervalo de tiempo -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Intervalo de Tiempo</h6>
                            <div class="btn-group time-filter" role="group">
                                <button type="button" class="btn btn-outline-primary active" onclick="changeTimeRange(30, 30)">
                                    <i class="bi bi-clock me-1"></i>30 min (30s)
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="changeTimeRange(60, 60)">
                                    <i class="bi bi-clock-history me-1"></i>1 hora (60s)
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="changeTimeRange(180, 120)">
                                    <i class="bi bi-hourglass-split me-1"></i>3 horas (2min)
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="changeTimeRange(720, 300)">
                                    <i class="bi bi-calendar-day me-1"></i>12 horas (5min)
                                </button>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted" id="timeRangeLabel">
                                <i class="bi bi-info-circle me-1"></i>Mostrando: Últimos 30 minutos con datos cada 30 segundos
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficas combinadas -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-thermometer-half me-2"></i>Temperatura por Dispositivo</h5>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="toggleDataset('tempChart')">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="exportChart('tempChart')">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="tempChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-droplet-half me-2"></i>Humedad por Dispositivo</h5>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="toggleDataset('humChart')">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="exportChart('humChart')">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="humChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjetas de dispositivos -->
        <div class="row mb-4" id="deviceCards">
            <?php foreach ($dispositivos as $index => $dispositivo): 
                $color = $colors[$index % count($colors)];
                $bgColor = str_replace('rgb', 'rgba', $color) . ',0.1)';
                
                // Obtener estadísticas del dispositivo
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_datos,
                        SUM(CASE WHEN sensor = 'temperatura' THEN 1 ELSE 0 END) as datos_temp,
                        SUM(CASE WHEN sensor = 'humedad' THEN 1 ELSE 0 END) as datos_hum,
                        MIN(CASE WHEN sensor = 'temperatura' THEN valor END) as min_temp,
                        MAX(CASE WHEN sensor = 'temperatura' THEN valor END) as max_temp,
                        MIN(CASE WHEN sensor = 'humedad' THEN valor END) as min_hum,
                        MAX(CASE WHEN sensor = 'humedad' THEN valor END) as max_hum
                    FROM mqtt_data 
                    WHERE dispositivo_id = ?
                ");
                $stmt->execute([$dispositivo]);
                $stats = $stmt->fetch();
            ?>
            <div class="col-md-3 mb-3 device-card" data-device="<?= htmlspecialchars($dispositivo) ?>">
                <div class="card sensor-card h-100" style="border-top: 4px solid <?= $color ?>">
                    <div class="card-body position-relative">
                        <!-- Botones de acción -->
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-outline-danger" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#confirmDeleteModal"
                                    data-device-id="<?= htmlspecialchars($dispositivo) ?>"
                                    data-device-name="<?= htmlspecialchars($dispositivo) ?>"
                                    onclick="setDeviceToDelete(this)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        
                        <h5 class="card-title mb-3" style="color: <?= $color ?>">
                            <i class="bi bi-cpu me-2"></i><?= htmlspecialchars($dispositivo) ?>
                        </h5>
                        
                        <?php
                        // Última temperatura (últimos 30 segundos)
                        $stmt = $pdo->prepare("SELECT valor FROM mqtt_data WHERE dispositivo_id = ? AND sensor='temperatura' ORDER BY timestamp DESC LIMIT 1");
                        $stmt->execute([$dispositivo]);
                        $temp = $stmt->fetch();
                        
                        // Última humedad (últimos 30 segundos)
                        $stmt = $pdo->prepare("SELECT valor FROM mqtt_data WHERE dispositivo_id = ? AND sensor='humedad' ORDER BY timestamp DESC LIMIT 1");
                        $stmt->execute([$dispositivo]);
                        $hum = $stmt->fetch();
                        
                        // Estado (en línea si ha enviado datos en los últimos 30 segundos)
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 SECOND)");
                        $stmt->execute([$dispositivo]);
                        $online = $stmt->fetchColumn() > 0;
                        ?>
                        
                        <div class="row mb-3">
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="me-2" style="color: <?= $color ?>">
                                        <i class="bi bi-thermometer-half fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Temperatura</div>
                                        <div class="fw-bold fs-5"><?= $temp ? number_format($temp['valor'], 1) . '°C' : '--' ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="me-2" style="color: <?= $color ?>">
                                        <i class="bi bi-droplet-half fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Humedad</div>
                                        <div class="fw-bold fs-5"><?= $hum ? number_format($hum['valor'], 1) . '%' : '--' ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($stats['min_temp'] && $stats['max_temp']): ?>
                        <div class="small mb-2">
                            <span class="badge bg-light text-dark me-1">
                                Min: <?= number_format($stats['min_temp'], 1) ?>°C
                            </span>
                            <span class="badge bg-light text-dark">
                                Max: <?= number_format($stats['max_temp'], 1) ?>°C
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-3 pt-3 border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge <?= $online ? 'badge-online' : 'badge-offline' ?>">
                                    <?php if ($online): ?>
                                        <i class="bi bi-wifi"></i> En línea
                                    <?php else: ?>
                                        <i class="bi bi-wifi-off"></i> Offline
                                    <?php endif; ?>
                                </span>
                                <small class="text-muted">
                                    <?= $stats['total_datos'] ?> datos
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Gráficas individuales -->
        <div class="row mb-4">
            <?php foreach ($dispositivos as $index => $dispositivo): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center" 
                         style="background: linear-gradient(90deg, <?= $colors[$index % count($colors)] ?>20, transparent); 
                                border-left: 4px solid <?= $colors[$index % count($colors)] ?>">
                        <h5 class="mb-0" style="color: <?= $colors[$index % count($colors)] ?>">
                            <i class="bi bi-graph-up me-2"></i><?= htmlspecialchars($dispositivo) ?>
                        </h5>
                        <div>
                            <span class="badge" style="background-color: <?= $colors[$index % count($colors)] ?>">
                                Dispositivo <?= $index + 1 ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Pestañas para gráficas individuales -->
                        <ul class="nav nav-tabs mb-3" id="deviceTabs<?= $index ?>" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" 
                                        data-bs-target="#tempTab<?= $index ?>" type="button">
                                    <i class="bi bi-thermometer-half me-1"></i>Temperatura
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" 
                                        data-bs-target="#humTab<?= $index ?>" type="button">
                                    <i class="bi bi-droplet-half me-1"></i>Humedad
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" 
                                        data-bs-target="#statsTab<?= $index ?>" type="button">
                                    <i class="bi bi-bar-chart-line me-1"></i>Estadísticas
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="tempTab<?= $index ?>">
                                <div class="chart-container-sm">
                                    <canvas id="chart_<?= $dispositivo ?>_temp"></canvas>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="humTab<?= $index ?>">
                                <div class="chart-container-sm">
                                    <canvas id="chart_<?= $dispositivo ?>_hum"></canvas>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="statsTab<?= $index ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card bg-light mb-2">
                                            <div class="card-body py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Datos totales:</span>
                                                    <strong id="totalData_<?= $dispositivo ?>">--</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-light mb-2">
                                            <div class="card-body py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Última lectura:</span>
                                                    <strong id="lastUpdate_<?= $dispositivo ?>">--</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-outline-primary w-100 mt-2" onclick="exportDeviceData('<?= $dispositivo ?>')">
                                    <i class="bi bi-download me-1"></i>Exportar Datos
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Panel de control y tabla de datos -->
        <div class="row">
            <!-- Panel de Control -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header gradient-bg">
                        <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Control de Dispositivos</h5>
                    </div>
                    <div class="card-body">
                        <form id="controlForm">
                            <div class="mb-3">
                                <label class="form-label">Dispositivo:</label>
                                <select class="form-control" id="deviceSelect">
                                    <?php foreach ($dispositivos as $dispositivo): ?>
                                        <option value="<?= htmlspecialchars($dispositivo) ?>"><?= $dispositivo ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Comando:</label>
                                <select class="form-control" id="commandSelect">
                                    <option value="led_on">💡 Encender LED</option>
                                    <option value="led_off">🌙 Apagar LED</option>
                                    <option value="read_sensors">📊 Leer sensores</option>
                                    <option value="reboot">🔄 Reiniciar</option>
                                    <option value="calibrate">⚙️ Calibrar</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Parámetro (opcional):</label>
                                <input type="text" class="form-control" id="parameter" placeholder="Ej: 180">
                            </div>
                            <button type="button" class="btn btn-primary w-100" onclick="sendCommand()">
                                <i class="bi bi-send me-1"></i>Enviar Comando
                            </button>
                        </form>
                        <div class="mt-3">
                            <div class="alert alert-info mb-0" id="commandStatus">
                                <i class="bi bi-info-circle me-1"></i>Listo para enviar comandos
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de datos recientes -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-table me-2"></i>Últimas Lecturas</h5>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="refreshData()">
                                <i class="bi bi-arrow-clockwise"></i> Actualizar
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="exportTableData()">
                                <i class="bi bi-download"></i> CSV
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table data-table" id="sensorTable">
                                <thead>
                                    <tr>
                                        <th>Dispositivo</th>
                                        <th>Sensor</th>
                                        <th>Valor</th>
                                        <th>Fecha/Hora</th>
                                        <th>Topic</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT * FROM mqtt_data ORDER BY timestamp DESC LIMIT 15");
                                    while ($row = $stmt->fetch()):
                                        $colorIndex = array_search($row['dispositivo_id'], $dispositivos);
                                        $color = $colorIndex !== false ? $colors[$colorIndex % count($colors)] : '#6c757d';
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge" style="background-color: <?= $color ?>">
                                                <?= htmlspecialchars($row['dispositivo_id']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($row['sensor']) ?></td>
                                        <td>
                                            <span class="badge" style="background-color: <?= $color ?>">
                                                <?= $row['valor'] ?><?= $row['sensor'] == 'temperatura' ? '°C' : '%' ?>
                                            </span>
                                        </td>
                                        <td><?= date('H:i:s', strtotime($row['timestamp'])) ?></td>
                                        <td><small class="text-muted"><?= $row['topic'] ?></small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar dispositivo -->
    <div class="modal fade modal-danger" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-octagon-fill me-2"></i><strong>¡ADVERTENCIA!</strong> Esta acción no se puede deshacer.
                    </div>
                    
                    <p>¿Estás seguro de que deseas eliminar el dispositivo <strong id="deviceNameToDelete"></strong>?</p>
                    
                    <div id="deviceStatsInfo"></div>
                    
                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <h6><i class="bi bi-info-circle me-2"></i> Esta acción eliminará:</h6>
                            <ul class="mb-0">
                                <li>Todos los datos MQTT del dispositivo</li>
                                <li>Todas las lecturas de sensores almacenadas</li>
                                <li>El historial completo del dispositivo</li>
                            </ul>
                        </div>
                    </div>
                    
                    <form id="deleteDeviceForm" method="POST" action="">
                        <input type="hidden" name="eliminar_dispositivo" value="1">
                        <input type="hidden" name="dispositivo_id" id="deviceIdToDelete">
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                            <label class="form-check-label" for="confirmDelete">
                                Confirmo que comprendo que esta acción es permanente
                            </label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="deleteReason" class="form-label">Motivo de eliminación (opcional):</label>
                            <textarea class="form-control" id="deleteReason" name="delete_reason" rows="2" placeholder="Ej: Dispositivo dañado, fuera de servicio..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancelar
                    </button>
                    <button type="submit" form="deleteDeviceForm" class="btn btn-danger btn-delete" id="confirmDeleteBtn" disabled>
                        <i class="bi bi-trash me-1"></i> Eliminar Dispositivo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de gestión de dispositivos -->
    <div class="modal fade" id="gestionDispositivosModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Gestión de Dispositivos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Lista de dispositivos -->
                        <div class="col-md-6">
                            <h6><i class="bi bi-list-ul me-2"></i>Dispositivos Activos</h6>
                            <div class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($dispositivos as $index => $dispositivo): 
                                    // Obtener información del dispositivo
                                    $stmt = $pdo->prepare("
                                        SELECT 
                                            COUNT(*) as total,
                                            MIN(timestamp) as primer_dato,
                                            MAX(timestamp) as ultimo_dato
                                        FROM mqtt_data 
                                        WHERE dispositivo_id = ?
                                    ");
                                    $stmt->execute([$dispositivo]);
                                    $info = $stmt->fetch();
                                    
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                                    $stmt->execute([$dispositivo]);
                                    $online = $stmt->fetchColumn() > 0;
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <span class="badge" style="background-color: <?= $colors[$index % count($colors)] ?>">
                                                <?= htmlspecialchars($dispositivo) ?>
                                            </span>
                                        </h6>
                                        <small class="<?= $online ? 'text-success' : 'text-danger' ?>">
                                            <?= $online ? '<i class="bi bi-circle-fill"></i>' : '<i class="bi bi-circle"></i>' ?>
                                        </small>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-database me-1"></i><?= $info['total'] ?> datos<br>
                                        <i class="bi bi-clock-history me-1"></i>Último: <?= $info['ultimo_dato'] ? date('H:i', strtotime($info['ultimo_dato'])) : '--' ?><br>
                                        <i class="bi bi-calendar me-1"></i>Primer: <?= $info['primer_dato'] ? date('d/m/Y', strtotime($info['primer_dato'])) : '--' ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Estadísticas y acciones -->
                        <div class="col-md-6">
                            <h6><i class="bi bi-graph-up me-2"></i>Estadísticas Totales</h6>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <ul class="list-unstyled">
                                        <li><i class="bi bi-cpu me-2"></i>Dispositivos: <strong><?= $global_stats['total_dispositivos'] ?></strong></li>
                                        <li><i class="bi bi-database me-2"></i>Total datos: <strong><?= number_format($global_stats['total_datos']) ?></strong></li>
                                        <li><i class="bi bi-calendar me-2"></i>Días activos: <strong><?= $global_stats['total_dias'] ?></strong></li>
                                        <li><i class="bi bi-clock-history me-2"></i>Período: 
                                            <strong><?= $global_stats['fecha_inicio'] ? date('d/m/Y', strtotime($global_stats['fecha_inicio'])) : '--' ?></strong>
                                             a 
                                            <strong><?= $global_stats['fecha_fin'] ? date('d/m/Y', strtotime($global_stats['fecha_fin'])) : '--' ?></strong>
                                        </li>
                                    </ul>
                                    
                                    <hr>
                                    
                                    <h6><i class="bi bi-tools me-2"></i>Acciones:</h6>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
                                            <i class="bi bi-trash me-1"></i> Eliminar Múltiples
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="exportAllData()">
                                            <i class="bi bi-download me-1"></i> Exportar Todos
                                        </button>
                                        <button class="btn btn-outline-warning" onclick="clearOldData()">
                                            <i class="bi bi-broom me-1"></i> Limpiar Antiguos
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para eliminar múltiples dispositivos -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Eliminar Múltiples</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>Selecciona los dispositivos a eliminar:
                    </div>
                    
                    <form id="bulkDeleteForm" method="POST" action="bulk_delete.php">
                        <div class="mb-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($dispositivos as $dispositivo): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="dispositivos[]" value="<?= htmlspecialchars($dispositivo) ?>" id="check_<?= md5($dispositivo) ?>">
                                <label class="form-check-label d-flex justify-content-between" for="check_<?= md5($dispositivo) ?>">
                                    <span><?= htmlspecialchars($dispositivo) ?></span>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ?");
                                    $stmt->execute([$dispositivo]);
                                    $count = $stmt->fetchColumn();
                                    ?>
                                    <span class="badge bg-secondary"><?= $count ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmBulkDelete" required>
                            <label class="form-check-label" for="confirmBulkDelete">
                                Confirmo que deseo eliminar los dispositivos seleccionados
                            </label>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Motivo (opcional):</label>
                            <input type="text" class="form-control" name="delete_reason" placeholder="Motivo de eliminación">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancelar
                    </button>
                    <button type="submit" form="bulkDeleteForm" class="btn btn-danger" id="confirmBulkDeleteBtn" disabled>
                        <i class="bi bi-trash me-1"></i> Eliminar Seleccionados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔥 SCRIPTS COMPLETOS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Variables globales
    let tempChart;
    let humChart;
    let individualCharts = {};
    let currentTimeRange = 30;
    let currentInterval = 30;
    let updateInterval;
    let chartVisibility = {
        tempChart: {},
        humChart: {}
    };

    // Inicialización
    document.addEventListener("DOMContentLoaded", () => {
        loadChartData();
        setupAutoUpdate();
        setupEventListeners();
        updateDeviceBadges();
        
        // Actualizar cada 10 segundos los datos recientes
        setInterval(updateRecentData, 10000);
    });

    function setupEventListeners() {
        // Habilitar botón de eliminar cuando se marca el checkbox
        document.getElementById('confirmDelete').addEventListener('change', function() {
            document.getElementById('confirmDeleteBtn').disabled = !this.checked;
        });

        // Habilitar botón de eliminar múltiples
        document.getElementById('confirmBulkDelete').addEventListener('change', function() {
            document.getElementById('confirmBulkDeleteBtn').disabled = !this.checked;
        });

        // Limpiar modal cuando se cierra
        document.getElementById('confirmDeleteModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('confirmDelete').checked = false;
            document.getElementById('confirmDeleteBtn').disabled = true;
            document.getElementById('deleteReason').value = '';
            document.getElementById('deviceStatsInfo').innerHTML = '';
        });

        // Búsqueda en tiempo real
        document.getElementById('searchDevices').addEventListener('input', searchDevices);
    }

    function searchDevices() {
        const searchTerm = document.getElementById('searchDevices').value.toLowerCase().trim();
        const cards = document.querySelectorAll('.device-card');
        const badges = document.querySelectorAll('.device-badge');
        
        let visibleCount = 0;
        
        cards.forEach(card => {
            const deviceName = card.getAttribute('data-device').toLowerCase();
            const matches = deviceName.includes(searchTerm);
            
            if (matches) {
                card.style.display = 'block';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        badges.forEach(badge => {
            const deviceName = badge.getAttribute('data-device').toLowerCase();
            if (searchTerm === '') {
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = deviceName.includes(searchTerm) ? 'inline-block' : 'none';
            }
        });
    }

    function loadChartData() {
        showLoadingState(true);
        
        fetch(`../api/get-chart-data.php?separated=true&minutes=${currentTimeRange}&interval=${currentInterval}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                updateCombinedCharts(data);
                updateIndividualCharts(data);
                updateCurrentValues(data);
                showLoadingState(false);
            })
            .catch(error => {
                console.error('Error loading chart data:', error);
                showLoadingState(false);
                showError('Error al cargar datos: ' + error.message);
            });
    }

    function updateCombinedCharts(data) {
        // Gráfica de temperatura
        const tempCtx = document.getElementById('tempChart').getContext('2d');
        if (tempChart) tempChart.destroy();
        
        const tempDatasets = data.dispositivos.map((dispositivo, index) => ({
            label: dispositivo,
            data: data.temperatura[index],
            borderColor: getColor(index),
            backgroundColor: getColor(index).replace('rgb', 'rgba').replace(')', ',0.05)'),
            tension: 0.2,
            borderWidth: 2,
            pointRadius: 1,
            pointHoverRadius: 4,
            fill: false,
            hidden: chartVisibility.tempChart[dispositivo] === false
        }));
        
        tempChart = new Chart(tempCtx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: tempDatasets
            },
            options: getChartOptions('Temperatura (°C)')
        });

        // Gráfica de humedad
        const humCtx = document.getElementById('humChart').getContext('2d');
        if (humChart) humChart.destroy();
        
        const humDatasets = data.dispositivos.map((dispositivo, index) => ({
            label: dispositivo,
            data: data.humedad[index],
            borderColor: getColor(index),
            backgroundColor: getColor(index).replace('rgb', 'rgba').replace(')', ',0.05)'),
            tension: 0.2,
            borderWidth: 2,
            pointRadius: 1,
            pointHoverRadius: 4,
            fill: false,
            hidden: chartVisibility.humChart[dispositivo] === false
        }));
        
        humChart = new Chart(humCtx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: humDatasets
            },
            options: getChartOptions('Humedad (%)', 100)
        });
    }

    function updateIndividualCharts(data) {
        data.dispositivos.forEach((dispositivo, index) => {
            // Temperatura individual
            const tempCanvas = document.getElementById(`chart_${dispositivo}_temp`);
            if (tempCanvas) {
                const tempCtx = tempCanvas.getContext('2d');
                if (individualCharts[`${dispositivo}_temp`]) {
                    individualCharts[`${dispositivo}_temp`].destroy();
                }
                
                individualCharts[`${dispositivo}_temp`] = new Chart(tempCtx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Temperatura (°C)',
                            data: data.temperatura[index],
                            borderColor: getColor(index),
                            backgroundColor: getColor(index).replace('rgb', 'rgba').replace(')', ',0.1)'),
                            tension: 0.3,
                            borderWidth: 2,
                            fill: true
                        }]
                    },
                    options: getIndividualChartOptions('Temperatura (°C)')
                });
            }

            // Humedad individual
            const humCanvas = document.getElementById(`chart_${dispositivo}_hum`);
            if (humCanvas) {
                const humCtx = humCanvas.getContext('2d');
                if (individualCharts[`${dispositivo}_hum`]) {
                    individualCharts[`${dispositivo}_hum`].destroy();
                }
                
                individualCharts[`${dispositivo}_hum`] = new Chart(humCtx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Humedad (%)',
                            data: data.humedad[index],
                            borderColor: getColor(index),
                            backgroundColor: getColor(index).replace('rgb', 'rgba').replace(')', ',0.1)'),
                            tension: 0.3,
                            borderWidth: 2,
                            fill: true
                        }]
                    },
                    options: getIndividualChartOptions('Humedad (%)', 100)
                });
            }
        });
    }

    function getChartOptions(title, maxY = null) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 10,
                        font: {
                            size: 11
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.7)',
                    titleFont: { size: 12 },
                    bodyFont: { size: 11 },
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += context.parsed.y.toFixed(1) + (title.includes('Temperatura') ? '°C' : '%');
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: true,
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45,
                        font: { size: 10 },
                        callback: function(value, index, values) {
                            if (index % Math.max(1, Math.floor(values.length / 8)) === 0 || index === values.length - 1) {
                                return this.getLabelForValue(value);
                            }
                            return '';
                        }
                    }
                },
                y: {
                    beginAtZero: title.includes('Humedad'),
                    max: maxY,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    title: {
                        display: true,
                        text: title,
                        font: { size: 12 }
                    },
                    ticks: {
                        font: { size: 10 }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'nearest'
            },
            animation: {
                duration: 300
            }
        };
    }

    function getIndividualChartOptions(title, maxY = null) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                x: { display: false },
                y: {
                    beginAtZero: title.includes('Humedad'),
                    max: maxY,
                    ticks: {
                        font: { size: 9 }
                    }
                }
            },
            elements: {
                point: {
                    radius: 0
                }
            }
        };
    }

    function updateCurrentValues(data) {
        data.dispositivos.forEach((dispositivo, index) => {
            const tempData = data.temperatura[index];
            const humData = data.humedad[index];
            
            // Encontrar el último valor no nulo
            let lastTemp = null;
            let lastHum = null;
            
            for (let i = tempData.length - 1; i >= 0; i--) {
                if (tempData[i] !== null && lastTemp === null) {
                    lastTemp = tempData[i];
                }
                if (humData[i] !== null && lastHum === null) {
                    lastHum = humData[i];
                }
                if (lastTemp !== null && lastHum !== null) break;
            }
            
            // Actualizar estadísticas
            updateDeviceStats(dispositivo, lastTemp, lastHum);
        });
    }

    function updateDeviceStats(deviceId, temp, hum) {
        // Actualizar contador de datos
        fetch(`../api/get-device-stats.php?device=${encodeURIComponent(deviceId)}`)
            .then(res => res.json())
            .then(stats => {
                const badge = document.getElementById(`badge-count-${md5(deviceId)}`);
                if (badge) {
                    badge.textContent = stats.total_datos;
                }
                
                const totalDataElement = document.getElementById(`totalData_${deviceId}`);
                if (totalDataElement) {
                    totalDataElement.textContent = stats.total_datos;
                }
                
                const lastUpdateElement = document.getElementById(`lastUpdate_${deviceId}`);
                if (lastUpdateElement) {
                    lastUpdateElement.textContent = stats.ultimo_dato;
                }
            });
    }

    function updateDeviceBadges() {
        fetch('../api/get-recent-data.php?minutes=30')
            .then(res => res.json())
            .then(data => {
                if (data.dispositivos) {
                    Object.keys(data.dispositivos).forEach(deviceId => {
                        const badge = document.getElementById(`badge-count-${md5(deviceId)}`);
                        if (badge) {
                            badge.textContent = data.dispositivos[deviceId].length;
                        }
                    });
                }
            });
    }

    function updateRecentData() {
        fetch('../api/get-recent-data.php?minutes=5&limit=10')
            .then(res => res.json())
            .then(data => {
                // Actualizar tabla con nuevos datos
                // Implementar si es necesario
            });
    }

    function changeTimeRange(minutes, interval) {
        currentTimeRange = minutes;
        currentInterval = interval;
        
        // Actualizar botones activos
        document.querySelectorAll('.time-filter .btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        
        // Actualizar etiqueta
        const label = document.getElementById('timeRangeLabel');
        const intervalText = interval < 60 ? `${interval}s` : `${Math.floor(interval/60)}min`;
        label.innerHTML = `<i class="bi bi-info-circle me-1"></i>Mostrando: Últimos ${minutes} minutos con datos cada ${intervalText}`;
        
        // Recargar datos
        loadChartData();
        
        // Actualizar intervalo de actualización
        if (updateInterval) {
            clearInterval(updateInterval);
        }
        setupAutoUpdate();
    }

    function setupAutoUpdate() {
        // Actualizar cada 30 segundos
        updateInterval = setInterval(loadChartData, 30000);
    }

    function toggleDataset(chartId) {
        const chart = chartId === 'tempChart' ? tempChart : humChart;
        if (!chart) return;
        
        const datasets = chart.data.datasets;
        const visibilityKey = chartId === 'tempChart' ? 'tempChart' : 'humChart';
        
        datasets.forEach((dataset, index) => {
            const deviceName = dataset.label;
            const isHidden = chart.getDatasetMeta(index).hidden;
            
            if (isHidden === null) {
                dataset.hidden = true;
                chartVisibility[visibilityKey][deviceName] = true;
            } else {
                dataset.hidden = !isHidden;
                chartVisibility[visibilityKey][deviceName] = !isHidden;
            }
        });
        
        chart.update();
    }

    function setDeviceToDelete(button) {
        const deviceId = button.getAttribute('data-device-id');
        const deviceName = button.getAttribute('data-device-name');
        
        document.getElementById('deviceIdToDelete').value = deviceId;
        document.getElementById('deviceNameToDelete').textContent = deviceName;
        
        // Obtener estadísticas del dispositivo
        fetch(`../api/get-device-stats.php?device=${encodeURIComponent(deviceId)}`)
            .then(res => res.json())
            .then(data => {
                const statsDiv = document.getElementById('deviceStatsInfo');
                statsDiv.innerHTML = `
                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <h6><i class="bi bi-info-circle me-2"></i>Información del dispositivo:</h6>
                            <div class="row small">
                                <div class="col-6">
                                    <div>Total de datos:</div>
                                    <strong>${data.total_datos}</strong>
                                </div>
                                <div class="col-6">
                                    <div>Datos temperatura:</div>
                                    <strong>${data.datos_temp}</strong>
                                </div>
                                <div class="col-6">
                                    <div>Datos humedad:</div>
                                    <strong>${data.datos_hum}</strong>
                                </div>
                                <div class="col-6">
                                    <div>Último dato:</div>
                                    <strong>${data.ultimo_dato}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            })
            .catch(error => {
                console.error('Error fetching device stats:', error);
            });
    }

    function getColor(index) {
        const colors = [
            'rgb(255, 99, 132)',   // Rojo
            'rgb(54, 162, 235)',   // Azul
            'rgb(255, 205, 86)',   // Amarillo
            'rgb(75, 192, 192)',   // Verde azulado
            'rgb(153, 102, 255)',  // Morado
            'rgb(255, 159, 64)',   // Naranja
            'rgb(201, 203, 207)',  // Gris
            'rgb(220, 53, 69)',    // Rojo Bootstrap
            'rgb(40, 167, 69)',    // Verde Bootstrap
            'rgb(23, 162, 184)',   // Cyan Bootstrap
        ];
        return colors[index % colors.length];
    }

    function md5(str) {
        // Función hash simple para usar como ID
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString(16);
    }

    function sendCommand() {
        const device = document.getElementById('deviceSelect').value;
        const command = document.getElementById('commandSelect').value;
        const parameter = document.getElementById('parameter').value;

        const statusDiv = document.getElementById("commandStatus");
        statusDiv.className = "alert alert-warning";
        statusDiv.innerHTML = `<span class="loading-spinner me-2"></span>Enviando comando...`;

        fetch('../api/mqtt-control.php', {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({ 
                dispositivo: device, 
                comando: command, 
                parametro: parameter 
            })
        })
        .then(res => res.json())
        .then(data => {
            statusDiv.className = data.success ? "alert alert-success" : "alert alert-danger";
            statusDiv.innerHTML = data.success ? 
                `<i class="bi bi-check-circle-fill me-2"></i>${data.message}` :
                `<i class="bi bi-exclamation-triangle-fill me-2"></i>${data.message}`;
            
            // Limpiar después de 5 segundos
            setTimeout(() => {
                statusDiv.className = "alert alert-info";
                statusDiv.innerHTML = `<i class="bi bi-info-circle me-1"></i>Listo para enviar comandos`;
            }, 5000);
        })
        .catch(error => {
            statusDiv.className = "alert alert-danger";
            statusDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>Error de conexión: ${error.message}`;
        });
    }

    function exportChart(chartId) {
        const canvas = document.getElementById(chartId);
        if (!canvas) return;
        
        const link = document.createElement('a');
        link.download = `grafica_${chartId}_${new Date().toISOString().slice(0,10)}.png`;
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showToast('Gráfica exportada exitosamente', 'success');
    }

    function exportDeviceData(deviceId) {
        fetch(`../api/export-device-data.php?device=${encodeURIComponent(deviceId)}`)
            .then(res => res.blob())
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `datos_${deviceId}_${new Date().toISOString().slice(0,10)}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                showToast('Datos exportados exitosamente', 'success');
            })
            .catch(error => {
                showToast('Error al exportar datos: ' + error.message, 'danger');
            });
    }

    function exportAllData() {
        fetch('../api/export-all-data.php')
            .then(res => res.blob())
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `export_iot_completo_${new Date().toISOString().slice(0,10)}.zip`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                showToast('Exportación completa descargada', 'success');
            })
            .catch(error => {
                showToast('Error al exportar: ' + error.message, 'danger');
            });
    }

    function exportTableData() {
        const table = document.getElementById('sensorTable');
        let csv = [];
        const rows = table.querySelectorAll('tr');
        
        rows.forEach(row => {
            const rowData = [];
            const cols = row.querySelectorAll('td, th');
            
            cols.forEach(col => {
                // Eliminar etiquetas HTML de las celdas
                let text = col.textContent.trim();
                // Escapar comillas para CSV
                text = text.replace(/"/g, '""');
                rowData.push(`"${text}"`);
            });
            
            csv.push(rowData.join(','));
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `datos_tabla_${new Date().toISOString().slice(0,10)}.csv`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        showToast('Tabla exportada a CSV', 'success');
    }

    function clearOldData() {
        if (!confirm('¿Eliminar datos antiguos (más de 30 días)? Esta acción no se puede deshacer.')) {
            return;
        }
        
        fetch('../api/clear-old-data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(`Se eliminaron ${data.deleted} registros antiguos`, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast('Error: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            showToast('Error: ' + error.message, 'danger');
        });
    }

    function refreshData() {
        location.reload();
    }

    function showLoadingState(show) {
        const loadingElement = document.getElementById('loadingIndicator');
        if (!loadingElement && show) {
            const div = document.createElement('div');
            div.id = 'loadingIndicator';
            div.className = 'position-fixed top-0 end-0 m-3';
            div.innerHTML = `
                <div class="alert alert-info d-flex align-items-center">
                    <span class="loading-spinner me-2"></span>
                    <span>Cargando datos...</span>
                </div>
            `;
            document.body.appendChild(div);
        } else if (loadingElement && !show) {
            loadingElement.remove();
        }
    }

    function showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger position-fixed top-0 start-50 translate-middle-x mt-3';
        errorDiv.style.zIndex = '9999';
        errorDiv.innerHTML = `
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            ${message}
            <button type="button" class="btn-close float-end" onclick="this.parentElement.remove()"></button>
        `;
        document.body.appendChild(errorDiv);
        
        setTimeout(() => {
            if (errorDiv.parentElement) {
                errorDiv.remove();
            }
        }, 5000);
    }

    function showToast(message, type = 'info') {
        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi ${type === 'success' ? 'bi-check-circle' : type === 'danger' ? 'bi-exclamation-circle' : 'bi-info-circle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        const toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            const container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }
        
        document.querySelector('.toast-container').innerHTML += toastHtml;
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', function () {
            this.remove();
        });
    }

    // Función para manejar eliminación de dispositivo
    document.getElementById('deleteDeviceForm').addEventListener('submit', function(e) {
        const deviceName = document.getElementById('deviceNameToDelete').textContent;
        const confirmMessage = `¿ESTÁS COMPLETAMENTE SEGURO de eliminar el dispositivo "${deviceName}"?\n\nEsta acción es IRREVERSIBLE y eliminará todos los datos asociados.`;
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });
    </script>

    <?php include '../core/footer.php'; ?>
</body>
</html>