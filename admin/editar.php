<?php
session_start();
require "../config/db.php";

if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    header("Location: ../public/index.php?error=Acceso+denegado");
    exit();
}

// Obtener datos del técnico si se proporciona ID
$tecnico = null;
if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'tecnico'");
    $stmt->execute([$id]);
    $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Si no se encontró el técnico, redirigir
if(!$tecnico) {
    header("Location: tecnicos.php?mensaje=Técnico+no+encontrado&tipo=error");
    exit();
}

// Procesar actualización
if($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $status = $_POST['status'] ?? '';
    
    // Validar datos
    if(empty($username)) {
        $error = "El nombre de usuario es requerido";
    } else {
        // Actualizar técnico
        $stmt = $pdo->prepare("UPDATE users SET username = ?, status = ? WHERE id = ?");
        if($stmt->execute([$username, $status, $id])) {
            header("Location: tecnicos.php?mensaje=Técnico+actualizado+exitosamente&tipo=success");
            exit();
        } else {
            $error = "Error al actualizar el técnico";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Técnico - Sistema IoT</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Estilos similares a los anteriores */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .edit-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        
        .edit-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .edit-header h1 {
            color: var(--dark);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 600;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 14px;
            border-radius: 10px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: var(--dark);
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="edit-container">
        <div class="edit-header">
            <h1>
                <i class="fas fa-user-edit"></i>
                Editar Técnico
            </h1>
            <p>Modifica la información del técnico</p>
        </div>
        
        <?php if(isset($error)): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Nombre de Usuario</label>
                <input type="text" id="username" name="username" class="form-control" 
                       value="<?php echo htmlspecialchars($tecnico['username']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="status">Estado</label>
                <select id="status" name="status" class="form-control" required>
                    <option value="active" <?php echo $tecnico['status'] == 'active' ? 'selected' : ''; ?>>Activo</option>
                    <option value="pending" <?php echo $tecnico['status'] == 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                    <option value="inactive" <?php echo $tecnico['status'] == 'inactive' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
            
            <div class="btn-group">
                <a href="tecnicos.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancelar
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</body>
</html>
