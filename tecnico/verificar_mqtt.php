<?php
// verificar_simple.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Verificación Simplificada</h2>";

// 1. Conectar a BD
$host = "localhost";
$db   = "iotdb";
$user = "piero";
$pass = "piero123";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ BD conectada<br><br>";
    
    // 2. Verificar dispositivos MQTT
    echo "<h3>Dispositivos MQTT:</h3>";
    $stmt = $pdo->query("SELECT id, codigo, nombre FROM dispositivos WHERE protocolo = 'MQTT'");
    $dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if(empty($dispositivos)) {
        echo "No hay dispositivos MQTT<br>";
    } else {
        foreach($dispositivos as $d) {
            echo "<strong>{$d['nombre']}</strong> (Código: {$d['codigo']})<br>";
            
            // Verificar datos
            $stmt2 = $pdo->prepare("SELECT COUNT(*) as total FROM mqtt_data WHERE dispositivo_id = ?");
            $stmt2->execute([$d['codigo']]);
            $datos = $stmt2->fetch();
            
            if($datos['total'] > 0) {
                // Último dato
                $stmt3 = $pdo->prepare("SELECT MAX(timestamp) as ultimo FROM mqtt_data WHERE dispositivo_id = ?");
                $stmt3->execute([$d['codigo']]);
                $ultimo = $stmt3->fetch();
                
                echo " - Datos: {$datos['total']} | Último: {$ultimo['ultimo']}<br>";
            } else {
                echo " - ❌ Sin datos<br>";
            }
        }
    }
    
    echo "<br><h3>Últimos 10 datos MQTT:</h3>";
    $stmt4 = $pdo->query("SELECT dispositivo_id, sensor, valor, timestamp FROM mqtt_data ORDER BY timestamp DESC LIMIT 10");
    $ultimos = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    
    if(empty($ultimos)) {
        echo "No hay datos MQTT<br>";
    } else {
        echo "<table border='1'><tr><th>Dispositivo</th><th>Sensor</th><th>Valor</th><th>Fecha</th></tr>";
        foreach($ultimos as $u) {
            echo "<tr><td>{$u['dispositivo_id']}</td><td>{$u['sensor']}</td><td>{$u['valor']}</td><td>{$u['timestamp']}</td></tr>";
        }
        echo "</table>";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>