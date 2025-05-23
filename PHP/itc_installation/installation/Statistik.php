<?php
require_once("../template.php");

$jahr = isset($_GET['jahr']) && is_numeric($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$monatsnamen = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];

// Hauptdaten
$daten = array_fill(1, 12, 0);
$max = 1;

function hole_daten($conn, $jahr) {
    $start = "$jahr-01-01";
    $ende = "$jahr-12-31";
    $query = "SELECT MONTH(datum) AS monat, COUNT(*) AS anzahl FROM installation WHERE datum BETWEEN ? AND ? GROUP BY monat";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'ss', $start, $ende);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $out = array_fill(1, 12, 0);
    while ($row = mysqli_fetch_assoc($result)) {
        $out[(int)$row['monat']] = (int)$row['anzahl'];
    }
    return $out;
}

// Aktuelles Jahr
$daten = hole_daten($conn, $jahr);
$max = max($daten);

// Vorjahr
$vorjahr = ($jahr > 2024) ? $jahr - 1 : null;
$vorjahrdaten = $vorjahr ? hole_daten($conn, $vorjahr) : [];
if ($vorjahr) {
    $max = max($max, max($vorjahrdaten));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Installation Statistik</title>
    <link rel="stylesheet" href="../ITA.css">
    <link rel="stylesheet" href="../HEADER.css">
    <link rel="stylesheet" href="../LIST.css">
    <link rel="stylesheet" href="INSTALLATION.css">
    <link rel="icon" type="image/png" href="../favicon.png">
</head>
<body>
<?php
include("../header.php");
?>

<div class="list-header">
    <form method="get" class="jahr-selector">
        <select name="jahr" id="jahr" onchange="this.form.submit()">
            <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $jahr ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<?php if ($vorjahr): ?>
<div class="chart-legend">
    <span><span class="color-box farbe-aktuell"></span> <?= $jahr ?></span>
    <span><span class="color-box farbe-vorjahr"></span> <?= $vorjahr ?></span>
</div>
<?php endif; ?>

<div class="container" style="margin-top: 30px;">
    <div class="bar-chart">
        <?php foreach ($daten as $monat => $anzahl): 
            $höhe = round(($anzahl / $max) * 250);
            $höheAlt = $vorjahr ? round(($vorjahrdaten[$monat] / $max) * 250) : 0;
        ?>
        <div class="bar">
            <?php if ($vorjahr): ?>
                <div class="bar-rect-prev" style="height: <?= $höheAlt ?>px;"></div>
            <?php endif; ?>
            <div class="bar-value"><?= $anzahl > 0 ? $anzahl : '' ?></div>
            <div class="bar-rect" style="height: <?= $höhe ?>px;"></div>
            <div class="bar-label"><?= $monatsnamen[$monat - 1] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>