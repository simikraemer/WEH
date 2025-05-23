<?php
require_once("template.php");
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ITA Quittung</title>
    <link rel="stylesheet" href="ITA.css">
    <link rel="stylesheet" href="HEADER.css">
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
include("header.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || !is_numeric($_POST['id'])) {
    echo "<div class='container'><h2>Kein gültiger Eintrag ausgewählt.</h2></div>";
    exit;
}

$id = (int)$_POST['id'];
$typ = $_POST['typ'] ?? 'ausgabe'; // fallback
$titel = $typ === 'rueckgabe' ? 'Quittung für Rückgabe' : 'Quittung für Ausgabe';

// Datenbankeintrag holen
$stmt = mysqli_prepare($conn, "SELECT * FROM installation WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    echo "<div class='container'><h2>Eintrag nicht gefunden.</h2></div>";
    exit;
}

// Admins aus DB
$admins = [];
$res = mysqli_query($conn, "SELECT name FROM administratoren ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($res)) {
    $admins[] = $r['name'];
}
?>

<div class="container" style="max-width: 500px; margin-top: 50px;">
    <h2 style="text-align: center; margin-bottom: 30px;"><?= htmlspecialchars($titel) ?></h2>
    <form method="post" action="quittung_generate.php" target="_blank" style="display: flex; flex-direction: column; align-items: center;">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="typ" value="<?= htmlspecialchars($typ) ?>">
        <label for="admin">Ausgebende Person:</label>
        <select name="admin" id="admin" required style="margin-top: 10px; padding: 6px; width: 100%;">
            <?php foreach ($admins as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="save-button" style="margin-top: 20px;">Quittung erstellen</button>
    </form>
</div>
</body>
</html>
