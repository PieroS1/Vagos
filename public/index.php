<?php
session_start();
require "../config/db.php";

// Variables para mensajes
$error_message = "";
$success_message = "";

if($_SERVER["REQUEST_METHOD"]=="POST"){
    $user = $_POST["username"] ?? "";
    $pass = $_POST["password"] ?? "";

    // Validar campos vacíos
    if(empty($user) || empty($pass)){
        $error_message = "Por favor, completa todos los campos";
    } else {
        // Buscar usuario
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
        $stmt->execute([$user]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$data){
            $error_message = "Usuario o contraseña incorrectos";
        } else {
            // Obtener hash generado por MariaDB
            $stmt2 = $pdo->prepare("SELECT PASSWORD(?)");
            $stmt2->execute([$pass]);
            $hashed = $stmt2->fetchColumn();

            if($hashed != $data["password"]){
                $error_message = "Usuario o contraseña incorrectos";
            } else {
                // Si es técnico pendiente
                if($data["role"]=="tecnico" && $data["status"]=="pending"){
                    $error_message = "Tu cuenta está esperando aprobación del administrador";
                } else {
                    $_SESSION["user"] = $data["username"];
                    $_SESSION["role"] = $data["role"];
                    $_SESSION["user_id"] = $data["id"];
                    $_SESSION["full_name"] = $data["full_name"] ?? $data["username"];

                    // Redirección por rol
                    $redirect_url = "";
                    switch($data["role"]){
                        case "admin":
                            $redirect_url = "../admin/index.php";
                            break;
                        case "tecnico":
                            $redirect_url = "../tecnico/index.php";
                            break;
                        case "cliente":
                            $redirect_url = "../cliente/index.php";
                            break;
                        default:
                            $redirect_url = "../public/index.php";
                    }
                    
                    // Redirección con mensaje de éxito
                    $_SESSION['login_success'] = "¡Bienvenido, " . htmlspecialchars($data["username"]) . "!";
                    header("Location: $redirect_url");
                    exit;
                }
            }
        }
    }
}

// Si llegamos aquí, mostramos el formulario con errores
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema IoT</title>
    <link rel="stylesheet" href="../css/styles.css">
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

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 450px;
            overflow: hidden;
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

        .login-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            animation: float 20s linear infinite;
        }

        @keyframes float {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .login-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 16px;
            position: relative;
            z-index: 1;
        }

        .login-header i {
            font-size: 60px;
            margin-bottom: 20px;
            display: block;
            position: relative;
            z-index: 1;
            color: rgba(255, 255, 255, 0.9);
        }

        .login-content {
            padding: 40px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
            border: 1px solid #f87171;
        }

        .alert-success {
            background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%);
            color: #065f46;
            border: 1px solid #34d399;
        }

        .alert i {
            font-size: 20px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: var(--primary);
            width: 20px;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            transition: color 0.3s ease;
        }

        .input-with-icon input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .input-with-icon input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            background: white;
        }

        .input-with-icon input:focus + i {
            color: var(--primary);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #a0aec0;
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(79, 70, 229, 0.2);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 14px;
        }

        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .login-footer a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4a5568;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 2px solid #cbd5e0;
            cursor: pointer;
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
                margin: 10px;
            }
            
            .login-content {
                padding: 30px 20px;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-microchip"></i>
            <h1>Sistema IoT</h1>
            <p>Control y monitoreo de dispositivos inteligentes</p>
        </div>
        
        <div class="login-content">
            <?php if($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if(isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>Sesión cerrada exitosamente</span>
                </div>
            <?php endif; ?>

            <?php if(isset($_GET['registered']) && $_GET['registered'] == 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>¡Registro exitoso! Por favor inicia sesión</span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Usuario
                    </label>
                    <div class="input-with-icon">
                        <i class="fas fa-user-circle"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="Ingresa tu nombre de usuario"
                               value="<?php echo htmlspecialchars($user ?? ''); ?>"
                               required
                               autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Contraseña
                    </label>
                    <div class="input-with-icon">
                        <i class="fas fa-key"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="Ingresa tu contraseña"
                               required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="remember-forgot">
                    <div class="checkbox-group">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Recordar sesión</label>
                    </div>
                    <a href="#" class="forgot-link">¿Olvidaste tu contraseña?</a>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Iniciar Sesión</span>
                </button>
            </form>

            <div class="login-footer">
                <p>¿No tienes una cuenta? 
                    <a href="../public/registrar_tecnico.php">Regístrate aquí</a>
                </p>
                <p style="margin-top: 10px; font-size: 12px; color: #a0aec0;">
                    <i class="fas fa-shield-alt"></i> Tu información está protegida
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }

        // Efecto de focus en inputs
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });

        // Prevenir envío múltiple del formulario
        document.querySelector('form').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Iniciando sesión...</span>';
        });

        // Auto focus en el campo de usuario si hay error
        <?php if($error_message): ?>
            document.getElementById('username').focus();
        <?php endif; ?>
    </script>
</body>
</html>
