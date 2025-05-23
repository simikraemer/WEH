<?php
// ==========================================
// template.php – zentrale Konfigurationsbasis
// ==========================================

// ------------------------------
// DB-Konfiguration
// ------------------------------
$config_path = '/etc/credentials/config.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config = json_decode(file_get_contents($config_path), true);
$mysql_config = $config['itcphp'] ?? die('MySQL-Konfiguration fehlt.');

$conn = mysqli_connect(
    $mysql_config['host'],
    $mysql_config['user'],
    $mysql_config['password'],
    $mysql_config['database']
) or die('Datenbankverbindung fehlgeschlagen.');

mysqli_set_charset($conn, 'utf8');