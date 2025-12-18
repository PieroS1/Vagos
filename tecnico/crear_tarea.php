<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";
$tecnico_id = $_SESSION["user_id"];

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $titulo = $_POST["titulo"];
    $descripcion = $_POST["descripcion"];
    $prioridad = $_POST["prioridad"];
    $fecha_limite = $_POST["fecha_limite"];
    $cliente_id = $_POST["cliente_id"];
    $dispositivo_id = $_POST["dispositivo_id"];
    
    $stmt = $pdo->prepare("INSERT INTO tareas (titulo, descripcion, prioridad, fecha_limite, cliente_id, dispositivo_id, tecnico_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    $stmt->execute([$titulo, $descripcion, $prioridad, $fecha_limite, $cliente_id, $dispositivo_id, $tecnico_id]);
    
    header("Location: index.php?success=Tarea+creada");
    exit();
}

// Obtener clientes y dispositivos
$clientes = $pdo->query("SELECT id, username FROM users WHERE role = 'cliente'")->fetchAll();
$dispositivos = $pdo->query("SELECT id, nombre FROM dispositivos WHERE tecnico_id = $tecnico_id")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Crear Tarea</title>
</head>
<body>
    <h1>Crear Nueva Tarea</h1>
    <form method="POST">
        <input type="text" name="titulo" placeholder="Título" required>
        <textarea name="descripcion" placeholder="Descripción" required></textarea>
        <select name="prioridad" required>
            <option value="baja">Baja</option>
            <option value="media">Media</option>
            <option value="alta">Alta</option>
            <option value="critica">Crítica</option>
        </select>
        <input type="date" name="fecha_limite">
        <select name="cliente_id">
            <option value="">Sin cliente</option>
            <?php foreach($clientes as $cliente): ?>
            <option value="<?php echo $cliente['id']; ?>"><?php echo $cliente['username']; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="dispositivo_id">
            <option value="">Sin dispositivo</option>
            <?php foreach($dispositivos as $disp): ?>
            <option value="<?php echo $disp['id']; ?>"><?php echo $disp['nombre']; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Crear Tarea</button>
    </form>
</body>
</html>
