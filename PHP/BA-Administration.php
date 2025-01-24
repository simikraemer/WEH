<?php
# Geschrieben von Fiji
# September 2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

session_start();
?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && ($_SESSION["WEH-BA"] || $_SESSION["Webmaster"])) {
  load_menu();



  if (!isset($_SESSION["AdminPanelToggleState"])) {
    $_SESSION["AdminPanelToggleState"] = "none"; // Standardmäßig eingeklappt
  }

  // Wenn ein Toggle-Request erfolgt, den Zustand der Session-Variable umschalten
  if (isset($_POST["toggleAdminPanel"])) {
      $_SESSION["AdminPanelToggleState"] = $_SESSION["AdminPanelToggleState"] === "none" ? "block" : "none";
  }

  if ($_SESSION["Webmaster"]) {
    echo '<div style="margin: 0 auto; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
    echo '<span class="white-text" style="font-size: 35px; cursor: pointer;" onclick="toggleAdminPanel()">Admin Panel</span>';
    echo '<div id="adminPanel" style="display: ' . $_SESSION["AdminPanelToggleState"] . ';">';  // Beginn des ausklappbaren Bereichs
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (isset($_POST['action'])) {
        if ($_POST['action'] === 'turmchoice_weh') {
            $_SESSION["ap_turm_var"] = 'weh';
        } elseif ($_POST['action'] === 'turmchoice_tvk') {
            $_SESSION["ap_turm_var"] = 'tvk';
        }
      }
    }

    $turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
    $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
    $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
    echo '<div style="display:flex; justify-content:center; align-items:center;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center; gap:0px;">';
    echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; width:200px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
    echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; width:200px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
    echo '</form>';
    echo '</div>';
    echo "<br><br>";
    
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

          document.body.appendChild(form);
          form.submit();
      }
  </script>';

    echo '<br><br><br><br>';
  } else {
    $turm = $_SESSION["turm"];
  }













  if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt

    if (isset($_POST["insert_neue_belegung"])) {
      $uid = $_SESSION['user'];
      $tstamp = time();
      $anzahl_kandidaten = $_POST["anzahl_kandidaten"];
      $endtime = $tstamp + ($_POST["duration"] * 24 * 60 * 60); 
      $endtime_string = date('d.m.Y H:i', $endtime);
      $etage = (int)$_POST["etage"];
      $zimmernummer = (int)$_POST["zimmernummer"];
      $room = ($etage * 100) + $zimmernummer; // Kombination von Etage und Zimmernummer als Integer
      $etage_str = str_pad(intval($room / 100), 2, '0', STR_PAD_LEFT);
      $turm = $turm;
      $beendet = 0;
  
      if (!isset($_FILES['pdf_upload']) || $_FILES['pdf_upload']['error'] != UPLOAD_ERR_OK) {
          echo "<div style='text-align: center;'>";
          echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
          echo "</div>";            
      } elseif (empty($anzahl_kandidaten)) {
        echo "<div style='text-align: center;'>";
        echo "<p style='color:red; text-align:center;'>Es wurde keine Anzahl an Kandidaten festgelegt!</p>";
        echo "</div>";
      } else {
          $file_path = null;
  
          if (!is_dir('bapolls')) {
              mkdir('bapolls', 0777, true);
          }
  
          $file_extension = strtolower(pathinfo($_FILES['pdf_upload']['name'], PATHINFO_EXTENSION));
          $file_name = $turm . "_" . $room . "_" . $tstamp . '.' . $file_extension; // Dateiname inklusive Extension
          $file_path = 'bapolls/' . $file_name;
  
          // Überprüfung auf erlaubte Dateitypen (Erweiterungen)
          $allowed_extensions = array('pdf');
          if (!in_array($file_extension, $allowed_extensions)) {
              echo "<div style='text-align: center;'>";
              echo "<p style='color:red; text-align:center;'>Nur PDF-Dateien sind erlaubt.</p>";
              echo "</div>";
              exit();
          }
  
          // Überprüfung auf erlaubte MIME-Typen
          $allowed_mime_types = array('application/pdf');
          $file_mime_type = $_FILES['pdf_upload']['type'];
          if (!in_array($file_mime_type, $allowed_mime_types)) {
              echo "<div style='text-align: center;'>";
              echo "<p style='color:red; text-align:center;'>Nur PDF-Dateien sind erlaubt.</p>";
              echo "</div>";
              exit();
          }
  
          if (!move_uploaded_file($_FILES['pdf_upload']['tmp_name'], $file_path)) {
              echo "<div style='text-align: center;'>";
              echo "<p style='color:red; text-align:center;'>Fehler beim Hochladen der Datei.</p>";
              echo "</div>";
              exit();
          }


          $message = "Hello,\n\n";
          $message .= "Room " . $room .  " on your floor is being reassigned.\nYou can view the list of applicants and vote for your favorites on this page:\n\n";
          $message .= "https://backend.weh.rwth-aachen.de/BA-Voting.php\n\n";
          $message .= "Please note that the page is only accessible from the RWTH network (e.g., tuermeroam, RWTH-eduroam or RWTH-VPN).\n\n";
          $message .= "The voting will be open until $endtime_string.\n\n";
          $message .= "Best regards,\n";
          $message .= "Belegungsausschuss & Netzwerk-AG";
          

          $to = "etage" . $etage_str . "@" . $turm . ".rwth-aachen.de";   
          #$to = "fiji@weh.rwth-aachen.de";
          $subject = "[BA] New Vote for Room " . $room;
          $headers = "From: " . $mailconfig['address'] . "\r\n";
          $headers .= "Reply-To: ba@".$turm.".rwth-aachen.de\r\n";

          if (!mail($to, $subject, $message, $headers)) {
              echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                      <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                    </div>";
          }
  
          // SQL-Insert-Befehl
          $insert_sql = "INSERT INTO bapolls (uid, tstamp, anzahl_kandidaten, endtime, room, turm, pfad, beendet) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = mysqli_prepare($conn, $insert_sql);
          mysqli_stmt_bind_param($stmt, "iiiisssi", $uid, $tstamp, $anzahl_kandidaten, $endtime, $room, $turm, $file_path, $beendet);
          mysqli_stmt_execute($stmt);
  
          if (mysqli_stmt_affected_rows($stmt) > 0) {
              echo "<div style='text-align: center;'>";
              echo "<p style='color:green; text-align:center;'>Eintrag erfolgreich hinzugefügt.</p>";
              echo "</div>";
          } else {
              echo "<div style='text-align: center;'>";
              echo "<p style='color:red; text-align:center;'>Fehler beim Einfügen in die Datenbank.</p>";
              echo "</div>";
          }
  
          mysqli_stmt_close($stmt);
      }
    }
    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
      setTimeout(function() {
        document.forms['reload'].submit();
      }, 4000);
    </script>";
  }















    echo '<div style="text-align: center;">';
    echo('<form method="post">');
    echo '<input type="submit" name="neue_belegung" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 30px;" value="Neue Belegung">';
    echo('</form>');
    echo '</div>';
    
    echo '<br><br><br><hr><br><br><br>';
    
    // Dropdown für Monat und Jahr mit GET-Methode
    echo '<div style="text-align: center;">';
    
    $datedropdownsize = "25px";

    // Aktuelles Datum
    $currentMonth = date('m');
    $currentYear = date('Y');

    // Ausgewählte Monat und Jahr festlegen
    $selectedMonth = isset($_GET['month']) ? $_GET['month'] : $currentMonth;
    $selectedYear = isset($_GET['year']) ? $_GET['year'] : $currentYear;

    echo '<form method="get">';
    echo '<select name="month" onchange="this.form.submit()" style="font-size: '.$datedropdownsize.'; padding: 5px;">';
    for ($m = 1; $m <= 12; $m++) {
        $selected = ($m == $selectedMonth) ? 'selected' : '';
        echo "<option value='$m' $selected>" . date('F', mktime(0, 0, 0, $m, 10)) . "</option>";
    }
    echo '</select>';
    echo '<select name="year" onchange="this.form.submit()" style="font-size: '.$datedropdownsize.'; padding: 5px; margin-left: 10px;">';
    for ($y = $currentYear - 5; $y <= $currentYear + 0; $y++) {
        $selected = ($y == $selectedYear) ? 'selected' : '';
        echo "<option value='$y' $selected>$y</option>";
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';

    // Abstand zwischen den Dropdowns und der Tabelle mit Inline-CSS
    echo '<div style="margin-top: 20px;"></div>';

    // Datenbankabfrage und Tabelle anzeigen
    if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($selectedMonth) && isset($selectedYear)) {
        $month = (int) $selectedMonth;
        $year = (int) $selectedYear;
    } else {
      $month = (int) $currentMonth;
      $year = (int) $currentYear;
    }
        
      $sql = "SELECT id, uid, tstamp, anzahl_kandidaten, endtime, room, turm, pfad, beendet 
              FROM bapolls 
              WHERE MONTH(FROM_UNIXTIME(tstamp)) = ? AND YEAR(FROM_UNIXTIME(tstamp)) = ? AND turm = ?
              ORDER BY beendet, room, tstamp";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iis", $month, $year, $turm);
      mysqli_stmt_execute($stmt);
      $result = mysqli_stmt_get_result($stmt);
      
      echo '<table class="grey-table" style="margin: 0 auto; text-align: center;">';
      echo "<tr>
              <th>Turm</th>
              <th>Raum</th>
              <th>Datum</th>
          </tr>";
      
      while ($row = mysqli_fetch_assoc($result)) {
          $date = date('d.m.Y', $row['tstamp']);
          $formatted_turm = ($row['turm'] == 'tvk') ? 'TvK' : strtoupper($row['turm']);
          $row_color = ($row['beendet'] == 1) ? '#333' : '#0b7309';
          $id = $row['id']; // Benötigt, um das Formular zu identifizieren
          
          echo "<tr style='cursor: pointer;' onclick='document.getElementById(\"form_$id\").submit();'>";
          
          echo "<td style='background-color: $row_color;'>$formatted_turm</td>";
          echo "<td style='background-color: $row_color;'>{$row['room']}</td>";
          echo "<td style='background-color: $row_color;'>$date</td>";
          
          echo "</tr>";
      
          // Das unsichtbare Formular direkt nach der Zeile einfügen
          echo "<form method='POST' style='display: none;' id='form_$id'>
              <input type='hidden' name='selected_belegung' value='{$row['id']}'>
              <input type='hidden' name='uid' value='{$row['uid']}'>
              <input type='hidden' name='tstamp' value='{$row['tstamp']}'>
              <input type='hidden' name='endtime' value='{$row['endtime']}'>
              <input type='hidden' name='anzahl_kandidaten' value='{$row['anzahl_kandidaten']}'>
              <input type='hidden' name='room' value='{$row['room']}'>
              <input type='hidden' name='turm' value='{$row['turm']}'>
              <input type='hidden' name='pfad' value='{$row['pfad']}'>
              <input type='hidden' name='beendet' value='{$row['beendet']}'>
          </form>";
      }
      echo "</table>";
      











    
    if (isset($_POST['selected_belegung'])) {
      $id = $_POST["selected_belegung"];
      $uid = $_POST["uid"];
      $anzahl_kandidaten = $_POST["anzahl_kandidaten"];
      $room = $_POST["room"];
      $formatted_turm = ( $_POST['turm'] == 'tvk') ? 'TvK' : strtoupper( $_POST['turm']);
      $pfad = $_POST["pfad"];
      $starttime = date('d.m.Y', $_POST["tstamp"]);
      $endtime = date('d.m.Y', $_POST["endtime"]);

      if ($_POST["beendet"] == 0) { # Voting läuft noch          
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
            </form>
            <div style="color: white; text-align: center; padding: 20px;">
                <h2>Übersicht</h2>
                <p>Turm: ' . htmlspecialchars($formatted_turm) . '</p>
                <p>Room: ' . htmlspecialchars($room) . '</p>
                <p>Anzahl Kandidaten: ' . htmlspecialchars($anzahl_kandidaten) . '</p>
                <p>Startzeit: ' . htmlspecialchars($starttime) . '</p>
                <p>Endzeit: ' . htmlspecialchars($endtime) . '</p>
    
                <form action="' . htmlspecialchars($pfad) . '" method="get" target="_blank">
                    <button type="submit" class="center-btn">Zum PDF</button>
                </form>
            </div>
        </div>');
      } else { // Übersicht Ergebnisse
        display_ba_results($conn, $id, $anzahl_kandidaten, $formatted_turm, $room, $starttime, $endtime, $pfad);
    }
  }







    if (isset($_POST['neue_belegung'])) {
      echo ('<div class="overlay"></div>
      <div class="anmeldung-form-container form-container">
      <form method="post" enctype="multipart/form-data">');
      
      // Schließen-Button
      echo '<button type="submit" name="close" value="close" class="close-btn">X</button>';
      
      // Etage Dropdown
      echo '<label class="form-label">Etage:</label>';
      echo '<select name="etage" class="form-input">';
      for ($i = 1; $i <= 17; $i++) {
          echo "<option value='$i'>Etage $i</option>";
      }
      echo '</select>';
      echo '<br>';
  
      // Zimmernummer Dropdown
      echo '<label class="form-label">Zimmernummer:</label>';
      echo '<select name="zimmernummer" class="form-input">';
      for ($i = 1; $i <= 16; $i++) {
          echo "<option value='$i'>Zimmer $i</option>";
      }
      echo '</select>';
      echo '<br>';
  
      // Anzahl Kandidaten
      echo '<label class="form-label">Anzahl Kandidaten:</label>';
      echo '<input type="number" name="anzahl_kandidaten" class="form-input" min="0" max="50">';
      echo '<br>';
  
      // Dauer des Votings Dropdown
      echo '<label class="form-label">Dauer des Votings:</label>';
      echo '<select name="duration" class="form-input">';
      for ($i = 1; $i <= 7; $i++) {
          $selected = ($i == 3) ? 'selected' : ''; // Standardoption 3 Tage
          if ($i > 1) {
            echo "<option value='$i' $selected>$i Tage</option>";
          } else {
            echo "<option value='$i' $selected>$i Tag</option>";
          }
      }
      echo '</select>';
      echo '<br>';
  
      // Uploadfeld für PDF
      echo '<label class="form-label">Upload PDF:</label>';
      echo '<input type="file" name="pdf_upload" class="form-input" accept=".pdf">';
      echo '<br>';
  
      // Submit Button
      echo '<div class="form-group">';
      echo '<input type="hidden" name="reload" value="1">';
      echo '<input type="hidden" name="insert_neue_belegung">';
      echo '<input type="submit" value="Submit" class="form-submit">';
      echo '</div>';
  
      echo '</form>
      </div>';
  }
  


  echo '<div style="height: 100px;"></div>';



    $conn->close();
} else {
    header("Location: denied.php");
}
?>
</body>
</html>
