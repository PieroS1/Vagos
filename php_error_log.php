<?php
// Activar reporte de errores máximo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Mostrar un mensaje de prueba
echo "PHP Error Reporting está activado.<br>";

// Probar una función que podría fallar
$test = 1/0; // Esto generará un error
?>