<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
        <style>
            /* Darkmode Dropdown */
            #daySelect {
                background-color: #111;
                color: #ffffffff;
                border: 1px solid #11a50d;
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 14px;
                cursor: pointer;
            }
            #daySelect option {
                background-color: #111;
                color: #ffffffff;
            }
            #daySelect:focus {
                outline: none;
                border-color: #11a50d;
                box-shadow: 0 0 6px #11a50d;
            }
        </style>
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (!auth($conn) && !($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["WohnzimmerAG"])) {
  header("Location: denied.php");
  exit;
}
load_menu();

// Alle vorhandenen Zeitstempel holen
$data = [];
$res = $conn->query("SELECT ts_minute, mean_dbfs, max_dbfs FROM room_noise_minute ORDER BY ts_minute ASC");
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}
$res->free_result();
$conn->close();

// Tage berechnen, Beginn jeweils 06:00
$days = [];
foreach ($data as $row) {
    $ts = strtotime($row["ts_minute"]);
    // Tag wechselt 06:00; alles <06:00 zählt noch zum Vortag
    $dayKey = date("Y-m-d", $ts - (date("H", $ts) < 6 ? 24*3600 : 0));
    $days[$dayKey][] = $row;
}
// Keys DESC sortieren (neueste zuerst)
$dayKeys = array_keys($days);
rsort($dayKeys);
?>
<body>
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;">
        <h2 style="color:white;text-align:center;">Wohnzimmer - Lärmverlauf</h2>

        <select id="daySelect">
            <?php foreach ($dayKeys as $d) { ?>
                <option value="<?php echo $d; ?>"><?php echo date("d.m.Y", strtotime($d)); ?></option>
            <?php } ?>
        </select>

        <div style="width:100%;max-width:1000px;margin-top:15px;">
            <canvas id="noiseChart" style="width:100%;height:500px;"></canvas>
        </div>
    </div>

    <script>
    // schwelle zentral definieren
    const threshold = -40;

    // php -> js
    const allData = <?php echo json_encode($days); ?>;

    // helper: date-range für ausgewählten "tag" (06:00 bis +24h)
    function dayRange(dayKey) {
        // Browser-Zeit: ISO ohne TZ = lokal; ausreichend hier
        const start = new Date(dayKey + 'T06:00:00');
        const end   = new Date(start.getTime() + 24*60*60*1000);
        return {start, end};
    }

    const ctx = document.getElementById('noiseChart').getContext('2d');
    let noiseChart = new Chart(ctx, {
        type: 'line',
        data: { datasets: [] },
        options: {
            responsive: true,
            parsing: false, // wir liefern {x:..., y:...}
            plugins: {
                legend: { labels: { color: '#fff' } },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            if (!items.length) return '';
                            const d = new Date(items[0].parsed.x);
                            const hh = d.getHours().toString().padStart(2,'0');
                            const mm = d.getMinutes().toString().padStart(2,'0');
                            return hh + ':' + mm;
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'hour',
                        displayFormats: { hour: 'HH' } // nur stunden anzeigen
                    },
                    ticks: {
                        color: '#fff',
                        autoSkip: false,
                        maxRotation: 0,
                        minRotation: 0
                    },
                    grid: { color: 'rgba(255,255,255,0.08)' },
                    title: { display: true, text: 'Zeit', color: '#fff' }
                },
                y: {
                    title: { display: true, text: 'dBFS', color: '#fff' },
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255,255,255,0.08)' }
                }
            }
        }
    });

    function loadDay(dayKey) {
        const {start, end} = dayRange(dayKey);
        const rows = (allData[dayKey] || []).filter(r => {
            const t = new Date(r.ts_minute.replace(' ', 'T'));
            return t >= start && t < end;
        });

        // datenpunkte als {x,y}, nur linie (keine punkte)
        const meanPoints = rows.map(r => ({ x: new Date(r.ts_minute.replace(' ', 'T')), y: parseFloat(r.mean_dbfs) }));
        const maxPoints  = rows.map(r => ({ x: new Date(r.ts_minute.replace(' ', 'T')), y: parseFloat(r.max_dbfs) }));

        // schwellenlinie: nur 2 punkte (zieht waagerechte linie über gesamten bereich)
        const thresholdLine = [
            { x: start, y: threshold },
            { x: end,   y: threshold }
        ];

        noiseChart.options.scales.x.min = start;
        noiseChart.options.scales.x.max = end;

        // reihenfolge: zuerst daten, zuletzt schwelle -> schwelle oben
        noiseChart.data.datasets = [            
            {
                label: 'Schwellenwert',
                data: thresholdLine,
                borderColor: 'white',
                borderDash: [6,6],
                pointRadius: 0,
                fill: false,
                tension: 0,
                borderWidth: 2
            },
            {
                label: 'Durchschnitt',
                data: meanPoints,
                borderColor: '#0A6307',
                backgroundColor: '#0A6307',
                pointRadius: 0,
                tension: 0.1
            },
            {
                label: 'Spitze',
                data: maxPoints,
                borderColor: '#11a50d',
                backgroundColor: '#11a50d',
                pointRadius: 0,
                tension: 0.1
            },
        ];

        noiseChart.update();
    }

    const select = document.getElementById('daySelect');
    loadDay(select.value);
    select.addEventListener('change', () => loadDay(select.value));
    </script>
</body>
</html>
