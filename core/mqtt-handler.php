<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttHandler
{
    private MqttClient $mqtt;
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadConfig();

        $this->mqtt = new MqttClient(
            $this->config['broker'],
            $this->config['port'],
            $this->config['client_id'] . '_' . uniqid()
        );
    }

    /* =========================
       CONFIGURACIÓN
    ========================== */

    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../config/mqtt.php';

        $this->config = file_exists($configFile)
            ? require $configFile
            : [
                'broker' => 'localhost',
                'port' => 1883,
                'client_id' => 'iot-system',
                'username' => null,
                'password' => null,
                'topics' => [
                    'sensor_data' => 'esp32/sensor/data'
                ]
            ];
    }

    /* =========================
       MQTT
    ========================== */

    public function connect(): bool
    {
        try {
            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(5)
                ->setUseTls(false);

            if (!empty($this->config['username'])) {
                $settings->setUsername($this->config['username']);
            }

            if (!empty($this->config['password'])) {
                $settings->setPassword($this->config['password']);
            }

            $this->mqtt->connect($settings, true);
            echo "✅ Conectado a MQTT\n";
            return true;

        } catch (Exception $e) {
            echo "❌ Error MQTT: {$e->getMessage()}\n";
            return false;
        }
    }

    public function subscribe(string $topic): void
    {
        $this->mqtt->subscribe($topic, function ($topic, $message) {
            $this->saveSensorData($topic, $message);
        }, 0);

        echo "📡 Suscrito a $topic\n";
    }

    public function loop(): void
    {
        $this->mqtt->loop(true);
    }

    public function disconnect(): void
    {
        $this->mqtt->disconnect();
    }

    /* =========================
       LÓGICA PRINCIPAL
    ========================== */

    public function saveSensorData(string $topic, string $message): bool
    {
        $data = json_decode($message, true);

        if (!$data || empty($data['dispositivo'])) {
            echo "⚠️ JSON inválido\n";
            return false;
        }

        $codigo = trim($data['dispositivo']);

        // 1️⃣ Obtener o crear dispositivo
        $dispositivoId = $this->getOrCreateDevice($codigo, $data);

        // 2️⃣ Guardar sensores
        $this->guardarSensores($codigo, $dispositivoId, $topic, $data);

        // 3️⃣ Actualizar conexión
        $this->actualizarUltimaConexion($dispositivoId);

        echo "✅ Datos guardados para $codigo\n";
        return true;
    }

    /* =========================
       DISPOSITIVOS
    ========================== */

    private function getOrCreateDevice(string $codigo, array $data): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM dispositivos WHERE codigo = ?"
        );
        $stmt->execute([$codigo]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return (int)$row['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO dispositivos (
                codigo, nombre, tipo, ubicacion,
                descripcion, protocolo, estado
            ) VALUES (?, ?, ?, ?, ?, 'MQTT', 'activo')
        ");

        $stmt->execute([
            $codigo,
            $data['nombre'] ?? "ESP32_$codigo",
            $data['tipo'] ?? 'esp32',
            $data['ubicacion'] ?? 'Sin ubicación',
            $data['descripcion'] ?? 'Dispositivo IoT MQTT'
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function actualizarUltimaConexion(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE dispositivos
            SET ultima_conexion_mqtt = NOW(),
                estado = 'activo'
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /* =========================
       SENSORES
    ========================== */

    private function guardarSensores(
        string $codigo,
        int $realId,
        string $topic,
        array $data
    ): void {
        $sensores = [
            'temperatura', 'humedad', 'voltaje',
            'presion', 'luminosidad', 'co2'
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO mqtt_data (
                dispositivo_id,
                dispositivo_real_id,
                sensor,
                valor,
                topic,
                timestamp
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($sensores as $sensor) {
            if (isset($data[$sensor]) && is_numeric($data[$sensor])) {
                $stmt->execute([
                    $codigo,   // 🔑 CÓDIGO ALEATORIO
                    $realId,   // 🔢 ID INTERNO
                    $sensor,
                    $data[$sensor],
                    $topic
                ]);
            }
        }
    }
}
