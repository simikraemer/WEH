<?php
session_start();
require_once('conn.php');

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_set_charset($conn, "utf8");
}

if (!function_exists('erstattung_h')) {
    function erstattung_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('erstattung_unixtime2startofsemester')) {
    function erstattung_unixtime2startofsemester(int $ts): int {
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);

        if ($month >= 4 && $month < 10) {
            return strtotime('01-04-' . $year);
        }

        if ($month >= 10) {
            return strtotime('01-10-' . $year);
        }

        return strtotime('01-10-' . ($year - 1));
    }
}

if (!function_exists('erstattung_unixtime2semester')) {
    function erstattung_unixtime2semester(int $ts): string {
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);

        if ($month === 4) {
            return 'SS ' . $year;
        }

        return 'WS ' . $year . '/' . substr((string)($year + 1), -2);
    }
}

if (!function_exists('erstattung_semester_end')) {
    function erstattung_semester_end(int $semesterStart): int {
        $month = (int)date('n', $semesterStart);
        $year = (int)date('Y', $semesterStart);

        if ($month === 4) {
            return strtotime('01-10-' . $year);
        }

        return strtotime('01-04-' . ($year + 1));
    }
}

if (!function_exists('buildUserRoomEmail')) {
    function buildUserRoomEmail($room, $turm): string {
        $roomPart = str_pad((string)intval($room), 4, '0', STR_PAD_LEFT);
        $turmPart = strtolower(trim((string)$turm));
        return 'z' . $roomPart . '@' . $turmPart . '.rwth-aachen.de';
    }
}

if (!function_exists('erstattung_file_type')) {
    function erstattung_file_type(string $pfad): string {
        $pathOnly = parse_url($pfad, PHP_URL_PATH);

        if ($pathOnly === null || $pathOnly === false) {
            $pathOnly = $pfad;
        }

        $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return 'pdf';
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return 'image';
        }

        return 'other';
    }
}

if (!function_exists('erstattung_file_extension')) {
    function erstattung_file_extension(string $pfad): string {
        $pathOnly = parse_url($pfad, PHP_URL_PATH);

        if ($pathOnly === null || $pathOnly === false) {
            $pathOnly = $pfad;
        }

        $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
        return $ext !== '' ? strtoupper($ext) : 'Datei';
    }
}

if (!function_exists('erstattung_status_label')) {
    function erstattung_status_label(int $status): string {
        if ($status === 1) {
            return 'überwiesen';
        }

        if ($status === -1) {
            return 'abgelehnt';
        }

        return 'offen';
    }
}

if (!function_exists('erstattung_status_class')) {
    function erstattung_status_class(int $status): string {
        if ($status === 1) {
            return 'refund-status-accepted';
        }

        if ($status === -1) {
            return 'refund-status-declined';
        }

        return 'refund-status-open';
    }
}

if (!function_exists('erstattung_purchase_status_label')) {
    function erstattung_purchase_status_label(string $status): string {
        if ($status === 'geschlossen') {
            return 'geschlossen';
        }

        if ($status === 'abgelehnt') {
            return 'abgelehnt';
        }

        return 'gestellt';
    }
}

if (!function_exists('erstattung_purchase_status_class')) {
    function erstattung_purchase_status_class(string $status): string {
        if ($status === 'geschlossen') {
            return 'refund-status-accepted';
        }

        if ($status === 'abgelehnt') {
            return 'refund-status-declined';
        }

        return 'refund-status-open';
    }
}

if (!function_exists('erstattung_group_name_by_id')) {
    function erstattung_group_name_by_id(mysqli $conn, int $groupId): string {
        if ($groupId <= 0) {
            return '';
        }

        $stmt = mysqli_prepare($conn, "SELECT name FROM `groups` WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }

        mysqli_stmt_bind_param($stmt, "i", $groupId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;

        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $row && trim((string)$row['name']) !== '' ? (string)$row['name'] : '';
    }
}

if (!function_exists('erstattung_format_einrichtung')) {
    function erstattung_format_einrichtung(string $einrichtung, mysqli $conn): string {
        if (preg_match('/^ag:(\d+)$/', $einrichtung, $m)) {
            $groupName = erstattung_group_name_by_id($conn, (int)$m[1]);
            return $groupName !== '' ? $groupName : 'AG #' . $m[1];
        }

        if (preg_match('/^etage:([a-zA-Z]+)_(\d+)$/', $einrichtung, $m)) {
            return strtoupper($m[1]) . ' Etage ' . $m[2];
        }

        return $einrichtung;
    }
}

if (!function_exists('erstattung_extract_ag_id')) {
    function erstattung_extract_ag_id(string $einrichtung): ?int {
        if (preg_match('/^ag:(\d+)$/', $einrichtung, $m)) {
            return (int)$m[1];
        }

        return null;
    }
}

if (!function_exists('erstattung_extract_turm_etage')) {
    function erstattung_extract_turm_etage(string $einrichtung): array {
        if (preg_match('/^etage:([a-zA-Z]+)_(\d+)$/', $einrichtung, $m)) {
            return [strtolower($m[1]), (int)$m[2]];
        }

        return ['', null];
    }
}

if (!function_exists('erstattung_short_name')) {
    function erstattung_short_name(string $name, string $firstname = '', string $lastname = ''): string {
        $name = trim($name);

        if ($name !== '') {
            return $name;
        }

        $combined = trim($firstname . ' ' . $lastname);
        return $combined !== '' ? $combined : '-';
    }
}

if (!function_exists('erstattung_is_authorized')) {
    function erstattung_is_authorized(): bool {
        return !empty($_SESSION['valid'])
            && (
                (!empty($_SESSION['Vorstand']) && (int)$_SESSION['Vorstand'] > 0)
                || (!empty($_SESSION['Webmaster']) && (int)$_SESSION['Webmaster'] > 0)
            );
    }
}

if (!function_exists('erstattung_fetch_purchase_request')) {
    function erstattung_fetch_purchase_request(mysqli $conn, ?int $purchaseId): ?array {
        if ($purchaseId === null || $purchaseId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                ea.id,
                ea.uid,
                ea.ag_id,
                ea.tstamp,
                ea.titel,
                ea.beschreibung,
                ea.maxbetrag,
                ea.status,
                g.name AS ag_name,
                applicant.name AS applicant_name,
                ea.vorstand_uid_1,
                ea.vorstand_uid_1_tstamp,
                ea.vorstand_decision_1,
                v1.name AS vorstand_name_1,
                ea.vorstand_uid_2,
                ea.vorstand_uid_2_tstamp,
                ea.vorstand_decision_2,
                v2.name AS vorstand_name_2,
                ea.vorstand_uid_3,
                ea.vorstand_uid_3_tstamp,
                ea.vorstand_decision_3,
                v3.name AS vorstand_name_3,
                ea.vorstand_uid_4,
                ea.vorstand_uid_4_tstamp,
                ea.vorstand_decision_4,
                v4.name AS vorstand_name_4,
                ea.vorstand_uid_5,
                ea.vorstand_uid_5_tstamp,
                ea.vorstand_decision_5,
                v5.name AS vorstand_name_5
            FROM einkaufantraege ea
            LEFT JOIN `groups` g ON g.id = ea.ag_id
            LEFT JOIN users applicant ON applicant.uid = ea.uid
            LEFT JOIN users v1 ON v1.uid = ea.vorstand_uid_1
            LEFT JOIN users v2 ON v2.uid = ea.vorstand_uid_2
            LEFT JOIN users v3 ON v3.uid = ea.vorstand_uid_3
            LEFT JOIN users v4 ON v4.uid = ea.vorstand_uid_4
            LEFT JOIN users v5 ON v5.uid = ea.vorstand_uid_5
            WHERE ea.id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, "i", $purchaseId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;

        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        if (!$row) {
            return null;
        }

        $votes = [];

        for ($i = 1; $i <= 5; $i++) {
            $decision = $row['vorstand_decision_' . $i] ?? null;
            $vUid = $row['vorstand_uid_' . $i] ?? null;
            $vName = $row['vorstand_name_' . $i] ?? '';
            $vTstamp = (int)($row['vorstand_uid_' . $i . '_tstamp'] ?? 0);

            if ($vUid === null && $decision === null) {
                continue;
            }

            $votes[] = [
                'name' => trim((string)$vName) !== '' ? (string)$vName : ($vUid !== null ? 'UID ' . (int)$vUid : '-'),
                'decision' => $decision !== null ? (string)$decision : 'offen',
                'decisionLabel' => $decision === 'accepted' ? 'angenommen' : ($decision === 'declined' ? 'abgelehnt' : 'offen'),
                'tstamp' => $vTstamp,
                'date' => $vTstamp > 0 ? date('d.m.Y H:i', $vTstamp) : '-',
            ];
        }

        $status = (string)$row['status'];

        return [
            'tstamp' => (int)$row['tstamp'],
            'date' => date('d.m.Y H:i', (int)$row['tstamp']),
            'titel' => (string)$row['titel'],
            'beschreibung' => (string)$row['beschreibung'],
            'maxbetrag' => (float)$row['maxbetrag'],
            'maxbetragDisplay' => number_format((float)$row['maxbetrag'], 2, ',', '.') . ' €',
            'status' => $status,
            'statusLabel' => erstattung_purchase_status_label($status),
            'statusClass' => erstattung_purchase_status_class($status),
            'ag_name' => (string)($row['ag_name'] ?? ''),
            'applicant_name' => (string)($row['applicant_name'] ?? ''),
            'votes' => $votes,
        ];
    }
}

if (!function_exists('erstattung_build_request_payload')) {
    function erstattung_build_request_payload(mysqli $conn, array $row): array {
        $status = (int)$row['status'];
        $rawEinrichtung = (string)$row['einrichtung'];
        $formattedEinrichtung = erstattung_format_einrichtung($rawEinrichtung, $conn);
        $datum = date('d.m.Y', (int)$row['tstamp']);
        $datumLong = date('d.m.Y H:i', (int)$row['tstamp']);
        $betrag = (float)$row['betrag'];
        $betragDisplay = number_format($betrag, 2, ',', '.') . ' €';
        $mailto = buildUserRoomEmail($row['room'], $row['turm']);
        $agId = erstattung_extract_ag_id($rawEinrichtung);
        [$etageTurm, $etageNr] = erstattung_extract_turm_etage($rawEinrichtung);
        $einkaufantragId = isset($row['einkaufantrag_id']) && $row['einkaufantrag_id'] !== null ? (int)$row['einkaufantrag_id'] : null;
        $purchase = erstattung_fetch_purchase_request($conn, $einkaufantragId);

        return [
            'id' => (int)$row['id'],
            'uid' => (int)$row['uid'],
            'label' => $datum . ' • ' . $formattedEinrichtung,
            'datum' => $datum,
            'datumLong' => $datumLong,
            'tstamp' => (int)$row['tstamp'],
            'name' => erstattung_short_name((string)($row['name'] ?? ''), (string)($row['firstname'] ?? ''), (string)($row['lastname'] ?? '')),
            'firstname' => (string)($row['firstname'] ?? ''),
            'lastname' => (string)($row['lastname'] ?? ''),
            'iban' => (string)$row['iban'],
            'betrag' => $betrag,
            'betragDisplay' => $betragDisplay,
            'einrichtung' => $formattedEinrichtung,
            'agId' => $agId,
            'etageTurm' => $etageTurm,
            'etageNr' => $etageNr,
            'verwendungszweck' => $formattedEinrichtung . ' Erstattung',
            'pfad' => (string)$row['pfad'],
            'fileType' => erstattung_file_type((string)$row['pfad']),
            'fileExt' => erstattung_file_extension((string)$row['pfad']),
            'email' => $mailto,
            'mailto' => 'mailto:' . $mailto,
            'room' => (string)$row['room'],
            'turm' => (string)$row['turm'],
            'status' => $status,
            'statusLabel' => erstattung_status_label($status),
            'statusClass' => erstattung_status_class($status),
            'statusAgentUid' => isset($row['status_agent_uid']) && $row['status_agent_uid'] !== null ? (int)$row['status_agent_uid'] : null,
            'statusAgentName' => trim((string)($row['status_agent_name'] ?? '')),
            'purchaseRequest' => $purchase,
            'hasPurchaseLink' => $einkaufantragId !== null,
        ];
    }
}

if (!function_exists('erstattung_get_semester_options')) {
    function erstattung_get_semester_options(mysqli $conn, int $currentSemesterStart): array {
        $minSemesterStart = strtotime('01-10-2022');
        $minRes = mysqli_query($conn, "SELECT MIN(tstamp) AS min_ts FROM erstattung");

        if ($minRes) {
            $minRow = mysqli_fetch_assoc($minRes);
            if (!empty($minRow['min_ts'])) {
                $minSemesterStart = min($minSemesterStart, erstattung_unixtime2startofsemester((int)$minRow['min_ts']));
            }
            mysqli_free_result($minRes);
        }

        $semesterOptions = [];
        $ts = $currentSemesterStart;

        while ($ts >= $minSemesterStart) {
            $semesterOptions[erstattung_unixtime2semester($ts)] = $ts;

            $month = (int)date('n', $ts);
            $year = (int)date('Y', $ts);

            if ($month === 4) {
                $ts = strtotime('01-10-' . ($year - 1));
            } else {
                $ts = strtotime('01-04-' . $year);
            }
        }

        return $semesterOptions;
    }
}

if (!function_exists('erstattung_normalize_semester_start')) {
    function erstattung_normalize_semester_start(int $candidate, array $semesterOptions, int $fallback): int {
        if (in_array($candidate, array_values($semesterOptions), true)) {
            return $candidate;
        }

        return $fallback;
    }
}

if (!function_exists('erstattung_get_ag_filter_options')) {
    function erstattung_get_ag_filter_options(mysqli $conn): array {
        $options = [];
        $sql = "
            SELECT DISTINCT g.id, g.name, g.prio
            FROM `groups` g
            LEFT JOIN erstattung e ON e.einrichtung = CONCAT('ag:', g.id)
            WHERE (g.active = 1 AND g.agessen = 1)
               OR e.id IS NOT NULL
            ORDER BY COALESCE(g.prio, 999999), g.name
        ";

        $res = mysqli_query($conn, $sql);
        while ($res && $row = mysqli_fetch_assoc($res)) {
            $id = (int)$row['id'];
            $name = trim((string)$row['name']);
            if ($id > 0 && $name !== '') {
                $options[$id] = $name;
            }
        }

        if ($res) {
            mysqli_free_result($res);
        }

        return $options;
    }
}

if (!function_exists('erstattung_normalize_ag_filter')) {
    function erstattung_normalize_ag_filter($candidate, array $agOptions): int {
        $agId = (int)$candidate;
        if ($agId > 0 && array_key_exists($agId, $agOptions)) {
            return $agId;
        }

        return 0;
    }
}

if (!function_exists('erstattung_build_period_sql')) {
    function erstattung_build_period_sql(string $alias, ?int $periodStart, ?int $periodEnd): array {
        if ($periodStart === null || $periodEnd === null) {
            return ['', '', []];
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        return [" AND {$prefix}tstamp >= ? AND {$prefix}tstamp < ?", 'ii', [$periodStart, $periodEnd]];
    }
}

if (!function_exists('erstattung_bind_dynamic')) {
    function erstattung_bind_dynamic(mysqli_stmt $stmt, string $types, array $params): bool {
        if ($types === '') {
            return true;
        }

        $refs = [];
        $refs[] = $stmt;
        $refs[] = $types;

        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }

        return (bool)call_user_func_array('mysqli_stmt_bind_param', $refs);
    }
}

if (!function_exists('erstattung_filter_label')) {
    function erstattung_filter_label(string $periodLabel, int $agFilterId, array $agOptions): string {
        if ($agFilterId > 0 && isset($agOptions[$agFilterId])) {
            return $periodLabel . ' · ' . $agOptions[$agFilterId];
        }

        return $periodLabel;
    }
}

if (!function_exists('erstattung_fetch_filtered_requests')) {
    function erstattung_fetch_filtered_requests(mysqli $conn, ?int $periodStart, ?int $periodEnd, int $agFilterId): array {
        $requests = [];
        [$periodSql, $periodTypes, $periodParams] = erstattung_build_period_sql('e', $periodStart, $periodEnd);

        $agSql = '';
        $agTypes = '';
        $agParams = [];
        if ($agFilterId > 0) {
            $agSql = " AND e.einrichtung = ?";
            $agTypes = 's';
            $agParams[] = 'ag:' . $agFilterId;
        }

        $sql = "
            SELECT
                e.id,
                e.uid,
                u.name,
                u.firstname,
                u.lastname,
                e.tstamp,
                e.einrichtung,
                e.betrag,
                e.iban,
                e.pfad,
                e.status,
                e.status_agent_uid,
                status_agent.name AS status_agent_name,
                e.einkaufantrag_id,
                u.turm,
                u.room
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            LEFT JOIN users status_agent ON status_agent.uid = e.status_agent_uid
            WHERE 1 = 1
            {$periodSql}
            {$agSql}
            ORDER BY e.tstamp DESC, e.id DESC
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $requests;
        }

        $types = $periodTypes . $agTypes;
        $params = array_merge($periodParams, $agParams);
        erstattung_bind_dynamic($stmt, $types, $params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        while ($res && $row = mysqli_fetch_assoc($res)) {
            $requests[] = erstattung_build_request_payload($conn, $row);
        }

        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $requests;
    }
}

if (!function_exists('erstattung_sum_for_einrichtung')) {
    function erstattung_sum_for_einrichtung(mysqli $conn, string $einrichtung, ?int $periodStart, ?int $periodEnd): float {
        [$periodSql, $periodTypes, $periodParams] = erstattung_build_period_sql('', $periodStart, $periodEnd);
        $sql = "
            SELECT COALESCE(SUM(betrag), 0) AS summe
            FROM erstattung
            WHERE status = 1
              AND einrichtung = ?
              {$periodSql}
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0.0;
        }

        $types = 's' . $periodTypes;
        $params = array_merge([$einrichtung], $periodParams);
        erstattung_bind_dynamic($stmt, $types, $params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : ['summe' => 0];

        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return (float)($row['summe'] ?? 0);
    }
}

if (!function_exists('erstattung_fetch_chart_data')) {
    function erstattung_fetch_chart_data(mysqli $conn, ?int $periodStart, ?int $periodEnd, int $agFilterId, array $agOptions): array {
        $agLabels = [];
        $agData = [];

        foreach ($agOptions as $agId => $agName) {
            if ($agFilterId > 0 && (int)$agId !== $agFilterId) {
                continue;
            }

            $agLabels[] = (string)$agName;
            $agData[] = erstattung_sum_for_einrichtung($conn, 'ag:' . (int)$agId, $periodStart, $periodEnd);
        }

        $wehSums = array_fill(0, 18, 0.0);
        $tvkSums = array_fill(0, 16, 0.0);

        if ($agFilterId <= 0) {
            [$periodSql, $periodTypes, $periodParams] = erstattung_build_period_sql('e', $periodStart, $periodEnd);

            $stmt = mysqli_prepare($conn, "
                SELECT e.einrichtung, SUM(e.betrag) AS summe
                FROM erstattung e
                WHERE e.status = 1
                  {$periodSql}
                  AND e.einrichtung LIKE 'etage:weh\\_%'
                GROUP BY e.einrichtung
            ");

            if ($stmt) {
                erstattung_bind_dynamic($stmt, $periodTypes, $periodParams);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);

                while ($res && $r = mysqli_fetch_assoc($res)) {
                    if (preg_match('/^etage:weh_(\d+)$/', $r['einrichtung'], $m)) {
                        $idx = (int)$m[1];
                        if ($idx >= 0 && $idx <= 17) {
                            $wehSums[$idx] = (float)$r['summe'];
                        }
                    }
                }

                if ($res) {
                    mysqli_free_result($res);
                }
                mysqli_stmt_close($stmt);
            }

            $stmt = mysqli_prepare($conn, "
                SELECT e.einrichtung, SUM(e.betrag) AS summe
                FROM erstattung e
                WHERE e.status = 1
                  {$periodSql}
                  AND e.einrichtung LIKE 'etage:tvk\\_%'
                GROUP BY e.einrichtung
            ");

            if ($stmt) {
                erstattung_bind_dynamic($stmt, $periodTypes, $periodParams);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);

                while ($res && $r = mysqli_fetch_assoc($res)) {
                    if (preg_match('/^etage:tvk_(\d+)$/', $r['einrichtung'], $m)) {
                        $idx = (int)$m[1];
                        if ($idx >= 0 && $idx <= 15) {
                            $tvkSums[$idx] = (float)$r['summe'];
                        }
                    }
                }

                if ($res) {
                    mysqli_free_result($res);
                }
                mysqli_stmt_close($stmt);
            }
        }

        return [
            'agLabels' => $agLabels,
            'agData' => $agData,
            'wehSums' => array_values($wehSums),
            'tvkSums' => array_values($tvkSums),
        ];
    }
}

if (!function_exists('erstattung_load_filtered_data')) {
    function erstattung_load_filtered_data(mysqli $conn, string $periodValue, int $agFilterId, array $semesterOptions, array $agOptions): array {
        $periodStart = null;
        $periodEnd = null;
        $periodLabel = 'Alle Jahre';

        if ($periodValue !== 'all') {
            $candidate = (int)$periodValue;
            $fallback = erstattung_unixtime2startofsemester(time());
            $periodStart = erstattung_normalize_semester_start($candidate, $semesterOptions, $fallback);
            $periodEnd = erstattung_semester_end($periodStart);
            $periodValue = (string)$periodStart;
            $periodLabel = erstattung_unixtime2semester($periodStart);
        } else {
            $periodValue = 'all';
        }

        $chartData = erstattung_fetch_chart_data($conn, $periodStart, $periodEnd, $agFilterId, $agOptions);

        return [
            'periodValue' => $periodValue,
            'periodLabel' => $periodLabel,
            'filterLabel' => erstattung_filter_label($periodLabel, $agFilterId, $agOptions),
            'agFilterId' => $agFilterId,
            'agFilterLabel' => $agFilterId > 0 && isset($agOptions[$agFilterId]) ? $agOptions[$agFilterId] : 'Alle AGs',
            'requests' => erstattung_fetch_filtered_requests($conn, $periodStart, $periodEnd, $agFilterId),
            'charts' => $chartData,
        ];
    }
}

if (!function_exists('erstattung_render_refund_table')) {
    function erstattung_render_refund_table(array $requests): string {
        ob_start();
        ?>
        <?php if (count($requests) === 0): ?>
            <div class="refund-empty-table">Keine Erstattungsanträge für diesen Filter.</div>
        <?php else: ?>
            <table class="transfer-table refund-table" id="refund-history-table">
                <thead>
                    <tr>
                        <th onclick="sortRefundTable(0, this)">Datum</th>
                        <th onclick="sortRefundTable(1, this)">Name</th>
                        <th onclick="sortRefundTable(2, this)">Raum</th>
                        <th onclick="sortRefundTable(3, this)">Einrichtung</th>
                        <th onclick="sortRefundTable(4, this)">Betrag</th>
                        <th onclick="sortRefundTable(5, this)">Status</th>
                        <th onclick="sortRefundTable(6, this)">Einkaufantrag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr data-id="<?= (int)$request['id'] ?>" onclick="openRefundModal(<?= (int)$request['id'] ?>)">
                            <td data-sort="<?= (int)$request['tstamp'] ?>"><?= erstattung_h($request['datum']) ?></td>
                            <td><?= erstattung_h($request['name']) ?></td>
                            <td><?= erstattung_h($request['room']) ?> [<?= erstattung_h(strtoupper($request['turm'])) ?>]</td>
                            <td><?= erstattung_h($request['einrichtung']) ?></td>
                            <td data-sort="<?= erstattung_h((string)$request['betrag']) ?>"><?= erstattung_h($request['betragDisplay']) ?></td>
                            <td data-sort="<?= (int)$request['status'] ?>">
                                <span class="refund-status-pill <?= erstattung_h($request['statusClass']) ?>"><?= erstattung_h($request['statusLabel']) ?></span>
                            </td>
                            <td data-sort="<?= $request['purchaseRequest'] ? '1' : '0' ?>">
                                <?php if ($request['purchaseRequest']): ?>
                                    <?= erstattung_h($request['purchaseRequest']['titel']) ?>
                                <?php elseif ($request['hasPurchaseLink']): ?>
                                    Einkaufantrag nicht gefunden
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}

if (!erstattung_is_authorized()) {
    if (isset($_GET['ajax']) && ($_GET['ajax'] === 'data' || $_GET['ajax'] === 'semester')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Nicht berechtigt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: denied.php');
    exit;
}

$zeit = time();
$currentSemesterStart = erstattung_unixtime2startofsemester($zeit);
$semester_options = erstattung_get_semester_options($conn, $currentSemesterStart);
$ag_filter_options = erstattung_get_ag_filter_options($conn);

$selectedPeriodValue = 'all';
if (isset($_GET['period'])) {
    $selectedPeriodValue = (string)$_GET['period'];
} elseif (isset($_GET['semester_start'])) {
    $selectedPeriodValue = (string)$_GET['semester_start'];
}

if ($selectedPeriodValue !== 'all') {
    $selectedPeriodStart = erstattung_normalize_semester_start((int)$selectedPeriodValue, $semester_options, $currentSemesterStart);
    $selectedPeriodValue = (string)$selectedPeriodStart;
}

$selectedAgFilterId = isset($_GET['ag_id']) ? erstattung_normalize_ag_filter($_GET['ag_id'], $ag_filter_options) : 0;

if (isset($_GET['ajax']) && ($_GET['ajax'] === 'data' || $_GET['ajax'] === 'semester')) {
    header('Content-Type: application/json; charset=utf-8');

    $data = erstattung_load_filtered_data($conn, $selectedPeriodValue, $selectedAgFilterId, $semester_options, $ag_filter_options);

    echo json_encode(
        [
            'ok' => true,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
    exit;
}

$pageData = erstattung_load_filtered_data($conn, $selectedPeriodValue, $selectedAgFilterId, $semester_options, $ag_filter_options);
$semesterRequests = $pageData['requests'];
$currentPeriodLabel = $pageData['periodLabel'];
$currentFilterLabel = $pageData['filterLabel'];
$agLabels = $pageData['charts']['agLabels'];
$agData = $pageData['charts']['agData'];
$wehSums = $pageData['charts']['wehSums'];
$tvkSums = $pageData['charts']['tvkSums'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <link rel="stylesheet" href="TRANSFERS.css" media="screen">

    <style>
        :root {
            --refund-primary: #11a50d;
            --refund-bg: #121212;
            --refund-panel: #181818;
            --refund-panel-2: #202020;
            --refund-field: #2b2b2b;
            --refund-border: #444;
            --refund-border-soft: #333;
            --refund-border-strong: rgba(17, 165, 13, 0.55);
            --refund-text: #f2f2f2;
            --refund-muted: #aaa;
            --refund-danger: #ff5252;
            --refund-warning: #E49B0F;
            --refund-radius: 13px;
        }

        body {
            background-color: var(--refund-bg);
            color: var(--refund-text);
            font-family: sans-serif;
        }

        .charts-wrapper {
            display: flex;
            justify-content: center;
            margin: 2em 0;
            padding: 0 2em;
        }

        .charts-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2em;
            width: 100%;
            max-width: 1500px;
        }

        .chart-box {
            flex: 1 1 calc(50% - 1em);
            min-width: 420px;
            height: 250px;
            background: var(--refund-panel);
            border: 1px solid var(--refund-border-soft);
            border-radius: 8px;
            padding: 12px;
            box-sizing: border-box;
        }

        .chart-box-wide {
            flex-basis: 100%;
        }

        .chart-box canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .refund-history-wrapper {
            max-width: 1500px;
            margin: 0 auto 3em auto;
            padding: 0 2em;
            box-sizing: border-box;
        }

        .refund-history-wrapper.is-loading {
            opacity: 0.65;
            pointer-events: none;
        }

        .refund-history-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin: 0 0 12px 0;
        }

        .refund-history-title {
            font-size: 1.35em;
            font-weight: 800;
            color: #fff;
        }

        .refund-history-subtitle {
            margin-top: 3px;
            font-size: 0.9em;
            color: var(--refund-muted);
            font-weight: 500;
        }

        .refund-history-controls {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            min-width: 180px;
        }

        .refund-semester-select,
        .refund-ag-select,
        .semester-dropdown.refund-semester-select,
        .semester-dropdown.refund-ag-select {
            width: 190px;
        }

        .transfer-table.refund-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--refund-panel);
            border: 1px solid var(--refund-border-soft);
            border-radius: 8px;
            overflow: hidden;
        }

        .transfer-table.refund-table th,
        .transfer-table.refund-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: left;
            vertical-align: middle;
        }

        .transfer-table.refund-table th {
            cursor: pointer;
            user-select: none;
            background: #202020;
            color: #fff;
            font-weight: 900;
        }

        .transfer-table.refund-table tbody tr {
            cursor: pointer;
        }

        .transfer-table.refund-table tbody tr:hover {
            background: rgba(17, 165, 13, 0.16);
        }

        .refund-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 86px;
            padding: 0.24em 0.56em;
            border-radius: 999px;
            border: 1px solid #555;
            font-size: 0.84em;
            font-weight: 900;
            white-space: nowrap;
        }

        .refund-status-open {
            color: #ffd27a;
            border-color: rgba(228, 155, 15, 0.8);
            background: rgba(228, 155, 15, 0.12);
        }

        .refund-status-accepted {
            color: #9dff9a;
            border-color: rgba(17, 165, 13, 0.85);
            background: rgba(17, 165, 13, 0.12);
        }

        .refund-status-declined {
            color: #ff9a9d;
            border-color: rgba(165, 13, 17, 0.85);
            background: rgba(165, 13, 17, 0.14);
        }

        .refund-empty-table {
            background: var(--refund-panel);
            border: 1px solid var(--refund-border-soft);
            border-radius: 8px;
            padding: 1.4em;
            text-align: center;
            color: var(--refund-muted);
        }

        .refund-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.74);
            z-index: 9998;
            backdrop-filter: blur(2px);
        }

        .refund-overlay.is-open {
            display: block;
        }

        .refund-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            width: min(1420px, calc(100vw - 72px));
            height: min(720px, calc(100vh - 72px));
            overflow: hidden;
            box-sizing: border-box;
            background: linear-gradient(180deg, #222 0%, #181717 100%);
            border: 1px solid var(--refund-border-strong);
            border-radius: var(--refund-radius);
            box-shadow: 0 24px 90px rgba(0, 0, 0, 0.75);
            color: var(--refund-text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .refund-modal.is-open {
            display: flex;
            flex-direction: column;
        }

        .refund-modal-header {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 18px;
            border-bottom: 1px solid var(--refund-border);
            background: rgba(17, 165, 13, 0.08);
        }

        .refund-modal-title-block {
            min-width: 0;
        }

        .refund-modal-kicker {
            color: var(--refund-primary);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .refund-modal-title {
            margin: 0;
            color: #fff;
            font-size: 20px;
            line-height: 1.12;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .refund-modal-subtitle {
            margin-top: 4px;
            color: var(--refund-muted);
            font-size: 12px;
        }

        .refund-modal-close {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #2b2b2b;
            color: #fff;
            border: 1px solid var(--refund-border);
            border-radius: 999px;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
        }

        .refund-modal-close:hover {
            background: rgba(255, 82, 82, 0.12);
            color: var(--refund-danger);
            border-color: rgba(255, 82, 82, 0.6);
        }

        .refund-modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            padding: 14px;
            box-sizing: border-box;
        }

        .refund-modal-layout {
            display: grid;
            grid-template-columns: minmax(500px, 1.55fr) minmax(280px, 0.82fr) minmax(280px, 0.82fr);
            gap: 14px;
            height: 100%;
            min-height: 0;
            align-items: stretch;
        }

        .refund-modal-card {
            min-height: 0;
            background: rgba(32, 32, 32, 0.96);
            border: 1px solid var(--refund-border);
            border-radius: 11px;
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .refund-card-header {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 13px;
            border-bottom: 1px solid var(--refund-border);
            background: rgba(255, 255, 255, 0.025);
        }

        .refund-card-title {
            margin: 0;
            color: #fff;
            font-size: 14px;
            font-weight: 900;
        }

        .refund-card-note {
            color: var(--refund-muted);
            font-size: 11px;
            white-space: nowrap;
        }

        .refund-card-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 10px 12px;
            box-sizing: border-box;
        }

        .refund-receipt-card {
            min-width: 0;
        }

        .refund-receipt-preview {
            flex: 1 1 auto;
            min-height: 0;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
        }

        .refund-receipt-img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            background: #111;
        }

        .refund-receipt-frame {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
            background: #111;
        }

        .refund-receipt-empty,
        .refund-receipt-fallback {
            padding: 20px;
            text-align: center;
            color: var(--refund-muted);
            line-height: 1.45;
        }

        .refund-receipt-actions {
            flex: 0 0 auto;
            display: flex;
            justify-content: center;
            padding: 9px 12px;
            border-top: 1px solid var(--refund-border);
            background: #1c1c1c;
        }

        .refund-link-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border: 1px solid var(--refund-border-strong);
            border-radius: 8px;
            background: rgba(17, 165, 13, 0.12);
            color: #fff;
            font-size: 12px;
            font-weight: 900;
            text-decoration: none;
        }

        .refund-link-button:hover {
            background: var(--refund-primary);
            color: #000;
        }

        .refund-fields {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .refund-field {
            display: grid;
            grid-template-columns: 98px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding-bottom: 7px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.055);
        }

        .refund-field:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .refund-label {
            color: var(--refund-muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            line-height: 1.25;
            padding-top: 7px;
        }

        .refund-readonly {
            min-height: 17px;
            background: var(--refund-field);
            color: #fff;
            border: 1px solid var(--refund-border);
            border-radius: 8px;
            padding: 7px 9px;
            font-size: 13px;
            line-height: 1.32;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .refund-readonly--muted {
            color: var(--refund-muted);
        }

        .refund-vote-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .refund-vote-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
            background: var(--refund-field);
            border: 1px solid var(--refund-border);
            border-radius: 8px;
            padding: 7px 8px;
        }

        .refund-vote-name {
            color: #fff;
            font-weight: 800;
            min-width: 0;
            font-size: 13px;
            line-height: 1.25;
        }

        .refund-vote-date {
            display: block;
            color: var(--refund-muted);
            font-weight: 500;
            font-size: 0.82em;
            margin-top: 1px;
        }

        .refund-vote-pill {
            border-radius: 999px;
            padding: 3px 7px;
            font-weight: 900;
            font-size: 0.78em;
            border: 1px solid #555;
            white-space: nowrap;
        }

        .refund-vote-accepted {
            color: #9dff9a;
            border-color: rgba(17, 165, 13, 0.85);
            background: rgba(17, 165, 13, 0.12);
        }

        .refund-vote-declined {
            color: #ff9a9d;
            border-color: rgba(165, 13, 17, 0.85);
            background: rgba(165, 13, 17, 0.14);
        }

        .refund-vote-open {
            color: #ddd;
            border-color: #555;
            background: rgba(255, 255, 255, 0.06);
        }

        @media (max-width: 1180px) {
            .refund-modal {
                width: calc(100vw - 40px);
                height: calc(100vh - 40px);
            }

            .refund-modal-layout {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }

            .refund-modal-card {
                min-height: 280px;
            }

            .refund-receipt-card {
                min-height: 420px;
            }
        }

        @media (max-width: 980px) {
            .chart-box {
                min-width: 100%;
            }

            .refund-history-head {
                align-items: stretch;
                flex-direction: column;
            }

            .refund-history-controls {
                justify-content: flex-start;
            }
        }

        @media (max-width: 620px) {
            .refund-modal {
                width: calc(100vw - 18px);
                height: calc(100vh - 18px);
            }

            .refund-modal-header {
                padding: 11px 12px;
            }

            .refund-modal-body {
                padding: 10px;
            }

            .refund-field {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .refund-label {
                padding-top: 0;
            }
        }
    </style>
</head>

<body>
<?php
require_once('template.php');
if (function_exists('load_menu')) {
    load_menu();
}
?>

<div class="charts-wrapper">
    <div class="charts-grid">
        <div class="chart-box">
            <canvas id="chartWeh"></canvas>
        </div>

        <div class="chart-box">
            <canvas id="chartTvk"></canvas>
        </div>

        <div class="chart-box chart-box-wide">
            <canvas id="chartAG"></canvas>
        </div>
    </div>
</div>

<div class="refund-history-wrapper" id="refundHistoryWrapper">
    <div class="refund-history-head">
        <div>
            <div class="refund-history-title">Bisherige Erstattungsanträge</div>
            <div class="refund-history-subtitle">Anzeige für <span id="refund-filter-label"><?= erstattung_h($currentFilterLabel) ?></span>. Eintrag anklicken für Rechnung, Erstattungsdaten und ggf. Einkaufantrag.</div>
        </div>

        <div class="refund-history-controls">
            <select id="refund-period-select" name="erstattung_period" class="semester-dropdown refund-semester-select" aria-label="Zeitraum">
                <option value="all" <?= $selectedPeriodValue === 'all' ? 'selected' : '' ?>>Alle Jahre</option>
                <?php foreach ($semester_options as $label => $start_ts): ?>
                    <option value="<?= (int)$start_ts ?>" <?= ((string)$start_ts === (string)$selectedPeriodValue) ? 'selected' : '' ?>><?= erstattung_h($label) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="refund-ag-select" name="erstattung_ag_id" class="semester-dropdown refund-ag-select" aria-label="AG">
                <option value="0" <?= $selectedAgFilterId === 0 ? 'selected' : '' ?>>Alle AGs</option>
                <?php foreach ($ag_filter_options as $ag_id => $ag_name): ?>
                    <option value="<?= (int)$ag_id ?>" <?= ((int)$ag_id === (int)$selectedAgFilterId) ? 'selected' : '' ?>><?= erstattung_h($ag_name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="refund-history-content">
        <?= erstattung_render_refund_table($semesterRequests) ?>
    </div>
</div>

<div id="refundOverlay" class="refund-overlay" onclick="closeRefundModal()"></div>

<div id="refundModal" class="refund-modal" role="dialog" aria-modal="true" aria-labelledby="refundModalTitle">
    <div class="refund-modal-header">
        <div class="refund-modal-title-block">
            <div class="refund-modal-kicker" id="refundModalKicker">Erstattungsantrag</div>
            <h2 class="refund-modal-title" id="refundModalTitle">-</h2>
            <div class="refund-modal-subtitle" id="refundModalSubtitle">-</div>
        </div>
        <button type="button" class="refund-modal-close" onclick="closeRefundModal()" aria-label="Modal schließen">&times;</button>
    </div>

    <div class="refund-modal-body">
        <div class="refund-modal-layout">
            <section class="refund-modal-card refund-receipt-card">
                <div class="refund-card-header">
                    <h3 class="refund-card-title">Rechnung</h3>
                    <span class="refund-card-note" id="refundReceiptNote">Datei</span>
                </div>
                <div id="refundReceiptPreview" class="refund-receipt-preview">
                    <div class="refund-receipt-empty">Keine Rechnung ausgewählt.</div>
                </div>
                <div class="refund-receipt-actions" id="refundReceiptActions"></div>
            </section>

            <section class="refund-modal-card refund-data-card">
                <div class="refund-card-header">
                    <h3 class="refund-card-title">Erstattung</h3>
                </div>
                <div class="refund-card-scroll">
                    <div class="refund-fields" id="refundDataFields"></div>
                </div>
            </section>

            <section class="refund-modal-card refund-data-card">
                <div class="refund-card-header">
                    <h3 class="refund-card-title">Einkaufantrag</h3>
                </div>
                <div class="refund-card-scroll">
                    <div class="refund-fields" id="refundPurchaseFields"></div>
                </div>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let refundRequests = <?= json_encode(
    $semesterRequests,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;
let refundRequestsById = {};
let chartWeh = null;
let chartTvk = null;
let chartAG = null;

function setRefundRequests(requests) {
    refundRequests = Array.isArray(requests) ? requests : [];
    refundRequestsById = {};

    refundRequests.forEach(function(request) {
        refundRequestsById[String(request.id)] = request;
    });
}

setRefundRequests(refundRequests);

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function fieldHtml(label, value, raw) {
    const normalized = (value === null || value === undefined || value === '') ? '-' : value;
    const safeValue = raw ? String(normalized) : escapeHtml(normalized);

    return `
        <div class="refund-field">
            <div class="refund-label">${escapeHtml(label)}</div>
            <div class="refund-readonly">${safeValue}</div>
        </div>
    `;
}

function emptyFieldHtml(text) {
    return `
        <div class="refund-field">
            <div class="refund-label">Hinweis</div>
            <div class="refund-readonly refund-readonly--muted">${escapeHtml(text)}</div>
        </div>
    `;
}

function statusPillHtml(label, className) {
    return '<span class="refund-status-pill ' + escapeHtml(className) + '">' + escapeHtml(label) + '</span>';
}

function renderVotes(purchaseRequest) {
    if (!purchaseRequest || !Array.isArray(purchaseRequest.votes) || purchaseRequest.votes.length === 0) {
        return '<div class="refund-readonly refund-readonly--muted">Keine Vorstandsvoten gespeichert.</div>';
    }

    return `
        <div class="refund-vote-list">
            ${purchaseRequest.votes.map(function(vote) {
                let voteClass = 'refund-vote-open';
                if (vote.decision === 'accepted') {
                    voteClass = 'refund-vote-accepted';
                } else if (vote.decision === 'declined') {
                    voteClass = 'refund-vote-declined';
                }

                return `
                    <div class="refund-vote-row">
                        <div class="refund-vote-name">
                            ${escapeHtml(vote.name)}
                            <span class="refund-vote-date">${escapeHtml(vote.date)}</span>
                        </div>
                        <div class="refund-vote-pill ${voteClass}">${escapeHtml(vote.decisionLabel)}</div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

function renderReceipt(request) {
    const preview = document.getElementById('refundReceiptPreview');
    const actions = document.getElementById('refundReceiptActions');
    const note = document.getElementById('refundReceiptNote');
    const fileUrl = getSafeDocumentUrl(request.pfad);

    preview.innerHTML = '';
    actions.innerHTML = '';
    note.textContent = request.fileExt || 'Datei';

    if (!fileUrl) {
        preview.innerHTML = '<div class="refund-receipt-empty">Keine Rechnung hinterlegt.</div>';
        return;
    }

    if (request.fileType === 'image') {
        const img = document.createElement('img');
        img.className = 'refund-receipt-img';
        img.src = fileUrl;
        img.alt = 'Rechnung';
        preview.appendChild(img);
    } else if (request.fileType === 'pdf') {
        const object = document.createElement('object');
        object.className = 'refund-receipt-frame';
        object.type = 'application/pdf';
        object.data = fileUrl + '#toolbar=1&navpanes=0';
        object.innerHTML = '<div class="refund-receipt-fallback">PDF-Vorschau konnte nicht geladen werden.</div>';
        preview.appendChild(object);
    } else {
        preview.innerHTML = '<div class="refund-receipt-fallback">Für diesen Dateityp ist keine eingebettete Vorschau verfügbar.</div>';
    }

    const link = document.createElement('a');
    link.className = 'refund-link-button';
    link.href = fileUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Rechnung separat öffnen';
    actions.appendChild(link);
}

function renderRefundFields(request) {
    const statusHtml = statusPillHtml(request.statusLabel, request.statusClass);

    document.getElementById('refundDataFields').innerHTML = [
        fieldHtml('Status', statusHtml, true),
        fieldHtml('Entschieden von', request.statusAgentName || (Number(request.status) === 0 ? 'Noch offen' : '-')),
        fieldHtml('Datum', request.datumLong),
        fieldHtml('Name', request.name),
        fieldHtml('Raum', (request.room || '-') + ' [' + String(request.turm || '').toUpperCase() + ']'),
        fieldHtml('Einrichtung', request.einrichtung),
        fieldHtml('Betrag', request.betragDisplay),
        fieldHtml('IBAN', request.iban),
        fieldHtml('Zweck', request.verwendungszweck)
    ].join('');
}

function renderPurchaseFields(request) {
    const purchase = request.purchaseRequest;

    if (!purchase) {
        const text = request.hasPurchaseLink
            ? 'Verknüpfter Einkaufantrag wurde nicht gefunden.'
            : 'Kein Einkaufantrag verknüpft.';
        document.getElementById('refundPurchaseFields').innerHTML = emptyFieldHtml(text);
        return;
    }

    const statusHtml = statusPillHtml(purchase.statusLabel, purchase.statusClass);

    document.getElementById('refundPurchaseFields').innerHTML = [
        fieldHtml('Status', statusHtml, true),
        fieldHtml('Titel', purchase.titel),
        fieldHtml('Antragsteller', purchase.applicant_name || '-'),
        fieldHtml('AG', purchase.ag_name || '-'),
        fieldHtml('Datum', purchase.date),
        fieldHtml('Maxbetrag', purchase.maxbetragDisplay),
        fieldHtml('Beschreibung', purchase.beschreibung || '-'),
        '<div class="refund-field"><div class="refund-label">Vorstand</div><div>' + renderVotes(purchase) + '</div></div>'
    ].join('');
}

function getSafeDocumentUrl(url) {
    const cleaned = String(url || '').trim();

    if (
        cleaned === ''
        || /^javascript:/i.test(cleaned)
        || /^data:/i.test(cleaned)
        || /^vbscript:/i.test(cleaned)
    ) {
        return '';
    }

    return cleaned;
}

function openRefundModal(id) {
    const request = refundRequestsById[String(id)];

    if (!request) {
        return;
    }

    document.getElementById('refundModalKicker').textContent = 'Erstattungsantrag · ' + request.statusLabel;
    document.getElementById('refundModalTitle').textContent = request.name + ' · ' + request.betragDisplay;
    document.getElementById('refundModalSubtitle').textContent = request.datumLong + ' · ' + request.einrichtung;

    renderReceipt(request);
    renderRefundFields(request);
    renderPurchaseFields(request);

    document.getElementById('refundOverlay').classList.add('is-open');
    document.getElementById('refundModal').classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeRefundModal() {
    document.getElementById('refundOverlay').classList.remove('is-open');
    document.getElementById('refundModal').classList.remove('is-open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeRefundModal();
    }
});

function sortRefundTable(columnIndex, header) {
    const table = document.getElementById('refund-history-table');
    if (!table) {
        return;
    }

    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);
    const currentDir = header.dataset.sortDir === 'asc' ? 'desc' : 'asc';

    table.querySelectorAll('th').forEach(function(th) {
        delete th.dataset.sortDir;
    });
    header.dataset.sortDir = currentDir;

    rows.sort(function(a, b) {
        const aCell = a.cells[columnIndex];
        const bCell = b.cells[columnIndex];
        const aRaw = aCell ? (aCell.dataset.sort ?? aCell.textContent.trim()) : '';
        const bRaw = bCell ? (bCell.dataset.sort ?? bCell.textContent.trim()) : '';
        const aNum = parseFloat(String(aRaw).replace(',', '.'));
        const bNum = parseFloat(String(bRaw).replace(',', '.'));

        let result;
        if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) {
            result = aNum - bNum;
        } else {
            result = String(aRaw).localeCompare(String(bRaw), 'de', { numeric: true, sensitivity: 'base' });
        }

        return currentDir === 'asc' ? result : -result;
    });

    rows.forEach(function(row) {
        tbody.appendChild(row);
    });
}

function renderRefundHistoryTable(requests) {
    const target = document.getElementById('refund-history-content');

    if (!Array.isArray(requests) || requests.length === 0) {
        target.innerHTML = '<div class="refund-empty-table">Keine Erstattungsanträge für diesen Filter.</div>';
        return;
    }

    const rows = requests.map(function(request) {
        const purchaseText = request.purchaseRequest
            ? escapeHtml(request.purchaseRequest.titel)
            : (request.hasPurchaseLink ? 'Einkaufantrag nicht gefunden' : '-');

        return `
            <tr data-id="${Number(request.id) || 0}" onclick="openRefundModal(${Number(request.id) || 0})">
                <td data-sort="${Number(request.tstamp) || 0}">${escapeHtml(request.datum)}</td>
                <td>${escapeHtml(request.name)}</td>
                <td>${escapeHtml(request.room)} [${escapeHtml(String(request.turm || '').toUpperCase())}]</td>
                <td>${escapeHtml(request.einrichtung)}</td>
                <td data-sort="${Number(request.betrag) || 0}">${escapeHtml(request.betragDisplay)}</td>
                <td data-sort="${Number(request.status) || 0}">
                    ${statusPillHtml(request.statusLabel, request.statusClass)}
                </td>
                <td data-sort="${request.purchaseRequest ? '1' : '0'}">${purchaseText}</td>
            </tr>
        `;
    }).join('');

    target.innerHTML = `
        <table class="transfer-table refund-table" id="refund-history-table">
            <thead>
                <tr>
                    <th onclick="sortRefundTable(0, this)">Datum</th>
                    <th onclick="sortRefundTable(1, this)">Name</th>
                    <th onclick="sortRefundTable(2, this)">Raum</th>
                    <th onclick="sortRefundTable(3, this)">Einrichtung</th>
                    <th onclick="sortRefundTable(4, this)">Betrag</th>
                    <th onclick="sortRefundTable(5, this)">Status</th>
                    <th onclick="sortRefundTable(6, this)">Einkaufantrag</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
}

Chart.defaults.color = '#e0e0e0';
Chart.defaults.borderColor = '#333';

const wehLabels = Array.from({ length: 18 }, function(_, i) { return i.toString(); });
const tvkLabels = Array.from({ length: 16 }, function(_, i) { return i.toString(); });

function makeBarChart(ctxId, labels, data, title, color) {
    return new Chart(document.getElementById(ctxId).getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '€',
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: title
                },
                legend: {
                    display: false
                }
            }
        }
    });
}

function makeFloorChart(ctxId, labels, data, title, color) {
    return new Chart(document.getElementById(ctxId).getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '€',
                    data: data,
                    backgroundColor: color,
                    borderColor: color,
                    borderWidth: 1
                },
                {
                    type: 'line',
                    label: 'Limit 170€',
                    data: labels.map(function() { return 170; }),
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
            animation: false,
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: title
                },
                legend: {
                    display: false
                }
            }
        }
    });
}

function updateChartData(chart, labels, data, title) {
    chart.data.labels = labels;
    chart.data.datasets[0].data = data;
    chart.options.plugins.title.text = title;

    if (chart.data.datasets[1]) {
        chart.data.datasets[1].data = labels.map(function() { return 170; });
    }

    chart.update('none');
}

function updateFilterView(data) {
    const semesterLabel = data.periodLabel || data.semesterLabel || '-';
    setRefundRequests(data.requests || []);
    renderRefundHistoryTable(refundRequests);

    document.getElementById('refund-filter-label').textContent = data.filterLabel || semesterLabel;

    updateChartData(chartWeh, wehLabels, data.charts.wehSums || [], 'WEH Etagen 0-17 (€) · ' + (data.filterLabel || semesterLabel));
    updateChartData(chartTvk, tvkLabels, data.charts.tvkSums || [], 'TVK Etagen 0-15 (€) · ' + (data.filterLabel || semesterLabel));
    updateChartData(chartAG, data.charts.agLabels || [], data.charts.agData || [], 'AG-Erstattungen (€) · ' + (data.filterLabel || semesterLabel));
}

async function loadRefundData() {
    const wrapper = document.getElementById('refundHistoryWrapper');
    const periodSelect = document.getElementById('refund-period-select');
    const agSelect = document.getElementById('refund-ag-select');
    const url = new URL(window.location.href);

    url.searchParams.set('ajax', 'data');
    url.searchParams.set('period', String(periodSelect.value));
    url.searchParams.set('ag_id', String(agSelect.value));
    url.searchParams.delete('semester_start');

    wrapper.classList.add('is-loading');
    periodSelect.disabled = true;
    agSelect.disabled = true;

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });

        const payload = await response.json();

        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Filter konnten nicht geladen werden.');
        }

        updateFilterView(payload.data);
    } catch (error) {
        alert(error.message || 'Filter konnten nicht geladen werden.');
    } finally {
        wrapper.classList.remove('is-loading');
        periodSelect.disabled = false;
        agSelect.disabled = false;
    }
}

document.getElementById('refund-period-select').addEventListener('change', loadRefundData);
document.getElementById('refund-ag-select').addEventListener('change', loadRefundData);

chartWeh = makeFloorChart(
    'chartWeh',
    wehLabels,
    <?= json_encode(array_values($wehSums), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    'WEH Etagen 0-17 (€) · <?= erstattung_h($currentFilterLabel) ?>',
    '#11a50d'
);

chartTvk = makeFloorChart(
    'chartTvk',
    tvkLabels,
    <?= json_encode(array_values($tvkSums), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    'TVK Etagen 0-15 (€) · <?= erstattung_h($currentFilterLabel) ?>',
    '#E49B0F'
);

chartAG = makeBarChart(
    'chartAG',
    <?= json_encode($agLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    <?= json_encode($agData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    'AG-Erstattungen (€) · <?= erstattung_h($currentFilterLabel) ?>',
    '#007bff'
);
</script>

</body>
</html>
