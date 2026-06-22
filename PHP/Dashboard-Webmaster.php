<?php
use Cron\CronExpression;

session_start();
require('conn.php');
date_default_timezone_set('Europe/Berlin');
require_once 'vendor/autoload.php';

/*
 * Dashboard2.1.php
 * - Ajax/API-Antworten laufen über ?d2api=...
 * - template.php wird absichtlich gepuffert geladen, damit dessen HTML-Ausgabe keine JSON-Antworten zerstört.
 * - Die fachliche Logik entspricht Dashboard.php; die Oberfläche arbeitet ohne Seitenreloads.
 */
ob_start();
require_once 'template.php';
$d2_template_output = ob_get_clean();

mysqli_set_charset($conn, "utf8");

function d2_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function d2_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function d2_stmt_rows(mysqli_stmt $stmt): array
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
        return $rows;
    }

    return [];
}

function d2_require_netzag(mysqli $conn): void
{
    if (!auth($conn) || empty($_SESSION['Webmaster'])) {
        d2_json(['ok' => false, 'error' => 'Nicht berechtigt.'], 403);
    }
}

function d2_script_catalog(): array
{
    return [
        'anmeldung' => [
            'key' => 'anmeldung',
            'label' => 'Anmeldung',
            'needle' => 'anmeldung.sh',
            'description' => 'Verarbeitet offene Anmeldungen systemseitig und führt die regulären Nacharbeiten aus.',
            'kind' => 'system',
        ],
        'entsperren' => [
            'key' => 'entsperren',
            'label' => 'Entsperren',
            'needle' => 'payment_entsperren.py',
            'description' => 'Entsperrt User nach Zahlungseingang, sofern die Kriterien erfüllt sind.',
            'kind' => 'system',
        ],
        'wehdhcp' => [
            'key' => 'wehdhcp',
            'label' => 'WEH DHCP',
            'needle' => 'wehdhcp',
            'description' => 'DHCP-Abgleich für WEH. Cronzeit ist simuliert, falls der Job remote läuft.',
            'kind' => 'simulated',
            'expression' => '*/5 * * * *',
        ],
        'abmeldung' => [
            'key' => 'abmeldung',
            'label' => 'Abmeldung',
            'needle' => 'abmeldung.py',
            'description' => 'Verarbeitet Abmeldungen und zugehörige Backend-Schritte.',
            'kind' => 'system',
        ],
        'pskabgleich' => [
            'key' => 'pskabgleich',
            'label' => 'PSK-Abgleich',
            'needle' => 'pskabgleich.py',
            'description' => 'Synchronisiert WEH-PSK-MACs mit dem WLC. Wird nach WEH-PSK-Accept automatisch gestartet.',
            'kind' => 'system',
            'hidden' => true,
        ],
        'cleanup' => [
            'key' => 'cleanup',
            'label' => 'User-Cleanup',
            'needle' => 'user_cleanup.py',
            'description' => 'Räumt abgelaufene Zustände und User-bezogene Altlasten auf.',
            'kind' => 'system',
        ],
        'tvkdhcp' => [
            'key' => 'tvkdhcp',
            'label' => 'TvK DHCP',
            'needle' => 'tvkdhcp',
            'description' => 'DHCP-Abgleich für TvK. Cronzeit ist simuliert, falls der Job remote läuft.',
            'kind' => 'simulated',
            'expression' => '*/5 * * * *',
        ],
    ];
}

function d2_cron_times_from_crontab(string $crontabPath, string $scriptName): ?array
{
    if (!is_readable($crontabPath)) {
        return null;
    }

    $lines = file($crontabPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (strpos($line, $scriptName) === false) {
            continue;
        }

        $parts = preg_split('/\s+/', $line, 7);
        if (count($parts) < 6) {
            continue;
        }

        $schedule = implode(' ', array_slice($parts, 0, 5));
        try {
            $cron = CronExpression::factory($schedule);
            $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
            return [
                'prev' => $cron->getPreviousRunDate($now),
                'next' => $cron->getNextRunDate($now),
                'expression' => $schedule,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

function d2_cron_times_from_expression(string $expression): ?array
{
    try {
        $cron = CronExpression::factory($expression);
        $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        return [
            'prev' => $cron->getPreviousRunDate($now),
            'next' => $cron->getNextRunDate($now),
            'expression' => $expression,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function d2_collect_crons(): array
{
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $crons = [];

    foreach (d2_script_catalog() as $key => $script) {
        $times = null;
        if (($script['kind'] ?? 'system') === 'simulated') {
            $times = d2_cron_times_from_expression($script['expression']);
        } else {
            $times = d2_cron_times_from_crontab('/etc/crontab', $script['needle']);
        }

        $entry = $script;
        $entry['prevRun'] = null;
        $entry['nextRun'] = null;
        $entry['secondsLeft'] = null;
        $entry['expression'] = $times['expression'] ?? ($script['expression'] ?? null);

        if ($times) {
            $entry['prevRun'] = $times['prev']->format(DateTime::ATOM);
            $entry['nextRun'] = $times['next']->format(DateTime::ATOM);
            $entry['secondsLeft'] = max(0, $times['next']->getTimestamp() - $now->getTimestamp());
        }

        $crons[$key] = $entry;
    }

    return $crons;
}

function d2_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $refs = [$types];
    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}



function d2_money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

function d2_build_user_room_email($room, $turm): string
{
    $roomPart = str_pad((string)intval($room), 4, '0', STR_PAD_LEFT);
    $turmPart = strtolower(trim((string)$turm));
    return 'z' . $roomPart . '@' . $turmPart . '.rwth-aachen.de';
}

function d2_send_plain_mail(string $to, string $subject, string $message, string $replyTo = 'vorstand@weh.rwth-aachen.de'): bool
{
    $from = 'system@weh.rwth-aachen.de';
    $headers  = "From: WEH Backend <{$from}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    return mail($to, $subject, $message, $headers);
}

function d2_balance(mysqli $conn, int $kasse): float
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

function d2_user_bound_sum(mysqli $conn): float
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

function d2_sum_negative_transfers(mysqli $conn, int $startTs, int $endTsExcl, string $whereSql, string $whereTypes = '', array $whereParams = []): float
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
    d2_bind_params($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sum);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (float)($sum ?? 0);
}

function d2_current_year_balance(mysqli $conn): float
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
    $income += d2_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung = 'Waschmarken generiert'");
    $income += d2_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE 'Abrechnung Netzbeitrag%'");
    $income += d2_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE 'Abrechnung Hausbeitrag%'");
    $income += d2_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.print_id IS NOT NULL AND t.print_id <> 0");

    $groupNetzAG = [1, 2, 69, 72];
    $groupHaus = [92, 93, 94, 95];

    $phNetz = implode(',', array_fill(0, count($groupNetzAG), '?'));
    $expenseNetz = d2_sum_negative_transfers(
        $conn,
        $startTs,
        $endTsExcl,
        "$internalPairFilter AND t.kasse IN ($phNetz) AND $knownCategoryFilter",
        str_repeat('i', count($groupNetzAG)),
        $groupNetzAG
    );

    $phHaus = implode(',', array_fill(0, count($groupHaus), '?'));
    $expenseHaus = d2_sum_negative_transfers(
        $conn,
        $startTs,
        $endTsExcl,
        "$internalPairFilter AND t.kasse IN ($phHaus) AND $knownCategoryFilter",
        str_repeat('i', count($groupHaus)),
        $groupHaus
    );

    $expenseAgEssen = d2_sum_negative_transfers($conn, $startTs, $endTsExcl, "$internalPairFilter AND t.beschreibung LIKE '%AG-Essen%'");

    return $income - ($expenseNetz + $expenseHaus + $expenseAgEssen);
}

function d2_member_count(mysqli $conn): int
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

function d2_get_ag_name(mysqli $conn, int $agId): string
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

function d2_format_einrichtung(mysqli $conn, string $einrichtung): string
{
    if (function_exists('formatEinrichtung')) {
        return formatEinrichtung($einrichtung, $conn);
    }

    if (preg_match('/^ag:([0-9]+)$/', $einrichtung, $matches)) {
        return d2_get_ag_name($conn, intval($matches[1]));
    }

    if (preg_match('/^etage:(weh|tvk)_([0-9]+)$/', $einrichtung, $matches)) {
        return d2_format_turm($matches[1]) . ' ' . intval($matches[2]) . '. Etage';
    }

    return $einrichtung;
}

function d2_file_preview(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '<div class="d2-empty-note">Keine Datei hinterlegt.</div>';
    }

    $pathOnly = parse_url($path, PHP_URL_PATH);
    if ($pathOnly === null || $pathOnly === false) {
        $pathOnly = $path;
    }

    $extension = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
    $safePath = d2_h($path);

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return '<div class="d2-file-stage"><img src="' . $safePath . '" alt="Datei"></div>';
    }

    if ($extension === 'pdf') {
        return '<div class="d2-file-stage"><embed src="' . $safePath . '#zoom=page-width" type="application/pdf"></div>';
    }

    return '<a class="d2-file-link" href="' . $safePath . '" target="_blank">Datei öffnen</a>';
}

function d2_account_info(string $text): string
{
    return '<div class="d2-account-info">' . d2_h($text) . '</div>';
}

function d2_detail_rows(array $rows, string $extraClass = ''): string
{
    $class = trim('d2-detail-list ' . $extraClass);
    $html = '<div class="' . d2_h($class) . '">';

    foreach ($rows as $row) {
        $label = $row[0] ?? '';
        $value = $row[1] ?? '';
        $wideClass = !empty($row[2]) ? ' d2-detail-row-wide' : '';
        $html .= '<div class="d2-detail-row' . $wideClass . '"><span>' . d2_h($label) . '</span><strong>' . $value . '</strong></div>';
    }

    $html .= '</div>';
    return $html;
}

function d2_decision_count(array $row, string $decision): int
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

function d2_purchase_open_sql_condition(string $alias = 'ea'): string
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

function d2_fetch_purchase_request(mysqli $conn, int $id): ?array
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
    $rows = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    return $rows[0] ?? null;
}


function d2_modal_agessen(mysqli $conn, int $id): string
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
    $rows = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $row = $rows[0] ?? null;
    if (!$row) {
        return d2_modal_shell('AG-Essen', '<p class="d2-modal-error">AG-Essen-Antrag nicht gefunden oder bereits verarbeitet.</p>');
    }

    $agId = intval($row['ag']);
    $agName = $ag_complete[$agId]['name'] ?? d2_get_ag_name($conn, $agId);
    $betrag = number_format((float)$row['betrag'], 2, ',', '.');
    $ibanShow = $ibanLabels[$row['iban']] ?? $row['iban'];

    ob_start();
    ?>
    <form method="post" class="d2-action-form">
        <input type="hidden" name="agessen_confirm" value="1">
        <input type="hidden" name="id" value="<?= d2_h($row['id']) ?>">
        <input type="hidden" name="pfad" value="<?= d2_h($row['pfad']) ?>">
        <input type="hidden" name="betrag" value="<?= d2_h($row['betrag']) ?>">
        <input type="hidden" name="iban" value="<?= d2_h($row['iban']) ?>">
        <input type="hidden" name="ag" value="<?= d2_h($row['ag']) ?>">
        <input type="hidden" name="uid" value="<?= d2_h($row['uid']) ?>">

        <?= d2_account_info('AG-Essen: hochgeladene Rechnung vom Hauskonto DE37 3905 0000 1070 3345 84 erstatten. Bei Bar-Auswahl wird wie in AG-Essen.php die entsprechende Barkasse benutzt.') ?>

        <div class="d2-modal-split">
            <div><?= d2_file_preview($row['pfad']) ?></div>
            <div>
                <?= d2_detail_rows([
                    ['Datum', d2_h(date('d.m.Y', intval($row['tstamp'])))],
                    ['AG', d2_h($agName)],
                    ['Betrag', d2_h($betrag) . ' €'],
                    ['IBAN / Kasse', d2_h($ibanShow)],
                    ['Kontoinhaber', d2_h($row['full_name'])],
                    ['Teilnehmer', d2_h($row['teilnehmer_namen'])],
                ]) ?>

                <div class="d2-copy-list">
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($row['iban']) ?>"><span>IBAN/Kasse</span><?= d2_h($ibanShow) ?></button>
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($row['full_name']) ?>"><span>Name</span><?= d2_h($row['full_name']) ?></button>
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($betrag) ?>"><span>Betrag</span><?= d2_h($betrag) ?> €</button>
                    <button type="button" class="d2-copy-btn" data-copy="AG-Essen <?= d2_h($agName) ?>"><span>Zweck</span>AG-Essen <?= d2_h($agName) ?></button>
                </div>

                <button type="submit" class="d2-submit">Überwiesen</button>
            </div>
        </div>
    </form>
    <?php
    return d2_modal_shell('AG-Essen erstatten', ob_get_clean(), 'd2-wide-modal');
}

function d2_modal_erstattung(mysqli $conn, int $id): string
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
    $rows = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $row = $rows[0] ?? null;
    if (!$row) {
        return d2_modal_shell('Erstattung', '<p class="d2-modal-error">Erstattungsantrag nicht gefunden oder bereits verarbeitet.</p>');
    }

    $einrichtung = d2_format_einrichtung($conn, (string)$row['einrichtung']);
    $betrag = number_format((float)$row['betrag'], 2, ',', '.');
    $email = d2_build_user_room_email($row['room'], $row['turm']);

    ob_start();
    ?>
    <form method="post" class="d2-action-form">
        <input type="hidden" name="erstattung_action" value="1">
        <input type="hidden" name="request_id" value="<?= d2_h($row['id']) ?>">

        <?= d2_account_info('Erstattung: hochgeladene Rechnung vom Hauskonto DE37 3905 0000 1070 3345 84 an die angegebene IBAN erstatten.') ?>

        <div class="d2-modal-split">
            <div><?= d2_file_preview($row['pfad']) ?></div>
            <div>
                <?= d2_detail_rows([
                    ['Datum', d2_h(date('d.m.Y', intval($row['tstamp'])))],
                    ['Einrichtung', d2_h($einrichtung)],
                    ['Name', d2_h($row['name'])],
                    ['IBAN', d2_h($row['iban'])],
                    ['Betrag', d2_h($betrag) . ' €'],
                    ['E-Mail', d2_h($email)],
                ]) ?>

                <div class="d2-copy-list">
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($row['iban']) ?>"><span>IBAN</span><?= d2_h($row['iban']) ?></button>
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($row['name']) ?>"><span>Name</span><?= d2_h($row['name']) ?></button>
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($betrag) ?>"><span>Betrag</span><?= d2_h($betrag) ?> €</button>
                    <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($einrichtung) ?> Erstattung"><span>Zweck</span><?= d2_h($einrichtung) ?> Erstattung</button>
                </div>

            </div>
        </div>

        <div class="d2-radio-row">
            <label><input type="radio" name="action" value="accept"> <span class="d2-green">ÜBERWIESEN</span></label>
            <label><input type="radio" name="action" value="decline"> <span class="d2-red">ABLEHNEN</span></label>
            <label><input type="radio" name="action" value="remove"> <span class="d2-warn">REMOVE</span></label>
        </div>

        <div class="d2-hint">
            Bei Überwiesen wird ein Transfer angelegt und eine Mail versendet.<br>
            Bei Ablehnen wird der User per Mail informiert.<br>
            Remove setzt den Antrag auf entfernt, ohne Mail und ohne Transfer.
        </div>

        <button type="submit" class="d2-submit">Hau raus!</button>
    </form>
    <?php
    return d2_modal_shell('Erstattung bearbeiten', ob_get_clean(), 'd2-wide-modal');
}

function d2_modal_einkauf(mysqli $conn, int $id): string
{
    $row = d2_fetch_purchase_request($conn, $id);
    if (!$row) {
        return d2_modal_shell('Einkaufsantrag', '<p class="d2-modal-error">Einkaufsantrag nicht gefunden.</p>');
    }

    ob_start();
    ?>
    <form method="post" class="d2-action-form">
        <input type="hidden" name="purchase_decision" value="1">
        <input type="hidden" name="purchase_id" value="<?= d2_h($id) ?>">

        <?= d2_account_info('Einkauf: AG-Antrag für Kauf über 100 € oder unsicheren Zweck. Bei dritter Zustimmung/Ablehnung wird automatisch eine Mail an die AG-Adresse aus groups.mail verschickt.') ?>

        <?= d2_detail_rows([
            ['Titel', d2_h($row['titel'])],
            ['AG', d2_h($row['ag_name'] ?: ('AG #' . intval($row['ag_id'])))],
            ['Maximalbetrag', d2_h(d2_money((float)$row['maxbetrag']))],
            ['Eingereicht von', d2_h($row['submitter_name'] ?: ('UID ' . intval($row['uid'])))],
            ['Datum', d2_h(date('d.m.Y H:i', intval($row['tstamp'])))],
            ['AG-Mail', d2_h($row['ag_mail'] ?: 'Keine Mail hinterlegt')],
            ['Beschreibung', nl2br(d2_h($row['beschreibung'])), true],
        ], 'd2-detail-list-two-cols') ?>

        <div class="d2-approval-row">
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
                <div class="d2-approval-box <?= d2_h($boxClass) ?>">Vorstand <?= intval($i) ?><br><?= d2_h($label) ?></div>
            <?php endfor; ?>
        </div>


        <div class="d2-button-row">
            <button type="submit" name="decision" value="accepted" class="d2-submit d2-green-submit">Genehmigen</button>
            <button type="submit" name="decision" value="declined" class="d2-submit d2-red-submit">Ablehnen</button>
        </div>
    </form>
    <?php
    return d2_modal_shell('Einkaufsantrag entscheiden', ob_get_clean());
}

function d2_handle_agessen_action(mysqli $conn, array &$terminal): void
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
    $agName = $ag_complete[$ag]['name'] ?? d2_get_ag_name($conn, $ag);
    $insert_beschreibung = 'AG-Essen ' . $agName;

    if ($id <= 0 || $insert_betrag >= 0 || $ag <= 0) {
        d2_json(['ok' => false, 'error' => 'Ungültiger AG-Essen-Antrag.'], 400);
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
        $terminal[] = "AG-Essen #{$id} bestätigt. Transfer vom Hauskonto angelegt: {$insert_beschreibung}, " . d2_money($insert_betrag) . '.';
    } else {
        $xString = substr($iban, strpos($iban, 'Bar') + strlen('Bar'));
        $kasse = intval(trim($xString));
        $insertSql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, kasse, konto, pfad, agent, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        mysqli_stmt_bind_param($stmt, 'iisdiisss', $zeit, $dummy_uid, $insert_beschreibung, $insert_betrag, $kasse, $konto, $pfad, $agent, $changelog);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $terminal[] = "AG-Essen #{$id} bestätigt. Transfer aus Barkasse {$kasse} angelegt: {$insert_beschreibung}, " . d2_money($insert_betrag) . '.';
    }

    d2_json(['ok' => true, 'message' => 'AG-Essen bestätigt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function d2_handle_erstattung_action(mysqli $conn, array &$terminal): void
{
    $reqId = intval($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $statusAgentUid = intval($_SESSION['uid'] ?? 0);

    if ($reqId <= 0 || !in_array($action, ['accept', 'decline', 'remove'], true) || $statusAgentUid <= 0) {
        d2_json(['ok' => false, 'error' => 'Ungültige Erstattungsaktion.'], 400);
    }

    if ($action === 'remove') {
        $stmt = mysqli_prepare($conn, "
            UPDATE erstattung
            SET status = -1,
                status_agent_uid = ?
            WHERE id = ?
              AND status = 0
        ");
        mysqli_stmt_bind_param($stmt, 'ii', $statusAgentUid, $reqId);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected <= 0) {
            d2_json(['ok' => false, 'error' => 'Erstattungsantrag nicht gefunden oder bereits verarbeitet.'], 404);
        }

        $terminal[] = "Erstattung #{$reqId} entfernt durch UID {$statusAgentUid}, ohne Mailversand.";
        d2_json(['ok' => true, 'message' => 'Erstattung entfernt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
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
            d2_json(['ok' => false, 'error' => 'Erstattungsantrag nicht gefunden oder bereits verarbeitet.'], 404);
        }

        $formattedEinrichtung = d2_format_einrichtung($conn, $einrichtung);

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

        $to = d2_build_user_room_email($room, $turm);
        $subject = 'Reimbursement request approved';
        $message = "Hello {$name},\n\nYour reimbursement request for " . number_format((float)$betrag, 2, ',', '.') . " EUR has been approved.\nThe amount will be transferred to your IBAN shortly.\n\nIBAN: {$iban}\nPurpose: {$formattedEinrichtung} reimbursement\n\nBest regards\nVorstand WEH e.V.";
        $mailOk = d2_send_plain_mail($to, $subject, $message, 'vorstand@weh.rwth-aachen.de');

        $terminal[] = "Erstattung #{$reqId} genehmigt durch UID {$statusAgentUid}. Transfer vom Hauskonto angelegt: " . d2_money($negBetrag) . '.';
        $terminal[] = $mailOk ? 'Mail an User versendet.' : 'Mail an User konnte nicht versendet werden.';
        d2_json(['ok' => true, 'message' => 'Erstattung genehmigt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
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
            d2_json(['ok' => false, 'error' => 'Erstattungsantrag nicht gefunden oder bereits verarbeitet.'], 404);
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

        $to = d2_build_user_room_email($room, $turm);
        $subject = 'Reimbursement request declined';
        $message = "Hello {$name},\n\nYour reimbursement request has been declined.\nPlease contact the Vorstand at vorstand@weh.rwth-aachen.de if you have any questions.\n\nBest regards\nVorstand WEH e.V.";
        $mailOk = d2_send_plain_mail($to, $subject, $message, 'vorstand@weh.rwth-aachen.de');

        $terminal[] = "Erstattung #{$reqId} abgelehnt durch UID {$statusAgentUid}.";
        $terminal[] = $mailOk ? 'Mail an User versendet.' : 'Mail an User konnte nicht versendet werden.';
        d2_json(['ok' => true, 'message' => 'Erstattung abgelehnt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }
}

function d2_handle_purchase_action(mysqli $conn, array &$terminal): void
{
    $purchaseId = intval($_POST['purchase_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $vorstandUid = intval($_SESSION['uid'] ?? 0);

    if ($purchaseId <= 0 || !in_array($decision, ['accepted', 'declined'], true) || $vorstandUid <= 0) {
        d2_json(['ok' => false, 'error' => 'Ungültige Einkaufsentscheidung.'], 400);
    }

    $request = d2_fetch_purchase_request($conn, $purchaseId);
    if (!$request) {
        d2_json(['ok' => false, 'error' => 'Einkaufsantrag nicht gefunden.'], 404);
    }

    if ((string)$request['status'] !== 'gestellt') {
        d2_json(['ok' => false, 'error' => 'Dieser Einkaufsantrag ist nicht mehr offen.'], 409);
    }

    $acceptedBefore = d2_decision_count($request, 'accepted');
    $declinedBefore = d2_decision_count($request, 'declined');

    if ($acceptedBefore >= 3 || $declinedBefore >= 3) {
        d2_json(['ok' => false, 'error' => 'Dieser Einkaufsantrag ist bereits final entschieden.'], 409);
    }

    for ($i = 1; $i <= 5; $i++) {
        if (intval($request['vorstand_uid_' . $i] ?? 0) === $vorstandUid) {
            d2_json(['ok' => false, 'error' => 'Du hast diesen Antrag bereits entschieden.'], 409);
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
        d2_json(['ok' => false, 'error' => 'Alle Vorstandsfelder sind bereits belegt.'], 409);
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

    $requestAfter = d2_fetch_purchase_request($conn, $purchaseId);
    if (!$requestAfter) {
        d2_json(['ok' => false, 'error' => 'Einkaufsantrag nach Update nicht lesbar.'], 500);
    }

    $acceptedAfter = d2_decision_count($requestAfter, 'accepted');
    $declinedAfter = d2_decision_count($requestAfter, 'declined');

    $terminal[] = "Einkaufsantrag #{$purchaseId}: Vorstand UID {$vorstandUid} hat " . ($decision === 'accepted' ? 'genehmigt' : 'abgelehnt') . '.';
    $terminal[] = "Zwischenstand: {$acceptedAfter}/3 Zusagen, {$declinedAfter}/3 Ablehnungen.";

    if ($acceptedAfter >= 3) {
        $mailTo = trim((string)($requestAfter['ag_mail'] ?? ''));
        $mailOk = false;
        if ($mailTo !== '') {
            $subject = 'Einkaufsantrag genehmigt: ' . $requestAfter['titel'];
            $message = "Hallo,\n\nder Einkaufsantrag eurer AG wurde vom Vorstand genehmigt.\n\nAntrag: " . $requestAfter['titel'] . "\nMaximalbetrag: " . d2_money((float)$requestAfter['maxbetrag']) . "\n\nDer Einkauf kann nun getätigt werden. Danach kann im Backend ein Erstattungsantrag mit Rechnung gestellt werden.\n\nDiese Nachricht wurde automatisch vom Backend versendet.\n";
            $mailOk = d2_send_plain_mail($mailTo, $subject, $message, 'vorstand@weh.rwth-aachen.de');
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
            $message = "Hallo,\n\nder Einkaufsantrag eurer AG wurde vom Vorstand abgelehnt.\n\nAntrag: " . $requestAfter['titel'] . "\nMaximalbetrag: " . d2_money((float)$requestAfter['maxbetrag']) . "\n\nBitte sprecht euch bei Rückfragen direkt mit dem Vorstand ab.\n\nDiese Nachricht wurde automatisch vom Backend versendet.\n";
            $mailOk = d2_send_plain_mail($mailTo, $subject, $message, 'vorstand@weh.rwth-aachen.de');
        }
        $terminal[] = $mailOk ? 'Dritte Ablehnung erreicht. Antrag wurde abgelehnt und Mail an AG wurde versendet.' : 'Dritte Ablehnung erreicht. Antrag wurde abgelehnt. Mail konnte nicht versendet werden oder groups.mail ist leer.';
    }

    d2_json(['ok' => true, 'message' => 'Einkaufsantrag aktualisiert.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
}

function d2_collect_dashboard_data(mysqli $conn): array
{
    global $config;

    $nowTs = time();

    $stmt = mysqli_prepare($conn, "SELECT id, room, turm FROM registration WHERE status = 0 ORDER BY room ASC");
    mysqli_stmt_execute($stmt);
    $anmeldungen = d2_stmt_rows($stmt);
    $stmt->close();

    $stmt = mysqli_prepare($conn, "SELECT id, name, betrag, netzkonto FROM unknowntransfers WHERE status = 0 ORDER BY id ASC");
    mysqli_stmt_execute($stmt);
    $kontowecker = d2_stmt_rows($stmt);
    $stmt->close();

    $stmt = mysqli_prepare($conn, "SELECT pskonly.id, users.room, users.turm FROM pskonly JOIN users ON pskonly.uid = users.uid WHERE pskonly.status = 0 ORDER BY users.room ASC");
    mysqli_stmt_execute($stmt);
    $pskonly = d2_stmt_rows($stmt);
    $stmt->close();

    $stmt = mysqli_prepare($conn, "SELECT a.id, u.room, u.oldroom, u.turm FROM abmeldungen a JOIN users u ON a.uid = u.uid WHERE a.status = 1 ORDER BY a.id ASC");
    mysqli_stmt_execute($stmt);
    $abm = d2_stmt_rows($stmt);
    $stmt->close();

    $balances = [
        72 => d2_balance($conn, 72),
        69 => d2_balance($conn, 69),
        92 => d2_balance($conn, 92),
        1  => d2_balance($conn, 1),
        2  => d2_balance($conn, 2),
        93 => d2_balance($conn, 93),
        94 => d2_balance($conn, 94),
        95 => d2_balance($conn, 95),
    ];

    $hausKapital = $balances[92] + $balances[93] + $balances[94] + $balances[95];
    $netzKapital = $balances[72] + $balances[69] + $balances[1] + $balances[2];
    $gesamtKapital = $hausKapital + $netzKapital;
    $bilanz = d2_current_year_balance($conn);
    $members = d2_member_count($conn);

    $cn = null;
    $endtime = null;
    $alert = null;
    $stmt = mysqli_prepare($conn, "SELECT cn, endtime, alert FROM certs WHERE alert >= 0 ORDER BY endtime LIMIT 1");
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cn, $endtime, $alert);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ($endtime && (int)$alert > 0) {
        $certValue = date('d.m.Y', (int)$endtime);
        $certDetail = (string)$cn;
        $certState = 'bad';
        $certCompact = true;
    } elseif ($endtime) {
        $certValue = '-';
        $certDetail = '';
        $certState = 'good';
        $certCompact = false;
    } else {
        $certValue = 'Keine Daten';
        $certDetail = 'Kein Zertifikat mit alert >= 0 gefunden';
        $certState = 'bad';
        $certCompact = false;
    }

    $downhosts_list = [];
    $nagiosError = '';
    $nagiosconfig = $config['nagios'] ?? null;
    if (is_array($nagiosconfig) && !empty($nagiosconfig['host'])) {
        $nagiosURL = $nagiosconfig['host'] . 'cgi-bin/statusjson.cgi?query=hostlist&details=true';
        $nagioscontext = stream_context_create([
            'http' => [
                'header' => 'Authorization: Basic ' . base64_encode($nagiosconfig['user'] . ':' . $nagiosconfig['password']),
                'timeout' => 8,
            ],
        ]);
        $response = @file_get_contents($nagiosURL, false, $nagioscontext);
        $data = $response ? json_decode($response, true) : null;

        if (isset($data['data']['hostlist']) && is_array($data['data']['hostlist'])) {
            foreach ($data['data']['hostlist'] as $host => $info) {
                if (isset($info['status']) && (int)$info['status'] === 4 && (int)($info['scheduled_downtime_depth'] ?? 0) === 0) {
                    $downhosts_list[] = $host;
                }
            }
        } else {
            $nagiosError = 'Nagios nicht erreichbar oder JSON ungültig';
        }
    } else {
        $nagiosError = 'Nagios-Konfiguration fehlt';
    }

    $stmt = mysqli_prepare($conn, "
        SELECT a.id, a.tstamp, a.betrag, a.iban, a.ag, g.name AS ag_name
        FROM agessen a
        LEFT JOIN `groups` g ON g.id = a.ag
        WHERE a.status = 0
        ORDER BY a.tstamp DESC, a.id DESC
    ");
    mysqli_stmt_execute($stmt);
    $agessen = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "
        SELECT e.id, e.betrag, e.einrichtung, e.tstamp, u.name
        FROM erstattung e
        LEFT JOIN users u ON u.uid = e.uid
        WHERE e.status = 0
        ORDER BY e.tstamp ASC, e.id ASC
    ");
    mysqli_stmt_execute($stmt);
    $erstattungen = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $purchaseOpenCondition = d2_purchase_open_sql_condition('ea');
    $currentVorstandUid = intval($_SESSION['uid'] ?? 0);
    if ($currentVorstandUid <= 0) {
        $currentVorstandUid = -1;
    }
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
    $einkaeufe = d2_stmt_rows($stmt);
    mysqli_stmt_close($stmt);

    $queueMap = [
        'Anmeldung' => [
            'title' => 'Anmeldungen',
            'type' => 'Anmeldung',
            'items' => array_map(function ($entry) {
                return [
                    'id' => (int)$entry['id'],
                    'label' => str_pad((string)$entry['room'], 4, '0', STR_PAD_LEFT),
                    'sub' => strtolower((string)$entry['turm']) === 'tvk' ? 'TvK' : strtoupper((string)$entry['turm']),
                    'tower' => (string)$entry['turm'],
                ];
            }, $anmeldungen),
        ],
        'Abmeldung' => [
            'title' => 'Abmeldungen',
            'type' => 'Abmeldung',
            'items' => array_map(function ($entry) {
                $roomNum = ((int)$entry['room'] === 0 && !empty($entry['oldroom'])) ? (string)$entry['oldroom'] : (string)$entry['room'];
                return [
                    'id' => (int)$entry['id'],
                    'label' => str_pad($roomNum, 4, '0', STR_PAD_LEFT),
                    'sub' => strtolower((string)$entry['turm']) === 'tvk' ? 'TvK' : strtoupper((string)$entry['turm']),
                    'tower' => (string)$entry['turm'],
                ];
            }, $abm),
        ],
        'UnknownTransfers' => [
            'title' => 'Transfers',
            'type' => 'UnknownTransfers',
            'items' => array_map(function ($entry) {
                return [
                    'id' => (int)$entry['id'],
                    'label' => d2_money((float)$entry['betrag']),
                    'sub' => ((int)($entry['netzkonto'] ?? 0) === 1 ? 'Netzkonto' : 'Hauskonto') . ' · ' . (string)$entry['name'],
                    'tower' => 'weh',
                ];
            }, $kontowecker),
        ],
        'PSK' => [
            'title' => 'PSK',
            'type' => 'PSK',
            'items' => array_map(function ($entry) {
                return [
                    'id' => (int)$entry['id'],
                    'label' => str_pad((string)$entry['room'], 4, '0', STR_PAD_LEFT),
                    'sub' => strtolower((string)$entry['turm']) === 'tvk' ? 'TvK' : strtoupper((string)$entry['turm']),
                    'tower' => (string)$entry['turm'],
                ];
            }, $pskonly),
        ],
        'AGEssen' => ['title' => 'AG-Essen', 'type' => 'AGEssen', 'items' => array_map(static function ($entry) {
            return ['id' => intval($entry['id']), 'label' => d2_money((float)$entry['betrag']), 'sub' => (string)($entry['ag_name'] ?? ('AG #' . $entry['ag'])), 'tower' => 'weh'];
        }, $agessen)],
        'Erstattung' => ['title' => 'Erstattung', 'type' => 'Erstattung', 'items' => array_map(static function ($entry) use ($conn) {
            return ['id' => intval($entry['id']), 'label' => d2_money((float)$entry['betrag']), 'sub' => d2_format_einrichtung($conn, (string)$entry['einrichtung']), 'tower' => 'weh'];
        }, $erstattungen)],
    ];

    return [
        'generatedAt' => date(DateTime::ATOM, $nowTs),
        'cards' => [
            'gesamtgeld' => ['title' => 'Gesamt-Kapital', 'state' => $gesamtKapital >= 0 ? 'good' : 'bad', 'value' => d2_money($gesamtKapital), 'detail' => '', 'meta' => ''],
            'netzkapital' => ['title' => 'Netz-Kapital', 'state' => $netzKapital >= 0 ? 'good' : 'bad', 'value' => d2_money($netzKapital), 'detail' => '', 'meta' => ''],
            'hauskapital' => ['title' => 'Haus-Kapital', 'state' => $hausKapital >= 0 ? 'good' : 'bad', 'value' => d2_money($hausKapital), 'detail' => '', 'meta' => ''],
            'bilanz' => ['title' => 'Bilanz in ' . date('Y'), 'state' => $bilanz >= 0 ? 'good' : 'bad', 'value' => ($bilanz > 0 ? '+' : '') . d2_money($bilanz), 'detail' => '', 'meta' => ''],
            'netzkonto' => ['title' => 'Netzkonto', 'state' => $balances[72] >= 0 ? 'good' : 'bad', 'value' => d2_money($balances[72]), 'detail' => '', 'meta' => ''],
            'hauskonto' => ['title' => 'Hauskonto', 'state' => $balances[92] >= 0 ? 'good' : 'bad', 'value' => d2_money($balances[92]), 'detail' => '', 'meta' => ''],
            'certs' => ['title' => 'Auslaufende Zertifikate', 'state' => $certState, 'value' => $certValue, 'detail' => $certDetail, 'meta' => 'Nächstes Ablaufdatum', 'compact' => $certCompact],
            'nagios' => ['title' => 'Host-Ausfälle', 'state' => ($nagiosError !== '' || count($downhosts_list) > 0) ? 'bad' : 'good', 'value' => $nagiosError !== '' ? 'Fehler' : (count($downhosts_list) > 0 ? count($downhosts_list) . ' offline' : '-'), 'detail' => $nagiosError !== '' ? $nagiosError : (count($downhosts_list) > 0 ? implode(', ', $downhosts_list) : ''), 'meta' => '', 'hosts' => $downhosts_list, 'compact' => count($downhosts_list) > 0],
            'mitglieder' => ['title' => 'Anzahl Vereinsmitglieder', 'state' => 'good', 'value' => (string)$members, 'detail' => '', 'meta' => ''],
        ],
        'queues' => $queueMap,
        'crons' => d2_collect_crons(),
    ];
}

function d2_search_users(mysqli $conn): void
{
    $searchTerm = trim((string)($_GET['search'] ?? ''));
    if ($searchTerm === '') {
        d2_json([]);
    }

    $like = '%' . $searchTerm . '%';
    $searchedusers = [];

    if (ctype_digit($searchTerm)) {
        $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE
                (pid in (11,64) AND (name LIKE ? OR room = ? OR oldroom = ?))
                ORDER BY FIELD(turm, 'weh', 'tvk'), room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $like, $searchTerm, $searchTerm);
    } else {
        $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE
                (pid in (11,64) AND name LIKE ?)
                ORDER BY FIELD(turm, 'weh', 'tvk'), room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $like);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm, $groups);
    while (mysqli_stmt_fetch($stmt)) {
        $searchedusers[$uid][] = [
            'uid' => $uid,
            'name' => $name,
            'username' => $username,
            'room' => $room,
            'oldroom' => $oldroom,
            'turm' => $turm,
            'groups' => $groups,
        ];
    }
    mysqli_stmt_close($stmt);

    d2_json($searchedusers);
}

function d2_modal_shell(string $title, string $body, string $class = ''): string
{
    $modalClass = trim('d2-modal ' . $class);
    return '<div class="d2-modal-backdrop" data-d2-close="1"></div>
        <div class="' . d2_h($modalClass) . '">
            <div class="d2-modal-head">
                <div class="d2-modal-title">' . d2_h($title) . '</div>
                <button type="button" class="d2-modal-close" data-d2-close="1">×</button>
            </div>
            <div class="d2-modal-body">' . $body . '</div>
        </div>';
}

function d2_modal_registration(mysqli $conn, int $id): string
{
    $zeit = time();
    $stmt = mysqli_prepare($conn, "SELECT * FROM registration WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = d2_stmt_rows($stmt);
    $user = array_shift($result);
    $stmt->close();

    if (!$user) {
        return d2_modal_shell('Anmeldung', '<p class="d2-modal-error">Anmeldung nicht gefunden.</p>');
    }

    $stmt = mysqli_prepare($conn, "SELECT lastradius, username, name, uid FROM users WHERE room = ? and turm = ?");
    mysqli_stmt_bind_param($stmt, 'is', $user['room'], $user['turm']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $lastradius, $aktuellerbewohner_username, $aktuellerbewohner_name, $aktuellerbewohner_uid);
    $raumbelegt = false;
    if (mysqli_stmt_fetch($stmt)) {
        $raumbelegt = true;
    }
    mysqli_stmt_close($stmt);

    $lastradiusstring = '';
    $lastradiuscolor = 'white';
    if ($raumbelegt) {
        if ((int)$lastradius === 0) {
            $lastradiusstring = 'Noch nie!';
            $lastradiuscolor = 'red';
        } else {
            $abstand_in_sekunden = $zeit - (int)$lastradius + 3600;
            $abstand_tage = floor($abstand_in_sekunden / (24 * 60 * 60));
            $abstand_stunden = floor(($abstand_in_sekunden % (24 * 60 * 60)) / 3600);
            if ($abstand_tage == 0 && $abstand_stunden == 0) {
                $lastradiusstring = 'Verbunden';
                $lastradiuscolor = 'red';
            } elseif ($abstand_tage > 0) {
                $lastradiusstring = "$abstand_tage Tage, $abstand_stunden Stunden";
                $lastradiuscolor = 'yellow';
            } elseif ($abstand_stunden == 1) {
                $lastradiusstring = "$abstand_stunden Stunde";
                $lastradiuscolor = 'red';
            } else {
                $lastradiusstring = "$abstand_stunden Stunden";
                $lastradiuscolor = 'red';
            }
        }
    }

    $uploadDir = 'anmeldung/';
    $userId = (int)$user['id'];
    $documentLabels = [
        'id' => 'Ausweis',
        'mv' => 'Mietvertrag',
        'af' => 'Anmeldung',
    ];
    $documents = [];
    if (is_dir($uploadDir)) {
        $files = array_diff(scandir($uploadDir), ['.', '..']);
        foreach ($files as $file) {
            if (preg_match("/^{$userId}_(id|mv|af)\.(.+)$/", $file, $matches)) {
                $type = $matches[1];
                $extension = strtolower($matches[2]);
                $documents[$type] = [
                    'label' => $documentLabels[$type],
                    'path' => $uploadDir . $file,
                    'extension' => $extension,
                ];
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
    <form method="post" class="d2-action-form d2-registration-form">
        <input type="hidden" name="id" value="<?= d2_h($id) ?>">
        <input type="hidden" name="reload" value="1">
        <input type="hidden" name="username" value="<?= d2_h($user['username']) ?>">

        <?php if (!empty($user['sublet'])): ?>
            <div class="d2-alert d2-alert-bad">SUBLET · Ende: <?= d2_h(date('d.m.Y', (int)$user['subletterend'])) ?></div>
        <?php endif; ?>

        <?php if ($raumbelegt): ?>
            <div class="d2-alert d2-alert-bad">
                Ein alter User verliert seine Verbindung, wenn dieser User angenommen wird.<br><br>
                Raum: <?= d2_h($user['room']) ?><br>
                Name: <?= d2_h($aktuellerbewohner_name) ?><br>
                Username: <?= d2_h($aktuellerbewohner_username) ?><br>
                UID: <?= d2_h($aktuellerbewohner_uid) ?><br>
                <span style="color:<?= d2_h($lastradiuscolor) ?>">Letzter Radius Auth: <?= d2_h($lastradiusstring) ?></span>
            </div>
        <?php endif; ?>

        <div class="d2-registration-overview">
            <div class="d2-registration-docs">
                <div class="d2-document-tabs">
                    <?php foreach ($documentLabels as $type => $label): ?>
                        <button type="button" class="d2-doc-tab<?= $type === $firstDocument ? ' d2-active' : '' ?>" data-d2-doc-tab="<?= d2_h($type) ?>" <?= isset($documents[$type]) ? '' : 'disabled' ?>>
                            <?= d2_h($label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="d2-registration-document-stage">
                    <?php if (!is_dir($uploadDir)): ?>
                        <p class="d2-modal-error">Das Verzeichnis <?= d2_h($uploadDir) ?> existiert nicht.</p>
                    <?php elseif (empty($documents)): ?>
                        <p class="d2-muted">Keine Dateien für User-ID <?= d2_h($userId) ?> gefunden.</p>
                    <?php else: ?>
                        <?php foreach ($documents as $type => $document): ?>
                            <div class="d2-document-frame d2-registration-doc<?= $type === $firstDocument ? ' d2-active' : '' ?>" data-d2-doc-panel="<?= d2_h($type) ?>">
                                <?php if (in_array($document['extension'], ['jpg', 'jpeg', 'png', 'gif'], true)): ?>
                                    <img src="<?= d2_h($document['path']) ?>" alt="<?= d2_h($document['label']) ?>">
                                <?php elseif ($document['extension'] === 'pdf'): ?>
                                    <embed src="<?= d2_h($document['path']) ?>#zoom=page-width" type="application/pdf">
                                <?php else: ?>
                                    <a href="<?= d2_h($document['path']) ?>" target="_blank">Datei öffnen</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d2-registration-data">
                <div class="d2-document-tabs">
                    <button type="button" class="d2-doc-tab d2-active" disabled>Daten</button>
                </div>
                <div class="d2-info-grid d2-registration-info">
                <div class="d2-info-item"><span>Name</span><strong><?= d2_h($user['firstname'] . ' ' . $user['lastname']) ?></strong></div>
                <div class="d2-info-item"><span>Einzug</span><strong class="<?= ((int)$user['starttime'] > $zeit) ? 'd2-input-bad' : '' ?>"><?= d2_h(date('d.m.Y', (int)$user['starttime'])) ?></strong></div>
                <div class="d2-info-item"><span>Turm</span><strong><?= d2_h(formatTurm($user['turm'])) ?></strong></div>
                <div class="d2-info-item d2-editable-info-item">
                    <span>Zimmer für Accept</span>
                    <input
                        type="text"
                        name="room_override"
                        class="d2-room-override"
                        value="<?= d2_h($user['room']) ?>"
                        inputmode="numeric"
                        pattern="[0-9]{1,4}"
                        maxlength="4"
                        autocomplete="off"
                        title="Dieser Raum wird bei Accept für Subnetz, roomcheck und users.room verwendet."
                    >
                    <small class="d2-room-override-note">Falls User hier Fehler macht, können wir das vor der Anmeldung noch korrigieren.</small>
                </div>
                <div class="d2-info-item"><span>Herkunftsland</span><strong><?= d2_h($user['geburtsort']) ?></strong></div>
                <div class="d2-info-item"><span>Geburtstag</span><strong><?= d2_h(date('d.m.Y', (int)$user['geburtstag'])) ?></strong></div>
                <div class="d2-info-item"><span>Username</span><strong><?= d2_h($user['username']) ?></strong></div>
                <div class="d2-info-item"><span>E-Mail</span><strong><?= d2_h($user['email']) ?></strong></div>
                <div class="d2-info-item"><span>Telefon</span><strong><?= d2_h($user['telefon']) ?></strong></div>
                </div>
            </div>
        </div>

        <?php if ((int)$user['starttime'] > $zeit && $raumbelegt): ?>
            <div class="d2-alert d2-alert-bad d2-registration-warning">Einzugsdatum noch nicht erreicht.</div>
        <?php endif; ?>

        <div class="d2-radio-row">
            <label><input type="radio" name="decision" value="accept"> <span class="d2-green">ACCEPT</span></label>
            <label><input type="radio" name="decision" value="decline"> <span class="d2-red">DECLINE</span></label>
            <label><input type="radio" name="decision" value="remove"> <span class="d2-warn">REMOVE</span></label>
        </div>

        <label class="d2-full-label d2-decline-reason">Grund für Ablehnung
            <input type="text" name="kommentar" placeholder="Falsches Dokument / Falscher Raum ..." disabled>
        </label>

        <div class="d2-hint d2-registration-action-hint">
            Bei Accept wird der User angelegt und per Mail mit seinen Zugangsdaten informiert.<br>
            Bei Decline wird der User per Mail über die Ablehnung informiert.<br>
            Remove ist für doppelte Anmeldungen und löscht die Anmeldung ohne Mail an den User.
        </div>

        <button type="submit" class="d2-submit">Hau raus!</button>
    </form>
    <?php
    return d2_modal_shell('Anmeldung prüfen', ob_get_clean(), 'd2-registration-modal');
}

function d2_modal_transfer(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM unknowntransfers WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = d2_stmt_rows($stmt);
    $transfer = array_shift($result);
    $stmt->close();

    if (!$transfer) {
        return d2_modal_shell('Transfer', '<p class="d2-modal-error">Transfer nicht gefunden.</p>');
    }

    ob_start();
    ?>
    <form method="post" class="d2-action-form d2-transfer-form">
        <input type="hidden" name="transfer_zuweisen_check" value="user">
        <input type="hidden" name="transfer_id" value="<?= d2_h($id) ?>">
        <input type="hidden" name="selected_uid" class="d2-selected-uid">
        <input type="hidden" name="reload" value="1">

        <div class="d2-info-grid d2-transfer-info">
            <div class="d2-info-item"><span>Name</span><strong><?= d2_h($transfer['name']) ?></strong></div>
            <div class="d2-info-item"><span>Betreff</span><strong><?= d2_h($transfer['betreff']) ?></strong></div>
            <div class="d2-info-item"><span>Konto</span><strong><?= !empty($transfer['netzkonto']) ? 'Netzkonto' : 'Hauskonto' ?></strong></div>
            <div class="d2-info-item"><span>Betrag</span><strong><?= d2_h($transfer['betrag']) ?> €</strong></div>
        </div>

        <div class="d2-button-row">
            <button type="button" class="d2-small-btn d2-select-dummy" data-uid="472" data-label="Netzwerk-AG Dummy">Netz</button>
            <button type="button" class="d2-small-btn d2-select-dummy" data-uid="492" data-label="Haussprecher Dummy">Haus</button>
        </div>

        <label class="d2-full-label">Nutzer suchen
            <input type="text" class="d2-user-search" placeholder="Name oder Zimmer..." autocomplete="off">
        </label>
        <div class="d2-search-results"></div>

        <button type="submit" class="d2-submit d2-assign-button" disabled>Zuweisen</button>
    </form>
    <?php
    return d2_modal_shell('Unklare Zahlung', ob_get_clean());
}

function d2_modal_psk(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "SELECT pskonly.*, users.turm AS pskturm, users.room, users.username, users.name FROM pskonly JOIN users ON pskonly.uid = users.uid WHERE pskonly.id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = d2_stmt_rows($stmt);
    $user = array_shift($result);
    $stmt->close();

    if (!$user) {
        return d2_modal_shell('PSK', '<p class="d2-modal-error">PSK-Anfrage nicht gefunden.</p>');
    }

    $pskturm = (string)$user['pskturm'];
    if ($pskturm === 'weh') {
        $psk_info_text = 'MAC-Filter-Regel wird automatisch in WLC eingetragen.';
        $psk_info_class = 'd2-green';
    } elseif ($pskturm === 'tvk') {
        $psk_info_text = 'tvk-pskonly hat aktuell kein MAC-Filtering.';
        $psk_info_class = 'd2-warn';
    } else {
        $psk_info_text = 'Unbekannter Turm. Anfrage bitte prüfen.';
        $psk_info_class = 'd2-red';
    }
    $psk_description = trim((string)($user['beschreibung'] ?? ''));
    $psk_mac = trim((string)($user['mac'] ?? ''));
    $psk_room = trim((string)($user['room'] ?? ''));
    $psk_username = trim((string)($user['username'] ?? ''));
    $psk_name = trim((string)($user['name'] ?? ''));

    ob_start();
    ?>
    <form method="post" class="d2-action-form d2-psk-form">
        <input type="hidden" name="id" value="<?= d2_h($id) ?>">
        <input type="hidden" name="reload" value="1">

        <div class="d2-psk-overview">
            <div class="d2-image-preview">
                <img src="<?= d2_h($user['pfad']) ?>" alt="PSK-Bild">
            </div>
            <div class="d2-info-grid">
                <div class="d2-info-item">
                    <span>Beschreibung</span>
                    <strong><?= d2_h($psk_description !== '' ? $psk_description : 'Keine Beschreibung') ?></strong>
                </div>
                <div class="d2-info-item">
                    <span>MAC-Adresse</span>
                    <strong><?= d2_h($psk_mac !== '' ? $psk_mac : 'Keine MAC') ?></strong>
                </div>
                <div class="d2-info-item">
                    <span>Nutzer</span>
                    <strong><?= d2_h($psk_name !== '' ? $psk_name : ($psk_username !== '' ? $psk_username : 'Unbekannt')) ?></strong>
                </div>
                <div class="d2-info-item">
                    <span>Zimmer / Netz</span>
                    <strong><?= d2_h(($psk_room !== '' ? $psk_room . ' · ' : '') . $pskturm . '-pskonly') ?></strong>
                </div>
            </div>
        </div>

        <div class="d2-centered-text">
            Anfrage für <strong><?= d2_h($pskturm) ?>-pskonly</strong><br>
            <span class="<?= d2_h($psk_info_class) ?>"><?= d2_h($psk_info_text) ?></span><br><br>
            Der User erhält bei Accept automatisch eine Mail mit dem Passwort für das pskonly-Netzwerk.
        </div>

        <div class="d2-radio-row">
            <label><input type="radio" name="decision" value="psk"> <span class="d2-green">Accept</span></label>
            <label><input type="radio" name="decision" value="pskdeclined"> <span class="d2-red">Abgelehnt</span></label>
        </div>

        <div class="d2-hint">
            Bei Accept erhält der User eine automatisierte Mail mit Credentials und die MAC wird in macauth eingetragen.<br>
            Bei WEH wird der WLC-Abgleich automatisch über run_script.php gestartet.<br>
            Bei TvK wird kein WLC-Abgleich gestartet, da tvk-pskonly aktuell kein MAC-Filtering hat.<br>
            Bei Decline wird nur der Status der Anfrage geändert.
        </div>

        <button type="submit" class="d2-submit">Hau raus!</button>
    </form>
    <?php
    return d2_modal_shell('PSK-Anfrage', ob_get_clean());
}

function d2_modal_abmeldung(mysqli $conn, int $id): string
{
    $stmt = mysqli_prepare($conn, "SELECT u.name, a.betrag, a.iban FROM abmeldungen a JOIN users u ON a.uid = u.uid WHERE a.id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = d2_stmt_rows($stmt);
    $user = array_shift($result);
    $stmt->close();

    if (!$user) {
        return d2_modal_shell('Abmeldung', '<p class="d2-modal-error">Abmeldung nicht gefunden.</p>');
    }

    $betrag_formatiert = number_format((float)$user['betrag'], 2, ',', '.');
    ob_start();
    ?>
    <form method="post" class="d2-action-form d2-transfer-form">
        <input type="hidden" name="abmeldung_finish" value="1">
        <input type="hidden" name="abmeldung_id" value="<?= d2_h($id) ?>">
        <input type="hidden" name="reload" value="1">

        <div class="d2-centered-text d2-modal-heading">Folgende Überweisung tätigen:</div>
        <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($user['iban']) ?>"><?= d2_h($user['iban']) ?></button>
        <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($user['name']) ?>"><?= d2_h($user['name']) ?></button>
        <button type="button" class="d2-copy-btn" data-copy="<?= d2_h($betrag_formatiert) ?>"><?= d2_h($betrag_formatiert) ?> €</button>
        <button type="button" class="d2-copy-btn" data-copy="Abmeldung WEH e.V.">Abmeldung WEH e.V.</button>

        <button type="submit" class="d2-submit">Überwiesen</button>
    </form>
    <?php
    return d2_modal_shell('Abmeldung auszahlen', ob_get_clean());
}

function d2_modal(mysqli $conn): void
{
    $type = (string)($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        d2_json(['ok' => false, 'error' => 'Ungültige ID.'], 400);
    }

    $html = match ($type) {
        'Anmeldung' => d2_modal_registration($conn, $id),
        'UnknownTransfers' => d2_modal_transfer($conn, $id),
        'PSK' => d2_modal_psk($conn, $id),
        'Abmeldung' => d2_modal_abmeldung($conn, $id),
        'AGEssen' => d2_modal_agessen($conn, $id),
        'Erstattung' => d2_modal_erstattung($conn, $id),
        'Einkauf' => d2_modal_einkauf($conn, $id),
        default => '',
    };

    if ($html === '') {
        d2_json(['ok' => false, 'error' => 'Unbekannter Modal-Typ.'], 400);
    }

    d2_json(['ok' => true, 'html' => $html]);
}


function d2_handle_csv_import(mysqli $conn): void
{
    d2_require_netzag($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        d2_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    $csvText = (string)($_POST['csv_text'] ?? '');
    if ($csvText === '') {
        d2_json(['ok' => false, 'error' => 'empty_csv'], 400);
    }

    if (function_exists('mb_detect_encoding') && !mb_detect_encoding($csvText, 'UTF-8', true)) {
        $csvText = mb_convert_encoding($csvText, 'UTF-8');
    }

    $CUTOFF_TS = mktime(0, 0, 0, 11, 9, 2025);
    $NETZ_IBAN = 'DE90390500001070334600';
    $HAUS_IBAN = 'DE37390500001070334584';

    $kasseMap = [
        $NETZ_IBAN => 72,
        $HAUS_IBAN => 92,
    ];
    $netzkontoMap = [
        $NETZ_IBAN => 1,
        $HAUS_IBAN => 0,
    ];

    $normalizeIban = static function (string $iban): string {
        return strtoupper(preg_replace('/\s+/', '', trim($iban)));
    };

    $parseAmount = static function (string $s): float {
        $s = trim($s);
        $neg = false;
        if (substr($s, -1) === '-') {
            $neg = true;
            $s = substr($s, 0, -1);
        }
        $s = str_replace(['.', ' '], '', $s);
        $s = str_replace(',', '.', $s);
        $v = (float)$s;
        return $neg ? -$v : $v;
    };

    $parseDate = static function (string $s): int {
        $s = trim($s);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return mktime(12, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $s, $m)) {
            $y = (int)$m[3];
            $y = ($y < 70) ? 2000 + $y : 1900 + $y;
            return mktime(12, 0, 0, (int)$m[2], (int)$m[1], $y);
        }
        return time();
    };

    $moneyKey = static function ($x): string {
        $v = round((float)$x, 2);
        return number_format($v, 2, '.', '');
    };

    $lines = preg_split('/\R/u', $csvText, -1, PREG_SPLIT_NO_EMPTY);
    if (!$lines || count($lines) < 2) {
        d2_json(['ok' => false, 'error' => 'no_rows'], 400);
    }

    $header = str_getcsv($lines[0], ';', '"');
    $idx = [];
    foreach ($header as $i => $h) {
        $h = trim($h, " \t\n\r\0\x0B\"");
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        $idx[$h] = $i;
    }

    $required = [
        'Auftragskonto',
        'Buchungstag',
        'Valutadatum',
        'Buchungstext',
        'Verwendungszweck',
        'Beguenstigter/Zahlungspflichtiger',
        'Kontonummer/IBAN',
        'Betrag',
    ];

    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            d2_json(['ok' => false, 'error' => 'missing_col:' . $col], 400);
        }
    }

    $existingKnown = [];
    $existingUnknown = [];

    $prefKnown = $conn->prepare("\n        SELECT iban, kasse, tstamp, betrag\n        FROM transfers\n        WHERE konto = 4\n          AND tstamp >= ?\n          AND kasse IN (69,72,92)\n    ");
    if (!$prefKnown) {
        d2_json(['ok' => false, 'error' => 'db_prepare_known_failed', 'detail' => $conn->error], 500);
    }
    $prefKnown->bind_param('i', $CUTOFF_TS);
    $prefKnown->execute();
    $prefKnown->bind_result($p_iban, $p_kasse, $p_tstamp, $p_betrag);
    while ($prefKnown->fetch()) {
        $p_iban = $normalizeIban((string)$p_iban);
        $k = $p_iban . '|' . (int)$p_kasse . '|' . (int)$p_tstamp . '|' . $moneyKey($p_betrag);
        $existingKnown[$k] = ($existingKnown[$k] ?? 0) + 1;
    }
    $prefKnown->close();

    $prefUnk = $conn->prepare("\n        SELECT iban, tstamp, betrag, COALESCE(betreff, '') AS betreff_norm\n        FROM unknowntransfers\n        WHERE tstamp >= ?\n    ");
    if (!$prefUnk) {
        d2_json(['ok' => false, 'error' => 'db_prepare_unknown_failed', 'detail' => $conn->error], 500);
    }
    $prefUnk->bind_param('i', $CUTOFF_TS);
    $prefUnk->execute();
    $prefUnk->bind_result($u_iban, $u_tstamp, $u_betrag, $u_betreff_norm);
    while ($prefUnk->fetch()) {
        $u_iban = $normalizeIban((string)$u_iban);
        $k = $u_iban . '|' . (int)$u_tstamp . '|' . $moneyKey($u_betrag) . '|' . (string)$u_betreff_norm;
        $existingUnknown[$k] = ($existingUnknown[$k] ?? 0) + 1;
    }
    $prefUnk->close();

    $insKnown = $conn->prepare(
        'INSERT INTO transfers (uid, iban, tstamp, beschreibung, konto, kasse, betrag, agent, changelog) VALUES (?, ?, ?, ?, 4, ?, ?, ?, ?)'
    );
    if (!$insKnown) {
        d2_json(['ok' => false, 'error' => 'db_prepare_insert_known_failed', 'detail' => $conn->error], 500);
    }

    $insUnknown = $conn->prepare(
        'INSERT INTO unknowntransfers (uid, tstamp, name, betreff, betrag, netzkonto, iban, agent, status) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    if (!$insUnknown) {
        d2_json(['ok' => false, 'error' => 'db_prepare_insert_unknown_failed', 'detail' => $conn->error], 500);
    }

    $execOrFail = static function (mysqli_stmt $st) use ($conn): void {
        if (!$st->execute()) {
            $err = $st->error ?: 'stmt_execute_failed';
            $conn->rollback();
            d2_json(['ok' => false, 'error' => 'db_error', 'detail' => $err], 500);
        }
    };

    $seenKnown = [];
    $seenUnknown = [];
    $agent = (int)($_SESSION['uid'] ?? 0);
    $nowStr = date('d.m.Y H:i');
    $inserted = 0;
    $skipped = 0;
    $unknown = 0;

    $conn->begin_transaction();

    for ($li = 1; $li < count($lines); $li++) {
        $row = str_getcsv($lines[$li], ';', '"');
        if (!$row || count($row) < count($header)) {
            continue;
        }

        $auftragskonto = $normalizeIban((string)($row[$idx['Auftragskonto']] ?? ''));
        $valutaStr = trim((string)($row[$idx['Valutadatum']] ?? ''));
        $buchungStr = trim((string)($row[$idx['Buchungstag']] ?? ''));
        $buchungstext = trim((string)($row[$idx['Buchungstext']] ?? ''));
        $verwendung = trim((string)($row[$idx['Verwendungszweck']] ?? ''));
        $name = trim((string)($row[$idx['Beguenstigter/Zahlungspflichtiger']] ?? ''));
        $iban = $normalizeIban((string)($row[$idx['Kontonummer/IBAN']] ?? ''));
        $betragStr = trim((string)($row[$idx['Betrag']] ?? ''));

        if (!isset($kasseMap[$auftragskonto])) {
            $skipped++;
            continue;
        }

        if (
            (($auftragskonto === $NETZ_IBAN && $iban === $HAUS_IBAN) || ($auftragskonto === $HAUS_IBAN && $iban === $NETZ_IBAN))
            && stripos($verwendung, 'kassenausgleich') !== false
        ) {
            $skipped++;
            continue;
        }

        $betrag = $parseAmount($betragStr);

        if (($auftragskonto === $NETZ_IBAN || $auftragskonto === $HAUS_IBAN) && stripos($verwendung, 'abmeldung') !== false && $betrag < 0) {
            $skipped++;
            continue;
        }

        $isInterAccount = ($auftragskonto === $NETZ_IBAN && $iban === $HAUS_IBAN) || ($auftragskonto === $HAUS_IBAN && $iban === $NETZ_IBAN);
        if ($isInterAccount && abs($betrag) > 1000) {
            $skipped++;
            continue;
        }

        $isEntgeltabschluss = (stripos($buchungstext, 'ENTGELTABSCHLUSS') !== false);
        $isPaypalTransfer = ($auftragskonto === $NETZ_IBAN && stripos($name, 'paypal europe') !== false);

        if ($betrag <= 0.0 && !$isEntgeltabschluss) {
            $skipped++;
            continue;
        }

        $tstamp = $parseDate($valutaStr !== '' ? $valutaStr : $buchungStr);
        if ($tstamp < $CUTOFF_TS) {
            $skipped++;
            continue;
        }

        $kasse = $kasseMap[$auftragskonto];
        $netzkonto = $netzkontoMap[$auftragskonto];
        $uid = null;
        $beschreibung = 'Transfer';

        if ($isEntgeltabschluss) {
            $beschreibung = 'Entgeltabschluss';
            if ($auftragskonto === $NETZ_IBAN) {
                $uid = 472;
            } elseif ($auftragskonto === $HAUS_IBAN) {
                $uid = 492;
            }
        } elseif ($isPaypalTransfer) {
            $uid = 472;
            $beschreibung = 'Kassenausgleich PayPal';
        }

        if ($uid === null && preg_match('/W\s*(\d{1,6})\s*H(?!\d)/iu', $verwendung, $m)) {
            $uid = (int)$m[1];
        }

        if ($uid !== null) {
            $betrag2 = round($betrag, 2);
            $keyKnown = $iban . '|' . $kasse . '|' . $tstamp . '|' . $moneyKey($betrag2);
            $seenKnown[$keyKnown] = ($seenKnown[$keyKnown] ?? 0) + 1;
            $knownOccurrenceNo = $seenKnown[$keyKnown];

            if (($existingKnown[$keyKnown] ?? 0) >= $knownOccurrenceNo) {
                $skipped++;
                continue;
            }

            $changelog = "[{$nowStr}] CSV-Import durch Agent {$agent}\nQuelle: Sparkasse CSV via Dashboard-Webmaster";
            $insKnown->bind_param('isisidis', $uid, $iban, $tstamp, $beschreibung, $kasse, $betrag2, $agent, $changelog);
            $execOrFail($insKnown);
            $inserted++;

            if ($isPaypalTransfer) {
                $kassePaypal = 69;
                $betragPaypal = round(-$betrag2, 2);
                $keyPaypal = $iban . '|' . $kassePaypal . '|' . $tstamp . '|' . $moneyKey($betragPaypal);
                $seenKnown[$keyPaypal] = ($seenKnown[$keyPaypal] ?? 0) + 1;
                $paypalOccurrenceNo = $seenKnown[$keyPaypal];

                if (($existingKnown[$keyPaypal] ?? 0) < $paypalOccurrenceNo) {
                    $insKnown->bind_param('isisidis', $uid, $iban, $tstamp, $beschreibung, $kassePaypal, $betragPaypal, $agent, $changelog);
                    $execOrFail($insKnown);
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        } else {
            $betrag2 = round($betrag, 2);
            $betreffNorm = $verwendung;
            $keyUnk = $iban . '|' . $tstamp . '|' . $moneyKey($betrag2) . '|' . $betreffNorm;
            $seenUnknown[$keyUnk] = ($seenUnknown[$keyUnk] ?? 0) + 1;
            $unknownOccurrenceNo = $seenUnknown[$keyUnk];

            if (($existingUnknown[$keyUnk] ?? 0) >= $unknownOccurrenceNo) {
                $skipped++;
                continue;
            }

            $insUnknown->bind_param('issdisi', $tstamp, $name, $verwendung, $betrag2, $netzkonto, $iban, $agent);
            $execOrFail($insUnknown);
            $unknown++;
        }
    }

    $conn->commit();
    $insKnown->close();
    $insUnknown->close();

    d2_json([
        'ok' => true,
        'inserted' => $inserted,
        'unknown' => $unknown,
        'skipped' => $skipped,
        'data' => d2_collect_dashboard_data($conn),
        'terminal' => "CSV-Import abgeschlossen. {$inserted} Transfers eingetragen, {$unknown} unbekannt, {$skipped} übersprungen."
    ]);
}

function d2_handle_action(mysqli $conn): void
{
    global $mailconfig;

    $terminal = [];
    $pskabgleich_autorun = false;
    $pskabgleich_autorun_message = '';
    $psk_confirmation_done = false;
    $psk_mail_status_message = '';
    $psk_mail_status_color = 'green';

    if (isset($_POST['transfer_zuweisen_check'])) {
        $uid = intval($_POST['selected_uid'] ?? 0);
        $transfer_id = intval($_POST['transfer_id'] ?? 0);

        if ($uid <= 0 || $transfer_id <= 0) {
            d2_json(['ok' => false, 'error' => 'Transfer oder Nutzer fehlt.'], 400);
        }

        $stmt = mysqli_prepare($conn, "SELECT betrag, netzkonto, betreff FROM unknowntransfers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $transfer_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $betrag, $netzkonto, $betreff);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $betrag = floatval(str_replace(',', '.', (string)$betrag));
        $konto = 4;
        $kasse = ((int)$netzkonto === 1) ? 72 : 92;
        $beschreibung = 'Transfer';
        $zeit = time();
        $zeit_formatiert = date('d.m.Y H:i');
        $changelog = '[' . $zeit_formatiert . "] Insert durch manuelle Zuordnung des Transfers\n";
        $agent = (int)($_SESSION['uid'] ?? 0);

        $confirm_sql = "UPDATE unknowntransfers SET status = 1, uid = ?, agent = ? WHERE id = ?";
        $stmt_confirm = mysqli_prepare($conn, $confirm_sql);
        mysqli_stmt_bind_param($stmt_confirm, 'iii', $uid, $agent, $transfer_id);
        mysqli_stmt_execute($stmt_confirm);
        mysqli_stmt_close($stmt_confirm);

        $insert_sql = "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, changelog) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($stmt_insert, 'iisiids', $uid, $zeit, $beschreibung, $konto, $kasse, $betrag, $changelog);
        mysqli_stmt_execute($stmt_insert);
        mysqli_stmt_close($stmt_insert);

        $terminal[] = "Transfer #{$transfer_id} wurde UID {$uid} zugewiesen und in transfers eingetragen.";
        d2_json(['ok' => true, 'message' => 'Transfer zugewiesen.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if (isset($_POST['abmeldung_finish'])) {
        $abmeldung_id = intval($_POST['abmeldung_id'] ?? 0);
        $confirm_sql = "UPDATE abmeldungen SET status = 2 WHERE id = ?";
        $stmt_confirm = mysqli_prepare($conn, $confirm_sql);
        mysqli_stmt_bind_param($stmt_confirm, 'i', $abmeldung_id);
        mysqli_stmt_execute($stmt_confirm);
        mysqli_stmt_close($stmt_confirm);

        $terminal[] = "Abmeldung #{$abmeldung_id} wurde auf status=2 gesetzt.";
        d2_json(['ok' => true, 'message' => 'Abmeldung abgeschlossen.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if (isset($_POST['agessen_confirm'])) {
        d2_handle_agessen_action($conn, $terminal);
    }

    if (isset($_POST['erstattung_action'])) {
        d2_handle_erstattung_action($conn, $terminal);
    }

    if (isset($_POST['purchase_decision'])) {
        d2_handle_purchase_action($conn, $terminal);
    }

    if (!isset($_POST['decision'], $_POST['id'])) {
        d2_json(['ok' => false, 'error' => 'Keine Aktion übergeben.'], 400);
    }

    $decision = (string)$_POST['decision'];
    $id = intval($_POST['id']);
    $address = $mailconfig['address'] ?? '';

    if ($decision === 'accept') {
        $kommentar = (string)($_POST['kommentar'] ?? '');
        $username = (string)($_POST['username'] ?? '');
        $agent = (string)($_SESSION['username'] ?? '');

        $restrictedNames = [
            'netag', 'waschen', 'sprecher', 'community', 'important', 'essential', 'kasse', 'werkzeug', 'ags', 'hausmeister',
            'mailer-daemon', 'nobody', 'news', 'daemon', 'security', 'root', 'clamav', 'mail', 'postmaster', 'hostmaster',
            'virusalert', 'www', 'www2', 'www-data', 'www2-data', 'dns', 'ftp', 'usenet', 'noc', 'abuse', 'syslog', 'nagios',
            'domain', 'drucker', 'spam', 'ftp-admin', 'kontowecker', 'info', 'netz-ag', 'netzag', 'netz', 'netzwerk-ag',
            'netzwerkag', 'netzwerk', 'buchungssystem', 'cloud', 'no-reply', 'noreply', 'wlan', 'ipv6', 'cacti', 'graph',
            'system', 'verwaltung', 'kamera', 'lernraum', 'net', 'haussprecher', 'vorstand', 'pappnasen', 'wag', 'werkzeuge',
            'werkzeugbuchung', 'spuelen', 'wasch'
        ];

        $roomMailLocalParts = [];
        for ($etage = 0; $etage <= 17; $etage++) {
            for ($zimmer = 1; $zimmer <= 16; $zimmer++) {
                $roomMailLocalParts[] = 'z' . str_pad((string)$etage, 2, '0', STR_PAD_LEFT) . str_pad((string)$zimmer, 2, '0', STR_PAD_LEFT);
            }
            $roomMailLocalParts[] = 'etage' . str_pad((string)$etage, 2, '0', STR_PAD_LEFT);
        }
        $restrictedNames = array_merge($restrictedNames, $roomMailLocalParts);

        $uniqueUsername = false;
        while (!$uniqueUsername) {
            $sql = "SELECT 1 FROM users WHERE username = ? OR (FIND_IN_SET(?, aliase) > 0 AND mailisactive = 1)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'ss', $username, $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                mysqli_stmt_close($stmt);
                $username .= '0';
                continue;
            }
            mysqli_stmt_close($stmt);

            $sql = "SELECT 1 FROM groups WHERE FIND_IN_SET(?, aliase) > 0";
            $stmt = mysqli_prepare($conn, $sql);
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

        $sql = "SELECT room, firstname, lastname, starttime, geburtsort, email, geburtstag, telefon, forwardemail, sublet, subletterend, turm FROM registration WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname, $starttime, $geburtsort, $email, $geburtstag, $telefon, $forwardemail, $sublet, $subletterend, $turm);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $registrationRoom = (string)$room;
        $roomOverride = trim((string)($_POST['room_override'] ?? ''));

        if ($roomOverride !== '') {
            $roomOverride = preg_replace('/\s+/', '', $roomOverride);
            if (!ctype_digit($roomOverride) || strlen($roomOverride) > 4) {
                d2_json([
                    'ok' => false,
                    'error' => 'Ungültiger Raum. Erlaubt sind 1 bis 4 Ziffern.',
                    'terminal' => 'Ungültiger Raum für Anmeldung #' . $id . ': ' . $roomOverride,
                ], 400);
            }
            $room = (int)$roomOverride;
        } else {
            $room = (int)$room;
        }

        if ($room <= 0 || $room > 9999) {
            d2_json([
                'ok' => false,
                'error' => 'Ungültiger Raum. Der Raum muss zwischen 1 und 9999 liegen.',
                'terminal' => 'Ungültiger Raum für Anmeldung #' . $id . ': ' . $room,
            ], 400);
        }

        $name = $firstname . ' ' . $lastname;
        $groups = 1;
        $subtenanttill = ($subletterend === null) ? 0 : $subletterend;
        $historieAgent = $_SESSION['agent'] ?? $_SESSION['username'] ?? $agent;
        $historie = date('d.m.Y') . ' Anmeldung bestätigt (' . $historieAgent . ')';

        $subnet = getRoomSubnet($conn, $room, $turm);

        if ($subnet === false) {
            d2_json([
                'ok' => false,
                'error' => 'Kein Subnetz für Raum ' . $room . ' (' . formatTurm($turm) . ') gefunden!',
                'terminal' => 'Kein Subnetz gefunden für Turm ' . $turm . ', Raum ' . $room . '.',
            ], 500);
        }

        $pwwifi = pwgen();
        $pwhausunhashed = pwgen();
        $pwhaus = pwhash($pwhausunhashed);

        if ((string)$sublet === '0') {
            roomcheck($conn, $room, $turm);
        } elseif ((string)$sublet === '1') {
            subletcheck($conn, $room, $turm, $subletterend);
        }

        $sql = "INSERT INTO users SET username = ?, room = ?, name = ?, firstname = ?, lastname = ?, groups = ?, starttime = ?, subtenanttill = ?, geburtstag = ?, geburtsort = ?, telefon = ?, email = ?, forwardemail = ?, historie = ?, subnet = ?, pwhaus = ?, pwwifi = ?, turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssssssiiisssisssss', $username, $room, $name, $firstname, $lastname, $groups, $starttime, $subtenanttill, $geburtstag, $geburtsort, $telefon, $email, $forwardemail, $historie, $subnet, $pwhaus, $pwwifi, $turm);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $uid = mysqli_insert_id($conn);
        addPrivateIPs($conn, $uid, $subnet);

        $sql = "UPDATE registration SET status = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $uploadDir = 'anmeldung/';
        $userDir = $uploadDir . $username . '/';
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        $files = glob($uploadDir . $id . '_*');
        foreach ($files as $file) {
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
            . "Netzwerk-AG WEH e.V.";

        $to = $email;
        $subject = 'WEH - Registration';
        $headers = 'From: ' . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
        $mailOk = mail($to, $subject, $message, $headers);

        $terminal[] = "Anmeldung #{$id} akzeptiert.";
        if ((int)$registrationRoom !== (int)$room) {
            $terminal[] = "Raumkorrektur: registration.room {$registrationRoom} -> users.room {$room}.";
        }
        $terminal[] = "Neuer User: {$username}, UID {$uid}, Turm {$turm}, Raum {$room}.";
        $terminal[] = $mailOk ? 'Mail erfolgreich versendet.' : 'Fehler beim Versenden der Mail.';

        d2_json(['ok' => true, 'message' => 'Anmeldung akzeptiert.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($decision === 'decline') {
        $sql = "UPDATE registration SET status = -1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $sql = "SELECT email, firstname FROM registration WHERE id=? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $email = '';
        $firstname = '';
        if (mysqli_stmt_num_rows($stmt) == 1) {
            mysqli_stmt_bind_result($stmt, $email, $firstname);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);

        $uploadDir = 'anmeldung/';
        $files = glob($uploadDir . $id . '_*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $message = "Dear " . $firstname . ",\n\nyour registration was declined.\n\nThis is the reason:\n" . ($_POST['kommentar'] ?? '') . "\n\nBest Regards,\nNetzwerk-AG WEH e.V.";
        $to = $email;
        $subject = 'WEH - Declined Registration';
        $headers = 'From: ' . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
        $mailOk = mail($to, $subject, $message, $headers);

        $terminal[] = "Anmeldung #{$id} abgelehnt.";
        $terminal[] = $mailOk ? 'Mail erfolgreich versendet.' : 'Fehler beim Versenden der Mail.';
        d2_json(['ok' => true, 'message' => 'Anmeldung abgelehnt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($decision === 'remove') {
        $sql = "UPDATE registration SET status = -1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $uploadDir = 'anmeldung/';
        $files = glob($uploadDir . $id . '_*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $terminal[] = "Anmeldung #{$id} entfernt, ohne Mailversand.";
        d2_json(['ok' => true, 'message' => 'Anmeldung entfernt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    if ($decision === 'psk') {
        $psk_confirmation_done = true;
        $psk_id = $id;

        $sql = "UPDATE pskonly SET status = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $psk_id);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $sql = "SELECT users.username, users.firstname, pskonly.mac, users.subnet, pskonly.beschreibung, users.uid, users.turm FROM users JOIN pskonly ON users.uid = pskonly.uid WHERE pskonly.id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $psk_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $username, $firstname, $mac, $subnet, $beschreibung, $uid, $pskturm);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($pskturm === 'weh') {
            $pskabgleich_autorun = true;
            $pskabgleich_autorun_message = 'PSK-Anfrage bestätigt. pskabgleich.py wird automatisch über run_script.php gestartet.';
        } elseif ($pskturm === 'tvk') {
            $pskabgleich_autorun = false;
            $pskabgleich_autorun_message = 'PSK-Anfrage bestätigt. TvK hat aktuell kein MAC-Filtering; kein WLC-Abgleich nötig.';
        }

        $mac1 = $mac2 = $mac3 = null;
        $sql = "SELECT mac1, mac2, mac3 FROM macauth WHERE mac1 = ? OR mac2 = ? OR mac3 = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $mac, $mac, $mac);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mac1, $mac2, $mac3);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($mac1 === null && $mac2 === null && $mac3 === null) {
            $subnetWithoutEnding = substr((string)$subnet, 0, -1);
            $availableIPs = [];
            for ($i = 1; $i <= 255; $i++) {
                $availableIPs[] = $subnetWithoutEnding . $i;
            }

            $sql = "SELECT ip FROM macauth WHERE uid=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'i', $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $ip);
            $occupiedIPs = [];
            while (mysqli_stmt_fetch($stmt)) {
                $occupiedIPs[] = $ip;
            }
            $stmt->close();

            $freeIP = '';
            foreach ($availableIPs as $ip) {
                if (!in_array($ip, $occupiedIPs, true)) {
                    $freeIP = $ip;
                    break;
                }
            }

            $pos = strpos((string)$beschreibung, '-');
            if ($pos !== false) {
                $hostname = 'PSK - ' . substr((string)$beschreibung, $pos + 2);
            } else {
                $hostname = $beschreibung;
            }
            $zeit = time();

            $insert_sql = "INSERT INTO macauth (uid, tstamp, ip, mac1, hostname) VALUES (?,?,?,?,?)";
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, 'iisss', $uid, $zeit, $freeIP, $mac, $hostname);
            mysqli_stmt_execute($stmt);
            if (mysqli_error($conn)) {
                $terminal[] = 'MySQL Fehler: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
            $terminal[] = "MAC {$mac} wurde in macauth eingetragen. IP: {$freeIP}.";
        } else {
            $terminal[] = "MAC {$mac} existiert bereits in macauth; kein neuer Eintrag.";
        }

        $message = "Dear " . $firstname . ",\n\nyour device " . $mac . " was registered for " . $pskturm . "-pskonly.\n\nThe password for the network is:\nEikieJ9Yie3aTh9sHaz1\n\nBest Regards,\nNetzwerk-AG WEH e.V.";
        $to = $username . '@' . $pskturm . '.rwth-aachen.de';
        $subject = $pskturm . '-pskonly Credentials';
        $headers = 'From: ' . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";

        if (mail($to, $subject, $message, $headers)) {
            $psk_mail_status_message = 'Mail erfolgreich versendet.';
            $psk_mail_status_color = 'green';
        } else {
            $psk_mail_status_message = 'Fehler beim Versenden der Mail.';
            $psk_mail_status_color = 'red';
        }

        $terminal[] = $pskabgleich_autorun_message;
        $terminal[] = $psk_mail_status_message;

        $action_done_message = $psk_confirmation_done ? 'Wurde geaddet.' : 'Erfolgreich durchgeführt.';
        d2_json([
            'ok' => true,
            'message' => $action_done_message,
            'mailStatus' => $psk_mail_status_message,
            'mailColor' => $psk_mail_status_color,
            'autorunScript' => $pskabgleich_autorun ? 'pskabgleich' : null,
            'terminal' => implode("\n", array_filter($terminal)),
            'refresh' => true,
        ]);
    }

    if ($decision === 'pskdeclined') {
        $sql = "UPDATE pskonly SET status = -1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $terminal[] = "PSK-Anfrage #{$id} abgelehnt.";
        d2_json(['ok' => true, 'message' => 'PSK-Anfrage abgelehnt.', 'terminal' => implode("\n", $terminal), 'refresh' => true]);
    }

    d2_json(['ok' => false, 'error' => 'Unbekannte Entscheidung.'], 400);
}

if (isset($_GET['d2api'])) {
    d2_require_netzag($conn);

    switch ((string)$_GET['d2api']) {
        case 'summary':
            d2_json(['ok' => true, 'data' => d2_collect_dashboard_data($conn)]);
            break;
        case 'search':
            d2_search_users($conn);
            break;
        case 'modal':
            d2_modal($conn);
            break;
        case 'action':
            d2_handle_action($conn);
            break;
        case 'csvimport':
            d2_handle_csv_import($conn);
            break;
        default:
            d2_json(['ok' => false, 'error' => 'Unbekannter API-Endpunkt.'], 404);
    }
}

if (!auth($conn) || empty($_SESSION['Webmaster'])) {
    header('Location: denied.php');
    exit;
}

$initialData = d2_collect_dashboard_data($conn);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title>Dashboard Webmaster</title>
    <style>
        .d2-page {
            width: min(1840px, 97vw);
            height: var(--d2-page-height, calc(100vh - 170px));
            margin: 10px auto 0;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            box-sizing: border-box;
            overflow: visible;
        }
        body.d2-script-running,
        body.d2-script-running .d2-page,
        body.d2-script-running .d2-page * {
            cursor: wait !important;
        }
        .d2-layout {
            height: 100%;
            display: grid;
            grid-template-columns: minmax(760px, 1.18fr) minmax(430px, 0.82fr);
            gap: 30px;
            align-items: stretch;
            min-height: 0;
            box-sizing: border-box;
        }
        .d2-left { min-width: 0; min-height: 0; height: 100%; box-sizing: border-box; overflow: visible; }
        .d2-left-shell {
            height: 100%;
            min-height: 0;
            display: grid;
            grid-template-rows: repeat(3, minmax(0, 1fr));
            gap: 12px;
            padding: 14px;
            border-radius: 26px;
            border: 0;
            background: transparent;
            box-shadow: none;
            box-sizing: border-box;
            overflow: hidden;
        }
        .d2-section {
            position: relative;
            min-height: 0;
            padding: 12px;
            overflow: hidden;
            color: #f2fff2;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        .d2-metric-grid {
            min-height: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(180px, 1fr));
            gap: 12px;
            align-items: start;
            box-sizing: border-box;
            overflow: hidden;
        }
        .d2-card {
            border: 2px solid #11a50d;
            border-radius: 14px;
            padding: 12px;
            min-height: 0;
            height: auto;
            min-height: 105px;
            max-height: 105px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: #fff;
            background: linear-gradient(180deg, rgba(22, 54, 26, 0.97), rgba(8, 24, 10, 0.97));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18), 0 8px 18px rgba(0,0,0,0.24);
            box-sizing: border-box;
            overflow: hidden;
        }
        .d2-card.d2-state-good { border-color: #11a50d; }
        .d2-card.d2-state-bad { border-color: #c01818; background: linear-gradient(180deg, rgba(72, 24, 24, 0.97), rgba(30, 8, 8, 0.97)); }
        .d2-card.d2-state-warn { border-color: #c01818; background: linear-gradient(180deg, rgba(72, 24, 24, 0.97), rgba(30, 8, 8, 0.97)); color: #fff; }
        .d2-card.d2-card-csv-upload {
            cursor: pointer;
            transition: transform .12s ease, filter .12s ease, box-shadow .12s ease, border-color .12s ease;
        }
        .d2-card.d2-card-csv-upload:hover {
            transform: translateY(-1px);
            filter: brightness(1.08);
            border-color: #3bea37;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.24), 0 10px 22px rgba(0,0,0,0.30), 0 0 0 2px rgba(17,165,13,0.15);
        }
        .d2-card.d2-card-csv-upload.d2-card-csv-running {
            opacity: .72;
            cursor: wait;
        }
        .d2-csv-file-input {
            display: none;
        }
        .d2-card-title {
            font-size: clamp(14px, 0.92vw, 16px);
            letter-spacing: 0;
            text-transform: none;
            opacity: .94;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .d2-card-value {
            font-size: clamp(22px, 2vw, 34px);
            line-height: 1.08;
            font-weight: 700;
            word-break: break-word;
        }
        .d2-card.d2-card-compact .d2-card-value {
            font-size: clamp(17px, 1.22vw, 22px);
            line-height: 1.12;
        }
        .d2-card-detail {
            display: block;
            margin-top: 5px;
            font-size: clamp(12px, 0.9vw, 14px);
            line-height: 1.25;
            opacity: .92;
            max-height: 36px;
            overflow: hidden;
        }
        .d2-card.d2-card-compact .d2-card-detail {
            font-size: clamp(13px, 0.95vw, 15px);
            font-weight: 600;
            max-height: 38px;
        }
        .d2-card-detail:empty { display: none; }
        .d2-card-meta { display: none; }
        .d2-panel-head {
            position: relative;
            flex: 0 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .d2-panel-title {
            font-size: clamp(20px, 1.35vw, 26px);
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0;
            color: #fff;
            text-align: center;
        }
        .d2-panel-head .d2-terminal-mini {
            position: absolute;
            right: 0;
        }
        .d2-panel-sub { display: none; }
        .d2-terminal-mini {
            border: 1px solid rgba(17,165,13,0.35);
            background: #11a50d;
            color: #fff;
            border-radius: 999px;
            padding: 7px 12px;
            max-height: 34px;
            cursor: pointer;
            font-weight: 600;
        }
        .d2-queue-grid {
            flex: 0 1 auto;
            height: auto;
            min-height: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(180px, 1fr));
            grid-auto-rows: auto;
            gap: 10px;
            padding-top: 2px;
            box-sizing: border-box;
            align-items: start;
            overflow: hidden;
        }
        .d2-queue-card {
            min-height: 0;
            height: auto;
            min-height: 92px;
            max-height: 120px;
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(22, 54, 26, 0.97), rgba(8, 24, 10, 0.97));
            border: 2px solid #11a50d;
            padding: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18), 0 8px 18px rgba(0,0,0,0.24);
            box-sizing: border-box;
        }
        .d2-queue-card.d2-has-items {
            border-color: #c01818;
            background: linear-gradient(180deg, rgba(72, 24, 24, 0.97), rgba(30, 8, 8, 0.97));
        }
        .d2-queue-head { margin-bottom: 6px; }
        .d2-queue-title {
            font-size: clamp(14px, 0.92vw, 16px);
            letter-spacing: 0;
            text-transform: none;
            opacity: .94;
            font-weight: 600;
            color: #fff;
        }
        .d2-queue-empty { color: #fff; font-size: clamp(22px, 2vw, 34px); line-height: 1; font-weight: 700; }
        .d2-count-pill, .d2-empty { display: none; }
        .d2-item-list {
            display: flex;
            align-content: flex-start;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            overflow: visible;
            min-height: 0;
        }
        .d2-open-modal {
            border: 1px solid rgba(0,0,0,0.95);
            border-radius: 999px;
            padding: 8px 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            max-height: 32px;
            cursor: pointer;
            background: #11a50d;
            box-shadow: 0 5px 12px rgba(0,0,0,0.20);
            box-sizing: border-box;
            transition: transform .12s ease, filter .12s ease, box-shadow .12s ease;
        }
        .d2-open-modal:hover, .d2-script-card:hover, .d2-terminal-send:hover, .d2-terminal-mini:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }
        .d2-open-modal[data-tower="tvk"] { background: #E49B0F; color: #111; box-shadow: 0 6px 14px rgba(228,155,15,0.24); }
        .d2-script-grid {
            flex: 0 1 auto;
            height: auto;
            min-height: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(180px, 1fr));
            grid-auto-rows: auto;
            gap: 10px;
            padding-top: 2px;
            box-sizing: border-box;
            align-items: start;
            overflow: hidden;
        }
        .d2-script-card {
            width: 100%;
            min-width: 0;
            min-height: 46px;
            height: auto;
            max-height: 56px;
            border: 2px solid #11a50d;
            border-radius: 14px;
            padding: 9px 12px;
            background: linear-gradient(180deg, rgba(22, 54, 26, 0.97), rgba(8, 24, 10, 0.97));
            color: #fff;
            cursor: pointer;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18), 0 8px 18px rgba(0,0,0,0.24);
            box-sizing: border-box;
            transition: transform .12s ease, filter .12s ease, border-color .12s ease, box-shadow .12s ease;
        }
        .d2-script-card:hover { border-color: #3bea37; box-shadow: inset 0 1px 0 rgba(255,255,255,0.24), 0 10px 20px rgba(0,0,0,0.28); }
        .d2-script-card:disabled { opacity: .68; cursor: wait; }
        .d2-script-card.d2-running { border-color: #3bea37; box-shadow: inset 0 0 0 2px rgba(17,165,13,0.42), 0 10px 20px rgba(0,0,0,0.28); }
        .d2-script-title { font-size: clamp(14px, 0.92vw, 16px); font-weight: 600; color: #fff; line-height: 1.15; }
        .d2-script-run { display: none; }
        .d2-terminal-wrap {
            height: 100%;
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
        .d2-terminal-head {
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
        .d2-terminal-title { font-weight: 700; letter-spacing: 0; }
        .d2-terminal-actions { display: flex; gap: 8px; }
        .d2-terminal-screen {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
            padding: 14px;
            font: 13px/1.42 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            white-space: pre-wrap;
            color: #e8ffe8;
        }
        .d2-terminal-line { margin: 0 0 6px 0; }
        .d2-terminal-line.d2-error { color: #ff9a9a; }
        .d2-terminal-line.d2-muted-line { color: #8c8c8c; }
        .d2-terminal-input {
            flex: 0 0 auto;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 8px;
            align-items: center;
            padding: 10px;
            background: #0d0d0d;
        }
        .d2-terminal-input span { color: #73ff73; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .d2-terminal-input input {
            background: #050505;
            border: 1px solid rgba(255,255,255,0.16);
            color: white;
            border-radius: 12px;
            padding: 9px 11px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .d2-terminal-send { border: 0; border-radius: 12px; padding: 9px 12px; background: #11a50d; color: #fff; font-weight: 600; cursor: pointer; }
        .d2-modal-root:empty { display: none; }
        .d2-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 9000; }
        .d2-modal { position: fixed; z-index: 9001; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(980px, 92vw); max-height: 90vh; overflow: hidden; color: #fff; background: #171717; border: 1px solid rgba(255,255,255,0.16); border-radius: 24px; box-shadow: 0 30px 100px rgba(0,0,0,0.65); display: flex; flex-direction: column; }
        .d2-registration-modal { width: min(1220px, 94vw); max-height: 94vh; overflow: hidden; }
        .d2-modal-head { flex: 0 0 auto; position: sticky; top: 0; z-index: 2; background: #171717; border-bottom: 1px solid rgba(255,255,255,0.12); padding: 16px 18px; display: flex; justify-content: space-between; align-items: center; }
        .d2-modal-title { font-size: 22px; font-weight: 700; }
        .d2-modal-close { border: 0; background: #fff; color: #111; width: 34px; height: 34px; border-radius: 999px; cursor: pointer; font-weight: 700; }
        .d2-modal-body { flex: 1 1 auto; min-height: 0; max-height: calc(90vh - 68px); overflow: auto; padding: 18px; }
        .d2-registration-modal .d2-modal-body { max-height: calc(94vh - 68px); overflow: auto; padding: 14px 16px 16px; }
        .d2-form-wide label, .d2-full-label { color: rgba(255,255,255,0.75); font-size: 13px; font-weight: 700; display: flex; flex-direction: column; gap: 6px; }
        .d2-form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 14px 0; }
        .d2-form-grid input, .d2-full-label input, .d2-user-search { border: 1px solid rgba(255,255,255,0.16); background: rgba(0,0,0,0.25); color: white; border-radius: 12px; padding: 10px 12px; }
        .d2-input-bad { color: #ff6b6b !important; font-weight: 700; }
        .d2-alert { border-radius: 16px; padding: 12px; margin: 10px 0; }
        .d2-alert-bad { background: rgba(132,13,10,0.36); border: 1px solid rgba(255,90,90,0.35); }
        .d2-documents { display: flex; flex-direction: column; gap: 14px; margin: 14px 0; }
        .d2-document-frame { border: 1px solid rgba(255,255,255,0.16); border-radius: 18px; overflow: hidden; background: rgba(0,0,0,0.25); display: flex; justify-content: center; align-items: center; }
        .d2-document-frame img { max-width: 100%; height: auto; object-fit: contain; }
        .d2-document-frame embed { width: 100%; height: 900px; }
        .d2-registration-form { display: flex; flex-direction: column; gap: 10px; height: calc(94vh - 98px); min-height: 0; }
        .d2-registration-form .d2-alert { margin: 0; }
        .d2-registration-overview { flex: 1 1 auto; min-height: 0; display: grid; grid-template-columns: minmax(430px, 1.08fr) minmax(330px, 0.92fr); gap: 16px; align-items: stretch; }
        .d2-registration-docs { min-height: 0; display: grid; grid-template-rows: auto minmax(0, 1fr); gap: 10px; }
        .d2-registration-data { min-height: 0; display: grid; grid-template-rows: auto minmax(0, 1fr); gap: 10px; }
        .d2-document-tabs { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .d2-doc-tab { border: 1px solid rgba(255,255,255,0.16); border-radius: 999px; padding: 8px 12px; background: rgba(255,255,255,0.1); color: #fff; cursor: pointer; font-weight: 600; }
        .d2-doc-tab.d2-active { background: #11a50d; border-color: #11a50d; }
        .d2-doc-tab.d2-active:disabled { opacity: 1; color: #fff; cursor: default; }
        .d2-doc-tab:disabled { opacity: .35; cursor: not-allowed; }
        .d2-registration-document-stage { min-height: 0; display: flex; }
        .d2-registration-doc { display: none; width: 100%; height: 100%; min-height: 0; }
        .d2-registration-doc.d2-active { display: flex; }
        .d2-registration-doc img { width: 100%; height: 100%; max-height: 100%; object-fit: contain; }
        .d2-registration-doc embed { width: 100%; height: 100%; min-height: 0; }
        .d2-registration-info { min-height: 0; align-content: start; overflow: auto; padding-right: 4px; }
        .d2-registration-info .d2-info-item { padding: 9px 10px; }
        .d2-registration-info .d2-info-item span { font-size: 11px; margin-bottom: 3px; }
        .d2-registration-info .d2-info-item strong { font-size: 15px; }
        .d2-registration-info .d2-editable-info-item {
            border-color: rgba(53,210,53,0.34);
            background: rgba(17,165,13,0.10);
        }
        .d2-room-override {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid rgba(255,255,255,0.22);
            background: rgba(0,0,0,0.30);
            color: #fff;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 17px;
            font-weight: 700;
        }
        .d2-room-override:focus {
            outline: none;
            border-color: #35d235;
            box-shadow: 0 0 0 2px rgba(53,210,53,0.18);
        }
        .d2-room-override-note {
            display: block;
            margin-top: 5px;
            color: rgba(255,255,255,0.62);
            font-size: 11px;
            line-height: 1.3;
        }
        .d2-registration-warning { padding: 9px 12px; }
        .d2-registration-form .d2-full-label { gap: 5px; }
        .d2-registration-form .d2-full-label input { padding: 8px 10px; }
        .d2-registration-form .d2-radio-row { margin: 2px 0 10px; }
        .d2-decline-reason { display: none; margin-top: -4px; }
        .d2-decline-reason.d2-visible { display: flex; }
        .d2-registration-action-hint { margin-top: -4px; }
        .d2-registration-form .d2-submit { margin-top: 0; padding: 10px 18px; }
        .d2-radio-row { display: flex; justify-content: center; align-items: center; gap: 34px; margin: 18px 0; flex-wrap: wrap; }
        .d2-radio-row label { display: inline-flex; flex-direction: row; align-items: center; gap: 10px; font-size: 20px; }
        .d2-radio-row input[type="radio"] { width: 20px; height: 20px; }
        .d2-green { color: #35d235; font-weight: 700; }
        .d2-red { color: #ff5f5f; font-weight: 700; }
        .d2-warn { color: #E49B0F; font-weight: 700; }
        .d2-submit { display: block; margin: 18px auto 0; border: 0; border-radius: 16px; padding: 12px 20px; background: #fff; color: #111; font-size: 18px; font-weight: 600; cursor: pointer; min-width: 180px; }
        .d2-submit:disabled { opacity: .45; cursor: not-allowed; }
        .d2-transfer-form { max-width: 620px; margin: 0 auto; text-align: center; }
        .d2-transfer-facts { display: grid; gap: 8px; font-size: 18px; margin: 8px 0 20px; }
        .d2-transfer-info { margin: 8px 0 20px; text-align: left; }
        .d2-button-row { display: flex; justify-content: center; gap: 12px; margin: 18px 0; }
        .d2-small-btn, .d2-copy-btn { border: 0; border-radius: 14px; padding: 10px 14px; background: rgba(255,255,255,0.92); color: #111; cursor: pointer; font-weight: 600; }
        .d2-search-results { display: flex; flex-direction: column; align-items: center; gap: 8px; margin: 12px 0; }
        .d2-psk-overview { display: grid; grid-template-columns: minmax(260px, 0.9fr) minmax(280px, 1.1fr); gap: 16px; align-items: stretch; margin: 10px 0 18px; }
        .d2-image-preview { display: flex; justify-content: center; margin: 10px 0 18px; }
        .d2-psk-overview .d2-image-preview { margin: 0; align-items: center; }
        .d2-image-preview img { max-width: 420px; max-height: 420px; width: auto; height: auto; border-radius: 18px; border: 1px solid rgba(255,255,255,0.16); }
        .d2-info-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        .d2-info-item { border: 1px solid rgba(255,255,255,0.14); border-radius: 14px; background: rgba(255,255,255,0.06); padding: 11px 12px; }
        .d2-info-item span { display: block; color: rgba(255,255,255,0.62); font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
        .d2-info-item strong { display: block; color: #fff; font-size: 16px; line-height: 1.25; word-break: break-word; }
        .d2-centered-text { text-align: center; font-size: 18px; line-height: 1.45; }
        .d2-modal-heading { font-size: 26px; font-weight: 700; margin-bottom: 20px; }
        .d2-copy-btn { display: block; width: min(460px, 100%); margin: 9px auto; }
        .d2-hint, .d2-muted { color: rgba(255,255,255,0.62); font-size: 13px; line-height: 1.45; text-align: center; }
        .d2-modal-error { color: #ff8a8a; font-weight: 700; text-align: center; }
        @media (max-width: 1200px) {
            .d2-layout { grid-template-columns: minmax(650px, 1fr) minmax(380px, 0.72fr); }
            .d2-script-grid { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
        }
        .d2-page.d2-compact-left .d2-metric-grid {
            grid-template-columns: repeat(4, minmax(120px, 1fr));
        }
        .d2-page.d2-compact-left .d2-queue-grid {
            grid-template-columns: repeat(4, minmax(115px, 1fr));
        }
        .d2-page.d2-compact-left .d2-script-grid {
            grid-template-columns: repeat(3, minmax(120px, 1fr));
        }
        @media (max-width: 900px) {
            .d2-page { height: auto; overflow: visible; }
            .d2-layout { display: block; }
            .d2-left-shell { height: auto; }
            .d2-terminal-wrap { height: 520px; margin-top: 14px; }
            .d2-metric-grid, .d2-queue-grid, .d2-script-grid, .d2-form-grid { grid-template-columns: 1fr; height: auto; }
            .d2-psk-overview { grid-template-columns: 1fr; }
            .d2-registration-modal { max-height: 94vh; overflow: hidden; }
            .d2-registration-modal .d2-modal-body { max-height: calc(94vh - 68px); overflow: auto; }
            .d2-registration-form { height: auto; max-height: none; overflow: visible; }
            .d2-registration-overview { grid-template-columns: 1fr; }
            .d2-registration-document-stage { min-height: 420px; }
        }
        @media (max-width: 700px) {
            .d2-page {
                width: calc(100vw - 16px);
                height: auto;
                margin-top: 8px;
                overflow: visible;
            }
            .d2-layout {
                display: flex;
                flex-direction: column;
                gap: 14px;
                height: auto;
                min-height: 0;
                overflow: visible;
            }
            .d2-left,
            .d2-left-shell {
                width: 100%;
                height: auto;
                min-height: 0;
                overflow: visible;
            }
            .d2-left-shell {
                display: flex;
                flex-direction: column;
                gap: 12px;
                padding: 8px 0;
            }
            .d2-section {
                min-height: auto;
                overflow: visible;
            }
            .d2-terminal-wrap {
                width: 100%;
                flex: 0 0 auto;
                height: min(520px, 72vh);
                min-height: 360px;
                margin-top: 0;
            }
        }

        /* Webmaster-Kombination: mehr Blöcke, alle Vorgänge und alle Scriptbuttons. */
        .d2-page {
            height: auto;
            min-height: var(--d2-page-height, calc(100vh - 170px));
        }
        .d2-layout {
            align-items: stretch;
        }
        .d2-left,
        .d2-left-shell {
            height: auto;
            overflow: visible;
        }
        .d2-left-shell {
            display: flex;
            flex-direction: column;
            gap: 34px;
        }
        .d2-metric-grid,
        .d2-queue-grid,
        .d2-script-grid {
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            overflow: visible;
        }
        .d2-card,
        .d2-queue-card {
            max-height: none;
        }
        .d2-terminal-wrap {
            height: 100%;
            min-height: 620px;
        }
        .d2-account-info {
            border: 1px solid rgba(17,165,13,0.6);
            background: rgba(17,165,13,0.08);
            color: #dfffdc;
            border-radius: 14px;
            padding: 12px 14px;
            line-height: 1.4;
            font-weight: 700;
        }
        .d2-modal-split {
            display: grid;
            grid-template-columns: minmax(420px, 1.42fr) minmax(360px, 0.88fr);
            gap: 18px;
            align-items: start;
        }
        .d2-detail-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }
        .d2-detail-row {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            padding: 9px 10px;
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 12px;
            background: rgba(255,255,255,0.06);
        }
        .d2-detail-row span {
            color: rgba(255,255,255,0.62);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .d2-detail-row strong {
            color: #fff;
            font-size: 15px;
            line-height: 1.28;
            word-break: break-word;
        }
        .d2-detail-list-two-cols {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .d2-detail-list-two-cols .d2-detail-row {
            grid-template-columns: 1fr;
            gap: 4px;
            margin: 0;
        }
        .d2-detail-list-two-cols .d2-detail-row-wide {
            grid-column: 1 / -1;
        }
        .d2-file-stage {
            height: 620px;
            background: #0f0f0f;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .d2-file-stage img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .d2-file-stage embed {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        .d2-copy-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0 0;
        }
        .d2-copy-list .d2-copy-btn {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            width: 100%;
            text-align: left;
            border: 1px solid #11a50d;
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-radius: 12px;
            padding: 9px 11px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
        }
        .d2-copy-list .d2-copy-btn span {
            color: #a0ffa0;
            font-size: 12px;
            text-transform: uppercase;
        }
        .d2-approval-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin: 14px 0;
        }
        .d2-approval-box {
            background: #2a2a2a;
            border: 1px solid #555;
            color: #aaa;
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            font-weight: 800;
            line-height: 1.25;
        }
        .d2-approval-box.accepted {
            background: #11a50d;
            border-color: #11a50d;
            color: #000;
        }
        .d2-approval-box.declined {
            background: #c01818;
            border-color: #e05b61;
            color: #fff;
        }
        .d2-open-main,
        .d2-open-sub {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .d2-open-sub {
            font-size: 11px;
            opacity: .8;
        }
        .d2-open-modal {
            max-height: none;
            border-radius: 10px;
            text-align: left;
            padding: 7px 10px;
        }
        .d2-wide-modal {
            width: min(1320px, 94vw);
            max-height: 94vh;
        }
        .d2-wide-modal .d2-modal-body {
            max-height: calc(94vh - 68px);
        }
        .d2-toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            z-index: 10050;
            padding: 10px 16px;
            border-radius: 999px;
            background: #11a50d;
            color: #000;
            font-weight: 800;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            opacity: 0;
            pointer-events: none;
            transition: opacity .16s ease, transform .16s ease;
        }
        .d2-toast.d2-visible {
            opacity: 1;
            transform: translate(-50%, -4px);
        }
        @media (max-width: 1100px) {
            .d2-script-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
            .d2-modal-split {
                grid-template-columns: 1fr;
            }
        }

        /* Webmaster-Fix: Allgemein und offene Vorgänge desktop immer 3-spaltig; Terminalhöhe folgt linker Seite. */
        @media (min-width: 901px) {
            .d2-layout {
                height: auto !important;
                align-items: stretch !important;
            }

            .d2-left,
            .d2-left-shell {
                height: auto !important;
            }

            .d2-metric-grid,
            .d2-queue-grid,
            .d2-page.d2-compact-left .d2-metric-grid,
            .d2-page.d2-compact-left .d2-queue-grid {
                grid-template-columns: repeat(3, minmax(160px, 1fr)) !important;
            }

            .d2-terminal-wrap {
                height: var(--d2-terminal-height, auto) !important;
                min-height: 0 !important;
                align-self: stretch !important;
            }

            .d2-terminal-screen {
                min-height: 0 !important;
            }
        }
    </style>
</head>
<?php
// template.php wurde oben gepuffert geladen. Die Ausgabe wird erst jetzt bewusst an die Seite gehängt.
echo $d2_template_output;
load_menu();
?>
<div class="d2-page" id="d2Page">
    <div class="d2-layout">
        <main class="d2-left">
            <section class="d2-left-shell">
                <section class="d2-section d2-metric-section">
                    <div class="d2-panel-head">
                        <div class="d2-panel-title">Allgemein</div>
                    </div>
                    <div class="d2-metric-grid" id="d2MetricGrid"></div>
                </section>

                <section class="d2-section d2-queue-section">
                    <div class="d2-panel-head">
                        <div class="d2-panel-title">Offene Vorgänge</div>
                        <button type="button" class="d2-terminal-mini" data-d2-refresh="1">Aktualisieren</button>
                    </div>
                    <div class="d2-queue-grid" id="d2QueueGrid"></div>
                </section>

                <section class="d2-section d2-script-section">
                    <div class="d2-panel-head">
                        <div class="d2-panel-title">Skripte ausführen</div>
                    </div>
                    <div class="d2-script-grid" id="d2ScriptGrid"></div>
                </section>
            </section>
        </main>

        <aside class="d2-terminal-wrap">
            <div class="d2-terminal-head">
                <div class="d2-terminal-title">Terminal / Aktionslog</div>
                <div class="d2-terminal-actions">
                    <button type="button" class="d2-terminal-mini" data-d2-terminal-clear="1">Clear</button>
                    <button type="button" class="d2-terminal-mini" data-d2-refresh="1">Refresh</button>
                </div>
            </div>
            <div class="d2-terminal-screen" id="d2Terminal"></div>
            <form class="d2-terminal-input" id="d2TerminalForm" autocomplete="off">
                <span>webmaster$</span>
                <input id="d2TerminalCommand" type="text" placeholder="Befehl">
                <button class="d2-terminal-send" type="submit">Enter</button>
            </form>
        </aside>
    </div>
</div>
<div class="d2-modal-root" id="d2ModalRoot"></div>
<input type="file" id="d2CsvFileInput" class="d2-csv-file-input" accept=".csv,text/csv">
<div class="d2-toast" id="d2Toast">In Zwischenablage kopiert</div>

<script>
const D2 = {
  data: <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  apiBase: "<?= d2_h(basename(__FILE__)) ?>",
  running: new Set(),
  searchTimer: null
};

function d2Escape(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function d2FormatDate(value) {
  if (!value) return "unbekannt";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "unbekannt";
  return date.toLocaleString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function d2FormatSeconds(seconds) {
  if (seconds === null || seconds === undefined) return "kein Cron gefunden";
  seconds = Math.max(0, Math.floor(seconds));
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const parts = [];
  if (h > 0) parts.push(`${h}h`);
  if (m > 0 || h > 0) parts.push(`${m}m`);
  parts.push(`${s}s`);
  return parts.join(" ");
}

function d2Terminal(text, type = "normal") {
  const terminal = document.getElementById("d2Terminal");
  if (!terminal) return;
  const line = document.createElement("div");
  line.className = "d2-terminal-line" + (type === "error" ? " d2-error" : "") + (type === "muted" ? " d2-muted-line" : "");
  line.innerHTML = d2Escape(text);
  terminal.appendChild(line);
  terminal.scrollTop = terminal.scrollHeight;
}

function d2TerminalBlock(text, type = "normal") {
  String(text || "").split(/\r?\n/).forEach(line => d2Terminal(line, type));
}

async function d2ReadScriptResponse(res, type = "normal") {
  if (!res.body || !window.TextDecoder) {
    const fallbackText = await res.text();
    if (fallbackText.trim() !== "") {
      d2TerminalBlock(fallbackText, type);
    }
    return fallbackText;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let output = "";
  let pending = "";

  while (true) {
    const { value, done } = await reader.read();
    const chunk = done ? decoder.decode() : decoder.decode(value, { stream: true });
    if (chunk) {
      output += chunk;
      pending += chunk;
      const lines = pending.split(/\r?\n/);
      pending = lines.pop() || "";
      lines.forEach(line => d2Terminal(line, type));
    }
    if (done) break;
  }

  if (pending !== "") {
    d2Terminal(pending, type);
  }

  return output;
}

function d2RenderCards() {
  const grid = document.getElementById("d2MetricGrid");
  const cards = D2.data.cards || {};
  const order = ["gesamtgeld", "netzkapital", "hauskapital", "bilanz", "netzkonto", "hauskonto", "certs", "nagios", "mitglieder"];
  grid.innerHTML = order.map(key => {
    const card = cards[key] || {};
    const detail = String(card.detail || "").trim();
    const compact = card.compact ? " d2-card-compact" : "";
    const csvKasse = key === "netzkonto" ? "72" : (key === "hauskonto" ? "92" : "");
    const csvClass = csvKasse ? " d2-card-csv-upload" : "";
    const csvAttr = csvKasse ? ` data-d2-csv-kasse="${csvKasse}" tabindex="0" role="button"` : "";
    return `
      <article class="d2-card d2-state-${d2Escape(card.state || "warn")}${compact}${csvClass}"${csvAttr}>
        <div class="d2-card-title">${d2Escape(card.title)}</div>
        <div class="d2-card-value">${d2Escape(card.value)}</div>
        ${detail ? `<div class="d2-card-detail">${d2Escape(detail)}</div>` : ""}
      </article>`;
  }).join("");
}

function d2RenderQueues() {
  const grid = document.getElementById("d2QueueGrid");
  const queues = D2.data.queues || {};
  grid.innerHTML = Object.values(queues).map(queue => {
    const items = queue.items || [];
    const buttons = items.map(item => `
      <button type="button" class="d2-open-modal" data-type="${d2Escape(queue.type)}" data-id="${d2Escape(item.id)}" data-tower="${d2Escape((item.tower || "").toLowerCase())}" title="${d2Escape(item.sub || "")}">
        <span class="d2-open-main">${d2Escape(item.label)}</span>
        <span class="d2-open-sub">${d2Escape(item.sub || "")}</span>
      </button>`).join("");
    const content = items.length ? buttons : `<div class="d2-queue-empty">-</div>`;

    return `
      <article class="d2-queue-card${items.length ? " d2-has-items" : ""}">
        <div class="d2-queue-head">
          <div class="d2-queue-title">${d2Escape(queue.title)}</div>
        </div>
        <div class="d2-item-list">${content}</div>
      </article>`;
  }).join("");
}

function d2RenderScripts() {
  const grid = document.getElementById("d2ScriptGrid");
  const crons = D2.data.crons || {};
  const order = ["anmeldung", "abmeldung", "entsperren", "cleanup", "wehdhcp", "tvkdhcp"];
  grid.innerHTML = order
    .map(key => crons[key])
    .filter(script => script && !script.hidden)
    .map(script => `
      <button type="button" class="d2-script-card" data-run-script="${d2Escape(script.key)}" data-script-card="${d2Escape(script.key)}" data-prev-run="${d2Escape(script.prevRun || "")}" data-next-run="${d2Escape(script.nextRun || "")}">
        <div class="d2-script-title">${d2Escape(script.label)}</div>
      </button>`).join("");
}

function d2RenderAll() {
  d2RenderCards();
  d2RenderQueues();
  d2RenderScripts();
  requestAnimationFrame(d2SetDashboardHeight);
}

function d2UpdateCountdowns() {
  document.querySelectorAll("[data-script-card]").forEach(card => {
    const key = card.getAttribute("data-script-card");
    const out = card.querySelector(`[data-countdown="${CSS.escape(key)}"]`);
    if (!out) return;
    const nextRaw = card.getAttribute("data-next-run");
    const prevRaw = card.getAttribute("data-prev-run");
    if (!nextRaw || !prevRaw) {
      out.textContent = "kein Cron gefunden";
      return;
    }
    const next = new Date(nextRaw).getTime();
    const prev = new Date(prevRaw).getTime();
    const total = next - prev;
    if (!Number.isFinite(next) || !Number.isFinite(prev) || total <= 0) {
      out.textContent = "kein Cron gefunden";
      return;
    }
    let remaining = next - Date.now();
    if (remaining < 0) {
      const cycles = Math.floor((Date.now() - prev) / total) + 1;
      remaining = (prev + cycles * total) - Date.now();
    }
    out.textContent = d2FormatSeconds(remaining / 1000);
  });
}


async function d2ImportSparkasseCsv(file, kasseId) {
  if (!file) return;

  const activeCard = document.querySelector(`[data-d2-csv-kasse="${CSS.escape(String(kasseId))}"]`);
  if (activeCard) activeCard.classList.add("d2-card-csv-running");

  d2Terminal(`CSV-Import gestartet (${kasseId === "72" ? "Netzkonto" : "Hauskonto"}).`);

  try {
    const text = await file.text();
    const form = new FormData();
    form.append("csv_text", text);
    form.append("kasse_id", String(kasseId || ""));

    const res = await fetch(`${D2.apiBase}?d2api=csvimport`, {
      method: "POST",
      credentials: "same-origin",
      body: form,
      headers: { "Accept": "application/json" }
    });

    const raw = await res.text();
    let json = null;
    try { json = JSON.parse(raw); } catch (_) {}

    if (!res.ok || !json || !json.ok) {
      throw new Error(json?.error || raw.trim() || `HTTP ${res.status}`);
    }

    if (json.data) {
      D2.data = json.data;
      d2RenderAll();
      d2UpdateCountdowns();
    } else {
      await d2Refresh(true);
    }

    d2Terminal(json.terminal || `CSV-Import abgeschlossen: ${json.inserted || 0} eingetragen, ${json.unknown || 0} unbekannt, ${json.skipped || 0} übersprungen.`);
  } catch (err) {
    d2Terminal(`CSV-Import fehlgeschlagen: ${err.message || err}`, "error");
    alert(`CSV-Import fehlgeschlagen:\n${err.message || err}`);
  } finally {
    if (activeCard) activeCard.classList.remove("d2-card-csv-running");
  }
}

async function d2Refresh(silent = false) {
  try {
    const res = await fetch(`${D2.apiBase}?d2api=summary`, { credentials: "same-origin", cache: "no-store" });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
    D2.data = json.data;
    d2RenderAll();
    d2UpdateCountdowns();
    if (!silent) d2Terminal(`Dashboard aktualisiert: ${new Date().toLocaleTimeString("de-DE")}`);
  } catch (err) {
    d2Terminal(`Dashboard-Refresh fehlgeschlagen: ${err.message || err}`, "error");
  }
}

function d2ScriptOutputLooksFailed(text) {
  const lines = String(text || "").split(/\r?\n/);
  return lines.some(rawLine => {
    const line = rawLine.trim();
    const summaryLine = line
      .replace(/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+\[[^\]]+\]\s*/, "")
      .replace(/^\[[^\]]+\]\s*/, "");
    if (line === "" || /^Fehler:\s*0\s*$/i.test(summaryLine)) {
      return false;
    }
    return /(^|[^a-z])(error|exception|traceback|permission denied|no such file|command not found|failed|fatal)([^a-z]|$)/i.test(line)
      || /^Fehler:\s*[1-9]\d*\s*$/i.test(summaryLine)
      || /^Fehler:\s*(?!0\s*$).+/i.test(summaryLine)
      || /\bfehlgeschlagen\b/i.test(line);
  });
}

function d2UpdateScriptRunningCursor() {
  const isRunning = D2.running.size > 0;
  document.body.classList.toggle("d2-script-running", isRunning);
  const page = document.getElementById("d2Page");
  if (page) {
    page.classList.toggle("d2-script-running", isRunning);
  }
}

async function d2RunScript(key, label = null) {
  if (!key || D2.running.has(key)) return;
  D2.running.add(key);
  d2UpdateScriptRunningCursor();
  const runButton = document.querySelector(`[data-run-script="${CSS.escape(key)}"]`);
  if (runButton) {
    runButton.disabled = true;
    runButton.classList.add("d2-running");
  }

  const started = performance.now();
  const display = label || (D2.data.crons && D2.data.crons[key] ? D2.data.crons[key].label : key);
  d2Terminal(`$ run ${key}`);
  d2Terminal(`[${display}] gestartet...`, "muted");

  try {
    const res = await fetch("run_script.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "script=" + encodeURIComponent(key),
      credentials: "same-origin",
      cache: "no-store"
    });

    const text = await d2ReadScriptResponse(res, res.ok ? "normal" : "error");
    const duration = Math.round(performance.now() - started);
    d2Terminal(`[${display}] HTTP ${res.status} ${res.statusText} · ${duration} ms`, res.ok ? "muted" : "error");
    if (text.trim() === "") {
      d2Terminal("[leere Ausgabe]", "muted");
    }
    if (!res.ok || d2ScriptOutputLooksFailed(text)) {
      throw new Error(`Skript ${key} lieferte Fehlerausgabe.`);
    }
    await d2Refresh(true);
  } catch (err) {
    d2Terminal(`[${display}] fehlgeschlagen: ${err.message || err}`, "error");
  } finally {
    D2.running.delete(key);
    d2UpdateScriptRunningCursor();
    if (runButton) {
      runButton.disabled = false;
      runButton.classList.remove("d2-running");
    }
  }
}

async function d2OpenModal(type, id) {
  try {
    d2Terminal(`Öffne ${type} #${id}...`, "muted");
    const res = await fetch(`${D2.apiBase}?d2api=modal&type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`, { credentials: "same-origin", cache: "no-store" });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
    document.getElementById("d2ModalRoot").innerHTML = json.html;
  } catch (err) {
    d2Terminal(`Modal konnte nicht geöffnet werden: ${err.message || err}`, "error");
  }
}

function d2CloseModal() {
  document.getElementById("d2ModalRoot").innerHTML = "";
}

async function d2SubmitAction(form) {
  const submit = form.querySelector("button[type='submit'], input[type='submit']");
  if (submit) submit.disabled = true;
  try {
    const formData = new FormData(form);
    if (form._d2Submitter && form._d2Submitter.name) {
      formData.append(form._d2Submitter.name, form._d2Submitter.value || "");
    }
    const res = await fetch(`${D2.apiBase}?d2api=action`, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
      cache: "no-store"
    });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);

    d2Terminal(json.message || "Aktion erfolgreich.");
    if (json.terminal) d2TerminalBlock(json.terminal);
    d2CloseModal();
    await d2Refresh(true);

    if (json.autorunScript) {
      await d2RunScript(json.autorunScript, "PSK-Abgleich WLC automatisch");
    }
  } catch (err) {
    d2Terminal(`Aktion fehlgeschlagen: ${err.message || err}`, "error");
  } finally {
    if (submit) submit.disabled = false;
  }
}

function d2SelectTransferUser(form, uid, label) {
  const selected = form.querySelector(".d2-selected-uid");
  const search = form.querySelector(".d2-user-search");
  const assign = form.querySelector(".d2-assign-button");
  const results = form.querySelector(".d2-search-results");
  if (selected) selected.value = uid;
  if (search) search.value = label;
  if (assign) {
    assign.disabled = false;
    assign.textContent = "Zuweisen an " + label;
  }
  if (results) results.innerHTML = "";
}

function d2ToggleDeclineReason(form) {
  const reason = form.querySelector(".d2-decline-reason");
  if (!reason) return;
  const input = reason.querySelector("input");
  const show = !!form.querySelector('input[name="decision"][value="decline"]:checked');
  reason.classList.toggle("d2-visible", show);
  if (input) {
    input.disabled = !show;
    if (!show) input.value = "";
  }
}

async function d2SearchUsers(input) {
  const query = input.value.trim();
  const form = input.closest("form");
  const results = form.querySelector(".d2-search-results");
  if (query.length < 2) {
    results.innerHTML = "";
    return;
  }
  try {
    const res = await fetch(`${D2.apiBase}?d2api=search&search=${encodeURIComponent(query)}`, { credentials: "same-origin", cache: "no-store" });
    const data = await res.json();
    results.innerHTML = "";
    if (Object.keys(data).length === 0) {
      results.innerHTML = `<div class="d2-empty">Keine Treffer</div>`;
      return;
    }
    for (const uid in data) {
      const user = data[uid][0];
      const turm = String(user.turm || "").toLowerCase() === "tvk" ? "TvK" : String(user.turm || "").toUpperCase();
      const label = `${user.name} (${turm} ${user.room})`;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "d2-small-btn d2-select-user";
      btn.dataset.uid = uid;
      btn.dataset.label = label;
      btn.textContent = label;
      results.appendChild(btn);
    }
  } catch (err) {
    results.innerHTML = `<div class="d2-modal-error">Suche fehlgeschlagen</div>`;
  }
}

function d2HandleTerminalCommand(command) {
  const cmd = command.trim();
  if (!cmd) return;
  d2Terminal(`$ ${cmd}`);

  if (cmd === "clear") {
    document.getElementById("d2Terminal").innerHTML = "";
    return;
  }
  if (cmd === "help") {
    d2TerminalBlock("Befehle:\nhelp\nclear\nrefresh\nrun anmeldung\nrun entsperren\nrun cleanup\nrun abmeldung\nrun wehdhcp\nrun tvkdhcp", "muted");
    return;
  }
  if (cmd === "refresh") {
    d2Refresh(false);
    return;
  }
  const runMatch = cmd.match(/^run\s+([a-z0-9_-]+)$/i);
  if (runMatch) {
    const key = runMatch[1];
    if (!D2.data.crons || !D2.data.crons[key] || D2.data.crons[key].hidden) {
      d2Terminal(`Unbekannter Script-Key: ${key}`, "error");
      return;
    }
    d2RunScript(key);
    return;
  }

  d2Terminal("Nicht unterstützter Befehl.", "error");
}

document.addEventListener("click", event => {
  const docTab = event.target.closest("[data-d2-doc-tab]");
  if (docTab && !docTab.disabled) {
    const modal = docTab.closest(".d2-registration-modal");
    const key = docTab.dataset.d2DocTab;
    if (modal && key) {
      modal.querySelectorAll("[data-d2-doc-tab]").forEach(tab => tab.classList.toggle("d2-active", tab === docTab));
      modal.querySelectorAll("[data-d2-doc-panel]").forEach(panel => panel.classList.toggle("d2-active", panel.dataset.d2DocPanel === key));
    }
    return;
  }

  const modalButton = event.target.closest(".d2-open-modal");
  if (modalButton) {
    d2OpenModal(modalButton.dataset.type, modalButton.dataset.id);
    return;
  }

  if (event.target.closest("[data-d2-close]")) {
    d2CloseModal();
    return;
  }

  const runButton = event.target.closest("[data-run-script]");
  if (runButton) {
    d2RunScript(runButton.dataset.runScript);
    return;
  }

  if (event.target.closest("[data-d2-refresh]")) {
    d2Refresh(false);
    return;
  }

  if (event.target.closest("[data-d2-terminal-clear]")) {
    document.getElementById("d2Terminal").innerHTML = "";
    return;
  }

  const dummy = event.target.closest(".d2-select-dummy");
  if (dummy) {
    d2SelectTransferUser(dummy.closest("form"), dummy.dataset.uid, dummy.dataset.label);
    return;
  }

  const userButton = event.target.closest(".d2-select-user");
  if (userButton) {
    d2SelectTransferUser(userButton.closest("form"), userButton.dataset.uid, userButton.dataset.label);
    return;
  }

  const copyButton = event.target.closest(".d2-copy-btn");
  if (copyButton) {
    navigator.clipboard.writeText(copyButton.dataset.copy || "").then(() => {
      copyButton.classList.add("d2-copied");
      const toast = document.getElementById("d2Toast");
      if (toast) {
        toast.classList.add("d2-visible");
        clearTimeout(toast._d2Timer);
        toast._d2Timer = setTimeout(() => toast.classList.remove("d2-visible"), 950);
      }
      setTimeout(() => copyButton.classList.remove("d2-copied"), 900);
    });
  }
});

document.addEventListener("submit", event => {
  const actionForm = event.target.closest(".d2-action-form");
  if (actionForm) {
    event.preventDefault();
    actionForm._d2Submitter = event.submitter || document.activeElement || null;
    d2SubmitAction(actionForm);
    return;
  }

  if (event.target.id === "d2TerminalForm") {
    event.preventDefault();
    const input = document.getElementById("d2TerminalCommand");
    d2HandleTerminalCommand(input.value);
    input.value = "";
  }
});

document.addEventListener("input", event => {
  const searchInput = event.target.closest(".d2-user-search");
  if (!searchInput) return;
  clearTimeout(D2.searchTimer);
  D2.searchTimer = setTimeout(() => d2SearchUsers(searchInput), 220);
});

document.addEventListener("change", event => {
  const decision = event.target.closest('input[name="decision"]');
  if (!decision) return;
  const form = decision.closest("form");
  if (form) d2ToggleDeclineReason(form);
});

function d2SetDashboardHeight() {
  const page = document.getElementById("d2Page");
  const leftShell = document.querySelector(".d2-left-shell");
  const terminal = document.querySelector(".d2-terminal-wrap");

  if (!page) return;

  const top = Math.max(0, page.getBoundingClientRect().top);
  const available = Math.max(360, window.innerHeight - top - 72);
  document.documentElement.style.setProperty("--d2-page-height", `${available}px`);

  /* Die Webmaster-Seite soll desktop nicht in 4er-Grids springen. */
  page.classList.remove("d2-compact-left");

  if (leftShell && terminal && window.innerWidth > 900) {
    document.documentElement.style.setProperty("--d2-terminal-height", `${leftShell.offsetHeight}px`);
  } else {
    document.documentElement.style.removeProperty("--d2-terminal-height");
  }
}


document.addEventListener("click", event => {
  const csvCard = event.target.closest("[data-d2-csv-kasse]");
  if (!csvCard) return;

  const input = document.getElementById("d2CsvFileInput");
  if (!input) return;

  input.dataset.d2CsvKasse = csvCard.dataset.d2CsvKasse || "";
  input.value = "";
  input.click();
});

document.addEventListener("keydown", event => {
  if (event.key !== "Enter" && event.key !== " ") return;
  const csvCard = event.target.closest("[data-d2-csv-kasse]");
  if (!csvCard) return;

  event.preventDefault();
  const input = document.getElementById("d2CsvFileInput");
  if (!input) return;

  input.dataset.d2CsvKasse = csvCard.dataset.d2CsvKasse || "";
  input.value = "";
  input.click();
});

document.getElementById("d2CsvFileInput")?.addEventListener("change", event => {
  const input = event.currentTarget;
  const file = input.files && input.files[0] ? input.files[0] : null;
  const kasseId = input.dataset.d2CsvKasse || "";
  d2ImportSparkasseCsv(file, kasseId);
});

d2RenderAll();
d2UpdateCountdowns();
setInterval(d2UpdateCountdowns, 1000);
setInterval(() => d2Refresh(true), 30000);
d2SetDashboardHeight();
window.addEventListener("resize", d2SetDashboardHeight);
d2Terminal("Webmaster-Dashboard wurde geladen.", "muted");
</script>
<?php $conn->close(); ?>
</html>
