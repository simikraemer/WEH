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
  $turm = $_SESSION['turm'];
  
  if ($_SESSION["FahrradAG"] || $_SESSION["TvK-Sprecher"] || (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    $agentuid = $_SESSION["uid"];
    echo "<br><br>";

    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
      if (isset($_POST["stellplatz"])) {
          $stellplatz = $_POST["stellplatz"];
        
          $sql = "SELECT users.name, users.room 
                  FROM users 
                  INNER JOIN fahrrad ON users.uid = fahrrad.uid 
                  WHERE fahrrad.platz = ? AND fahrrad.turm = ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "is", $stellplatz, $turm);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $name, $room);
          mysqli_stmt_fetch($stmt);
          mysqli_stmt_close($stmt);

          $sql = "SELECT users.name, users.room, users.uid
                  FROM users 
                  INNER JOIN fahrrad ON users.uid = fahrrad.uid 
                  WHERE fahrrad.platz = 0 AND (fahrrad.endtime IS NULL OR fahrrad.endtime > ?) AND fahrrad.turm = ?
                  ORDER BY 
                      CASE
                        WHEN users.groups NOT LIKE '1' AND users.groups NOT LIKE '1,19' THEN 0
                        ELSE 1
                      END,
                      fahrrad.starttime ASC
                  LIMIT 1";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "is", $zeit, $turm);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $name2, $room2, $uid2);
          mysqli_stmt_fetch($stmt);
          mysqli_stmt_close($stmt);
        
          echo "<div class='confirmation-form'>";
          echo "<p>Soll $name ($room) als eingetragener Bewohner für Stellplatz $stellplatz entfernt werden?
          <br><br>Bewohner $name2 ($room2) rückt nach und wird automatisch über seinen/ihren neuen Stellplatz $stellplatz informiert.</p>";
          echo "<form method='post'>";
          echo "<input type='hidden' name='stellplatz2' value='$stellplatz'>";
          echo "<input type='hidden' name='uid2' value='$uid2'>";
          echo "<br>";
          echo "<input type='submit' name='stellplatz_action' value='Bestätigen' style='margin-right: 10px;'>";
          echo "<input type='submit' name='cancel' value='Abbrechen'>";
          echo '<input type="hidden" name="reload" value=1>';
          echo "</form>";
          echo "</div>";
      } 

      if (isset($_POST["stellplatz_action"])) {
        $stellplatz = $_POST["stellplatz2"];
        $uid2 = $_POST["uid2"];

        $sql = "UPDATE fahrrad SET endtime = ?, status = 2, platz = 0, endagent = ? 
                WHERE platz = ? AND turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiis", $zeit, $agentuid, $stellplatz, $turm);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        $sql = "UPDATE fahrrad SET platz = ?, platztime = ?, status = 3 
                WHERE uid = ? AND turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiis", $stellplatz, $zeit, $uid2, $turm);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        echo '<div style="text-align: center;"><span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br></div>';
        echo "<script>setTimeout(() => document.forms['reload'].submit(), 2000);</script>";
      }
    
      if (isset($_POST["queue_uid"])) {
        $queue_uid = $_POST["queue_uid"];

        $sql = "SELECT users.name, users.room 
                FROM users 
                INNER JOIN fahrrad ON users.uid = fahrrad.uid 
                WHERE fahrrad.uid = ? AND fahrrad.turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "is", $queue_uid, $turm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $name, $room);
        mysqli_stmt_fetch($stmt);
        $stmt->close();

      
        echo "<div class='confirmation-form'>";
        echo "<p>Soll $name ($room) aus der Warteschlange entfernt werden?</p>";
        echo "<form method='post'>";
        echo "<input type='hidden' name='queue_uid2' value='$queue_uid'>";
        echo '<input type="hidden" name="reload" value=1>';
        echo "<br>";
        echo "<input type='submit' name='queue_action' value='Bestätigen' style='margin-right: 10px;'>";
        echo "<input type='submit' name='cancel' value='Abbrechen'>";
        echo "</form>";
        echo "</div>";
      } 

      if (isset($_POST["queue_action"])) {
        $queue_uid = $_POST["queue_uid2"];

        $sql = "UPDATE fahrrad SET endtime = ?, status = 4, endagent = ? 
                WHERE uid = ? AND turm = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiis", $zeit, $agentuid, $queue_uid, $turm);
        mysqli_stmt_execute($stmt);
        $stmt->close();

        echo '<div style="text-align: center;"><span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br></div>';
        echo "<script>setTimeout(() => document.forms['reload'].submit(), 2000);</script>";
      }
    }


    $sql = "SELECT users.uid, users.firstname, users.lastname, users.room,
               users.oldroom, fahrrad.starttime, fahrrad.platz, fahrrad.platztime,
               users.username, users.groups
        FROM users 
        INNER JOIN fahrrad ON users.uid = fahrrad.uid 
        WHERE (fahrrad.endtime IS NULL OR fahrrad.endtime > ?) AND fahrrad.turm = ?
        ORDER BY fahrrad.platz";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
      die("Error: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "is", $zeit, $turm);
    if (!mysqli_stmt_execute($stmt)) {
      die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_store_result($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $firstname, $lastname, $room, $oldroom, $tstamp, $bike_storageid, $platztime, $username, $groups);

    $users_by_stellplatz = array();
    while (mysqli_stmt_fetch($stmt)){
      $users_by_stellplatz[$bike_storageid][] = array(
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
    }
    mysqli_stmt_free_result($stmt);
    mysqli_stmt_close($stmt);

    $users_queue = array();
    $users_stellplatz = array();

    foreach ($users_by_stellplatz as $bike_storageid => $users) {
      if ($bike_storageid < 1) {
        usort($users, function ($a, $b) {
          $groupPriority = function ($group) {
            $priority = ["1" => 1, "1,19" => 1];
            return isset($priority[$group]) ? $priority[$group] : 0;
          };
          $priorityA = $groupPriority($a["groups"]);
          $priorityB = $groupPriority($b["groups"]);

          if ($priorityA != $priorityB) {
            return $priorityA - $priorityB;
          }
          return $a["tstamp"] - $b["tstamp"];
        });
        $users_queue[$bike_storageid] = $users;
      } else {
        $users_stellplatz[$bike_storageid] = $users;
      }
    }
  
  
    
    #ksort($users_stellplatz);

    echo "<div class='bike-container'>";

    // Stellplätze
    echo "<div class='leftbike-container'>";
    echo '<h1 class="center">Stellplätze</h1>';
    echo "<table class='leftbike'>";
    echo '<tr><th>Stellplatz</th><th>Einteilung</th><th>Raum</th><th>Name</th><th>Mail</th><th>Kick</th></tr>';

    foreach ($users_stellplatz as $stellplatz => $users) {
      foreach ($users as $user) {
        $username = $user['username'];
        $firstname = strtok($user['firstname'], ' ');
        $lastname = strtok($user['lastname'], ' ');
        $platztime = $user['platztime'];
        $name = $firstname . ' ' . $lastname;
        $user_platztimeform = date('d.m.Y', $platztime);

        echo '<tr>';
        echo "<td style='color: white;'>$stellplatz</td>";
        echo "<td style='color: white;'>$user_platztimeform</td>";
        echo "<td style='color: white;'>" . ($user['room'] === 0 ? $user['oldroom'] . " <img src='images/sublet.png' width='20' height='20'>" : $user['room']) . "</td>";
        echo "<td style='color: white;'>$name</td>";
        echo "<td style='text-align: center;'>
                <a href='mailto:\"$name\" <$username@weh.rwth-aachen.de>'>
                  <img src='images/mail_white.png' alt='Contact Icon' style='width: 24px; height: 24px;'
                      onmouseover=\"this.src='images/mail_green.png';\" 
                      onmouseout=\"this.src='images/mail_white.png';\">
                </a>
              </td>";
        echo '<td style="text-align: center;">
                <form method="post" style="margin: 0;">
                  <input type="hidden" name="stellplatz" value="'.$stellplatz.'">
                  <input type="hidden" name="reload" value="1">
                  <button type="submit" style="background: none; border: none; cursor: pointer;">
                    <img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">
                  </button>
                </form>
              </td>';
        echo '</tr>';
      }
    }
    echo "</table></div>";


    // Warteschlange
    echo "<div class='rightbike-container'>";
    echo '<h1 class="center">Warteschlange</h1>';
    echo "<table class='rightbike'>";
    echo '<tr><th>Position</th><th>Anmeldung</th><th>Raum</th><th>Name</th><th>Mail</th><th>Kick</th></tr>';

    $position = 1;
    foreach ($users_queue as $stellplatz => $users) {
      foreach ($users as $user) {
        $uid = $user['uid'];
        $username = $user['username'];
        $firstname = strtok($user['firstname'], ' ');
        $lastname = strtok($user['lastname'], ' ');
        $name = $firstname . ' ' . $lastname;
        $user_zeitform = date('d.m.Y', $user['tstamp']);

        echo '<tr>';
        echo "<td style='color: white;'>$position</td>";
        echo "<td style='color: white;'>$user_zeitform</td>";
        echo "<td style='color: white;'>" . ($user['room'] === 0 ? $user['oldroom'] . " <img src='images/sublet.png' width='20' height='20'>" : $user['room']) . "</td>";
        echo "<td style='color: white;'>$name</td>";
        echo "<td style='text-align: center;'>
                <a href='mailto:\"$name\" <$username@weh.rwth-aachen.de>'>
                  <img src='images/mail_white.png' alt='Contact Icon' style='width: 24px; height: 24px;'
                      onmouseover=\"this.src='images/mail_green.png';\" 
                      onmouseout=\"this.src='images/mail_white.png';\">
                </a>
              </td>";
        echo '<td style="text-align: center;">
                <form method="post" style="margin: 0;">
                  <input type="hidden" name="reload" value="1">
                  <input type="hidden" name="queue_uid" value="'.$uid.'">
                  <button type="submit" style="background: none; border: none; cursor: pointer;">
                    <img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">
                  </button>
                </form>
              </td>';
        echo '</tr>';
        $position++;
      }
    }
    echo "</table></div></div>";
    
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