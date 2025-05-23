<?php
function getUpdatedStatus(array $row, string $field, bool $checked): ?int {
    // Felder vor Updates
    $preUpdateFields = ['prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios'];
    if (!empty($row['software'])) {
        $preUpdateFields[] = 'prog_software';
    }

    // Hilfsfunktion innerhalb
    $isSet = function($key) use ($row, $field, $checked) {
        return $key === $field ? $checked : !empty($row[$key]);
    };

    $status = $row['status'];

    $allPreDone = true;
    foreach ($preUpdateFields as $f) {
        if (!$isSet($f)) {
            $allPreDone = false;
            break;
        }
    }

    $updatesDone = $isSet('prog_updates');
    $ausgabeDone = $isSet('prog_ausgabe');
    $altgerätDone = $isSet('prog_altgerät');

    // 0/1 → 2
    if (in_array($status, [0, 1]) && $checked && in_array($field, $preUpdateFields)) {
        return 2;
    }

    // 2/4 → 4/5
    if (in_array($status, [2, 4])) {
        if ($allPreDone && $updatesDone) return 5;
        if ($allPreDone) return 4;
        if ($status === 4 && !$allPreDone) return 2;
    }

    // 5 → 4, wenn Updates entfernt
    if ($status === 5 && $field === 'prog_updates' && !$checked) {
        return 4;
    }

    // Ausgabe gesetzt
    if ($field === 'prog_ausgabe' && $checked) {
        if (!empty($row['altgerät']) && !$altgerätDone) return 7;
        return 8;
    }

    // Ausgabe entfernt
    if ($field === 'prog_ausgabe' && !$checked && in_array($status, [7, 8])) {
        if ($updatesDone) return 5;
        if ($allPreDone) return 4;
        return 2;
    }

    // Altgerät zurückgegeben
    if ($field === 'prog_altgerät' && $checked && $status == 7) {
        return 8;
    }

    // Altgerät entfernt
    if ($field === 'prog_altgerät' && !$checked && $status == 8 && !empty($row['altgerät'])) {
        if (!empty($row['prog_ausgabe'])) return 7;
        if ($updatesDone) return 5;
        if ($allPreDone) return 4;
        return 2;
    }

    return null; // Kein Statuswechsel nötig
}


function getContrastTextColor($bgColorHex) {
    $hex = ltrim($bgColorHex, '#');

    if (strlen($hex) !== 6) return '#000000'; // Fallback

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // Helligkeit nach YIQ-Formel
    $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

    return ($brightness > 160) ? '#000000' : '#ffffff'; // ab ca. 160: schwarz besser
}


// Listen

function fetchListByYear(mysqli $conn, string $table, ?string $jahr): mysqli_result {
    if ($jahr === 'all') {
        $query = "SELECT * FROM `$table` ORDER BY datum ASC";
        $stmt = mysqli_prepare($conn, $query);
    } elseif (is_numeric($jahr)) {
        $start = "$jahr-01-01";
        $end = "$jahr-12-31";
        $query = "SELECT * FROM `$table` WHERE datum BETWEEN ? AND ? ORDER BY datum ASC";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
    } else {
        die("<div class='container'><h2>Ungültiges Jahr.</h2></div>");
    }

    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}


function renderYearSelect(?string $selectedJahr): void {
    echo '<form method="get" class="jahr-selector">';
    echo '<select name="jahr" id="jahr" onchange="this.form.submit()">';

    $sel = (!isset($selectedJahr) || $selectedJahr === 'all') ? 'selected' : '';
    echo "<option value=\"all\" $sel>Gesamt</option>";

    for ($y = date('Y'); $y >= 2024; $y--) {
        $sel = ((string)$selectedJahr === (string)$y) ? 'selected' : '';
        echo "<option value=\"$y\" $sel>$y</option>";
    }

    echo '</select>';
    echo '</form>';
}

function renderListTable(array $rows, array $columns, callable $rowRenderer): void {
    echo '<table class="list-table">';
    echo '<thead><tr>';
    foreach ($columns as $col) {
        echo "<th>$col</th>";
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo $rowRenderer($row);
    }

    echo '</tbody></table>';
}

function renderListTableAuto(array $rows, array $columns): void {
    echo '<table class="list-table"><thead><tr>';
    foreach ($columns as $col) {
        echo "<th>" . htmlspecialchars($col['label']) . "</th>";
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo "<tr class='clickable-row' data-id='" . (int)$row['id'] . "'>";
        foreach ($columns as $col) {
            $key = $col['key'];
            $val = $row[$key] ?? '';

            // Mapping
            if (isset($col['map']) && isset($col['map'][$val])) {
                $val = is_array($col['map'][$val]) ? $col['map'][$val]['label'] : $col['map'][$val];
            }

            // Formatierung
            if ($col['format'] ?? false) {
                switch ($col['format']) {
                    case 'date':
                        $val = $val ? (DateTime::createFromFormat('Y-m-d', $val)?->format('d.m.Y') ?? $val) : '';
                        break;
                    case 'time':
                        $val = substr($val, 0, 5);
                        break;
                }
            }

            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";
    }

    echo '</tbody></table>';
}

// Edit / New
function inputField($label, $field, $type, $row, $options = []) {
    $value = htmlspecialchars($row[$field] ?? '');
    $out = "<div class='edit-field'>";
    $out .= "<label for='$field'>$label</label>";

    switch ($type) {
        case 'text':
        case 'date':
        case 'time':
            $out .= "<input type='$type' name='$field' id='$field' value='$value'>";
            break;

        case 'textarea':
            $out .= "<textarea name='$field' id='$field'>$value</textarea>";
            break;

        case 'select':
            $out .= "<select name='$field' id='$field'>";
            foreach ($options as $k => $v) {
                $labelText = is_array($v) ? $v['label'] : $v;
                $selected = ($field === 'abteilung')
                    ? ((string)$value === (string)$labelText ? "selected" : "")
                    : ((string)$value === (string)$k ? "selected" : "");
                $optionValue = $field === 'abteilung' ? $labelText : $k;
                $out .= "<option value='" . htmlspecialchars($optionValue) . "' $selected>" . htmlspecialchars($labelText) . "</option>";
            }
            $out .= "</select>";
            break;

        case 'datetime':
            $formatted = $value ? date('Y-m-d\TH:i', (int)$value) : '';
            $out .= "<input type='datetime-local' name='$field' id='$field' value='$formatted'>";
            break;
    }

    return $out . "</div>";
}

function renderEditForm(array $fieldGroups, array $row, string $table, ?int $id = null, string $action = '../update.php'): void {
    echo '<div class="container">';
    echo "<form method='post' action='" . htmlspecialchars($action) . "' class='edit-section'>";

    if ($id !== null) {
        echo "<input type='hidden' name='id' value='" . htmlspecialchars($id) . "'>";
    }

    echo "<input type='hidden' name='table' value='" . htmlspecialchars($table) . "'>";

    foreach ($fieldGroups as $rowSet) {
        echo '<div class="edit-row">';
        foreach ($rowSet as $item) {
            echo inputField($item[0], $item[1], $item[2], $row, $item[3] ?? []);
        }
        echo '</div>';
    }

    echo '<div class="edit-row button-row">';
    echo '<button type="submit" class="save-button">💾 Speichern</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

