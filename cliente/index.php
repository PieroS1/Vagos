<?php
session_start();

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "cliente"){
    die("Acceso denegado");
}

echo "Bienvenido Cliente: " . $_SESSION["user"];
?>

<br><br>
Aquí irán los dashboards con sensores ESP32 👌

<a href="../logout.php">Salir</a>
