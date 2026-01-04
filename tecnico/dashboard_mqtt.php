<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tecnico') {
    header('Location: /iot-system/public/index.php');
    exit();
}
require_once __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard MQTT - Sistema IoT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .sensor-card { transition: transform 0.3s; }
        .sensor-card:hover { transform: translateY(-5px); }
        .badge-online { background-color: #28a745; }
        .badge-offline { background-color: #dc3545; }
    </style>
</head>
<body>

<?php include '../core/header.php'; ?>

<div class="container-fluid mt-4">

    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4">📊 Dashboard MQTT - Monitoreo en Tiempo Real</h2>
        </div>
    </div>

    <!-- 📌 Resumen de tarjetas -->
    <div class="row mb-4">
        
        <div class="col-md-3">
            <div class="card bg-primary text-white sensor-card">
                <div class="card-body">
                    <h5>Dispositivos Activos</h5>
                    <?php
                    $stmt = $pdo->query("SELECT COUNT(DISTINCT dispositivo_id) as total FROM mqtt_data WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                    $result = $stmt->fetch();
                    echo "<h2 class='display-4'>" . $result['total'] . "</h2>";
                    ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card bg-success text-white sensor-card">
                <div class="card-body">
                    <h5>Última Temperatura</h5>
                    <?php
                    $stmt = $pdo->query("SELECT valor FROM mqtt_data WHERE sensor='temperatura' ORDER BY timestamp DESC LIMIT 1");
                    $result = $stmt->fetch();
                    echo "<h2 class='display-4'>" . ($result ? $result['valor']."°C" : "--") . "</h2>";
                    ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card bg-info text-white sensor-card">
                <div class="card-body">
                    <h5>Última Humedad</h5>
                    <?php
                    $stmt = $pdo->query("SELECT valor FROM mqtt_data WHERE sensor='humedad' ORDER BY timestamp DESC LIMIT 1");
                    $result = $stmt->fetch();
                    echo "<h2 class='display-4'>" . ($result ? $result['valor']."%" : "--") . "</h2>";
                    ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card bg-warning text-white sensor-card">
                <div class="card-body">
                    <h5>Total de Datos</h5>
                    <?php
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM mqtt_data");
                    $result = $stmt->fetch();
                    echo "<h2 class='display-4'>" . $result['total'] . "</h2>";
                    ?>
                </div>
            </div>
        </div>

    </div>

    <div class="row">

        <!-- 🔥 GRÁFICA DE TEMPERATURA -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5>🌡️ Historial de Temperatura (24h)</h5>
                </div>
                <div class="card-body">
                    <canvas id="tempChart" height="100"></canvas>
                </div>
            </div>

            <!-- 💧 GRÁFICA DE HUMEDAD -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5>💧 Historial de Humedad (24h)</h5>
                </div>
                <div class="card-body">
                    <canvas id="humChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- 🎛️ PANEL DE CONTROL -->
        <div class="col-md-4">
            <div class="card">

                <div class="card-header">
                    <h5>🎛️ Control de Dispositivos</h5>
                </div>

                <div class="card-body">

                    <form id="controlForm">

                        <div class="mb-3">
                            <label class="form-label">Dispositivo:</label>
                            <select class="form-control" id="deviceSelect">
                                <?php
                                $stmt = $pdo->query("SELECT DISTINCT dispositivo_id FROM mqtt_data ORDER BY dispositivo_id");
                                while ($row = $stmt->fetch()) {
                                    echo "<option value='".htmlspecialchars($row['dispositivo_id'])."'>".$row['dispositivo_id']."</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Comando:</label>
                            <select class="form-control" id="commandSelect">
                                <option value="led_on">Encender LED</option>
                                <option value="led_off">Apagar LED</option>
                                <option value="read_sensors">Leer sensores</option>
                                <option value="reboot">Reiniciar</option>
                                <option value="calibrate">Calibrar</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Parámetro (opcional):</label>
                            <input type="text" class="form-control" id="parameter" placeholder="Ej: 180">
                        </div>

                        <button type="button" class="btn btn-primary w-100" onclick="sendCommand()">
                            📨 Enviar Comando
                        </button>

                    </form>

                    <div class="mt-3">
                        <div class="alert alert-info" id="commandStatus">Listo para enviar comandos</div>
                    </div>

                </div>

            </div>
        </div>

    </div>

    <!-- 📋 TABLA DE ÚLTIMOS DATOS -->
    <div class="row mt-4">
        <div class="col-md-12">

            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5>📋 Últimas Lecturas de Sensores</h5>
                    <button class="btn btn-sm btn-secondary" onclick="refreshData()">🔄 Actualizar</button>
                </div>

                <div class="card-body">
                    <div class="table-responsive">

                        <table class="table table-striped" id="sensorTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Dispositivo</th>
                                    <th>Sensor</th>
                                    <th>Valor</th>
                                    <th>Fecha/Hora</th>
                                    <th>Topic</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $stmt = $pdo->query("SELECT * FROM mqtt_data ORDER BY timestamp DESC LIMIT 20");
                                while ($row = $stmt->fetch()):
                                    $badgeClass = $row['sensor'] == 'temperatura' ? 'bg-danger' : 'bg-info';
                                    $unit = $row['sensor'] == 'temperatura' ? '°C' : '%';
                                ?>
                                <tr>
                                    <td><?= $row['id']; ?></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($row['dispositivo_id']); ?></span></td>
                                    <td><?= htmlspecialchars($row['sensor']); ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $row['valor'].$unit; ?></span></td>
                                    <td><?= $row['timestamp']; ?></td>
                                    <td><small class="text-muted"><?= $row['topic']; ?></small></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>

                        </table>

                    </div>
                </div>

            </div>

        </div>
    </div>

</div>

<!-- 🔥 GRÁFICAS SEPARADAS -->
<script>
let tempChart;
let humChart;

function loadChartData() {
    fetch('../api/get-chart-data.php')
        .then(res => res.json())
        .then(data => {
            updateTempChart(data);
            updateHumChart(data);
        });
}

function updateTempChart(data) {
    const ctx = document.getElementById('tempChart').getContext('2d');
    if (tempChart) tempChart.destroy();

    tempChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Temperatura (°C)',
                data: data.temperatura,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255,99,132,0.25)',
                tension: 0.4
            }]
        }
    });
}

function updateHumChart(data) {
    const ctx = document.getElementById('humChart').getContext('2d');
    if (humChart) humChart.destroy();

    humChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Humedad (%)',
                data: data.humedad,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54,162,235,0.25)',
                tension: 0.4
            }]
        }
    });
}

function sendCommand() {
    const device = deviceSelect.value;
    const command = commandSelect.value;
    const parameter = parameter.value;

    const statusDiv = document.getElementById("commandStatus");
    statusDiv.className = "alert alert-warning";
    statusDiv.textContent = "Enviando comando...";

    fetch('../api/send-command.php', {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ dispositivo: device, comando: command, parametro: parameter })
    })
    .then(res => res.json())
    .then(data => {
        statusDiv.className = data.success ? "alert alert-success" : "alert alert-danger";
        statusDiv.textContent = data.message;
    });
}

function refreshData() { location.reload(); }

function startRealtimeUpdates() {
    setInterval(() => {
        fetch('../api/get-latest-data.php').then(res => res.json());
    }, 10000);
}

document.addEventListener("DOMContentLoaded", () => {
    loadChartData();
    startRealtimeUpdates();
    setInterval(loadChartData, 30000);
});
</script>

<?php include '../core/footer.php'; ?>

</body>
</html>
