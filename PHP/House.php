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


  
    echo '<form method="post" action="House.php" style="display:flex; justify-content:center; flex-wrap:wrap; gap:10px;">';
    echo '<div style="display:flex; flex-basis:100%; justify-content:center;">'; // Erste Zeile

    $buttons = [
        'weh' => 'WEH',
        'tvk' => 'TvK',
        'sublet' => 'Subtenant',
        'subletter' => 'Subletter',
        'moved' => 'Ausgezogen',
        'out' => 'Abgemeldet',
        'ehre' => 'Ehrenmitglieder',
        'dummy' => 'Dummys'
    ];

    $activeButton = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_keys($_POST)[0] : 'weh';

    foreach ($buttons as $name => $label) {
        $bgColor = ($activeButton === $name) ? '#0d840a' : '#fff';
        $fontSize = in_array($name, ['weh', 'tvk']) ? '50px' : '20px';
        $width = in_array($name, ['weh', 'tvk']) ? '200px' : '160px';
        $padding = in_array($name, ['weh', 'tvk']) ? 'initial' : '10px 5px';

        if ($name === 'weh' || $name === 'tvk') {
            echo "<button type=\"submit\" name=\"$name\" class=\"house-button\" style=\"font-size:$fontSize; width:$width; background-color:$bgColor; color:#000; border:2px solid #000; transition:background-color 0.2s;\">$label</button>";
        }
    }
    echo '</div>';

    echo '<div style="display:flex; flex-basis:100%; justify-content:center;">'; // Zweite Zeile
    foreach ($buttons as $name => $label) {
        if ($name !== 'weh' && $name !== 'tvk') {
            $bgColor = ($activeButton === $name) ? '#0d840a' : '#fff';
            echo "<button type=\"submit\" name=\"$name\" class=\"house-button\" style=\"font-size:20px; width:160px; background-color:$bgColor; color:#000; border:2px solid #000; padding:10px 5px; transition:background-color 0.2s;\">$label</button>";
        }
    }
    echo '</div>';
    echo '</form>';

  


  if (!isset($_POST["sublet_return"])) {
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
    echo "                const mailtoLink = document.createElement('a');";
    echo "                mailtoLink.href = 'mailto:' + user.username + '@' + user.turm + '.rwth-aachen.de';";
    echo "                mailtoLink.textContent = user.username;";
    echo "                mailtoLink.style.color = 'white';";
    echo "                mailtoLink.style.textDecoration = 'none';";
    echo "                mailtoLink.onmouseover = function() { this.style.color = '#11a50d'; };";
    echo "                mailtoLink.onmouseout = function() { this.style.color = 'white'; };";
    echo "                cell2.appendChild(mailtoLink);";
                    
    
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
    echo "    form.action = 'User.php';"; // Leitet zu User.php weiter
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

  function generateRooms($floor, $zeroFloorRoomCount = 4) {
    if ($floor == 0) {
      $rooms = range(1, $zeroFloorRoomCount); // Räume von 1 bis zur angegebenen Anzahl
      $half = ceil(count($rooms) / 2); // Räume aufteilen in "left" und "right"
      return [
          'left' => array_slice($rooms, 0, $half),
          'right' => array_slice($rooms, $half)
      ];
    } else {
        $roomsleft = array();
        for ($j = 1; $j <= 8; $j++) {
            $roomsleft[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
        }
        $roomsright = array();
        for ($j = 9; $j <= 16; $j++) {
            $roomsright[] = $floor . str_pad($j, 2, "0", STR_PAD_LEFT);
        }
        return [
            'left' => $roomsleft,
            'right' => $roomsright
        ];
    }
  }

  function renderHouseTable($rooms, $wlanRooms, $usersByRoom, $subletRooms, $bannedUids) {
    $suffixes = array("left", "right");

    foreach ($suffixes as $suffix) {
        echo "<div class='" . $suffix . "-table-container'>";
        echo "<table class='house-table " . $suffix . "-table'>";
        echo "<br><br>";
        foreach ($rooms[$suffix] as $room) {
            echo "<tr>";
            $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
            $wlan_icon = (in_array($room, $wlanRooms)) ? "<img src='images/ap.png' width='20' height='20'>" : "";
            echo "<td style='color: #888888;'>$roomformatiert $wlan_icon</td>";

            if (array_key_exists($room, $usersByRoom)) {
                $users = $usersByRoom[$room];
                foreach ($users as $user) {
                    $user_id = $user["uid"];
                    $user_name = $user["username"];
                    $firstname = $user["firstname"];
                    $lastname = $user["lastname"];
                    $name_html = htmlspecialchars($firstname . ' ' . $lastname, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                    $email = $user_name . '@weh.rwth-aachen.de';
                    $mailto = "mailto:\"$name_html\" <$email>";
                    $ags = $user["groups"];
                    $ags_icons = getAgsIcons($ags, 20);

                    $sublet_icon = (in_array($room, $subletRooms)) ? "<img src='images/sublet.png' width='20' height='20'>" : "";
                    $ban_icon = (in_array($user["uid"], $bannedUids)) ? "<img src='images/ban.png' width='20' height='20'>" : "";

                    echo "<td>$user_id</td>";
                    echo "<td>
                          <a href='$mailto'>
                              <img src='images/mail_white.png'                    
                                  style='width: 20px; height: 20px;'
                                  onmouseover=\"this.src='images/mail_green.png';\" 
                                  onmouseout=\"this.src='images/mail_white.png';\">
                          </a>
                      </td>";
                    echo "<td>$user_name</td>";
                    echo "<td><a href='javascript:void(0);' onclick='
                    var form = document.createElement(\"form\");
                    form.setAttribute(\"method\", \"post\");
                    form.setAttribute(\"action\", \"User.php\"); // Leitet zu User.php weiter
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

  function renderCustomUserTable($conn, $query, $dateColumn, $isHonory = false) {
    if ($dateColumn == "") {
        $tstampANDroomRow = false;
    } else {
        $tstampANDroomRow = true;
    }
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_execute($stmt);

    // Ergebnisse binden (inkl. Raum)
    mysqli_stmt_bind_result($stmt, $uid, $room, $turm, $firstname, $lastname, $username, $timestamp);

    $users = [];
    while (mysqli_stmt_fetch($stmt)) {
        // Namen kürzen
        $shortFirstname = explode(' ', $firstname)[0];
        $shortLastname = explode(' ', $lastname)[0];
        $shortName = htmlspecialchars("$shortFirstname $shortLastname", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");

        // Datum oder Status für Honory
        if ($isHonory && $timestamp == 0) {
            $dateValue = "Aktiv";
        } elseif ($tstampANDroomRow) {
            $dateValue = date("d.m.Y", $timestamp);
        } else {
            $dateValue = null; // Kein Wert, wenn tstampRow false ist
        }

        // Room checken
        if ($room == 0) {
          $room = "-";
        }
        
        // Turm formatieren
        if (strtolower($turm) === "tvk") {
            $formattedTurm = "TvK";
        } else {
            $formattedTurm = strtoupper($turm);
        }

        $users[] = [
            'date' => $dateValue,
            'uid' => $uid,
            'room' => $room,
            'turm' => $formattedTurm,
            'name' => $shortName,
            'username' => $username
        ];
    }

    mysqli_stmt_close($stmt);

    // Tabelle erstellen
    echo "<div class='table-container' style='text-align: center;'>";
    echo "<table class='grey-table' style='margin: 0 auto; margin-bottom:60px;'>";
    echo "<tr>";
    if ($tstampANDroomRow) {
        echo "<th>$dateColumn</th>";
    }
    echo "<th>UID</th>";
    echo "<th>Name</th>";
    if ($tstampANDroomRow) {
        echo "<th>Raum</th>";
        echo "<th>Turm</th>";
    }
    #echo "<th>Username</th>";
    echo "</tr>";

    if (!empty($users)) {
        foreach ($users as $user) {
            // Verstecktes Formular für die UID
            echo "<form method='POST' action='User.php' style='display: none;' id='form_{$user['uid']}'>
                    <input type='hidden' name='id' value='{$user['uid']}'>
                  </form>";

            // Prüfen, ob $dateColumn "Sublet Ende" ist
            $cellStyle = "";
            if ($dateColumn == "Sublet Ende" && isset($user['date'])) {
                $currentDate = strtotime(date("Y-m-d")); // Aktuelles Datum als Timestamp
                $userDate = strtotime($user['date']);  // Datum des Users als Timestamp
    
                if ($userDate < $currentDate) {
                    $cellStyle = "background-color: red;";
                }
            }

            // Klickbare Zeile
            echo "<tr onclick='document.getElementById(\"form_{$user['uid']}\").submit();' style='cursor: pointer;'>";
            if ($tstampANDroomRow && isset($user['date'])) {
                echo "<td style='$cellStyle'>{$user['date']}</td>";
            }
            echo "<td style='$cellStyle'>{$user['uid']}</td>";
            echo "<td style='$cellStyle'>{$user['name']}</td>";
            if ($tstampANDroomRow) {
                echo "<td style='$cellStyle'>{$user['room']}</td>";
                echo "<td style='$cellStyle'>{$user['turm']}</td>";
            }
            #echo "<td style='$cellStyle'>{$user['username']}</td>";
            echo "</tr>";
        }
    } else {
        $colspan = $tstampANDroomRow ? 5 : 4; // Dynamische Spaltenanzahl
        echo "<tr><td colspan='$colspan'>No data available</td></tr>";
    }

    echo "</table>";
    echo "</div>";
  }


  function createNewDummyUser($conn, $currentUserName) {
    $starttime = time();
    $historie = date('d.m.Y') . " Neuer Dummy angelegt von " . $currentUserName;
    $username = "neuerdummy";
    $number = 1;
    $uniqueUsername = false;

    // Eindeutigen Benutzernamen generieren
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

    // Neuen Dummy-Benutzer einfügen
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
    $newDummyId = mysqli_insert_id($conn); // ID des neu eingefügten Datensatzes abrufen
    mysqli_stmt_close($stmt);
    
    return $newDummyId; // Neue Dummy-ID zurückgeben
  }


  function processSubletReturn($conn, $subletterUid, $emptyRoom, $currentUsername) {
    $zeit = time();

    // Subnet, oldroom und turm des Subletters abrufen
    $sql = "SELECT subnet, oldroom, turm FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $subletterUid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $subnet, $room, $turm);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ($subnet == "") {
        // Subnet von anderen Nutzern im gleichen Raum und Turm abrufen
        $sql = "SELECT subnet FROM users WHERE room = ? AND turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "is", $room, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $subnet);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    $date = date("d.m.Y");
    $stringie1 = utf8_decode("\n" . $date . " Back von Untervermietung (" . $currentUsername . ")");
    $stringie2 = utf8_decode("\n" . $date . " Ablauf Untermiete (" . $currentUsername . ")");

    // Abmeldung des Sublets, falls der Raum nicht leer ist
    if ($emptyRoom == 0) {
        $sql = "SELECT uid FROM users WHERE room = ? AND turm = ? AND pid = 11 LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "is", $room, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $subletUid);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $sql = "UPDATE users SET room = 0, oldroom = ?, pid = 13, subtenanttill = ?, ausgezogen = ?, historie = CONCAT(historie, ?), subnet = '' WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "iissi", $room, $zeit, $zeit, $stringie2, $subletUid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Sublet-Daten aktualisieren
    $sql = "UPDATE users SET room = ?, oldroom = 0, pid = 11, subletterend = ?, historie = CONCAT(historie, ?), subnet = ? WHERE oldroom = ? AND pid = 12";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iissi", $room, $zeit, $stringie1, $subnet, $room);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Prüfen, ob MAC-Adresse vorhanden ist
    $sql = "SELECT * FROM macauth WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $subletterUid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $rowCount = mysqli_stmt_num_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($rowCount == 0) {
        addPrivateIPs($conn, $subletterUid, $subnet);
    }

    // MAC-Adresse aktualisieren
    $sql = "UPDATE macauth SET sublet = 0 WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Vorbereitung fehlgeschlagen: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $subletterUid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return true;
  }


  if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    if ($_POST["processSubletReturn"] == "Bestätigen") {
        $subletterUid = $_POST["user_id"];
        $emptyRoom = $_POST["emptyroom"];
        $currentUsername = $_SESSION['username'];

        $success = processSubletReturn($conn, $subletterUid, $emptyRoom, $currentUsername);

        if ($success) {
          echo "<div style='text-align: center;'>
                  <span style='color: green; font-size: 20px;'>Erfolgreich durchgeführt.</span>
                </div><br><br>";
            echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
            echo "<script>
                setTimeout(function() {
                    document.forms['reload'].submit();
                }, 2000);
              </script>";
        }
    }
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
    echo "<input type='submit' name='processSubletReturn' value='Bestätigen'>";
    echo "<input type='hidden' name='user_id' value='$user_id'>";
    echo "</form>";
    echo "<form method='post'>";
    echo "<input type='submit' name='processSubletReturn' value='Abbrechen'>";
    echo "</form>";
    echo "</div>";

  } elseif (isset($_POST["subletter"])){ 

      $query = "SELECT uid, oldroom, turm, firstname, lastname, username, subletterend FROM users WHERE pid = 12 ORDER BY subletterend";
      renderCustomUserTable($conn, $query, "Sublet Ende");   

  } elseif (isset($_POST["sublet"])){ 

      $query = "SELECT uid, room, turm, firstname, lastname, username, subtenanttill FROM users WHERE subtenanttill != 0 && pid = 11 && room != 0 ORDER BY subtenanttill";
      renderCustomUserTable($conn, $query, "Sublet Ende");

  } elseif (isset($_POST["moved"])) {

      $query = "SELECT uid, oldroom, turm, firstname, lastname, username, ausgezogen FROM users WHERE pid = 13 ORDER BY ausgezogen DESC";
      renderCustomUserTable($conn, $query, "Auszug");   

  } elseif (isset($_POST["out"])){

    $query = "SELECT uid, oldroom, turm, firstname, lastname, username, endtime FROM users WHERE pid = 14 ORDER BY endtime DESC";
    renderCustomUserTable($conn, $query, "Ende");

  } elseif (isset($_POST["ehre"])) {

$query = "
SELECT 
    uid, 
    CASE WHEN pid = 11 THEN room ELSE oldroom END AS room, 
    turm, 
    firstname, 
    lastname, 
    username, 
    ausgezogen AS tstamp
FROM users
WHERE honory = 1
ORDER BY 
    CASE WHEN pid in (11,12) THEN 0 ELSE 1 END,
    CASE WHEN pid in (11,12) THEN starttime END ASC,
    CASE WHEN pid <> 11 THEN ausgezogen END DESC
";

    renderCustomUserTable($conn, $query, "Ausgezogen", true);  

  } elseif (isset($_POST["dummy"]) || isset($_POST["createNewDummy"])){

      if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
        if (isset($_POST["createNewDummy"])) {
          $newdummyid = createNewDummyUser($conn, $_SESSION['name']);
        } 
      
        if ($newdummyid) {
          echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
          echo "<form id='dummyForm' method='post' action='User.php' style='display: none;'>
                  <input type='hidden' name='id' value='{$newdummyid}'>
                </form>";
          echo "<script>
                  setTimeout(function() {
                      document.getElementById('dummyForm').submit();
                  }, 0); // Senden ohne Verzögerung
                </script>";
        }
      }

      echo '<form id="createUserForm" style="display:flex; justify-content:center;" method="post">';
      echo '<input type="hidden" name="reload" value="1">';
      echo '<button type="submit" name="createNewDummy" class="house-button" style="font-size:40px; margin-bottom:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s; cursor: pointer;">Neuen Dummy erstellen</button>';
      echo '</form><br><br>';
      

      $query = "SELECT uid, room, turm, firstname, lastname, username, starttime FROM users WHERE pid = 64 ORDER BY uid";
      renderCustomUserTable($conn, $query, "");  

  } elseif (isset($_POST["tvk"])){  

      $floors = range(0, 15);
      $zeroFloorRoomCount = 2;
      
      echo "<div style='margin-bottom: 60px; overflow: auto;'>";
      foreach ($floors as $floor) {
          $rooms = generateRooms($floor, $zeroFloorRoomCount);
          renderHouseTable($rooms, $tvk_wlan_rooms, $users_by_room_tvk, $tvk_roomssublet, $banned_uids);
      }
      echo "</div>";

  } else { // House für alles andere

      $floors = range(0, 17);
      $zeroFloorRoomCount = 4;
      
      echo "<div style='margin-bottom: 60px; overflow: auto;'>";
      foreach ($floors as $floor) {
          $rooms = generateRooms($floor, $zeroFloorRoomCount);
          renderHouseTable($rooms, $weh_wlan_rooms, $users_by_room_weh, $weh_roomssublet, $banned_uids);
      }
      echo "</div>";

  }
  

}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>
</body>
</html>