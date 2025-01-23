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


function printRoomCell($roomnumber, $user_by_id, $roomtype) {

    $found = false;
    $agmember = false;
    $icon_size = 15;

    foreach ($user_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $uid = $entry["uid"];
            $ags_icons = getAgsIcons($entry["groups"], $icon_size);
            #$ags_icons = str_replace('<img src=\'images/ags/netzwerk.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>', '', $ags_icons);
            $honory = ($entry["honory"] == 1);
            $onlyfs = ($entry["groups"] == "1,19" || $entry["groups"] == "1,20");
            $today = getdate();
            $bdayUnix = $entry["bday"];
            $bdayDate = date("d-m", $bdayUnix);
            $currentDay = $today["mday"];
            $currentMonth = $today["mon"];
            list($bdayDay, $bdayMonth) = explode("-", $bdayDate);
            $isBirthday = ($bdayDay == $currentDay && $bdayMonth == $currentMonth);
            break;
        }
    }

    if ($found) {
        if ($honory) {
            $ags_icons = '<img src=\'images/ags/ehrenmitglied.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>' . $ags_icons;
        }
        #if ($uid == 2136) {
        #    $ags_icons = '<img src=\'images/fijiwhite.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>' . $ags_icons;
        #}       
        $iconCount = substr_count($ags_icons, '<img src=');
        if ($iconCount === 0) {
            $backgroundcolor = '#3f3c3c';
        } elseif ($iconCount < 2 && $onlyfs) {
            $backgroundcolor = '#004c00';
        #} elseif ($iconCount < 2) {
        #    $backgroundcolor = '#006600';
        } else {
            $backgroundcolor = '#008000';
        }
        if ($isBirthday) {
            $ags_icons = '<img src=\'images/bd.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>' . $ags_icons;
        }
        $iconCount_afterBD = substr_count($ags_icons, '<img src=');
        $over3 = false;
        if ($iconCount_afterBD > 3) {
            $over3 = true;
            $ags_icons = '<img src=\'images/ags/herz.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>';
        }
        if ($honory && $over3) {
            $ags_icons = '<img src=\'images/ags/ehrenmitglied.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>' . $ags_icons;
        }
        if ($isBirthday && $over3) {
            $ags_icons = '<img src=\'images/bd.png\' width=\'' . $icon_size . '\' height=\'' . $icon_size . '\'>' . $ags_icons;
        }
        echo '<td style="width: 55px; cursor: pointer; color: black; background-color: ' . $backgroundcolor . ';" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'' . $backgroundcolor . '\'" onclick="submitForm(this)">';
        echo '<form method="post">';
        echo '<input type="hidden" name="menu_show" value="1">';
        echo '<input type="hidden" name="uid" value="' . $uid . '">';
        echo '<button type="submit" style="background: none; border: none; color: white; cursor: pointer;">' . $ags_icons . '</button>';
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="width: 55px; cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
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


if (auth($conn) && $_SESSION['valid']) {
    load_menu();

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

    if ($turm === "tvk") {
        $maxfloor = 15;
        $zimmerauf0 = 2;
    } elseif ($turm === "weh") {
        $maxfloor = 17;
        $zimmerauf0 = 4;
    } else {
        $maxfloor = 16; // Fallback, falls $turm einen unerwarteten Wert hat
        $zimmerauf0 = 4; // Fallback, falls $turm einen unerwarteten Wert hat
    }

  
    $sql = "SELECT uid, username, room, groups, honory, geburtstag FROM users WHERE turm = ? ORDER BY room";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Error: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 's', $turm);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_error($stmt)) {
    die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_bind_result($stmt, $uid, $username, $room, $groups, $honory, $bday);
    if (mysqli_stmt_error($stmt)) {
    die("Error: " . mysqli_stmt_error($stmt));
    }

    $user_by_id = array();
    while (mysqli_stmt_fetch($stmt)){
        $user_by_id[$uid] = array("username" => $username, "room" => $room, "uid" => $uid, "groups" => $groups, "honory" => $honory, "bday" => $bday);
    }
    
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
    for ($floor = $maxfloor; $floor >= 0; $floor--) {
        echo '<tr>';
        
        // Überschriftszeile für Etage
        echo '<td style="color: white;">' . $floor . '</td>';
        echo '<td></td>';
        
        if ($floor == 0) {
            for ($room = 1; $room <= $zimmerauf0; $room++) {
                $roomnumber = $floor * 100 + $room;
                $roomtype = 1;
                printRoomCell($roomnumber, $user_by_id, $roomtype);
            }
        } else {
            // Schleife für die Zimmer auf anderen Etagen
            for ($room = 1; $room <= 16; $room++) {
                $roomnumber = $floor * 100 + $room;
                $roomtype = 1;
                printRoomCell($roomnumber, $user_by_id, $roomtype);
                
                // Leerzelle nach Zimmer 8
                if ($room == 8) {
                    echo '<td></td>';
                    echo '<td></td>';
                }
            }
        }
        
        echo '</tr>';
    }

    echo '<tr>';
    echo '<td style="color: white;">EG</td>';
    echo '<td></td>';
    $roomnumber = 18992;
    $roomtype = 2;
    printRoomCell($roomnumber, $user_by_id, $roomtype);
    echo '</tr>';
    
    echo '</table>';
    echo '</div>';

    echo '<br><br><br><br>';

#    echo "<div class='center' style='color: white; font-size:40px;'>Legende</div><br><br>";
#    $ag_icons_mapping = array(
#        "Etagensprecher" => "etagensprecher",
#        "Ehrenmitglied" => "ehrenmitglied",
#        "Hausmeister" => "hausmeister",
#        "Vorstand" => "vorstand",
#        "Netzwerk-AG" => "netzwerk",
#        "Belegungsausschuss" => "ba",
#        "Wohnzimmer-AG" => "wohnzimmer",
#        "Wasch-AG" => "wasch",
#        "Werkzeug-AG" => "werkzeug",
#        "Sport-AG" => "sport",
#        "Fahrrad-AG" => "fahrrad",
#        "Musik-AG" => "musik",
#        "Party-AG" => "party",
#        "Datenschutzbeauftragter" => "datenschutz",
#        "Diverse AGs" => "herz"
#    );
#
#    $icon_size = 20;
#    $columns_per_row = 3;
#    $current_column = 0;
#    
#    echo '<table class="legend-table" style="margin: 0 auto; text-align: center; width: 50%;">';
#    echo '<tr>';
#    foreach ($ag_icons_mapping as $ag => $icon) {
#        if ($current_column % $columns_per_row === 0 && $current_column !== 0) {
#            echo '</tr><tr>';
#        }
#    
#        echo '<td style="width: 25%;">';
#        echo "<img src='images/ags/$icon.png' width='$icon_size' height='$icon_size'>";
#        echo "<br><span style='color: white;'>$ag</span><br><br>";
#        echo '</td>';
#    
#        $current_column++;
#    }
#    echo '</tr>';
#    echo '</table>';

  
    if (isset($_POST["menu_empty"])) {
        $room = $_POST["roomnumber"];
        
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post"">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo "<div class='center' style='color: white; font-size:35px;'>Raum ".$room." ist aktuell unbewohnt.</div>";
        echo '</div>';
        
    
      } elseif (isset($_POST["menu_show"])) {
        $uid = $_POST["uid"];
    
        $sql = "SELECT firstname, lastname, room, turm, groups, email FROM users WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $firstname, $lastname, $room, $turm, $groups, $hausmeistermail);  
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);
        
        if ($groups != "1") {
            $name = $firstname . " " . $lastname;
        } else {
            $firstname_OhneZweitnamen = strtok($firstname, ' ');
            $firstLetterLastName = mb_substr($lastname, 0, 1, 'UTF-8');            
            $name = $firstname_OhneZweitnamen . " " . $firstLetterLastName . ".";
        }
        $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
    
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post"">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
        
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        if ($room != 18992) {
            echo "<div class='center' style='color: white; font-size:35px;'>Zimmer " . $room . " - " . $name . "</div>";
        } else {
            echo "<div class='center' style='color: white; font-size:35px;'>Hausmeister " . $name . "</div>";
        }
        echo '</div>';

        echo '<div style="text-align: center; margin-top: 10px;">';
        if ($room != 18992) {
            echo '<a href="mailto:z' . $roomformatiert . '@'.$turm.'.rwth-aachen.de" class="center-btn" style="font-size: 25px; color: black; text-decoration: none;">Person kontaktieren</a>';
            echo '</div>';
            foreach ($ag_complete as $id => $data) {
                if (in_array($id, explode(',', $groups))) {
                    echo '<div style="text-align: center; margin-top: 10px;">';
                    echo '<a href="mailto:' .  $data["mail"] . '" class="center-btn" style="font-size: 25px; color: black; text-decoration: none;">' . $data["name"] . ' kontaktieren</a>';
                    echo '</div>';
                }
            }
        } else {
            echo '<a href="mailto:' . $hausmeistermail . '" class="center-btn" style="font-size: 25px; color: black; text-decoration: none;">Hausmeister kontaktieren</a>';
            echo '</div>';
        }

        echo '</div>'; // Schließe das äußere DIV
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