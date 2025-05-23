<?php
require_once("template.php");

$table = $_GET['table'] ?? null;
if (!$table || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    http_response_code(400);
    die("Ungültiger Tabellenname");
}

// Daten abfragen
$result = mysqli_query($conn, "SELECT * FROM `$table`");
if (!$result) {
    http_response_code(500);
    die("Datenbankfehler");
}

// UTF-8 mit BOM für Excel
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . date('Ymd') . "_$table.csv\"");

echo "\xEF\xBB\xBF"; // BOM

$first = true;
while ($row = mysqli_fetch_assoc($result)) {
    if ($first) {
        echo implode(';', array_map(fn($h) => '"' . str_replace('"', '""', $h) . '"', array_keys($row))) . "\n";
        $first = false;
    }
    echo implode(';', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
}
?>
