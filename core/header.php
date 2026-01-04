<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema IoT - <?= $page_title ?? 'Inicio' ?></title>

    <!-- Bootstrap CSS real -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>
<body class="bg-light">

<?php if (!isset($no_navbar)): ?>

<!-- 🌐 NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">

        <!-- Marca -->
        <a class="navbar-brand fw-bold" href="/iot-system">
            <i class="fas fa-microchip"></i> Sistema IoT
        </a>

        <!-- Botón hamburguesa -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Links -->
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto">

                <?php if (isset($_SESSION["user_type"])): ?>

                    <!-- 🔥 INICIO DINÁMICO SEGÚN USER -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?=
                            $_SESSION['user_type'] == 'tecnico' ? '/iot-system/tecnico/index.php' :
                            ($_SESSION['user_type'] == 'admin' ? '/iot-system/admin/index.php' :
                            '/iot-system/cliente/index.php')
                        ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>

                    <!-- ADMIN -->
                    <?php if ($_SESSION["user_type"] == "admin"): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/iot-system/admin/">
                                <i class="fas fa-user-shield"></i> Admin
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- TÉCNICO -->
                    <?php if ($_SESSION["user_type"] == "tecnico"): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/iot-system/tecnico/">
                                <i class="fas fa-tools"></i> Panel Técnico
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- CLIENTE -->
                    <?php if ($_SESSION["user_type"] == "cliente"): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/iot-system/cliente/">
                                <i class="fas fa-user"></i> Cliente
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- SALIR -->
                    <li class="nav-item">
                        <a class="nav-link" href="/iot-system/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Salir
                        </a>
                    </li>

                <?php else: ?>

                    <!-- SIN SESIÓN -->
                    <li class="nav-item">
                        <a class="nav-link" href="/iot-system/public/index.php">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="/iot-system/public/registrar_tecnico.php">
                            <i class="fas fa-user-plus"></i> Registrarse
                        </a>
                    </li>

                <?php endif; ?>

            </ul>
        </div>

    </div>
</nav>
<?php endif; ?>

<!-- 🔙 BOTÓN REGRESAR -->
<div class="container mt-2">
    <button class="btn btn-secondary btn-sm mb-3" onclick="history.back()">
        ⬅️ Regresar
    </button>
</div>

<!-- 🧱 CONTENEDOR GLOBAL -->
<div class="container mt-3">
