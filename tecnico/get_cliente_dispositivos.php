<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    exit('Acceso denegado');
}

require "../config/db.php";

$cliente_id = intval($_GET['cliente_id'] ?? 0);

if($cliente_id <= 0){
    echo "<p>Cliente no válido</p>";
    exit;
}

try {
    // Obtener información del cliente
    $stmt = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();
    
    if(!$cliente){
        echo "<p>Cliente no encontrado</p>";
        exit;
    }
    
    // Obtener dispositivos del cliente
    $stmt = $pdo->prepare("
        SELECT DISTINCT dispositivo_id, 
               COUNT(*) as total_lecturas,
               MAX(timestamp) as ultima_lectura,
               AVG(CASE WHEN sensor = 'temperatura' THEN valor END) as temp_promedio,
               AVG(CASE WHEN sensor = 'humedad' THEN valor END) as hum_promedio
        FROM mqtt_data 
        WHERE cliente_id = ?
        GROUP BY dispositivo_id
        ORDER BY dispositivo_id
    ");
    $stmt->execute([$cliente_id]);
    $dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<h4>Cliente: ' . htmlspecialchars($cliente['full_name'] ?: $cliente['username']) . '</h4>';
    echo '<p>Total dispositivos: ' . count($dispositivos) . '</p>';
    
    if(empty($dispositivos)){
        echo '<div class="alert alert-info">Este cliente no tiene dispositivos asignados.</div>';
    } else {
        echo '<table class="table" style="width:100%;">';
        echo '<thead><tr><th>Dispositivo</th><th>Lecturas</th><th>Temp. Prom</th><th>Hum. Prom</th><th>Última Lectura</th><th>Acciones</th></tr></thead>';
        echo '<tbody>';
        
        foreach($dispositivos as $dispositivo){
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($dispositivo['dispositivo_id']) . '</strong></td>';
            echo '<td>' . $dispositivo['total_lecturas'] . '</td>';
            echo '<td>' . ($dispositivo['temp_promedio'] ? number_format($dispositivo['temp_promedio'], 1) . '°C' : '--') . '</td>';
            echo '<td>' . ($dispositivo['hum_promedio'] ? number_format($dispositivo['hum_promedio'], 1) . '%' : '--') . '</td>';
            echo '<td>' . ($dispositivo['ultima_lectura'] ? date('d/m/Y H:i', strtotime($dispositivo['ultima_lectura'])) : '--') . '</td>';
            echo '<td>';
            echo '<form method="POST" action="gestionar_clientes.php" style="display:inline;">';
            echo '<input type="hidden" name="remover_dispositivo" value="' . $dispositivo['dispositivo_id'] . '">';
            echo '<input type="hidden" name="cliente_id" value="' . $cliente_id . '">';
            echo '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'¿Remover este dispositivo del cliente?\')">';
            echo '<i class="fas fa-unlink"></i> Remover';
            echo '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
} catch(Exception $e) {
    echo '<p class="text-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>