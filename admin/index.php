<?php
session_start();
require "../config/db.php";

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

// Obtener estadísticas reales desde la base de datos
try {
    // Contar técnicos activos
    $stmtTecnicos = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'tecnico' AND status = 'active'");
    $stmtTecnicos->execute();
    $tecnicosActivos = $stmtTecnicos->fetchColumn();

    // Contar técnicos pendientes
    $stmtTecnicosPendientes = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'tecnico' AND status = 'pending'");
    $stmtTecnicosPendientes->execute();
    $tecnicosPendientes = $stmtTecnicosPendientes->fetchColumn();

    // Contar clientes
    $stmtClientes = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'cliente'");
    $stmtClientes->execute();
    $totalClientes = $stmtClientes->fetchColumn();

    // Contar administradores
    $stmtAdmins = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmtAdmins->execute();
    $totalAdmins = $stmtAdmins->fetchColumn();

    // Obtener actividad reciente (usuarios recién registrados)
    // Como no hay created_at, obtenemos los últimos registros por ID
    $stmtActividad = $pdo->prepare("SELECT id, username, role, status FROM users ORDER BY id DESC LIMIT 5");
    $stmtActividad->execute();
    $actividadReciente = $stmtActividad->fetchAll(PDO::FETCH_ASSOC);

    // Obtener usuario actual
    $stmtUsuario = $pdo->prepare("SELECT username FROM users WHERE username = ?");
    $stmtUsuario->execute([$_SESSION["user"]]);
    $usuarioActual = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    // Obtener total de usuarios
    $stmtTotalUsuarios = $pdo->prepare("SELECT COUNT(*) FROM users");
    $stmtTotalUsuarios->execute();
    $totalUsuarios = $stmtTotalUsuarios->fetchColumn();

} catch(PDOException $e) {
    die("Error al cargar datos: " . $e->getMessage());
}

$page_title = "Panel de Administración";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - Sistema IoT</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1f2937;
            --light: #f9fafb;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .admin-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border-left: 6px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-section h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .welcome-section h1 i {
            color: var(--primary);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-section p {
            color: #6b7280;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 15px 25px;
            border-radius: 15px;
            border: 1px solid #e2e8f0;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
        }

        .user-details h3 {
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 18px;
        }

        .user-details .role-badge {
            background: var(--primary);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .card-icon.blue { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .card-icon.green { background: linear-gradient(135deg, #10b981, #059669); }
        .card-icon.purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .card-icon.orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .card-icon.red { background: linear-gradient(135deg, #ef4444, #dc2626); }

        .card-content h3 {
            color: var(--dark);
            font-size: 18px;
            margin-bottom: 5px;
        }

        .card-content p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .card-stats {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin: 15px 0;
            display: flex;
            align-items: baseline;
            gap: 10px;
        }

        .card-stats span {
            font-size: 16px;
            color: #6b7280;
            font-weight: normal;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .card-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .card-link:hover {
            color: var(--secondary);
            gap: 12px;
        }

        .trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            font-weight: 600;
        }

        .trend.up {
            color: var(--success);
        }

        .trend.down {
            color: var(--danger);
        }

        .trend.neutral {
            color: #6b7280;
        }

        .trend.warning {
            color: var(--warning);
        }

        .quick-actions {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .section-title h2 {
            color: var(--dark);
            font-size: 24px;
            margin: 0;
        }

        .section-title i {
            color: var(--primary);
            font-size: 20px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .action-button {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 25px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-button:hover {
            transform: translateY(-3px);
            border-color: var(--primary);
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.1);
            background: white;
        }

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .action-content h3 {
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 16px;
        }

        .action-content p {
            color: #6b7280;
            font-size: 13px;
            margin: 0;
            line-height: 1.4;
        }

        .recent-activity {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }

        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            display: flex;
            gap: 20px;
            padding: 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.3s ease;
        }

        .activity-item:hover {
            background: #f8fafc;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 15px;
        }

        .activity-content p {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .activity-time {
            font-size: 12px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .user-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }

        .status-active { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-inactive { background: #f3f4f6; color: #374151; }

        .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.2);
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #d1d5db;
        }

        .refresh-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .refresh-btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                text-align: center;
            }
            
            .user-info {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-container {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .admin-header {
                padding: 20px;
            }
            
            .welcome-section h1 {
                font-size: 24px;
            }
            
            .dashboard-card,
            .quick-actions,
            .recent-activity {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="admin-header">
            <div class="welcome-section">
                <h1>
                    <i class="fas fa-user-shield"></i>
                    Panel de Administración
                </h1>
                <p>Gestiona todos los aspectos del sistema IoT</p>
                <p><small>Sesión iniciada como administrador | Total de usuarios: <?php echo $totalUsuarios; ?></small></p>
                <small id="current-time"></small>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION["user"], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($_SESSION["user"]); ?></h3>
                    <span class="role-badge">Administrador</span>
                    <a href="javascript:location.reload()" class="refresh-btn" style="margin-top: 10px; display: inline-block;">
                        <i class="fas fa-sync-alt"></i>
                        Actualizar
                    </a>
                </div>
            </div>
        </div>

        <!-- Dashboard Stats -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon blue">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="card-content">
                        <h3>Técnicos Activos</h3>
                        <p>Técnicos aprobados en el sistema</p>
                    </div>
                </div>
                <div class="card-stats">
                    <?php echo $tecnicosActivos; ?> <span>Técnicos</span>
                </div>
                <div class="card-footer">
                    <a href="tecnicos.php" class="card-link">
                        Gestionar
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php if($tecnicosPendientes > 0): ?>
                        <div class="trend warning">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $tecnicosPendientes; ?> pendientes
                        </div>
                    <?php else: ?>
                        <div class="trend neutral">
                            <i class="fas fa-check"></i>
                            Al día
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon purple">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="card-content">
                        <h3>Pendientes</h3>
                        <p>Solicitudes por aprobar</p>
                    </div>
                </div>
                <div class="card-stats">
                    <?php echo $tecnicosPendientes; ?> <span>Solicitudes</span>
                </div>
                <div class="card-footer">
                    <a href="aprobar.php" class="card-link">
                        Revisar
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php if($tecnicosPendientes > 0): ?>
                        <div class="trend warning">
                            <i class="fas fa-exclamation"></i>
                            Por revisar
                        </div>
                    <?php else: ?>
                        <div class="trend neutral">
                            <i class="fas fa-check-circle"></i>
                            Sin pendientes
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon green">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="card-content">
                        <h3>Clientes</h3>
                        <p>Clientes registrados</p>
                    </div>
                </div>
                <div class="card-stats">
                    <?php echo $totalClientes; ?> <span>Clientes</span>
                </div>
                <div class="card-footer">
                    <a href="clientes.php" class="card-link">
                        Ver todos
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <div class="trend neutral">
                        <i class="fas fa-users"></i>
                        Registrados
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon orange">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="card-content">
                        <h3>Administradores</h3>
                        <p>Usuarios con privilegios</p>
                    </div>
                </div>
                <div class="card-stats">
                    <?php echo $totalAdmins; ?> <span>Admins</span>
                </div>
                <div class="card-footer">
                    <a href="#" class="card-link" onclick="alert('Lista de administradores')">
                        Ver
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <div class="trend neutral">
                        <i class="fas fa-shield-alt"></i>
                        Sistema
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="section-title">
                <i class="fas fa-bolt"></i>
                <h2>Acciones Rápidas</h2>
            </div>
            
            <div class="actions-grid">
                <a href="tecnicos.php" class="action-button">
                    <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="action-content">
                        <h3>Gestionar Técnicos</h3>
                        <p><?php echo $tecnicosActivos; ?> técnicos activos | <?php echo $tecnicosPendientes; ?> pendientes</p>
                    </div>
                </a>

                <a href="clientes.php" class="action-button">
                    <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="action-content">
                        <h3>Gestionar Clientes</h3>
                        <p><?php echo $totalClientes; ?> clientes registrados en el sistema</p>
                    </div>
                </a>

                <a href="aprobar.php" class="action-button">
                    <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="action-content">
                        <h3>Aprobar Solicitudes</h3>
                        <p><?php echo $tecnicosPendientes; ?> solicitudes pendientes de revisión</p>
                    </div>
                </a>

                <a href="javascript:location.reload()" class="action-button">
                    <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div class="action-content">
                        <h3>Actualizar Datos</h3>
                        <p>Refrescar información del panel de control</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity">
            <div class="section-title">
                <i class="fas fa-history"></i>
                <h2>Últimos Registros</h2>
            </div>
            
            <?php if(empty($actividadReciente)): ?>
                <div class="no-data">
                    <i class="fas fa-history"></i>
                    <h3>No hay usuarios registrados</h3>
                    <p>No se han encontrado usuarios en el sistema</p>
                </div>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach($actividadReciente as $actividad): 
                        $username = htmlspecialchars($actividad['username']);
                        $role = $actividad['role'];
                        $status = $actividad['status'];
                        
                        // Determinar icono y color según rol y estado
                        if($role == 'tecnico') {
                            $icon = 'fa-user-cog';
                            $color = '#3b82f6';
                            $roleText = 'Técnico';
                            if($status == 'pending') {
                                $icon = 'fa-clock';
                                $color = '#f59e0b';
                            }
                        } elseif($role == 'cliente') {
                            $icon = 'fa-user';
                            $color = '#10b981';
                            $roleText = 'Cliente';
                        } elseif($role == 'admin') {
                            $icon = 'fa-user-shield';
                            $color = '#8b5cf6';
                            $roleText = 'Administrador';
                        } else {
                            $icon = 'fa-user';
                            $color = '#6b7280';
                            $roleText = 'Usuario';
                        }
                        
                        // Texto del estado
                        $statusText = '';
                        $statusClass = '';
                        if($status == 'active') {
                            $statusText = 'Activo';
                            $statusClass = 'status-active';
                        } elseif($status == 'pending') {
                            $statusText = 'Pendiente';
                            $statusClass = 'status-pending';
                        } elseif($status == 'inactive') {
                            $statusText = 'Inactivo';
                            $statusClass = 'status-inactive';
                        }
                    ?>
                    <li class="activity-item">
                        <div class="activity-icon" style="background: linear-gradient(135deg, <?php echo $color; ?>, <?php echo adjustBrightness($color, -30); ?>);">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="activity-content">
                            <h4>
                                <?php echo $username; ?>
                                <?php if($statusText): ?>
                                    <span class="user-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                <?php endif; ?>
                            </h4>
                            <p><?php echo $roleText; ?> registrado en el sistema</p>
                            <div class="activity-time">
                                <i class="fas fa-user-tag"></i>
                                ID: <?php echo $actividad['id']; ?>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Logout Button -->
        <div style="text-align: center; margin-top: 40px; display: flex; justify-content: center; gap: 20px;">
            <a href="tecnicos.php" class="logout-btn" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                <i class="fas fa-users-cog"></i>
                Ver Técnicos
            </a>
            <a href="aprobar.php" class="logout-btn" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="fas fa-check-circle"></i>
                Aprobar Solicitudes
            </a>
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Cerrar Sesión
            </a>
        </div>
    </div>

    <script>
        // Actualizar hora actual
        function updateTime() {
            const now = new Date();
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                const options = { 
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                };
                timeElement.textContent = 'Última actualización: ' + now.toLocaleDateString('es-ES', options);
            }
        }

        // Actualizar cada segundo
        updateTime();
        setInterval(updateTime, 1000);

        // Mostrar notificación si hay pendientes
        <?php if($tecnicosPendientes > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            if("Notification" in window && Notification.permission === "granted") {
                new Notification("⚠️ Solicitudes pendientes", {
                    body: "Tienes <?php echo $tecnicosPendientes; ?> solicitud(es) de técnicos por revisar",
                    icon: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23f59e0b'><path d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z'/></svg>"
                });
            } else if("Notification" in window && Notification.permission !== "denied") {
                Notification.requestPermission().then(permission => {
                    if(permission === "granted") {
                        new Notification("⚠️ Solicitudes pendientes", {
                            body: "Tienes <?php echo $tecnicosPendientes; ?> solicitud(es) de técnicos por revisar",
                            icon: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23f59e0b'><path d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z'/></svg>"
                        });
                    }
                });
            }
        });
        <?php endif; ?>

        // Efecto hover en tarjetas
        const actionButtons = document.querySelectorAll('.action-button');
        actionButtons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                const icon = this.querySelector('.action-icon');
                icon.style.transform = 'scale(1.1) rotate(5deg)';
                icon.style.transition = 'transform 0.3s ease';
            });
            
            button.addEventListener('mouseleave', function() {
                const icon = this.querySelector('.action-icon');
                icon.style.transform = 'scale(1) rotate(0)';
            });
        });

        // Auto-refresh opcional cada 2 minutos
        let autoRefreshEnabled = true;
        let refreshTimer = null;

        function startAutoRefresh() {
            if (autoRefreshEnabled) {
                refreshTimer = setTimeout(() => {
                    location.reload();
                }, 120000); // 2 minutos
            }
        }

        function stopAutoRefresh() {
            clearTimeout(refreshTimer);
            autoRefreshEnabled = false;
        }

        // Iniciar auto-refresh
        startAutoRefresh();

        // Permitir al usuario detener el auto-refresh
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                stopAutoRefresh();
                alert('Auto-refresh desactivado. Presiona F5 para actualizar manualmente.');
            }
        });
    </script>
</body>
</html>

<?php
// Función auxiliar para ajustar brillo de colores
function adjustBrightness($hex, $steps) {
    $steps = max(-255, min(255, $steps));
    $hex = str_replace('#', '', $hex);
    
    if(strlen($hex) == 3) {
        $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
    }
    
    $color_parts = str_split($hex, 2);
    $return = '#';
    
    foreach($color_parts as $color) {
        $color = hexdec($color);
        $color = max(0, min(255, $color + $steps));
        $return .= str_pad(dechex($color), 2, '0', STR_PAD_LEFT);
    }
    
    return $return;
}
?>
