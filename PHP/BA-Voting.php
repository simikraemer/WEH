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

if (auth($conn) && $_SESSION['valid']) {
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
            // Setzen der ap_floor_var
            if (isset($_POST['floor'])) {
                $_SESSION["ap_floor_var"] = $_POST['floor'];
            }
        }

        $turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
        $floor = isset($_SESSION["ap_floor_var"]) ? $_SESSION["ap_floor_var"] : $_SESSION["floor"];

        $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
        $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
        echo '<div style="display:flex; justify-content:center; align-items:center;">';
        echo '<form method="post" style="display:flex; justify-content:center; align-items:center; gap:0px;">';
        echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; width:200px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
        echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; width:200px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
        echo '</form>';
        echo '</div>';
        echo "<br><br>";

        // Freifeld für Floor-Variable
        echo '<div style="display:flex; justify-content:center; align-items:center; margin-top:20px;">';
        echo '<form method="post" style="display:flex; align-items:center;">';
        echo '<input type="text" name="floor" id="floor" style="padding:5px; font-size:20px; margin-right:10px;">';
        echo '<button type="submit" class="center-btn" style="padding:5px 10px; font-size:20px;">Set Floor</button>';
        echo '</form>';
        echo '</div>';

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
        $floor = $_SESSION["floor"];
    }













    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt

        if (isset($_POST["insert_neues_voting"])) {
            $uid = $_SESSION['user'];
            $tstamp = time();
            $pollid = $_POST['pollid'];
            $first_choice = $_POST['choice_1'];
            $second_choice = $_POST['choice_2'];
            $third_choice = $_POST['choice_3'];

            // Überprüfen, ob die Kandidaten unterschiedlich sind
            if ($first_choice == $second_choice || $first_choice == $third_choice || $second_choice == $third_choice) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Choose three different candidates!</p>";
                echo "</div>";
            } else {
                // Kandidaten und Prioritäten in die Datenbank einfügen
                $insert_sql = "INSERT INTO bavotes (uid, tstamp, pollid, kandidat, count) VALUES (?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert_sql);

                // Erster Kandidat (1. Prio -> count = 3)
                $kandidat = $first_choice;
                $count = 3;
                mysqli_stmt_bind_param($stmt, "iiiii", $uid, $tstamp, $pollid, $kandidat, $count);
                mysqli_stmt_execute($stmt);

                // Zweiter Kandidat (2. Prio -> count = 2)
                $kandidat = $second_choice;
                $count = 2;
                mysqli_stmt_bind_param($stmt, "iiiii", $uid, $tstamp, $pollid, $kandidat, $count);
                mysqli_stmt_execute($stmt);

                // Dritter Kandidat (3. Prio -> count = 1)
                $kandidat = $third_choice;
                $count = 1;
                mysqli_stmt_bind_param($stmt, "iiiii", $uid, $tstamp, $pollid, $kandidat, $count);
                mysqli_stmt_execute($stmt);

                if (mysqli_stmt_affected_rows($stmt) > 0) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:green; text-align:center;'>Eintrag erfolgreich hinzugefügt.</p>";
                    echo "</div>";
                    echo "<script>
                        setTimeout(function() {
                            document.forms['reload'].submit();
                        }, 3000);
                    </script>";
                } else {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Fehler beim Einfügen in die Datenbank.</p>";
                    echo "</div>";
                }

                mysqli_stmt_close($stmt);
            }
        } elseif (isset($_POST["update_neues_voting"])) {
            
            $uid = $_SESSION['user'];
            $tstamp = time();
            $pollid = $_POST['pollid'];
            $first_choice = $_POST['choice_1'];
            $second_choice = $_POST['choice_2'];
            $third_choice = $_POST['choice_3'];

            // Überprüfen, ob die Kandidaten unterschiedlich sind
            if ($first_choice == $second_choice || $first_choice == $third_choice || $second_choice == $third_choice) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Choose three different candidates!</p>";
                echo "</div>";
            } else {
                // Update der bestehenden Einträge in der Datenbank
                $update_sql = "UPDATE bavotes SET kandidat = ?, tstamp = ?, count = ? WHERE uid = ? AND pollid = ? AND count = ?";
                $stmt = mysqli_prepare($conn, $update_sql);
    
                // Erster Kandidat (1. Prio -> count = 3)
                $kandidat = $first_choice;
                $count = 3;
                mysqli_stmt_bind_param($stmt, "iiiiii", $kandidat, $tstamp, $count, $uid, $pollid, $count);
                mysqli_stmt_execute($stmt);
    
                // Zweiter Kandidat (2. Prio -> count = 2)
                $kandidat = $second_choice;
                $count = 2;
                mysqli_stmt_bind_param($stmt, "iiiiii", $kandidat, $tstamp, $count, $uid, $pollid, $count);
                mysqli_stmt_execute($stmt);
    
                // Dritter Kandidat (3. Prio -> count = 1)
                $kandidat = $third_choice;
                $count = 1;
                mysqli_stmt_bind_param($stmt, "iiiiii", $kandidat, $tstamp, $count, $uid, $pollid, $count);
                mysqli_stmt_execute($stmt);
    
                if (mysqli_stmt_affected_rows($stmt) > 0) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:green; text-align:center;'>Voting erfolgreich aktualisiert.</p>";
                    echo "</div>";
                    echo "<script>
                        setTimeout(function() {
                            document.forms['reload'].submit();
                        }, 3000);
                    </script>";
                } else {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Fehler beim Aktualisieren des Votings.</p>";
                    echo "</div>";
                }

                mysqli_stmt_close($stmt);
            }
        }
    }













   
    // Dropdown nur für das Jahr mit GET-Methode
    echo '<div style="text-align: center;">';

    $datedropdownsize = "25px";

    // Aktuelles Jahr
    $currentYear = date('Y');

    // Ausgewähltes Jahr festlegen
    $selectedYear = isset($_GET['year']) ? $_GET['year'] : $currentYear;

    echo '<form method="get">';
    echo '<select name="year" onchange="this.form.submit()" style="font-size: ' . $datedropdownsize . '; padding: 5px;">';
    for ($y = $currentYear - 5; $y <= $currentYear; $y++) {
        $selected = ($y == $selectedYear) ? 'selected' : '';
        echo "<option value='$y' $selected>$y</option>";
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';

    // Abstand zwischen dem Dropdown und der Tabelle mit Inline-CSS
    echo '<div style="margin-top: 20px;"></div>';

    // Datenbankabfrage und Tabelle anzeigen
    if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($selectedYear)) {
        $year = (int) $selectedYear;
    } else {
        $year = (int) $currentYear;
    }

    $sql = "SELECT id, uid, tstamp, anzahl_kandidaten, endtime, room, turm, pfad, beendet 
        FROM bapolls 
        WHERE YEAR(FROM_UNIXTIME(tstamp)) = ? AND turm = ? AND room BETWEEN ? AND ?
        ORDER BY beendet, room, tstamp";
    $stmt = mysqli_prepare($conn, $sql);

    // Berechne den Zimmerbereich für die Etage
    $room_start = $floor * 100;
    $room_end = ($floor * 100) + 99;

    mysqli_stmt_bind_param($stmt, "isii", $year, $turm, $room_start, $room_end);
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
      $formatted_turm = ($_POST['turm'] == 'tvk') ? 'TvK' : strtoupper($_POST['turm']);
      $pfad = $_POST["pfad"];
      $starttime = date('d.m.Y', $_POST["tstamp"]);
      $endtime = date('d.m.Y', $_POST["endtime"]);

      if ($_POST["beendet"] == 0) { // Voting läuft noch
        // Überprüfe, ob der Benutzer bereits abgestimmt hat
        $check_sql = "SELECT kandidat, count FROM bavotes WHERE uid = ? AND pollid = ?";
        $stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($stmt, "ii", $_SESSION["uid"], $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    
        // Array zum Speichern der bisherigen Auswahlen
        $existing_votes = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $existing_votes[$row['count']] = $row['kandidat']; // Speichert die Kandidaten nach ihrer Priorität
        }
    
        echo '<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
            </form>
            <div style="color: white; text-align: center; padding: 20px;">';
    
        // Wenn bereits abgestimmt wurde, zeige Nachricht in Grün
        if (!empty($existing_votes)) {
            echo '<p style="color: green;">You already voted, but you can still change your vote.</p>';
        }
    
        echo '<p style="font-size: 18px;">
        Your vote is anonymous.<br>
        Please select your top three favorite candidates in order of priority.<br>
        You must select three different candidates.<br><br>
        The first candidate you choose will receive 3 points,<br>
        the second candidate will receive 2 points,<br>
        and the third candidate will receive 1 point.<br><br>
        BA will try to contact the candidate with the highest score after the vote ends at ' . $endtime . '.<br><br>
        Candidates may have already decided on another apartment by the time the selection takes place,
        so do not be surprised if someone with a lower score ends up being selected.
      </p>


    
            <div style="display: flex; justify-content: center; margin-top: 40px; margin-bottom: 40px;">
                <form action="' . htmlspecialchars($pfad) . '" method="get" target="_blank">
                    <button type="submit" class="center-btn">View PDF</button>
                </form>
            </div>
            
            <form method="post" style="margin-top: 20px;">';
    
        // Generiere Dropdown-Menüs für alle drei Wahlmöglichkeiten
        for ($priority = 1; $priority <= 3; $priority++) {
            $selected_candidate = isset($existing_votes[4 - $priority]) ? $existing_votes[4 - $priority] : ''; // 4 - $priority, weil count: 1 -> 3rd, 2 -> 2nd, 3 -> 1st
    
            echo '<div style="margin-top: 20px;">
                    <label for="choice_' . $priority . '" style="margin-right: 10px;">' . $priority . 'st Choice:</label>
                    <select name="choice_' . $priority . '" id="choice_' . $priority . '" style="padding: 5px; font-size: 16px;">';
    
            // Optionen für alle Kandidaten (Integer)
            for ($i = 1; $i <= $anzahl_kandidaten; $i++) {
                $selected = ($i == $selected_candidate) ? 'selected' : '';
                echo "<option value='$i' $selected>Candidate $i</option>";
            }
    
            echo '  </select>
                  </div>';
        }
    
        // Überprüfe, ob der Benutzer bereits abgestimmt hat und wähle den entsprechenden Submit-Typ
        $submit_type = empty($existing_votes) ? 'insert_neues_voting' : 'update_neues_voting';
        $submit_str = empty($existing_votes) ? 'Submit Vote' : 'Update Vote';
    
        echo '  <div style="margin-top: 20px;">
                    <input type="hidden" name="reload" value="1">
                    <input type="hidden" name="' . $submit_type . '" value="1">
                    <input type="hidden" name="pollid" value="' . $id . '">
                    <input type="submit" value="' . $submit_str .'" class="form-submit">
                </div>
                </form>
            </div>
        </div>';
    } else { // Übersicht Ergebnisse
        display_ba_results($conn, $id, $anzahl_kandidaten, $formatted_turm, $room, $starttime, $endtime, $pfad);
    
    }
  }



  echo '<div style="height: 100px;"></div>';


    $conn->close();
} else {
    header("Location: denied.php");
}
?>
</body>
</html>
