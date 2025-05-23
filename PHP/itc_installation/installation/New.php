<?php
require_once("../template.php");

// Konfiguration
$table = 'installation';
$fieldGroups = [
    [
        ['Status', 'status', 'select', $status],
        ['Ticket', 'ticket', 'text'],
        ['Ausgabedatum', 'datum', 'date'],
        ['Zeit', 'zeit', 'time'],
    ],
    [
        ['Neugerät', 'neugerät', 'text'],
        ['Name', 'name', 'text'],
        ['Abteilung', 'abteilung', 'select', $abteilungen],
        ['MA-Status', 'mastatus', 'select', $mastatus],
    ],
    [
        ['Docking-Station', 'dock', 'text'],
        ['Monitor', 'monitor', 'text'],
        ['Altgerät', 'altgerät', 'text'],
    ],
    [
        ['Software/Lizenz', 'software', 'textarea'],
        ['Notiz', 'notiz', 'textarea'],
    ],
];

// Leeres Array für Default-Werte
$row = array_fill_keys(array_map(fn($f) => $f[1], array_merge(...$fieldGroups)), '');

// Beispielwerte für Platzhalter (optional)
$row['status'] = 2;

// Beim POST-Submit weiterleiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once("../insert.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Neue Installation</title>
    <link rel="stylesheet" href="../ITA.css">
    <link rel="stylesheet" href="../HEADER.css">
    <link rel="stylesheet" href="../EDIT.css">
    <link rel="icon" type="image/png" href="../favicon.png">
</head>
<body>
    <?php include("../header.php"); ?>
    <?php renderEditForm($fieldGroups, $row, $table, null, '../insert.php'); ?>
</body>
</html>
