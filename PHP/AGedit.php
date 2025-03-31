<?php
  session_start();
  require('conn.php');

  $suche = FALSE;
  
  if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    if (!empty($searchTerm)) {
        $suche = TRUE;
        $searchTerm = mysqli_real_escape_string($conn, $searchTerm);
        if (ctype_digit($searchTerm)) {
          $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE
                  (pid in (11,64) AND (name LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (oldroom = '$searchTerm')))";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE 
                    (pid in (11,64) AND (name LIKE '%$searchTerm%'))";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm, $groups);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm, "groups" => $groups);
        }

        header('Content-Type: application/json');
        echo json_encode($searchedusers);
    } else {
        echo json_encode([]); // Senden einer leeren JSON-Antwort, wenn der Suchbegriff leer ist
    }
    exit;
  }


?>
<!DOCTYPE html>

<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && $_SESSION['valid']) {
    load_menu();








// POSTS
if (isset($_POST['leave_group']) || isset($_POST['remove_user'])) {
    $selected_user_id = $_POST['selected_user'];
    $group_id = $_POST['group_id'];
    $changelog = "\n" . date("d.m.Y") . " Ende {$ag_complete[$group_id]['name']} ({$_SESSION['agent']})";

    $sql = "UPDATE users 
            SET groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', ?, ','), ',')),
                historie = CONCAT(COALESCE(historie, ''), ?)
            WHERE uid = ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("isi", $group_id, $changelog, $selected_user_id);
        $stmt->execute();
        $stmt->close();
    }
}


if (isset($_POST['set_group'])) {
    $selected_user_id = $_POST['selected_user'];
    $group_id = $_POST['group_id'];
    $changelog = "\n" . date("d.m.Y") . " Start {$ag_complete[$group_id]['name']} ({$_SESSION['agent']})";

    // Nutzer zur Gruppe hinzufügen
    $sql = "UPDATE users 
            SET groups = TRIM(BOTH ',' FROM CONCAT_WS(',', groups, ?)), 
                historie = CONCAT(COALESCE(historie, ''), ?) 
            WHERE uid = ? AND (FIND_IN_SET(?, groups) = 0 OR groups IS NULL)";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("isis", $group_id, $changelog, $selected_user_id, $group_id);
        $stmt->execute();
        $stmt->close();
    }

    // Vacancy um 1 reduzieren, wenn > 0 (aber nicht unter 0 setzen)
    $sql_vacancy = "UPDATE groups 
                    SET vacancy = CASE 
                                    WHEN vacancy > 0 THEN vacancy - 1 
                                    ELSE 0 
                                  END 
                    WHERE id = ?";

    if ($stmt_vacancy = $conn->prepare($sql_vacancy)) {
        $stmt_vacancy->bind_param("i", $group_id);
        $stmt_vacancy->execute();
        $stmt_vacancy->close();
    }
}




if (isset($_POST['set_speaker'])) {
    $selected_user_id = $_POST['selected_user'];
    $group_id = $_POST['group_id'];
    $remove_changelog = "\n" . date("d.m.Y") . " Ende Sprecher {$ag_complete[$group_id]['name']} ({$_SESSION['agent']})";
    $add_changelog = "\n" . date("d.m.Y") . " Start Sprecher {$ag_complete[$group_id]['name']} ({$_SESSION['agent']})";

    // 1. SQL-Anweisung zum Entfernen des alten AG-Sprechers der Gruppe und Historie aktualisieren
    $sql_remove_old_speaker = "UPDATE users 
                               SET sprecher = 0, 
                                   historie = CONCAT(COALESCE(historie, ''), ?)
                               WHERE sprecher = ?";
    if ($stmt = $conn->prepare($sql_remove_old_speaker)) {
        $stmt->bind_param("si", $remove_changelog, $group_id);
        $stmt->execute();
        $stmt->close();
    }

    // 2. SQL-Anweisung zum Setzen des neuen AG-Sprechers und Historie aktualisieren
    $sql_set_new_speaker = "UPDATE users 
                            SET sprecher = ?, 
                                historie = CONCAT(COALESCE(historie, ''), ?)
                            WHERE uid = ?";
    if ($stmt = $conn->prepare($sql_set_new_speaker)) {
        $stmt->bind_param("isi", $group_id, $add_changelog, $selected_user_id);
        $stmt->execute();
        $stmt->close();
    }
}



if (isset($_POST['save_vacancy'])) {
    $vacancy = intval($_POST['vacancy']);
    $group_id = intval($_POST['save_vacancy']);

    $sql = "UPDATE groups SET vacancy = ? WHERE id = ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ii", $vacancy, $group_id);
        $stmt->execute();
        $stmt->close();
    }
}










// Admin View Button


// Initialisiere die adminview_agedit-Variable, wenn sie nicht existiert
if (!isset($_SESSION['adminview_agedit'])) {
    $_SESSION['adminview_agedit'] = false;
}

// Verarbeite den Button-Klick zum Umschalten
if (isset($_POST['toggle_adminview'])) {
    // Umschalten von true auf false oder umgekehrt
    $_SESSION['adminview_agedit'] = !$_SESSION['adminview_agedit'];
}

// Überprüfe, ob NetzAG in der Session aktiviert ist
if ($_SESSION['NetzAG'] || $_SESSION['Vorstand'] || $_SESSION['TvK-Sprecher'] || $_SESSION['WEH-Sprecher']) {
    // Setze die Button-Farbe basierend auf dem Status von adminview_agedit
    $buttonColor = $_SESSION['adminview_agedit'] ? 'green' : 'red';
    $buttonText = $_SESSION['adminview_agedit'] ? 'Aktiviert' : 'Deaktiviert';

    // Zentrierte Anzeige des Umschalt-Buttons mit Beschriftung
    echo '<div style="display: flex; flex-direction: column; align-items: center; ">';
    
    // Weißer Text über dem Button
    echo '<p style="color: white; font-size: 30px;">Admin Modus</p>';
    
    // Anzeige des Umschalt-Buttons
    echo '<form method="post" action="">
            <button type="submit" name="toggle_adminview" style="background-color: ' . $buttonColor . '; color: white; padding: 10px 20px; margin-bottom: 50px; border: none; cursor: pointer; font-size: 20px;">
                ' . $buttonText . '
            </button>
          </form>';
    
    echo '</div>';
} else {
    // Falls NetzAG nicht aktiviert ist, wird adminview_agedit auf false gesetzt
    $_SESSION['adminview_agedit'] = false;
}


















// Den Benutzer authentifizieren
$uid = $_SESSION['uid'];  // Wir verwenden noch die Session für die Benutzer-ID


if (isset($_SESSION['adminview_agedit']) && $_SESSION['adminview_agedit'] == true) {
    // Admin View aktiviert: Alle aktiven Gruppen abfragen
    $query = "SELECT g.id, g.name, g.session, g.turm, g.vacancy
              FROM groups g
              WHERE active = TRUE 
              ORDER BY g.prio";
    $stmt = $conn->prepare($query);
} else {
    // Normale Abfrage: Gruppen des Benutzers abfragen
    $query = "SELECT g.id, g.name, g.session, u.sprecher, u.room, g.turm, g.vacancy
              FROM groups g 
              INNER JOIN users u ON FIND_IN_SET(g.id, u.groups)
              WHERE active = TRUE AND u.uid = ? AND u.pid IN (11,12) 
              ORDER BY g.prio";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $uid);  // Nur für den spezifischen Benutzer
}

$stmt->execute();
$result = $stmt->get_result();

// Variablen für Gruppen und Sprecherstatus initialisieren
$groups = [];
$sprecher = [];

while ($row = $result->fetch_assoc()) {
    $groups[] = $row;

    // Wenn der Benutzer der Sprecher für die Gruppe ist (u.sprecher entspricht der group_id)
    if ($row['sprecher'] == $row['id']) {
        $sprecher[] = $row['id']; // Füge die Gruppen-ID zum Sprecher-Array hinzu
    }

    // Wenn der Benutzer Sprecher der Gruppe 9 ist, auch Sprecher der Gruppe 63 machen
    if ($row['id'] == 9 && $row['sprecher'] == 9) {
        $sprecher[] = 63; // Füge auch Gruppe 63 zum Sprecher-Array hinzu
    }
}
$stmt->close();

// Berechnung für Zentrierung und Anordnung der Gruppen
$numGroups = count($groups);
$groupClass = ($numGroups == 1) ? 'single-group' : 'multiple-groups';













// Ausgabe der Gruppen in flexiblen Boxen
echo '<div style="display:flex; flex-wrap:wrap; justify-content:center; margin:10px; padding:0px 60px 60px 60px;">';

foreach ($groups as $index => $group) {    
    if ((!$_SESSION["NetzAG"] && !$_SESSION["Webmaster"]) && ($group['id'] === 7 || $group['id'] === 8)) {
        continue;
    } 
    if ($group['id'] === 1) {
        continue;
    }
    if ($group['id'] === 19) {
        continue;
    }

    $randfarbe = [
        "weh" => "#11a50d",
        "tvk" => "#E49B0F"
    ][$group["turm"]] ?? "white";   

    echo '<div class="ag-box" style="border: 20px outset ' . $randfarbe . ';">';

    // Gruppenname in der Box
    echo '<div style="flex-grow: 1;">';  // Flex-grow sorgt dafür, dass der Gruppenname und Mitgliederliste den freien Platz einnehmen
    echo '<h2 style="font-size:28px; font-weight:bold; color:white; text-align:center; margin-bottom:10px;">' . htmlspecialchars($group['name']) . '</h2>';

    if (!empty($group["vacancy"])) {
        echo '<p style="text-align:center; color:gold; margin-top:20px; font-size:20px;">';
        echo 'Open Spots: ' . intval($group["vacancy"]);
        echo '</p>';
    }    


    // Mitglieder der Gruppe anzeigen
    $query = "SELECT u.uid, u.firstname, u.lastname, u.turm, u.room, u.sprecher FROM users u 
              WHERE FIND_IN_SET(?, u.groups) 
              ORDER BY FIELD(turm, 'weh', 'tvk'), room";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $group['id']);
    $stmt->execute();
    $result = $stmt->get_result();

    // Ausgabe der Mitglieder als Liste
    echo "<table style='width: 100%; text-align: center;'>";
    
    while ($row = $result->fetch_assoc()) {
        // Formatierung für Turm
        $formatted_turm = ($row['turm'] == 'tvk') ? 'TvK' : strtoupper($row['turm']);
        $room_color = ($row['turm'] == 'tvk') ? '#E49B0F' : '#11a50d'; // Farbzuordnung für den Turm
    
        // Überprüfung, ob der Benutzer Sprecher der Gruppe ist
        $isSpeaker = ($row['sprecher'] == $group['id']);

        if ($isSpeaker) {
            $name_output = '<img src="images/ags/vorstand.png" width="22" height="22" alt="Sprecher Icon" style="vertical-align: center; margin-right: 5px;">'  
                           . '<span class="white-text">' 
                           . explode(' ', $row['firstname'])[0] . ' ' 
                           . explode(' ', $row['lastname'])[0] 
                           . '</span>';
        } else {
            $name_output = '<span class="white-text">' 
                           . explode(' ', $row['firstname'])[0] . ' ' 
                           . explode(' ', $row['lastname'])[0] 
                           . '</span>';
        }              

        // Formatierung der Raum- und Turm-Anzeige
        if ($group['id'] == 24) {
            $output = '<strong style="color:' . $room_color . ';">' . $formatted_turm . '</strong>';
        } else {
            $output = '<strong style="color:' . $room_color . ';">' . $formatted_turm . ' - ' . $row['room'] . '</strong>';
        }

        // Ausgabe der Zeile mit POST-Formular für den Namen
        echo '<tr>';
        echo '<td style="padding:4px 8px;">' . $output . '</td>';
        echo '<td style="padding:4px 8px;">
                <form method="post" action="">
                    <input type="hidden" name="group_id" value="' . htmlspecialchars($group['id']) . '">
                    <input type="hidden" name="selected_user" value="' . htmlspecialchars($row['uid']) . '">
                    <button type="submit" style="background:none; border:none; font-size:20px; cursor:pointer;">' . $name_output . '</button>
                </form>
              </td>';
        echo '</tr>';
    }   
    echo "</table>";
    echo '</div>';  // Ende der flex-grow div für Inhalte
    
    // Container für die Buttons (Flexbox)
    echo "<div style='display: flex; justify-content: center; gap: 10px; margin-top: 10px;'>";

    // Option zum Hinzufügen eines Benutzers, falls berechtigt
    if (in_array($group['id'], $sprecher) || $_SESSION['adminview_agedit']) {
        echo "<form method='post' action=''>
                <input type='hidden' name='group_id' value='{$group['id']}'>
                <button type='submit' name='add_user' class='sml-center-btn'>Benutzer hinzufügen</button>
            </form>";
    }

    // Option zum Austreten
    if (!in_array($group['id'], $sprecher) && !$_SESSION['adminview_agedit']) {
        echo "<form method='post' action=''>
                <input type='hidden' name='selected_user' value='{$_SESSION["user"]}'>
                <input type='hidden' name='group_id' value='{$group['id']}'>
                <input type='hidden' name='close_popup' value='true'>
                <button type='submit' name='leave_group' class='sml-center-btn'>Austreten</button>
            </form>";
    }

    // Neuer Button für "Vacancy"
    echo "<form method='post' action=''>
            <input type='hidden' name='group_id' value='{$group['id']}'>
            <button type='submit' name='edit_vacancy' class='sml-center-btn'>Vacancy bearbeiten</button>
        </form>";

    echo "</div>";


    echo "</div>";  // Ende der Gruppen-Box
    $stmt->close();
}

echo "</div>";  // Ende des Container-Divs


























if (isset($_POST['add_user'])) {
    $group_id = $_POST['group_id'];

    // Start des Formulars und der Overlay-Box
    echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');

    echo '<div style="text-align: center;">';
    echo '<form method="post" action="">';
    ?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Dynamische Benutzersuche</title>
        <style>
            #searchInput {
                display: block;
                margin: 20px auto;
                height: 30px;
                font-size: 25px;
            }
            .center-table {
                margin-left: auto;
                margin-right: auto;
            }
            .disabled {
                color: gray;
                text-decoration: none;
            }
            .white-text {
                color: white;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
        <input type='text' id='searchInput' placeholder='Suche nach User...' onkeyup='searchUser(this.value)'>

        <div style='margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;'>
            <table class='center-table'>
                <thead id="tableHeader" style="display:none;">
                    <tr><th>Name</th><th>Raum</th></tr>
                </thead>
                <tbody id="userTableBody">
                    <!-- Dynamischer Inhalt wird hier eingefügt -->
                </tbody>
            </table>
        </div>

        <script>
        function searchUser(searchValue) {
            fetch('?search=' + encodeURIComponent(searchValue))
            .then(response => response.json())
            .then(data => {
                const tableBody = document.getElementById('userTableBody');
                const tableHeader = document.getElementById('tableHeader');
                tableBody.innerHTML = '';  // Bestehende Tabelle leeren

                // Wenn keine Daten vorhanden sind, verstecke den Header
                if (Object.keys(data).length === 0) {
                    tableHeader.style.display = 'none';
                    return;
                }

                // Wenn es Ergebnisse gibt, zeige den Header an
                tableHeader.style.display = 'table-header-group';

                Object.keys(data).forEach(uid => {
                    data[uid].forEach(user => {
                        const row = document.createElement('tr');
                        const cellName = document.createElement('td');
                        const cellRoom = document.createElement('td');

                        const link = document.createElement('span');
                        link.textContent = user.name;
                        link.style.textDecoration = 'none';  // Keine Unterstreichung

                        // Überprüfen, ob der Nutzer in der Gruppe ist
                        const userGroups = user.groups.split(',');
                        if (userGroups.includes('<?= $group_id ?>')) {
                            // Nutzer bereits in der Gruppe -> ausgegraut und nicht anklickbar
                            link.classList.add('disabled'); // Grauer Text
                        } else {
                            // Nutzer nicht in der Gruppe -> anklickbar
                            link.classList.add('white-text');
                            link.style.cursor = 'pointer';
                            link.onclick = function() { submitForm(user.uid, '<?= $group_id ?>'); };
                        }

                        cellName.appendChild(link);
                        cellRoom.textContent = user.room == 0 ? user.oldroom : user.room;

                        // Raumfarbe anhand des Turms bestimmen
                        const roomColor = user.turm === 'tvk' ? '#E49B0F' : '#11a50d';
                        cellRoom.style.color = roomColor;

                        row.appendChild(cellName);
                        row.appendChild(cellRoom);
                        tableBody.appendChild(row);
                    });
                });
            });
        }

        function submitForm(userId, groupId) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = '';

            const actionField = document.createElement('input');
            actionField.type = 'hidden';
            actionField.name = 'set_group';
            actionField.value = 'true';
            form.appendChild(actionField);

            const userField = document.createElement('input');
            userField.type = 'hidden';
            userField.name = 'selected_user';
            userField.value = userId;
            form.appendChild(userField);

            const groupField = document.createElement('input');
            groupField.type = 'hidden';
            groupField.name = 'group_id';
            groupField.value = groupId;
            form.appendChild(groupField);

            const closePopupField = document.createElement('input');
            closePopupField.type = 'hidden';
            closePopupField.name = 'close_popup';
            closePopupField.value = 'true';
            form.appendChild(closePopupField);

            document.body.appendChild(form);
            form.submit();
        }
        </script>
    </body>
    </html>

    <?php
    echo '</form>';
    echo '</div>';
}









if (isset($_POST['edit_vacancy'])) {
    $group_id = $_POST['group_id'];

    // Vacancy für die ausgewählte Gruppe aus $ag_complete holen
    $current_vacancy = isset($ag_complete[$group_id]['vacancy']) ? intval($ag_complete[$group_id]['vacancy']) : 0;

    // Start des Formulars und der Overlay-Box
    echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');

    // Formular-Inhalt
    echo '<div style="text-align: center;">';
    echo '<form method="post" action="">';

    // Eingabefeld für Vacancy
    echo '<br>';
    echo '<label for="vacancy" style="display: block; margin-bottom: 10px; font-size: 30px; color: white;">Anzahl der offenen Stellen:</label>';
    echo '<br>';
    echo '<input type="number" id="vacancy" name="vacancy" value="' . $current_vacancy . '" min="0" max="10" step="1" style="text-align: center; font-size: 30px; padding: 10px; width: 100px; appearance: textfield;">';

    // Speichern-Button
    echo '<br><br>';
    echo '<div style="display: flex; justify-content: center; align-items: center; margin-top: 20px;">';
    echo '<button type="submit" name="save_vacancy" value="' . $group_id . '" class="center-btn">Speichern</button>';
    echo '</div>';
    

    echo '</form>';
    echo '</div>';
}










// Überprüfen, ob ein Benutzername geklickt wurde und die Modal-Box anzeigen
if (isset($_POST['selected_user']) && isset($_POST['group_id']) && !isset($_POST['close_popup'])) {
    $selected_user_id = $_POST['selected_user'];
    $group_id = $_POST['group_id'];
    
    // Hier würden wir den Namen des Benutzers anhand der UID abrufen
    $query = "SELECT name, room, turm FROM users WHERE uid = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $selected_user_id);
    $stmt->execute();
    $stmt->bind_result($selected_user_name, $selected_user_room, $selected_user_turm);
    $stmt->fetch();
    $stmt->close();
    
    $turm = ($selected_user_turm === "tvk") ? "TvK" : (($selected_user_turm === "weh") ? "WEH" : $selected_user_turm);

    // Start des Formulars und der Overlay-Box
    echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');

    echo '<div style="text-align: center;">';
    echo '<form method="post">';
    
    // Den Namen und den Raum des angeklickten Users in 40px weißer Schrift anzeigen
    echo '<div style="font-size: 40px; color: white;">' . $turm . ' ' . $selected_user_room . '<br>' . $selected_user_name . '</div>';

    if (in_array($group_id, $sprecher) && $uid == $selected_user_id) {
        // Der Benutzer ist Sprecher und versucht, sich selbst auszutreten
        echo '
            <div style="text-align: center; margin-top: 20px;">
                <p style="color: red;">Du bist der aktuelle Sprecher. Du musst zuerst einen neuen Sprecher ernennen, bevor du austreten kannst.</p>
            </div>
        ';
    }
    // Überprüfung, ob der Benutzer der Sprecher ist oder Adminansicht aktiv ist
    elseif (in_array($group_id, $sprecher) || $_SESSION['adminview_agedit']) {
        // Buttons für "Entfernen", "Zum Sprecher ernennen" und (nur für Admins) "User bearbeiten"
        echo '
            <div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px;">
                <form method="post" action="">
                    <input type="hidden" name="selected_user" value="' . $selected_user_id . '">
                    <input type="hidden" name="group_id" value="' . $group_id . '">
                    <input type="hidden" name="close_popup" value="true">
                    <button type="submit" name="remove_user" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Entfernen</button>
                </form>
                <form method="post" action="">
                    <input type="hidden" name="selected_user" value="' . $selected_user_id . '">
                    <input type="hidden" name="group_id" value="' . $group_id . '">
                    <input type="hidden" name="close_popup" value="true">
                    <button type="submit" name="set_speaker" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Zum Sprecher ernennen</button>
                </form>';
    
        // Nur wenn Adminansicht aktiv ist, füge den "User bearbeiten" Button hinzu
        if ($_SESSION['adminview_agedit']) {
            echo '
            <form action="User.php" method="post" target="_blank" style="display: inline-flex; align-items: center; justify-content: center;">
                <input type="hidden" name="id" value="' . $selected_user_id . '">
                <button class="sml-center-btn" type="submit" style="padding: 10px 20px;">User bearbeiten</button>
            </form>
        ';
        
        }
    
        echo '</div>';  // Ende des flex-Containers
    } 
    // Überprüfung, ob der aktuelle Benutzer sich selbst ausgewählt hat
    elseif ($uid == $selected_user_id) {
        // Button zum Austreten
        echo '
            <div style="display: flex; justify-content: center; margin-top: 20px;">
                <form method="post" action="">
                    <input type="hidden" name="selected_user" value="' . $selected_user_id . '">
                    <input type="hidden" name="group_id" value="' . $group_id . '">
                    <input type="hidden" name="close_popup" value="true">
                    <button type="submit" name="leave_group" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Austreten</button>
                </form>
            </div>
        ';
    }
    
    

    // Sonst nichts anzeigen
    echo '</form>';
    echo '</div>';
}





















}
    
else {
    header("Location: denied.php");
}
$conn->close();