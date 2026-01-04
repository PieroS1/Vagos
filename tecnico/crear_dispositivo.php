<?php
// Activar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Verificar sesión y rol
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

if(!isset($_SESSION["user_id"])){
    header("Location: ../public/index.php");
    exit();
}

$tecnico_id = $_SESSION["user_id"];

require "../config/db.php";

// Obtener clientes para asignar - CORREGIDO: sin filtrar por estado
$stmt_clientes = $pdo->query("SELECT id, username FROM users WHERE role = 'cliente'");
$clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

// Verificar si existe columna 'codigo'
$stmt_check = $pdo->prepare("
    SELECT COUNT(*) as exists_column 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'dispositivos' 
    AND column_name = 'codigo'
");
$stmt_check->execute();
$hasCodigoColumn = $stmt_check->fetch(PDO::FETCH_ASSOC)['exists_column'] > 0;

// Procesar formulario
if($_SERVER["REQUEST_METHOD"] == "POST"){
    try {
        $nombre = $_POST["nombre"] ?? '';
        $tipo = $_POST["tipo"] ?? 'temperatura';
        $protocolo = $_POST["protocolo"] ?? 'HTTP';
        $ubicacion = $_POST["ubicacion"] ?? '';
        $cliente_id = $_POST["cliente_id"] ?? null;
        $fecha_instalacion = $_POST["fecha_instalacion"] ?? date('Y-m-d');
        $descripcion = $_POST["descripcion"] ?? '';
        
        // Para MQTT, obtener código si existe la columna
        $codigo = '';
        if($hasCodigoColumn && $protocolo == 'MQTT') {
            $codigo = $_POST["codigo"] ?? '';
            if(empty($codigo)) {
                // Generar código automático
                $codigo = 'ESP32_' . strtoupper(substr(md5(uniqid()), 0, 6));
            }
        }
        
        // Validaciones básicas
        if(empty($nombre)){
            throw new Exception("El nombre del dispositivo es requerido");
        }
        
        // Verificar si ya existe dispositivo con mismo nombre para este técnico
        $stmt_check = $pdo->prepare("SELECT id FROM dispositivos WHERE nombre = ? AND tecnico_id = ?");
        $stmt_check->execute([$nombre, $tecnico_id]);
        
        if($stmt_check->rowCount() > 0){
            throw new Exception("Ya existe un dispositivo con ese nombre para tu cuenta");
        }
        
        // Para MQTT, verificar si código ya existe
        if($hasCodigoColumn && !empty($codigo) && $protocolo == 'MQTT') {
            $stmt_check_codigo = $pdo->prepare("SELECT id FROM dispositivos WHERE codigo = ?");
            $stmt_check_codigo->execute([$codigo]);
            
            if($stmt_check_codigo->rowCount() > 0){
                throw new Exception("Ya existe un dispositivo con el código MQTT: $codigo");
            }
        }
        
        // Insertar dispositivo
        if($hasCodigoColumn && $protocolo == 'MQTT') {
            // Insertar con código MQTT
            $stmt = $pdo->prepare("
                INSERT INTO dispositivos (
                    nombre, tipo, protocolo, ubicacion, 
                    cliente_id, tecnico_id, fecha_instalacion, 
                    descripcion, estado, codigo
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)
            ");
            $stmt->execute([
                $nombre, $tipo, $protocolo, $ubicacion,
                $cliente_id, $tecnico_id, $fecha_instalacion,
                $descripcion, $codigo
            ]);
        } else {
            // Insertar sin código
            $stmt = $pdo->prepare("
                INSERT INTO dispositivos (
                    nombre, tipo, protocolo, ubicacion, 
                    cliente_id, tecnico_id, fecha_instalacion, 
                    descripcion, estado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')
            ");
            $stmt->execute([
                $nombre, $tipo, $protocolo, $ubicacion,
                $cliente_id, $tecnico_id, $fecha_instalacion,
                $descripcion
            ]);
        }
        
        $dispositivo_id = $pdo->lastInsertId();
        
        // Redirigir con mensaje de éxito
        $_SESSION['mensaje_exito'] = "Dispositivo creado exitosamente";
        
        if($protocolo == 'MQTT' && $hasCodigoColumn) {
            $_SESSION['mensaje_exito'] .= "<br><strong>Código MQTT:</strong> $codigo";
            $_SESSION['mensaje_exito'] .= "<br><small>Usa este código en tu ESP32 para identificarse</small>";
        }
        
        header("Location: dispositivos.php");
        exit();
        
    } catch(Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nuevo Dispositivo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .header h1 {
            color: #2d3436;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #636e72;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2d3436;
            font-weight: 500;
            font-size: 1rem;
        }
        
        .form-group.required label::after {
            content: " *";
            color: #e74c3c;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #dfe6e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .form-control:focus {
            border-color: #6c5ce7;
            background: white;
            outline: none;
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .protocol-section {
            border: 2px solid #dfe6e9;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        
        .protocol-section.active {
            border-color: #6c5ce7;
            background: rgba(108, 92, 231, 0.05);
        }
        
        .protocol-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            cursor: pointer;
        }
        
        .protocol-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
            color: white;
        }
        
        .icon-http {
            background: linear-gradient(135deg, #0984e3, #0770c4);
        }
        
        .icon-mqtt {
            background: linear-gradient(135deg, #6c5ce7, #5b4fcf);
        }
        
        .protocol-info h3 {
            color: #2d3436;
            margin-bottom: 5px;
        }
        
        .protocol-info p {
            color: #636e72;
            font-size: 0.9rem;
        }
        
        .protocol-options {
            display: none;
        }
        
        .protocol-options.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .mqtt-instructions {
            background: rgba(108, 92, 231, 0.1);
            border-left: 4px solid #6c5ce7;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
        }
        
        .mqtt-instructions h4 {
            color: #6c5ce7;
            margin-bottom: 10px;
        }
        
        .mqtt-instructions ul {
            padding-left: 20px;
            color: #2d3436;
        }
        
        .mqtt-instructions li {
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .mqtt-instructions code {
            background: rgba(0,0,0,0.05);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.85rem;
        }
        
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #00b894, #00a085);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 184, 148, 0.3);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .error-message {
            background: #ffeaea;
            color: #e74c3c;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #e74c3c;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #28a745;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #6c5ce7;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .help-text {
            font-size: 0.85rem;
            color: #636e72;
            margin-top: 5px;
            display: block;
        }
        
        /* Mensaje de sesión */
        .session-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #00b894;
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            z-index: 1000;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php if(isset($_SESSION['mensaje_exito'])): ?>
        <div class="session-message">
            <i class="fas fa-check-circle"></i> 
            <?php echo $_SESSION['mensaje_exito']; ?>
            <?php unset($_SESSION['mensaje_exito']); ?>
        </div>
        <script>
            // Ocultar mensaje después de 5 segundos
            setTimeout(() => {
                document.querySelector('.session-message').style.opacity = '0';
                setTimeout(() => {
                    document.querySelector('.session-message').style.display = 'none';
                }, 500);
            }, 5000);
        </script>
    <?php endif; ?>
    
    <div class="container">
        <div class="card">
            <div class="header">
                <h1><i class="fas fa-plus-circle"></i> Nuevo Dispositivo</h1>
                <p>Agrega un nuevo dispositivo IoT a tu sistema</p>
            </div>
            
            <?php if(isset($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="deviceForm">
                <!-- Información básica -->
                <div class="form-row">
                    <div class="form-group required">
                        <label><i class="fas fa-microchip"></i> Nombre del dispositivo</label>
                        <input type="text" name="nombre" class="form-control" 
                               placeholder="Ej: Sensor de Temperatura Sala 1" 
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" 
                               required>
                    </div>
                    
                    <div class="form-group required">
                        <label><i class="fas fa-tag"></i> Tipo de dispositivo</label>
                        <select name="tipo" class="form-control" required>
                            <option value="temperatura" <?php echo ($_POST['tipo'] ?? '') == 'temperatura' ? 'selected' : ''; ?>>🌡️ Sensor de Temperatura</option>
                            <option value="humedad" <?php echo ($_POST['tipo'] ?? '') == 'humedad' ? 'selected' : ''; ?>>💧 Sensor de Humedad</option>
                            <option value="temperatura_humedad" <?php echo ($_POST['tipo'] ?? '') == 'temperatura_humedad' ? 'selected' : ''; ?>>🌡️💧 Sensor de T/H</option>
                            <option value="presion" <?php echo ($_POST['tipo'] ?? '') == 'presion' ? 'selected' : ''; ?>>📊 Sensor de Presión</option>
                            <option value="luminosidad" <?php echo ($_POST['tipo'] ?? '') == 'luminosidad' ? 'selected' : ''; ?>>💡 Sensor de Luminosidad</option>
                            <option value="control" <?php echo ($_POST['tipo'] ?? '') == 'control' ? 'selected' : ''; ?>>🎛️ Dispositivo de Control</option>
                            <option value="otros" <?php echo ($_POST['tipo'] ?? '') == 'otros' ? 'selected' : ''; ?>>📱 Otro tipo</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Ubicación</label>
                        <input type="text" name="ubicacion" class="form-control" 
                               placeholder="Ej: Sala de Servidores, Oficina 101" 
                               value="<?php echo htmlspecialchars($_POST['ubicacion'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Fecha de instalación</label>
                        <input type="date" name="fecha_instalacion" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['fecha_instalacion'] ?? date('Y-m-d')); ?>">
                    </div>
                </div>
                
                <!-- Selección de protocolo -->
                <div class="form-group">
                    <label><i class="fas fa-network-wired"></i> Protocolo de comunicación</label>
                    
                    <div class="protocol-section <?php echo ($_POST['protocolo'] ?? '') == 'MQTT' ? 'active' : ''; ?>" id="mqttSection">
                        <div class="protocol-header" onclick="selectProtocol('mqtt')">
                            <div class="protocol-icon icon-mqtt">
                                <i class="fas fa-satellite-dish"></i>
                            </div>
                            <div class="protocol-info">
                                <h3>MQTT - Comunicación en tiempo real</h3>
                                <p>Ideal para dispositivos IoT como ESP32/ESP8266</p>
                            </div>
                            <input type="radio" name="protocolo" value="MQTT" 
                                   <?php echo ($_POST['protocolo'] ?? '') == 'MQTT' ? 'checked' : ''; ?>
                                   style="margin-left: auto;" onchange="selectProtocol('mqtt')">
                        </div>
                        
                        <div class="protocol-options <?php echo ($_POST['protocolo'] ?? '') == 'MQTT' ? 'active' : ''; ?>" id="mqttOptions">
                            <?php if($hasCodigoColumn): ?>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Código MQTT (Identificador único)</label>
                                <input type="text" name="codigo" id="codigoMQTT" class="form-control" 
                                       placeholder="Ej: ESP32_001, NODE_01" 
                                       value="<?php echo htmlspecialchars($_POST['codigo'] ?? ''); ?>">
                                <span class="help-text">
                                    Este código será usado por el ESP32 para identificarse.
                                    Si lo dejas vacío, se generará automáticamente.
                                    <button type="button" onclick="generateCode()" style="margin-left: 10px; padding: 3px 8px; background: #6c5ce7; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fas fa-sync-alt"></i> Generar código
                                    </button>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mqtt-instructions">
                                <h4><i class="fas fa-info-circle"></i> Cómo configurar tu ESP32:</h4>
                                <ul>
                                    <li>Usa la librería <strong>PubSubClient</strong> en Arduino IDE</li>
                                    <li>Configura el broker MQTT: <code>localhost:1883</code></li>
                                    <li>Publica datos en el topic: <code>esp32/sensor/data</code></li>
                                    <li>Formato JSON requerido:
                                        <pre style="background: rgba(0,0,0,0.05); padding: 10px; border-radius: 5px; margin-top: 5px;">
{
    "dispositivo": "CODIGO_AQUI",
    "temperatura": 25.5,
    "humedad": 60
}</pre>
                                    </li>
                                    <li>Envía datos cada 30-60 segundos para mantener el estado "en línea"</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="protocol-section <?php echo ($_POST['protocolo'] ?? 'HTTP' || !isset($_POST['protocolo'])) ? 'active' : ''; ?>" id="httpSection">
                        <div class="protocol-header" onclick="selectProtocol('http')">
                            <div class="protocol-icon icon-http">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div class="protocol-info">
                                <h3>HTTP - Comunicación por peticiones</h3>
                                <p>Para dispositivos con acceso web o API REST</p>
                            </div>
                            <input type="radio" name="protocolo" value="HTTP" 
                                   <?php echo (!isset($_POST['protocolo']) || ($_POST['protocolo'] ?? '') == 'HTTP') ? 'checked' : ''; ?>
                                   style="margin-left: auto;" onchange="selectProtocol('http')">
                        </div>
                    </div>
                </div>
                
                <!-- Asignación a cliente -->
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Asignar a cliente (opcional)</label>
                    <select name="cliente_id" class="form-control">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach($clientes as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>" 
                                <?php echo ($_POST['cliente_id'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cliente['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="help-text">
                        Solo clientes aparecen en esta lista.
                        Puedes asignar el dispositivo después si lo prefieres.
                    </span>
                </div>
                
                <!-- Descripción -->
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Descripción adicional</label>
                    <textarea name="descripcion" class="form-control" rows="4" 
                              placeholder="Describe las características del dispositivo, su propósito, etc."><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
                </div>
                
                <!-- Botón de envío -->
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Crear Dispositivo
                </button>
                
                <!-- Enlace para volver -->
                <a href="dispositivos.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Volver a la lista de dispositivos
                </a>
            </form>
        </div>
    </div>

    <script>
        function selectProtocol(protocol) {
            // Desactivar todas las secciones
            document.getElementById('mqttSection').classList.remove('active');
            document.getElementById('httpSection').classList.remove('active');
            
            // Ocultar todas las opciones
            document.getElementById('mqttOptions').classList.remove('active');
            
            // Activar la sección seleccionada
            if(protocol === 'mqtt') {
                document.getElementById('mqttSection').classList.add('active');
                document.getElementById('mqttOptions').classList.add('active');
                document.querySelector('input[name="protocolo"][value="MQTT"]').checked = true;
                
                // Generar código automático si está vacío
                generateCode();
            } else {
                document.getElementById('httpSection').classList.add('active');
                document.querySelector('input[name="protocolo"][value="HTTP"]').checked = true;
            }
        }
        
        // Generar código MQTT automático
        function generateCode() {
            const codigoInput = document.getElementById('codigoMQTT');
            if(codigoInput && !codigoInput.value.trim()) {
                // Generar código tipo ESP32_XXXXXX
                const randomChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                let randomCode = '';
                for(let i = 0; i < 6; i++) {
                    randomCode += randomChars.charAt(Math.floor(Math.random() * randomChars.length));
                }
                codigoInput.value = 'ESP32_' + randomCode;
            }
        }
        
        // Inicializar según protocolo seleccionado
        document.addEventListener('DOMContentLoaded', function() {
            const protocol = document.querySelector('input[name="protocolo"]:checked').value;
            if(protocol === 'MQTT') {
                selectProtocol('mqtt');
            } else {
                selectProtocol('http');
            }
        });
        
        // Validar formulario antes de enviar
        document.getElementById('deviceForm').addEventListener('submit', function(event) {
            const protocol = document.querySelector('input[name="protocolo"]:checked').value;
            const nombre = document.querySelector('input[name="nombre"]').value.trim();
            
            if(!nombre) {
                event.preventDefault();
                alert('Por favor ingresa un nombre para el dispositivo');
                document.querySelector('input[name="nombre"]').focus();
                return;
            }
            
            // Para MQTT, validar código si se ingresó manualmente
            if(protocol === 'MQTT') {
                const codigoInput = document.getElementById('codigoMQTT');
                if(codigoInput) {
                    const codigo = codigoInput.value.trim();
                    if(codigo && !/^[a-zA-Z0-9_-]+$/.test(codigo)) {
                        event.preventDefault();
                        alert('El código MQTT solo puede contener letras, números, guiones y guiones bajos');
                        codigoInput.focus();
                        return;
                    }
                }
            }
            
            // Mostrar confirmación para MQTT
            if(protocol === 'MQTT') {
                const codigoInput = document.getElementById('codigoMQTT');
                const codigo = codigoInput ? codigoInput.value.trim() : '';
                
                if(!confirm(`¿Crear dispositivo MQTT?\n\nNombre: ${nombre}\nCódigo: ${codigo || '(se generará automático)'}\n\nIMPORTANTE: Usa este código en tu ESP32 para que pueda enviar datos.`)) {
                    event.preventDefault();
                }
            }
        });
    </script>
</body>
</html>