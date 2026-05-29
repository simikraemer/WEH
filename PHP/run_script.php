<?php

// run_script.php (angepasst fuer sudo + Live-Ausgabe)
$allowedScripts = [
    'anmeldung'   => 'bash /WEH/skripte/anmeldung.sh',
    'entsperren'  => 'python3 -u /WEH/skripte/payment_entsperren.py',
    'cleanup'     => 'python3 -u /WEH/skripte/user_cleanup.py',
    'abmeldung'   => 'python3 -u /WEH/skripte/abmeldung.py',
    'pskabgleich'  => 'python3 -u /WEH/skripte/pskabgleich.py --apply',
    'tvkdhcp'     => 'ssh -i /etc/credentials/fijinotausprivatekey -p 22 fijinotaus@kvasir.tvk.rwth-aachen.de "bash /usr/local/sbin/update_dhcpd.sh 2>&1"',
    'wehdhcp'     => 'ssh -i /etc/credentials/fijinotausprivatekey -p 22022 fijinotaus@dns2.weh.rwth-aachen.de "bash /usr/local/sbin/update_dhcpd.sh 2>&1"',
];

function stream_output(string $chunk): void
{
    echo $chunk;
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
set_time_limit(0);
ini_set('zlib.output_compression', '0');

while (ob_get_level() > 0) {
    ob_end_flush();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['script'])) {
    http_response_code(400);
    echo "Ungueltige Anfrage.";
    exit;
}

$key = $_POST['script'];

if (!array_key_exists($key, $allowedScripts)) {
    http_response_code(403);
    echo "Unbekanntes Skript.";
    exit;
}

$baseCmd = $allowedScripts[$key];
$cmd = 'sudo -u www-data ' . $baseCmd . ' 2>&1';
$descriptors = [
    1 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptors, $pipes);

if (!is_resource($process)) {
    http_response_code(500);
    echo "Skript konnte nicht gestartet werden.";
    exit;
}

foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$openPipes = $pipes;
while (!empty($openPipes)) {
    $read = $openPipes;
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    foreach ($read as $pipe) {
        $chunk = fread($pipe, 8192);
        if ($chunk !== false && $chunk !== '') {
            stream_output($chunk);
        }
    }

    foreach ($openPipes as $index => $pipe) {
        if (feof($pipe)) {
            fclose($pipe);
            unset($openPipes[$index]);
        }
    }
}

$exitCode = proc_close($process);
if ($exitCode !== 0) {
    stream_output("\nFehler: " . $exitCode . "\n");
}
