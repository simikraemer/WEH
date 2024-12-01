<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji Januar 2024 -->
<!-- Für den WEH e.V. -->
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");


function printRoomCell2remove($roomnumber, $user_by_id) {
    $found = false;
    $icon_size = 15;
    $euro = '';
    $missing = 0;

    foreach ($user_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $uid = $entry["uid"];
            $id = $entry["id"];
            $key = $entry["key"];
            $room = $entry["room"];
            $firstname = strtok($entry["firstname"], ' ');
            $lastname = strtok($entry["lastname"], ' ');
            $name = $firstname . ' ' . $lastname;
            $vollformname = $name . " [" . $room . "]";
            break;
        }
    }

    if ($found) {
        $backgroundcolor = "#11a50d";        
        
        echo '<td style="width: 40px; height: 20px; cursor: pointer; color: black; background-color: ' . $backgroundcolor . ';';
        echo '" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'' . $backgroundcolor . '\'" onclick="submitForm(this)" title="' . $firstname .' '. $lastname .'">';        
        echo '<form method="post">';
        echo '<input type="hidden" name="removezuweisung" value="1">';
        echo '<input type="hidden" name="roomselected" value="1">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="key" value="' . $key . '">';
        echo '<input type="hidden" name="vollformname" value="' . $vollformname . '">';
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="width: 40px; height: 20px; cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
        echo '<form id="myForm" method="post">';
        echo '<input type="hidden" name="roomnumber" value="' . $roomnumber . '">';
        echo '<input type="hidden" name="menu_empty" value="1">';
        echo '</form>';
        echo '</td>';
        echo '<script>
        function submitForm(element) {
            var form = element.getElementsByTagName(\'form\')[0];
            form.submit();
        }
        </script>';
    }
}

function printRoomCell($roomnumber, $user_by_id) {

    $found = false;
    $icon_size = 15;
    $euro = '';
    $missing = 0;

    foreach ($user_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $uid = $entry["uid"];
            $room = $entry["room"];
            $firstname = strtok($entry["firstname"], ' ');
            $lastname = strtok($entry["lastname"], ' ');
            $name = $firstname . ' ' . $lastname;
            $vollformname = $name . " [" . $room . "]";
            break;
        }
    }

    if ($found) {
        $backgroundcolor = "#11a50d";        
        
        echo '<td style="width: 40px; height: 20px; cursor: pointer; color: black; background-color: ' . $backgroundcolor . ';';
        echo '" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'' . $backgroundcolor . '\'" onclick="submitForm(this)" title="' . $firstname .' '. $lastname .'">';        
        echo '<form method="post">';
        echo '<input type="hidden" name="userselected" value="1">';
        echo '<input type="hidden" name="uid" value="' . $uid . '">';
        echo '<input type="hidden" name="vollformname" value="' . $vollformname . '">';
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="width: 40px; height: 20px; cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
        echo '<form id="myForm" method="post">';
        echo '<input type="hidden" name="roomnumber" value="' . $roomnumber . '">';
        echo '<input type="hidden" name="menu_empty" value="1">';
        echo '</form>';
        echo '</td>';
        echo '<script>
        function submitForm(element) {
            var form = element.getElementsByTagName(\'form\')[0];
            form.submit();
        }
        </script>';
    }
}


if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["Hausmeister"])) {
    load_menu();

    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<a href="Schluessel.php" style="text-decoration: none;">';
    echo '<button type="button" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">User-Ansicht</button>';
    echo '</a>';
    // Mittlerer Button mit Hidden Input
    echo '<button type="submit" name="showinserttable" class="house-button" style="font-size:30px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Neuen Schlüssel zuweisen';
    echo '</button>';
    echo '<button type="submit" name="roomansicht" class="house-button" style="font-size:20px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Raum-Ansicht';
    echo '</button>';
    echo '</form>';
    
  
    $schlüsselraumdaten = array(
        1 => array(
            'id' => 1,
            'Raum' => 'Dach',
            'Stockwerk' => 'Dach',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        2 => array(
            'id' => 2,
            'Raum' => 'Schacht',
            'Stockwerk' => 'Schacht',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        3 => array(
            'id' => 3,
            'Raum' => 'Wohnzimmer',
            'Stockwerk' => '0. Etage',
            'Zuständig 1' => 10,
            'Zuständig 2' => 7,
            'Hausmeisterrelevant' => 1,
        ),
        4 => array(
            'id' => 4,
            'Raum' => 'Trennwand',
            'Stockwerk' => '0. Etage',
            'Zuständig 1' => 10,
            'Zuständig 2' => 9,
            'Hausmeisterrelevant' => 0,
        ),
        5 => array(
            'id' => 5,
            'Raum' => 'Wohnzimmerschrank',
            'Stockwerk' => '0. Etage',
            'Zuständig 1' => 10,
            'Zuständig 2' => 7,
            'Hausmeisterrelevant' => 0,
        ),
        6 => array(
            'id' => 6,
            'Raum' => 'Tischtennisraum',
            'Stockwerk' => 'EG',
            'Zuständig 1' => 23,
            'Zuständig 2' => 9,
            'Hausmeisterrelevant' => 1,
        ),
        7 => array(
            'id' => 7,
            'Raum' => 'Druckerschrank',
            'Stockwerk' => 'EG',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 0,
        ),
        8 => array(
            'id' => 8,
            'Raum' => 'Putzraum',
            'Stockwerk' => 'EG',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        9 => array(
            'id' => 9,
            'Raum' => 'Sprecherbriefkasten',
            'Stockwerk' => 'EG',
            'Zuständig 1' => 9,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 0,
        ),
        10 => array(
            'id' => 10,
            'Raum' => 'Getränkekeller',
            'Stockwerk' => 'Keller',
            'Zuständig 1' => 9,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        11 => array(
            'id' => 11,
            'Raum' => 'Waschkellerschrank',
            'Stockwerk' => 'Keller',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 0,
        ),
        12 => array(
            'id' => 12,
            'Raum' => 'Rechter TK-Flügel',
            'Stockwerk' => 'Tiefkeller',
            'Zuständig 1' => 7,
            'Zuständig 2' => 9,
            'Hausmeisterrelevant' => 1,
        ),
        13 => array(
            'id' => 13,
            'Raum' => 'Serverraum',
            'Stockwerk' => 'Tiefkeller',
            'Zuständig 1' => 7,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        14 => array(
            'id' => 14,
            'Raum' => 'Sprecherkeller',
            'Stockwerk' => 'Tiefkeller',
            'Zuständig 1' => 9,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        15 => array(
            'id' => 15,
            'Raum' => 'Musikkeller',
            'Stockwerk' => 'Tiefkeller',
            'Zuständig 1' => 32,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        16 => array(
            'id' => 16,
            'Raum' => 'Werkzeugkeller',
            'Stockwerk' => 'Tiefkeller',
            'Zuständig 1' => 13,
            'Zuständig 2' => null,
            'Hausmeisterrelevant' => 1,
        ),
        17 => array(
            'id' => 17,
            'Raum' => 'Aufzug',
            'Stockwerk' => 'Schacht',
            'Zuständig 1' => 7,
            'Zuständig 2' => 9,
            'Hausmeisterrelevant' => 1,
        ),
    );
    
    $stockwerkeReihenfolge = array("Dach", "Schacht", "0. Etage", "EG", "Keller", "Tiefkeller");

    $zeit = time();
    

    ######################################
    ## Zuweisung eines neuen Schlüssels ##
    ######################################

    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
        if (isset($_POST["showusers"])) {
            echo '<br><br><br>';
            echo '<div style="text-align: center; font-size: 50px; color: white;">';
            echo "Nun einen der berechtigten Bewohner auswählen:";
            echo '</div>';
            echo '<br><br>';
            
            echo '<div style="text-align: center; font-size: 20px; color: white;">';
            echo "Falls der Bewohner hier nicht aufgelistet ist, muss er sich erst an seinen AG-Sprecher wenden!";
            echo '</div>';
            echo '<br>';

            $key = $_POST["key"];
            $gruppe1 = $_POST["gruppe1"];
            $gruppe2 = $_POST["gruppe2"];
            if ($gruppe2 == NULL) {
                $gruppe2 = "DiesistkeineGruppelol";
            }

            echo '<table class="grey-table">
            <tr>
              <th>Name</th>
              <th>Raum</th>
              <th>Auswählen</th>
              </tr>';
            $sql = "SELECT DISTINCT u.firstname, u.lastname, u.uid, u.room
            FROM weh.users u 
            LEFT JOIN weh.`keys` k ON u.uid = k.uid
            WHERE u.pid = 11 AND (u.groups LIKE CONCAT('%,', ?, '%') OR u.groups LIKE CONCAT('%,', ? , '%'))
              AND NOT EXISTS (
                SELECT 1
                FROM weh.`keys` k2
                WHERE k2.uid = u.uid AND k2.`key` = ? AND k2.`back` = 0
              )
            ORDER BY room;";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssi", $gruppe1, $gruppe2, $key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $firstname, $lastname, $uid, $room);
            while (mysqli_stmt_fetch($stmt)){
                $firstname = strtok($firstname, ' ');
                $lastname = strtok($lastname, ' ');
                $name = $firstname . ' ' . $lastname;
                echo '<tr>';
                echo '<td>' . $name . '</td>';
                echo '<td>' . $room . '</td>';
                echo "<td>";
                echo "<form method='post'>";
                echo "<div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'>";
                echo "<button type='submit' name='exec_keyzuweisen' class='center-btn'>Add</button>";  
                echo '<input type="hidden" name="reload" value=1>';
                echo '<input type="hidden" name="uid" value='.$uid.'>';
                echo '<input type="hidden" name="key" value='.$key.'>';
                echo "</div>";
                echo "</form>";
                echo "</td>";
                echo '</tr>';
            }
            mysqli_stmt_close($stmt);
            echo '</table>';

        }
        if (isset($_POST["exec_keyzuweisen"])) {
            $key = $_POST["key"];
            $uid = $_POST["uid"];

            $sql = "INSERT INTO `keys` (tstamp, uid, `key`) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iii", $zeit, $uid, $key);
            mysqli_stmt_execute($stmt);                
            mysqli_stmt_close($stmt);

            echo '<br><br><br>';
            echo '<div style="text-align: center;">
            <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
            </div>';
            echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
            echo "<script>
              setTimeout(function() {
                document.forms['reload'].submit();
              }, 1000);
            </script>";
        }

    } elseif (isset($_POST["showinserttable"])) {
        echo '<br><br><br>';
        echo '<div style="text-align: center; font-size: 50px; color: white;">';
        echo "Zuerst einen Schlüssel auswählen:";
        echo '</div>';
        echo '<br><br>';
        
        echo '<table style="margin: auto; text-align: center; color: white; border-spacing: 20px;">';
        foreach ($stockwerkeReihenfolge as $stockwerk) {
            echo '<tr>';
            echo '<td style="font-weight: bold; font-size: 2em; border-right: 3px solid white;">' . $stockwerk . '   '.'</td>'; // Erste Spalte ist der Name des Stockwerks
            foreach ($schlüsselraumdaten as $raum) {
                if ($raum['Stockwerk'] === $stockwerk) {
                    echo '<td style="font-size: 1.3em; word-wrap: break-word;">';
                    echo '<form method="post" style="cursor: pointer;" onmouseover="this.style.color=\'#11a50d\'" onmouseout="this.style.color=\'white\'">';
                    echo '<input type="hidden" name="showusers">';
                    echo '<input type="hidden" name="key" value="' . $raum['id'] . '">';
                    echo '<input type="hidden" name="reload" value=1>';
                    echo '<input type="hidden" name="gruppe1" value="' . $raum['Zuständig 1'] . '">';
                    echo '<input type="hidden" name="gruppe2" value="' . $raum['Zuständig 2'] . '">';
                    echo '<button type="submit" style="background: none; border: none; padding: 0; font-size: inherit; color: inherit; cursor: pointer;" 
                    onmouseenter="this.style.color=\'#11a50d\'" onmouseleave="this.style.color=\'white\'">' . $raum['Raum'] . '</button>';
                    echo '</form>';
                    echo '</td>';
                }
            }
            
            echo '</tr>';
        }
        echo '</table>';
    } else {


        #####################################
        ### Schlüssel Zuweisung entfernen ###
        #####################################
        if (isset($_POST["exec_removezuweisung"])) {
            $sql = "UPDATE weh.keys SET back = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $zeit, $_POST["id"]);
            mysqli_stmt_execute($stmt);
            $stmt->close(); 

            echo '<br><br><br>';
            echo '<div style="text-align: center;">
            <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
            </div>';
        }

        if (isset($_POST["removezuweisung"])) {
            $id = $_POST["id"];

            echo '<div class="overlay"></div>
            <div class="anmeldung-form-container form-container">
                <form method="post">
                    <button type="submit" name="close" value="close" class="close-btn">X</button>
                </form>
                <br>';

            echo '<div style="text-align: center;">
                <div class="center" style="color: white; font-size: 35px;">Willst du diese Schlüsselzuweisung wirklich aufheben?</div>';
            echo '</div>';
            echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center; margin-top: 10px;">            
                <form method="post">
                    <input type="hidden" name="id" value="' . $id . '">
                    <button class="red-center-btn" name="exec_removezuweisung" type="submit">Zuweisung entfernen!</button>
                </form>
            </div>';
            echo '</div>';
        }


        #############
        ## Tabelle ##
        #############

        if (isset($_POST["userselected"])) {

            echo '<br>';

            $uid = $_POST["uid"];
            $vollformname = $_POST["vollformname"];

            echo '<br><br><br>';
            echo '<div style="text-align: center; font-size: 50px; color: white;">';
            echo $vollformname;
            echo '</div>';
            echo '<br>';

            $sql = "SELECT id, tstamp, `key` FROM weh.keys WHERE uid = ? AND back = 0 ORDER BY `key`";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $id, $tstamp, $key);
            $keys_ar = array();
            while (mysqli_stmt_fetch($stmt)){
                $keys_ar[] = array(
                    'id' => $id,
                    'tstamp' => $tstamp,
                    'key' => $key,
                );
            }
            mysqli_stmt_close($stmt);
    
            echo '<table class="grey-table">
            <tr>
              <th>Schlüssel</th>
              <th>Bereich</th>
              <th>Ausgabedatum</th>';
            echo '<th>Entfernen</th>';
            echo '</tr>';

            usort($keys_ar, function($a, $b) use ($schlüsselraumdaten, $stockwerkeReihenfolge) {
                $stockwerkA = $schlüsselraumdaten[$a['key']]['Stockwerk'];
                $stockwerkB = $schlüsselraumdaten[$b['key']]['Stockwerk'];
            
                $indexA = array_search($stockwerkA, $stockwerkeReihenfolge);
                $indexB = array_search($stockwerkB, $stockwerkeReihenfolge);
            
                return $indexA - $indexB;
            });
            
            foreach ($keys_ar as $key_info) {
                $ausgabedatum = date("d.m.Y", $key_info['tstamp']);
    
                echo '<tr>';
                echo '<td>' . $schlüsselraumdaten[$key_info['key']]['Raum'] . '</td>';
                echo '<td>' . $schlüsselraumdaten[$key_info['key']]['Stockwerk'] . '</td>';
                echo '<td>' . $ausgabedatum . '</td>';
                echo "<td>";
                echo "<form method='post'>";
                echo "<div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'>";
                echo "<button type='submit' name='removezuweisung' class='red-center-btn'>Zurückgegeben</button>";  
                echo '<input type="hidden" name="id" value="' . $key_info['id'] . '">';
                echo "</div>";
                echo "</form>";
                echo "</td>";
                echo '</tr>';
            }
    
            echo '</table>';

        } elseif (isset($_POST["roomselected"])) {

            $key = $_POST["key"];

            echo '<br><br><br>';
            echo '<div style="text-align: center; font-size: 50px; color: white;">';
            echo 'Aktuelle Schlüsselträger<br>' . $schlüsselraumdaten[$key]['Raum'];
            echo '</div>';
            echo '<br>';
#            echo '<div style="text-align: center; font-size: 20px; color: white;">';
#            echo '<i>Mit Maus über die Zelle hovern, um Namen anzuzeigen</i>';
#            echo '</div>';
#
            $sql = "SELECT u.firstname, u.lastname, u.room, u.uid, MAX(k.id) as id, MAX(k.tstamp) as tstamp, MAX(k.key) as `key` 
            FROM weh.keys k 
            LEFT JOIN weh.users u ON k.uid = u.uid 
            WHERE k.back = 0 AND k.key = ?
            GROUP BY u.uid 
            ORDER BY u.room";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $firstname, $lastname, $room, $uid, $id, $tstamp, $key);
            $user_ar = array();
            while (mysqli_stmt_fetch($stmt)){
                $user_ar[] = array(
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'room' => $room,
                    'uid' => $uid,
                    'id' => $id,
                    'tstamp' => $tstamp,
                    'key' => $key,
                );
            }
            mysqli_stmt_close($stmt);

            echo '<br>';
            
            echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
            echo '<table class="grey-table">
            <tr>
              <th>User</th>
              <th>Ausgabedatum</th>';
            echo '<th>Entfernen</th>';
            echo '</tr>';

            #usort($user_ar, function($a, $b) use ($schlüsselraumdaten, $stockwerkeReihenfolge) {
            #    $stockwerkA = $schlüsselraumdaten[$a['key']]['Stockwerk'];
            #    $stockwerkB = $schlüsselraumdaten[$b['key']]['Stockwerk'];
            #
            #    $indexA = array_search($stockwerkA, $stockwerkeReihenfolge);
            #    $indexB = array_search($stockwerkB, $stockwerkeReihenfolge);
            #
            #    return $indexA - $indexB;
            #});
            
            foreach ($user_ar as $user_info) {
                $ausgabedatum = date("d.m.Y", $user_info['tstamp']);
                $name = $user_info["firstname"] . ' ' . $user_info["lastname"];
                echo '<tr>';
                echo '<td>' . $name . '</td>';
                echo '<td>' . $ausgabedatum . '</td>';
                echo "<td>";
                echo "<form method='post'>";
                echo "<div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'>";
                echo "<button type='submit' name='removezuweisung' class='red-center-btn'>Zurückgegeben</button>";  
                echo '<input type="hidden" name="id" value="' . $user_info['id'] . '">';
                echo '<input type="hidden" name="roomselected" value=1>';
                echo '<input type="hidden" name="key" value="' . $key . '">';
                echo "</div>";
                echo "</form>";
                echo "</td>";
                echo '</tr>';
            }
    
            echo '</table>';
            echo '</div>';

#
#            // Überschriftszeile für Raumnummern
#            echo '<tr><td style="color: white;"></td>';
#            echo '<td></td>';
#            for ($room = 1; $room <= 16; $room++) {
#                echo '<td style="color: white;">' . $room . '</td>';
#
#                // Leerzelle nach Zimmer 8
#                if ($room == 8) {
#                    echo '<td></td>';
#                    echo '<td></td>';
#                }
#            }
#            echo '</tr>';
#
#            // Schleife für die Etagen
#            for ($floor = 17; $floor >= 0; $floor--) {
#                echo '<tr>';
#
#                // Überschriftszeile für Etage
#                echo '<td style="color: white;">' . $floor . '</td>';
#                echo '<td></td>';
#
#                if ($floor == 0) {
#                    // Schleifer für die 0. Etage
#                    for ($room = 1; $room <= 4; $room++) {
#                        $roomnumber = $floor * 100 + $room;
#                        printRoomCell2remove($roomnumber, $user_ar);
#                    }
#                } else {
#                    // Schleife für die Zimmer auf anderen Etagen
#                    for ($room = 1; $room <= 16; $room++) {
#                        $roomnumber = $floor * 100 + $room;
#                        printRoomCell2remove($roomnumber, $user_ar);
#
#                        // Leerzelle nach Zimmer 8
#                        if ($room == 8) {
#                            echo '<td></td>';
#                            echo '<td></td>';
#                        }
#                    }
#                }
#
#                echo '</tr>';
#            }
#        
#
#            echo '</table>';
            
        } elseif (isset($_POST["roomansicht"])) {
            echo "<br><br><br>";
            echo '<div style="text-align: center; font-size: 50px; color: white;">';
            echo "Raum-Ansicht";
            echo '</div>';
            echo '<br><br>';

            echo '<table style="margin: auto; text-align: center; color: white; border-spacing: 20px;">';
            foreach ($stockwerkeReihenfolge as $stockwerk) {
                echo '<tr>';
                echo '<td style="font-weight: bold; font-size: 2em; border-right: 3px solid white;">' . $stockwerk . '   '.'</td>'; // Erste Spalte ist der Name des Stockwerks
                foreach ($schlüsselraumdaten as $raum) {
                    if ($raum['Stockwerk'] === $stockwerk) {
                        echo '<td style="font-size: 1.3em; word-wrap: break-word;">';
                        echo '<form method="post" style="cursor: pointer;" onmouseover="this.style.color=\'#11a50d\'" onmouseout="this.style.color=\'white\'">';
                        echo '<input type="hidden" name="roomselected" value="' . $raum['Raum'] . '">';
                        echo '<input type="hidden" name="key" value="' . $raum['id'] . '">';
                        echo '<button type="submit" style="background: none; border: none; padding: 0; font-size: inherit; color: inherit; cursor: pointer;" 
                        onmouseover="this.style.color=\'#11a50d\'" onmouseout="this.style.color=\'white\'">' . $raum['Raum'] . '</button>';
                        echo '</form>';
                        echo '</td>';
                    }
                }
                
                echo '</tr>';
            }
            echo '</table>';
        } else {

            echo '<br><br><br>';
            echo '<div style="text-align: center; font-size: 50px; color: white;">';
            echo 'User-Ansicht';
            echo '</div>';
            echo '<br>';
            echo '<div style="text-align: center; font-size: 20px; color: white;">';
            echo '<i>Mit Maus über die Zelle hovern, um Namen anzuzeigen</i>';
            echo '</div>';

            $sql = "SELECT u.firstname, u.lastname, u.room, u.uid, MAX(k.id) as id, MAX(k.tstamp) as tstamp, MAX(k.key) as `key` 
            FROM weh.keys k 
            LEFT JOIN weh.users u ON k.uid = u.uid 
            WHERE k.back = 0 
            GROUP BY u.uid 
            ORDER BY u.room";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $firstname, $lastname, $room, $uid, $id, $tstamp, $key);
            $user_ar = array();
            while (mysqli_stmt_fetch($stmt)){
                $user_ar[] = array(
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'room' => $room,
                    'uid' => $uid,
                    'id' => $id,
                    'tstamp' => $tstamp,
                    'key' => $key,
                );
            }
            mysqli_stmt_close($stmt);

            echo '<br>';
            
            echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
            echo '<table>';

            // Überschriftszeile für Raumnummern
            echo '<tr><td style="color: white;"></td>';
            echo '<td></td>';
            for ($room = 1; $room <= 16; $room++) {
                echo '<td style="color: white;">' . $room . '</td>';

                // Leerzelle nach Zimmer 8
                if ($room == 8) {
                    echo '<td></td>';
                    echo '<td></td>';
                }
            }
            echo '</tr>';

            // Schleife für die Etagen
            for ($floor = 17; $floor >= 0; $floor--) {
                echo '<tr>';

                // Überschriftszeile für Etage
                echo '<td style="color: white;">' . $floor . '</td>';
                echo '<td></td>';

                if ($floor == 0) {
                    // Schleifer für die 0. Etage
                    for ($room = 1; $room <= 4; $room++) {
                        $roomnumber = $floor * 100 + $room;
                        printRoomCell($roomnumber, $user_ar);
                    }
                } else {
                    // Schleife für die Zimmer auf anderen Etagen
                    for ($room = 1; $room <= 16; $room++) {
                        $roomnumber = $floor * 100 + $room;
                        printRoomCell($roomnumber, $user_ar);

                        // Leerzelle nach Zimmer 8
                        if ($room == 8) {
                            echo '<td></td>';
                            echo '<td></td>';
                        }
                    }
                }

                echo '</tr>';
            }
        

            echo '</table>';
            echo '</div>';
        }
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