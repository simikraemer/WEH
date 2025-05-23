<?php
require_once("template.php"); // erwartet: $DBtableConfig ist dort definiert

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || !is_numeric($_POST['id']) || empty($_POST['table'])) {
    die("Ungültiger Aufruf");
}

$id = (int)$_POST['id'];
$table = $_POST['table'];

// $DBtableConfig muss durch template.php global bereitgestellt werden
if (!isset($DBtableConfig[$table])) {
    die("Unbekannte Tabelle");
}

$config = $DBtableConfig[$table];
$allowedFields = $config['fields'] ?? [];
$timestampFields = $config['timestamps'] ?? [];
$redirectAfter = $config['redirect'] ?? null;

$updates = [];
$params = [];
$types = '';

foreach ($_POST as $key => $value) {
    if ($key === 'id' || $key === 'table' || !in_array($key, $allowedFields, true)) {
        continue;
    }

    // Zeitfelder
    if (in_array($key, $timestampFields, true)) {
        $value = empty($value) ? null : DateTime::createFromFormat('Y-m-d\TH:i', $value)?->getTimestamp();
    }

    // Sonderbehandlung für "zeit"
    if ($key === 'zeit') {
        $value = trim($value);
        if ($value === '' || $value === '00:00' || $value === '00:00:00') {
            $value = null;
        }
    }

    $updates[] = "`$key` = ?";
    $params[] = $value;
    $types .= is_null($value) ? 's' : (is_numeric($value) ? 'i' : 's');
}

if (empty($updates)) {
    die("Keine Felder zum Speichern.");
}

// ID anhängen
$params[] = $id;
$types .= 'i';

$sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);

// Redirect oder plain OK
if ($redirectAfter) {
    header("Location: $redirectAfter");
    exit;
} else {
    echo "OK";
}
