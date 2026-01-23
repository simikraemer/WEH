<?php
    session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<?php
    require('template.php'); // liefert $conn
    mysqli_set_charset($conn, "utf8");

    if (
        auth($conn)
        && (
            $_SESSION["NetzAG"]
            || $_SESSION["Vorstand"]
            || $_SESSION["TvK-Sprecher"]
            || $_SESSION["WEH-BA"]
            || $_SESSION["TvK-BA"]
        )
    ) {
        load_menu();

        // ------------------- Kassen -------------------
        $onlinekassen = [
            ['id' => 72, 'label' => 'Netzkonto'],
            ['id' => 69, 'label' => 'PayPal'],
            ['id' => 92, 'label' => 'Hauskonto']
        ];

        $barkassen = [
            ['id' => 1, 'label' => 'Netzbarkasse I'],
            ['id' => 2, 'label' => 'Netzbarkasse II'],
            ['id' => 93, 'label' => 'Kassenwart I'],
            ['id' => 94, 'label' => 'Kassenwart II'],
            ['id' => 95, 'label' => 'Tresor']
        ];

        $allKassen = array_merge($onlinekassen, $barkassen);

        // Gruppen (Zuständigkeit)
        $groupNetzAG = [1, 2, 69, 72];
        $groupHaus   = [92, 93, 94, 95];

        // ------------------- Input -------------------
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        // view:
        // "online" => nur Online-Konten (Einzelkassen)
        // "cash"   => nur Barkassen (Einzelkassen)
        // "all"    => alle Kassen (Einzelkassen)
        // "group"  => Netz/Vorstand (Gruppenansicht)
        $view = isset($_GET['view']) ? (string)$_GET['view'] : 'online';
        if (!in_array($view, ['online', 'cash', 'all', 'group'], true)) {
            $view = 'online';
        }

        // ------------------- min/max year aus DB (min. 2023) -------------------
        $minYear = 2024;
        $maxYear = intval(date('Y'));

        $stmt = $conn->prepare("SELECT MIN(tstamp), MAX(tstamp) FROM transfers WHERE tstamp IS NOT NULL");
        if ($stmt) {
            $stmt->execute();
            $stmt->bind_result($min_ts, $max_ts);
            if ($stmt->fetch() && $min_ts !== null && $max_ts !== null) {
                $dbMinYear = intval(date('Y', intval($min_ts)));
                $dbMaxYear = intval(date('Y', intval($max_ts)));
                $minYear = max(2023, $dbMinYear);
                $maxYear = max($minYear, $dbMaxYear);
            }
            $stmt->close();
        }

        if ($year < $minYear) {
            $year = $minYear;
        }
        if ($year > $maxYear) {
            $year = $maxYear;
        }

        // ------------------- Zeitraum -------------------
        $startTs = strtotime($year . "-01-01 00:00:00");
        $nextYearStartTs = strtotime(($year + 1) . "-01-01 00:00:00");

        $currentYear = intval(date('Y'));
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $tomorrowStart = $todayStart + 86400;

        // X-Achse: IMMER ganzes Jahr (damit alle Monate da sind)
        $axisEndTsExcl = $nextYearStartTs;

        // Daten: bei aktuellem Jahr nur bis morgen (damit Graph "abbricht")
        $dataEndTsExcl = $nextYearStartTs;
        if ($year === $currentYear) {
            $dataEndTsExcl = min($nextYearStartTs, $tomorrowStart);
        }

        // ------------------- Keys/Labels fürs GANZE Jahr (Achse/Monate) -------------------
        $keys = [];
        $labels = [];
        $tsList = [];
        for ($ts = $startTs; $ts < $axisEndTsExcl; $ts += 86400) {
            $keys[] = date('Y-m-d', $ts);
            $labels[] = date('d.m.', $ts);
            $tsList[] = $ts;
        }

        // ------------------- Monatstrennlinien + Monatslabel-Positionen -------------------
        $monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        $monthLines = [];   // Indexe, an denen eine vertikale Linie (Monatswechsel) gezeichnet wird (zwischen Tagen)
        $monthLabels = [];  // ['index' => midIndex, 'label' => 'Januar', 'year' => 2024]

        if (count($tsList) > 0) {
            $firstMonth = intval(date('n', $tsList[0])); // 1..12
            $firstYear = intval(date('Y', $tsList[0]));

            $curMonth = intval(date('n', $tsList[0]));
            $curYearM = intval(date('Y', $tsList[0]));
            $monthStartIdx = 0;

            for ($i = 1; $i < count($tsList); $i++) {
                $m = intval(date('n', $tsList[$i]));
                $y = intval(date('Y', $tsList[$i]));

                if ($m !== $curMonth || $y !== $curYearM) {
                    // Monatswechsel zwischen i-1 und i -> Linie bei i
                    $monthLines[] = $i;

                    // Label in der Mitte des Monats (Start..i-1)
                    $monthEndIdx = $i - 1;
                    $mid = intval(floor(($monthStartIdx + $monthEndIdx) / 2));
                    $monthLabels[] = [
                        'index' => $mid,
                        'label' => $monthNames[$curMonth - 1],
                        'year'  => $curYearM
                    ];

                    // neuer Monat
                    $curMonth = $m;
                    $curYearM = $y;
                    $monthStartIdx = $i;
                }
            }

            // letztes (aktuelles) Monatstück
            $monthEndIdx = count($tsList) - 1;
            $mid = intval(floor(($monthStartIdx + $monthEndIdx) / 2));
            $monthLabels[] = [
                'index' => $mid,
                'label' => $monthNames[$curMonth - 1],
                'year'  => $curYearM
            ];
        }

        // ------------------- Daten holen (ohne get_result, MySQL 5.5 kompatibel) -------------------
        function fetch_start_balance_and_daily_sums($conn, $kasseIds, $startTs, $endTsExcl)
        {
            $out = ['start' => 0.0, 'daily' => []];
            if (count($kasseIds) === 0) {
                return $out;
            }

            $ph = implode(',', array_fill(0, count($kasseIds), '?'));

            // Startsaldo: alles vor Jahresanfang
            $sqlStart = "SELECT COALESCE(SUM(betrag),0)
                         FROM transfers
                         WHERE kasse IN ($ph) AND tstamp IS NOT NULL AND tstamp < ?";
            $stmt = $conn->prepare($sqlStart);
            if (!$stmt) {
                return $out;
            }

            $types = str_repeat('i', count($kasseIds)) . 'i';
            $params = array_merge($kasseIds, [$startTs]);
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);

            $stmt->execute();
            $stmt->bind_result($sumStart);
            if ($stmt->fetch()) {
                $out['start'] = floatval($sumStart);
            }
            $stmt->close();

            // Tagessummen: nur im Zeitraum (bis endTsExcl)
            $sqlDaily = "SELECT DATE(FROM_UNIXTIME(tstamp)) AS d, COALESCE(SUM(betrag),0) AS s
                         FROM transfers
                         WHERE kasse IN ($ph) AND tstamp IS NOT NULL AND tstamp >= ? AND tstamp < ?
                         GROUP BY d
                         ORDER BY d ASC";
            $stmt = $conn->prepare($sqlDaily);
            if (!$stmt) {
                return $out;
            }

            $types = str_repeat('i', count($kasseIds)) . 'ii';
            $params = array_merge($kasseIds, [$startTs, $endTsExcl]);
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);

            $stmt->execute();
            $stmt->bind_result($d, $s);
            while ($stmt->fetch()) {
                if ($d !== null) {
                    $out['daily'][$d] = floatval($s);
                }
            }
            $stmt->close();

            return $out;
        }

        function build_cumulative_series($keys, $startBalance, $dailySumsByDate)
        {
            $series = [];
            $running = $startBalance;
            foreach ($keys as $d) {
                if (isset($dailySumsByDate[$d])) {
                    $running += $dailySumsByDate[$d];
                }
                $series[] = round($running, 2);
            }
            return $series;
        }
        
        // ------------------- Series bauen: ab "heute" -> null (damit Linie abbricht) -------------------
        function build_cumulative_series_until($keys, $startBalance, $dailySumsByDate, $validUntilIso)
        {
            $series = [];
            $running = $startBalance;

            foreach ($keys as $d) {
                if ($d > $validUntilIso) {
                    $series[] = null; // Chart.js bricht Linie ab
                    continue;
                }

                if (isset($dailySumsByDate[$d])) {
                    $running += $dailySumsByDate[$d];
                }
                $series[] = round($running, 2);
            }

            return $series;
        }

        $validUntilIso = ($year === $currentYear) ? date('Y-m-d') : '9999-12-31';


        // ------------------- Datasets (immer 1 Chart) -------------------
        $datasets = [];

        if ($view === 'group') {
            $netz = fetch_start_balance_and_daily_sums($conn, $groupNetzAG, $startTs, $dataEndTsExcl);
            $haus = fetch_start_balance_and_daily_sums($conn, $groupHaus,   $startTs, $dataEndTsExcl);

            $datasets[] = [
                'label' => 'Netzwerk-AG',
                'data' => build_cumulative_series_until($keys, $netz['start'], $netz['daily'], $validUntilIso)
            ];
            $datasets[] = [
                'label' => 'Haussprecher',
                'data' => build_cumulative_series_until($keys, $haus['start'], $haus['daily'], $validUntilIso)
            ];
        } else {
            if ($view === 'online') {
                $selected = $onlinekassen;
            } elseif ($view === 'cash') {
                $selected = $barkassen;
            } else {
                $selected = $allKassen;
            }

            foreach ($selected as $k) {
                $r = fetch_start_balance_and_daily_sums($conn, [$k['id']], $startTs, $dataEndTsExcl);
                $datasets[] = [
                    'label' => $k['label'],
                    'data' => build_cumulative_series_until($keys, $r['start'], $r['daily'], $validUntilIso)
                ];
            }
        }

        $payload = [
            'year' => $year,
            'view' => $view,
            'keys' => $keys,         // YYYY-MM-DD (Tooltip)
            'labels' => $labels,     // dd.mm. (wird NICHT für Monatsnamen benutzt, nur Fallback)
            'monthLines' => $monthLines,
            'monthLabels' => $monthLabels, // index + name
            'datasets' => $datasets
        ];
?>

<style>
    .wehKassenScope, .wehKassenScope * { box-sizing: border-box; }

    .wehKassenScope{
        width: 100%;
        height: calc(100vh - 120px);
        max-width: 1500px;
        margin: 0 auto;
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow: hidden;
    }

    /* Topbar schicker */
    .wehKassenTopBar{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding: 14px 14px;
        border-radius: 16px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.14);
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    }

    .wehKassenTitleWrap{ display:flex; flex-direction:column; gap:4px; }
    .wehKassenTitle{ color:#fff !important; margin:0; }
    .wehKassenSub{ color:rgba(255,255,255,0.78) !important; margin:0; }

    .wehKassenControls{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }

    /* Dropdowns: dunkler Hintergrund, weiße Schrift, gut lesbar */
    .wehKassenSelect{
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(17,165,13,0.80);
        background: rgba(0,0,0,0.42);
        color: #ffffff;
        cursor: pointer;
        outline: none;
        transition: transform 120ms ease, border-color 120ms ease, background 120ms ease;
    }
    .wehKassenSelect:hover{
        transform: translateY(-1px);
        border-color: rgba(17,165,13,1);
        background: rgba(0,0,0,0.50);
    }
    .wehKassenSelect option{
        background: #111;
        color: #fff;
    }

    .wehKassenChartCard{
        flex: 1;
        min-height: 0;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,0.14);
        background: rgba(255,255,255,0.06);
        box-shadow: 0 14px 40px rgba(0,0,0,0.30);
        overflow: hidden;
        padding: 10px;
    }

    .wehKassenCanvasWrap{
        width: 100%;
        height: 100%;
        min-height: 0;
        border-radius: 14px;
        background: #262525;
        overflow: hidden;
    }

    canvas#wehKassenChart{
        display:block !important;
        width:100% !important;
        height:100% !important;
    }
</style>

<div class="wehKassenScope" id="wehKassenScope">
    <div class="wehKassenTopBar">
        <div class="wehKassenTitleWrap">
            <h2 class="wehKassenTitle">Kassenstände - Verlauf</h2>
            <p class="wehKassenSub">
                Zeitraum:
                <?php
                    echo "01.01.$year - ";
                    if ($year === intval(date('Y'))) {
                        echo date('d.m.Y');
                    } else {
                        echo "31.12.$year";
                    }
                ?>
            </p>
        </div>

        <form class="wehKassenControls" method="get" action="">
            <select class="wehKassenSelect" name="year" onchange="this.form.submit()">
                <?php for ($y = $maxYear; $y >= $minYear; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y === $year ? 'selected' : ''); ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select class="wehKassenSelect" name="view" onchange="this.form.submit()">
                <option value="online" <?php echo ($view === 'online' ? 'selected' : ''); ?>>Online-Konten</option>
                <option value="cash"   <?php echo ($view === 'cash' ? 'selected' : ''); ?>>Barkassen</option>
                <option value="all"    <?php echo ($view === 'all' ? 'selected' : ''); ?>>Alle Kassen</option>
                <option value="group"  <?php echo ($view === 'group' ? 'selected' : ''); ?>>Netz/Vorstand</option>
            </select>
        </form>
    </div>

    <div class="wehKassenChartCard">
        <div class="wehKassenCanvasWrap">
            <canvas id="wehKassenChart"></canvas>
        </div>
    </div>
</div>

<script>
    // Höhe so setzen, dass kein Scrollen nötig ist
    (function () {
        const scope = document.getElementById('wehKassenScope');
        if (!scope) return;
        const rect = scope.getBoundingClientRect();
        const topOffset = Math.max(0, rect.top);
        scope.style.height = `calc(95vh - ${Math.ceil(topOffset) + 10}px)`;
    })();

    Chart.defaults.animation = false;
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;

    const payload = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE); ?>;

    // Plugin: Hintergrund der ChartArea
    const bgPlugin = {
        id: 'bgPlugin',
        beforeDraw(chart) {
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            ctx.save();
            ctx.fillStyle = '#262525';
            ctx.fillRect(
                chartArea.left,
                chartArea.top,
                chartArea.right - chartArea.left,
                chartArea.bottom - chartArea.top
            );
            ctx.restore();
        }
    };

    // Plugin: Monats-Trennlinien + Monatsnamen mittig unter dem Monat
    const monthPlugin = {
        id: 'monthPlugin',
        afterDraw(chart) {
            const { ctx, chartArea, scales } = chart;
            if (!chartArea || !scales.x) return;

            const xScale = scales.x;
            ctx.save();

            // vertikale Linien an Monatswechseln
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.16)';
            ctx.lineWidth = 1;

            (payload.monthLines || []).forEach((idx) => {
                const x = xScale.getPixelForValue(idx);
                ctx.beginPath();
                ctx.moveTo(x, chartArea.top);
                ctx.lineTo(x, chartArea.bottom);
                ctx.stroke();
            });

            // Monatsnamen mittig positionieren
            ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            const y = chartArea.bottom + 8; // unterhalb der ChartArea
            (payload.monthLabels || []).forEach((m) => {
                const x = xScale.getPixelForValue(m.index);
                ctx.fillText(m.label, x, y);
            });

            ctx.restore();
        }
    };

    const ctx = document.getElementById('wehKassenChart').getContext('2d');

    // klare Farben, dickere Linien
    const palette = ['#11a50d', '#2563eb', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9', '#10b981', '#f97316', '#111827'];

    const datasets = (payload.datasets || []).map((ds, i) => ({
        label: ds.label,
        data: ds.data,
        borderColor: palette[i % palette.length],
        backgroundColor: 'transparent',
        borderWidth: 4,
        tension: 0.15,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: '#262525',
        pointHoverBorderWidth: 2,
        pointHoverBorderColor: palette[i % palette.length]
    }));

    const chart = new Chart(ctx, {
        type: 'line',
        data: { labels: payload.labels, datasets },
        plugins: [bgPlugin, monthPlugin],
        options: {
            normalized: true,
            interaction: { mode: 'index', intersect: false },
            layout: {
                padding: { bottom: 20 }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { color: '#ffffff' }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.92)',
                    titleColor: '#000',
                    bodyColor: '#000',
                    callbacks: {
                        title: (items) => {
                            if (!items || !items.length) return '';
                            const idx = items[0].dataIndex;
                            const iso = (payload.keys && payload.keys[idx]) ? payload.keys[idx] : '';
                            if (!iso) return '';
                            const parts = iso.split('-'); // YYYY-MM-DD
                            return `${parts[2]}.${parts[1]}.${parts[0]}`;
                        },
                        label: (context) => `${context.dataset.label}: ${Number(context.parsed.y).toFixed(2)} €`
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        display: false
                    }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.12)' },
                    ticks: {
                        color: '#ffffff',
                        callback: v => `${Number(v).toFixed(0)} €`
                    }
                }
            }
        }
    });
</script>

<?php
    } else {
        header("Location: denied.php");
    }
    $conn->close();
?>
</html>
