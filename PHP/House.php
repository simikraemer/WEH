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
                   (oldroom = '$searchTerm') OR
                   (uid = '$searchTerm'))";
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
<!-- Fiji -->
<!-- Für den WEH e.V. -->

<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["TvK-Sprecher"])) {
  load_menu();

  echo '<form method="post" style="display:flex; justify-content:center;">';
  echo '<button type="submit" name="weh" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
  echo '<button type="submit" name="tvk" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
  echo '<button type="submit" name="sublet" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Sublet</button>';
  echo '<button type="submit" name="moved" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Ausgezogen</button>';
  echo '<button type="submit" name="out" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Abgemeldet</button>';
  echo '<button type="submit" name="ehre" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Ehrenmitglieder</button>';
  echo '<button type="submit" name="dummy" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Dummys</button>';
  echo '</form>';
  echo '<br><br>';


  if (!isset($_POST["id"]) && !isset($_POST["sublet_return"])) {
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
    echo "    hiddenField.name = 'id';";
    echo "    hiddenField.value = userId;";
    echo "    form.appendChild(hiddenField);";
    echo "    document.body.appendChild(form);";
    echo "    form.submit();";
    echo "}";
    echo "</script>";
    
    
    echo "</body>";
    echo "</html>";
  }

  
  $sqltx = "SELECT uid FROM sperre WHERE starttime < UNIX_TIMESTAMP() AND UNIX_TIMESTAMP() < endtime AND internet = 1";
  $stmttx = mysqli_prepare($conn, $sqltx);
  mysqli_stmt_execute($stmttx);
  mysqli_stmt_bind_result($stmttx, $banned_uid);
  $banned_uids = array();
  while (mysqli_stmt_fetch($stmttx)){
      $banned_uids[] = $banned_uid;
  }

  $sqltx = "SELECT room FROM aps WHERE turm = 'weh' AND nagios > 0";
  $stmttx = mysqli_prepare($conn, $sqltx);
  mysqli_stmt_execute($stmttx);
  mysqli_stmt_bind_result($stmttx, $wlan_room);
  $weh_wlan_rooms = array();
  while (mysqli_stmt_fetch($stmttx)){
      $weh_wlan_rooms[] = $wlan_room;
  }

  $sqltx = "SELECT room FROM aps WHERE turm = 'tvk' AND nagios > 0";
  $stmttx = mysqli_prepare($conn, $sqltx);
  mysqli_stmt_execute($stmttx);
  mysqli_stmt_bind_result($stmttx, $wlan_room);
  $tvk_wlan_rooms = array();
  while (mysqli_stmt_fetch($stmttx)){
      $tvk_wlan_rooms[] = $wlan_room;
  }

  $sql = "SELECT room, uid, firstname, lastname, groups, username FROM users WHERE pid = 11 AND turm = 'weh'";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $room, $uid, $firstname, $lastname, $groups, $username);
  $users_by_room_weh = array();
  while (mysqli_stmt_fetch($stmt)) {
      $firstname = strtok($firstname, ' ');
      $lastname = strtok($lastname, ' ');
      $users_by_room_weh[$room][] = array("uid" => $uid, "firstname" => $firstname, "lastname" => $lastname, "groups" => $groups, "username" => $username);
  }

  $sql = "SELECT room, uid, firstname, lastname, groups, username FROM users WHERE pid = 11 AND turm = 'tvk'";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $room, $uid, $firstname, $lastname, $groups, $username);
  $users_by_room_tvk = array();
  while (mysqli_stmt_fetch($stmt)) {
      $firstname = strtok($firstname, ' ');
      $lastname = strtok($lastname, ' ');
      $users_by_room_tvk[$room][] = array("uid" => $uid, "firstname" => $firstname, "lastname" => $lastname, "groups" => $groups, "username" => $username);
  }

  
  $sqlsublet = "SELECT oldroom, uid, name, groups, subletterstart, subletterend, username FROM users WHERE pid = 12 ORDER BY subletterend ASC";
  $stmt = mysqli_prepare($conn, $sqlsublet);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $oldroom, $uid, $name, $groups, $subletterstart, $subletterend, $username);    
  $roomssublet = array();
  $sublets_by_room = array();
  while (mysqli_stmt_fetch($stmt)){
      $sublets_by_room[$oldroom][] = array("uid" => $uid, "name" => $name, "groups" => $groups, "subletterstart" => $subletterstart, "subletterend" => $subletterend, "username" => $username);
      $roomssublet[] = $oldroom;
  }
  
  $sqlsublet = "SELECT oldroom, uid, name, groups, subletterstart, subletterend, username FROM users WHERE pid = 12 and turm = 'tvk' ORDER BY subletterend ASC";
  $stmt = mysqli_prepare($conn, $sqlsublet);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $oldroom, $uid, $name, $groups, $subletterstart, $subletterend, $username);    
  $tvk_roomssublet = array();
  $sublets_by_room = array();
  while (mysqli_stmt_fetch($stmt)){
      $sublets_by_room[$oldroom][] = array("uid" => $uid, "name" => $name, "groups" => $groups, "subletterstart" => $subletterstart, "subletterend" => $subletterend, "username" => $username);
      $tvk_roomssublet[] = $oldroom;
  }
  
  $sqlsublet = "SELECT oldroom, uid, name, groups, subletterstart, subletterend, username FROM users WHERE pid = 12 and turm = 'weh' ORDER BY subletterend ASC";
  $stmt = mysqli_prepare($conn, $sqlsublet);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $oldroom, $uid, $name, $groups, $subletterstart, $subletterend, $username);    
  $weh_roomssublet = array();
  $sublets_by_room = array();
  while (mysqli_stmt_fetch($stmt)){
      $sublets_by_room[$oldroom][] = array("uid" => $uid, "name" => $name, "groups" => $groups, "subletterstart" => $subletterstart, "subletterend" => $subletterend, "username" => $username);
      $weh_roomssublet[] = $oldroom;
  }
  
  $floorstvk = range(0, 15);
  $floors = range(0, 17);
  $suffixes = array("left", "right");



  if (isset($_POST["sublet_return"])) {
    $user_id = $_POST["sublet_return"];
    $sql = "SELECT name, oldroom, turm FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $name_subletter = $result[0]["name"];
    $room = $result[0]["oldroom"];
    $turm = $result[0]["turm"];

    
    $sql = "SELECT name FROM users WHERE room = ? AND turm = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "is", $room, $turm);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);

    echo "<div class='confirmation-form'>";
    echo "<form method='post'>";
    if (isset($result[0]["name"])) {
      $name_sublet = $result[0]["name"];
      echo "<p>Sicher, dass $name_subletter bereits zurück ist?<br><br>$name_sublet verliert mit sofortiger Wirkung seinen Zugang!</p>";
      echo "<input type='hidden' name='emptyroom' value='0'>";
    } else {
      echo "<p>Sicher, dass $name_subletter bereits zurück ist?<br><br>Aktuell gibt es keinen Bewohner für den Raum,<br>also ist der Untermieter wohl bereits ausgezogen.</p>";
      echo "<input type='hidden' name='emptyroom' value='1'>";
    }
    echo "<input type='hidden' name='reload' value='1'>";
    echo "<input type='submit' name='sublet' value='Bestätigen'>";
    echo "<input type='hidden' name='user_id' value='$user_id'>";
    echo "</form>";
    echo "<form method='post'>";
    echo "<input type='submit' name='sublet' value='Abbrechen'>";
    echo "</form>";
    echo "</div>";



  } elseif (isset($_POST["id"])) {
    
    if (isset($_POST["id_update"])) {

      // Eingegebene Postvariablen definieren
      $id = $_POST['id'];
      $room = isset($_POST['room']) ? $_POST['room'] : 0;
      $oldroom = isset($_POST['oldroom']) && $_POST['oldroom'] !== '' ? $_POST['oldroom'] : 0;
      $firstname = isset($_POST['firstname']) ? $_POST['firstname'] : '';
      $lastname = isset($_POST['lastname']) ? $_POST['lastname'] : '';
      $name = $firstname . " " . $lastname;      
      $telefon = isset($_POST['telefon']) ? $_POST['telefon'] : '';
      $email = isset($_POST['email']) ? $_POST['email'] : '';
      $geburtsort = isset($_POST['geburtsort']) ? $_POST['geburtsort'] : '';
      $historie = isset($_POST['historie']) ? $_POST['historie'] : '';
      $username = $_POST['username'];
      $pid = $_POST['pid'];
      $geburtstag = isset($_POST['geburtstag']) ? $_POST['geburtstag'] : '';
      $starttime = isset($_POST['starttime']) ? $_POST['starttime'] : '';
      $ausgezogen = isset($_POST['ausgezogen']) ? $_POST['ausgezogen'] : '';
      $endtime = isset($_POST['endtime']) ? $_POST['endtime'] : '';
      $subletterstart = isset($_POST['subletterstart']) ? $_POST['subletterstart'] : '';
      $subletterend = isset($_POST['subletterend']) ? $_POST['subletterend'] : '';
      $subtenanttill = isset($_POST['subtenanttill']) ? $_POST['subtenanttill'] : '';
      $subnet = isset($_POST['subnet']) ? $_POST['subnet'] : '';
      $mailisactive = isset($_POST['mailisactive']) ? $_POST['mailisactive'] : '';
      $honory = isset($_POST['honory']) ? $_POST['honory'] : '';
      $forwardemail = isset($_POST['forwardemail']) ? $_POST['forwardemail'] : '';
      $aliase = isset($_POST['aliase']) ? $_POST['aliase'] : '';
      $mailsettings = isset($_POST['mailsettings']) ? $_POST['mailsettings'] : '';
      $mailquota = isset($_POST['mailquota']) ? $_POST['mailquota'] : '';
      $insolvent = isset($_POST['insolvent']) ? $_POST['insolvent'] : '';
      $pwwifi = isset($_POST['pwwifi']) ? $_POST['pwwifi'] : '';
      $turm = $_POST['turm'];

      $pwhaus_changed = false;
      if(isset($_POST['pwhaus']) && strlen($_POST['pwhaus']) > 0) {
        $pwhaus = pwhash($_POST['pwhaus']);
        $pwhaus_changed = true;
      }

      $pwwifi_changed = false;
      if(isset($_POST['pwwifi']) && strlen($_POST['pwwifi']) > 0) {
        $pwwifi = $_POST['pwwifi'];
        $pwwifi_changed = true;
      }

      // Überprüfen ob Essentielles bereits belegt ist, erstmal alle Räume, Subnets und Usernames abrufen
      $sql = "SELECT subnet, username FROM users WHERE uid != ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $verify_subnet, $verify_username);
      $resultArray = array();
      while (mysqli_stmt_fetch($stmt)) {
          $resultArray[] = array(
              'allsubnets' => $verify_subnet,
              'allusernames' => $verify_username
          );
      }

      // Überprüfen ob Essentielles bereits belegt ist, erstmal alle Räume, Subnets und Usernames abrufen
      $sql = "SELECT room FROM users WHERE uid != ? AND turm = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "is", $id, $turm);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $verify_room);
      $resultArray = array();
      while (mysqli_stmt_fetch($stmt)) {
          $resultArray[] = array(
              'allrooms' => $verify_room
          );
      }

      // Alle möglichen Eingabefehler überprüfen
      $eingabefehler = false;
      $fehlermeldung = '';
      
      if (in_array($room, array_column($resultArray, 'allrooms')) && $room != 0) {
        $eingabefehler = true;

        $sql = "SELECT username FROM users WHERE room = ? AND turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "is", $room, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $username_from_room);
        mysqli_stmt_fetch($stmt);
        $fehlermeldung .= "Raum bereits von Benutzer '$username_from_room' belegt!<br>";
      }

      if (in_array($subnet, array_column($resultArray, 'allsubnets')) && $subnet != '') {
          $eingabefehler = true;
          $username_from_sublet = $resultArray[array_search($subnet, array_column($resultArray, 'allsubnets'))]['allusernames'];
          $fehlermeldung .= "Subnetz bereits von Benutzer '$username_from_sublet' belegt!<br>";
      } elseif ($subnet != '' && !preg_match('/^10\.(2\.(?:[0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.0|3\.(?:[0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.0|6\.(?:[0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.0)$/', $subnet)) {
          $eingabefehler = true;
          $fehlermeldung .= "Subnetz hat das falsche Format!<br>";
      }

      if (in_array($username, array_column($resultArray, 'allusernames'))) {
          $eingabefehler = true;
          $fehlermeldung .= "Username schon belegt!<br>";
      } 

      if ($geburtstag !== '0' && $geburtstag !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $geburtstag)) {
        $eingabefehler = true;
        $fehlermeldung .= "Ungültiges Datumsformat für Geburtsdatum!<br>";
      }

      if ($starttime !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $starttime)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Einzugsdatum!<br>";
      }

      if ($subletterstart !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $subletterstart)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Subletter Start!<br>";
      }

      if ($subletterend !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $subletterend)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Subletter End!<br>";
      }      
      if ($subtenanttill !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $subtenanttill)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Untermieter bis!<br>";
      }

      if ($ausgezogen !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $ausgezogen)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Auszugsdatum!<br>";
      }

      if ($endtime !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $endtime)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Austrittsdatum!<br>";
      }

      if ($insolvent !== '' && !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $insolvent)) {
          $eingabefehler = true;
          $fehlermeldung .= "Ungültiges Datumsformat für Insolvenzdatum!<br>";
      }

      if ($eingabefehler) {
          echo "<p style='color:red; text-align:center; font-size: 30px;'>$fehlermeldung</p>";
      } else {

        // Datumsvariablen in 0 (leerer String) oder UNIXTIME umformen
        $geburtstag = $_POST['geburtstag'] != '' ? strtotime($_POST['geburtstag']) : 0;
        $starttime = $_POST['starttime'] != '' ? strtotime($_POST['starttime']) : 0;
        $ausgezogen = $_POST['ausgezogen'] != '' ? strtotime($_POST['ausgezogen']) : 0;
        $endtime = $_POST['endtime'] != '' ? strtotime($_POST['endtime']) : 0;
        $subletterstart = $_POST['subletterstart'] != '' ? strtotime($_POST['subletterstart']) : 0;
        $subletterend = $_POST['subletterend'] != '' ? strtotime($_POST['subletterend']) : 0;
        $subtenanttill = $_POST['subtenanttill'] != '' ? strtotime($_POST['subtenanttill']) : 0; 
        $insolvent = $_POST['insolvent'] != '' ? strtotime($_POST['insolvent']) : 0; 

        // $sql = "UPDATE users SET room=?, name=?, firstname=?, lastname=?, starttime=?, endtime=?, telefon=?, email=?, geburtstag=?, geburtsort=?, historie=?, oldroom=?, username=?, ausgezogen=?, pid=?, subletterstart=?, subletterend=?, subtenanttill=?, subnet=?, mailisactive=?, forwardemail=?, aliase=?, mailsettings=?, mailquota=?, insolvent=? WHERE uid=?";
        // $stmt = mysqli_prepare($conn, $sql);
        // mysqli_stmt_bind_param($stmt, "ssssssssssssssissssiisiiii", $room, $name, $firstname, $lastname, $starttime, $endtime, $telefon, $email, $geburtstag, $geburtsort, $historie, $oldroom, $username, $ausgezogen, $pid, $subletterstart, $subletterend, $subtenanttill, $subnet, $mailisactive, $forwardemail, $aliase, $mailsettings, $mailquota, $insolvent, $id);
        // mysqli_stmt_execute($stmt);
        // $stmt->close();

        $sql = "UPDATE users SET room=?, name=?, firstname=?, lastname=?, starttime=?, endtime=?, telefon=?, email=?, geburtstag=?, geburtsort=?, historie=?, oldroom=?, username=?, ausgezogen=?, pid=?, subletterstart=?, subletterend=?, subtenanttill=?, subnet=?, mailisactive=?, forwardemail=?, aliase=?, mailsettings=?, mailquota=?, insolvent=?, honory=?, turm=?";
        $paramTypes = "ssssssssssssssissssiisiiiis";
        $params = [$room, $name, $firstname, $lastname, $starttime, $endtime, $telefon, $email, $geburtstag, $geburtsort, $historie, $oldroom, $username, $ausgezogen, $pid, $subletterstart, $subletterend, $subtenanttill, $subnet, $mailisactive, $forwardemail, $aliase, $mailsettings, $mailquota, $insolvent, $honory, $turm];
        if ($pwhaus_changed) {
            $sql .= ", pwhaus=?";
            $paramTypes .= "s";
            $params[] = $pwhaus;
        }
        if ($pwwifi_changed) {
          $sql .= ", pwwifi=?";
          $paramTypes .= "s";
          $params[] = $pwwifi;
        }
        $sql .= " WHERE uid=?";
        $paramTypes .= "i";
        $params[] = $id;
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
        mysqli_stmt_execute($stmt);
        $stmt->close();
      
        echo "<div style='text-align: center; font-size: 20px; color: green;'>Änderungen erfolgreich eingetragen.</div>";
      }
    }
    
    $sql = "SELECT room, name, firstname, lastname, starttime, endtime, telefon, email, geburtstag, geburtsort, historie, oldroom, username, ausgezogen, pid, subletterstart, subletterend, subtenanttill, subnet, mailisactive, forwardemail, aliase, mailsettings, mailquota, insolvent, honory, turm, lastradius FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $room, $name, $firstname, $lastname, $starttime, $endtime, $telefon, $email, $geburtstag, $geburtsort, $historie, $oldroom, $username, $ausgezogen, $pid, $subletterstart, $subletterend, $subtenanttill, $subnet, $mailisactive, $forwardemail, $aliase, $mailsettings, $mailquota, $insolvent, $honory, $turm, $lastradius);
    mysqli_stmt_fetch($stmt);
    $stmt->close();

    $pid_options = array(
      11 => "Bewohner",
      12 => "Untervermieter",
      13 => "Ausgezogen",
      14 => "Abgemeldet",
      64 => "Dummy"
    );

    // In DB werden diese Zeitangaben oft als 0 angegeben. Der leere String wird beim Update wieder zu einer 0 umgeformt
    $starttime = $starttime === 0 ? '' : date("d.m.Y", $starttime);
    $endtime = $endtime === 0 ? '' : date("d.m.Y", $endtime);
    $geburtstag = $geburtstag === 0 ? '' : date("d.m.Y", $geburtstag);
    $subletterstart = $subletterstart === 0 ? '' : date("d.m.Y", $subletterstart);
    $subletterend = $subletterend === 0 ? '' : date("d.m.Y", $subletterend);
    $subtenanttillUNIX = $subtenanttill;
    $subtenanttill = $subtenanttill === 0 ? '' : date("d.m.Y", $subtenanttill);
    $insolvent = $insolvent === 0 ? '' : date("d.m.Y", $insolvent);
    $ausgezogen = $ausgezogen === 0 ? '' : date("d.m.Y", $ausgezogen);

    // Relevant für Dropdownmenü bei honory
    if ($pid == 11 || $pid == 12) {
      $honory0string = "Ordentliches Mitglied";
    } elseif ($pid == 13) {
      $honory0string = "Außerordentliches Mitglied";
    } elseif ($pid == 14) {
      $honory0string = "Ehemaliges Mitglied";
    } elseif ($pid == 64) {
      $honory0string = "Dummy";
    } else {
      $honory0string = "Unbekannt";
    }

    echo "<hr>";

    echo '<h2 style="margin-bottom: 30px; font-size: 30px; text-align: center;">' . $name . '</h2>';

    echo "<div style='display: flex; flex-direction: column; align-items: center;'>";

    echo "<div style='display: flex; justify-content: center;'>";

    if ($_SESSION["NetzAG"]) {
      echo "<div style='margin: 0 10px;'>";
      echo "<form method='post' action='IPverwaltung.php' target='_blank'>";
      echo "<input type='hidden' name='uid' value='".$_POST["id"]."'>";
      echo "<input type='submit' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 25px;' value='IP-Verwaltung'>";
      echo "</form>";
      echo "</div>";
    }

    echo "<div style='margin: 0 10px;'>";
    echo "<form method='post' action='UserKonto.php' target='_blank'>";
    echo "<input type='hidden' name='uid' value='".$_POST["id"]."'>";
    echo "<input type='submit' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 25px;' value='Mitgliedskonto'>";  
    echo "</form>";
    echo "</div>";

    if ($_SESSION["NetzAG"]) {
      echo "<div style='margin: 0 10px;'>";
      echo "<form method='post' action='Troubleshoot.php' target='_blank'>";
      echo "<input type='hidden' name='uid' value='".$_POST["id"]."'>";
      echo "<input type='submit' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 25px;' value='Troubleshoot'>";
      echo "</form>";
      echo "</div>";

      echo "<div style='margin: 0 10px;'>";
      echo "<form method='post' action='WaschmarkenExchange.php' target='_blank'>";
      echo "<input type='hidden' name='uid' value='".$_POST["id"]."'>";
      echo "<input type='submit' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 25px;' value='Waschmarken'>";
      echo "</form>";
      echo "</div>";
    }

    echo "</div>";

    echo "<br><br>";

    $zeit = time();

// Zuerst: Holen Sie alle Sperren und speichern Sie sie in einem Array
$sql = "SELECT beschreibung, mail, internet, waschen, buchen, drucken, werkzeugbuchen, missedpayment
        FROM sperre 
        WHERE uid = ? AND sperre.starttime <= ? AND sperre.endtime >= ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iii", $_POST["id"], $zeit, $zeit);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $beschreibung, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen, $missedpayment);

$sperren = []; // Array für Sperrendaten
while (mysqli_stmt_fetch($stmt)) {
    $sperren[] = [
        'beschreibung' => $beschreibung,
        'mail' => $mail,
        'internet' => $internet,
        'waschen' => $waschen,
        'buchen' => $buchen,
        'drucken' => $drucken,
        'werkzeugbuchen' => $werkzeugbuchen,
        'missedpayment' => $missedpayment
    ];
}
mysqli_stmt_close($stmt);

// Zweitens: Holen Sie die Summe der Beträge für die UID
$sql = "SELECT SUM(betrag) FROM transfers WHERE uid = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $summe);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

if (is_null($summe)) {
    $summe = 0.00;
}
$neg_summe = (-1) * $summe;

// Ausgabe der Sperren
if (empty($sperren)) {
    echo "<div style='border: 2px solid rgb(0,200,0); border-radius: 10px; padding: 15px; margin-bottom: 20px; margin-top: 20px; font-size: 20px; background-color: transparent; text-align: center;'>
            <span style='font-weight: bold; font-size: 25px; color: rgb(0,200,0);'>Keine Sperre vorhanden</span>
          </div>";
} else {
    foreach ($sperren as $sperre) {
        // Array der betroffenen Dienste
        $services = [];
        if ($sperre['mail']) $services[] = "Mail";
        if ($sperre['internet']) $services[] = "Internet";
        if ($sperre['waschen']) $services[] = "Waschen";
        if ($sperre['buchen']) $services[] = "Buchen";
        if ($sperre['drucken']) $services[] = "Drucken";
        if ($sperre['werkzeugbuchen']) $services[] = "Werkzeugbuchen";

        // Dienste-Ausgabe
        $servicesOutput = implode(", ", $services);

        echo "<div style='border: 2px solid red; border-radius: 10px; padding: 15px; margin-bottom: 20px; margin-top: 20px; font-size: 20px; background-color: transparent; text-align: center;'>
                <span style='font-weight: bold; font-size: 25px; color: red;'>Sperre:</span><br>
                <span style='font-size: 20px; color: red;'>" . htmlspecialchars($sperre['beschreibung']) . "</span><br><br>
                <span style='font-weight: bold; font-size: 18px; color: red;'>Betroffene Dienste:</span><br>
                <span style='font-size: 18px; color: red;'>" . htmlspecialchars($servicesOutput) . "</span>";

        // Wenn "missedpayment" aktiv ist, zeige den Mail-Button
        if ($sperre['missedpayment'] == 1) {
            echo '<form method="POST" style="margin-top: 20px;">';
            echo '<input type="hidden" name="id" value="' . $_POST["id"] . '">';
            echo '<button type="submit" name="send_mail_banned" class="red-center-btn" id="sendBtn" 
                    style="transition: all 0.3s ease; font-size: 15px; padding: 10px 20px; border-radius: 5px; background-color: red; color: white; cursor: pointer;">';

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_mail_banned'])) {
                $address = $mailconfig['address'];
                $message = "Dear " . $firstname . ",\n\n" .
                           "This is a reminder mail.\nThis information has already been sent to your email address of choice.\n\n" .
                           "Your membership account balance is too low to extend your access to WEH services, so it was cancelled.\n\n" .
                           "To reactivate your internet connection, there are still " . $neg_summe . "€ missing.\n\n" .
                           "Name: WEH e.V.\n" .
                           "IBAN: DE90 3905 0000 1070 3346 00\n" .
                           "Transfer Reference: W" . $_POST["id"] . "H\n" .
                           "If you do not set this exact Transfer Reference, we will not be able to assign your payment to your account!\n\n" .
                           "When your member account has a positive balance, your internet connection will be reactivated automatically.\n\n" .
                           "It will take some time until the transfer will be entered for your account.\n\n" .
                           "Best Regards,\nNetzwerk-AG WEH e.V.";

                $to = $email;
                $subject = "WEH - Currently Banned";
                $headers = "From: " . $address . "\r\n";
                $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";

                if (mail($to, $subject, $message, $headers)) {
                    echo '<script>
                            const btn = document.getElementById("sendBtn");
                            btn.style.backgroundColor = "green";
                            btn.textContent = "Mail erfolgreich versendet.";
                            btn.disabled = true;
                          </script>';
                } else {
                    echo '<script>
                            const btn = document.getElementById("sendBtn");
                            btn.style.backgroundColor = "red";
                            btn.textContent = "Fehler beim Versenden der Mail.";
                            btn.disabled = true;
                          </script>';
                }
            } else {
                echo 'Reminder mit Transferinfos an hinterlegte Mail senden.';
            }

            echo '</button>';
            echo '</form>';
        }

        echo "</div>";
    }
}





    function calculateColor($diff_seconds) {
      $one_week = 60 * 60 * 24 * 7; // Eine Woche in Sekunden
      $one_month = 60 * 60 * 24 * 31; // Ein Monat in Sekunden
  
      if ($diff_seconds <= $one_week) {
          // Bis 1 Woche -> Grün
          $border_color = "rgb(0,200,0)";
      } elseif ($diff_seconds <= $one_month) {
          // Bis 1 Monat -> Grau
          $border_color = "gray";
      } else {
          // Älter als 1 Monat -> Rot
          $border_color = "red";
      }
  
      // Hintergrundfarbe transparent
      $background_color = "transparent";
  
      return array('borderColor' => $border_color, 'backgroundColor' => $background_color);
  }
  
  $zeit = time();
  $diff_seconds = $zeit - $lastradius;  // Zeitdifferenz in Sekunden
    
  $colors = calculateColor($diff_seconds);
    
  echo "<div style='border: 2px solid " . $colors['borderColor'] . "; border-radius: 10px; padding: 15px; margin-bottom: 20px; margin-top: 20px; font-size: 20px; background-color: " . $colors['backgroundColor'] . "; text-align: center;'>
            <span style='font-weight: bold; font-size: 25px; color: " . $colors['borderColor'] . ";'>Letzter Radius: ". date("d.m.Y H:i", $lastradius) ."</span>
          </div>";
  

    if ($pid == 12) {
      echo "<form method='post'>";
      echo "<input type='hidden' name='sublet_return' value='".$_POST["id"]."'>";
      echo "<input type='submit' name='sublet_return_from_id' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 30px; background-color: yellow;' value='Rückkehr von Untervermietung'>";        
      echo "</form>";
    }

    if ($subtenanttillUNIX != 0) {
      $sql = "SELECT uid FROM users WHERE pid = 12 AND oldroom = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $room);
      mysqli_stmt_execute($stmt);
      #mysqli_set_charset($conn, "utf8");
      mysqli_stmt_bind_result($stmt, $subletter_uid);
      mysqli_stmt_fetch($stmt);
      echo "<form method='post'>";
      echo "<input type='hidden' name='sublet_return' value='".$subletter_uid."'>";
      echo "<input type='submit' name='sublet_return_from_id' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 30px; background-color: yellow;' value='Rückkehr Untervermieter'>";        
      echo "</form>";
    }
    
    if ($_SESSION["NetzAG"]) {
      echo "<form method='post'>";
      echo "<input type='submit' name='id_update' class='center-btn' style='margin-bottom: 20px; margin-top: 20px; font-size: 40px;' value='Änderungen Speichern'>";
      echo "</div>";
    }

    echo "</div></div><hr>";
    echo "<table class='userpage-table'>";

    echo "<div style='display: flex; flex-direction: column; align-items: center;'>";

    echo "<div style='display: flex; justify-content: center;'>";



    $uploadDir = "anmeldung/" . $username . "/"; // Neuer Pfad für die Dateien des Benutzers

    // Datei-Pfade definieren
    $idFile = glob($uploadDir . $username . "_id.*")[0] ?? null;
    $mvFile = glob($uploadDir . $username . "_mv.*")[0] ?? null;
    $afFile = glob($uploadDir . $username . "_af.*")[0] ?? null;
    
    // Flexbox-Container
    echo "<div style='display: flex; flex-direction: column; align-items: center; gap: 20px;'>";
    
    // ID anzeigen (Bild oder PDF)
    if ($idFile) {
        $extension = pathinfo($idFile, PATHINFO_EXTENSION);
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            echo "<img src=\"$idFile\" alt=\"ID\" style='max-width: 600px; max-height: 400px; height: auto;'>";
        } elseif ($extension === 'pdf') {
          echo "<embed src=\"$idFile\" type=\"application/pdf\" style='width: 100%; height: 600px;'>";
        } else {
            echo "<p>Dateiformat für ID wird nicht unterstützt.</p>";
        }
    }
    
    // Button-Container für Mietvertrag und Anmeldeformular
    echo "<div style='display: flex; justify-content: center; gap: 20px;'>";
    
    if ($mvFile) {
        echo "<a href=\"$mvFile\" target=\"_blank\" class=\"center-btn\">Mietvertrag</a>";
    }
    
    if ($afFile) {
        echo "<a href=\"$afFile\" target=\"_blank\" class=\"center-btn\">Anmeldeformular</a>";
    }
    
    echo "</div>"; // Ende Button-Container
    echo "</div>"; // Ende Flexbox-Container
    
    
    
    

    
    
    $readonly = !$_SESSION["NetzAG"] ? "readonly" : "";
    $disabled = !$_SESSION["NetzAG"] ? "disabled" : "";
    $ausgegraut = !$_SESSION["NetzAG"] ? "grayed-out" : "";

    
    echo "<tr><th colspan='2'><br>Userdaten:</th></tr>";
    
    echo "<tr><td>Status</td><td><select name='pid' class='$ausgegraut' style='width: 455px; font-size: 25px;' $disabled>";
    foreach ($pid_options as $pid_nummer => $pid_wert) {
        $selected = ($pid_nummer == $pid) ? "selected" : "";
        echo "<option value='" . htmlentities($pid_nummer) . "' " . $selected . " style='font-size: 25px;'>" . htmlentities($pid_wert) . "</option>";
    }
    echo "</select></td></tr>";
    
    echo '<tr><td>User-ID</td><td><input type="text" name="id" class="' . $ausgegraut . '" style="width: 455px; height: 30px; font-size: 25px;" value="' . $_POST["id"] . '" readonly></td></tr>';
    if ($pid == 64 && $_SESSION["NetzAG"]) {
        echo '<tr><td>Username</td><td><input type="text" name="username" class="' . $ausgegraut . '" style="width: 455px; height: 30px; font-size: 25px;" value="' . $username . '"></td></tr>';
    } else {
        echo '<tr><td>Username</td><td><input type="text" name="username" class="' . $ausgegraut . '" style="width: 455px; height: 30px; font-size: 25px;" value="' . $username . '" readonly></td></tr>';
    }
    
    echo "<tr><td>Turm</td><td><select name='turm' class='$ausgegraut' style='width: 455px; font-size: 25px;' $disabled>";
    echo "<option value='weh' " . ($turm == 'weh' ? 'selected' : '') . ">WEH</option>";
    echo "<option value='tvk' " . ($turm == 'tvk' ? 'selected' : '') . ">TvK</option>";
    echo "</select></td></tr>";
    echo "<tr><td>Raum</td><td><input type='text' name='room' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$room."' $readonly></td></tr>";
    
    echo "<tr><td>Alter Raum</td><td><input type='text' name='oldroom' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$oldroom."' $readonly></td></tr>";
    echo "<tr><td>NAT-Subnetz</td><td>";
    echo "<input type='text' name='subnet' id='subnet-input' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$subnet."' $readonly>";
    echo "</td></tr>";      
    echo "<tr><td>Haus-Passwort</td><td><input type='password' name='pwhaus' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' $readonly></td></tr>";   
    echo "<tr><td>WiFi-Passwort</td><td><input type='password' name='pwwifi' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' $readonly></td></tr>";   
    echo "<tr><td>Mitgliedshistorie</td><td><textarea name='historie' class='$ausgegraut' style='width: 455px; height: 200px; font-size: 15px;' $readonly>".$historie."</textarea></td></tr>";
    echo "<tr><td>Mitgliedsstatus</td><td><select name='honory' class='$ausgegraut' style='width: 455px; height: 35px; font-size: 25px;' id='honorySelect' $disabled><option value='0' " . ($honory == 0 ? 'selected' : '') . ">$honory0string</option><option value='1' " . ($honory == 1 ? 'selected' : '') . ">Ehrenmitglied</option></select></td></tr>";
    
    echo "<tr><th colspan='2'><br>Personendaten:</th></tr>";
    echo "<tr><td>Vorname</td><td><input type='text' name='firstname' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$firstname."' $readonly></td></tr>";      
    echo "<tr><td>Nachname</td><td><input type='text' name='lastname' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$lastname."' $readonly></td></tr>";
    echo "<tr><td>Telefonnummer</td><td><input type='text' name='telefon' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$telefon."' $readonly></td></tr>";
    echo "<tr><td>Geburtsdatum</td><td><input type='text' name='geburtstag' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$geburtstag."' $readonly></td></tr>";
    echo "<tr><td>Herkunftsort</td><td><input type='text' name='geburtsort' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$geburtsort."' $readonly></td></tr>";
    
    echo "<tr><th colspan='2'><br>Maildaten:</th></tr>";      
    echo "<tr><td>Postfach</td><td><select name='mailisactive' class='$ausgegraut' style='width: 455px; height: 35px; font-size: 25px;' $disabled><option value='0' " . ($mailisactive == 0 ? 'selected' : '') . ">Deaktiviert</option><option value='1' " . ($mailisactive == 1 ? 'selected' : '') . ">Aktiv</option></select></td></tr>";
    echo "<tr><td>Private Mail</td><td><input type='text' name='email' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$email."' $readonly></td></tr>";
    echo "<tr><td>Empfänger</td><td><select name='forwardemail' class='$ausgegraut' style='width: 455px; height: 35px; font-size: 25px;' $disabled><option value='0' " . ($forwardemail == 0 ? 'selected' : '') . ">".$username."@weh.rwth-aachen.de</option><option value='1' " . ($forwardemail == 1 ? 'selected' : '') . ">".$email."</option></select></td></tr>";
    echo "<tr><td>Aliase</td><td><input type='text' name='aliase' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$aliase."' $readonly></td></tr>";
    echo "<tr><td>Rundmails</td><td><select name='mailsettings' class='$ausgegraut' style='width: 455px; height: 35px; font-size: 25px;' $disabled><option value='0' " . ($mailsettings == 0 ? 'selected' : '') . ">Alles</option><option value='1' " . ($mailsettings == 1 ? 'selected' : '') . ">Nur Wichtiges</option><option value='1' " . ($mailsettings == 2 ? 'selected' : '') . ">Nur Essentielles</option></select></td></tr>";
    echo "<tr><td>Quota</td><td><input type='number' name='mailquota' class='$ausgegraut' style='width: 400px; height: 30px; font-size: 25px;' value='".$mailquota."' $readonly><span> MB</span></td></tr>";
    
    echo "<tr><th colspan='2'><br>Datum:</th></tr>";
    echo "<tr><td>Einzugsdatum</td><td><input type='text' name='starttime' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$starttime."' $readonly></td></tr>";
    echo "<tr><td>Subletter Start</td><td><input type='text' name='subletterstart' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$subletterstart."' $readonly></td></tr>";
    echo "<tr><td>Subletter Ende</td><td><input type='text' name='subletterend' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$subletterend."' $readonly></td></tr>";
    echo "<tr><td>Untermieter bis</td><td><input type='text' name='subtenanttill' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$subtenanttill."' $readonly></td></tr>";
    echo "<tr><td>Auszugsdatum</td><td><input type='text' name='ausgezogen' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$ausgezogen."' $readonly></td></tr>";
    echo "<tr><td>Insolvent</td><td><input type='text' name='insolvent' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$insolvent."' readonly></td></tr>";
    echo "<tr><td>Austrittsdatum</td><td><input type='text' name='endtime' class='$ausgegraut' style='width: 455px; height: 30px; font-size: 25px;' value='".$endtime."' $readonly></td></tr>";
    
    echo "</form>";
    

    echo "</table>";


  } elseif (isset($_POST["sublet"])){

    if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
      if ($_POST["sublet"] == "Bestätigen") { 

        $subletter_uid = $_POST["user_id"];
        $emptyroom = $_POST["emptyroom"];
        $zeit = time();

        $sql = "SELECT subnet, oldroom, turm FROM users WHERE uid = ?";
        $result = executePreparedQuery($conn, $sql, "i", $subletter_uid);
        $subnet = $result['subnet'];
        $room = $result['oldroom'];
        $turm = $result['turm'];
        if ($subnet == "") {
            $sql = "SELECT subnet FROM users WHERE room = ? AND turm = ?";
            $result = executePreparedQuery($conn, $sql, "is", $room, $turm);
            $subnet = $result['subnet'];
        }
        
        echo "subnet1: " . $subnet;

        $date = date("d.m.Y");
        $stringie1 = utf8_decode("\n" . $date . " Back von Untervermietung (" . $_SESSION['username'] . ")");
        $stringie2 = utf8_decode("\n" . $date . " Ablauf Untermiete (" . $_SESSION['username'] . ")");

        if ($emptyroom == 0) { # Nur durchzuführen, wenn der Raum aktuell nicht leer ist -> also die "Abmeldung" des Sublets
          $sql = "SELECT uid FROM users WHERE room = ? AND turm = ? AND pid = 11 LIMIT 1";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "is", $room, $turm);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $sublet_uid);
          mysqli_stmt_fetch($stmt);
          mysqli_stmt_close($stmt);

          $sql = "UPDATE users SET room = 0, oldroom = ?, pid = 13, subtenanttill = ?, ausgezogen = ?, historie = CONCAT(historie, ?), subnet = '' WHERE uid = ?";
          $stmt = mysqli_prepare($conn, $sql);
          if ($stmt) {
              mysqli_stmt_bind_param($stmt, "iissi", $room, $zeit, $zeit, $stringie2, $sublet_uid);
              mysqli_stmt_execute($stmt);
              mysqli_stmt_close($stmt);
          } else {
              die('Fehler beim Vorbereiten des SQL-Statements: ' . mysqli_error($conn));
          }
          
        }

        $sql = "UPDATE users SET room = ?, oldroom = 0, pid = 11, subletterend = ?, historie = CONCAT(historie, ?), subnet = ? WHERE oldroom = ? AND pid = 12";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iissi", $room, $zeit, $stringie1, $subnet, $room);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $sql = "SELECT * FROM macauth WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $subletter_uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $row_count = mysqli_stmt_num_rows($stmt);
        if ($row_count == 0) {
          addPrivateIPs($conn, $subletter_uid, $subnet);
        }

        mysqli_stmt_close($stmt);
        $sql = "UPDATE macauth SET sublet = 0 WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $subletter_uid);
        mysqli_stmt_execute($stmt);
        $stmt->close(); 
      }
      echo "<span style='color: green; font-size: 20px;'>Erfolgreich durchgeführt.</span><br><br>";
      echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
      echo "<script>
        setTimeout(function() {
          document.forms['reload'].submit();
        }, 4000);
      </script>";
    }

    
    echo "<div class='sublet-table-container'>";
    echo "<table class='sublet-table'>";

    echo "<tr>
    <th>Raum</th>
    <th>UID</th>
    <th>Username</th>
    <th>Subletter</th>
    <th>Zeitraum</th>
    </tr>";
      foreach ($roomssublet as $room) {
        $users = $sublets_by_room[$room];
        foreach ($users as $user) {
          $user_id = $user["uid"];
          $subletterstart = $user["subletterstart"];
          $subletterend = $user["subletterend"];
          $user_name = $user["username"];
          $name_html = htmlspecialchars($user["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
          $ags = $user["groups"];
          $ags_icons = getAgsIcons($ags, 20);

          $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
          echo "<tr>";
          echo "<td style='color: #888888;'>$roomformatiert</td>";
          echo "<td>$user_id</td>";
          echo "<td>$user_name</td>";
          echo    "<td><a href='javascript:void(0);' onclick='
                  var form = document.createElement(\"form\");
                  form.setAttribute(\"method\", \"post\");
                  form.setAttribute(\"action\", \"\");
                  var hiddenField = document.createElement(\"input\");
                  hiddenField.setAttribute(\"type\", \"hidden\");
                  hiddenField.setAttribute(\"name\", \"id\");
                  hiddenField.setAttribute(\"value\", \"$user_id\");
                  form.appendChild(hiddenField);
                  document.body.appendChild(form);
                  form.submit();
                  ' class='white-text' style='user-select: text;'>$name_html $ags_icons</a></td>";
        
          if ($_SESSION["NetzAG"]) {               
            echo "<td><a href='javascript:void(0);' onclick='
            var form = document.createElement(\"form\");
            form.setAttribute(\"method\", \"post\");
            form.setAttribute(\"action\", \"\");
            var hiddenField = document.createElement(\"input\");
            hiddenField.setAttribute(\"type\", \"hidden\");
            hiddenField.setAttribute(\"name\", \"sublet_return\");
            hiddenField.setAttribute(\"value\", \"$user_id\");
            form.appendChild(hiddenField);
            document.body.appendChild(form);
            form.submit();
            ' class='white-text' style='user-select: text;'>".Date("d.m.Y", $subletterstart)." - ".Date("d.m.Y", $subletterend)."</a></td>";
          }
          else {
          echo "<td>".Date("d.m.Y", $subletterstart)." - ".Date("d.m.Y", $subletterend)."</td>";
          }
          
          echo "</tr>";
        }
    }
    echo "</table>";    
    echo "</div>";

  } elseif (isset($_POST["moved"])){

    $sqlmoved = "SELECT uid, name, groups, ausgezogen, username FROM users WHERE pid = 13 ORDER by ausgezogen";
    $stmt = mysqli_prepare($conn, $sqlmoved);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $uid, $name, $groups, $ausgezogen, $username);    
    $moved_by_uid = array();
    while (mysqli_stmt_fetch($stmt)){
        $moved_by_uid[$uid][] = array("uid" => $uid, "name" => $name, "groups" => $groups, "ausgezogen" => $ausgezogen, "username" => $username);
    }
    
    echo "<div class='sublet-table-container'>";
    echo "<table class='center-table'>";
    echo "<tr>
    <th>Auszug</th>
    <th>UID</th>
    <th>Username</th>
    <th>Name</th>
    </tr>";
    echo "<tr>";
    if (!empty($moved_by_uid)) {
        foreach ($moved_by_uid as $uid => $users) {
            foreach ($users as $user) {
                $user_id = $user["uid"];
                $user_name = $user["username"];
                $user_ausgezogen = $user["ausgezogen"];
                $name_html = htmlspecialchars($user["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                $ags = $user["groups"];
                $ags_icons = getAgsIcons($ags, 20);
    
                echo "<td>".Date("d.m.Y", $user_ausgezogen)."</td>";
                echo "<td>$user_id</td>";
                echo "<td>$user_name</td>";
                echo "<td><a href='javascript:void(0);' onclick='
                       var form = document.createElement(\"form\");
                       form.setAttribute(\"method\", \"post\");
                       form.setAttribute(\"action\", \"\");
                       var hiddenField = document.createElement(\"input\");
                       hiddenField.setAttribute(\"type\", \"hidden\");
                       hiddenField.setAttribute(\"name\", \"id\");
                       hiddenField.setAttribute(\"value\", \"$user_id\");
                       form.appendChild(hiddenField);
                       document.body.appendChild(form);
                       form.submit();
                       '  class='white-text' style='user-select: text;'>$name_html $ags_icons</a></td>";
                       
            }
            echo "</tr><tr>";
        }
    } else {
        echo "<td></td><td></td>";
    }
    echo "</tr>";
    echo "</table>";    
    echo "</div>";
    

  } elseif (isset($_POST["out"])){

    $sqlout = "SELECT uid, name, groups, username FROM users WHERE pid = 14 ORDER by uid";
    $stmt = mysqli_prepare($conn, $sqlout);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $uid, $name, $groups, $username);  
    $out_by_uid = array();
    while (mysqli_stmt_fetch($stmt)){
      $out_by_uid[$uid][] = array("uid" => $uid, "name" => $name, "groups" => $groups, "username" => $username);
    }

    
    echo "<div class='sublet-table-container'>";
    echo "<table class='center-table'>";
    echo "<tr>";
    if (!empty($out_by_uid)) {
        foreach ($out_by_uid as $uid => $users) {
            foreach ($users as $user) {
                $user_id = $user["uid"];
                $user_name = $user["username"];
                $name_html = htmlspecialchars($user["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                $ags = $user["groups"];
                $ags_icons = getAgsIcons($ags, 20);
    
                echo "<td>$user_id</td>";
                echo "<td>$user_name</td>";
                echo "<td><a href='javascript:void(0);' onclick='
                       var form = document.createElement(\"form\");
                       form.setAttribute(\"method\", \"post\");
                       form.setAttribute(\"action\", \"\");
                       var hiddenField = document.createElement(\"input\");
                       hiddenField.setAttribute(\"type\", \"hidden\");
                       hiddenField.setAttribute(\"name\", \"id\");
                       hiddenField.setAttribute(\"value\", \"$user_id\");
                       form.appendChild(hiddenField);
                       document.body.appendChild(form);
                       form.submit();
                       '  class='white-text' style='user-select: text;'>$name_html $ags_icons</a></td>";
                       
            }
            echo "</tr><tr>";
        }
    } else {
        echo "<td></td><td></td>";
    }
    echo "</tr>";
    echo "</table>";    
    echo "</div>";
  
  
    } elseif (isset($_POST["dummy"]) || isset($_POST["createNewDummy"])){
    
        if (isset($_POST["createNewDummy"])) {
            $starttime = time();
            $historie = date('d.m.Y')." Neuer Dummy angelegt von ".$_SESSION['name'];
            $username = "neuerdummy";
            $number = 1;
            $uniqueUsername = false;
            while (!$uniqueUsername) {
                $currentUsername = $username . $number;
                $sql = "SELECT * FROM users WHERE username = ?";
                $stmt = mysqli_prepare($conn, $sql);
                if (!$stmt) {
                    die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt, "s", $currentUsername);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $row_count = mysqli_stmt_num_rows($stmt);
                if ($row_count == 0) {
                    // Der Benutzername ist eindeutig
                    $username = $currentUsername;
                    $uniqueUsername = true;
                } else {
                    // Der Benutzername existiert bereits, erhöhe die Zahl
                    $number++;
                }
                mysqli_stmt_close($stmt);
            }
            $sql = "INSERT INTO users SET room=0, name='New Dummy', firstname='New', lastname='Dummy', starttime=?, historie=?, username=?, pid=64";
            $paramTypes = "iss";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) {
                die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, $paramTypes, $starttime, $historie, $username);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) <= 0) {
                die("Einfügen fehlgeschlagen: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);
        }

        echo '<form id="createUserForm" style="display:flex; justify-content:center;" method="post">';
        echo '<button type="submit" name="createNewDummy" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Neuen Dummy erstellen</button>';
        echo '</form><br><br>';

      $sqlmoved = "SELECT uid, name, username FROM users WHERE pid = 64 ORDER by uid";
      $stmt = mysqli_prepare($conn, $sqlmoved);
      mysqli_stmt_execute($stmt);
      #mysqli_set_charset($conn, "utf8");
      mysqli_stmt_bind_result($stmt, $uid, $name, $username);    
      $moved_by_uid = array();
      while (mysqli_stmt_fetch($stmt)){
          $moved_by_uid[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username);
      }
      
      echo "<div class='sublet-table-container'>";
      echo "<table class='center-table'>";
      echo "<tr>";
      if (!empty($moved_by_uid)) {
          foreach ($moved_by_uid as $uid => $users) {
              foreach ($users as $user) {
                  $user_id = $user["uid"];
                  $user_name = $user["username"];
                  $name_html = htmlspecialchars($user["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
      
                  echo "<td>$user_id</td>";
                  echo "<td>$user_name</td>";
                  echo "<td><a href='javascript:void(0);' onclick='
                         var form = document.createElement(\"form\");
                         form.setAttribute(\"method\", \"post\");
                         form.setAttribute(\"action\", \"\");
                         var hiddenField = document.createElement(\"input\");
                         hiddenField.setAttribute(\"type\", \"hidden\");
                         hiddenField.setAttribute(\"name\", \"id\");
                         hiddenField.setAttribute(\"value\", \"$user_id\");
                         form.appendChild(hiddenField);
                         document.body.appendChild(form);
                         form.submit();
                         '  class='white-text' style='user-select: text;'>$name_html</a></td>";
                         
              }
              echo "</tr><tr>";
          }
        }
    echo "</tr>";
    echo "</table>";    
    echo "</div>";

  } elseif (isset($_POST["ehre"])) {
    $sqlmoved = "SELECT uid, name, username FROM users WHERE honory = 1 ORDER by uid";
    $stmt = mysqli_prepare($conn, $sqlmoved);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $uid, $name, $username);    
    $moved_by_uid = array();
    while (mysqli_stmt_fetch($stmt)){
        $moved_by_uid[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username);
    }
    
    echo "<div class='sublet-table-container'>";
    echo "<table class='center-table'>";
    echo "<tr>";
    if (!empty($moved_by_uid)) {
        foreach ($moved_by_uid as $uid => $users) {
            foreach ($users as $user) {
                $user_id = $user["uid"];
                $user_name = $user["username"];
                $name_html = htmlspecialchars($user["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    
                echo "<td>$user_id</td>";
                echo "<td>$user_name</td>";
                echo "<td><a href='javascript:void(0);' onclick='
                       var form = document.createElement(\"form\");
                       form.setAttribute(\"method\", \"post\");

                       form.setAttribute(\"action\", \"\");
                       var hiddenField = document.createElement(\"input\");
                       hiddenField.setAttribute(\"type\", \"hidden\");
                       hiddenField.setAttribute(\"name\", \"id\");
                       hiddenField.setAttribute(\"value\", \"$user_id\");
                       form.appendChild(hiddenField);
                       document.body.appendChild(form);
                       form.submit();
                       '  class='white-text' style='user-select: text;'>$name_html</a></td>";
                       
            }
            echo "</tr><tr>";
        }
      }

    } elseif (isset($_POST["tvk"])){

      foreach ($floorstvk as $floor) {

        if ($floor == 0) {
            $roomsleft = array('1');
            $roomsright = array('2');
        } else {
            $roomsleft = array();
            for ($j = 1; $j <= 8; $j++) {
                $roomsleft[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
            }
            $roomsright = array();
            for ($j = 9; $j <= 16; $j++) {
                $roomsright[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
            }
        }

        foreach ($suffixes as $suffix) {

            echo "<div class='" . $suffix . "-table-container'>";
            echo "<table class='house-table " . $suffix . "-table'>";
            $last_floor = '';

            echo "<br><br>";
            foreach (${'rooms' . $suffix} as $room) {
                echo "<tr>";
                $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
                $wlan_icon = (in_array($room, $tvk_wlan_rooms)) ? "<img src='images/ap.png' width='20' height='20'>" : "";
                echo "<td style='color: #888888;'>$roomformatiert $wlan_icon</td>";


                if (array_key_exists($room, $users_by_room_tvk)) {
                    $users = $users_by_room_tvk[$room];

                    foreach ($users as $user) {
                        $user_id = $user["uid"];
                        $user_name = $user["username"];
                        $firstname = $user["firstname"];
                        $lastname = $user["lastname"];
                        $name_html = htmlspecialchars($firstname . ' ' . $lastname, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                        $ags = $user["groups"];
                        $ags_icons = getAgsIcons($ags, 20);



                      $sublet_icon = (in_array($room, $tvk_roomssublet)) ? "<img src='images/sublet.png' width='20' height='20'>" : "";
                      $ban_icon = (in_array($user["uid"], $banned_uids)) ? "<img src='images/ban.png' width='20' height='20'>" : "";

                      echo "<td>$user_id</td>";
                      echo "<td><a href='mailto:$user_name@tvk.rwth-aachen.de' style='color: white; text-decoration: none;' onmouseover=\"this.style.color='#11a50d';\" onmouseout=\"this.style.color='white';\">$user_name</a></td>";
                      echo    "<td><a href='javascript:void(0);' onclick='
                              var form = document.createElement(\"form\");
                              form.setAttribute(\"method\", \"post\");
                              form.setAttribute(\"action\", \"\");
                              var hiddenField = document.createElement(\"input\");
                              hiddenField.setAttribute(\"type\", \"hidden\");
                              hiddenField.setAttribute(\"name\", \"id\");
                              hiddenField.setAttribute(\"value\", \"$user_id\");
                              form.appendChild(hiddenField);
                              document.body.appendChild(form);
                              form.submit();
                              '  class='white-text' style='user-select: text;'>$name_html $ban_icon $sublet_icon $ags_icons</a></td>";
                  }
                } else {
                  echo "<td></td><td></td><td></td><td></td>";
                }
            }
            echo "</tr>";

            echo "</table>";    
            echo "</div>";
          }
        
      }
    } else { // House für alles andere

      foreach ($floors as $floor) {

        if ($floor == 0) {
            $roomsleft = array('1', '2');
            $roomsright = array('3', '4');
        } else {
            $roomsleft = array();
            for ($j = 1; $j <= 8; $j++) {
                $roomsleft[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
            }
            $roomsright = array();
            for ($j = 9; $j <= 16; $j++) {
                $roomsright[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
            }
        }

        foreach ($suffixes as $suffix) {

            echo "<div class='" . $suffix . "-table-container'>";
            echo "<table class='house-table " . $suffix . "-table'>";
            $last_floor = '';

            echo "<br><br>";
            foreach (${'rooms' . $suffix} as $room) {
                echo "<tr>";
                $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
                $wlan_icon = (in_array($room, $weh_wlan_rooms)) ? "<img src='images/ap.png' width='20' height='20'>" : "";
                echo "<td style='color: #888888;'>$roomformatiert $wlan_icon</td>";

                if (array_key_exists($room, $users_by_room_weh)) {
                    $users = $users_by_room_weh[$room];
                    foreach ($users as $user) {
                        $user_id = $user["uid"];
                        $user_name = $user["username"];
                        $firstname = $user["firstname"];
                        $lastname = $user["lastname"];
                        $name_html = htmlspecialchars($firstname . ' ' . $lastname, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                        $ags = $user["groups"];
                        $ags_icons = getAgsIcons($ags, 20);

                      $sublet_icon = (in_array($room, $weh_roomssublet)) ? "<img src='images/sublet.png' width='20' height='20'>" : "";
                      $ban_icon = (in_array($user["uid"], $banned_uids)) ? "<img src='images/ban.png' width='20' height='20'>" : "";

                      echo "<td>$user_id</td>";
                      echo "<td><a href='mailto:$user_name@weh.rwth-aachen.de' style='color: white; text-decoration: none;' onmouseover=\"this.style.color='#11a50d';\" onmouseout=\"this.style.color='white';\">$user_name</a></td>";
                      echo    "<td><a href='javascript:void(0);' onclick='
                              var form = document.createElement(\"form\");
                              form.setAttribute(\"method\", \"post\");
                              form.setAttribute(\"action\", \"\");
                              var hiddenField = document.createElement(\"input\");
                              hiddenField.setAttribute(\"type\", \"hidden\");
                              hiddenField.setAttribute(\"name\", \"id\");
                              hiddenField.setAttribute(\"value\", \"$user_id\");
                              form.appendChild(hiddenField);
                              document.body.appendChild(form);
                              form.submit();
                              '  class='white-text' style='user-select: text;'>$name_html $ban_icon $sublet_icon $ags_icons</a></td>";
                  }
                } else {
                  echo "<td></td><td></td><td></td><td></td>";
                }
            }
            echo "</tr>";

            echo "</table>";    
            echo "</div>";
          }
      }
    }
  

}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>

<script>
function setFreeSubnet() {
  var xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4 && xhr.status === 200) {
      document.getElementById('subnet-input').value = xhr.responseText;
    }
  };
  xhr.open('GET', 'template.php?action=getFreeSubnet', true);
  xhr.send();
}



</script>
</body>
</html>