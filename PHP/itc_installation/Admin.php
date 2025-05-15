<?php
require_once("template.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Verwaltung</title>
    <link rel="stylesheet" href="ITC.css">
</head>
<body>
<?php

$tables = [
    'abteilungen' => ['label' => 'Abteilungen', 'columns' => ['name']],
    'ma_status' => ['label' => 'Mitarbeiter-Status', 'columns' => ['label']],
    'administratoren' => ['label' => 'Admins', 'columns' => ['name']],
    'allowed_ips' => ['label' => 'Freigeschaltete IPs', 'columns' => ['ip', 'prefix']]
];

$table = $_GET['table'] ?? 'abteilungen';
if (!array_key_exists($table, $tables)) $table = 'abteilungen';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;

    if ($action === 'delete' && is_numeric($id)) {
        mysqli_query($conn, "DELETE FROM $table WHERE id = " . (int)$id);
    }

    if ($action === 'add') {
        $cols = $tables[$table]['columns'];
        $vals = [];

        foreach ($cols as $col) {
            $val = $_POST[$col] ?? '';

            // Sonderbehandlung für prefix: wenn leer, auf 32 setzen
            if ($table === 'allowed_ips' && $col === 'prefix' && trim($val) === '') {
                $val = '32';
            }

            $val = mysqli_real_escape_string($conn, $val);
            $vals[] = "'$val'";
        }
        $colList = implode(',', $cols);
        $valList = implode(',', $vals);
        mysqli_query($conn, "INSERT INTO $table ($colList) VALUES ($valList)");
    }
}

// Daten abfragen
$result = mysqli_query($conn, "SELECT * FROM $table ORDER BY id ASC");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Verwaltung</title>
    <link rel="stylesheet" href="ITC.css">
</head>
<body>
<div class="main-header">
    <div class="logo-title">💻 IT-Administration Neugeräte</div>
    <nav class="main-nav">
        <a href="Installation.php" class="nav-link">📋 Übersicht</a>
        <a href="New.php" class="nav-link">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link">📁 Archiv</a>
        <a href="Admin.php" class="nav-link active">⚙️ Einstellungen</a>
    </nav>
</div>

<div class="container">
    <div class="admin-wrapper">
        
        <!-- Tabs-Leiste oben -->
        <div class="admin-tabs">
            <?php foreach ($tables as $key => $meta): ?>
                <a href="?table=<?= $key ?>" class="admin-tab <?= $key === $table ? 'active' : '' ?>">
                    <?= htmlspecialchars($meta['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Tabellen-Container -->
        <div class="admin-table-container">
            <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <?php foreach ($tables[$table]['columns'] as $col): ?>
                        <th><?= ucfirst($col) ?></th>
                    <?php endforeach; ?>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <?php foreach ($tables[$table]['columns'] as $col): ?>
                            <td><?= htmlspecialchars($row[$col]) ?></td>
                        <?php endforeach; ?>
                        <td>
                            <form method="post" onsubmit="return confirm('Eintrag wirklich löschen?')">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="btn danger">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <tr>
                    <form method="post" onsubmit="handlePrefixDefault(event)">
                        <input type="hidden" name="action" value="add">
                        <td>➕</td>

                        <?php if ($table === 'allowed_ips'): ?>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="text" name="ip" class="admin-input" placeholder="z.B. 192.168.0.1">
                                    <button type="button" class="btn small" id="net-toggle" onclick="showPrefix()">Netz?</button>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="prefix" class="admin-input" id="prefix-field" style="display: none;" placeholder="z.B. 24">
                            </td>
                        <?php else: ?>
                            <?php foreach ($tables[$table]['columns'] as $col): ?>
                                <td><input type="text" name="<?= $col ?>" class="admin-input"></td>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <td><button class="btn add">Hinzufügen</button></td>
                    </form>
                </tr>


            </tbody>
        </table>
    </div>
</div>

</div>
<script>
function showPrefix() {
    const prefixField = document.getElementById('prefix-field');
    const toggleButton = document.getElementById('net-toggle');
    if (prefixField && toggleButton) {
        prefixField.style.display = 'inline-block';
        toggleButton.remove(); // Kein Zurück mehr
    }
}
</script>


</body>
</html>
