<?php
// ==========================================
// template.php – zentrale Konfigurationsbasis
// ==========================================

// ------------------------------
// DB-Konfiguration
// ------------------------------
$config_path = '/etc/credentials/config.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config = json_decode(file_get_contents($config_path), true);
$mysql_config = $config['itcphp'] ?? die('MySQL-Konfiguration fehlt.');

$conn = mysqli_connect(
    $mysql_config['host'],
    $mysql_config['user'],
    $mysql_config['password'],
    $mysql_config['database']
) or die('Datenbankverbindung fehlgeschlagen.');

mysqli_set_charset($conn, 'utf8');

// ------------------------------
// IP-Zugriffsprüfung
// ------------------------------
function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    return ($ip & $mask) === ($subnet & $mask);
}

$allowed_ips = [
    '134.130.0.0/23',
    '137.226.141.200',
    '137.226.141.203'
];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$access_granted = false;

foreach ($allowed_ips as $range) {
    if (ip_in_range($client_ip, $range)) {
        $access_granted = true;
        break;
    }
}

if (!$access_granted) {
    header('HTTP/1.1 401 Unauthorized');
    echo 'Kein Zugriff.';
    exit;
}

// ------------------------------
// Status-Definitionen
// ------------------------------
$status = [
    -1 => ['label' => 'Storniert', 'color' => '#a10000'],      // rot
     0 => ['label' => 'Vorgemerkt', 'color' => '#aaaaaa'],     // hellgrau
     1 => ['label' => 'Bestellt', 'color' => '#4e9fff'],       // blau
     2 => ['label' => 'In Installation', 'color' => '#e0c000'],// gelb
     3 => ['label' => 'Pausiert', 'color' => '#666666'],       // grau
     4 => ['label' => 'Updates ausstehend', 'color' => '#8cc63f'], // gelbgrün
     5 => ['label' => 'Bereit zur Ausgabe', 'color' => '#66cc99'], // hellgrün
     6 => ['label' => 'Rückgabe ausstehend', 'color' => '#669999'], // grüngrau
     7 => ['label' => 'Altgerät ausstehend', 'color' => '#669999'],
     8 => ['label' => 'Abgeschlossen', 'color' => '#3b7d3b']   // dunkelgrün
];

// ------------------------------
// Mitarbeiter-Status
// ------------------------------
$mastatus = [
     0 => 'Neuer Mitarbeiter',
     1 => 'Neuer HiWi',
     2 => 'Neuer Azubi',
     3 => 'Praktikant',
     4 => 'Bestehender Mitarbeiter',
     5 => 'Bestehender HiWi',
     6 => 'Bestehender Azubi',
     7 => 'IT-Koordinator',
     8 => 'Undefiniert'
];

// ------------------------------
// Abteilungen
// ------------------------------
$abteilungen = [
    'SeKo', 'FPO', 'SuB', 'RPDM', 'PDSL', 'Netze', 'CSE', 'ITSSM', 'Stabsstelle',
    'SeKo Marie', 'SeKo WiPro', 'SeKo IT-Admin', 'SeKo Security',
    'CSE HPC', 'CSE MATSE', 'CSE VR', 'CSE VIS',
    'SuB ABC', 'SuB IDM', 'SuB Anwendungsentwicklung', 'SuB SSH',
    'Netze Kommunikation', 'Netze NOC', 'Netze Planung', 'Netze Organisation', 'Netze Support'
];

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


?>

