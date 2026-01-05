<?php
session_start();
if(!isset($_SESSION["role"]) || $_SESSION["role"] != "admin"){
    exit("Acceso denegado");
}

require "../config/db.php";

$cliente_id = intval($_GET['cliente_id'] ?? 0);

if($cliente_id <= 0) {
    exit("ID de cliente inválido");
}

try {
    // Obtener información del cliente
    $stmt = $pdo->prepare("SELECT full_name, username, email FROM users WHERE id = ? AND role = 'cliente'");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();
    
    if(!$cliente) {
        exit("Cliente no encontrado");
    }
    
    // Obtener dispositivos del cliente
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.nombre,
            d.codigo,
            d.tipo,
            d.protocolo,
            d.ubicacion,
            d.estado,
            d.fecha_instalacion,
            t.full_name as tecnico_nombre,
            t.username as tecnico_username
        FROM dispositivos d
        LEFT JOIN users t ON d.tecnico_id = t.id
        WHERE d.cliente_id = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$cliente_id]);
    $dispositivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ?>
    <style>
        .badge-tecnico {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .no-tecnico-badge {
            background: #f1f5f9;
            color: #64748b;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
    
    <div class="mb-4">
        <h5>Cliente: <?= htmlspecialchars($cliente['full_name'] ?: $cliente['username']) ?></h5>
        <p class="text-muted mb-0">Email: <?= htmlspecialchars($cliente['email']) ?></p>
    </div>
    
    <?php if(empty($dispositivos)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        Este cliente no tiene dispositivos asignados.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Código</th>
                    <th>Tipo</th>
                    <th>Protocolo</th>
                    <th>Técnico</th>
                    <th>Estado</th>
                    <th>Ubicación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($dispositivos as $dispositivo): ?>
                <tr>
                    <td><?= htmlspecialchars($dispositivo['nombre']) ?></td>
                    <td><code><?= htmlspecialchars($dispositivo['codigo']) ?></code></td>
                    <td><?= htmlspecialchars($dispositivo['tipo']) ?></td>
                    <td>
                        <span class="badge bg-info"><?= $dispositivo['protocolo'] ?></span>
                    </td>
                    <td>
                        <?php if($dispositivo['tecnico_nombre']): ?>
                        <span class="badge-tecnico">
                            <i class="bi bi-tools"></i>
                            <?= htmlspecialchars($dispositivo['tecnico_nombre']) ?>
                        </span>
                        <?php else: ?>
                        <span class="no-tecnico-badge">Sin técnico</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $dispositivo['estado'] == 'activo' ? 'success' : 'warning' ?>">
                            <?= $dispositivo['estado'] ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($dispositivo['ubicacion']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="alert alert-info mt-3">
        <i class="bi bi-info-circle me-2"></i>
        Total de dispositivos: <?= count($dispositivos) ?>
    </div>
    <?php endif;
    
} catch(Exception $e) {
    echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>