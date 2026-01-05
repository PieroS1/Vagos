<?php
// Activar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// Verificar sesión
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Variables
$mensaje = '';
$tipo_mensaje = '';
$clientes = [];
$dispositivos_disponibles = [];
$todos_dispositivos = [];
$estadisticas = [];

// ========== OBTENER DATOS DE LA BASE DE DATOS ==========
try {
    // 1. Obtener estadísticas generales (ACTUALIZADO para usar tabla dispositivos)
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT u.id) as total_clientes,
            COUNT(DISTINCT CASE WHEN u.status = 'active' THEN u.id END) as clientes_activos,
            COUNT(DISTINCT d.id) as total_dispositivos,
            COUNT(DISTINCT CASE WHEN d.cliente_id IS NULL THEN d.id END) as dispositivos_libres,
            COUNT(DISTINCT CASE WHEN d.protocolo = 'MQTT' THEN d.id END) as dispositivos_mqtt
        FROM users u
        LEFT JOIN dispositivos d ON u.id = d.cliente_id
        WHERE u.role = 'cliente'
    ");
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Obtener todos los clientes con sus datos (ACTUALIZADO)
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.email,
            u.status,
            u.full_name,
            u.phone,
            u.created_at,
            COUNT(DISTINCT d.id) as total_dispositivos,
            GROUP_CONCAT(DISTINCT d.nombre ORDER BY d.nombre SEPARATOR ', ') as lista_dispositivos,
            GROUP_CONCAT(DISTINCT d.codigo ORDER BY d.codigo SEPARATOR ', ') as lista_codigos,
            MAX(md.timestamp) as ultima_lectura,
            AVG(CASE WHEN md.sensor = 'temperatura' THEN md.valor END) as temp_promedio,
            AVG(CASE WHEN md.sensor = 'humedad' THEN md.valor END) as hum_promedio
        FROM users u
        LEFT JOIN dispositivos d ON u.id = d.cliente_id
        LEFT JOIN mqtt_data md ON d.codigo = md.dispositivo_id
        WHERE u.role = 'cliente'
        GROUP BY u.id, u.username, u.email, u.status, u.full_name, u.phone, u.created_at
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener dispositivos disponibles (sin asignar) de la tabla dispositivos
    $stmt = $pdo->query("
        SELECT 
            d.id,
            d.nombre,
            d.codigo,
            d.tipo,
            d.protocolo
        FROM dispositivos d
        WHERE d.cliente_id IS NULL 
        ORDER BY d.nombre
    ");
    $dispositivos_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Obtener todos los dispositivos para el select
    $stmt = $pdo->query("
        SELECT 
            d.id,
            d.nombre,
            d.codigo,
            CONCAT(d.nombre, ' (', d.codigo, ') - ', d.tipo) as display_name
        FROM dispositivos d
        WHERE d.codigo IS NOT NULL
        ORDER BY d.nombre
    ");
    $todos_dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(Exception $e) {
    $mensaje = "Error obteniendo datos: " . $e->getMessage();
    $tipo_mensaje = "error";
}

// ========== CREAR CLIENTE ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["crear_cliente"])){
    try {
        $username = trim($_POST["username"]);
        $email = trim($_POST["email"]);
        $password = $_POST["password"];
        $full_name = trim($_POST["full_name"] ?? '');
        $phone = trim($_POST["phone"] ?? '');
        
        // Validaciones
        if(empty($username) || empty($email) || empty($password)){
            throw new Exception("Todos los campos marcados con * son obligatorios");
        }
        
        if(strlen($password) < 6){
            throw new Exception("La contraseña debe tener al menos 6 caracteres");
        }
        
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            throw new Exception("El email no tiene un formato válido");
        }
        
        // Verificar si ya existe
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if($stmt->rowCount() > 0){
            throw new Exception("El usuario o email ya existe en el sistema");
        }
        
        // Crear hash de contraseña
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insertar en transacción
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, role, status, full_name, phone) 
            VALUES (?, ?, ?, 'cliente', 'active', ?, ?)
        ");
        $stmt->execute([$username, $email, $password_hash, $full_name, $phone]);
        
        $cliente_id = $pdo->lastInsertId();
        
        // Registrar acción en logs si existe la tabla
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_acciones (usuario_id, accion, detalles, fecha) 
                VALUES (?, 'crear_cliente', ?, NOW())
            ");
            $stmt->execute([$_SESSION['user_id'], "Cliente creado: $username ($email)"]);
        } catch(Exception $e) {
            // Tabla no existe, continuar
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Cliente creado exitosamente - ID: $cliente_id";
        $tipo_mensaje = "success";
        
        // Refrescar datos
        header("Location: gestionar_clientes.php?success=1");
        exit();
        
    } catch(Exception $e) {
        if($pdo->inTransaction()){
            $pdo->rollBack();
        }
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// ========== ASIGNAR DISPOSITIVO ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["asignar_dispositivo"])){
    try {
        $cliente_id = intval($_POST["cliente_id"]);
        $dispositivo_id = intval($_POST["dispositivo_id"]);
        
        if(empty($cliente_id) || empty($dispositivo_id)){
            throw new Exception("Selecciona un cliente y un dispositivo");
        }
        
        // Verificar que el cliente existe y es cliente
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'cliente'");
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();
        
        if(!$cliente){
            throw new Exception("El cliente no existe o no tiene rol de cliente");
        }
        
        // Verificar que el dispositivo existe en la tabla dispositivos
        $stmt = $pdo->prepare("SELECT id, nombre, codigo, cliente_id FROM dispositivos WHERE id = ?");
        $stmt->execute([$dispositivo_id]);
        $dispositivo = $stmt->fetch();
        
        if(!$dispositivo){
            throw new Exception("El dispositivo no existe en la base de datos");
        }
        
        // Verificar si ya está asignado a otro cliente
        if($dispositivo['cliente_id'] && $dispositivo['cliente_id'] != $cliente_id){
            // Obtener nombre del cliente actual
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$dispositivo['cliente_id']]);
            $cliente_actual = $stmt->fetchColumn();
            throw new Exception("El dispositivo ya está asignado al cliente: $cliente_actual");
        }
        
        // Actualizar asignación en transacción
        $pdo->beginTransaction();
        
        // Actualizar la tabla dispositivos
        $stmt = $pdo->prepare("UPDATE dispositivos SET cliente_id = ? WHERE id = ?");
        $stmt->execute([$cliente_id, $dispositivo_id]);
        
        // Si el dispositivo tiene código MQTT, actualizar también mqtt_data
        if(!empty($dispositivo['codigo'])) {
            $stmt = $pdo->prepare("UPDATE mqtt_data SET cliente_id = ? WHERE dispositivo_id = ?");
            $stmt->execute([$cliente_id, $dispositivo['codigo']]);
        }
        
        // Registrar en logs
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_acciones (usuario_id, accion, detalles, fecha) 
                VALUES (?, 'asignar_dispositivo', ?, NOW())
            ");
            $stmt->execute([$_SESSION['user_id'], "Dispositivo {$dispositivo['nombre']} ({$dispositivo['codigo']}) asignado a cliente ID: $cliente_id"]);
        } catch(Exception $e) {
            // Tabla no existe
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Dispositivo '{$dispositivo['nombre']}' asignado exitosamente al cliente " . htmlspecialchars($cliente['username']);
        $tipo_mensaje = "success";
        
        // Refrescar datos
        header("Location: gestionar_clientes.php?success=1");
        exit();
        
    } catch(Exception $e) {
        if(isset($pdo) && $pdo->inTransaction()){
            $pdo->rollBack();
        }
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// ========== ELIMINAR CLIENTE ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["eliminar_cliente"])){
    try {
        $cliente_id = intval($_POST["eliminar_cliente"]);
        
        // Verificar que existe y es cliente
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'cliente'");
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();
        
        if(!$cliente){
            throw new Exception("El cliente no existe");
        }
        
        // Confirmación adicional (se hará con JavaScript)
        if(!isset($_POST['confirmar_eliminacion'])){
            throw new Exception("Confirmación requerida");
        }
        
        // Iniciar transacción
        $pdo->beginTransaction();
        
        // 1. Obtener información para el log
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispositivos WHERE cliente_id = ?");
        $stmt->execute([$cliente_id]);
        $dispositivos_asignados = $stmt->fetchColumn();
        
        // 2. Desasignar dispositivos en tabla dispositivos
        $stmt = $pdo->prepare("UPDATE dispositivos SET cliente_id = NULL WHERE cliente_id = ?");
        $stmt->execute([$cliente_id]);
        
        // 3. Desasignar dispositivos en mqtt_data
        $stmt = $pdo->prepare("UPDATE mqtt_data SET cliente_id = NULL WHERE cliente_id = ?");
        $stmt->execute([$cliente_id]);
        
        // 4. Eliminar cliente
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$cliente_id]);
        
        // 5. Registrar en logs
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_acciones (usuario_id, accion, detalles, fecha) 
                VALUES (?, 'eliminar_cliente', ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                "Cliente eliminado: " . $cliente['username'] . " (ID: $cliente_id) - Dispositivos desasignados: $dispositivos_asignados"
            ]);
        } catch(Exception $e) {
            // Tabla no existe
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Cliente eliminado correctamente. Se desasignaron $dispositivos_asignados dispositivos.";
        $tipo_mensaje = "success";
        
        // Refrescar datos
        header("Location: gestionar_clientes.php?success=1");
        exit();
        
    } catch(Exception $e) {
        if($pdo->inTransaction()){
            $pdo->rollBack();
        }
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes - Sistema IoT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --light-color: #f8f9fa;
            --dark-color: #212529;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
            margin: 30px auto;
            overflow: hidden;
            max-width: 1400px;
        }
        
        .header-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .header-gradient::before {
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
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border-left: 5px solid var(--primary-color);
            transition: transform 0.3s ease;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0.5rem 0;
        }
        
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .nav-tabs-custom .nav-link {
            color: var(--dark-color);
            font-weight: 500;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
        }
        
        .nav-tabs-custom .nav-link.active {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .badge-active {
            background: linear-gradient(135deg, var(--success-color), #04b486);
            color: white;
        }
        
        .badge-inactive {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        
        .badge-count {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-size: 0.9rem;
            padding: 4px 10px;
        }
        
        .device-list {
            max-height: 200px;
            overflow-y: auto;
            background: var(--light-color);
            border-radius: 10px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .device-tag {
            display: inline-block;
            background: #e3f2fd;
            padding: 5px 12px;
            margin: 3px;
            border-radius: 15px;
            font-size: 0.85rem;
            border-left: 3px solid var(--primary-color);
        }
        
        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-assign {
            background: linear-gradient(135deg, var(--success-color), #04b486);
            color: white;
        }
        
        .btn-assign:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(6, 214, 160, 0.3);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, var(--danger-color), #d90429);
            color: white;
        }
        
        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 71, 111, 0.3);
        }
        
        .modal-custom .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .search-box {
            border-radius: 25px;
            padding-left: 40px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }
        
        .search-box:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .dataTables_wrapper {
            padding: 0;
        }
        
        .dataTables_length select {
            border-radius: 8px;
            padding: 6px 12px;
        }
        
        .dataTables_filter input {
            border-radius: 8px;
            padding: 6px 12px;
            border: 2px solid #e2e8f0;
        }
        
        .dataTables_filter input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .dataTable {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .dataTable thead th {
            background: var(--light-color);
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: var(--dark-color);
            padding: 1rem;
        }
        
        .dataTable tbody tr {
            transition: background-color 0.2s;
        }
        
        .dataTable tbody tr:hover {
            background: #f8f9fa;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border: 1px solid #dee2e6;
            margin: 0 2px;
            border-radius: 8px;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-color: var(--primary-color);
            color: white;
        }
        
        @media (max-width: 768px) {
            .main-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .stats-number {
                font-size: 2rem;
            }
            
            .header-gradient {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="main-container">
            <!-- Header -->
            <div class="header-gradient">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2">
                            <i class="bi bi-people-fill me-2"></i>Gestión de Clientes
                        </h1>
                        <p class="mb-0 opacity-90">Administra clientes y asigna dispositivos IoT</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="index.php" class="btn btn-light">
                                <i class="bi bi-house-door me-1"></i> Dashboard
                            </a>
                            <a href="dashboard_mqtt.php" class="btn btn-outline-light">
                                <i class="bi bi-graph-up me-1"></i> Monitoreo
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contenido principal -->
            <div class="container-fluid p-4">
                <!-- Mensajes -->
                <?php if($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje == 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                    <?php if($tipo_mensaje == 'success'): ?>
                        <i class="bi bi-check-circle-fill me-2"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php endif; ?>
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="bi bi-people-fill text-primary fs-4"></i>
                                </div>
                                <div>
                                    <div class="stats-number"><?= $estadisticas['total_clientes'] ?? 0 ?></div>
                                    <div class="text-muted">Total Clientes</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="bi bi-person-check-fill text-success fs-4"></i>
                                </div>
                                <div>
                                    <div class="stats-number"><?= $estadisticas['clientes_activos'] ?? 0 ?></div>
                                    <div class="text-muted">Clientes Activos</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-warning bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="bi bi-cpu-fill text-warning fs-4"></i>
                                </div>
                                <div>
                                    <div class="stats-number"><?= $estadisticas['total_dispositivos'] ?? 0 ?></div>
                                    <div class="text-muted">Dispositivos Totales</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-info bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="bi bi-wifi text-info fs-4"></i>
                                </div>
                                <div>
                                    <div class="stats-number"><?= $estadisticas['dispositivos_libres'] ?? 0 ?></div>
                                    <div class="text-muted">Dispositivos Libres</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pestañas -->
                <ul class="nav nav-tabs nav-tabs-custom mb-4" id="myTab" role="tablist">
                     <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-arrow-left me-2"></i>Panel Principal
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="crear-tab" data-bs-toggle="tab" data-bs-target="#crear" type="button">
                            <i class="bi bi-person-plus me-2"></i>Crear Cliente
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="asignar-tab" data-bs-toggle="tab" data-bs-target="#asignar" type="button">
                            <i class="bi bi-link me-2"></i>Asignar Dispositivo
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="lista-tab" data-bs-toggle="tab" data-bs-target="#lista" type="button">
                            <i class="bi bi-list-ul me-2"></i>Lista de Clientes
                        </button>
                        
                    </li>
                   
                </ul>
                
                <div class="tab-content" id="myTabContent">
                    <!-- Tab 1: Crear Cliente -->
                    <div class="tab-pane fade show active" id="crear" role="tabpanel">
                        <div class="form-card">
                            <h4 class="mb-4">
                                <i class="bi bi-person-plus me-2 text-primary"></i>Crear Nuevo Cliente
                            </h4>
                            <form method="POST" id="clienteForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-person me-1"></i> Nombre Completo
                                        </label>
                                        <input type="text" name="full_name" class="form-control" placeholder="Ej: Juan Pérez">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-at me-1"></i> Nombre de Usuario *
                                        </label>
                                        <input type="text" name="username" class="form-control" required placeholder="Ej: juanperez">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-envelope me-1"></i> Email *
                                        </label>
                                        <input type="email" name="email" class="form-control" required placeholder="cliente@ejemplo.com">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-phone me-1"></i> Teléfono
                                        </label>
                                        <input type="text" name="phone" class="form-control" placeholder="+1234567890">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-lock me-1"></i> Contraseña *
                                        </label>
                                        <input type="password" name="password" class="form-control" required minlength="6" id="password">
                                        <div class="form-text">Mínimo 6 caracteres</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-lock-fill me-1"></i> Confirmar Contraseña *
                                        </label>
                                        <input type="password" class="form-control" required minlength="6" id="confirmPassword">
                                        <div class="form-text" id="passwordMatch"></div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="crear_cliente" class="btn btn-primary px-4">
                                        <i class="bi bi-save me-2"></i>Crear Cliente
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary ms-2">
                                        <i class="bi bi-x-circle me-2"></i>Limpiar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tab 2: Asignar Dispositivo -->
                    <div class="tab-pane fade" id="asignar" role="tabpanel">
                        <div class="form-card">
                            <h4 class="mb-4">
                                <i class="bi bi-link me-2 text-success"></i>Asignar Dispositivo a Cliente
                            </h4>
                            <form method="POST" id="asignarForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-person me-1"></i> Seleccionar Cliente *
                                        </label>
                                        <select name="cliente_id" class="form-control select2" required id="selectCliente">
                                            <option value="">-- Selecciona un cliente --</option>
                                            <?php foreach($clientes as $cliente): ?>
                                            <option value="<?= $cliente['id'] ?>" data-dispositivos="<?= $cliente['total_dispositivos'] ?>">
                                                <?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?> 
                                                (<?= $cliente['email'] ?>)
                                                <?php if($cliente['total_dispositivos'] > 0): ?>
                                                - <?= $cliente['total_dispositivos'] ?> disp.
                                                <?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-cpu me-1"></i> Seleccionar Dispositivo *
                                        </label>
                                        <select name="dispositivo_id" class="form-control select2" required id="selectDispositivo">
                                            <option value="">-- Selecciona un dispositivo --</option>
                                            <?php foreach($dispositivos_disponibles as $dispositivo): ?>
                                            <option value="<?= $dispositivo['id'] ?>">
                                                <?= htmlspecialchars($dispositivo['nombre']) ?> 
                                                (<?= htmlspecialchars($dispositivo['codigo']) ?> - <?= $dispositivo['tipo'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Dispositivos disponibles:</strong> 
                                    <?php if(empty($dispositivos_disponibles)): ?>
                                        No hay dispositivos disponibles para asignar
                                    <?php else: ?>
                                        <?= count($dispositivos_disponibles) ?> dispositivo(s) sin asignar
                                    <?php endif; ?>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="asignar_dispositivo" class="btn btn-success px-4">
                                        <i class="bi bi-link me-2"></i>Asignar Dispositivo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tab 3: Lista de Clientes -->
                    <div class="tab-pane fade" id="lista" role="tabpanel">
                        <div class="table-container">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="mb-0">
                                    <i class="bi bi-people me-2 text-primary"></i>Clientes Registrados
                                </h4>
                                <div class="position-relative" style="width: 300px;">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" class="form-control search-box" id="searchInput" placeholder="Buscar cliente...">
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover" id="clientesTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Cliente</th>
                                            <th>Contacto</th>
                                            <th>Estado</th>
                                            <th>Dispositivos</th>
                                            <th>Registro</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($clientes)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="bi bi-people display-4 mb-3"></i>
                                                    <h5>No hay clientes registrados</h5>
                                                    <p class="mb-0">Comienza creando tu primer cliente</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($clientes as $cliente): 
                                                // Convertir lista de dispositivos en array
                                                $dispositivos_array = $cliente['lista_dispositivos'] ? 
                                                    explode(', ', $cliente['lista_dispositivos']) : [];
                                                $codigos_array = $cliente['lista_codigos'] ? 
                                                    explode(', ', $cliente['lista_codigos']) : [];
                                            ?>
                                            <tr data-cliente-id="<?= $cliente['id'] ?>">
                                                <td>
                                                    <strong class="text-primary">#<?= $cliente['id'] ?></strong>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-3">
                                                            <i class="bi bi-person text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <strong><?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?></strong><br>
                                                            <small class="text-muted">@<?= htmlspecialchars($cliente['username']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cliente['email']) ?><br>
                                                        <?php if($cliente['phone']): ?>
                                                        <small><i class="bi bi-phone me-1"></i><?= htmlspecialchars($cliente['phone']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge-status badge-<?= $cliente['status'] == 'active' ? 'active' : 'inactive' ?>">
                                                        <?= $cliente['status'] == 'active' ? 'Activo' : 'Inactivo' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge-count"><?= $cliente['total_dispositivos'] ?></span>
                                                    <?php if(!empty($dispositivos_array)): ?>
                                                    <div class="device-list mt-2">
                                                        <?php foreach(array_slice($dispositivos_array, 0, 3) as $index => $dispositivo): 
                                                            $codigo = isset($codigos_array[$index]) ? $codigos_array[$index] : '';
                                                        ?>
                                                        <span class="device-tag">
                                                            <?= htmlspecialchars($dispositivo) ?>
                                                            <?php if($codigo): ?>
                                                                <small class="text-muted">(<?= htmlspecialchars($codigo) ?>)</small>
                                                            <?php endif; ?>
                                                        </span>
                                                        <?php endforeach; ?>
                                                        <?php if(count($dispositivos_array) > 3): ?>
                                                        <span class="device-tag">+<?= count($dispositivos_array) - 3 ?> más</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?= date('d/m/Y', strtotime($cliente['created_at'])) ?><br>
                                                        <small class="text-muted"><?= date('H:i', strtotime($cliente['created_at'])) ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <button class="action-btn btn-view" onclick="viewClientDevices(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?>')">
                                                            <i class="bi bi-eye me-1"></i>Ver
                                                        </button>
                                                        <button class="action-btn btn-assign" onclick="quickAssign(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?>')">
                                                            <i class="bi bi-plus-circle me-1"></i>Asignar
                                                        </button>
                                                        <form method="POST" class="delete-form" data-cliente="<?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?>">
                                                            <input type="hidden" name="eliminar_cliente" value="<?= $cliente['id'] ?>">
                                                            <button type="button" class="action-btn btn-delete" onclick="confirmDelete(this)">
                                                                <i class="bi bi-trash me-1"></i>Eliminar
                                                            </button>
                                                        </form>
                                                    </div>
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
        </div>
    </div>

    <!-- Modal para ver dispositivos del cliente -->
    <div class="modal fade modal-custom" id="devicesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cpu me-2"></i>Dispositivos del Cliente
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modalContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para asignación rápida -->
    <div class="modal fade modal-custom" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignModalTitle">
                        <i class="bi bi-link me-2"></i>Asignar Dispositivo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="quickAssignForm">
                        <input type="hidden" name="cliente_id" id="quickClienteId">
                        <div class="mb-3">
                            <label class="form-label">Seleccionar Dispositivo:</label>
                            <select name="dispositivo_id" class="form-control" required>
                                <option value="">-- Selecciona --</option>
                                <?php foreach($dispositivos_disponibles as $dispositivo): ?>
                                <option value="<?= $dispositivo['id'] ?>">
                                    <?= htmlspecialchars($dispositivo['nombre']) ?> 
                                    (<?= htmlspecialchars($dispositivo['codigo']) ?> - <?= $dispositivo['tipo'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Solo se muestran dispositivos disponibles
                        </div>
                        <div class="text-end">
                            <button type="submit" name="asignar_dispositivo" class="btn btn-success">
                                <i class="bi bi-link me-1"></i>Asignar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
    // Inicializar DataTable
    $(document).ready(function() {
        const table = $('#clientesTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
            },
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']]
        });
        
        // Búsqueda personalizada
        $('#searchInput').on('keyup', function() {
            table.search(this.value).draw();
        });
    });

    // Validación de contraseñas
    document.getElementById('confirmPassword').addEventListener('input', function() {
        const password = document.getElementById('password').value;
        const confirm = this.value;
        const matchDiv = document.getElementById('passwordMatch');
        
        if(confirm === '') {
            matchDiv.innerHTML = '';
            matchDiv.className = 'form-text';
        } else if(password === confirm) {
            matchDiv.innerHTML = '<i class="bi bi-check-circle text-success"></i> Las contraseñas coinciden';
            matchDiv.className = 'form-text text-success';
        } else {
            matchDiv.innerHTML = '<i class="bi bi-x-circle text-danger"></i> Las contraseñas no coinciden';
            matchDiv.className = 'form-text text-danger';
        }
    });

    document.getElementById('clienteForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirmPassword').value;
        
        if(password.length < 6) {
            e.preventDefault();
            alert('La contraseña debe tener al menos 6 caracteres');
            document.getElementById('password').focus();
            return;
        }
        
        if(password !== confirm) {
            e.preventDefault();
            alert('Las contraseñas no coinciden');
            document.getElementById('confirmPassword').focus();
            return;
        }
    });

    // Ver dispositivos del cliente (ACTUALIZADO)
    function viewClientDevices(clienteId, clienteName) {
        fetch(`get_cliente_dispositivos.php?cliente_id=${clienteId}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('modalContent').innerHTML = data;
                const modal = new bootstrap.Modal(document.getElementById('devicesModal'));
                modal.show();
            })
            .catch(error => {
                document.getElementById('modalContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error al cargar dispositivos: ${error.message}
                    </div>
                `;
                const modal = new bootstrap.Modal(document.getElementById('devicesModal'));
                modal.show();
            });
    }

    // Asignación rápida
    function quickAssign(clienteId, clienteName) {
        document.getElementById('quickClienteId').value = clienteId;
        document.getElementById('assignModalTitle').innerHTML = 
            `<i class="bi bi-link me-2"></i>Asignar Dispositivo a ${clienteName}`;
        
        const modal = new bootstrap.Modal(document.getElementById('assignModal'));
        modal.show();
    }

    // Confirmar eliminación
    function confirmDelete(button) {
        const form = button.closest('form');
        const clienteName = form.getAttribute('data-cliente');
        
        if(confirm(`¿Estás seguro de que deseas eliminar al cliente "${clienteName}"?\n\nEsta acción desasignará todos sus dispositivos pero NO eliminará los datos históricos.`)) {
            // Agregar campo de confirmación
            const confirmInput = document.createElement('input');
            confirmInput.type = 'hidden';
            confirmInput.name = 'confirmar_eliminacion';
            confirmInput.value = '1';
            form.appendChild(confirmInput);
            
            form.submit();
        }
    }

    // Cambiar automáticamente a pestaña de lista si hay mensaje de éxito
    <?php if(isset($_GET['success'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const listaTab = new bootstrap.Tab(document.getElementById('lista-tab'));
        listaTab.show();
    });
    <?php endif; ?>

    // Auto-focus en campos según la pestaña
    document.addEventListener('DOMContentLoaded', function() {
        const tabTriggers = document.querySelectorAll('[data-bs-toggle="tab"]');
        tabTriggers.forEach(tab => {
            tab.addEventListener('shown.bs.tab', function(event) {
                const target = event.target.getAttribute('data-bs-target');
                switch(target) {
                    case '#crear':
                        document.querySelector('#crear input[name="full_name"]').focus();
                        break;
                    case '#asignar':
                        document.querySelector('#asignar select[name="cliente_id"]').focus();
                        break;
                    case '#lista':
                        document.querySelector('#lista #searchInput').focus();
                        break;
                }
            });
        });
    });
    </script>
</body>
</html>