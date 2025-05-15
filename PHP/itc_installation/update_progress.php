<?php
require_once("template.php");
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
$id = $input['id'] ?? null;
$field = $input['field'] ?? null;
$checked = $input['checked'] ?? false;

$allowed_fields = [ 'prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios', 'prog_software', 'prog_updates', 'prog_dock', 'prog_monitor', 'prog_ausgabe', 'prog_altgerät' ];

if (!is_numeric($id) || !in_array($field, $allowed_fields, true)) {
    http_response_code(400);
    echo json_encode(["error" => "Ungültige Eingabe"]);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM installation WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    http_response_code(404);
    echo json_encode(["error" => "Eintrag nicht gefunden"]);
    exit;
}

// Timestamp aktualisieren
$newValue = $checked ? time() : null;
$stmt = mysqli_prepare($conn, "UPDATE installation SET `$field` = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $newValue, $id);
mysqli_stmt_execute($stmt);

// Neue Statusberechnung
$newStatus = getUpdatedStatus($row, $field, $checked);
if (!is_null($newStatus) && $newStatus !== (int)$row['status']) {
    mysqli_query($conn, "UPDATE installation SET status = $newStatus WHERE id = $id");
} else {
    $newStatus = (int)$row['status'];
}

echo json_encode([
    "success" => true,
    "new_status" => $newStatus
]);
