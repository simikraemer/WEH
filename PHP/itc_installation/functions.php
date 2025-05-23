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