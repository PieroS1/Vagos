<?php
require "../config/db.php";

$error_message = "";
$success_message = "";
$username = "";

if($_SERVER["REQUEST_METHOD"]=="POST"){
    $user = trim($_POST["username"] ?? "");
    $pass = $_POST["password"] ?? "";
    $confirm_pass = $_POST["confirm_password"] ?? "";
    $fullname = trim($_POST["fullname"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    // Validaciones
    if(empty($user) || empty($pass) || empty($confirm_pass)) {
        $error_message = "Todos los campos son obligatorios";
        $username = $user;
    } elseif(strlen($pass) < 6) {
        $error_message = "La contraseña debe tener al menos 6 caracteres";
        $username = $user;
    } elseif($pass !== $confirm_pass) {
        $error_message = "Las contraseñas no coinciden";
        $username = $user;
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email)) {
        $error_message = "Correo electrónico inválido";
        $username = $user;
    } else {
        // Verificar si el usuario ya existe
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $checkStmt->execute([$user]);
        $userExists = $checkStmt->fetchColumn();

        if($userExists > 0) {
            $error_message = "El nombre de usuario ya está registrado";
            $username = $user;
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status, full_name, email, phone, created_at) 
                                       VALUES (?, PASSWORD(?), 'tecnico', 'pending', ?, ?, ?, NOW())");
                $stmt->execute([$user, $pass, $fullname, $email, $phone]);

                $success_message = "¡Registro exitoso! Tu cuenta está pendiente de aprobación del administrador.";
                $username = "";
            } catch(PDOException $e) {
                $error_message = "Error en el registro. Por favor intenta nuevamente.";
                $username = $user;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Técnico - Sistema IoT</title>
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

        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 500px;
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

        .register-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 35px 30px;
            text-align: center;
            position: relative;
        }

        .register-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .register-header p {
            opacity: 0.9;
            font-size: 15px;
            max-width: 80%;
            margin: 0 auto;
            line-height: 1.5;
        }

        .steps-indicator {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .step {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
        }

        .step.active {
            background: white;
            transform: scale(1.3);
        }

        .register-content {
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

        .input-with-icon input, .input-with-icon select {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .input-with-icon input:focus, .input-with-icon select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            background: white;
        }

        .input-with-icon input:focus + i, .input-with-icon select:focus + i {
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

        .password-strength {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            background: var(--danger);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .password-strength-bar.weak { width: 33%; background: #ef4444; }
        .password-strength-bar.medium { width: 66%; background: #f59e0b; }
        .password-strength-bar.strong { width: 100%; background: #10b981; }

        .password-requirements {
            margin-top: 8px;
            font-size: 12px;
            color: #718096;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .requirement i {
            font-size: 12px;
        }

        .requirement.valid {
            color: #10b981;
        }

        .requirement.invalid {
            color: #718096;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .register-btn {
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

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(79, 70, 229, 0.2);
        }

        .register-btn:active {
            transform: translateY(0);
        }

        .register-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 14px;
        }

        .register-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .register-footer a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        .terms-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            color: #4a5568;
        }

        .terms-group input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 2px solid #cbd5e0;
            cursor: pointer;
            flex-shrink: 0;
        }

        .terms-group a {
            color: var(--primary);
            text-decoration: none;
        }

        .terms-group a:hover {
            text-decoration: underline;
        }

        .info-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            color: #0369a1;
        }

        .info-box h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .info-box ul {
            padding-left: 20px;
            margin: 0;
        }

        .info-box li {
            margin-bottom: 8px;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .register-container {
                max-width: 100%;
                margin: 10px;
            }
            
            .register-content {
                padding: 30px 20px;
            }
            
            .register-header {
                padding: 25px 20px;
            }
            
            .register-header h1 {
                font-size: 24px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1>
                <i class="fas fa-user-plus"></i>
                Registro de Técnico
            </h1>
            <p>Completa el formulario para solicitar acceso como técnico</p>
            <div class="steps-indicator">
                <div class="step active"></div>
                <div class="step"></div>
                <div class="step"></div>
            </div>
        </div>
        
        <div class="register-content">
            <?php if($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
                <div class="register-footer">
                    <p>¿Ya tienes cuenta? <a href="../public/index.php">Inicia sesión aquí</a></p>
                </div>
                <?php exit(); ?>
            <?php endif; ?>

            <div class="info-box">
                <h3><i class="fas fa-info-circle"></i> Información importante</h3>
                <ul>
                    <li>Tu cuenta requerirá aprobación del administrador</li>
                    <li>Proporciona información precisa y verídica</li>
                    <li>Recibirás una notificación cuando tu cuenta sea aprobada</li>
                </ul>
            </div>

            <form method="POST" action="" id="registerForm">
                <div class="form-group">
                    <label for="fullname">
                        <i class="fas fa-id-card"></i> Nombre completo
                    </label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               id="fullname" 
                               name="fullname" 
                               placeholder="Ingresa tu nombre completo"
                               value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user-tag"></i> Nombre de usuario
                    </label>
                    <div class="input-with-icon">
                        <i class="fas fa-at"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="Ej: juan.perez"
                               value="<?php echo htmlspecialchars($username); ?>"
                               required
                               onblur="checkUsername()">
                    </div>
                    <div id="username-feedback" style="font-size: 12px; margin-top: 5px;"></div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i> Correo electrónico
                        </label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   placeholder="tecnico@ejemplo.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">
                            <i class="fas fa-phone"></i> Teléfono
                        </label>
                        <div class="input-with-icon">
                            <i class="fas fa-phone-alt"></i>
                            <input type="tel" 
                                   id="phone" 
                                   name="phone" 
                                   placeholder="+51 987654321"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock"></i> Contraseña
                        </label>
                        <div class="input-with-icon">
                            <i class="fas fa-key"></i>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Mínimo 6 caracteres"
                                   required
                                   onkeyup="checkPasswordStrength()">
                            <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password-strength-bar"></div>
                        </div>
                        <div class="password-requirements" id="password-requirements">
                            <div class="requirement invalid" id="req-length">
                                <i class="fas fa-circle"></i>
                                <span>6+ caracteres</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">
                            <i class="fas fa-lock"></i> Confirmar contraseña
                        </label>
                        <div class="input-with-icon">
                            <i class="fas fa-key"></i>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   placeholder="Repite tu contraseña"
                                   required
                                   onkeyup="checkPasswordMatch()">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="password-match" style="font-size: 12px; margin-top: 5px;"></div>
                    </div>
                </div>

                <div class="terms-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">
                        Acepto los <a href="#">términos y condiciones</a> y la 
                        <a href="#">política de privacidad</a> del sistema.
                    </label>
                </div>

                <button type="submit" class="register-btn" id="submitBtn">
                    <i class="fas fa-user-plus"></i>
                    <span>Solicitar Registro</span>
                </button>
            </form>

            <div class="register-footer">
                <p>¿Ya tienes una cuenta? <a href="../public/index.php">Inicia sesión aquí</a></p>
                <p style="margin-top: 10px; font-size: 12px; color: #a0aec0;">
                    <i class="fas fa-shield-alt"></i> Tus datos están protegidos
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = passwordInput.parentElement.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('password-strength-bar');
            const lengthReq = document.getElementById('req-length');
            
            let strength = 0;
            
            if (password.length >= 6) {
                strength += 33;
                lengthReq.className = 'requirement valid';
                lengthReq.innerHTML = '<i class="fas fa-check-circle"></i><span>6+ caracteres</span>';
            } else {
                lengthReq.className = 'requirement invalid';
                lengthReq.innerHTML = '<i class="fas fa-circle"></i><span>6+ caracteres</span>';
            }
            
            if (password.length >= 8) strength += 33;
            if (/[A-Z]/.test(password) && /[0-9]/.test(password)) strength += 34;
            
            strengthBar.style.width = strength + '%';
            
            if (strength < 33) {
                strengthBar.className = 'password-strength-bar weak';
            } else if (strength < 66) {
                strengthBar.className = 'password-strength-bar medium';
            } else {
                strengthBar.className = 'password-strength-bar strong';
            }
        }

        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchIndicator = document.getElementById('password-match');
            
            if (confirmPassword === '') {
                matchIndicator.innerHTML = '';
                return;
            }
            
            if (password === confirmPassword) {
                matchIndicator.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check"></i> Las contraseñas coinciden</span>';
            } else {
                matchIndicator.innerHTML = '<span style="color: #ef4444;"><i class="fas fa-times"></i> Las contraseñas no coinciden</span>';
            }
        }

        function checkUsername() {
            const username = document.getElementById('username').value;
            const feedback = document.getElementById('username-feedback');
            
            if (username.length < 3) {
                feedback.innerHTML = '<span style="color: #ef4444;">El usuario debe tener al menos 3 caracteres</span>';
                return;
            }
            
            if (!/^[a-zA-Z0-9._]+$/.test(username)) {
                feedback.innerHTML = '<span style="color: #ef4444;">Solo letras, números, puntos y guiones bajos</span>';
                return;
            }
            
            feedback.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check"></i> Usuario válido</span>';
        }

        // Prevenir envío múltiple del formulario
        document.getElementById('registerForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Registrando...</span>';
        });

        // Validación en tiempo real
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', function() {
                if (this.checkValidity()) {
                    this.style.borderColor = '#10b981';
                } else {
                    this.style.borderColor = '#e2e8f0';
                }
            });
        });

        // Efecto de focus en inputs
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>
