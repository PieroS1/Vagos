<?php
// Activar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// Verificar sesión
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Variables
$mensaje = '';
$tipo_mensaje = '';
$clientes = [];
$tecnicos = [];
$clientes_por_tecnico = [];
$dispositivos_disponibles = [];
$todos_dispositivos = [];
$estadisticas = [];

// ========== OBTENER DATOS DE LA BASE DE DATOS ==========
try {
    // 1. Obtener estadísticas generales
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT CASE WHEN u.role = 'cliente' THEN u.id END) as total_clientes,
            COUNT(DISTINCT CASE WHEN u.role = 'cliente' AND u.status = 'active' THEN u.id END) as clientes_activos,
            COUNT(DISTINCT CASE WHEN u.role = 'tecnico' AND u.status = 'active' THEN u.id END) as total_tecnicos,
            COUNT(DISTINCT d.id) as total_dispositivos,
            COUNT(DISTINCT CASE WHEN d.cliente_id IS NULL THEN d.id END) as dispositivos_libres,
            COUNT(DISTINCT CASE WHEN d.protocolo = 'MQTT' THEN d.id END) as dispositivos_mqtt
        FROM users u
        LEFT JOIN dispositivos d ON u.id = d.cliente_id
    ");
    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Obtener todos los técnicos activos
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.email,
            u.status,
            u.full_name,
            u.phone,
            u.created_at,
            COUNT(DISTINCT c.id) as total_clientes_asignados,
            GROUP_CONCAT(DISTINCT CONCAT(c.full_name, ' (ID:', c.id, ')') ORDER BY c.full_name SEPARATOR ', ') as lista_clientes,
            COUNT(DISTINCT d.id) as dispositivos_gestionados
        FROM users u
        LEFT JOIN users c ON c.id IN (SELECT cliente_id FROM dispositivos WHERE tecnico_id = u.id)
        LEFT JOIN dispositivos d ON c.id = d.cliente_id
        WHERE u.role = 'tecnico' AND u.status = 'active'
        GROUP BY u.id, u.username, u.email, u.status, u.full_name, u.phone, u.created_at
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $tecnicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener todos los clientes con sus datos
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.email,
            u.status,
            u.full_name,
            u.phone,
            u.created_at,
            t.id as tecnico_id,
            t.full_name as tecnico_nombre,
            t.username as tecnico_username,
            COUNT(DISTINCT d.id) as total_dispositivos,
            GROUP_CONCAT(DISTINCT d.nombre ORDER BY d.nombre SEPARATOR ', ') as lista_dispositivos,
            GROUP_CONCAT(DISTINCT d.codigo ORDER BY d.codigo SEPARATOR ', ') as lista_codigos,
            MAX(md.timestamp) as ultima_lectura,
            AVG(CASE WHEN md.sensor = 'temperatura' THEN md.valor END) as temp_promedio,
            AVG(CASE WHEN md.sensor = 'humedad' THEN md.valor END) as hum_promedio
        FROM users u
        LEFT JOIN dispositivos d ON u.id = d.cliente_id
        LEFT JOIN users t ON d.tecnico_id = t.id
        LEFT JOIN mqtt_data md ON d.codigo = md.dispositivo_id
        WHERE u.role = 'cliente'
        GROUP BY u.id, u.username, u.email, u.status, u.full_name, u.phone, u.created_at
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Organizar clientes por técnico (basado en dispositivos)
    foreach ($clientes as $cliente) {
        // Para cada cliente, obtener sus técnicos a través de los dispositivos
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.full_name, t.username
            FROM dispositivos d
            JOIN users t ON d.tecnico_id = t.id
            WHERE d.cliente_id = ?
        ");
        $stmt->execute([$cliente['id']]);
        $tecnicos_cliente = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tecnicos_cliente as $tecnico) {
            $tecnico_id = $tecnico['id'];
            if (!isset($clientes_por_tecnico[$tecnico_id])) {
                $clientes_por_tecnico[$tecnico_id] = [
                    'tecnico' => $tecnico,
                    'clientes' => []
                ];
            }
            $clientes_por_tecnico[$tecnico_id]['clientes'][] = $cliente;
        }
    }

    // 5. Obtener dispositivos disponibles (sin asignar)
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

    // 6. Obtener todos los dispositivos para el select
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
        
        // Registrar acción en logs
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

// ========== ASIGNAR DISPOSITIVO CON TÉCNICO ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["asignar_dispositivo"])){
    try {
        $cliente_id = intval($_POST["cliente_id"]);
        $dispositivo_id = intval($_POST["dispositivo_id"]);
        $tecnico_id = isset($_POST["tecnico_id"]) ? intval($_POST["tecnico_id"]) : null;
        
        if(empty($cliente_id) || empty($dispositivo_id)){
            throw new Exception("Selecciona un cliente y un dispositivo");
        }
        
        // Verificar que el cliente existe y es cliente
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'cliente'");
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();
        
        if(!$cliente){
            throw new Exception("El cliente no existe o no tiene rol de cliente");
        }
        
        // Verificar que el dispositivo existe
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
        
        // Si se especificó técnico, verificarlo
        if($tecnico_id) {
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'tecnico' AND status = 'active'");
            $stmt->execute([$tecnico_id]);
            $tecnico = $stmt->fetch();
            
            if(!$tecnico){
                throw new Exception("El técnico no existe o no está activo");
            }
        }
        
        // Actualizar asignación en transacción
        $pdo->beginTransaction();
        
        // Actualizar la tabla dispositivos con cliente y técnico
        $stmt = $pdo->prepare("UPDATE dispositivos SET cliente_id = ?, tecnico_id = ? WHERE id = ?");
        $stmt->execute([$cliente_id, $tecnico_id, $dispositivo_id]);
        
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
            $stmt->execute([
                $_SESSION['user_id'], 
                "Dispositivo {$dispositivo['nombre']} ({$dispositivo['codigo']}) asignado a cliente {$cliente['full_name']}" .
                ($tecnico_id ? " con técnico ID: $tecnico_id" : "")
            ]);
        } catch(Exception $e) {
            // Tabla no existe
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Dispositivo '{$dispositivo['nombre']}' asignado exitosamente al cliente " . htmlspecialchars($cliente['full_name']);
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

// ========== ASIGNAR TÉCNICO A DISPOSITIVO ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["asignar_tecnico_dispositivo"])){
    try {
        $dispositivo_id = intval($_POST["dispositivo_id"]);
        $tecnico_id = intval($_POST["tecnico_id"]);
        
        if(empty($dispositivo_id) || empty($tecnico_id)){
            throw new Exception("Selecciona un dispositivo y un técnico");
        }
        
        // Verificar que el dispositivo existe
        $stmt = $pdo->prepare("SELECT d.id, d.nombre, d.codigo, u.full_name as cliente_nombre 
                              FROM dispositivos d
                              LEFT JOIN users u ON d.cliente_id = u.id
                              WHERE d.id = ?");
        $stmt->execute([$dispositivo_id]);
        $dispositivo = $stmt->fetch();
        
        if(!$dispositivo){
            throw new Exception("El dispositivo no existe");
        }
        
        // Verificar que el técnico existe y es técnico activo
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'tecnico' AND status = 'active'");
        $stmt->execute([$tecnico_id]);
        $tecnico = $stmt->fetch();
        
        if(!$tecnico){
            throw new Exception("El técnico no existe, no es técnico o no está activo");
        }
        
        // Actualizar asignación
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE dispositivos SET tecnico_id = ? WHERE id = ?");
        $stmt->execute([$tecnico_id, $dispositivo_id]);
        
        // Registrar en logs
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_acciones (usuario_id, accion, detalles, fecha) 
                VALUES (?, 'asignar_tecnico_dispositivo', ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                "Técnico {$tecnico['full_name']} asignado al dispositivo {$dispositivo['nombre']} " .
                ($dispositivo['cliente_nombre'] ? "del cliente {$dispositivo['cliente_nombre']}" : "sin cliente")
            ]);
        } catch(Exception $e) {
            // Tabla no existe
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Técnico '{$tecnico['full_name']}' asignado exitosamente al dispositivo '{$dispositivo['nombre']}'";
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

// ========== ELIMINAR CLIENTE ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["eliminar_cliente"])){
    try {
        $cliente_id = intval($_POST["eliminar_cliente"]);
        
        // Verificar que existe y es cliente
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'cliente'");
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
        
        // 2. Desasignar dispositivos
        $stmt = $pdo->prepare("UPDATE dispositivos SET cliente_id = NULL WHERE cliente_id = ?");
        $stmt->execute([$cliente_id]);
        
        // 3. Desasignar en mqtt_data
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
                "Cliente eliminado: " . $cliente['full_name'] . " ({$cliente['username']}) - Dispositivos desasignados: $dispositivos_asignados"
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
// ========== ASIGNAR MÚLTIPLES TÉCNICOS A DISPOSITIVOS ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["asignar_multiples_tecnicos"])){
    try {
        $cliente_id = intval($_POST["cliente_id"]);
        $tecnico_dispositivo = $_POST["tecnico_dispositivo"] ?? [];
        
        if(empty($cliente_id)){
            throw new Exception("ID de cliente no válido");
        }
        
        // Verificar que el cliente existe
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'cliente'");
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();
        
        if(!$cliente){
            throw new Exception("Cliente no encontrado");
        }
        
        $pdo->beginTransaction();
        $actualizados = 0;
        
        foreach($tecnico_dispositivo as $dispositivo_id => $tecnico_id) {
            $dispositivo_id = intval($dispositivo_id);
            $tecnico_id = ($tecnico_id === "0" || $tecnico_id === "") ? null : intval($tecnico_id);
            
            if($tecnico_id !== null && $tecnico_id > 0) {
                // Verificar que el técnico existe
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'tecnico' AND status = 'active'");
                $stmt->execute([$tecnico_id]);
                if(!$stmt->fetch()){
                    continue; // Saltar si el técnico no existe
                }
            }
            
            // Actualizar el técnico del dispositivo
            $stmt = $pdo->prepare("UPDATE dispositivos SET tecnico_id = ? WHERE id = ? AND cliente_id = ?");
            $stmt->execute([$tecnico_id, $dispositivo_id, $cliente_id]);
            
            if($stmt->rowCount() > 0){
                $actualizados++;
            }
        }
        
        // Registrar en logs
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logs_acciones (usuario_id, accion, detalles, fecha) 
                VALUES (?, 'asignar_multiples_tecnicos', ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                "Actualizados $actualizados dispositivos del cliente {$cliente['full_name']}"
            ]);
        } catch(Exception $e) {
            // Tabla no existe
        }
        
        $pdo->commit();
        
        $mensaje = "✅ Actualizados $actualizados dispositivos del cliente " . htmlspecialchars($cliente['full_name']);
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
            max-width: 1600px;
        }
        
        .header-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            position: relative;
            overflow: hidden;
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
        
        .badge-tecnico {
            background: linear-gradient(135deg, #7209b7, #560bad);
            color: white;
        }
        
        .badge-sin-tecnico {
            background: linear-gradient(135deg, #6c757d, #495057);
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
        
        .tecnico-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .tecnico-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .tecnico-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #dee2e6;
        }
        
        .cliente-tecnico-item {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }
        
        .cliente-tecnico-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .tecnico-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
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
        
        .btn-tecnico {
            background: linear-gradient(135deg, #7209b7, #560bad);
            color: white;
        }
        
        .btn-tecnico:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(114, 9, 183, 0.3);
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
        
        .tecnico-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .no-tecnico-badge {
            background: #f1f5f9;
            color: #64748b;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .dispositivo-tecnico {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0f9ff;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin: 2px;
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
                            <i class="bi bi-people-fill me-2"></i>Gestión de Clientes y Técnicos
                        </h1>
                        <p class="mb-0 opacity-90">Administra clientes, técnicos y asigna dispositivos IoT</p>
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
                                    <i class="bi bi-tools text-warning fs-4"></i>
                                </div>
                                <div>
                                    <div class="stats-number"><?= $estadisticas['total_tecnicos'] ?? 0 ?></div>
                                    <div class="text-muted">Técnicos Activos</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-info bg-opacity-10 rounded-circle p-3 me-3">
                                    <i class="bi bi-cpu text-info fs-4"></i>
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
                        <button class="nav-link" id="tecnicos-tab" data-bs-toggle="tab" data-bs-target="#tecnicos" type="button">
                            <i class="bi bi-person-workspace me-2"></i>Técnicos y Clientes
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tecnico-dispositivo-tab" data-bs-toggle="tab" data-bs-target="#tecnico-dispositivo" type="button">
                            <i class="bi bi-tools me-2"></i>Asignar Técnico a Dispositivo
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
                                            <i class="bi bi-person me-1"></i> Nombre Completo *
                                        </label>
                                        <input type="text" name="full_name" class="form-control" required placeholder="Ej: Juan Pérez">
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
                                        <select name="cliente_id" class="form-control" required>
                                            <option value="">-- Selecciona un cliente --</option>
                                            <?php foreach($clientes as $cliente): ?>
                                            <option value="<?= $cliente['id'] ?>">
                                                <?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?> 
                                                (<?= $cliente['email'] ?>)
                                                <?php if($cliente['tecnico_nombre']): ?>
                                                - Téc: <?= htmlspecialchars($cliente['tecnico_nombre']) ?>
                                                <?php endif; ?>
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
                                        <select name="dispositivo_id" class="form-control" required>
                                            <option value="">-- Selecciona un dispositivo --</option>
                                            <?php foreach($dispositivos_disponibles as $dispositivo): ?>
                                            <option value="<?= $dispositivo['id'] ?>">
                                                <?= htmlspecialchars($dispositivo['nombre']) ?> 
                                                (<?= htmlspecialchars($dispositivo['codigo']) ?> - <?= $dispositivo['tipo'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-tools me-1"></i> Técnico Responsable (Opcional)
                                        </label>
                                        <select name="tecnico_id" class="form-control">
                                            <option value="">-- Sin técnico --</option>
                                            <?php foreach($tecnicos as $tecnico): ?>
                                            <option value="<?= $tecnico['id'] ?>">
                                                <?= htmlspecialchars($tecnico['full_name'] ?: $tecnico['username']) ?> 
                                                (<?= $tecnico['email'] ?>)
                                                - <?= $tecnico['total_clientes_asignados'] ?> clientes
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Dispositivos disponibles:</strong> 
                                    <?= count($dispositivos_disponibles) ?> dispositivo(s) sin asignar
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="asignar_dispositivo" class="btn btn-success px-4">
                                        <i class="bi bi-link me-2"></i>Asignar Dispositivo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tab 3: Técnicos y Clientes -->
                    <div class="tab-pane fade" id="tecnicos" role="tabpanel">
                        <div class="table-container">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="mb-0">
                                    <i class="bi bi-person-workspace me-2 text-primary"></i>Técnicos y Sus Clientes Asignados
                                </h4>
                                <span class="badge-tecnico">
                                    <i class="bi bi-tools me-1"></i>
                                    <?= count($tecnicos) ?> Técnicos Activos
                                </span>
                            </div>
                            
                            <?php if(empty($tecnicos)): ?>
                            <div class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-tools display-4 mb-3"></i>
                                    <h5>No hay técnicos registrados</h5>
                                    <p class="mb-0">Los técnicos se gestionan desde el panel de usuarios</p>
                                </div>
                            </div>
                            <?php else: ?>
                                <?php foreach($clientes_por_tecnico as $tecnico_id => $data): 
                                    $tecnico = $data['tecnico'];
                                    $clientes_tecnico = $data['clientes'];
                                ?>
                                <div class="tecnico-card">
                                    <div class="tecnico-header">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="tecnico-avatar">
                                                <?= strtoupper(substr($tecnico['full_name'] ?: $tecnico['username'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h5 class="mb-1"><?= htmlspecialchars($tecnico['full_name'] ?: $tecnico['username']) ?></h5>
                                                <div class="text-muted">
                                                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($tecnico['email']) ?>
                                                    <?php if($tecnico['phone']): ?>
                                                    <span class="ms-3">
                                                        <i class="bi bi-phone me-1"></i><?= htmlspecialchars($tecnico['phone']) ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="badge-count">
                                                <i class="bi bi-people me-1"></i>
                                                <?= count($clientes_tecnico) ?> clientes
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if(empty($clientes_tecnico)): ?>
                                    <div class="text-center py-3 text-muted">
                                        <i class="bi bi-people fs-4 mb-2"></i>
                                        <p class="mb-0">Este técnico no tiene clientes asignados</p>
                                    </div>
                                    <?php else: ?>
                                        <?php foreach($clientes_tecnico as $cliente): 
                                            $dispositivos_array = $cliente['lista_dispositivos'] ? 
                                                explode(', ', $cliente['lista_dispositivos']) : [];
                                        ?>
                                        <div class="cliente-tecnico-item">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="bg-primary bg-opacity-10 rounded-circle p-2">
                                                            <i class="bi bi-person text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <strong><?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?></strong><br>
                                                            <small class="text-muted">
                                                                <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cliente['email']) ?>
                                                                <?php if($cliente['phone']): ?>
                                                                <span class="ms-2">
                                                                    <i class="bi bi-phone me-1"></i><?= htmlspecialchars($cliente['phone']) ?>
                                                                </span>
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="d-flex justify-content-end gap-2">
                                                        <span class="badge-count">
                                                            <i class="bi bi-cpu me-1"></i>
                                                            <?= $cliente['total_dispositivos'] ?> disp
                                                        </span>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="viewClientDevices(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?>')">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-success" onclick="quickAssign(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?>')">
                                                            <i class="bi bi-plus"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if(!empty($dispositivos_array)): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">Dispositivos:</small>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    <?php foreach(array_slice($dispositivos_array, 0, 3) as $dispositivo): ?>
                                                    <span class="device-tag"><?= htmlspecialchars($dispositivo) ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if(count($dispositivos_array) > 3): ?>
                                                    <span class="device-tag">+<?= count($dispositivos_array) - 3 ?> más</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Clientes sin técnico asignado -->
                                <?php 
                                $clientes_con_tecnicos_ids = [];
                                foreach($clientes_por_tecnico as $data) {
                                    foreach($data['clientes'] as $cliente) {
                                        $clientes_con_tecnicos_ids[] = $cliente['id'];
                                    }
                                }
                                
                                $clientes_sin_tecnico = array_filter($clientes, function($cliente) use ($clientes_con_tecnicos_ids) {
                                    return !in_array($cliente['id'], $clientes_con_tecnicos_ids);
                                });
                                ?>
                                
                                <?php if(!empty($clientes_sin_tecnico)): ?>
                                <div class="tecnico-card mt-4">
                                    <div class="tecnico-header">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="tecnico-avatar" style="background: linear-gradient(135deg, #6c757d, #495057);">
                                                <i class="bi bi-question-lg"></i>
                                            </div>
                                            <div>
                                                <h5 class="mb-1">Clientes Sin Técnico Asignado</h5>
                                                <div class="text-muted">
                                                    Clientes cuyos dispositivos no tienen técnico responsable
                                                </div>
                                            </div>
                                        </div>
                                        <span class="badge-count">
                                            <i class="bi bi-people me-1"></i>
                                            <?= count($clientes_sin_tecnico) ?> clientes
                                        </span>
                                    </div>
                                    
                                    <?php foreach($clientes_sin_tecnico as $cliente): ?>
                                    <div class="cliente-tecnico-item">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="bg-secondary bg-opacity-10 rounded-circle p-2">
                                                        <i class="bi bi-person text-secondary"></i>
                                                    </div>
                                                    <div>
                                                        <strong><?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?></strong><br>
                                                        <small class="text-muted">
                                                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cliente['email']) ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <div class="d-flex justify-content-end gap-2">
                                                    <span class="badge-count">
                                                        <i class="bi bi-cpu me-1"></i>
                                                        <?= $cliente['total_dispositivos'] ?> disp
                                                    </span>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="assignTecnicoDispositivoCliente(<?= $cliente['id'] ?>)">
                                                        <i class="bi bi-tools"></i> Asignar Técnico
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tab 4: Asignar Técnico a Dispositivo -->
                    <div class="tab-pane fade" id="tecnico-dispositivo" role="tabpanel">
                        <div class="form-card">
                            <h4 class="mb-4">
                                <i class="bi bi-tools me-2 text-warning"></i>Asignar Técnico a Dispositivo
                            </h4>
                            <form method="POST" id="tecnicoDispositivoForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-cpu me-1"></i> Seleccionar Dispositivo *
                                        </label>
                                        <select name="dispositivo_id" class="form-control" required id="selectDispositivoTecnico">
                                            <option value="">-- Selecciona un dispositivo --</option>
                                            <?php foreach($todos_dispositivos as $dispositivo): ?>
                                            <option value="<?= $dispositivo['id'] ?>">
                                                <?= htmlspecialchars($dispositivo['nombre']) ?> 
                                                (<?= htmlspecialchars($dispositivo['codigo']) ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-tools me-1"></i> Seleccionar Técnico *
                                        </label>
                                        <select name="tecnico_id" class="form-control" required>
                                            <option value="">-- Selecciona un técnico --</option>
                                            <?php foreach($tecnicos as $tecnico): ?>
                                            <option value="<?= $tecnico['id'] ?>">
                                                <?= htmlspecialchars($tecnico['full_name'] ?: $tecnico['username']) ?> 
                                                (<?= $tecnico['email'] ?>)
                                                - <?= $tecnico['total_clientes_asignados'] ?> clientes
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Información:</strong> Asigna un técnico responsable para un dispositivo específico.
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="asignar_tecnico_dispositivo" class="btn btn-warning px-4">
                                        <i class="bi bi-person-gear me-2"></i>Asignar Técnico al Dispositivo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tab 5: Lista de Clientes -->
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
                                            <th>Técnicos Asignados</th>
                                            <th>Contacto</th>
                                            <th>Estado</th>
                                            <th>Dispositivos</th>
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
                                                $dispositivos_array = $cliente['lista_dispositivos'] ? 
                                                    explode(', ', $cliente['lista_dispositivos']) : [];
                                                $codigos_array = $cliente['lista_codigos'] ? 
                                                    explode(', ', $cliente['lista_codigos']) : [];
                                                
                                                // Obtener técnicos asignados a los dispositivos de este cliente
                                                $stmt = $pdo->prepare("
                                                    SELECT DISTINCT t.full_name, t.username 
                                                    FROM dispositivos d
                                                    JOIN users t ON d.tecnico_id = t.id
                                                    WHERE d.cliente_id = ? AND d.tecnico_id IS NOT NULL
                                                ");
                                                $stmt->execute([$cliente['id']]);
                                                $tecnicos_cliente = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                                                    <?php if(!empty($tecnicos_cliente)): ?>
                                                        <?php foreach($tecnicos_cliente as $tecnico): ?>
                                                        <div class="tecnico-badge mb-1">
                                                            <i class="bi bi-tools"></i>
                                                            <?= htmlspecialchars($tecnico['full_name']) ?>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="no-tecnico-badge">
                                                            <i class="bi bi-question-circle"></i>
                                                            Sin técnico
                                                        </span>
                                                    <?php endif; ?>
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

    <!-- Modal para asignación rápida de dispositivo -->
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
                        <div class="mb-3">
                            <label class="form-label">Técnico Responsable (Opcional):</label>
                            <select name="tecnico_id" class="form-control">
                                <option value="">-- Sin técnico --</option>
                                <?php foreach($tecnicos as $tecnico): ?>
                                <option value="<?= $tecnico['id'] ?>">
                                    <?= htmlspecialchars($tecnico['full_name'] ?: $tecnico['username']) ?> 
                                    (<?= $tecnico['email'] ?>)
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

    <!-- Modal para asignar técnico a dispositivos de cliente -->
    <div class="modal fade modal-custom" id="tecnicoDispositivoClienteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tecnicoDispositivoClienteModalTitle">
                        <i class="bi bi-tools me-2"></i>Asignar Técnico a Dispositivos
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="tecnicoDispositivoClienteContent">
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
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [6] } // Deshabilitar orden en columna de acciones
            ]
        });
        
        // Búsqueda personalizada
        $('#searchInput').on('keyup', function() {
            table.search(this.value).draw();
        });
        
        // Cargar información del dispositivo seleccionado
        $('#selectDispositivoTecnico').change(function() {
            const dispositivoId = $(this).val();
            if(dispositivoId) {
                // Aquí podrías cargar información del dispositivo si es necesario
            }
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

    // Ver dispositivos del cliente
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

    // Asignación rápida de dispositivo
    function quickAssign(clienteId, clienteName) {
        document.getElementById('quickClienteId').value = clienteId;
        document.getElementById('assignModalTitle').innerHTML = 
            `<i class="bi bi-link me-2"></i>Asignar Dispositivo a ${clienteName}`;
        
        const modal = new bootstrap.Modal(document.getElementById('assignModal'));
        modal.show();
    }

    // Asignar técnico a dispositivos de un cliente
    function assignTecnicoDispositivoCliente(clienteId) {
        fetch(`get_dispositivos_cliente.php?cliente_id=${clienteId}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('tecnicoDispositivoClienteContent').innerHTML = data;
                document.getElementById('tecnicoDispositivoClienteModalTitle').innerHTML = 
                    `<i class="bi bi-tools me-2"></i>Asignar Técnico a Dispositivos del Cliente`;
                const modal = new bootstrap.Modal(document.getElementById('tecnicoDispositivoClienteModal'));
                modal.show();
            })
            .catch(error => {
                document.getElementById('tecnicoDispositivoClienteContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error: ${error.message}
                    </div>
                `;
                const modal = new bootstrap.Modal(document.getElementById('tecnicoDispositivoClienteModal'));
                modal.show();
            });
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
                    case '#tecnico-dispositivo':
                        document.querySelector('#tecnico-dispositivo select[name="dispositivo_id"]').focus();
                        break;
                    case '#lista':
                        document.querySelector('#lista #searchInput').focus();
                        break;
                }
            });
        });
        
        // Mostrar pestaña de técnicos si hay
        const tecnicosTab = document.getElementById('tecnicos-tab');
        if(tecnicosTab && <?= count($tecnicos) > 0 ? 'true' : 'false' ?>) {
            setTimeout(() => {
                tecnicosTab.click();
            }, 100);
        }
    });
    </script>
</body>
</html>