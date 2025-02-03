<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
//AJAX
if (isset($_REQUEST["action"]) && $_REQUEST["action"] == 'ajax') {
  if ($_SESSION["NetzAG"]) {
    $sql = "SELECT name FROM users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $_REQUEST["username"]);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();

    if (!empty($result)) {
      echo '<span style="color: green;">User found: ' . $result[0]['name'] . '</span>';
    } else {
      echo '<span style="color: red;">No user found</span>';
    }
    

    exit(0);
  }
}

if (auth($conn) && $_SESSION["NetzAG"]) {
  load_menu();
  
  $address = $mailconfig['address'];
  $user = $mailconfig['user'];
  $password = $mailconfig['password'];
  $mailserverIP = $mailconfig['ip'];

  if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
    if (isset($_POST["decision"]) && isset($_POST["id"])) {
      if($_POST["decision"] == "accept") { 
        $kommentar = $_POST["kommentar"];
        $username = $_POST['username'];
        $agent = $_SESSION['username'];
        $id = $_POST["id"];

        // Username wird zu localpart der Mail, hier wird überprüft ob eindeutig!
        $restrictedNames = [
          'netag', 'waschen', 'sprecher', 'community', 'important', 'essential', 'kasse', 'werkzeug', 'ags', 'hausmeister',
          'mailer-daemon', 'nobody', 'news', 'daemon', 'security', 'root', 'clamav', 'mail', 'postmaster', 'hostmaster',
          'virusalert', 'www', 'www2', 'www-data', 'www2-data', 'dns', 'ftp', 'usenet', 'noc', 'abuse', 'syslog', 'nagios',
          'domain', 'drucker', 'spam', 'ftp-admin', 'kontowecker', 'info', 'netz-ag', 'netzag', 'netz', 'netzwerk-ag',
          'netzwerkag', 'netzwerk', 'buchungssystem', 'cloud', 'no-reply', 'noreply', 'wlan', 'ipv6', 'cacti', 'graph', 
          'system', 'verwaltung', 'kamera', 'lernraum', 'net', 'haussprecher', 'vorstand', 'pappnasen', 'wag', 'werkzeuge', 
          'werkzeugbuchung', 'spuelen', 'wasch'
        ];
      
        $roomMailLocalParts = [];
        for ($etage = 0; $etage <= 17; $etage++) {
            for ($zimmer = 1; $zimmer <= 16; $zimmer++) {
                $roomMailLocalParts[] = 'z' . str_pad($etage, 2, '0', STR_PAD_LEFT) . str_pad($zimmer, 2, '0', STR_PAD_LEFT);
            }
            $roomMailLocalParts[] = 'etage' . str_pad($etage, 2, '0', STR_PAD_LEFT);
        }        
        $restrictedNames = array_merge($restrictedNames, $roomMailLocalParts);

        $uniqueUsername = false;
        while (!$uniqueUsername) {
            $sql = "SELECT 1 FROM users WHERE username = ? OR (FIND_IN_SET(?, aliase) > 0 AND mailisactive = 1)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ss", $username, $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
        
            if (mysqli_stmt_num_rows($stmt) > 0) {
                mysqli_stmt_close($stmt);
                $username .= '0';
                continue;
            }
            mysqli_stmt_close($stmt);
        
            $sql = "SELECT 1 FROM groups WHERE FIND_IN_SET(?, aliase) > 0";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
        
            if (mysqli_stmt_num_rows($stmt) > 0) {
                mysqli_stmt_close($stmt);
                $username .= '0';
                continue;
            }
            mysqli_stmt_close($stmt);
        
            if (in_array($username, $restrictedNames)) {
                $username .= '0';
                continue;
            }
        
            $uniqueUsername = true;
        }
        
        $sql = "SELECT room, firstname, lastname, starttime, geburtsort, email, geburtstag, telefon, forwardemail, sublet, subletterend, turm FROM registration WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname, $starttime, $geburtsort, $email, $geburtstag, $telefon, $forwardemail, $sublet, $subletterend, $turm);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        
        $name = $firstname . " " . $lastname;
        $groups = 1;
        $subtenanttill = ($subletterend === NULL) ? 0 : $subletterend;
        $historie = date("d.m.Y") . " Anmeldung bestätigt ({$_SESSION['agent']})";

        $subnet = getRoomSubnet($conn, $room, $turm);

        if ($subnet !== false) {
        
          $pwwifi = pwgen();
          $pwhausunhashed = pwgen();
          $pwhaus = pwhash($pwhausunhashed);

          if ($sublet == "0") {
            roomcheck($conn, $room, $turm);
          } elseif ($sublet == "1") {
            subletcheck($conn, $room, $turm, $subletterend);
          }

          $sql = "INSERT INTO users SET username = ?, room = ?, name = ?, firstname = ?, lastname = ?, groups = ?, starttime = ?, subtenanttill = ?, geburtstag = ?, geburtsort = ?, telefon = ?, email = ?, forwardemail = ?, historie = ?, subnet = ?, pwhaus = ?, pwwifi = ?, turm = ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "ssssssiiisssisssss", $username, $room, $name, $firstname, $lastname, $groups, $starttime, $subtenanttill, $geburtstag, $geburtsort, $telefon, $email, $forwardemail, $historie, $subnet, $pwhaus, $pwwifi, $turm);
          mysqli_stmt_execute($stmt);
          $stmt->close();
          
          $uid = mysqli_insert_id($conn);
          addPrivateIPs($conn, $uid, $subnet);
          
          $status_anmeldung = 1;

          $sql = "UPDATE registration SET status = 1 WHERE id = ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "i", $id);
          mysqli_stmt_execute($stmt);
          $stmt->close();
          

          // Dateien mit <id>_* in den neuen Benutzerordner verschieben und umbenennen
          $uploadDir = "anmeldung/"; // Relativer Pfad für hochgeladene Dateien

          // Zielordner für den Benutzer erstellen
          $userDir = $uploadDir . $username . "/";
          if (!is_dir($userDir)) {
              mkdir($userDir, 0755, true); // Ordner erstellen, falls nicht vorhanden
          }

          // Alle passenden Dateien finden
          $files = glob($uploadDir . $id . "_*");

          foreach ($files as $file) {
              if (is_file($file)) {
                  // Ursprünglichen Dateinamen analysieren
                  $newFileName = preg_replace("/^{$id}_/", $username . "_", basename($file)); // Dateiname anpassen
                  $newFilePath = $userDir . $newFileName; // Neuer Pfad mit angepasstem Namen

                  // Datei verschieben
                  rename($file, $newFilePath);
              }
          }


          $message = "Dear " . $firstname . ",\n\nyour registration was successful.\n\n"
          . "Credentials:\n\n House-Username: " . $username . "\n House-Password: " . $pwhausunhashed . "\n\n";
          if ($turm != 'tvk') {
            $message .= " WiFiOnly-Username: " . $username . "@weh.rwth-aachen.de\n WiFiOnly-Password: " . $pwwifi . "\n\n";
            
            $message .= "Connecting to the internet:\n"
            . " 1. Connect your device to tuermeroam.\n"
            . " 1.1. Wireless: Connect your device to the Wi-Fi network 'tuermeroam' with your Wi-Fi-Only credentials.\n"
            . " 1.2. Wired: Connect your device to the network socket in your room using a common ethernet cable. If you have two outlets; only one of these actually works. Most often it's the one closer to the window.\n"
            . " 2. Open a web browser and navigate to the following web page: getnet.weh.ac\n"
            . " 3. Log in with your House credentials. These credentials are also used for every other login at a WEH service.\n"
            . " 4. If this is the first device you're registering, you need to change your password. After you have changed it, please return to this web site: getnet.weh.ac. The Wi-Fi-Only password was not changed by this!\n"
            . " 5. Choose any free IP address. Which one you choose is irrelevant, but you should use one device per IP address. After up to 10 minutes your device will be connected. If needed, you can ask the Netzwerk-AG for more IPs.\n\n"
            . "We also want to point out:\n"
            . " • It is not allowed to have your own Wi-Fi network in the tower. These networks interfere with the already existing tuermeroam network. Netzwerk-AG is always working on improving the connection for every room in the tower.\n"
            . " • There are some Smart-Home devices and gaming consoles that don't support our security protocol WPA2 Enterprise. We set up the parallel network weh-pskonly for these. If you want to connect your device with this network, please use this page backend.weh.rwth-aachen.de/PSK.php\n"
            . " • Before you ask, take a look at the FAQ on our website first! www2.weh.rwth-aachen.de/en/faq/\n"
            . " • Sharing your login data with other residents is not allowed and may lead to a penalty of 150€.\n\n"
            . "Paying your membership fees:\n"
            . " • Your WEH account is also a prepaid account for all services within WEH. You can use the money to purchase washing coins, use the printer or pay your membership fees.\n"
            . " • Membership fees are automatically debited from your WEH account on the 1st of each month. If you don't have sufficient funds, a warning email will be sent to you before the billing cycle.\n"
            . " • So make sure there's always enough money on your account for your membership fees or you risk an internet ban.\n"
            . " • You can top up your account via bank transfer or PayPal on this page: backend.weh.rwth-aachen.de/UserKonto.php\n\n";

          } else {
            $message .= " WiFiOnly-Username: " . $username . "\n WiFiOnly-Password: " . $pwwifi . "\n\n"
            . "=== IMPORTANT: Temporary Information for TvK Residents ===\n\n"
            . "Your WiFiOnly-Username is only used for the temporary network 'fijiroam' - not for 'tuermeroam'!\n"
            . "You will receive more information as soon as 'tuermeroam' becomes available in TvK. Until then, please use the 'fijiroam' WiFi network.\n\n"
            . "=== END OF TEMPORARY MESSAGE FOR TvK RESIDENTS ===\n\n";
            
            $message .= "Connecting to the internet:\n"
            . " 1. Connect your device to fijiroam.\n"
            . " 1.1. Wireless: Connect your device to the Wi-Fi network 'fijiroam' with your Wi-Fi-Only credentials.\n"
            . " 1.2. Wired: Connect your device to the network socket in your room using a common ethernet cable. If you have two outlets; only one of these actually works. Most often it's the one closer to the window.\n"
            . " 2. Open a web browser and navigate to the following web page: backend.weh.rwth-aachen.de/denied.php\n"
            . " 3. Log in with your House credentials. These credentials are also used for every other login at a WEH service.\n"
            . " 4. Enter the TAN that was sent to your E-Mail!\n"
            . " 5. Navigate to 'Netz' -> 'IP Management'\n"
            . " 6.1. Register the MAC-Address of your devices on your IPs. You can only use one device per IP at a time, so it's safe to register each device on a different IP!\n"
            . " 6.2. After up to 10 minutes your device will be connected. If needed, you can ask the Netzwerk-AG for more IPs.\n\n"
            . "We also want to point out:\n"
            . " • It is not allowed to have your own Wi-Fi network in the tower. These networks interfere with the already existing tuermeroam network. Netzwerk-AG is always working on improving the connection for every room in the tower.\n"
            . " • There are some Smart-Home devices and gaming consoles that don't support our security protocol WPA2 Enterprise. We set up the parallel network weh-pskonly for these. If you want to connect your device with this network, please use this page backend.weh.rwth-aachen.de/PSK.php\n"
            . " • Before you ask, take a look at the FAQ on our website first! www2.weh.rwth-aachen.de/en/faq/\n"
            . " • Sharing your login data with other residents is not allowed and may lead to a penalty of 150€.\n\n"
            . "Paying your membership fees:\n"
            . " • Your WEH account is also a prepaid account for all services within WEH. You can use the money to purchase washing coins, use the printer or pay your membership fees.\n"
            . " • Membership fees are automatically debited from your WEH account on the 1st of each month. If you don't have sufficient funds, a warning email will be sent to you before the billing cycle.\n"
            . " • So make sure there's always enough money on your account for your membership fees or you risk an internet ban.\n"
            . " • You can top up your account via bank transfer or PayPal on this page: backend.weh.rwth-aachen.de/UserKonto.php\n\n";
          }
          if ($forwardemail != 1) {
            $message .= "Using your E-Mail account:\n"
          . " • You can find all the information about how to use your new mail address on this page: https://www2.weh.rwth-aachen.de/ags/netzag/email/\n"
          . " • Please make sure to check your mails at least once a week!\n\n";
          } else {
            $message .= "E-Mail Forwarding:\n"
          . " • All mails will be forwarded to your mailaccount $email\n"
          . " • Please make sure to check your mails at least once a week and ensure your mailbox does not overflow!!\n\n";
          }
          if ($turm != 'tvk') {
            $message .= "Activating your washing account:\n"
            . " • In order to be allowed to use the laundry room, a short instruction to washing must be completed.\n" 
            . " • To attend this event, please check our website www2.weh.rwth-aachen.de/en/ags/waschag/ for the most up-to-date information.\n\n"
            . "Bicycle parking in the basement:\n"
            . " • If you want to park your bike in the basement you have to apply with the Fahrrad-AG for a parking space on our website. You are not allowed to park your bike on a space in the basement that has not been assigned to you.\n\n";
          }
          $message .= "If you have any other questions feel free to ask us in our consultation hour.\n"
          . "We will see you there!\n"
          . "Netzwerk-AG WEH e.V.";

          $to = $email;
          $subject = "WEH - Registration";
          $headers = "From: " . $address . "\r\n";
          $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
          if (mail($to, $subject, $message, $headers)) {
              echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                      <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                    </div>";
          } else {
              echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                      <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                    </div>";
          }
        } else {
          echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
          <span style='color: red; font-size: 20px;'>Kein Subnetz gefunden!</span>
          </div>";
        }

      } elseif($_POST["decision"] == "decline") {
        $sql = "UPDATE registration SET status = -1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        mysqli_stmt_execute($stmt);
        $stmt->close(); 

        $sql = "SELECT email, firstname FROM registration WHERE id=? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        mysqli_stmt_execute($stmt);
        
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) == 1) {
            mysqli_stmt_bind_result($stmt, $email, $firstname);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);
        


        // Dateien mit <id>_* aus 'anmeldungen/' löschen
        $uploadDir = "anmeldung/"; // Relativer Pfad für hochgeladene Dateien
        $userId = $_POST["id"]; // Die ID aus POST-Daten
        $files = glob($uploadDir . $userId . "_*"); // Alle passenden Dateien finden

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file); // Datei löschen
            }
        }


        $message = "Dear " . $firstname . ",\n\nyour registration was declined.\n\nThis is the reason:\n" . $_POST["kommentar"] . "\n\nBest Regards,\nNetzwerk-AG WEH e.V.";
        
        $to = $email;
        $subject = "WEH - Declined Registration";
        $headers = "From: " . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
        
        if (mail($to, $subject, $message, $headers)) {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                  </div>";
        } else {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                  </div>";
        }


      } elseif($_POST["decision"] == "psk") {
        $sql = "UPDATE pskonly SET status = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        mysqli_stmt_execute($stmt);
        $stmt->close(); 
        
        $sql = "SELECT users.username, users.firstname, pskonly.mac, users.subnet, pskonly.beschreibung, users.uid, users.turm FROM users JOIN pskonly ON users.uid = pskonly.uid WHERE pskonly.id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $username, $firstname, $mac, $subnet, $beschreibung, $uid, $pskturm);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $sql = "SELECT mac1, mac2, mac3 FROM macauth WHERE mac1 = ? OR mac2 = ? OR mac3 = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sss", $mac, $mac, $mac);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mac1, $mac2, $mac3);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        
        if ($mac1 === null && $mac2 === null && $mac3 === null) {
          $subnetWithoutEnding = substr($subnet, 0, -1);
  
          $availableIPs = array();
          for ($i = 1; $i <= 255; $i++) {
            $availableIPs[] = $subnetWithoutEnding . $i;
          }
          
          $sql = "SELECT ip FROM macauth WHERE uid=?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "i", $uid);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $ip);
          
          // Erstelle ein Array mit den belegten IPs
          $occupiedIPs = array();
          while (mysqli_stmt_fetch($stmt)) {
            $occupiedIPs[] = $ip;
          }
          
          // Finde eine freie IP, die noch nicht belegt ist
          $freeIP = '';
          foreach ($availableIPs as $ip) {
            if (!in_array($ip, $occupiedIPs)) {
              $freeIP = $ip;
              break;
            }
          }
    
          $stmt->close();

          $pos = strpos($beschreibung, "-");
          if ($pos !== false) {
              $hostname = "PSK - " . substr($beschreibung, $pos + 2);
          } else {
              $hostname = $beschreibung;
          }
          $zeit = time();

          $insert_sql = "INSERT INTO macauth (uid, tstamp, ip, mac1, hostname) VALUES (?,?,?,?,?)";
          $insert_var = array($uid, $zeit, $freeIP, $mac, $hostname);
          $stmt = mysqli_prepare($conn, $insert_sql);
          mysqli_stmt_bind_param($stmt, "iisss", ...$insert_var);
          mysqli_stmt_execute($stmt);
          if(mysqli_error($conn)) {
              echo "MySQL Fehler: " . mysqli_error($conn);
          }
          mysqli_stmt_close($stmt);
        }

        $message = "Dear " . $firstname . ",\n\nyour device " . $mac . " was registered for " . $pskturm . "-pskonly.\n\nThe password for the network is:\nEikieJ9Yie3aTh9sHaz1\n\nBest Regards,\nNetzwerk-AG WEH e.V.";
        
        $to = $username . "@" . $pskturm . ".rwth-aachen.de";
        $subject = $pskturm . "-pskonly Credentials";
        $headers = "From: " . $address . "\r\n";
        $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
        
        if (mail($to, $subject, $message, $headers)) {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                  </div>";
        } else {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                  </div>";
        }

      } elseif($_POST["decision"] == "pskdeclined") {
        $sql = "UPDATE pskonly SET status = -1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        mysqli_stmt_execute($stmt);
        $stmt->close(); 
      }
    }
    echo '<div style="text-align: center;">
    <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
    </div>';
    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
      setTimeout(function() {
        document.forms['reload'].submit();
      }, 2000);
    </script>";

  }

  echo '<div style="display: flex; justify-content: center; align-items: flex-start; gap: 50px; margin: 0 auto; max-width: 80%; padding: 20px; box-sizing: border-box;">';

    // Linke Box: Neue Anmeldungen
    $status0 = false; // Initialstatus für neue Anmeldungen
    $sql = "SELECT id, room, turm FROM registration WHERE status = 0 ORDER BY room ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
    
    // Überprüfe, ob die Abfrage Ergebnisse enthält
    if (empty($result)) {
        $left_box_color = "rgba(17, 165, 13, 0.7)"; // Grün mit Transparenz, wenn leer
        $left_no_requests_text = "<p style='color: white; font-size: 20px; margin-top: 50px;'>Keine Anmeldungen</p>";
    } else {
        $left_box_color = "rgba(0, 0, 0, 0.7)"; // Schwarz, wenn Ergebnisse vorhanden
        $left_no_requests_text = "";
    }
    
    echo "<div style='flex: 1; text-align: center; border: 2px solid white; padding: 20px; border-radius: 10px; background-color: $left_box_color; margin-bottom: 50px;'>";
      echo "<span style='color: white; font-size: 30px;'>Neue Anmeldungen:</span><br><br>";
      echo '<form method="post" action="Anmeldung.php"><input type="hidden" name="wayoflife" value="666">';
      echo '<div style="max-width: 300px; margin: 0 auto;">';     
        if (empty($result)) {
          // Zeige den Text, wenn keine Ergebnisse vorhanden sind
          echo $left_no_requests_text;
        } else { 
          while ($entry = array_shift($result)) {
              $btn_color = ($entry['turm'] === 'tvk') ? '#E49B0F' : '#11a50d';
              echo '<button type="submit" name="id" value="' . htmlspecialchars($entry["id"]) . '" class="white-center-btn" style="display: inline-block; font-size: 20px; background-color:' . $btn_color . ';">' . htmlspecialchars(str_pad($entry["room"], 4, '0', STR_PAD_LEFT)) . '</button>';
              $status0 = true;
          }
        }
      echo '</div>';
      if ($status0) {
          echo "<br>";
      }
      echo '</form>';
    echo '</div>'; // Ende der linken Box
    
    // Rechte Box: PSK-Only Anfragen
    $statuspsk = false; // Initialstatus für PSK-Only Anfragen
    $sql = "SELECT pskonly.id, users.room, users.turm FROM pskonly JOIN users ON pskonly.uid = users.uid WHERE pskonly.status = 0 ORDER BY users.room ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
    
    // Überprüfe, ob die Abfrage Ergebnisse enthält
    if (empty($result)) {
        $right_box_color = "rgba(17, 165, 13, 0.7)"; // Grün mit Transparenz, wenn leer
        $right_no_requests_text = "<p style='color: white; font-size: 20px; margin-top: 50px;'>Keine Anfragen</p>";
    } else {
        $right_box_color = "rgba(0, 0, 0, 0.7)"; // Schwarz, wenn Ergebnisse vorhanden
        $right_no_requests_text = ""; // Kein Text, wenn es Ergebnisse gibt
    }
    
    echo "<div style='flex: 1; text-align: center; border: 2px solid white; padding: 20px; border-radius: 10px; background-color: $right_box_color; margin-bottom: 50px;'>";
      echo "<span style='color: white; font-size: 30px;'>PSK-Only Anfragen:</span><br><br>";
      echo '<form method="post"><input type="hidden" name="wayoflife" value=3>';
      echo '<div style="max-width: 300px; margin: 0 auto;">';      
        if (empty($result)) {
          // Zeige den Text, wenn keine Ergebnisse vorhanden sind
          echo $right_no_requests_text;
        } else {
            // Generiere die Buttons, wenn Ergebnisse vorhanden sind
            while ($entry = array_shift($result)) {
                $room_color = ($entry['turm'] === 'tvk') ? '#E49B0F' : '#11a50d';
                echo '<button type="submit" name="id" value="' . htmlspecialchars($entry["id"]) . '" class="white-center-btn" style="display: inline-block; font-size: 20px; background-color:' . $room_color . ';">' . htmlspecialchars(str_pad($entry["room"], 4, '0', STR_PAD_LEFT)) . '</button>';
                $statuspsk = true;
            }
        }
      echo '</div>';
      if ($statuspsk) {
          echo "<br>";
      }
      echo '</form>';
    echo '</div>'; // Ende der rechten Box
  
  echo '</div>'; // Ende des Flexbox-Containers
  


  if (isset($_POST["id"]) && !isset($_POST["decision"]) && !isset($_POST["close"])) {

    $zeit = time();
    $wayoflife = $_POST["wayoflife"];
    if ($wayoflife == 666) { # Anmeldung annehmen
        $id = $_POST["id"];
        $sql = "SELECT * FROM registration WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $result = get_result($stmt);
        $user = array_shift($result);
        $stmt->close();
        
        $sql = "SELECT lastradius, username, name, uid FROM users WHERE room = ? and turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "is", $user["room"], $user["turm"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $lastradius, $aktuellerbewohner_username, $aktuellerbewohner_name, $aktuellerbewohner_uid);
        $raumbelegt = false;
        if (mysqli_stmt_fetch($stmt)) {
            $raumbelegt = true;
        }
        mysqli_stmt_close($stmt);
  
        if ($lastradius == 0) {
            $lastradiusstring = "> 1 Woche";
            $lastradiuscolor = "green";
        } else {
            $abstand_in_sekunden = $zeit - $lastradius + 3600;
            $abstand_tage = floor($abstand_in_sekunden / (24 * 60 * 60));
            $abstand_stunden = floor(($abstand_in_sekunden % (24 * 60 * 60)) / 3600);
            if ($abstand_tage == 0 && $abstand_stunden == 0) {
                $abstand_text = "Verbunden";
                $lastradiuscolor = "red";
            } elseif ($abstand_tage > 0) {
                $abstand_text = "$abstand_tage Tage, $abstand_stunden Stunden";
                $lastradiuscolor = "yellow";
            } elseif ($abstand_stunden == 1) {
                $abstand_text = "$abstand_stunden Stunde";
                $lastradiuscolor = "red";
            } else {
                $abstand_text = "$abstand_stunden Stunden";
                $lastradiuscolor = "red";
            }
            $lastradiusstring = $abstand_text;
        }    
  
        if ($user["starttime"] > $zeit) {
            $registrationDateStyle = 'style="color: red; font-weight: bold;"';
        } else {
            $registrationDateStyle = '';
        }
      
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post"">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>
        <form action="Anmeldung.php" method="post" name="formular1">');
        if ($user["sublet"]) {
          echo('<p style="color:red; font-weight:bold">SUBLET</p>
          <label class="form-label">End of sublet:</label>
          <input type="text" name="subletterend" class="form-input" value="'.htmlspecialchars(utf8_encode((date("d.m.Y", $user["subletterend"])))).'" readonly>');
        }
  
        if ($raumbelegt) {
          echo('<p>
            <span style="color:red; font-size: larger;">Ein alter User verliert seine Verbindung, wenn dieser User angenommen wird!<br><br></span>
            <span style="color:red">Raum: '.$user["room"].'<br>
            Name: '.$aktuellerbewohner_name.'<br>
            Username: '.$aktuellerbewohner_username.'<br>
            UID: '.$aktuellerbewohner_uid.'</span><br>
            <span style="color:'.$lastradiuscolor.'">Letzter Radius Auth: '.$lastradiusstring.'</span>
          </p>');
        }
        echo('
          <input type="hidden" name="id" value='.htmlspecialchars($id).'>

          <label class="form-label">Turm:</label>
          <input type="text" name="turm" class="form-input" value="'.htmlspecialchars($user["turm"]).'" readonly>
          <br>
          <label class="form-label">Name:</label>
          <input type="text" name="firstname" class="form-input" value="'.htmlspecialchars($user["firstname"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8").' '.htmlspecialchars($user["lastname"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8").'" readonly>
          <br>
          <label class="form-label">Registration Date:</label>
          <input type="text" name="registration_date" class="form-input" value="'.htmlspecialchars(date("d.m.Y", $user["starttime"])).'" readonly ' . $registrationDateStyle . '>');
          if (strtotime($user["starttime"]) > $zeit && $raumbelegt) {
            echo ('<span style="color:red; font-size: larger;">Einzugsdatum noch nicht erreicht!</span>');
          }
        echo('
          <br><br>
          <label class="form-label">Geburtstag:</label>
          <input type="text" name="geburtstag" class="form-input" value="'.htmlspecialchars(date("d.m.Y", $user["geburtstag"])).'" readonly>
          <br>
          <label class="form-label">Herkunftsland:</label>
          <input type="text" name="geburtsort" class="form-input" value="'.htmlspecialchars($user["geburtsort"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8").'" readonly>
          <br>
          <label class="form-label">Telefonnummer:</label>
          <input type="tel" name="telefon" class="form-input" value="'.htmlspecialchars($user["telefon"]).'" readonly>
          <br>
          <label class="form-label">E-Mail:</label>
          <input type="email" name="email" class="form-input" value="'.htmlspecialchars(utf8_encode($user["email"])).'" readonly>
          <br>
          <label class="form-label">Zimmernummer:</label>
          <input type="text" name="room_number" class="form-input" value="'.htmlspecialchars($user["room"]).'" readonly>
          <br>
          <label class="form-label">Username:</label>
          <input type="text" name="username" class="form-input" value="'.htmlspecialchars($user["username"]).'" readonly>
          <br>');
        
        
          $uploadDir = "anmeldung/"; // Verzeichnis mit den hochgeladenen Dateien
          $userId = $user["id"]; // Benutzer-ID
          // Prüfen, ob das Verzeichnis existiert
          if (!is_dir($uploadDir)) {
              echo "<p style='color: red;'>Das Verzeichnis '$uploadDir' existiert nicht.</p>";
              exit;
          }
          // Dateien im Verzeichnis durchsuchen
          $files = array_diff(scandir($uploadDir), ['.', '..']); // Entfernt "." und ".."
          echo "<div style='display: flex; flex-wrap: wrap; gap: 20px;'>";
          // Überprüfen, ob Dateien vorhanden sind
          $foundFiles = false;
          foreach ($files as $file) {
            // Überprüfen, ob die Datei zur User-ID gehört
            if (preg_match("/^{$userId}_(id|mv|af)\.(.+)$/", $file, $matches)) {
                $type = $matches[1]; // Typ der Datei (id, mv, af)
                $extension = $matches[2]; // Dateiendung
                $filePath = $uploadDir . $file;
        
                echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center;'>";
        
                // Datei direkt anzeigen
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    // Bild direkt einbetten und skalieren
                    echo "<img src=\"$filePath\" alt=\"$type\" style='max-width: 100%; max-height: 100%; object-fit: contain;'>";
                } elseif ($extension === 'pdf') {
                    // PDF einbetten mit Zoom
                    echo "<embed src=\"$filePath#zoom=page-width\" type=\"application/pdf\" style='width: 100%; height: 1000px;'>";
                  } else {
                    // Fallback für unbekannte Typen
                    echo "<a href=\"$filePath\" target=\"_blank\">Datei öffnen</a>";
                }
        
                echo "</div>";
        
                $foundFiles = true;

            }
        }
          echo "</div>";
          if (!$foundFiles) {
              echo "<p style='color: orange;'>Keine Dateien für User-ID $userId gefunden.</p>";
          }
        
        echo('<label class="form-label">Grund für Ablehnung:</label>
          <input type="text" name="kommentar" class="form-input">
          <br>
          <label>
          <input type="radio" name="decision" value="accept">
          <span style="color:green; font-weight: bold;">ACCEPT</span>
          </label>
          <label>
          <input type="radio" name="decision" value="decline">
          <span style="color:red; font-weight: bold;">DECLINE</span>
          </label>
          <br>
          <br>
          <div class="form-group">
          <input type="hidden" name="reload" value=1>
            <input type="submit" value="Submit" class="form-submit">
          </div>
        </form>
        </div>');
  
    } elseif ($wayoflife == 3) { # PSK
      $id = $_POST["id"];
      $sql = "SELECT * FROM pskonly WHERE id = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $id);
      mysqli_stmt_execute($stmt);
      $result = get_result($stmt);
      $user = array_shift($result);
      $stmt->close();
      $pskturm = "error";
      $interfacename = "error";
      $sql = "SELECT turm FROM users WHERE uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $user["uid"]);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $pskturm);
      mysqli_stmt_fetch($stmt);
      $stmt->close();
      if ($pskturm == "weh") {
        $interfacename = "vlan919";
        $wlc_url = "http://wlc.wlan.weh.ac/";
      } elseif ($pskturm == "tvk") {
        $interfacename = "bewohnernetz";
        $wlc_url = "http://wlc.tvk.rwth-aachen.de/";
      }
      
      echo '<div class="overlay"></div>
      <div class="anmeldung-form-container form-container">
          <form method="post">
              <button type="submit" name="close" value="close" class="close-btn">X</button>
          </form>
          <br>
          <form method="post" name="formularpsk" id="formularpsk">
        
          <br><br>
        <div style="text-align: center;">
            <img src="'.$user["pfad"].'" alt="Bild" style="max-width: 400px; max-height: 400px; width: auto; height: auto;">
        </div>

          <br><a href=' . $wlc_url . ' target="_blank" class="white-text" style="font-size: 30px; text-align: center; display: block; margin: 0 auto;">Zum WLC</a>              
              <br><br>
              <span style="font-size: 20px; text-align: center; color: white; display: block; margin: 0 auto;">Was ihr im WLC unter Security->MAC Filtering->New eintragen müsst:</span><br>              
              <input type="hidden" name="id" value="'.htmlspecialchars($id).'">
              <label class="form-label">MAC Address:</label>
              <input type="text" name="mac" class="form-input" value="'.htmlspecialchars($user["mac"]).'" readonly>
              <label class="form-label">Profile Name:</label>
              <input type="text" name="free" class="form-input" value="'.$pskturm.'-pskonly" readonly>
              <label class="form-label">Beschreibung:</label>
              <input type="text" name="beschreibung" class="form-input" value="'.htmlspecialchars($user["beschreibung"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8").'" readonly>
              <label class="form-label">IP Address:</label>
              <input type="text" name="free" class="form-input" value="" readonly>
              <label class="form-label">Interface Name:</label>
              <input type="text" name="free" class="form-input" value="'.$interfacename.'" readonly>
              <br>
              <br>
              <div class="form-group">           
                  <label>
                  <input type="radio" name="decision" value="psk">
                  <span style="color:green; font-weight: bold;">Daten wurden im WLC eingetragen</span>
                  </label>            
                  <label>
                  <input type="radio" name="decision" value="pskdeclined">
                  <span style="color:red; font-weight: bold;">Abgelehnt</span>
                  </label>
                  <input type="hidden" name="reload" value="1">   
                  <br>
                  <br>           
                  <span style="font-size: 15px; text-align: center; color: white; display: block; margin: 0 auto;">Bei Accept erhält der User eine automatisierte Mail mit Credentials und die MAC wird in macauth eingetragen.<br>Bei Decline wird NUR der Status der Anfrage geändert. </span><br>              
                  <br>
                  <input type="submit" value="Hau raus!" class="form-submit">
              </div>
          </form>
      </div>';  
    }
  }
}
else {
  header("Location: denied.php");
}

$conn->close();
?>
</html>