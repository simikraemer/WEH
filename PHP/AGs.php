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

    $ag_complete = array();
    $sql = "SELECT id, name, mail, link, session, turm FROM groups WHERE active = 1 ORDER BY prio";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $ag_complete[$row['id']] = array(
                "id" => $row['id'],
                "name" => $row['name'],
                "mail" => $row['mail'],
                "link" => $row['link'],
                "session" => $row['session'],
                "turm" => $row['turm'],
                "users" => array() // Platz für die zugehörigen Benutzer
            );
        }
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
        if ($group_id === 26) {
            continue;
        }

        $randfarbe = [
            "weh" => "#11a50d",
            "tvk" => "#E49B0F"
        ][$group["turm"]] ?? "white";        
        
        echo '<div class="ag-box" style="border: 20px outset ' . $randfarbe . ';">';
        
        // Gruppenname mit Gruppenlink
        echo '<h2 class="white-text" style="font-size:28px; font-weight:bold; text-align:center; margin-bottom:10px;">';
        echo '<a href="' . htmlspecialchars($group['link']) . '" class="white-text">' . htmlspecialchars($group['name']) . '</a>';
        echo '</h2>';
            
        // Mailto-Link als kursiver Text
        echo '<p style="text-align:center; margin-bottom:15px;"><a href="mailto:' . htmlspecialchars($group['mail']) . '" class="white-text" style="font-style:italic;">' . htmlspecialchars($group['mail']) . '</a></p>';

        if (!empty($group['users'])) {
            echo '<table style="width:75%; border-collapse:collapse; margin: 0 auto; text-align:center;">';
            foreach ($group['users'] as $user) {
                if ($user['turm'] === 'tvk') {
                    $turm_form = 'TvK';
                } else {
                    $turm_form = strtoupper($user['turm']); // Fallback für andere Werte, falls vorhanden
                }

                // Überprüfung, ob der Benutzer Sprecher der Gruppe ist
                $isSpeaker = ($user['sprecher'] == $group['id']);

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