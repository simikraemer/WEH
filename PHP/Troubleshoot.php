<?php
  session_start();
  require('conn.php');

  $suche = FALSE;
  
  if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    if (!empty($searchTerm)) {
        $suche = TRUE;
        $searchTerm = mysqli_real_escape_string($conn, $searchTerm);
        if (ctype_digit($searchTerm)) {
          $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                  (name LIKE '%$searchTerm%' OR 
                   username LIKE '%$searchTerm%' OR 
                   geburtsort LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (uid = '$searchTerm') OR 
                   (oldroom = '$searchTerm'))";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                    (name LIKE '%$searchTerm%' OR 
                     username LIKE '%$searchTerm%' OR 
                     email LIKE '%$searchTerm%' OR 
                     aliase LIKE '%$searchTerm%' OR 
                     geburtsort LIKE '%$searchTerm%')";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm);
        }

        header('Content-Type: application/json');
        echo json_encode($searchedusers);
    } else {
        echo json_encode([]); // Senden einer leeren JSON-Antwort, wenn der Suchbegriff leer ist
    }
    exit;
  }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && $_SESSION['valid']) {
    load_menu();

    $uid = isset($_POST['uid']) ? $_POST['uid'] : $_SESSION["uid"];
    
    if (!isset($_SESSION["AdminPanelToggleState"])) {
        $_SESSION["AdminPanelToggleState"] = "none"; // Standardmäßig eingeklappt
    }

    // Wenn ein Toggle-Request erfolgt, den Zustand der Session-Variable umschalten
    if (isset($_POST["toggleAdminPanel"])) {
        $_SESSION["AdminPanelToggleState"] = $_SESSION["AdminPanelToggleState"] === "none" ? "block" : "none";
    }

    if ($_SESSION['NetzAG']) {
        echo '<div style="margin: 0 auto; text-align: center;">';
        echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
        echo '<span class="white-text" style="font-size: 35px; cursor: pointer;" onclick="toggleAdminPanel()">Admin Panel</span>';
        echo '<div id="adminPanel" style="display: ' . $_SESSION["AdminPanelToggleState"] . ';">'; // Beginn des ausklappbaren Bereichs
    
        echo '<form method="post">';
        echo '<label for="uid" style="color: white; font-size: 25px;">Bewohner: </label>';
        
        echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()">';
    
        // Holen des ersten Benutzers
        $sql = "SELECT name, room, pid FROM users WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $name, $room, $pid);
        mysqli_stmt_fetch($stmt);
        
        $roomLabel = '';
        if ($pid == 11) {
          $roomLabel = ' ['.$room.']';
        } elseif ($pid == 12) {
            $roomLabel = ' [Subletter]';
        } elseif ($pid == 13) {
            $roomLabel = ' [Ausgezogen]';
        } elseif ($pid == 14) {
            $roomLabel = ' [Abgemeldet]';
        } elseif ($pid == 64) {
          $roomLabel = ' [Dummy]';
        } else {
          $roomLabel = ' [Undefined]';
        }
    
        // Setze den ausgewählten Wert im Dropdown
        echo '<option value="' . $uid . '" selected>' . $name . $roomLabel . '</option>';
        mysqli_stmt_free_result($stmt);
    
        // Andere Benutzer laden
        $sql = "SELECT uid, name, room, turm 
        FROM users 
        WHERE pid = 11 
        ORDER BY FIELD(turm, 'weh', 'tvk'), room";
    
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uidOption, $name, $room, $turm);
    
        while (mysqli_stmt_fetch($stmt)) {
          // Formatierung der Ausgabe
          $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
          
          // UID prüfen und den Dropdown-Wert entsprechend markieren
          $selected = ($uidOption == $uid) ? 'selected' : '';
          echo '<option value="' . $uidOption . '" ' . $selected . '>' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
        }
        echo '</select>';
        echo '</form>';

        echo "<!DOCTYPE html>";
        echo "<html lang='de'>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<title>Dynamische Benutzersuche</title>";
        echo "<style>";
        echo "#searchInput {";
        echo "    display: block;";
        echo "    margin: 20px auto;";
        echo "    height: 30px;";
        echo "    font-size: 25px;"; // Schriftgröße auf 16px setzen
        echo "}";
        echo ".center-table {";
        echo "    margin-left: auto;";
        echo "    margin-right: auto;";
        echo "}";
        echo "</style>";
        echo "</head>";
        echo "<body>";
        echo "<input type='text' id='searchInput' placeholder='Suche nach User...' onkeyup='searchUser(this.value)'>";
        echo "</body>";
        echo "</html>";
        
    
        echo "<div style='margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;'>";
        echo "<table class='center-table'>";
        echo "<tr></tr>";
      
        if (!empty($searchedusers)) {
          foreach ($searchedusers as $uid => $users) {
              foreach ($users as $user) {
                  $user_id = htmlspecialchars($user['uid'], ENT_QUOTES);
                  $user_name = htmlspecialchars($user["username"], ENT_QUOTES);
                  $name_html = htmlspecialchars($user["name"], ENT_QUOTES);
                  $room = $user['room'] == 0 ? $user['oldroom'] : $user['room'];
                  $color = $user['room'] == 0 ? 'red' : '#7FFF00';
                  echo "<tr><td>" . $user_id . "</td><td>" . $user_name . "</td><td><a href='javascript:void(0);' onclick='submitForm(\"$user_id\");' class='white-text' style='user-select: none;'>" . $name_html . "</a></td><td style='color:" . $color . ";'>" . $room . "</td></tr>";
              }
          }
        }
        echo "</table>";
        echo "</div>";
    
        echo "<script>";
        echo "function searchUser(searchValue) {";
        echo "    fetch('?search=' + encodeURIComponent(searchValue))";
        echo "    .then(response => response.json())";
        echo "    .then(data => {";
        echo "        const table = document.querySelector('.center-table tbody');";
        echo "        table.innerHTML = '';";  // Clear the existing table content
        echo "        Object.keys(data).forEach(uid => {";
        echo "            data[uid].forEach(user => {";
        echo "                const row = table.insertRow();";
        echo "                const cell1 = row.insertCell(0);";  // For 'Turm'
        echo "                const cell2 = row.insertCell(1);";  // For 'uid'
        echo "                const cell3 = row.insertCell(2);";  // For 'username'
        echo "                const cell4 = row.insertCell(3);";  // For 'name'
        echo "                const cell5 = row.insertCell(4);";  // For 'room'
        
                        // UID
        echo "                cell1.textContent = user.uid;";
        
                        // Username
        echo "                cell2.textContent = user.username;";
        
                        // Name with link
        echo "                const link = document.createElement('a');";
        echo "                link.href = 'javascript:void(0);';";
        echo "                link.className = 'white-text';";
        echo "                link.style.userSelect = 'none';";
        echo "                link.textContent = user.name;";
        echo "                link.onclick = function() { submitForm(user.uid); };";
        echo "                cell3.appendChild(link);";
        
                        // Logic for "Turm" and "room" field
        echo "                const room = user.room == 0 ? user.oldroom : user.room;";
        echo "                cell5.textContent = room;";
        echo "                if (user.turm === 'weh') {";
        echo "                    cell4.textContent = 'WEH';";
        echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
        echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
        echo "                } else if (user.turm === 'tvk') {";
        echo "                    cell4.textContent = 'TvK';";
        echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
        echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
        echo "                } else {";
        echo "                    cell4.textContent = user.turm.toUpperCase();";
        echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
        echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
        echo "                }";
        echo "                cell4.style.paddingRight = '15px';";  // Adds padding to the right
    
    
        
                        // Room handling
        echo "            });";
        echo "        });";
        echo "    });";
        echo "}";
        
        echo "function submitForm(userId) {";
        echo "    var form = document.createElement('form');";
        echo "    form.method = 'post';";
        echo "    form.action = '';";
        echo "    var hiddenField = document.createElement('input');";
        echo "    hiddenField.type = 'hidden';";
        echo "    hiddenField.name = 'uid';";
        echo "    hiddenField.value = userId;";
        echo "    form.appendChild(hiddenField);";
        echo "    document.body.appendChild(form);";
        echo "    form.submit();";
        echo "}";
        echo "</script>";
        
        
        echo "</body>";
        echo "</html>";

    
        echo '</div>'; // Ende des ausklappbaren Bereichs
        echo '</div>';
        echo '</div>';
    
        echo '<script>
            function toggleAdminPanel() {
                // Unsichtbares Formular erstellen und absenden, um den Zustand zu speichern
                var form = document.createElement("form");
                form.method = "POST";
                form.action = ""; // Seite wird neu geladen
    
                // Hidden Field für toggleAdminPanel
                var inputToggle = document.createElement("input");
                inputToggle.type = "hidden";
                inputToggle.name = "toggleAdminPanel";
                inputToggle.value = "1";
                form.appendChild(inputToggle);
    
                // Hidden Field für UID
                var inputUid = document.createElement("input");
                inputUid.type = "hidden";
                inputUid.name = "uid";
                inputUid.value = "' . $uid . '";
                form.appendChild(inputUid);
    
                document.body.appendChild(form);
                form.submit();
            }
        </script>';
        
        echo '<br><br>';
    }



    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<input type="hidden" name="uid" value="' . $uid . '">';
    echo '<button type="submit" name="execAction" value="radius" class="house-button" style="font-size:50px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">RADIUS';
    echo '</button>';
    echo '<button type="submit" name="execAction" value="dhcp" class="house-button" style="font-size:50px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">DHCP';
    echo '</button>';
    echo '</form>';
    echo "<br><br>";         

    if (isset($_POST["execAction"])) {
        if ($_POST["execAction"] == "radius") {
            $sql = "SELECT username, turm FROM users WHERE uid = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $username, $turm);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);

            if ($turm == 'weh') {

                $command = 'ssh -i /etc/credentials/fijinotausprivatekey -p 22022 fijinotaus@radius1.weh.rwth-aachen.de "less /var/log/syslog | grep ' . $username. '@weh.rwth-aachen.de 2>&1; echo $?"';
                $descriptors = [
                    0 => ['pipe', 'r'], // stdin
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w']  // stderr
                ];

                // Befehl ausführen
                $process = proc_open($command, $descriptors, $pipes);

                if (is_resource($process)) {
                    // Output von stdout lesen
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                
                    // Fehler von stderr lesen (falls vorhanden)
                    $errors = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                
                    // Warte auf den Abschluss des Prozesses
                    $return_value = proc_close($process);
                
                    // Ausgabe zur Fehlersuche
                    #echo "Command: " . htmlspecialchars($command) . "<br>";
                    #echo "Output: " . nl2br(htmlspecialchars($output)) . "<br>";
                    #echo "Errors: " . nl2br(htmlspecialchars($errors)) . "<br>";
                    #echo "Return value: " . $return_value . "<br>";
                } else {
                    echo "Fehler: Prozess konnte nicht geöffnet werden.<br>";
                }

                $outputfeld = True;

            } elseif ($turm == 'tvk') {
                $command = 'ssh -i /etc/credentials/fijinotausprivatekey -p 22 fijinotaus@kvasir.tvk.rwth-aachen.de "less /var/log/syslog | grep ' . $username. ' 2>&1; echo $?"';
                $descriptors = [
                    0 => ['pipe', 'r'], // stdin
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w']  // stderr
                ];

                // Befehl ausführen
                $process = proc_open($command, $descriptors, $pipes);

                if (is_resource($process)) {
                    // Output von stdout lesen
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                
                    // Fehler von stderr lesen (falls vorhanden)
                    $errors = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                
                    // Warte auf den Abschluss des Prozesses
                    $return_value = proc_close($process);
                
                    // Ausgabe zur Fehlersuche
                    #echo "Command: " . htmlspecialchars($command) . "<br>";
                    #echo "Output: " . nl2br(htmlspecialchars($output)) . "<br>";
                    #echo "Errors: " . nl2br(htmlspecialchars($errors)) . "<br>";
                    #echo "Return value: " . $return_value . "<br>";
                } else {
                    echo "Fehler: Prozess konnte nicht geöffnet werden.<br>";
                }

                $outputfeld = True;
            }
        
        } elseif ($_POST["execAction"] == "dhcp") { 
            $sql = "SELECT username, turm FROM users WHERE uid = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $username, $turm);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);

            if ($turm == 'weh') {

                $ips = [];
                $sql = "SELECT DISTINCT ip FROM macauth WHERE uid = ? AND (mac1 != '' OR mac2 != '' OR mac3 != '') ORDER BY ip";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $ip);
                
                while (mysqli_stmt_fetch($stmt)) {
                    $ips[] = $ip;
                }

                mysqli_stmt_close($stmt);
                echo '</form>';

                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $output = "";
                    $errors = "";
                
                    if (count($ips) > 0) {
                        // Erstellen des Grep-Ausdrucks, abhängig von der Anzahl der IPs
                        if (count($ips) == 1) {
                            $grepPattern = escapeshellarg($ips[0]);
                        } else {
                            $escapedIps = array_map('escapeshellarg', $ips);
                            $grepPattern = "'(" . implode('|', $escapedIps) . ")'";
                        }
                    
                        $command = 'ssh -i /etc/credentials/fijinotausprivatekey -p 22022 fijinotaus@dns2.weh.rwth-aachen.de "grep -E ' . $grepPattern . ' /var/log/syslog 2>&1"';
                        $descriptors = [
                            0 => ['pipe', 'r'], // stdin
                            1 => ['pipe', 'w'], // stdout
                            2 => ['pipe', 'w']  // stderr
                        ];
                    
                        $process = proc_open($command, $descriptors, $pipes);
                    
                        if (is_resource($process)) {
                            $output .= stream_get_contents($pipes[1]);
                            fclose($pipes[1]);
                            $errors .= stream_get_contents($pipes[2]);
                            fclose($pipes[2]);
                            proc_close($process);
                        }
                    }
                }
                $outputfeld = True;
            } elseif ($turm == 'tvk') {
                $ips = [];
                $sql = "SELECT DISTINCT ip FROM macauth WHERE uid = ? AND (mac1 != '' OR mac2 != '' OR mac3 != '') ORDER BY ip";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $ip);
                
                while (mysqli_stmt_fetch($stmt)) {
                    $ips[] = $ip;
                }

                mysqli_stmt_close($stmt);
                echo '</form>';

                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $output = "";
                    $errors = "";
                
                    if (count($ips) > 0) {
                        // Erstellen des Grep-Ausdrucks, abhängig von der Anzahl der IPs
                        if (count($ips) == 1) {
                            $grepPattern = escapeshellarg($ips[0]);
                        } else {
                            $escapedIps = array_map('escapeshellarg', $ips);
                            $grepPattern = "'(" . implode('|', $escapedIps) . ")'";
                        }

                        $command = 'ssh -i /etc/credentials/fijinotausprivatekey -p 22 fijinotaus@kvasir.tvk.rwth-aachen.de "grep -E ' . $grepPattern . ' /var/log/syslog 2>&1"';
                        $descriptors = [
                            0 => ['pipe', 'r'], // stdin
                            1 => ['pipe', 'w'], // stdout
                            2 => ['pipe', 'w']  // stderr
                        ];
                    
                        $process = proc_open($command, $descriptors, $pipes);
                    
                        if (is_resource($process)) {
                            $output .= stream_get_contents($pipes[1]);
                            fclose($pipes[1]);
                            $errors .= stream_get_contents($pipes[2]);
                            fclose($pipes[2]);
                            proc_close($process);
                        }
                    }
                }
                $outputfeld = True;
            }
        }
    }
    
    if (isset($outputfeld) && $outputfeld) {
        echo '<div style="text-align: center;">';
        echo '<div style="background-color: white; padding: 5px; display: inline-block;">'; // Äußerer Container
        echo '<div style="background-color: black; color: white; font-family: monospace; padding: 10px; display: inline-block; text-align: left;">'; // Innerer Container
        echo '<pre>';
        if (strlen($output) < 5) {
            echo "Keine Logs vorhanden!";
        } else {
            $output = str_replace("dns2", "", $output);
            $output = str_replace("radius1", "", $output); 
            #$output = str_replace("via eth0", "", $output); 
            #$output = str_replace("via eth0", "", $output); 
            $output = str_replace("from client radiusproxy-weh-1 port 0 ", "", $output); 
            $output = str_replace("from client radiusproxy-weh-1 port 1 ", "", $output); 
            #$output = str_replace("@weh.rwth-aachen.de", "", $output); 
            
            $output = str_replace("kvasir", "", $output); 
            $output = str_replace("from client wlc-tvk port 0 ", "", $output); 
            $output = str_replace("from client wlc-tvk port 1 ", "", $output); 

            $output = str_replace("AP:", "", $output); 
            $output = str_replace("cli ", "", $output); 
            $output = str_replace("(via TLS tunnel)", "", $output); 
            $output = str_replace("via eth0", "", $output); 
            $output = preg_replace("/radiusd\[\d+\]/", "", $output);
            $output = preg_replace("/freeradius\[\d+\]/", "", $output);
            $output = str_replace("dhcpd", "", $output); 
            $output = preg_replace("/dhcpd\[\d+\]/", "", $output);
            #$output = preg_replace("/\(\d+\)/", "", $output);
            while (strpos($output, "  ") !== false) {
                $output = str_replace("  ", " ", $output);
            }
            
            
            echo htmlspecialchars(substr($output, 0, -2));
        }
        echo '</pre>';
        echo '</div>'; // Ende des inneren Containers
        echo '</div>'; // Ende des äußeren Containers
        echo '</div>'; // Ende der zentrierten Ausrichtung
    }

    


    echo '<div style="width: 70%; margin: 0px auto; margin-top: 60px; text-align: center; color: white; font-size: 29px;">';
    echo 'On this page, you can view your RADIUS and DHCP Logs, allowing you to troubleshoot connection issues.';
    echo '</div>';
    
    echo '<div style="width: 70%; margin: 0 auto; text-align: center; color: white; font-size: 20px; margin-bottom: 30px;">';
    echo 'Below, you will find the most common issues that might be causing errors in the logs displayed above.';
    echo '</div>';
    
    // Flexbox-Container für RADIUS und DHCP
    echo '<div style="display: flex; justify-content: space-between; width: 70%; margin: 0 auto; margin-bottom: 60px; gap: 50px;">';
    
    // RADIUS-Bereich
    echo '<div style="flex: 1; text-align: left; color: white; padding: 20px; border: 2px solid white; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);">';
    echo '<div style="font-size: 25px; margin-bottom: 10px; text-align: center;">1. RADIUS (WLAN only!)</div>';
    echo '<div style="font-size: 20px;">';
    echo '<p>The device\'s access to the access point is authenticated by verifying the credentials. Common errors that may arise in this step include:</p>';
    echo '<ul style="list-style: disc; padding-left: 20px;">';
    echo '<li>Incorrect Username - Remember to use &lt;username&gt;@weh.rwth-aachen.de for WiFi-Only Authentication!</li>';
    echo '<li>Incorrect Password - Your WiFi-Only Password differs from your House Password!</li>';
    echo '<li><a href="https://doku.tid.dfn.de/de:dfnpki:tcs_ca_certs" >Missing CA certificate</a> (Thin Linux Distros) - Your device can\'t authenticate the GÉANT SSL-Cert of our Server.</li>';
    echo '<li>Wrong security type - Some devices can\'t use WPA2-Enterprise and need to be registered on <a href="https://backend.weh.rwth-aachen.de/PSK.php" >this page</a>.</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    
    // DHCP-Bereich
    echo '<div style="flex: 1; text-align: left; color: white; padding: 20px; border: 2px solid white; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);">';
    echo '<div style="font-size: 25px; margin-bottom: 10px; text-align: center;">2. DHCP</div>';
    echo '<div style="font-size: 20px;">';
    echo '<p>For the device to connect to the internet, it needs to obtain an IP from our DHCP service. Common errors that may arise in this step include:</p>';
    echo '<ul style="list-style: disc; padding-left: 20px;">';
    echo '<li>Device not registered - The MAC of your device is not assigned to an IP on <a href="https://backend.weh.rwth-aachen.de/IPverwaltung.php" >this page</a>.</li>';
    echo '<li>Randomized MAC Address - If you have a setting like this enabled, turn it off or your MAC will reset after some time!</li>';
    echo '<li>IP not available - If you have multiple MACs on an IP, only one device can obtain an IP at a time!</li>';
    echo '<li>LAN/WLAN - The same device uses different MACs for connecting via LAN and WLAN, so you need to register both!</li>';
    echo '<li>Time - After registering a device, it may take up to 10 minutes for it to actually request a new IP.</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    
    // Ende des Flexbox-Containers
    echo '</div>';
    
    
    

}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>