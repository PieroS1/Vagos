<?php
require "../config/db.php";

if (!isset($_GET['id'])) {
    echo "offline";
    exit();
}

$dispositivo_id = $_GET['id'];

// Buscar la última lectura desde mqtt_data
$stmt = $pdo->prepare("
    SELECT MAX(timestamp) AS ultima
    FROM mqtt_data
    WHERE dispositivo_id = ?
");
$stmt->execute([$dispositivo_id]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !$row['ultima']) {
    echo "offline";
    exit();
}

$diff = time() - strtotime($row['ultima']);

// Si hace menos de 60 segundos → ONLINE
if ($diff <= 60) {
    echo "online";
} else {
    echo "offline";
}
