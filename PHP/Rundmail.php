<?php
// Rundmail: geführter Versand. Anzeige und POST verwenden dieselben Berechtigungen.
session_start();
ob_start();
require __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8');
$templateOutput = ob_get_clean();
ob_start();
$authenticated = auth($conn) && !empty($_SESSION['valid']);
ob_end_clean();
if (!$authenticated) {
    header('Location: denied.php');
    exit;
}

function rmEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rmAllowedAddresses(array $session, bool $hasAgMembership): array {
    $privileged = !empty($session['sprecher']) || !empty($session['Vorstand'])
        || !empty($session['TvK-Sprecher']) || !empty($session['NetzAG']);
    $allowed = [];
    foreach (['' => 'Both towers (WEH & TvK)', 'weh-' => 'WEH', 'tvk-' => 'TvK'] as $prefix => $scope) {
        foreach (['community' => 'Community', 'important' => 'Important', 'essential' => 'Essential', 'ags' => 'AG members'] as $type => $label) {
            if ($type === 'essential' && !$privileged) continue;
            if (in_array($type, ['important', 'ags'], true) && !$hasAgMembership) continue;
            if ($type === 'community') {
                if ($prefix === '' && !$hasAgMembership) continue;
                if ($prefix !== '' && !$privileged && ($session['turm'] ?? '') !== rtrim($prefix, '-')) continue;
            }
            $allowed[$prefix . $type] = ['scope' => $scope, 'type' => $type, 'label' => $scope . ' - ' . $label];
        }
    }
    if (!empty($session['Webmaster'])) {
        $allowed['webmaster'] = ['scope' => 'Debug', 'type' => 'webmaster', 'label' => 'Debug - Webmaster'];
    }
    return $allowed;
}

function rmCountLabel(array $option): string {
    if ($option['count'] === null) return 'Recipient count unavailable';
    if ($option['snapshot']) return $option['count'] . ' addresses (27 Nov 2026 snapshot)';
    return $option['count'] . ' recipients';
}

$turm = $_SESSION['turm'];
$uid = (int)$_SESSION['uid'];
$accent = $turm === 'tvk' ? '#FFA500' : '#11a50d';
$user_address = $_SESSION['username'] . '@' . $turm . '.rwth-aachen.de';
$hasAgMembership = false;
$replyOptions = ['user' => ['name' => 'Your address', 'mail' => $user_address]];
foreach ($ag_complete as $num => $data) {
    if (!empty($data['session']) && !empty($_SESSION[$data['session']])) {
        $hasAgMembership = true;
        if (!empty($data['mail'])) {
            $replyOptions[(string)$num] = ['name' => $data['name'], 'mail' => $data['mail']];
        }
    }
}
$addressOptions = rmAllowedAddresses($_SESSION, $hasAgMembership);
$descriptions = [
    'community' => 'Social events, invitations, buy/sell offers and other non-essential notices. Only residents who opted into Community receive these.',
    'important' => 'Relevant information for residents, such as planned maintenance or important organisational updates. Not everyone receives these.',
    'essential' => 'Only for information that every resident needs to know, such as urgent safety notices or critical disruptions. Residents cannot opt out of Essential.',
    'ags' => 'Information for the working groups covered by this list. Not a way to contact one specific AG.',
    'webmaster' => 'Internal test mailing list.'
];
$recipients = [];
foreach (['' => 'tuerme', 'weh-' => 'weh', 'tvk-' => 'tvk'] as $prefix => $domain) {
    foreach (['community', 'important', 'essential', 'ags'] as $type) {
        $recipients[$prefix . $type] = $type . '@' . $domain . '.rwth-aachen.de';
    }
}
$recipients['webmaster'] = 'webmaster@weh.rwth-aachen.de';
$wordpress_allowed = ['essential','tvk-essential','weh-essential','important','tvk-important','weh-important','webmaster'];

// Versandlimit und Sperre wie bisher.
$time = time() - 30 * 24 * 60 * 60;
$stmt = mysqli_prepare($conn, 'SELECT COUNT(id) FROM rundmails WHERE uid=? AND tstamp > ?');
mysqli_stmt_bind_param($stmt, 'ii', $uid, $time);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $count);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);
$stmt = mysqli_prepare($conn, 'SELECT uid FROM sperre WHERE uid = ? AND mail = 1 AND starttime < UNIX_TIMESTAMP() AND endtime > UNIX_TIMESTAMP()');
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$mailban_active = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);
$blocked = $mailban_active ? 'You are banned from sending mails!' : (($count >= 4 && !$hasAgMembership) ? 'You have already sent 4 circular mails in the past 30 days and have reached your limit.' : '');

if (empty($_SESSION['rundmail_csrf']) || (int)($_SESSION['rundmail_csrf_uid'] ?? 0) !== $uid) {
    $_SESSION['rundmail_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['rundmail_csrf_uid'] = $uid;
}
$error = '';
$flash = $_SESSION['rundmail_flash'] ?? null;
unset($_SESSION['rundmail_flash']);
if (!is_array($flash) || ($flash['uid'] ?? null) !== $uid) $flash = null;
$draft = ['address' => '', 'replyto' => '', 'titel' => '', 'body' => '', 'publish' => false];
$restoreDraft = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rundmail_send'])) {
    $token = $_POST['csrf'] ?? '';
    if ($blocked !== '') {
        $error = $blocked;
    } elseif (!is_string($token) || !hash_equals($_SESSION['rundmail_csrf'], $token)) {
        $error = 'This form is no longer valid. Please start again.';
    } else {
        foreach (['address', 'replyto', 'titel', 'body'] as $field) {
            $draft[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
        $draft['publish'] = !empty($_POST['publish_frontend']);
        $validSelections = isset($addressOptions[$draft['address']], $replyOptions[$draft['replyto']]);
        $restoreDraft = $validSelections && ($_POST['acknowledged'] ?? '') === '1';
        if (!$restoreDraft) {
            $error = 'Please confirm the introduction and select an authorised mailing list and reply address.';
        } elseif (trim($draft['titel']) === '' || trim($draft['body']) === '' || preg_match('/[\r\n]/', $draft['titel']) || preg_match_all('/./us', $draft['titel']) > 200) {
            $error = 'Please enter a valid subject (maximum 200 characters) and a message.';
        } else {
            // Bestehende Mailheader, Bcc, HTML-Nachricht und Footer bleiben erhalten.
            $reply = $replyOptions[$draft['replyto']];
            $replyName = $draft['replyto'] === 'user' ? $_SESSION['name'] : $reply['name'];
            $selectedReplyTo = $replyName . ' <' . $reply['mail'] . '>';
            $mailheader = 'Reply-To: ' . mb_encode_mimeheader($selectedReplyTo) . "\r\n";
            $mailheader .= 'From: ' . mb_encode_mimeheader($_SESSION['name']) . " <rundmail@weh.rwth-aachen.de>\r\n";
            $mailheader .= 'Bcc: ' . mb_encode_mimeheader($_SESSION['name']) . ' <' . $user_address . ">\r\n";
            $mailheader .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body = nl2br($draft['body']);
            $body .= "<br><br>--------------------------------------------------<br>This mail was sent using the Rundmail-Webpage of WEH Backend:<br><a href='https://backend.weh.rwth-aachen.de/Rundmail.php'>https://backend.weh.rwth-aachen.de/Rundmail.php</a><br><br>To receive only important or essential Rundmails, you can adjust your preferences here:<br><a href='https://backend.weh.rwth-aachen.de/Mail.php'>https://backend.weh.rwth-aachen.de/Mail.php</a>";
            if (mail($recipients[$draft['address']], $draft['titel'], $body, $mailheader)) {
                // Mail wurde angenommen: kein erneuter Versand durch Reload, auch bei Folgefehlern.
                $_SESSION['rundmail_csrf'] = bin2hex(random_bytes(32));
                $notice = 'Your circular mail has been sent.';
                $warning = false;
                $titel = remove_emojis($draft['titel']);
                $body = remove_emojis($draft['body']);
                try {
                    $stmt = mysqli_prepare($conn, 'INSERT INTO rundmails (uid, address, subject, tstamp, nachricht) VALUES (?,?,?,?,?)');
                    $time = time();
                    mysqli_stmt_bind_param($stmt, 'issis', $uid, $draft['address'], $titel, $time, $body);
                    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Archive insert failed');
                    mysqli_stmt_close($stmt);
                } catch (Throwable $e) {
                    error_log('Rundmail archive: ' . $e->getMessage());
                    $notice .= ' The archive entry could not be saved. Do not send the mail again; contact the webmaster.';
                    $warning = true;
                }
                if ($draft['publish'] && in_array($draft['address'], $wordpress_allowed, true)) {
                    try {
                        post2frontend($titel, $body);
                    } catch (Throwable $e) {
                        error_log('Rundmail frontend: ' . $e->getMessage());
                        $notice .= ' Publication on the public website failed.';
                        $warning = true;
                    }
                }
                $_SESSION['rundmail_flash'] = ['uid' => $uid, 'message' => $notice, 'warning' => $warning];
                header('Location: Rundmail.php', true, 303);
                exit;
            }
            $error = 'The mail could not be sent. Your draft has been preserved.';
        }
    }
}

// Die drei regulären Verteiler entsprechend den bekannten Exim-SELECTs zählen.
// AG/Webmaster: eindeutig aufgelöste Ziele aus dem gelieferten Aliasstand.
// Nicht als Live-Zahlen ausgeben: die Aliasdateien liegen auf dem Mailserver.
// Gezählt vor individuellen Weiterleitungen; Dubletten zwischen Gruppen entfernt.
// WEH: 11 Gruppenaliase, TvK: 9 Gruppenaliase; Türme expandiert auf beide.
$aliasSnapshotCounts = ['weh-ags' => 44, 'tvk-ags' => 28, 'ags' => 72, 'webmaster' => 1];
$counts = [];
if ($blocked === '' && $addressOptions) {
    try {
        $result = mysqli_query($conn, "SELECT turm, COUNT(*) AS essential, SUM(CASE WHEN mailsettings != 2 THEN 1 ELSE 0 END) AS important, SUM(CASE WHEN mailsettings = 0 THEN 1 ELSE 0 END) AS community FROM users WHERE mailisactive = 1 AND pid IN (11,12) GROUP BY turm");
        $counts = ['' => ['essential' => 0, 'important' => 0, 'community' => 0], 'weh-' => ['essential' => 0, 'important' => 0, 'community' => 0], 'tvk-' => ['essential' => 0, 'important' => 0, 'community' => 0]];
        while ($row = mysqli_fetch_assoc($result)) {
            foreach (['essential','important','community'] as $type) {
                $counts[''][$type] += (int)$row[$type];
                if (isset($counts[$row['turm'] . '-'])) $counts[$row['turm'] . '-'][$type] += (int)$row[$type];
            }
        }
        mysqli_free_result($result);
    } catch (Throwable $e) {
        $counts = [];
        error_log('Rundmail recipient counts: ' . $e->getMessage());
    }
    foreach ($addressOptions as $id => &$option) {
        $prefix = substr($id, 0, strlen($id) - strlen($option['type']));
        $type = $option['type'];
        $option['snapshot'] = array_key_exists($id, $aliasSnapshotCounts);
        $option['count'] = $option['snapshot'] ? $aliasSnapshotCounts[$id] : ($counts[$prefix][$type] ?? null);
        $option['countLabel'] = rmCountLabel($option);
        $option['description'] = $descriptions[$type];
    }
    unset($option);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="format-detection" content="telephone=no">
<title>Rundmail</title>
<link rel="stylesheet" href="WEH.css" media="screen">
<style>
#rmWizard { --rm-accent: <?= $accent ?>; width:min(94%, 1180px); margin:28px auto 0; color:#fff; font-size:17px; }
#rmWizard *, #rmWizard *::before, #rmWizard *::after { box-sizing:border-box; }
#rmWizard [hidden] { display:none !important; }
#rmWizard .rm-panel { background:#232323; border:1px solid #494949; border-radius:12px; padding:clamp(18px,3vw,34px); }
#rmWizard h1 { margin:0 0 18px; font-size:32px; text-align:center; }
#rmWizard h2 { margin:0 0 12px; font-size:25px; }
#rmWizard h3 { margin:0 0 10px; font-size:19px; }
#rmWizard p { margin:8px 0 16px; line-height:1.55; }
#rmWizard .rm-muted { color:#bdbdbd; font-size:15px; }
#rmWizard .rm-steps { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:18px; padding:0; list-style:none; }
#rmWizard .rm-steps li { display:flex; align-items:center; gap:9px; color:#aaa; border-bottom:3px solid #424242; padding:8px 4px 12px; font-size:16px; }
#rmWizard .rm-steps li span { display:grid; place-items:center; width:27px; height:27px; border:1px solid #666; border-radius:50%; flex:none; }
#rmWizard .rm-steps li.rm-current { color:white; border-color:var(--rm-accent); font-weight:bold; }
#rmWizard .rm-steps li.rm-current span, #rmWizard .rm-steps li.rm-done span { background:var(--rm-accent); border-color:var(--rm-accent); color:#101010; }
#rmWizard .rm-steps li.rm-done { border-color:var(--rm-accent); color:#ddd; }
#rmWizard .rm-links, #rmWizard .rm-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
#rmWizard .rm-links { margin:16px 0 24px; }
#rmWizard .rm-actions { justify-content:space-between; margin-top:24px; }
#rmWizard .rm-actions.rm-right { justify-content:flex-end; }
#rmWizard .rm-btn, #rmWizard .rm-link { display:inline-flex; align-items:center; justify-content:center; gap:8px; font:inherit; font-weight:bold; border:1px solid #666; border-radius:7px; color:white; background:#303030; text-decoration:none; cursor:pointer; padding:11px 19px; min-height:42px; transition:background .15s,border-color .15s; }
#rmWizard .rm-btn:hover, #rmWizard .rm-link:hover { border-color:var(--rm-accent); background:#383838; color:white; }
#rmWizard .rm-primary { background:var(--rm-accent); border-color:var(--rm-accent); color:#101010; }
#rmWizard .rm-primary:hover { background:var(--rm-accent); filter:brightness(1.15); color:#101010; }
#rmWizard .rm-btn:disabled { opacity:.4; cursor:not-allowed; filter:none; }
#rmWizard .rm-btn:focus-visible, #rmWizard .rm-link:focus-visible, #rmWizard .rm-choice:focus-visible { outline:2px solid var(--rm-accent); outline-offset:3px; }
#rmWizard .rm-info { border-left:4px solid var(--rm-accent); background:#292929; border-radius:5px; padding:14px 18px; margin:18px 0; }
#rmWizard .rm-check { display:flex; gap:11px; align-items:flex-start; cursor:pointer; line-height:1.45; }
#rmWizard input[type=checkbox] { width:20px; height:20px; flex:none; margin:2px 0 0; accent-color:var(--rm-accent); }
#rmWizard .rm-scopes { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
#rmWizard .rm-scope { background:#1b1b1b; border:1px solid #444; border-radius:9px; padding:13px; }
#rmWizard .rm-scope h3 { border-bottom:1px solid #484848; padding-bottom:10px; }
#rmWizard .rm-choice { display:flex; flex-direction:column; gap:6px; width:100%; min-height:105px; padding:12px; background:#2b2b2b; color:white; text-align:left; font:inherit; border:1px solid #545454; border-radius:7px; cursor:pointer; margin:8px 0 0; transition:background .15s,border-color .15s; }
#rmWizard .rm-choice:hover, #rmWizard .rm-choice[aria-pressed=true] { border-color:var(--rm-accent); background:#343434; box-shadow:inset 3px 0 var(--rm-accent); }
#rmWizard .rm-choice-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:6px; width:100%; }
#rmWizard .rm-choice strong { font-size:18px; }
#rmWizard .rm-count { font:inherit; font-weight:bold; font-size:14px; background:#191919; border:1px solid #646464; border-radius:5px; padding:4px 7px; }
#rmWizard .rm-choice-desc { color:#c8c8c8; font-size:14px; line-height:1.4; }
#rmWizard .rm-type-community { border-left:3px solid #777; }
#rmWizard .rm-type-important { border-left:3px solid #39883b; }
#rmWizard .rm-type-essential { border-left:3px solid #11a50d; }
#rmWizard .rm-type-ags { border-left:3px solid #6378c7; }
#rmWizard .rm-replies { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:12px; }
#rmWizard .rm-replies .rm-choice { min-height:76px; margin:0; overflow-wrap:anywhere; }
#rmWizard .rm-fields { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
#rmWizard .rm-field { display:flex; flex-direction:column; gap:7px; margin-bottom:16px; min-width:0; }
#rmWizard .rm-field label { font-weight:bold; }
#rmWizard .rm-field input, #rmWizard .rm-field select, #rmWizard .rm-field textarea { display:block; width:100%; min-width:0; max-width:100%; height:auto; font:inherit; color:white; background:#2d2d2d; border:1px solid #666; border-radius:6px; padding:11px 12px; margin:0; text-align:left; }
#rmWizard .rm-field textarea { min-height:280px; resize:vertical; line-height:1.5; }
#rmWizard .rm-field input:focus, #rmWizard .rm-field select:focus, #rmWizard .rm-field textarea:focus { outline:2px solid var(--rm-accent); outline-offset:1px; border-color:var(--rm-accent); }
#rmWizard .rm-summary { background:#191919; border:1px solid #4d4d4d; border-left:4px solid var(--rm-accent); border-radius:7px; padding:13px 15px; margin:0 0 18px; }
#rmWizard .rm-summary strong { font-size:20px; }
#rmWizard .rm-summary p { margin:6px 0 0; }
#rmWizard .rm-notice { padding:14px 18px; border:1px solid #4c9f49; border-radius:8px; background:#203b20; margin-bottom:18px; }
#rmWizard .rm-error { border-color:#c96c6c; background:#3a2323; }
#rmWizard .rm-publish { border:1px solid #827245; border-radius:7px; padding:12px; margin:0 0 18px; color:#f0d48b; }
@media (max-width:850px) { #rmWizard .rm-scopes { grid-template-columns:1fr 1fr; } }
@media (max-width:600px) { #rmWizard .rm-scopes, #rmWizard .rm-fields { grid-template-columns:1fr; gap:8px; } #rmWizard .rm-steps li { flex-direction:column; text-align:center; font-size:13px; gap:5px; } #rmWizard h1 { font-size:26px; } #rmWizard .rm-panel { padding:16px; } }
</style>
</head>
<body>
<?= $templateOutput ?>
<?php load_menu(); ?>
<main id="rmWizard">
    <h1>Send a circular mail</h1>
    <?php if ($flash): ?>
        <div class="rm-notice <?= $flash['warning'] ? 'rm-error' : '' ?>" role="status"><?= rmEscape($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="rm-notice rm-error" role="alert"><?= rmEscape($error) ?></div><?php endif; ?>
    <?php if ($blocked !== ''): ?>
        <div class="rm-panel"><p><?= rmEscape($blocked) ?></p><div class="rm-links"><a class="rm-link" href="AGs.php">Contact AGs</a><a class="rm-link" href="Individuen.php">Contact individuals</a><a class="rm-link" href="Mail.php">Mail settings</a></div></div>
    <?php elseif (!$addressOptions): ?>
        <div class="rm-panel"><p>No mailing lists are available to your account.</p><a class="rm-link" href="AGs.php">Contact AGs</a></div>
    <?php else: ?>
    <ol class="rm-steps" aria-label="Sending steps">
        <li class="rm-current" aria-current="step"><span>1</span> Introduction</li>
        <li><span>2</span> Recipients</li>
        <li><span>3</span> Reply-To</li>
        <li><span>4</span> Message</li>
    </ol>
    <form method="post" action="Rundmail.php" id="mail_form" name="mail-form" class="rm-panel">
        <input type="hidden" name="csrf" value="<?= rmEscape($_SESSION['rundmail_csrf']) ?>">
        <input type="hidden" name="rundmail_send" value="1">
        <input type="hidden" name="reload" value="1">
        <section data-step="1">
            <h2>Before you send a circular mail</h2>
            <p>A circular mail is a <strong>mass email to a mailing list in WEH, TvK or both towers</strong>, depending on the list you choose. It can reach many people at once.</p>
            <div class="rm-info"><strong>Do you only want to contact a working group or a specific person?</strong><p>Do not use a circular mail for private requests, network problems, booking questions or messages to a single AG. Use the appropriate contact page instead.</p>
                <div class="rm-links"><a class="rm-link" href="AGs.php">Contact AGs (e.g. NetzAG)</a><a class="rm-link" href="Individuen.php">Contact individuals</a></div>
            </div>
            <p>Residents choose whether to receive Community and Important messages. Essential messages are the mandatory minimum and reach all residents with an active eligible mail account. Please select the lowest suitable priority for your message.</p>
            <p class="rm-muted">You can manage your own subscriptions in <a href="Mail.php">Mail settings</a>.</p>
            <label class="rm-check"><input type="checkbox" id="rmAck" name="acknowledged" value="1" <?= $restoreDraft ? 'checked' : '' ?>><strong>I understand that a circular mail is sent to a mailing list, not to an individual AG or person.</strong></label>
            <div class="rm-actions rm-right"><button class="rm-btn rm-primary" id="rmContinue" type="button" disabled>Continue →</button></div>
        </section>
        <section data-step="2" hidden>
            <h2>Who should receive your message?</h2>
            <p>Select the appropriate tower and mailing list. For Community, Important and Essential, the recipient count shows how many eligible resident accounts currently receive this mailing list. Choose Essential only when everyone needs to receive the information.</p>
            <div class="rm-scopes">
            <?php foreach (['Both towers (WEH & TvK)', 'WEH', 'TvK', 'Debug'] as $scope): ?>
                <?php $scopeOptions = array_filter($addressOptions, static fn($option) => $option['scope'] === $scope); if (!$scopeOptions) continue; ?>
                <div class="rm-scope"><h3><?= rmEscape($scope) ?></h3>
                <?php foreach ($scopeOptions as $id => $option): ?>
                    <button class="rm-choice rm-type-<?= rmEscape($option['type']) ?>" type="button" data-address="<?= rmEscape($id) ?>" aria-pressed="false">
                        <span class="rm-choice-head"><strong><?= rmEscape($option['type'] === 'ags' ? 'AG members' : ucfirst($option['type'])) ?></strong><span class="rm-count"><?= rmEscape(rmCountLabel($option)) ?></span></span>
                        <span class="rm-choice-desc"><?= rmEscape($option['description']) ?></span>
                    </button>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <p class="rm-muted">Community, Important and Essential counts are calculated from current database subscriptions. AG and Webmaster counts are unique addresses from the mail server alias snapshot of 27 November 2026, before individual forwarding; they are not live counts. The sender's separate copy is not included.</p>
            <div class="rm-actions"><button class="rm-btn" type="button" data-back="1">← Back</button></div>
        </section>
        <section data-step="3" hidden>
            <h2>Where should replies go?</h2>
            <p>Choose the address that will receive replies to your circular mail. This does not change who receives the original message.</p>
            <div class="rm-replies">
            <?php foreach ($replyOptions as $id => $reply): ?>
                <button class="rm-choice" type="button" data-reply="<?= rmEscape($id) ?>" aria-pressed="false"><strong><?= rmEscape($reply['name']) ?></strong><span class="rm-choice-desc"><?= rmEscape($reply['mail']) ?></span></button>
            <?php endforeach; ?>
            </div>
            <div class="rm-actions"><button class="rm-btn" type="button" data-back="2">← Back</button></div>
        </section>
        <section data-step="4" hidden>
            <h2>Write your circular mail</h2>
            <div class="rm-fields">
                <div class="rm-field"><label for="address">Send to</label><select name="address" id="address" required><option value="" disabled <?= $draft['address'] === '' ? 'selected' : '' ?>>Select mailing list</option>
                <?php foreach ($addressOptions as $id => $option): ?><option value="<?= rmEscape($id) ?>" <?= $draft['address'] === $id ? 'selected' : '' ?>><?= rmEscape($option['label'] . ' (' . rmCountLabel($option) . ')') ?></option><?php endforeach; ?>
                </select></div>
                <div class="rm-field"><label for="replyto">Reply-To</label><select name="replyto" id="replyto" required><option value="" disabled <?= $draft['replyto'] === '' ? 'selected' : '' ?>>Select reply address</option>
                <?php foreach ($replyOptions as $id => $reply): ?><option value="<?= rmEscape($id) ?>" <?= $draft['replyto'] === (string)$id ? 'selected' : '' ?>><?= rmEscape($reply['name'] . ' (' . $reply['mail'] . ')') ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="rm-summary" aria-live="polite"><strong id="rmAudience"></strong><p id="rmAudienceDesc"></p><p class="rm-muted" id="rmReplySummary"></p></div>
            <div id="publish-wrap" class="rm-publish" hidden><label class="rm-check"><input type="checkbox" id="publish_frontend" name="publish_frontend" value="1" <?= $draft['publish'] ? 'checked' : '' ?>><span>Also publish this message on the <strong>public association website</strong>.</span></label></div>
            <div class="rm-field"><label for="titel">Subject</label><input type="text" name="titel" id="titel" maxlength="200" value="<?= rmEscape($draft['titel']) ?>" required></div>
            <div class="rm-field"><label for="body">Message</label><textarea name="body" id="body" rows="14" required><?= rmEscape($draft['body']) ?></textarea></div>
            <div class="rm-actions"><button class="rm-btn" type="button" data-back="3">← Back</button><button type="submit" class="rm-btn rm-primary" id="rmSend" disabled>Send circular mail</button></div>
        </section>
        <noscript><p>JavaScript is required to send a circular mail using this form.</p></noscript>
    </form>
    <script type="application/json" id="rmWizardData"><?= json_encode(['addresses' => $addressOptions, 'replies' => $replyOptions, 'restore' => $restoreDraft], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <script>
    (() => {
        const root = document.getElementById('rmWizard');
        const form = root.querySelector('#mail_form');
        const data = JSON.parse(root.querySelector('#rmWizardData').textContent);
        const ack = root.querySelector('#rmAck');
        const next = root.querySelector('#rmContinue');
        const address = root.querySelector('#address');
        const reply = root.querySelector('#replyto');
        const send = root.querySelector('#rmSend');
        const publish = root.querySelector('#publish_frontend');
        const publishWrap = root.querySelector('#publish-wrap');
        const sections = [...form.querySelectorAll('[data-step]')];
        const steps = [...root.querySelectorAll('.rm-steps li')];
        const has = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key);
        let current = 1;
        let sending = false;
        function showStep(step, focus = true) {
            current = step;
            sections.forEach(section => { section.hidden = Number(section.dataset.step) !== step; });
            steps.forEach((item, i) => {
                item.classList.toggle('rm-current', i + 1 === step);
                item.classList.toggle('rm-done', i + 1 < step);
                if (i + 1 === step) item.setAttribute('aria-current', 'step');
                else item.removeAttribute('aria-current');
            });
            send.disabled = step !== 4 || !ack.checked || !has(data.addresses, address.value) || !has(data.replies, reply.value) || sending;
            if (focus) {
                const heading = sections[step - 1].querySelector('h2');
                heading.tabIndex = -1;
                heading.focus({preventScroll:true});
                root.scrollIntoView({block:'start', behavior:'instant'});
            }
        }
        function syncSelections(resetPublish) {
            const option = data.addresses[address.value];
            const replyOption = data.replies[reply.value];
            form.querySelectorAll('[data-address]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.address === address.value)));
            form.querySelectorAll('[data-reply]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.reply === reply.value)));
            if (option) {
                const count = option.countLabel;
                root.querySelector('#rmAudience').textContent = `${option.label} · ${count}`;
                root.querySelector('#rmAudienceDesc').textContent = option.description;
                send.textContent = option.count !== null && !option.snapshot ? `Send to ${option.count} recipients` : 'Send circular mail';
            } else {
                root.querySelector('#rmAudience').textContent = 'Select a mailing list';
                root.querySelector('#rmAudienceDesc').textContent = '';
                send.textContent = 'Send circular mail';
            }
            root.querySelector('#rmReplySummary').textContent = replyOption ? `Replies go to ${replyOption.mail}.` : '';
            const eligible = option && ['essential','important','webmaster'].includes(option.type);
            publishWrap.hidden = !eligible;
            publish.disabled = !eligible;
            if (!eligible) publish.checked = false;
            else if (resetPublish) publish.checked = ['essential','webmaster'].includes(option.type);
            send.disabled = current !== 4 || !ack.checked || !has(data.addresses, address.value) || !has(data.replies, reply.value) || sending;
        }
        ack.addEventListener('change', () => { next.disabled = !ack.checked; syncSelections(false); });
        next.addEventListener('click', () => { if (ack.checked) showStep(2); });
        form.querySelectorAll('[data-address]').forEach(button => button.addEventListener('click', () => {
            if (!ack.checked || !has(data.addresses, button.dataset.address)) return;
            const changed = address.value !== button.dataset.address;
            address.value = button.dataset.address;
            syncSelections(changed);
            showStep(3);
        }));
        form.querySelectorAll('[data-reply]').forEach(button => button.addEventListener('click', () => {
            if (!ack.checked || !has(data.addresses, address.value) || !has(data.replies, button.dataset.reply)) return;
            reply.value = button.dataset.reply;
            syncSelections(false);
            showStep(4);
        }));
        form.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => showStep(Number(button.dataset.back))));
        address.addEventListener('change', () => syncSelections(true));
        reply.addEventListener('change', () => syncSelections(false));
        form.addEventListener('keydown', event => { if (event.key === 'Enter' && current !== 4 && event.target.tagName !== 'BUTTON') event.preventDefault(); });
        form.addEventListener('submit', event => {
            if (sending || current !== 4 || !ack.checked || !has(data.addresses, address.value) || !has(data.replies, reply.value)) { event.preventDefault(); return; }
            sending = true;
            send.disabled = true;
            send.textContent = 'Sending…';
        });
        next.disabled = !ack.checked;
        syncSelections(false);
        showStep(data.restore && ack.checked && has(data.addresses, address.value) && has(data.replies, reply.value) ? 4 : 1, false);
    })();
    </script>
    <?php endif; ?>
</main>
<?php
// Die bestehende 30-Tage-Übersicht inklusive Archivlink bleibt unverändert.
displayRundmails($conn);
$conn->close();

function post2frontend($titel, $body) {
  $config = json_decode(file_get_contents('/etc/credentials/config.json'), true);
  $mysql_rundmailphpconfig = $config['rundmailphp'];
  $conn = mysqli_connect(
      $mysql_rundmailphpconfig['host'],
      $mysql_rundmailphpconfig['user'],
      $mysql_rundmailphpconfig['password'],
      $mysql_rundmailphpconfig['database']
  );
  #mysqli_set_charset($conn,"utf8");
  if (!$conn) {
    throw new RuntimeException("Verbindung fehlgeschlagen: " . mysqli_connect_error());
  }
  $currenttime = time();
  $post_date = date('Y-m-d H:i:s', $currenttime);
  $post_date_gmt = gmdate('Y-m-d H:i:s', $currenttime);
  $post_name = strval($currenttime);

  $sql = "INSERT INTO wp_posts (post_date, post_date_gmt, post_content, post_title, post_name, post_modified, post_modified_gmt) VALUES (?, ?, ?, ?, ?, ?, ?)";
  $stmt = mysqli_prepare($conn, $sql);
  if ($stmt) {
      mysqli_stmt_bind_param($stmt, "sssssss", $post_date, $post_date_gmt, $body, $titel, $post_name, $post_date, $post_date_gmt);
      if (!mysqli_stmt_execute($stmt)) throw new RuntimeException("Frontend insert failed");
      mysqli_stmt_close($stmt);
  } else {
      throw new RuntimeException("Fehler bei der Vorbereitung der Abfrage: " . mysqli_error($conn));
  }
    mysqli_close($conn);
}

?>
</body>
</html>
