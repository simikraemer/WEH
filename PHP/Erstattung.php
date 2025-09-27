<?php
  session_start();

?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">

        
    <style>
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: sans-serif;
        }

        .form-wrapper {
            max-width: 600px;
            margin: 3em auto;
            padding: 2em;
            background-color: #1c1c1c;
            border: 2px solid #11a50d;
            border-radius: 8px;
        }

        .form-wrapper h1 {
            color: #11a50d;
            font-size: 1.8em;
            margin-bottom: 0.5em;
        }

        .form-wrapper p {
            color: #cccccc;
            margin-bottom: 1em;
        }

        .form-wrapper ul {
            margin-top: 0.1em;
            margin-bottom: 1.5em;
            padding-left: 1.2em;
        }

        .form-wrapper label {
            display: block;
            margin-top: 1em;
            margin-bottom: 0.3em;
            color: #a0ffa0;
        }

        .form-wrapper input[type="file"],
        .form-wrapper input[type="text"],
        .form-wrapper input[type="number"],
        .form-wrapper select,
        .form-wrapper button {
            width: 100%;
            padding: 0.7em;
            margin-bottom: 1em;
            background-color: #252525;
            border: 1px solid #11a50d;
            color: #e0ffe0;
            border-radius: 4px;
        }

        .form-wrapper button {
            background-color: #11a50d;
            color: #000;
            font-weight: bold;
            transition: background-color 0.2s ease;
        }

        .form-wrapper button:hover {
            background-color: #0e8c0b;
        }
    </style>
    </head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

// 🔐 Zugriffsrecht
$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        (!empty($_SESSION['Vorstand']) && $_SESSION['Vorstand'] > 0)
    );

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}


if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    
    if (isset($_POST['action'], $_POST['request_id']) && $_POST['action'] === 'accept') {
        $reqId = intval($_POST['request_id']);
        
        // 1) Fetch Antrag-Daten
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.username, u.name, u.turm, e.einrichtung, e.betrag, e.iban, e.pfad
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ?
        ");
        mysqli_stmt_bind_param($stmt, "i", $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $userUid, $username, $name, $turm, $einrichtung, $betrag, $iban, $pfad);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $einrichtung = formatEinrichtung($einrichtung, $conn);

        // 2) Update status = 1
        $upd = mysqli_prepare($conn, "
            UPDATE erstattung
            SET status = 1
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd, "i", $reqId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // 3) Transfer-Eintrag anlegen
        $transferUid   = 472;                    // Dummy-Konto für Vorstand
        $ts            = time();
        $beschreibung  = sprintf(
            "Erstattung: %s, IBAN: %s",
            $einrichtung,
            $iban
        );
        $konto         = 8;
        $kasse         = 92;
        $neg_betrag    = -1 * $betrag;
        $insert = mysqli_prepare($conn, "
            INSERT INTO transfers
                (uid, tstamp, beschreibung, konto, kasse, betrag, pfad)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param(
            $insert,
            "iisiids",
            $transferUid,
            $ts,
            $beschreibung,
            $konto,
            $kasse,
            $neg_betrag,
            $pfad
        );
        mysqli_stmt_execute($insert);
        mysqli_stmt_close($insert);

        // 4) E-Mail an Nutzer
        $to      = $username . '@' . $turm . '.rwth-aachen.de';
        $subject = "Ihr Erstattungsantrag wurde genehmigt";
        $message = "Sehr geehrte/r $name,\n\n"
                . "Ihr Erstattungsantrag über "
                . number_format($betrag,2,',','.') . " € wurde soeben überwiesen.\n"
                . "IBAN: $iban\n"
                . "Einrichtung: $einrichtung\n\n"
                . "Mit freundlichen Grüßen\n"
                . "WEH Vorstand";
        $headers  = "From: " . $mailconfig['address'] . "\r\n";
        $headers .= "Reply-To: kasse@weh.rwth-aachen.de\r\n";
        mail($to, $subject, $message, $headers);

    } elseif (isset($_POST['action'], $_POST['request_id']) && $_POST['action'] === 'decline') {
        $reqId = intval($_POST['request_id']);
    
        // 1) Fetch uid, um user-Daten zu holen
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.username, u.name, u.turm
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ?
        ");
        mysqli_stmt_bind_param($stmt, "i", $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $username, $name, $turm);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        // 2) Update status = -1
        $upd = mysqli_prepare($conn, "
            UPDATE erstattung
            SET status = -1
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd, "i", $reqId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // 3) E-Mail an Nutzer
        $to      = $username . '@' . $turm . '.rwth-aachen.de';
        $subject = "Ihr Erstattungsantrag wurde abgelehnt";
        $message = "Sehr geehrte/r $name,\n\n"
                . "Ihr Erstattungsantrag wurde leider abgelehnt.\n"
                . "Bei weiteren Fragen wenden Sie sich bitte per E-Mail an den Vorstand "
                . "(vorstand@weh.rwth-aachen.de) oder besuchen Sie unser nächstes Vorstandstreffen.\n\n"
                . "Mit freundlichen Grüßen\n"
                . "WEH Vorstand";
        $headers  = "From: " . $mailconfig['address'] . "\r\n";
        $headers .= "Reply-To: kasse@weh.rwth-aachen.de\r\n";

        mail($to, $subject, $message, $headers);

    }

    // Done
        echo '<div style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0, 0, 0, 0.85);
        padding: 1em 2em;
        color: white;
        font-size: 2em;
        font-weight: bold;
        border: 2px solid #11a50d;
        border-radius: 8px;
        z-index: 9999;
        box-shadow: 0 0 10px #11a50d;
    ">
        Verarbeitet.
    </div>';
    
    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
        setTimeout(function() {
        document.forms['reload'].submit();
        }, 2000);
    </script>";
}

load_menu();

if (isset($_POST['view_request'])):
    $id = intval($_POST['view_request']);
    $sql = "
        SELECT e.id, e.uid, u.name, e.tstamp, e.einrichtung, e.betrag, e.iban, e.pfad, u.username, u.turm
        FROM erstattung e
        JOIN users u ON e.uid = u.uid
        WHERE e.id = ? AND e.status = 0
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = get_result($stmt);
    $req = array_shift($res);
    mysqli_stmt_close($stmt);

    ?>

    <!-- Overlay -->
    <div class="overlay" style="
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.7); z-index: 1000;
    "></div>

    <div class="modal" style="
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        background: #1c1c1c; padding: 2em; border: 2px solid #11a50d;
        border-radius: 8px; z-index: 1001; min-width: 400px;
    ">
        <!-- Close Button -->
        <form method="post" style="text-align: right; margin-bottom: 1em;">
            <button type="submit" name="close_modal" value="1" style="
                background: transparent; border: none; color: #fff; font-size: 1.2em;
                cursor: pointer;
            ">✕</button>
        </form>

        <?php
            // Nutzer-Email bauen
            $mailto = $req['username'] . '@' . $req['turm'] . '.rwth-aachen.de';
            // formatiertes Einrichtung-Label
            $formatted = formatEinrichtung($req['einrichtung'], $conn);
        ?>

        <div style="color: #e0ffe0; font-size: 1em; margin-bottom: 1em;">
            
            <div style="display: flex; gap: 0.5em; margin-bottom: 1em;">
                <!-- Link-Button Rechnung -->
                <a href="<?= htmlspecialchars($req['pfad'], ENT_QUOTES, 'UTF-8') ?>"
                target="_blank"
                style="
                    flex: 1;
                    padding: 0.5em;
                    background: #252525;
                    border: 1px solid #11a50d;
                    color: #fff;
                    font-weight: bold;
                    text-align: center;
                    text-decoration: none;
                    cursor: pointer;
                    border-radius: 4px;
                    transition: background 0.2s;
                "
                onmouseover="this.style.background='#11a50d';"
                onmouseout="this.style.background='#252525';"
                >Rechnung ansehen</a>

                <!-- Mailto-Button -->
                <a href="mailto:<?= htmlspecialchars($mailto, ENT_QUOTES, 'UTF-8') ?>"
                style="
                    flex: 1;
                    padding: 0.5em;
                    background: #252525;
                    border: 1px solid #11a50d;
                    color: #fff;
                    font-weight: bold;
                    text-align: center;
                    text-decoration: none;
                    cursor: pointer;
                    border-radius: 4px;
                    transition: background 0.2s;
                "
                onmouseover="this.style.background='#11a50d';"
                onmouseout="this.style.background='#252525';"
                >Mail an Nutzer</a>
            </div>

            <!-- Copy-Buttons in neuer Reihenfolge -->
            <button type="button" onclick="copyToClipboard(this,'<?= htmlspecialchars($req['name'], ENT_QUOTES, 'UTF-8') ?>')" style="
                display: block; width: 100%; margin-bottom: 0.5em; padding: 0.5em;
                background: #252525; border: 1px solid #11a50d; color: #fff; font-weight: bold;
                cursor: pointer;
            "><?= htmlspecialchars($req['name'], ENT_QUOTES, 'UTF-8') ?></button>

            <button type="button" onclick="copyToClipboard(this,'<?= htmlspecialchars($req['iban'], ENT_QUOTES, 'UTF-8') ?>')" style="
                display: block; width: 100%; margin-bottom: 0.5em; padding: 0.5em;
                background: #252525; border: 1px solid #11a50d; color: #fff; font-weight: bold;
                cursor: pointer;
            "><?= htmlspecialchars($req['iban'], ENT_QUOTES, 'UTF-8') ?></button>

            <button type="button" onclick="copyToClipboard(this,'<?= number_format($req['betrag'],2,',','.') ?> €')" style="
                display: block; width: 100%; margin-bottom: 0.5em; padding: 0.5em;
                background: #252525; border: 1px solid #11a50d; color: #fff; font-weight: bold;
                cursor: pointer;
            "><?= number_format($req['betrag'],2,',','.') ?> €</button>

            <button type="button" onclick="copyToClipboard(this,'<?= htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8') . ' Erstattung' ?>')" style="
                display: block; width: 100%; margin-bottom: 1em; padding: 0.5em;
                background: #252525; border: 1px solid #11a50d; color: #fff; font-weight: bold;
                cursor: pointer;
            "><?= htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8') . ' Erstattung' ?></button>
        </div>

        <!-- Accept/Decline Formular -->
        <form method="post" style="display: flex; justify-content: space-between;">
            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
            <input type="hidden" name="reload" value="1">

            <button type="submit" name="action" value="accept" style="
                padding: 0.7em 1.5em; background: #11a50d; color: #000; font-weight: bold;
                border: none; border-radius: 4px; cursor: pointer;
            ">Überwiesen</button>

            <button type="submit" name="action" value="decline" style="
                padding: 0.7em 1.5em; background: #a50d11; color: #fff; font-weight: bold;
                border: none; border-radius: 4px; cursor: pointer;
            ">Ablehnen</button>
        </form>
    </div>
<?php endif;

// Hauptansicht: Liste offener Anträge
echo '<div class="requests-container" style="
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 1em;
        padding: 2em;
    ">';

$sql = "SELECT id, tstamp, einrichtung
        FROM erstattung
        WHERE status = 0
        ORDER BY tstamp DESC";
$res = mysqli_query($conn, $sql);

if (mysqli_num_rows($res) === 0) {
    // Keine offenen Anträge
    echo '<div style="
            width: 100%;
            text-align: center;
            color: #ffffff;
            font-size: 1.2em;
            padding: 2em 0;
        ">
        Keine neuen Anträge
    </div>';
} else {
    while ($row = mysqli_fetch_assoc($res)) {
        // Datum (ohne Uhrzeit)
        $datum = date("d.m.Y", $row['tstamp']);
        // Einrichtung formatieren
        $labelEinr = formatEinrichtung($row['einrichtung'], $conn);
        // Button-Label: Datum und Einrichtung
        $label = sprintf("%s • %s", $datum, $labelEinr);

        echo '<form method="post" style="margin:0;">
                <button type="submit" name="view_request" value="' . intval($row['id']) . '" style="
                    padding: 1em 2em;
                    background: #252525;
                    color: #e0ffe0;
                    border: 1px solid #11a50d;
                    border-radius: 4px;
                    font-weight: bold;
                    cursor: pointer;
                ">'. htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .'</button>
              </form>';
    }
}

echo '</div>';


// --- Daten für Diagramme sammeln ---

// 1) AG-Summen (nur AGs mit agessen = 1, direkte DB-Abfrage)
$agLabels = [];
$agData   = [];

$sql = "
    SELECT id, name
    FROM `groups`
    WHERE active = 1
      AND agessen = 1
    ORDER BY prio
";
$res = mysqli_query($conn, $sql);

while ($ag = mysqli_fetch_assoc($res)) {
    $agId = (int)$ag['id'];
    $name = $ag['name'];
    $agLabels[] = $name;

    // Summe aller erstatteten Beträge für diese AG
    $sumSql = "
        SELECT COALESCE(SUM(betrag),0) AS summe
        FROM erstattung
        WHERE status = 1
          AND einrichtung = 'ag:{$agId}'
    ";
    $sumRes = mysqli_query($conn, $sumSql);
    $sumRow = mysqli_fetch_assoc($sumRes);
    $agData[] = (float)$sumRow['summe'];
    mysqli_free_result($sumRes);
}

mysqli_free_result($res);


// 2) WEH-Etagen 0-17 (Format "etage:weh_<n>")
$wehSums = array_fill(0, 18, 0.0);
$sql = "
    SELECT e.einrichtung, SUM(e.betrag) AS summe
    FROM erstattung e
    WHERE e.status = 1
      AND e.einrichtung LIKE 'etage:weh\\_%'
    GROUP BY e.einrichtung
";
$res = mysqli_query($conn, $sql);
while ($r = mysqli_fetch_assoc($res)) {
    if (preg_match('/^etage:weh_(\d+)$/', $r['einrichtung'], $m)) {
        $idx = intval($m[1]);
        if ($idx >= 0 && $idx <= 17) {
            $wehSums[$idx] = (float)$r['summe'];
        }
    }
}

// 3) TVK-Etagen 0-15 (Format "etage:tvk_<n>")
$tvkSums = array_fill(0, 16, 0.0);
$sql = "
    SELECT e.einrichtung, SUM(e.betrag) AS summe
    FROM erstattung e
    WHERE e.status = 1
      AND e.einrichtung LIKE 'etage:tvk\\_%'
    GROUP BY e.einrichtung
";
$res = mysqli_query($conn, $sql);
while ($r = mysqli_fetch_assoc($res)) {
    if (preg_match('/^etage:tvk_(\d+)$/', $r['einrichtung'], $m)) {
        $idx = intval($m[1]);
        if ($idx >= 0 && $idx <= 15) {
            $tvkSums[$idx] = (float)$r['summe'];
        }
    }
}
?>


<!-- Chart.js einbinden -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Unsichtbarer, zentrierter Flex-Wrapper -->
<div style="
    display: flex;
    justify-content: center;
    margin: 2em 0;
">
    <!-- Innerer Container mit max-width und einheitlicher Chart-Höhe -->
    <div style="
        display: flex;
        flex-wrap: wrap;
        gap: 2em;
        width: 100%;
        max-width: 1500px;
        height: 250px;     /* Einmalige Höhe für alle Diagramme */
    ">
        <!-- WEH-Chart -->
        <div style="flex: 1 1 48%; height: 100%;">
            <canvas id="chartWeh" style="width:100%; height:100%;"></canvas>
        </div>
        <!-- TVK-Chart -->
        <div style="flex: 1 1 48%; height: 100%;">
            <canvas id="chartTvk" style="width:100%; height:100%;"></canvas>
        </div>
        <!-- AG-Chart, doppelte Breite -->
        <div style="flex: 1 1 100%; height: 100%;">
            <canvas id="chartAG" style="width:100%; height:100%;"></canvas>
        </div>
    </div>
</div>





<script>
// Daten aus PHP übernehmen
const agLabels = <?= json_encode($agLabels) ?>;
const agData   = <?= json_encode($agData) ?>;
const wehLabels  = Array.from({length:18}, (_,i)=>i.toString());
const wehData    = <?= json_encode(array_values($wehSums)) ?>;
const tvkLabels  = Array.from({length:16}, (_,i)=>i.toString());
const tvkData    = <?= json_encode(array_values($tvkSums)) ?>;

function makeBarChart(ctxId, labels, data, title, color) {
    new Chart(document.getElementById(ctxId).getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: "€",
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 } },
                y: { beginAtZero: true }
            },
            plugins: {
                title: { display: true, text: title },
                legend: { display: false }
            }
        }
    });
}

// Charts erzeugen mit individuellen Farben

// WEH-Chart ohne Legende
new Chart(document.getElementById('chartWeh').getContext('2d'), {
    type: 'bar',
    data: {
        labels: wehLabels,
        datasets: [
            {
                label: '€',
                data: wehData,
                backgroundColor: '#11a50d',
                borderColor: '#11a50d',
                borderWidth: 1
            },
            {
                type: 'line',
                label: 'Limit 170€',
                data: wehLabels.map(() => 170),
                borderColor: 'red',
                borderWidth: 2,
                fill: false,
                pointRadius: 0
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { ticks: { maxRotation: 45, minRotation: 0 } },
            y: { beginAtZero: true }
        },
        plugins: {
            title:    { display: true,  text: 'WEH Etagen 0-17 (€)' },
            legend:   { display: false }   // <-- Legende aus
        }
    }
});

// TVK-Chart ohne Legende
new Chart(document.getElementById('chartTvk').getContext('2d'), {
    type: 'bar',
    data: {
        labels: tvkLabels,
        datasets: [
            {
                label: '€',
                data: tvkData,
                backgroundColor: '#E49B0F',
                borderColor: '#E49B0F',
                borderWidth: 1
            },
            {
                type: 'line',
                label: 'Limit 170€',
                data: tvkLabels.map(() => 170),
                borderColor: 'red',
                borderWidth: 2,
                fill: false,
                pointRadius: 0
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { ticks: { maxRotation: 45, minRotation: 0 } },
            y: { beginAtZero: true }
        },
        plugins: {
            title:  { display: true, text: 'TVK Etagen 0-15 (€)' },
            legend: { display: false }   // <-- Legende aus
        }
    }
});
makeBarChart('chartAG',  agLabels,  agData,  'AG-Erstattungen (€)', '#007bff');

</script>

