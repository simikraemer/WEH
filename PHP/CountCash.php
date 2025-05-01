<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        body {
            color: white;
            text-align: center;
        }

        h1 {
            margin-top: 30px;
            margin-bottom: 20px;
            font-size: 28px;
            color: white;
        }

        .gesamt-summe {
            font-size: 50px;
            margin: 20px auto;
            font-weight: bold;
            color: #11a50d;
            font-family: 'Courier New', monospace;
        }

        .bargeld-table {
            width: 50%;
            margin: 0 auto 30px auto;
            border-collapse: collapse;
            border: 1px solid #444;
            color: white;
            table-layout: fixed;
        }

        .bargeld-table th {    
            border: 1px solid #333;
            padding: 10px;
            text-align: center;        
            font-size: 30px;
            background-color: #111;
        }

        .bargeld-table td {
            border: 1px solid #333;
            padding: 10px;
            text-align: center;
            font-size: 20px;
        }

        .bargeld-table tr:nth-child(even) {
            background-color: #1f1f1f;
        }
        .bargeld-table tr:nth-child(odd) {
            background-color: #1c1c1c;
        }
        .bargeld-table tr:hover {
            background-color: #2c2c2c;
            cursor: default;
        }

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

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

// 🔐 Zugriffsschutz
$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        !empty($_SESSION["NetzAG"])
        || !empty($_SESSION["Vorstand"])
        || !empty($_SESSION["Kassenpruefer"])
    );

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

load_menu();
?>

<div class="gesamt-summe">
    <span id="gesamtwert">0,00 €</span>
</div>

<table class="bargeld-table">
    <thead>
        <tr>
            <th>Betrag</th>
            <th>Anzahl</th>
            <th>Teilsumme</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $werte = [500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1, 0.05, 0.02, 0.01];
        foreach ($werte as $wert):
            $id = 'wert_' . str_replace('.', '_', $wert);
        ?>
            <tr>
                <td><?= number_format($wert, 2, ',', '.') ?> €</td>
                <td><input type="text" value="0" class="bargeld-input" data-wert="<?= $wert ?>" oninput="berechneSumme()"></td>
                <td id="ts_<?= $id ?>" class="teilsumme">0,00 €</td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
function berechneSumme() {
    const inputs = document.querySelectorAll('.bargeld-input');
    let gesamt = 0;

    inputs.forEach(input => {
        const anzahl = parseInt(input.value) || 0;
        const wert = parseFloat(input.dataset.wert);
        const teilsumme = anzahl * wert;
        gesamt += teilsumme;

        const id = 'ts_wert_' + input.dataset.wert.replace('.', '_');
        const zelle = document.getElementById(id);
        if (zelle) {
            zelle.textContent = teilsumme.toFixed(2).replace('.', ',') + ' €';
        }
    });

    document.getElementById('gesamtwert').textContent = gesamt.toFixed(2).replace('.', ',') + ' €';
}
</script>

</body>
</html>
