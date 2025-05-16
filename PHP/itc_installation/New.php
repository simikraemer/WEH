<?php
require_once("template.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ITA Neuer Eintrag</title>
    <link rel="stylesheet" href="ITC.css">
    <link rel="icon" type="image/png" href="favicon.png">
</head>
<body>
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'status', 'ticket', 'datum', 'zeit', 'neugerät', 'name', 'abteilung',
        'mastatus', 'altgerät', 'dock', 'monitor', 'software', 'notiz'
    ];

    $data = [];
    foreach ($fields as $field) {
        $data[$field] = $_POST[$field] ?? null;

        // Zeit leeren
        if ($field === 'zeit' && ($v === '' || $v === '00:00' || $v === '00:00:00')) {
            $data[$field] = null;
        }

        // Datum leeren
        if ($field === 'datum' && ($v === '' || $v === '0000-00-00')) {
            $data[$field] = null;
        }
    }

    // SQL vorbereiten
    $placeholders = implode(',', array_fill(0, count($data), '?'));
    $columns = implode(',', array_keys($data));
    $stmt = mysqli_prepare($conn, "INSERT INTO installation ($columns) VALUES ($placeholders)");

    // Bind-Typen bauen (s = string, i = int)
    $types = '';
    $bindValues = [];
    foreach ($data as $val) {
        if (is_numeric($val) && $val !== '') {
            $types .= 'i';
            $bindValues[] = (int)$val;
        } else {
            $types .= 's';
            $bindValues[] = $val;
        }
    }

    mysqli_stmt_bind_param($stmt, $types, ...$bindValues);
    mysqli_stmt_execute($stmt);

    header("Location: Installation.php");
    exit;
}
?>

<div class="main-header">
    <div class="logo-title">💻 IT-Administration Neugeräte</div>
    <nav class="main-nav">
        <a class="nav-link deactive">✏️ Quittung</a>
        <a class="nav-link deactive">🔨 Bearbeiten</a>
        <a href="Installation.php" class="nav-link">📋 Übersicht</a>
        <a href="New.php" class="nav-link active">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link">📁 Archiv</a>
        <a href="Statistik.php" class="nav-link">📊 Statistik</a>
        <a href="Admin.php" class="nav-link">⚙️ Einstellungen</a>
    </nav>
</div>

<div class="container">
    <h2>Neuen Eintrag erstellen</h2>
    <form method="post" class="edit-form">
        <div class="edit-section">
            <h3>Allgemeine Informationen</h3>
            <div class="edit-row">
                <?= selectInput('Status', 'status', $status, 2) ?>
                <?= textInput('Ticket', 'ticket', 'text', '20250101-0101') ?>
                <?= textInput('Ausgabedatum', 'datum', 'date') ?>
                <?= textInput('Zeit', 'zeit', 'time') ?>
            </div>
            <div class="edit-row">
                <?= textInput('Neugerät', 'neugerät', 'text', 'ITC123456') ?>
                <?= textInput('Name', 'name', 'text', 'Sabio Feer') ?>
                <?= selectInput('Abteilung', 'abteilung', $abteilungen) ?>
                <?= selectInput('MA-Status', 'mastatus', $mastatus) ?>
            </div>
            <div class="edit-row">
                <?= textInput('Docking-Station', 'dock', 'text', 'ITC123456') ?>
                <?= textInput('Monitor', 'monitor', 'text', 'ITC123456') ?>
                <?= textInput('Altgerät', 'altgerät', 'text', 'ITC123456') ?>
            </div>
            <div class="edit-row">
                <?= textareaInput('Software/Lizenz', 'software') ?>
                <?= textareaInput('Notiz', 'notiz') ?>
            </div>
        </div>

        <div class="edit-row button-row" style="margin-top: 20px;">
            <button type="submit" class="save-button">💾 Speichern</button>
        </div>
    </form>
</div>

<?php
function textInput($label, $name, $type = 'text', $placeholder = '') {
    $ph = $placeholder ? "placeholder=\"$placeholder\"" : "";
    return <<<HTML
    <div class='edit-field'>
        <label for='$name'>$label</label>
        <input type='$type' name='$name' id='$name' $ph>
    </div>
    HTML;
}


function textareaInput($label, $name) {
    return <<<HTML
    <div class='edit-field'>
        <label for='$name'>$label</label>
        <textarea name='$name' id='$name'></textarea>
    </div>
    HTML;
}

function selectInput($label, $name, $options, $default = null) {
    $html = "<div class='edit-field'><label for='$name'>$label</label><select name='$name' id='$name'>";
    foreach ($options as $k => $v) {
        $v = is_array($v) ? $v['label'] : $v;
        $sel = ((string)$k === (string)$default) ? 'selected' : '';
        $html .= "<option value='$k' $sel>" . htmlspecialchars($v) . "</option>";
    }
    $html .= "</select></div>";
    return $html;
}
?>
