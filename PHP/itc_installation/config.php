<?php
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
$mastatus = [];
$res = mysqli_query($conn, "SELECT id, label FROM ma_status ORDER BY id ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $mastatus[(int)$row['id']] = $row['label'];
}

// ------------------------------
// Abteilungen
// ------------------------------
$abteilungen = [];
$res = mysqli_query($conn, "SELECT name FROM abteilungen ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $abteilungen[] = $row['name'];
}

// ------------------------------
// Admins
// ------------------------------
$admins = [];
$res = mysqli_query($conn, "SELECT name FROM administratoren ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $admins[] = $row['name'];
}

$DBtableConfig = [
    'installation' => [
        'fields' => [
            'status', 'ticket', 'datum', 'zeit', 'neugerät', 'name', 'abteilung', 'mastatus',
            'altgerät', 'dock', 'monitor', 'software', 'notiz',
            'prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios', 'prog_software',
            'prog_updates', 'prog_dock', 'prog_monitor', 'prog_ausgabe', 'prog_altgerät'
        ],
        'timestamps' => [
            'prog_sp', 'prog_dhcp', 'prog_pxe', 'prog_bios', 'prog_software',
            'prog_updates', 'prog_dock', 'prog_monitor', 'prog_ausgabe', 'prog_altgerät'
        ],
        'redirect' => 'installation/Installation.php'
    ],
    // weitere Tabellen …
];
