<?php

// run_script.php (angepasst für sudo)
$allowedScripts = [
    'anmeldung'   => 'bash /WEH/skripte/anmeldung.sh',
    'entsperren'  => 'python3 /WEH/skripte/payment_entsperren.py',
    'cleanup'     => 'python3 /WEH/skripte/user_cleanup.py',
    'abmeldung'   => 'python3 /WEH/skripte/abmeldung.py',
    'tvkdhcp'         => 'ssh -i /etc/credentials/fijinotausprivatekey -p 22 fijinotaus@kvasir.tvk.rwth-aachen.de "bash /usr/local/sbin/update_dhcpd.sh 2>&1"' ,
    'wehdhcp'         => 'ssh -i /etc/credentials/fijinotausprivatekey -p 22022 fijinotaus@dns2.weh.rwth-aachen.de "bash /usr/local/sbin/update_dhcpd.sh 2>&1"' ,
];

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['script'])) {
    $key = $_POST['script'];

    if (array_key_exists($key, $allowedScripts)) {
        $baseCmd = $allowedScripts[$key];
        $cmd = 'sudo -u www-data ' . escapeshellcmd($baseCmd) . ' 2>&1';
        $output = shell_exec($cmd);
        echo "Skript $key ausgeführt.\n\n$output";
    } else {
        http_response_code(403);
        echo "Unbekanntes Skript.";
    }
} else {
    http_response_code(400);
    echo "Ungültige Anfrage.";
}


?>
