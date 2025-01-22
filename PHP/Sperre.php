<?php
  session_start();
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
if (auth($conn) && ($_SESSION['NetzAG'] || $_SESSION['Vorstand'] || $_SESSION["TvK-Sprecher"])) {
  load_menu();
 
  function sperroptionen() {
    echo '<label for="starttime" class="sperre_form-label">Startzeit:</label>';
    echo '<input type="datetime-local" name="starttime" id="starttime" class="sperre_form-control">';
    echo '<button type="button" onclick="setLocalTime()">Aktuelle Zeit</button><br><br>';
    
    echo '<script>
    function setLocalTime() {
        var now = new Date();
        var timezoneOffset = now.getTimezoneOffset() * 60000; // Offset in milliseconds
        var localISOTime = new Date(Date.now() - timezoneOffset).toISOString().slice(0, 16);
        document.getElementById("starttime").value = localISOTime;
    }
    </script>';

    echo "<br>";

    echo '<label for="endtime" class="sperre_form-label">Endzeit:</label>';
    echo '<input type="datetime-local" name="endtime" id="endtime" class="sperre_form-control"><br><br>';

    if ($_SESSION['NetzAG']) {
      echo '<table class="sperre-table">';
      echo '<tr>';
      echo '<td><label for="mail" class="sperre_form-label">Rundmails:</label></td>';
      echo '<td><input type="checkbox" name="mail" id="mail" value="1" size="2"></td>';
      echo '</tr>';
    }

    if ($_SESSION['NetzAG']) {
      echo '<tr>';
      echo '<td><label for="internet" class="sperre_form-label">Internet:</label></td>';
      echo '<td><input type="checkbox" name="internet" id="internet" value="1" size="2"></td>';
      echo '</tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WEH-WaschAG']) {
      echo '<tr>';
      echo '<td><label for="waschen" class="sperre_form-label">Waschen:</label></td>';
      echo '<td><input type="checkbox" name="waschen" id="waschen" value="1" size="2"></td>';
      echo '</tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WohnzimmerAG'] || $_SESSION['SportAG']) {
      echo '<tr>';
      echo '<td><label for="buchen" class="sperre_form-label">Buchen:</label></td>';
      echo '<td><input type="checkbox" name="buchen" id="buchen" value="1" size="3"></td>';
      echo '</tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WerkzeugAG']) {
      echo '<tr>';
      echo '<td><label for="buchen" class="sperre_form-label">Werkzeug buchen:</label></td>';
      echo '<td><input type="checkbox" name="werkzeugbuchen" id="werkzeugbuchen" value="1" size="3"></td>';
      echo '</tr>';
    }
    
    
    if ($_SESSION['NetzAG']) {
      echo '<tr>';
      echo '<td><label for="drucken" class="sperre_form-label">Drucken:</label></td>';
      echo '<td><input type="checkbox" name="drucken" id="drucken" value="1" size="2"></td>';
      echo '</tr>';
    }
    
    echo '</table>';
  }

  if(isset($_POST['sperre_new'])){
    echo '<div class="sperre_container">';
    echo '<form method="POST" class="sperre_form">';

    // Dropdown-Menü zur Auswahl eines Benutzers erstellen
    echo '<label for="uid" class="sperre_form-label">Benutzer auswählen:</label>';
    echo '<select name="uid" id="uid"  class="sperre_form-select">
            <option value="" disabled selected>Wähle einen Benutzer</option>';

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

    echo "</select>";

    
    echo "<br><br><br>";

    SperrOptionen();
    
    echo "<br>";

    echo '<label for="beschreibung" class="sperre_form-label">Grund:</label>';
    echo '<input type="text" name="beschreibung" id="beschreibung" required class="sperre_form-control"><br><br>';
    
    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="sperre_exec" value="Sperre erstellen" class="center-btn">';
    echo '</div>';
    echo '</form>';
    echo '</div>';

  } 
  
  elseif(isset($_POST['sperre_etage'])){
    echo '<div class="sperre_container">';
    echo '<form method="POST" class="sperre_form">';

    echo '<label for="turm" class="sperre_form-label">Turm:</label>';
    echo '<select name="turm" id="turm" class="sperre_form-select">';
    echo '<option value="">Bitte wählen</option>';
    echo '<option value="weh">WEH</option>';
    echo '<option value="tvk">TvK</option>';
    echo '</select><br>';

    echo '<label for="etage" class="sperre_form-label">Etage:</label>';
    echo '<select name="etage" id="etage" class="sperre_form-select">';
    echo '<option value="">Bitte wählen</option>';
    for ($i = 0; $i <= 17; $i++) {
        echo '<option value="' . $i . '">' . $i . '</option>';
    }
    echo '</select><br>';

    echo "<br>";

    SperrOptionen();
    
    echo "<br>";

    echo '<label for="beschreibung" class="sperre_form-label">Grund:</label>';
    echo '<input type="text" name="beschreibung" id="beschreibung" required class="sperre_form-control"><br><br>';

    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="sperre_etage_exec" value="Sperren erstellen" class="center-btn">';
    echo '</div>';
    echo '</form>';
    echo '</div>';

  } 
  
  else {

    if(isset($_POST['sperre_exec'])){
      $zeit = time();
      $user_id = $_POST['uid'];
      $agent = $_SESSION['uid'];
      $starttime = strtotime($_POST['starttime']);
      $endtime = strtotime($_POST['endtime']);
      $mail = isset($_POST['mail']) ? $_POST['mail'] : 0;
      $internet = isset($_POST['internet']) ? $_POST['internet'] : 0;
      $waschen = isset($_POST['waschen']) ? $_POST['waschen'] : 0;
      $buchen = isset($_POST['buchen']) ? $_POST['buchen'] : 0;
      $werkzeugbuchen = isset($_POST['werkzeugbuchen']) ? $_POST['werkzeugbuchen'] : 0;
      $drucken = isset($_POST['drucken']) ? $_POST['drucken'] : 0;
      $beschreibung = isset($_POST['beschreibung']) ? $_POST['beschreibung'] : "";

      $date = date("d.m.Y");
      $stringie = utf8_decode("\n" . $date . " Sperre " . $beschreibung . " (" . $_SESSION['username'] . ")");
      $sql = "UPDATE users SET historie = CONCAT(historie, ?) WHERE uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "si", $stringie, $user_id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
  
      $insert_sql = "INSERT INTO sperre (uid, tstamp, starttime, endtime, agent, beschreibung, mail, internet, waschen, buchen, drucken, werkzeugbuchen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
      $insert_var = array($user_id, $zeit, $starttime, $endtime, $agent, $beschreibung, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen);
      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_bind_param($stmt, "iiiiisiiiiii", ...$insert_var);
      mysqli_stmt_execute($stmt);
      if(mysqli_error($conn)) {
          echo "MySQL Fehler: " . mysqli_error($conn);
      } else {
          echo "<p style='color:green; text-align:center;'>Sperre erfolgreich eingestellt.</p>";
      }
      mysqli_stmt_close($stmt);
    }      

    if(isset($_POST['sperre_etage_exec'])){
      $zeit = time();
      $turm = $_POST['turm'];
      $etage = $_POST['etage'];
      $agent = $_SESSION['uid'];
      $starttime = strtotime($_POST['starttime']);
      $endtime = strtotime($_POST['endtime']);
      $mail = isset($_POST['mail']) ? $_POST['mail'] : 0;
      $internet = isset($_POST['internet']) ? $_POST['internet'] : 0;
      $waschen = isset($_POST['waschen']) ? $_POST['waschen'] : 0;
      $buchen = isset($_POST['buchen']) ? $_POST['buchen'] : 0;
      $drucken = isset($_POST['drucken']) ? $_POST['drucken'] : 0;
      $werkzeugbuchen = isset($_POST['werkzeugbuchen']) ? $_POST['werkzeugbuchen'] : 0;
      $beschreibung = isset($_POST['beschreibung']) ? $_POST['beschreibung'] : "";
    
      // Erstelle ein Array mit allen Zimmernummern der Etage
      $zimmernummern = range(1, 16);
      $zimmer_array = array_map(function($zimmer) use ($etage) {
        return intval($etage . sprintf("%02d", $zimmer));
      }, $zimmernummern);
    
      // Select-Abfrage auf users für die angegebenen Zimmer
      $select_sql = "SELECT uid FROM users WHERE room=? AND turm=? AND pid in (11,12,13,14) ORDER BY room ASC";
      $stmt = mysqli_prepare($conn, $select_sql);

      $uids = array(); // Array für alle gefundenen UIDs

      foreach($zimmer_array as $zimmer) {
        mysqli_stmt_bind_param($stmt, "is", $zimmer, $turm);
        mysqli_stmt_bind_result($stmt, $uid);
        mysqli_stmt_execute($stmt);
        while (mysqli_stmt_fetch($stmt)) {
          // UID zum Array hinzufügen
          $uids[] = $uid;
        }
      }

      $success = true; // Variable, um zu überprüfen, ob das Einfügen für alle UIDs erfolgreich war

      foreach($uids as $uid) {
        // Einfügen von Sperren für jede UID
        $insert_sql = "INSERT INTO sperre (uid, tstamp, starttime, endtime, mail, internet, waschen, buchen, drucken, beschreibung, agent, werkzeugbuchen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $insert_var = array($uid, $zeit, $starttime, $endtime, $mail, $internet, $waschen, $buchen, $drucken, $beschreibung, $agent, $werkzeugbuchen);
        $stmt2 = mysqli_prepare($conn, $insert_sql);
    
        if (!$stmt2) {
            die('Error: ' . mysqli_error($conn)); // Ausgabe des Fehlers und Beenden des Skripts
        }
    
        mysqli_stmt_bind_param($stmt2, "iiiiiiiiisii", ...$insert_var);
        $execute_result = mysqli_stmt_execute($stmt2);
    
        if (!$execute_result) {
            die('Execution failed: ' . mysqli_stmt_error($stmt2)); // Ausgabe des Fehlers und Beenden des Skripts
        }
        
        mysqli_stmt_close($stmt2);
    }
      
      if ($success) { // Wenn der Erfolg für alle UIDs true ist, die Erfolgsmeldung ausgeben
          echo "<p style='color:green; text-align:center;'>Etagensperre erfolgreich eingestellt.</p>";
      } else { // Andernfalls den Fehler ausgeben
          echo "MySQL Fehler: " . mysqli_error($conn);
      }
      
    }
    
    if(isset($_POST['sperre_end'])){
      $zeit = time() - 10;
      $id = $_POST['sperre_end'];
      $sql = "UPDATE sperre SET endtime = ? WHERE id = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "ii", $zeit, $id);
      mysqli_stmt_execute($stmt);
      if(mysqli_error($conn)) {
          echo "MySQL Fehler: " . mysqli_error($conn);
      } else {
          echo "<p style='color:green; text-align:center;'>Sperre erfolgreich beendet.</p>";
      }
      mysqli_stmt_close($stmt);
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WerkzeugAG'] || $_SESSION['WohnzimmerAG'] || $_SESSION['SportAG'] || $_SESSION['WEH-WaschAG']){
      echo "<form method='post'>";
      echo "<div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'>";
      echo "<button type='submit' name='sperre_new' class='center-btn'>Neue Usersperre</button>";  
      echo "</div>";
      echo "</form>";
  
      echo "<form method='post'>";
      echo "<div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'>";
      echo "<button type='submit' name='sperre_etage' class='center-btn'>Neue Etagensperre</button>";  
      echo "</div>";
      echo "</form>";
      
      echo '<br><br><hr style="border-color: #fff;"><br><br>';
    }

    echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
    echo '<div style="text-align: center; font-size: 40px; color: white;">';
    echo 'Manuelle Sperren:';
    echo '</div>';
    echo '</div>';


    $zeit = time();
    $sql = "SELECT sperre.id, sperre.beschreibung, sperre.starttime, sperre.endtime, users.room, users.turm, users.name, users.uid, 
    sperre.mail, sperre.internet, sperre.waschen, sperre.buchen, sperre.drucken, sperre.werkzeugbuchen 
    FROM users 
    JOIN sperre ON users.uid = sperre.uid 
    WHERE sperre.endtime >= ? AND sperre.missedconsultation != 1 AND sperre.missedpayment != 1 
    ORDER BY FIELD(users.turm, 'weh', 'tvk'), users.room ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $zeit);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) > 0) {
    mysqli_stmt_bind_result($stmt, $id, $beschreibung, $starttime, $endtime, $room, $turm, $name, $uid, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen);
    
    echo '<table class="grey-table">
    <tr>
        <th>Name</th>
        <th>Turm</th>
        <th>Raum</th>
        <th>Grund</th>
        <th>Art</th>
        <th>Startzeit</th>
        <th>Endzeit</th>
        <th>Beenden</th>
    </tr>';
    
    while (mysqli_stmt_fetch($stmt)) {
      $turm4ausgabe = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
  
      // Form für die POST-Anfrage mit Benutzer-UID
      echo "<form method='POST' action='User.php' target='_blank' style='display: none;' id='form_{$uid}'>
              <input type='hidden' name='id' value='{$uid}'>
            </form>";
  
      // Zeile mit klickbarem Verhalten
      echo "<tr onclick='document.getElementById(\"form_{$uid}\").submit();' style='cursor: pointer;'>";    
      echo "<td>" . $name . "</td>";
      echo "<td>" . $turm4ausgabe . "</td>";
      echo "<td>" . $room . "</td>";
      echo "<td>" . $beschreibung . "</td>";
  
      // Verarbeitung der Art
      $types = [
        $mail == 1 ? "Rundmails" : null,
        $internet == 1 ? "Internet" : null,
        $waschen == 1 ? "Waschen" : null,
        $buchen == 1 ? "Buchen" : null,
        $drucken == 1 ? "Drucken" : null,
        $werkzeugbuchen == 1 ? "Werkzeugbuchen" : null
      ];
    
      // Null-Werte entfernen und als kommagetrennte Liste ausgeben
      echo "<td>" . implode(', ', array_filter($types)) . "</td>";

    
      // Formatieren von Startzeit und Endzeit
      $formattedStartTime = date('d.m.Y H:i', $starttime);
      $formattedEndTime = date('d.m.Y H:i', $endtime);
  
      echo "<td>" . $formattedStartTime . "</td>";
      echo "<td>" . ($endtime == 2147483647 ? "Unbegrenzt" : $formattedEndTime) . "</td>";
  
      // "End"-Button mit animiertem Hover-Effekt
      echo '<td>';
      echo '<form method="post" action="" style="margin: 0;" onClick="event.stopPropagation();">';
      echo '<button type="submit" name="sperre_end" value="' . $id . '" style="background: none; border: none; cursor: pointer; padding: 0;">';
      echo '<img src="images/trash_white.png" 
                class="animated-trash-icon" 
                style="width: 24px; height: 24px;">';
      echo '</button>';
      echo '</form>';
      echo '</td>';

    
      echo "</tr>";
  }
  
  echo '</table>';
  


    } else {    
      
      echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
      echo '<div style="text-align: center; font-size: 25px; color: white;">';
      echo 'Aktuell sind keine manuellen Sperren eingetragen.';
      echo '</div>';
      echo '</div>';

    }
    
    mysqli_stmt_close($stmt);

    echo '<br><br><hr style="border-color: #fff;"><br><br>';
    
    echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
    echo '<div style="text-align: center; font-size: 40px; color: white;">';
    echo 'Automatische Sperren:';
    echo '</div>';
    echo '<br>';
    echo '<div style="text-align: center; font-size: 25px; color: white;">';
    echo 'Sperren wegen fehlendem Guthaben oder Fernbleiben der Anmeldung.<br>
    Entsperrung erfolgt durch Besuch der Sprechstunde oder Auffüllen des Guthabens automatisch.';
    echo '</div>';
    echo '</div>';
    echo '<br>';

    $sql = "SELECT sperre.id, sperre.beschreibung, sperre.starttime, sperre.endtime, users.room, users.turm, users.name, users.uid,
    sperre.mail, sperre.internet, sperre.waschen, sperre.buchen, sperre.drucken, sperre.werkzeugbuchen, sperre.missedconsultation, sperre.missedpayment
    FROM users JOIN sperre 
    ON users.uid = sperre.uid
    WHERE sperre.endtime >= ? AND (sperre.missedconsultation = 1 OR sperre.missedpayment = 1)
    ORDER BY users.room ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $zeit);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $beschreibung, $starttime, $endtime, $room, $turm, $name, $uid, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen, $mc, $mp);

    echo '<table class="grey-table">
    <tr>
      <th>Name</th>
      <th>Turm</th>
      <th>Raum</th>
      <th>Grund</th>
      <th>Startzeit</th>
    </tr>';
  
    while (mysqli_stmt_fetch($stmt)) {
      $turm4ausgabe = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);    
      
      // Form für jede Zeile erstellen
      echo "<form method='POST' action='User.php' target='_blank' style='display: none;' id='form_{$uid}'>
              <input type='hidden' name='id' value='{$uid}'>
            </form>";
  
      // Zeile mit klickbarem Verhalten
      echo "<tr onclick='document.getElementById(\"form_{$uid}\").submit();' style='cursor: pointer;'>";
      echo "<td>" . $name . "</td>";
      echo "<td>" . $turm4ausgabe . "</td>";
      echo "<td>" . $room . "</td>";
      echo "<td>" . $beschreibung . "</td>";
    
      // Formatieren von Startzeit und Endzeit
      $formattedStartTime = date('d.m.Y H:i', $starttime);
    
      echo "<td>" . $formattedStartTime . "</td>";
    
      echo "</tr>";
    }
    
    mysqli_stmt_close($stmt);
    
    echo '</table>';
    
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