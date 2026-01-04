<?php
// Activar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// Verificar sesión
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "tecnico"){
    header("Location: ../public/index.php");
    exit();
}

require "../config/db.php";

// Variables
$mensaje = '';
$tipo_mensaje = '';

// ========== CREAR CLIENTE ==========
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["crear_cliente"])){
    try {
        $username = trim($_POST["username"]);
        $email = trim($_POST["email"]);
        $password = $_POST["password"];
        
        // Validaciones básicas
        if(empty($username) || empty($email) || empty($password)){
            throw new Exception("Todos los campos son obligatorios");
        }
        
        if(strlen($password) < 6){
            throw new Exception("La contraseña debe tener al menos 6 caracteres");
        }
        
        // Verificar si ya existe
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if($stmt->rowCount() > 0){
            throw new Exception("El usuario o email ya existe");
        }
        
        // Crear hash
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insertar
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'cliente', 'active')");
        $stmt->execute([$username, $email, $password_hash]);
        
        $mensaje = "✅ Cliente creado exitosamente";
        $tipo_mensaje = "success";
        
    } catch(Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}
// ========== ELIMINAR CLIENTE ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["eliminar_cliente"])) {
    try {
        $cliente_id = intval($_POST["eliminar_cliente"]);

        // Verificar que existe
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$cliente_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("El cliente no existe");
        }

        if ($user["role"] !== "cliente") {
            throw new Exception("Solo se pueden eliminar clientes");
        }

        // Eliminar dispositivos asociados
        $stmt = $pdo->prepare("DELETE FROM dispositivos WHERE cliente_id = ?");
        $stmt->execute([$cliente_id]);

        // Eliminar el cliente
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$cliente_id]);

        $mensaje = "✅ Cliente eliminado correctamente";
        $tipo_mensaje = "success";

    } catch (Exception $e) {
        $mensaje = "❌ Error eliminando cliente: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// ========== OBTENER CLIENTES ==========
try {
    // Consulta básica primero
    $clientes = $pdo->query("SELECT id, username, email, status, created_at FROM users WHERE role = 'cliente' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Ahora agregar estadísticas de dispositivos
    foreach($clientes as &$cliente) {
        // Contar dispositivos
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM dispositivos WHERE cliente_id = ?");
        $stmt->execute([$cliente['id']]);
        $result = $stmt->fetch();
        $cliente['total_dispositivos'] = $result['total'];
        
        // Contar dispositivos activos
        $stmt = $pdo->prepare("SELECT COUNT(*) as activos FROM dispositivos WHERE cliente_id = ? AND estado = 'activo'");
        $stmt->execute([$cliente['id']]);
        $result = $stmt->fetch();
        $cliente['dispositivos_activos'] = $result['activos'];
    }
    
} catch(Exception $e) {
    $mensaje = "Error obteniendo clientes: " . $e->getMessage();
    $tipo_mensaje = "error";
    $clientes = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes - Sistema IoT</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f5f7fa;
            color: #333;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #7f8c8d;
        }
        
        /* Mensajes */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #7f8c8d;
        }
        
        /* Formulario */
        .form-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .form-section h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-control:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        /* Tabla */
        .table-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            padding: 15px;
            text-align: left;
            background: #f8f9fa;
            color: #2c3e50;
            border-bottom: 2px solid #ecf0f1;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-count {
            background: #3498db;
            color: white;
            margin-right: 5px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            th, td {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
        
        /* Navigation */
        .nav {
            margin-bottom: 20px;
        }
        
        .nav a {
            color: #3498db;
            text-decoration: none;
            margin-right: 15px;
            font-weight: 500;
        }
        
        .nav a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <div class="nav">
            <a href="dispositivos.php"><i class="fas fa-arrow-left"></i> Volver a Dispositivos</a>
        </div>
        
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-users"></i> Gestión de Clientes</h1>
            <p>Administra los clientes del sistema IoT</p>
        </div>
        
        <!-- Mensajes -->
        <?php if($mensaje): ?>
        <div class="message <?php echo $tipo_mensaje; ?>">
            <i class="fas fa-<?php echo $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $mensaje; ?>
        </div>
        <?php endif; ?>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($clientes); ?></div>
                <div class="stat-label">Total Clientes</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $activos = array_filter($clientes, function($c) { 
                        return $c['status'] == 'active'; 
                    });
                    echo count($activos);
                    ?>
                </div>
                <div class="stat-label">Clientes Activos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $total_dispositivos = array_sum(array_column($clientes, 'total_dispositivos'));
                    echo $total_dispositivos;
                    ?>
                </div>
                <div class="stat-label">Dispositivos Asignados</div>
            </div>
        </div>
        
        <!-- Formulario -->
        <div class="form-section">
            <h2><i class="fas fa-user-plus"></i> Crear Nuevo Cliente</h2>
            <form method="POST" id="clienteForm">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Nombre de Usuario *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Contraseña *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        Mínimo 6 caracteres
                    </small>
                </div>
                
                <button type="submit" name="crear_cliente" class="btn btn-primary">
                    <i class="fas fa-save"></i> Crear Cliente
                </button>
            </form>
            
        </div>
        
        <!-- Lista de Clientes -->
        <div class="table-container">
            <h2><i class="fas fa-list"></i> Lista de Clientes</h2>
            
            <?php if(empty($clientes)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 40px;">
                    <i class="fas fa-users-slash" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                    No hay clientes registrados
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Estado</th>
                            <th>Dispositivos</th>
                            <th>Fecha Registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($clientes as $cliente): ?>
                        <tr>
                            <td><strong>#<?php echo $cliente['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($cliente['username']); ?></td>
                            <td><?php echo htmlspecialchars($cliente['email']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $cliente['status'] == 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo $cliente['status'] == 'active' ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-count"><?php echo $cliente['total_dispositivos']; ?></span>
                                <?php if($cliente['dispositivos_activos'] > 0): ?>
                                <span class="badge badge-active"><?php echo $cliente['dispositivos_activos']; ?> activos</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($cliente['created_at'])); ?></td>\
                            <td>
                                <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar este cliente?');">
                                    <input type="hidden" name="eliminar_cliente" value="<?php echo $cliente['id']; ?>">
                                    <button class="btn btn-danger" style="background:#e74c3c;color:white;border:none;padding:8px 12px;border-radius:6px;cursor:pointer;">
                                        <i class="fas fa-trash"></i> Eliminar
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Validación simple del formulario
        document.getElementById('clienteForm').addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]').value;
            
            if (password.length < 6) {
                e.preventDefault();
                alert('La contraseña debe tener al menos 6 caracteres');
                document.querySelector('input[name="password"]').focus();
            }
        });
    </script>
</body>
</html>