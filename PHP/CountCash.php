<?php
session_start();

$kassen = ['netz1', 'netz2', 'haus1', 'haus2'];
$namen = [
    'netz1' => 'Netzkasse 1',
    'netz2' => 'Netzkasse 2',
    'haus1' => 'Hauskasse 1',
    'haus2' => 'Hauskasse 2'
];

$werte = [500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1, 0.05, 0.02, 0.01];

$active = $_GET['tab'] ?? 'netz1';
if (!in_array($active, $kassen)) $active = 'netz1';

require('template.php');
mysqli_set_charset($conn, "utf8");
if (!(auth($conn) && $_SESSION['valid'] && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION['Kassenpruefer']))) {
    header("Location: denied.php");
    exit;
}
load_menu();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        body { color: white; text-align: center; }
        .tabs { margin: 20px auto; display: flex; justify-content: center; gap: 10px; }
        .tab-button {
            padding: 8px 16px;
            background-color: #222;
            border: 1px solid #555;
            color: white;
            cursor: pointer;
        }
        .tab-button.active {
            background-color: #166534;
            border-color: #11a50d;
        }
        .gesamt-summe {
            font-size: 50px;
            margin: 20px auto;
            font-weight: bold;
            color: #11a50d;
            font-family: 'Courier New', monospace;
        }
        .bargeld-table {
            width: 50%; margin: 0 auto 30px auto;
            border-collapse: collapse; border: 1px solid #444;
            table-layout: fixed;
        }
        .bargeld-table th, .bargeld-table td {
            border: 1px solid #333; padding: 10px;
            text-align: center; font-size: 20px; width: 33%;
        }
        .bargeld-table tr:nth-child(even) { background-color: #1f1f1f; }
        .bargeld-table tr:nth-child(odd) { background-color: #1c1c1c; }
        .bargeld-table tr:hover { background-color: #2c2c2c; }
        input.bargeld-input {
            width: 70px; padding: 6px 8px; font-size: 16px;
            background-color: #2a2a2a; border: 1px solid #555;
            color: #fff; text-align: right; border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="tabs">
<?php foreach ($kassen as $key): ?>
    <button class="tab-button <?= $active === $key ? 'active' : '' ?>" onclick="wechselTab('<?= $key ?>')"><?= $namen[$key] ?></button>
<?php endforeach; ?>
</div>
<div class="gesamt-summe" id="gesamtwert">0,00 €</div>
<table class="bargeld-table">
    <thead>
        <tr><th>Betrag</th><th>Anzahl</th><th>Teilsumme</th></tr>
    </thead>
    <tbody>
<?php foreach ($werte as $wert):
    $key = str_replace('.', '_', $wert);
?>
<tr>
    <td><?= number_format($wert, 2, ',', '.') ?> €</td>
    <td><input type="text" id="anzahl_<?= $key ?>" class="bargeld-input" data-wert="<?= $wert ?>" oninput="updateWert('<?= $key ?>')"></td>
    <td id="ts_wert_<?= $key ?>">0,00 €</td>
</tr>
<?php endforeach; ?>
    </tbody>
</table>
<script>
const kassen = <?= json_encode($kassen) ?>;
const werte = <?= json_encode(array_map(fn($v) => str_replace('.', '_', $v), $werte)) ?>;
let daten = {};
let aktiverTab = "<?= $active ?>";

// Initialisieren
kassen.forEach(tab => {
    daten[tab] = {};
    werte.forEach(w => daten[tab][w] = "0");
});

function wechselTab(tab) {
    speichernAktiveWerte();
    aktiverTab = tab;
    aktualisiereAnzeige();
    document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

function speichernAktiveWerte() {
    werte.forEach(w => {
        const feld = document.getElementById('anzahl_' + w);
        if (feld) daten[aktiverTab][w] = feld.value;
    });
}

function aktualisiereAnzeige() {
    let gesamt = 0;
    werte.forEach(w => {
        const input = document.getElementById('anzahl_' + w);
        const wert = parseFloat(input.dataset.wert);
        const anzahl = parseInt(daten[aktiverTab][w]) || 0;
        input.value = daten[aktiverTab][w];
        const teil = anzahl * wert;
        document.getElementById('ts_wert_' + w).textContent = teil.toFixed(2).replace('.', ',') + ' €';
        gesamt += teil;
    });
    document.getElementById('gesamtwert').textContent = gesamt.toFixed(2).replace('.', ',') + ' €';
}

function updateWert(w) {
    const feld = document.getElementById('anzahl_' + w);
    daten[aktiverTab][w] = feld.value;
    aktualisiereAnzeige();
}

window.onload = () => {
    aktualisiereAnzeige();
};
</script>
</body>
</html>
