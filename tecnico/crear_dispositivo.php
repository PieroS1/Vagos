<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Procesar formulario si se envió
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $nombre = $_POST['nombre'];
    $tipo = $_POST['tipo'];
    $protocolo = $_POST['protocolo'];
    $ubicacion = $_POST['ubicacion'];
    $cliente_id = $_POST['cliente_id'];
    $tecnico_id = $_SESSION['user_id'];
    $estado = $_POST['estado'];
    $fecha_instalacion = $_POST['fecha_instalacion'];
    
    $stmt = $pdo->prepare("
        INSERT INTO dispositivos 
        (nombre, tipo, protocolo, ubicacion, cliente_id, tecnico_id, estado, fecha_instalacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if($stmt->execute([$nombre, $tipo, $protocolo, $ubicacion, $cliente_id, $tecnico_id, $estado, $fecha_instalacion])){
        header("Location: dispositivos.php?success=1");
        exit();
    }
}

// Obtener clientes para el select
$clientes = $pdo->query("SELECT id, username FROM users WHERE role = 'cliente'")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Crear Dispositivo</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 600px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #45a049; }
    </style>
</head>
<body>
    <h1>Crear Nuevo Dispositivo</h1>
    <form method="POST">
        <div class="form-group">
            <label>Nombre del Dispositivo:</label>
            <input type="text" name="nombre" required>
        </div>
        <div class="form-group">
            <label>Tipo:</label>
            <select name="tipo" required>
                <option value="temperatura">🌡️ Sensor de Temperatura</option>
                <option value="humedad">💧 Sensor de Humedad</option>
                <option value="control">🎛️ Controlador</option>
                <option value="movimiento">🚶 Sensor de Movimiento</option>
                <option value="luz">💡 Control de Luces</option>
            </select>
        </div>
        <div class="form-group">
            <label>Protocolo:</label>
            <select name="protocolo" required>
                <option value="HTTP">HTTP</option>
                <option value="MQTT">MQTT</option>
                <option value="WebSocket">WebSocket</option>
            </select>
        </div>
        <div class="form-group">
            <label>Ubicación:</label>
            <input type="text" name="ubicacion" placeholder="Ej: Sala Principal, Cocina, Jardín...">
        </div>
        <div class="form-group">
            <label>Cliente:</label>
            <select name="cliente_id">
                <option value="">Sin cliente asignado</option>
                <?php foreach($clientes as $cliente): ?>
                <option value="<?php echo $cliente['id']; ?>"><?php echo $cliente['username']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estado:</label>
            <select name="estado">
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
                <option value="mantenimiento">Mantenimiento</option>
            </select>
        </div>
        <div class="form-group">
            <label>Fecha de Instalación:</label>
            <input type="date" name="fecha_instalacion">
        </div>
        <button type="submit">Crear Dispositivo</button>
        <a href="dispositivos.php" style="margin-left: 10px;">Cancelar</a>
    </form>
</body>
</html>