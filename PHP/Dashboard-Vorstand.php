<?php
session_start();

require_once('conn.php');
date_default_timezone_set('Europe/Berlin');

ob_start();
require_once('template.php');
$dv_template_output = ob_get_clean();

mysqli_set_charset($conn, "utf8");

function dv_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dv_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dv_stmt_rows(mysqli_stmt $stmt): array
{
    if (function_exists('get_result')) {
        $rows = get_result($stmt);
        return is_array($rows) ? $rows : [];
    }

    if (function_exists('mysqli_stmt_get_result')) {
        $result = mysqli_stmt_get_result($stmt);

        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);
        return $rows;
    }

    return [];
}

function dv_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $refs = [$types];
    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function dv_require_vorstand(mysqli $conn): void
{
    if (!auth($conn) || empty($_SESSION['valid']) || (empty($_SESSION['Vorstand']) && empty($_SESSION['Webmaster']))) {
        dv_json(['ok' => false, 'error' => 'Nicht berechtigt.'], 403);
    }
}

function dv_format_turm(string $turm): string
{
    $turm = strtolower(trim($turm));

    if (function_exists('formatTurm')) {
        return formatTurm($turm);
    }

    if ($turm === 'weh') {
        return 'WEH';
    }

    if ($turm === 'tvk') {
        return 'TvK';
    }

    return strtoupper($turm);
}

function dv_money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

function dv_build_user_room_email($room, $turm): string
{
    $roomPart = str_pad((string)intval($room), 4, '0', STR_PAD_LEFT);
    $turmPart = strtolower(trim((string)$turm));
    return 'z' . $roomPart . '@' . $turmPart . '.rwth-aachen.de';
}

function dv_send_plain_mail(string $to, string $subject, string $message, string $replyTo = 'vorstand@weh.rwth-aachen.de'): bool
{
    $from = 'system@weh.rwth-aachen.de';
    $headers  = "From: WEH Backend <{$from}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    return mail($to, $subject, $message, $headers);
}

function dv_balance(mysqli $conn, int $kasse): float
{
    if (function_exists('berechneKontostand')) {
        return (float)berechneKontostand($conn, $kasse);
    }

    $sql = "SELECT COALESCE(SUM(betrag), 0) FROM transfers WHERE kasse = ?";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0.0;
    }

    mysqli_stmt_bind_param($stmt, 'i', $kasse);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sum);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (float)($sum ?? 0);
}

function dv_user_bound_sum(mysqli $conn): float
{
    $sql = "
        SELECT SUM(subquery.gesamtsumme) AS gesamtsumme_aller_benutzer
        FROM (
            SELECT SUM(t.betrag) AS gesamtsumme
            FROM weh.users u
            JOIN weh.transfers t ON t.uid = u.uid
            WHERE u.pid IN (11, 12, 13)
            GROUP BY u.uid
            HAVING gesamtsumme > 0
        ) AS subquery
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0.0;
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sum);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (float)($sum ?? 0);
}

function dv_sum_negative_transfers(mysqli $conn, int $startTs, int $endTsExcl, string $whereSql, string $whereTypes = '', array $whereParams = []): float
{
    $sql = "
        SELECT COALESCE(SUM(-t.betrag), 0)
        FROM transfers t
        WHERE t.tstamp IS NOT NULL
          AND t.tstamp >= ?
          AND t.tstamp < ?
          AND t.betrag < 0
          AND ($whereSql)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0.0;
    }

    $types = 'ii' . $whereTypes;
    $params = array_merge([$startTs, $endTsExcl], $whereParams);
    dv_bind_params($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sum);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (float)($sum ?? 0);
}

function dv_current_year_balance(mysqli $conn): float
{
    $year = intval(date('Y'));
    $startTs = strtotime($year . '-01-01 00:00:00');
    $endTsExcl = strtotime(($year + 1) . '-01-01 00:00:00');

    $internalIds = [];
    $pairStartTs = $startTs - 259200;
    $pairEndTsExcl = $endTsExcl + 259200;

    $sqlPairs = "
        SELECT DISTINCT a.id AS neg_id, b.id AS pos_id
        FROM transfers a
        JOIN transfers b
            ON b.id <> a.id
            AND b.tstamp IS NOT NULL
            AND b.tstamp BETWEEN (a.tstamp - 259200) AND (a.tstamp + 259200)
            AND b.betrag > 0
            AND b.betrag BETWEEN (ABS(a.betrag) - 0.005) AND (ABS(a.betrag) + 0.005)
            AND ((a.kasse = 69 AND b.kasse = 72) OR (a.kasse = 72 AND b.kasse = 92))
        WHERE a.tstamp IS NOT NULL
          AND a.tstamp >= ?
          AND a.tstamp < ?
          AND a.betrag < 0
          AND a.kasse IN (69, 72)
    ";

    $stmt = mysqli_prepare($conn, $sqlPairs);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $pairStartTs, $pairEndTsExcl);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $negId, $posId);

        while (mysqli_stmt_fetch($stmt)) {
            $internalIds[(int)$negId] = true;
            $internalIds[(int)$posId] = true;
        }

        mysqli_stmt_close($stmt);
    }

    $internalPairFilter = '1=1';
    if (count($internalIds) > 0) {
        $internalPairFilter = 't.id NOT IN (' . implode(',', array_map('intval', array_keys($internalIds))) . ')';
    }

    $knownCategoryFilter = "
        NOT (
            t.beschreibung LIKE '%AG-Essen%'
            OR t.beschreibung LIKE '%Abmeldung%'
            OR (t.print_id IS NOT NULL AND t.print_id <> 0)
            OR t.beschreibung LIKE 'Abrechnung Hausbeitrag%'
            OR t.beschreibung LIKE 'Abrechnung Netzbeitrag%'
            OR t.beschreibung = 'Waschmarken generiert'
        )
    ";

    $income = 0.0;
    $income += dv_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung = 'Waschmarken generiert'");
    $income += dv_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE 'Abrechnung Netzbeitrag%'");
    $income += dv_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE 'Abrechnung Hausbeitrag%'");
    $income += dv_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.print_id IS NOT NULL AND t.print_id <> 0");

    $groupNetzAG = [1, 2, 69, 72];
    $groupHaus = [92, 93, 94, 95];

    $phNetz = implode(',', array_fill(0, count($groupNetzAG), '?'));
    $expenseNetz = dv_sum_negative_transfers(
        $conn,
        $startTs,
        $endTsExcl,
        "$internalPairFilter AND t.kasse IN ($phNetz) AND $knownCategoryFilter",
        str_repeat('i', count($groupNetzAG)),
        $groupNetzAG
    );

    $phHaus = implode(',', array_fill(0, count($groupHaus), '?'));
    $expenseHaus = dv_sum_negative_transfers(
        $conn,
        $startTs,
        $endTsExcl,
        "$internalPairFilter AND t.kasse IN ($phHaus) AND $knownCategoryFilter",
        str_repeat('i', count($groupHaus)),
        $groupHaus
    );

    $expenseAgEssen = dv_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE '%AG-Essen%'");

    return $income - ($expenseNetz + $expenseHaus + $expenseAgEssen);
}

function dv_member_count(mysqli $conn): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE pid IN (11, 12, 13) OR honory = 1";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$count;
}

function dv_get_ag_name(mysqli $conn, int $agId): string
{
    global $ag_complete;

    if (isset($ag_complete[$agId]['name'])) {
        return (string)$ag_complete[$agId]['name'];
    }

    $stmt = mysqli_prepare($conn, "SELECT name FROM `groups` WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 'AG #' . $agId;
    }

    mysqli_stmt_bind_param($stmt, 'i', $agId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name);

    if (mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        return (string)$name;
    }

    mysqli_stmt_close($stmt);
    return 'AG #' . $agId;
}

function dv_format_einrichtung(mysqli $conn, string $einrichtung): string
{
    if (function_exists('formatEinrichtung')) {
        return formatEinrichtung($einrichtung, $conn);
    }

    if (preg_match('/^ag:([0-9]+)$/', $einrichtung, $matches)) {
        return dv_get_ag_name($conn, intval($matches[1]));
    }

    if (preg_match('/^etage:(weh|tvk)_([0-9]+)$/', $einrichtung, $matches)) {
        return dv_format_turm($matches[1]) . ' ' . intval($matches[2]) . '. Etage';
    }

    return $einrichtung;
}

function dv_file_preview(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '<div class="dv-empty-note">Keine Datei hinterlegt.</div>';
    }

    $pathOnly = parse_url($path, PHP_URL_PATH);
    if ($pathOnly === null || $pathOnly === false) {
        $pathOnly = $path;
    }

    $extension = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
    $safePath = dv_h($path);

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return '<div class="dv-file-stage"><img src="' . $safePath . '" alt="Datei"></div>';
    }

    if ($extension === 'pdf') {
        return '<div class="dv-file-stage"><embed src="' . $safePath . '#zoom=page-width" type="application/pdf"></div>';
    }

    return '<a class="dv-file-link" href="' . $safePath . '" target="_blank">Datei öffnen</a>';
}

function dv_account_info(string $text): string
{
    return '<div class="dv-account-info">' . dv_h($text) . '</div>';
}

function dv_detail_rows(array $rows, string $extraClass = ''): string
{
    $class = trim('dv-detail-list ' . $extraClass);
    $html = '<div class="' . dv_h($class) . '">';

    foreach ($rows as $row) {
        $label = $row[0] ?? '';
        $value = $row[1] ?? '';
        $wideClass = !empty($row[2]) ? ' dv-detail-row-wide' : '';
        $html .= '<div class="dv-detail-row' . $wideClass . '"><span>' . dv_h($label) . '</span><strong>' . $value . '</strong></div>';
    }

    $html .= '</div>';
    return $html;
}

function dv_decision_count(array $row, string $decision): int
{
    $count = 0;

    for ($i = 1; $i <= 5; $i++) {
        $uidKey = 'vorstand_uid_' . $i;
        $decisionKey = 'vorstand_decision_' . $i;
        $vorstandUid = intval($row[$uidKey] ?? 0);
        $currentDecision = (string)($row[$decisionKey] ?? '');

        if ($decision === 'accepted' && $vorstandUid > 0 && ($currentDecision === 'accepted' || $currentDecision === '')) {
            $count++;
        }

        if ($decision === 'declined' && $vorstandUid > 0 && $currentDecision === 'declined') {
            $count++;
        }
    }

    return $count;
}

function dv_purchase_open_sql_condition(string $alias = 'ea'): string
{
    return "
        (
            (CASE WHEN {$alias}.vorstand_decision_1 = 'accepted' OR ({$alias}.vorstand_uid_1 IS NOT NULL AND {$alias}.vorstand_decision_1 IS NULL) THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_2 = 'accepted' OR ({$alias}.vorstand_uid_2 IS NOT NULL AND {$alias}.vorstand_decision_2 IS NULL) THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_3 = 'accepted' OR ({$alias}.vorstand_uid_3 IS NOT NULL AND {$alias}.vorstand_decision_3 IS NULL) THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_4 = 'accepted' OR ({$alias}.vorstand_uid_4 IS NOT NULL AND {$alias}.vorstand_decision_4 IS NULL) THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_5 = 'accepted' OR ({$alias}.vorstand_uid_5 IS NOT NULL AND {$alias}.vorstand_decision_5 IS NULL) THEN 1 ELSE 0 END)
        ) < 3
        AND
        (
            (CASE WHEN {$alias}.vorstand_decision_1 = 'declined' THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_2 = 'declined' THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_3 = 'declined' THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_4 = 'declined' THEN 1 ELSE 0 END)
          + (CASE WHEN {$alias}.vorstand_decision_5 = 'declined' THEN 1 ELSE 0 END)
        ) < 3
    ";
}

function dv_fetch_purchase_request(mysqli $conn, int $id): ?array
{
    $sql = "
        SELECT ea.*, u.name AS submitter_name, u.username AS submitter_username, u.room AS submitter_room, u.turm AS submitter_turm, g.name AS ag_name, g.mail AS ag_mail
        FROM einkaufantraege ea
        LEFT JOIN users u ON u.uid = ea.uid
        LEFT JOIN `groups` g ON g.id = ea.ag_id
        WHERE ea.id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    return $rows[0] ?? null;
}

function dv_collect_dashboard_data(mysqli $conn): array
{
    $balances = [
        72 => dv_balance($conn, 72),
        69 => dv_balance($conn, 69),
        92 => dv_balance($conn, 92),
        1  => dv_balance($conn, 1),
        2  => dv_balance($conn, 2),
        93 => dv_balance($conn, 93),
        94 => dv_balance($conn, 94),
        95 => dv_balance($conn, 95),
    ];

    $bargeld = $balances[1] + $balances[2] + $balances[93] + $balances[94];
    $hausKapital = $balances[92] + $balances[93] + $balances[94] + $balances[95];
    $netzKapital = $balances[72] + $balances[69] + $balances[1] + $balances[2];
    $gesamtGeld = $hausKapital + $netzKapital;
    $bilanz = dv_current_year_balance($conn);
    $members = dv_member_count($conn);
    $userBound = dv_user_bound_sum($conn);

    $stmt = mysqli_prepare($conn, "SELECT id, room, turm FROM registration WHERE status = 0 ORDER BY room ASC");
    mysqli_stmt_execute($stmt);
    $anmeldungen = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT a.id, u.room, u.oldroom, u.turm FROM abmeldungen a JOIN users u ON a.uid = u.uid WHERE a.status = 1 ORDER BY a.id ASC");
    mysqli_stmt_execute($stmt);
    $abmeldungen = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT id, name, betrag, netzkonto FROM unknowntransfers WHERE status = 0 ORDER BY id ASC");
    mysqli_stmt_execute($stmt);
    $transfers = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "
        SELECT a.id, a.tstamp, a.betrag, a.iban, a.ag, g.name AS ag_name
        FROM agessen a
        LEFT JOIN `groups` g ON g.id = a.ag
        WHERE a.status = 0
        ORDER BY a.tstamp DESC, a.id DESC
    ");
    mysqli_stmt_execute($stmt);
    $agessen = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "
        SELECT e.id, e.betrag, e.einrichtung, e.tstamp, u.name
        FROM erstattung e
        LEFT JOIN users u ON u.uid = e.uid
        WHERE e.status = 0
        ORDER BY e.tstamp ASC, e.id ASC
    ");
    mysqli_stmt_execute($stmt);
    $erstattungen = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $purchaseOpenCondition = dv_purchase_open_sql_condition('ea');
    $currentVorstandUid = intval($_SESSION['uid'] ?? 0);
    $stmt = mysqli_prepare($conn, "
        SELECT ea.id, ea.titel, ea.maxbetrag,
               ea.vorstand_uid_1, ea.vorstand_decision_1,
               ea.vorstand_uid_2, ea.vorstand_decision_2,
               ea.vorstand_uid_3, ea.vorstand_decision_3,
               ea.vorstand_uid_4, ea.vorstand_decision_4,
               ea.vorstand_uid_5, ea.vorstand_decision_5,
               g.name AS ag_name
        FROM einkaufantraege ea
        LEFT JOIN `groups` g ON g.id = ea.ag_id
        WHERE ea.status = 'gestellt'
          AND {$purchaseOpenCondition}
          AND NOT (
                COALESCE(ea.vorstand_uid_1, 0) = ?
             OR COALESCE(ea.vorstand_uid_2, 0) = ?
             OR COALESCE(ea.vorstand_uid_3, 0) = ?
             OR COALESCE(ea.vorstand_uid_4, 0) = ?
             OR COALESCE(ea.vorstand_uid_5, 0) = ?
          )
        ORDER BY ea.tstamp ASC, ea.id ASC
    ");
    mysqli_stmt_bind_param($stmt, 'iiiii', $currentVorstandUid, $currentVorstandUid, $currentVorstandUid, $currentVorstandUid, $currentVorstandUid);
    mysqli_stmt_execute($stmt);
    $einkaeufe = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    return [
        'generatedAt' => date(DateTime::ATOM),
        'cards' => [
            'gesamtgeld' => ['title' => 'Gesamt-Kapital', 'value' => dv_money($gesamtGeld), 'detail' => '', 'state' => $gesamtGeld >= 0 ? 'good' : 'bad'],
            'hauskapital' => ['title' => 'Haus-Kapital', 'value' => dv_money($hausKapital), 'detail' => '', 'state' => $hausKapital >= 0 ? 'good' : 'bad'],
            'netzkapital' => ['title' => 'Netz-Kapital', 'value' => dv_money($netzKapital), 'detail' => '', 'state' => $netzKapital >= 0 ? 'good' : 'bad'],
            'bilanz' => ['title' => 'Bilanz in ' . date('Y'), 'value' => ($bilanz > 0 ? '+' : '') . dv_money($bilanz), 'detail' => '', 'state' => $bilanz >= 0 ? 'good' : 'bad'],
            'bargeld' => ['title' => 'Bargeld', 'value' => dv_money($bargeld), 'detail' => '', 'state' => $bargeld >= 0 ? 'good' : 'bad'],
            'mitglieder' => ['title' => 'Anzahl Vereinsmitglieder', 'value' => (string)$members, 'detail' => '', 'state' => 'good'],
        ],
        'queues' => [
            'Anmeldung' => ['title' => 'Anmeldungen', 'type' => 'Anmeldung', 'info' => '', 'items' => array_map(static function ($entry) {
                return ['id' => intval($entry['id']), 'label' => str_pad((string)$entry['room'], 4, '0', STR_PAD_LEFT), 'sub' => dv_format_turm((string)$entry['turm']), 'tower' => (string)$entry['turm']];
            }, $anmeldungen)],
            'Abmeldung' => ['title' => 'Abmeldungen', 'type' => 'Abmeldung', 'info' => '', 'items' => array_map(static function ($entry) {
                $roomNum = ((int)$entry['room'] === 0 && !empty($entry['oldroom'])) ? (string)$entry['oldroom'] : (string)$entry['room'];
                return ['id' => intval($entry['id']), 'label' => str_pad($roomNum, 4, '0', STR_PAD_LEFT), 'sub' => dv_format_turm((string)$entry['turm']), 'tower' => (string)$entry['turm']];
            }, $abmeldungen)],
            'UnknownTransfers' => ['title' => 'Transfers', 'type' => 'UnknownTransfers', 'info' => '', 'items' => array_map(static function ($entry) {
                return ['id' => intval($entry['id']), 'label' => dv_money((float)$entry['betrag']), 'sub' => ((int)$entry['netzkonto'] === 1 ? 'Netzkonto' : 'Hauskonto') . ' · ' . (string)$entry['name'], 'tower' => 'weh'];
            }, $transfers)],
            'AGEssen' => ['title' => 'AG-Essen', 'type' => 'AGEssen', 'info' => '', 'items' => array_map(static function ($entry) {
                return ['id' => intval($entry['id']), 'label' => dv_money((float)$entry['betrag']), 'sub' => (string)($entry['ag_name'] ?? ('AG #' . $entry['ag'])), 'tower' => 'weh'];
            }, $agessen)],
            'Erstattung' => ['title' => 'Erstattung', 'type' => 'Erstattung', 'info' => '', 'items' => array_map(static function ($entry) use ($conn) {
                return ['id' => intval($entry['id']), 'label' => dv_money((float)$entry['betrag']), 'sub' => dv_format_einrichtung($conn, (string)$entry['einrichtung']), 'tower' => 'weh'];
            }, $erstattungen)],
            'Einkauf' => ['title' => 'Einkäufe', 'type' => 'Einkauf', 'info' => '', 'items' => array_map(static function ($entry) {
                $accepted = dv_decision_count($entry, 'accepted');
                $declined = dv_decision_count($entry, 'declined');
                return ['id' => intval($entry['id']), 'label' => (string)$entry['titel'], 'sub' => dv_money((float)$entry['maxbetrag']) . ' · ' . $accepted . '/3 ja · ' . $declined . '/3 nein', 'tower' => 'weh'];
            }, $einkaeufe)],
        ],
    ];
}

function dv_search_users(mysqli $conn): void
{
    $searchTerm = trim((string)($_GET['search'] ?? ''));

    if ($searchTerm === '') {
        dv_json([]);
    }

    $like = '%' . $searchTerm . '%';
    $searchedusers = [];

    if (ctype_digit($searchTerm)) {
        $sql = "
            SELECT uid, name, username, room, oldroom, turm, groups
            FROM users
            WHERE pid IN (11, 64)
              AND (name LIKE ? OR room = ? OR oldroom = ?)
            ORDER BY FIELD(turm, 'weh', 'tvk'), room
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $like, $searchTerm, $searchTerm);
    } else {
        $sql = "
            SELECT uid, name, username, room, oldroom, turm, groups
            FROM users
            WHERE pid IN (11, 64)
              AND name LIKE ?
            ORDER BY FIELD(turm, 'weh', 'tvk'), room
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $like);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm, $groups);

    while (mysqli_stmt_fetch($stmt)) {
        $searchedusers[$uid][] = ['uid' => $uid, 'name' => $name, 'username' => $username, 'room' => $room, 'oldroom' => $oldroom, 'turm' => $turm, 'groups' => $groups];
    }

    mysqli_stmt_close($stmt);
    dv_json($searchedusers);
}

function dv_modal_shell(string $title, string $body, string $class = ''): string
{
    $modalClass = trim('dv-modal ' . $class);

    return '<div class="dv-modal-backdrop" data-dv-close="1"></div>
        <div class="' . dv_h($modalClass) . '">
            <div class="dv-modal-head">
                <div class="dv-modal-title">' . dv_h($title) . '</div>
                <button type="button" class="dv-modal-close" data-dv-close="1">×</button>
            </div>
            <div class="dv-modal-body">' . $body . '</div>
        </div>';
}

function dv_modal_registration(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM registration WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $user = $rows[0] ?? null;
    if (!$user) {
        return dv_modal_shell('Anmeldung', '<p class="dv-modal-error">Anmeldung nicht gefunden.</p>');
    }

    $stmt = mysqli_prepare($conn, "
        SELECT lastradius, username, name, uid, pid
        FROM users
        WHERE room = ? AND turm = ?
        ORDER BY FIELD(pid, 11, 12, 64, 13), uid
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'is', $user['room'], $user['turm']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $lastradius, $currentUsername, $currentName, $currentUid, $currentPid);
    $roomOccupied = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    $requiresRoomTakeover = $roomOccupied && intval($currentPid) === 11;

    $lastRadiusString = '-';
    $lastRadiusColor = '#cccccc';

    if ($roomOccupied) {
        if ((int)$lastradius === 0) {
            $lastRadiusString = 'Noch nie';
            $lastRadiusColor = '#ff5555';
        } else {
            $delta = time() - (int)$lastradius + 3600;
            $days = floor($delta / 86400);
            $hours = floor(($delta % 86400) / 3600);

            if ($days <= 0 && $hours <= 0) {
                $lastRadiusString = 'Verbunden';
                $lastRadiusColor = '#ff5555';
            } elseif ($days > 0) {
                $lastRadiusString = $days . ' Tage, ' . $hours . ' Stunden';
                $lastRadiusColor = '#ffd166';
            } else {
                $lastRadiusString = $hours . ' Stunden';
                $lastRadiusColor = '#ff5555';
            }
        }
    }

    $uploadDir = 'anmeldung/';
    $userId = intval($user['id']);
    $documentLabels = ['id' => 'Ausweis', 'mv' => 'Mietvertrag', 'af' => 'Anmeldung'];
    $documents = [];

    if (is_dir($uploadDir)) {
        $files = array_diff(scandir($uploadDir), ['.', '..']);
        foreach ($files as $file) {
            if (preg_match("/^{$userId}_(id|mv|af)\.(.+)$/", $file, $matches)) {
                $documents[$matches[1]] = ['label' => $documentLabels[$matches[1]], 'path' => $uploadDir . $file, 'extension' => strtolower($matches[2])];
            }
        }
    }

    $firstDocument = null;
    foreach (array_keys($documentLabels) as $type) {
        if (isset($documents[$type])) {
            $firstDocument = $type;
            break;
        }
    }

    ob_start();
    ?>
    <form method="post" class="dv-action-form dv-registration-form">
        <input type="hidden" name="id" value="<?= dv_h($id) ?>">
        <input type="hidden" name="username" value="<?= dv_h($user['username']) ?>">
        <input type="hidden" name="requires_room_takeover" value="<?= $requiresRoomTakeover ? '1' : '0' ?>">

        <?= dv_account_info('Anmeldung / Benutzerverwaltung. Es wird kein Zahlungskonto belastet. Accept legt den User an und sendet Zugangsdaten per Mail. Decline lehnt mit Mail und Grund ab. Remove entfernt doppelte oder fehlerhafte Anmeldungen ohne Mail.') ?>

        <?php if (!empty($user['sublet'])): ?>
            <div class="dv-alert dv-alert-warn">SUBLET · Ende: <?= dv_h(date('d.m.Y', intval($user['subletterend']))) ?></div>
        <?php endif; ?>

        <?php if ($roomOccupied): ?>
            <div class="dv-alert <?= $requiresRoomTakeover ? 'dv-alert-bad' : 'dv-alert-warn' ?>">
                Raum ist bereits belegt.<br>
                Aktueller User: <?= dv_h($currentName) ?> · <?= dv_h($currentUsername) ?> · UID <?= dv_h($currentUid) ?> · PID <?= dv_h($currentPid) ?><br>
                Letzter Radius Auth: <span style="color: <?= dv_h($lastRadiusColor) ?>"><?= dv_h($lastRadiusString) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($requiresRoomTakeover): ?>
            <label class="dv-danger-confirm">
                <input type="checkbox" name="confirm_room_takeover" value="1">
                Ich bestätige ausdrücklich, dass dadurch ein noch wohnender User seinen Raumbezug/Account verlieren kann.
            </label>
        <?php endif; ?>

        <div class="dv-modal-split dv-registration-split">
            <div>
                <div class="dv-document-tabs">
                    <?php foreach ($documentLabels as $type => $label): ?>
                        <button type="button" class="dv-doc-tab<?= $type === $firstDocument ? ' dv-active' : '' ?>" data-dv-doc-tab="<?= dv_h($type) ?>" <?= isset($documents[$type]) ? '' : 'disabled' ?>><?= dv_h($label) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="dv-document-stage">
                    <?php if (!is_dir($uploadDir)): ?>
                        <p class="dv-modal-error">Das Verzeichnis <?= dv_h($uploadDir) ?> existiert nicht.</p>
                    <?php elseif (empty($documents)): ?>
                        <p class="dv-empty-note">Keine Dateien für Anmeldung #<?= dv_h($id) ?> gefunden.</p>
                    <?php else: ?>
                        <?php foreach ($documents as $type => $document): ?>
                            <div class="dv-document-frame <?= $type === $firstDocument ? 'dv-active' : '' ?>" data-dv-doc-panel="<?= dv_h($type) ?>">
                                <?php if (in_array($document['extension'], ['jpg', 'jpeg', 'png', 'gif'], true)): ?>
                                    <img src="<?= dv_h($document['path']) ?>" alt="<?= dv_h($document['label']) ?>">
                                <?php elseif ($document['extension'] === 'pdf'): ?>
                                    <embed src="<?= dv_h($document['path']) ?>#zoom=page-width" type="application/pdf">
                                <?php else: ?>
                                    <a href="<?= dv_h($document['path']) ?>" target="_blank">Datei öffnen</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <?= dv_detail_rows([
                    ['Name', dv_h(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))],
                    ['Username', dv_h($user['username'])],
                    ['Turm', dv_h(dv_format_turm($user['turm']))],
                    ['Zimmer laut Antrag', dv_h($user['room'])],
                    ['Einzug', dv_h(date('d.m.Y', intval($user['starttime'])))],
                    ['E-Mail', dv_h($user['email'])],
                    ['Geburtstag', !empty($user['geburtstag']) ? dv_h(date('d.m.Y', intval($user['geburtstag']))) : '-'],
                    ['Telefon', dv_h($user['telefon'])],
                ]) ?>

                <label class="dv-full-label">Zimmer für Accept
                    <input type="text" name="room_override" class="dv-input" value="<?= dv_h($user['room']) ?>" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4" autocomplete="off">
                </label>

                <div class="dv-radio-row">
                    <label><input type="radio" name="decision" value="accept"> <span class="dv-green">ACCEPT</span></label>
                    <label><input type="radio" name="decision" value="decline"> <span class="dv-red">DECLINE</span></label>
                    <label><input type="radio" name="decision" value="remove"> <span class="dv-warn">REMOVE</span></label>
                </div>

                <label class="dv-full-label dv-decline-reason">Grund für Ablehnung
                    <input type="text" name="kommentar" class="dv-input" placeholder="Falsches Dokument / Falscher Raum ..." disabled>
                </label>

                <button type="submit" class="dv-submit">Hau raus!</button>
            </div>
        </div>
    </form>
    <?php
    return dv_modal_shell('Anmeldung prüfen', ob_get_clean(), 'dv-wide-modal');
}

function dv_modal_abmeldung(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "
        SELECT u.name, u.uid, u.room, u.oldroom, u.turm, a.betrag, a.iban
        FROM abmeldungen a
        JOIN users u ON a.uid = u.uid
        WHERE a.id = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $user = $rows[0] ?? null;
    if (!$user) {
        return dv_modal_shell('Abmeldung', '<p class="dv-modal-error">Abmeldung nicht gefunden.</p>');
    }

    $betrag = number_format((float)$user['betrag'], 2, ',', '.');

    ob_start();
    ?>
    <form method="post" class="dv-action-form">
        <input type="hidden" name="abmeldung_finish" value="1">
        <input type="hidden" name="abmeldung_id" value="<?= dv_h($id) ?>">

        <?= dv_account_info('Abmeldung: Restbudget des Users vom Netzkonto DE90 3905 0000 1070 3346 00 an die gewünschte IBAN zurücküberweisen.') ?>

        <?= dv_detail_rows([
            ['Name', dv_h($user['name'])],
            ['UID', dv_h($user['uid'])],
            ['Turm', dv_h(dv_format_turm($user['turm']))],
            ['Raum', dv_h(((int)$user['room'] === 0 && !empty($user['oldroom'])) ? $user['oldroom'] : $user['room'])],
            ['IBAN', dv_h($user['iban'])],
            ['Betrag', dv_h($betrag) . ' €'],
        ]) ?>

        <div class="dv-copy-list">
            <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($user['iban']) ?>"><span>IBAN</span><?= dv_h($user['iban']) ?></button>
            <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($user['name']) ?>"><span>Name</span><?= dv_h($user['name']) ?></button>
            <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($betrag) ?>"><span>Betrag</span><?= dv_h($betrag) ?> €</button>
            <button type="button" class="dv-copy-btn" data-copy="Abmeldung WEH e.V."><span>Zweck</span>Abmeldung WEH e.V.</button>
        </div>

        <button type="submit" class="dv-submit">Überwiesen</button>
    </form>
    <?php
    return dv_modal_shell('Abmeldung auszahlen', ob_get_clean());
}

function dv_modal_transfer(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM unknowntransfers WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $transfer = $rows[0] ?? null;
    if (!$transfer) {
        return dv_modal_shell('Transfer', '<p class="dv-modal-error">Transfer nicht gefunden.</p>');
    }

    ob_start();
    ?>
    <form method="post" class="dv-action-form dv-transfer-form">
        <input type="hidden" name="transfer_zuweisen_check" value="user">
        <input type="hidden" name="transfer_id" value="<?= dv_h($id) ?>">
        <input type="hidden" name="selected_uid" class="dv-selected-uid">

        <?= dv_account_info('Transfer: Usertransfer ohne korrekte Transfer Reference. Anhand Name/Betreff manuell einem User zuweisen. Zielkonto richtet sich nach dem Originaleintrag.') ?>

        <?= dv_detail_rows([
            ['Name', dv_h($transfer['name'] ?? '')],
            ['Betreff', dv_h($transfer['betreff'] ?? '')],
            ['Konto', !empty($transfer['netzkonto']) ? 'Netzkonto' : 'Hauskonto'],
            ['Betrag', dv_h($transfer['betrag'] ?? '') . ' €'],
        ]) ?>

        <div class="dv-button-row">
            <button type="button" class="dv-small-btn dv-select-dummy" data-uid="472" data-label="Netzwerk-AG Dummy">Netz</button>
            <button type="button" class="dv-small-btn dv-select-dummy" data-uid="492" data-label="Haussprecher Dummy">Haus</button>
        </div>

        <label class="dv-full-label">Nutzer suchen
            <input type="text" class="dv-user-search dv-input" placeholder="Name oder Zimmer..." autocomplete="off">
        </label>

        <div class="dv-search-results"></div>

        <button type="submit" class="dv-submit dv-assign-button" disabled>Zuweisen</button>
    </form>
    <?php
    return dv_modal_shell('Unklare Zahlung zuweisen', ob_get_clean());
}

function dv_modal_agessen(mysqli $conn, int $id): string
{
    global $ag_complete;

    $ibanLabels = ['Bar 1' => 'Netzbarkasse 1', 'Bar 2' => 'Netzbarkasse 2', 'Bar 93' => 'Kassenwartkasse 1', 'Bar 94' => 'Kassenwartkasse 2'];

    $stmt = mysqli_prepare($conn, "
        SELECT a.id, a.tstamp, a.pfad, a.betrag, a.iban, a.ag, a.uid,
            (SELECT CONCAT(SUBSTRING_INDEX(u2.firstname, ' ', 1), ' ', SUBSTRING_INDEX(u2.lastname, ' ', 1)) FROM weh.users u2 WHERE u2.uid = a.uid) AS full_name,
            GROUP_CONCAT(CONCAT(SUBSTRING_INDEX(u.firstname, ' ', 1), ' ', LEFT(u.lastname, 1), '.') ORDER BY FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) SEPARATOR ', ') AS teilnehmer_namen
        FROM weh.agessen a
        JOIN weh.users u ON FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) > 0
        WHERE a.id = ? AND a.status = 0
        GROUP BY a.id
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $row = $rows[0] ?? null;
    if (!$row) {
        return dv_modal_shell('AG-Essen', '<p class="dv-modal-error">AG-Essen-Antrag nicht gefunden oder bereits verarbeitet.</p>');
    }

    $agId = intval($row['ag']);
    $agName = $ag_complete[$agId]['name'] ?? dv_get_ag_name($conn, $agId);
    $betrag = number_format((float)$row['betrag'], 2, ',', '.');
    $ibanShow = $ibanLabels[$row['iban']] ?? $row['iban'];

    ob_start();
    ?>
    <form method="post" class="dv-action-form">
        <input type="hidden" name="agessen_confirm" value="1">
        <input type="hidden" name="id" value="<?= dv_h($row['id']) ?>">
        <input type="hidden" name="pfad" value="<?= dv_h($row['pfad']) ?>">
        <input type="hidden" name="betrag" value="<?= dv_h($row['betrag']) ?>">
        <input type="hidden" name="iban" value="<?= dv_h($row['iban']) ?>">
        <input type="hidden" name="ag" value="<?= dv_h($row['ag']) ?>">
        <input type="hidden" name="uid" value="<?= dv_h($row['uid']) ?>">

        <?= dv_account_info('AG-Essen: hochgeladene Rechnung vom Hauskonto DE37 3905 0000 1070 3345 84 erstatten. Bei Bar-Auswahl wird wie in AG-Essen.php die entsprechende Barkasse benutzt.') ?>

        <div class="dv-modal-split">
            <div><?= dv_file_preview($row['pfad']) ?></div>
            <div>
                <?= dv_detail_rows([
                    ['Datum', dv_h(date('d.m.Y', intval($row['tstamp'])))],
                    ['AG', dv_h($agName)],
                    ['Betrag', dv_h($betrag) . ' €'],
                    ['IBAN / Kasse', dv_h($ibanShow)],
                    ['Kontoinhaber', dv_h($row['full_name'])],
                    ['Teilnehmer', dv_h($row['teilnehmer_namen'])],
                ]) ?>

                <div class="dv-copy-list">
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($row['iban']) ?>"><span>IBAN/Kasse</span><?= dv_h($ibanShow) ?></button>
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($row['full_name']) ?>"><span>Name</span><?= dv_h($row['full_name']) ?></button>
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($betrag) ?>"><span>Betrag</span><?= dv_h($betrag) ?> €</button>
                    <button type="button" class="dv-copy-btn" data-copy="AG-Essen <?= dv_h($agName) ?>"><span>Zweck</span>AG-Essen <?= dv_h($agName) ?></button>
                </div>

                <button type="submit" class="dv-submit">Überwiesen</button>
            </div>
        </div>
    </form>
    <?php
    return dv_modal_shell('AG-Essen erstatten', ob_get_clean(), 'dv-wide-modal');
}

function dv_modal_erstattung(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "
        SELECT e.id, e.uid, e.tstamp, e.einrichtung, e.betrag, e.iban, e.pfad, u.name, u.turm, u.room
        FROM erstattung e
        JOIN users u ON e.uid = u.uid
        WHERE e.id = ? AND e.status = 0
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $rows = dv_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $row = $rows[0] ?? null;
    if (!$row) {
        return dv_modal_shell('Erstattung', '<p class="dv-modal-error">Erstattungsantrag nicht gefunden oder bereits verarbeitet.</p>');
    }

    $einrichtung = dv_format_einrichtung($conn, (string)$row['einrichtung']);
    $betrag = number_format((float)$row['betrag'], 2, ',', '.');
    $email = dv_build_user_room_email($row['room'], $row['turm']);

    ob_start();
    ?>
    <form method="post" class="dv-action-form">
        <input type="hidden" name="erstattung_action" value="1">
        <input type="hidden" name="request_id" value="<?= dv_h($row['id']) ?>">

        <?= dv_account_info('Erstattung: hochgeladene Rechnung vom Hauskonto DE37 3905 0000 1070 3345 84 an die angegebene IBAN erstatten.') ?>

        <div class="dv-modal-split">
            <div><?= dv_file_preview($row['pfad']) ?></div>
            <div>
                <?= dv_detail_rows([
                    ['Datum', dv_h(date('d.m.Y', intval($row['tstamp'])))],
                    ['Einrichtung', dv_h($einrichtung)],
                    ['Name', dv_h($row['name'])],
                    ['IBAN', dv_h($row['iban'])],
                    ['Betrag', dv_h($betrag) . ' €'],
                    ['E-Mail', dv_h($email)],
                ]) ?>

                <div class="dv-copy-list">
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($row['iban']) ?>"><span>IBAN</span><?= dv_h($row['iban']) ?></button>
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($row['name']) ?>"><span>Name</span><?= dv_h($row['name']) ?></button>
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($betrag) ?>"><span>Betrag</span><?= dv_h($betrag) ?> €</button>
                    <button type="button" class="dv-copy-btn" data-copy="<?= dv_h($einrichtung) ?> Erstattung"><span>Zweck</span><?= dv_h($einrichtung) ?> Erstattung</button>
                </div>

                <div class="dv-button-row">
                    <button type="submit" name="action" value="accept" class="dv-submit dv-green-submit">Überwiesen</button>
                    <button type="submit" name="action" value="decline" class="dv-submit dv-red-submit">Ablehnen</button>
                </div>
            </div>
        </div>
    </form>
    <?php
    return dv_modal_shell('Erstattung bearbeiten', ob_get_clean(), 'dv-wide-modal');
}

function dv_modal_einkauf(mysqli $conn, int $id): string
{
    $row = dv_fetch_purchase_request($conn, $id);
    if (!$row) {
        return dv_modal_shell('Einkaufsantrag', '<p class="dv-modal-error">Einkaufsantrag nicht gefunden.</p>');
    }

    ob_start();
    ?>
    <form method="post" class="dv-action-form">
        <input type="hidden" name="purchase_decision" value="1">
        <input type="hidden" name="purchase_id" value="<?= dv_h($id) ?>">

        <?= dv_account_info('Einkauf: AG-Antrag für Kauf über 100 € oder unsicheren Zweck. Bei dritter Zustimmung/Ablehnung wird automatisch eine Mail an die AG-Adresse aus groups.mail verschickt.') ?>

        <?= dv_detail_rows([
            ['Titel', dv_h($row['titel'])],
            ['AG', dv_h($row['ag_name'] ?: ('AG #' . intval($row['ag_id'])))],
            ['Maximalbetrag', dv_h(dv_money((float)$row['maxbetrag']))],
            ['Eingereicht von', dv_h($row['submitter_name'] ?: ('UID ' . intval($row['uid'])))],
            ['Datum', dv_h(date('d.m.Y H:i', intval($row['tstamp'])))],
            ['AG-Mail', dv_h($row['ag_mail'] ?: 'Keine Mail hinterlegt')],
            ['Beschreibung', nl2br(dv_h($row['beschreibung'])), true],
        ], 'dv-detail-list-two-cols') ?>

        <div class="dv-approval-row">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <?php
                $uidKey = 'vorstand_uid_' . $i;
                $decisionKey = 'vorstand_decision_' . $i;
                $vorstandUid = intval($row[$uidKey] ?? 0);
                $decision = (string)($row[$decisionKey] ?? '');
                $boxClass = '';
                $label = 'Offen';

                if ($vorstandUid > 0 && ($decision === 'accepted' || $decision === '')) {
                    $boxClass = 'accepted';
                    $label = 'Zusage';
                } elseif ($vorstandUid > 0 && $decision === 'declined') {
                    $boxClass = 'declined';
                    $label = 'Ablehnung';
                }
                ?>
                <div class="dv-approval-box <?= dv_h($boxClass) ?>">Vorstand <?= intval($i) ?><br><?= dv_h($label) ?></div>
            <?php endfor; ?>
        </div>


        <div class="dv-button-row">
            <button type="submit" name="decision" value="accepted" class="dv-submit dv-green-submit">Genehmigen</button>
            <button type="submit" name="decision" value="declined" class="dv-submit dv-red-submit">Ablehnen</button>
        </div>
    </form>
    <?php
    return dv_modal_shell('Einkaufsantrag entscheiden', ob_get_clean());
}

function dv_modal(mysqli $conn): void
{
    $type = (string)($_GET['type'] ?? '');
    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        dv_json(['ok' => false, 'error' => 'Ungültige ID.'], 400);
    }

    $html = match ($type) {
        'Anmeldung' => dv_modal_registration($conn, $id),
        'Abmeldung' => dv_modal_abmeldung($conn, $id),
        'UnknownTransfers' => dv_modal_transfer($conn, $id),
        'AGEssen' => dv_modal_agessen($conn, $id),
        'Erstattung' => dv_modal_erstattung($conn, $id),
        'Einkauf' => dv_modal_einkauf($conn, $id),
        default => dv_modal_shell('Unbekannt', '<p class="dv-modal-error">Unbekannter Vorgang.</p>'),
    };

    dv_json(['ok' => true, 'html' => $html]);
}

function dv_handle_transfer_action(mysqli $conn, array &$terminal): void
{
    $uid = intval($_POST['selected_uid'] ?? 0);
    $transferId = intval($_POST['transfer_id'] ?? 0);

    if ($uid <= 0 || $transferId <= 0) {
        dv_json(['ok' => false, 'error' => 'Transfer oder Nutzer fehlt.'], 400);
    }

    $stmt = mysqli_prepare($conn, "SELECT betrag, netzkonto, betreff FROM unknowntransfers WHERE id = ? AND status = 0 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $transferId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $betrag, $netzkonto, $betreff);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        dv_json(['ok' => false, 'error' => 'Transfer nicht gefunden oder bereits verarbeitet.'], 404);
    }

    $betrag = floatval(str_replace(',', '.', (string)$betrag));
    $konto = 4;
    $kasse = ((int)$netzkonto === 1) ? 72 : 92;
    $beschreibung = 'Transfer';
    $zeit = time();
    $changelog = '[' . date('d.m.Y H:i') . "] Insert durch manuelle Zuordnung des Transfers\n";
    $agent = intval($_SESSION['uid'] ?? 0);

    $stmt = mysqli_prepare($conn, "UPDATE unknowntransfers SET status = 1, uid = ?, agent = ? WHERE id = ? AND status = 0");
    mysqli_stmt_bind_param($stmt, 'iii', $uid, $agent, $transferId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, changelog) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iisiids', $uid, $zeit, $beschreibung, $konto, $kasse, $betrag, $changelog);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $terminal[] = "Transfer #{$transferId} wurde UID {$uid} zugewiesen und in transfers eingetragen.";
    dv_json(['ok' => true, 'message' => 'Transfer zugewiesen.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function dv_handle_abmeldung_action(mysqli $conn, array &$terminal): void
{
    $abmeldungId = intval($_POST['abmeldung_id'] ?? 0);

    if ($abmeldungId <= 0) {
        dv_json(['ok' => false, 'error' => 'Abmeldung fehlt.'], 400);
    }

    $stmt = mysqli_prepare($conn, "UPDATE abmeldungen SET status = 2 WHERE id = ? AND status = 1");
    mysqli_stmt_bind_param($stmt, 'i', $abmeldungId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $terminal[] = "Abmeldung #{$abmeldungId} wurde nach Rücküberweisung auf status=2 gesetzt.";
    dv_json(['ok' => true, 'message' => 'Abmeldung abgeschlossen.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function dv_handle_registration_action(mysqli $conn, array &$terminal): void
{
    global $mailconfig;

    $decision = (string)($_POST['decision'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    $address = $mailconfig['address'] ?? 'system@weh.rwth-aachen.de';

    if ($id <= 0 || !in_array($decision, ['accept', 'decline', 'remove'], true)) {
        dv_json(['ok' => false, 'error' => 'Ungültige Anmeldung-Aktion.'], 400);
    }

    if ($decision === 'accept') {
        $username = (string)($_POST['username'] ?? '');
        $agent = (string)($_SESSION['username'] ?? '');
        $roomOverride = trim((string)($_POST['room_override'] ?? ''));

        $sql = "
            SELECT room, firstname, lastname, starttime, geburtsort, email, geburtstag, telefon, forwardemail, sublet, subletterend, turm
            FROM registration
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname, $starttime, $geburtsort, $email, $geburtstag, $telefon, $forwardemail, $sublet, $subletterend, $turm);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$found) {
            dv_json(['ok' => false, 'error' => 'Anmeldung nicht gefunden.'], 404);
        }

        $registrationRoom = (string)$room;

        if ($roomOverride !== '') {
            $roomOverride = preg_replace('/\s+/', '', $roomOverride);
            if (!ctype_digit($roomOverride) || strlen($roomOverride) > 4) {
                dv_json(['ok' => false, 'error' => 'Ungültiger Raum.'], 400);
            }
            $room = intval($roomOverride);
        } else {
            $room = intval($room);
        }

        if ($room <= 0 || $room > 9999) {
            dv_json(['ok' => false, 'error' => 'Ungültiger Raum.'], 400);
        }

        $stmt = mysqli_prepare($conn, "SELECT uid, name, username FROM users WHERE room = ? AND turm = ? AND pid = 11 LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'is', $room, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $existingUid, $existingName, $existingUsername);
        $occupiedByActiveUser = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($occupiedByActiveUser && empty($_POST['confirm_room_takeover'])) {
            dv_json(['ok' => false, 'error' => 'Dieser Raum ist noch durch einen aktiven Bewohner belegt. Bitte explizit bestätigen.'], 409);
        }

        $restrictedNames = ['netag','waschen','sprecher','community','important','essential','kasse','werkzeug','ags','hausmeister','mailer-daemon','nobody','news','daemon','security','root','clamav','mail','postmaster','hostmaster','virusalert','www','www2','www-data','www2-data','dns','ftp','usenet','noc','abuse','syslog','nagios','domain','drucker','spam','ftp-admin','kontowecker','info','netz-ag','netzag','netz','netzwerk-ag','netzwerkag','netzwerk','buchungssystem','cloud','no-reply','noreply','wlan','ipv6','cacti','graph','system','verwaltung','kamera','lernraum','net','haussprecher','vorstand','pappnasen','wag','werkzeuge','werkzeugbuchung','spuelen','wasch'];
        for ($etage = 0; $etage <= 17; $etage++) {
            for ($zimmer = 1; $zimmer <= 16; $zimmer++) {
                $restrictedNames[] = 'z' . str_pad((string)$etage, 2, '0', STR_PAD_LEFT) . str_pad((string)$zimmer, 2, '0', STR_PAD_LEFT);
            }
            $restrictedNames[] = 'etage' . str_pad((string)$etage, 2, '0', STR_PAD_LEFT);
        }

        $uniqueUsername = false;
        while (!$uniqueUsername) {
            $stmt = mysqli_prepare($conn, "SELECT 1 FROM users WHERE username = ? OR (FIND_IN_SET(?, aliase) > 0 AND mailisactive = 1)");
            mysqli_stmt_bind_param($stmt, 'ss', $username, $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                mysqli_stmt_close($stmt);
                $username .= '0';
                continue;
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "SELECT 1 FROM `groups` WHERE FIND_IN_SET(?, aliase) > 0");
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                mysqli_stmt_close($stmt);
                $username .= '0';
                continue;
            }
            mysqli_stmt_close($stmt);

            if (in_array($username, $restrictedNames, true)) {
                $username .= '0';
                continue;
            }

            $uniqueUsername = true;
        }

        $name = $firstname . ' ' . $lastname;
        $groups = 1;
        $subtenanttill = ($subletterend === null) ? 0 : $subletterend;
        $historieAgent = $_SESSION['agent'] ?? $_SESSION['username'] ?? $agent;
        $historie = date('d.m.Y') . ' Anmeldung bestätigt (' . $historieAgent . ')';
        $subnet = function_exists('getRoomSubnet') ? getRoomSubnet($conn, $room, $turm) : false;

        if ($subnet === false) {
            dv_json(['ok' => false, 'error' => 'Kein Subnetz für Raum ' . $room . ' (' . dv_format_turm($turm) . ') gefunden.'], 500);
        }

        $pwwifi = function_exists('pwgen') ? pwgen() : bin2hex(random_bytes(5));
        $pwhausunhashed = function_exists('pwgen') ? pwgen() : bin2hex(random_bytes(5));
        $pwhaus = function_exists('pwhash') ? pwhash($pwhausunhashed) : password_hash($pwhausunhashed, PASSWORD_DEFAULT);

        if ((string)$sublet === '0' && function_exists('roomcheck')) {
            roomcheck($conn, $room, $turm);
        } elseif ((string)$sublet === '1' && function_exists('subletcheck')) {
            subletcheck($conn, $room, $turm, $subletterend);
        }

        $sql = "
            INSERT INTO users SET
                username = ?, room = ?, name = ?, firstname = ?, lastname = ?, groups = ?, starttime = ?, subtenanttill = ?, geburtstag = ?, geburtsort = ?, telefon = ?, email = ?, forwardemail = ?, historie = ?, subnet = ?, pwhaus = ?, pwwifi = ?, turm = ?
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssssssiiisssisssss', $username, $room, $name, $firstname, $lastname, $groups, $starttime, $subtenanttill, $geburtstag, $geburtsort, $telefon, $email, $forwardemail, $historie, $subnet, $pwhaus, $pwwifi, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $uid = mysqli_insert_id($conn);

        if (function_exists('addPrivateIPs')) {
            addPrivateIPs($conn, $uid, $subnet);
        }

        $stmt = mysqli_prepare($conn, "UPDATE registration SET status = 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $uploadDir = 'anmeldung/';
        $userDir = $uploadDir . $username . '/';
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }

        foreach (glob($uploadDir . $id . '_*') as $file) {
            if (is_file($file)) {
                $newFileName = preg_replace("/^{$id}_/", $username . '_', basename($file));
                rename($file, $userDir . $newFileName);
            }
        }

        $message = "Dear " . $firstname . ",\n\nyour registration was successful.\n\n"
            . "Credentials:\n\n House-Username: " . $username . "\n House-Password: " . $pwhausunhashed . "\n\n";

        if ($turm != 'tvk') {
            $message .= " WiFiOnly-Username: " . $username . "@weh.rwth-aachen.de\n WiFiOnly-Password: " . $pwwifi . "\n\n";
            $message .= "Connecting to the internet:\n"
                . " 1. Connect your device to tuermeroam.\n"
                . " 1.1. Wireless: Connect your device to the Wi-Fi network 'tuermeroam' with your Wi-Fi-Only credentials.\n"
                . " 1.2. Wired: Connect your device to the network socket in your room using a common ethernet cable. If you have two outlets; only one of these actually works. Most often it's the one closer to the window.\n"
                . " 2. Open a web browser and navigate to the following web page: getnet.weh.ac\n"
                . " 3. Log in with your House credentials. These credentials are also used for every other login at a WEH service.\n"
                . " 4. If this is the first device you're registering, you need to change your password. After you have changed it, please return to this web site: getnet.weh.ac. The Wi-Fi-Only password was not changed by this!\n"
                . " 5. Choose any free IP address. Which one you choose is irrelevant, but you should use one device per IP address. After up to 10 minutes your device will be connected. If needed, you can ask the Netzwerk-AG for more IPs.\n\n"
                . "We also want to point out:\n"
                . " • It is not allowed to have your own Wi-Fi network in the tower. These networks interfere with the already existing tuermeroam network. Netzwerk-AG is always working on improving the connection for every room in the tower.\n"
                . " • There are some Smart-Home devices and gaming consoles that don't support our security protocol WPA2 Enterprise. We set up the parallel network weh-pskonly for these. If you want to connect your device with this network, please use this page backend.weh.rwth-aachen.de/PSK.php\n"
                . " • Before you ask, take a look at the FAQ on our website first! www2.weh.rwth-aachen.de/en/faq/\n"
                . " • Sharing your login data with other residents is not allowed and may lead to a penalty of 150€.\n\n"
                . "Paying your membership fees:\n"
                . " • Your WEH account is also a prepaid account for all services within WEH. You can use the money to purchase washing coins, use the printer or pay your membership fees.\n"
                . " • Membership fees are automatically debited from your WEH account on the 1st of each month. If you don't have sufficient funds, a warning email will be sent to you before the billing cycle.\n"
                . " • So make sure there's always enough money on your account for your membership fees or you risk an internet ban.\n"
                . " • You can top up your account via bank transfer or PayPal on this page: backend.weh.rwth-aachen.de/UserKonto.php\n\n";
        } else {
            $message .= " WiFiOnly-Username: " . $username . "\n WiFiOnly-Password: " . $pwwifi . "\n\n"
                . "=== IMPORTANT: Temporary Information for TvK Residents ===\n\n"
                . "Your WiFiOnly-Username is only used for the temporary network 'fijiroam' - not for 'tuermeroam'!\n"
                . "You will receive more information as soon as 'tuermeroam' becomes available in TvK. Until then, please use the 'fijiroam' WiFi network.\n\n"
                . "=== END OF TEMPORARY MESSAGE FOR TvK RESIDENTS ===\n\n";
            $message .= "Connecting to the internet:\n"
                . " 1. Connect your device to fijiroam.\n"
                . " 1.1. Wireless: Connect your device to the Wi-Fi network 'fijiroam' with your Wi-Fi-Only credentials.\n"
                . " 1.2. Wired: Connect your device to the network socket in your room using a common ethernet cable. If you have two outlets; only one of these actually works. Most often it's the one closer to the window.\n"
                . " 2. Open a web browser and navigate to the following web page: backend.weh.rwth-aachen.de/denied.php\n"
                . " 3. Log in with your House credentials. These credentials are also used for every other login at a WEH service.\n"
                . " 4. Enter the TAN that was sent to your E-Mail!\n"
                . " 5. Navigate to 'Netz' -> 'IP Management'\n"
                . " 6.1. Register the MAC-Address of your devices on your IPs. You can only use one device per IP at a time, so it's safe to register each device on a different IP!\n"
                . " 6.2. After up to 10 minutes your device will be connected. If needed, you can ask the Netzwerk-AG for more IPs.\n\n"
                . "We also want to point out:\n"
                . " • It is not allowed to have your own Wi-Fi network in the tower. These networks interfere with the already existing tuermeroam network. Netzwerk-AG is always working on improving the connection for every room in the tower.\n"
                . " • There are some Smart-Home devices and gaming consoles that don't support our security protocol WPA2 Enterprise. We set up the parallel network weh-pskonly for these. If you want to connect your device with this network, please use this page backend.weh.rwth-aachen.de/PSK.php\n"
                . " • Before you ask, take a look at the FAQ on our website first! www2.weh.rwth-aachen.de/ags/netzag/faq/\n"
                . " • Sharing your login data with other residents is not allowed and may lead to a penalty of 150€.\n\n"
                . "Paying your membership fees:\n"
                . " • Your WEH account is also a prepaid account for all services within WEH. You can use the money to purchase washing coins, use the printer or pay your membership fees.\n"
                . " • Membership fees are automatically debited from your WEH account on the 1st of each month. If you don't have sufficient funds, a warning email will be sent to you before the billing cycle.\n"
                . " • So make sure there's always enough money on your account for your membership fees or you risk an internet ban.\n"
                . " • You can top up your account via bank transfer or PayPal on this page: backend.weh.rwth-aachen.de/UserKonto.php\n\n";
        }

        if ($forwardemail != 1) {
            $message .= "Using your E-Mail account:\n"
                . " • You can find all the information about how to use your new mail address on this page: https://www2.weh.rwth-aachen.de/ags/netzag/email/\n"
                . " • Please make sure to check your mails at least once a week!\n\n";
        } else {
            $message .= "E-Mail Forwarding:\n"
                . " • All mails will be forwarded to your mailaccount $email\n"
                . " • Please make sure to check your mails at least once a week and ensure your mailbox does not overflow!!\n\n";
        }

        if ($turm != 'tvk') {
            $message .= "Activating your washing account:\n"
                . " • In order to be allowed to use the laundry room, a short instruction to washing must be completed.\n"
                . " • To attend this event, please check our website www2.weh.rwth-aachen.de/en/ags/waschag/ for the most up-to-date information.\n\n"
                . "Bicycle parking in the basement:\n"
                . " • If you want to park your bike in the basement you have to apply with the Fahrrad-AG for a parking space on our website. You are not allowed to park your bike on a space in the basement that has not been assigned to you.\n\n";
        }
        $message .= "If you have any other questions feel free to ask us in our consultation hour.\n"
            . "We will see you there!\n"
            . "Vorstand WEH e.V.";

        $headers = 'From: ' . $address . "
";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de
";
        $mailOk = mail($email, 'WEH - Registration', $message, $headers);

        $terminal[] = "Anmeldung #{$id} akzeptiert.";
        if ((int)$registrationRoom !== (int)$room) {
            $terminal[] = "Raumkorrektur: registration.room {$registrationRoom} -> users.room {$room}.";
        }
        if ($occupiedByActiveUser) {
            $terminal[] = "Warnung bestätigt: vorhandener Bewohner UID {$existingUid} wurde durch roomcheck/subletcheck verdrängt.";
        }
        $terminal[] = "Neuer User: {$username}, UID {$uid}, Turm {$turm}, Raum {$room}.";
        $terminal[] = $mailOk ? 'Mail erfolgreich versendet.' : 'Fehler beim Versenden der Mail.';

        dv_json(['ok' => true, 'message' => 'Anmeldung akzeptiert.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($decision === 'decline') {
        $stmt = mysqli_prepare($conn, "UPDATE registration SET status = -1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "SELECT email, firstname FROM registration WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $email, $firstname);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        foreach (glob('anmeldung/' . $id . '_*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $message = "Dear {$firstname},\n\nyour registration was declined.\n\nThis is the reason:\n" . ($_POST['kommentar'] ?? '') . "\n\nBest regards\nVorstand WEH e.V.";
        $headers = 'From: ' . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
        $mailOk = mail($email, 'WEH - Declined Registration', $message, $headers);

        $terminal[] = "Anmeldung #{$id} abgelehnt.";
        $terminal[] = $mailOk ? 'Mail erfolgreich versendet.' : 'Fehler beim Versenden der Mail.';
        dv_json(['ok' => true, 'message' => 'Anmeldung abgelehnt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($decision === 'remove') {
        $stmt = mysqli_prepare($conn, "UPDATE registration SET status = -1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        foreach (glob('anmeldung/' . $id . '_*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $terminal[] = "Anmeldung #{$id} entfernt, ohne Mailversand.";
        dv_json(['ok' => true, 'message' => 'Anmeldung entfernt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    dv_json(['ok' => false, 'error' => 'Unbekannte Anmeldung-Aktion.'], 400);
}

function dv_handle_agessen_action(mysqli $conn, array &$terminal): void
{
    global $ag_complete;

    $id = intval($_POST['id'] ?? 0);
    $pfad = (string)($_POST['pfad'] ?? '');
    $insert_betrag = (-1) * floatval(str_replace(',', '.', (string)($_POST['betrag'] ?? '0')));
    $iban = (string)($_POST['iban'] ?? '');
    $ag = intval($_POST['ag'] ?? 0);
    $dummy_uid = 492;
    $zeit = time();
    $agent = intval($_SESSION['uid'] ?? 0);
    $agName = $ag_complete[$ag]['name'] ?? dv_get_ag_name($conn, $ag);
    $insert_beschreibung = 'AG-Essen ' . $agName;

    if ($id <= 0 || $insert_betrag >= 0 || $ag <= 0) {
        dv_json(['ok' => false, 'error' => 'Ungültiger AG-Essen-Antrag.'], 400);
    }

    $stmt = mysqli_prepare($conn, "UPDATE agessen SET status = 1 WHERE id = ? AND status = 0");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $konto = ($insert_betrag >= 0) ? 4 : 8;
    $zeitstempel = date('d.m.Y H:i', $zeit);
    $changelog = '[' . $zeitstempel . '] Agent ' . $agent . "\nAG-Essen bestätigt\n";

    if (strpos($iban, 'Bar') === false) {
        $kasse = 92;
        $insertSql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, konto, kasse, agent, changelog, pfad) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        mysqli_stmt_bind_param($stmt, 'iisdiisss', $zeit, $dummy_uid, $insert_beschreibung, $insert_betrag, $konto, $kasse, $agent, $changelog, $pfad);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $terminal[] = "AG-Essen #{$id} bestätigt. Transfer vom Hauskonto angelegt: {$insert_beschreibung}, " . dv_money($insert_betrag) . '.';
    } else {
        $xString = substr($iban, strpos($iban, 'Bar') + strlen('Bar'));
        $kasse = intval(trim($xString));
        $insertSql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, kasse, konto, pfad, agent, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        mysqli_stmt_bind_param($stmt, 'iisdiisss', $zeit, $dummy_uid, $insert_beschreibung, $insert_betrag, $kasse, $konto, $pfad, $agent, $changelog);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $terminal[] = "AG-Essen #{$id} bestätigt. Transfer aus Barkasse {$kasse} angelegt: {$insert_beschreibung}, " . dv_money($insert_betrag) . '.';
    }

    dv_json(['ok' => true, 'message' => 'AG-Essen bestätigt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function dv_handle_erstattung_action(mysqli $conn, array &$terminal): void
{
    $reqId = intval($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $statusAgentUid = intval($_SESSION['uid'] ?? 0);

    if ($reqId <= 0 || !in_array($action, ['accept', 'decline'], true) || $statusAgentUid <= 0) {
        dv_json(['ok' => false, 'error' => 'Ungültige Erstattungsaktion.'], 400);
    }

    if ($action === 'accept') {
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.name, u.turm, u.room, e.einrichtung, e.betrag, e.iban, e.pfad
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ? AND e.status = 0
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 'i', $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $userUid, $name, $turm, $room, $einrichtung, $betrag, $iban, $pfad);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$found) {
            dv_json(['ok' => false, 'error' => 'Erstattungsantrag nicht gefunden oder bereits verarbeitet.'], 404);
        }

        $formattedEinrichtung = dv_format_einrichtung($conn, $einrichtung);

        $stmt = mysqli_prepare($conn, "
            UPDATE erstattung
            SET status = 1,
                status_agent_uid = ?
            WHERE id = ?
              AND status = 0
        ");
        mysqli_stmt_bind_param($stmt, 'ii', $statusAgentUid, $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $transferUid = 472;
        $ts = time();
        $beschreibung = sprintf('Erstattung: %s, IBAN: %s', $formattedEinrichtung, $iban);
        $konto = 8;
        $kasse = 92;
        $negBetrag = -1 * (float)$betrag;

        $insert = mysqli_prepare($conn, "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, pfad) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($insert, 'iisiids', $transferUid, $ts, $beschreibung, $konto, $kasse, $negBetrag, $pfad);
        mysqli_stmt_execute($insert);
        mysqli_stmt_close($insert);

        $to = dv_build_user_room_email($room, $turm);
        $subject = 'Reimbursement request approved';
        $message = "Hello {$name},\n\nYour reimbursement request for " . number_format((float)$betrag, 2, ',', '.') . " EUR has been approved.\nThe amount will be transferred to your IBAN shortly.\n\nIBAN: {$iban}\nPurpose: {$formattedEinrichtung} reimbursement\n\nBest regards\nVorstand WEH e.V.";
        $mailOk = dv_send_plain_mail($to, $subject, $message, 'vorstand@weh.rwth-aachen.de');

        $terminal[] = "Erstattung #{$reqId} genehmigt durch UID {$statusAgentUid}. Transfer vom Hauskonto angelegt: " . dv_money($negBetrag) . '.';
        $terminal[] = $mailOk ? 'Mail an User versendet.' : 'Mail an User konnte nicht versendet werden.';
        dv_json(['ok' => true, 'message' => 'Erstattung genehmigt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($action === 'decline') {
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.name, u.turm, u.room
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ? AND e.status = 0
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 'i', $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $userUid, $name, $turm, $room);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$found) {
            dv_json(['ok' => false, 'error' => 'Erstattungsantrag nicht gefunden oder bereits verarbeitet.'], 404);
        }

        $stmt = mysqli_prepare($conn, "
            UPDATE erstattung
            SET status = -1,
                status_agent_uid = ?
            WHERE id = ?
              AND status = 0
        ");
        mysqli_stmt_bind_param($stmt, 'ii', $statusAgentUid, $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $to = dv_build_user_room_email($room, $turm);
        $subject = 'Reimbursement request declined';
        $message = "Hello {$name},\n\nYour reimbursement request has been declined.\nPlease contact the Vorstand at vorstand@weh.rwth-aachen.de if you have any questions.\n\nBest regards\nVorstand WEH e.V.";
        $mailOk = dv_send_plain_mail($to, $subject, $message, 'vorstand@weh.rwth-aachen.de');

        $terminal[] = "Erstattung #{$reqId} abgelehnt durch UID {$statusAgentUid}.";
        $terminal[] = $mailOk ? 'Mail an User versendet.' : 'Mail an User konnte nicht versendet werden.';
        dv_json(['ok' => true, 'message' => 'Erstattung abgelehnt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }
}

function dv_handle_purchase_action(mysqli $conn, array &$terminal): void
{
    $purchaseId = intval($_POST['purchase_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $vorstandUid = intval($_SESSION['uid'] ?? 0);

    if ($purchaseId <= 0 || !in_array($decision, ['accepted', 'declined'], true) || $vorstandUid <= 0) {
        dv_json(['ok' => false, 'error' => 'Ungültige Einkaufsentscheidung.'], 400);
    }

    $request = dv_fetch_purchase_request($conn, $purchaseId);
    if (!$request) {
        dv_json(['ok' => false, 'error' => 'Einkaufsantrag nicht gefunden.'], 404);
    }

    if ((string)$request['status'] !== 'gestellt') {
        dv_json(['ok' => false, 'error' => 'Dieser Einkaufsantrag ist nicht mehr offen.'], 409);
    }

    $acceptedBefore = dv_decision_count($request, 'accepted');
    $declinedBefore = dv_decision_count($request, 'declined');

    if ($acceptedBefore >= 3 || $declinedBefore >= 3) {
        dv_json(['ok' => false, 'error' => 'Dieser Einkaufsantrag ist bereits final entschieden.'], 409);
    }

    for ($i = 1; $i <= 5; $i++) {
        if (intval($request['vorstand_uid_' . $i] ?? 0) === $vorstandUid) {
            dv_json(['ok' => false, 'error' => 'Du hast diesen Antrag bereits entschieden.'], 409);
        }
    }

    $slot = null;
    for ($i = 1; $i <= 5; $i++) {
        if (intval($request['vorstand_uid_' . $i] ?? 0) <= 0) {
            $slot = $i;
            break;
        }
    }

    if ($slot === null) {
        dv_json(['ok' => false, 'error' => 'Alle Vorstandsfelder sind bereits belegt.'], 409);
    }

    $uidCol = 'vorstand_uid_' . $slot;
    $tsCol = 'vorstand_uid_' . $slot . '_tstamp';
    $decisionCol = 'vorstand_decision_' . $slot;
    $now = time();

    $sql = "UPDATE einkaufantraege SET {$uidCol} = ?, {$tsCol} = ?, {$decisionCol} = ? WHERE id = ? AND status = 'gestellt' LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iisi', $vorstandUid, $now, $decision, $purchaseId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $requestAfter = dv_fetch_purchase_request($conn, $purchaseId);
    if (!$requestAfter) {
        dv_json(['ok' => false, 'error' => 'Einkaufsantrag nach Update nicht lesbar.'], 500);
    }

    $acceptedAfter = dv_decision_count($requestAfter, 'accepted');
    $declinedAfter = dv_decision_count($requestAfter, 'declined');

    $terminal[] = "Einkaufsantrag #{$purchaseId}: Vorstand UID {$vorstandUid} hat " . ($decision === 'accepted' ? 'genehmigt' : 'abgelehnt') . '.';
    $terminal[] = "Zwischenstand: {$acceptedAfter}/3 Zusagen, {$declinedAfter}/3 Ablehnungen.";

    if ($acceptedAfter >= 3) {
        $mailTo = trim((string)($requestAfter['ag_mail'] ?? ''));
        $mailOk = false;
        if ($mailTo !== '') {
            $subject = 'Einkaufsantrag genehmigt: ' . $requestAfter['titel'];
            $message = "Hallo,\n\nder Einkaufsantrag eurer AG wurde vom Vorstand genehmigt.\n\nAntrag: " . $requestAfter['titel'] . "\nMaximalbetrag: " . dv_money((float)$requestAfter['maxbetrag']) . "\n\nDer Einkauf kann nun getätigt werden. Danach kann im Backend ein Erstattungsantrag mit Rechnung gestellt werden.\n\nDiese Nachricht wurde automatisch vom Backend versendet.\n";
            $mailOk = dv_send_plain_mail($mailTo, $subject, $message, 'vorstand@weh.rwth-aachen.de');
        }
        $terminal[] = $mailOk ? 'Dritte Zustimmung erreicht. Mail an AG wurde versendet.' : 'Dritte Zustimmung erreicht. Mail an AG konnte nicht versendet werden oder groups.mail ist leer.';
    }

    if ($declinedAfter >= 3) {
        $stmt = mysqli_prepare($conn, "UPDATE einkaufantraege SET status = 'abgelehnt' WHERE id = ? AND status = 'gestellt' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $purchaseId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $mailTo = trim((string)($requestAfter['ag_mail'] ?? ''));
        $mailOk = false;
        if ($mailTo !== '') {
            $subject = 'Einkaufsantrag abgelehnt: ' . $requestAfter['titel'];
            $message = "Hallo,\n\nder Einkaufsantrag eurer AG wurde vom Vorstand abgelehnt.\n\nAntrag: " . $requestAfter['titel'] . "\nMaximalbetrag: " . dv_money((float)$requestAfter['maxbetrag']) . "\n\nBitte sprecht euch bei Rückfragen direkt mit dem Vorstand ab.\n\nDiese Nachricht wurde automatisch vom Backend versendet.\n";
            $mailOk = dv_send_plain_mail($mailTo, $subject, $message, 'vorstand@weh.rwth-aachen.de');
        }
        $terminal[] = $mailOk ? 'Dritte Ablehnung erreicht. Antrag wurde abgelehnt und Mail an AG wurde versendet.' : 'Dritte Ablehnung erreicht. Antrag wurde abgelehnt. Mail konnte nicht versendet werden oder groups.mail ist leer.';
    }

    dv_json(['ok' => true, 'message' => 'Einkaufsantrag aktualisiert.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function dv_handle_action(mysqli $conn): void
{
    dv_require_vorstand($conn);
    $terminal = [];

    if (isset($_POST['transfer_zuweisen_check'])) {
        dv_handle_transfer_action($conn, $terminal);
    }

    if (isset($_POST['abmeldung_finish'])) {
        dv_handle_abmeldung_action($conn, $terminal);
    }

    if (isset($_POST['agessen_confirm'])) {
        dv_handle_agessen_action($conn, $terminal);
    }

    if (isset($_POST['erstattung_action'])) {
        dv_handle_erstattung_action($conn, $terminal);
    }

    if (isset($_POST['purchase_decision'])) {
        dv_handle_purchase_action($conn, $terminal);
    }

    if (isset($_POST['decision'], $_POST['id'])) {
        dv_handle_registration_action($conn, $terminal);
    }

    dv_json(['ok' => false, 'error' => 'Keine bekannte Aktion übergeben.'], 400);
}

if (isset($_GET['dvapi'])) {
    dv_require_vorstand($conn);

    switch ((string)$_GET['dvapi']) {
        case 'summary':
            dv_json(['ok' => true, 'data' => dv_collect_dashboard_data($conn)]);
        case 'search':
            dv_search_users($conn);
        case 'modal':
            dv_modal($conn);
        case 'action':
            dv_handle_action($conn);
        default:
            dv_json(['ok' => false, 'error' => 'Unbekannter API-Endpunkt.'], 404);
    }
}

if (!auth($conn) || empty($_SESSION['valid']) || (empty($_SESSION['Vorstand']) && empty($_SESSION['Webmaster']))) {
    header('Location: denied.php');
    exit;
}

$initialData = dv_collect_dashboard_data($conn);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Vorstand</title>
    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        :root {
            --dv-green: #11a50d;
            --dv-green-light: #35d235;
            --dv-red: #c01818;
            --dv-red-soft: rgba(72, 24, 24, 0.97);
            --dv-orange: #E49B0F;
            --dv-bg: #101010;
            --dv-panel: rgba(15, 22, 16, 0.98);
            --dv-terminal: #050805;
            --dv-border: rgba(17,165,13,0.55);
            --dv-text: #f2fff2;
            --dv-muted: rgba(255,255,255,0.65);
        }

        body {
            background: #101010;
            color: var(--dv-text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .dv-page {
            width: min(1840px, 97vw);
            margin: 10px auto 32px;
            color: var(--dv-text);
            box-sizing: border-box;
        }

        .dv-layout {
            display: grid;
            grid-template-columns: minmax(760px, 1.28fr) minmax(390px, 0.72fr);
            gap: 30px;
            align-items: stretch;
            box-sizing: border-box;
        }

        .dv-left {
            min-width: 0;
            box-sizing: border-box;
        }

        .dv-left-shell {
            display: flex;
            flex-direction: column;
            gap: 38px;
            padding: 14px;
            background: transparent;
            box-shadow: none;
            border: 0;
            box-sizing: border-box;
        }

        .dv-section {
            color: var(--dv-text);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }

        .dv-panel-head {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .dv-panel-title {
            font-size: clamp(20px, 1.35vw, 26px);
            font-weight: 700;
            line-height: 1.15;
            color: #fff;
            text-align: center;
        }

        .dv-panel-head .dv-terminal-mini {
            position: absolute;
            right: 0;
        }

        .dv-terminal-mini {
            border: 1px solid rgba(17,165,13,0.35);
            background: var(--dv-green);
            color: #fff;
            border-radius: 999px;
            padding: 7px 12px;
            max-height: 34px;
            cursor: pointer;
            font-weight: 600;
        }

        .dv-metric-grid,
        .dv-queue-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
            align-items: start;
            box-sizing: border-box;
        }

        .dv-card,
        .dv-queue-card {
            border: 2px solid var(--dv-green);
            border-radius: 14px;
            padding: 12px;
            min-height: 105px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: #fff;
            background: linear-gradient(180deg, rgba(22, 54, 26, 0.97), rgba(8, 24, 10, 0.97));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18), 0 8px 18px rgba(0,0,0,0.24);
            box-sizing: border-box;
            overflow: hidden;
        }

        .dv-card.dv-state-bad {
            border-color: var(--dv-red);
            background: linear-gradient(180deg, rgba(72, 24, 24, 0.97), rgba(30, 8, 8, 0.97));
        }

        .dv-queue-card.dv-has-items {
            border-color: var(--dv-green);
            background: linear-gradient(180deg, rgba(22, 54, 26, 0.97), rgba(8, 24, 10, 0.97));
        }

        .dv-card-title,
        .dv-queue-title {
            font-size: clamp(14px, 0.92vw, 16px);
            opacity: .94;
            font-weight: 600;
            margin-bottom: 6px;
            color: #fff;
        }

        .dv-card-value,
        .dv-queue-empty {
            font-size: clamp(22px, 2vw, 34px);
            line-height: 1.08;
            font-weight: 700;
            word-break: break-word;
        }

        .dv-card-detail,
        .dv-queue-info {
            display: block;
            margin-top: 5px;
            font-size: clamp(12px, 0.9vw, 14px);
            line-height: 1.25;
            opacity: .92;
            overflow: hidden;
        }

        .dv-queue-info {
            margin: 0 0 8px;
            max-height: 35px;
        }

        .dv-queue-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: baseline;
        }

        .dv-queue-count {
            font-size: clamp(22px, 2vw, 34px);
            line-height: 1;
            font-weight: 700;
            color: #fff;
        }

        .dv-item-list {
            display: flex;
            align-content: flex-start;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            overflow: visible;
            min-height: 0;
        }

        .dv-open-modal {
            border: 1px solid rgba(0,0,0,0.95);
            border-radius: 10px;
            padding: 7px 10px;
            color: #000;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            background: var(--dv-green);
            box-shadow: 0 5px 12px rgba(0,0,0,0.20);
            box-sizing: border-box;
            max-width: 180px;
            text-align: left;
        }

        .dv-open-modal:hover,
        .dv-terminal-mini:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .dv-open-modal[data-tower="tvk"] {
            background: var(--dv-orange);
            color: #111;
        }

        .dv-open-main,
        .dv-open-sub {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dv-open-sub {
            font-size: 11px;
            opacity: 0.8;
        }

        .dv-terminal-wrap {
            position: static;
            align-self: stretch;
            height: auto;
            min-height: 0;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(15, 22, 16, 0.98), rgba(5, 8, 6, 0.98));
            border: 2px solid rgba(17,165,13,0.55);
            overflow: hidden;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.14), 0 0 16px rgba(17,165,13,0.16);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        .dv-terminal-head {
            flex: 0 0 auto;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            background: #0d0d0d;
            color: #fff;
        }

        .dv-terminal-title {
            font-weight: 700;
            letter-spacing: 0;
        }

        .dv-terminal-screen {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
            padding: 14px;
            font: 13px/1.42 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            white-space: pre-wrap;
            color: #e8ffe8;
        }

        .dv-terminal-line {
            margin: 0 0 6px 0;
        }

        .dv-terminal-line.dv-error {
            color: #ff9a9a;
        }

        .dv-terminal-line.dv-muted-line {
            color: #8c8c8c;
        }

        .dv-modal-root:empty {
            display: none;
        }

        .dv-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.72);
            z-index: 9000;
        }

        .dv-modal {
            position: fixed;
            z-index: 9001;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: min(980px, 92vw);
            max-height: 90vh;
            overflow: hidden;
            color: #fff;
            background: #171717;
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 24px;
            box-shadow: 0 30px 100px rgba(0,0,0,0.65);
            display: flex;
            flex-direction: column;
        }

        .dv-wide-modal {
            width: min(1320px, 94vw);
            max-height: 94vh;
        }

        .dv-modal-head {
            flex: 0 0 auto;
            position: sticky;
            top: 0;
            z-index: 2;
            background: #171717;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            padding: 16px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dv-modal-title {
            font-size: 22px;
            font-weight: 700;
        }

        .dv-modal-close {
            border: 0;
            background: #fff;
            color: #111;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 700;
            font-size: 22px;
            line-height: 1;
        }

        .dv-modal-body {
            flex: 1 1 auto;
            min-height: 0;
            max-height: calc(90vh - 68px);
            overflow: auto;
            padding: 18px;
        }

        .dv-wide-modal .dv-modal-body {
            max-height: calc(94vh - 68px);
        }

        .dv-action-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .dv-account-info {
            border: 1px solid rgba(17,165,13,0.6);
            background: rgba(17,165,13,0.08);
            color: #dfffdc;
            border-radius: 14px;
            padding: 12px 14px;
            line-height: 1.4;
            font-weight: 700;
        }

        .dv-modal-split {
            display: grid;
            grid-template-columns: minmax(420px, 1.42fr) minmax(360px, 0.88fr);
            gap: 18px;
            align-items: start;
        }

        .dv-detail-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .dv-detail-row {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            padding: 9px 10px;
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 12px;
            background: rgba(255,255,255,0.06);
        }

        .dv-detail-row span {
            color: rgba(255,255,255,0.62);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .dv-detail-row strong {
            color: #fff;
            font-size: 15px;
            line-height: 1.28;
            word-break: break-word;
        }

        .dv-detail-list-two-cols {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .dv-detail-list-two-cols .dv-detail-row {
            grid-template-columns: 1fr;
            gap: 4px;
            margin: 0;
        }

        .dv-detail-list-two-cols .dv-detail-row-wide {
            grid-column: 1 / -1;
        }

        .dv-full-label.dv-decline-reason {
            display: none !important;
        }

        .dv-full-label.dv-decline-reason.dv-visible {
            display: flex !important;
        }

        .dv-warn {
            color: #ffd166;
            font-weight: 800;
        }

        .dv-toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            z-index: 10050;
            padding: 10px 16px;
            border-radius: 999px;
            background: var(--dv-green);
            color: #000;
            font-weight: 800;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            opacity: 0;
            pointer-events: none;
            transition: opacity .16s ease, transform .16s ease;
        }

        .dv-toast.dv-visible {
            opacity: 1;
            transform: translate(-50%, -4px);
        }

        .dv-copy-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0 0;
        }

        .dv-copy-btn {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            width: 100%;
            text-align: left;
            border: 1px solid var(--dv-green);
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-radius: 12px;
            padding: 9px 11px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
        }

        .dv-copy-btn span {
            color: #a0ffa0;
            font-size: 12px;
            text-transform: uppercase;
        }

        .dv-copy-btn:hover,
        .dv-copy-btn.dv-copied {
            background: var(--dv-green);
            color: #000;
        }

        .dv-copy-btn:hover span,
        .dv-copy-btn.dv-copied span {
            color: #000;
        }

        .dv-document-tabs {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .dv-doc-tab {
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 999px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }

        .dv-doc-tab.dv-active {
            background: var(--dv-green);
            border-color: var(--dv-green);
        }

        .dv-doc-tab:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        .dv-document-stage,
        .dv-file-stage {
            height: 620px;
            background: #0f0f0f;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dv-document-frame {
            display: none;
            width: 100%;
            height: 100%;
        }

        .dv-document-frame.dv-active {
            display: flex;
        }

        .dv-document-frame img,
        .dv-file-stage img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .dv-document-frame embed,
        .dv-file-stage embed {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }

        .dv-input,
        .dv-full-label input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.16);
            background: rgba(0,0,0,0.25);
            color: white;
            font: inherit;
        }

        .dv-full-label {
            color: rgba(255,255,255,0.75);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .dv-alert {
            border-radius: 16px;
            padding: 12px;
            margin: 0;
            font-weight: 700;
            line-height: 1.35;
        }

        .dv-alert-bad {
            background: rgba(132,13,10,0.36);
            border: 1px solid rgba(255,90,90,0.35);
        }

        .dv-alert-warn {
            background: rgba(228,155,15,0.18);
            border: 1px solid rgba(228,155,15,0.38);
        }

        .dv-danger-confirm {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            background: rgba(132,13,10,0.36);
            border: 1px solid rgba(255,90,90,0.45);
            border-radius: 16px;
            padding: 12px;
            color: #ffd8d8;
            font-weight: 700;
            line-height: 1.35;
        }

        .dv-radio-row,
        .dv-button-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 18px;
            margin: 12px 0;
            flex-wrap: wrap;
        }

        .dv-radio-row label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 18px;
        }

        .dv-submit,
        .dv-small-btn {
            display: block;
            margin: 12px auto 0;
            border: 0;
            border-radius: 16px;
            padding: 11px 18px;
            background: #fff;
            color: #111;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            min-width: 165px;
        }

        .dv-small-btn {
            display: inline-block;
            margin: 0;
            min-width: 90px;
            background: rgba(255,255,255,0.92);
        }

        .dv-green-submit {
            background: var(--dv-green);
            color: #000;
        }

        .dv-red-submit {
            background: var(--dv-red);
            color: #fff;
        }

        .dv-submit:disabled,
        .dv-assign-button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .dv-green {
            color: var(--dv-green-light);
            font-weight: 700;
        }

        .dv-red {
            color: #ff5f5f;
            font-weight: 700;
        }

        .dv-transfer-form {
            max-width: 660px;
            margin: 0 auto;
        }

        .dv-search-results {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 8px 0;
            max-height: 290px;
            overflow: auto;
        }

        .dv-user-result {
            border: 1px solid rgba(255,255,255,0.16);
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-radius: 12px;
            padding: 10px 12px;
            cursor: pointer;
            text-align: left;
            font-weight: 700;
        }

        .dv-user-result:hover,
        .dv-user-result.dv-selected,
        .dv-select-dummy.dv-selected {
            background: var(--dv-green);
            color: #000;
        }

        .dv-approval-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin: 12px 0;
        }

        .dv-approval-box {
            background: #2a2a2a;
            border: 1px solid #555;
            color: #aaa;
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            font-weight: 700;
            line-height: 1.25;
        }

        .dv-approval-box.accepted {
            background: var(--dv-green);
            border-color: var(--dv-green);
            color: #000;
        }

        .dv-approval-box.declined {
            background: var(--dv-red);
            border-color: #e05b61;
            color: #fff;
        }

        .dv-centered-text {
            text-align: center;
            font-size: 17px;
            line-height: 1.45;
        }

        .dv-empty-note,
        .dv-modal-error {
            color: #ff8a8a;
            font-weight: 700;
            text-align: center;
            padding: 22px;
        }

        .navbar-welcome-name {
            color: var(--dv-green) !important;
        }

        .navbar-menu-wrapper .header-menu .center-btn:hover,
        .navbar-menu-wrapper .header-submenu button:hover,
        .header-submenu button:hover {
            background-color: var(--dv-green) !important;
            color: #000 !important;
        }

        @media (max-width: 1200px) {
            .dv-layout {
                grid-template-columns: 1fr;
            }
            .dv-terminal-wrap {
                position: static;
                height: 420px;
                min-height: 320px;
            }
        }

        @media (max-width: 1100px) {
            .dv-metric-grid,
            .dv-queue-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 760px) {
            .dv-page {
                width: calc(100vw - 16px);
                margin-top: 8px;
            }
            .dv-layout,
            .dv-left-shell {
                display: flex;
                flex-direction: column;
                gap: 14px;
            }
            .dv-left-shell {
                padding: 8px 0;
            }
            .dv-metric-grid,
            .dv-queue-grid,
            .dv-modal-split,
            .dv-registration-split,
            .dv-approval-row {
                grid-template-columns: 1fr;
            }
            .dv-detail-row,
            .dv-copy-btn {
                grid-template-columns: 1fr;
                gap: 4px;
            }
            .dv-document-stage,
            .dv-file-stage {
                height: 420px;
            }
            .dv-modal {
                width: 94vw;
            }
        }
    </style>
</head>
<?php
echo $dv_template_output;
load_menu();
?>
<div class="dv-page" id="dvPage">
    <div class="dv-layout">
        <main class="dv-left">
            <section class="dv-left-shell">
                <section class="dv-section dv-metric-section">
                    <div class="dv-panel-head">
                        <div class="dv-panel-title">Allgemein</div>
                    </div>
                    <div class="dv-metric-grid" id="dvMetricGrid"></div>
                </section>

                <section class="dv-section dv-queue-section">
                    <div class="dv-panel-head">
                        <div class="dv-panel-title">Offene Vorgänge</div>
                        <button type="button" class="dv-terminal-mini" data-dv-refresh="1">Aktualisieren</button>
                    </div>
                    <div class="dv-queue-grid" id="dvQueueGrid"></div>
                </section>
            </section>
        </main>

        <aside class="dv-terminal-wrap">
            <div class="dv-terminal-head">
                <div class="dv-terminal-title">Aktionslog</div>
                <button type="button" class="dv-terminal-mini" data-dv-terminal-clear="1">Leeren</button>
            </div>
            <div class="dv-terminal-screen" id="dvTerminal"></div>
        </aside>
    </div>
</div>
<div class="dv-modal-root" id="dvModalRoot"></div>
<div class="dv-toast" id="dvToast">In Zwischenablage kopiert</div>

<script>
const DV = {
  data: <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  apiBase: "<?= dv_h(basename(__FILE__)) ?>",
  searchTimer: null
};

function dvEscape(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function dvTerminal(text, type = "normal") {
  const terminal = document.getElementById("dvTerminal");
  if (!terminal) return;
  String(text || "").split(/\r?\n/).forEach(rawLine => {
    const line = document.createElement("div");
    line.className = "dv-terminal-line" + (type === "error" ? " dv-error" : "") + (type === "muted" ? " dv-muted-line" : "");
    line.innerHTML = dvEscape(rawLine);
    terminal.appendChild(line);
  });
  terminal.scrollTop = terminal.scrollHeight;
}

function dvToast(message = "In Zwischenablage kopiert") {
  const toast = document.getElementById("dvToast");
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add("dv-visible");
  clearTimeout(DV.toastTimer);
  DV.toastTimer = setTimeout(() => toast.classList.remove("dv-visible"), 1150);
}

function dvToggleDeclineReason(form) {
  const reason = form.querySelector(".dv-decline-reason");
  if (!reason) return;
  const input = reason.querySelector("input");
  const show = !!form.querySelector('input[name="decision"][value="decline"]:checked');
  reason.classList.toggle("dv-visible", show);
  if (input) {
    input.disabled = !show;
    if (!show) input.value = "";
  }
}

function dvRenderCards() {
  const grid = document.getElementById("dvMetricGrid");
  const cards = DV.data.cards || {};
  const order = ["gesamtgeld", "hauskapital", "netzkapital", "bilanz", "bargeld", "mitglieder"];
  grid.innerHTML = order.map(key => {
    const card = cards[key] || {};
    const detail = String(card.detail || "").trim();
    return `
      <article class="dv-card dv-state-${dvEscape(card.state || "good")}">
        <div class="dv-card-title">${dvEscape(card.title)}</div>
        <div class="dv-card-value">${dvEscape(card.value)}</div>
        ${detail ? `<div class="dv-card-detail">${dvEscape(detail)}</div>` : ""}
      </article>`;
  }).join("");
}

function dvRenderQueues() {
  const grid = document.getElementById("dvQueueGrid");
  const queues = DV.data.queues || {};
  grid.innerHTML = Object.values(queues).map(queue => {
    const items = queue.items || [];
    const buttons = items.map(item => `
      <button type="button" class="dv-open-modal" data-type="${dvEscape(queue.type)}" data-id="${dvEscape(item.id)}" data-tower="${dvEscape((item.tower || "weh").toLowerCase())}" title="${dvEscape(item.sub || "")}">
        <span class="dv-open-main">${dvEscape(item.label)}</span>
        <span class="dv-open-sub">${dvEscape(item.sub || "")}</span>
      </button>`).join("");
    const content = items.length ? buttons : `<div class="dv-queue-empty">-</div>`;

    const queueInfo = String(queue.info || "").trim();
    return `
      <article class="dv-queue-card${items.length ? " dv-has-items" : ""}">
        <div class="dv-queue-head">
          <div class="dv-queue-title">${dvEscape(queue.title)}</div>
          ${items.length ? `<div class="dv-queue-count">${items.length}</div>` : ""}
        </div>
        ${queueInfo ? `<div class="dv-queue-info">${dvEscape(queueInfo)}</div>` : ""}
        <div class="dv-item-list">${content}</div>
      </article>`;
  }).join("");
}

function dvRenderAll() {
  dvRenderCards();
  dvRenderQueues();
}

async function dvRefresh(silent = false) {
  try {
    const res = await fetch(`${DV.apiBase}?dvapi=summary`, { credentials: "same-origin", cache: "no-store" });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
    DV.data = json.data;
    dvRenderAll();
    if (!silent) dvTerminal(`Dashboard aktualisiert: ${new Date().toLocaleTimeString("de-DE")}`);
  } catch (err) {
    dvTerminal(`Dashboard-Refresh fehlgeschlagen: ${err.message || err}`, "error");
  }
}

async function dvOpenModal(type, id) {
  try {
    dvTerminal(`Öffne ${type} #${id}...`, "muted");
    const res = await fetch(`${DV.apiBase}?dvapi=modal&type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`, { credentials: "same-origin", cache: "no-store" });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
    document.getElementById("dvModalRoot").innerHTML = json.html;
  } catch (err) {
    dvTerminal(`Modal-Fehler: ${err.message || err}`, "error");
  }
}

function dvCloseModal() {
  document.getElementById("dvModalRoot").innerHTML = "";
}

async function dvSubmitAction(form, submitter = null) {
  const fd = new FormData(form);

  if (submitter && submitter.name) {
    fd.append(submitter.name, submitter.value);
  }

  const submitBtn = submitter || form.querySelector("[type='submit']");
  const oldText = submitBtn ? submitBtn.textContent : "";

  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = "Verarbeite...";
  }

  try {
    const res = await fetch(`${DV.apiBase}?dvapi=action`, {
      method: "POST",
      credentials: "same-origin",
      body: fd
    });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
    if (json.terminal) dvTerminal(json.terminal);
    else dvTerminal(json.message || "Aktion durchgeführt.");
    dvCloseModal();
    if (json.refresh) await dvRefresh(true);
  } catch (err) {
    dvTerminal(`Fehler: ${err.message || err}`, "error");
    alert(err.message || err);
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = oldText;
    }
  }
}

async function dvSearchUsers(input) {
  const form = input.closest(".dv-transfer-form");
  const results = form ? form.querySelector(".dv-search-results") : null;
  if (!results) return;
  const query = input.value.trim();
  if (query.length < 2) {
    results.innerHTML = "";
    return;
  }
  try {
    const res = await fetch(`${DV.apiBase}?dvapi=search&search=${encodeURIComponent(query)}`, { credentials: "same-origin", cache: "no-store" });
    const data = await res.json();
    const rows = [];
    Object.values(data || {}).forEach(group => (group || []).forEach(user => rows.push(user)));
    if (rows.length === 0) {
      results.innerHTML = `<div class="dv-empty-note">Keine Nutzer gefunden</div>`;
      return;
    }
    results.innerHTML = rows.map(user => `
      <button type="button" class="dv-user-result" data-uid="${dvEscape(user.uid)}" data-label="${dvEscape(user.name)}">
        <strong>${dvEscape(user.name)}</strong><br>
        UID ${dvEscape(user.uid)} · ${dvEscape(user.username)} · ${dvEscape(user.turm)} ${dvEscape(user.room)}
      </button>
    `).join("");
  } catch (err) {
    results.innerHTML = `<div class="dv-modal-error">Suche fehlgeschlagen</div>`;
  }
}

document.addEventListener("click", function(event) {
  const close = event.target.closest("[data-dv-close]");
  if (close) {
    dvCloseModal();
    return;
  }

  const refresh = event.target.closest("[data-dv-refresh]");
  if (refresh) {
    dvRefresh(false);
    return;
  }

  const clear = event.target.closest("[data-dv-terminal-clear]");
  if (clear) {
    document.getElementById("dvTerminal").innerHTML = "";
    dvTerminal("Aktionslog geleert.", "muted");
    return;
  }

  const modalButton = event.target.closest(".dv-open-modal");
  if (modalButton) {
    dvOpenModal(modalButton.dataset.type, modalButton.dataset.id);
    return;
  }

  const copyButton = event.target.closest(".dv-copy-btn");
  if (copyButton) {
    const text = copyButton.dataset.copy || copyButton.textContent || "";
    navigator.clipboard.writeText(text).then(() => {
      copyButton.classList.add("dv-copied");
      dvToast("In Zwischenablage kopiert");
      setTimeout(() => copyButton.classList.remove("dv-copied"), 900);
    });
    return;
  }

  const dummyButton = event.target.closest(".dv-select-dummy");
  if (dummyButton) {
    const form = dummyButton.closest(".dv-transfer-form");
    if (!form) return;
    const uidInput = form.querySelector(".dv-selected-uid");
    const assignButton = form.querySelector(".dv-assign-button");
    uidInput.value = dummyButton.dataset.uid || "";
    form.querySelectorAll(".dv-user-result, .dv-select-dummy").forEach(el => el.classList.remove("dv-selected"));
    dummyButton.classList.add("dv-selected");
    if (assignButton) assignButton.disabled = false;
    return;
  }

  const userResult = event.target.closest(".dv-user-result");
  if (userResult) {
    const form = userResult.closest(".dv-transfer-form");
    if (!form) return;
    const uidInput = form.querySelector(".dv-selected-uid");
    const assignButton = form.querySelector(".dv-assign-button");
    uidInput.value = userResult.dataset.uid || "";
    form.querySelectorAll(".dv-user-result, .dv-select-dummy").forEach(el => el.classList.remove("dv-selected"));
    userResult.classList.add("dv-selected");
    if (assignButton) assignButton.disabled = false;
    return;
  }

  const docTab = event.target.closest("[data-dv-doc-tab]");
  if (docTab) {
    const modal = docTab.closest(".dv-modal");
    const key = docTab.dataset.dvDocTab;
    if (modal && key) {
      modal.querySelectorAll("[data-dv-doc-tab]").forEach(tab => tab.classList.toggle("dv-active", tab === docTab));
      modal.querySelectorAll("[data-dv-doc-panel]").forEach(panel => panel.classList.toggle("dv-active", panel.dataset.dvDocPanel === key));
    }
  }
});

document.addEventListener("submit", function(event) {
  const form = event.target.closest(".dv-action-form");
  if (!form) return;
  event.preventDefault();

  if (form.querySelector("input[name='requires_room_takeover'][value='1']")) {
    const checkbox = form.querySelector("input[name='confirm_room_takeover']");
    const decision = form.querySelector("input[name='decision']:checked");
    if (decision && decision.value === "accept" && checkbox && !checkbox.checked) {
      alert("Bitte bestätige explizit, dass der vorhandene User aus dem Raum verdrängt werden darf.");
      return;
    }
  }

  dvSubmitAction(form, event.submitter || null);
});

document.addEventListener("change", function(event) {
  const decision = event.target.closest('input[name="decision"]');
  if (!decision) return;
  const form = decision.closest("form");
  if (form) dvToggleDeclineReason(form);
});

document.addEventListener("input", function(event) {
  const searchInput = event.target.closest(".dv-user-search");
  if (searchInput) {
    clearTimeout(DV.searchTimer);
    DV.searchTimer = setTimeout(() => dvSearchUsers(searchInput), 180);
  }
});

dvRenderAll();
dvTerminal("Dashboard-Vorstand geladen.", "muted");
</script>
</html>
