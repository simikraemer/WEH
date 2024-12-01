<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
    <body onload="alumniFunction(); ibanFunction();">

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION["NetzAG"]) {
    load_menu();

    $sql_users = "SELECT users.uid, users.username, 
        users.name, users.room, users.oldroom,
        natmapping.subnet, natmapping.ip, users.turm
        FROM users JOIN natmapping
        ON users.subnet = natmapping.subnet";
    $stmt_users = mysqli_prepare($conn, $sql_users);
    mysqli_stmt_execute($stmt_users);
    mysqli_stmt_bind_result($stmt_users, $uid, $username, $name, $room, $oldroom, $subnet, $externalip, $turm);

    $usersNATData = array();

    while (mysqli_stmt_fetch($stmt_users)) {
        $usersNATData[] = array(
            'uid' => $uid,
            'username' => $username,
            'name' => $name,
            'hostname' => "",
            'room' => $room,
            'oldroom' => $oldroom,
            'turm' => $turm,
            'subnet' => $subnet,
            'externalip' => $externalip,
            'sublet' => 0
        );
    }

    mysqli_stmt_free_result($stmt_users);
    mysqli_stmt_close($stmt_users);

    $sql_users = "SELECT users.uid, users.username, 
        users.name, macauth.hostname,
        users.room, users.oldroom,
        macauth.ip, macauth.sublet, users.turm
        FROM macauth 
        JOIN users ON macauth.uid = users.uid 
        WHERE ip NOT LIKE '10.%'
        ORDER BY ip";
    $stmt_users = mysqli_prepare($conn, $sql_users);
    mysqli_stmt_execute($stmt_users);
    mysqli_stmt_bind_result($stmt_users, $uid, $username, $name, $hostname, $room, $oldroom, $ip, $sublet, $turm);

    $usersNotNATData = array();

    while (mysqli_stmt_fetch($stmt_users)) {
        $usersNotNATData[] = array(
            'uid' => $uid,
            'username' => $username,
            'name' => $name,
            'hostname' => $hostname,
            'room' => $room,
            'oldroom' => $oldroom,
            'turm' => $turm,
            'ip' => $ip,
            'sublet' => $sublet
        );
    }

    mysqli_stmt_free_result($stmt_users);
    mysqli_stmt_close($stmt_users);
    $allUserData = array_merge($usersNATData, $usersNotNATData);

    $sql_users = "
    SELECT 
        a.hostname, 
        a.produkt, 
        a.beschreibung, 
        a.ip, 
        a.nagios, 
        a.room, 
        a.turm,
        CASE 
            WHEN a.room BETWEEN 101 AND 1716 AND a.turm = u.turm THEN u.uid 
            ELSE '472' 
        END AS uid,
        CASE 
            WHEN a.room BETWEEN 101 AND 1716 AND a.turm = u.turm THEN u.username 
            ELSE 'netagdummy' 
        END AS username
    FROM aps a
    LEFT JOIN users u ON a.room = u.room AND a.turm = u.turm
    WHERE a.ip IS NOT NULL AND a.ip <> '' -- Nur Zeilen mit Einträgen bei ip
    ORDER BY a.ip";
    

$stmt_users = mysqli_prepare($conn, $sql_users);
mysqli_stmt_execute($stmt_users);
mysqli_stmt_bind_result($stmt_users, $hostname, $produkt, $beschreibung, $ip, $nagios, $room, $turm, $uid, $username);


    $apDATA = array();

    while (mysqli_stmt_fetch($stmt_users)) {    
        if ($nagios == 1) {
            $nagios_inv = 0;
        } elseif ($nagios == 0) {
            $nagios_inv = 1;
        }
        $apDATA[] = array(
            'uid' => $uid,
            'username' => $username,
            'name' => $produkt,
            'hostname' => $hostname,
            'room' => $room,
            'oldroom' => "",
            'turm' => $turm,
            'ip' => $ip,
            'sublet' => $nagios_inv
        );
    }

    mysqli_stmt_free_result($stmt_users);
    mysqli_stmt_close($stmt_users);
    $allUserData = array_merge($allUserData, $apDATA);

    // Sortiere das Array nach der IP
    usort($allUserData, function($a, $b) {
        $aIp = isset($a['externalip']) ? $a['externalip'] : $a['ip'];
        $bIp = isset($b['externalip']) ? $b['externalip'] : $b['ip'];
        
        // Zerlege die IP-Adressen in Teile
        $aIpParts = explode('.', $aIp);
        $bIpParts = explode('.', $bIp);
        
        // Vergleiche die Teile
        for ($i = 0; $i < 4; $i++) {
            $result = $aIpParts[$i] - $bIpParts[$i];
            if ($result !== 0) {
                return $result;
            }
        }
        
        return 0; // Gleich, wenn alle Teile übereinstimmen
    });

    // Definiere die Netzbereiche
    $networks = [
        'IT Center Hausnetz' => ['start' => ip2long('134.130.0.0'), 'end' => ip2long('134.130.1.255')],
        'WEH Servernetz #1 | VLAN 165' => ['start' => ip2long('137.226.140.0'), 'end' => ip2long('137.226.140.128')],
        'WEH Bewohnernetz #1 | VLAN 919' => ['start' => ip2long('137.226.140.129'), 'end' => ip2long('137.226.140.255')],
        'WEH Servernetz #2 | VLAN 919' => ['start' => ip2long('137.226.141.0'), 'end' => ip2long('137.226.141.15')],
        'WEH Bewohnernetz #2 | VLAN 919' => ['start' => ip2long('137.226.141.16'), 'end' => ip2long('137.226.141.255')],
        'TvK Bewohnernetz #1 | VLAN 261' => ['start' => ip2long('137.226.142.0'), 'end' => ip2long('137.226.142.255')],
        'TvK Servernetz | VLAN 2' => ['start' => ip2long('137.226.143.0'), 'end' => ip2long('137.226.143.30')],
        'TvK AP-Netz | VLAN 2' => ['start' => ip2long('137.226.143.31'), 'end' => ip2long('137.226.143.128')],
        'TvK Bewohnernetz #2 | VLAN 261' => ['start' => ip2long('137.226.143.128'), 'end' => ip2long('137.226.143.255')],
        'WEH AP-Netz | VLAN 12' => ['start' => ip2long('192.168.12.0'), 'end' => ip2long('192.168.12.255')],
    ];

    echo '<div style="margin: 20px 0; text-align: center;">';
    echo '<table class="grey-table" style="margin: 0 auto; text-align: center; border: 2px solid white; border-radius: 10px; padding: 10px; box-shadow: 0 4px 8px rgba(255, 255, 255, 0.2); background-color: #222;">';
    echo "<tr><th colspan='8' style='font-size: 50px; background-color: #222; color: #fff;'>Inhaltsverzeichnis</th></tr>";
    #echo '<table style="margin: 0 auto; border-collapse: collapse; width: 50%; text-align: left; color: #fff;">';  
    #echo '<thead style="background-color: #333; color: #fff;">';
    echo '<tr>
            <th style="padding: 10px; border-bottom: 2px solid #444;">Name</th>
            <th style="padding: 10px; border-bottom: 2px solid #444;">IP-Range</th>
            <th style="padding: 10px; border-bottom: 2px solid #444;">VLAN</th>
          </tr>';
    echo '</thead>';
    echo '<tbody>';    
    foreach ($networks as $networkName => $range) {
        preg_match('/VLAN\s(\d+)/', $networkName, $matches);
        $vlan = $matches[1] ?? '-';
        $cleanName = explode('|', $networkName)[0];
        $cleanName = trim($cleanName);
        $startIp = long2ip($range['start']);
        $endIp = long2ip($range['end']);
        $ipRange = "$startIp - $endIp";
        $networkId = strtolower(str_replace([' ', '#', '|'], '_', $networkName));
        echo "<tr onclick=\"location.href='#$networkId'\" style='cursor: pointer; background-color: #222;' 
                onmouseover=\"this.style.backgroundColor='#333'\" 
                onmouseout=\"this.style.backgroundColor='#222'\">
                <td style='padding: 10px; border-bottom: 1px solid #444;'>$cleanName</td>
                <td style='padding: 10px; border-bottom: 1px solid #444;'>$ipRange</td>
                <td style='padding: 10px; border-bottom: 1px solid #444;'>$vlan</td>
              </tr>";
    }    
    echo '</tbody>';
    echo '</table>';
    echo '</div><br><br><hr><br><hr><br><br>';
    
    

    echo '<table class="grey-table" style="margin: 0 auto; text-align: center;">';

    $currentNetwork = '';
    
    foreach ($allUserData as $user) {
        // Konvertiere die IP des aktuellen Benutzers in einen Long-Wert
        $userIpLong = ip2long(isset($user['externalip']) ? $user['externalip'] : $user['ip']);
    
        // Finde das passende Netz für die aktuelle IP
        foreach ($networks as $networkName => $range) {
            if ($userIpLong >= $range['start'] && $userIpLong <= $range['end']) {
                // Wenn wir ein neues Netz beginnen, füge eine Überschrift und den Tabellenheader hinzu
                if ($currentNetwork !== $networkName) {
                    if ($currentNetwork !== '') {
                        // Schließe die vorherige Tabelle ab, bevor eine neue beginnt
                        echo '</table><br><br><br><br><table class="grey-table" style="margin: 0 auto; text-align: center;">';
                    }
                    $currentNetwork = $networkName;
                    $networkId = strtolower(str_replace([' ', '#', '|'], '_', $networkName)); // ID für das HTML-Element
                    echo "<tr id='$networkId'><th colspan='8' style='background-color: #222; font-size: 35px;'>$networkName</th></tr>";
                    $startIp = long2ip($networks[$networkName]['start']);
                    $endIp = long2ip($networks[$networkName]['end']);
                    echo "<tr><th colspan='8' style='font-size: 25px; background-color: #222; color: #fff;'>$startIp - $endIp</th></tr>";
                    echo "<tr>
                            <th>UID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Hostname</th>
                            <th>Room</th>
                            <th>Turm</th>
                            <th>IP</th>
                            <th>Subnet</th>
                        </tr>";
                }
                break; // Netz gefunden, Schleife verlassen
            }
        }
        
        echo "<form method='POST' action='House.php' target='_blank' style='display: none;' id='form_{$user['uid']}'>
              <input type='hidden' name='id' value='{$user['uid']}'>
            </form>";
    
        // Überprüfen, ob 'sublet' gleich 1 ist, um die Hintergrundfarbe zu setzen
        #$cellStyle = (isset($user['sublet']) && $user['sublet'] == 1) ? "style='background-color: #8d150c;'" : "";
        # Zu verwirrend und ablenkend für neue NetzAG, daher erstmall alle Zeilen grau.
        $cellStyle = "style='font-size: 20px; padding: 3px;'";
    
        echo "<tr onclick='document.getElementById(\"form_{$user['uid']}\").submit();' style='cursor: pointer;'>";
        echo "<td $cellStyle>{$user['uid']}</td>";
        echo "<td $cellStyle>{$user['username']}</td>";
        echo "<td $cellStyle>{$user['name']}</td>";
        echo "<td $cellStyle>{$user['hostname']}</td>";
    
        $room = $user['room'];
        if ($room < 1 || $room > 1717) {
            echo "<td $cellStyle></td>";
        } else {
            echo "<td $cellStyle>{$room}</td>";
        }

        $turm = ($user['turm'] === "tvk") ? "TvK" : (($user['turm'] === "weh") ? "WEH" : $user['turm']);
        echo "<td $cellStyle>$turm</td>";        
    
        if (isset($user['externalip'])) {
            echo "<td $cellStyle>{$user['externalip']}</td>";
        } else {
            echo "<td $cellStyle>{$user['ip']}</td>";
        }
    
        if (isset($user['externalip'])) {
            echo "<td $cellStyle>{$user['subnet']}</td>";
        } else {
            echo "<td $cellStyle></td>";
        }
    
        echo "</tr>";
    }
    
    echo '</table>';
    
    
    

}
else {
  header("Location: denied.php");
}




// Close the connection to the database
$conn->close();
?>
</body>
</html>