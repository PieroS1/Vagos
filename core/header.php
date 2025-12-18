<?php
// core/header.php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema IoT - <?php echo $page_title ?? 'Inicio'; ?></title>
    <link rel="stylesheet" href="/iot-system/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #1f2937;
            --light: #f9fafb;
        }
    </style>
</head>
<body>
    <?php if (!isset($no_navbar)): ?>
    <nav class="navbar">
        <a href="/iot-system" class="nav-brand">
            <i class="fas fa-microchip"></i> Sistema IoT
        </a>
        <div class="nav-links">
            <?php if (isset($_SESSION['user_type'])): ?>
                <?php if ($_SESSION['user_type'] == 'admin'): ?>
                    <a href="/iot-system/admin/"><i class="fas fa-user-shield"></i> Admin</a>
                <?php elseif ($_SESSION['user_type'] == 'tecnico'): ?>
                    <a href="/iot-system/tecnico/"><i class="fas fa-tools"></i> Panel Técnico</a>
                <?php elseif ($_SESSION['user_type'] == 'cliente'): ?>
                    <a href="/iot-system/cliente/"><i class="fas fa-user"></i> Cliente</a>
                <?php endif; ?>
                <a href="/iot-system/logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
            <?php else: ?>
                <a href="/iot-system/public/index.php"><i class="fas fa-home"></i> Inicio</a>
                <a href="/iot-system/public/registrar_tecnico.php"><i class="fas fa-user-plus"></i> Registrarse</a>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    
    <div class="container">
