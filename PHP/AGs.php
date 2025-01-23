<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php

// Connect to database PATH
require('template.php');
mysqli_set_charset($conn, "utf8");

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
    
    echo '<table class="clear-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width:50%; text-align:center;">AG Name</th>';
    echo '<th style="width:50%; text-align:center;">Vacancies</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($ag_complete as $group_id => $group) {
        if ($group["turm"] != $turm) {
            continue;
        }
        if (!empty($group["vacancy"])) {
            echo '<tr onclick="highlightContainer(\'container_' . $group_id . '\'); location.href=\'#container_' . $group_id . '\';" style="cursor: pointer;">';
            echo '<td style="width:50%;">' . htmlspecialchars($group["name"]) . '</td>';
            echo '<td style="width:50%;">' . intval($group["vacancy"]) . '</td>';
            echo '</tr>';
        }       
    }

    echo '</tbody>';
    echo '</table>';


    foreach ($ag_complete as $id => &$data_user_erweiterung) {
        $data_user_erweiterung['users'] = array();
    }
    
    // Abfrage der Benutzer und Zuordnung zu den AGs
    $sql = "SELECT room, firstname, lastname, groups, sprecher, uid, turm, pid, username FROM users WHERE pid IN (11,12,64) ORDER BY room ASC";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $user_groups = explode(",", $row['groups']);
            foreach ($user_groups as $group_id) {
                if (isset($ag_complete[$group_id])) {
                    $ag_complete[$group_id]['users'][] = array(
                        "room" => $row['room'],
                        "name" => explode(' ', $row['firstname'])[0] . ' ' . explode(' ', $row['lastname'])[0],
                        "turm" => $row['turm'],
                        "pid" => $row['pid'],
                        "username" => $row['username'],
                        "sprecher" => $row['sprecher']
                    );
                }
            }
        }
    }

    // Ausgabe
    echo '<div style="display:flex; flex-wrap:wrap; justify-content:center; margin:10px; padding:0px 60px 60px 60px;">';
    foreach ($ag_complete as $group_id => $group) {
        if ($group["turm"] != $turm) {
            continue;
        }
        if ($group_id === 26) {
            continue;
        }

        $randfarbe = [
            "weh" => "#11a50d",
            "tvk" => "#E49B0F"
        ][$group["turm"]] ?? "white";        
        
        echo '<div id="container_' . $group_id . '" class="ag-box" style="border: 20px outset ' . $randfarbe . '; transition: background-color 0.5s ease;">';
        
        // Wrapper für Name und Mail mit festem Abstand
        echo '<div style="margin-bottom:20px;">';
        
        // Gruppenname mit Gruppenlink
        echo '<h2 class="white-text" style="font-size:28px; font-weight:bold; text-align:center; margin-bottom:10px;">';
        echo '<a href="' . htmlspecialchars($group['link']) . '" class="white-text">' . $group['name'] . '</a>';
        echo '</h2>';
            
        // Mailto-Link als kursiver Text
        echo '<p style="text-align:center; margin:0;"><a href="mailto:' . htmlspecialchars($group['mail']) . '" class="white-text" style="font-style:italic;">' . htmlspecialchars($group['mail']) . '</a></p>';
        
        if (!empty($group["vacancy"])) {
            echo '<p style="text-align:center; color:gold; margin-top:20px; font-size:20px;">';
            echo 'Open Spots: ' . intval($group["vacancy"]);
            echo '</p>';
        }        

        echo '</div>'; // Ende des Wrappers

        if (!empty($group['users'])) {
            echo '<table style="width:75%; border-collapse:collapse; margin: 0 auto; text-align:center;">';
            foreach ($group['users'] as $user) {
                if ($user['turm'] === 'tvk') {
                    $turm_form = 'TvK';
                } else {
                    $turm_form = strtoupper($user['turm']); // Fallback für andere Werte, falls vorhanden
                }

                // Überprüfung, ob der Benutzer Sprecher der Gruppe ist
                $isSpeaker = ($user['sprecher'] == $group_id);

                // Bestimme Raumfarbe basierend auf Turm
                $room_color = ($user['turm'] === 'tvk') ? '#E49B0F' : '#11a50d';
                            
                if ($group_id === 24 || $group_id === 27 ) {
                    // Mailto-Link für Hausmeister
                    $mailto = 'hausmeister@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                    $output = '<strong>' . $turm_form . '</strong>';
                } elseif ($user["pid"] != 11) {                    
                    $mailto = $user["username"] . '@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                    $output = '<strong>' . $turm_form . '</strong>';
                } else {
                    // Mailto-Link für normale Gruppenmitglieder
                    $formatted_room = str_pad($user['room'], 4, "0", STR_PAD_LEFT);
                    $mailto = 'z' . $formatted_room . '@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                    $output = $turm_form . ' <strong> - ' . htmlspecialchars($user['room']) . '</strong>'; 
                }
                
                // Ausgabe der Zeile
                echo '<tr>';
                echo '<td style="padding:4px 8px; color:' . $room_color . ';">' . $output . '</td>';
                if ($isSpeaker) {
                    echo '<td style="padding:4px 8px;" class="white-text">
                            <img src="images/ags/vorstand.png" width="18" height="18" alt="Sprecher Icon" style="vertical-align:">
                            <a href="mailto:' . $mailto . '" class="white-text">' . htmlspecialchars($user['name']) . '</a>
                          </td>';
                } else {
                    echo '<td style="padding:4px 8px;" class="white-text">
                            <a href="mailto:' . $mailto . '" class="white-text">' . htmlspecialchars($user['name']) . '</a>
                          </td>';
                }                
                echo '</tr>';

            }
            echo '</table>';
        } else {
            echo '<p class="white-text" style="text-align:center;">Keine Mitglieder in dieser AG.</p>';
        }

        echo '</div>';
    }
    echo '</div>';
}





else {
  header("Location: denied.php");
}
$conn->close();

?>
<script>
    function highlightContainer(containerId) {
        const container = document.getElementById(containerId);

        if (container) {
            // Ursprüngliche Hintergrundfarbe speichern
            const originalColor = container.style.backgroundColor;

            // Hintergrundfarbe setzen (highlight)
            container.style.backgroundColor = "#333";

            // Nach kurzer Zeit zurücksetzen
            setTimeout(() => {
                container.style.backgroundColor = originalColor;
            }, 1000); // Farbe nach 1 Sekunde zurücksetzen
        }
    }
</script>
