<?php
require_once("template.php");
header("Content-Type: application/json");

// Eingabe dekodieren
$input = json_decode(file_get_contents("php://input"), true);
$id     = $input['id']     ?? null;
$field  = $input['field']  ?? null;
$value  = $input['value']  ?? null;

$allowedFields = [
    'status', 'ticket', 'datum', 'zeit', 'neugerät', 'name', 'abteilung', 'mastatus',
    'altgerät', 'dock', 'monitor', 'software', 'notiz',
    'prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios', 'prog_software',
    'prog_updates', 'prog_dock', 'prog_monitor', 'prog_ausgabe', 'prog_altgerät'
];

if (!is_numeric($id) || !in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(["error" => "Ungültige Eingabe"]);
    exit;
}

// Zeitfelder erkennen (werden als 'dd.mm.yyyy hh:mm' übergeben)
$timestampFields = [
    'prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios', 'prog_software',
    'prog_updates', 'prog_dock', 'prog_monitor', 'prog_ausgabe', 'prog_altgerät'
];

if (in_array($field, $timestampFields, true)) {
    if (empty($value)) {
        $value = null;
    } else {
        $dt = DateTime::createFromFormat('d.m.Y H:i', $value);
        if ($dt === false) {
            http_response_code(400);
            echo json_encode(["error" => "Ungültiges Datumsformat"]);
            exit;
        }
        $value = $dt->getTimestamp();
    }
}

// Sonderfall: Zeitfeld "zeit" → "00:00" oder "00:00:00" = NULL
if ($field === 'zeit') {
    $trimmed = trim((string)$value);
    if ($trimmed === '' || $trimmed === '00:00' || $trimmed === '00:00:00') {
        $value = null;
    }
}

// SQL vorbereiten
$query = "UPDATE installation SET `$field` = ? WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);

// Datentyp bestimmen
$type = is_null($value) ? 's' : (is_numeric($value) ? 'i' : 's');
mysqli_stmt_bind_param($stmt, $type . 'i', $value, $id);

// Ausführen
if (mysqli_stmt_execute($stmt)) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Speichern fehlgeschlagen"]);
}
?>
