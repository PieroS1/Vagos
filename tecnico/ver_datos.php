<?php
// Activar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Obtener ID del dispositivo desde URL
$dispositivo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$horas = isset($_GET['horas']) ? intval($_GET['horas']) : 24;

if($dispositivo_id <= 0) {
    die("Dispositivo no especificado");
}

// Obtener información del dispositivo
$stmt = $pdo->prepare("
    SELECT d.*, u.username as cliente_nombre 
    FROM dispositivos d
    LEFT JOIN users u ON d.cliente_id = u.id
    WHERE d.id = ?
");
$stmt->execute([$dispositivo_id]);
$dispositivo = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$dispositivo) {
    die("Dispositivo no encontrado");
}

// Determinar el código para buscar en mqtt_data
$codigo_para_buscar = $dispositivo['codigo'];

// DEPURACIÓN: Mostrar qué estamos buscando
echo "<!-- DEPURACIÓN: dispositivo_id=$dispositivo_id, código_busqueda=$codigo_para_buscar -->";

// Obtener lista de sensores ÚNICOS que tiene este dispositivo
$stmt = $pdo->prepare("
    SELECT DISTINCT sensor 
    FROM mqtt_data 
    WHERE dispositivo_id = ?
    AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ORDER BY sensor
");
$stmt->execute([$codigo_para_buscar, $horas]);
$sensores_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sensores = array_column($sensores_raw, 'sensor');

// Si no hay sensores, buscar sin límite de tiempo
if(empty($sensores)) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT sensor 
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        ORDER BY sensor
    ");
    $stmt->execute([$codigo_para_buscar]);
    $sensores_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sensores = array_column($sensores_raw, 'sensor');
}

// Array para almacenar datos por sensor
$datos_por_sensor = [];
$estadisticas_por_sensor = [];

// Obtener datos ESPECÍFICOS para cada sensor
foreach($sensores as $sensor) {
    // Obtener datos históricos de ESTE SENSOR específico
    $stmt = $pdo->prepare("
        SELECT 
            sensor,
            valor,
            DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') as timestamp_formatted,
            DATE_FORMAT(timestamp, '%H:%i') as hora
        FROM mqtt_data 
        WHERE dispositivo_id = ?
        AND sensor = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY timestamp ASC
    ");
    $stmt->execute([$codigo_para_buscar, $sensor, $horas]);
    $datos_por_sensor[$sensor] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas SOLO para este sensor
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lecturas,
            MIN(valor) as minimo,
            MAX(valor) as maximo,
            AVG(valor) as promedio,
            MAX(timestamp) as ultima_lectura
        FROM mqtt_data 
        WHERE dispositivo_id = ? 
        AND sensor = ?
        AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$codigo_para_buscar, $sensor, $horas]);
    $estadisticas_por_sensor[$sensor] = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Si todavía no hay datos, buscar algunos datos de ejemplo para debug
if(empty($sensores) && !empty($codigo_para_buscar)) {
    echo "<!-- DEBUG: Buscando cualquier dato del dispositivo $codigo_para_buscar -->";
    $stmt = $pdo->prepare("
        SELECT DISTINCT sensor 
        FROM mqtt_data 
        WHERE dispositivo_id LIKE ?
        LIMIT 5
    ");
    $stmt->execute(["%$codigo_para_buscar%"]);
    $sensores_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sensores = array_column($sensores_raw, 'sensor');
    
    foreach($sensores as $sensor) {
        $stmt = $pdo->prepare("
            SELECT 
                sensor,
                valor,
                DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') as timestamp_formatted,
                DATE_FORMAT(timestamp, '%H:%i') as hora
            FROM mqtt_data 
            WHERE dispositivo_id LIKE ?
            AND sensor = ?
            ORDER BY timestamp DESC
            LIMIT 50
        ");
        $stmt->execute(["%$codigo_para_buscar%", $sensor]);
        $datos_por_sensor[$sensor] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datos del Sensor - <?php echo htmlspecialchars($dispositivo['nombre']); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f2f6;
        }
        
        .header h1 {
            color: #2d3436;
            font-size: 1.8rem;
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
        
        .info-dispositivo {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            color: #636e72;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .info-value {
            color: #2d3436;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        /* Panel de información de sensores */
        .sensor-info {
            background: #e3f2fd;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #2196f3;
        }
        
        .sensor-info p {
            margin: 5px 0;
            color: #1565c0;
        }
        
        .sensor-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .sensor-badge {
            padding: 5px 12px;
            background: #2196f3;
            color: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Filtros */
        .filtros {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filtro-group {
            display: flex;
            flex-direction: column;
        }
        
        .filtro-group label {
            color: #636e72;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .filtro-group select {
            padding: 10px;
            border: 2px solid #dfe6e9;
            border-radius: 8px;
            background: white;
        }
        
        .btn-filtrar {
            padding: 10px 20px;
            background: #00b894;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .btn-filtrar:hover {
            background: #00a085;
        }
        
        /* Gráficos */
        .graficos-container {
            margin-bottom: 40px;
        }
        
        .grafico-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
        }
        
        .grafico-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f2f6;
        }
        
        .grafico-header h3 {
            color: #2d3436;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sensor-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .icon-temperature {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
        }
        
        .icon-humidity {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
        }
        
        .icon-generic {
            background: linear-gradient(135deg, #a8e6cf, #56ab91);
            color: white;
        }
        
        .grafico-estadisticas {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .estadistica-item {
            text-align: center;
            padding: 10px;
        }
        
        .estadistica-valor {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2d3436;
            margin-bottom: 5px;
        }
        
        .estadistica-label {
            color: #636e72;
            font-size: 0.9rem;
        }
        
        /* Sin datos */
        .sin-datos {
            text-align: center;
            padding: 50px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .sin-datos i {
            font-size: 4rem;
            color: #dfe6e9;
            margin-bottom: 20px;
        }
        
        .sin-datos h3 {
            color: #2d3436;
            margin-bottom: 10px;
        }
        
        .sin-datos p {
            color: #636e72;
            margin-bottom: 30px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filtros {
                flex-direction: column;
                align-items: stretch;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .grafico-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .grafico-card {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* Sensor details */
        .sensor-details {
            font-size: 0.9rem;
            color: #636e72;
            margin-top: 5px;
        }
        
        /* WebSocket Status */
        #websocket-status {
            position: fixed;
            bottom: 20px;
            left: 20px;
            padding: 10px 15px;
            border-radius: 20px;
            color: white;
            font-weight: 600;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            animation: fadeIn 0.5s ease;
        }
        
        #websocket-controls {
            position: fixed;
            bottom: 70px;
            left: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
        }
        
        #websocket-controls button {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        #websocket-controls button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Notificaciones en tiempo real */
        .live-notification {
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .notification-icon {
            font-size: 24px;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2d3436;
            margin: 5px 0;
        }
        
        .notification-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #636e72;
            padding: 0 5px;
        }
        
        .notification-close:hover {
            color: #e74c3c;
        }
        
        /* Responsive para controles WebSocket */
        @media (max-width: 768px) {
            #websocket-status {
                bottom: 10px;
                left: 10px;
                right: 10px;
                text-align: center;
            }
            
            #websocket-controls {
                bottom: 60px;
                left: 10px;
                right: 10px;
                justify-content: center;
            }
            
            .live-notification {
                left: 10px;
                right: 10px;
                max-width: none;
            }
        }
        
        /* Indicador de actualización en tiempo real */
        .live-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #27ae60;
            margin-right: 5px;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i> 
                Datos del Dispositivo: <?php echo htmlspecialchars($dispositivo['nombre']); ?>
                <span id="live-indicator" style="display: none;">
                    <span class="live-indicator"></span>TIEMPO REAL
                </span>
            </h1>
            <a href="dispositivos.php" class="btn-volver">
                <i class="fas fa-arrow-left"></i> Volver al Dashboard
            </a>
        </div>
        
        <div class="info-dispositivo">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">ID del Dispositivo</span>
                    <span class="info-value">#<?php echo $dispositivo['id']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Código MQTT</span>
                    <span class="info-value" style="font-family: monospace;"><?php echo htmlspecialchars($dispositivo['codigo']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Protocolo</span>
                    <span class="info-value"><?php echo htmlspecialchars($dispositivo['protocolo']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Ubicación</span>
                    <span class="info-value"><?php echo htmlspecialchars($dispositivo['ubicacion']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Cliente</span>
                    <span class="info-value"><?php echo htmlspecialchars($dispositivo['cliente_nombre'] ?? 'No asignado'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Estado</span>
                    <span class="info-value" style="color: <?php echo $dispositivo['estado'] == 'activo' ? '#27ae60' : '#e74c3c'; ?>">
                        <?php echo ucfirst($dispositivo['estado']); ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">WebSocket</span>
                    <span class="info-value" id="ws-status-text">🔴 Desconectado</span>
                </div>
            </div>
        </div>
        
        <!-- Información de sensores detectados -->
        <div class="sensor-info">
            <p><strong>📊 Información de datos:</strong></p>
            <p><strong>🔍 Buscando datos por:</strong> dispositivo_id = "<?php echo htmlspecialchars($codigo_para_buscar); ?>"</p>
            <p><strong>⏰ Rango de tiempo:</strong> Últimas <?php echo $horas; ?> horas</p>
            
            <?php if(!empty($sensores)): ?>
                <p><strong>📈 Sensores detectados en este dispositivo:</strong></p>
                <div class="sensor-badges">
                    <?php foreach($sensores as $sensor): 
                        $total_datos = isset($datos_por_sensor[$sensor]) ? count($datos_por_sensor[$sensor]) : 0;
                    ?>
                    <div class="sensor-badge" data-sensor="<?php echo $sensor; ?>">
                        <?php echo htmlspecialchars($sensor); ?> (<?php echo $total_datos; ?>)
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><strong>⚠️ No se encontraron sensores para este dispositivo.</strong></p>
                <p>Verifica que el código MQTT sea correcto y que el dispositivo esté enviando datos.</p>
            <?php endif; ?>
        </div>
        
        <div class="filtros">
            <div class="filtro-group">
                <label for="rango-tiempo"><i class="fas fa-clock"></i> Rango de Tiempo</label>
                <select id="rango-tiempo">
                    <option value="1" <?php echo $horas == 1 ? 'selected' : ''; ?>>Última hora</option>
                    <option value="6" <?php echo $horas == 6 ? 'selected' : ''; ?>>Últimas 6 horas</option>
                    <option value="24" <?php echo $horas == 24 ? 'selected' : ''; ?>>Últimas 24 horas</option>
                    <option value="48" <?php echo $horas == 48 ? 'selected' : ''; ?>>Últimas 48 horas</option>
                    <option value="168" <?php echo $horas == 168 ? 'selected' : ''; ?>>Última semana</option>
                </select>
            </div>
            
            <div class="filtro-group">
                <label for="tipo-grafico"><i class="fas fa-chart-bar"></i> Tipo de Gráfico</label>
                <select id="tipo-grafico">
                    <option value="line">Línea</option>
                    <option value="bar">Barras</option>
                </select>
            </div>
            
            <button class="btn-filtrar" onclick="actualizarGraficos()">
                <i class="fas fa-sync-alt"></i> Aplicar Filtros
            </button>
            
            <div style="margin-left: auto;">
                <button onclick="toggleWebSocket()" id="ws-toggle-btn" style="background: #e74c3c; color: white; padding: 10px 15px; border: none; border-radius: 8px; cursor: pointer;">
                    ⚫ Desactivar WebSocket
                </button>
            </div>
        </div>
        
        <div class="graficos-container" id="graficosContainer">
            <?php if(empty($sensores)): ?>
                <div class="sin-datos">
                    <i class="fas fa-database"></i>
                    <h3>No hay datos disponibles</h3>
                    <p>Este dispositivo no tiene datos MQTT registrados.</p>
                    <p><strong>Código MQTT configurado:</strong> "<?php echo htmlspecialchars($codigo_para_buscar); ?>"</p>
                    <p><small>Verifica que:</small></p>
                    <ol style="text-align: left; display: inline-block; margin-top: 10px;">
                        <li>El dispositivo esté encendido y conectado</li>
                        <li>El código MQTT coincida exactamente</li>
                        <li>El dispositivo esté enviando datos al servidor</li>
                    </ol>
                </div>
            <?php else: ?>
                <?php foreach($sensores as $index => $sensor): 
                    $datos_sensor = $datos_por_sensor[$sensor] ?? [];
                    $estadisticas = $estadisticas_por_sensor[$sensor] ?? [];
                    
                    // Si no hay datos para este sensor, saltar
                    if(empty($datos_sensor)) continue;
                    
                    // Determinar icono según tipo de sensor
                    $icono = '📊';
                    $icon_class = 'icon-generic';
                    $unidad = '';
                    
                    if(strpos(strtolower($sensor), 'temp') !== false) {
                        $icono = '🌡️';
                        $icon_class = 'icon-temperature';
                        $unidad = '°C';
                    } elseif(strpos(strtolower($sensor), 'hum') !== false) {
                        $icono = '💧';
                        $icon_class = 'icon-humidity';
                        $unidad = '%';
                    } elseif(strpos(strtolower($sensor), 'volt') !== false) {
                        $icono = '⚡';
                        $unidad = 'V';
                    } elseif(strpos(strtolower($sensor), 'pres') !== false) {
                        $icono = '📊';
                        $unidad = 'hPa';
                    } elseif(strpos(strtolower($sensor), 'co2') !== false) {
                        $icono = '☁️';
                        $unidad = 'ppm';
                    } elseif(strpos(strtolower($sensor), 'luz') !== false) {
                        $icono = '💡';
                        $unidad = 'lux';
                    }
                    
                    // Preparar datos para JavaScript (SOLO este sensor)
                    $labels = [];
                    $values = [];
                    
                    foreach($datos_sensor as $dato) {
                        $labels[] = $dato['hora'];
                        $values[] = floatval($dato['valor']);
                    }
                    
                    // Limitar a 200 puntos máximo para rendimiento
                    if(count($labels) > 200) {
                        $step = ceil(count($labels) / 200);
                        $labels_filtrados = [];
                        $values_filtrados = [];
                        
                        for($i = 0; $i < count($labels); $i += $step) {
                            $labels_filtrados[] = $labels[$i];
                            $values_filtrados[] = $values[$i];
                        }
                        
                        $labels = $labels_filtrados;
                        $values = $values_filtrados;
                    }
                ?>
                <div class="grafico-card" data-sensor="<?php echo $sensor; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s">
                    <div class="grafico-header">
                        <h3>
                            <span class="sensor-icon <?php echo $icon_class; ?>">
                                <?php echo $icono; ?>
                            </span>
                            <?php echo ucfirst($sensor); ?>
                            <span style="font-size: 0.9rem; color: #636e72; margin-left: 10px;">
                                (<span id="count-<?php echo $sensor; ?>"><?php echo count($datos_sensor); ?></span> lecturas)
                            </span>
                        </h3>
                        <div class="sensor-details">
                            <span id="last-<?php echo $sensor; ?>">
                            <?php 
                            if($estadisticas && $estadisticas['ultima_lectura']) {
                                $tiempo = time() - strtotime($estadisticas['ultima_lectura']);
                                if($tiempo < 60) {
                                    echo '<i class="fas fa-clock"></i> Hace ' . $tiempo . ' segundos';
                                } elseif($tiempo < 3600) {
                                    echo '<i class="fas fa-clock"></i> Hace ' . floor($tiempo / 60) . ' minutos';
                                } else {
                                    echo '<i class="fas fa-clock"></i> ' . date('d/m H:i', strtotime($estadisticas['ultima_lectura']));
                                }
                            } else {
                                echo '<i class="fas fa-exclamation-triangle"></i> Sin lecturas recientes';
                            }
                            ?>
                            </span>
                        </div>
                    </div>
                    
                    <div style="position: relative; height: 300px;">
                        <canvas id="chart-<?php echo $sensor; ?>"></canvas>
                    </div>
                    
                    <?php if(!empty($estadisticas) && $estadisticas['total_lecturas'] > 0): ?>
                    <div class="grafico-estadisticas">
                        <div class="estadistica-item">
                            <div class="estadistica-valor" id="total-<?php echo $sensor; ?>"><?php echo $estadisticas['total_lecturas']; ?></div>
                            <div class="estadistica-label">Total lecturas</div>
                        </div>
                        <div class="estadistica-item">
                            <div class="estadistica-valor" id="current-<?php echo $sensor; ?>">
                                <?php 
                                $ultimo_valor = end($values);
                                echo number_format($ultimo_valor, 2); 
                                ?><?php echo $unidad; ?>
                            </div>
                            <div class="estadistica-label">Actual</div>
                        </div>
                        <div class="estadistica-item">
                            <div class="estadistica-valor" id="min-<?php echo $sensor; ?>">
                                <?php echo number_format($estadisticas['minimo'], 2); ?><?php echo $unidad; ?>
                            </div>
                            <div class="estadistica-label">Mínimo</div>
                        </div>
                        <div class="estadistica-item">
                            <div class="estadistica-valor" id="max-<?php echo $sensor; ?>">
                                <?php echo number_format($estadisticas['maximo'], 2); ?><?php echo $unidad; ?>
                            </div>
                            <div class="estadistica-label">Máximo</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Datos para JavaScript (SOLO de este sensor) -->
                    <script type="application/json" id="data-<?php echo $sensor; ?>">
                        <?php echo json_encode([
                            'labels' => $labels,
                            'values' => $values,
                            'sensor' => $sensor,
                            'unidad' => $unidad,
                            'total_lecturas' => count($datos_sensor)
                        ]); ?>
                    </script>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // CONFIGURACIÓN DE GRÁFICOS
        // ============================================
        const sensorColors = {
            'temperatura': { bg: 'rgba(255, 99, 132, 0.2)', border: 'rgba(255, 99, 132, 1)' },
            'temp': { bg: 'rgba(255, 99, 132, 0.2)', border: 'rgba(255, 99, 132, 1)' },
            'humedad': { bg: 'rgba(54, 162, 235, 0.2)', border: 'rgba(54, 162, 235, 1)' },
            'hum': { bg: 'rgba(54, 162, 235, 0.2)', border: 'rgba(54, 162, 235, 1)' },
            'voltaje': { bg: 'rgba(255, 206, 86, 0.2)', border: 'rgba(255, 206, 86, 1)' },
            'volt': { bg: 'rgba(255, 206, 86, 0.2)', border: 'rgba(255, 206, 86, 1)' },
            'presion': { bg: 'rgba(75, 192, 192, 0.2)', border: 'rgba(75, 192, 192, 1)' },
            'pres': { bg: 'rgba(75, 192, 192, 0.2)', border: 'rgba(75, 192, 192, 1)' },
            'co2': { bg: 'rgba(153, 102, 255, 0.2)', border: 'rgba(153, 102, 255, 1)' },
            'luz': { bg: 'rgba(255, 159, 64, 0.2)', border: 'rgba(255, 159, 64, 1)' },
            'luminosidad': { bg: 'rgba(255, 159, 64, 0.2)', border: 'rgba(255, 159, 64, 1)' }
        };
        
        const charts = {};
        let websocketEnabled = true;
        
        // ============================================
        // INICIALIZACIÓN DE GRÁFICOS
        // ============================================
        function inicializarGraficos() {
            document.querySelectorAll('.grafico-card[data-sensor]').forEach(card => {
                const sensor = card.getAttribute('data-sensor');
                const canvasId = `chart-${sensor}`;
                const dataElement = document.getElementById(`data-${sensor}`);
                
                if(!dataElement) {
                    console.warn(`No se encontraron datos para el sensor: ${sensor}`);
                    return;
                }
                
                const data = JSON.parse(dataElement.textContent);
                
                // Si no hay datos, no crear gráfico
                if(data.values.length === 0) {
                    console.warn(`Sensor ${sensor} no tiene datos`);
                    return;
                }
                
                const ctx = document.getElementById(canvasId).getContext('2d');
                
                // Determinar color del sensor (buscando coincidencias parciales)
                let color = { bg: 'rgba(201, 203, 207, 0.2)', border: 'rgba(201, 203, 207, 1)' };
                Object.keys(sensorColors).forEach(key => {
                    if(sensor.toLowerCase().includes(key.toLowerCase())) {
                        color = sensorColors[key];
                    }
                });
                
                charts[sensor] = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: `${sensor.charAt(0).toUpperCase() + sensor.slice(1)} (${data.unidad})`,
                            data: data.values,
                            backgroundColor: color.bg,
                            borderColor: color.border,
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            pointBackgroundColor: color.border
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    font: {
                                        size: 12
                                    }
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
                                        label += context.parsed.y.toFixed(2);
                                        if(data.unidad) {
                                            label += data.unidad;
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                title: {
                                    display: true,
                                    text: data.unidad || 'Valor'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + (data.unidad || '');
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    maxTicksLimit: 10,
                                    autoSkip: true,
                                    maxRotation: 45
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            });
        }
        
        // ============================================
        // CLIENTE WEBSOCKET
        // ============================================
        class IoTWebSocketClient {
            constructor(deviceCode) {
                this.deviceCode = deviceCode;
                this.ws = null;
                this.connected = false;
                this.reconnectAttempts = 0;
                this.maxReconnectAttempts = 10;
                this.reconnectDelay = 3000;
                
                this.stats = {
                    messagesReceived: 0,
                    lastMessageTime: null,
                    errors: 0
                };
            }
            
            connect() {
                if (!websocketEnabled) {
                    console.log('WebSocket deshabilitado por usuario');
                    return;
                }
                
                console.log(`🔗 Conectando WebSocket para: ${this.deviceCode}`);
                
                try {
                    this.ws = new WebSocket('ws://localhost:8080');
                    
                    this.ws.onopen = () => {
                        console.log('✅ WebSocket conectado');
                        this.connected = true;
                        this.reconnectAttempts = 0;
                        updateWebSocketStatus(true);
                        
                        this.ws.send(JSON.stringify({
                            action: 'subscribe',
                            device_id: this.deviceCode
                        }));
                    };
                    
                    this.ws.onmessage = (event) => {
                        this.handleMessage(event.data);
                    };
                    
                    this.ws.onclose = () => {
                        console.log('🔌 WebSocket desconectado');
                        this.connected = false;
                        updateWebSocketStatus(false);
                        this.reconnect();
                    };
                    
                    this.ws.onerror = (error) => {
                        console.error('❌ Error WebSocket:', error);
                        this.stats.errors++;
                    };
                    
                } catch (error) {
                    console.error('❌ Error creando WebSocket:', error);
                    this.reconnect();
                }
            }
            
            handleMessage(data) {
                try {
                    const message = JSON.parse(data);
                    this.stats.messagesReceived++;
                    this.stats.lastMessageTime = new Date();
                    
                    switch(message.type) {
                        case 'welcome':
                            console.log(`👋 ${message.message}`);
                            break;
                        case 'subscription_confirmed':
                            console.log(`✅ Suscrito a: ${message.device_id}`);
                            break;
                        case 'sensor_data':
                            this.processSensorData(message);
                            break;
                        default:
                            console.log('📨 Mensaje:', message);
                    }
                } catch (error) {
                    console.error('❌ Error procesando mensaje:', error);
                }
            }
            
            processSensorData(data) {
                console.log(`📡 Nuevo dato: ${data.sensor} = ${data.value} ${this.getUnit(data.sensor)}`);
                
                // Mostrar indicador de tiempo real
                document.getElementById('live-indicator').style.display = 'inline';
                
                // Actualizar gráfico
                const chart = charts[data.sensor];
                if (!chart) {
                    console.warn(`⚠️ No hay gráfico para: ${data.sensor}`);
                    return;
                }
                
                // Agregar nuevo punto
                const hora = new Date(data.timestamp).toLocaleTimeString([], { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    second: '2-digit' 
                });
                
                chart.data.labels.push(hora);
                chart.data.datasets[0].data.push(data.value);
                
                // Mantener solo últimos 50 puntos
                if (chart.data.labels.length > 50) {
                    chart.data.labels.shift();
                    chart.data.datasets[0].data.shift();
                }
                
                // Actualizar gráfico
                chart.update({
                    duration: 800,
                    easing: 'easeOutQuart'
                });
                
                // Actualizar estadísticas
                this.updateStats(data.sensor, data.value);
                
                // Mostrar notificación
                this.showNotification(data);
            }
            
            updateStats(sensor, value) {
                // Actualizar contador
                const countElement = document.getElementById(`count-${sensor}`);
                if (countElement) {
                    const current = parseInt(countElement.textContent) || 0;
                    countElement.textContent = current + 1;
                }
                
                // Actualizar total
                const totalElement = document.getElementById(`total-${sensor}`);
                if (totalElement) {
                    const current = parseInt(totalElement.textContent) || 0;
                    totalElement.textContent = current + 1;
                }
                
                // Actualizar valor actual
                const currentElement = document.getElementById(`current-${sensor}`);
                if (currentElement) {
                    currentElement.textContent = `${value.toFixed(2)}${this.getUnit(sensor)}`;
                    currentElement.style.animation = 'pulse 0.5s';
                    setTimeout(() => {
                        currentElement.style.animation = '';
                    }, 500);
                }
                
                // Actualizar mínimo
                const minElement = document.getElementById(`min-${sensor}`);
                if (minElement) {
                    const currentMin = parseFloat(minElement.textContent) || value;
                    if (value < currentMin) {
                        minElement.textContent = `${value.toFixed(2)}${this.getUnit(sensor)}`;
                    }
                }
                
                // Actualizar máximo
                const maxElement = document.getElementById(`max-${sensor}`);
                if (maxElement) {
                    const currentMax = parseFloat(maxElement.textContent) || value;
                    if (value > currentMax) {
                        maxElement.textContent = `${value.toFixed(2)}${this.getUnit(sensor)}`;
                    }
                }
                
                // Actualizar última lectura
                const lastElement = document.getElementById(`last-${sensor}`);
                if (lastElement) {
                    const now = new Date();
                    lastElement.innerHTML = `<i class="fas fa-clock"></i> Hace 0 segundos`;
                }
            }
            
            getUnit(sensor) {
                const units = {
                    'temperatura': '°C',
                    'temp': '°C',
                    'humedad': '%',
                    'hum': '%',
                    'voltaje': 'V',
                    'volt': 'V',
                    'presion': 'hPa',
                    'pres': 'hPa',
                    'co2': 'ppm',
                    'luz': 'lux',
                    'luminosidad': 'lux'
                };
                
                for (const [key, unit] of Object.entries(units)) {
                    if (sensor.toLowerCase().includes(key)) {
                        return unit;
                    }
                }
                
                return '';
            }
            
            showNotification(data) {
                const notification = document.createElement('div');
                notification.className = 'live-notification';
                notification.innerHTML = `
                    <div class="notification-icon">📡</div>
                    <div class="notification-content">
                        <strong>${data.sensor.toUpperCase()}</strong>
                        <div class="notification-value">${data.value} ${this.getUnit(data.sensor)}</div>
                        <small>${data.timestamp.split(' ')[1]}</small>
                    </div>
                    <button class="notification-close" onclick="this.parentElement.remove()">×</button>
                `;
                
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: white;
                    border-left: 4px solid #6c5ce7;
                    padding: 15px;
                    border-radius: 8px;
                    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    z-index: 10000;
                    animation: slideInRight 0.3s ease-out;
                    min-width: 250px;
                    max-width: 300px;
                `;
                
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
                        setTimeout(() => notification.remove(), 300);
                    }
                }, 5000);
            }
            
            reconnect() {
                if (!websocketEnabled || this.reconnectAttempts >= this.maxReconnectAttempts) {
                    console.error('❌ Máximo de intentos alcanzado');
                    return;
                }
                
                this.reconnectAttempts++;
                const delay = this.reconnectDelay * this.reconnectAttempts;
                
                console.log(`🔄 Reconectando ${this.reconnectAttempts}/${this.maxReconnectAttempts} en ${delay/1000}s...`);
                
                setTimeout(() => {
                    this.connect();
                }, delay);
            }
            
            disconnect() {
                if (this.ws) {
                    this.ws.close();
                }
            }
        }
        
        // ============================================
        // FUNCIONES AUXILIARES
        // ============================================
        function updateWebSocketStatus(connected) {
            const statusElement = document.getElementById('ws-status-text');
            const toggleBtn = document.getElementById('ws-toggle-btn');
            
            if (connected) {
                statusElement.innerHTML = '🟢 Conectado';
                statusElement.style.color = '#27ae60';
                document.getElementById('live-indicator').style.display = 'inline';
            } else {
                statusElement.innerHTML = '🔴 Desconectado';
                statusElement.style.color = '#e74c3c';
                document.getElementById('live-indicator').style.display = 'none';
            }
        }
        
        function toggleWebSocket() {
            websocketEnabled = !websocketEnabled;
            const toggleBtn = document.getElementById('ws-toggle-btn');
            
            if (websocketEnabled) {
                toggleBtn.innerHTML = '⚫ Desactivar WebSocket';
                toggleBtn.style.background = '#e74c3c';
                
                if (window.iotWebSocket) {
                    window.iotWebSocket.connect();
                }
            } else {
                toggleBtn.innerHTML = '🟢 Activar WebSocket';
                toggleBtn.style.background = '#27ae60';
                
                if (window.iotWebSocket) {
                    window.iotWebSocket.disconnect();
                    updateWebSocketStatus(false);
                }
            }
        }
        
        function actualizarGraficos() {
            const rangoTiempo = document.getElementById('rango-tiempo').value;
            const tipoGrafico = document.getElementById('tipo-grafico').value;
            const dispositivoId = <?php echo $dispositivo_id; ?>;
            
            if (tipoGrafico === 'bar') {
                Object.values(charts).forEach(chart => {
                    chart.config.type = 'bar';
                    chart.update();
                });
            }
            
            window.location.href = `ver_datos.php?id=${dispositivoId}&horas=${rangoTiempo}`;
        }
        
        function addWebSocketControls() {
            const controlsContainer = document.createElement('div');
            controlsContainer.id = 'websocket-controls';
            controlsContainer.style.cssText = `
                position: fixed;
                bottom: 70px;
                left: 20px;
                display: flex;
                gap: 10px;
                z-index: 1000;
            `;
            
            const reconnectBtn = document.createElement('button');
            reconnectBtn.innerHTML = '🔄 Reconectar';
            reconnectBtn.style.cssText = `
                padding: 8px 15px;
                background: #f39c12;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: 600;
            `;
            reconnectBtn.onclick = () => {
                if (window.iotWebSocket) {
                    window.iotWebSocket.disconnect();
                    setTimeout(() => window.iotWebSocket.connect(), 1000);
                }
            };
            
            const statsBtn = document.createElement('button');
            statsBtn.innerHTML = '📊 Estadísticas';
            statsBtn.style.cssText = `
                padding: 8px 15px;
                background: #3498db;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: 600;
            `;
            statsBtn.onclick = () => {
                if (window.iotWebSocket) {
                    const stats = window.iotWebSocket.stats;
                    alert(`📊 Estadísticas WebSocket:
• Mensajes recibidos: ${stats.messagesReceived}
• Errores: ${stats.errors}
• Último mensaje: ${stats.lastMessageTime ? stats.lastMessageTime.toLocaleTimeString() : 'Nunca'}
• Conectado: ${window.iotWebSocket.connected ? 'Sí' : 'No'}`);
                }
            };
            
            controlsContainer.appendChild(reconnectBtn);
            controlsContainer.appendChild(statsBtn);
            document.body.appendChild(controlsContainer);
        }
        
        function checkWebSocketService() {
            fetch('http://localhost:8080', { 
                method: 'HEAD',
                mode: 'no-cors' 
            }).then(() => {
                console.log('✅ Servicio WebSocket disponible');
            }).catch(() => {
                console.warn('⚠️ Servicio WebSocket no disponible');
                console.info('💡 Ejecuta: php websocket_server.php en otra terminal');
            });
        }
        
        // ============================================
        // INICIALIZACIÓN
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const dispositivoCodigo = <?php echo json_encode($dispositivo['codigo'] ?? ''); ?>;
            
            if (dispositivoCodigo) {
                // Inicializar gráficos
                inicializarGraficos();
                
                // Iniciar WebSocket
                window.iotWebSocket = new IoTWebSocketClient(dispositivoCodigo);
                window.iotWebSocket.connect();
                
                // Agregar controles
                addWebSocketControls();
                
                // Verificar servicio
                setTimeout(checkWebSocketService, 2000);
                
                console.log('🚀 WebSocket inicializado para:', dispositivoCodigo);
            }
        });
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>