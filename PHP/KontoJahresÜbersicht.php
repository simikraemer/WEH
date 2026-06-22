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
            || $_SESSION["Kassenwart"]
            || $_SESSION["TvK-Sprecher"]
            || $_SESSION["TvK-Kasse"]
            || $_SESSION["Kassenpruefer"]
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
            ['id' => 93, 'label' => 'Kassenwartkasse I'],
            ['id' => 94, 'label' => 'Kassenwartkasse II'],
            ['id' => 95, 'label' => 'Tresor']
        ];

        $netzagKassen = [
            ['id' => 1,  'label' => 'Netzbarkasse I'],
            ['id' => 2,  'label' => 'Netzbarkasse II'],
            ['id' => 72, 'label' => 'Netzkonto'],
            ['id' => 69, 'label' => 'PayPal']
        ];

        $hausKassen = [
            ['id' => 93, 'label' => 'Kassenwartkasse I'],
            ['id' => 94, 'label' => 'Kassenwartkasse II'],
            ['id' => 92, 'label' => 'Hauskonto'],
            ['id' => 95, 'label' => 'Tresor']
        ];

        $allKassen = array_merge($onlinekassen, $barkassen);

        // Gruppen (Zuständigkeit)
        $groupNetzAG = [1, 2, 69, 72];
        $groupHaus   = [92, 93, 94, 95];

        // ------------------- Input -------------------
        // year:
        // "all" => Mehrjahresansicht über alle Jahre mit Daten
        // YYYY  => Einzeljahr
        $yearParam = isset($_GET['year']) ? trim((string)$_GET['year']) : (string)intval(date('Y'));
        $isMultiYear = ($yearParam === 'all');
        $year = $isMultiYear ? intval(date('Y')) : intval($yearParam);

        // view:
        // "online"       => nur Online-Konten (Einzelkassen)
        // "cash"         => nur Barkassen (Einzelkassen)
        // "all"          => alle Kassen (Einzelkassen)
        // "netzag"       => Netzwerk-AG-Kassen als Einzelverläufe
        // "haus"         => Haus-Kassen als Einzelverläufe
        // "group"        => Netzwerk-AG/Haus als Gruppenansicht
        // "wehtvk"       => WEH/TvK Einnahmen nach Typ
        // "ein_ausgaben" => Ein-/Ausgaben nach Kategorie
        $view = isset($_GET['view']) ? (string)$_GET['view'] : 'online';
        if (!in_array($view, ['online', 'cash', 'all', 'netzag', 'haus', 'group', 'wehtvk', 'ein_ausgaben'], true)) {
            $view = 'online';
        }

        // ------------------- min/max year aus DB (min. 2024) -------------------
        $minYear = 2024;
        $maxYear = intval(date('Y'));

        $stmt = $conn->prepare("SELECT MIN(tstamp), MAX(tstamp) FROM transfers WHERE tstamp IS NOT NULL");
        if ($stmt) {
            $stmt->execute();
            $stmt->bind_result($min_ts, $max_ts);
            if ($stmt->fetch() && $min_ts !== null && $max_ts !== null) {
                $dbMinYear = intval(date('Y', intval($min_ts)));
                $dbMaxYear = intval(date('Y', intval($max_ts)));
                $minYear = max(2024, $dbMinYear);
                $maxYear = max($minYear, $dbMaxYear);
            }
            $stmt->close();
        }

        if (!$isMultiYear) {
            if ($year < $minYear) {
                $year = $minYear;
            }
            if ($year > $maxYear) {
                $year = $maxYear;
            }
        }

        // ------------------- Zeitraum -------------------
        $currentYear = intval(date('Y'));
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $tomorrowStart = $todayStart + 86400;

        $startYear = $isMultiYear ? $minYear : $year;
        $endYear   = $isMultiYear ? $maxYear : $year;

        $startTs = strtotime($startYear . "-01-01 00:00:00");
        $axisEndTsExcl = strtotime(($endYear + 1) . "-01-01 00:00:00");

        // Daten: sobald der ausgewählte Zeitraum das aktuelle Jahr enthält, nur bis morgen laden,
        // damit der Graph ab heute abbricht und nicht künstlich durch das Restjahr läuft.
        $rangeContainsCurrentYear = ($startYear <= $currentYear && $endYear >= $currentYear);
        $dataEndTsExcl = $axisEndTsExcl;
        if ($rangeContainsCurrentYear) {
            $dataEndTsExcl = min($axisEndTsExcl, $tomorrowStart);
        }

        $validUntilIso = $rangeContainsCurrentYear ? date('Y-m-d') : '9999-12-31';

        // In der Mehrjahresansicht der Balkendiagramme wird direkt pro Jahr aggregiert.
        // Alle Linienansichten behalten Tagesauflösung; nur die Trenner/Beschriftungen wechseln.
        $isPeriodBarView = in_array($view, ['wehtvk', 'ein_ausgaben'], true);
        $axisGranularity = ($isMultiYear && $isPeriodBarView) ? 'year' : 'day';
        $periodMode = $isMultiYear ? 'year' : 'month';

        // ------------------- Keys/Labels + Periodenmarkierungen -------------------
        $keys = [];
        $labels = [];
        $tsList = [];
        $periodLines = [];
        $periodLabels = [];

        $monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        if ($axisGranularity === 'year') {
            $idx = 0;
            for ($y = $startYear; $y <= $endYear; $y++, $idx++) {
                $keys[] = (string)$y;
                $labels[] = (string)$y;

                if ($idx > 0) {
                    $periodLines[] = $idx;
                }

                $periodLabels[] = [
                    'index' => $idx,
                    'label' => (string)$y,
                    'year'  => $y,
                    'month' => null
                ];
            }
        } else {
            for ($ts = $startTs; $ts < $axisEndTsExcl; $ts += 86400) {
                $keys[] = date('Y-m-d', $ts);
                $labels[] = date('d.m.', $ts);
                $tsList[] = $ts;
            }

            if (count($tsList) > 0) {
                $curMonth = intval(date('n', $tsList[0]));
                $curYearP = intval(date('Y', $tsList[0]));
                $periodStartIdx = 0;

                for ($i = 1; $i < count($tsList); $i++) {
                    $m = intval(date('n', $tsList[$i]));
                    $y = intval(date('Y', $tsList[$i]));

                    $changed = ($periodMode === 'year')
                        ? ($y !== $curYearP)
                        : ($m !== $curMonth || $y !== $curYearP);

                    if ($changed) {
                        $periodLines[] = $i;

                        $periodEndIdx = $i - 1;
                        $mid = intval(floor(($periodStartIdx + $periodEndIdx) / 2));

                        $periodLabels[] = [
                            'index' => $mid,
                            'label' => ($periodMode === 'year') ? (string)$curYearP : $monthNames[$curMonth - 1],
                            'year'  => $curYearP,
                            'month' => ($periodMode === 'year') ? null : $curMonth
                        ];

                        $curMonth = $m;
                        $curYearP = $y;
                        $periodStartIdx = $i;
                    }
                }

                $periodEndIdx = count($tsList) - 1;
                $mid = intval(floor(($periodStartIdx + $periodEndIdx) / 2));

                $periodLabels[] = [
                    'index' => $mid,
                    'label' => ($periodMode === 'year') ? (string)$curYearP : $monthNames[$curMonth - 1],
                    'year'  => $curYearP,
                    'month' => ($periodMode === 'year') ? null : $curMonth
                ];
            }
        }

        // ------------------- Daten holen (ohne get_result, MySQL 5.5 kompatibel) -------------------
        function fetch_start_balance_and_daily_sums($conn, $kasseIds, $startTs, $endTsExcl)
        {
            $out = ['start' => 0.0, 'daily' => []];
            if (count($kasseIds) === 0) {
                return $out;
            }

            $ph = implode(',', array_fill(0, count($kasseIds), '?'));

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

        function fetch_weh_tvk_monthly_user_transfer_stacks($conn, $startTs, $endTsExcl)
        {
            $out = [];
            for ($m = 1; $m <= 12; $m++) {
                $out[$m] = [
                    'weh' => ['netz' => 0.0, 'haus' => 0.0, 'wasch' => 0.0, 'druck' => 0.0],
                    'tvk' => ['netz' => 0.0, 'haus' => 0.0, 'wasch' => 0.0, 'druck' => 0.0],
                ];
            }

            $sql = "
                SELECT
                    YEAR(FROM_UNIXTIME(t.tstamp))  AS y,
                    MONTH(FROM_UNIXTIME(t.tstamp)) AS m,
                    u.turm AS turm,
                    SUM(CASE WHEN t.print_id IS NULL AND t.beschreibung LIKE 'Abrechnung Netzbeitrag%' THEN ABS(t.betrag) ELSE 0 END) AS netz,
                    SUM(CASE WHEN t.print_id IS NULL AND t.beschreibung LIKE 'Abrechnung Hausbeitrag%' THEN ABS(t.betrag) ELSE 0 END) AS haus,
                    SUM(CASE WHEN t.print_id IS NULL AND t.konto = 6 THEN ABS(t.betrag) ELSE 0 END) AS wasch,
                    SUM(CASE WHEN t.print_id IS NOT NULL THEN ABS(t.betrag) ELSE 0 END) AS druck
                FROM transfers t
                JOIN users u ON u.uid = t.uid
                WHERE
                    t.tstamp IS NOT NULL
                    AND t.tstamp >= ?
                    AND t.tstamp < ?
                    AND u.pid IN (11,12,13,14)
                    AND u.turm IN ('weh','tvk')
                GROUP BY y, m, turm
                ORDER BY y ASC, m ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) return $out;

            $stmt->bind_param('ii', $startTs, $endTsExcl);
            $stmt->execute();
            $stmt->bind_result($y, $m, $turm, $netz, $haus, $wasch, $druck);

            while ($stmt->fetch()) {
                $mm = (int)$m;
                $tt = ($turm === 'tvk') ? 'tvk' : 'weh';
                if ($mm >= 1 && $mm <= 12) {
                    $out[$mm][$tt]['netz']  = (float)$netz;
                    $out[$mm][$tt]['haus']  = (float)$haus;
                    $out[$mm][$tt]['wasch'] = (float)$wasch;
                    $out[$mm][$tt]['druck'] = (float)$druck;
                }
            }
            $stmt->close();

            return $out;
        }

        function fetch_weh_tvk_yearly_user_transfer_stacks($conn, $startYear, $endYear, $startTs, $endTsExcl)
        {
            $out = [];
            for ($y = $startYear; $y <= $endYear; $y++) {
                $out[$y] = [
                    'weh' => ['netz' => 0.0, 'haus' => 0.0, 'wasch' => 0.0, 'druck' => 0.0],
                    'tvk' => ['netz' => 0.0, 'haus' => 0.0, 'wasch' => 0.0, 'druck' => 0.0],
                ];
            }

            $sql = "
                SELECT
                    YEAR(FROM_UNIXTIME(t.tstamp)) AS y,
                    u.turm AS turm,
                    SUM(CASE WHEN t.print_id IS NULL AND t.beschreibung LIKE 'Abrechnung Netzbeitrag%' THEN ABS(t.betrag) ELSE 0 END) AS netz,
                    SUM(CASE WHEN t.print_id IS NULL AND t.beschreibung LIKE 'Abrechnung Hausbeitrag%' THEN ABS(t.betrag) ELSE 0 END) AS haus,
                    SUM(CASE WHEN t.print_id IS NULL AND t.konto = 6 THEN ABS(t.betrag) ELSE 0 END) AS wasch,
                    SUM(CASE WHEN t.print_id IS NOT NULL THEN ABS(t.betrag) ELSE 0 END) AS druck
                FROM transfers t
                JOIN users u ON u.uid = t.uid
                WHERE
                    t.tstamp IS NOT NULL
                    AND t.tstamp >= ?
                    AND t.tstamp < ?
                    AND u.pid IN (11,12,13,14)
                    AND u.turm IN ('weh','tvk')
                GROUP BY y, turm
                ORDER BY y ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) return $out;

            $stmt->bind_param('ii', $startTs, $endTsExcl);
            $stmt->execute();
            $stmt->bind_result($y, $turm, $netz, $haus, $wasch, $druck);

            while ($stmt->fetch()) {
                $yy = (int)$y;
                $tt = ($turm === 'tvk') ? 'tvk' : 'weh';
                if ($yy >= $startYear && $yy <= $endYear) {
                    $out[$yy][$tt]['netz']  = (float)$netz;
                    $out[$yy][$tt]['haus']  = (float)$haus;
                    $out[$yy][$tt]['wasch'] = (float)$wasch;
                    $out[$yy][$tt]['druck'] = (float)$druck;
                }
            }
            $stmt->close();

            return $out;
        }

        function fetch_transfer_period_sums($conn, $periodMode, $startTs, $endTsExcl, $whereSql, $whereTypes = '', $whereParams = [], $direction = 'negative_to_positive')
        {
            $out = [];

            $periodExpr = ($periodMode === 'year')
                ? "YEAR(FROM_UNIXTIME(t.tstamp))"
                : "MONTH(FROM_UNIXTIME(t.tstamp))";

            if ($direction === 'positive_only') {
                $betragCondition = "t.betrag > 0";
                $sumExpr = "t.betrag";
            } else {
                $betragCondition = "t.betrag < 0";
                $sumExpr = "-t.betrag";
            }

            $sql = "
                SELECT
                    $periodExpr AS p,
                    COALESCE(SUM($sumExpr), 0) AS s
                FROM transfers t
                WHERE
                    t.tstamp IS NOT NULL
                    AND t.tstamp >= ?
                    AND t.tstamp < ?
                    AND $betragCondition
                    AND ($whereSql)
                GROUP BY p
                ORDER BY p ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) return $out;

            $types = 'ii' . $whereTypes;
            $params = array_merge([$startTs, $endTsExcl], $whereParams);

            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);

            $stmt->execute();
            $stmt->bind_result($p, $s);

            while ($stmt->fetch()) {
                $out[(int)$p] = (float)$s;
            }

            $stmt->close();
            return $out;
        }

        function add_period_sums_to_bucket(&$out, $rows, $stackKey, $categoryKey)
        {
            foreach ($rows as $p => $sum) {
                if (!isset($out[$p])) continue;
                $out[$p][$stackKey][$categoryKey] = round(max(0, (float)$sum), 2);
            }
        }

        function fetch_ein_ausgaben_period_stacks($conn, $periodMode, $startYear, $endYear, $startTs, $endTsExcl, $groupNetzAG, $groupHaus)
        {
            $out = [];

            if ($periodMode === 'year') {
                for ($y = $startYear; $y <= $endYear; $y++) {
                    $out[$y] = [
                        'income' => [
                            'wasch' => 0.0,
                            'netzbeitrag' => 0.0,
                            'hausbeitrag' => 0.0,
                            'druck' => 0.0
                        ],
                        'expense' => [
                            'ausgaben_netzag' => 0.0,
                            'ausgaben_haus' => 0.0,
                            'agessen' => 0.0
                        ]
                    ];
                }
            } else {
                for ($m = 1; $m <= 12; $m++) {
                    $out[$m] = [
                        'income' => [
                            'wasch' => 0.0,
                            'netzbeitrag' => 0.0,
                            'hausbeitrag' => 0.0,
                            'druck' => 0.0
                        ],
                        'expense' => [
                            'ausgaben_netzag' => 0.0,
                            'ausgaben_haus' => 0.0,
                            'agessen' => 0.0
                        ]
                    ];
                }
            }

            // Interne Ausgleichspaare einmalig vorab ermitteln.
            // Erkannt werden:
            // 1) PayPal negativ -> Netzkonto positiv, gleicher Betrag, max. 3 Tage Abstand
            // 2) Netzkonto negativ -> Hauskonto positiv, gleicher Betrag, max. 3 Tage Abstand
            //
            // Beide IDs werden gesammelt und später per t.id NOT IN (...) ausgeschlossen.
            $internalIds = [];

            $pairStartTs = $startTs - 259200;
            $pairEndTsExcl = $endTsExcl + 259200;

            $sqlPairs = "
                SELECT DISTINCT
                    a.id AS neg_id,
                    b.id AS pos_id
                FROM transfers a
                JOIN transfers b
                    ON b.id <> a.id
                    AND b.tstamp IS NOT NULL
                    AND b.tstamp BETWEEN (a.tstamp - 259200) AND (a.tstamp + 259200)
                    AND b.betrag > 0
                    AND b.betrag BETWEEN (ABS(a.betrag) - 0.005) AND (ABS(a.betrag) + 0.005)
                    AND (
                        (a.kasse = 69 AND b.kasse = 72)
                        OR
                        (a.kasse = 72 AND b.kasse = 92)
                    )
                WHERE
                    a.tstamp IS NOT NULL
                    AND a.tstamp >= ?
                    AND a.tstamp < ?
                    AND a.betrag < 0
                    AND a.kasse IN (69, 72)
            ";

            $stmtPairs = $conn->prepare($sqlPairs);
            if ($stmtPairs) {
                $stmtPairs->bind_param('ii', $pairStartTs, $pairEndTsExcl);
                $stmtPairs->execute();
                $stmtPairs->bind_result($negId, $posId);

                while ($stmtPairs->fetch()) {
                    $internalIds[(int)$negId] = true;
                    $internalIds[(int)$posId] = true;
                }

                $stmtPairs->close();
            }

            if (count($internalIds) > 0) {
                $internalIdList = implode(',', array_map('intval', array_keys($internalIds)));
                $internalPairFilter = "t.id NOT IN ($internalIdList)";
            } else {
                $internalPairFilter = "1=1";
            }

            // Bereits explizit kategorisierte Buchungen.
            $knownCategoryFilter = "
                NOT (
                    t.beschreibung LIKE '%AG-Essen%'
                    OR t.beschreibung LIKE '%Abmeldung%'
                    OR (t.print_id IS NOT NULL AND t.print_id <> 0)
                    OR t.beschreibung LIKE 'Abrechnung Hausbeitrag%'
                    OR t.beschreibung LIKE 'Abrechnung Netzbeitrag%'
                    OR t.konto = 6
                )
            ";

            // Einnahmen aus Vereinsperspektive:
            // Diese Kategorien sind in transfers aus Userperspektive negativ, daher SUM(-betrag).
            $wasch = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.konto = 6
                "
            );
            add_period_sums_to_bucket($out, $wasch, 'income', 'wasch');

            $netzbeitrag = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.beschreibung LIKE 'Abrechnung Netzbeitrag%'
                "
            );
            add_period_sums_to_bucket($out, $netzbeitrag, 'income', 'netzbeitrag');

            $hausbeitrag = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.beschreibung LIKE 'Abrechnung Hausbeitrag%'
                "
            );
            add_period_sums_to_bucket($out, $hausbeitrag, 'income', 'hausbeitrag');

            $druck = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.print_id IS NOT NULL
                    AND t.print_id <> 0
                "
            );
            add_period_sums_to_bucket($out, $druck, 'income', 'druck');

            // Andere Ausgaben:
            // Alle negativen Transfers VON den jeweiligen Kassen,
            // aber ohne explizite Kategorien und ohne interne Ausgleichspaare.
            $phNetz = implode(',', array_fill(0, count($groupNetzAG), '?'));
            $netzAndere = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.kasse IN ($phNetz)
                    AND $knownCategoryFilter
                ",
                str_repeat('i', count($groupNetzAG)),
                $groupNetzAG
            );
            add_period_sums_to_bucket($out, $netzAndere, 'expense', 'ausgaben_netzag');

            $phHaus = implode(',', array_fill(0, count($groupHaus), '?'));
            $hausAndere = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.kasse IN ($phHaus)
                    AND $knownCategoryFilter
                ",
                str_repeat('i', count($groupHaus)),
                $groupHaus
            );
            add_period_sums_to_bucket($out, $hausAndere, 'expense', 'ausgaben_haus');

            // AG-Essen:
            // Kommt aus transfers. Betrag ist dort negativ, daher SUM(-betrag).
            $agessen = fetch_transfer_period_sums(
                $conn,
                $periodMode,
                $startTs,
                $endTsExcl,
                "
                    $internalPairFilter
                    AND t.beschreibung LIKE '%AG-Essen%'
                "
            );
            add_period_sums_to_bucket($out, $agessen, 'expense', 'agessen');

            return $out;
        }

        function build_cumulative_series_until($keys, $startBalance, $dailySumsByDate, $validUntilIso)
        {
            $series = [];
            $running = $startBalance;

            foreach ($keys as $d) {
                if ($d > $validUntilIso) {
                    $series[] = null;
                    continue;
                }

                if (isset($dailySumsByDate[$d])) {
                    $running += $dailySumsByDate[$d];
                }
                $series[] = round($running, 2);
            }

            return $series;
        }

        // ------------------- Datasets -------------------
        $datasets = [];
        $chartType = 'line';

        if ($view === 'wehtvk') {
            $chartType = 'bar';

            $catDefs = [
                ['key' => 'wasch', 'label' => 'Waschmarken'],
                ['key' => 'netz',  'label' => 'Netzbeitrag'],
                ['key' => 'haus',  'label' => 'Hausbeitrag'],
                ['key' => 'druck', 'label' => 'Drucker'],
            ];

            $towerDefs = [
                ['key' => 'weh', 'label' => 'WEH'],
                ['key' => 'tvk', 'label' => 'TvK'],
            ];

            if ($isMultiYear) {
                $yearIndexByYear = [];
                foreach ($periodLabels as $pl) {
                    if (isset($pl['year'], $pl['index'])) {
                        $yearIndexByYear[(int)$pl['year']] = (int)$pl['index'];
                    }
                }

                $yStacks = fetch_weh_tvk_yearly_user_transfer_stacks($conn, $startYear, $endYear, $startTs, $dataEndTsExcl);

                foreach ($towerDefs as $t) {
                    foreach ($catDefs as $ci => $c) {
                        $data = array_fill(0, count($keys), null);

                        for ($y = $startYear; $y <= $endYear; $y++) {
                            if (!isset($yearIndexByYear[$y])) continue;
                            $idx = $yearIndexByYear[$y];

                            $val = $yStacks[$y][$t['key']][$c['key']] ?? 0.0;
                            if (abs((float)$val) < 0.00001) continue;

                            $data[$idx] = round((float)$val, 2);
                        }

                        $datasets[] = [
                            'label' => $t['label'] . ' ' . $c['label'],
                            'data'  => $data,
                            'stack' => $t['key'],
                            'shade' => $ci,
                            'barGroup' => 'wehtvk'
                        ];
                    }
                }
            } else {
                $monthMidByMonth = [];
                foreach ($periodLabels as $pl) {
                    if (isset($pl['month'], $pl['index']) && $pl['month'] !== null) {
                        $monthMidByMonth[(int)$pl['month']] = (int)$pl['index'];
                    }
                }

                $mStacks = fetch_weh_tvk_monthly_user_transfer_stacks($conn, $startTs, $dataEndTsExcl);

                foreach ($towerDefs as $t) {
                    foreach ($catDefs as $ci => $c) {
                        $data = array_fill(0, count($keys), null);

                        for ($m = 1; $m <= 12; $m++) {
                            if (!isset($monthMidByMonth[$m])) continue;
                            $idx = $monthMidByMonth[$m];

                            $val = $mStacks[$m][$t['key']][$c['key']] ?? 0.0;
                            if (abs((float)$val) < 0.00001) continue;

                            $data[$idx] = round((float)$val, 2);
                        }

                        $datasets[] = [
                            'label' => $t['label'] . ' ' . $c['label'],
                            'data'  => $data,
                            'stack' => $t['key'],
                            'shade' => $ci,
                            'barGroup' => 'wehtvk'
                        ];
                    }
                }
            }
        } elseif ($view === 'ein_ausgaben') {
            $chartType = 'bar';

            // Reihenfolge im Stack von unten nach oben:
            // Einnahmen: Waschmarken, Netzbeitrag, Hausbeitrag, Druckaufträge
            $incomeDefs = [
                ['key' => 'wasch',       'label' => 'Waschmarken'],
                ['key' => 'netzbeitrag', 'label' => 'Netzbeitrag'],
                ['key' => 'hausbeitrag', 'label' => 'Hausbeitrag'],
                ['key' => 'druck',       'label' => 'Druckaufträge'],
            ];

            // Reihenfolge im Stack von unten nach oben:
            // Ausgaben: Netzwerk-AG, Haus, AG-Essen
            $expenseDefs = [
                ['key' => 'ausgaben_netzag', 'label' => 'Ausgaben Netzwerk-AG'],
                ['key' => 'ausgaben_haus',   'label' => 'Ausgaben Haus'],
                ['key' => 'agessen',          'label' => 'AG-Essen'],
            ];

            $periodStacks = fetch_ein_ausgaben_period_stacks(
                $conn,
                $periodMode,
                $startYear,
                $endYear,
                $startTs,
                $dataEndTsExcl,
                $groupNetzAG,
                $groupHaus
            );

            $periodIndexByPeriod = [];
            foreach ($periodLabels as $pl) {
                if ($periodMode === 'year' && isset($pl['year'], $pl['index'])) {
                    $periodIndexByPeriod[(int)$pl['year']] = (int)$pl['index'];
                } elseif ($periodMode === 'month' && isset($pl['month'], $pl['index']) && $pl['month'] !== null) {
                    $periodIndexByPeriod[(int)$pl['month']] = (int)$pl['index'];
                }
            }

            foreach ($incomeDefs as $ci => $c) {
                $data = array_fill(0, count($keys), null);

                foreach ($periodStacks as $period => $stacks) {
                    if (!isset($periodIndexByPeriod[(int)$period])) continue;
                    $idx = $periodIndexByPeriod[(int)$period];

                    $val = $stacks['income'][$c['key']] ?? 0.0;
                    if (abs((float)$val) < 0.00001) continue;

                    $data[$idx] = round((float)$val, 2);
                }

                $datasets[] = [
                    'label' => 'Einnahmen ' . $c['label'],
                    'data' => $data,
                    'stack' => 'income',
                    'shade' => $ci,
                    'barGroup' => 'ein_ausgaben'
                ];
            }

            foreach ($expenseDefs as $ci => $c) {
                $data = array_fill(0, count($keys), null);

                foreach ($periodStacks as $period => $stacks) {
                    if (!isset($periodIndexByPeriod[(int)$period])) continue;
                    $idx = $periodIndexByPeriod[(int)$period];

                    $val = $stacks['expense'][$c['key']] ?? 0.0;
                    if (abs((float)$val) < 0.00001) continue;

                    $data[$idx] = round((float)$val, 2);
                }

                $datasets[] = [
                    'label' => $c['label'],
                    'data' => $data,
                    'stack' => 'expense',
                    'shade' => $ci,
                    'barGroup' => 'ein_ausgaben'
                ];
            }
        } elseif ($view === 'group') {
            $netz = fetch_start_balance_and_daily_sums($conn, $groupNetzAG, $startTs, $dataEndTsExcl);
            $haus = fetch_start_balance_and_daily_sums($conn, $groupHaus,   $startTs, $dataEndTsExcl);

            $datasets[] = [
                'label' => 'Netzwerk-AG',
                'data' => build_cumulative_series_until($keys, $netz['start'], $netz['daily'], $validUntilIso)
            ];
            $datasets[] = [
                'label' => 'Haus',
                'data' => build_cumulative_series_until($keys, $haus['start'], $haus['daily'], $validUntilIso)
            ];
        } else {
            if ($view === 'online') {
                $selected = $onlinekassen;
            } elseif ($view === 'cash') {
                $selected = $barkassen;
            } elseif ($view === 'netzag') {
                $selected = $netzagKassen;
            } elseif ($view === 'haus') {
                $selected = $hausKassen;
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
            'year' => $isMultiYear ? 'all' : $year,
            'isMultiYear' => $isMultiYear,
            'view' => $view,
            'chartType' => $chartType,
            'axisGranularity' => $axisGranularity,
            'periodMode' => $periodMode,
            'keys' => $keys,
            'labels' => $labels,
            'periodLines' => $periodLines,
            'periodLabels' => $periodLabels,
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
                    if ($isMultiYear) {
                        echo "01.01.$startYear - ";
                        if ($rangeContainsCurrentYear && $endYear === $currentYear) {
                            echo date('d.m.Y');
                        } else {
                            echo "31.12.$endYear";
                        }
                    } else {
                        echo "01.01.$year - ";
                        if ($year === intval(date('Y'))) {
                            echo date('d.m.Y');
                        } else {
                            echo "31.12.$year";
                        }
                    }
                ?>
            </p>
        </div>

        <form class="wehKassenControls" method="get" action="">
            <select class="wehKassenSelect" name="year" onchange="this.form.submit()">
                <option value="all" <?php echo ($isMultiYear ? 'selected' : ''); ?>>Alle Jahre</option>
                <?php for ($y = $maxYear; $y >= $minYear; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo (!$isMultiYear && $y === $year ? 'selected' : ''); ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select class="wehKassenSelect" name="view" onchange="this.form.submit()">
                <option value="online"       <?php echo ($view === 'online' ? 'selected' : ''); ?>>Online-Konten</option>
                <option value="cash"         <?php echo ($view === 'cash' ? 'selected' : ''); ?>>Barkassen</option>
                <option value="all"          <?php echo ($view === 'all' ? 'selected' : ''); ?>>Alle Kassen</option>
                <option value="netzag"       <?php echo ($view === 'netzag' ? 'selected' : ''); ?>>Netzwerk-AG</option>
                <option value="haus"         <?php echo ($view === 'haus' ? 'selected' : ''); ?>>Haus</option>
                <option value="group"        <?php echo ($view === 'group' ? 'selected' : ''); ?>>Netz/Haus</option>
                <option value="wehtvk"       <?php echo ($view === 'wehtvk' ? 'selected' : ''); ?>>WEH/TvK</option>
                <option value="ein_ausgaben" <?php echo ($view === 'ein_ausgaben' ? 'selected' : ''); ?>>Ein-/Ausgaben</option>
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

    const chartType = payload.chartType || 'line';
    const periodLines = payload.periodLines || [];
    const periodLabels = payload.periodLabels || [];

    const palette = ['#11a50d', '#2563eb', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9', '#10b981', '#f97316', '#111827'];

    const barBase = {
        weh: '#11a50d',
        tvk: '#ffa600',
        income: '#111111',
        expense: '#7f1d1d'
    };

    const barMixTarget = {
        weh: '#000000',
        tvk: '#000000',
        income: '#6b7280',
        expense: '#fca5a5'
    };

    const defaultShadeWeights = [0.00, 0.18, 0.34, 0.48, 0.60];
    const expenseShadeWeights = [0.00, 0.42, 0.78, 0.90];

    function hexToRgb(hex) {
        const h = String(hex).replace('#', '').trim();
        const full = (h.length === 3) ? h.split('').map(ch => ch + ch).join('') : h;
        const n = parseInt(full, 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function mixHex(a, b, w) {
        const A = hexToRgb(a), B = hexToRgb(b);
        const r = Math.round(A.r * (1 - w) + B.r * w);
        const g = Math.round(A.g * (1 - w) + B.g * w);
        const b2 = Math.round(A.b * (1 - w) + B.b * w);
        return `rgb(${r}, ${g}, ${b2})`;
    }

    function barShade(stackKey, shadeIdx) {
        const base = barBase[stackKey] || '#999999';
        const target = barMixTarget[stackKey] || '#000000';
        const weights = (stackKey === 'expense') ? expenseShadeWeights : defaultShadeWeights;
        const w = weights[Math.max(0, Math.min(weights.length - 1, shadeIdx || 0))];
        return mixHex(base, target, w);
    }

    function barBorder(stackKey) {
        if (stackKey === 'income') return 'rgba(255, 255, 255, 0.42)';
        if (stackKey === 'expense') return 'rgba(255, 240, 240, 0.60)';
        return 'rgba(255, 255, 255, 0.22)';
    }

    function boundaryPixelForIndex(xScale, chartArea, idx) {
        const i = Number(idx);
        if (!Number.isFinite(i)) return null;
        if (i <= 0) return chartArea.left;

        const current = xScale.getPixelForValue(i);
        const previous = xScale.getPixelForValue(i - 1);

        if (Number.isFinite(current) && Number.isFinite(previous)) {
            return (previous + current) / 2;
        }
        return Number.isFinite(current) ? current : null;
    }

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

    const periodPlugin = {
        id: 'periodPlugin',
        afterDraw(chart) {
            const { ctx, chartArea, scales } = chart;
            if (!chartArea || !scales.x) return;

            const xScale = scales.x;
            ctx.save();

            ctx.strokeStyle = 'rgba(255, 255, 255, 0.16)';
            ctx.lineWidth = 1;

            periodLines.forEach((idx) => {
                const x = boundaryPixelForIndex(xScale, chartArea, idx);
                if (x === null) return;

                ctx.beginPath();
                ctx.moveTo(x, chartArea.top);
                ctx.lineTo(x, chartArea.bottom);
                ctx.stroke();
            });

            ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            const y = chartArea.bottom + 8;
            periodLabels.forEach((p) => {
                const x = scales.x.getPixelForValue(p.index);
                ctx.fillText(p.label, x, y);
            });

            ctx.restore();
        }
    };

    const periodRanges = (() => {
        const n = (payload.labels || []).length;
        const ranges = [];

        let start = 0;
        for (let i = 0; i < periodLabels.length; i++) {
            const end = (i < periodLines.length) ? (Number(periodLines[i]) - 1) : (n - 1);
            ranges.push({
                start,
                end,
                mid: periodLabels[i].index,
                label: periodLabels[i].label,
                year: periodLabels[i].year,
                month: periodLabels[i].month || null
            });
            start = end + 1;
        }
        return ranges;
    })();

    function rangeForIndex(idx) {
        for (const r of periodRanges) {
            if (idx >= r.start && idx <= r.end) return r;
        }
        return periodRanges.length ? periodRanges[periodRanges.length - 1] : null;
    }

    if (!Chart.Tooltip.positioners.periodCursor) {
        Chart.Tooltip.positioners.periodCursor = function (_items, pos) {
            return pos;
        };
    }

    const periodHoverPlugin = {
        id: 'periodHoverPlugin',
        afterEvent(chart, args) {
            if ((payload.chartType || 'line') !== 'bar') return;

            const e = (args && args.event) ? args.event : null;
            if (!e) return;

            if (e.type !== 'mousemove' && e.type !== 'mouseout') return;

            const { chartArea, scales } = chart;
            if (!chartArea || !scales.x) return;

            const st = chart.$periodHoverState || (chart.$periodHoverState = { raf: 0, pending: null });

            const outside =
                e.type === 'mouseout' ||
                e.x < chartArea.left || e.x > chartArea.right ||
                e.y < chartArea.top  || e.y > chartArea.bottom;

            const xScale = scales.x;

            const periodIndexAtX = (x) => {
                if (!periodLabels.length) return 0;

                const edges = [chartArea.left];
                for (let i = 0; i < periodLines.length; i++) {
                    const px = boundaryPixelForIndex(xScale, chartArea, periodLines[i]);
                    if (px !== null) edges.push(px);
                }
                edges.push(chartArea.right);

                const pCount = Math.min(periodLabels.length, edges.length - 1);

                for (let i = 0; i < pCount; i++) {
                    const a = edges[i];
                    const b = edges[i + 1];
                    if (x >= a && (x < b || i === pCount - 1)) return i;
                }
                return pCount - 1;
            };

            let active = [];
            let pos = { x: e.x, y: e.y };

            if (!outside && periodLabels.length) {
                const pi = periodIndexAtX(e.x);
                const mid = periodLabels[pi].index;

                for (let di = 0; di < chart.data.datasets.length; di++) {
                    const v = chart.data.datasets[di].data[mid];
                    if (v !== null && typeof v !== 'undefined') {
                        active.push({ datasetIndex: di, index: mid });
                    }
                }
            }

            st.pending = { active, pos };

            if (st.raf) return;
            st.raf = requestAnimationFrame(() => {
                st.raf = 0;
                const p = st.pending;
                if (!p) return;

                chart.tooltip.setActiveElements(p.active, p.pos);
                chart.draw();
            });
        }
    };

    const ctx = document.getElementById('wehKassenChart').getContext('2d');

    const datasets = (payload.datasets || []).map((ds, i) => {
        if (chartType === 'bar') {
            const c = barShade(ds.stack, ds.shade);
            return {
                label: ds.label,
                data: ds.data,
                stack: ds.stack,
                backgroundColor: c,
                borderColor: barBorder(ds.stack),
                borderWidth: ds.barGroup === 'ein_ausgaben' ? 1 : 0,
                borderSkipped: false,
                barThickness: ds.barGroup === 'ein_ausgaben' ? 38 : 34,
                maxBarThickness: ds.barGroup === 'ein_ausgaben' ? 48 : 44
            };
        }

        return {
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
        };
    });

    const chart = new Chart(ctx, {
        type: chartType,
        data: { labels: payload.labels, datasets },
        plugins: (chartType === 'bar') ? [bgPlugin, periodPlugin, periodHoverPlugin] : [bgPlugin, periodPlugin],
        options: {
            normalized: true,
            interaction: (chartType === 'bar')
                ? { mode: 'nearest', axis: 'x', intersect: false }
                : { mode: 'index', intersect: false },
            layout: { padding: { bottom: 20 } },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { color: '#ffffff' }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.92)',
                    titleColor: '#000',
                    bodyColor: '#000',
                    position: (chartType === 'bar') ? 'periodCursor' : 'average',
                    callbacks: {
                        title: (items) => {
                            if (!items || !items.length) return '';

                            const idx = items[0].dataIndex;

                            if (chartType === 'bar') {
                                const r = rangeForIndex(idx);
                                if (!r) return '';
                                return (payload.periodMode === 'year') ? `${r.label}` : `${r.label} ${r.year}`;
                            }

                            const iso = (payload.keys && payload.keys[idx]) ? payload.keys[idx] : '';
                            if (!iso) return '';

                            if (payload.axisGranularity === 'year') {
                                return iso;
                            }

                            const parts = iso.split('-');
                            return `${parts[2]}.${parts[1]}.${parts[0]}`;
                        },
                        label: (context) => {
                            const y = context.parsed && typeof context.parsed.y !== 'undefined' ? context.parsed.y : null;
                            if (y === null) return null;
                            return `${context.dataset.label}: ${Number(y).toFixed(2)} €`;
                        }
                    },
                }
            },
            scales: {
                x: {
                    stacked: (chartType === 'bar'),
                    grid: { display: false },
                    ticks: { display: false }
                },
                y: {
                    stacked: (chartType === 'bar'),
                    beginAtZero: true,
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