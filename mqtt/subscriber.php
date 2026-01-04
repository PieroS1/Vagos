#!/usr/bin/php
<?php
/**
 * Suscriptor MQTT con WebSocket para IoT System
 * Recibe datos MQTT y los envía a WebSocket para actualización en tiempo real
 */

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

// La conexión $pdo debe existir después de db.php
if (!isset($pdo)) {
    die("❌ Error: No hay conexión a la base de datos\n");
}

require_once __DIR__ . '/../core/mqtt-handler.php';

// ============================================
// CONFIGURACIÓN WEBSOCKET
// ============================================
define('WS_SERVER_URL', 'http://localhost:8080/notify');
define('WS_ENABLED', true); // Cambiar a false para desactivar WebSocket

// ============================================
// FUNCIONES AUXILIARES
// ============================================
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}

function notifyWebSocket($deviceId, $sensor, $value, $timestamp = null) {
    if (!WS_ENABLED) {
        return false;
    }
    
    $data = [
        'type' => 'sensor_data',
        'device_id' => $deviceId,
        'sensor' => $sensor,
        'value' => floatval($value),
        'timestamp' => $timestamp ?? date('Y-m-d H:i:s')
    ];
    
    try {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'timeout' => 1, // Timeout corto, no es crítico
            'ignore_errors' => true // No fallar si WebSocket está caído
        ]]);
        
        $response = @file_get_contents(WS_SERVER_URL, false, $context);
        
        if ($response === false) {
            logMessage("⚠️ WebSocket no disponible (ignorando)");
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        // No mostrar error, solo continuar
        return false;
    }
}

function processSensorData($pdo, $topic, $message) {
    logMessage("📨 Mensaje recibido en topic: $topic");
    
    $data = json_decode($message, true);
    
    if (!$data || empty($data['dispositivo'])) {
        logMessage("⚠️ JSON inválido o sin dispositivo");
        return false;
    }
    
    $codigo = trim($data['dispositivo']);
    logMessage("🔧 Procesando dispositivo: $codigo");
    
    // 1️⃣ Obtener o crear dispositivo en la base de datos
    $stmt = $pdo->prepare("SELECT id FROM dispositivos WHERE codigo = ?");
    $stmt->execute([$codigo]);
    
    $dispositivoId = null;
    
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dispositivoId = (int)$row['id'];
        logMessage("✅ Dispositivo encontrado, ID: $dispositivoId");
    } else {
        // Crear nuevo dispositivo
        $stmt = $pdo->prepare("
            INSERT INTO dispositivos (
                codigo, nombre, tipo, ubicacion,
                descripcion, protocolo, estado
            ) VALUES (?, ?, ?, ?, ?, 'MQTT', 'activo')
        ");
        
        $nombre = $data['nombre'] ?? "ESP32_$codigo";
        $tipo = $data['tipo'] ?? 'esp32';
        $ubicacion = $data['ubicacion'] ?? 'Sin ubicación';
        $descripcion = $data['descripcion'] ?? 'Dispositivo IoT MQTT';
        
        $stmt->execute([$codigo, $nombre, $tipo, $ubicacion, $descripcion]);
        $dispositivoId = (int)$pdo->lastInsertId();
        logMessage("🎯 Nuevo dispositivo creado, ID: $dispositivoId");
    }
    
    // 2️⃣ Actualizar última conexión
    $stmt = $pdo->prepare("
        UPDATE dispositivos 
        SET ultima_conexion_mqtt = NOW(), estado = 'activo'
        WHERE id = ?
    ");
    $stmt->execute([$dispositivoId]);
    
    // 3️⃣ Guardar datos de sensores
    $sensores = ['temperatura', 'humedad', 'voltaje', 'presion', 'luminosidad', 'co2'];
    $savedCount = 0;
    $wsNotifications = 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO mqtt_data (
            dispositivo_id,
            dispositivo_real_id,
            sensor,
            valor,
            topic,
            timestamp
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $timestamp = date('Y-m-d H:i:s');
    
    foreach ($sensores as $sensor) {
        if (isset($data[$sensor]) && is_numeric($data[$sensor])) {
            $valor = floatval($data[$sensor]);
            
            // Guardar en base de datos
            $stmt->execute([$codigo, $dispositivoId, $sensor, $valor, $topic]);
            $savedCount++;
            
            // Enviar a WebSocket
            if (notifyWebSocket($codigo, $sensor, $valor, $timestamp)) {
                $wsNotifications++;
            }
            
            logMessage("   📊 $sensor: $valor");
        }
    }
    
    // 4️⃣ Mostrar resumen
    logMessage("✅ $savedCount sensores guardados");
    if (WS_ENABLED && $wsNotifications > 0) {
        logMessage("   📡 $wsNotifications notificaciones enviadas a WebSocket");
    }
    
    return true;
}

// ============================================
// MÉTODO PRINCIPAL
// ============================================
function main() {
    global $pdo;
    
    logMessage("🚀 Iniciando suscriptor MQTT con WebSocket...");
    
    if (WS_ENABLED) {
        logMessage("📡 WebSocket habilitado (URL: " . WS_SERVER_URL . ")");
    } else {
        logMessage("⚙️  WebSocket deshabilitado");
    }
    
    try {
        // Crear handler MQTT
        $mqttHandler = new MqttHandler($pdo);
        
        // Conectar al broker MQTT
        logMessage("🔌 Conectando al broker MQTT...");
        
        if ($mqttHandler->connect()) {
            logMessage("✅ Conectado al broker MQTT");
            
            // Definir callback para procesar mensajes
            $callback = function($topic, $message) use ($pdo) {
                processSensorData($pdo, $topic, $message);
            };
            
            // Suscribirse al topic principal
            $mqttHandler->subscribe('esp32/sensor/data', $callback);
            logMessage("📡 Suscrito a: esp32/sensor/data");
            
            // También suscribirse a topic con wildcard para debugging
            $mqttHandler->subscribe('esp32/+/data', $callback);
            logMessage("📡 Suscrito a: esp32/+/data (wildcard)");
            
            logMessage("========================================");
            logMessage("🎯 SISTEMA LISTO - ESCUCHANDO MENSAJES");
            logMessage("========================================");
            logMessage("📊 Los datos se guardarán en BD y enviarán a WebSocket");
            logMessage("🛑 Presiona Ctrl+C para detener");
            logMessage("========================================");
            
            // Loop principal
            while (true) {
                try {
                    $mqttHandler->loop(false, 1); // Timeout de 1 segundo
                } catch (Exception $e) {
                    logMessage("⚠️ Error en loop MQTT: " . $e->getMessage());
                    sleep(1); // Esperar antes de reintentar
                }
                
                // Pequeña pausa para evitar uso excesivo de CPU
                usleep(100000); // 100ms
            }
            
        } else {
            logMessage("❌ No se pudo conectar al broker MQTT");
            logMessage("💡 Verifica que:");
            logMessage("   1. Mosquitto esté instalado: sudo apt install mosquitto");
            logMessage("   2. Mosquitto esté corriendo: sudo systemctl status mosquitto");
            logMessage("   3. El servicio esté activo: sudo systemctl start mosquitto");
            exit(1);
        }
        
    } catch (Exception $e) {
        logMessage("💥 ERROR CRÍTICO: " . $e->getMessage());
        logMessage("📝 Stack trace: " . $e->getTraceAsString());
        exit(1);
    }
}

// ============================================
// MANEJADOR DE SEÑALES (Ctrl+C)
// ============================================
declare(ticks = 1);
$running = true;

function signalHandler($signo) {
    global $running;
    
    switch ($signo) {
        case SIGINT:
        case SIGTERM:
            logMessage("⏹️  Señal de terminación recibida, deteniendo...");
            $running = false;
            break;
    }
}

// Registrar manejadores
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
}

// ============================================
// EJECUTAR
// ============================================
main();