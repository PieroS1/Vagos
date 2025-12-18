<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Crear nuevo cliente
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["crear_cliente"])){
    $username = $_POST["username"];
    $email = $_POST["email"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'cliente', 'active')");
    $stmt->execute([$username, $email, $password]);
    
    header("Location: gestionar_clientes.php?success=Cliente+creado");
    exit();
}

// Obtener lista de clientes
$clientes = $pdo->query("SELECT id, username, email, created_at FROM users WHERE role = 'cliente' ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gestionar Clientes</title>
</head>
<body>
    <h1>Gestionar Clientes</h1>
    
    <h2>Crear Nuevo Cliente</h2>
    <form method="POST">
        <input type="text" name="username" placeholder="Nombre de usuario" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Contraseña" required>
        <button type="submit" name="crear_cliente">Crear Cliente</button>
    </form>
    
    <h2>Lista de Clientes</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Email</th>
            <th>Fecha Registro</th>
        </tr>
        <?php foreach($clientes as $cliente): ?>
        <tr>
            <td><?php echo $cliente['id']; ?></td>
            <td><?php echo $cliente['username']; ?></td>
            <td><?php echo $cliente['email']; ?></td>
            <td><?php echo $cliente['created_at']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
