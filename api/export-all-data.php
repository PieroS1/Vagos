<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tecnico') {
    http_response_code(403);
    exit('Acceso denegado');
}

require_once __DIR__ . '/../config/db.php';

// Crear archivo ZIP temporal
$zip = new ZipArchive();
$zip_filename = tempnam(sys_get_temp_dir(), 'iot_export_') . '.zip';

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    exit("No se pudo crear el archivo ZIP");
}

// Obtener todos los dispositivos
$stmt = $pdo->query("SELECT DISTINCT dispositivo_id FROM mqtt_data ORDER BY dispositivo_id");
$dispositivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($dispositivos as $dispositivo) {
    // Crear CSV para cada dispositivo
    $csv_content = "Dispositivo: {$dispositivo}\n";
    $csv_content .= "Fecha Exportación: " . date('Y-m-d H:i:s') . "\n";
    $csv_content .= "Sensor,Valor,Timestamp,Topic\n";
    
    $stmt = $pdo->prepare("
        SELECT sensor, valor, timestamp, topic 
        FROM mqtt_data 
        WHERE dispositivo_id = ? 
        ORDER BY timestamp DESC
    ");
    $stmt->execute([$dispositivo]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $csv_content .= implode(',', [
            $row['sensor'],
            $row['valor'],
            $row['timestamp'],
            $row['topic']
        ]) . "\n";
    }
    
    $zip->addFromString("dispositivo_{$dispositivo}.csv", $csv_content);
}

// Agregar archivo de resumen
$summary = "RESUMEN DE EXPORTACIÓN\n";
$summary .= "====================\n";
$summary .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
$summary .= "Usuario: " . $_SESSION['username'] . "\n";
$summary .= "Total dispositivos: " . count($dispositivos) . "\n\n";

foreach ($dispositivos as $dispositivo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mqtt_data WHERE dispositivo_id = ?");
    $stmt->execute([$dispositivo]);
    $count = $stmt->fetchColumn();
    
    $summary .= "{$dispositivo}: {$count} registros\n";
}

$zip->addFromString("resumen.txt", $summary);

$zip->close();

// Enviar el archivo ZIP
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="export_iot_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zip_filename));
readfile($zip_filename);

// Eliminar archivo temporal
unlink($zip_filename);
?>