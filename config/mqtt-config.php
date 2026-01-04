<?php
// Configuración MQTT para ESP32
define('MQTT_BROKER', 'localhost');
define('MQTT_PORT', 1883);
define('MQTT_CLIENT_ID', 'iot-system-web');
define('MQTT_USERNAME', null);  // Usar null en lugar de string vacío
define('MQTT_PASSWORD', null);  // Usar null en lugar de string vacío

// Topics para ESP32
define('TOPIC_SENSOR_DATA', 'esp32/sensor/data');
define('TOPIC_SENSOR_STATUS', 'esp32/sensor/status');
define('TOPIC_CONTROL', 'esp32/control');
// También puedes agregar topics específicos por dispositivo
// define('TOPIC_ESP32_001', 'esp32/001/data');