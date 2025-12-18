<?php
session_start();
require "../config/db.php";

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

// Obtener datos del técnico antes de aprobar
if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        // Obtener información del técnico
        $stmt = $pdo->prepare("SELECT username, email, status FROM users WHERE id = ? AND role = 'tecnico'");
        $stmt->execute([$id]);
        $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$tecnico) {
            header("Location: tecnicos.php?mensaje=Técnico+no+encontrado&tipo=error");
            exit();
        }
        
        if($tecnico['status'] == 'active') {
            header("Location: tecnicos.php?mensaje=El+técnico+ya+está+activo&tipo=warning");
            exit();
        }
        
        // Procesar aprobación si se confirma
        if(isset($_GET['confirmar']) && $_GET['confirmar'] == 'si') {
            $stmt = $pdo->prepare("UPDATE users SET status='active' WHERE id=?");
            if($stmt->execute([$id])) {
                // También podemos registrar la fecha de aprobación si tuvieras la columna
                // $stmt = $pdo->prepare("UPDATE users SET approved_at = NOW() WHERE id=?");
                // $stmt->execute([$id]);
                
                header("Location: tecnicos.php?mensaje=Técnico+aprobado+exitosamente&tipo=success");
                exit();
            } else {
                header("Location: tecnicos.php?mensaje=Error+al+aprobar+técnico&tipo=error");
                exit();
            }
        }
        
    } catch(PDOException $e) {
        die("Error de base de datos: " . $e->getMessage());
    }
} else {
    header("Location: tecnicos.php?mensaje=ID+inválido&tipo=error");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aprobar Técnico - Sistema IoT</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --light: #f9fafb;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .approval-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .approval-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .approval-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
        }

        .approval-header h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .approval-header p {
            color: #6b7280;
            font-size: 16px;
            max-width: 80%;
            margin: 0 auto;
            line-height: 1.6;
        }

        .technician-info {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            border: 2px solid #e2e8f0;
        }

        .info-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .info-title i {
            color: var(--primary);
            font-size: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .info-label {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-label i {
            width: 20px;
            color: var(--primary);
        }

        .info-value {
            color: var(--dark);
            font-size: 16px;
            font-weight: 600;
            padding: 10px 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 10px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .warning-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #fbbf24;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 40px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { border-color: #fbbf24; }
            50% { border-color: #f59e0b; }
        }

        .warning-box h3 {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .warning-box p {
            color: #92400e;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .warning-box ul {
            padding-left: 20px;
            margin: 0;
        }

        .warning-box li {
            color: #92400e;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 16px 40px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            min-width: 180px;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.2);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(16, 185, 129, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
            box-shadow: 0 10px 20px rgba(107, 114, 128, 0.2);
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(107, 114, 128, 0.3);
        }

        .btn-icon {
            font-size: 18px;
        }

        .confirmation-dialog {
            text-align: center;
            padding: 30px;
            background: #f8fafc;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 2px dashed #cbd5e0;
        }

        .confirmation-dialog h3 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 20px;
        }

        .confirmation-dialog p {
            color: #6b7280;
            font-size: 16px;
            line-height: 1.6;
        }

        .user-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            margin: 0 auto 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            box-shadow: 0 15px 35px rgba(79, 70, 229, 0.3);
        }

        @media (max-width: 768px) {
            .approval-container {
                padding: 30px 20px;
            }
            
            .approval-header h1 {
                font-size: 26px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .approval-header h1 {
                font-size: 22px;
                flex-direction: column;
                gap: 10px;
            }
            
            .technician-info {
                padding: 20px;
            }
            
            .warning-box {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="approval-container">
        <div class="approval-header">
            <div class="user-avatar">
                <?php echo strtoupper(substr($tecnico['username'], 0, 2)); ?>
            </div>
            <h1>
                <i class="fas fa-user-check"></i>
                Aprobar Técnico
            </h1>
            <p>Confirma la aprobación de acceso al sistema</p>
        </div>

        <div class="technician-info">
            <div class="info-title">
                <i class="fas fa-id-card"></i>
                Información del Técnico
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-user"></i>
                        Usuario
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($tecnico['username']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-envelope"></i>
                        Correo Electrónico
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($tecnico['email'] ?? 'No especificado'); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-hourglass-half"></i>
                        Estado Actual
                    </div>
                    <div class="info-value">
                        <?php echo $tecnico['status'] == 'pending' ? 'Pendiente' : 'Activo'; ?>
                        <span class="status-badge <?php echo $tecnico['status'] == 'pending' ? 'status-pending' : 'status-active'; ?>">
                            <i class="fas fa-<?php echo $tecnico['status'] == 'pending' ? 'clock' : 'check-circle'; ?>"></i>
                            <?php echo $tecnico['status'] == 'pending' ? 'Pendiente' : 'Activo'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="warning-box">
            <h3>
                <i class="fas fa-exclamation-triangle"></i>
                Importante: Verificación Requerida
            </h3>
            <p>Antes de aprobar al técnico, verifica que:</p>
            <ul>
                <li>Los datos del usuario sean correctos</li>
                <li>El técnico cumpla con los requisitos del sistema</li>
                <li>Se haya realizado la validación correspondiente</li>
                <li>El correo electrónico sea válido y profesional</li>
            </ul>
        </div>

        <div class="confirmation-dialog">
            <h3>¿Deseas aprobar a este técnico?</h3>
            <p>Al aprobar, el técnico <strong><?php echo htmlspecialchars($tecnico['username']); ?></strong> 
            tendrá acceso completo al sistema y podrá comenzar a trabajar inmediatamente.</p>
        </div>

        <div class="actions">
            <a href="tecnicos.php" class="btn btn-secondary">
                <i class="fas fa-times btn-icon"></i>
                Cancelar
            </a>
            
            <a href="aprobar.php?id=<?php echo $id; ?>&confirmar=si" 
               class="btn btn-success"
               onclick="return confirmApproval()">
                <i class="fas fa-check-circle btn-icon"></i>
                Sí, Aprobar Técnico
            </a>
        </div>
    </div>

    <script>
        function confirmApproval() {
            return confirm(`¿Estás seguro de aprobar al técnico?\n\nEsta acción dará acceso completo al sistema.`);
        }

        // Animación de carga en el botón de aprobar
        document.addEventListener('DOMContentLoaded', function() {
            const approveBtn = document.querySelector('.btn-success');
            if (approveBtn) {
                approveBtn.addEventListener('click', function(e) {
                    if (!confirmApproval()) {
                        e.preventDefault();
                        return;
                    }
                    
                    // Mostrar loader
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
                    this.style.pointerEvents = 'none';
                    this.style.opacity = '0.8';
                    
                    // Restaurar después de 3 segundos si no se redirige
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.pointerEvents = 'auto';
                        this.style.opacity = '1';
                    }, 3000);
                });
            }
        });

        // Efecto de entrada para los elementos
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.technician-info, .warning-box, .confirmation-dialog');
            elements.forEach((el, index) => {
                el.style.animationDelay = `${index * 0.2}s`;
                el.style.animation = 'slideUp 0.5s ease forwards';
                el.style.opacity = '0';
            });
        });
    </script>
</body>
</html>
