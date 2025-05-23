<?php
require_once("../template.php");

// Konfiguration
$table = 'installation';
$fieldGroups = [
    [ // Erste Zeile
        ['Status', 'status', 'select', $status],
        ['Ticket', 'ticket', 'text'],
        ['Ausgabedatum', 'datum', 'date'],
        ['Zeit', 'zeit', 'time'],
    ],
    [ // Zweite Zeile
        ['Neugerät', 'neugerät', 'text'],
        ['Name', 'name', 'text'],
        ['Abteilung', 'abteilung', 'select', $abteilungen],
        ['MA-Status', 'mastatus', 'select', $mastatus],
    ],
    [ // Dritte Zeile
        ['Docking-Station', 'dock', 'text'],
        ['Monitor', 'monitor', 'text'],
        ['Altgerät', 'altgerät', 'text'],
    ],
    [ // Vierte Zeile
        ['Software/Lizenz', 'software', 'textarea'],
        ['Notiz', 'notiz', 'textarea'],
    ],
];

// ID aus POST holen
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || !is_numeric($_POST['id'])) {
    header("Location: Archiv.php");
    exit;
}

$id = (int)$_POST['id'];
$stmt = mysqli_prepare($conn, "SELECT * FROM `$table` WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    echo "<div class='container'><h2>Eintrag nicht gefunden.</h2></div>";
    exit;
}

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($row['name'] . " " . $row['neugerät']) ?></title>
    <link rel="stylesheet" href="../ITA.css">
    <link rel="stylesheet" href="../HEADER.css">
    <link rel="stylesheet" href="../EDIT.css">
    <link rel="icon" type="image/png" href="../favicon.png">
</head>
<body>
    <?php include("../header.php"); ?>
    <?php renderEditForm($fieldGroups, $row, $table, $id); ?>
</body>
</html>
