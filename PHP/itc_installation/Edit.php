<?php
require_once("template.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || !is_numeric($_POST['id'])) {
    echo "<div class='container'><h2>Kein gültiger Eintrag ausgewählt.</h2></div>";
    exit;
}

$id = (int)$_POST['id'];

// Datenbankeintrag laden
$stmt = mysqli_prepare($conn, "SELECT * FROM installation WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    echo "<div class='container'><h2>Eintrag nicht gefunden.</h2></div>";
    exit;
}

// Helper-Funktion
function formatUnix($ts) {
    return $ts ? date('d.m.Y H:i', $ts) : '';
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ITA Bearbeiten</title>
    <link rel="stylesheet" href="ITC.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <script>
        async function updateField(field, value) {
            const response = await fetch('update_field.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: <?= $id ?>, field: field, value: value })
            });

            if (!response.ok) {
                alert("Fehler beim Speichern von " + field);
            }
        }
    </script>
</head>
<body>
<?php
echo <<<HTML
<div class="main-header">
    <div class="logo-title">💻 IT-Administration Neugeräte</div>
    <nav class="main-nav">
        <a class="nav-link deactive">✏️ Quittung</a>
        <a class="nav-link active">🔨 Bearbeiten</a>
        <a href="Installation.php" class="nav-link">📋 Übersicht</a>
        <a href="New.php" class="nav-link">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link">📁 Archiv</a>
        <a href="Statistik.php" class="nav-link">📊 Statistik</a>
        <a href="Admin.php" class="nav-link">⚙️ Einstellungen</a>
    </nav>
</div>
HTML;
?>

<div class="container">
    <h2>Eintrag bearbeiten</h2>

    <div class="edit-section">
        <h3>Allgemeine Informationen</h3>
        <div class="edit-row">
            <?= inputField('Status', 'status', 'select', $row, $status) ?>
            <?= inputField('Ticket', 'ticket', 'text', $row) ?>
            <?= inputField('Ausgabedatum', 'datum', 'date', $row) ?>
            <?= inputField('Zeit', 'zeit', 'time', $row) ?>
        </div>
        <div class="edit-row">
            <?= inputField('Neugerät', 'neugerät', 'text', $row) ?>
            <?= inputField('Name', 'name', 'text', $row) ?>
            <?= inputField('Abteilung', 'abteilung', 'select', $row, $abteilungen) ?>
            <?= inputField('MA-Status', 'mastatus', 'select', $row, $mastatus) ?>
        </div>
        <div class="edit-row">
            <?= inputField('Docking-Station', 'dock', 'text', $row) ?>
            <?= inputField('Monitor', 'monitor', 'text', $row) ?>
            <?= inputField('Altgerät', 'altgerät', 'text', $row) ?>
        </div>
        <div class="edit-row">
            <?= inputField('Software/Lizenz', 'software', 'textarea', $row) ?>
            <?= inputField('Notiz', 'notiz', 'textarea', $row) ?>
        </div>
    </div>

    <div class="edit-section">
        <h3>Installationsfortschritt Zeitpunkte</h3>
        <div class="edit-row">
            <?= inputField('Geräte-Sharepoint', 'prog_sp', 'datetime', $row) ?>
            <?= inputField('DHCP-Admin', 'prog_dhcp', 'datetime', $row) ?>
            <?= inputField('PXE-Boot', 'prog_pxe', 'datetime', $row) ?>
            <?= inputField('BIOS-PW', 'prog_bios', 'datetime', $row) ?>
        </div>
        <div class="edit-row">
            <?= inputField('Software/Lizenz erledigt', 'prog_software', 'datetime', $row) ?>
            <?= inputField('Dock vorbereitet', 'prog_dock', 'datetime', $row) ?>
            <?= inputField('Monitor vorbereitet', 'prog_monitor', 'datetime', $row) ?>
        </div>
        <div class="edit-row">
            <?= inputField('Updates installiert', 'prog_updates', 'datetime', $row) ?>
            <?= inputField('Neugerät ausgegeben', 'prog_ausgabe', 'datetime', $row) ?>
            <?= inputField('Altgerät zurückgegeben', 'prog_altgerät', 'datetime', $row) ?>
        </div>
    </div>
</div>

<?php
// PHP-Funktion für Eingabefelder
function inputField($label, $field, $type, $row, $options = []) {
    $value = htmlspecialchars($row[$field] ?? '');
    $out = "<div class='edit-field'>";
    $out .= "<label for='$field'>$label</label>";

    switch ($type) {
        case 'text':
        case 'date':
        case 'time':
            $out .= "<input type='$type' id='$field' value='$value' onchange=\"updateField('$field', this.value)\">";
            break;

        case 'textarea':
            $out .= "<textarea id='$field' onchange=\"updateField('$field', this.value)\">$value</textarea>";
            break;

        case 'select':
            $out .= "<select id='$field' onchange=\"updateField('$field', this.value)\">";

            foreach ($options as $k => $v) {
                // Wenn Wert ein Array (z. B. bei $status), extrahiere Label
                $label = is_array($v) ? $v['label'] : $v;

                // WENN Feld "abteilung", vergleiche mit Label (nicht ID)
                if ($field === 'abteilung') {
                    $selected = ((string)$value === (string)$label) ? "selected" : "";
                    $out .= "<option value='" . htmlspecialchars($label) . "' $selected>" . htmlspecialchars($label) . "</option>";
                } else {
                    $selected = ((string)$value === (string)$k) ? "selected" : "";
                    $out .= "<option value='" . htmlspecialchars($k) . "' $selected>" . htmlspecialchars($label) . "</option>";
                }
            }

            $out .= "</select>";
            break;


        case 'datetime':
            $formatted = $value ? date('d.m.Y H:i', (int)$value) : '';
            $out .= "<input type='text' id='$field' value='$formatted' onchange=\"updateField('$field', this.value)\">";
            break;
    }

    return $out . "</div>";
}
?>


</body>
</html>
