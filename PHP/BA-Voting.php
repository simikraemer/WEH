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

            // Kandidaten in Array speichern und Duplikate sowie leere Einträge entfernen
            $choices = array_filter([$first_choice, $second_choice, $third_choice], fn($v) => $v !== '');
            if (count($choices) !== count(array_unique($choices))) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Please do not select the same candidate multiple times.</p>";
                echo "</div>";
            } elseif (count($choices) === 0) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Please select at least one candidate.</p>";
                echo "</div>";
            } else {
                // Kandidaten und Prioritäten in die Datenbank einfügen
                $insert_sql = "INSERT INTO bavotes (uid, tstamp, pollid, kandidat, count) VALUES (?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert_sql);

                $prio_map = [
                    1 => $first_choice,
                    2 => $second_choice,
                    3 => $third_choice
                ];
                $prio_to_count = [1 => 3, 2 => 2, 3 => 1];

                foreach ($prio_map as $prio => $kandidat) {
                    if ($kandidat === '' || $kandidat === null) continue;

                    $count = $prio_to_count[$prio];
                    mysqli_stmt_bind_param($stmt, "iiiii", $uid, $tstamp, $pollid, $kandidat, $count);
                    mysqli_stmt_execute($stmt);
                }

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

            // Kandidaten in Array speichern und Duplikate sowie leere Einträge entfernen
            $choices = array_filter([$first_choice, $second_choice, $third_choice], fn($v) => $v !== '');
            if (count($choices) !== count(array_unique($choices))) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Please do not select the same candidate multiple times.</p>";
                echo "</div>";
            } elseif (count($choices) === 0) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>ERROR: Please select at least one candidate.</p>";
                echo "</div>";
            } else {
                $prio_map = [
                    1 => $first_choice,
                    2 => $second_choice,
                    3 => $third_choice
                ];
                $prio_to_count = [1 => 3, 2 => 2, 3 => 1];

                // Alle vorhandenen count-Werte abrufen
                $all_counts = [1, 2, 3];
                $keep_counts = [];

                $success = false;

                foreach ($prio_map as $prio => $kandidat) {
                    $count = $prio_to_count[$prio];

                    if ($kandidat === '' || $kandidat === null) continue;

                    $keep_counts[] = $count;

                    // Prüfen ob Eintrag existiert
                    $check_sql = "SELECT 1 FROM bavotes WHERE uid = ? AND pollid = ? AND count = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "iii", $uid, $pollid, $count);
                    mysqli_stmt_execute($check_stmt);
                    mysqli_stmt_store_result($check_stmt);

                    if (mysqli_stmt_num_rows($check_stmt) > 0) {
                        // UPDATE
                        $update_sql = "UPDATE bavotes SET kandidat = ?, tstamp = ? WHERE uid = ? AND pollid = ? AND count = ?";
                        $update_stmt = mysqli_prepare($conn, $update_sql);
                        mysqli_stmt_bind_param($update_stmt, "iiiii", $kandidat, $tstamp, $uid, $pollid, $count);
                        mysqli_stmt_execute($update_stmt);
                        $success = $success || (mysqli_stmt_affected_rows($update_stmt) > 0);
                        mysqli_stmt_close($update_stmt);
                    } else {
                        // INSERT
                        $insert_sql = "INSERT INTO bavotes (uid, tstamp, pollid, kandidat, count) VALUES (?, ?, ?, ?, ?)";
                        $insert_stmt = mysqli_prepare($conn, $insert_sql);
                        mysqli_stmt_bind_param($insert_stmt, "iiiii", $uid, $tstamp, $pollid, $kandidat, $count);
                        mysqli_stmt_execute($insert_stmt);
                        $success = $success || (mysqli_stmt_affected_rows($insert_stmt) > 0);
                        mysqli_stmt_close($insert_stmt);
                    }

                    mysqli_stmt_close($check_stmt);
                }

                // Nicht mehr verwendete Prioritäten löschen
                $delete_counts = array_diff($all_counts, $keep_counts);
                foreach ($delete_counts as $del_count) {
                    $delete_sql = "DELETE FROM bavotes WHERE uid = ? AND pollid = ? AND count = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_sql);
                    mysqli_stmt_bind_param($delete_stmt, "iii", $uid, $pollid, $del_count);
                    mysqli_stmt_execute($delete_stmt);
                    mysqli_stmt_close($delete_stmt);
                }

                if ($success || !empty($delete_counts)) {
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
            }
        }






    }














    ?>
    <style>
        .bapolls-dropdown-wrapper {
            text-align: center;
            margin-bottom: 20px;
        }

        .bapolls-year-select {
            font-size: 25px;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #11a50d;
            background-color: #1e1e1e;
            color: #ffffff;
        }

.bapolls-table {
    width: 90%;
    max-width: 1000px;
    margin: 40px auto;
    border-collapse: separate;
    border-spacing: 0;
    background-color: #181818;
    color: #ffffff;
    border: 2px solid #11a50d;
    border-radius: 12px;
    table-layout: fixed;
}

.bapolls-table th,
.bapolls-table td {
    padding: 20px;
    font-size: 18px;
    text-align: center;
    border-bottom: 1px solid #2c2c2c;
    word-wrap: break-word;
}

.bapolls-table th {
    background-color: #11a50d;
    color: #181818;
    font-weight: bold;
    text-transform: uppercase;
}

/* Feste Spaltenbreiten für perfekte Zentrierung */
.bapolls-table th:nth-child(1),
.bapolls-table td:nth-child(1) {
    width: 33.3%;
}
.bapolls-table th:nth-child(2),
.bapolls-table td:nth-child(2) {
    width: 33.3%;
}
.bapolls-table th:nth-child(3),
.bapolls-table td:nth-child(3) {
    width: 33.3%;
}

.bapolls-table tr:last-child td {
    border-bottom: none;
}

.bapolls-table tr:hover td {
    background-color: #11a50d;
    transition: background-color 0.15s ease-in-out;
}

.bapolls-row-open td {
    background-color:rgb(70, 70, 70);
}

.bapolls-row-closed td {
    background-color: #202020;
}

.bapolls-table th:first-child {
    border-top-left-radius: 10px;
}
.bapolls-table th:last-child {
    border-top-right-radius: 10px;
}
.bapolls-table tr:last-child td:first-child {
    border-bottom-left-radius: 10px;
}
.bapolls-table tr:last-child td:last-child {
    border-bottom-right-radius: 10px;
}




    </style>

    <?php
    echo '<div class="bapolls-dropdown-wrapper">';
    echo '<form method="get">';
    echo '<select name="year" onchange="this.form.submit()" class="bapolls-year-select">';
    $currentYear = date('Y');
    $selectedYear = isset($_GET['year']) ? $_GET['year'] : $currentYear;
    for ($y = $currentYear - 5; $y <= $currentYear; $y++) {
        $selected = ($y == $selectedYear) ? 'selected' : '';
        echo "<option value='$y' $selected>$y</option>";
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';

    // Query & Darstellung
    $year = (int) $selectedYear;

    $sql = "SELECT id, uid, tstamp, anzahl_kandidaten, endtime, room, turm, pfad, beendet 
            FROM bapolls 
            WHERE YEAR(FROM_UNIXTIME(tstamp)) = ? AND turm = ? AND room BETWEEN ? AND ?
            ORDER BY beendet, tstamp DESC, room";
    $stmt = mysqli_prepare($conn, $sql);

    $room_start = $floor * 100;
    $room_end = ($floor * 100) + 99;

    mysqli_stmt_bind_param($stmt, "isii", $year, $turm, $room_start, $room_end);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    echo '<table class="bapolls-table">';
    echo "<tr>
            <th>Turm</th>
            <th>Raum</th>
            <th>Datum</th>
        </tr>";

    while ($row = mysqli_fetch_assoc($result)) {
        $date = date('d.m.Y', $row['tstamp']);
        $formatted_turm = ($row['turm'] == 'tvk') ? 'TvK' : strtoupper($row['turm']);
        $id = $row['id'];
        $rowClass = $row['beendet'] == 1 ? 'bapolls-row-closed' : 'bapolls-row-open';

        echo "<tr class='$rowClass' style='cursor: pointer;' onclick='document.getElementById(\"form_$id\").submit();'>";
        echo "<td>$formatted_turm</td>";
        echo "<td>{$row['room']}</td>";
        echo "<td>$date</td>";
        echo "</tr>";

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
    mysqli_stmt_close($stmt);
      











    
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
                    <select name="choice_' . $priority . '" id="choice_' . $priority . '" style="padding: 5px; font-size: 16px;">
                    <option value="">-- keine Auswahl --</option>';

    
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
