<?php
session_start();
require "../config/db.php";

// Validar sesión
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico") {
    header("Location: ../public/index.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("ID no proporcionado.");
}

$tecnico_id = $_SESSION["user_id"];
$dispositivo_id = $_GET['id'];

// Verificar que el dispositivo pertenece al técnico
$stmt = $pdo->prepare("SELECT * FROM dispositivos WHERE id = ? AND tecnico_id = ?");
$stmt->execute([$dispositivo_id, $tecnico_id]);
$disp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$disp) {
    die("No tienes permiso para eliminar este dispositivo.");
}

// Primero eliminar datos MQTT relacionados (si existen)
$stm1 = $pdo->prepare("DELETE FROM mqtt_data WHERE dispositivo_id = ?");
$stm1->execute([$disp['codigo']]);

// Luego eliminar el dispositivo
$stm2 = $pdo->prepare("DELETE FROM dispositivos WHERE id = ?");
$stm2->execute([$dispositivo_id]);

// Volver al dashboard
header("Location: dispositivos.php?eliminado=1");
exit();
