<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji -->
<!-- Für den WEH e.V. -->

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


function printRoomCell($roomnumber, $user_by_id, $roomtype, $hausbeitrag, $netzbeitrag) {

    $found = false;
    $insolvent = false;
    $icon_size = 15;
    $euro = '';
    $missing = 0;

    foreach ($user_by_id as $entry) {
        if ($roomnumber == $entry["room"]) {
            $found = true;
            $uid = $entry["uid"];
            $netzkey = 7;
            $lastradius = $entry["lastradius"];
            #$banned = $entry["banned"];
            $banned = false;
            $firstname = $entry["firstname"];
            $lastname = $entry["lastname"];
            $room = $entry["room"];
            $groups = $entry["groups"];

            $fees = 0;
            $netzkey = 7;
            if (!in_array($netzkey, explode(',', $entry["groups"]))) {   
                $fees += $netzbeitrag;
            }
            if (!$entry["honory"] == 1) {   
                $fees += $hausbeitrag;
            }
            
            if ($entry["betrag"] < $fees) {   
                $insolvent = true;
                $missing = $fees - $entry["betrag"];
            }

            break;
        }
    }

    if ($found) {

        $currentTimestamp = time();
        $timeDifference = $currentTimestamp - $lastradius;
        $maxTimeDifference = 21 * 24 * 60 * 60;
        
        if ($timeDifference > $maxTimeDifference) {
            $timeDifference = $maxTimeDifference;
        }
        
        if ($banned) {
            $backgroundcolor = "#b81414";
        } else {
            $startColor = [30, 200, 30];
            $endColor = [30, 30, 30];
            $progress = $timeDifference / $maxTimeDifference;
            $gradientColor = [
                $startColor[0] + round($progress * ($endColor[0] - $startColor[0])),
                $startColor[1] + round($progress * ($endColor[1] - $startColor[1])),
                $startColor[2] + round($progress * ($endColor[2] - $startColor[2]))
            ];
            $backgroundcolor = sprintf("#%02X%02X%02X", $gradientColor[0], $gradientColor[1], $gradientColor[2]);
        }
        
        
        echo '<td style="width: 40px; height: 30px; cursor: pointer; color: black; background-color: ' . $backgroundcolor . ';';
        echo '" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'' . $backgroundcolor . '\'" onclick="submitForm(this)" title="' . $firstname .' '. $lastname .'">';        
        echo '<form method="post">';
        echo '<input type="hidden" name="menu_show" value="1">';
        echo '<input type="hidden" name="uid" value="' . $uid . '">';
        echo '<input type="hidden" name="lastradius" value="' . $lastradius . '">';
        echo '<input type="hidden" name="missing" value="' . $missing . '">';
        echo '<input type="hidden" name="banned" value="' . $banned . '">';
        echo '<input type="hidden" name="firstname" value="' . $firstname . '">';
        echo '<input type="hidden" name="lastname" value="' . $lastname . '">';
        echo '<input type="hidden" name="room" value="' . $room . '">';
        echo '<input type="hidden" name="groups" value="' . $groups . '">';
        echo '</form>';
        echo '</td>';
    } else {
        echo '<td style="width: 40px; cursor: pointer; background-color: black;" onmouseover="this.style.backgroundColor=\'#0ffa0f\'" onmouseout="this.style.backgroundColor=\'black\'" onclick="submitForm(this)">';
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

function getBenutzerDaten($conn, $turm) {
    $sql = "
    SELECT
        u.uid,
        u.username,
        u.room,
        u.lastradius,
        u.firstname,
        u.lastname,
        CASE
            WHEN s.uid IS NOT NULL AND s.starttime <= UNIX_TIMESTAMP() AND s.endtime >= UNIX_TIMESTAMP() AND s.internet = 1 THEN 1
            ELSE 0
        END AS banned
    FROM
        users u
    LEFT JOIN
        sperre s ON u.uid = s.uid
    WHERE
        u.pid = '11' AND u.turm = ?
    GROUP BY
        u.uid,
        u.username,
        u.room,
        u.lastradius,
        u.firstname,
        u.lastname,
        banned
    ORDER BY
        u.room;
    ";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $turm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($row['uid'])) {
                $user_by_id[$row['uid']] = array(
                    "username" => $row['username'],
                    "room" => $row['room'],
                    "uid" => $row['uid'],
                    "lastradius" => $row['lastradius'],
                    "firstname" => $row['firstname'],
                    "lastname" => $row['lastname'],
                    "banned" => $row['banned']
                );
            }
        }
        mysqli_free_result($result);
    }

    // Zweite SQL-Abfrage für constants
    $sql_constants = "
    SELECT name, wert FROM constants WHERE name IN ('hausbeitrag', 'netzbeitrag')
    ";
    
    $result_constants = mysqli_query($conn, $sql_constants);

    if ($result_constants) {
        while ($row = mysqli_fetch_assoc($result_constants)) {
            if ($row['name'] === 'hausbeitrag') {
                $hausbeitrag = $row['wert'];
            } elseif ($row['name'] === 'netzbeitrag') {
                $netzbeitrag = $row['wert'];
            }
        }
        mysqli_free_result($result_constants);
    }

    $_SESSION["user_by_id"] = $user_by_id;
    $_SESSION["hausbeitrag"] = $hausbeitrag;
    $_SESSION["netzbeitrag"] = $netzbeitrag;

    mysqli_stmt_close($stmt);
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
  
    getBenutzerDaten($conn,$turm);
    
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
                printRoomCell($roomnumber, $_SESSION["user_by_id"], $roomtype, $_SESSION["hausbeitrag"], $_SESSION["netzbeitrag"]);
            }
        } else {
            // Schleife für die Zimmer auf anderen Etagen
            for ($room = 1; $room <= 16; $room++) {
                $roomnumber = $floor * 100 + $room;
                $roomtype = 1;
                printRoomCell($roomnumber, $_SESSION["user_by_id"], $roomtype, $_SESSION["hausbeitrag"], $_SESSION["netzbeitrag"]);
                
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

    echo '<br><br><br><br>';

  
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
        $lastradius = $_POST["lastradius"];
        $missing = $_POST["missing"];
        $banned = $_POST["banned"];
        $zeit = time();
        $firstname = $_POST["firstname"]; 
        $lastname = $_POST["lastname"]; 
        $room = $_POST["room"];
        $groups = $_POST["groups"];
        $abstand_in_sekunden = $zeit - $lastradius + 3600;
        $abstand_tage = floor($abstand_in_sekunden / (24 * 60 * 60));
        $abstand_stunden = floor(($abstand_in_sekunden % (24 * 60 * 60)) / 3600);
        
        if ($abstand_tage > 10000) {
        $abstand_text = "Nicht dokumentiert";
        } elseif ($abstand_tage == 0 && $abstand_stunden == 0) {
            $abstand_text = "Verbunden";
        } elseif ($abstand_tage > 0) {
            $abstand_text = "$abstand_tage Tage, $abstand_stunden Stunden";
        } elseif ($abstand_stunden == 1) {
            $abstand_text = "$abstand_stunden Stunde";
        } else {
            $abstand_text = "$abstand_stunden Stunden";
        }
    
        $lastradius = $abstand_text;
        $sql = "SELECT firstname, lastname, room, groups FROM users WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $firstname, $lastname, $room, $groups);  
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);

        $name = $firstname . " " . $lastname;
        $roomformatiert = str_pad($room, 4, "0", STR_PAD_LEFT);
    
        echo '<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
            </form>
            <br>';
        
        echo '<div style="text-align: center;">
            <div class="center" style="color: white; font-size: 35px;">Zimmer ' . $room . ' - ' . $name . '</div>';
        echo '</div>';
        
        echo '<div style="text-align: center;">
            <div class="center" style="color: white; font-size: 35px;">Letzter Radius: ' . $lastradius . '</div>
        </div>';

        if ($banned) {
            echo '<div style="text-align: center;">
                <br><div class="center" style="color: red; font-size: 30px;">Aktive Internetsperre</div>
            </div>';
        }

        #if ($missing > 0) {
        #    echo '<div style="text-align: center;">
        #        <br><div class="center" style="color: orange; font-size: 30px;">Fehlender Betrag zum nächsten Monat:<br>' . number_format($missing, 2, ",", ".") . '€</div>
        #    </div>';
        #}
        
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center; margin-top: 10px;">            
            <form action="House.php" method="post">
                <input type="hidden" name="id" value="' . $uid . '">
                <button class="center-btn" type="submit">User bearbeiten</button>
            </form>
        </div>';
        
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