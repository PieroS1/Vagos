<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../core/mqtt-handler.php';

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['comando'])) {
    try {
        $mqtt = new MqttHandler();
        $mqtt->connect();
        
        $message = json_encode([
            'comando' => $input['comando'],
            'timestamp' => date('Y-m-d H:i:s'),
            'origen' => 'web'
        ]);
        
        $mqtt->publish(TOPIC_CONTROL, $message);
        $mqtt->disconnect();
        
        echo json_encode([
            'success' => true,
            'message' => 'Comando enviado al ESP32'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido o comando no especificado'
    ]);
}