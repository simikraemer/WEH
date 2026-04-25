<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$AJAX_ACTION = $_GET['ajax'] ?? '';

function wm_json(array $data, int $status = 200): never {
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wm_allowed(): bool {
    return !empty($_SESSION["NetzAG"]) || !empty($_SESSION["Vorstand"]) || !empty($_SESSION["WEH-WaschAG"]);
}

function wm_parse_groups(?string $groups): array {
    if ($groups === null || trim($groups) === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', trim($groups, " \t\n\r\0\x0B,"));
    $ids = [];

    foreach ($parts as $part) {
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int)$part;
        }
    }

    return array_values(array_unique($ids));
}

function wm_read_json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    return is_array($data) ? $data : [];
}

function wm_format_turm_label(string $turm): string {
    if (function_exists('formatTurm')) {
        return formatTurm($turm);
    }

    return strtolower($turm) === 'tvk' ? 'TvK' : strtoupper($turm);
}

function wm_group_display_name(string $name): string {
    $name = trim($name);

    if (preg_match('/^TvK-Sprecher$/iu', $name)) {
        return 'Haussprecher';
    }

    if (preg_match('/^Datenschutz$/iu', $name)) {
        return 'Datenschutzbeauftragter';
    }

    $name = preg_replace('/^(WEH|TvK)-/iu', '', $name);

    if (preg_match('/^Datenschutz$/iu', $name)) {
        return 'Datenschutzbeauftragter';
    }

    return $name;
}

function wm_group_section(int $groupId, string $turm): string {
    if (in_array($groupId, [7, 8, 9, 66], true)) {
        return 'shared';
    }

    return strtolower($turm) === 'tvk' ? 'tvk' : 'weh';
}

function wm_group_accent(int $groupId, string $turm): string {
    if (wm_group_section($groupId, $turm) === 'shared') {
        return '#777';
    }

    return strtolower($turm) === 'tvk' ? '#c97a00' : '#11a50d';
}

function wm_group_accent_hover(int $groupId, string $turm): string {
    if (wm_group_section($groupId, $turm) === 'shared') {
        return '#999';
    }

    return strtolower($turm) === 'tvk' ? '#e08a00' : '#18c914';
}

function wm_get_waschconn_by_turm(string $turm): mysqli {
    global $waschconn, $tvkwaschconn;

    if ($turm === 'weh') {
        if (!isset($waschconn) || !($waschconn instanceof mysqli)) {
            throw new RuntimeException('WEH-Waschdatenbank ist nicht verbunden.');
        }

        return $waschconn;
    }

    if ($turm === 'tvk') {
        if (!isset($tvkwaschconn) || !($tvkwaschconn instanceof mysqli)) {
            throw new RuntimeException('TvK-Waschdatenbank ist nicht verbunden.');
        }

        return $tvkwaschconn;
    }

    throw new RuntimeException('Für Turm "' . $turm . '" ist keine Waschdatenbank definiert.');
}

function wm_user_display_name(array $u): string {
    $displayName = trim((string)($u['name'] ?? ''));

    if ($displayName === '') {
        $displayName = trim((string)($u['firstname'] ?? '') . ' ' . (string)($u['lastname'] ?? ''));
    }

    if ($displayName === '') {
        $displayName = (string)($u['username'] ?? '');
    }

    return $displayName;
}

function wm_user_room_label(array $u): string {
    $room = $u['room'] === null ? 0 : (int)$u['room'];
    $oldroom = $u['oldroom'] === null ? 0 : (int)$u['oldroom'];
    $displayRoom = $room > 0 ? $room : $oldroom;

    return $displayRoom > 0 ? str_pad((string)$displayRoom, 4, '0', STR_PAD_LEFT) : '----';
}

function wm_user_email(string $username, string $turm): string {
    $username = strtolower(trim($username));
    $turm = strtolower(trim($turm));

    if (!preg_match('/^[a-z0-9._%+\-]+$/i', $username)) {
        throw new RuntimeException('Ungültiger Username für Mailadresse: ' . $username);
    }

    if (!in_array($turm, ['weh', 'tvk'], true)) {
        throw new RuntimeException('Ungültiger Turm für Mailadresse: ' . $turm);
    }

    return $username . '@' . $turm . '.rwth-aachen.de';
}

function wm_build_relief_mail_body(int $amount): string {
    return implode("\n", [
        "Liebe Mitbewohner,",
        "",
        "der WEH-Vorstand möchte sich bei Ihnen für die großartige Arbeit bedanken, die Sie im letzten Jahr geleistet haben. Wir schätzen Mitglieder sehr, die sich Zeit nehmen, um zum Leben im WEH beizutragen.",
        "",
        "Als kleines Zeichen unserer Dankbarkeit haben Sie " . $amount . " Waschmarken erhalten. Unser Verein kann nur dann erfolgreich sein, wenn Mitglieder wie Sie ihre Zeit, ihre Kräfte und ihre Ideen einbringen.",
        "",
        "Vielen Dank für Ihr Engagement.",
        "",
        "Viele Grüße",
        "Euer Haussprecher",
        "",
        "------------------------------",
        "",
        "Dear member,",
        "",
        "The WEH Vorstand would like to thank you for the great work you have carried out over the past year. We greatly value members who take the time to contribute to life at WEH.",
        "",
        "As a token of appreciation for your hard work, you have received " . $amount . " laundry tokens. Our association can only thrive because members like you contribute their time, effort, and ideas.",
        "",
        "Thank you for your commitment.",
        "",
        "Best wishes",
        "Your housespeakers",
    ]);
}


function wm_send_relief_mail(array $selectedUsers, int $amount, bool $isTestRun): void {
    $from = 'vorstand@weh.rwth-aachen.de';
    $to = 'vorstand@weh.rwth-aachen.de';

    $liveSubject = 'Danke für Ihr Engagement / Thank you for your commitment';
    $subject = $isTestRun ? '[Test Run] ' . $liveSubject : $liveSubject;

    $headers = "From: " . $from . "\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $bcc = [];

    foreach ($selectedUsers as $user) {
        $email = wm_user_email((string)$user['username'], (string)$user['turm']);

        if (!$isTestRun) {
            $bcc[] = $email;
        }
    }

    if (!$isTestRun && count($bcc) > 0) {
        $headers .= "Bcc: " . implode(',', array_unique($bcc)) . "\r\n";
    }

    if ($isTestRun) {
        $previewAmount = 0;

        $lines = [
            "This is a test run.",
            "",
            "No laundry tokens were added to the database.",
            "No users were contacted via Bcc.",
            "",
            "Live mail subject would be:",
            $liveSubject,
            "",
            "Live mail body would be:",
            "------------------------",
            wm_build_relief_mail_body($previewAmount),
            "------------------------",
            "",
            "Selected users:",
        ];

        foreach ($selectedUsers as $user) {
            $lines[] = "- " . wm_user_display_name($user)
                . " (" . wm_format_turm_label((string)$user['turm'])
                . " " . wm_user_room_label($user)
                . ", " . wm_user_email((string)$user['username'], (string)$user['turm']) . ")";
        }

        $message = implode("\n", $lines);
    } else {
        $message = wm_build_relief_mail_body($amount);
    }

    if (!mail($to, $subject, $message, $headers)) {
        throw new RuntimeException('Mail konnte nicht versendet werden.');
    }
}

if ($AJAX_ACTION !== '') {
    ob_start();

    require_once('template.php');

    mysqli_set_charset($conn, 'utf8');

    if (isset($waschconn) && $waschconn instanceof mysqli) {
        mysqli_set_charset($waschconn, 'utf8');
    }

    if (isset($tvkwaschconn) && $tvkwaschconn instanceof mysqli) {
        mysqli_set_charset($tvkwaschconn, 'utf8');
    }

    if (!wm_allowed()) {
        wm_json(['ok' => false, 'error' => 'Zugriff verweigert.'], 403);
    }

    if ($AJAX_ACTION === 'data') {
        $ignoredGroupIds = [24, 27, 61, 62, 63, 55];

        $groups = [];
        $groupUsers = [];

        $groupSql = "
            SELECT g.id, g.name, g.turm, g.prio
            FROM `groups` g
            WHERE g.active = 1
              AND g.id NOT IN (24, 27, 61, 62, 63, 55)
            ORDER BY COALESCE(g.prio, 999999), g.name
        ";

        $groupResult = $conn->query($groupSql);
        if (!$groupResult) {
            wm_json(['ok' => false, 'error' => 'Gruppen konnten nicht geladen werden.'], 500);
        }

        while ($g = $groupResult->fetch_assoc()) {
            $gid = (int)$g['id'];
            $turm = strtolower(trim((string)$g['turm']));
            $section = wm_group_section($gid, $turm);
            $rawName = $g['name'] ?: ('Gruppe ' . $gid);

            $groups[$gid] = [
                'id' => $gid,
                'name' => wm_group_display_name($rawName),
                'rawName' => $rawName,
                'turm' => $turm,
                'turmLabel' => wm_format_turm_label($turm),
                'section' => $section,
                'accent' => wm_group_accent($gid, $turm),
                'accentHover' => wm_group_accent_hover($gid, $turm),
                'count' => 0,
            ];

            $groupUsers[$gid] = [];
        }

        $users = [];

        $userSql = "
            SELECT uid, room, oldroom, turm, name, firstname, lastname, username, `groups`
            FROM users
            WHERE turm IN ('weh', 'tvk')
              AND pid IN (11, 12)
            ORDER BY
                turm ASC,
                CASE
                    WHEN room IS NULL OR room = 0 THEN oldroom
                    ELSE room
                END ASC,
                lastname ASC,
                firstname ASC,
                name ASC
        ";

        $userResult = $conn->query($userSql);
        if (!$userResult) {
            wm_json(['ok' => false, 'error' => 'User konnten nicht geladen werden.'], 500);
        }

        $nonActiveUsers = [];

        while ($u = $userResult->fetch_assoc()) {
            $uid = (int)$u['uid'];
            $turm = strtolower(trim((string)$u['turm']));
            $turmLabel = wm_format_turm_label($turm);

            $room = $u['room'] === null ? 0 : (int)$u['room'];
            $oldroom = $u['oldroom'] === null ? 0 : (int)$u['oldroom'];
            $displayRoom = $room > 0 ? $room : $oldroom;
            $roomLabel = $displayRoom > 0 ? str_pad((string)$displayRoom, 4, '0', STR_PAD_LEFT) : '----';

            $userGroupIds = array_values(array_diff(wm_parse_groups($u['groups'] ?? ''), $ignoredGroupIds));

            $displayName = wm_user_display_name($u);

            $users[$uid] = [
                'uid' => $uid,
                'name' => $displayName,
                'room' => $displayRoom,
                'roomLabel' => $roomLabel,
                'turm' => $turm,
                'turmLabel' => $turmLabel,
                'groups' => $userGroupIds,
            ];

            $isInAnyShownAg = false;

            foreach ($userGroupIds as $gid) {
                if (isset($groups[$gid])) {
                    $groupUsers[$gid][] = $uid;
                    $groups[$gid]['count']++;
                    $isInAnyShownAg = true;
                }
            }

            if (!$isInAnyShownAg) {
                $nonActiveUsers[] = $uid;
            }
        }

        foreach ($groups as $gid => $group) {
            if ((int)$group['count'] < 1) {
                unset($groups[$gid], $groupUsers[$gid]);
            }
        }

        wm_json([
            'ok' => true,
            'groups' => array_values($groups),
            'groupUsers' => $groupUsers,
            'nonActiveUsers' => $nonActiveUsers,
            'users' => array_values($users),
            'limits' => [
                'minWaschmarken' => 0,
                'maxWaschmarken' => 50,
                'stepWaschmarken' => 5,
                'startWaschmarken' => 0,
            ],
        ]);
    }

    if ($AJAX_ACTION === 'transfer') {
        $input = wm_read_json_input();

        $uids = $input['uids'] ?? [];
        $amount = isset($input['waschmarken']) ? (int)$input['waschmarken'] : 0;
        $isTestRun = ($amount === 0);

        if (!is_array($uids)) {
            wm_json(['ok' => false, 'error' => 'Ungültige User-Auswahl.'], 400);
        }

        $uids = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $uids), static fn($v) => $v > 0)));

        if (count($uids) === 0) {
            wm_json(['ok' => false, 'error' => 'Keine User ausgewählt.'], 400);
        }

        if ($amount < 0 || $amount > 50 || $amount % 5 !== 0) {
            wm_json(['ok' => false, 'error' => 'Die Anzahl Waschmarken muss 0 oder zwischen 5 und 50 liegen und in 5er-Schritten gewählt werden.'], 400);
        }

        $uidList = implode(',', $uids);

        $checkSql = "
            SELECT uid, turm, username, room, oldroom, name, firstname, lastname
            FROM users
            WHERE turm IN ('weh', 'tvk')
              AND pid IN (11, 12)
              AND uid IN ($uidList)
        ";

        $checkResult = $conn->query($checkSql);
        if (!$checkResult) {
            wm_json(['ok' => false, 'error' => 'Prüfung der User fehlgeschlagen.'], 500);
        }

        $validUids = [];
        $selectedUsers = [];
        $uidsByTurm = [
            'weh' => [],
            'tvk' => [],
        ];

        while ($row = $checkResult->fetch_assoc()) {
            $uid = (int)$row['uid'];
            $turm = strtolower(trim((string)$row['turm']));

            $validUids[] = $uid;
            $selectedUsers[] = $row;

            if (!isset($uidsByTurm[$turm])) {
                wm_json(['ok' => false, 'error' => 'Nicht unterstützter Turm: ' . $turm], 400);
            }

            $uidsByTurm[$turm][] = $uid;
        }

        sort($validUids);
        sort($uids);

        if ($validUids !== $uids) {
            wm_json(['ok' => false, 'error' => 'Mindestens ein ausgewählter User ist nicht gültig.'], 400);
        }

        if ($isTestRun) {
            try {
                wm_send_relief_mail($selectedUsers, 0, true);

                wm_json([
                    'ok' => true,
                    'message' => 'Testlauf erfolgreich: Keine DB-Änderung, keine User-Mail. Testmail mit Mail-Vorschau an den Vorstand wurde versendet.',
                    'count' => count($selectedUsers),
                    'testRun' => true,
                ]);
            } catch (Throwable $e) {
                wm_json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        $startedTransactions = [];

        try {
            foreach ($uidsByTurm as $turm => $turmUids) {
                if (count($turmUids) === 0) {
                    continue;
                }

                $washDbConn = wm_get_waschconn_by_turm($turm);
                $washDbConn->begin_transaction();
                $startedTransactions[] = $washDbConn;
            }

            $time = time();
            $successCount = 0;

            foreach ($uidsByTurm as $turm => $turmUids) {
                if (count($turmUids) === 0) {
                    continue;
                }

                $washDbConn = wm_get_waschconn_by_turm($turm);

                $insertStmt = $washDbConn->prepare("
                    INSERT INTO transfers (nach_uid, von_uid, anzahl, time)
                    VALUES (?, -4, ?, ?)
                ");

                if (!$insertStmt) {
                    throw new RuntimeException('Transfer-Statement konnte nicht vorbereitet werden (' . $turm . '): ' . $washDbConn->error);
                }

                $updateStmt = $washDbConn->prepare("
                    UPDATE waschusers
                    SET waschmarken = waschmarken + ?
                    WHERE uid = ?
                ");

                if (!$updateStmt) {
                    throw new RuntimeException('Waschuser-Statement konnte nicht vorbereitet werden (' . $turm . '): ' . $washDbConn->error);
                }

                foreach ($turmUids as $uid) {
                    $insertStmt->bind_param('iii', $uid, $amount, $time);

                    if (!$insertStmt->execute()) {
                        throw new RuntimeException('Transfer konnte nicht gespeichert werden (' . $turm . '): ' . $insertStmt->error);
                    }

                    $updateStmt->bind_param('ii', $amount, $uid);

                    if (!$updateStmt->execute()) {
                        throw new RuntimeException('Waschmarken konnten nicht aktualisiert werden (' . $turm . '): ' . $updateStmt->error);
                    }

                    if ($updateStmt->affected_rows < 1) {
                        throw new RuntimeException('Für UID ' . $uid . ' existiert kein Eintrag in der Waschdatenbank (' . $turm . ').');
                    }

                    $successCount++;
                }

                $insertStmt->close();
                $updateStmt->close();
            }

            foreach ($startedTransactions as $transactionConn) {
                $transactionConn->commit();
            }

            wm_send_relief_mail($selectedUsers, $amount, false);

            wm_json([
                'ok' => true,
                'message' => $successCount . ' Usern wurden jeweils ' . $amount . ' Waschmarken hinzugefügt. Die Mail wurde per Bcc versendet.',
                'count' => $successCount,
                'testRun' => false,
            ]);
        } catch (Throwable $e) {
            foreach ($startedTransactions as $transactionConn) {
                $transactionConn->rollback();
            }

            wm_json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    wm_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 404);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Waschmarken verteilen</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>
<body>

<?php
require('template.php');
mysqli_set_charset($conn, 'utf8');

if (!auth($conn) || !wm_allowed()) {
    header('Location: denied.php');
    exit;
}

load_menu();
?>

<style>
.wm-page {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 18px 40px;
    color: #eee;
}

.wm-card {
    background: #151515;
    border: 1px solid #2b2b2b;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 18px;
}

.wm-title {
    margin: 0 0 6px;
    color: #fff;
    font-size: 1.5rem;
}

.wm-subtitle {
    margin: 0;
    color: #bdbdbd;
    font-size: 0.95rem;
}

.wm-section {
    margin-top: 28px;
}

.wm-section:first-child {
    margin-top: 0;
}

.wm-ag-section {
    --wm-section-accent: #11a50d;
    margin-top: 18px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-left: 5px solid var(--wm-section-accent);
    border-radius: 10px;
    background: linear-gradient(90deg, color-mix(in srgb, var(--wm-section-accent) 14%, transparent), transparent 55%);
}

.wm-ag-section:first-of-type {
    margin-top: 16px;
}

.wm-ag-section-title {
    margin: 0;
    color: #fff;
    font-size: 1.15rem;
    font-weight: 900;
}

.wm-ag-section-subtitle {
    margin: 4px 0 0;
    color: #aaa;
    font-size: 0.88rem;
}

.wm-button {
    background: #11a50d;
    color: #fff;
    border: 0;
    border-radius: 7px;
    padding: 10px 14px;
    cursor: pointer;
    font-weight: 700;
}

.wm-button:hover {
    filter: brightness(1.1);
}

.wm-button:disabled {
    background: #444;
    cursor: not-allowed;
    color: #999;
}

.wm-button-secondary {
    background: #252525;
    border: 1px solid #3a3a3a;
}

.wm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(285px, 1fr));
    gap: 12px;
    margin-top: 14px;
}

.wm-ag-card {
    --wm-accent: #11a50d;
    --wm-accent-hover: #18c914;
    background: #101010;
    border: 1px solid #303030;
    border-radius: 10px;
    color: #fff;
    display: grid;
    grid-template-columns: 2fr 1fr;
    overflow: hidden;
    min-height: 86px;
}

.wm-ag-card:hover {
    border-color: var(--wm-accent);
}

.wm-ag-card.is-full {
    border: 2px solid var(--wm-accent);
}

.wm-ag-card.is-partial {
    border: 2px dashed var(--wm-accent);
}

.wm-ag-main {
    padding: 14px;
    cursor: pointer;
    text-align: left;
    background: transparent;
    border: 0;
    color: inherit;
}

.wm-ag-main:hover {
    background: #151515;
}

.wm-ag-add {
    background: var(--wm-accent);
    border: 0;
    border-left: 1px solid #303030;
    color: #fff;
    cursor: pointer;
    font-weight: 900;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 12px;
}

.wm-ag-add:hover {
    background: var(--wm-accent-hover);
}

.wm-ag-card-name {
    font-weight: 800;
    margin-bottom: 7px;
    font-size: 1.03rem;
}

.wm-ag-card-count {
    color: #aaa;
    font-size: 0.9rem;
}

.wm-selected-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.wm-selected-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 8px;
    margin-top: 14px;
}

.wm-user-chip {
    background: #0f0f0f;
    border: 1px solid #303030;
    border-radius: 8px;
    padding: 11px 12px;
    cursor: pointer;
    transition: background .12s ease, border-color .12s ease, transform .12s ease;
}

.wm-user-chip:hover {
    background: #451616;
    border-color: #b93131;
    transform: translateY(-1px);
}

.wm-user-name {
    color: #fff;
    font-weight: 700;
}

.wm-user-meta {
    color: #aaa;
    font-size: 0.9rem;
    margin-top: 3px;
}

.wm-footer-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    margin-top: 34px;
}

.wm-amount-box {
    background: #0f0f0f;
    border: 1px solid #303030;
    border-radius: 14px;
    padding: 16px 22px;
    min-width: min(100%, 460px);
    text-align: center;
}

.wm-amount-label {
    color: #ddd;
    font-weight: 800;
    margin-bottom: 12px;
}

.wm-stepper {
    display: grid;
    grid-template-columns: 80px 1fr 80px;
    align-items: center;
    gap: 12px;
}

.wm-step-btn {
    background: #1f1f1f;
    color: #fff;
    border: 1px solid #3a3a3a;
    border-radius: 12px;
    height: 68px;
    cursor: pointer;
    font-size: 2.4rem;
    font-weight: 900;
    line-height: 1;
}

.wm-step-btn:hover {
    border-color: #11a50d;
    background: #172516;
}

.wm-step-btn:disabled {
    opacity: .35;
    cursor: not-allowed;
}

.wm-amount-value {
    color: #fff;
    font-size: 3.4rem;
    font-weight: 900;
    line-height: 1;
}

.wm-amount-value.is-test {
    font-size: 2.35rem;
    color: #ffcc66;
}

.wm-test-info {
    margin-top: 12px;
    color: #ccc;
    font-size: .9rem;
    line-height: 1.35;
    max-width: 460px;
}

.wm-submit-wrap {
    width: 100%;
    display: flex;
    justify-content: center;
}

.wm-submit {
    min-width: min(100%, 460px);
    font-size: 1.25rem;
    padding: 18px 28px;
    border-radius: 12px;
    box-shadow: 0 0 0 1px rgba(17, 165, 13, .35), 0 12px 28px rgba(0, 0, 0, .28);
}

.wm-status {
    margin-top: 4px;
    font-weight: 800;
    text-align: center;
}

.wm-status.ok {
    color: #49d845;
}

.wm-status.err {
    color: #ff7777;
}

.wm-empty {
    color: #aaa;
    padding: 12px 0;
}

.wm-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .75);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    padding: 20px;
}

.wm-modal-backdrop.is-open {
    display: flex;
}

.wm-modal {
    width: min(900px, 100%);
    max-height: 88vh;
    overflow: hidden;
    background: #151515;
    border: 1px solid #333;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
}

.wm-modal-head {
    padding: 16px;
    border-bottom: 1px solid #303030;
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 12px;
}

.wm-modal-title {
    margin: 0;
    color: #fff;
}

.wm-modal-body {
    padding: 16px;
    overflow: auto;
}

.wm-modal-actions {
    padding: 16px;
    border-top: 1px solid #303030;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.wm-modal-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.wm-modal-search {
    min-width: min(100%, 340px);
    flex: 1;
    background: #0d0d0d;
    color: #fff;
    border: 1px solid #333;
    border-radius: 7px;
    padding: 10px 12px;
    outline: none;
}

.wm-modal-search:focus {
    border-color: #11a50d;
}

.wm-modal-user {
    display: grid;
    grid-template-columns: 28px 1fr 110px;
    gap: 10px;
    align-items: center;
    padding: 10px 6px;
    border-bottom: 1px solid #252525;
    cursor: pointer;
}

.wm-modal-user:hover {
    background: #101010;
}

.wm-small {
    color: #aaa;
    font-size: 0.85rem;
}

.wm-close {
    background: transparent;
    color: #ddd;
    border: 0;
    font-size: 1.4rem;
    cursor: pointer;
}

@supports not (background: color-mix(in srgb, red 10%, transparent)) {
    .wm-ag-section {
        background: #111;
    }
}

@media (max-width: 620px) {
    .wm-ag-card {
        grid-template-columns: 1fr;
    }

    .wm-ag-add {
        min-height: 54px;
        border-left: 0;
        border-top: 1px solid #303030;
    }

    .wm-stepper {
        grid-template-columns: 64px 1fr 64px;
    }

    .wm-step-btn {
        height: 60px;
    }

    .wm-amount-value {
        font-size: 2.7rem;
    }

    .wm-amount-value.is-test {
        font-size: 2rem;
    }
}
</style>

<div class="wm-page">
    <div class="wm-card">
        <div class="wm-section">
            <h1 class="wm-title">Waschmarken verteilen</h1>
            <p class="wm-subtitle">
                Links öffnet die AG-Liste. Rechts werden direkt alle User der AG hinzugefügt.
            </p>

            <div id="wmAgSections"></div>
        </div>

        <div class="wm-section">
            <div class="wm-selected-header">
                <div>
                    <h2 class="wm-title">Ausgewählte User</h2>
                    <p class="wm-subtitle"><span id="wmSelectedCount">0</span> User ausgewählt · Klick auf User entfernt ihn wieder</p>
                </div>
            </div>

            <div id="wmSelectedList" class="wm-selected-list"></div>
        </div>
    </div>

    <div class="wm-footer-action">
        <div class="wm-amount-box">
            <div class="wm-amount-label">Anzahl Waschmarken pro User</div>

            <div class="wm-stepper">
                <button type="button" class="wm-step-btn" id="wmAmountMinus">−</button>
                <div>
                    <div class="wm-amount-value" id="wmAmountValue">Testlauf</div>
                </div>
                <button type="button" class="wm-step-btn" id="wmAmountPlus">+</button>
            </div>

            <div class="wm-test-info" id="wmTestInfo"></div>
        </div>

        <div class="wm-submit-wrap">
            <button type="button" class="wm-button wm-submit" id="wmSubmit" disabled>Testlauf senden</button>
        </div>

        <div id="wmStatus" class="wm-status"></div>
    </div>
</div>

<div class="wm-modal-backdrop" id="wmModalBackdrop">
    <div class="wm-modal">
        <div class="wm-modal-head">
            <div>
                <h2 class="wm-modal-title" id="wmModalTitle">User auswählen</h2>
                <div class="wm-small" id="wmModalInfo"></div>
            </div>
            <button type="button" class="wm-close" id="wmModalClose">×</button>
        </div>

        <div class="wm-modal-body">
            <div class="wm-modal-toolbar">
                <input type="search" id="wmModalSearch" class="wm-modal-search" placeholder="User suchen...">
                <button type="button" class="wm-button wm-button-secondary" id="wmModalSelectAll">Alle auswählen</button>
                <button type="button" class="wm-button wm-button-secondary" id="wmModalSelectNone">Alle abwählen</button>
            </div>

            <div id="wmModalUsers" style="margin-top:14px;"></div>
        </div>

        <div class="wm-modal-actions">
            <button type="button" class="wm-button wm-button-secondary" id="wmModalCancel">Abbrechen</button>
            <button type="button" class="wm-button" id="wmModalAdd">Auswahl übernehmen</button>
        </div>
    </div>
</div>

<script>
(() => {
    const state = {
        users: new Map(),
        groups: [],
        groupUsers: new Map(),
        nonActiveUsers: [],
        selected: new Set(),
        modalUserIds: [],
        modalChecked: new Set(),
        amount: 0,
        minAmount: 0,
        maxAmount: 50,
        stepAmount: 5
    };

    const els = {
        agSections: document.getElementById('wmAgSections'),
        selectedList: document.getElementById('wmSelectedList'),
        selectedCount: document.getElementById('wmSelectedCount'),
        amountValue: document.getElementById('wmAmountValue'),
        amountMinus: document.getElementById('wmAmountMinus'),
        amountPlus: document.getElementById('wmAmountPlus'),
        testInfo: document.getElementById('wmTestInfo'),
        submit: document.getElementById('wmSubmit'),
        status: document.getElementById('wmStatus'),

        modalBackdrop: document.getElementById('wmModalBackdrop'),
        modalTitle: document.getElementById('wmModalTitle'),
        modalInfo: document.getElementById('wmModalInfo'),
        modalUsers: document.getElementById('wmModalUsers'),
        modalSearch: document.getElementById('wmModalSearch'),
        modalClose: document.getElementById('wmModalClose'),
        modalCancel: document.getElementById('wmModalCancel'),
        modalAdd: document.getElementById('wmModalAdd'),
        modalSelectAll: document.getElementById('wmModalSelectAll'),
        modalSelectNone: document.getElementById('wmModalSelectNone')
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function userLocation(user) {
        return `${user.turmLabel} ${user.roomLabel}`;
    }

    function setStatus(message, type = '') {
        els.status.textContent = message || '';
        els.status.className = 'wm-status' + (type ? ' ' + type : '');
    }

    function updateAmount() {
        state.amount = Math.max(state.minAmount, Math.min(state.maxAmount, state.amount));
        state.amount = Math.round(state.amount / state.stepAmount) * state.stepAmount;

        if (state.amount === 0) {
            els.amountValue.textContent = 'Testlauf';
            els.amountValue.classList.add('is-test');
            els.testInfo.innerHTML = `
                Es werden keine Waschmarken gebucht und keine User angeschrieben.<br>
                Es geht nur eine Testmail an den Vorstand mit der ausgewählten Userliste und einer Vorschau der echten Mail.<br>
                Ab 5 Waschmarken erhalten die ausgewählten User die Mail auch per BCC und die Waschmarken werden eingetragen.
            `;
            els.submit.textContent = 'Testlauf senden';
        } else {
            els.amountValue.textContent = String(state.amount);
            els.amountValue.classList.remove('is-test');
            els.testInfo.textContent = 'Live-Modus: Die Waschmarken werden in der passenden Waschdatenbank gebucht und die ausgewählten User werden per Bcc informiert.';
            els.submit.textContent = 'Waschmarken ausgeben';
        }

        els.amountMinus.disabled = state.amount <= state.minAmount;
        els.amountPlus.disabled = state.amount >= state.maxAmount;

        updateSubmitState();
    }

    function updateSubmitState() {
        const amountOk = state.amount >= state.minAmount && state.amount <= state.maxAmount && state.amount % state.stepAmount === 0;
        els.submit.disabled = state.selected.size === 0 || !amountOk;
    }

    function groupSelectionClass(userIds) {
        if (userIds.length === 0) {
            return '';
        }

        const selectedCount = userIds.filter(uid => state.selected.has(uid)).length;

        if (selectedCount === 0) {
            return '';
        }

        return selectedCount === userIds.length ? 'is-full' : 'is-partial';
    }

    function groupCard(group) {
        const userIds = state.groupUsers.get(String(group.id)) || [];
        const selectedInGroup = userIds.filter(uid => state.selected.has(uid)).length;
        const selectionClass = groupSelectionClass(userIds);

        return `
            <div class="wm-ag-card ${selectionClass}" style="--wm-accent:${escapeHtml(group.accent)};--wm-accent-hover:${escapeHtml(group.accentHover)};" data-group-id="${group.id}">
                <button type="button" class="wm-ag-main" data-open-group="${group.id}">
                    <div class="wm-ag-card-name">${escapeHtml(group.name)}</div>
                    <div class="wm-ag-card-count">${group.count} User · ${selectedInGroup} ausgewählt</div>
                </button>
                <button type="button" class="wm-ag-add" data-add-group="${group.id}">SELECT<br>ALL</button>
            </div>
        `;
    }

    function renderSection(sectionKey, title, subtitle, accent, groupsHtml) {
        if (!groupsHtml.length) {
            return '';
        }

        return `
            <section class="wm-ag-section" data-section="${escapeHtml(sectionKey)}" style="--wm-section-accent:${escapeHtml(accent)};">
                <h2 class="wm-ag-section-title">${escapeHtml(title)}</h2>
                <p class="wm-ag-section-subtitle">${escapeHtml(subtitle)}</p>
                <div class="wm-grid">${groupsHtml.join('')}</div>
            </section>
        `;
    }

    function renderAgGrid() {
        const wehGroups = [];
        const tvkGroups = [];
        const sharedGroups = [];

        for (const group of state.groups) {
            if (group.section === 'shared') {
                sharedGroups.push(groupCard(group));
            } else if (group.section === 'tvk') {
                tvkGroups.push(groupCard(group));
            } else {
                wehGroups.push(groupCard(group));
            }
        }

        if (state.nonActiveUsers.length > 0) {
            const selectedInactive = state.nonActiveUsers.filter(uid => state.selected.has(uid)).length;
            const selectionClass = groupSelectionClass(state.nonActiveUsers);

            sharedGroups.push(`
                <div class="wm-ag-card ${selectionClass}" style="--wm-accent:#777;--wm-accent-hover:#999;" data-non-active="1">
                    <button type="button" class="wm-ag-main" data-open-non-active="1">
                        <div class="wm-ag-card-name">Nicht-Aktive</div>
                        <div class="wm-ag-card-count">${state.nonActiveUsers.length} User · ${selectedInactive} ausgewählt</div>
                    </button>
                    <button type="button" class="wm-ag-add" data-add-non-active="1">SELECT<br>ALL</button>
                </div>
            `);
        }

        const sections = [
            renderSection('weh', 'WEH', 'Gruppen und AGs aus dem WEH.', '#11a50d', wehGroups),
            renderSection('tvk', 'TvK', 'Gruppen und AGs aus dem TvK.', '#c97a00', tvkGroups),
            renderSection('shared', 'Hausübergreifend & Rest', 'Gemeinsame Rollen, Sondergruppen und User ohne aktive AG-Zuordnung.', '#777', sharedGroups),
        ].join('');

        els.agSections.innerHTML = sections || '<div class="wm-empty">Keine AG mit Usern gefunden.</div>';
    }

    function renderSelected() {
        els.selectedCount.textContent = String(state.selected.size);

        if (state.selected.size === 0) {
            els.selectedList.innerHTML = '<div class="wm-empty">Noch keine User ausgewählt.</div>';
            updateSubmitState();
            renderAgGrid();
            return;
        }

        const selectedUsers = [...state.selected]
            .map(uid => state.users.get(uid))
            .filter(Boolean)
            .sort((a, b) => {
                const turmCompare = a.turmLabel.localeCompare(b.turmLabel, 'de');

                if (turmCompare !== 0) {
                    return turmCompare;
                }

                const roomA = Number.parseInt(a.room || '999999', 10);
                const roomB = Number.parseInt(b.room || '999999', 10);

                if (roomA !== roomB) {
                    return roomA - roomB;
                }

                return a.name.localeCompare(b.name, 'de');
            });

        els.selectedList.innerHTML = selectedUsers.map(user => `
            <div class="wm-user-chip" data-remove-uid="${user.uid}" title="Klicken zum Entfernen">
                <div class="wm-user-name">${escapeHtml(user.name)}</div>
                <div class="wm-user-meta">${escapeHtml(userLocation(user))}</div>
            </div>
        `).join('');

        updateSubmitState();
        renderAgGrid();
    }

    function addUsers(userIds) {
        for (const uid of userIds) {
            if (state.users.has(uid)) {
                state.selected.add(uid);
            }
        }

        setStatus('');
        renderSelected();
    }

    function syncModalSelectionToMainSelection() {
        for (const uid of state.modalUserIds) {
            state.selected.delete(uid);
        }

        for (const uid of state.modalChecked) {
            if (state.users.has(uid)) {
                state.selected.add(uid);
            }
        }

        setStatus('');
        renderSelected();
    }

    function openModal(title, userIds) {
        state.modalUserIds = [...new Set(userIds)].filter(uid => state.users.has(uid));
        state.modalChecked = new Set(state.modalUserIds.filter(uid => state.selected.has(uid)));

        els.modalSearch.value = '';
        els.modalTitle.textContent = title;
        els.modalBackdrop.classList.add('is-open');

        renderModalUsers();
    }

    function closeModal() {
        els.modalBackdrop.classList.remove('is-open');
        state.modalUserIds = [];
        state.modalChecked.clear();
    }

    function renderModalUsers() {
        const query = els.modalSearch.value.trim().toLowerCase();

        const users = state.modalUserIds
            .map(uid => state.users.get(uid))
            .filter(Boolean)
            .filter(user => {
                if (!query) {
                    return true;
                }

                return [
                    user.name,
                    user.turmLabel,
                    user.roomLabel
                ].join(' ').toLowerCase().includes(query);
            })
            .sort((a, b) => {
                const turmCompare = a.turmLabel.localeCompare(b.turmLabel, 'de');

                if (turmCompare !== 0) {
                    return turmCompare;
                }

                const roomA = Number.parseInt(a.room || '999999', 10);
                const roomB = Number.parseInt(b.room || '999999', 10);

                if (roomA !== roomB) {
                    return roomA - roomB;
                }

                return a.name.localeCompare(b.name, 'de');
            });

        els.modalInfo.textContent = `${state.modalChecked.size} von ${state.modalUserIds.length} Usern ausgewählt`;

        if (users.length === 0) {
            els.modalUsers.innerHTML = '<div class="wm-empty">Keine User gefunden.</div>';
            return;
        }

        els.modalUsers.innerHTML = users.map(user => `
            <label class="wm-modal-user">
                <input type="checkbox" data-modal-uid="${user.uid}" ${state.modalChecked.has(user.uid) ? 'checked' : ''}>
                <span class="wm-user-name">${escapeHtml(user.name)}</span>
                <span class="wm-small">${escapeHtml(userLocation(user))}</span>
            </label>
        `).join('');
    }

    async function loadData() {
        setStatus('Lade Daten...');

        const response = await fetch('Waschmarken.php?ajax=data', {
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'Daten konnten nicht geladen werden.');
        }

        state.minAmount = Number(data.limits?.minWaschmarken ?? 0);
        state.maxAmount = Number(data.limits?.maxWaschmarken || 50);
        state.stepAmount = Number(data.limits?.stepWaschmarken || 5);
        state.amount = Number(data.limits?.startWaschmarken ?? 0);

        state.users.clear();
        for (const user of data.users) {
            state.users.set(Number(user.uid), {
                ...user,
                uid: Number(user.uid),
                room: Number(user.room || 0),
                roomLabel: String(user.roomLabel || '----'),
                turm: String(user.turm || ''),
                turmLabel: String(user.turmLabel || '')
            });
        }

        state.groups = data.groups || [];
        state.groupUsers.clear();

        for (const [gid, uids] of Object.entries(data.groupUsers || {})) {
            state.groupUsers.set(String(gid), uids.map(Number));
        }

        state.nonActiveUsers = (data.nonActiveUsers || []).map(Number);

        setStatus('');
        updateAmount();
        renderAgGrid();
        renderSelected();
    }

    async function submitTransfers() {
        if (state.selected.size === 0) {
            setStatus('Bitte mindestens einen User auswählen.', 'err');
            return;
        }

        if (state.amount < state.minAmount || state.amount > state.maxAmount || state.amount % state.stepAmount !== 0) {
            setStatus('Bitte eine gültige Anzahl Waschmarken auswählen.', 'err');
            return;
        }

        const isTestRun = state.amount === 0;

        const confirmed = isTestRun
            ? confirm(`Testlauf für ${state.selected.size} User senden? Es werden keine DB-Änderungen gemacht und keine User angeschrieben.`)
            : confirm(`${state.selected.size} Usern jeweils ${state.amount} Waschmarken hinzufügen und per Bcc informieren?`);

        if (!confirmed) {
            return;
        }

        els.submit.disabled = true;
        setStatus(isTestRun ? 'Sende Testmail...' : 'Speichere Waschmarken und sende Mail...');

        try {
            const response = await fetch('Waschmarken.php?ajax=transfer', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    uids: [...state.selected],
                    waschmarken: state.amount
                })
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Speichern fehlgeschlagen.');
            }

            if (!isTestRun) {
                state.selected.clear();
                state.amount = 0;
            }

            updateAmount();
            renderSelected();
            setStatus(data.message || 'Erfolgreich abgeschlossen.', 'ok');
        } catch (error) {
            setStatus(error.message || 'Speichern fehlgeschlagen.', 'err');
            updateSubmitState();
        }
    }

    els.amountMinus.addEventListener('click', () => {
        state.amount -= state.stepAmount;
        setStatus('');
        updateAmount();
    });

    els.amountPlus.addEventListener('click', () => {
        state.amount += state.stepAmount;
        setStatus('');
        updateAmount();
    });

    els.agSections.addEventListener('click', event => {
        const addGroupButton = event.target.closest('[data-add-group]');
        if (addGroupButton) {
            const gid = addGroupButton.dataset.addGroup;
            addUsers(state.groupUsers.get(String(gid)) || []);
            return;
        }

        const openGroupButton = event.target.closest('[data-open-group]');
        if (openGroupButton) {
            const gid = openGroupButton.dataset.openGroup;
            const group = state.groups.find(g => String(g.id) === String(gid));
            openModal(group ? group.name : 'AG', state.groupUsers.get(String(gid)) || []);
            return;
        }

        const addNonActiveButton = event.target.closest('[data-add-non-active]');
        if (addNonActiveButton) {
            addUsers(state.nonActiveUsers);
            return;
        }

        const openNonActiveButton = event.target.closest('[data-open-non-active]');
        if (openNonActiveButton) {
            openModal('Nicht-Aktive', state.nonActiveUsers);
        }
    });

    els.selectedList.addEventListener('click', event => {
        const chip = event.target.closest('[data-remove-uid]');
        if (!chip) {
            return;
        }

        state.selected.delete(Number(chip.dataset.removeUid));
        setStatus('');
        renderSelected();
    });

    els.modalUsers.addEventListener('change', event => {
        const checkbox = event.target.closest('[data-modal-uid]');
        if (!checkbox) {
            return;
        }

        const uid = Number(checkbox.dataset.modalUid);

        if (checkbox.checked) {
            state.modalChecked.add(uid);
        } else {
            state.modalChecked.delete(uid);
        }

        els.modalInfo.textContent = `${state.modalChecked.size} von ${state.modalUserIds.length} Usern ausgewählt`;
    });

    els.modalSearch.addEventListener('input', renderModalUsers);

    els.modalSelectAll.addEventListener('click', () => {
        state.modalChecked = new Set(state.modalUserIds);
        renderModalUsers();
    });

    els.modalSelectNone.addEventListener('click', () => {
        state.modalChecked.clear();
        renderModalUsers();
    });

    els.modalAdd.addEventListener('click', () => {
        syncModalSelectionToMainSelection();
        closeModal();
    });

    els.modalClose.addEventListener('click', closeModal);
    els.modalCancel.addEventListener('click', closeModal);

    els.modalBackdrop.addEventListener('click', event => {
        if (event.target === els.modalBackdrop) {
            closeModal();
        }
    });

    els.submit.addEventListener('click', submitTransfers);

    loadData().catch(error => {
        setStatus(error.message || 'Daten konnten nicht geladen werden.', 'err');
    });
})();
</script>

<?php
$conn->close();
?>

</body>
</html>