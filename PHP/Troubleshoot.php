<?php
session_start();
// auth() in template.php emits a <title>; keep it out of JSON responses
// and avoid sending output before redirects/headers.
ob_start();
require __DIR__ . '/conn.php';
require __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8');
$authenticated = auth($conn);
ob_end_clean();

if (!$authenticated || empty($_SESSION['valid']) || empty($_SESSION['uid'])) {
    if (isset($_GET['search'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo '[]';
    } else {
        header('Location: denied.php');
    }
    exit;
}

$isAdmin = !empty($_SESSION['NetzAG']);
$ownUid = (int) $_SESSION['uid'];
$uid = $ownUid;
if ($isAdmin && isset($_POST['uid'])) {
    $candidate = filter_var($_POST['uid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($candidate !== false) $uid = $candidate;
}

function netlog_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (isset($_GET['search'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!$isAdmin) {
        http_response_code(403);
        echo '[]';
        exit;
    }
    $term = is_string($_GET['search']) ? trim($_GET['search']) : '';
    $results = [];
    if ($term !== '' && strlen($term) <= 200) {
        $like = '%' . $term . '%';
        $stmt = $conn->prepare('SELECT uid, name, username, room, oldroom, turm FROM users WHERE name LIKE ? OR username LIKE ? OR email LIKE ? OR aliase LIKE ? OR geburtsort LIKE ? OR ( ? REGEXP \'^[0-9]+$\' AND (room = ? OR uid = ? OR oldroom = ?)) ORDER BY name LIMIT 50');
        $stmt->bind_param('sssssssss', $like, $like, $like, $like, $like, $term, $term, $term, $term);
        $stmt->execute();
        $stmt->bind_result($id, $name, $username, $room, $oldroom, $turm);
        while ($stmt->fetch()) {
            $results[] = ['uid' => $id, 'name' => $name, 'username' => $username, 'room' => $room, 'oldroom' => $oldroom, 'turm' => $turm];
        }
        $stmt->close();
    }
    echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
header('Cache-Control: no-store');

function netlog_user($conn, $uid) {
    $stmt = $conn->prepare('SELECT username, turm, name, room, pid FROM users WHERE uid = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->bind_result($username, $turm, $name, $room, $pid);
    $user = $stmt->fetch() ? compact('username', 'turm', 'name', 'room', 'pid') : null;
    $stmt->close();
    return $user;
}

$user = netlog_user($conn, $uid);
if (!$user) {
    $uid = $ownUid;
    $user = netlog_user($conn, $uid);
}
if (!$user) {
    http_response_code(403);
    exit;
}

$legacyKey = '/etc/credentials/fijinotausprivatekey';
$radiusServers = [
    'untenroam' => ['host' => 'bragi.weh.rwth-aachen.de', 'port' => 22, 'login' => 'fijinotaus', 'key' => $legacyKey, 'format' => 'bragi'],
    'fijiroam' => ['host' => 'kvasir.tvk.rwth-aachen.de', 'port' => 22, 'login' => 'fijinotaus', 'key' => $legacyKey, 'format' => 'legacy'],
    'tuermeroam' => ['host' => 'radius1.weh.rwth-aachen.de', 'port' => 22022, 'login' => 'fijinotaus', 'key' => $legacyKey, 'format' => 'legacy'],
];
$dhcpServers = [
    'weh' => ['host' => 'dns2.weh.rwth-aachen.de', 'port' => 22022, 'login' => 'fijinotaus', 'key' => $legacyKey],
    'tvk' => $radiusServers['fijiroam'],
];
if (empty($_SESSION['netlog_csrf'])) $_SESSION['netlog_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['netlog_csrf'];
if ($isAdmin && isset($_POST['toggleAdminPanel']) && is_string($_POST['csrf'] ?? null) && hash_equals($csrf, $_POST['csrf'])) {
    $_SESSION['AdminPanelToggleState'] = ($_SESSION['AdminPanelToggleState'] ?? 'none') === 'none' ? 'block' : 'none';
}

// Only fixed hosts and fixed remote commands; arguments are shell-quoted individually.
// Return codes 0/1 mean success/no match for the legacy grep commands.
function netlog_ssh($server, $remote, $parse) {
    $command = ['/usr/bin/timeout', '10s', '/usr/bin/ssh', '-T', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5'];
    array_push($command, '-i', $server['key'], '-p', (string) $server['port'], $server['login'] . '@' . $server['host'], $remote);
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) return null;
    $rows = [];
    while (($line = fgets($pipes[1])) !== false) {
        $row = $parse(rtrim($line, "\r\n"));
        if ($row !== null) {
            $rows[] = $row;
        }
    }
    fclose($pipes[1]);
    $status = proc_close($process);
    if ($status !== 0 && $status !== 1) {
        error_log('NetworkLogs SSH failure: ' . $server['host'] . ' (exit ' . $status . ')');
        return null;
    }
    return array_reverse($rows);
}

function netlog_timestamp($raw, $now = null) {
    // Both dormitory networks use German local time. ISO timestamps with an
    // explicit offset are converted to that timezone before display.
    $zone = new DateTimeZone('Europe/Berlin');
    $now = $now === null ? new DateTimeImmutable('now', $zone) : $now->setTimezone($zone);
    if (preg_match('/^([A-Z][a-z]{2}) +(\d{1,2}) (\d{2}:\d{2}:\d{2})$/D', $raw, $m)) {
        // Traditional syslog has no year. The active/previous rotation is recent;
        // a December entry read in January belongs to the preceding year.
        $year = (int) $now->format('Y');
        $input = $year . ' ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        $date = DateTimeImmutable::createFromFormat('!Y M j H:i:s', $input, $zone);
        if ($date === false) return null;
        if ($date > $now->modify('+1 day')) {
            $input = ($year - 1) . ' ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
            $date = DateTimeImmutable::createFromFormat('!Y M j H:i:s', $input, $zone);
        }
    } else {
        try {
            $date = new DateTimeImmutable(str_replace(',', '.', $raw), $zone);
        } catch (Exception $e) {
            return null;
        }
    }
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $date->setTimezone($zone)->getTimestamp();
}

function netlog_display_time($timestamp) {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s');
}

function netlog_syslog($line, $daemon) {
    // Recognize traditional and ISO-8601 syslog timestamps. Do not expose host or raw message.
    $timestamp = '(?:[A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2}|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:\d{2})?)';
    if (!preg_match('/^(' . $timestamp . ')\s+(?:[^\s]+\s+)?(?:' . $daemon . ')(?:\[\d+\])?:\s*(.*)$/D', $line, $m)) return null;
    $time = netlog_timestamp($m[1]);
    if ($time === null) return null;
    return [$time, $m[2]];
}

function netlog_mac($value) {
    $value = strtolower(trim((string) $value));
    if (!preg_match('/^(?:[a-f0-9]{12}|(?:[a-f0-9]{2}:){5}[a-f0-9]{2}|(?:[a-f0-9]{2}-){5}[a-f0-9]{2})$/D', $value)) return null;
    return implode(':', str_split(str_replace([':', '-'], '', $value), 2));
}

function netlog_radius_row($line, $identities, $format, $selectedMac = null, $allowOtherIdentities = false) {
    $entry = netlog_syslog($line, 'radiusd|freeradius');
    if (!$entry) return null;
    [$time, $message] = $entry;
    $mac = null;
    if ($format === 'bragi') {
        if (!preg_match('/^LOGIN (OK|FAIL|FAILED|REJECT|INCORRECT) user=([^\s]+)(?:\s+|$)/D', $message, $m)) return null;
        if (preg_match('/(?:^|\s)mac=([a-f0-9:-]+)(?=\s|$)/i', $message, $mm)) $mac = netlog_mac($mm[1]);
    } else {
        if (!preg_match('/^(?:\(\d+\)\s+)?Login (OK|incorrect|failed|rejected)(?:\s+\([^\r\n]*?\))?:\s*\[([^\]\r\n]+)\]/D', $message, $m)) return null;
        // Only the structured client field, never a MAC mentioned elsewhere in the message.
        if (preg_match('/\bcli\s+([a-f0-9:-]+)(?=[\s)]|$)/i', $message, $mm)) $mac = netlog_mac($mm[1]);
    }
    if ($selectedMac !== null && $mac !== $selectedMac) return null;
    if (!($selectedMac !== null && $allowOtherIdentities) && !in_array($m[2], $identities, true)) return null;
    return ['time' => $time, 'event' => $m[1] === 'OK' ? 'Login successful' : 'Login failed', 'mac' => $mac, 'ip' => null, 'source' => ''];
}

function netlog_devices($conn, $uid) {
    $stmt = $conn->prepare('SELECT ip, hostname, mac1, mac2, mac3 FROM macauth WHERE uid = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->bind_result($ip, $hostname, $mac1, $mac2, $mac3);
    $devices = [];
    while ($stmt->fetch()) {
        foreach ([$mac1, $mac2, $mac3] as $raw) {
            $mac = netlog_mac($raw);
            if ($mac === null) continue;
            if (!isset($devices[$mac])) $devices[$mac] = ['ips' => [], 'names' => []];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $devices[$mac]['ips'][$ip] = true;
            $hostname = trim((string) $hostname);
            if ($hostname !== '') $devices[$mac]['names'][$hostname] = true;
        }
    }
    $stmt->close();
    uasort($devices, function ($a, $b) {
        $an = implode(', ', array_keys($a['names']));
        $bn = implode(', ', array_keys($b['names']));
        return ($an === '') <=> ($bn === '') ?: strnatcasecmp($an, $bn);
    });
    return $devices;
}

function netlog_device_label($mac, $devices) {
    if ($mac === null) return '—';
    if (!isset($devices[$mac])) return 'Unregistered device';
    $names = implode(', ', array_keys($devices[$mac]['names']));
    return $mac . ($names !== '' ? ' (' . $names . ')' : '');
}

function netlog_dhcp_row($line, $devices) {
    $entry = netlog_syslog($line, 'dhcpd');
    if (!$entry) return null;
    [$time, $message] = $entry;
    if (!preg_match('/^(DHCPDISCOVER|DHCPOFFER|DHCPREQUEST|DHCPACK|DHCPNAK|DHCPRELEASE|DHCPDECLINE)\b(.*)$/D', $message, $m)) return null;
    // Examples: DHCPACK on IP to MAC; DHCPREQUEST for IP (server) from MAC;
    // DHCPDISCOVER from MAC; DHCPRELEASE of IP from MAC.
    if (!preg_match('/^\s+(?:(?:on|for|of)\s+(\d{1,3}(?:\.\d{1,3}){3})(?:\s+\([^)]*\))?\s+)?(?:from|to)\s+((?:[a-f0-9]{2}:){5}[a-f0-9]{2})(?=\s|\)|$)/i', $m[2], $mm)) return null;
    $mac = netlog_mac($mm[2]);
    if (!isset($devices[$mac])) return null;
    $ip = filter_var($mm[1] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
    return ['time' => $time, 'event' => $m[1], 'mac' => $mac, 'ip' => $ip, 'source' => 'DHCP'];
}

function netlog_ip_status($row, $devices) {
    if ($row['source'] !== 'DHCP' || $row['ip'] === null) return ['', ''];
    $expected = $devices[$row['mac']]['ips'] ?? [];
    if (!$expected) return ['No IP registered', ''];
    if (isset($expected[$row['ip']])) return ['Matches registration', 'netlog-success'];
    return ['Mismatch · expected ' . implode(', ', array_keys($expected)), 'netlog-failure'];
}

function netlog_grep($needles) {
    // Read the previous rotation first, then the active log. Suppress file names
    // so both files retain the same line format for the existing parsers.
    $remote = 'grep -aihF';
    foreach (array_unique($needles) as $needle) $remote .= ' -e ' . escapeshellarg($needle);
    return 'if [ -e /var/log/syslog.1 ]; then '
        . $remote . ' -- /var/log/syslog.1 /var/log/syslog; else '
        . $remote . ' -- /var/log/syslog; fi';
}

// radius1 may log the same authentication twice (inner and outer login).
// For the same user, second and result, prefer entries containing a MAC.
// Keep standalone entries without a MAC and all entries containing a MAC.
function netlog_radius_prefer_mac($rows) {
    $withMac = [];
    foreach ($rows as $row) {
        if ($row['mac'] !== null) {
            $withMac[$row['time']][$row['event']] = true;
        }
    }
    return array_values(array_filter($rows, static function ($row) use ($withMac) {
        return $row['mac'] !== null || !isset($withMac[$row['time']][$row['event']]);
    }));
}

function netlog_radius_fetch($ssid, $server, $user, $selectedMac = null, $allowOtherIdentities = false) {
    $username = (string) $user['username'];
    $identities = [$username];
    if ($ssid === 'tuermeroam') {
        $identities = [$username . '@weh.rwth-aachen.de'];
    } elseif ($ssid === 'untenroam' && in_array($user['turm'], ['weh', 'tvk'], true)) {
        $identities[] = $username . '@' . $user['turm'] . '.rwth-aachen.de';
    }
    if ($selectedMac !== null) {
        $needles = [$selectedMac, str_replace(':', '-', $selectedMac), str_replace(':', '', $selectedMac)];
    } else {
        if ($username === '' || strlen($username) > 230 || preg_match('/[\s\x00-\x1f\x7f]/', $username)) return null;
        $needles = [];
        foreach ($identities as $identity) {
            $needles[] = $server['format'] === 'bragi' ? 'user=' . $identity : '[' . $identity . ']';
        }
    }
    $rows = netlog_ssh($server, netlog_grep($needles), function ($line) use ($ssid, $identities, $server, $selectedMac, $allowOtherIdentities) {
        $row = netlog_radius_row($line, $identities, $server['format'], $selectedMac, $allowOtherIdentities);
        if ($row !== null) $row['source'] = $ssid;
        return $row;
    });
    // Only tuermeroam's user query: the parser has already verified the exact
    // single RADIUS identity. Device queries already require a matching MAC.
    if ($rows !== null && $ssid === 'tuermeroam' && $selectedMac === null) {
        $rows = netlog_radius_prefer_mac($rows);
    }
    return $rows;
}

function netlog_dhcp_fetch($server, $devices) {
    if (!$devices) return [];
    return netlog_ssh($server, netlog_grep(array_keys($devices)), function ($line) use ($devices) {
        return netlog_dhcp_row($line, $devices);
    });
}

$devices = netlog_devices($conn, $uid);
$rows = [];
$error = null;
$unavailable = [];
$action = $_POST['execAction'] ?? '';
if (!is_string($action) || !in_array($action, array_merge(array_keys($radiusServers), ['dhcp', 'device']), true)) $action = '';
$selectedMac = is_string($_POST['device'] ?? null) ? netlog_mac($_POST['device']) : null;
if (!isset($devices[$selectedMac ?? ''])) $selectedMac = null;
if ($action !== '') {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        $error = 'Please reload this page and try again.';
    } elseif (isset($radiusServers[$action])) {
        $result = netlog_radius_fetch($action, $radiusServers[$action], $user);
        if ($result === null) $unavailable[] = $action;
        else $rows = $result;
    } elseif ($action === 'dhcp') {
        if (!$devices) $error = 'No registered devices. Register your device in IP Management first.';
        elseif (!isset($dhcpServers[$user['turm']])) $error = 'No DHCP server is assigned to this account.';
        else {
            $result = netlog_dhcp_fetch($dhcpServers[$user['turm']], $devices);
            if ($result === null) $unavailable[] = 'DHCP';
            else $rows = $result;
        }
    } elseif ($selectedMac === null) {
        $error = 'Please select one of your registered devices.';
    } else {
        // The MAC must belong to the selected account. Residents also need a matching RADIUS identity.
        // NetzAG may diagnose incorrect usernames by MAC; never return raw identities in the table.
        foreach ($radiusServers as $ssid => $server) {
            $result = netlog_radius_fetch($ssid, $server, $user, $selectedMac, $isAdmin);
            if ($result === null) $unavailable[] = $ssid;
            else $rows = array_merge($rows, $result);
        }
        if (isset($dhcpServers[$user['turm']])) {
            $result = netlog_dhcp_fetch($dhcpServers[$user['turm']], [$selectedMac => $devices[$selectedMac]]);
            if ($result === null) $unavailable[] = 'DHCP';
            else $rows = array_merge($rows, $result);
        } else {
            $unavailable[] = 'DHCP';
        }
    }
    // Sort by Unix timestamp for every query, independently of the source format.
    usort($rows, function ($a, $b) { return $b['time'] <=> $a['time']; });
}
$showIp = in_array($action, ['dhcp', 'device'], true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WEH Backend</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
#networkLogs { width:min(calc(90% + 100px), 96%); max-width:1780px; margin:12px auto 20px; color:white; font-size:17px; }
#networkLogs .netlog-layout { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); grid-template-areas:"guide workspace"; gap:16px; align-items:start; }
#networkLogs .netlog-panel { box-sizing:border-box; min-width:0; border:1px solid #777; border-radius:10px; padding:16px; }
#networkLogs .netlog-guide { grid-area:guide; text-align:left; font-size:16px; line-height:1.3; }
#networkLogs .netlog-guide h1 { font-size:23px; margin:0 0 8px; }
#networkLogs .netlog-guide h2 { font-size:20px; margin:12px 0 5px; }
#networkLogs .netlog-guide p { margin:0 0 7px; overflow-wrap:anywhere; }
#networkLogs .netlog-guide ul { margin:6px 0 9px; padding-left:22px; list-style:disc; }
#networkLogs .netlog-guide li { margin:3px 0; overflow-wrap:anywhere; }
#networkLogs .netlog-guide .netlog-guide-accounts { display:grid; gap:2px; margin:6px 0 9px; }
#networkLogs .netlog-guide .netlog-guide-accounts div { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1.5fr); gap:8px; padding:3px 0; border-bottom:1px solid #484848; overflow-wrap:anywhere; }
#networkLogs .netlog-guide p:last-child { margin-bottom:0; }
#networkLogs .netlog-guide a { color:var(--netlog-accent); text-decoration:underline; }
#networkLogs .netlog-workspace { grid-area:workspace; min-width:0; }
#networkLogs .netlog-controls { display:flex; flex-direction:column; gap:11px; margin:0; }
#networkLogs .netlog-controls h2 { font-size:25px; text-align:center; margin:0; }
#networkLogs .netlog-actions { display:grid; grid-template-columns:minmax(0, 3fr) minmax(0, 1fr); gap:12px; align-items:end; }
#networkLogs .netlog-group { display:flex; flex-direction:column; align-items:stretch; gap:7px; min-width:0; }
#networkLogs .netlog-buttons { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:7px; }
#networkLogs .netlog-label { font-size:17px; color:#ddd; text-align:center; }
#networkLogs .netlog-button { box-sizing:border-box; width:100%; min-width:0; min-height:46px; font-size:clamp(17px, 1.6vw, 22px); line-height:1.15; padding:9px 8px; margin:0; border:2px solid #000; border-radius:5px; background:#fff; color:#000; cursor:pointer; white-space:nowrap; }
#networkLogs .netlog-button:not(:disabled):hover, #networkLogs .netlog-button.is-active { background:var(--netlog-accent) !important; color:#000; }
#networkLogs .netlog-button:disabled { opacity:.45; cursor:not-allowed; }
#networkLogs .netlog-device-group { display:flex; flex-direction:column; gap:5px; padding-top:10px; border-top:1px solid #484848; min-width:0; }
#networkLogs .netlog-device { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-items:center; gap:8px; }
#networkLogs .netlog-device select { box-sizing:border-box; font-size:17px; min-height:46px; padding:6px 10px; width:100%; min-width:0; background:white; color:black; border:2px solid #000; border-radius:5px; }
#networkLogs .netlog-device .netlog-button { width:auto; font-size:19px; padding-inline:16px; }
#networkLogs .netlog-results { margin:12px 0 0; padding-top:10px; border-top:1px solid #484848; text-align:center; min-width:0; }
#networkLogs .netlog-results h2 { font-size:25px; margin:0 0 7px; overflow-wrap:anywhere; }
#networkLogs .netlog-results p { margin:4px 0 8px; }
#networkLogs .netlog-table-wrap { max-height:min(60vh, 560px); max-width:100%; overflow:auto; overscroll-behavior:contain; border:1px solid #555; border-radius:6px; text-align:left; }
#networkLogs .netlog-table-wrap:focus-visible { outline:2px solid var(--netlog-accent); outline-offset:3px; }
#networkLogs .netlog-table { width:100%; margin:0; border-collapse:separate; border-spacing:0; text-align:left; font-size:17px; }
#networkLogs .netlog-table th, #networkLogs .netlog-table td { padding:7px 10px; border-bottom:1px solid #484848; }
#networkLogs .netlog-table th { position:sticky; top:0; z-index:1; background:#282828; white-space:nowrap; }
#networkLogs .netlog-table td:first-child { white-space:nowrap; }
#networkLogs .netlog-table td { overflow-wrap:normal; word-break:normal; }
#networkLogs .netlog-table td.netlog-source, #networkLogs .netlog-table td.netlog-event,
#networkLogs .netlog-table td.netlog-ip { white-space:nowrap; }
#networkLogs .netlog-table td.netlog-device-name { overflow-wrap:anywhere; }
@media (min-width:1051px) {
    #networkLogs.netlog-fit .netlog-guide { max-height:var(--netlog-guide-max); overflow:auto; overscroll-behavior:contain; }
    /* Only the guide determines the grid row height; log rows cannot expand it. */
    #networkLogs .netlog-workspace { contain:size; align-self:stretch; display:flex; flex-direction:column; min-height:0; overflow:auto; }
    #networkLogs .netlog-controls { flex:0 0 auto; }
    #networkLogs .netlog-results { display:flex; flex-direction:column; flex:1 1 auto; min-height:0; }
    #networkLogs .netlog-results > h2, #networkLogs .netlog-results > p { flex:0 0 auto; }
    #networkLogs .netlog-table-wrap { flex:0 1 auto; min-height:0; max-height:none; }
}
#networkLogs .netlog-table tbody tr:hover { background:#242424; }
#networkLogs .netlog-muted { color:#bbb; font-size:15px; }
#networkLogs .netlog-success { color:#7fff00; }
#networkLogs .netlog-failure { color:#ff8888; }
@media (max-width:1050px) {
    #networkLogs { width:96%; }
    #networkLogs .netlog-layout { grid-template-columns:minmax(0, 1fr); grid-template-areas:"workspace" "guide"; gap:12px; }
    #networkLogs .netlog-button { font-size:20px; }
}
@media (max-width:600px) {
    #networkLogs { font-size:16px; margin-top:12px; }
    #networkLogs .netlog-panel { padding:14px; }
    #networkLogs .netlog-guide h1, #networkLogs .netlog-controls h2, #networkLogs .netlog-results h2 { font-size:22px; }
    #networkLogs .netlog-actions { grid-template-columns:minmax(0, 1fr); gap:14px; }
    #networkLogs .netlog-button { font-size:17px; padding-inline:5px; }
    #networkLogs .netlog-device { grid-template-columns:minmax(0, 1fr); }
    #networkLogs .netlog-device .netlog-button { width:100%; font-size:17px; }
    #networkLogs .netlog-table { font-size:15px; }
    #networkLogs .netlog-table th, #networkLogs .netlog-table td { padding:7px 9px; }
    #networkLogs .netlog-table-wrap { max-height:60vh; max-height:min(60dvh, 560px); }
}
@media (max-width:430px) {
    #networkLogs .netlog-buttons { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    #networkLogs .netlog-buttons .netlog-button:last-child { grid-column:1 / -1; }
}
@media (max-width:280px) {
    #networkLogs .netlog-buttons { grid-template-columns:minmax(0, 1fr); }
}
    </style>
</head>
<body>
<?php load_menu(); ?>
    <?php if ($isAdmin): ?>
    <div style="margin:0 auto 16px;text-align:center;">
        <div style="border:2px solid white;border-radius:10px;display:inline-block;padding:20px;text-align:center;background-color:transparent;">
        <form method="post" id="netlogAdminToggle" style="margin:0;">
            <input type="hidden" name="uid" value="<?= $uid ?>">
            <input type="hidden" name="csrf" value="<?= netlog_h($csrf) ?>">
            <input type="hidden" name="toggleAdminPanel" value="1">
            <span class="white-text" style="font-size:35px;cursor:pointer;display:inline-block;" role="button" tabindex="0" aria-expanded="<?= (($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block') ? 'true' : 'false' ?>" onclick="this.closest('form').submit()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.closest('form').submit();}">Admin Panel</span>
        </form>
        <?php if (($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block'): ?>
            <div id="adminPanel">
            <?php renderUserPostButtons($conn, $uid); ?>
            <form method="post" id="netlogUserForm">
                    <select name="uid" id="netlogUserSelect" style="margin-top:20px;font-size:20px;text-align:center;" onchange="this.form.submit()">
                    <?php
                    $statusLabels = [12 => 'Subletter', 13 => 'Ausgezogen', 14 => 'Abgemeldet', 64 => 'Dummy'];
                    $label = $user['pid'] == 11 ? strtoupper($user['turm']) . ' ' . $user['room'] : ($statusLabels[$user['pid']] ?? 'Undefined');
                    echo '<option value="' . $uid . '" selected>' . netlog_h($user['name'] . ' [' . $label . ']') . '</option>';
                    $stmt = $conn->prepare("SELECT uid, name, room, turm FROM users WHERE pid = 11 AND uid <> ? ORDER BY FIELD(turm, 'weh', 'tvk'), room");
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $stmt->bind_result($id, $name, $room, $turm);
                    while ($stmt->fetch()) echo '<option value="' . (int) $id . '">' . netlog_h($name . ' [' . ($turm === 'tvk' ? 'TvK' : strtoupper($turm)) . ' ' . $room . ']') . '</option>';
                    $stmt->close();
                    ?>
                </select>
            </form>
            <input type="text" id="netlogSearch" style="display:block;margin:20px auto;height:30px;font-size:25px;" placeholder="Suche nach User..." autocomplete="off">
            <div style="margin:0 auto 2%;display:table;table-layout:fixed;padding:10px;"><table class="center-table" style="margin:0 auto;"><tbody id="netlogSearchResults"></tbody></table></div>
            </div>
        <?php endif; ?>
        </div>
        <?php if ($uid !== $ownUid && ($_SESSION['AdminPanelToggleState'] ?? 'none') !== 'block'): ?>
            <div style="color:white;font-size:18px;margin-top:8px;">Selected user: <?= netlog_h($user['name']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>


<main id="networkLogs" style="--netlog-accent: <?= ($_SESSION['turm'] ?? 'weh') === 'tvk' ? '#FFA500' : '#11a50d' ?>;">
    <div class="netlog-layout">
        <section class="netlog-workspace netlog-panel">
        <form method="post" class="netlog-controls" aria-label="Log queries">
            <h2>Check your connection</h2>
            <input type="hidden" name="uid" value="<?= $uid ?>">
            <input type="hidden" name="csrf" value="<?= netlog_h($csrf) ?>">
            <div class="netlog-actions">
                <div class="netlog-group">
                    <span class="netlog-label">RADIUS · Wi-Fi login</span>
                    <div class="netlog-buttons">
                        <?php foreach ($radiusServers as $ssid => $server): ?>
                            <button type="submit" name="execAction" value="<?= netlog_h($ssid) ?>" class="house-button netlog-button<?= $action === $ssid ? ' is-active' : '' ?>"><?= netlog_h($ssid) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="netlog-group">
                    <span class="netlog-label">IP address</span>
                    <button type="submit" name="execAction" value="dhcp" class="house-button netlog-button<?= $action === 'dhcp' ? ' is-active' : '' ?>">DHCP</button>
                </div>
            </div>
            <div class="netlog-device-group">
                <label class="netlog-label" for="netlogDevice">Logs by registered device</label>
                <div class="netlog-device">
                    <select name="device" id="netlogDevice" aria-label="Registered device" <?= !$devices ? 'disabled' : '' ?>>
                        <?php if (!$devices): ?>
                            <option value="">No registered devices</option>
                        <?php else: ?>
                            <option value="">Select registered device</option>
                            <?php foreach ($devices as $mac => $device): ?>
                                <option value="<?= netlog_h($mac) ?>" <?= $selectedMac === $mac ? 'selected' : '' ?>><?= netlog_h(netlog_device_label($mac, $devices)) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="submit" name="execAction" value="device" class="house-button netlog-button<?= $action === 'device' ? ' is-active' : '' ?>" <?= !$devices ? 'disabled' : '' ?>>Device logs</button>
                </div>
            </div>
        </form>

    <?php if ($action !== ''): ?>
        <section class="netlog-results" aria-live="polite">
            <h2><?php
                if ($action === 'device') echo 'Device logs' . ($selectedMac !== null ? ' · ' . netlog_h(netlog_device_label($selectedMac, $devices)) : '');
                elseif ($action === 'dhcp') echo 'DHCP';
                else echo 'RADIUS · ' . netlog_h($action);
            ?></h2>
            <?php if ($error !== null): ?>
                <p class="netlog-failure"><?= netlog_h($error) ?></p>
            <?php else: ?>
                <?php if ($unavailable): ?><p class="netlog-failure">Log service unavailable: <?= netlog_h(implode(', ', $unavailable)) ?></p><?php endif; ?>
                <?php if (!$rows): ?>
                    <p><?= $unavailable ? 'No matching events returned by the available log services.' : 'No matching events in the current or previous log. Reconnect your device and check again.' ?></p>
                <?php else: ?>
                    <div class="netlog-table-wrap" tabindex="0" role="region" aria-label="Scrollable log results"><table class="netlog-table">
                        <thead><tr><th>Time</th><?php if ($action === 'device'): ?><th>Source</th><?php endif; ?><th>Event</th><th>MAC address / Hostname</th><?php if ($showIp): ?><th>IP address</th><th>IP check</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php [$ipStatus, $ipClass] = netlog_ip_status($row, $devices); ?>
                            <tr>
                                <td><?= netlog_h(netlog_display_time($row['time'])) ?></td>
                                <?php if ($action === 'device'): ?><td class="netlog-source"><?= netlog_h($row['source']) ?></td><?php endif; ?>
                                <td class="netlog-event <?= in_array($row['event'], ['Login successful', 'DHCPACK'], true) ? 'netlog-success' : (in_array($row['event'], ['Login failed', 'DHCPNAK'], true) ? 'netlog-failure' : '') ?>"><?= netlog_h($row['event']) ?></td>
                                <td class="netlog-device-name"><?= netlog_h(netlog_device_label($row['mac'], $devices)) ?></td>
                                <?php if ($showIp): ?>
                                    <td class="netlog-ip"><?= netlog_h(!$isAdmin && $row['ip'] !== null && !isset($devices[$row['mac']]['ips'][$row['ip']]) ? 'Different IP' : ($row['ip'] ?? '—')) ?></td>
                                    <td class="<?= $ipClass ?>"><?= netlog_h($ipStatus !== '' ? $ipStatus : '—') ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
        </section>

        <section class="netlog-guide netlog-panel">
            <h1>Internet connection not working?</h1>
            <p>Use this page to find out why your device cannot connect to the internet. There are two main steps: joining the Wi-Fi network and receiving an IP address.</p>

            <h2>1. Wi-Fi login (RADIUS)</h2>
            <p>If your device cannot join Wi-Fi, click the button for the network you are trying to use. Check whether your login was successful.</p>
            <div class="netlog-guide-accounts">
                <div><strong>untenroam</strong><span>Your username</span></div>
                <div><strong>fijiroam</strong><span>Your username</span></div>
                <div><strong>tuermeroam</strong><span>username@weh.rwth-aachen.de</span></div>
            </div>
            <ul>
                <li><strong>Password:</strong> Use your Wi-Fi-only password, not your house password.</li>
                <li><strong>Security settings:</strong> Check that your device is configured for WPA2-Enterprise and the correct server certificate settings.</li>
                <li><strong>PSK-only:</strong> Devices that cannot use WPA2-Enterprise can use <a href="PSK.php">PSK registration</a>. Neither <strong>weh-pskonly</strong> nor <strong>tvk-pskonly</strong> creates RADIUS logs. Check DHCP instead.</li>
            </ul>

            <h2>2. IP address (DHCP)</h2>
            <p>If your device is connected to Wi-Fi or via a network cable but has no internet access, click DHCP to check whether it receives an IP address. This also applies to both PSK-only networks.</p>
            <ul>
                <li><strong>Device registration:</strong> Register your device's MAC address in <a href="IPverwaltung.php">IP Management</a>.</li>
                <li><strong>Private/randomized MAC:</strong> Make sure the registered address matches the one your device actually uses. Disable rotating MAC addresses or use a fixed address for this network.</li>
                <li><strong>LAN and Wi-Fi:</strong> These usually use different MAC addresses. Register both if needed.</li>
                <li><strong>Shared IP:</strong> Only one device can use a registered IP address at a time.</li>
            </ul>
            <p><strong>DHCPACK</strong> means an IP address was assigned. The IP check compares it with your current registration. After registering a device, reconnect it and allow up to 10 minutes.</p>
            <p>With <strong>untenroam</strong>, your home network (WEH or TvK) is selected automatically, even in the other building.</p>

            <h2>3. Logs by device</h2>
            <p>Select a registered MAC address or hostname and click Device logs to see its Wi-Fi login and DHCP events together.</p>
        </section>
    </div>
</main>
<script>
(() => {
    const main = document.getElementById('networkLogs');
    const desktop = window.matchMedia('(min-width:1051px)');
    const guide = main.querySelector('.netlog-guide');
    const fit = () => {
        const viewportHeight = document.documentElement.clientHeight;
        const bottomPadding = parseFloat(getComputedStyle(document.body).paddingBottom) || 0;
        const bottomSpace = bottomPadding + 28;
        const available = Math.floor(viewportHeight - (main.getBoundingClientRect().top + window.scrollY) - bottomSpace);
        const enabled = desktop.matches && !document.getElementById('adminPanel') && available >= 380;
        main.classList.toggle('netlog-fit', enabled);
        if (!enabled) return;
        if (guide) main.style.setProperty('--netlog-guide-max', available + 'px');

    };
    fit();
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
})();
</script>
<?php if ($isAdmin): ?>
<script>
(() => {
    const search = document.getElementById('netlogSearch');
    if (!search) return;
    const results = document.getElementById('netlogSearchResults');
    let controller, timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        if (controller) controller.abort();
        results.replaceChildren();
        const term = search.value.trim();
        if (!term) return;
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch('?search=' + encodeURIComponent(term), {signal: controller.signal});
                if (!response.ok) return;
                const users = await response.json();
                if (search.value.trim() !== term) return;
                results.replaceChildren();
                for (const user of users) {
                    const row = results.insertRow();
                    row.insertCell().textContent = user.uid;
                    row.insertCell().textContent = user.username;
                    const link = document.createElement('a');
                    link.href = '#';
                    link.className = 'white-text';
                    link.style.userSelect = 'none';
                    link.textContent = user.name;
                    link.addEventListener('click', event => {
                        event.preventDefault();
                        const form = document.getElementById('netlogUserForm');
                        const input = document.getElementById('netlogUserSelect');
                        const option = new Option(user.name, user.uid, true, true);
                        input.add(option);
                        input.value = user.uid;
                        form.submit();
                    });
                    row.insertCell().append(link);
                    const house = row.insertCell();
                    const room = row.insertCell();
                    const turm = String(user.turm ?? '');
                    house.textContent = turm === 'tvk' ? 'TvK' : turm.toUpperCase();
                    room.textContent = user.room || user.oldroom || '';
                    house.style.paddingRight = '15px';
                    house.style.color = room.style.color = !Number(user.room) ? '#a9a9a9' : (turm === 'weh' ? '#18ec13' : (turm === 'tvk' ? '#FFA500' : '#fff'));
                }
            } catch (error) { if (error.name !== 'AbortError') console.error('User search failed'); }
        }, 200);
    });
})();
</script>
<?php endif; ?>
<script>
(() => {
    const device = document.getElementById('netlogDevice');
    if (!device || device.disabled) return;
    const button = device.form.querySelector('button[value="device"]');
    const update = () => { button.disabled = device.value === ''; };
    device.addEventListener('change', update);
    update();
})();
</script>
</body>
</html>
<?php $conn->close(); ?>
