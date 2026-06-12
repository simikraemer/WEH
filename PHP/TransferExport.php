<?php
// TransferExport.php

session_start();
require_once __DIR__ . '/conn.php';

mysqli_set_charset($conn, 'utf8');

$TE_KONTO_OPTIONS = [
    0 => "Kaution",
    1 => "Netzbeitrag",
    2 => "Hausbeitrag",
    3 => "Druckauftrag",
    4 => "Einzahlung",
    5 => "Getränk",
    6 => "Waschmaschine",
    7 => "Spülmaschine",
    8 => "Undefiniert"
];

$TE_KASSE_OPTIONS = [
    72 => "Netzkonto",
    92 => "Hauskonto",
    69 => "PayPal",
    1  => "Netzbarkasse I",
    2  => "Netzbarkasse II",
    93 => "Kassenwartkasse I",
    94 => "Kassenwartkasse II",
    95 => "Tresor",
    3  => "imaginäre Schuldbuchung",
    5  => "imaginäre Rückzahlung",
    0  => "Haus (alt)",
    4  => "Netzkonto (alt)",
];

$TE_EXTERNAL_GROUPS = [
    'netzkonto'        => ['label' => 'Netzkonto',          'kind' => 'external', 'kasse' => 72],
    'paypal'           => ['label' => 'PayPal',             'kind' => 'external', 'kasse' => 69],
    'hauskonto'        => ['label' => 'Hauskonto',          'kind' => 'external', 'kasse' => 92],
    'netzbarkasse_1'   => ['label' => 'Netzbarkasse I',     'kind' => 'external', 'kasse' => 1],
    'netzbarkasse_2'   => ['label' => 'Netzbarkasse II',    'kind' => 'external', 'kasse' => 2],
    'kassenwart_1'     => ['label' => 'Kassenwartkasse I',  'kind' => 'external', 'kasse' => 93],
    'kassenwart_2'     => ['label' => 'Kassenwartkasse II', 'kind' => 'external', 'kasse' => 94],
    'tresor'           => ['label' => 'Tresor',             'kind' => 'external', 'kasse' => 95],
];

$TE_INTERNAL_GROUPS = [
    'druckauftraege'   => ['label' => 'Druckaufträge', 'kind' => 'internal'],
    'abrechnungen'     => ['label' => 'Abrechnungen',  'kind' => 'internal'],
    'waschmarken'      => ['label' => 'Waschmarken',   'kind' => 'internal'],
    'sonstiges'        => ['label' => 'Sonstiges',     'kind' => 'internal'],
];

$TE_EXPORT_GROUPS = array_merge($TE_EXTERNAL_GROUPS, $TE_INTERNAL_GROUPS);

$TE_COLUMN_DEFS = [
    'buchungstag'     => ['label' => 'Buchungstag',                         'default' => true],
    'beschreibung'    => ['label' => 'Beschreibung',                        'default' => true],
    'zahlungspartner' => ['label' => 'Begünstigter/Zahlungspartner',         'default' => true],
    'betrag'          => ['label' => 'Betrag',                              'default' => true],
    'waehrung'        => ['label' => 'Währung',                             'default' => true],
    'kasse_konto'     => ['label' => 'Kasse/Konto',                         'default' => true],
    'kategorie'       => ['label' => 'Kategorie',                           'default' => true],

    'transfer_id'     => ['label' => 'Transfer-ID',                         'default' => false],
    'uid'             => ['label' => 'UID',                                 'default' => false],
    'username'        => ['label' => 'Username',                            'default' => false],
    'voller_name'     => ['label' => 'Name',                                'default' => false],
    'raum'            => ['label' => 'Raum',                                'default' => false],
    'turm'            => ['label' => 'Turm',                                'default' => false],
    'konto_id'        => ['label' => 'Konto-ID',                            'default' => false],
    'konto_label'     => ['label' => 'Konto-Bezeichnung',                   'default' => false],
    'kasse_id'        => ['label' => 'Kasse-ID',                            'default' => false],
    'kasse_label'     => ['label' => 'Kasse-Bezeichnung Rohwert',           'default' => false],
    'exportbereich'   => ['label' => 'Exportbereich',                       'default' => false],
    'unix_timestamp'  => ['label' => 'UNIX-Timestamp',                      'default' => false],
    'iban'            => ['label' => 'IBAN',                                'default' => false],
    'print_id'        => ['label' => 'Print-ID',                            'default' => false],
    'wasch_id'        => ['label' => 'Wasch-ID',                            'default' => false],
    'rechnungspfad'   => ['label' => 'Rechnungspfad',                       'default' => false],
];

function te_has_access(): bool {
    return !empty($_SESSION['valid'])
        && (
            !empty($_SESSION['Webmaster'])
            || !empty($_SESSION['Kassenwart'])
        );
}

function te_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function te_csrf_token(): string {
    if (empty($_SESSION['transfer_export_csrf'])) {
        try {
            $_SESSION['transfer_export_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['transfer_export_csrf'] = md5(uniqid((string)mt_rand(), true));
        }
    }

    return $_SESSION['transfer_export_csrf'];
}

function te_validate_csrf(): bool {
    $sent = (string)($_POST['csrf_token'] ?? '');
    $real = (string)($_SESSION['transfer_export_csrf'] ?? '');

    return $sent !== '' && $real !== '' && hash_equals($real, $sent);
}

function te_json_response(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function te_plain_response(string $text, int $status = 400): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

function te_post_array_json(string $key): array {
    $raw = $_POST[$key] ?? '[]';

    if (is_array($raw)) {
        return $raw;
    }

    $raw = trim((string)$raw);

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    return array_filter(array_map('trim', explode(',', $raw)));
}

function te_selected_groups(): array {
    global $TE_EXPORT_GROUPS;

    $groups = te_post_array_json('groups');
    $clean = [];

    foreach ($groups as $group) {
        $group = (string)$group;

        if (isset($TE_EXPORT_GROUPS[$group])) {
            $clean[] = $group;
        }
    }

    return array_values(array_unique($clean));
}

function te_selected_columns(): array {
    global $TE_COLUMN_DEFS;

    $columns = te_post_array_json('columns');
    $clean = [];

    foreach ($columns as $column) {
        $column = (string)$column;

        if (isset($TE_COLUMN_DEFS[$column])) {
            $clean[] = $column;
        }
    }

    if (!$clean) {
        foreach ($TE_COLUMN_DEFS as $key => $def) {
            if (!empty($def['default'])) {
                $clean[] = $key;
            }
        }
    }

    return array_values(array_unique($clean));
}

function te_date_start_ts(?string $date): ?int {
    $date = trim((string)$date);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return null;
    }

    return mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
}

function te_date_end_ts(?string $date): ?int {
    $date = trim((string)$date);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return null;
    }

    return mktime(0, 0, 0, (int)$m[2], (int)$m[3] + 1, (int)$m[1]);
}

function te_bind_stmt(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || !$params) {
        return;
    }

    $bind = [];
    $bind[] = $types;

    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function te_group_condition(string $group, string &$types, array &$params): string {
    global $TE_EXPORT_GROUPS;

    if (!isset($TE_EXPORT_GROUPS[$group])) {
        return '0=1';
    }

    $def = $TE_EXPORT_GROUPS[$group];

    if ($def['kind'] === 'external') {
        $types .= 'i';
        $params[] = (int)$def['kasse'];

        return 't.kasse = ?';
    }

    $baseNotIn = 't.kasse NOT IN (1,2,69,72,92,93,94,95)';

    if ($group === 'druckauftraege') {
        return "($baseNotIn AND t.print_id IS NOT NULL AND t.print_id <> 0)";
    }

    if ($group === 'abrechnungen') {
        return "($baseNotIn AND (
            COALESCE(t.beschreibung, '') LIKE 'Abrechnung Hausbeitrag%'
            OR COALESCE(t.beschreibung, '') LIKE 'Abrechnung Netzbeitrag%'
        ))";
    }

    if ($group === 'waschmarken') {
        return "($baseNotIn AND COALESCE(t.beschreibung, '') = 'Waschmarken generiert')";
    }

    if ($group === 'sonstiges') {
        return "($baseNotIn
            AND (t.print_id IS NULL OR t.print_id = 0)
            AND NOT (
                COALESCE(t.beschreibung, '') LIKE 'Abrechnung Hausbeitrag%'
                OR COALESCE(t.beschreibung, '') LIKE 'Abrechnung Netzbeitrag%'
            )
            AND COALESCE(t.beschreibung, '') <> 'Waschmarken generiert'
        )";
    }

    return '0=1';
}

function te_build_where(array $groups, string &$types, array &$params): string {
    $where = ['1=1'];
    $groupParts = [];

    foreach ($groups as $group) {
        $groupParts[] = '(' . te_group_condition($group, $types, $params) . ')';
    }

    if (!$groupParts) {
        $where[] = '0=1';
    } else {
        $where[] = '(' . implode(' OR ', $groupParts) . ')';
    }

    $fromTs = te_date_start_ts($_POST['date_from'] ?? '');

    if ($fromTs !== null) {
        $where[] = 't.tstamp >= ?';
        $types .= 'i';
        $params[] = $fromTs;
    }

    $toTs = te_date_end_ts($_POST['date_to'] ?? '');

    if ($toTs !== null) {
        $where[] = 't.tstamp < ?';
        $types .= 'i';
        $params[] = $toTs;
    }

    $amountFilter = (string)($_POST['amount_filter'] ?? 'all');

    if ($amountFilter === 'positive') {
        $where[] = 't.betrag > 0';
    } elseif ($amountFilter === 'negative') {
        $where[] = 't.betrag < 0';
    } elseif ($amountFilter === 'nonzero') {
        $where[] = 't.betrag <> 0';
    }

    $search = trim((string)($_POST['search'] ?? ''));

    if ($search !== '') {
        $needle = '%' . $search . '%';

        $where[] = "(
            COALESCE(t.beschreibung, '') LIKE ?
            OR COALESCE(u.name, '') LIKE ?
            OR COALESCE(u.firstname, '') LIKE ?
            OR COALESCE(u.lastname, '') LIKE ?
            OR COALESCE(u.username, '') LIKE ?
            OR CAST(t.uid AS CHAR) LIKE ?
            OR COALESCE(t.iban, '') LIKE ?
            OR CAST(t.id AS CHAR) LIKE ?
        )";

        for ($i = 0; $i < 8; $i++) {
            $types .= 's';
            $params[] = $needle;
        }
    }

    return implode(' AND ', $where);
}

function te_order_sql(): string {
    $sort = (string)($_POST['sort'] ?? 'date_asc');

    $map = [
        'date_asc'     => 't.tstamp ASC, t.id ASC',
        'date_desc'    => 't.tstamp DESC, t.id DESC',
        'kasse_date'   => 't.kasse ASC, t.tstamp ASC, t.id ASC',
        'konto_date'   => 't.konto ASC, t.tstamp ASC, t.id ASC',
        'amount_asc'   => 't.betrag ASC, t.tstamp ASC, t.id ASC',
        'amount_desc'  => 't.betrag DESC, t.tstamp ASC, t.id ASC',
        'partner_date' => 'u.lastname ASC, u.firstname ASC, u.name ASC, t.tstamp ASC, t.id ASC',
    ];

    return $map[$sort] ?? $map['date_asc'];
}

function te_fetch_rows(mysqli $conn, array $groups, int $limit = 0): array {
    $types = '';
    $params = [];
    $where = te_build_where($groups, $types, $params);
    $order = te_order_sql();

    $sql = "
        SELECT
            t.id AS transfer_id,
            t.uid,
            t.tstamp,
            t.beschreibung,
            t.konto,
            t.kasse,
            t.betrag,
            t.print_id,
            t.wasch_id,
            t.iban,
            t.pfad,
            u.username,
            u.name,
            u.firstname,
            u.lastname,
            u.room,
            u.turm
        FROM transfers t
        LEFT JOIN users u ON t.uid = u.uid
        WHERE $where
        ORDER BY $order
    ";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        te_json_response(['ok' => false, 'error' => 'prepare_failed', 'detail' => mysqli_error($conn)], 500);
    }

    te_bind_stmt($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        $detail = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        te_json_response(['ok' => false, 'error' => 'execute_failed', 'detail' => $detail], 500);
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function te_count_group(mysqli $conn, string $group): array {
    $types = '';
    $params = [];
    $where = te_build_where([$group], $types, $params);

    $sql = "
        SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(t.betrag), 0) AS summe,
            MIN(t.tstamp) AS min_ts,
            MAX(t.tstamp) AS max_ts
        FROM transfers t
        LEFT JOIN users u ON t.uid = u.uid
        WHERE $where
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        te_json_response(['ok' => false, 'error' => 'prepare_failed', 'detail' => mysqli_error($conn)], 500);
    }

    te_bind_stmt($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        $detail = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        te_json_response(['ok' => false, 'error' => 'execute_failed', 'detail' => $detail], 500);
    }

    mysqli_stmt_bind_result($stmt, $cnt, $summe, $minTs, $maxTs);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return [
        'count' => (int)$cnt,
        'sum'   => (float)$summe,
        'minTs' => $minTs !== null ? (int)$minTs : null,
        'maxTs' => $maxTs !== null ? (int)$maxTs : null,
    ];
}

function te_konto_label($konto): string {
    global $TE_KONTO_OPTIONS;

    $konto = is_numeric($konto) ? (int)$konto : null;

    return $konto !== null && isset($TE_KONTO_OPTIONS[$konto])
        ? $TE_KONTO_OPTIONS[$konto]
        : 'Undefiniert';
}

function te_kasse_label($kasse): string {
    global $TE_KASSE_OPTIONS;

    $kasse = is_numeric($kasse) ? (int)$kasse : null;

    return $kasse !== null && isset($TE_KASSE_OPTIONS[$kasse])
        ? $TE_KASSE_OPTIONS[$kasse]
        : 'Undefiniert';
}

function te_format_date($ts): string {
    $ts = (int)$ts;

    return $ts > 0 ? date('d.m.Y', $ts) : '';
}

function te_format_amount($amount): string {
    return number_format((float)$amount, 2, ',', '');
}

function te_format_amount_display($amount): string {
    return number_format((float)$amount, 2, ',', '.') . ' €';
}

function te_format_turm($turm): string {
    $turm = trim((string)$turm);

    if ($turm === '') {
        return '';
    }

    return strtolower($turm) === 'tvk' ? 'TvK' : strtoupper($turm);
}

function te_user_fullname(array $row): string {
    $name = trim((string)($row['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $firstname = trim((string)($row['firstname'] ?? ''));
    $lastname = trim((string)($row['lastname'] ?? ''));
    $combined = trim($firstname . ' ' . $lastname);

    if ($combined !== '') {
        return $combined;
    }

    $username = trim((string)($row['username'] ?? ''));

    if ($username !== '') {
        return $username;
    }

    $uid = (string)($row['uid'] ?? '');

    return $uid !== '' ? 'UID ' . $uid : '';
}

function te_partner_name(array $row): string {
    $name = te_user_fullname($row);
    $uid = (string)($row['uid'] ?? '');

    if ($name !== '' && $uid !== '') {
        return $name . ' [UID ' . $uid . ']';
    }

    if ($name !== '') {
        return $name;
    }

    if ($uid !== '') {
        return 'UID ' . $uid;
    }

    return '';
}

function te_bucket_label(array $row): string {
    $kasse = isset($row['kasse']) ? (int)$row['kasse'] : null;

    if (in_array($kasse, [72, 69, 92, 1, 2, 93, 94, 95], true)) {
        return te_kasse_label($kasse);
    }

    $beschreibung = (string)($row['beschreibung'] ?? '');

    if (!empty($row['print_id'])) {
        return 'Druckaufträge';
    }

    if (
        strpos($beschreibung, 'Abrechnung Hausbeitrag') === 0
        || strpos($beschreibung, 'Abrechnung Netzbeitrag') === 0
    ) {
        return 'Abrechnungen';
    }

    if ($beschreibung === 'Waschmarken generiert') {
        return 'Waschmarken';
    }

    return 'Sonstiges';
}

function te_receipt_info(array $row): ?array {
    $pfad = trim((string)($row['pfad'] ?? ''));

    if ($pfad === '') {
        return null;
    }

    $baseDir = realpath(__DIR__);

    if ($baseDir === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $pfad);
    $normalized = ltrim($normalized, '/');

    $candidate = __DIR__ . '/' . $normalized;
    $real = realpath($candidate);

    if ($real === false || !is_file($real) || !is_readable($real)) {
        return null;
    }

    $baseDirWithSlash = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (strpos($real, $baseDirWithSlash) !== 0) {
        return null;
    }

    $transferId = preg_replace('/\D+/', '', (string)($row['transfer_id'] ?? ''));

    if ($transferId === '') {
        return null;
    }

    $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $filename = $transferId . ($extension !== '' ? '.' . $extension : '');

    return [
        'number'   => $transferId,
        'zip_path' => 'rechnungen/' . $filename,
        'path'     => $real,
    ];
}

function te_csv_value(string $column, array $row): string {
    switch ($column) {
        case 'buchungstag':
            return te_format_date($row['tstamp'] ?? 0);

        case 'beschreibung':
            return (string)($row['beschreibung'] ?? '');

        case 'zahlungspartner':
            return te_partner_name($row);

        case 'betrag':
            return te_format_amount($row['betrag'] ?? 0);

        case 'waehrung':
            return 'EUR';

        case 'kasse_konto':
            return te_bucket_label($row);

        case 'kategorie':
            return te_konto_label($row['konto'] ?? null);

        case 'transfer_id':
            return (string)($row['transfer_id'] ?? '');

        case 'uid':
            return (string)($row['uid'] ?? '');

        case 'username':
            return (string)($row['username'] ?? '');

        case 'voller_name':
            return te_user_fullname($row);

        case 'raum':
            return (string)($row['room'] ?? '');

        case 'turm':
            return te_format_turm($row['turm'] ?? '');

        case 'konto_id':
            return (string)($row['konto'] ?? '');

        case 'konto_label':
            return te_konto_label($row['konto'] ?? null);

        case 'kasse_id':
            return (string)($row['kasse'] ?? '');

        case 'kasse_label':
            return te_kasse_label($row['kasse'] ?? null);

        case 'exportbereich':
            return te_bucket_label($row);

        case 'unix_timestamp':
            return (string)($row['tstamp'] ?? '');

        case 'iban':
            return (string)($row['iban'] ?? '');

        case 'print_id':
            return (string)($row['print_id'] ?? '');

        case 'wasch_id':
            return (string)($row['wasch_id'] ?? '');

        case 'rechnungspfad':
            return (string)($row['pfad'] ?? '');

        default:
            return '';
    }
}

function te_csv_string(array $rows, array $columns, bool $includeReceiptNumber = false): string {
    global $TE_COLUMN_DEFS;

    $fp = fopen('php://temp', 'r+');

    fwrite($fp, "\xEF\xBB\xBF");

    $header = [];

    foreach ($columns as $column) {
        if (isset($TE_COLUMN_DEFS[$column])) {
            $header[] = $TE_COLUMN_DEFS[$column]['label'];
        }
    }

    if ($includeReceiptNumber) {
        $header[] = 'Rechnungsnummer';
    }

    fputcsv($fp, $header, ';', '"');

    foreach ($rows as $row) {
        $csvRow = [];

        foreach ($columns as $column) {
            $csvRow[] = te_csv_value($column, $row);
        }

        if ($includeReceiptNumber) {
            $receipt = te_receipt_info($row);
            $csvRow[] = $receipt ? $receipt['number'] : '';
        }

        fputcsv($fp, $csvRow, ';', '"');
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    return $csv;
}

function te_safe_filename_part(string $value): string {
    $value = strtolower(trim($value));
    $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
    $value = preg_replace('/[^a-z0-9_\-]+/', '_', $value);
    $value = trim($value, '_-');

    return $value !== '' ? $value : 'export';
}

function te_filename(array $groups): string {
    global $TE_EXPORT_GROUPS;

    $from = trim((string)($_POST['date_from'] ?? ''));
    $to = trim((string)($_POST['date_to'] ?? ''));

    if (count($groups) === 1 && isset($TE_EXPORT_GROUPS[$groups[0]])) {
        $base = 'transfers_' . te_safe_filename_part($TE_EXPORT_GROUPS[$groups[0]]['label']);
    } else {
        $base = 'transfers_export';
    }

    if ($from !== '' || $to !== '') {
        $base .= '_' . te_safe_filename_part($from !== '' ? $from : 'anfang');
        $base .= '_bis_' . te_safe_filename_part($to !== '' ? $to : 'ende');
    } else {
        $base .= '_alle';
    }

    return $base . '.csv';
}

function te_zip_filename(array $groups): string {
    $from = trim((string)($_POST['date_from'] ?? ''));
    $to = trim((string)($_POST['date_to'] ?? ''));

    $base = 'transfers_mit_rechnungen';

    if (count($groups) === 1) {
        global $TE_EXPORT_GROUPS;
        $base .= '_' . te_safe_filename_part($TE_EXPORT_GROUPS[$groups[0]]['label'] ?? $groups[0]);
    }

    if ($from !== '' || $to !== '') {
        $base .= '_' . te_safe_filename_part($from !== '' ? $from : 'anfang');
        $base .= '_bis_' . te_safe_filename_part($to !== '' ? $to : 'ende');
    } else {
        $base .= '_alle';
    }

    return $base . '.zip';
}

function te_handle_preview(mysqli $conn): void {
    global $TE_EXPORT_GROUPS;

    $groups = te_selected_groups();

    if (!$groups) {
        te_json_response([
            'ok' => false,
            'error' => 'no_groups',
            'message' => 'Keine Exportbereiche ausgewählt.'
        ], 400);
    }

    $summary = [];
    $totalCount = 0;
    $totalSum = 0.0;

    foreach ($groups as $group) {
        $stats = te_count_group($conn, $group);
        $totalCount += $stats['count'];
        $totalSum += $stats['sum'];

        $summary[] = [
            'key'     => $group,
            'label'   => $TE_EXPORT_GROUPS[$group]['label'],
            'count'   => $stats['count'],
            'sum'     => te_format_amount_display($stats['sum']),
            'minDate' => $stats['minTs'] ? te_format_date($stats['minTs']) : '',
            'maxDate' => $stats['maxTs'] ? te_format_date($stats['maxTs']) : '',
        ];
    }

    $rows = te_fetch_rows($conn, $groups, 50);
    $previewRows = [];

    foreach ($rows as $row) {
        $previewRows[] = [
            'date'        => te_format_date($row['tstamp'] ?? 0),
            'bucket'      => te_bucket_label($row),
            'category'    => te_konto_label($row['konto'] ?? null),
            'partner'     => te_partner_name($row),
            'description' => (string)($row['beschreibung'] ?? ''),
            'amount'      => te_format_amount_display($row['betrag'] ?? 0),
        ];
    }

    te_json_response([
        'ok' => true,
        'total' => [
            'count' => $totalCount,
            'sum'   => te_format_amount_display($totalSum),
        ],
        'summary' => $summary,
        'rows' => $previewRows,
    ]);
}

function te_handle_download(mysqli $conn): void {
    $groups = te_selected_groups();

    if (!$groups) {
        te_plain_response('Keine Exportbereiche ausgewählt.', 400);
    }

    $columns = te_selected_columns();
    $rows = te_fetch_rows($conn, $groups, 0);
    $filename = te_filename($groups);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo te_csv_string($rows, $columns, false);
    exit;
}

function te_handle_download_with_receipts(mysqli $conn): void {
    global $TE_EXPORT_GROUPS;

    if (!class_exists('ZipArchive')) {
        te_plain_response('ZIP-Export nicht möglich: PHP-ZipArchive ist auf diesem Server nicht verfügbar.', 500);
    }

    $groups = te_selected_groups();

    if (!$groups) {
        te_plain_response('Keine Exportbereiche ausgewählt.', 400);
    }

    $columns = te_selected_columns();
    $exportMode = (string)($_POST['export_mode'] ?? 'combined');

    if (!in_array($exportMode, ['combined', 'separate'], true)) {
        $exportMode = 'combined';
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'transfer_export_');

    if ($tmpZip === false) {
        te_plain_response('ZIP-Export nicht möglich: Temporäre Datei konnte nicht erstellt werden.', 500);
    }

    $zip = new ZipArchive();

    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        te_plain_response('ZIP-Export nicht möglich: ZIP-Datei konnte nicht geöffnet werden.', 500);
    }

    $allRowsForReceipts = [];

    if ($exportMode === 'separate') {
        foreach ($groups as $group) {
            $rows = te_fetch_rows($conn, [$group], 0);
            $allRowsForReceipts = array_merge($allRowsForReceipts, $rows);

            $csvName = te_filename([$group]);
            $zip->addFromString('csv/' . $csvName, te_csv_string($rows, $columns, true));
        }
    } else {
        $rows = te_fetch_rows($conn, $groups, 0);
        $allRowsForReceipts = $rows;

        $csvName = te_filename($groups);
        $zip->addFromString($csvName, te_csv_string($rows, $columns, true));
    }

    $addedReceipts = [];

    foreach ($allRowsForReceipts as $row) {
        $receipt = te_receipt_info($row);

        if (!$receipt) {
            continue;
        }

        if (isset($addedReceipts[$receipt['zip_path']])) {
            continue;
        }

        $zip->addFile($receipt['path'], $receipt['zip_path']);
        $addedReceipts[$receipt['zip_path']] = true;
    }

    $infoText = "Export erzeugt am: " . date('d.m.Y H:i:s') . "\n";
    $infoText .= "CSV-Dateien enthalten nur bei diesem ZIP-Export zusätzlich die Spalte \"Rechnungsnummer\".\n";
    $infoText .= "Rechnungsdateien liegen im Ordner rechnungen/ und sind nach Transfer-ID benannt.\n";
    $infoText .= "Exportierte Rechnungen: " . count($addedReceipts) . "\n";

    $zip->addFromString('README.txt', $infoText);
    $zip->close();

    $filename = te_zip_filename($groups);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

function te_existing_years(mysqli $conn): array {
    $sql = "
        SELECT DISTINCT YEAR(FROM_UNIXTIME(tstamp)) AS jahr
        FROM transfers
        WHERE tstamp IS NOT NULL
          AND tstamp > 0
        ORDER BY jahr DESC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return [];
    }

    $years = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $year = (int)($row['jahr'] ?? 0);

        if ($year > 1970) {
            $years[] = $year;
        }
    }

    return $years;
}

function te_existing_tax_blocks(array $years, int $anchor = 2023): array {
    $blocks = [];

    foreach ($years as $year) {
        if ($year < $anchor) {
            continue;
        }

        $start = $anchor + (3 * floor(($year - $anchor) / 3));
        $end = $start + 2;
        $key = $start . '-' . $end;

        $blocks[$key] = [
            'start' => $start,
            'end'   => $end,
        ];
    }

    uasort($blocks, function ($a, $b) {
        return $b['start'] <=> $a['start'];
    });

    return array_values($blocks);
}

$action = $_POST['transfer_export_action'] ?? '';

if ($action !== '') {
    if (!te_has_access()) {
        te_json_response(['ok' => false, 'error' => 'not_authorized'], 403);
    }

    if (!te_validate_csrf()) {
        te_json_response(['ok' => false, 'error' => 'csrf_invalid'], 403);
    }

    if ($action === 'preview') {
        te_handle_preview($conn);
    }

    if ($action === 'download') {
        te_handle_download($conn);
    }

    if ($action === 'download_with_receipts') {
        te_handle_download_with_receipts($conn);
    }

    te_json_response(['ok' => false, 'error' => 'unknown_action'], 400);
}

if (!te_has_access()) {
    header('Location: denied.php');
    exit;
}

$csrf = te_csrf_token();

$singleYears = te_existing_years($conn);
$taxBlocks = te_existing_tax_blocks($singleYears, 2023);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Transfer-Export</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <link rel="stylesheet" href="TRANSFERS.css" media="screen">
    <style>
        .te-page {
            width: 82%;
            max-width: 1400px;
            margin: 20px auto 60px auto;
            color: #eee;
            font-family: system-ui, sans-serif;
        }

        .te-title {
            text-align: center;
            margin: 20px 0 8px 0;
            color: #fff;
        }

        .te-subtitle {
            width: 75%;
            max-width: 980px;
            margin: 0 auto 22px auto;
            color: #aaa;
            text-align: center;
            line-height: 1.45;
            font-size: 14px;
        }

        .te-panel {
            background: #1f1f1f;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 16px;
            margin: 14px 0;
        }

        .te-panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            color: #fff;
            font-size: 18px;
            font-weight: 800;
        }

        .te-panel-subtitle {
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            margin: 14px 0 10px 0;
        }

        .te-panel-note {
            color: #aaa;
            font-size: 13px;
            line-height: 1.35;
            margin-top: -4px;
            margin-bottom: 12px;
        }

        .te-actions-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            margin: 10px 0 14px 0;
        }

        .te-small-btn,
        .te-primary-btn,
        .te-secondary-btn {
            font-family: inherit;
            border: 2px solid #444;
            padding: 8px 12px;
            cursor: pointer;
            background-color: #2a2a2a;
            color: #fff;
            transition: background-color 0.2s, color 0.2s, border-color 0.2s;
            border-radius: 4px;
            font-weight: 700;
        }

        .te-small-btn {
            font-size: 13px;
            padding: 6px 10px;
        }

        .te-primary-btn {
            background-color: #14532d;
            border-color: #166534;
            font-size: 15px;
        }

        .te-primary-btn:hover,
        .te-small-btn:hover,
        .te-secondary-btn:hover {
            background-color: #166534;
            color: #fff;
        }

        .te-secondary-btn {
            font-size: 15px;
        }

        .te-primary-btn:disabled,
        .te-secondary-btn:disabled,
        .te-small-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .te-group-grid,
        .te-column-grid {
            display: grid;
            gap: 10px;
        }

        .te-external-grid,
        .te-internal-grid,
        .te-column-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .te-group-card,
        .te-column-option {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #262626;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            user-select: none;
            min-height: 42px;
            box-sizing: border-box;
        }

        .te-group-card:hover,
        .te-column-option:hover {
            border-color: #166534;
            background: #222;
        }

        .te-group-card input,
        .te-column-option input {
            transform: scale(1.2);
            accent-color: #11a50d;
        }

        .te-group-card span,
        .te-column-option span {
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            line-height: 1.25;
        }

        .te-divider {
            height: 0;
            border-top: 2px solid rgba(255,255,255,0.18);
            margin: 16px 0;
        }

        .te-soft-divider {
            height: 0;
            border-top: 1px solid rgba(255,255,255,0.12);
            margin: 14px 0;
        }

        .te-preset-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 10px 0 16px 0;
        }

        .te-preset-group {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
        }

        .te-preset-separator {
            width: 1px;
            height: 32px;
            background: rgba(255,255,255,0.25);
        }

        .te-filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .te-field label {
            display: block;
            color: #aaa;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .te-field input,
        .te-field select {
            width: 100%;
            box-sizing: border-box;
            background-color: #2a2a2a;
            color: #fff;
            border: 1px solid #444;
            border-radius: 4px;
            padding: 8px;
            font-size: 14px;
        }

        .te-mode-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 4px;
        }

        .te-mode-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #262626;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 12px;
            cursor: pointer;
            color: #eee;
        }

        .te-mode-card:hover {
            border-color: #166534;
            background: #222;
        }

        .te-mode-card input {
            margin-top: 3px;
            transform: scale(1.2);
            accent-color: #11a50d;
        }

        .te-mode-card strong {
            display: block;
            color: #fff;
            margin-bottom: 3px;
            font-size: 14px;
        }

        .te-mode-card span {
            display: block;
            color: #aaa;
            font-size: 13px;
            line-height: 1.35;
        }

        .te-status {
            display: none;
            white-space: pre-wrap;
            background: #111;
            border: 1px solid #444;
            border-left: 5px solid #11a50d;
            color: #ddd;
            border-radius: 5px;
            padding: 12px;
            line-height: 1.4;
            margin: 14px 0;
        }

        .te-status.error {
            border-left-color: #b91c1c;
            color: #ffb4b4;
        }

        .te-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .te-summary-card {
            background: #262626;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 10px;
            color: #ddd;
        }

        .te-summary-card strong {
            display: block;
            color: #fff;
            margin-bottom: 5px;
        }

        .te-summary-card span {
            display: block;
            color: #aaa;
            font-size: 13px;
            line-height: 1.35;
        }

        .te-preview-wrap {
            overflow-x: auto;
            margin-top: 14px;
        }

        .te-preview-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            color: #eee;
            background-color: #1e1e1e;
            font-size: 13px;
        }

        .te-preview-table th,
        .te-preview-table td {
            border: 1px solid #444;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }

        .te-preview-table th {
            background-color: #2a2a2a;
            color: #fff;
            font-weight: 800;
        }

        .te-preview-table tr:nth-child(even) {
            background-color: #262626;
        }

        .te-muted {
            color: #aaa;
        }

        @media (max-width: 1050px) {
            .te-external-grid,
            .te-internal-grid,
            .te-column-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .te-page {
                width: 94%;
            }

            .te-filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .te-preset-separator {
                display: none;
            }
        }

        @media (max-width: 560px) {
            .te-filter-grid,
            .te-external-grid,
            .te-internal-grid,
            .te-column-grid {
                grid-template-columns: 1fr;
            }
        }

        .te-loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.62);
            backdrop-filter: blur(2px);
        }

        .te-loading-overlay.active {
            display: flex;
        }

        .te-loading-box {
            width: min(420px, calc(100vw - 40px));
            background: #1f1f1f;
            border: 1px solid #444;
            border-radius: 10px;
            padding: 26px 24px;
            color: #fff;
            text-align: center;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.55);
        }

        .te-loading-spinner {
            width: 54px;
            height: 54px;
            margin: 0 auto 18px auto;
            border: 6px solid rgba(255, 255, 255, 0.22);
            border-top-color: #11a50d;
            border-radius: 50%;
            animation: teSpin 0.9s linear infinite;
        }

        .te-loading-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .te-loading-text {
            color: #aaa;
            font-size: 14px;
            line-height: 1.45;
        }

        body.te-is-loading {
            overflow: hidden;
            cursor: wait;
        }

        @keyframes teSpin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/template.php';

if (function_exists('load_menu')) {
    load_menu();
}
?>

<div class="te-page">
    <h1 class="te-title">Transfer-Export</h1>
    <div class="te-subtitle">
        Exportiert Einträge aus <code>transfers</code> direkt als CSV im Browser.
        <code>Buchungstag</code> ist der Zeitpunkt, der bei uns im System als <code>tstamp</code> gespeichert ist.
        Ein echtes Valutadatum, ein separater Buchungstext oder ein separater Verwendungszweck existieren in unserem Backend nicht.
    </div>

    <div id="te_status" class="te-status"></div>

    <div id="te_loading_overlay" class="te-loading-overlay" aria-hidden="true">
        <div class="te-loading-box">
            <div class="te-loading-spinner"></div>
            <div id="te_loading_title" class="te-loading-title">Export wird vorbereitet …</div>
            <div id="te_loading_text" class="te-loading-text">
                Die ZIP-Datei wird erzeugt. Je nach Anzahl der Rechnungen kann das etwas dauern.
            </div>
        </div>
    </div>

    <div class="te-panel">
        <div class="te-panel-title">
            <span>Exportbereiche</span>
        </div>

        <div class="te-actions-row">
            <button type="button" class="te-small-btn" data-select-mode="all">Alle auswählen</button>
            <button type="button" class="te-small-btn" data-select-mode="none">Alle abwählen</button>
            <button type="button" class="te-small-btn" data-select-mode="external">Nur externe Transfers</button>
            <button type="button" class="te-small-btn" data-select-mode="internal">Nur interne Transfers</button>
        </div>

        <div class="te-panel-note">
            Standardmäßig sind nur externe Transfers ausgewählt, weil das die echten Geldbewegungen auf Konten/Kassen sind.
            Interne Transfers sind Systembuchungen wie Druckaufträge, Abrechnungen und Waschmarken.
        </div>

        <div class="te-panel-subtitle">Externe Transfers</div>
        <div class="te-group-grid te-external-grid">
            <?php foreach ($TE_EXTERNAL_GROUPS as $key => $def): ?>
                <label class="te-group-card">
                    <input type="checkbox" class="te-group" value="<?= te_e($key) ?>" data-kind="external" checked>
                    <span><?= te_e($def['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="te-divider"></div>

        <div class="te-panel-subtitle">Interne Transfers</div>
        <div class="te-group-grid te-internal-grid">
            <?php foreach ($TE_INTERNAL_GROUPS as $key => $def): ?>
                <label class="te-group-card">
                    <input type="checkbox" class="te-group" value="<?= te_e($key) ?>" data-kind="internal">
                    <span><?= te_e($def['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="te-panel">
        <div class="te-panel-title">
            <span>Filter & Sortierung</span>
        </div>

        <div class="te-preset-bar">
            <div class="te-preset-group">
                <button type="button" class="te-small-btn" data-date-preset="all">Alle Jahre</button>
            </div>

            <div class="te-preset-separator"></div>

            <div class="te-preset-group">
                <?php foreach ($taxBlocks as $block): ?>
                    <button
                        type="button"
                        class="te-small-btn"
                        data-date-range-from="<?= (int)$block['start'] ?>-01-01"
                        data-date-range-to="<?= (int)$block['end'] ?>-12-31"
                    >
                        <?= (int)$block['start'] ?>–<?= (int)$block['end'] ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="te-preset-separator"></div>

            <div class="te-preset-group">
                <?php foreach ($singleYears as $year): ?>
                    <button type="button" class="te-small-btn" data-date-preset="<?= (int)$year ?>">
                        <?= (int)$year ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="te-filter-grid">
            <div class="te-field">
                <label for="te_date_from">Von</label>
                <input type="date" id="te_date_from">
            </div>

            <div class="te-field">
                <label for="te_date_to">Bis einschließlich</label>
                <input type="date" id="te_date_to">
            </div>

            <div class="te-field">
                <label for="te_amount_filter">Beträge</label>
                <select id="te_amount_filter">
                    <option value="all">Alle Beträge</option>
                    <option value="nonzero">Ohne 0,00 €</option>
                    <option value="positive">Nur Einnahmen / positive Beträge</option>
                    <option value="negative">Nur Ausgaben / negative Beträge</option>
                </select>
            </div>

            <div class="te-field">
                <label for="te_sort">Sortierung</label>
                <select id="te_sort">
                    <option value="date_asc" selected>Chronologisch: alt → neu</option>
                    <option value="date_desc">Chronologisch: neu → alt</option>
                    <option value="kasse_date">Kasse/Konto, dann Datum</option>
                    <option value="konto_date">Kategorie, dann Datum</option>
                    <option value="partner_date">Zahlungspartner, dann Datum</option>
                    <option value="amount_asc">Betrag aufsteigend</option>
                    <option value="amount_desc">Betrag absteigend</option>
                </select>
            </div>

            <div class="te-field" style="grid-column: span 4;">
                <label for="te_search">Suche in Beschreibung, Name, Username, UID, IBAN oder Transfer-ID</label>
                <input type="text" id="te_search" placeholder="Optional">
            </div>
        </div>
    </div>

    <div class="te-panel">
        <div class="te-panel-title">
            <span>CSV-Ausgabe</span>
        </div>

        <div class="te-panel-subtitle">Gruppierung</div>
        <div class="te-mode-options">
            <label class="te-mode-card">
                <input type="radio" name="te_export_mode" value="combined" checked>
                <span>
                    <strong>Eine gemeinsame CSV-Datei</strong>
                    <span>Standard. Alle ausgewählten Bereiche werden in eine Datei geschrieben. Die Spalte „Kasse/Konto“ trennt die Herkunft.</span>
                </span>
            </label>

            <label class="te-mode-card">
                <input type="radio" name="te_export_mode" value="separate">
                <span>
                    <strong>Getrennte CSV-Dateien pro ausgewähltem Bereich</strong>
                    <span>Erzeugt je Bereich eine eigene CSV, zum Beispiel Netzkonto, Hauskonto, PayPal und Tresor separat.</span>
                </span>
            </label>
        </div>

        <div class="te-divider"></div>

        <div class="te-panel-title" style="font-size:15px;">
            <span>Spalten</span>
            <span>
                <button type="button" class="te-small-btn" data-columns-mode="default">Standard</button>
                <button type="button" class="te-small-btn" data-columns-mode="all">Alle</button>
                <button type="button" class="te-small-btn" data-columns-mode="none">Keine</button>
            </span>
        </div>

        <div class="te-panel-note">
            Standardspalten sind für den Export Richtung Kassenprüfung/Steuererklärung gedacht.
            Die Spalte <code>Rechnungsnummer</code> wird absichtlich nicht hier angezeigt, sondern nur beim Button „CSV + Rechnungen exportieren“ automatisch ergänzt.
        </div>

        <div class="te-column-grid">
            <?php foreach ($TE_COLUMN_DEFS as $key => $def): ?>
                <?php if (empty($def['default'])) continue; ?>
                <label class="te-column-option">
                    <input
                        type="checkbox"
                        class="te-column"
                        value="<?= te_e($key) ?>"
                        data-default="1"
                        checked
                    >
                    <span><?= te_e($def['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="te-soft-divider"></div>

        <div class="te-panel-subtitle">Weitere Spalten</div>
        <div class="te-panel-note">
            Diese Felder sind eher für Zuordnungsprobleme, Debugging oder interne Nachvollziehbarkeit gedacht und dürften für die eigentliche Steuererklärung normalerweise nicht relevant sein.
        </div>

        <div class="te-column-grid">
            <?php foreach ($TE_COLUMN_DEFS as $key => $def): ?>
                <?php if (!empty($def['default'])) continue; ?>
                <label class="te-column-option">
                    <input
                        type="checkbox"
                        class="te-column"
                        value="<?= te_e($key) ?>"
                        data-default="0"
                    >
                    <span><?= te_e($def['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="te-actions-row" style="margin-top:18px;">
            <button type="button" id="te_preview_btn" class="te-secondary-btn">Vorschau aktualisieren</button>
            <button type="button" id="te_export_btn" class="te-primary-btn">CSV exportieren</button>
            <button type="button" id="te_export_receipts_btn" class="te-primary-btn">CSV + Rechnungen exportieren</button>
        </div>
    </div>

    <div id="te_preview_area" class="te-panel" style="display:none;">
        <div class="te-panel-title">
            <span>Vorschau</span>
            <span class="te-muted" id="te_preview_total"></span>
        </div>

        <div id="te_summary_grid" class="te-summary-grid"></div>

        <div class="te-preview-wrap">
            <table class="te-preview-table">
                <thead>
                    <tr>
                        <th>Buchungstag</th>
                        <th>Kasse/Konto</th>
                        <th>Kategorie</th>
                        <th>Zahlungspartner</th>
                        <th>Beschreibung</th>
                        <th>Betrag</th>
                    </tr>
                </thead>
                <tbody id="te_preview_rows"></tbody>
            </table>
        </div>

        <div class="te-muted" style="margin-top:10px;">
            Es werden maximal 50 Beispielzeilen angezeigt. Der CSV-Export enthält alle Treffer.
        </div>
    </div>
</div>

<script>
(() => {
    const CSRF_TOKEN = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    const statusBox = document.getElementById('te_status');
    const previewBtn = document.getElementById('te_preview_btn');
    const exportBtn = document.getElementById('te_export_btn');
    const exportReceiptsBtn = document.getElementById('te_export_receipts_btn');
    const previewArea = document.getElementById('te_preview_area');
    const summaryGrid = document.getElementById('te_summary_grid');
    const previewRows = document.getElementById('te_preview_rows');
    const previewTotal = document.getElementById('te_preview_total');

    const loadingOverlay = document.getElementById('te_loading_overlay');
    const loadingTitle = document.getElementById('te_loading_title');
    const loadingText = document.getElementById('te_loading_text');

    let loadingTimer = null;
    let loadingStartedAt = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function setStatus(message, isError = false) {
        statusBox.textContent = message;
        statusBox.classList.toggle('error', !!isError);
        statusBox.style.display = message ? 'block' : 'none';
    }

    function showLoading(title, text) {
        if (!loadingOverlay) return;

        loadingStartedAt = Date.now();

        if (loadingTitle) {
            loadingTitle.textContent = title || 'Export wird vorbereitet …';
        }

        if (loadingText) {
            loadingText.textContent = text || 'Bitte diese Seite nicht neu laden.';
        }

        loadingOverlay.classList.add('active');
        loadingOverlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('te-is-loading');

        clearInterval(loadingTimer);
        loadingTimer = setInterval(() => {
            const seconds = Math.max(1, Math.floor((Date.now() - loadingStartedAt) / 1000));

            if (loadingText) {
                loadingText.textContent =
                    `Die ZIP-Datei wird erzeugt. Bitte diese Seite nicht neu laden. Läuft seit ${seconds} Sekunden.`;
            }
        }, 1000);
    }

    function hideLoading() {
        if (!loadingOverlay) return;

        clearInterval(loadingTimer);
        loadingTimer = null;

        loadingOverlay.classList.remove('active');
        loadingOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('te-is-loading');
    }

    function selectedGroups() {
        return Array.from(document.querySelectorAll('.te-group:checked')).map(el => el.value);
    }

    function selectedColumns() {
        return Array.from(document.querySelectorAll('.te-column:checked')).map(el => el.value);
    }

    function exportMode() {
        const checked = document.querySelector('input[name="te_export_mode"]:checked');
        return checked ? checked.value : 'combined';
    }

    function buildFormData(action, groups) {
        const fd = new FormData();

        fd.append('transfer_export_action', action);
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('groups', JSON.stringify(groups));
        fd.append('columns', JSON.stringify(selectedColumns()));
        fd.append('export_mode', exportMode());
        fd.append('date_from', document.getElementById('te_date_from').value || '');
        fd.append('date_to', document.getElementById('te_date_to').value || '');
        fd.append('amount_filter', document.getElementById('te_amount_filter').value || 'all');
        fd.append('sort', document.getElementById('te_sort').value || 'date_asc');
        fd.append('search', document.getElementById('te_search').value || '');

        return fd;
    }

    function setBusy(isBusy) {
        previewBtn.disabled = isBusy;
        exportBtn.disabled = isBusy;
        exportReceiptsBtn.disabled = isBusy;

        document.querySelectorAll('.te-small-btn').forEach(btn => {
            btn.disabled = isBusy;
        });
    }

    function assertReadyForExport() {
        const groups = selectedGroups();

        if (!groups.length) {
            throw new Error('Bitte mindestens einen Exportbereich auswählen.');
        }

        const columns = selectedColumns();

        if (!columns.length) {
            throw new Error('Bitte mindestens eine CSV-Spalte auswählen.');
        }

        return groups;
    }

    async function updatePreview() {
        let groups;

        try {
            groups = assertReadyForExport();
        } catch (err) {
            setStatus(err.message, true);
            return;
        }

        setBusy(true);
        setStatus('Vorschau wird geladen …');

        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: buildFormData('preview', groups),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            const raw = await res.text();
            let data = null;

            try {
                data = JSON.parse(raw);
            } catch (e) {
                throw new Error(raw || 'Server hat keine gültige JSON-Antwort geliefert.');
            }

            if (!res.ok || !data.ok) {
                throw new Error(data.message || data.error || 'Vorschau konnte nicht geladen werden.');
            }

            previewTotal.textContent = `${data.total.count} Treffer | Summe ${data.total.sum}`;

            summaryGrid.innerHTML = '';

            data.summary.forEach(item => {
                const card = document.createElement('div');
                card.className = 'te-summary-card';
                card.innerHTML = `
                    <strong>${escapeHtml(item.label)}</strong>
                    <span>${escapeHtml(item.count)} Treffer</span>
                    <span>Summe: ${escapeHtml(item.sum)}</span>
                    <span>${escapeHtml(item.minDate || '—')} bis ${escapeHtml(item.maxDate || '—')}</span>
                `;
                summaryGrid.appendChild(card);
            });

            previewRows.innerHTML = '';

            if (!data.rows.length) {
                previewRows.innerHTML = `
                    <tr>
                        <td colspan="6" class="te-muted">Keine Treffer für die aktuelle Auswahl.</td>
                    </tr>
                `;
            } else {
                data.rows.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escapeHtml(row.date)}</td>
                        <td>${escapeHtml(row.bucket)}</td>
                        <td>${escapeHtml(row.category)}</td>
                        <td>${escapeHtml(row.partner)}</td>
                        <td>${escapeHtml(row.description)}</td>
                        <td>${escapeHtml(row.amount)}</td>
                    `;
                    previewRows.appendChild(tr);
                });
            }

            previewArea.style.display = 'block';
            setStatus('Vorschau geladen.');
        } catch (err) {
            setStatus('Fehler:\n' + (err.message || String(err)), true);
        } finally {
            setBusy(false);
        }
    }

    function filenameFromResponse(res, fallback) {
        const cd = res.headers.get('Content-Disposition') || '';
        const match = cd.match(/filename\*=UTF-8''([^;]+)|filename="?([^"]+)"?/i);

        if (match) {
            return decodeURIComponent(match[1] || match[2] || fallback);
        }

        return fallback;
    }

    async function downloadBlob(action, groups, fallbackFilename) {
        const res = await fetch(window.location.href, {
            method: 'POST',
            body: buildFormData(action, groups),
            credentials: 'same-origin'
        });

        if (!res.ok) {
            const text = await res.text();
            throw new Error(text || 'Download fehlgeschlagen.');
        }

        const blob = await res.blob();
        const filename = filenameFromResponse(res, fallbackFilename);
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.style.display = 'none';

        document.body.appendChild(a);
        a.click();
        a.remove();

        setTimeout(() => URL.revokeObjectURL(url), 30000);
    }

    async function exportCsv() {
        let groups;

        try {
            groups = assertReadyForExport();
        } catch (err) {
            setStatus(err.message, true);
            return;
        }

        setBusy(true);

        try {
            if (exportMode() === 'combined') {
                setStatus('CSV wird erzeugt …');
                await downloadBlob('download', groups, 'transfers_export.csv');
                setStatus('CSV wurde heruntergeladen.');
            } else {
                setStatus(`CSV-Dateien werden erzeugt …\n0/${groups.length}`);

                let done = 0;

                for (const group of groups) {
                    await downloadBlob('download', [group], 'transfers_export.csv');
                    done++;
                    setStatus(`CSV-Dateien werden erzeugt …\n${done}/${groups.length}`);
                    await new Promise(resolve => setTimeout(resolve, 180));
                }

                setStatus(`${groups.length} CSV-Datei(en) wurden heruntergeladen.`);
            }
        } catch (err) {
            setStatus('Fehler:\n' + (err.message || String(err)), true);
        } finally {
            setBusy(false);
        }
    }

    async function exportCsvWithReceipts() {
        let groups;

        try {
            groups = assertReadyForExport();
        } catch (err) {
            setStatus(err.message, true);
            return;
        }

        setBusy(true);
        setStatus('ZIP mit CSV und Rechnungen wird erzeugt …');

        showLoading(
            'ZIP-Export läuft …',
            'Die CSV-Datei und alle vorhandenen Rechnungen werden gesammelt. Bitte diese Seite nicht neu laden! Der Prozess kann mehrere Minuten in Anspruch nehmen, je nach Anzahl und Größe der Rechnungen.'
        );

        try {
            await downloadBlob('download_with_receipts', groups, 'transfers_mit_rechnungen.zip');
            setStatus('ZIP mit CSV und Rechnungen wurde heruntergeladen.');
        } catch (err) {
            setStatus('Fehler:\n' + (err.message || String(err)), true);
        } finally {
            hideLoading();
            setBusy(false);
        }
    }

    document.querySelectorAll('[data-select-mode]').forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.selectMode;

            document.querySelectorAll('.te-group').forEach(cb => {
                if (mode === 'all') cb.checked = true;
                if (mode === 'none') cb.checked = false;
                if (mode === 'external') cb.checked = cb.dataset.kind === 'external';
                if (mode === 'internal') cb.checked = cb.dataset.kind === 'internal';
            });
        });
    });

    document.querySelectorAll('[data-columns-mode]').forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.columnsMode;

            document.querySelectorAll('.te-column').forEach(cb => {
                if (mode === 'all') cb.checked = true;
                if (mode === 'none') cb.checked = false;
                if (mode === 'default') cb.checked = cb.dataset.default === '1';
            });
        });
    });

    document.querySelectorAll('[data-date-preset]').forEach(btn => {
        btn.addEventListener('click', () => {
            const preset = btn.dataset.datePreset;
            const from = document.getElementById('te_date_from');
            const to = document.getElementById('te_date_to');

            if (preset === 'all') {
                from.value = '';
                to.value = '';
                return;
            }

            from.value = `${preset}-01-01`;
            to.value = `${preset}-12-31`;
        });
    });

    document.querySelectorAll('[data-date-range-from][data-date-range-to]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('te_date_from').value = btn.dataset.dateRangeFrom;
            document.getElementById('te_date_to').value = btn.dataset.dateRangeTo;
        });
    });

    previewBtn.addEventListener('click', updatePreview);
    exportBtn.addEventListener('click', exportCsv);
    exportReceiptsBtn.addEventListener('click', exportCsvWithReceipts);
})();
</script>

</body>
</html>