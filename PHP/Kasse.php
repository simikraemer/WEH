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


# Offline genommen von Fiji am 01.05.2025 - Von nun an alles via Transfers.php
if (auth($conn) && (($_SESSION["Webmaster"]))) {
  #if (auth($conn) && ($_SESSION['kasse'])) {  
  load_menu();

#///// START IN WORK /////
#echo '<!DOCTYPE html>';
#echo '<html lang="en">';
#echo '<head>';
#echo '<meta charset="UTF-8">';
#echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
#echo '<style>';
#echo 'body { background-color: #880000; color: white; font-family: Arial, sans-serif; }';
#echo '.center-table { margin: 50px auto; border-collapse: collapse; text-align: left; width: 80%; }';
#echo '.center-table th, .center-table td { border: 1px solid white; padding: 10px; }';
#echo '.center-table th { background-color: #444; }';
#echo '.title { font-size: 2rem; margin-bottom: 10px; text-align: center; }';
#echo '.subtitle { font-size: 1.2rem; margin-bottom: 20px; text-align: center; }';
#echo '</style>';
#echo '<title>POST Variablen</title>';
#echo '</head>';
#echo '<body>';
#echo '<div class="title">Diese Seite ist aktuell in Bearbeitung</div>';
#echo '<div class="subtitle">POST-Daten werden unten angezeigt</div>';
#if (!empty($_POST)) {
#    echo '<table class="center-table">';
#    echo '<tr>';
#    echo '<th>Key</th>';
#    echo '<th>Value</th>';
#    echo '</tr>';
#    foreach ($_POST as $key => $value) {
#        echo '<tr>';
#        echo '<td>' . htmlspecialchars($key) . '</td>';
#        echo '<td>' . htmlspecialchars($value) . '</td>';
#        echo '</tr>';
#    }
#    echo '</table>';
#} else {
#    echo '<p style="text-align: center;">Es wurden keine POST-Daten übermittelt.</p>';
#}
#echo '<br><br><hr><br><br>';
#echo '</body>';
#echo '</html>';
#///// ENDE IN WORK /////


  if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    if(isset($_POST['submit'])){ 
      $check_kasse = $_POST['kasse'];
      $check_text = $_POST['text'];

      $betrag = $_POST['betrag'];
      $betrag = str_replace(',', '.', $betrag);

      $beschreibung = !empty($_POST['beschreibung']) ? $_POST['beschreibung'] : 'Barzahlung';
      $beschreibung = mb_convert_encoding($beschreibung, 'UTF-8', mb_detect_encoding($beschreibung));

      if (!isset($_POST['uid'])) {
        $user_id = $_SESSION["uid"];
      } else {
        $user_id = $_POST['uid'];
      }

      $zeit = time();

      $file_path = null;
      if (!is_dir('uploads')) {
        mkdir('uploads', 0777, true);
      }
      if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        $unixtime = time(); // aktuelle Unixzeit
        $file_extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $file_name = $unixtime . '.' . $file_extension;
        $file_path = 'uploads/' . $file_name;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $file_path)) {
            echo "<p style='color:red; text-align:center;'>Fehler beim Hochladen der Datei.</p>";
            exit();
        }
      }

      // Werte für transfers vorbereiten
      if ($check_kasse > 3) {
        $transfers_kasse = 0;
      } else {
        $transfers_kasse = $check_kasse;
      }
      if ($betrag >= 0) {
        $konto = 4;
      } else {
        $konto = 8;
      }
      
      $agent = $_SESSION["uid"];
      $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
      $changelog .= "Insert durch Kasse.php [Mode4]\n";
      
      // Schritt 1: INSERT in transfers
      $insert_transfers_sql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, kasse, konto, agent, changelog) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
      $insert_transfers_var = array($zeit, $user_id, $beschreibung, $betrag, $transfers_kasse, $konto, $agent, $changelog);
      
      $stmt = mysqli_prepare($conn, $insert_transfers_sql);
      mysqli_stmt_bind_param($stmt, "iisdiiis", ...$insert_transfers_var);
      mysqli_stmt_execute($stmt);
      
      // Überprüfen, ob der INSERT erfolgreich war
      if (mysqli_error($conn)) {
        echo "<p style='color:red; text-align:center;'>MySQL Fehler: " . mysqli_error($conn) . "</p>";
        exit();
      } else {
        echo "<p style='color:green; text-align:center;'>Erfolgreich in weh.transfers hinzugefügt.</p>";
      }
      mysqli_stmt_close($stmt);

      // Schritt 2: Abrufen der transfer_id mit SELECT
      $select_transfer_sql = "SELECT id FROM transfers WHERE tstamp = ? AND uid = ? ORDER BY id DESC LIMIT 1";
      $stmt = mysqli_prepare($conn, $select_transfer_sql);
      mysqli_stmt_bind_param($stmt, "ii", $zeit, $user_id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $transfer_id);
      mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      
      // Schritt 3: INSERT in barkasse mit der ermittelten transfer_id
      $insert_barkasse_sql = "INSERT INTO barkasse (tstamp, uid, beschreibung, betrag, pfad, kasse, transfer_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
      $insert_barkasse_var = array($zeit, $user_id, $beschreibung, $betrag, $file_path, $check_kasse, $transfer_id);
      
      $stmt = mysqli_prepare($conn, $insert_barkasse_sql);
      mysqli_stmt_bind_param($stmt, "iisdssi", ...$insert_barkasse_var);
      mysqli_stmt_execute($stmt);
      
      // Überprüfen, ob der INSERT erfolgreich war
      if (mysqli_error($conn)) {
        echo "<p style='color:red; text-align:center;'>MySQL Fehler: " . mysqli_error($conn) . "</p>";
        exit();
      } else {
        echo "<p style='color:green; text-align:center;'>Erfolgreich in weh.barkasse hinzugefügt.</p>";
      }
      
      mysqli_stmt_close($stmt);

    }

    if(isset($_POST['check'])){ 
      $check_kasse = $_POST['kasse'];
      $check_text = $_POST['text'];
      $insert_sql = "UPDATE barkasse SET status = 1 WHERE status = 0 AND kasse = $check_kasse";
      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_execute($stmt);
      if(mysqli_error($conn)) {
        echo "MySQL Fehler: " . mysqli_error($conn);
      } else {
          echo "<p style='color:green; text-align:center;'>Zahlungen für " . $check_text . " erfolgreich abgecheckt!</p>";
      }
      mysqli_stmt_close($stmt);
    }

    if(isset($_POST['delete_transfer_id'])){ 
      $id = intval($_POST['transfer_id']);
    
      // Verbindung zur Datenbank überprüfen
      if (!$conn) {
        die("Datenbankverbindung fehlgeschlagen: " . mysqli_connect_error());
      }
    
      $select_sql = "SELECT transfer_id FROM barkasse WHERE id = ?";
      if ($stmt = mysqli_prepare($conn, $select_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $realtransferid);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
      } else {
        echo '<p style="color: red; text-align: center;">Fehler beim Abrufen des Transfers: ' . mysqli_error($conn) . '</p>';
        exit();
      }
    
      $delete_sql = "DELETE FROM barkasse WHERE id = ?";
      if ($stmt = mysqli_prepare($conn, $delete_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        $result1 = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
      } else {
        echo '<p style="color: red; text-align: center;">Fehler beim Löschen aus barkasse: ' . mysqli_error($conn) . '</p>';
        exit();
      }
    
      if (!empty($realtransferid)) {
        $delete_sql = "DELETE FROM transfers WHERE id = ?";
        if ($stmt = mysqli_prepare($conn, $delete_sql)) {
          mysqli_stmt_bind_param($stmt, "i", $realtransferid);
          $result2 = mysqli_stmt_execute($stmt);
          mysqli_stmt_close($stmt);
        } else {
          echo '<p style="color: red; text-align: center;">Fehler beim Löschen aus transfers: ' . mysqli_error($conn) . '</p>';
          exit();
        }
      } else {
        $result2 = true; // Kein Eintrag in 'transfers' zu löschen
      }
    
      // Überprüfen, ob beide Löschvorgänge erfolgreich waren
      if ($result1 && $result2) {
        echo '<p style="color: green; text-align: center;">Der Transfer wurde erfolgreich aus Barkasse und UserKonto gelöscht.</p>';
      } else {
        echo '<p style="color: red; text-align: center;">Fehler beim Löschen des Transfers.</p>';
      }
    }
    
    
    if (isset($_POST['save_transfer_id'])) {
      $transfer_id = $_POST['transfer_id'];
      $selected_user = $_POST['selected_user']; // Benutzer-ID
      $kasse = $_POST['kasse']; // Kasse
      $betrag = str_replace(",", ".", str_replace(".", "", $_POST['betrag']));
      $beschreibung = $_POST['beschreibung']; // Neue Beschreibung
      $agent = $_SESSION['uid']; // Agent, der die Änderung durchführt (aus Session)

      $query = "UPDATE barkasse 
      SET uid = ?, 
          kasse = ?, 
          betrag = ?, 
          beschreibung = ?
      WHERE id = ?";
      $stmt = $conn->prepare($query);
      $stmt->bind_param("iidsi", $selected_user, $kasse, $betrag, $beschreibung, $transfer_id);
      if ($stmt->execute()) {
        echo '<p style="color: green; text-align: center;">Der Transfer wurde erfolgreich aktualisiert.</p>';
      } else {
        echo '<p style="color: red; text-align: center;">Fehler beim Aktualisieren des Transfers: ' . $conn->error . '</p>';
      }
      $stmt->close();
    }

    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
      setTimeout(function() {
        document.forms['reload'].submit();
      }, 3000);
    </script>";
  }

  $kassen = array(
    1 => array('name' => 'Netzwerk-AG Barkasse I', 'wert' => 'kasse_netz1'),
    2 => array('name' => 'Netzwerk-AG Barkasse II', 'wert' => 'kasse_netz2'),
    3 => array('name' => 'Kassenwart-Barkasse I', 'wert' => 'kasse_wart1'),
    4 => array('name' => 'Kassenwart-Barkasse II', 'wert' => 'kasse_wart2'),
    5 => array('name' => 'Tresor', 'wert' => 'kasse_tresor')
  );
  
  foreach ($kassen as $kasse => $kassendaten) {
    $kassedb = $kassendaten['wert'];
    $kassenname = $kassendaten['name'];

    $sql = "SELECT wert FROM constants WHERE name = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $kassedb);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $wert);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if ($_SESSION['uid'] == $wert) {
      
        echo "<br><br><hr><br><br>";

        $sql = "SELECT SUM(betrag) FROM barkasse WHERE kasse = $kasse";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
          die('Fehler beim Vorbereiten der Anweisung: ' . mysqli_error($conn));
        }

        if (!mysqli_stmt_execute($stmt)) {
          die('Fehler beim Ausführen der Anweisung: ' . mysqli_error($stmt));
        }

        $result = get_result($stmt);
        $stand = $result[0]['SUM(betrag)']; // Extrahiere den Wert aus dem Array
        $text = "Aktueller Kassenstand: $stand €";
        echo '<h1 class = "center">'.strtoupper($kassenname).'</h1>';
        echo '<h2 class = "center">'.strtoupper($text).'</h2>';

        echo '<div style="display: flex; justify-content: center; align-items: center;">';
        echo '<form method="post" style="text-align: center;">';
        echo '<input type="hidden" name="popup" value="1">';
        echo '<input type="hidden" name="kasse" value="' . htmlspecialchars($kasse, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="kassenname" value="' . htmlspecialchars($kassenname, ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" class="center-btn" style="padding: 10px 20px; font-size: 16px;">Neue Zahlung eintragen</button>';
        echo '</form>';
        echo '</div>';
        
        

#        echo '<div style="display: flex; justify-content: center;">
#        <div style="padding: 20px; width: 50%;">
#          <div style="text-align: center; color: white; font-size: 30px;">Zahlung eintragen:</div>
#          <form method=\'POST\' enctype=\'multipart/form-data\' style="text-align: center;">
#            <div style="display: flex; flex-direction: column; width: 30%; margin: auto;">
#            ';
#            echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()">';
#            echo '<option value="" disabled selected hidden>User/Dummy auswählen</option>'; 
#            mysqli_stmt_free_result($stmt);
#            $sql = "SELECT uid, name, room, turm FROM users WHERE pid = 11 OR uid in (472, 492) ORDER BY FIELD(turm, 'weh', 'tvk'), room";
#            $stmt = mysqli_prepare($conn, $sql);
#            mysqli_stmt_execute($stmt);
#            #mysqli_set_charset($conn, "utf8");
#            mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
#            while (mysqli_stmt_fetch($stmt)) {
#              if ($uid == 472){                
#                echo '<option value="' . $uid . '">NetzAG Dummy</option>';
#              } elseif ($uid == 492){                
#                echo '<option value="' . $uid . '">Haussprecher Dummy</option>';
#              } else {
#                $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
#                echo '<option value="' . $uid . '">' . $name . ' [' .$formatted_turm . ' ' . $room . ']</option>';
#              }
#            }
#            echo '</select>';
#            echo'
#              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
#                <label for=\'beschreibung\' style="margin-right: 10px; color: white; font-size: 30px;">Grund:</label>
#                <input type=\'text\' id=\'beschreibung\' name=\'beschreibung\' style="flex-grow: 1;">
#              </div>
#              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
#                <label for=\'betrag\' style="margin-right: 10px; color: white; font-size: 30px;">Betrag:</label>
#                <input type=\'text\' id=\'betrag\' name=\'betrag\' style="flex-grow: 1;">
#              </div>
#              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
#                <label for=\'file\' style="margin-right: 10px; color: #fff; font-size: 30px;">Datei:</label>
#                <input type=\'file\' id=\'file\' name=\'file\' style="flex-grow: 1;">
#              </div>
#              <input type=\'hidden\' name=\'kasse\' value=\'' . $kasse . '\'>
#              <input type=\'hidden\' name=\'text\' value=\'' . $kassenname . '\'>
#              <div style="display: flex; justify-content: center; margin-top: 1px;">
#                <input type="hidden" name="reload" value="1">
#                <button type=\'submit\' name=\'submit\' class="center-btn">Hinzufügen</button>
#              </div>
#            </div>
#          </form>
#        </div>
#      </div>';

          $sql = "SELECT barkasse.id, barkasse.tstamp, barkasse.beschreibung, barkasse.betrag, barkasse.pfad, users.name, users.uid
          FROM users 
          JOIN barkasse ON barkasse.uid = users.uid
          WHERE barkasse.status = 0 AND kasse = $kasse";

          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $id, $tstamp_unform, $beschreibung, $betrag, $path, $name, $uid);

          echo "<br><br>";

          echo '<table class="grey-table"
                <tr>
                  <th>Zeitstempel</th>
                  <th>User</th>
                  <th>Grund</th>
                  <th>Betrag</th>
                </tr>';
          
          while (mysqli_stmt_fetch($stmt)) {
              echo '<tr onclick="document.getElementById(\'form-'.$id.'\').submit();" style="cursor: pointer;">';  // JavaScript zum Absenden des Formulars bei Klick
              echo '<form id="form-'.$id.'" method="post">';  // Das Formular für die Zeile
              echo "<td>".date('d.m.Y', $tstamp_unform)."</td>";
              echo "<td>".$name . " [" . $uid . "]</td>";
              $beschreibung_text = htmlspecialchars($beschreibung);
              $gekürzte_beschreibung = strlen($beschreibung_text) > 50 
                  ? substr($beschreibung_text, 0, 50) . "[...]"
                  : $beschreibung_text;
              echo "<td>" . $gekürzte_beschreibung . "</td>";
              echo "<td>".number_format($betrag, 2, ",", ".")." €</td>";
          
              // Verstecktes Input-Feld für die Transfer-ID in jeder Zeile
              echo '<input type="hidden" name="transfer_id" value="'.$id.'">';
              echo '<input type="hidden" name="popup" value="true">';
          
              echo '</form>';  // Formular beenden
              echo "</tr>";
          }
          mysqli_stmt_close($stmt);          
          echo "</table>";


          // Die Form nur um die Eingabefelder und den Button
          echo "<form action='Kasse.php' method='post' name='kassenprüfung'>";
          echo "<div style='display: flex; justify-content: center; margin-top: 1%'>";
          echo "<input type='hidden' name='kasse' value='$kasse'>";
          echo "<input type='hidden' name='text' value='$kassenname'>";
          echo "<input type='hidden' name='reload' value='1'>";
          echo "<button type='check' name='check' class='center-btn'>Alle Einträge bestätigen</button>";
          echo "</div>";
          echo "</form>";
          
          
          echo "<br><br><br>";


          if (isset($_POST['transfer_id']) && isset($_POST['popup'])) {
            $query = "SELECT uid, betrag, tstamp, beschreibung, kasse, pfad FROM barkasse WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $_POST['transfer_id']);
            $stmt->execute();
            $stmt->bind_result(
                $selected_transfer_uid, 
                $selected_transfer_betrag, 
                $selected_transfer_tstamp,
                $selected_transfer_beschreibung,
                $selected_transfer_kasse,
                $selected_transfer_pfad
            );
            $stmt->fetch();
            $stmt->close();
          
            $kasse_options = [
                1 => "Netzbarkasse 1",
                2 => "Netzbarkasse 2",
                3 => "Kassenwartkasse 1",
                4 => "Kassenwartkasse 2",
                5 => "Tresor"
            ];

            $formatted_date = date("d.m.Y H:i", $selected_transfer_tstamp);

            echo ('<div class="overlay"></div>
            <div class="anmeldung-form-container form-container">
              <form method="post">
                  <button type="submit" name="close" value="close" class="close-btn">X</button>
              </form>
            <br>');

            echo '<div style="text-align: center;">';

            if (!empty($selected_transfer_pfad)) {
              echo '<div>
                      <img src="' . htmlspecialchars($selected_transfer_pfad, ENT_QUOTES, 'UTF-8') . '" alt="Bild" class="full-size-image">
                    </div><br><br>';
          }
          
    

            echo '<form method="post">';
            echo '<input type="hidden" name="transfer_id" value="'.$_POST['transfer_id'].'">';
            echo '<input type="hidden" name="ausgangs_betrag" value="'.number_format($selected_transfer_betrag, 2, ".", "").'">';
            echo '<input type="hidden" name="ausgangs_uid" value="'.$selected_transfer_uid.'">';
            echo '<input type="hidden" name="ausgangs_beschreibung" value="'.htmlspecialchars($selected_transfer_beschreibung).'">';

            echo '<div style="text-align: center; color: lightgrey;">';
            echo 'Transfer ID: <span style="color:white;">'.$_POST['transfer_id'].'</span>';
            echo '</div>';
            echo '<br><br>';
            

            echo '<label for="selected_user" style="color:lightgrey;">Benutzer auswählen:</label><br>';
            echo '<select name="selected_user" id="selected_user" style="margin-top: 10px; padding: 5px; text-align: center; text-align-last: center; display: block; margin-left: auto; margin-right: auto;">
                    <option value="" disabled selected>Wähle einen Benutzer</option>';
            echo '<option value="472" ' . (472 == $selected_transfer_uid ? 'selected' : '') . '>NetzAG-Dummy</option>';
            echo '<option value="492" ' . (492 == $selected_transfer_uid ? 'selected' : '') . '>Vorstand-Dummy</option>';

            // Abfrage, um alle Benutzer zu laden
            $sql = "SELECT uid, name, room, turm 
                    FROM users 
                    WHERE pid = 11 
                    ORDER BY FIELD(turm, 'weh', 'tvk'), room";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);

            while (mysqli_stmt_fetch($stmt)) {
                $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
                echo '<option value="' . $uid . '" ' . ($uid == $selected_transfer_uid ? 'selected' : '') . '>' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
            }
            echo '</select><br><br>';

            
            echo '<label style="color:lightgrey;">Kasse:</label><br>';
            $kasse_display = isset($kasse_options[$selected_transfer_kasse]) ? $kasse_options[$selected_transfer_kasse] : "Undefinierte Kasse";
            echo '<select name="kasse" style="text-align: center; width: 100%;">'; // Volle Breite
            foreach ($kasse_options as $key => $value) {
                echo '<option value="'.$key.'" '.($key == $selected_transfer_kasse ? 'selected' : '').'>'.$value.'</option>';
            }
            echo '</select><br><br><br>';

            echo '<label style="color:lightgrey;">Beschreibung:</label><br>';
            echo '<input type="text" name="beschreibung" value="'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'" style="text-align: center; width: 80%;"><br><br><br>';


            echo '<label style="color:lightgrey;">Betrag:</label><br>';
            echo '<input type="text" name="betrag" value="'.number_format($selected_transfer_betrag, 2, ",", ".").'" style="text-align: center;"><br><br>';
            
            echo '<div style="display: flex; justify-content: center; margin-top: 20px;">';            
            echo "<input type='hidden' name='reload' value='1'>";
            echo '<button type="submit" name="save_transfer_id" class="center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Speichern</button>';
            echo '</form>'; // Hier endet das Hauptformular
            echo '</div>';

            echo '<div style="display: flex; justify-content: center; margin-top: 20px;">';            
            echo '<form method="post" style=text-align: center;">';
            echo "<input type='hidden' name='transfer_id' value='" . $_POST["transfer_id"] . "'>"; // Übergabe der Transfer-ID
            echo "<input type='hidden' name='reload' value='1'>";
            echo '<button type="submit" name="delete_transfer_id" class="red-center-btn" style="padding: 10px 20px;">Löschen</button>';
            echo '</form>';
            echo '</div>';

            echo '</div>'; # Ende textalign center
            echo '</div>'; # Ende Popup Klasse

          } # Ende popup logik


          if (isset($_POST['kasse']) && isset($_POST['kassenname']) && isset($_POST['popup'])) {
            echo '<div class="overlay"></div>
            <div class="lightbox" style="display: flex; justify-content: center; align-items: center;">
                <form method="post">
                    <button type="submit" name="close" value="close" class="close-btn">X</button>
                </form>
                <br>
            
                <div style="text-align: center;">
                    <div style="text-align: center; color: white; font-size: 30px; margin-bottom: 20px;">Zahlung eintragen:</div>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="display: flex; flex-direction: column; align-items: center;">
            
                            <!-- Dropdown für User/Dummy -->
                            <select name="uid" style="width: 80%; margin-bottom: 20px; font-size: 20px; padding: 10px; text-align: center;" onchange="this.form.submit()">
                                <option value="" disabled selected hidden>User/Dummy auswählen</option>';
            
                                // SQL-Abfrage vorbereiten
                                $sql = "SELECT uid, name, room, turm FROM users WHERE pid = 11 OR uid in (472, 492) 
                                        ORDER BY FIELD(turm, 'weh', 'tvk'), room";
                                $stmt = mysqli_prepare($conn, $sql);
                                mysqli_stmt_execute($stmt);
                                mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
            
                                // Ergebnisse iterieren und Optionen generieren
                                while (mysqli_stmt_fetch($stmt)) {
                                    if ($uid == 472) {
                                        echo '<option value="' . $uid . '">NetzAG Dummy</option>';
                                    } elseif ($uid == 492) {
                                        echo '<option value="' . $uid . '">Haussprecher Dummy</option>';
                                    } else {
                                        $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
                                        echo '<option value="' . $uid . '">' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
                                    }
                                }
                                echo '</select>';
            
                            // Eingabefelder für Beschreibung, Betrag und Datei
                            echo '
                                <div style="width: 80%; margin-bottom: 20px;">
                                    <label for="beschreibung" style="color: white; font-size: 18px; margin-bottom: 5px; display: block;">Grund:</label>
                                    <input type="text" id="beschreibung" name="beschreibung" style="width: 100%; padding: 10px; font-size: 16px;">
                                </div>
                                <div style="width: 80%; margin-bottom: 20px;">
                                    <label for="betrag" style="color: white; font-size: 18px; margin-bottom: 5px; display: block;">Betrag:</label>
                                    <input type="text" id="betrag" name="betrag" style="width: 100%; padding: 10px; font-size: 16px;">
                                </div>
                                <div style="width: 80%; margin-bottom: 20px;">
                                    <label for="file" style="color: white; font-size: 18px; margin-bottom: 5px; display: block;">Datei:</label>
                                    <input type="file" id="file" name="file" style="width: 100%; padding: 10px; font-size: 16px;">
                                </div>';
            
                            // Hidden-Felder für Kasse und Kassenname
                            echo '
                                <input type="hidden" name="kasse" value="' . htmlspecialchars($_POST["kasse"], ENT_QUOTES, 'UTF-8') . '">
                                <input type="hidden" name="text" value="' . htmlspecialchars($_POST["kassenname"], ENT_QUOTES, 'UTF-8') . '">
                                <input type="hidden" name="reload" value="1">';
            
                            // Hinzufügen-Button
                            echo '
                                <div style="margin-top: 20px;">
                                    <button type="submit" name="submit" class="center-btn" style="padding: 10px 20px; font-size: 16px;">Hinzufügen</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>';
            
        }
        

        } # Ende von weh.constants UID für Kasse = SESSION[uid]
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