<?php
session_start();
require('conn.php');
mysqli_set_charset($conn, "utf8");

/*
 * AJAX VOR template.php
 * auth() kommt aus template.php und ist hier bewusst noch nicht verfügbar.
 */

function weh_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function weh_first_token(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $value);
    return isset($parts[0]) ? trim((string)$parts[0]) : '';
}

function weh_user_display_label(array $user): string
{
    $firstname = weh_first_token((string)($user['firstname'] ?? ''));
    $lastname  = weh_first_token((string)($user['lastname'] ?? ''));

    if ($firstname === '' || $lastname === '') {
        $fullName = trim((string)($user['name'] ?? ''));
        $tokens = preg_split('/\s+/u', $fullName);

        if ($firstname === '' && isset($tokens[0])) {
            $firstname = trim((string)$tokens[0]);
        }

        if ($lastname === '' && is_array($tokens) && count($tokens) > 1) {
            $lastname = trim((string)$tokens[count($tokens) - 1]);
        }
    }

    $name = trim($firstname . ' ' . $lastname);
    if ($name === '') {
        $name = trim((string)($user['username'] ?? ''));
    }

    $room = isset($user['room']) && (int)$user['room'] > 0 ? (string)(int)$user['room'] : '';
    if ($room !== '') {
        $name .= ' (' . $room . ')';
    }

    return $name;
}

function weh_normalize_mac(string $mac): string
{
    $hex = strtolower((string)preg_replace('/[^a-fA-F0-9]/', '', $mac));
    if (strlen($hex) !== 12) {
        return '';
    }

    return implode(':', str_split($hex, 2));
}

function weh_fetch_nagios_states(array $config): array
{
    $nagiosURL = '';
    $nagiosResponse = '';
    $nagiosError = '';
    $nagiosHostStates = [];
    $downhostsList = [];

    if (
        isset($config['nagios']) &&
        is_array($config['nagios']) &&
        isset($config['nagios']['host'], $config['nagios']['user'], $config['nagios']['password'])
    ) {
        $nagiosconfig = $config['nagios'];
        $nagiosURL = rtrim((string)$nagiosconfig['host'], '/') . '/cgi-bin/statusjson.cgi?query=hostlist&details=true';

        $nagioscontext = stream_context_create([
            "http" => [
                "header" => "Authorization: Basic " . base64_encode($nagiosconfig['user'] . ":" . $nagiosconfig['password'])
            ]
        ]);

        $response = @file_get_contents($nagiosURL, false, $nagioscontext);

        if ($response === false) {
            $lastError = error_get_last();
            $nagiosError = isset($lastError['message']) ? (string)$lastError['message'] : 'Unbekannter Fehler bei der Nagios-Abfrage.';
        } else {
            $nagiosResponse = $response;
            $nagiosData = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $nagiosError = 'JSON-Decode fehlgeschlagen: ' . json_last_error_msg();
            } elseif (isset($nagiosData['data']['hostlist']) && is_array($nagiosData['data']['hostlist'])) {
                foreach ($nagiosData['data']['hostlist'] as $host => $info) {
                    $hostKey = strtolower(trim((string)$host));
                    $status = isset($info['status']) ? (int)$info['status'] : null;
                    $scheduledDowntimeDepth = isset($info['scheduled_downtime_depth']) ? (int)$info['scheduled_downtime_depth'] : null;
                    $isDown = ($status === 4);

                    $nagiosHostStates[$hostKey] = [
                        'status' => $status,
                        'scheduled_downtime_depth' => $scheduledDowntimeDepth,
                        'state' => $isDown ? 'down' : 'online',
                    ];

                    if ($isDown) {
                        $downhostsList[] = (string)$host;
                    }
                }
            } else {
                $nagiosError = 'Nagios-Antwort enthält keine data.hostlist.';
            }
        }
    } else {
        $nagiosError = 'Nagios-Konfiguration fehlt oder ist unvollständig.';
    }

    sort($downhostsList);

    return [
        'url' => $nagiosURL,
        'response' => $nagiosResponse,
        'error' => $nagiosError,
        'host_states' => $nagiosHostStates,
        'downhosts' => $downhostsList,
    ];
}

function weh_load_aps(mysqli $conn, array $nagiosHostStates): array
{
    $aps = [];
    $apsByHostname = [];
    $unmatchedApHostnames = [];
    $roomApCount = 0;
    $kaminApCount = 0;
    $buildingApCount = 0;

    $apSql = "
        SELECT
            id,
            room,
            hostname,
            beschreibung,
            coord_x,
            coord_y,
            coord_z,
            CASE
                WHEN room BETWEEN 100 AND 1716 THEN 'room'
                WHEN room BETWEEN 2000 AND 2100 THEN 'kamin'
                ELSE 'building'
            END AS ap_category
        FROM aps
        WHERE turm = 'weh'
          AND nagios = 1
    ";

    $apResult = mysqli_query($conn, $apSql);
    if ($apResult) {
        while ($row = mysqli_fetch_assoc($apResult)) {
            if (
                $row['coord_x'] === null || $row['coord_x'] === '' ||
                $row['coord_y'] === null || $row['coord_y'] === '' ||
                $row['coord_z'] === null || $row['coord_z'] === ''
            ) {
                continue;
            }

            $hostname = trim((string)($row['hostname'] ?? ''));
            $hostnameKey = strtolower($hostname);

            $nagiosState = 'unknown';
            $nagiosStatus = null;
            $nagiosDowntime = null;

            if ($hostnameKey !== '' && isset($nagiosHostStates[$hostnameKey])) {
                $nagiosState = (string)$nagiosHostStates[$hostnameKey]['state'];
                $nagiosStatus = $nagiosHostStates[$hostnameKey]['status'];
                $nagiosDowntime = $nagiosHostStates[$hostnameKey]['scheduled_downtime_depth'];
            } elseif ($hostname !== '') {
                $unmatchedApHostnames[] = $hostname;
            }

            $apCategory = (string)($row['ap_category'] ?? 'building');

            if ($apCategory === 'room') {
                $roomApCount++;
            } elseif ($apCategory === 'kamin') {
                $kaminApCount++;
            } else {
                $apCategory = 'building';
                $buildingApCount++;
            }

            $ap = [
                'id' => (int)$row['id'],
                'room' => isset($row['room']) ? (int)$row['room'] : null,
                'hostname' => $hostname,
                'beschreibung' => (string)($row['beschreibung'] ?? ''),
                'x' => (float)$row['coord_x'],
                'y' => (float)$row['coord_y'],
                'z' => (float)$row['coord_z'],
                'nagios_state' => $nagiosState,
                'nagios_status' => $nagiosStatus,
                'nagios_downtime' => $nagiosDowntime,
                'ap_category' => $apCategory,
            ];

            $aps[] = $ap;

            if ($hostnameKey !== '') {
                $apsByHostname[$hostnameKey] = $ap;
            }
        }
        mysqli_free_result($apResult);
    }

    $unmatchedApHostnames = array_values(array_unique($unmatchedApHostnames));
    sort($unmatchedApHostnames);

    return [
        'aps' => $aps,
        'aps_by_hostname' => $apsByHostname,
        'room_ap_count' => $roomApCount,
        'kamin_ap_count' => $kaminApCount,
        'building_ap_count' => $buildingApCount,
        'unmatched_ap_hostnames' => $unmatchedApHostnames,
    ];
}

function weh_search_users(mysqli $conn, string $query): array
{
    $query = trim($query);

    $sql = "
        SELECT
            uid,
            username,
            room,
            firstname,
            lastname,
            name
        FROM users
        WHERE turm = 'weh'
          AND pid IN (11, 12)
          AND (
                ? = ''
                OR username LIKE CONCAT('%', ?, '%')
                OR CAST(room AS CHAR) LIKE CONCAT('%', ?, '%')
                OR name LIKE CONCAT('%', ?, '%')
                OR firstname LIKE CONCAT('%', ?, '%')
                OR lastname LIKE CONCAT('%', ?, '%')
          )
        ORDER BY
            CASE WHEN room IS NULL OR room = 0 THEN 1 ELSE 0 END ASC,
            room ASC,
            lastname ASC,
            firstname ASC,
            username ASC
        LIMIT 30
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'ssssss', $query, $query, $query, $query, $query, $query);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = [
                'uid' => (int)$row['uid'],
                'username' => (string)$row['username'],
                'room' => isset($row['room']) ? (int)$row['room'] : 0,
                'label' => weh_user_display_label($row),
            ];
        }
        mysqli_free_result($result);
    }

    mysqli_stmt_close($stmt);
    return $items;
}

function weh_get_user(mysqli $conn, int $uid): ?array
{
    $sql = "
        SELECT
            uid,
            username,
            room,
            firstname,
            lastname,
            name
        FROM users
        WHERE uid = ?
          AND turm = 'weh'
          AND pid IN (11, 12)
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $user = null;
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            $user = $row;
        }
        mysqli_free_result($result);
    }

    mysqli_stmt_close($stmt);
    return $user ?: null;
}

function weh_get_user_devices(mysqli $conn, int $uid): array
{
    $sql = "
        SELECT
            id,
            tstamp,
            hostname,
            mac1,
            mac2,
            mac3
        FROM macauth
        WHERE uid = ?
        ORDER BY tstamp DESC, id DESC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $devices = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rowHostname = trim((string)($row['hostname'] ?? ''));

            foreach (['mac1', 'mac2', 'mac3'] as $field) {
                $mac = weh_normalize_mac((string)($row[$field] ?? ''));
                if ($mac === '') {
                    continue;
                }

                if (!isset($devices[$mac])) {
                    $devices[$mac] = [
                        'mac' => $mac,
                        'hostname' => $rowHostname,
                        'tstamp' => isset($row['tstamp']) ? (int)$row['tstamp'] : 0,
                    ];
                } else {
                    if ($devices[$mac]['hostname'] === '' && $rowHostname !== '') {
                        $devices[$mac]['hostname'] = $rowHostname;
                    }
                    if ((int)$devices[$mac]['tstamp'] < (int)$row['tstamp']) {
                        $devices[$mac]['tstamp'] = (int)$row['tstamp'];
                    }
                }
            }
        }
        mysqli_free_result($result);
    }

    mysqli_stmt_close($stmt);

    usort($devices, static function ($a, $b) {
        return ($b['tstamp'] ?? 0) <=> ($a['tstamp'] ?? 0);
    });

    return array_values($devices);
}

function weh_get_pid11_user_map_for_macs(mysqli $conn, array $macs): array
{
    $wanted = [];
    foreach ($macs as $mac) {
        $norm = weh_normalize_mac((string)$mac);
        if ($norm !== '') {
            $wanted[$norm] = true;
        }
    }

    if (empty($wanted)) {
        return [];
    }

    $sql = "
        SELECT
            u.uid,
            u.username,
            u.room,
            u.firstname,
            u.lastname,
            u.name,
            m.id,
            m.tstamp,
            m.hostname,
            m.mac1,
            m.mac2,
            m.mac3
        FROM users u
        INNER JOIN macauth m
            ON m.uid = u.uid
        WHERE u.turm = 'weh'
          AND u.pid IN (11)
        ORDER BY m.tstamp DESC, m.id DESC, u.uid DESC
    ";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return [];
    }

    $map = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $userLabel = weh_user_display_label($row);
        $rowHostname = trim((string)($row['hostname'] ?? ''));

        foreach (['mac1', 'mac2', 'mac3'] as $field) {
            $mac = weh_normalize_mac((string)($row[$field] ?? ''));
            if ($mac === '' || !isset($wanted[$mac]) || isset($map[$mac])) {
                continue;
            }

            $map[$mac] = [
                'uid' => (int)$row['uid'],
                'username' => (string)$row['username'],
                'room' => isset($row['room']) ? (int)$row['room'] : 0,
                'label' => $userLabel,
                'hostname' => $rowHostname,
                'tstamp' => isset($row['tstamp']) ? (int)$row['tstamp'] : 0,
            ];
        }
    }

    mysqli_free_result($result);

    return $map;
}

function weh_tcl_escape(string $value): string
{
    return strtr($value, [
        "\\" => "\\\\",
        "\"" => "\\\"",
        "$"  => "\\$",
        "["  => "\\[",
        "]"  => "\\]",
        "\r" => "",
        "\n" => "\\n",
    ]);
}

function weh_build_expect_batch_script(string $host, string $username, string $password, array $macs, int $timeoutSeconds): string
{
    $lines = [];
    $lines[] = '#!/usr/bin/expect -f';
    $lines[] = 'set timeout ' . max(5, $timeoutSeconds);
    $lines[] = 'log_user 1';
    $lines[] = '';
    $lines[] = 'set host "' . weh_tcl_escape($host) . '"';
    $lines[] = 'set user "' . weh_tcl_escape($username) . '"';
    $lines[] = 'set pass "' . weh_tcl_escape($password) . '"';
    $lines[] = '';
    $lines[] = 'spawn ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $host';
    $lines[] = '';
    $lines[] = 'expect "User:"';
    $lines[] = 'send "$user\r"';
    $lines[] = '';
    $lines[] = 'expect "Password:"';
    $lines[] = 'send "$pass\r"';
    $lines[] = '';
    $lines[] = 'expect "(Cisco Controller) >"';
    $lines[] = 'send "config paging disable\r"';
    $lines[] = '';
    $lines[] = 'expect "(Cisco Controller) >"';
    $lines[] = '';

    foreach ($macs as $mac) {
        $safeMac = weh_tcl_escape($mac);
        $lines[] = 'puts "__WLC_BEGIN__::' . $safeMac . '"';
        $lines[] = 'send "show client detail ' . $safeMac . '\r"';
        $lines[] = 'expect {';
        $lines[] = '    "(Cisco Controller) >" {';
        $lines[] = '        puts "__WLC_END__::' . $safeMac . '"';
        $lines[] = '    }';
        $lines[] = '    timeout {';
        $lines[] = '        puts "__WLC_TIMEOUT__::' . $safeMac . '"';
        $lines[] = '        exit 21';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
    }

    $lines[] = 'send "logout\r"';
    $lines[] = '';
    $lines[] = 'expect {';
    $lines[] = '    "Would you like to save them now? (y/N)" {';
    $lines[] = '        send "N\r"';
    $lines[] = '    }';
    $lines[] = '    eof {}';
    $lines[] = '    timeout {';
    $lines[] = '        puts "TIMEOUT_BEI_LOGOUT_PROMPT"';
    $lines[] = '        exit 22';
    $lines[] = '    }';
    $lines[] = '}';
    $lines[] = '';
    $lines[] = 'expect eof';

    return implode("\n", $lines) . "\n";
}

function weh_build_expect_command_script(string $host, string $username, string $password, string $command, int $timeoutSeconds): string
{
    $safeCommand = weh_tcl_escape($command);

    $lines = [];
    $lines[] = '#!/usr/bin/expect -f';
    $lines[] = 'set timeout ' . max(5, $timeoutSeconds);
    $lines[] = 'log_user 1';
    $lines[] = '';
    $lines[] = 'set host "' . weh_tcl_escape($host) . '"';
    $lines[] = 'set user "' . weh_tcl_escape($username) . '"';
    $lines[] = 'set pass "' . weh_tcl_escape($password) . '"';
    $lines[] = '';
    $lines[] = 'spawn ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $host';
    $lines[] = '';
    $lines[] = 'expect "User:"';
    $lines[] = 'send "$user\r"';
    $lines[] = '';
    $lines[] = 'expect "Password:"';
    $lines[] = 'send "$pass\r"';
    $lines[] = '';
    $lines[] = 'expect "(Cisco Controller) >"';
    $lines[] = 'send "config paging disable\r"';
    $lines[] = '';
    $lines[] = 'expect "(Cisco Controller) >"';
    $lines[] = 'puts "__WLC_CMD_BEGIN__"';
    $lines[] = 'send "' . $safeCommand . '\r"';
    $lines[] = 'expect {';
    $lines[] = '    "(Cisco Controller) >" {';
    $lines[] = '        puts "__WLC_CMD_END__"';
    $lines[] = '    }';
    $lines[] = '    timeout {';
    $lines[] = '        puts "__WLC_CMD_TIMEOUT__"';
    $lines[] = '        exit 31';
    $lines[] = '    }';
    $lines[] = '}';
    $lines[] = '';
    $lines[] = 'send "logout\r"';
    $lines[] = '';
    $lines[] = 'expect {';
    $lines[] = '    "Would you like to save them now? (y/N)" {';
    $lines[] = '        send "N\r"';
    $lines[] = '    }';
    $lines[] = '    eof {}';
    $lines[] = '    timeout {';
    $lines[] = '        puts "TIMEOUT_BEI_LOGOUT_PROMPT"';
    $lines[] = '        exit 32';
    $lines[] = '    }';
    $lines[] = '}';
    $lines[] = '';
    $lines[] = 'expect eof';

    return implode("\n", $lines) . "\n";
}

function weh_split_batch_output_by_mac(string $output, array $macs): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $output);

    $result = [];
    foreach ($macs as $mac) {
        $mac = strtolower(trim((string)$mac));
        $result[$mac] = [
            'raw' => '',
            'timed_out' => false,
        ];
    }

    if (!preg_match_all('/__WLC_BEGIN__::([0-9a-f:]+)/i', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
        return $result;
    }

    $fullMatches = $matches[0];
    $macMatches = $matches[1];
    $count = count($fullMatches);

    for ($i = 0; $i < $count; $i++) {
        $fullMarker = (string)$fullMatches[$i][0];
        $fullPos = (int)$fullMatches[$i][1];
        $mac = strtolower(trim((string)$macMatches[$i][0]));

        if (!isset($result[$mac])) {
            continue;
        }

        $contentStart = $fullPos + strlen($fullMarker);
        $nextBoundaryPos = strlen($normalized);

        $endMarker = '__WLC_END__::' . $mac;
        $timeoutMarker = '__WLC_TIMEOUT__::' . $mac;

        $endPos = strpos($normalized, $endMarker, $contentStart);
        $timeoutPos = strpos($normalized, $timeoutMarker, $contentStart);

        if ($endPos !== false) {
            $nextBoundaryPos = min($nextBoundaryPos, $endPos);
        }

        if ($timeoutPos !== false) {
            $nextBoundaryPos = min($nextBoundaryPos, $timeoutPos);
            $result[$mac]['timed_out'] = true;
        }

        if ($i + 1 < $count) {
            $nextBeginPos = (int)$fullMatches[$i + 1][1];
            $nextBoundaryPos = min($nextBoundaryPos, $nextBeginPos);
        }

        $chunk = substr($normalized, $contentStart, $nextBoundaryPos - $contentStart);
        $chunk = trim($chunk);

        $chunk = preg_replace('/^\s*show client detail\s+[0-9a-f:\.]+\s*$/mi', '', $chunk);
        $chunk = preg_replace('/^\s*\(Cisco Controller\)\s*>\s*/mi', '', $chunk);
        $chunk = trim($chunk);

        $result[$mac]['raw'] = $chunk;
    }

    return $result;
}

function weh_extract_single_command_output(string $output, string $command): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $output);

    $begin = '__WLC_CMD_BEGIN__';
    $end = '__WLC_CMD_END__';
    $timeout = '__WLC_CMD_TIMEOUT__';

    $beginPos = strpos($normalized, $begin);
    if ($beginPos === false) {
        return [
            'raw' => trim($normalized),
            'timed_out' => strpos($normalized, $timeout) !== false,
        ];
    }

    $contentStart = $beginPos + strlen($begin);
    $contentEnd = strlen($normalized);

    $endPos = strpos($normalized, $end, $contentStart);
    if ($endPos !== false) {
        $contentEnd = min($contentEnd, $endPos);
    }

    $timeoutPos = strpos($normalized, $timeout, $contentStart);
    $timedOut = false;
    if ($timeoutPos !== false) {
        $contentEnd = min($contentEnd, $timeoutPos);
        $timedOut = true;
    }

    $chunk = substr($normalized, $contentStart, $contentEnd - $contentStart);
    $chunk = trim($chunk);

    $quotedCommand = preg_quote($command, '/');
    $chunk = preg_replace('/^\s*' . $quotedCommand . '\s*$/mi', '', $chunk);
    $chunk = preg_replace('/^\s*\(Cisco Controller\)\s*>\s*/mi', '', $chunk);
    $chunk = trim($chunk);

    return [
        'raw' => $chunk,
        'timed_out' => $timedOut,
    ];
}

function weh_run_wlc_expect_batch(array $wlcConfig, array $macs, array &$debug, int $timeoutSeconds = 30): array
{
    $expectPath = trim((string)@shell_exec('command -v expect 2>/dev/null'));
    if ($expectPath === '') {
        $debug['error'] = 'expect nicht gefunden';
        return [
            'success' => false,
            'outputs' => [],
            'raw_output' => '',
            'exit_code' => null,
        ];
    }

    $host = trim((string)($wlcConfig['host'] ?? ''));
    $username = trim((string)($wlcConfig['username'] ?? ''));
    $password = (string)($wlcConfig['password'] ?? '');

    if ($host === '' || $username === '' || $password === '') {
        $debug['error'] = 'WLC-Konfiguration unvollständig';
        return [
            'success' => false,
            'outputs' => [],
            'raw_output' => '',
            'exit_code' => null,
        ];
    }

    $expectScript = weh_build_expect_batch_script($host, $username, $password, $macs, $timeoutSeconds);

    $tmpFile = tempnam(sys_get_temp_dir(), 'wlc_expect_');
    if ($tmpFile === false) {
        $debug['error'] = 'tempnam() fehlgeschlagen';
        return [
            'success' => false,
            'outputs' => [],
            'raw_output' => '',
            'exit_code' => null,
        ];
    }

    file_put_contents($tmpFile, $expectScript);
    @chmod($tmpFile, 0700);

    $debug['expect_path'] = $expectPath;
    $debug['script_path'] = $tmpFile;

    $command = $expectPath . ' ' . escapeshellarg($tmpFile);
    $debug['command'] = $command;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        @unlink($tmpFile);
        $debug['error'] = 'proc_open() fehlgeschlagen';
        return [
            'success' => false,
            'outputs' => [],
            'raw_output' => '',
            'exit_code' => null,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $hardTimeout = max(5, $timeoutSeconds);

    while (true) {
        $status = proc_get_status($process);
        $running = is_array($status) ? (bool)$status['running'] : false;

        $read = [];
        if (is_resource($pipes[1])) $read[] = $pipes[1];
        if (is_resource($pipes[2])) $read[] = $pipes[2];

        if (!empty($read)) {
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 250000);

            if ($changed !== false && $changed > 0) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
        }

        $now = microtime(true);

        if (($now - $start) > $hardTimeout) {
            $debug['timed_out'] = true;
            @proc_terminate($process);
            break;
        }

        if (!$running) {
            break;
        }
    }

    $stdoutRest = stream_get_contents($pipes[1]);
    $stderrRest = stream_get_contents($pipes[2]);

    if ($stdoutRest !== false && $stdoutRest !== '') {
        $stdout .= $stdoutRest;
    }
    if ($stderrRest !== false && $stderrRest !== '') {
        $stderr .= $stderrRest;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    @unlink($tmpFile);

    $rawOutput = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    $outputsByMac = weh_split_batch_output_by_mac($rawOutput, $macs);

    $debug['exit_code'] = $exitCode;
    $debug['duration_seconds'] = round(microtime(true) - $start, 2);
    $debug['raw_output'] = $rawOutput;

    return [
        'success' => true,
        'outputs' => $outputsByMac,
        'raw_output' => $rawOutput,
        'exit_code' => $exitCode,
    ];
}

function weh_run_wlc_expect_command(array $wlcConfig, string $wlcCommand, array &$debug, int $timeoutSeconds = 45): array
{
    $expectPath = trim((string)@shell_exec('command -v expect 2>/dev/null'));
    if ($expectPath === '') {
        $debug['error'] = 'expect nicht gefunden';
        return [
            'success' => false,
            'command_output' => '',
            'raw_output' => '',
            'exit_code' => null,
            'timed_out' => false,
        ];
    }

    $host = trim((string)($wlcConfig['host'] ?? ''));
    $username = trim((string)($wlcConfig['username'] ?? ''));
    $password = (string)($wlcConfig['password'] ?? '');

    if ($host === '' || $username === '' || $password === '') {
        $debug['error'] = 'WLC-Konfiguration unvollständig';
        return [
            'success' => false,
            'command_output' => '',
            'raw_output' => '',
            'exit_code' => null,
            'timed_out' => false,
        ];
    }

    $expectScript = weh_build_expect_command_script($host, $username, $password, $wlcCommand, $timeoutSeconds);

    $tmpFile = tempnam(sys_get_temp_dir(), 'wlc_cmd_');
    if ($tmpFile === false) {
        $debug['error'] = 'tempnam() fehlgeschlagen';
        return [
            'success' => false,
            'command_output' => '',
            'raw_output' => '',
            'exit_code' => null,
            'timed_out' => false,
        ];
    }

    file_put_contents($tmpFile, $expectScript);
    @chmod($tmpFile, 0700);

    $debug['expect_path'] = $expectPath;
    $debug['script_path'] = $tmpFile;
    $debug['wlc_command'] = $wlcCommand;

    $command = $expectPath . ' ' . escapeshellarg($tmpFile);
    $debug['command'] = $command;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        @unlink($tmpFile);
        $debug['error'] = 'proc_open() fehlgeschlagen';
        return [
            'success' => false,
            'command_output' => '',
            'raw_output' => '',
            'exit_code' => null,
            'timed_out' => false,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $hardTimeout = max(5, $timeoutSeconds);

    while (true) {
        $status = proc_get_status($process);
        $running = is_array($status) ? (bool)$status['running'] : false;

        $read = [];
        if (is_resource($pipes[1])) $read[] = $pipes[1];
        if (is_resource($pipes[2])) $read[] = $pipes[2];

        if (!empty($read)) {
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 250000);

            if ($changed !== false && $changed > 0) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
        }

        $now = microtime(true);

        if (($now - $start) > $hardTimeout) {
            $debug['timed_out'] = true;
            @proc_terminate($process);
            break;
        }

        if (!$running) {
            break;
        }
    }

    $stdoutRest = stream_get_contents($pipes[1]);
    $stderrRest = stream_get_contents($pipes[2]);

    if ($stdoutRest !== false && $stdoutRest !== '') {
        $stdout .= $stdoutRest;
    }
    if ($stderrRest !== false && $stderrRest !== '') {
        $stderr .= $stderrRest;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    @unlink($tmpFile);

    $rawOutput = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    $parsed = weh_extract_single_command_output($rawOutput, $wlcCommand);

    $debug['exit_code'] = $exitCode;
    $debug['duration_seconds'] = round(microtime(true) - $start, 2);
    $debug['raw_output'] = $rawOutput;

    return [
        'success' => true,
        'command_output' => (string)$parsed['raw'],
        'raw_output' => $rawOutput,
        'exit_code' => $exitCode,
        'timed_out' => !empty($parsed['timed_out']),
    ];
}

function weh_parse_wlc_client_summary_macs(string $raw): array
{
    $matches = [];

    preg_match_all(
        '/(?:[0-9A-Fa-f]{2}(?:[:\-])){5}[0-9A-Fa-f]{2}|(?:[0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}/',
        $raw,
        $matches
    );

    $macs = [];
    if (!empty($matches[0])) {
        foreach ($matches[0] as $candidate) {
            $mac = weh_normalize_mac((string)$candidate);
            if ($mac !== '') {
                $macs[$mac] = true;
            }
        }
    }

    return array_values(array_keys($macs));
}

function weh_parse_wlc_client_output(string $raw, array $apsByHostname): array
{
    $associatedAp = null;
    $associatedRssi = null;
    $nearby = [];
    $nearbySection = false;
    $currentNearbyAp = null;

    $lines = preg_split('/\R/u', $raw);

    foreach ($lines as $line) {
        $line = rtrim((string)$line);
        $trimmed = trim($line);

        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^AP Name\.+\s+([A-Za-z0-9._-]+)/i', $trimmed, $m)) {
            $associatedAp = strtolower(trim((string)$m[1]));
            continue;
        }

        if (preg_match('/^Radio Signal Strength Indicator\.+\s+(-\d+)\s*dBm/i', $trimmed, $m)) {
            $associatedRssi = (int)$m[1];
            continue;
        }

        if (preg_match('/^Nearby AP Statistics:/i', $trimmed)) {
            $nearbySection = true;
            $currentNearbyAp = null;
            continue;
        }

        if ($nearbySection) {
            if (preg_match('/^(DNS Server details:|Assisted Roaming Prediction List details:|Client Dhcp Required:|Allowed \(URL\)IP Addresses|AVC Profile Name:|Fastlane Client:)/i', $trimmed)) {
                $nearbySection = false;
                $currentNearbyAp = null;
                continue;
            }

            if (preg_match('/^\s*([A-Za-z0-9._-]+)\(slot\s+\d+\)\s*$/i', $line, $m)) {
                $currentNearbyAp = strtolower(trim((string)$m[1]));
                if (!isset($nearby[$currentNearbyAp])) {
                    $nearby[$currentNearbyAp] = null;
                }
                continue;
            }

            if ($currentNearbyAp !== null && preg_match('/(-\d+)\s*dBm/i', $trimmed, $m)) {
                $rssi = (int)$m[1];
                if ($nearby[$currentNearbyAp] === null || $rssi > $nearby[$currentNearbyAp]) {
                    $nearby[$currentNearbyAp] = $rssi;
                }
                continue;
            }
        }
    }

    $nearby = array_filter($nearby, static function ($value) {
        return $value !== null;
    });

    return [
        'associated_ap' => $associatedAp,
        'associated_rssi' => $associatedRssi,
        'nearby_aps' => $nearby,
    ];
}

function weh_rssi_weight(?int $rssi, bool $isPrimary = false): float
{
    if ($rssi === null) {
        $weight = 24.0;
    } else {
        $weight = max(4.0, 120.0 + (float)$rssi);
    }

    if ($isPrimary) {
        $weight *= 1.8;
    }

    return $weight;
}


function weh_estimate_position(array $parsed, array $apsByHostname): ?array
{
    $candidates = [];

    $addCandidate = static function (
        array &$candidates,
        string $hostname,
        float $weight,
        ?int $rssi,
        string $source,
        array $ap
    ): void {
        if (!isset($candidates[$hostname])) {
            $candidates[$hostname] = [
                'hostname' => $hostname,
                'weight' => 0.0,
                'rssi' => $rssi,
                'source' => [],
                'x' => (float)$ap['x'],
                'y' => (float)$ap['y'],
                'z' => (float)$ap['z'],
            ];
        }

        $candidates[$hostname]['weight'] += $weight;

        if ($rssi !== null) {
            if ($candidates[$hostname]['rssi'] === null || $rssi > $candidates[$hostname]['rssi']) {
                $candidates[$hostname]['rssi'] = $rssi;
            }
        }

        if (!in_array($source, $candidates[$hostname]['source'], true)) {
            $candidates[$hostname]['source'][] = $source;
        }
    };

    $rssiToWeight = static function (int $rssi, int $bestRssi): float {
        return exp(((float)$rssi - (float)$bestRssi) / 6.0);
    };

    $associatedAp = strtolower(trim((string)($parsed['associated_ap'] ?? '')));
    $associatedRssi = isset($parsed['associated_rssi']) ? (int)$parsed['associated_rssi'] : null;

    $validNearby = [];
    if (isset($parsed['nearby_aps']) && is_array($parsed['nearby_aps'])) {
        foreach ($parsed['nearby_aps'] as $hostname => $rssi) {
            $hostname = strtolower(trim((string)$hostname));
            if ($hostname === '' || !isset($apsByHostname[$hostname])) {
                continue;
            }

            $validNearby[] = [
                'hostname' => $hostname,
                'rssi' => (int)$rssi,
                'ap' => $apsByHostname[$hostname],
            ];
        }
    }

    usort($validNearby, static function (array $a, array $b): int {
        return $b['rssi'] <=> $a['rssi'];
    });

    if ($associatedAp === '' || !isset($apsByHostname[$associatedAp])) {
        if (!empty($validNearby)) {
            $associatedAp = $validNearby[0]['hostname'];
            $associatedRssi = $validNearby[0]['rssi'];
        }
    }

    if ($associatedAp === '' || !isset($apsByHostname[$associatedAp])) {
        return null;
    }

    $bestRssi = $associatedRssi ?? -90;
    if (!empty($validNearby)) {
        $bestRssi = max($bestRssi, (int)$validNearby[0]['rssi']);
    }

    $keptNearby = [];
    foreach ($validNearby as $item) {
        if ($item['hostname'] === $associatedAp) {
            continue;
        }

        if ($item['rssi'] < -82) {
            continue;
        }

        if (($bestRssi - $item['rssi']) > 15) {
            continue;
        }

        $keptNearby[] = $item;

        if (count($keptNearby) >= 5) {
            break;
        }
    }

    if ($associatedRssi !== null) {
        if ($associatedRssi >= -55) {
            $associatedBoost = 1.65;
        } elseif ($associatedRssi >= -62) {
            $associatedBoost = 1.50;
        } elseif ($associatedRssi >= -70) {
            $associatedBoost = 1.32;
        } elseif ($associatedRssi >= -78) {
            $associatedBoost = 1.18;
        } else {
            $associatedBoost = 1.05;
        }

        $associatedWeight = $rssiToWeight($associatedRssi, $bestRssi) * $associatedBoost;
    } else {
        $associatedWeight = 1.10;
    }

    $addCandidate(
        $candidates,
        $associatedAp,
        $associatedWeight,
        $associatedRssi,
        'associated',
        $apsByHostname[$associatedAp]
    );

    foreach ($keptNearby as $item) {
        $nearbyWeight = $rssiToWeight((int)$item['rssi'], $bestRssi);

        $addCandidate(
            $candidates,
            $item['hostname'],
            $nearbyWeight,
            (int)$item['rssi'],
            'nearby',
            $item['ap']
        );
    }

    if (empty($candidates)) {
        return null;
    }

    $sumWeight = 0.0;
    $sumX = 0.0;
    $sumY = 0.0;
    $sumZ = 0.0;

    foreach ($candidates as $candidate) {
        $weight = (float)$candidate['weight'];
        $sumWeight += $weight;
        $sumX += (float)$candidate['x'] * $weight;
        $sumY += (float)$candidate['y'] * $weight;
        $sumZ += (float)$candidate['z'] * $weight;
    }

    if ($sumWeight <= 0.0) {
        return null;
    }

    $x = $sumX / $sumWeight;
    $y = $sumY / $sumWeight;
    $z = $sumZ / $sumWeight;

    if (!empty($keptNearby)) {
        $assocX = (float)$apsByHostname[$associatedAp]['x'];
        $assocY = (float)$apsByHostname[$associatedAp]['y'];
        $assocZ = (float)$apsByHostname[$associatedAp]['z'];

        $dx = $x - $assocX;
        $dy = $y - $assocY;
        $dz = $z - $assocZ;

        $horizontalDistance = sqrt(($dx * $dx) + ($dz * $dz));
        $maxHorizontalDistance = 2.35;

        if ($horizontalDistance > $maxHorizontalDistance && $horizontalDistance > 0.0) {
            $scale = $maxHorizontalDistance / $horizontalDistance;
            $dx *= $scale;
            $dz *= $scale;
        }

        $maxVerticalDistance = 0.45;
        if (abs($dy) > $maxVerticalDistance) {
            $dy = ($dy < 0 ? -1.0 : 1.0) * $maxVerticalDistance;
        }

        $x = $assocX + $dx;
        $y = $assocY + $dy;
        $z = $assocZ + $dz;
    }

    uasort($candidates, static function ($a, $b) {
        return $b['weight'] <=> $a['weight'];
    });

    return [
        'x' => round($x, 2),
        'y' => round($y, 2),
        'z' => round($z, 2),
        'used_aps' => array_values($candidates),
        'primary_ap' => $associatedAp,
    ];
}


if (
    (isset($_GET['ajax']) && $_GET['ajax'] === '1') ||
    (isset($_POST['ajax']) && $_POST['ajax'] === '1')
) {
    $ajaxAction = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

    if ($ajaxAction === 'search_users') {
        $query = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $items = weh_search_users($conn, $query);

        weh_json_response([
            'success' => true,
            'items' => $items,
        ]);
    }

    if ($ajaxAction === 'locate_user') {
        @set_time_limit(30);

        $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
        if ($uid <= 0) {
            weh_json_response([
                'success' => false,
                'error' => 'Ungültige UID.',
            ], 400);
        }

        $user = weh_get_user($conn, $uid);
        if (!$user) {
            weh_json_response([
                'success' => false,
                'error' => 'User nicht gefunden.',
            ], 404);
        }

        if (
            !isset($config['wlcweh']) ||
            !is_array($config['wlcweh']) ||
            !isset($config['wlcweh']['host'], $config['wlcweh']['username'], $config['wlcweh']['password'])
        ) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Konfiguration fehlt.',
            ], 500);
        }

        $devices = weh_get_user_devices($conn, $uid);
        $nagios = weh_fetch_nagios_states($config);
        $apLoad = weh_load_aps($conn, $nagios['host_states']);
        $apsByHostname = $apLoad['aps_by_hostname'];

        $macs = [];
        foreach ($devices as $device) {
            if (!in_array($device['mac'], $macs, true)) {
                $macs[] = $device['mac'];
            }
        }

        $batchDebug = [
            'mac_count' => count($macs),
        ];
        $batchResult = weh_run_wlc_expect_batch($config['wlcweh'], $macs, $batchDebug, 30);

        $wlcDebug = [
            'mode' => 'single_user',
            'user' => [
                'uid' => (int)$user['uid'],
                'username' => (string)$user['username'],
                'label' => weh_user_display_label($user),
            ],
            'device_count' => count($devices),
            'queried_mac_count' => count($macs),
            'displayed_device_count' => 0,
            'batch_debug' => $batchDebug,
            'attempts' => [],
        ];

        if (!$batchResult['success']) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Abfrage fehlgeschlagen.',
                'debug' => $wlcDebug,
            ], 500);
        }

        $outputsByMac = $batchResult['outputs'] ?? [];
        $deviceResults = [];

        foreach ($devices as $device) {
            $mac = (string)$device['mac'];
            $raw = isset($outputsByMac[$mac]['raw']) ? (string)$outputsByMac[$mac]['raw'] : '';
            $timedOut = !empty($outputsByMac[$mac]['timed_out']);

            $parsed = weh_parse_wlc_client_output($raw, $apsByHostname);
            $estimated = weh_estimate_position($parsed, $apsByHostname);

            $attemptDebug = [
                'mac' => $mac,
                'hostname' => (string)$device['hostname'],
                'timed_out' => $timedOut,
                'raw' => $raw,
                'parsed' => $parsed,
                'estimated' => $estimated,
            ];

            $wlcDebug['attempts'][] = $attemptDebug;

            if ($estimated === null) {
                continue;
            }

            $hoverLabel = trim((string)$device['hostname']) !== '' ? (string)$device['hostname'] : $mac;

            $deviceResults[] = [
                'kind' => 'selected',
                'mac' => $mac,
                'hostname' => (string)$device['hostname'],
                'hover_label' => $hoverLabel,
                'position' => [
                    'x' => (float)$estimated['x'],
                    'y' => (float)$estimated['y'],
                    'z' => (float)$estimated['z'],
                ],
                'primary_ap' => $estimated['primary_ap'] ?? null,
                'used_aps' => $estimated['used_aps'] ?? [],
                'parsed' => $parsed,
                'timed_out' => $timedOut,
                'found_on_wlc' => trim($raw) !== '',
            ];
        }

        $wlcDebug['displayed_device_count'] = count($deviceResults);

        weh_json_response([
            'success' => true,
            'user' => [
                'uid' => (int)$user['uid'],
                'username' => (string)$user['username'],
                'label' => weh_user_display_label($user),
            ],
            'devices' => $deviceResults,
            'debug' => $wlcDebug,
        ]);
    }

    if ($ajaxAction === 'locate_all_users_start') {
        @set_time_limit(120);

        if (
            !isset($config['wlcweh']) ||
            !is_array($config['wlcweh']) ||
            !isset($config['wlcweh']['host'], $config['wlcweh']['username'], $config['wlcweh']['password'])
        ) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Konfiguration fehlt.',
            ], 500);
        }

        $summaryDebug = [];
        $summaryResult = weh_run_wlc_expect_command($config['wlcweh'], 'show client summary', $summaryDebug, 60);

        $debug = [
            'mode' => 'all_users_start',
            'summary_debug' => $summaryDebug,
            'current_client_count' => 0,
            'matched_pid11_device_count' => 0,
            'queue_count' => 0,
        ];

        if (!$summaryResult['success']) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Summary-Abfrage fehlgeschlagen.',
                'debug' => $debug,
            ], 500);
        }

        $currentMacs = weh_parse_wlc_client_summary_macs((string)$summaryResult['command_output']);
        $debug['current_client_count'] = count($currentMacs);

        $userMap = weh_get_pid11_user_map_for_macs($conn, $currentMacs);

        $queue = [];
        foreach ($currentMacs as $mac) {
            if (!isset($userMap[$mac])) {
                continue;
            }

            $queue[] = [
                'mac' => $mac,
                'user_uid' => (int)$userMap[$mac]['uid'],
                'user_label' => (string)$userMap[$mac]['label'],
                'username' => (string)$userMap[$mac]['username'],
                'room' => (int)$userMap[$mac]['room'],
                'hostname' => (string)$userMap[$mac]['hostname'],
            ];
        }

        $debug['matched_pid11_device_count'] = count($userMap);
        $debug['queue_count'] = count($queue);

        weh_json_response([
            'success' => true,
            'total' => count($queue),
            'items' => $queue,
            'debug' => $debug,
        ]);
    }

    if ($ajaxAction === 'locate_all_users_chunk') {
        @set_time_limit(60);

        if (
            !isset($config['wlcweh']) ||
            !is_array($config['wlcweh']) ||
            !isset($config['wlcweh']['host'], $config['wlcweh']['username'], $config['wlcweh']['password'])
        ) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Konfiguration fehlt.',
            ], 500);
        }

        $itemsJson = isset($_POST['items_json']) ? (string)$_POST['items_json'] : '[]';
        $items = json_decode($itemsJson, true);

        if (!is_array($items)) {
            weh_json_response([
                'success' => false,
                'error' => 'Ungültige Chunk-Daten.',
            ], 400);
        }

        $items = array_values(array_filter($items, static function ($item) {
            return is_array($item) && !empty($item['mac']);
        }));

        if (empty($items)) {
            weh_json_response([
                'success' => true,
                'devices' => [],
                'processed_count' => 0,
                'debug' => [
                    'mode' => 'all_users_chunk',
                    'chunk_size' => 0,
                    'attempts' => [],
                ],
            ]);
        }

        $macs = [];
        foreach ($items as $item) {
            $mac = weh_normalize_mac((string)($item['mac'] ?? ''));
            if ($mac !== '' && !in_array($mac, $macs, true)) {
                $macs[] = $mac;
            }
        }

        $apLoad = weh_load_aps($conn, []);
        $apsByHostname = $apLoad['aps_by_hostname'];

        $batchDebug = [
            'mac_count' => count($macs),
        ];
        $batchResult = weh_run_wlc_expect_batch($config['wlcweh'], $macs, $batchDebug, 45);

        $debug = [
            'mode' => 'all_users_chunk',
            'chunk_size' => count($items),
            'queried_mac_count' => count($macs),
            'displayed_device_count' => 0,
            'batch_debug' => $batchDebug,
            'attempts' => [],
        ];

        if (!$batchResult['success']) {
            weh_json_response([
                'success' => false,
                'error' => 'WLC-Chunk-Abfrage fehlgeschlagen.',
                'debug' => $debug,
            ], 500);
        }

        $outputsByMac = $batchResult['outputs'] ?? [];
        $deviceResults = [];

        foreach ($items as $item) {
            $mac = weh_normalize_mac((string)($item['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $raw = isset($outputsByMac[$mac]['raw']) ? (string)$outputsByMac[$mac]['raw'] : '';
            $timedOut = !empty($outputsByMac[$mac]['timed_out']);

            $parsed = weh_parse_wlc_client_output($raw, $apsByHostname);
            $estimated = weh_estimate_position($parsed, $apsByHostname);

            $attemptDebug = [
                'mac' => $mac,
                'user_uid' => isset($item['user_uid']) ? (int)$item['user_uid'] : 0,
                'user_label' => (string)($item['user_label'] ?? ''),
                'hostname' => (string)($item['hostname'] ?? ''),
                'timed_out' => $timedOut,
                'raw' => $raw,
                'parsed' => $parsed,
                'estimated' => $estimated,
            ];

            $debug['attempts'][] = $attemptDebug;

            if ($estimated === null) {
                continue;
            }

            $userLabel = trim((string)($item['user_label'] ?? '')) !== '' ? (string)$item['user_label'] : 'Unbekannter User';
            $hoverLabel = $userLabel . "\n" . $mac;

            $deviceResults[] = [
                'kind' => 'bulk',
                'user_uid' => isset($item['user_uid']) ? (int)$item['user_uid'] : 0,
                'user_label' => $userLabel,
                'username' => (string)($item['username'] ?? ''),
                'room' => isset($item['room']) ? (int)$item['room'] : 0,
                'mac' => $mac,
                'hostname' => (string)($item['hostname'] ?? ''),
                'hover_label' => $hoverLabel,
                'position' => [
                    'x' => (float)$estimated['x'],
                    'y' => (float)$estimated['y'],
                    'z' => (float)$estimated['z'],
                ],
                'primary_ap' => $estimated['primary_ap'] ?? null,
                'used_aps' => $estimated['used_aps'] ?? [],
                'parsed' => $parsed,
                'timed_out' => $timedOut,
                'found_on_wlc' => trim($raw) !== '',
            ];
        }

        $debug['displayed_device_count'] = count($deviceResults);

        weh_json_response([
            'success' => true,
            'devices' => $deviceResults,
            'processed_count' => count($items),
            'debug' => $debug,
        ]);
    }

    weh_json_response([
        'success' => false,
        'error' => 'Unbekannte AJAX-Action.',
    ], 400);
}
?>
<!DOCTYPE html>
<html lang="de">
    <title>Karte des Rumtreibers</title>
<?php
require('template.php');

if (auth($conn) && (isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true)) {
    $nagios = weh_fetch_nagios_states($config);
    $apLoad = weh_load_aps($conn, $nagios['host_states']);

    $aps = $apLoad['aps'];
    $roomApCount = $apLoad['room_ap_count'];
    $kaminApCount = $apLoad['kamin_ap_count'];
    $buildingApCount = $apLoad['building_ap_count'];
    $unmatchedApHostnames = $apLoad['unmatched_ap_hostnames'];

    $nagiosDebugSummary = [
        'nagios_url' => $nagios['url'],
        'nagios_error' => $nagios['error'],
        'nagios_response_length' => strlen($nagios['response']),
        'nagios_host_count' => count($nagios['host_states']),
        'nagios_downhosts_count' => count($nagios['downhosts']),
        'nagios_downhosts' => $nagios['downhosts'],
        'aps_loaded_from_db' => count($aps),
        'room_aps_count' => $roomApCount,
        'kamin_aps_count' => $kaminApCount,
        'building_aps_count' => $buildingApCount,
        'unmatched_ap_hostnames' => $unmatchedApHostnames,
    ];

    $apsJson = json_encode($aps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nagiosDebugSummaryJson = json_encode($nagiosDebugSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<head>
    <meta charset="utf-8">
    <title>Turm 3D</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #eef2f7;
            font-family: Arial, Helvetica, sans-serif;
        }

        #towerCanvas {
            display: block;
            width: 100vw;
            height: 100vh;
        }

        .tower-controls {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 20;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(8px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10);
        }

        .tower-controls-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .tower-controls label,
        .tower-controls select,
        .tower-controls button,
        .tower-controls input,
        .tower-controls span {
            font-size: 14px;
        }

        .tower-controls .check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .tower-controls .range {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tower-controls select,
        .tower-controls input[type="text"] {
            padding: 6px 8px;
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 8px;
            background: #fff;
            box-sizing: border-box;
        }

        .tower-controls button {
            padding: 7px 12px;
            border: 0;
            border-radius: 8px;
            background: #4b5563;
            color: #fff;
            cursor: pointer;
        }

        .tower-controls button.is-running {
            background: #b91c1c;
        }

        .view-select {
            min-width: 140px;
        }

        .user-search-wrap {
            position: relative;
            width: 420px;
        }

        .user-search-input {
            width: 100%;
            padding-right: 36px !important;
        }

        .user-search-input.is-selected {
            background: #e5eefc;
            border-color: rgba(37, 99, 235, 0.28);
            cursor: pointer;
        }

        .user-inline-spinner {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid rgba(59, 130, 246, 0.25);
            border-top-color: #2563eb;
            animation: spin 0.8s linear infinite;
            display: none;
            pointer-events: none;
        }

        .user-inline-spinner.is-active {
            display: block;
        }

        .user-suggestions {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 30;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.10);
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
            max-height: 320px;
            overflow: auto;
            display: none;
        }

        .user-suggestions.is-open {
            display: block;
        }

        .user-suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .user-suggestion-item:last-child {
            border-bottom: 0;
        }

        .user-suggestion-item:hover {
            background: #f3f4f6;
        }

        .all-users-progress {
            min-width: 84px;
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: #111827;
        }

        @keyframes spin {
            to {
                transform: translateY(-50%) rotate(360deg);
            }
        }

        .modal {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.50);
            padding: 24px;
            box-sizing: border-box;
        }

        .modal.is-open {
            display: flex;
        }

        .modal-card {
            width: min(1200px, calc(100vw - 48px));
            max-height: calc(100vh - 48px);
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.22);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .modal-close {
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            background: #111827;
            color: #fff;
            cursor: pointer;
        }

        .modal-body {
            overflow: auto;
            padding: 18px;
            display: grid;
            gap: 18px;
        }

        .debug-block h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #111827;
        }

        .debug-block pre {
            margin: 0;
            padding: 14px;
            border-radius: 10px;
            background: #0f172a;
            color: #e5eefc;
            font-size: 12px;
            line-height: 1.45;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .hover-tooltip {
            position: fixed;
            z-index: 60;
            pointer-events: none;
            display: none;
            padding: 6px 8px;
            border-radius: 8px;
            background: rgba(17, 24, 39, 0.92);
            color: #fff;
            font-size: 12px;
            white-space: pre-line;
        }

        @media (max-width: 768px) {
            .tower-controls {
                top: calc(env(safe-area-inset-top, 0px) + 10px);
                left: 8px;
                right: 8px;
                width: auto;
                max-width: none;
                padding: 10px;
                gap: 8px;
                border-radius: 14px;
            }

            .tower-controls-row {
                gap: 8px;
            }

            .tower-controls label,
            .tower-controls select,
            .tower-controls button,
            .tower-controls input,
            .tower-controls span {
                font-size: 12px;
            }

            .tower-controls button {
                padding: 7px 10px;
            }

            .tower-controls .check {
                gap: 5px;
                min-width: 0;
                white-space: normal;
            }

            .tower-controls .range {
                width: 100%;
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
                gap: 8px;
                align-items: center;
            }

            .tower-controls .range select,
            .tower-controls .range button {
                min-width: 0;
            }

            .tower-controls-row:nth-child(1) {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                align-items: center;
            }

            .tower-controls-row:nth-child(1) > * {
                min-width: 0;
            }

            .tower-controls-row:nth-child(2) {
                flex-direction: column;
                align-items: stretch;
            }

            .view-select {
                width: 100%;
                min-width: 0;
            }

            .user-search-wrap {
                width: 100%;
            }

            .tower-controls-row:nth-child(4) {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
            }

            .tower-controls-row:nth-child(4) > button {
                min-width: 0;
                width: 100%;
            }

            .all-users-progress {
                min-width: 0;
                text-align: right;
            }

            .tower-controls-row:nth-child(5) {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
                align-items: center;
            }

            .tower-controls-row:nth-child(5) .check {
                min-width: 0;
                white-space: nowrap;
                gap: 4px;
                font-size: 11px;
                line-height: 1.1;
                align-items: center;
            }

            .tower-controls-row:nth-child(5) .check {
                align-items: flex-start;
            }
        }

    </style>

    <script async src="https://unpkg.com/es-module-shims@1.10.0/dist/es-module-shims.js"></script>
    <script type="importmap">
    {
        "imports": {
            "three": "https://unpkg.com/three@0.161.0/build/three.module.js",
            "three/addons/": "https://unpkg.com/three@0.161.0/examples/jsm/"
        }
    }
    </script>
</head>
<body>
    <div class="tower-controls">
        <div class="tower-controls-row" style="display:none;">
            <button type="button" id="openNagiosDebug">Nagios Debug</button>
            <button type="button" id="openWlcDebug">WLC Debug</button>
            <label class="check">
                <input type="checkbox" id="toggleOrigin">
                Ursprung
            </label>
            <label class="check">
                <input type="checkbox" id="toggleFloorLabels" checked>
                Etagenbeschriftung
            </label>
        </div>

        <div class="tower-controls-row">
            <select id="towerViewMode" class="view-select">
                <option value="grid">Gitter</option>
                <option value="blocks">Blöcke</option>
                <option value="points">Punkte</option>
                <option value="solid">Einheit</option>
                <option value="plates">Etagenplatten</option>
                <option value="transparent">Transparent</option>
            </select>

            <div class="range">
                <select id="floorFrom"></select>
                <select id="floorTo"></select>
                <button type="button" id="resetFloors">Reset</button>
            </div>
        </div>

        <div class="tower-controls-row">
            <div class="user-search-wrap">
                <input type="text" id="userSearchInput" class="user-search-input" placeholder="User suchen: Name, Username oder Zimmer">
                <span id="userInlineSpinner" class="user-inline-spinner"></span>
                <div id="userSuggestions" class="user-suggestions"></div>
            </div>
        </div>

        <div class="tower-controls-row">
            <button type="button" id="loadAllUserDevicesButton">Alle Devices abfragen</button>
            <span id="allUserDevicesProgress" class="all-users-progress">0/0</span>
        </div>

        <div class="tower-controls-row">
            <label class="check">
                <input type="checkbox" id="toggleRoomAps">
                Zimmer-APs
            </label>

            <label class="check">
                <input type="checkbox" id="toggleKaminAps">
                Kamin-APs
            </label>

            <label class="check">
                <input type="checkbox" id="toggleBuildingAps">
                Gebäude-APs
            </label>

            <label class="check" style="display:none;">
                <input type="checkbox" id="toggleUserDevices">
                User-Devices
            </label>
        </div>
    </div>

    <div class="modal" id="nagiosModal">
        <div class="modal-card">
            <div class="modal-head">
                <h2 class="modal-title">Nagios Debug</h2>
                <button type="button" class="modal-close" id="closeNagiosModal">Schließen</button>
            </div>
            <div class="modal-body">
                <div class="debug-block">
                    <h3>Zusammenfassung</h3>
                    <pre><?php echo htmlspecialchars((string)$nagiosDebugSummaryJson, ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
                <div class="debug-block">
                    <h3>Raw Response</h3>
                    <pre><?php echo htmlspecialchars((string)$nagios['response'], ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="wlcModal">
        <div class="modal-card">
            <div class="modal-head">
                <h2 class="modal-title">WLC Debug</h2>
                <button type="button" class="modal-close" id="closeWlcModal">Schließen</button>
            </div>
            <div class="modal-body">
                <div class="debug-block">
                    <h3>Letzte Abfrage</h3>
                    <pre id="wlcDebugPre">Noch keine WLC-Abfrage durchgeführt.</pre>
                </div>
            </div>
        </div>
    </div>

    <div id="hoverTooltip" class="hover-tooltip"></div>
    <canvas id="towerCanvas"></canvas>

    <script type="module">
        import * as THREE from 'three';
        import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
        import * as BufferGeometryUtils from 'three/addons/utils/BufferGeometryUtils.js';

        const APS_DATA = <?php echo $apsJson ?: '[]'; ?>;
        const AJAX_URL = window.location.pathname;

        const GRID_X = 11;
        const GRID_Z = 8;
        const BLOCK_SIZE = 1;
        const BLOCK_GAP = 0.04;
        const AP_RADIUS = 2.0;
        const LOCATE_TIMEOUT_MS = 30000;
        const ALL_USERS_CHUNK_SIZE = 10;

        const FLOOR_DEFS = [
            { value: -3, label: 'Tiefkeller', short: 'Tiefkeller' },
            { value: -2, label: 'Keller', short: 'Keller' },
            { value: -1, label: 'EG', short: 'EG' },
            { value: 0,  label: '0. Etage', short: '0' },
            { value: 1,  label: '1. Etage', short: '1' },
            { value: 2,  label: '2. Etage', short: '2' },
            { value: 3,  label: '3. Etage', short: '3' },
            { value: 4,  label: '4. Etage', short: '4' },
            { value: 5,  label: '5. Etage', short: '5' },
            { value: 6,  label: '6. Etage', short: '6' },
            { value: 7,  label: '7. Etage', short: '7' },
            { value: 8,  label: '8. Etage', short: '8' },
            { value: 9,  label: '9. Etage', short: '9' },
            { value: 10, label: '10. Etage', short: '10' },
            { value: 11, label: '11. Etage', short: '11' },
            { value: 12, label: '12. Etage', short: '12' },
            { value: 13, label: '13. Etage', short: '13' },
            { value: 14, label: '14. Etage', short: '14' },
            { value: 15, label: '15. Etage', short: '15' },
            { value: 16, label: '16. Etage', short: '16' },
            { value: 17, label: '17. Etage', short: '17' }
        ];

        const FLOOR_MIN = FLOOR_DEFS[0].value;
        const FLOOR_MAX = FLOOR_DEFS[FLOOR_DEFS.length - 1].value;
        const IS_MOBILE_LAYOUT = window.matchMedia('(max-width: 768px)').matches;
        const MOBILE_CAMERA_Y_OFFSET = IS_MOBILE_LAYOUT ? 2.2 : 0;

        const towerViewMode = document.getElementById('towerViewMode');
        const floorFromSelect = document.getElementById('floorFrom');
        const floorToSelect = document.getElementById('floorTo');
        const resetFloorsButton = document.getElementById('resetFloors');

        const userSearchInput = document.getElementById('userSearchInput');
        const userSuggestions = document.getElementById('userSuggestions');
        const userInlineSpinner = document.getElementById('userInlineSpinner');

        const loadAllUserDevicesButton = document.getElementById('loadAllUserDevicesButton');
        const allUserDevicesProgress = document.getElementById('allUserDevicesProgress');

        const toggleRoomAps = document.getElementById('toggleRoomAps');
        const toggleKaminAps = document.getElementById('toggleKaminAps');
        const toggleBuildingAps = document.getElementById('toggleBuildingAps');
        const toggleUserDevices = document.getElementById('toggleUserDevices');
        const toggleOrigin = document.getElementById('toggleOrigin');
        const toggleFloorLabels = document.getElementById('toggleFloorLabels');

        const openNagiosDebugButton = document.getElementById('openNagiosDebug');
        const openWlcDebugButton = document.getElementById('openWlcDebug');
        const nagiosModal = document.getElementById('nagiosModal');
        const closeNagiosModal = document.getElementById('closeNagiosModal');
        const wlcModal = document.getElementById('wlcModal');
        const closeWlcModal = document.getElementById('closeWlcModal');
        const wlcDebugPre = document.getElementById('wlcDebugPre');

        const hoverTooltip = document.getElementById('hoverTooltip');

        let selectedUser = null;
        let currentSearchAbortController = null;
        let currentLocateAbortController = null;
        let currentAllUsersAbortController = null;
        let latestWlcDebug = null;
        let searchDebounceTimer = null;

        let selectedDeviceMeshes = [];
        let bulkDeviceMeshes = [];
        let apGroups = [];
        let apHoverMeshes = [];
        let floorLabelSprites = [];

        let allUsersRunState = null;

        for (const floor of FLOOR_DEFS) {
            const optionFrom = document.createElement('option');
            optionFrom.value = String(floor.value);
            optionFrom.textContent = floor.label;
            floorFromSelect.appendChild(optionFrom);

            const optionTo = document.createElement('option');
            optionTo.value = String(floor.value);
            optionTo.textContent = floor.label;
            floorToSelect.appendChild(optionTo);
        }

        floorFromSelect.value = String(FLOOR_MIN);
        floorToSelect.value = String(FLOOR_MAX);
        towerViewMode.value = 'grid';

        const canvas = document.getElementById('towerCanvas');

        const renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true
        });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.outputColorSpace = THREE.SRGBColorSpace;

        const scene = new THREE.Scene();
        scene.background = new THREE.Color(0xeef2f7);

        const camera = new THREE.PerspectiveCamera(
            50,
            window.innerWidth / window.innerHeight,
            0.1,
            2000
        );
        camera.position.set(
            IS_MOBILE_LAYOUT ? 22 : 19,
            IS_MOBILE_LAYOUT ? 13.5 : 11,
            IS_MOBILE_LAYOUT ? 24 : 20
        );

        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.08;
        controls.target.set(5.5, 7 + MOBILE_CAMERA_Y_OFFSET, GRID_Z / 2);
        controls.minDistance = 8;
        controls.maxDistance = 140;
        controls.maxPolarAngle = Math.PI;
        controls.enablePan = true;
        controls.screenSpacePanning = true;
        controls.panSpeed = 1.0;
        controls.mouseButtons = {
            LEFT: THREE.MOUSE.ROTATE,
            MIDDLE: THREE.MOUSE.PAN,
            RIGHT: THREE.MOUSE.PAN
        };

        const ambientLight = new THREE.AmbientLight(0xffffff, 1.4);
        scene.add(ambientLight);

        const lightA = new THREE.DirectionalLight(0xffffff, 0.95);
        lightA.position.set(28, 35, 24);
        scene.add(lightA);

        const lightB = new THREE.DirectionalLight(0xffffff, 0.35);
        lightB.position.set(-16, 12, -18);
        scene.add(lightB);

        function isCornerCell(x, z) {
            const isMainTowerCorner =
                (
                    (x === 0 && z === 0) ||
                    (x === 9 && z === 0) ||
                    (x === 0 && z === GRID_Z - 1) ||
                    (x === 9 && z === GRID_Z - 1)
                );

            const isExtensionColumn = (x === 10 && z >= 1 && z <= 6);

            if (isExtensionColumn) {
                return false;
            }

            if (x === 10) {
                return true;
            }

            return isMainTowerCorner;
        }

        const instanceDefs = [];
        for (const floor of FLOOR_DEFS) {
            for (let z = 0; z < GRID_Z; z++) {
                for (let x = 0; x < GRID_X; x++) {
                    if (isCornerCell(x, z)) {
                        continue;
                    }

                    instanceDefs.push({
                        floor: floor.value,
                        x,
                        z
                    });
                }
            }
        }

        function getVisibleDefs(minFloor, maxFloor) {
            return instanceDefs.filter((def) => def.floor >= minFloor && def.floor <= maxFloor);
        }

        function getCellKey(floor, x, z) {
            return floor + '|' + x + '|' + z;
        }

        function createSurfaceGeometry(visibleDefs) {
            if (!visibleDefs.length) {
                return new THREE.BufferGeometry();
            }

            const cellSet = new Set();
            for (const def of visibleDefs) {
                cellSet.add(getCellKey(def.floor, def.x, def.z));
            }

            const positions = [];
            const normals = [];
            const indices = [];
            let currentIndex = 0;

            function pushFace(a, b, c, d, nx, ny, nz) {
                positions.push(...a, ...b, ...c, ...d);
                normals.push(nx, ny, nz, nx, ny, nz, nx, ny, nz, nx, ny, nz);
                indices.push(
                    currentIndex, currentIndex + 1, currentIndex + 2,
                    currentIndex, currentIndex + 2, currentIndex + 3
                );
                currentIndex += 4;
            }

            for (const def of visibleDefs) {
                const x0 = def.x;
                const x1 = def.x + 1;
                const y0 = def.floor;
                const y1 = def.floor + 1;
                const z0 = def.z;
                const z1 = def.z + 1;

                if (!cellSet.has(getCellKey(def.floor, def.x - 1, def.z))) {
                    pushFace(
                        [x0, y0, z0],
                        [x0, y0, z1],
                        [x0, y1, z1],
                        [x0, y1, z0],
                        -1, 0, 0
                    );
                }

                if (!cellSet.has(getCellKey(def.floor, def.x + 1, def.z))) {
                    pushFace(
                        [x1, y0, z1],
                        [x1, y0, z0],
                        [x1, y1, z0],
                        [x1, y1, z1],
                        1, 0, 0
                    );
                }

                if (!cellSet.has(getCellKey(def.floor - 1, def.x, def.z))) {
                    pushFace(
                        [x0, y0, z0],
                        [x1, y0, z0],
                        [x1, y0, z1],
                        [x0, y0, z1],
                        0, -1, 0
                    );
                }

                if (!cellSet.has(getCellKey(def.floor + 1, def.x, def.z))) {
                    pushFace(
                        [x0, y1, z1],
                        [x1, y1, z1],
                        [x1, y1, z0],
                        [x0, y1, z0],
                        0, 1, 0
                    );
                }

                if (!cellSet.has(getCellKey(def.floor, def.x, def.z - 1))) {
                    pushFace(
                        [x0, y0, z0],
                        [x0, y1, z0],
                        [x1, y1, z0],
                        [x1, y0, z0],
                        0, 0, -1
                    );
                }

                if (!cellSet.has(getCellKey(def.floor, def.x, def.z + 1))) {
                    pushFace(
                        [x1, y0, z1],
                        [x1, y1, z1],
                        [x0, y1, z1],
                        [x0, y0, z1],
                        0, 0, 1
                    );
                }
            }

            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
            geometry.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
            geometry.setIndex(indices);
            geometry.computeBoundingSphere();

            return geometry;
        }

        function createPointsGeometry(visibleDefs) {
            const geometry = new THREE.BufferGeometry();

            if (!visibleDefs.length) {
                geometry.setAttribute('position', new THREE.Float32BufferAttribute([], 3));
                return geometry;
            }

            const positions = [];
            for (const def of visibleDefs) {
                positions.push(def.x + 0.5, def.floor + 0.5, def.z + 0.5);
            }

            geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
            geometry.computeBoundingSphere();

            return geometry;
        }

        function createPlatesGeometry(visibleDefs) {
            if (!visibleDefs.length) {
                return new THREE.BufferGeometry();
            }

            const geoms = [];
            const plateKeys = new Set();
            const plateThickness = 0.06;

            for (const def of visibleDefs) {
                const boundaryYs = [def.floor, def.floor + 1];

                for (const boundaryY of boundaryYs) {
                    const key = boundaryY + '|' + def.x + '|' + def.z;
                    if (plateKeys.has(key)) {
                        continue;
                    }

                    plateKeys.add(key);

                    const g = new THREE.BoxGeometry(1, plateThickness, 1);
                    g.translate(def.x + 0.5, boundaryY, def.z + 0.5);
                    geoms.push(g);
                }
            }

            const merged = BufferGeometryUtils.mergeGeometries(geoms, false) || new THREE.BufferGeometry();
            for (const g of geoms) {
                g.dispose();
            }

            return merged;
        }

        const referencePlane = new THREE.Mesh(
            new THREE.PlaneGeometry(GRID_X * 3, GRID_Z * 3),
            new THREE.MeshStandardMaterial({
                color: 0x9aa6b2,
                transparent: true,
                opacity: 0.22,
                depthWrite: false
            })
        );
        referencePlane.rotation.x = -Math.PI / 2;
        referencePlane.position.set(GRID_X / 2, -1.5, GRID_Z / 2);
        scene.add(referencePlane);

        function createTextSprite(text, {
            fontSize = 72,
            paddingX = 24,
            paddingY = 14,
            textColor = '#233142',
            worldHeight = 0.42,
            fontWeight = 700,
            fontFamily = 'Arial'
        } = {}) {
            const measureCanvas = document.createElement('canvas');
            const measureCtx = measureCanvas.getContext('2d');

            measureCtx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
            const metrics = measureCtx.measureText(text);

            const textWidth = Math.ceil(metrics.width);
            const textHeight = Math.ceil(fontSize);

            const logicalWidth = textWidth + paddingX * 2;
            const logicalHeight = textHeight + paddingY * 2;

            const renderScale = 4;
            const canvas = document.createElement('canvas');
            canvas.width = logicalWidth * renderScale;
            canvas.height = logicalHeight * renderScale;

            const ctx = canvas.getContext('2d');
            ctx.scale(renderScale, renderScale);
            ctx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = textColor;
            ctx.clearRect(0, 0, logicalWidth, logicalHeight);
            ctx.fillText(text, logicalWidth / 2, logicalHeight / 2);

            const texture = new THREE.CanvasTexture(canvas);
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.minFilter = THREE.LinearFilter;
            texture.magFilter = THREE.LinearFilter;
            texture.generateMipmaps = true;
            texture.anisotropy = renderer.capabilities.getMaxAnisotropy();

            const material = new THREE.SpriteMaterial({
                map: texture,
                transparent: true,
                depthTest: false,
                depthWrite: false
            });

            const sprite = new THREE.Sprite(material);
            const aspect = logicalWidth / logicalHeight;
            sprite.scale.set(worldHeight * aspect, worldHeight, 1);
            sprite.renderOrder = 999;

            return sprite;
        }

        const originGroup = new THREE.Group();
        originGroup.visible = false;
        scene.add(originGroup);

        const floorLabelGroup = new THREE.Group();
        scene.add(floorLabelGroup);

        const axesHelper = new THREE.AxesHelper(14);
        originGroup.add(axesHelper);

        const originDot = new THREE.Mesh(
            new THREE.SphereGeometry(0.10, 16, 16),
            new THREE.MeshBasicMaterial({ color: 0x111111 })
        );
        originGroup.add(originDot);

        const xLabel = createTextSprite('X', {
            fontSize: 84,
            textColor: '#d62828',
            worldHeight: 0.54
        });
        xLabel.position.set(15.5, 0.2, 0);
        originGroup.add(xLabel);

        const zLabel = createTextSprite('Z', {
            fontSize: 84,
            textColor: '#1d4ed8',
            worldHeight: 0.54
        });
        zLabel.position.set(0, 0.2, 12.5);
        originGroup.add(zLabel);

        for (let x = 0; x <= GRID_X; x++) {
            const marker = createTextSprite(String(x), {
                fontSize: 72,
                textColor: '#b91c1c',
                worldHeight: 0.40
            });
            marker.position.set(x, 0.22, -0.28);
            originGroup.add(marker);
        }

        for (let z = 0; z <= GRID_Z; z++) {
            const marker = createTextSprite(String(z), {
                fontSize: 72,
                textColor: '#1d4ed8',
                worldHeight: 0.40
            });
            marker.position.set(-0.28, 0.22, z);
            originGroup.add(marker);
        }

        for (const floor of FLOOR_DEFS) {
            const marker = createTextSprite(floor.short, {
                fontSize: 150,
                paddingX: 28,
                paddingY: 16,
                textColor: '#000',
                worldHeight: 0.42
            });

            marker.position.set(GRID_X + 0.85, floor.value + 0.5, GRID_Z + 0.55);
            marker.userData = {
                floorValue: floor.value
            };

            floorLabelGroup.add(marker);
            floorLabelSprites.push(marker);
        }

        const towerGroup = new THREE.Group();
        scene.add(towerGroup);

        const blockGeometry = new THREE.BoxGeometry(
            BLOCK_SIZE - BLOCK_GAP,
            BLOCK_SIZE - BLOCK_GAP,
            BLOCK_SIZE - BLOCK_GAP
        );

        const blockMaterial = new THREE.MeshStandardMaterial({
            color: 0x8b9098,
            roughness: 0.68,
            metalness: 0.04,
            transparent: true,
            opacity: 0.18,
            depthWrite: false
        });

        const blocks = new THREE.InstancedMesh(
            blockGeometry,
            blockMaterial,
            instanceDefs.length
        );
        blocks.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        towerGroup.add(blocks);

        const solidDepthMesh = new THREE.Mesh(
            new THREE.BufferGeometry(),
            new THREE.MeshBasicMaterial({
                visible: false
            })
        );
        solidDepthMesh.visible = false;
        solidDepthMesh.renderOrder = 0;
        solidDepthMesh.frustumCulled = false;
        towerGroup.add(solidDepthMesh);

        const solidMesh = new THREE.Mesh(
            new THREE.BufferGeometry(),
            new THREE.MeshBasicMaterial({
                color: 0x7f8791,
                transparent: true,
                opacity: 0.10,
                depthWrite: false,
                depthTest: false,
                side: THREE.DoubleSide
            })
        );
        solidMesh.visible = false;
        solidMesh.renderOrder = 2000;
        solidMesh.frustumCulled = false;
        towerGroup.add(solidMesh);

        const gridLines = new THREE.LineSegments(
            new THREE.BufferGeometry(),
            new THREE.LineBasicMaterial({
                color: 0x6b7280,
                transparent: true,
                opacity: 0.75
            })
        );
        gridLines.visible = false;
        towerGroup.add(gridLines);

        const pointsCloud = new THREE.Points(
            new THREE.BufferGeometry(),
            new THREE.PointsMaterial({
                color: 0x6b7280,
                size: 0.14,
                transparent: true,
                opacity: 0.80,
                sizeAttenuation: true
            })
        );
        pointsCloud.visible = false;
        towerGroup.add(pointsCloud);

        const platesMesh = new THREE.Mesh(
            new THREE.BufferGeometry(),
            new THREE.MeshStandardMaterial({
                color: 0x7f8791,
                roughness: 0.65,
                metalness: 0.04,
                transparent: true,
                opacity: 0.28,
                side: THREE.DoubleSide
            })
        );
        platesMesh.visible = false;
        towerGroup.add(platesMesh);

        const matrix = new THREE.Matrix4();

        function setGeometry(target, geometry) {
            if (target.geometry) {
                target.geometry.dispose();
            }
            target.geometry = geometry;
        }

        function updateBlocksFromDefs(visibleDefs) {
            let visibleCount = 0;

            for (const def of visibleDefs) {
                matrix.makeTranslation(
                    def.x + 0.5,
                    def.floor + 0.5,
                    def.z + 0.5
                );
                blocks.setMatrixAt(visibleCount, matrix);
                visibleCount++;
            }

            blocks.count = visibleCount;
            blocks.instanceMatrix.needsUpdate = true;
        }

        function updateTowerModeVisibility() {
            const mode = towerViewMode.value;

            blocks.visible = (mode === 'blocks');
            solidDepthMesh.visible = false;
            solidMesh.visible = (mode === 'solid');
            gridLines.visible = (mode === 'grid');
            pointsCloud.visible = (mode === 'points');
            platesMesh.visible = (mode === 'plates');
        }

        function rebuildTowerGeometry(minFloor, maxFloor) {
            const visibleDefs = getVisibleDefs(minFloor, maxFloor);

            updateBlocksFromDefs(visibleDefs);

            const surfaceGeometry = createSurfaceGeometry(visibleDefs);
            setGeometry(solidMesh, surfaceGeometry);
            setGeometry(solidDepthMesh, surfaceGeometry.clone());

            const edgesGeometry = (surfaceGeometry.getAttribute('position') && surfaceGeometry.getAttribute('position').count > 0)
                ? new THREE.EdgesGeometry(surfaceGeometry)
                : new THREE.BufferGeometry();
            setGeometry(gridLines, edgesGeometry);

            const pointsGeometry = createPointsGeometry(visibleDefs);
            setGeometry(pointsCloud, pointsGeometry);

            const platesGeometry = createPlatesGeometry(visibleDefs);
            setGeometry(platesMesh, platesGeometry);

            updateTowerModeVisibility();
        }

        const apGroup = new THREE.Group();
        scene.add(apGroup);

        const AP_SHELLS = [
            { radiusFactor: 0.08, opacity: 0.80, hoverable: true },
            { radiusFactor: 0.16, opacity: 0.50, hoverable: true },
            { radiusFactor: 0.32, opacity: 0.15, hoverable: false },
            { radiusFactor: 0.52, opacity: 0.10, hoverable: false },
            { radiusFactor: 0.70, opacity: 0.06, hoverable: false },
            { radiusFactor: 0.86, opacity: 0.03, hoverable: false },
            { radiusFactor: 1.00, opacity: 0.01, hoverable: false }
        ];

        const AP_STATE_COLORS = {
            online: 0x16a34a,
            down: 0xdc2626,
            unknown: 0xdc2626
        };

        function createApAura(ap, state) {
            const group = new THREE.Group();
            const color = AP_STATE_COLORS[state] ?? AP_STATE_COLORS.unknown;

            const hoverLabel =
                (ap.hostname && String(ap.hostname).trim() !== '')
                    ? String(ap.hostname).trim()
                    : (
                        (ap.beschreibung && String(ap.beschreibung).trim() !== '')
                            ? String(ap.beschreibung).trim()
                            : 'AP'
                    );

            group.userData = {
                ...ap,
                type: 'ap',
                hoverLabel
            };

            for (const shell of AP_SHELLS) {
                const geometry = new THREE.SphereGeometry(AP_RADIUS * shell.radiusFactor, 24, 24);
                const material = new THREE.MeshBasicMaterial({
                    color,
                    transparent: true,
                    opacity: shell.opacity,
                    depthWrite: false
                });

                const mesh = new THREE.Mesh(geometry, material);

                if (shell.hoverable) {
                    mesh.userData = {
                        ...group.userData
                    };
                    apHoverMeshes.push(mesh);
                } else {
                    mesh.raycast = function () {};
                    mesh.userData = {
                        type: 'ap_non_hover'
                    };
                }

                group.add(mesh);
            }

            return group;
        }

        for (const ap of APS_DATA) {
            const state =
                ap.nagios_state === 'online'
                    ? 'online'
                    : (ap.nagios_state === 'down' ? 'down' : 'unknown');

            const aura = createApAura(ap, state);
            aura.position.set(ap.x, ap.y, ap.z);

            apGroup.add(aura);
            apGroups.push(aura);
        }

        const selectedDeviceGroup = new THREE.Group();
        scene.add(selectedDeviceGroup);

        const bulkDeviceGroup = new THREE.Group();
        scene.add(bulkDeviceGroup);

        function clearSelectedDeviceMarkers() {
            for (const mesh of selectedDeviceMeshes) {
                selectedDeviceGroup.remove(mesh);
                mesh.geometry.dispose?.();
                mesh.material.dispose?.();
            }
            selectedDeviceMeshes = [];
            hoverTooltip.style.display = 'none';
        }

        function clearBulkDeviceMarkers() {
            for (const mesh of bulkDeviceMeshes) {
                bulkDeviceGroup.remove(mesh);
                mesh.geometry.dispose?.();
                mesh.material.dispose?.();
            }
            bulkDeviceMeshes = [];
            hoverTooltip.style.display = 'none';
        }

        function createSelectedDeviceMarker(device) {
            const geometry = new THREE.SphereGeometry(0.22, 20, 20);
            const material = new THREE.MeshStandardMaterial({
                color: 0x2563eb,
                emissive: 0x1d4ed8,
                emissiveIntensity: 0.25,
                roughness: 0.35,
                metalness: 0.10
            });

            const mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(device.position.x, device.position.y, device.position.z);
            mesh.userData = {
                type: 'device_selected',
                hoverLabel: device.hover_label,
                mac: device.mac,
                hostname: device.hostname
            };

            selectedDeviceGroup.add(mesh);
            selectedDeviceMeshes.push(mesh);
        }

        function createBulkDeviceMarker(device) {
            const geometry = new THREE.SphereGeometry(0.16, 18, 18);
            const material = new THREE.MeshStandardMaterial({
                color: 0x2563eb,
                emissive: 0x1d4ed8,
                emissiveIntensity: 0.18,
                roughness: 0.40,
                metalness: 0.08
            });

            const mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(device.position.x, device.position.y, device.position.z);
            mesh.userData = {
                type: 'device_bulk',
                hoverLabel: device.hover_label,
                mac: device.mac,
                hostname: device.hostname,
                userLabel: device.user_label
            };

            bulkDeviceGroup.add(mesh);
            bulkDeviceMeshes.push(mesh);
        }

        function getSelectedRange() {
            let minFloor = parseInt(floorFromSelect.value, 10);
            let maxFloor = parseInt(floorToSelect.value, 10);

            if (minFloor > maxFloor) {
                const tmp = minFloor;
                minFloor = maxFloor;
                maxFloor = tmp;
            }

            return { minFloor, maxFloor };
        }

        function updateOriginVisibility() {
            originGroup.visible = toggleOrigin.checked;
        }

        function updateFloorLabelsVisibility(minFloor, maxFloor) {
            floorLabelGroup.visible = toggleFloorLabels.checked;

            for (const sprite of floorLabelSprites) {
                const floorValue = Number(sprite.userData.floorValue);
                sprite.visible = toggleFloorLabels.checked && floorValue >= minFloor && floorValue <= maxFloor;
            }
        }

        function updateApVisibility(minFloor, maxFloor) {
            const showRoomAps = toggleRoomAps.checked;
            const showKaminAps = toggleKaminAps.checked;
            const showBuildingAps = toggleBuildingAps.checked;

            apGroup.visible = showRoomAps || showKaminAps || showBuildingAps;

            for (const group of apGroups) {
                const ap = group.userData;
                const y = group.position.y;

                const categoryVisible =
                    (ap.ap_category === 'room' && showRoomAps) ||
                    (ap.ap_category === 'kamin' && showKaminAps) ||
                    (ap.ap_category === 'building' && showBuildingAps);

                const floorVisible =
                    (y + AP_RADIUS) >= minFloor &&
                    (y - AP_RADIUS) <= (maxFloor + 1);

                group.visible = categoryVisible && floorVisible;
            }

            if (!apGroup.visible) {
                hoverTooltip.style.display = 'none';
            }
        }

        function updateDeviceVisibility(minFloor, maxFloor) {
            const showUserDevices = toggleUserDevices.checked;
            const hasSelected = selectedDeviceMeshes.length > 0;
            const hasBulk = bulkDeviceMeshes.length > 0;

            selectedDeviceGroup.visible = showUserDevices && hasSelected;
            bulkDeviceGroup.visible = showUserDevices && hasBulk;

            for (const mesh of selectedDeviceMeshes) {
                const y = mesh.position.y;
                mesh.visible = showUserDevices && y >= minFloor && y <= (maxFloor + 1);
            }

            for (const mesh of bulkDeviceMeshes) {
                const y = mesh.position.y;
                mesh.visible = showUserDevices && y >= minFloor && y <= (maxFloor + 1);
            }

            if (!selectedDeviceGroup.visible && !bulkDeviceGroup.visible) {
                hoverTooltip.style.display = 'none';
            }
        }

        function updateCameraTarget(minFloor, maxFloor) {
            const centerY = (minFloor + maxFloor + 1) / 2;
            controls.target.set(5.5, centerY + MOBILE_CAMERA_Y_OFFSET, GRID_Z / 2);
        }

        function applyFloorSelection() {
            const { minFloor, maxFloor } = getSelectedRange();
            floorFromSelect.value = String(minFloor);
            floorToSelect.value = String(maxFloor);

            rebuildTowerGeometry(minFloor, maxFloor);
            updateFloorLabelsVisibility(minFloor, maxFloor);
            updateApVisibility(minFloor, maxFloor);
            updateDeviceVisibility(minFloor, maxFloor);
            updateCameraTarget(minFloor, maxFloor);
        }

        function setUserLoading(isLoading) {
            userInlineSpinner.classList.toggle('is-active', !!isLoading);
        }

        function setSelectedUser(user) {
            selectedUser = user;

            if (!user) {
                userSearchInput.readOnly = false;
                userSearchInput.value = '';
                userSearchInput.placeholder = 'User suchen: Name, Username oder Zimmer';
                userSearchInput.classList.remove('is-selected');
                return;
            }

            userSearchInput.readOnly = true;
            userSearchInput.value = user.label;
            userSearchInput.placeholder = 'User suchen: Name, Username oder Zimmer';
            userSearchInput.classList.add('is-selected');
        }

        function setAllUsersProgress(done, total) {
            allUserDevicesProgress.textContent = `${done}/${total}`;
        }

        function setAllUsersButtonRunning(isRunning) {
            loadAllUserDevicesButton.classList.toggle('is-running', !!isRunning);
            loadAllUserDevicesButton.textContent = isRunning ? 'Abbrechen' : 'Alle Devices abfragen';
        }

        function abortAllUsersRun() {
            if (allUsersRunState) {
                allUsersRunState.aborted = true;
            }

            if (currentAllUsersAbortController) {
                currentAllUsersAbortController.abort();
                currentAllUsersAbortController = null;
            }

            allUsersRunState = null;
            setAllUsersButtonRunning(false);
        }

        function clearSelectedUser(options = {}) {
            const resetDebug = !!options.resetDebug;

            if (currentLocateAbortController) {
                currentLocateAbortController.abort();
                currentLocateAbortController = null;
            }

            selectedUser = null;
            userSearchInput.readOnly = false;
            userSearchInput.value = '';
            userSearchInput.classList.remove('is-selected');
            userSuggestions.classList.remove('is-open');
            userSuggestions.innerHTML = '';
            clearSelectedDeviceMarkers();
            setUserLoading(false);
            applyFloorSelection();

            if (resetDebug) {
                latestWlcDebug = null;
                wlcDebugPre.textContent = 'Noch keine WLC-Abfrage durchgeführt.';
            }
        }

        function clearAllBulkDevices(options = {}) {
            const resetProgress = options.resetProgress !== false;

            abortAllUsersRun();
            clearBulkDeviceMarkers();

            if (resetProgress) {
                setAllUsersProgress(0, 0);
            }

            applyFloorSelection();
        }

        async function fetchJson(url, options = {}) {
            const response = await fetch(url, options);
            const data = await response.json();
            return data;
        }

        async function searchUsers(query) {
            if (currentSearchAbortController) {
                currentSearchAbortController.abort();
            }

            currentSearchAbortController = new AbortController();

            const url = new URL(AJAX_URL, window.location.origin);
            url.searchParams.set('ajax', '1');
            url.searchParams.set('action', 'search_users');
            url.searchParams.set('q', query);

            const data = await fetchJson(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                signal: currentSearchAbortController.signal
            });

            return data.items || [];
        }

        function renderSuggestions(items) {
            userSuggestions.innerHTML = '';

            if (!items.length) {
                userSuggestions.classList.remove('is-open');
                return;
            }

            for (const item of items) {
                const div = document.createElement('div');
                div.className = 'user-suggestion-item';
                div.textContent = item.label;
                div.addEventListener('click', () => {
                    userSuggestions.classList.remove('is-open');
                    locateUser(item);
                });
                userSuggestions.appendChild(div);
            }

            userSuggestions.classList.add('is-open');
        }

        async function locateUser(user) {
            abortAllUsersRun();
            clearBulkDeviceMarkers();
            setAllUsersProgress(0, 0);

            if (currentLocateAbortController) {
                currentLocateAbortController.abort();
            }

            const abortController = new AbortController();
            currentLocateAbortController = abortController;

            const timeoutId = window.setTimeout(() => {
                abortController.abort();
            }, LOCATE_TIMEOUT_MS);

            setSelectedUser(user);
            setUserLoading(true);
            clearSelectedDeviceMarkers();
            toggleUserDevices.checked = false;
            latestWlcDebug = {
                status: 'running',
                message: 'WLC-Abfrage läuft...'
            };
            wlcDebugPre.textContent = JSON.stringify(latestWlcDebug, null, 2);

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'locate_user');
            formData.append('uid', String(user.uid));

            try {
                const data = await fetchJson(AJAX_URL, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    signal: abortController.signal
                });

                latestWlcDebug = data.debug || { raw_response: data };
                wlcDebugPre.textContent = JSON.stringify(data, null, 2);

                if (!data.success) {
                    toggleUserDevices.checked = false;
                    applyFloorSelection();
                    return;
                }

                setSelectedUser(data.user);

                if (Array.isArray(data.devices)) {
                    for (const device of data.devices) {
                        if (device.position) {
                            createSelectedDeviceMarker(device);
                        }
                    }
                }

                toggleUserDevices.checked = true;
                applyFloorSelection();
            } catch (error) {
                if (error.name === 'AbortError') {
                    latestWlcDebug = {
                        error: 'Timeout nach 30 Sekunden',
                        timeout_ms: LOCATE_TIMEOUT_MS
                    };
                    wlcDebugPre.textContent = JSON.stringify(latestWlcDebug, null, 2);
                } else {
                    latestWlcDebug = {
                        error: String(error)
                    };
                    wlcDebugPre.textContent = JSON.stringify(latestWlcDebug, null, 2);
                }

                toggleUserDevices.checked = false;
                applyFloorSelection();
            } finally {
                window.clearTimeout(timeoutId);
                setUserLoading(false);
            }
        }

        async function startAllUserDevicesRun() {
            if (allUsersRunState && !allUsersRunState.aborted) {
                abortAllUsersRun();
                return;
            }

            clearSelectedUser({ resetDebug: false });
            clearBulkDeviceMarkers();
            setAllUsersProgress(0, 0);
            toggleUserDevices.checked = true;
            applyFloorSelection();

            const runState = {
                aborted: false,
                processed: 0,
                total: 0
            };
            allUsersRunState = runState;
            setAllUsersButtonRunning(true);
            latestWlcDebug = {
                status: 'running',
                message: 'Aktive PID11-Devices werden geladen...'
            };
            wlcDebugPre.textContent = JSON.stringify(latestWlcDebug, null, 2);

            try {
                currentAllUsersAbortController = new AbortController();

                const startForm = new FormData();
                startForm.append('ajax', '1');
                startForm.append('action', 'locate_all_users_start');

                const startData = await fetchJson(AJAX_URL, {
                    method: 'POST',
                    body: startForm,
                    credentials: 'same-origin',
                    signal: currentAllUsersAbortController.signal
                });

                latestWlcDebug = startData.debug || { raw_response: startData };
                wlcDebugPre.textContent = JSON.stringify(startData, null, 2);

                if (runState.aborted) {
                    return;
                }

                if (!startData.success) {
                    toggleUserDevices.checked = false;
                    applyFloorSelection();
                    return;
                }

                const items = Array.isArray(startData.items) ? startData.items : [];
                runState.total = Number(startData.total || items.length || 0);
                runState.processed = 0;
                setAllUsersProgress(runState.processed, runState.total);

                if (runState.total <= 0) {
                    applyFloorSelection();
                    return;
                }

                for (let offset = 0; offset < items.length; offset += ALL_USERS_CHUNK_SIZE) {
                    if (runState.aborted) {
                        break;
                    }

                    const chunkItems = items.slice(offset, offset + ALL_USERS_CHUNK_SIZE);
                    currentAllUsersAbortController = new AbortController();

                    const chunkForm = new FormData();
                    chunkForm.append('ajax', '1');
                    chunkForm.append('action', 'locate_all_users_chunk');
                    chunkForm.append('items_json', JSON.stringify(chunkItems));

                    const chunkData = await fetchJson(AJAX_URL, {
                        method: 'POST',
                        body: chunkForm,
                        credentials: 'same-origin',
                        signal: currentAllUsersAbortController.signal
                    });

                    latestWlcDebug = chunkData.debug || { raw_response: chunkData };
                    wlcDebugPre.textContent = JSON.stringify(chunkData, null, 2);

                    if (runState.aborted) {
                        break;
                    }

                    if (!chunkData.success) {
                        break;
                    }

                    if (Array.isArray(chunkData.devices)) {
                        for (const device of chunkData.devices) {
                            if (device.position) {
                                createBulkDeviceMarker(device);
                            }
                        }
                    }

                    runState.processed += Number(chunkData.processed_count || chunkItems.length || 0);
                    setAllUsersProgress(runState.processed, runState.total);
                    applyFloorSelection();
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    latestWlcDebug = {
                        error: String(error)
                    };
                    wlcDebugPre.textContent = JSON.stringify(latestWlcDebug, null, 2);
                }
            } finally {
                currentAllUsersAbortController = null;
                allUsersRunState = null;
                setAllUsersButtonRunning(false);
                applyFloorSelection();
            }
        }

        userSearchInput.addEventListener('click', () => {
            if (selectedUser !== null) {
                clearSelectedUser();
                userSearchInput.focus();
            }
        });

        userSearchInput.addEventListener('input', () => {
            if (selectedUser !== null) {
                return;
            }

            const query = userSearchInput.value.trim();

            if (searchDebounceTimer) {
                clearTimeout(searchDebounceTimer);
            }

            if (query.length === 0) {
                userSuggestions.classList.remove('is-open');
                userSuggestions.innerHTML = '';
                return;
            }

            searchDebounceTimer = setTimeout(async () => {
                try {
                    const items = await searchUsers(query);
                    renderSuggestions(items);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        userSuggestions.classList.remove('is-open');
                        userSuggestions.innerHTML = '';
                    }
                }
            }, 180);
        });

        document.addEventListener('click', (event) => {
            const insideSearch = userSearchInput.contains(event.target) || userSuggestions.contains(event.target);
            if (!insideSearch) {
                userSuggestions.classList.remove('is-open');
            }
        });

        floorFromSelect.addEventListener('change', applyFloorSelection);
        floorToSelect.addEventListener('change', applyFloorSelection);
        towerViewMode.addEventListener('change', applyFloorSelection);

        resetFloorsButton.addEventListener('click', () => {
            floorFromSelect.value = String(FLOOR_MIN);
            floorToSelect.value = String(FLOOR_MAX);
            applyFloorSelection();
        });

        loadAllUserDevicesButton.addEventListener('click', startAllUserDevicesRun);

        toggleRoomAps.addEventListener('change', () => {
            const { minFloor, maxFloor } = getSelectedRange();
            updateApVisibility(minFloor, maxFloor);
        });

        toggleKaminAps.addEventListener('change', () => {
            const { minFloor, maxFloor } = getSelectedRange();
            updateApVisibility(minFloor, maxFloor);
        });

        toggleBuildingAps.addEventListener('change', () => {
            const { minFloor, maxFloor } = getSelectedRange();
            updateApVisibility(minFloor, maxFloor);
        });

        toggleUserDevices.addEventListener('change', () => {
            const { minFloor, maxFloor } = getSelectedRange();
            updateDeviceVisibility(minFloor, maxFloor);
        });

        toggleOrigin.addEventListener('change', updateOriginVisibility);

        toggleFloorLabels.addEventListener('change', () => {
            const { minFloor, maxFloor } = getSelectedRange();
            updateFloorLabelsVisibility(minFloor, maxFloor);
        });

        openNagiosDebugButton.addEventListener('click', () => {
            nagiosModal.classList.add('is-open');
        });

        openWlcDebugButton.addEventListener('click', () => {
            wlcModal.classList.add('is-open');
        });

        closeNagiosModal.addEventListener('click', () => {
            nagiosModal.classList.remove('is-open');
        });

        closeWlcModal.addEventListener('click', () => {
            wlcModal.classList.remove('is-open');
        });

        nagiosModal.addEventListener('click', (event) => {
            if (event.target === nagiosModal) {
                nagiosModal.classList.remove('is-open');
            }
        });

        wlcModal.addEventListener('click', (event) => {
            if (event.target === wlcModal) {
                wlcModal.classList.remove('is-open');
            }
        });

        const raycaster = new THREE.Raycaster();
        const mouse = new THREE.Vector2();

        function isObjectActuallyVisible(object) {
            let current = object;
            while (current) {
                if (current.visible === false) {
                    return false;
                }
                current = current.parent;
            }
            return true;
        }

        function handlePointerMove(event) {
            const rect = renderer.domElement.getBoundingClientRect();
            mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

            raycaster.setFromCamera(mouse, camera);

            const hoverTargets = [];

            if (toggleUserDevices.checked) {
                for (const mesh of selectedDeviceMeshes) {
                    hoverTargets.push(mesh);
                }
                for (const mesh of bulkDeviceMeshes) {
                    hoverTargets.push(mesh);
                }
            }

            if (toggleRoomAps.checked || toggleKaminAps.checked || toggleBuildingAps.checked) {
                for (const mesh of apHoverMeshes) {
                    hoverTargets.push(mesh);
                }
            }

            const intersects = raycaster.intersectObjects(hoverTargets, true);

            for (const intersect of intersects) {
                const obj = intersect.object;
                if (!isObjectActuallyVisible(obj)) {
                    continue;
                }

                if (obj.userData && obj.userData.hoverLabel) {
                    hoverTooltip.textContent = obj.userData.hoverLabel;
                    hoverTooltip.style.display = 'block';
                    hoverTooltip.style.left = (event.clientX + 12) + 'px';
                    hoverTooltip.style.top = (event.clientY + 12) + 'px';
                    return;
                }
            }

            hoverTooltip.style.display = 'none';
        }

        renderer.domElement.addEventListener('pointermove', handlePointerMove);
        renderer.domElement.addEventListener('pointerleave', () => {
            hoverTooltip.style.display = 'none';
        });

        applyFloorSelection();
        updateOriginVisibility();
        setSelectedUser(null);
        setAllUsersProgress(0, 0);

        function animate() {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
        }

        animate();

        window.addEventListener('resize', () => {
            const width = window.innerWidth;
            const height = window.innerHeight;

            camera.aspect = width / height;
            camera.updateProjectionMatrix();

            renderer.setSize(width, height);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        });
    </script>

</body>
<?php
} else {
?>
<head>
    <meta charset="utf-8">
    <title>Zugriff verweigert</title>
</head>
<body>
Zugriff verweigert.
</body>
<?php
}
?>
</html>