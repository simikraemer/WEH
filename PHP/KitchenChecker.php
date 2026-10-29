<?php
declare(strict_types=1);

session_start();
ob_start(); // conn.php erzeugt ebenfalls HTML (Reload-Formular).
require __DIR__ . '/conn.php';
mysqli_set_charset($conn, 'utf8mb4');

/*
 * Kuechenmonitor WEH
 * - Authentifizierung und Navigation via template.php; WEH.css separat.
 * - Exakt ein AP; ein SSH-Login, drei lesende WLC-Befehle.
 * - Kein Zugriff auf User-/MAC-Datenbank und keine Einzelabfragen pro Client.
 */
const KM_AP = 'ap-weh-etage05';
const KM_INTERVAL_SECONDS = 300;
const KM_WLC_TIMEOUT_SECONDS = 25;

function km_json(array $payload, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function km_tcl_quoted(string $value): string
{
    // Keine Tcl-Befehlsinjektion durch Sonderzeichen in den WLC-Zugangsdaten.
    $value = str_replace(["\r", "\n"], '', $value);
    return '"' . strtr($value, [
        '\\' => '\\\\', '"' => '\\"', '$' => '\\$',
        '[' => '\\[', ']' => '\\]',
    ]) . '"';
}

function km_expect_script(array $wlc): string
{
    $host = km_tcl_quoted((string)$wlc['host']);
    $user = km_tcl_quoted((string)$wlc['username']);
    $pass = km_tcl_quoted((string)$wlc['password']);
    $ap = KM_AP; // Statische, fest vorgegebene Zeichenfolge.

    return <<<EXPECT
#!/usr/bin/expect -f
set timeout 15
log_user 0
match_max 100000
set host $host
set user $user
set pass $pass

proc km_fail {message code} {
    puts stderr "WLC_FEHLER: \$message"
    exit \$code
}

proc km_prompt {} {
    expect {
        -re {\(Cisco Controller\)\s*>} { return }
        timeout { km_fail "WLC-Prompt nicht rechtzeitig empfangen" 20 }
        eof { km_fail "SSH-Verbindung wurde unerwartet geschlossen" 21 }
    }
}

proc km_command {marker command} {
    send -- "\$command\\r"
    expect {
        -re {\(Cisco Controller\)\s*>} {
            puts "__KM_BEGIN_\${marker}__"
            puts \$expect_out(buffer)
            puts "__KM_END_\${marker}__"
        }
        timeout { km_fail "Timeout bei \$command" 22 }
        eof { km_fail "Verbindungsabbruch bei \$command" 23 }
    }
}

# Die Authentifizierungssequenz entspricht der vorhandenen WEH-WLC-Seite.
spawn ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -- \$host
expect {
    "User:" { send -- "\$user\\r" }
    timeout { km_fail "Keine User-Abfrage vom WLC" 24 }
    eof { km_fail "SSH-Login nicht erreichbar" 25 }
}
expect {
    "Password:" { send -- "\$pass\\r" }
    timeout { km_fail "Keine Passwort-Abfrage vom WLC" 26 }
    eof { km_fail "SSH-Verbindung beim Login geschlossen" 27 }
}
km_prompt
send -- "config paging disable\\r"
km_prompt

# AP erst verifizieren: Offline/Fehler darf niemals als 'frei' gelten.
km_command "AP" "show ap summary $ap"
km_command "A" "show client ap 802.11a $ap"
km_command "B" "show client ap 802.11b $ap"
puts "__KM_ALL_DONE__"

send -- "logout\\r"
expect {
    -re {save them now} { send -- "N\\r"; exp_continue }
    eof {}
    timeout {}
}
exit 0
EXPECT;
}

function km_execute_wlc(array $wlc): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Die PHP-Funktion proc_open() ist deaktiviert.');
    }
    if (!is_executable('/usr/bin/expect')) {
        $expectPath = trim((string)@shell_exec('command -v expect 2>/dev/null'));
        if ($expectPath === '' || !is_executable($expectPath)) {
            throw new RuntimeException('expect ist nicht installiert oder nicht ausführbar.');
        }
    } else {
        $expectPath = '/usr/bin/expect';
    }

    foreach (['host', 'username', 'password'] as $key) {
        if (trim((string)($wlc[$key] ?? '')) === '') {
            throw new RuntimeException('WLC-Konfiguration wlcweh ist unvollständig (' . $key . ').');
        }
    }

    $temp = tempnam(sys_get_temp_dir(), 'km_wlc_');
    if ($temp === false) {
        throw new RuntimeException('Expect-Temporärdatei konnte nicht angelegt werden.');
    }

    $process = null;
    $pipes = [];
    $timedOut = false;
    $output = '';
    $errorOutput = '';
    $lastExitCode = null;
    $started = microtime(true);

    try {
        @chmod($temp, 0600); // Datei enthält das WLC-Passwort, nur Webserver-Benutzer darf lesen.
        if (file_put_contents($temp, km_expect_script($wlc), LOCK_EX) === false) {
            throw new RuntimeException('Expect-Skript konnte nicht geschrieben werden.');
        }

        $process = @proc_open(
            escapeshellarg($expectPath) . ' ' . escapeshellarg($temp),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('WLC-Abfrage konnte nicht gestartet werden (proc_open).');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running'] && $status['exitcode'] >= 0) {
                $lastExitCode = $status['exitcode'];
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, 200000);
            if ($selected !== false && $selected > 0) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false) {
                        if ($stream === $pipes[1]) {
                            $output .= $chunk;
                        } else {
                            $errorOutput .= $chunk;
                        }
                    }
                }
            }

            if (strlen($output) + strlen($errorOutput) > 250000) {
                @proc_terminate($process);
                throw new RuntimeException('Ungewöhnlich große WLC-Antwort; Abfrage abgebrochen.');
            }
            if (microtime(true) - $started > KM_WLC_TIMEOUT_SECONDS) {
                $timedOut = true;
                @proc_terminate($process);
                break;
            }
            if (!$status['running']) {
                break;
            }
        }

        $output .= (string)stream_get_contents($pipes[1]);
        $errorOutput .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $closedCode = proc_close($process);
        $process = null;
        $exitCode = $lastExitCode ?? $closedCode;

        if ($timedOut) {
            throw new RuntimeException('WLC-Timeout nach ' . KM_WLC_TIMEOUT_SECONDS . ' Sekunden.');
        }
        if ($exitCode !== 0 || !str_contains($output, '__KM_ALL_DONE__')) {
            $reason = trim($errorOutput);
            if ($reason === '') {
                $reason = 'Exit-Code ' . $exitCode . ' / Ausgabe unvollständig';
            }
            throw new RuntimeException('SSH/Expect fehlgeschlagen: ' . substr($reason, 0, 500));
        }

        return [
            'output' => $output,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            @proc_terminate($process);
            @proc_close($process);
        }
        @unlink($temp);
    }
}

function km_section(string $all, string $marker): string
{
    $begin = '__KM_BEGIN_' . $marker . '__';
    $end = '__KM_END_' . $marker . '__';
    $beginPos = strpos($all, $begin);
    if ($beginPos === false) {
        throw new RuntimeException('WLC-Antwort ohne Abschnitt ' . $marker . '.');
    }
    $start = $beginPos + strlen($begin);
    $endPos = strpos($all, $end, $start);
    if ($endPos === false) {
        throw new RuntimeException('WLC-Antwort für Abschnitt ' . $marker . ' unvollständig.');
    }
    return trim(substr($all, $start, $endPos - $start));
}

function km_cli_error(string $output): ?string
{
    if (preg_match('/(?:invalid\s+command|incorrect\s+usage|ambiguous\s+command|syntax\s+error|not\s+supported|not\s+found|does\s+not\s+exist|^\s*ERROR\b|^\s*%\s*(?:error|invalid))/im', $output, $match)) {
        return trim($match[0]);
    }
    return null;
}

function km_parse_radio(string $raw, string $radio): array
{
    $error = km_cli_error($raw);
    if ($error !== null) {
        throw new RuntimeException($radio . ': WLC meldet ' . $error . '.');
    }

    $hasHeader = preg_match('/\bMAC\s+Address\b/i', $raw) && preg_match('/\bStatus\b/i', $raw);
    $emptyMessage = (bool)preg_match('/\b(?:no\s+clients?\s+(?:found|associated|connected)|zero\s+clients?)\b/i', $raw);
    if (!$hasHeader && !$emptyMessage) {
        throw new RuntimeException($radio . ': Keine gültige Client-Tabelle im WLC-Output.');
    }

    $clients = [];
    $otherStates = [];
    $macPattern = '(?:[a-f0-9]{2}[:\-]){5}[a-f0-9]{2}|(?:[a-f0-9]{4}\.){2}[a-f0-9]{4}';
    foreach (preg_split('/\R/', $raw) as $line) {
        if (!preg_match('/^\s*(' . $macPattern . ')\s+\S+\s+([a-z][a-z0-9_-]*)\b/i', $line, $m)) {
            continue;
        }
        $mac = strtolower(str_replace(['-', '.', ':'], '', $m[1]));
        $status = strtolower($m[2]);
        if ($status === 'associated') {
            $clients['mac_' . $mac] = $mac;
        } elseif (in_array($status, ['excluded', 'disabled', 'disassociated', 'probed', 'probing'], true)) {
            $otherStates[$status] = ($otherStates[$status] ?? 0) + 1;
        } else {
            throw new RuntimeException($radio . ': Unbekannter Clientstatus "' . $m[2] . '".');
        }
    }
    return ['macs' => array_values($clients), 'other_states' => $otherStates];
}

function km_check(array $wlc): array
{
    $result = km_execute_wlc($wlc);
    $raw = str_replace(["\r\n", "\r"], "\n", $result['output']);
    // Bei Parsingfehlern gekuerzte CLI-Ausgaben an den authentifizierten Browser
    // zurueckgeben. Ohne diese waere ein Firmware-Formatproblem kaum diagnostizierbar.
    try {
        $apOutput = km_section($raw, 'AP');
        $aOutput = km_section($raw, 'A');
        $bOutput = km_section($raw, 'B');

        $apError = km_cli_error($apOutput);
        if ($apError !== null) {
            throw new RuntimeException('AP-Prüfung: ' . $apError . '.');
        }
        if (!preg_match('/^\s*' . preg_quote(KM_AP, '/') . '\s+\d+\s+/im', $apOutput)) {
            throw new RuntimeException('AP nicht in der WLC-AP-Liste gefunden. AP möglicherweise offline.');
        }

        $a = km_parse_radio($aOutput, '802.11a');
        $b = km_parse_radio($bOutput, '802.11b');
    } catch (Throwable $e) {
        $shortRaw = substr($raw, 0, 4000);
        throw new RuntimeException($e->getMessage() . "\nWLC-Rohantwort (Auszug):\n" . $shortRaw, 0, $e);
    }
    $unique = array_unique(array_merge($a['macs'], $b['macs']));

    return [
        'success' => true,
        'ap' => KM_AP,
        'count' => count($unique),
        'by_radio' => ['802.11a' => count($a['macs']), '802.11b' => count($b['macs'])],
        'duration_ms' => $result['duration_ms'],
        'checked_at' => date('c'),
        'debug' => [
            'command_ap' => 'show ap summary ' . KM_AP,
            'command_a' => 'show client ap 802.11a ' . KM_AP,
            'command_b' => 'show client ap 802.11b ' . KM_AP,
            'ignored_states_a' => $a['other_states'],
            'ignored_states_b' => $b['other_states'],
            // Rohdaten nur im authentifizierten Diagnosefenster, niemals Zugangsdaten.
            'ap_output' => $apOutput,
            'radio_a_output' => $aOutput,
            'radio_b_output' => $bOutput,
        ],
    ];
}

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// template.php definiert auth(); WEH.css wird explizit im HTML-Head eingebunden.
// Bei AJAX wird ihre HTML-Ausgabe abgefangen, damit ausschliesslich JSON zurueckkommt.
require __DIR__ . '/template.php';
$hasAccess = (bool)(auth($conn) && isset($_SESSION['NetzAG']) && $_SESSION['NetzAG'] === true);
ob_end_clean(); // HTML-Ausgaben der Includes nicht vor dem Head ausgeben.

if ($isAjax) {
    if (!$hasAccess) {
        km_json(['success' => false, 'error' => 'Zugriff verweigert.'], 403);
    }
    if (($_GET['action'] ?? '') !== 'status') {
        km_json(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }
    if (!isset($config['wlcweh']) || !is_array($config['wlcweh'])) {
        km_json(['success' => false, 'error' => 'WLC-Konfiguration wlcweh fehlt.'], 500);
    }

    session_write_close(); // Keine Session-Sperre waehrend SSH laeuft.
    try {
        @set_time_limit(35);
        km_json(km_check($config['wlcweh']));
    } catch (Throwable $e) {
        km_json([
            'success' => false,
            'error' => $e->getMessage(),
            'checked_at' => date('c'),
        ], 503);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Küchenmonitor – 5. Etage</title>
    <link rel="stylesheet" href="WEH.css">
    <style>
        .km-page { width:min(720px,calc(100% - 30px)); margin:30px auto 60px; color:#fff; font-family:Arial,sans-serif; }
        .km-page * { box-sizing:border-box; }
        .km-title { margin:0 0 20px; font-size:30px; }
        .km-panel { background:#242323; border:1px solid #373737; border-radius:12px; padding:22px; margin-bottom:12px; }
        .km-status { margin:0 0 10px; font-size:clamp(32px,6vw,56px); color:#fff; }
        .km-status[data-state="free"] { color:#11a50d; }
        .km-status[data-state="occupied"] { color:#e7ae45; }
        .km-status[data-state="error"] { color:#ec8585; }
        .km-info { margin:0 0 16px; color:#bbb; }
        .km-meta { display:flex; flex-wrap:wrap; gap:12px 24px; color:#ccc; font-size:14px; }
        .km-meta strong { color:#fff; }
        .km-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
        .km-button { border:1px solid #454545; border-radius:7px; padding:10px 15px; background:#333; color:white; font:inherit; cursor:pointer; }
        .km-button:hover { background:#414141; }
        .km-button:disabled { opacity:.5; cursor:wait; }
        .km-button-primary { background:#11a50d; border-color:#11a50d; color:white; }
        .km-button-primary:hover { background:#0d840a; }
        .km-log-details { background:#242323; border:1px solid #373737; border-radius:10px; overflow:hidden; }
        .km-log-summary { cursor:pointer; padding:14px 18px; color:white; }
        .km-log-body { border-top:1px solid #373737; padding:12px; }
        .km-log { max-height:350px; overflow:auto; margin:10px 0 0; padding:12px; border-radius:6px; background:#151515; color:#ddd; font:12px/1.5 Consolas,monospace; white-space:pre-wrap; overflow-wrap:anywhere; }
        .km-log-tools { text-align:right; }
        .km-log-tools .km-button { padding:6px 10px; font-size:12px; }
        .km-gate { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.88); padding:18px; }
        .km-gate[hidden] { display:none; }
        .km-gate-dialog { width:min(390px,100%); padding:28px; background:#242323; border:1px solid #444; border-radius:12px; color:#fff; text-align:center; box-shadow:0 18px 60px #0009; }
        .km-gate-title { margin:0 0 22px; font-size:25px; color:#fff; }
        .km-gate-dialog .km-button { width:100%; font-size:17px; padding:14px; }
        .km-gate-error { margin:16px 0 0; color:#ff9292; font-size:13px; }
        .km-gate-error[hidden] { display:none; }
    </style>
</head>
<body>
<?php if ($hasAccess): ?>
<?php load_menu(); ?>
<main class="km-page">
    <h1 class="km-title">Küchenmonitor – 5. Etage</h1>
    <section class="km-panel">
        <h2 id="kmStatus" class="km-status" data-state="unknown" aria-live="polite">Prüfe …</h2>
        <p id="kmInfo" class="km-info">WLC-Abfrage läuft.</p>
        <div class="km-meta">
            <span>Clients: <strong id="kmCount">–</strong></span>
            <span>Letzter Check: <strong id="kmLast">–</strong></span>
            <span>Nächster Check: <strong id="kmNext">–</strong></span>
            <span>Ton: <strong id="kmAudioState">nicht aktiviert</strong></span>
        </div>
        <div class="km-actions">
            <button id="kmTest" class="km-button" type="button">Ton testen</button>
            <button id="kmRefresh" class="km-button" type="button">Jetzt prüfen</button>
        </div>
    </section>
    <details class="km-log-details" id="kmDetails">
        <summary class="km-log-summary">Log (<span id="kmLogCounter">0 Einträge</span>)</summary>
        <div class="km-log-body">
            <div class="km-log-tools"><button id="kmClearLog" class="km-button" type="button">Leeren</button></div>
            <pre id="kmLog" class="km-log">Noch keine Einträge.</pre>
        </div>
    </details>
</main>
<div id="kmAudioGate" class="km-gate" role="presentation">
    <div class="km-gate-dialog" role="dialog" aria-modal="true" aria-labelledby="kmGateTitle">
        <h2 class="km-gate-title" id="kmGateTitle">Alarm aktivieren</h2>
        <button id="kmGateStart" class="km-button km-button-primary" type="button">Ton aktivieren &amp; starten</button>
        <p id="kmGateError" class="km-gate-error" role="alert" hidden></p>
    </div>
</div>
<script>
(() => {
    'use strict';
    const INTERVAL_MS = <?php echo KM_INTERVAL_SECONDS * 1000; ?>;
    const AJAX_URL = new URL(window.location.pathname, window.location.href);
    AJAX_URL.searchParams.set('ajax', '1');
    AJAX_URL.searchParams.set('action', 'status');

    const el = (id) => document.getElementById(id);
    const status = el('kmStatus');
    const info = el('kmInfo');
    const count = el('kmCount');
    const last = el('kmLast');
    const next = el('kmNext');
    const audioState = el('kmAudioState');
    const audioGate = el('kmAudioGate');
    const gateStart = el('kmGateStart');
    const gateError = el('kmGateError');
    const refreshButton = el('kmRefresh');
    const logElement = el('kmLog');
    const logCounter = el('kmLogCounter');

    let lastKnownCount = null;
    let scheduledAt = 0;
    let timer = null;
    let checking = false;
    let audioContext = null;
    let soundArmed = false;
    let monitoringStarted = false;
    const logLines = [];

    const clock = (date = new Date()) => date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    function log(message, level = 'INFO') {
        for (const row of String(message).split(/\r?\n/)) {
            logLines.push(`[${clock()}] [${level}] ${row}`);
        }
        if (logLines.length > 250) logLines.splice(0, logLines.length - 250);
        logElement.textContent = logLines.join('\n') || 'Noch keine Einträge.';
        logCounter.textContent = `${logLines.length} Einträge`;
        if (el('kmDetails').open) logElement.scrollTop = logElement.scrollHeight;
    }
    function setState(kind, title, subtitle, label) {
        status.dataset.state = kind;
        status.textContent = title;
        info.textContent = subtitle;
    }
    function updateClock() {
        next.textContent = checking ? 'läuft …' : (scheduledAt ? `${Math.max(0, Math.ceil((scheduledAt - Date.now()) / 1000 / 60))} min` : '–');
    }

    function setAudioState(text) {
        audioState.textContent = text;
    }

    async function enableSound() {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            throw new Error('Dieser Browser unterstützt die Web Audio API nicht.');
        }
        if (!audioContext) audioContext = new AudioContextClass();
        if (audioContext.state !== 'running') {
            // Nur aus einem echten Nutzerklick aufrufen: Firefox blockiert sonst Audio.
            await audioContext.resume();
        }
        if (audioContext.state !== 'running') {
            throw new Error('Firefox hat die Audiowiedergabe blockiert.');
        }
        soundArmed = true;
        setAudioState('aktiv');
        log('Akustischer Alarm freigeschaltet.');
        return true;
    }

    function startMonitoring() {
        if (monitoringStarted) return;
        if (!soundArmed || !audioContext || audioContext.state !== 'running') return;
        monitoringStarted = true;
        audioGate.hidden = true;
        log('Küchenmonitor gestartet. Prüfintervall: 5 Minuten, Ziel: <?php echo KM_AP; ?>.');
        void checkNow();
    }

    async function playChime(force = false) {
        if (!soundArmed && !force) {
            log('Frei-Alarm nicht abgespielt: Ton ist nicht aktiviert.', 'WARNUNG');
            return;
        }
        if (!audioContext || audioContext.state !== 'running') {
            log('AudioContext pausiert; versuche Wiedergabe erneut.', 'WARNUNG');
            if (audioContext) {
                try {
                    // Firefox kann resume() ohne Nutzerinteraktion unbegrenzt zurückhalten.
                    await Promise.race([
                        audioContext.resume(),
                        new Promise((_, reject) => window.setTimeout(() => reject(new Error('Audiofreigabe erforderlich')), 2000))
                    ]);
                } catch (error) {
                    setAudioState('blockiert');
                    log('Alarm nicht abgespielt: ' + (error.message || error), 'FEHLER');
                    return;
                }
            }
            if (!audioContext || audioContext.state !== 'running') {
                setAudioState('blockiert');
                log('Alarm nicht abgespielt: AudioContext weiterhin pausiert.', 'FEHLER');
                return;
            }
            setAudioState('aktiv');
        }
        const start = audioContext.currentTime + 0.02;
        for (const [index, frequency] of [659.25, 830.61, 1046.5, 830.61, 1046.5].entries()) {
            const osc = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const t = start + index * 0.23;
            osc.type = 'sine';
            osc.frequency.setValueAtTime(frequency, t);
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(0.75, t + 0.025);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.19);
            osc.connect(gain).connect(audioContext.destination);
            osc.start(t);
            osc.stop(t + 0.20);
        }
    }

    function scheduleNext() {
        window.clearTimeout(timer);
        scheduledAt = Date.now() + INTERVAL_MS;
        timer = window.setTimeout(checkNow, INTERVAL_MS);
        updateClock();
    }

    async function checkNow() {
        if (checking) return;
        checking = true;
        window.clearTimeout(timer);
        scheduledAt = 0;
        refreshButton.disabled = true;
        updateClock();
        info.textContent = 'WLC wird abgefragt …';
        log('Starte AP-Prüfung (AP-Anmeldung, 802.11a und 802.11b).');

        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 35000);
        try {
            const response = await fetch(AJAX_URL.toString(), {
                method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: abort.signal,
                headers: { 'Accept': 'application/json' }
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); }
            catch (_) { throw new Error(`Server lieferte kein gültiges JSON (HTTP ${response.status}).`); }
            if (!response.ok || !data.success) throw new Error(data.error || `HTTP ${response.status}`);

            const deviceCount = Number(data.count);
            if (!Number.isInteger(deviceCount) || deviceCount < 0) throw new Error('Ungültige Clientzahl in der Serverantwort.');
            const previousCount = lastKnownCount;
            lastKnownCount = deviceCount;
            count.textContent = String(deviceCount);
            last.textContent = clock();
            log(`Prüfung erfolgreich: ${deviceCount} Clients, 802.11a: ${data.by_radio?.['802.11a'] ?? '?'} / 802.11b: ${data.by_radio?.['802.11b'] ?? '?'}, Dauer: ${data.duration_ms} ms.`);

            if (deviceCount === 0) {
                setState('free', 'Küche frei', 'Am überwachten Access Point sind aktuell keine Geräte angemeldet.', 'Keine WLAN-Clients erkannt');
                if (previousCount !== null && previousCount > 0) {
                    log(`Zustandswechsel: ${previousCount} → 0 verbundene Geräte. Küche frei!`, 'ALARM');
                    void playChime();
                } else if (previousCount === null) {
                    log('Bereits bei der ersten Prüfung frei. Alarm wird ausgelöst.', 'ALARM');
                    void playChime();
                }
            } else {
                setState('occupied', 'Noch belegt', `${deviceCount} ${deviceCount === 1 ? 'Gerät ist' : 'Geräte sind'} mit dem AP verbunden.`, 'WLAN-Clients vorhanden');
            }
        } catch (error) {
            const reason = error.name === 'AbortError' ? 'Browser-Timeout nach 35 Sekunden.' : (error.message || String(error));
            const previous = lastKnownCount === null ? 'keine gültige Messung' : `${lastKnownCount} Geräte (veraltet)`;
            setState('error', 'Status unklar', 'Die WLC-Abfrage ist fehlgeschlagen. Keine Freimeldung aufgrund eines Fehlers.', 'Verbindungs- oder Auslesefehler');
            count.textContent = '–';
            log(`Prüfung fehlgeschlagen: ${reason}. Vorheriger Messwert: ${previous}.`, 'FEHLER');
        } finally {
            window.clearTimeout(timeout);
            checking = false;
            refreshButton.disabled = false;
            scheduleNext();
        }
    }

    gateStart.addEventListener('click', async () => {
        gateStart.disabled = true;
        gateError.hidden = true;
        try {
            await enableSound();
            startMonitoring();
            await playChime(true); // Hörbare Bestätigung, dass die Freigabe funktioniert.
            log('Bestätigungston ausgelöst.');
        } catch (error) {
            const reason = error.message || String(error);
            gateError.textContent = reason;
            gateError.hidden = false;
            log('Audioaktivierung fehlgeschlagen: ' + reason, 'FEHLER');
        } finally {
            gateStart.disabled = false;
        }
    });

    el('kmTest').addEventListener('click', async () => {
        try {
            if (!soundArmed || audioContext?.state !== 'running') await enableSound();
            await playChime(true);
            log('Testton ausgelöst.');
        } catch (error) {
            log('Testton blockiert: ' + (error.message || error), 'FEHLER');
            setAudioState('blockiert');
            audioGate.hidden = false;
        }
    });
    refreshButton.addEventListener('click', checkNow);
    el('kmClearLog').addEventListener('click', () => {
        logLines.length = 0;
        logElement.textContent = 'Noch keine Einträge.';
        logCounter.textContent = '0 Einträge';
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !checking && scheduledAt && Date.now() >= scheduledAt) {
            log('Tab wieder aktiv; überfällige Prüfung wird nachgeholt.');
            void checkNow();
        }
    });

    window.setInterval(updateClock, 1000);
    // Falls für diese Website Autoplay explizit erlaubt ist, ohne Dialog starten.
    // Andernfalls bleibt der Startdialog sichtbar, bis der Nutzer die Freigabe anklickt.
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (AudioContextClass) {
        try {
            audioContext = new AudioContextClass();
            if (audioContext.state === 'running') {
                soundArmed = true;
                setAudioState('aktiv');
                log('Audio von Firefox beim Seitenaufruf zugelassen.');
                startMonitoring();
            } else {
                log('Warte auf Klick zur Audiofreigabe.');
                gateStart.focus();
            }
        } catch (error) {
            log('Web Audio API konnte nicht initialisiert werden: ' + (error.message || error), 'FEHLER');
        }
    } else {
        gateError.textContent = 'Dieser Browser unterstützt die Web Audio API nicht.';
        gateError.hidden = false;
        gateStart.disabled = true;
    }
})();
</script>
<?php else: ?>
<main class="km-page"><h1>Zugriff verweigert.</h1></main>
<?php endif; ?>
</body>
</html>
