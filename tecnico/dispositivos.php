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

// Obtener dispositivos del técnico - CONSULTA CORREGIDA
$stmt = $pdo->prepare("
    SELECT d.*, u.username as cliente_nombre 
    FROM dispositivos d
    LEFT JOIN users u ON d.cliente_id = u.id
    WHERE d.tecnico_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$tecnico_id]);
$dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            </div>
            
            <div class="stats-card">
                <h3 style="color: #2d3436; margin-bottom: 15px; font-size: 1.1rem;">
                    <i class="fas fa-chart-bar"></i> Estadísticas
                </h3>
                
                <?php
                // Calcular estadísticas
                $total = count($dispositivos);
                $activos = 0;
                $mqtt_count = 0;
                
                foreach($dispositivos as $disp) {
                    if($disp['estado'] == 'activo') $activos++;
                    if($disp['protocolo'] == 'MQTT') $mqtt_count++;
                }
                
                $inactivos = $total - $activos;
                $porcentaje_activos = $total > 0 ? round(($activos / $total) * 100) : 0;
                ?>
                
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
                    <span class="stat-label">Técnico ID</span>
                    <span class="stat-value">#<?php echo $tecnico_id; ?></span>
                </div>
            </div>
            
            <a href="crear_dispositivo.php" class="btn-add-device">
                <i class="fas fa-plus-circle"></i> Agregar Nuevo Dispositivo
            </a>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="dashboard_mqtt.php" style="color: #6c5ce7; text-decoration: none; font-weight: 500;">
                    <i class="fas fa-satellite-dish"></i> Ir a Dashboard MQTT
                </a>
            </div>
        </div>
        
        <!-- Contenido principal -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-sitemap"></i> Mis Dispositivos IoT</h1>
                <p>Gestiona y monitorea todos tus dispositivos conectados</p>
            </div>
            
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
                    ?>
                    <div class="device-card" 
                         data-type="<?php echo strtolower($disp['tipo']); ?>"
                         data-protocol="<?php echo $disp['protocolo']; ?>"
                         data-status="<?php echo $disp['estado']; ?>"
                         data-name="<?php echo strtolower($disp['nombre']); ?>"
                         data-location="<?php echo strtolower($disp['ubicacion'] ?? ''); ?>">
                        
                        <div class="device-header">
                            <div class="device-icon <?php echo $icon_class; ?>">
                                <?php echo $icon; ?>
                            </div>
                            <div class="device-info">
                                <h3><?php echo htmlspecialchars($disp['nombre']); ?></h3>
                                <p><?php echo htmlspecialchars($disp['tipo']); ?></p>
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
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-calendar-alt"></i> Instalación</span>
                                <span class="detail-value"><?php echo $fecha_instalacion; ?></span>
                            </div>
                        </div>
                        
                        <div class="device-actions">
                            <a href="editar_dispositivo.php?id=<?php echo $disp['id']; ?>" class="btn-action btn-edit">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <a href="ver_datos.php?dispositivo_id=<?php echo $disp['id']; ?>" class="btn-action btn-view">
                                <i class="fas fa-chart-line"></i> Ver Datos
                            </a>
                            <?php if($disp['protocolo'] == 'MQTT'): ?>
                                <a href="dashboard_mqtt.php?device=<?php echo $disp['id']; ?>" class="btn-action btn-mqtt">
                                    <i class="fas fa-satellite-dish"></i> MQTT
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterDevices() {
            const typeFilter = document.getElementById('filterType').value.toLowerCase();
            const protocolFilter = document.getElementById('filterProtocol').value;
            const statusFilter = document.getElementById('filterStatus').value;
            const searchTerm = document.getElementById('searchDevice').value.toLowerCase();
            
            const deviceCards = document.querySelectorAll('.device-card');
            
            deviceCards.forEach(card => {
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
                
                // Mostrar/ocultar tarjeta
                card.style.display = show ? 'block' : 'none';
            });
        }
        
        // Ordenar tarjetas por animación
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.device-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>