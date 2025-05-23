<?php
function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    return ($ip & $mask) === ($subnet & $mask);
}

$allowed_ips = [];
$res = mysqli_query($conn, "SELECT ip, prefix FROM allowed_ips");
while ($row = mysqli_fetch_assoc($res)) {
    $prefix = (int)$row['prefix'];
    $allowed_ips[] = $prefix === 32 ? $row['ip'] : $row['ip'] . '/' . $prefix;
}


$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$access_granted = false;

foreach ($allowed_ips as $range) {
    if (ip_in_range($client_ip, $range)) {
        $access_granted = true;
        break;
    }
}

if (!$access_granted) {
    header('HTTP/1.1 401 Unauthorized');
    echo 'Kein Zugriff.';
    exit;
}