<?php
require_once("../template.php"); // Lädt auch functions.php und DB-Verbindung

// Konfiguration
$config = [
    'table' => 'installation',
    'title' => 'Installation Archiv',
    'columns' => ['Status', 'Ticket', 'Datum', 'Neugerät', 'Name', 'Abteilung', 'MA-Status', 'Altgerät', 'Dock', 'Monitor'],
    'fields' => [
        'status'     => fn($r) => htmlspecialchars($GLOBALS['status'][$r['status']]['label'] ?? '-'),
        'ticket'     => fn($r) => htmlspecialchars($r['ticket']),
        'datum'      => function ($r) {
            $out = '';
            if (!empty($r['datum'])) {
                $d = DateTime::createFromFormat('Y-m-d', $r['datum']);
                $out .= $d ? $d->format('d.m.Y') : htmlspecialchars($r['datum']);
            }
            if (!empty($r['zeit'])) {
                $out .= ' ' . substr($r['zeit'], 0, 5);
            }
            return $out;
        },
        'neugerät'   => fn($r) => htmlspecialchars($r['neugerät']),
        'name'       => fn($r) => htmlspecialchars($r['name']),
        'abteilung'  => fn($r) => htmlspecialchars($r['abteilung']),
        'mastatus'   => fn($r) => htmlspecialchars($GLOBALS['mastatus'][$r['mastatus']] ?? '-'),
        'altgerät'   => fn($r) => htmlspecialchars($r['altgerät']),
        'dock'       => fn($r) => htmlspecialchars($r['dock']),
        'monitor'    => fn($r) => htmlspecialchars($r['monitor']),
    ]
];

// Daten laden
$jahr = $_GET['jahr'] ?? date('Y');
$result = fetchListByYear($conn, $config['table'], $jahr);
$rows = iterator_to_array($result);

// Zeilenrenderer dynamisch aus config
$rowRenderer = function ($row) use ($config) {
    ob_start();
    echo "<tr class='clickable-row' data-id='{$row['id']}'>";
    foreach ($config['fields'] as $field => $callback) {
        echo "<td>" . $callback($row) . "</td>";
    }
    echo "</tr>";
    return ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?= $config['title'] ?></title>
    <link rel="stylesheet" href="../ITA.css">
    <link rel="stylesheet" href="../HEADER.css">
    <link rel="stylesheet" href="../LIST.css">
    <link rel="icon" type="image/png" href="../favicon.png">
</head>
<body>

<?php include("../header.php"); ?>

<div class="list-header">
    <input type="text" id="table-search" placeholder="🔍 Suche..." class="table-search">

    <div class="list-header-right">
        <?php renderYearSelect($jahr); ?>
        <button class="csv-export-button" onclick="downloadCSV()">📄 CSV</button>
    </div>
</div>


<div class="list-table-container">
    <?php renderListTable($rows, $config['columns'], $rowRenderer); ?>
</div>
<script>
    window.listConfig = {
        table: <?= json_encode($config['table']) ?>
    };
</script>
<script src="../list.js" defer></script>

</body>
</html>
