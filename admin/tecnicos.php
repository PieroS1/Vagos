<?php
session_start();
require "../config/db.php";

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

// Manejar eliminación de técnico
if(isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $id_eliminar = $_GET['eliminar'];
    
    // Verificar que el usuario existe y es técnico
    $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
    $stmt->execute([$id_eliminar]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($usuario && $usuario['role'] == 'tecnico') {
        // Eliminar el técnico
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if($stmt->execute([$id_eliminar])) {
            $mensaje = "Técnico eliminado exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al eliminar el técnico";
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "Usuario no encontrado o no es técnico";
        $tipo_mensaje = "error";
    }
    
    // Redireccionar
    header("Location: tecnicos.php?mensaje=" . urlencode($mensaje) . "&tipo=" . $tipo_mensaje);
    exit();
}

// Obtener todos los técnicos (SIN created_at)
$stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE role = 'tecnico' ORDER BY id DESC");
$stmt->execute();
$tecnicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar técnicos por estado
$stmtActivos = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'tecnico' AND status = 'active'");
$stmtActivos->execute();
$totalActivos = $stmtActivos->fetchColumn();

$stmtPendientes = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'tecnico' AND status = 'pending'");
$stmtPendientes->execute();
$totalPendientes = $stmtPendientes->fetchColumn();

$stmtInactivos = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'tecnico' AND status = 'inactive'");
$stmtInactivos->execute();
$totalInactivos = $stmtInactivos->fetchColumn();

$totalTecnicos = count($tecnicos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Técnicos - Sistema IoT</title>
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
            padding: 20px;
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .welcome-section h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
        }

        .stat-card.active { border-top-color: var(--success); }
        .stat-card.pending { border-top-color: var(--warning); }
        .stat-card.inactive { border-top-color: var(--danger); }

        .stat-card i {
            font-size: 40px;
            margin-bottom: 15px;
        }

        .stat-card.active i { color: var(--success); }
        .stat-card.pending i { color: var(--warning); }
        .stat-card.inactive i { color: var(--danger); }

        .stat-card .number {
            font-size: 36px;
            font-weight: 700;
            color: var(--dark);
            display: block;
            margin-bottom: 5px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            color: #4a5568;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="admin-header">
            <div class="welcome-section">
                <h1>
                    <i class="fas fa-users-cog"></i>
                    Gestión de Técnicos
                </h1>
                <p>Administra los técnicos registrados en el sistema</p>
            </div>
            
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver al Panel
                </a>
            </div>
        </div>

        <!-- Mostrar mensajes -->
        <?php if(isset($_GET['mensaje'])): ?>
            <div class="alert alert-<?php echo $_GET['tipo'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo ($_GET['tipo'] ?? 'success') == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <span><?php echo htmlspecialchars($_GET['mensaje']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <span class="number"><?php echo $totalTecnicos; ?></span>
                <span class="label">Total de Técnicos</span>
            </div>
            
            <div class="stat-card active">
                <i class="fas fa-check-circle"></i>
                <span class="number"><?php echo $totalActivos; ?></span>
                <span class="label">Activos</span>
            </div>
            
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <span class="number"><?php echo $totalPendientes; ?></span>
                <span class="label">Pendientes</span>
            </div>
            
            <div class="stat-card inactive">
                <i class="fas fa-times-circle"></i>
                <span class="number"><?php echo $totalInactivos; ?></span>
                <span class="label">Inactivos</span>
            </div>
        </div>

        <!-- Tabla de Técnicos -->
        <?php if(empty($tecnicos)): ?>
            <div class="no-data">
                <i class="fas fa-users-slash"></i>
                <h3>No hay técnicos registrados</h3>
                <p>No se han encontrado técnicos en el sistema</p>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tecnicos as $tecnico): 
                        $statusClass = '';
                        $statusText = '';
                        switch($tecnico['status']) {
                            case 'active':
                                $statusClass = 'status-active';
                                $statusText = 'Activo';
                                break;
                            case 'pending':
                                $statusClass = 'status-pending';
                                $statusText = 'Pendiente';
                                break;
                            case 'inactive':
                                $statusClass = 'status-inactive';
                                $statusText = 'Inactivo';
                                break;
                            default:
                                $statusClass = 'status-inactive';
                                $statusText = 'Desconocido';
                        }
                    ?>
                    <tr>
                        <td>#<?php echo $tecnico['id']; ?></td>
                        <td><?php echo htmlspecialchars($tecnico['username']); ?></td>
                        <td>
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $statusText; ?>
                            </span>
                        </td>
                        <td>
                            <?php if($tecnico['status'] == 'pending'): ?>
                                <a href="aprobar.php?id=<?php echo $tecnico['id']; ?>" style="background: var(--success); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; margin-right: 10px;">
                                    <i class="fas fa-check"></i> Aprobar
                                </a>
                            <?php endif; ?>
                            
                            <a href="tecnicos.php?eliminar=<?php echo $tecnico['id']; ?>" 
                               class="btn-danger"
                               onclick="return confirm('¿Seguro que deseas eliminar a <?php echo htmlspecialchars($tecnico['username']); ?>?')">
                                <i class="fas fa-trash"></i> Eliminar
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Botón para volver -->
        <div style="text-align: center; margin-top: 40px;">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver al Panel de Administración
            </a>
        </div>
    </div>

    <script>
        // Auto-ocultar mensajes después de 5 segundos
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            }
        }, 5000);
    </script>
</body>
</html>
