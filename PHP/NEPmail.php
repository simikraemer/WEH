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
if (auth($conn) && ($_SESSION["PartyAG"] || $_SESSION["TvK-Sprecher"] || $_SESSION["Webmaster"])) {
  load_menu();

  $debug = false;

  function fetchEmailData($conn, $debug, $turmpost, $cutofftime) {
    if ($debug) {
        $sql = "SELECT name, turm, room, CONCAT('z', LPAD(users.room, 4, '0'), '@', users.turm, '.rwth-aachen.de') AS email 
                FROM users WHERE uid in (2136,2617) ORDER BY turm, room";
        $stmt = mysqli_prepare($conn, $sql);
    } elseif ($turmpost === 'both') {
        $sql = "SELECT name, turm, room, CONCAT('z', LPAD(users.room, 4, '0'), '@', users.turm, '.rwth-aachen.de') AS email 
                FROM users WHERE starttime > ? AND pid = 11 ORDER BY turm, room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $cutofftime); // Nur cutofftime wird gebunden
    } else {
        $sql = "SELECT name, turm, room, CONCAT('z', LPAD(users.room, 4, '0'), '@', users.turm, '.rwth-aachen.de') AS email 
                FROM users WHERE turm = ? AND starttime > ? AND pid = 11 ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $turmpost, $cutofftime); // turmpost und cutofftime werden gebunden
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $turm, $room, $email);
    $names = array();
    $turms = array();
    $rooms = array();
    $emails = array();

    while (mysqli_stmt_fetch($stmt)) {
        $names[] = $name;
        $turms[] = $turm;
        $rooms[] = $room;
        $emails[] = $email;
    }

    return array(
        'names' => $names,
        'turms' => $turms,
        'rooms' => $rooms,
        'emails' => $emails
    );
  }

    if ($_SESSION["TvK-Sprecher"]) {
        $address = "sprecher@tvk.rwth-aachen.de";
    } else {
        $address = "party@weh.rwth-aachen.de";
    }

    if (isset($_POST["sendmail"])) {
        $cutofftime = strtotime($_POST["cutofftime"]); 
        $message = $_POST["nachricht"];
        $turmpost = $_POST["turmpost"];

        $data = fetchEmailData($conn, $debug, $turmpost, $cutofftime);

        // Jetzt kannst du die Arrays aus $data verwenden
        $names = $data['names'];
        $turms = $data['turms'];
        $rooms = $data['rooms'];
        $emails = $data['emails'];
        
        $to = $address;
        $subject = "WEH Neueinzieherprojekt";
        $headers = "From: " . $address . "\r\n";
        $headers .= "BCC: " . implode(',', $emails) . "\r\n";
    

        if (mail($to, $subject, $message, $headers)) {
            echo "<div style='display: flex; justify-content: center; align-items: center;'>
                    <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                  </div>";
        } else {
            echo "<div style='display: flex; justify-content: center; align-items: center;'>
                    <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                  </div>";
        }
        
    }

    echo '<div style="display: flex; flex-direction: column; align-items: center; text-align: center;">';
    echo "<div style='color: white; font-size: 30px;'>Dieser Text wird an alle User gesendet, die nach dem Cutoff-Datum eingezogen sind.</div>";

    
  
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (isset($_POST['action'])) {
          if ($_POST['action'] === 'turmchoice_weh') {
              $_SESSION["ap_turm_var_with_both"] = 'weh';
          } elseif ($_POST['action'] === 'turmchoice_tvk') {
              $_SESSION["ap_turm_var_with_both"] = 'tvk';
          } elseif ($_POST['action'] === 'turmchoice_both') {
              $_SESSION["ap_turm_var_with_both"] = 'both';
          }
      }
  }
  
  $turm = isset($_SESSION["ap_turm_var_with_both"]) ? $_SESSION["ap_turm_var_with_both"] : 'both';
  $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
  $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
  $both_button_style = ($turm === 'both') ? 
      'background-image:linear-gradient(90deg, #18ec13, #18ec13, #18ec13, #18ec13, #18ec13, black, #FFA500,  #FFA500,  #FFA500, #FFA500, #FFA500); animation: rainbow 3s infinite; background-size: 400% 400%;' 
      : 'background-color:#fff;';
  
  echo '<div style="display:flex; justify-content:center; align-items:center; margin-top: 30px;">';
  echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
  echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:30px; margin-right:10px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
  echo '<button type="submit" name="action" value="turmchoice_both" class="house-button" style="font-size:50px; margin-right:10px; ' . $both_button_style . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Türme</button>';
  echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:30px; margin-right:10px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
  echo '</form>';
  echo '</div>';
  
  // CSS für die Regenbogen-Animation
  echo '<style>
  @keyframes rainbow {
      0% {background-position: 0% 50%;}
      50% {background-position: 99% 50%;}
      100% {background-position: 0% 50%;}
  }
  </style>';
  

    echo "<br><br>";
    
    echo '<form method="post">';    
    echo '<br><br><label for="cutofftime" style="color: white; font-size: 30px;">Cutoff-Datum:</label><br>';
    echo '<input type="date" name="cutofftime" id="cutofftime" style="font-size: 25px;"><br><br>';
    echo '<textarea name="nachricht" rows="30" cols="100"></textarea><br><br>';
    echo "<div style='display: flex; justify-content: center;'>";
    echo "<input type='hidden' name='turmpost' value='" . $turm . "'>";
    echo "<button type='submit' name='preview' class='center-btn'>Preview</button>";  
    echo "</div>";
    echo "</form>";
    
    echo '</div>';

    if (isset($_POST["preview"])) {
      $cutofftime = strtotime($_POST["cutofftime"]);
      $message = $_POST["nachricht"];
      $turmpost = $_POST["turmpost"];
      
        if ($_SESSION["TvK-Sprecher"]) {
            $address = "sprecher@tvk.rwth-aachen.de";
        } else {
            $address = "party@weh.rwth-aachen.de";
        }
  
      // Start des Formulars und der Overlay-Box
      echo ('<div class="overlay"></div>
          <div class="anmeldung-form-container form-container">
          <form method="post">
              <button type="submit" name="close" value="close" class="close-btn">X</button>
          </form>
          <br>');
  
      echo '<div style="display: flex; flex-direction: column; align-items: center; text-align: center;">';
      echo "<div style='color: white; font-size: 25px; margin-bottom: 10px;'>
        <strong>Absenderadresse:</strong> {$address}<br>
      </div>";
      echo "<div style='color: white; font-size: 30px;'>Folgender Text wird an folgende User gesendet:</div>";
  
      // Zentrierte Ausgabe in weißem Text von $message mit Zeilenumbrüchen in einem abgerundeten Feld
      echo "<div style='background-color: darkblue; color: white; font-size: 20px; margin-top: 20px; margin-bottom: 30px; padding: 20px; border-radius: 15px; border: 2px solid white; text-align: center;'>". nl2br(htmlspecialchars($message)) ."</div>";


      $data = fetchEmailData($conn, $debug, $turmpost, $cutofftime);

      // Jetzt kannst du die Arrays aus $data verwenden
      $names = $data['names'];
      $turms = $data['turms'];
      $rooms = $data['rooms'];
      $emails = $data['emails'];
      
      // Tabelle mit Empfängern anzeigen
      echo '<table class="center-table">';
      echo '<tr><th>Nummer</th><th>Name</th><th>Turm</th><th>Raum</th></tr>';
      
      // Iteriere durch die Arrays, um die Tabelle zu befüllen
      for ($i = 0; $i < count($names); $i++) {
          $turm4ausgabe = ($turms[$i] == 'tvk') ? 'TvK' : strtoupper($turms[$i]);
          echo "<tr><td>" . ($i + 1) . "</td><td>{$names[$i]}</td><td>{$turm4ausgabe}</td><td>{$rooms[$i]}</td></tr>";
      }
      
      echo '</table>';
      
  
      echo '<form method="post">';
  
      // Hidden Post-Values mitgeben
      echo "<input type='hidden' name='cutofftime' value='" . htmlspecialchars($_POST["cutofftime"]) . "'>";
      echo "<input type='hidden' name='nachricht' value='" . htmlspecialchars($_POST["nachricht"]) . "'>";
      echo "<input type='hidden' name='turmpost' value='" . $turmpost . "'>";
  
      echo "<div style='display: flex; justify-content: center; margin-top: 20px;'>";
      echo "<button type='submit' name='sendmail' class='center-btn'>Mail senden</button>";
      echo "</div>";
      echo "</form>";
  
      echo '</div>';
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