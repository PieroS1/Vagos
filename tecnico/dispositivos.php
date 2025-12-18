<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";
$tecnico_id = $_SESSION["user_id"];

// Obtener todos los dispositivos del técnico
$stmt = $pdo->prepare("
    SELECT d.*, u.username as cliente_nombre 
    FROM dispositivos d
    LEFT JOIN users u ON d.cliente_id = u.id
    WHERE d.tecnico_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$tecnico_id]);
$dispositivos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mis Dispositivos</title>
</head>
<body>
    <h1>Mis Dispositivos IoT</h1>
    
    <table>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Cliente</th>
            <th>Estado</th>
            <th>Última Conexión</th>
            <th>Acciones</th>
        </tr>
        <?php foreach($dispositivos as $disp): ?>
        <tr>
            <td><?php echo $disp['id']; ?></td>
            <td><?php echo $disp['nombre']; ?></td>
            <td><?php echo $disp['tipo']; ?></td>
            <td><?php echo $disp['cliente_nombre'] ?? 'Sin cliente'; ?></td>
            <td>
                <span class="status-<?php echo $disp['estado']; ?>">
                    <?php echo $disp['estado']; ?>
                </span>
            </td>
            <td><?php echo $disp['ultima_conexion'] ?? 'Nunca'; ?></td>
            <td>
                <a href="editar_dispositivo.php?id=<?php echo $disp['id']; ?>">Editar</a>
                <a href="ver_datos.php?dispositivo_id=<?php echo $disp['id']; ?>">Ver Datos</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
