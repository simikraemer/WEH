<?php
require_once("template.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ITA Übersicht</title>
    <link rel="stylesheet" href="ITC.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <script>
        async function toggleProgress(id, field, currentlyChecked) {
            const response = await fetch('update_progress.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    id: id,
                    field: field,
                    checked: !currentlyChecked
                })
            });

            if (!response.ok) {
                alert('Update fehlgeschlagen');
            } else {
                location.reload();
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
        <a href="Installation.php" class="nav-link active">📋 Übersicht</a>
        <a href="New.php" class="nav-link">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link">📁 Archiv</a>
        <a href="Admin.php" class="nav-link">⚙️ Einstellungen</a>
    </nav>
</div>
HTML;
?>
<div class="container">

    <?php
    $time_day_ago = time() - 24 * 60 * 60;
    $time_week_ago = time() - 7 * 24 * 60 * 60;
    $query = "
    SELECT * FROM installation
    WHERE status > -1 AND (
        status < 8 OR (
            status = 8 AND (
                prog_ausgabe > $time_day_ago
                OR (altgerät IS NOT NULL AND prog_altgerät > $time_day_ago)
            )
        )
    )
    ORDER BY datum ASC
    ";

    $result = mysqli_query($conn, $query);

    while ($row = mysqli_fetch_assoc($result)) {
        $bordercolor = $status[$row['status']]['color'];
        echo "<div class='installation'>";

        // Erste Etage: Infos und Buttons
        echo "<div class='info-row'>";

        $bgColor = $status[$row['status']]['color'];
        $textColor = getContrastTextColor($bgColor);

        echo "<div class='status-label' style='background-color: $bgColor; color: $textColor'>";
        echo htmlspecialchars($status[$row['status']]['label']);
        echo "</div>";

        echo "<div class='main-data'>";

        if (!empty($row['datum']) || !empty($row['zeit'])) {
            echo "<span class='ausgabe-datum'>";
            if (!empty($row['datum'])) {
                $datum = date('d.m.Y', strtotime($row['datum']));
                echo $datum;
            }
            if (!empty($row['zeit'])) {
                $zeit = date('H:i', strtotime($row['zeit']));
                echo !empty($row['datum']) ? " $zeit" : "Ausgabezeit: $zeit";
            }
            echo "</span><br>";
        }

        $parts = [];
        if (!empty($row['ticket'])) $parts[] = htmlspecialchars($row['ticket']);
        if (!empty($row['neugerät'])) $parts[] = htmlspecialchars($row['neugerät']);
        if (!empty($row['name'])) $parts[] = htmlspecialchars($row['name']);

        echo "<span class='ausgabe-subinfo'>" . implode(' | ', $parts) . "</span>";
        echo "</div>";

        echo "<div class='action-buttons'>";
        echo "<form method='post' action='Edit.php' style='display:inline;'>";
        echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
        echo "<button type='submit' class='icon-button edit-button' title='Bearbeiten'>🔨</button>";
        echo "</form>";
        echo "<button class='icon-button quittung-button' title='Quittung'>🧾</button>";
        echo "</div>";
        
        echo "</div>";
        echo "<hr>";

        // Zweite Etage: Fortschritt
        echo "<div class='progress-section'>";
        $prog_fields = [
            'prog_sp' => 'Geräte-Sharepoint',
            'prog_dhcp' => 'DHCP-Admin',
            'prog_bios' => 'BIOS-PW',
            'prog_pxe' => 'PXE-Boot',
            'prog_software' => 'Software',
            'prog_dock' => 'Dock vorbereitet',
            'prog_monitor' => 'Monitor vorbereitet',
            'prog_updates' => 'Updates',
            'prog_ausgabe' => 'Ausgegeben',
            'prog_altgerät' => 'Altgerät zurückgegeben'
        ];

        foreach ($prog_fields as $field => $label) {
            if ((strpos($field, 'software') !== false && empty($row['software'])) ||
                (strpos($field, 'dock') !== false && empty($row['dock'])) ||
                (strpos($field, 'monitor') !== false && empty($row['monitor'])) ||
                (strpos($field, 'altgerät') !== false && empty($row['altgerät']))) {
                continue;
            }

            $checked = !empty($row[$field]);
            $checkedClass = $checked ? 'checked' : '';
            $timestamp = $checked ? date('d.m.Y H:i', $row[$field]) : '';

            $dynamicLabel = $label;

            if ($field === 'prog_dock' && !empty($row['dock'])) {
                $dynamicLabel = "Dock " . $row['dock'];
            } elseif ($field === 'prog_monitor' && !empty($row['monitor'])) {
                $dynamicLabel = "Monitor " . $row['monitor'];
            } elseif ($field === 'prog_altgerät' && !empty($row['altgerät'])) {
                $dynamicLabel = $row['altgerät'] . " zurückgegeben";
            }

            echo "<div class='prog-item $checkedClass' onclick=\"toggleProgress(" . $row['id'] . ", '$field', this.classList.contains('checked'))\">";
            echo "<div class='prog-title'>" . htmlspecialchars($dynamicLabel) . "</div>";
            echo "<div class='date-label'>" . ($checked ? $timestamp : '') . "</div>";
            echo "</div>";


        }
        echo "</div>";


        // Software separat darunter
        if (!empty($row['software'])) {
            echo "<div class='software-hinweis'><strong>Software:</strong><br>" . nl2br(htmlspecialchars($row['software'])) . "</div>";
        }

        // Notiz separat darunter
        if (!empty($row['notiz'])) {
            echo "<div class='notiz'><strong>Notiz:</strong><br>" . nl2br(htmlspecialchars($row['notiz'])) . "</div>";
        }


        echo "</div>"; // .installation
    }
    ?>
</div>
</body>
</html>
