<?php
require_once("template.php");

// Nur POST zulassen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Nur POST erlaubt";
    exit;
}

$table = $_POST['table'] ?? null;
if (!$table || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    http_response_code(400);
    echo "Ungültiger Tabellenname";
    exit;
}

// Konfiguration laden (muss vorher in template.php gesetzt sein)
if (!isset($DBtableConfig[$table])) {
    http_response_code(400);
    echo "Unbekannte Tabelle";
    exit;
}

$config = $DBtableConfig[$table];
$allowedFields = $config['fields'] ?? [];
$timestampFields = $config['timestamps'] ?? [];
$redirectAfter = $config['redirect'] ?? null;

// Daten aus POST extrahieren
$data = [];
foreach ($_POST as $key => $value) {
    if (!in_array($key, $allowedFields, true)) {
        continue;
    }

    // Leere Zeit/Datum-Felder zu NULL machen
    if ($key === 'zeit' && ($value === '' || $value === '00:00' || $value === '00:00:00')) {
        $value = null;
    }
    if ($key === 'datum' && ($value === '' || $value === '0000-00-00')) {
        $value = null;
    }

    // Timestamps umwandeln
    if (in_array($key, $timestampFields, true)) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
        $value = $dt ? $dt->getTimestamp() : null;
    }

    $data[$key] = $value;
}

if (empty($data)) {
    http_response_code(400);
    echo "Keine gültigen Felder übermittelt";
    exit;
}

// SQL bauen
$columns = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
$placeholders = implode(', ', array_fill(0, count($data), '?'));
$query = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    http_response_code(500);
    echo "Fehler bei Vorbereitung der Datenbank";
    exit;
}

// Bind-Typen und Werte vorbereiten
$types = '';
$values = [];
foreach ($data as $value) {
    if (is_numeric($value) && $value !== '') {
        $types .= 'i';
        $values[] = (int)$value;
    } else {
        $types .= 's';
        $values[] = $value;
    }
}

mysqli_stmt_bind_param($stmt, $types, ...$values);

// Ausführen
if (mysqli_stmt_execute($stmt)) {
    if ($redirectAfter) {
        header("Location: $redirectAfter");
        exit;
    } else {
        echo "OK";
    }
} else {
    http_response_code(500);
    echo "Einfügen fehlgeschlagen";
}
