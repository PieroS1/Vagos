<?php
session_start();

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

require "../config/db.php";

try {
    // Información del técnico
    $stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE username = ? AND role = 'tecnico'");
    $stmt->execute([$_SESSION["user"]]);
    $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$tecnico) {
        session_destroy();
        header("Location: ../public/index.php?error=Usuario+no+encontrado");
        exit();
    }
    
    $tecnico_id = $tecnico['id'];
    
    // ========== DATOS REALES DE TODAS LAS TABLAS ==========
    
    // 1. DISPOSITIVOS asignados a este técnico
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activos,
               SUM(CASE WHEN estado = 'inactivo' THEN 1 ELSE 0 END) as inactivos,
               SUM(CASE WHEN estado = 'mantenimiento' THEN 1 ELSE 0 END) as mantenimiento
        FROM dispositivos 
        WHERE tecnico_id = ?
    ");
    $stmt->execute([$tecnico_id]);
    $estadisticasDispositivos = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Dispositivos recientes asignados
    $stmt = $pdo->prepare("
        SELECT d.*, u.username as cliente_nombre 
        FROM dispositivos d
        LEFT JOIN users u ON d.cliente_id = u.id
        WHERE d.tecnico_id = ?
        ORDER BY d.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$tecnico_id]);
    $dispositivosRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. ALERTAS asignadas a este técnico
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
               SUM(CASE WHEN estado = 'en_progreso' THEN 1 ELSE 0 END) as en_progreso,
               SUM(CASE WHEN estado = 'resuelto' THEN 1 ELSE 0 END) as resueltas,
               SUM(CASE WHEN tipo = 'error' THEN 1 ELSE 0 END) as errores,
               SUM(CASE WHEN tipo = 'advertencia' THEN 1 ELSE 0 END) as advertencias,
               SUM(CASE WHEN tipo = 'info' THEN 1 ELSE 0 END) as informativas
        FROM alertas 
        WHERE tecnico_id = ?
    ");
    $stmt->execute([$tecnico_id]);
    $estadisticasAlertas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Alertas urgentes (pendientes y en progreso)
    $stmt = $pdo->prepare("
        SELECT a.*, d.nombre as dispositivo_nombre, d.tipo as dispositivo_tipo
        FROM alertas a
        LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
        WHERE a.tecnico_id = ? AND a.estado IN ('pendiente', 'en_progreso')
        ORDER BY 
            CASE a.tipo 
                WHEN 'error' THEN 1
                WHEN 'advertencia' THEN 2
                WHEN 'info' THEN 3
            END,
            a.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$tecnico_id]);
    $alertasPendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. TAREAS asignadas a este técnico
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
               SUM(CASE WHEN estado = 'en_progreso' THEN 1 ELSE 0 END) as en_progreso,
               SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) as completadas,
               SUM(CASE WHEN prioridad = 'critica' THEN 1 ELSE 0 END) as criticas,
               SUM(CASE WHEN prioridad = 'alta' THEN 1 ELSE 0 END) as altas,
               SUM(CASE WHEN prioridad = 'media' THEN 1 ELSE 0 END) as medias,
               SUM(CASE WHEN prioridad = 'baja' THEN 1 ELSE 0 END) as bajas
        FROM tareas 
        WHERE tecnico_id = ?
    ");
    $stmt->execute([$tecnico_id]);
    $estadisticasTareas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Tareas urgentes (pendientes y en progreso)
    $stmt = $pdo->prepare("
        SELECT t.*, u.username as cliente_nombre
        FROM tareas t
        LEFT JOIN users u ON t.cliente_id = u.id
        WHERE t.tecnico_id = ? AND t.estado IN ('pendiente', 'en_progreso')
        ORDER BY 
            CASE t.prioridad 
                WHEN 'critica' THEN 1
                WHEN 'alta' THEN 2
                WHEN 'media' THEN 3
                WHEN 'baja' THEN 4
            END,
            t.fecha_limite ASC
        LIMIT 5
    ");
    $stmt->execute([$tecnico_id]);
    $tareasPendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. CLIENTES asignados a este técnico (a través de dispositivos)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.cliente_id) as total_clientes
        FROM dispositivos d
        WHERE d.tecnico_id = ? AND d.cliente_id IS NOT NULL
    ");
    $stmt->execute([$tecnico_id]);
    $clientesAsignados = $stmt->fetchColumn();
    
    // 5. ESTADÍSTICAS GENERALES del sistema
    $totalDispositivos = $pdo->query("SELECT COUNT(*) FROM dispositivos")->fetchColumn();
    $totalAlertas = $pdo->query("SELECT COUNT(*) FROM alertas")->fetchColumn();
    $totalTareas = $pdo->query("SELECT COUNT(*) FROM tareas")->fetchColumn();
    $totalClientes = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'cliente'")->fetchColumn();
    
    // 6. MI ACTIVIDAD RECIENTE (combinación de todas las tablas)
    $stmt = $pdo->prepare("
        (SELECT 'dispositivo' as tipo, nombre as titulo, created_at 
         FROM dispositivos WHERE tecnico_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'alerta' as tipo, mensaje as titulo, created_at 
         FROM alertas WHERE tecnico_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'tarea' as tipo, titulo, created_at 
         FROM tareas WHERE tecnico_id = ? ORDER BY created_at DESC LIMIT 3)
        ORDER BY created_at DESC 
        LIMIT 8
    ");
    $stmt->execute([$tecnico_id, $tecnico_id, $tecnico_id]);
    $miActividad = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Error al cargar datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del Técnico - Sistema IoT</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1f2937;
            --light: #f9fafb;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .tecnico-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .tecnico-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border-left: 6px solid var(--primary);
        }

        .welcome-section h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
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
            margin-top: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }

        .card-header h2 {
            color: var(--dark);
            font-size: 18px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-total {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value {
            color: var(--dark);
            font-weight: 600;
            font-size: 16px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-activo { background: #d1fae5; color: #065f46; }
        .status-inactivo { background: #f3f4f6; color: #374151; }
        .status-mantenimiento { background: #fef3c7; color: #92400e; }
        .status-pendiente { background: #fef3c7; color: #92400e; }
        .status-en_progreso { background: #dbeafe; color: #1e40af; }
        .status-resuelto { background: #d1fae5; color: #065f46; }
        .status-completada { background: #d1fae5; color: #065f46; }

        .priority-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .priority-critica { background: #fee2e2; color: #991b1b; }
        .priority-alta { background: #fef3c7; color: #92400e; }
        .priority-media { background: #dbeafe; color: #1e40af; }
        .priority-baja { background: #f3f4f6; color: #374151; }

        .alert-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-advertencia { background: #fef3c7; color: #92400e; }
        .alert-info { background: #dbeafe; color: #1e40af; }

        .data-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .section-title h2 {
            color: var(--dark);
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .data-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .data-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.3s ease;
        }

        .data-item:hover {
            background: #f8fafc;
        }

        .data-item:last-child {
            border-bottom: none;
        }

        .data-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .data-content {
            flex: 1;
        }

        .data-content h4 {
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 16px;
        }

        .data-content p {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }

        .data-time {
            font-size: 12px;
            color: #9ca3af;
            white-space: nowrap;
        }

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
            margin-top: 30px;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.2);
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #d1d5db;
        }

        .system-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .system-stat {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
        }

        .system-stat .number {
            font-size: 32px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 10px;
        }

        /* Estilos para las tarjetas de acción */
        .action-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 25px;
            color: white;
            text-decoration: none;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
        }

        .action-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .action-content h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }

        .action-content p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .tecnico-container {
                padding: 15px;
            }
            
            .data-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .data-time {
                align-self: flex-end;
            }
            
            .action-card {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .action-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="tecnico-container">
        <!-- Header -->
        <div class="tecnico-header">
            <div class="welcome-section">
                <h1>
                    <i class="fas fa-tools"></i>
                    Panel del Técnico IoT
                </h1>
                <p>Bienvenido, <strong><?php echo htmlspecialchars($_SESSION["user"]); ?></strong></p>
                <p>Gestiona dispositivos, alertas y tareas del sistema IoT</p>
                
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION["user"], 0, 1)); ?>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: var(--dark);">ID: #<?php echo $tecnico_id; ?></h3>
                        <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 14px;">
                            <?php echo $tecnico['status'] == 'active' ? '✅ Técnico Activo' : '⏳ Pendiente de Aprobación'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Herramientas de Gestión -->
        <div class="data-section">
            <div class="section-title">
                <h2><i class="fas fa-cogs"></i> Herramientas de Gestión</h2>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                <!-- Registrar Nuevo Dispositivo -->
                <a href="registrar_dispositivo.php" class="action-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-content">
                        <h3>Registrar Dispositivo</h3>
                        <p>Agregar nuevo dispositivo IoT al sistema</p>
                    </div>
                </a>
                
                <!-- Gestionar Clientes -->
                <a href="gestionar_clientes.php" class="action-card" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <div class="action-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="action-content">
                        <h3>Gestionar Clientes</h3>
                        <p>Agregar y administrar clientes</p>
                    </div>
                </a>
                
                <!-- Ver Todos los Dispositivos -->
                <a href="dispositivos.php" class="action-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="action-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="action-content">
                        <h3>Ver Dispositivos</h3>
                        <p>Lista completa de dispositivos</p>
                    </div>
                </a>
                
                <!-- Crear Tarea -->
                <a href="crear_tarea.php" class="action-card" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="action-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="action-content">
                        <h3>Crear Tarea</h3>
                        <p>Registrar nueva tarea o mantenimiento</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Estadísticas del Sistema -->
        <div class="data-section">
            <div class="section-title">
                <h2><i class="fas fa-chart-bar"></i> Estadísticas del Sistema</h2>
            </div>
            
            <div class="system-stats">
                <div class="system-stat">
                    <div class="number"><?php echo $totalDispositivos; ?></div>
                    <div>Dispositivos Totales</div>
                </div>
                <div class="system-stat">
                    <div class="number"><?php echo $totalAlertas; ?></div>
                    <div>Alertas Totales</div>
                </div>
                <div class="system-stat">
                    <div class="number"><?php echo $totalTareas; ?></div>
                    <div>Tareas Totales</div>
                </div>
                <div class="system-stat">
                    <div class="number"><?php echo $totalClientes; ?></div>
                    <div>Clientes</div>
                </div>
            </div>
        </div>

        <!-- Dashboard de Estadísticas -->
        <div class="stats-grid">
            <!-- Dispositivos -->
            <div class="stats-card">
                <div class="card-header">
                    <h2><i class="fas fa-microchip"></i> Dispositivos</h2>
                    <div class="card-total"><?php echo $estadisticasDispositivos['total'] ?? 0; ?></div>
                </div>
                
                <?php if($estadisticasDispositivos && $estadisticasDispositivos['total'] > 0): ?>
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            Activos
                        </div>
                        <div class="stat-value"><?php echo $estadisticasDispositivos['activos'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-times-circle" style="color: #6b7280;"></i>
                            Inactivos
                        </div>
                        <div class="stat-value"><?php echo $estadisticasDispositivos['inactivos'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-wrench" style="color: #f59e0b;"></i>
                            Mantenimiento
                        </div>
                        <div class="stat-value"><?php echo $estadisticasDispositivos['mantenimiento'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-users" style="color: #3b82f6;"></i>
                            Clientes
                        </div>
                        <div class="stat-value"><?php echo $clientesAsignados; ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-data" style="padding: 20px;">
                    <i class="fas fa-microchip"></i>
                    <p>No tienes dispositivos asignados</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Alertas -->
            <div class="stats-card">
                <div class="card-header">
                    <h2><i class="fas fa-exclamation-triangle"></i> Alertas</h2>
                    <div class="card-total"><?php echo $estadisticasAlertas['total'] ?? 0; ?></div>
                </div>
                
                <?php if($estadisticasAlertas && $estadisticasAlertas['total'] > 0): ?>
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-clock" style="color: #f59e0b;"></i>
                            Pendientes
                        </div>
                        <div class="stat-value"><?php echo $estadisticasAlertas['pendientes'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-spinner" style="color: #3b82f6;"></i>
                            En Progreso
                        </div>
                        <div class="stat-value"><?php echo $estadisticasAlertas['en_progreso'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            Resueltas
                        </div>
                        <div class="stat-value"><?php echo $estadisticasAlertas['resueltas'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-times-circle" style="color: #ef4444;"></i>
                            Errores
                        </div>
                        <div class="stat-value"><?php echo $estadisticasAlertas['errores'] ?? 0; ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-data" style="padding: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>No tienes alertas asignadas</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tareas -->
            <div class="stats-card">
                <div class="card-header">
                    <h2><i class="fas fa-tasks"></i> Tareas</h2>
                    <div class="card-total"><?php echo $estadisticasTareas['total'] ?? 0; ?></div>
                </div>
                
                <?php if($estadisticasTareas && $estadisticasTareas['total'] > 0): ?>
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-clock" style="color: #f59e0b;"></i>
                            Pendientes
                        </div>
                        <div class="stat-value"><?php echo $estadisticasTareas['pendientes'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-spinner" style="color: #3b82f6;"></i>
                            En Progreso
                        </div>
                        <div class="stat-value"><?php echo $estadisticasTareas['en_progreso'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            Completadas
                        </div>
                        <div class="stat-value"><?php echo $estadisticasTareas['completadas'] ?? 0; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">
                            <i class="fas fa-fire" style="color: #ef4444;"></i>
                            Críticas
                        </div>
                        <div class="stat-value"><?php echo $estadisticasTareas['criticas'] ?? 0; ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-data" style="padding: 20px;">
                    <i class="fas fa-tasks"></i>
                    <p>No tienes tareas asignadas</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alertas Pendientes -->
        <?php if(!empty($alertasPendientes)): ?>
        <div class="data-section">
            <div class="section-title">
                <h2><i class="fas fa-bell"></i> Alertas Pendientes</h2>
                <span class="card-total"><?php echo count($alertasPendientes); ?> por atender</span>
            </div>
            
            <ul class="data-list">
                <?php foreach($alertasPendientes as $alerta): 
                    $color = '';
                    $icon = '';
                    switch($alerta['tipo']) {
                        case 'error': $color = '#ef4444'; $icon = 'fa-times-circle'; break;
                        case 'advertencia': $color = '#f59e0b'; $icon = 'fa-exclamation-triangle'; break;
                        case 'info': $color = '#3b82f6'; $icon = 'fa-info-circle'; break;
                    }
                    
                    $fecha = date('d/m H:i', strtotime($alerta['created_at']));
                ?>
                <li class="data-item">
                    <div class="data-icon" style="background: <?php echo $color; ?>;">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <div class="data-content">
                        <h4><?php echo htmlspecialchars(substr($alerta['mensaje'], 0, 100)); ?>...</h4>
                        <p>
                            <?php if($alerta['dispositivo_nombre']): ?>
                                <strong>Dispositivo:</strong> <?php echo htmlspecialchars($alerta['dispositivo_nombre']); ?> 
                                (<?php echo htmlspecialchars($alerta['dispositivo_tipo']); ?>)
                            <?php else: ?>
                                Sin dispositivo asignado
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                        <span class="alert-badge alert-<?php echo $alerta['tipo']; ?>">
                            <?php echo ucfirst($alerta['tipo']); ?>
                        </span>
                        <span class="status-badge status-<?php echo str_replace(' ', '_', $alerta['estado']); ?>">
                            <?php echo str_replace('_', ' ', ucfirst($alerta['estado'])); ?>
                        </span>
                        <div class="data-time"><?php echo $fecha; ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Tareas Pendientes -->
        <?php if(!empty($tareasPendientes)): ?>
        <div class="data-section">
            <div class="section-title">
                <h2><i class="fas fa-clipboard-list"></i> Tareas Pendientes</h2>
                <span class="card-total"><?php echo count($tareasPendientes); ?> por hacer</span>
            </div>
            
            <ul class="data-list">
                <?php foreach($tareasPendientes as $tarea): 
                    $fecha = $tarea['fecha_limite'] ? date('d/m/Y', strtotime($tarea['fecha_limite'])) : 'Sin fecha';
                    $hoy = date('Y-m-d');
                    $urgente = $tarea['fecha_limite'] && strtotime($tarea['fecha_limite']) < strtotime('+3 days');
                ?>
                <li class="data-item">
                    <div class="data-icon" style="background: <?php echo $urgente ? '#ef4444' : '#3b82f6'; ?>;">
                        <i class="fas <?php echo $urgente ? 'fa-fire' : 'fa-tasks'; ?>"></i>
                    </div>
                    <div class="data-content">
                        <h4><?php echo htmlspecialchars($tarea['titulo']); ?></h4>
                        <p>
                            <?php if($tarea['cliente_nombre']): ?>
                                <strong>Cliente:</strong> <?php echo htmlspecialchars($tarea['cliente_nombre']); ?>
                            <?php endif; ?>
                            <?php if($tarea['descripcion']): ?>
                                <br><?php echo htmlspecialchars(substr($tarea['descripcion'], 0, 80)); ?>...
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                        <span class="priority-badge priority-<?php echo $tarea['prioridad']; ?>">
                            <?php echo ucfirst($tarea['prioridad']); ?>
                        </span>
                        <span class="status-badge status-<?php echo str_replace(' ', '_', $tarea['estado']); ?>">
                            <?php echo str_replace('_', ' ', ucfirst($tarea['estado'])); ?>
                        </span>
                        <div class="data-time">Vence: <?php echo $fecha; ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Mi Actividad Reciente -->
        <div class="data-section">
            <div class="section-title">
                <h2><i class="fas fa-history"></i> Mi Actividad Reciente</h2>
            </div>
            
            <?php if(!empty($miActividad)): ?>
                <ul class="data-list">
                    <?php foreach($miActividad as $actividad): 
                        $color = '';
                        $icon = '';
                        $tipo_texto = '';
                        
                        switch($actividad['tipo']) {
                            case 'dispositivo': 
                                $color = '#10b981'; 
                                $icon = 'fa-microchip';
                                $tipo_texto = 'Dispositivo';
                                break;
                            case 'alerta': 
                                $color = '#f59e0b'; 
                                $icon = 'fa-exclamation-triangle';
                                $tipo_texto = 'Alerta';
                                break;
                            case 'tarea': 
                                $color = '#3b82f6'; 
                                $icon = 'fa-tasks';
                                $tipo_texto = 'Tarea';
                                break;
                        }
                        
                        $fecha = date('d/m H:i', strtotime($actividad['created_at']));
                    ?>
                    <li class="data-item">
                        <div class="data-icon" style="background: <?php echo $color; ?>;">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="data-content">
                            <h4><?php echo htmlspecialchars(substr($actividad['titulo'], 0, 100)); ?></h4>
                            <p><?php echo $tipo_texto; ?> registrado en el sistema</p>
                        </div>
                        <div class="data-time"><?php echo $fecha; ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-history"></i>
                    <p>No hay actividad reciente</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Acciones -->
        <div style="text-align: center; margin-top: 40px;">
            <a href="#" onclick="location.reload()" style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 25px; background: var(--primary); color: white; text-decoration: none; border-radius: 12px; font-weight: 600; margin-right: 15px;">
                <i class="fas fa-sync-alt"></i>
                Actualizar Panel
            </a>
            
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Cerrar Sesión
            </a>
        </div>
    </div>

    <script>
        // Auto-refresh cada 2 minutos para datos en tiempo real
        setTimeout(() => {
            if(confirm('¿Deseas actualizar los datos del panel?')) {
                location.reload();
            }
        }, 120000);
        
        // Notificaciones para alertas urgentes
        <?php if(isset($estadisticasAlertas['pendientes']) && $estadisticasAlertas['pendientes'] > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                alert(`Tienes <?php echo $estadisticasAlertas['pendientes']; ?> alerta(s) pendiente(s) por atender.`);
            }, 3000);
        });
        <?php endif; ?>
        
        // Efectos visuales
        document.querySelectorAll('.data-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(10px)';
                this.style.transition = 'transform 0.3s ease';
            });
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });
        
        // Actualizar hora cada minuto
        function updateTime() {
            const now = new Date();
            console.log(`Panel actualizado: ${now.toLocaleTimeString('es-ES')}`);
        }
        setInterval(updateTime, 60000);
        
        // Navegación entre secciones
        document.querySelectorAll('.action-card').forEach(card => {
            card.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if(href && href !== '#') {
                    window.location.href = href;
                }
            });
        });
    </script>
</body>
</html>
