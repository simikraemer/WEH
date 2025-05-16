<?php
require_once("template.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ITA Archiv</title>
    <link rel="stylesheet" href="ITC.css">
    <link rel="icon" type="image/png" href="favicon.png">
</head>
<body>
<?php

// Jahr aus GET oder Standard auf aktuelles
$jahr = isset($_GET['jahr']) && is_numeric($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

// Start- und Enddatum für das Jahr
$startDate = "$jahr-01-01";
$endDate = "$jahr-12-31";

// Query vorbereiten
$query = "SELECT * FROM installation WHERE datum BETWEEN ? AND ? ORDER BY datum ASC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'ss', $startDate, $endDate);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<div class="main-header">
    <div class="logo-title">💻 IT-Administration Neugeräte</div>
    <nav class="main-nav">
        <a class="nav-link deactive">✏️ Quittung</a>
        <a class="nav-link deactive">🔨 Bearbeiten</a>
        <a href="Installation.php" class="nav-link">📋 Übersicht</a>
        <a href="New.php" class="nav-link">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link active">📁 Archiv</a>
        <a href="Statistik.php" class="nav-link">📊 Statistik</a>
        <a href="Admin.php" class="nav-link">⚙️ Einstellungen</a>
    </nav>
</div>

<div class="archiv-header">
    <form method="get" class="jahr-selector">
        <select name="jahr" id="jahr" onchange="this.form.submit()">
            <?php
            for ($y = date('Y'); $y >= 2024; $y--) {
                $sel = ($jahr === $y) ? 'selected' : '';
                echo "<option value=\"$y\" $sel>$y</option>";
            }
            ?>
        </select>
    </form>
</div>

<div class="container">
    <table class="archiv-table">
        <thead>
            <tr>
                <th>Status</th>
                <th>Ticket</th>
                <th>Datum</th>
                <th>Neugerät</th>
                <th>Name</th>
                <th>Abteilung</th>
                <th>MA-Status</th>
                <th>Altgerät</th>
                <th>Dock</th>
                <th>Monitor</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                <tr class="clickable-row" data-id="<?= $row['id'] ?>">
                    <td><?= htmlspecialchars($status[$row['status']]['label'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['ticket']) ?></td>
                    <td>
                        <?php
                        if (!empty($row['datum'])) {
                            $d = DateTime::createFromFormat('Y-m-d', $row['datum']);
                            echo $d ? $d->format('d.m.Y') : htmlspecialchars($row['datum']);
                        }
                        if (!empty($row['zeit'])) {
                            echo ' ' . substr($row['zeit'], 0, 5);
                        }
                        ?>
                    </td>
                    <td><?= htmlspecialchars($row['neugerät']) ?></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['abteilung']) ?></td>
                    <td><?= htmlspecialchars($mastatus[$row['mastatus']] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['altgerät']) ?></td>
                    <td><?= htmlspecialchars($row['dock']) ?></td>
                    <td><?= htmlspecialchars($row['monitor']) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.clickable-row').forEach(row => {
    row.addEventListener('click', () => {
        const id = row.dataset.id;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'Edit.php';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = id;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    });
});
</script>

<script>
let currentSort = { index: null, asc: true };

document.querySelectorAll('.archiv-table th').forEach((th, index) => {
    th.addEventListener('click', () => {
        const table = th.closest('table');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        const isAsc = currentSort.index === index ? !currentSort.asc : true;
        currentSort = { index, asc: isAsc };

        rows.sort((a, b) => {
            const aText = a.children[index].innerText.trim().toLowerCase();
            const bText = b.children[index].innerText.trim().toLowerCase();

            // Für Datumsspalte spezielles Parsen
            if (th.innerText.toLowerCase().includes('datum')) {
                const parseDate = str => {
                    const parts = str.split(/[.\s:]/);
                    return new Date(parts[2], parts[1] - 1, parts[0], parts[3] || 0, parts[4] || 0);
                };
                return (isAsc ? 1 : -1) * (parseDate(aText) - parseDate(bText));
            }

            return isAsc
                ? aText.localeCompare(bText)
                : bText.localeCompare(aText);
        });

        // Sortierte Zeilen neu anhängen
        rows.forEach(row => tbody.appendChild(row));

        // Pfeile setzen
        document.querySelectorAll('.archiv-table th').forEach(th => th.classList.remove('sorted-asc', 'sorted-desc'));
        th.classList.add(isAsc ? 'sorted-asc' : 'sorted-desc');
    });
});
</script>
