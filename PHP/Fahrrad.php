<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji  -->
<!-- Für den WEH e.V. -->
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION['valid'])) {
  load_menu();
  

  $zeit = time();
  if ($_SESSION['turm'] == 'weh') {
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
      if (isset($_POST["addqueue"])) {
        $uid = $_SESSION["uid"];
        $status = 1;

        $check_sql = "SELECT COUNT(*) FROM fahrrad WHERE uid = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $uid);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_bind_result($check_stmt, $count);
        mysqli_stmt_fetch($check_stmt);
        mysqli_stmt_close($check_stmt);

        if ($count == 0) {
          $insert_sql = "INSERT INTO fahrrad (uid, starttime, status) VALUES (?,?,?)";
          $insert_var = array($uid,$zeit,$status);
          $stmt = mysqli_prepare($conn, $insert_sql);
          mysqli_stmt_bind_param($stmt, "iii", ...$insert_var);
          mysqli_stmt_execute($stmt);
          echo '<div style="text-align: center;">
          <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
          </div>';
        }
        
        echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
        echo "<script>
          setTimeout(function() {
            document.forms['reload'].submit();
          }, 1000);
        </script>";
      }
    }

    $uid = $_SESSION["uid"];
    $sql = "SELECT starttime, platz, endtime FROM fahrrad WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
      die("Error: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $uid);
    if (!mysqli_stmt_execute($stmt)) {
      die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_store_result($stmt); // Speichern der Ergebnisse im Speicher
    
    mysqli_stmt_bind_result($stmt, $tstamp, $stellplatz, $endtime);
    if (mysqli_stmt_fetch($stmt)) {
      if ($endtime) {
        echo '<h2 class="center">Es wurde eine Endzeit für deinen Account gesetzt, daher hast du nicht mehr die Option auf einen Stellplatz oder die Warteliste.<br></span></h2>';
        echo '<h2 class = "center">If this sounds weird to you, please contact Fahrrad-AG.</span></h2>';
      }
      elseif ($stellplatz == 0) {
        $zeitform = date('d.m.Y', $tstamp);

        $sql = "SELECT users.uid, users.firstname, users.lastname, users.room,
                users.oldroom, fahrrad.starttime, fahrrad.platz, fahrrad.platztime, users.username, users.groups
                FROM users 
                INNER JOIN fahrrad ON users.uid = fahrrad.uid 
                WHERE (fahrrad.endtime IS NULL OR fahrrad.endtime > ?)
                ORDER BY 
                    CASE
                        WHEN users.groups NOT LIKE '1' AND users.groups NOT LIKE '1,19' THEN 0
                        ELSE 1
                    END,
                    fahrrad.platz";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            die("Error: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "s", $zeit);
        if (!mysqli_stmt_execute($stmt)) {
            die("Error: " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_store_result($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $firstname, $lastname, $room, $oldroom, $tstamp, $bike_storageid, $platztime, $username, $groups);
        
        $users_queue = array();
        $position = 0;
        
        while (mysqli_stmt_fetch($stmt)) {
            $users_queue[$bike_storageid][] = array(
                "uid" => $uid,
                "firstname" => $firstname,
                "lastname" => $lastname,
                "room" => $room,
                "oldroom" => $oldroom,
                "tstamp" => $tstamp,
                "platztime" => $platztime,
                "username" => $username,
                "groups" => $groups
            );
          
            if ($bike_storageid < 1) {
                $position++;
                // Überprüfe, ob die aktuelle Benutzer-ID mit der Session-Benutzer-ID übereinstimmt
                if ($uid == $_SESSION["uid"]) {
                    break; // Beende die Schleife, da die Position gefunden wurde
                }
            }
        }
        
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);
      
        echo '<h2 class="center">Du bist seit dem <span style="color: #11a50d;">' . $zeitform . '</span> auf der Warteliste für einen Fahrradstellplatz.
        <br> Auf der Warteliste belegst du derzeit den <span style="color: #11a50d;">' . ($position) . '. Platz</span>.</h2>';
        echo '<p style="color: white; text-align: center; font-size: 20px;">Die durchschnittliche Wartezeit liegt zwischen 6 bis 12 Monaten.</p>';
        echo '<h2 class="center">Sobald du einen Stellplatz zugewiesen bekommst, wirst du per E-Mail darüber informiert.</h2>';    
      }
      elseif ($stellplatz !== 0) {
        echo '<h2 class="center">Herzlichen Glückwunsch!
        <br>Dir wurde der Stellplatz <span style="color: #11a50d;">Nummer ' . $stellplatz . '</span> zugewiesen.</h2>';    
        echo '<p style="color: white; text-align: center; font-size: 20px;">Die Lage der Stellplätze wird im Keller ausgeschildert.</p>';
      }
    }
    else {
      echo '<h2 class="center">Hier kannst du dich auf die Warteliste für einen Fahrradstellplatz im Keller eintragen!</h2>';
      echo '<p style="color: white; text-align: center; font-size: 20px;">Die durchschnittliche Wartezeit liegt zwischen 6 bis 12 Monaten.</p>';
      echo '<form action="Fahrrad.php" method="post" name="addqueue">';
      echo '<div style="display: flex; justify-content: center; margin-top: 1%">';
      echo '<input type="hidden" name="reload" value=1>';
      echo '<button type="submit" name="addqueue" class="center-btn">Setz mich auf die Liste!</button>';
      echo '</div>';
      echo "</form>";
    } 
  } elseif ($_SESSION['turm'] == 'tvk') {
    echo '<h2 class="center">Stellplätze für das TvK werden bald digitalisiert!</h2>';
    echo '<p style="color: white; text-align: center; font-size: 20px;">IN BEARBEITUNG!</p>';
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