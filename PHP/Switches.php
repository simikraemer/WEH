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


function printRoomCell($roomnumber, $switches_by_id) {

    $found = false;

    foreach ($switches_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $id = $entry["id"];
            $pid = 11;
        }
    }

    if ($found) {
        if ($pid == 11){
          echo '<td style="width: 35px; cursor: pointer; color: black; background-color: green;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'green\'">';
        }
        elseif ($pid == 12) {
            echo '<td style="width: 35px; cursor: pointer; color: black; background-color: grey;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'grey\'">';        
        } elseif ($pid == 13 || $pid == 14) {
            echo '<td style="width: 35px; cursor: pointer; color: black; background-color: red;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'grey\'">';        
        } else {
            echo '<td style="width: 35px; cursor: pointer; color: black; background-color: blue;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'grey\'">';        
        }
        echo '<form method="post">';
        echo '<input type="hidden" name="menu_rueckgabe" value="1">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" style="background: none; border: none; color: white; cursor: pointer;">'.$roomnumber.'</button>';
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="width: 35px; cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#11a50d\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
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
    
    $turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : 'weh';
    $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
    $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
    echo '<div style="display:flex; justify-content:center; align-items:center;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; margin-right:10px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
    echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; margin-right:10px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
    echo '</form>';
    echo '</div>';
    echo "<br><br>";
  
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
        if (isset($_POST["exec_add"])) {
            $device_exec_add = $_POST["device"];
            $room_exec_add = $_POST["room"];
            $turm_exec_add = $_POST["turm"];
            $zeit = time();

            $sql = "UPDATE switches SET room = ?, turm = ?, ausgabe = ? WHERE device = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "isis", $room_exec_add, $turm_exec_add, $zeit, $device_exec_add);
            mysqli_stmt_execute($stmt);
            $stmt->close();
            reloadpost();
        }

        if (isset($_POST["exec_rueckgabe"])){    
            $sql = "UPDATE switches SET ausgabe = 0, room = NULL, turm = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
        
            if (!$stmt) {
                die("Vorbereitung der SQL-Anweisung fehlgeschlagen: " . mysqli_error($conn));
            }
        
            // Parameter binden
            mysqli_stmt_bind_param($stmt, "i", $_POST["id"]);
        
            // Anweisung ausführen
            if (!mysqli_stmt_execute($stmt)) {
                die("Ausführung der SQL-Anweisung fehlgeschlagen: " . mysqli_error($conn));
            }
        
            // Anweisung schließen
            mysqli_stmt_close($stmt);

            reloadpost();
        }
    }

    
    $sql = "SELECT switches.room, switches.device, switches.id 
    FROM switches 
    WHERE switches.turm = ? 
    ORDER BY switches.room";    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Error: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "s", $turm);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_error($stmt)) {
        die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_bind_result($stmt, $room, $device, $id);
    if (mysqli_stmt_error($stmt)) {
        die("Error: " . mysqli_stmt_error($stmt));
    }

    $switches_by_id = array();
    while (mysqli_stmt_fetch($stmt)) {
        if ($room == 0) {
            $room = $oldroom;
        }
        $switches_by_id[$id] = array("room" => $room, "device" => $device, "id" => $id, "pid" => $pid);
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
    for ($floor = 17; $floor >= 0; $floor--) {
        echo '<tr>';
        
        // Überschriftszeile für Etage
        echo '<td style="color: white;">' . $floor . '</td>';
        echo '<td></td>';
        
        if ($floor == 0) {
            for ($room = 1; $room <= 4; $room++) {
                $roomnumber = $floor * 100 + $room;
                printRoomCell($roomnumber, $switches_by_id);
            }
        } else {
            // Schleife für die Zimmer auf anderen Etagen
            for ($room = 1; $room <= 16; $room++) {
                $roomnumber = $floor * 100 + $room;
                printRoomCell($roomnumber, $switches_by_id);
                
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
    #echo "<div class='center' style='color: white;'><br><br>Grün = Bewohner<br>Grau = Subletter<br>Rot = Ausgezogen/Abgemeldet<br>Blau = Dummy</div>";

  
    if (isset($_POST["menu_add"])) {
        $room = $_POST["roomnumber"];

        $sql_check = "SELECT device FROM switches WHERE room IS NULL OR room = 0 OR room = ''";
        $stmt_check = mysqli_prepare($conn, $sql_check);
        mysqli_stmt_execute($stmt_check);
        mysqli_stmt_bind_result($stmt_check, $device);
        $devices = array();
        while (mysqli_stmt_fetch($stmt_check)) {
            $devices[] = $device;
        }
        mysqli_stmt_close($stmt_check);
        
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post"">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo '<form method="post">';
        echo '<table style="color: white;">';
        
        echo '<tr><td>Raum:</td><td><input type="text" name="room" value="' . $room . '" required readonly></td></tr>';
        echo '<tr><td>Turm:</td><td><input type="text" name="turm" value="' . $turm . '" required readonly></td></tr>';
        echo '<tr><td>Device:</td><td><select name="device" required>';
        foreach ($devices as $device) {
            echo '<option value="' . $device . '">' . $device . '</option>';
        }
        echo '</select></td></tr>';
        
        echo '</table>';
        echo '<input type="hidden" name="reload" value=1>';
        echo '<div style="display: flex; justify-content: center; align-items: center; margin-top: 20px;">';
        echo '<br><br><input type="submit" name="exec_add" class="center-btn" value="Switch vergeben">';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        
    
      } elseif (isset($_POST["menu_rueckgabe"])) {

        $id = $_POST["id"];

        // Nur noch Daten aus der 'switches'-Tabelle abrufen
        $sql = "SELECT device, mac, ausgabe, room FROM switches WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $device, $mac, $ausgabe, $room);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);
    
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
    
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo '<form method="post">';
        echo '<table style="color: white;">';
    
        echo '<tr><td>Raum:</td><td><input type="text" name="room" value="' . $room . '" required readonly></td></tr>';
        echo '<tr><td>Device:</td><td><input type="text" name="device" value="' . $device . '" required readonly></td></tr>';
        echo '<tr><td>MAC:</td><td><input type="text" name="mac" value="' . $mac . '" required readonly></td></tr>';
        echo '<tr><td>Ausgabe:</td><td><input type="text" name="ausgabe" value="' . date('d.m.Y', $ausgabe) . '" required readonly></td></tr>';
    
        echo '</table>';
        echo '<input type="hidden" name="id" value='.$id.'>';
        echo '<input type="hidden" name="reload" value=1>';
        echo '<div style="display: flex; justify-content: center; align-items: center; margin-top: 20px;">';
        echo '<br><br><input type="submit" name="exec_rueckgabe" class="center-btn" value="Rückgabe">';
        echo '</div>';
        echo '</form>';
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