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


function printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm) {

    $found = false;
    $nagios = 0;

    foreach ($aps_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $id = $entry["id"];
            $nagios = $entry["nagios"];
            $hostname = str_replace("ap-".$turm."-", "", $entry["hostname"]);
            if ($roomtype == 2){
                $hostname = str_replace("etage", "", $hostname);
            }
            if ($roomtype == 3){
                $hostname = ucfirst($hostname);
            }            
            if ($roomtype == 4){
                $hostname = str_replace("ap-farue-", "", $hostname);
            }
            break;
        }
    }

    if ($found) {
        $colors = [
            1 => 'green',
            2 => 'blue',
            3 => 'red',
            'default' => 'grey'
        ];
        
        $backgroundColor = $colors[$nagios] ?? $colors['default'];
        
        echo "<td style=\"cursor: pointer; color: black; background-color: $backgroundColor;\" 
                  onmouseover=\"this.style.backgroundColor='#11a50d'\" 
                  onmouseout=\"this.style.backgroundColor='$backgroundColor'\" 
                  onclick=\"submitForm(this)\">";
                  
        echo '<form method="post">';
        echo '<input type="hidden" name="menu_update" value="1">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        if ($roomtype == 1) {
            echo '<button type="submit" style="background: none; border: none; color: white; cursor: pointer;">'.$roomnumber.'</button>';
        } else {
            echo '<button type="submit" style="background: none; border: none; color: white; cursor: pointer;">'.$hostname.'</button>';
        }
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
        echo '<form id="myForm" method="post">';
        echo '<input type="hidden" name="roomnumber" value="' . $roomnumber . '">';
        echo '<input type="hidden" name="menu_add" value="1">';
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


if (auth($conn) && $_SESSION['NetzAG']) {
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
  
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
        if (isset($_POST["exec_new"])) {
            $room = $_POST["room"];
            $hostname = $_POST["hostname"];
            $ip = $_POST["ip"];
            $mac = $_POST["mac"];
            $beschreibung = $_POST["beschreibung"];
            $produkt = $_POST["produkt"];
            $nagios = $_POST["nagios"];
            $parentswitch = $_POST["parentswitch"];
            $turm = $_POST["turm"];
            
            // Überprüfen, ob der Raum bereits vorhanden ist
            $sql_check = "SELECT COUNT(*) FROM aps WHERE room = ? and turm = ?";
            $stmt_check = mysqli_prepare($conn, $sql_check);
            mysqli_stmt_bind_param($stmt_check, "is", $room, $turm);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_bind_result($stmt_check, $count);
            mysqli_stmt_fetch($stmt_check);
            mysqli_stmt_close($stmt_check);
            
            if ($count > 0) {
                echo '<div style="text-align: center;">
                <span style="color: red; font-size: 20px;">Dieser Raum ist bereits vergeben!</span><br><br>
                </div>';
            } else {
                // Raum ist nicht vorhanden, Daten in die Datenbank einfügen
                $sql_insert = "INSERT INTO aps (room, hostname, ip, mac, beschreibung, produkt, nagios, parentswitch, turm) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = mysqli_prepare($conn, $sql_insert);
                mysqli_stmt_bind_param($stmt_insert, "isssssiss", $room, $hostname, $ip, $mac, $beschreibung, $produkt, $nagios, $parentswitch, $turm);
                mysqli_stmt_execute($stmt_insert);
                $stmt_insert->close();

                reloadpost();
            }
        }

        if (isset($_POST["exec_update"])){
            $room = $_POST["room"];
            $hostname = $_POST["hostname"];
            $ip = $_POST["ip"];
            $mac = $_POST["mac"];
            $beschreibung = $_POST["beschreibung"];
            $produkt = $_POST["produkt"];
            $nagios = $_POST["nagios"];
            $parentswitch = $_POST["parentswitch"];
            $id = $_POST["id"];
            $turm_update = $_POST["turm"];
        
            // Überprüfen, ob der Raum bereits vorhanden ist
            $sql_check = "SELECT COUNT(*) FROM aps WHERE room = ? AND turm = ? AND id != ?";
            $stmt_check = mysqli_prepare($conn, $sql_check);
            mysqli_stmt_bind_param($stmt_check, "isi", $room, $_POST["turm"], $id);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_bind_result($stmt_check, $count);
            mysqli_stmt_fetch($stmt_check);
            mysqli_stmt_close($stmt_check);

            if ($count > 0) {
                echo '<div style="text-align: center;">
                <span style="color: red; font-size: 20px;">Dieser Raum ist bereits vergeben!</span><br><br>
                </div>';
            } else {
                // Raum ist nicht vorhanden, Daten in die Datenbank einfügen
                $sql = "UPDATE aps SET room = ?, hostname = ?, ip = ?, mac = ?, beschreibung = ?, produkt = ?, nagios = ?, parentswitch = ?, turm = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "isssssissi", $_POST["room"], $_POST["hostname"], $_POST["ip"], $_POST["mac"], $_POST["beschreibung"], 
                                       $_POST["produkt"], $_POST["nagios"], $_POST["parentswitch"], $turm_update, $_POST["id"]);
                mysqli_stmt_execute($stmt);
                $stmt->close(); 

                reloadpost();
            }
        }
    }

    
    $sql = "SELECT room, hostname, id, beschreibung, nagios FROM aps WHERE turm = ? ORDER by room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $turm);
    if (!$stmt) {
    die("Error: " . mysqli_error($conn));
    }
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_error($stmt)) {
    die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_bind_result($stmt, $room, $hostname, $id, $beschreibung, $nagios);
    if (mysqli_stmt_error($stmt)) {
    die("Error: " . mysqli_stmt_error($stmt));
    }

    $aps_by_id = array();
    while (mysqli_stmt_fetch($stmt)){
        $aps_by_id[$id] = array("room" => $room, "hostname" => $hostname, "id" => $id, "beschreibung" => $beschreibung, "nagios" => $nagios);
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
            echo '<td style="color: white;">Kamin</td>';
            echo '<td></td>';
        }
    }
    echo '</tr>';
    
    // Schleife für die Etagen
    if ($turm == "weh") {
        $max_floor = 17;
    } elseif ($turm == "tvk") {
        $max_floor = 15;
    }

    for ($floor = $max_floor; $floor >= 0; $floor--) {
        echo '<tr>';
        
        // Überschriftszeile für Etage
        echo '<td style="color: white;">' . $floor . '</td>';
        echo '<td></td>';
        
        if ($floor == 0) {
            for ($room = 1; $room <= 4; $room++) {
                $roomnumber = $floor * 100 + $room;
                $roomtype = 1;
                printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
            }
        } else {
            // Schleife für die Zimmer auf anderen Etagen
            for ($room = 1; $room <= 16; $room++) {
                $roomnumber = $floor * 100 + $room;
                $roomtype = 1;
                printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
                
                // Leerzelle nach Zimmer 8
                if ($room == 8) {
                    echo '<td></td>';
                    $roomnumber = 2000+ $floor;
                    $roomtype = 2;
                    printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
                    echo '<td></td>';
                }
            }
        }
        
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</div>';

    echo "<br><br>";

    echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
    $max_columns = 5;
    $current_column = 0;
    echo '<table>';
    echo '<tr><th colspan="' . ($max_columns + 2) . '" style="color: white; text-align: center;">Andere Räume</th></tr>';
    foreach ($aps_by_id as $entry) {
        if ($entry["room"] > 1800 && $entry["room"] < 1900) {
            if ($current_column >= $max_columns) {
                echo '</tr><tr>';
                $current_column = 0;
            }
            $roomnumber = $entry["room"];
            $roomtype = 3;
            printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
            $current_column++;
        }
    }
    echo '</table>';
    echo '</div>';
    

    echo "<br><br>";

    if ($turm == "weh") {
        $max_columns = 10;
        $current_column = 0;

        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo '<table>';
        echo '<tr><th colspan="' . ($max_columns + 2) . '" style="color: white; text-align: center;">FaRü</th></tr>';
        echo '</tr><tr>';
        foreach ($aps_by_id as $entry) {
            if ($entry["room"] > 3000 && $entry["room"] < 4000) {
                if ($current_column >= $max_columns) {
                    echo '</tr><tr>';
                    $current_column = 0;
                }
                $roomnumber = $entry["room"];
                $roomtype = 4;
                printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
                $current_column++;
            }
        }
        echo '</table>';
        echo '</div>';

        echo "<br><br>";
    }

    echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
    $max_columns = 10;
    $current_column = 0;
    echo '<table>';
    echo '<tr><th colspan="' . ($max_columns + 2) . '" style="color: white; text-align: center;">Vorrat</th></tr>';
    echo '</tr><tr>';
    foreach ($aps_by_id as $entry) {
        if ($entry["room"] > 4000 && $entry["room"] < 5000) {
            if ($current_column >= $max_columns) {
                echo '</tr><tr>';
                $current_column = 0;
            }
            $roomnumber = $entry["room"];
            $roomtype = 0;
            printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
            $current_column++;
        }
    }
    echo '</table>';
    echo '</div>';
    
    echo "<br><br>";    
    
    $max_columns = 10;
    $current_column = 0;
    echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
    echo '<table>';
    echo '<tr><th colspan="' . ($max_columns + 2) . '" style="color: white; text-align: center;">Veraltet/Verschollen</th></tr>';
    echo '</tr><tr>';
    foreach ($aps_by_id as $entry) {
        if ($entry["room"] > 5000 && $entry["room"] < 6000) {
            if ($current_column >= $max_columns) {
                echo '</tr><tr>';
                $current_column = 0;
            }
            $roomnumber = $entry["room"];
            $roomtype = 0;
            printRoomCell($roomnumber, $aps_by_id, $roomtype, $turm);
            $current_column++;
        }
    }
    echo '</table>';
    echo '</div>';

  
    function renderForm($room = '', $hostname = '', $ip = '', $mac = '', $beschreibung = '', $produkt = '', $nagios = 1, $parentswitch = '', $turm = 'weh', $id = '', $isUpdate = false) {
        // Start des Formulars
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
    
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo '<form method="post">';
        echo '<table style="color: white;">';
        
        // Room
        echo '<tr><td>Room:</td><td><input type="text" name="room" value="' . htmlspecialchars($room) . '" required></td></tr>';
        
        // Turm
        echo '<tr><td>Turm:</td><td>';
        echo '<select name="turm">';
        echo '<option value="weh" ' . ($turm == 'weh' ? 'selected' : '') . '>WEH</option>';
        echo '<option value="tvk" ' . ($turm == 'tvk' ? 'selected' : '') . '>TVK</option>';
        echo '</select></td></tr>';
        
        // Hostname
        $hostnameValue = $isUpdate ? $hostname : 'ap-weh-z' . sprintf("%04d", $room);
        echo '<tr><td>Hostname:</td><td><input type="text" name="hostname" value="' . htmlspecialchars($hostnameValue) . '" required></td></tr>';
        
        // IP
        echo '<tr><td>IP:</td><td><input type="text" name="ip" value="' . htmlspecialchars($ip) . '" required></td></tr>';
        
        // MAC
        echo '<tr><td>MAC:</td><td><input type="text" name="mac" value="' . htmlspecialchars($mac) . '" required></td></tr>';
        
        // Beschreibung
        echo '<tr><td>Beschreibung:</td><td><textarea name="beschreibung" rows="4" style="resize: none;">' . htmlspecialchars($beschreibung) . '</textarea></td></tr>';
        
        // Produkt
        echo '<tr><td>Produkt:</td><td><input type="text" name="produkt" value="' . htmlspecialchars($produkt) . '"></td></tr>';
        
        // Nagios
        echo '<tr><td>Nagios:</td><td>';
        echo '<select name="nagios">';
        echo '<option value="1" ' . ($nagios == 1 ? 'selected' : '') . '>eingetragen</option>';
        echo '<option value="0" ' . ($nagios == 0 ? 'selected' : '') . '>ausgetragen</option>';
        echo '<option value="2" ' . ($nagios == 2 ? 'selected' : '') . '>neu eingebaut</option>';
        echo '<option value="3" ' . ($nagios == 3 ? 'selected' : '') . '>in Bearbeitung</option>';
        echo '</select></td></tr>';
        
        // Parent Switch
        echo '<tr><td>Parent Switch:</td><td>';
        $switches = [
            "c4k-weh-1" => "c4k-weh-1 [Coreswitch]",
            "c4k-weh-2" => "c4k-weh-2 [Etage 9-17]",
            "c4k-weh-3" => "c4k-weh-3 [Etage 1-8]",
            "c3560-weh-1" => "c3560-weh-1 [Wohnzimmer]",
            "c4k-tvk-1" => "c4k-tvk-1 [Etage 8-15]",
            "c4k-tvk-2" => "c4k-tvk-2 [Etage 1-7]",
            "c3560-farue-1" => "c3560-farue-1",
            "c3560-farue-2" => "c3560-farue-2",
            "c3560-farue-3" => "c3560-farue-3"
        ];
        echo '<select name="parentswitch">';
        foreach ($switches as $value => $label) {
            echo '<option value="' . $value . '" ' . ($parentswitch == $value ? 'selected' : '') . '>' . $label . '</option>';
        }
        echo '</select></td></tr>';
        
        echo '</table>';
        
        if ($isUpdate) {
            echo '<input type="hidden" name="id" value="' . htmlspecialchars($id) . '">';
        }
        
        echo '<input type="hidden" name="reload" value="1">';
        $submitLabel = $isUpdate ? "Änderungen Speichern" : "Neuen AP hinzufügen";
        $submitName = $isUpdate ? "exec_update" : "exec_new";
        echo '<br><br><input type="submit" name="' . $submitName . '" class="center-btn" value="' . $submitLabel . '">';
        echo '</form>';
        echo '</div>';
    }
    
    if (isset($_POST["menu_add"])) {
        $room = $_POST["roomnumber"];
        renderForm($room);
        
    } elseif (isset($_POST["menu_update"])) {
        $id = $_POST["id"];
    
        $sql = "SELECT room, hostname, ip, mac, beschreibung, produkt, nagios, parentswitch, turm FROM aps WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $room, $hostname, $ip, $mac, $beschreibung, $produkt, $nagios, $parentswitch, $turm);  
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);
    
        renderForm($room, $hostname, $ip, $mac, $beschreibung, $produkt, $nagios, $parentswitch, $turm, $id, true);
    } else {
        echo "<br><br><br><br><br><br><br>";
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