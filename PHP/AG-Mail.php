<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require('conn.php');
mysqli_set_charset($conn, "utf8mb4");

const AGMAIL_IGNORED_GROUP_IDS = [1, 16, 19, 20, 22, 24, 27];

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bindDynamicParams(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types === '' || empty($params)) {
        return true;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function getUsersColumnConfig(mysqli $conn): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $existing = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $existing[strtolower((string)$row['Field'])] = true;
        }
        mysqli_free_result($result);
    }

    $pick = static function (array $candidates) use ($existing): ?string {
        foreach ($candidates as $candidate) {
            if (isset($existing[strtolower($candidate)])) {
                return $candidate;
            }
        }
        return null;
    };

    $config = [
        'uid'         => $pick(['uid']),
        'username'    => $pick(['username']),
        'name'        => $pick(['name']),
        'firstname'   => $pick(['firstname', 'vorname', 'first_name']),
        'lastname'    => $pick(['lastname', 'nachname', 'last_name']),
        'room'        => $pick(['room', 'raum', 'zimmer']),
        'oldroom'     => $pick(['oldroom', 'old_room']),
        'turm'        => $pick(['turm']),
        'mail_active' => $pick(['mailisactive', 'mailactive', 'mail_active']),
        'pid'         => $pick(['pid']),
    ];

    return $config;
}

function getResidentStatusLabel(int $pid): string
{
    if ($pid === 12) {
        return 'Untervermieter';
    }
    if ($pid === 13) {
        return 'Ausgezogen';
    }
    if ($pid === 14) {
        return 'Abgemeldet';
    }

    return '';
}

function buildUserDisplayName(array $row): string
{
    $name      = trim((string)($row['name'] ?? ''));
    $firstname = trim((string)($row['firstname'] ?? ''));
    $lastname  = trim((string)($row['lastname'] ?? ''));
    $username  = trim((string)($row['username'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $fullName = trim($firstname . ' ' . $lastname);
    if ($fullName !== '') {
        return $fullName;
    }

    if ($username !== '') {
        return $username;
    }

    return 'Unbekannt';
}

function buildUserRoom(array $row): string
{
    $pid = (int)($row['pid'] ?? 0);

    $roomRaw = $row['room'] ?? '';
    $room = trim((string)$roomRaw);

    $oldRoomRaw = $row['oldroom'] ?? '';
    $oldRoom = trim((string)$oldRoomRaw);

    $roomIsZero = ($room === '0' || $room === '' || $roomRaw === 0 || $roomRaw === '0');

    if ($roomIsZero && in_array($pid, [12, 13, 14], true) && $oldRoom !== '' && $oldRoom !== '0') {
        return $oldRoom;
    }

    if ($room !== '' && $room !== '0') {
        return $room;
    }

    if ($oldRoom !== '' && $oldRoom !== '0' && in_array($pid, [12, 13, 14], true)) {
        return $oldRoom;
    }

    return '-';
}

function normalizeUserRow(array $row, string $extraStatus = ''): array
{
    $pid = (int)($row['pid'] ?? 0);

    return [
        'uid'             => (int)($row['uid'] ?? 0),
        'name'            => buildUserDisplayName($row),
        'room'            => buildUserRoom($row),
        'turm'            => strtolower(trim((string)($row['turm'] ?? ''))),
        'pid'             => $pid,
        'resident_status' => getResidentStatusLabel($pid),
        'plaetze'         => trim((string)($row['plaetze'] ?? '')),
        'extra_status'    => $extraStatus,
    ];
}

function fetchAllowedSenderGroups(mysqli $conn): array
{
    $ignoredIds = implode(',', array_map('intval', AGMAIL_IGNORED_GROUP_IDS));

    $sql = "
        SELECT
            `id`,
            `name`,
            `mail`,
            `turm`,
            `session`
        FROM `groups`
        WHERE `active` = 1
          AND `session` IS NOT NULL
          AND `session` <> ''
          AND `mail` IS NOT NULL
          AND `mail` <> ''
          AND `id` NOT IN ($ignoredIds)
        ORDER BY
            CASE `turm` WHEN 'weh' THEN 0 WHEN 'tvk' THEN 1 ELSE 2 END,
            COALESCE(`prio`, 999999) ASC,
            `name` ASC
    ";

    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        return [];
    }

    $groups = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $sessionName = (string)($row['session'] ?? '');
        if ($sessionName === '' || empty($_SESSION[$sessionName])) {
            continue;
        }

        $groups[] = [
            'id'      => (int)$row['id'],
            'name'    => trim((string)$row['name']),
            'mail'    => trim((string)$row['mail']),
            'turm'    => strtolower(trim((string)$row['turm'])),
            'session' => $sessionName,
        ];
    }

    mysqli_free_result($result);

    return $groups;
}

function findSenderGroupById(array $groups, int $groupId): ?array
{
    foreach ($groups as $group) {
        if ((int)$group['id'] === $groupId) {
            return $group;
        }
    }

    return null;
}

function buildUserSelectParts(array $cfg): array
{
    return [
        "users.`{$cfg['uid']}` AS uid",
        $cfg['username']  ? "users.`{$cfg['username']}` AS username"   : "'' AS username",
        $cfg['name']      ? "users.`{$cfg['name']}` AS name"           : "'' AS name",
        $cfg['firstname'] ? "users.`{$cfg['firstname']}` AS firstname" : "'' AS firstname",
        $cfg['lastname']  ? "users.`{$cfg['lastname']}` AS lastname"   : "'' AS lastname",
        $cfg['room']      ? "users.`{$cfg['room']}` AS room"           : "'' AS room",
        $cfg['oldroom']   ? "users.`{$cfg['oldroom']}` AS oldroom"     : "'' AS oldroom",
        "users.`{$cfg['turm']}` AS turm",
        $cfg['pid']       ? "users.`{$cfg['pid']}` AS pid"             : "0 AS pid",
    ];
}

function searchUsers(mysqli $conn, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid']) ||
        empty($cfg['mail_active'])
    ) {
        return [];
    }

    $selectParts = buildUserSelectParts($cfg);

    $like = '%' . $query . '%';
    $conditions = [];
    $params = [];
    $types = '';

    $addCondition = static function (string $sqlPart) use (&$conditions, &$params, &$types, $like): void {
        $conditions[] = $sqlPart;
        $params[] = $like;
        $types .= 's';
    };

    $addCondition("CAST(users.`{$cfg['uid']}` AS CHAR) LIKE ?");

    if (!empty($cfg['username'])) {
        $addCondition("users.`{$cfg['username']}` LIKE ?");
        $addCondition("CONCAT(users.`{$cfg['username']}`, '@', users.`{$cfg['turm']}`, '.rwth-aachen.de') LIKE ?");
    }

    if (!empty($cfg['name'])) {
        $addCondition("users.`{$cfg['name']}` LIKE ?");
    }

    if (!empty($cfg['firstname']) && !empty($cfg['lastname'])) {
        $addCondition("CONCAT_WS(' ', users.`{$cfg['firstname']}`, users.`{$cfg['lastname']}`) LIKE ?");
    }

    if (!empty($cfg['firstname'])) {
        $addCondition("users.`{$cfg['firstname']}` LIKE ?");
    }

    if (!empty($cfg['lastname'])) {
        $addCondition("users.`{$cfg['lastname']}` LIKE ?");
    }

    if (!empty($cfg['room'])) {
        $addCondition("CAST(users.`{$cfg['room']}` AS CHAR) LIKE ?");
    }

    if (!empty($cfg['oldroom'])) {
        $addCondition("CAST(users.`{$cfg['oldroom']}` AS CHAR) LIKE ?");
    }

    $addCondition("users.`{$cfg['turm']}` LIKE ?");

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM users
        WHERE users.`{$cfg['turm']}` IN ('weh', 'tvk')
          AND users.`{$cfg['mail_active']}` = 1
          AND users.`{$cfg['pid']}` IN (11, 12, 13, 14)
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY
            CASE users.`{$cfg['turm']}` WHEN 'weh' THEN 0 WHEN 'tvk' THEN 1 ELSE 2 END,
            CASE
                WHEN users.`{$cfg['room']}` = 0 AND users.`{$cfg['pid']}` IN (12, 13, 14) THEN users.`{$cfg['oldroom']}`
                ELSE users.`{$cfg['room']}`
            END ASC
        LIMIT 40
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    if (!bindDynamicParams($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user = normalizeUserRow($row);
        if ($user['uid'] > 0) {
            $users[] = $user;
        }
    }

    mysqli_stmt_close($stmt);

    return $users;
}

function fetchBikeModeUsers(mysqli $conn, string $turm): array
{
    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid'])
    ) {
        return [
            'spot' => [],
            'queue' => [],
        ];
    }

    $selectParts = buildUserSelectParts($cfg);
    $selectParts[] = "fahrrad.starttime AS bike_starttime";
    $selectParts[] = "fahrrad.platz AS bike_platz";

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM users
        INNER JOIN fahrrad
            ON users.`{$cfg['uid']}` = fahrrad.uid
        WHERE (fahrrad.endtime IS NULL OR fahrrad.endtime > ?)
          AND fahrrad.turm = ?
          AND users.`{$cfg['turm']}` = ?
    ";

    if (!empty($cfg['mail_active'])) {
        $sql .= " AND users.`{$cfg['mail_active']}` = 1";
    }

    $sql .= " AND users.`{$cfg['pid']}` IN (11, 12, 13, 14)";
    $sql .= " ORDER BY fahrrad.platz ASC, fahrrad.starttime ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [
            'spot' => [],
            'queue' => [],
        ];
    }

    $zeit = time();
    mysqli_stmt_bind_param($stmt, "iss", $zeit, $turm, $turm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $spotByUid = [];
    $queueByUid = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            continue;
        }

        $platz = (int)($row['bike_platz'] ?? 0);

        if ($platz > 0) {
            if (!isset($spotByUid[$uid])) {
                $spotByUid[$uid] = normalizeUserRow($row);
                $spotByUid[$uid]['plaetze'] = '';
            }

            $existing = $spotByUid[$uid]['plaetze'] !== ''
                ? array_map('trim', explode(',', $spotByUid[$uid]['plaetze']))
                : [];

            if (!in_array((string)$platz, $existing, true)) {
                $existing[] = (string)$platz;
            }

            natsort($existing);
            $spotByUid[$uid]['plaetze'] = implode(', ', $existing);
            $spotByUid[$uid]['extra_status'] = (count($existing) > 1 ? 'Stellplätze ' : 'Stellplatz ') . $spotByUid[$uid]['plaetze'];
        } else {
            if (!isset($queueByUid[$uid])) {
                $queueByUid[$uid] = normalizeUserRow($row, 'Warteschlange');
            }
        }
    }

    mysqli_stmt_close($stmt);

    return [
        'spot'  => array_values($spotByUid),
        'queue' => array_values($queueByUid),
    ];
}

function fetchFitnessModeUsers(mysqli $conn): array
{
    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid'])
    ) {
        return [];
    }

    $selectParts = buildUserSelectParts($cfg);
    $selectParts[] = "fitness.status AS fitness_status";

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM fitness
        INNER JOIN users
            ON fitness.uid = users.`{$cfg['uid']}`
        WHERE users.`{$cfg['pid']}` IN (11, 12)
        ORDER BY
            FIELD(users.`{$cfg['turm']}`, 'weh', 'tvk'),
            CASE
                WHEN users.`{$cfg['room']}` = 0 AND users.`{$cfg['pid']}` IN (12, 13, 14) THEN users.`{$cfg['oldroom']}`
                ELSE users.`{$cfg['room']}`
            END ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $statusValue = (int)($row['fitness_status'] ?? 0);
        $statusText = $statusValue === 1 ? 'Fitness bestätigt' : 'Fitness-Check offen';

        $user = normalizeUserRow($row, $statusText);
        if ($user['uid'] > 0) {
            $users[] = $user;
        }
    }

    mysqli_stmt_close($stmt);

    return $users;
}

function sendMailToUsers(mysqli $conn, array $uids, string $subject, string $message, string $fromAddress): array
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    })));

    $subject = trim($subject);
    $message = trim($message);
    $fromAddress = trim($fromAddress);

    if ($subject === '') {
        return [
            'ok' => false,
            'message' => 'Bitte einen Betreff eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if ($message === '') {
        return [
            'ok' => false,
            'message' => 'Bitte eine Nachricht eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if ($fromAddress === '') {
        return [
            'ok' => false,
            'message' => 'Bitte eine gültige Sender-Adresse auswählen.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if (empty($uids)) {
        return [
            'ok' => false,
            'message' => 'Bitte mindestens eine Person auswählen.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $cfg = getUsersColumnConfig($conn);

    if (empty($cfg['uid']) || empty($cfg['username']) || empty($cfg['turm'])) {
        return [
            'ok' => false,
            'message' => 'Benutzerdaten konnten nicht gelesen werden.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $inList = implode(',', $uids);

    $sql = "
        SELECT DISTINCT
            CONCAT(users.`{$cfg['username']}`, '@', users.`{$cfg['turm']}`, '.rwth-aachen.de') AS email
        FROM users
        WHERE users.`{$cfg['uid']}` IN ($inList)
          AND users.`{$cfg['username']}` IS NOT NULL
          AND users.`{$cfg['username']}` <> ''
          AND users.`{$cfg['turm']}` IN ('weh', 'tvk')
    ";

    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        return [
            'ok' => false,
            'message' => 'Fehler beim Laden der Empfänger.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $emails = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    mysqli_free_result($result);

    $emails = array_values(array_unique($emails));

    if (empty($emails)) {
        return [
            'ok' => false,
            'message' => 'Es wurden keine gültigen Empfänger gefunden.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $headers = [
        'From: ' . $fromAddress,
        'Reply-To: ' . $fromAddress,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $headerString = implode("\r\n", $headers);

    $sent = 0;
    $total = count($emails);

    foreach ($emails as $email) {
        if (@mail($email, $subject, $message, $headerString)) {
            $sent++;
        }
    }

    if ($sent === $total) {
        return [
            'ok' => true,
            'message' => 'Nachricht erfolgreich versendet.',
            'sent' => $sent,
            'total' => $total,
        ];
    }

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Nachricht teilweise versendet (' . $sent . ' von ' . $total . ').'
            : 'Fehler beim Versenden der Nachricht.',
        'sent' => $sent,
        'total' => $total,
    ];
}

$senderGroups = fetchAllowedSenderGroups($conn);
$hasSessionAccess = !empty($_SESSION['valid']) && !empty($senderGroups);

$isJsonRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    );

$bikeAllowedTurms = [];
if (!empty($_SESSION['FahrradAG'])) {
    $bikeAllowedTurms[] = 'weh';
}
if (!empty($_SESSION['TvK-Sprecher'])) {
    $bikeAllowedTurms[] = 'tvk';
}
$bikeAllowedTurms = array_values(array_unique($bikeAllowedTurms));

$canUseFitnessMode = !empty($_SESSION['SportAG']);

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$hasSessionAccess) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Keine Berechtigung.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Ungültige Anfrage.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $action = (string)($payload['action'] ?? '');

    if ($action === 'search') {
        $query = (string)($payload['query'] ?? '');
        $users = searchUsers($conn, $query);

        echo json_encode([
            'ok' => true,
            'users' => $users,
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    if ($action === 'send') {
        $senderGroupId = (int)($payload['sender_group_id'] ?? 0);
        $senderGroup = findSenderGroupById($senderGroups, $senderGroupId);

        if ($senderGroup === null) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'Ungültige Sender-Adresse.',
            ], JSON_UNESCAPED_UNICODE);
            $conn->close();
            exit;
        }

        $uids = $payload['uids'] ?? [];
        if (!is_array($uids)) {
            $uids = [];
        }

        $subject = (string)($payload['subject'] ?? '');
        $message = (string)($payload['message'] ?? '');

        $response = sendMailToUsers($conn, $uids, $subject, $message, (string)$senderGroup['mail']);
        http_response_code($response['ok'] ? 200 : 400);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Unbekannte Aktion.',
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

ob_start();
require('template.php');
$templateBootstrap = ob_get_clean();

$canAccess = auth($conn) && !empty($_SESSION['valid']) && !empty($senderGroups);

if (!$canAccess) {
    header('Location: denied.php');
    exit;
}

$bikeUsersByTurm = [];
foreach ($bikeAllowedTurms as $turm) {
    $bikeUsersByTurm[$turm] = fetchBikeModeUsers($conn, $turm);
}

$fitnessUsers = $canUseFitnessMode ? fetchFitnessModeUsers($conn) : [];

$pageTitle = 'AG-Mail';
$hasSingleSender = count($senderGroups) === 1;
$singleSender = $hasSingleSender ? $senderGroups[0] : null;
$hasBikeModes = !empty($bikeAllowedTurms);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title><?php echo esc($pageTitle); ?></title>
    <style>
        .agmail-page {
            display: flex;
            justify-content: center;
            padding: 32px 20px 48px;
            background: transparent;
        }

        .agmail-shell {
            width: 100%;
            max-width: 1120px;
            background: #211f1f;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 24px;
            box-sizing: border-box;
        }

        .agmail-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .agmail-header h1 {
            margin: 0;
            color: #f1eeee;
            font-size: 32px;
            line-height: 1.15;
        }

        .agmail-modebar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .agmail-modebtn {
            appearance: none;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            padding: 10px 14px;
            background: #2a2727;
            color: #f1eeee;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.15s ease, opacity 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }

        .agmail-modebtn:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }

        .agmail-modebtn.is-active {
            border-color: rgba(17, 165, 13, 0.62);
            background: rgba(17, 165, 13, 0.14);
            color: #dff8df;
        }

        .agmail-section {
            margin-bottom: 22px;
        }

        .agmail-label {
            display: block;
            margin-bottom: 10px;
            color: #f1eeee;
            font-size: 16px;
            font-weight: 700;
        }

        .agmail-select,
        .agmail-input,
        .agmail-textarea {
            width: 100%;
            box-sizing: border-box;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: #2a2727;
            color: #f1eeee;
            padding: 14px 16px;
        }

        .agmail-select,
        .agmail-input {
            min-height: 52px;
        }

        .agmail-textarea {
            min-height: 220px;
            resize: vertical;
        }

        .agmail-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            color-scheme: dark;
        }

        .agmail-select option,
        .agmail-select optgroup {
            background: #2a2727;
            color: #f1eeee;
        }

        .agmail-select:focus,
        .agmail-input:focus,
        .agmail-textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.18);
            background: #312e2e;
        }

        .agmail-input::placeholder,
        .agmail-textarea::placeholder {
            color: rgba(255, 255, 255, 0.38);
        }

        .agmail-sender-card {
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            background: #2a2727;
            padding: 14px 16px;
            color: #f1eeee;
            line-height: 1.45;
        }

        .agmail-sender-card strong {
            display: block;
            margin-bottom: 3px;
        }

        .agmail-results,
        .agmail-recipients {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
            gap: 14px;
        }

        .agmail-user {
            appearance: none;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            background: #2a2727;
            padding: 14px 16px;
            text-align: left;
            cursor: pointer;
            transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease, opacity 0.15s ease;
        }

        .agmail-user:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.16);
            background: #312e2e;
        }

        .agmail-user.is-selected {
            border-color: rgba(17, 165, 13, 0.62);
            background: rgba(17, 165, 13, 0.14);
        }

        .agmail-user__name {
            display: block;
            color: #f1eeee;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 6px;
        }

        .agmail-user__meta {
            display: block;
            color: rgba(255, 255, 255, 0.74);
            font-size: 14px;
            line-height: 1.45;
        }

        .agmail-empty {
            border: 1px dashed rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            padding: 18px;
            color: rgba(255, 255, 255, 0.66);
            text-align: center;
            background: #262323;
        }

        .agmail-recipients-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 10px;
        }

        .agmail-recipients-head h2 {
            margin: 0;
            color: #f1eeee;
            font-size: 20px;
            line-height: 1.2;
        }

        .agmail-count {
            color: rgba(255, 255, 255, 0.72);
            font-size: 14px;
        }

        .agmail-actions {
            display: flex;
            justify-content: flex-end;
        }

        .agmail-send {
            appearance: none;
            border: 1px solid rgba(17, 165, 13, 0.65);
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            background: #11a50d;
            color: #f7fff7;
            transition: opacity 0.15s ease, transform 0.15s ease, background 0.15s ease;
        }

        .agmail-send:hover:not(:disabled) {
            transform: translateY(-1px);
            background: #149f11;
        }

        .agmail-send:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .agmail-page.is-sent .agmail-shell {
            display: none;
        }

        .agmail-success {
            display: none;
            width: 100%;
            max-width: 1100px;
            min-height: 260px;
            align-items: center;
            justify-content: center;
        }

        .agmail-page.is-sent .agmail-success {
            display: flex;
        }

        .agmail-success__text {
            color: #f1eeee;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        @media (max-width: 720px) {
            .agmail-page {
                padding: 20px 12px 36px;
            }

            .agmail-shell {
                padding: 18px;
                border-radius: 16px;
            }

            .agmail-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agmail-header h1 {
                font-size: 26px;
            }

            .agmail-success__text {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
<?php
echo $templateBootstrap;
load_menu();
?>
<main class="agmail-page" id="agMailPage">
    <section class="agmail-shell">
        <div class="agmail-header">
            <h1>AG-Mail</h1>

            <div class="agmail-modebar" id="modeBar">
                <button type="button" class="agmail-modebtn is-active" data-mode="regular">Suche</button>
                <?php if ($hasBikeModes) : ?>
                    <button type="button" class="agmail-modebtn" data-mode="bike_spot">Stellplätze</button>
                    <button type="button" class="agmail-modebtn" data-mode="bike_queue">Warteschlange</button>
                <?php endif; ?>
                <?php if ($canUseFitnessMode) : ?>
                    <button type="button" class="agmail-modebtn" data-mode="fitness">Fitness</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="agmail-section">
            <label class="agmail-label" for="senderSelect">Sender</label>

            <?php if ($hasSingleSender && $singleSender !== null) : ?>
                <div class="agmail-sender-card" id="singleSenderCard" data-sender-id="<?php echo (int)$singleSender['id']; ?>">
                    <strong><?php echo esc($singleSender['name']); ?></strong>
                    <span><?php echo esc($singleSender['mail']); ?> | <?php echo esc(strtoupper($singleSender['turm'])); ?></span>
                </div>
            <?php else : ?>
                <select class="agmail-select" id="senderSelect">
                    <?php foreach ($senderGroups as $group) : ?>
                        <option value="<?php echo (int)$group['id']; ?>">
                            <?php echo esc($group['name'] . ' | ' . $group['mail'] . ' | ' . strtoupper($group['turm'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="agmail-section" id="searchSection">
            <label class="agmail-label" for="userSearch">Empfänger suchen</label>
            <input
                type="text"
                id="userSearch"
                class="agmail-input"
                placeholder="Raum, Name, Mail, Username ..."
                autocomplete="off"
                spellcheck="false"
            >
        </div>

        <div class="agmail-section">
            <div id="resultsWrap" class="agmail-results"></div>
            <div id="resultsEmpty" class="agmail-empty" hidden></div>
        </div>

        <div class="agmail-section">
            <div class="agmail-recipients-head">
                <h2>Empfänger</h2>
                <span class="agmail-count" id="recipientCount">0 ausgewählt</span>
            </div>
            <div id="recipientsWrap" class="agmail-recipients"></div>
            <div id="recipientsEmpty" class="agmail-empty">Noch keine Empfänger ausgewählt.</div>
        </div>

        <div class="agmail-section">
            <label class="agmail-label" for="mailSubject">Betreff</label>
            <input type="text" id="mailSubject" class="agmail-input" placeholder="Betreff eingeben">
        </div>

        <div class="agmail-section">
            <label class="agmail-label" for="mailMessage">Nachricht</label>
            <textarea id="mailMessage" class="agmail-textarea" placeholder="Nachricht eingeben"></textarea>
        </div>

        <div class="agmail-actions">
            <button type="button" id="sendButton" class="agmail-send" disabled>Nachricht senden</button>
        </div>
    </section>

    <div class="agmail-success" id="sendSuccessScreen">
        <div class="agmail-success__text">Gesendet</div>
    </div>
</main>

<script>
(() => {
    const page = document.getElementById('agMailPage');
    const modeBar = document.getElementById('modeBar');
    const senderSelect = document.getElementById('senderSelect');
    const singleSenderCard = document.getElementById('singleSenderCard');
    const searchSection = document.getElementById('searchSection');
    const userSearch = document.getElementById('userSearch');
    const resultsWrap = document.getElementById('resultsWrap');
    const resultsEmpty = document.getElementById('resultsEmpty');
    const recipientsWrap = document.getElementById('recipientsWrap');
    const recipientsEmpty = document.getElementById('recipientsEmpty');
    const recipientCount = document.getElementById('recipientCount');
    const subjectField = document.getElementById('mailSubject');
    const messageField = document.getElementById('mailMessage');
    const sendButton = document.getElementById('sendButton');

    const senderGroups = <?php echo json_encode(array_values($senderGroups), JSON_UNESCAPED_UNICODE); ?>;
    const bikeUsersByTurm = <?php echo json_encode($bikeUsersByTurm, JSON_UNESCAPED_UNICODE); ?>;
    const bikeAllowedTurms = <?php echo json_encode(array_values($bikeAllowedTurms), JSON_UNESCAPED_UNICODE); ?>;
    const fitnessUsers = <?php echo json_encode(array_values($fitnessUsers), JSON_UNESCAPED_UNICODE); ?>;

    let currentMode = 'regular';
    let regularResults = [];
    let selectedRecipients = new Map();
    let searchRequestCounter = 0;
    let searchDebounceTimer = null;

    const escapeHtml = (value) => {
        return String(value).replace(/[&<>"']/g, (char) => {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return char;
            }
        });
    };

    const getSelectedSenderGroupId = () => {
        if (senderSelect) {
            return parseInt(senderSelect.value, 10) || 0;
        }
        if (singleSenderCard) {
            return parseInt(singleSenderCard.dataset.senderId, 10) || 0;
        }
        return 0;
    };

    const getCurrentSenderGroup = () => {
        const senderGroupId = getSelectedSenderGroupId();
        return senderGroups.find((group) => Number(group.id) === Number(senderGroupId)) || senderGroups[0] || null;
    };

    const buildMetaParts = (user) => {
        const parts = [
            String(user.room || '-'),
            String(user.turm || '').toUpperCase()
        ];

        const residentStatus = String(user.resident_status || '').trim();
        if (residentStatus !== '') {
            parts.push(residentStatus);
        }

        const extraStatus = String(user.extra_status || '').trim();
        if (extraStatus !== '') {
            parts.push(extraStatus);
        }

        return parts;
    };

    const formatUserMeta = (user) => buildMetaParts(user).join(' | ');

    const getCurrentBikeUsers = (bucket) => {
        const senderGroup = getCurrentSenderGroup();
        if (!senderGroup) {
            return null;
        }

        const turm = String(senderGroup.turm || '').toLowerCase();
        if (!bikeAllowedTurms.includes(turm)) {
            return null;
        }

        if (!bikeUsersByTurm[turm] || !Array.isArray(bikeUsersByTurm[turm][bucket])) {
            return [];
        }

        return bikeUsersByTurm[turm][bucket];
    };

    const getCurrentModeUsers = () => {
        if (currentMode === 'bike_spot') {
            return getCurrentBikeUsers('spot');
        }
        if (currentMode === 'bike_queue') {
            return getCurrentBikeUsers('queue');
        }
        if (currentMode === 'fitness') {
            return fitnessUsers;
        }
        return regularResults;
    };

    const getModeUnavailableMessage = () => {
        if (currentMode === 'bike_spot' || currentMode === 'bike_queue') {
            return 'Für den aktuell gewählten Sender ist dieser Modus nicht verfügbar.';
        }
        return '';
    };

    const updateSendState = () => {
        sendButton.disabled =
            selectedRecipients.size === 0 ||
            subjectField.value.trim() === '' ||
            messageField.value.trim() === '' ||
            !getCurrentSenderGroup();
    };

    const renderRecipients = () => {
        const recipients = Array.from(selectedRecipients.values());
        recipientCount.textContent = `${recipients.length} ausgewählt`;

        if (recipients.length === 0) {
            recipientsWrap.innerHTML = '';
            recipientsEmpty.hidden = false;
            updateSendState();
            return;
        }

        recipientsEmpty.hidden = true;
        recipientsWrap.innerHTML = recipients.map((user) => {
            const uid = escapeHtml(user.uid);
            return `
                <button type="button" class="agmail-user is-selected" data-role="recipient-remove" data-uid="${uid}">
                    <span class="agmail-user__name">${escapeHtml(user.name)}</span>
                    <span class="agmail-user__meta">${escapeHtml(formatUserMeta(user))}</span>
                </button>
            `;
        }).join('');

        Array.from(recipientsWrap.querySelectorAll('[data-role="recipient-remove"]')).forEach((button) => {
            button.addEventListener('click', () => {
                const uid = String(button.dataset.uid || '');
                selectedRecipients.delete(uid);
                renderRecipients();
                renderCurrentResults();
            });
        });

        updateSendState();
    };

    const toggleRecipient = (user) => {
        const key = String(user.uid);

        if (selectedRecipients.has(key)) {
            selectedRecipients.delete(key);
        } else {
            selectedRecipients.set(key, {
                uid: Number(user.uid),
                name: String(user.name || ''),
                room: String(user.room || '-'),
                turm: String(user.turm || ''),
                pid: Number(user.pid || 0),
                resident_status: String(user.resident_status || ''),
                plaetze: String(user.plaetze || ''),
                extra_status: String(user.extra_status || ''),
            });
        }

        renderRecipients();
        renderCurrentResults();
    };

    const renderResultCards = (users) => {
        resultsWrap.innerHTML = users.map((user) => {
            const uid = String(user.uid);
            const isSelected = selectedRecipients.has(uid);
            return `
                <button type="button" class="agmail-user${isSelected ? ' is-selected' : ''}" data-role="result-user" data-uid="${escapeHtml(uid)}">
                    <span class="agmail-user__name">${escapeHtml(user.name)}</span>
                    <span class="agmail-user__meta">${escapeHtml(formatUserMeta(user))}</span>
                </button>
            `;
        }).join('');

        Array.from(resultsWrap.querySelectorAll('[data-role="result-user"]')).forEach((button) => {
            button.addEventListener('click', () => {
                const uid = String(button.dataset.uid || '');
                const source = getCurrentModeUsers();

                if (!Array.isArray(source)) {
                    return;
                }

                const user = source.find((entry) => String(entry.uid) === uid);
                if (!user) {
                    return;
                }

                toggleRecipient(user);
            });
        });
    };

    const renderCurrentResults = () => {
        if (currentMode === 'regular') {
            if (userSearch.value.trim() === '') {
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = true;
                return;
            }

            if (regularResults.length === 0) {
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = false;
                resultsEmpty.textContent = 'Keine Treffer.';
                return;
            }

            resultsEmpty.hidden = true;
            renderResultCards(regularResults);
            return;
        }

        const users = getCurrentModeUsers();

        if (users === null) {
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent = getModeUnavailableMessage();
            return;
        }

        if (!Array.isArray(users) || users.length === 0) {
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent =
                currentMode === 'bike_spot' ? 'Keine Stellplatznutzer gefunden.' :
                currentMode === 'bike_queue' ? 'Keine Personen auf der Warteschlange gefunden.' :
                'Keine Fitness-Nutzer gefunden.';
            return;
        }

        resultsEmpty.hidden = true;
        renderResultCards(users);
    };

    const performSearch = () => {
        if (currentMode !== 'regular') {
            return;
        }

        const query = userSearch.value.trim();
        const requestId = ++searchRequestCounter;

        if (query === '') {
            regularResults = [];
            renderCurrentResults();
            return;
        }

        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'search',
                query
            }),
            credentials: 'same-origin'
        })
        .then((response) => response.text())
        .then((text) => {
            if (requestId !== searchRequestCounter || currentMode !== 'regular') {
                return null;
            }

            try {
                return JSON.parse(text);
            } catch (error) {
                regularResults = [];
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = false;
                resultsEmpty.textContent = 'Suche fehlgeschlagen.';
                return null;
            }
        })
        .then((data) => {
            if (!data || requestId !== searchRequestCounter || currentMode !== 'regular') {
                return;
            }

            regularResults = Array.isArray(data.users) ? data.users : [];
            renderCurrentResults();
        })
        .catch(() => {
            if (requestId !== searchRequestCounter || currentMode !== 'regular') {
                return;
            }

            regularResults = [];
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent = 'Suche fehlgeschlagen.';
        });
    };

    const scheduleSearch = () => {
        window.clearTimeout(searchDebounceTimer);
        searchDebounceTimer = window.setTimeout(performSearch, 180);
    };

    const renderMode = () => {
        Array.from(modeBar.querySelectorAll('[data-mode]')).forEach((button) => {
            button.classList.toggle('is-active', button.dataset.mode === currentMode);
        });

        searchSection.hidden = currentMode !== 'regular';
        renderCurrentResults();
        renderRecipients();
        updateSendState();
    };

    Array.from(modeBar.querySelectorAll('[data-mode]')).forEach((button) => {
        button.addEventListener('click', () => {
            currentMode = String(button.dataset.mode || 'regular');
            renderMode();
        });
    });

    if (senderSelect) {
        senderSelect.addEventListener('change', () => {
            renderMode();
        });
    }

    userSearch.addEventListener('input', scheduleSearch);

    subjectField.addEventListener('input', updateSendState);
    messageField.addEventListener('input', updateSendState);

    sendButton.addEventListener('click', () => {
        const senderGroup = getCurrentSenderGroup();
        const subject = subjectField.value.trim();
        const message = messageField.value.trim();
        const uids = Array.from(selectedRecipients.keys()).map((uid) => Number(uid)).filter((uid) => uid > 0);

        if (!senderGroup || !subject || !message || uids.length === 0) {
            updateSendState();
            return;
        }

        sendButton.disabled = true;
        page.classList.add('is-sent');

        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'send',
                sender_group_id: Number(senderGroup.id),
                uids,
                subject,
                message
            }),
            credentials: 'same-origin'
        }).catch(() => {});

        window.setTimeout(() => {
            window.location.reload();
        }, 3000);
    });

    renderRecipients();
    renderMode();
})();
</script>
</body>
</html>
<?php
$conn->close();
?>