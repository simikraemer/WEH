<?php
session_start();

$kassen = [
    'netz1' => ['name' => 'Netzbarkasse I', 'id' => 1],
    'netz2' => ['name' => 'Netzbarkasse II', 'id' => 2],
    'haus1' => ['name' => 'Kassenwart I', 'id' => 93],
    'haus2' => ['name' => 'Kassenwart II', 'id' => 94],
];

$werte = [500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1, 0.05, 0.02, 0.01];

$active = $_GET['tab'] ?? 'netz1';
if (!isset($kassen[$active])) $active = 'netz1';

require('template.php');
mysqli_set_charset($conn, "utf8");
if (!(auth($conn) && $_SESSION['valid'] && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION['Kassenpruefer']))) {
    header("Location: denied.php");
    exit;
}
load_menu();

// Kassenstand laden
$kontostand = berechneKontostand($conn, $kassen[$active]['id']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        body { color: white; text-align: center; }

        .tabs {
            margin: 0px auto;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

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

        .vergleich-wrapper {
            margin: 30px auto;
            display: flex;
            justify-content: center;
        }

        .vergleich-box {
            display: flex;
            gap: 40px;
            background-color: #1d1d1d;
            padding: 10px 20px;
            border: 1px solid #333;
            border-radius: 12px;
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.3);
        }

        .vergleich-box div {
            font-size: 16px;
            color: #ddd;
            text-align: center;
        }

        .vergleich-box span {
            display: block;
            margin-top: 5px;
            font-size: 20px;
            font-weight: bold;
        }

        .status-richtig {
            color: #22c55e;
        }

        .status-falsch {
            color: #e11d48;
        }


        .gesamt-summe {
            font-size: 50px;
            margin: 20px auto;
            font-weight: bold;
            color: #11a50d;
        }

        .bargeld-table {
            width: 50%;
            margin: 0 auto 30px auto;
            border-collapse: collapse;
            border: 1px solid #444;
            table-layout: fixed;
        }

        .bargeld-table th, .bargeld-table td {
            border: 1px solid #333;
            padding: 2px;
            text-align: center;
            font-size: 15px;
            width: 33%;
        }

        .bargeld-table tr:nth-child(even) { background-color: #1f1f1f; }
        .bargeld-table tr:nth-child(odd) { background-color: #1c1c1c; }
        .bargeld-table tr:hover { background-color: #2c2c2c; }

        input.bargeld-input {
            width: 70px;
            padding: 6px 8px;
            font-size: 16px;
            background-color: #2a2a2a;
            border: 1px solid #555;
            color: #fff;
            text-align: right;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="tabs">
<?php foreach ($kassen as $key => $info): ?>
    <button class="tab-button <?= $active === $key ? 'active' : '' ?>" onclick="wechselTab('<?= $key ?>')"><?= $info['name'] ?></button>
<?php endforeach; ?>
</div>

<div class="vergleich-wrapper">
    <div class="vergleich-box">
        <div><strong>Soll:</strong><br><span id="vergleich_soll"><?= number_format($kontostand, 2, ',', '.') ?> €</span></div>
        <div><strong>Differenz:</strong><br><span id="vergleich_diff">0,00 €</span></div>
        <div><strong>Ist:</strong><br><span id="vergleich_ist">0,00 €</span></div>
        <div><strong>Korrekt:</strong><br><span id="vergleich_status" class="status-falsch">❌</span></div>
    </div>
</div>


<!-- <div class="gesamt-summe" id="gesamtwert">0,00 €</div> -->

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
const kassen = <?= json_encode(array_keys($kassen)) ?>;
const werte = <?= json_encode(array_map(fn($v) => str_replace('.', '_', $v), $werte)) ?>;
let daten = {};
let aktiverTab = "<?= $active ?>";
const sollwert = <?= $kontostand ?>;

// Initialstruktur bauen
kassen.forEach(tab => {
    daten[tab] = {};
    werte.forEach(w => {
        daten[tab][w] = "0";
    });
});

function wechselTab(tab) {
    speichernAktiveWerte();
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.location.href = url.toString();
}

function speichernAktiveWerte() {
    werte.forEach(w => {
        const feld = document.getElementById('anzahl_' + w);
        if (feld) daten[aktiverTab][w] = feld.value;
    });
    localStorage.setItem("kassen_daten", JSON.stringify(daten));
}

function ladeWerteAusStorage() {
    const gespeichert = localStorage.getItem("kassen_daten");
    if (gespeichert) {
        try {
            const parsed = JSON.parse(gespeichert);
            kassen.forEach(tab => {
                if (!(tab in parsed)) return;
                werte.forEach(w => {
                    daten[tab][w] = parsed[tab][w] ?? "0";
                });
            });
        } catch (e) {
            console.warn("Fehler beim Laden von localStorage:", e);
        }
    }
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

    const ist = Math.round(gesamt * 100) / 100;
    const soll = Math.round(sollwert * 100) / 100;
    const diff = Math.round((ist - soll) * 100) / 100;

    document.getElementById('vergleich_ist').textContent = ist.toFixed(2).replace('.', ',') + " €";
    document.getElementById('vergleich_diff').textContent = diff.toFixed(2).replace('.', ',') + " €";

    const status = document.getElementById('vergleich_status');
    if (Math.abs(diff) <= 0.01) {
        status.textContent = "✔️";
        status.classList.remove('status-falsch');
        status.classList.add('status-richtig');
    } else {
        status.textContent = "❌";
        status.classList.remove('status-richtig');
        status.classList.add('status-falsch');
    }
}

function updateWert(w) {
    const feld = document.getElementById('anzahl_' + w);
    daten[aktiverTab][w] = feld.value;
    speichernAktiveWerte(); // sofort nach Eingabe speichern
    aktualisiereAnzeige();
}

window.onload = () => {
    ladeWerteAusStorage();
    aktualisiereAnzeige();
};

</script>

</body>
</html>
