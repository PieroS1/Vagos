<?php
session_start();
require "../config/db.php";

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar que el usuario existe y es técnico
    $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($usuario && $usuario['role'] == 'tecnico') {
        // Desactivar el técnico
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        if($stmt->execute([$id])) {
            $mensaje = "Técnico desactivado exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al desactivar el técnico";
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "Usuario no encontrado o no es técnico";
        $tipo_mensaje = "error";
    }
    
    header("Location: tecnicos.php?mensaje=" . urlencode($mensaje) . "&tipo=" . $tipo_mensaje);
    exit();
} else {
    header("Location: tecnicos.php");
    exit();
}
?>
