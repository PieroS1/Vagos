<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";
$tecnico_id = $_SESSION["user_id"]; // Necesitarás ajustar esto según tu sesión

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $nombre = $_POST["nombre"];
    $tipo = $_POST["tipo"];
    $cliente_id = $_POST["cliente_id"];
    $descripcion = $_POST["descripcion"];
    $ubicacion = $_POST["ubicacion"];
    
    $stmt = $pdo->prepare("INSERT INTO dispositivos (nombre, tipo, cliente_id, tecnico_id, descripcion, ubicacion, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')");
    $stmt->execute([$nombre, $tipo, $cliente_id, $tecnico_id, $descripcion, $ubicacion]);
    
    header("Location: index.php?success=Dispositivo+registrado");
    exit();
}

// Obtener lista de clientes
$clientes = $pdo->query("SELECT id, username FROM users WHERE role = 'cliente'")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Registrar Dispositivo</title>
</head>
<body>
    <h1>Registrar Nuevo Dispositivo IoT</h1>
    <form method="POST">
        <input type="text" name="nombre" placeholder="Nombre del dispositivo" required>
        <select name="tipo" required>
            <option value="">Tipo de dispositivo</option>
            <option value="sensor">Sensor</option>
            <option value="actuador">Actuador</option>
            <option value="gateway">Gateway</option>
            <option value="controlador">Controlador</option>
        </select>
        <select name="cliente_id">
            <option value="">Sin cliente asignado</option>
            <?php foreach($clientes as $cliente): ?>
            <option value="<?php echo $cliente['id']; ?>"><?php echo $cliente['username']; ?></option>
            <?php endforeach; ?>
        </select>
        <textarea name="descripcion" placeholder="Descripción"></textarea>
        <input type="text" name="ubicacion" placeholder="Ubicación">
        <button type="submit">Registrar</button>
    </form>
</body>
</html>
