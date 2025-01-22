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
  <link rel="stylesheet" href="WEH.css" media="screen">
	<script>
	function updateButton(button) {
  	button.style.backgroundColor = "green";
  	button.innerHTML = "Saved";
 	setTimeout(function() {
    	button.style.backgroundColor = "";
    	button.innerHTML = "Speichern";
  	}, 2000);
	}
	</script>

    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid']) {
  load_menu();
  
  $uid = isset($_POST['uid']) ? $_POST['uid'] : $_SESSION["uid"];
  $selected_uid = $uid;


  if ($_SESSION['NetzAG']) {
    echo '<div style="margin: 0 auto; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
    echo '<span class="white-text" style="font-size: 35px; cursor: pointer;" onclick="toggleAdminPanel()">Admin Panel</span>';
    echo '<div id="adminPanel" style="display: none;">'; // Beginn des ausklappbaren Bereichs

    echo '<form method="post">';
    echo '<label for="uid" style="color: white; font-size: 25px;">UID: </label>';
    echo '<input type="text" name="uid" id="uid" placeholder="UID" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()" value="' . $selected_uid . '">';
    echo '</form>';

    echo '<form method="post">';
    echo '<label for="uid" style="color: white; font-size: 25px;">Bewohner: </label>';
    
    echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()">';
    
    $sql = "SELECT name, room, pid FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
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
    
    echo '<option value="' . $selected_uid . '">' . $name . $roomLabel . '</option>';
    mysqli_stmt_free_result($stmt);
    
    $sql = "SELECT uid, name, room, turm 
        FROM users 
        WHERE pid = 11 
        ORDER BY FIELD(turm, 'weh', 'tvk'), room";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);

    while (mysqli_stmt_fetch($stmt)) {
      // Formatierung der Ausgabe
      $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
      echo '<option value="' . $uid . '">' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
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

    ### Abwicklung Posts ###

    if (isset($_POST["remove"])) {
      $id = $_POST["remove"];
      $sql = "DELETE FROM macauth WHERE id=?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
    }
    
    if (isset($_POST["addprivateip"])) {
      $uid = $_POST["uid"];
      $selected_ip = $_POST["selected_ip_priv"];

      $zeit = time();
      $insert_sql = "INSERT INTO macauth (uid, tstamp, ip) VALUES (?,?,?)";
      $insert_var = array($uid, $zeit, $selected_ip);
      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_bind_param($stmt, "iis", ...$insert_var);
      mysqli_stmt_execute($stmt);
      if(mysqli_error($conn)) {
          echo "MySQL Fehler: " . mysqli_error($conn);
      }
      mysqli_stmt_close($stmt);
    }    

    if (isset($_POST["addpublicip"])) {
      $uid = $_POST["uid"];
      $selected_ip = $_POST["selected_ip"];

      $zeit = time();
      $insert_sql = "INSERT INTO macauth (uid, tstamp, ip) VALUES (?,?,?)";
      $insert_var = array($uid, $zeit, $selected_ip);
      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_bind_param($stmt, "iis", ...$insert_var);
      mysqli_stmt_execute($stmt);
      if(mysqli_error($conn)) {
          echo "MySQL Fehler: " . mysqli_error($conn);
      }
      mysqli_stmt_close($stmt);
    }    


    ### Private IP ###

    $sql = "SELECT natmapping.ip, users.subnet, users.turm
      FROM users 
      JOIN natmapping ON users.subnet = natmapping.subnet
      WHERE users.uid = ?";
    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $public_ip, $subnet, $turm);
    mysqli_stmt_fetch($stmt);
    $stmt->close();

    $subnetWithoutEnding = substr($subnet, 0, -1);

    $availableIPs_priv = array();
    for ($i = 1; $i <= 255; $i++) {
        $availableIPs_priv[] = $subnetWithoutEnding . $i;
    }
    
    $sql = "SELECT ip FROM macauth";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $ip);
    
    // Erstelle ein Array mit den belegten IPs
    $occupiedIPs = array();
    while (mysqli_stmt_fetch($stmt)) {
        $occupiedIPs[] = $ip;
    }
    $stmt->close();
    
    // Erstelle ein Array mit den freien IPs
    $freeIPs_priv = array_diff($availableIPs_priv, $occupiedIPs);


    ### Public IP ###

    $availableIPs = array();

    if ($turm == 'weh') {
      // Bereich: 137.226.141.180 - 137.226.141.199
      for ($i = 180; $i <= 199; $i++) {
        $availableIPs[] = "137.226.141." . $i;
      }

      // Bereich 137.226.141.200 - 137.226.141.206 ist NoProxy, also nur für NetzAG! 
      // Aus Sicherheitsgründen muss man das manuell in DB eintragen und kann nicht über die Seite aus Versehen vergeben werden!

      // Bereich: 137.226.141.207 - 137.226.141.254
      for ($i = 207; $i <= 254; $i++) {
        $availableIPs[] = "137.226.141." . $i;
      }
    } elseif ($turm == 'tvk') {
      // Bereich: 137.226.143.129 - 137.226.143.254
      for ($i = 129; $i <= 254; $i++) {
        $availableIPs[] = "137.226.143." . $i;
      }
    } else {
      // Bereich: 137.226.141.180 - 137.226.141.199
      for ($i = 180; $i <= 199; $i++) {
        $availableIPs[] = "137.226.141." . $i;
      }

      // Bereich 137.226.141.200 - 137.226.141.206 ist NoProxy, also nur für NetzAG! 
      // Aus Sicherheitsgründen muss man das manuell in DB eintragen und kann nicht über die Seite aus Versehen vergeben werden!

      // Bereich: 137.226.141.207 - 137.226.141.254
      for ($i = 207; $i <= 254; $i++) {
        $availableIPs[] = "137.226.141." . $i;
      }

      // Bereich: 137.226.143.1 - 137.226.143.254
      for ($i = 1; $i <= 254; $i++) {
        $availableIPs[] = "137.226.143." . $i;
      }
    }
    
    $sql = "SELECT ip FROM macauth UNION SELECT ip FROM aps";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $ip);
    $occupiedIPs = array();
    while (mysqli_stmt_fetch($stmt)) {
      $occupiedIPs[] = $ip;
    }
    $stmt->close();
    
    $freeIPs = array_diff($availableIPs, $occupiedIPs);
    
    echo "<br><br>";
    echo '<form method="post">';
    echo "<div style='text-align: center; display: flex; justify-content: center;'>";
    
    // Linke Gruppe (Private IP)
    echo "<div style='display: flex; flex-direction: column; align-items: center; margin-right: 40px;'>"; // Flexbox-Layout für vertikale Ausrichtung
    echo "<select name='selected_ip_priv' class='center-drpdwn'>";
    foreach ($freeIPs_priv as $freeIP) {
        echo "<option value='" . htmlspecialchars($freeIP) . "'>" . htmlspecialchars($freeIP) . "</option>";
    }
    echo "</select>";
    echo "<input type='hidden' name='uid' value='" . htmlspecialchars($selected_uid) . "' readonly>";
    echo "<input type='hidden' name='reload' value=1 readonly>";
    echo "<button type='submit' name='addprivateip' class='center-btn'>Neue Private IP</button>";
    echo "</div>";
    
    // Rechte Gruppe (Public IP)
    echo "<div style='display: flex; flex-direction: column; align-items: center;'>"; // Flexbox-Layout für vertikale Ausrichtung
    echo "<select name='selected_ip' class='center-drpdwn'>";
    foreach ($freeIPs as $freeIP) {
        echo "<option value='" . htmlspecialchars($freeIP) . "'>" . htmlspecialchars($freeIP) . "</option>";
    }
    echo "</select>";
    echo "<input type='hidden' name='uid' value='" . htmlspecialchars($selected_uid) . "' readonly>";
    echo "<input type='hidden' name='reload' value=1 readonly>";
    echo "<button type='submit' name='addpublicip' class='center-btn'>Neue Public IP</button>";
    echo "</div>";
    
    echo "</div>";
    echo "</form>";
    
    echo '</div>'; // Ende des ausklappbaren Bereichs
    echo '</div>';
    echo '</div>';
    
    
    
    
    if (isset($_POST['addprivateip']) || isset($_POST['addpublicip']) || isset($_POST['uid'])) {
      echo '<script>document.getElementById("adminPanel").style.display = "block";</script>';
    }

    echo '<script>
    function toggleAdminPanel() {
        var panel = document.getElementById("adminPanel");
        if (panel.style.display === "none") {
            panel.style.display = "block";
        } else {
            panel.style.display = "none";
        }
    }
    </script>';
  }

  echo "<br><br>";


  $sql = "SELECT natmapping.ip, users.subnet, users.turm
    FROM users 
    JOIN natmapping ON users.subnet = natmapping.subnet
    WHERE users.uid = ?";
  $stmt = mysqli_prepare($conn, $sql);

  mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $public_ip, $subnet, $turm);
  mysqli_stmt_fetch($stmt);
  $stmt->close();
  
  if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    if (isset($_POST["save"]) && (!$_SESSION["NetzAG"] && !$_SESSION["Webmaster"]) && ($_POST["uid"] != $_SESSION["uid"])) {
      // Nachricht für den unbefugten Änderungsversuch formulieren
      $text = "Unbefugter Versuch einer Änderung der POST-Variablen auf IPverwaltung.php.\n\n" .
      "Der User mit der Session UID {$_SESSION['uid']} hat versucht, IPs der UID {$_POST['uid']} zu ändern.\n" .
      "Details der aktuellen POST-Variablen:\n" .
      print_r($_POST, true) . "\n\n" .
      "Details der aktuellen SESSION-Variablen:\n" .
      print_r($_SESSION, true) . "\n\n" .
      "Dies könnte auf einen potenziellen Missbrauch oder eine Sicherheitsverletzung hinweisen.\n\n" .
      "Bitte überprüfen Sie diesen Vorfall und sperren Sie gegebenenfalls den Benutzer sofort.";
  
      $from = "webmaster@weh.rwth-aachen.de";
      $to = "webmaster@weh.rwth-aachen.de";
      $subject = "Unbefugter Änderungsversuch auf IPverwaltung.php";
      $headers = "From: " . $from;
  
      // Senden der E-Mail
      mail($to, $subject, $text, $headers);
  
      // Weiterleitung des Users auf die denied.php Seite
      header("Location: denied.php");
      exit();
    } elseif (isset($_POST["save"])) {
      $ip = $_POST["ip"];
      $id = $_POST["id"];
      $mac1 = $_POST["mac1"];
      $mac2 = $_POST["mac2"];
      $mac3 = $_POST["mac3"];
      $hostname = $_POST["hostname"];
      $selected_uid = $_POST["uid"];


      // Überprüfen, ob die MAC-Adressen korrekt formatiert sind
      $macArray = array_merge($mac1, $mac2, $mac3);
      $all_macs_valid = true;
      $all_ips_valid = true;

      foreach ($macArray as $key => $mac) {
          $mac_valid = null;
      
          if (!empty($mac)) {
              // Entferne Leerzeichen und konvertiere die MAC-Adresse in Großbuchstaben
              $normalized_mac = strtoupper(str_replace(['-', '.', ' '], ':', trim($mac)));
          
              // Überprüfe, ob die normalisierte MAC-Adresse korrekt formatiert ist (XX:XX:XX:XX:XX:XX)
              $mac_valid = filter_var($normalized_mac, FILTER_VALIDATE_REGEXP, array(
                  "options" => array(
                      "regexp" => "/^([0-9A-F]{2}:){5}([0-9A-F]{2})$/"
                  )
              ));
            
              if ($mac_valid !== false) {
                  // Speichere die normalisierte MAC-Adresse zurück ins richtige Array, nur wenn sie valide ist
                  if ($key < count($mac1)) {
                      $mac1[$key] = $normalized_mac;
                  } elseif ($key < count($mac1) + count($mac2)) {
                      $mac2[$key - count($mac1)] = $normalized_mac;
                  } else {
                      $mac3[$key - count($mac1) - count($mac2)] = $normalized_mac;
                  }
              } else {
                  $all_macs_valid = false; 
                  $brokenmac = $mac; // Die ursprüngliche MAC-Adresse wird hier gespeichert
                  break;
              }
          }
      }

      // Überprüfen, ob die IP-Adressen korrekt formatiert sind
      foreach ($ip as $key => $single_ip) {
          $ip_valid = filter_var($single_ip, FILTER_VALIDATE_IP);
  
          if ($ip_valid === false) {
              $all_ips_valid = false;
              $brokenip = $single_ip; // Die ursprüngliche IP-Adresse wird hier gespeichert
              break;
          }
      }
    
      if ($all_macs_valid === false) {
        echo "<div style='text-align: center;'>";
        echo "<span style='color: red; font-size: 20px;'>The MAC address <strong><em>" . $brokenmac . "</em></strong> is not properly formatted! 
        <br>Entering an incorrectly formatted variable into the DHCP configuration would result in the service stopping, preventing anyone from using the internet. 
        <br>Please re-enter your input with the correct formatting!</span><br><br>";              
        echo "</div>";      
      } elseif ($all_ips_valid === false) {
          echo "<div style='text-align: center;'>";
          echo "<span style='color: red; font-size: 20px;'>The IP address <strong><em>" . htmlspecialchars($brokenip) . "</em></strong> is not properly formatted! 
          <br>Entering an incorrectly formatted IP into the DHCP configuration could cause network issues. 
          <br>Please re-enter your input with the correct formatting!</span><br><br>";              
          echo "</div>";
      } else {

        ## TESTAUSGABE
        #for ($j = 0; $j < count($ip); $j++) {
        #  // Ausgabe der Bind-Parameter für das Debugging
        #  echo "Preparing to bind the following parameters:<br>";
        #  echo "ID: " . htmlspecialchars($id[$j]) . "<br>";
        #  echo "MAC1: " . htmlspecialchars($mac1[$j]) . "<br>";
        #  echo "MAC2: " . htmlspecialchars($mac2[$j]) . "<br>";
        #  echo "MAC3: " . htmlspecialchars($mac3[$j]) . "<br>";
        #  echo "Hostname: " . htmlspecialchars($hostname[$j]) . "<br>";
        #  echo "IP: " . htmlspecialchars($ip[$j]) . "<br><br>";
        #}

        $sql = "UPDATE macauth SET mac1=?, mac2=?, mac3=?, hostname=?, ip=? WHERE id=?";
        
        for ($j = 0; $j < count($ip); $j++) {
          $stmt = mysqli_prepare($conn, $sql);
        
          if (!$stmt) {
              die("Error in SQL statement: " . mysqli_error($conn));
          }
        
          mysqli_stmt_bind_param($stmt, "sssssi", $mac1[$j], $mac2[$j], $mac3[$j], $hostname[$j], $ip[$j], $id[$j]);
          if (!mysqli_stmt_execute($stmt)) {
              die("Error in SQL statement execution: " . mysqli_error($conn));
          }
        
          $stmt->close();
        }
      }
    }

    
    //echo "<script>
    //  setTimeout(function() {
    //    document.forms['reload'].submit();
    //  }, 0000);
    //</script>";
    
  }


  $text = "You can only use one MAC per IP at a time!";
  echo '<h2 class = "center">'.($text).'</h2>';


  $sql = "SELECT ip, mac1, mac2, mac3, hostname, id, sublet
  FROM macauth 
  WHERE uid = ? 
  ORDER BY 
      INET_ATON(SUBSTRING_INDEX(ip, '.', 1)),
      INET_ATON(SUBSTRING_INDEX(ip, '.', -1)),
      ip ASC";

  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_bind_result($stmt, $ip, $mac, $mac2, $mac3, $hostname, $id, $sublet);
  
  echo '<form action="IPverwaltung.php" method="post" name="formularipverwaltung">';
  echo "<table class='grey-table'>";
  echo "<tr><th>IP</th><th>MAC 1</th><th>MAC 2</th><th>MAC 3</th><th>Description</th>";

  if ($_SESSION["NetzAG"]) {
    echo "<th></th>";
  }
  
  echo "</tr>";
  while(mysqli_stmt_fetch($stmt)) {

    $cellStyle = ($sublet == 1) ? "style='background-color: #8d150c;'" : "";
    echo "<tr>";

    if ((isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"])) {
      echo "<td $cellStyle><input type='text' name='ip[]' value='".$ip."'></td>";
      echo "<input type='hidden' name='id[]' value='".$id."' readonly>";
  } else {
      echo "<input type='hidden' name='ip[]' value='".$ip."' readonly>";
      echo "<input type='hidden' name='id[]' value='".$id."' readonly>";
      echo "<td $cellStyle style='font-size: 16px;'>$ip</td>";
  }
  echo "<td $cellStyle><input type='text' name='mac1[]' value='".$mac."'></td>";
  echo "<td $cellStyle><input type='text' name='mac2[]' value='".$mac2."'></td>";
  echo "<td $cellStyle><input type='text' name='mac3[]' value='".$mac3."'></td>";
  echo "<td $cellStyle><input type='text' name='hostname[]' value='".$hostname."'></td>";   
  
    
  if ($_SESSION["NetzAG"]) {
    echo "<td $cellStyle>";
    echo "<form method='post' style='margin: 0;'>";
    echo "<button type='submit' name='remove' value='" . $id . "' style='background: none; border: none; cursor: pointer; padding: 0;'>";
    echo '<img src="images/trash_white.png" 
      class="animated-trash-icon" 
      style="width: 24px; height: 24px;">';
    echo "</button>";
    echo "</form>";
    echo "</td>";
  }

    
    
  }
  
  mysqli_stmt_close($stmt);
  echo "</table>";

  echo "<input type='hidden' name='uid' value='".$selected_uid."' readonly>";
  echo "<div style='display: flex; justify-content: center; margin-top: 1%'>";
  echo "<input type='hidden' name='reload' value=1 readonly>";
  echo "<button type='submit' name='save' class='center-btn'>SAVE CHANGES</button>";  
  echo "</div>";
  
  echo "</form>";
  
  $text = "Any changes need 5 minutes to take effect.";
  $title = 'Wir betreiben 2 DNS-Server, die die DHCP-Konfiguration in unterschiedlichen Intervallen aktualisieren. '
    . 'Ein Server fragt alle 5 Minuten, der Andere alle 8 Minuten die Datenbank ab. '
    . 'Also dauert es maximal 5 Minuten, bis eure Änderungen wirksam werden.';
  echo '<h2 class="center" title="' . $title . '" style="cursor: help;">' . $text . '</h2>';
  

  $text = "Your Private IPs are linked to your Local Subnet (<span style='color:#11a50d'>$subnet</span>) and get converted to your assigned Public IP (<span style='color:#11a50d'>$public_ip</span>).<br>
  Every User starts with 5 Private IPs and 1 assigned Public IP.<br>
  If you need more IPs, please visit the Netzwerk-AG consultation hour!";
  echo '<h3 class="center">'.($text).'</h3>';
  echo "<br>";

}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>