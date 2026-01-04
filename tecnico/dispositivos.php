<?php
// Activar reporte de errores para desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Verificar sesión y rol
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

if(!isset($_SESSION["user_id"])){
    header("Location: ../public/index.php");
    exit();
}

$tecnico_id = $_SESSION["user_id"];

require "../config/db.php";

// ================================================
// CONSULTA PRINCIPAL - OBTENER DISPOSITIVOS
// ================================================
$stmt = $pdo->prepare("
    SELECT d.*, u.username as cliente_nombre 
    FROM dispositivos d
    LEFT JOIN users u ON d.cliente_id = u.id
    WHERE d.tecnico_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$tecnico_id]);
$dispositivos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================================================
// SOLUCIÓN DEFINITIVA: PROCESAR SIN REFERENCIAS
// ================================================
$total = count($dispositivos_raw);
$activos = 0;
$mqtt_count = 0;
$tiene_datos = 0;
$dispositivos = []; // ARRAY NUEVO para evitar problemas

foreach($dispositivos_raw as $dispositivo_original) {
    // 1. Crear COPIA del dispositivo (no referencia)
    $dispositivo = [];
    foreach($dispositivo_original as $key => $value) {
        $dispositivo[$key] = $value;
    }
    
    // 2. Contadores básicos
    if($dispositivo['estado'] == 'activo') $activos++;
    if($dispositivo['protocolo'] == 'MQTT') $mqtt_count++;
    
    // 3. INICIALIZAR TODOS LOS CAMPOS (IMPORTANTE)
    $dispositivo['en_linea'] = false;
    $dispositivo['ultima_lectura'] = null;
    $dispositivo['total_datos'] = 0;
    $dispositivo['ultima_temp'] = null;
    $dispositivo['ultima_hum'] = null;
    
    // 4. Solo procesar dispositivos MQTT con código
    if($dispositivo['protocolo'] == 'MQTT' && !empty($dispositivo['codigo'])) {
        $codigo = trim($dispositivo['codigo']);
        
        // DEBUG: Verificar código
        error_log("Procesando dispositivo ID: {$dispositivo['id']}, Código: '{$codigo}'");
        
        // 5. CONSULTA ÚNICA OPTIMIZADA
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                MAX(timestamp) as ultima_lectura,
                SUM(CASE WHEN timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as recientes,
                GROUP_CONCAT(CASE WHEN sensor = 'temperatura' THEN valor END ORDER BY timestamp DESC LIMIT 1) as temp,
                GROUP_CONCAT(CASE WHEN sensor = 'humedad' THEN valor END ORDER BY timestamp DESC LIMIT 1) as hum
            FROM mqtt_data 
            WHERE dispositivo_id = ?
            GROUP BY dispositivo_id
        ");
        $stmt->execute([$codigo]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($resultado) {
            // 6. ASIGNAR VALORES CORRECTAMENTE
            $dispositivo['total_datos'] = (int)$resultado['total'];
            $dispositivo['ultima_lectura'] = $resultado['ultima_lectura'];
            $dispositivo['en_linea'] = ($resultado['recientes'] > 0);
            $dispositivo['ultima_temp'] = $resultado['temp'];
            $dispositivo['ultima_hum'] = $resultado['hum'];
            
            if($dispositivo['total_datos'] > 0) {
                $tiene_datos++;
            }
            
            // DEBUG: Ver resultados
            error_log("Resultado para {$codigo}: datos={$dispositivo['total_datos']}, online=" . ($dispositivo['en_linea'] ? 'si' : 'no'));
        } else {
            error_log("Sin datos para código: {$codigo}");
        }
    }
    
    // 7. Añadir al array FINAL
    $dispositivos[] = $dispositivo;
}

$porcentaje_activos = $total > 0 ? round(($activos / $total) * 100) : 0;

// DEBUG FINAL
error_log("Total dispositivos procesados: " . count($dispositivos));
foreach($dispositivos as $d) {
    if($d['protocolo'] == 'MQTT') {
        error_log("Dispositivo {$d['id']} ({$d['codigo']}): datos={$d['total_datos']}, temp={$d['ultima_temp']}, hum={$d['ultima_hum']}");
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Dispositivos IoT</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            gap: 20px;
        }
        
        /* Barra lateral */
        .sidebar {
            width: 300px;
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f2f6;
            margin-bottom: 25px;
        }
        
        .sidebar-header h2 {
            color: #2d3436;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            color: #636e72;
            font-size: 0.9rem;
        }
        
        .device-filters {
            margin-bottom: 25px;
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #2d3436;
            font-weight: 500;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 2px solid #dfe6e9;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #0984e3;
            outline: none;
        }
        
        .stats-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .stat-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .stat-label {
            color: #636e72;
        }
        
        .stat-value {
            font-weight: 600;
            color: #2d3436;
        }
        
        .btn-add-device {
            display: block;
            width: 100%;
            padding: 12px;
            background: #00b894;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .btn-add-device:hover {
            background: #00a085;
        }
        
        /* Área principal */
        .main-content {
            flex: 1;
        }
        
        .header {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #2d3436;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #636e72;
        }
        
        /* Grid de dispositivos */
        .devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .device-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .device-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .device-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .device-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-right: 15px;
            color: white;
        }
        
        .icon-temperature {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
        }
        
        .icon-humidity {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
        }
        
        .icon-generic {
            background: linear-gradient(135deg, #a8e6cf, #56ab91);
        }
        
        .icon-control {
            background: linear-gradient(135deg, #ffd166, #ff9e00);
        }
        
        .device-info h3 {
            color: #2d3436;
            font-size: 1.3rem;
            margin-bottom: 5px;
        }
        
        .device-info p {
            color: #636e72;
            font-size: 0.9rem;
        }
        
        .device-status {
            position: absolute;
            top: 25px;
            right: 25px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-activo {
            background: #c8f7c5;
            color: #27ae60;
        }
        
        .status-inactivo {
            background: #ffcccc;
            color: #e74c3c;
        }
        
        .status-mantenimiento {
            background: #fff3cd;
            color: #856404;
        }
        
        .device-details {
            margin: 20px 0;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f1f2f6;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #636e72;
            font-size: 0.9rem;
        }
        
        .detail-value {
            color: #2d3436;
            font-weight: 500;
        }
        
        .device-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-action {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            text-align: center;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .btn-edit {
            background: #0984e3;
            color: white;
        }
        
        .btn-edit:hover {
            background: #0770c4;
        }
        
        .btn-view {
            background: #00b894;
            color: white;
        }
        
        .btn-view:hover {
            background: #00a085;
        }
        
        .btn-mqtt {
            background: #6c5ce7;
            color: white;
        }
        
        .btn-mqtt:hover {
            background: #5b4fcf;
        }
        
        /* Estado vacío */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dfe6e9;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #2d3436;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #636e72;
            margin-bottom: 30px;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .devices-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .devices-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .device-card {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* Indicador de estado MQTT */
        .mqtt-indicator {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .mqtt-online {
            background-color: #27ae60;
            box-shadow: 0 0 8px #27ae60;
        }
        
        .mqtt-offline {
            background-color: #e74c3c;
            box-shadow: 0 0 8px #e74c3c;
        }
        
        .mqtt-unknown {
            background-color: #f39c12;
            box-shadow: 0 0 8px #f39c12;
        }
        
        /* Badge para datos MQTT */
        .mqtt-data-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 5px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        /* Panel de control MQTT */
        .mqtt-control-panel {
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(108, 92, 231, 0.2);
        }
        
        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
        }

        .mqtt-control-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .mqtt-control-header h3 {
            margin: 0;
            font-size: 1.2rem;
        }
        
        .mqtt-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-mqtt-action {
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-mqtt-action:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .mqtt-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .mqtt-stat {
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
        }
        
        .mqtt-stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .mqtt-stat-label {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        /* Tooltip para datos MQTT */
        .mqtt-tooltip {
            position: relative;
            cursor: pointer;
        }
        
        .mqtt-tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
        }
        
        .mqtt-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .btn-volver {
            padding: 10px 20px;
            background: #6c5ce7;
            color: white;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-volver:hover {
            background: #5b4fcf;
        }
        
        /* Indicador de código MQTT */
        .mqtt-code-indicator {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
            font-family: monospace;
            background: #f1f2f6;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        /* Mensajes de debug (solo desarrollo) */
        .debug-info {
            font-size: 0.7rem;
            color: #888;
            font-family: monospace;
            background: #f8f9fa;
            padding: 5px;
            border-radius: 3px;
            margin-top: 5px;
            display: none; /* Ocultar en producción */
        }
        
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="dashboard-container">
        <!-- Barra lateral -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-microchip"></i> IoT Manager</h2>
                <p>Panel de control técnico</p>
            </div>
            
            <div class="device-filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Tipo:</label>
                    <select id="filterType" onchange="filterDevices()">
                        <option value="all">Todos los tipos</option>
                        <option value="temperatura">🌡️ Temperatura</option>
                        <option value="humedad">💧 Humedad</option>
                        <option value="control">🎛️ Control</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-broadcast-tower"></i> Filtrar por Protocolo:</label>
                    <select id="filterProtocol" onchange="filterDevices()">
                        <option value="all">Todos los protocolos</option>
                        <option value="HTTP">HTTP</option>
                        <option value="MQTT">MQTT</option>
                        <option value="WebSocket">WebSocket</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-power-off"></i> Filtrar por Estado:</label>
                    <select id="filterStatus" onchange="filterDevices()">
                        <option value="all">Todos los estados</option>
                        <option value="activo">🟢 Activo</option>
                        <option value="inactivo">🔴 Inactivo</option>
                        <option value="mantenimiento">🟡 Mantenimiento</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Buscar dispositivo:</label>
                    <input type="text" id="searchDevice" placeholder="Nombre o ubicación..." onkeyup="filterDevices()">
                </div>
                
                <!-- Filtro solo MQTT -->
                <div class="filter-group">
                    <label>
                        <input type="checkbox" id="filterMQTT" onchange="filtrarSoloMQTT()">
                        <i class="fas fa-broadcast-tower"></i> Mostrar solo MQTT
                    </label>
                </div>
            </div>
            
            <div class="stats-card">
                <h3 style="color: #2d3436; margin-bottom: 15px; font-size: 1.1rem;">
                    <i class="fas fa-chart-bar"></i> Estadísticas
                </h3>
                
                <div class="stat-item">
                    <span class="stat-label">Total dispositivos</span>
                    <span class="stat-value"><?php echo $total; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Activos</span>
                    <span class="stat-value"><?php echo $activos; ?> (<?php echo $porcentaje_activos; ?>%)</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Con MQTT</span>
                    <span class="stat-value"><?php echo $mqtt_count; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Con datos MQTT</span>
                    <span class="stat-value"><?php echo $tiene_datos; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Técnico ID</span>
                    <span class="stat-value">#<?php echo $tecnico_id; ?></span>
                </div>
            </div>
            
            <a href="crear_dispositivo.php" class="btn-add-device">
                <i class="fas fa-plus-circle"></i> Agregar Nuevo Dispositivo
            </a>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="index.php" class="btn-volver" style="text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Volver al Panel
                </a> 
            </div>
            
        </div>
        
        <!-- Contenido principal -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-sitemap"></i> Mis Dispositivos IoT</h1>
                <p>Gestiona y monitorea todos tus dispositivos conectados</p>
                <p style="font-size: 0.9rem; color: #888; margin-top: 10px;">
                    <i class="fas fa-info-circle"></i> Mostrando <?php echo $total; ?> dispositivo(s) | 
                    Última actualización: <?php echo date('H:i:s'); ?>
                </p>
            </div>
            
            <!-- Panel de control MQTT -->
            <?php if($mqtt_count > 0): ?>
            <div class="mqtt-control-panel">
                <div class="mqtt-control-header">
                    <h3><i class="fas fa-broadcast-tower"></i> Control MQTT</h3>
                    <div class="mqtt-buttons">
                        <button class="btn-mqtt-action" onclick="testMQTT()">
                            <i class="fas fa-bolt"></i> Probar Conexión
                        </button>
                        <button class="btn-mqtt-action" onclick="mostrarInstruccionesMQTT()">
                            <i class="fas fa-info-circle"></i> Instrucciones
                        </button>
                        <a href="dashboard_mqtt.php" class="btn-mqtt-action" style="text-decoration: none;">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>
                    </div>
                </div>
                
                <div class="mqtt-stats">
                    <div class="mqtt-stat">
                        <div class="mqtt-stat-value"><?php echo $mqtt_count; ?></div>
                        <div class="mqtt-stat-label">Dispositivos MQTT</div>
                    </div>
                    <div class="mqtt-stat">
                        <div class="mqtt-stat-value"><?php echo $tiene_datos; ?></div>
                        <div class="mqtt-stat-label">Enviando datos</div>
                    </div>
                    <div class="mqtt-stat">
                        <div class="mqtt-stat-value">
                            <?php 
                            // Contar dispositivos MQTT en línea (últimos 5 minutos)
                            $en_linea = 0;
                            foreach($dispositivos as $d) {
                                if(isset($d['en_linea']) && $d['en_linea']) {
                                    $en_linea++;
                                }
                            }
                            echo $en_linea;
                            ?>
                        </div>
                        <div class="mqtt-stat-label">En línea ahora</div>
                    </div>
                    <div class="mqtt-stat">
                        <div class="mqtt-stat-value">
                            <?php 
                            // Calcular total de datos MQTT
                            $total_datos_mqtt = 0;
                            foreach($dispositivos as $d) {
                                $total_datos_mqtt += $d['total_datos'] ?? 0;
                            }
                            echo number_format($total_datos_mqtt);
                            ?>
                        </div>
                        <div class="mqtt-stat-label">Lecturas totales</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(empty($dispositivos)): ?>
                <div class="empty-state">
                    <i class="fas fa-microchip-slash"></i>
                    <h3>No hay dispositivos registrados</h3>
                    <p>Comienza agregando tu primer dispositivo IoT al sistema.</p>
                    <a href="crear_dispositivo.php" class="btn-add-device" style="width: auto; display: inline-block; padding: 12px 30px;">
                        <i class="fas fa-plus-circle"></i> Crear Primer Dispositivo
                    </a>
                </div>
            <?php else: ?>
                <div class="devices-grid" id="devicesGrid">
                    <?php foreach($dispositivos as $index => $disp): 
                        // Determinar clase de icono según tipo
                        $icon_class = 'icon-generic';
                        if(strpos(strtolower($disp['tipo']), 'temperatura') !== false) {
                            $icon_class = 'icon-temperature';
                            $icon = '🌡️';
                        } elseif(strpos(strtolower($disp['tipo']), 'humedad') !== false) {
                            $icon_class = 'icon-humidity';
                            $icon = '💧';
                        } elseif(strpos(strtolower($disp['tipo']), 'control') !== false) {
                            $icon_class = 'icon-control';
                            $icon = '🎛️';
                        } else {
                            $icon = '📱';
                        }
                        
                        // Formatear fecha
                        $fecha_instalacion = !empty($disp['fecha_instalacion']) ? 
                            date('d/m/Y', strtotime($disp['fecha_instalacion'])) : 'No especificada';
                        
                        // Determinar color de protocolo
                        $protocol_color = $disp['protocolo'] == 'MQTT' ? '#6c5ce7' : 
                                         ($disp['protocolo'] == 'HTTP' ? '#0984e3' : '#00b894');
                        
                        // Para dispositivos MQTT, verificar estado
                        $mqtt_indicator = '';
                        $mqtt_badge = '';
                        $mqtt_code_info = '';
                        if($disp['protocolo'] == 'MQTT') {
                            $mqtt_indicator_class = 'mqtt-unknown';
                            if(isset($disp['en_linea'])) {
                                $mqtt_indicator_class = $disp['en_linea'] ? 'mqtt-online' : 'mqtt-offline';
                            }
                            $mqtt_indicator = '<div class="mqtt-indicator ' . $mqtt_indicator_class . '"></div>';
                            
                            // Badge para datos MQTT
                            if(isset($disp['total_datos']) && $disp['total_datos'] > 0) {
                                $mqtt_badge = '<span class="mqtt-data-badge badge-success">' . $disp['total_datos'] . ' datos</span>';
                            }
                            
                            // Info de código MQTT
                            $mqtt_code_info = !empty($disp['codigo']) ? 
                                '<div class="mqtt-code-indicator">ID MQTT: ' . htmlspecialchars($disp['codigo']) . '</div>' : '';
                        }
                        
                        // Debug info (solo desarrollo)
                        $debug_info = "ID:{$disp['id']}|Codigo:{$disp['codigo']}|Datos:{$disp['total_datos']}|Online:" . ($disp['en_linea']?'si':'no');
                    ?>
                    <div class="device-card" 
                         data-id="<?php echo $disp['id']; ?>"
                         data-type="<?php echo strtolower($disp['tipo']); ?>"
                         data-protocol="<?php echo $disp['protocolo']; ?>"
                         data-status="<?php echo $disp['estado']; ?>"
                         data-name="<?php echo strtolower($disp['nombre']); ?>"
                         data-location="<?php echo strtolower($disp['ubicacion'] ?? ''); ?>"
                         data-mqtt="<?php echo $disp['protocolo'] == 'MQTT' ? '1' : '0'; ?>"
                         data-debug="<?php echo htmlspecialchars($debug_info); ?>">
                        
                        <?php echo $mqtt_indicator; ?>
                        
                        <div class="device-header">
                            <div class="device-icon <?php echo $icon_class; ?>">
                                <?php echo $icon; ?>
                            </div>
                            <div class="device-info">
                                <h3>
                                    <?php echo htmlspecialchars($disp['nombre']); ?>
                                    <?php echo $mqtt_badge; ?>
                                </h3>
                                <p><?php echo htmlspecialchars($disp['tipo']); ?></p>
                                <?php echo $mqtt_code_info; ?>
                            </div>
                            <div class="device-status status-<?php echo $disp['estado']; ?>">
                                <?php echo ucfirst($disp['estado']); ?>
                            </div>
                        </div>
                        
                        <div class="device-details">
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-hashtag"></i> ID</span>
                                <span class="detail-value">#<?php echo $disp['id']; ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Ubicación</span>
                                <span class="detail-value"><?php echo htmlspecialchars($disp['ubicacion'] ?? 'No especificada'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-user"></i> Cliente</span>
                                <span class="detail-value"><?php echo htmlspecialchars($disp['cliente_nombre'] ?? 'Sin asignar'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-network-wired"></i> Protocolo</span>
                                <span class="detail-value" style="color: <?php echo $protocol_color; ?>; font-weight: bold;">
                                    <?php echo $disp['protocolo']; ?>
                                    <?php if($disp['protocolo'] == 'MQTT'): ?>
                                        <span style="font-size: 0.8rem; margin-left: 5px;">
                                            (<?php echo isset($disp['en_linea']) && $disp['en_linea'] ? '🟢 En línea' : '🔴 Offline'; ?>)
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <?php if($disp['protocolo'] == 'MQTT' && isset($disp['total_datos']) && $disp['total_datos'] > 0): ?>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-database"></i> Datos MQTT</span>
                                <span class="detail-value">
                                    <span class="mqtt-tooltip">
                                        <?php echo $disp['total_datos']; ?> lecturas
                                        <span class="tooltip-text">
                                            <strong>Última lectura:</strong> 
                                            <?php echo $disp['ultima_lectura'] ? date('d/m H:i', strtotime($disp['ultima_lectura'])) : 'Nunca'; ?>
                                            <br>
                                            <?php if($disp['ultima_temp']): ?>
                                            <strong>Temperatura:</strong> <?php echo $disp['ultima_temp']; ?>°C
                                            <br>
                                            <?php endif; ?>
                                            <?php if($disp['ultima_hum']): ?>
                                            <strong>Humedad:</strong> <?php echo $disp['ultima_hum']; ?>%
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-calendar-alt"></i> Instalación</span>
                                <span class="detail-value"><?php echo $fecha_instalacion; ?></span>
                            </div>
                        </div>
                        
                        <div class="device-actions">
                            <a href="editar_dispositivo.php?id=<?php echo $disp['id']; ?>" class="btn-action btn-edit">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <a href="ver_datos.php?id=<?php echo $disp['id']; ?>" class="btn-action btn-view">
                                <i class="fas fa-chart-line"></i> Ver Datos
                            </a>
                            <?php if($disp['protocolo'] == 'MQTT'): ?>
                                <a href="dashboard_mqtt.php?device=<?php echo $disp['id']; ?>" class="btn-action btn-mqtt">
                                    <i class="fas fa-satellite-dish"></i> MQTT
                                </a>
                            <?php endif; ?>
                            <a href="eliminar_dispositivo.php?id=<?php echo $disp['id']; ?>" 
                               class="btn-action btn-delete"
                               onclick="return confirm('¿Estás seguro de eliminar este dispositivo? Esta acción no se puede deshacer.');">
                                <i class="fas fa-trash"></i> Eliminar
                            </a>
                        </div>
                        
                        <!-- Debug info (opcional, solo desarrollo) -->
                        <div class="debug-info">
                            ID: <?php echo $disp['id']; ?> | 
                            Código: <?php echo htmlspecialchars($disp['codigo'] ?? 'N/A'); ?> | 
                            Datos: <?php echo $disp['total_datos']; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de instrucciones MQTT -->
    <div id="mqttInstructionsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 15px; padding: 30px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <h3 style="color: #2d3436; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> Instrucciones para conectar ESP32 vía MQTT
            </h3>
            
            <h4 style="color: #6c5ce7; margin-top: 20px;">1. Configuración del ESP32</h4>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; font-size: 0.9rem;">
// Configuración básica en Arduino IDE
#include &lt;WiFi.h&gt;
#include &lt;PubSubClient.h&gt;

const char* ssid = "TU_WIFI";
const char* password = "TU_PASSWORD";
const char* mqtt_server = "IP_DEL_SERVIDOR"; // IP de tu servidor

WiFiClient espClient;
PubSubClient client(espClient);

void setup() {
    WiFi.begin(ssid, password);
    client.setServer(mqtt_server, 1883);
}</pre>
            
            <h4 style="color: #6c5ce7; margin-top: 20px;">2. Enviar datos de sensor</h4>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; font-size: 0.9rem;">
// Publicar datos cada 30 segundos
void loop() {
    float temperatura = leerTemperatura();
    float humedad = leerHumedad();
    
    String json = "{";
    json += "\"dispositivo\":\"ESP32_001\",";
    json += "\"temperatura\":" + String(temperatura) + ",";
    json += "\"humedad\":" + String(humedad);
    json += "}";
    
    client.publish("esp32/sensor/data", json.c_str());
    delay(30000);
}</pre>
            
            <h4 style="color: #6c5ce7; margin-top: 20px;">3. Prueba manual desde terminal</h4>
            <code style="display: block; background: #2d3436; color: white; padding: 15px; border-radius: 8px; font-family: monospace; margin-top: 10px;">
                mosquitto_pub -h localhost -t "esp32/sensor/data" -m '{"dispositivo":"TEST_001","temperatura":25.5,"humedad":60}'
            </code>
            
            <h4 style="color: #6c5ce7; margin-top: 20px;">4. Verificar conexión</h4>
            <ul style="color: #2d3436; padding-left: 20px; margin-top: 10px;">
                <li>Ver logs: <code>tail -f /var/www/html/iot-system/mqtt.log</code></li>
                <li>Ver datos en BD: <code>SELECT * FROM mqtt_data ORDER BY id DESC LIMIT 5;</code></li>
                <li>Dashboard MQTT: <a href="dashboard_mqtt.php">dashboard_mqtt.php</a></li>
            </ul>
            
            <div style="text-align: right; margin-top: 30px;">
                <button onclick="cerrarModal()" style="padding: 10px 20px; background: #6c5ce7; color: white; border: none; border-radius: 8px; cursor: pointer;">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales para filtros
        let dispositivosOriginales = [];
        
        // Inicializar al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            // Guardar referencia original de dispositivos
            dispositivosOriginales = Array.from(document.querySelectorAll('.device-card'));
            
            // Ordenar tarjetas por animación
            const cards = document.querySelectorAll('.device-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Mostrar info de debug en consola
            console.log("=== INFORMACIÓN DE DISPOSITIVOS ===");
            cards.forEach(card => {
                const id = card.getAttribute('data-id');
                const debug = card.getAttribute('data-debug');
                console.log(`Dispositivo ${id}: ${debug}`);
            });
            console.log("==================================");
            
            // Actualizar estado MQTT cada 60 segundos
            setInterval(actualizarEstadoMQTT, 60000);
        });
        
        function filterDevices() {
            const typeFilter = document.getElementById('filterType').value.toLowerCase();
            const protocolFilter = document.getElementById('filterProtocol').value;
            const statusFilter = document.getElementById('filterStatus').value;
            const searchTerm = document.getElementById('searchDevice').value.toLowerCase();
            
            dispositivosOriginales.forEach(card => {
                const type = card.getAttribute('data-type');
                const protocol = card.getAttribute('data-protocol');
                const status = card.getAttribute('data-status');
                const name = card.getAttribute('data-name');
                const location = card.getAttribute('data-location');
                
                let show = true;
                
                // Filtrar por tipo
                if (typeFilter !== 'all' && !type.includes(typeFilter)) {
                    show = false;
                }
                
                // Filtrar por protocolo
                if (protocolFilter !== 'all' && protocol !== protocolFilter) {
                    show = false;
                }
                
                // Filtrar por estado
                if (statusFilter !== 'all' && status !== statusFilter) {
                    show = false;
                }
                
                // Filtrar por búsqueda
                if (searchTerm && !name.includes(searchTerm) && !location.includes(searchTerm)) {
                    show = false;
                }
                
                // Mostrar/ocultar tarjeta con animación
                if (show) {
                    card.style.display = 'block';
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 10);
                } else {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        card.style.display = 'none';
                    }, 300);
                }
            });
        }
        
        function filtrarSoloMQTT() {
            const mqttOnly = document.getElementById('filterMQTT').checked;
            
            dispositivosOriginales.forEach(card => {
                const isMqtt = card.getAttribute('data-mqtt') === '1';
                
                if (mqttOnly && !isMqtt) {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        card.style.display = 'none';
                    }, 300);
                } else if (!mqttOnly && card.style.display === 'none') {
                    card.style.display = 'block';
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 10);
                }
            });
        }
        
        function testMQTT() {
            fetch('../api/test-mqtt.php')
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        alert('✅ Conexión MQTT funcionando correctamente');
                        // Recargar dispositivos MQTT
                        recargarEstadoMQTT();
                    } else {
                        alert('❌ Error en conexión MQTT: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error al probar conexión MQTT');
                });
        }
        
        function mostrarInstruccionesMQTT() {
            document.getElementById('mqttInstructionsModal').style.display = 'flex';
        }
        
        function cerrarModal() {
            document.getElementById('mqttInstructionsModal').style.display = 'none';
        }
        
        function recargarEstadoMQTT() {
            // Aquí puedes implementar una recarga AJAX de los datos MQTT
            // Por ahora, solo recargamos la página
            window.location.reload();
        }
        
        function actualizarEstadoMQTT() {
            // Función para actualizar el estado de dispositivos MQTT vía AJAX
            const dispositivosMQTT = Array.from(document.querySelectorAll('.device-card[data-mqtt="1"]'));
            
            if(dispositivosMQTT.length > 0) {
                // Solo hacer ping para mantener sesión activa
                fetch('../api/ping-mqtt.php')
                    .then(response => response.json())
                    .then(data => {
                        if(data.online) {
                            console.log('✅ Servidor MQTT en línea');
                        }
                    })
                    .catch(error => {
                        console.log('⚠️ Error al verificar MQTT');
                    });
            }
        }
        
        // Cerrar modal con ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                cerrarModal();
            }
        });
        
        // Cerrar modal haciendo clic fuera
        document.getElementById('mqttInstructionsModal').addEventListener('click', function(event) {
            if (event.target === this) {
                cerrarModal();
            }
        });
        
        // Auto-refresh cada 5 minutos (opcional)
        setTimeout(function() {
            if(confirm('¿Deseas actualizar la lista de dispositivos?')) {
                window.location.reload();
            }
        }, 300000); // 5 minutos
    </script>
</body>
</html>