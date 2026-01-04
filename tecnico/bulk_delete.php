<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tecnico') {
    header('Location: /iot-system/public/index.php');
    exit();
}

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispositivos'])) {
    $dispositivos = $_POST['dispositivos'];
    $deleted_count = 0;
    $errors = [];
    
    if (empty($dispositivos)) {
        $_SESSION['error_message'] = "No se seleccionaron dispositivos para eliminar.";
        header('Location: dashboard.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        foreach ($dispositivos as $dispositivo_id) {
            // Registrar acción en log (opcional)
            $stmt = $pdo->prepare("
                INSERT INTO logs_eliminacion (
                    dispositivo_id,
                    usuario_id,
                    fecha_eliminacion,
                    motivo
                ) VALUES (?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $dispositivo_id,
                $_SESSION['user_id'],
                $_POST['delete_reason'] ?? 'Eliminación múltiple'
            ]);
            
            // Eliminar datos del dispositivo
            $stmt = $pdo->prepare("DELETE FROM mqtt_data WHERE dispositivo_id = ?");
            $stmt->execute([$dispositivo_id]);
            
            $deleted_count++;
        }
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Se eliminaron {$deleted_count} dispositivos exitosamente.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error al eliminar dispositivos: " . $e->getMessage();
    }
    
    header('Location: dashboard.php');
    exit();
}
?>