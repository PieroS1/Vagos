<?php
// websocket_server.php - Servidor WebSocket para tiempo real
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class IoTWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $subscriptions; // Suscripciones [device_id => [connection_id => connection]]
    protected $stats;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->subscriptions = [];
        $this->stats = [
            'start_time' => time(),
            'connections' => 0,
            'messages_sent' => 0,
            'devices' => []
        ];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->stats['connections']++;
        
        $clientId = $conn->resourceId;
        echo "✅ [$clientId] Cliente conectado\n";
        echo "   📊 Conexiones activas: {$this->stats['connections']}\n";
        
        // Enviar mensaje de bienvenida
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Conectado al servidor IoT WebSocket',
            'client_id' => $clientId,
            'timestamp' => date('Y-m-d H:i:s')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $clientId = $from->resourceId;
        $data = json_decode($msg, true);
        
        if (!$data) {
            echo "⚠️ [$clientId] Mensaje JSON inválido\n";
            return;
        }
        
        // Cliente se suscribe a un dispositivo
        if ($data['action'] === 'subscribe' && isset($data['device_id'])) {
            $deviceId = $data['device_id'];
            
            if (!isset($this->subscriptions[$deviceId])) {
                $this->subscriptions[$deviceId] = [];
            }
            
            $this->subscriptions[$deviceId][$clientId] = $from;
            
            if (!isset($this->stats['devices'][$deviceId])) {
                $this->stats['devices'][$deviceId] = 0;
            }
            $this->stats['devices'][$deviceId]++;
            
            echo "📡 [$clientId] Suscrito a dispositivo: $deviceId\n";
            echo "   👥 Suscriptores a $deviceId: " . count($this->subscriptions[$deviceId]) . "\n";
            
            // Confirmar suscripción
            $from->send(json_encode([
                'type' => 'subscription_confirmed',
                'device_id' => $deviceId,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
        
        // Cliente pide estadísticas
        elseif ($data['action'] === 'get_stats') {
            $uptime = time() - $this->stats['start_time'];
            $hours = floor($uptime / 3600);
            $minutes = floor(($uptime % 3600) / 60);
            $seconds = $uptime % 60;
            
            $from->send(json_encode([
                'type' => 'server_stats',
                'stats' => [
                    'uptime' => "$hours:$minutes:$seconds",
                    'connections' => $this->stats['connections'],
                    'messages_sent' => $this->stats['messages_sent'],
                    'devices' => $this->stats['devices']
                ]
            ]));
        }
    }

    // Método para recibir datos del suscriptor MQTT
    public function onSensorData($data) {
        if (!isset($data['device_id'])) {
            return;
        }
        
        $deviceId = $data['device_id'];
        
        if (isset($this->subscriptions[$deviceId])) {
            foreach ($this->subscriptions[$deviceId] as $client) {
                try {
                    $client->send(json_encode($data));
                    $this->stats['messages_sent']++;
                } catch (Exception $e) {
                    echo "⚠️ Error enviando mensaje: " . $e->getMessage() . "\n";
                }
            }
            
            echo "📤 Datos enviados a " . count($this->subscriptions[$deviceId]) . " clientes\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $clientId = $conn->resourceId;
        $this->clients->detach($conn);
        $this->stats['connections']--;
        
        // Remover de todas las suscripciones
        foreach ($this->subscriptions as $deviceId => &$clients) {
            if (isset($clients[$clientId])) {
                unset($clients[$clientId]);
                $this->stats['devices'][$deviceId]--;
                echo "❌ [$clientId] Desuscrito de dispositivo: $deviceId\n";
                
                // Si no hay más suscriptores, limpiar
                if (empty($clients)) {
                    unset($this->subscriptions[$deviceId]);
                    echo "   🗑️  Dispositivo $deviceId sin suscriptores\n";
                }
            }
        }
        
        echo "🔌 [$clientId] Cliente desconectado\n";
        echo "   📊 Conexiones activas: {$this->stats['connections']}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $clientId = $conn->resourceId;
        echo "⚠️ [$clientId] Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

// HTTP endpoint para recibir datos del suscriptor MQTT
class HttpServerWithNotify extends \Ratchet\Http\HttpServer {
    private $websocketHandler;
    
    public function __construct(\Ratchet\WebSocket\WsServer $wsServer, IoTWebSocket $handler) {
        parent::__construct($wsServer);
        $this->websocketHandler = $handler;
    }
    
    public function onOpen(\Ratchet\ConnectionInterface $conn, \Psr\Http\Message\RequestInterface $request = null) {
        // Si es una petición POST a /notify, procesar datos
        if ($request && $request->getUri()->getPath() === '/notify') {
            // Leer el cuerpo de la petición POST
            $body = (string)$request->getBody();
            $data = json_decode($body, true);
            
            if ($data && isset($data['device_id'])) {
                $this->websocketHandler->onSensorData($data);
                
                // Responder OK
                $response = new \React\Http\Message\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['status' => 'ok', 'message' => 'Datos recibidos'])
                );
                
                $conn->send($response);
                $conn->close();
                return;
            }
        }
        
        // Si no, proceder con el WebSocket normal
        parent::onOpen($conn, $request);
    }
}

// Iniciar servidor
echo "🚀 Iniciando servidor WebSocket IoT...\n";
echo "📡 Escuchando en:\n";
echo "   - WebSocket: ws://localhost:8080\n";
echo "   - HTTP POST: http://localhost:8080/notify\n";
echo "⏳ Listo para conexiones...\n\n";

$websocketHandler = new IoTWebSocket();

$server = IoServer::factory(
    new HttpServerWithNotify(
        new WsServer($websocketHandler),
        $websocketHandler
    ),
    8080
);

$server->run();