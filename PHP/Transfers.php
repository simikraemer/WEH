
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
          $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                  (name LIKE '%$searchTerm%' OR 
                   username LIKE '%$searchTerm%' OR 
                   geburtsort LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (uid = '$searchTerm') OR 
                   (oldroom = '$searchTerm'))
                  AND pid IN (11,12,13,64)";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                    (name LIKE '%$searchTerm%' OR 
                     username LIKE '%$searchTerm%' OR 
                     email LIKE '%$searchTerm%' OR 
                     aliase LIKE '%$searchTerm%' OR 
                     geburtsort LIKE '%$searchTerm%')
                   AND pid IN (11,12,13,64)";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm);
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
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (!(auth($conn) && $_SESSION['valid'] && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"]))) {
    header("Location: denied.php");
    exit;
}
load_menu();



if (isset($_POST['save_transfer_id'])) {
    

    // Alle übergebenen Werte abfangen
    $transfer_id = $_POST['transfer_id'];
    $selected_user = $_POST['selected_user']; // Benutzer-ID
    $konto = $_POST['konto_update']; // Konto
    $kasse = $_POST['kasse']; // Kasse
    $betrag = $_POST['betrag']; // Betrag (als Dezimalzahl mit Punkt)
    $beschreibung = $_POST['beschreibung']; // Neue Beschreibung
    $zeit = time(); // Neuer Timestamp (aktuelle Zeit)
    $agent = $_SESSION['uid']; // Agent, der die Änderung durchführt (aus Session)

    // Alte Werte abfangen
    $ausgangs_uid = $_POST['ausgangs_uid'];
    $ausgangs_konto = $_POST['ausgangs_konto'];
    $ausgangs_kasse = $_POST['ausgangs_kasse'];
    $ausgangs_betrag = $_POST['ausgangs_betrag'];
    $ausgangs_beschreibung = $_POST['ausgangs_beschreibung'];
    $ausgangs_pfad = $_POST['ausgangs_pfad'];

    
    if (isset($_FILES['rechnung_upload']) && $_FILES['rechnung_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'rechnungen/';
        $transfer_id = intval($transfer_id); // falls $transfer_id nicht garantiert integer ist
        $tmp_name = $_FILES['rechnung_upload']['tmp_name'];
        $original_name = $_FILES['rechnung_upload']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        // Sicherstellen, dass nur erlaubte Endungen akzeptiert werden
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        if (in_array($extension, $allowed_extensions)) {
            $new_filename = $transfer_id . '.' . $extension;
            $target_path = $upload_dir . $new_filename;

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true); // Ordner erstellen, falls nicht vorhanden
            }

            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path;
            } else {
                echo "Fehler beim Verschieben der Datei.";
            }
        } else {
            echo "Ungültiges Dateiformat.";
        }
    }

    function formatBetrag($betrag) {
        // Wenn Komma vorhanden, ist Punkt Tausendertrenner
        if (strpos($betrag, ',') !== false) {
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
        }
        return $betrag;
    }
        
            
    
    // Beispiele
    $formatierter_ausgangsbetrag = formatBetrag($ausgangs_betrag);
    $formatierter_betrag = formatBetrag($betrag);
       
    
    // Variable für Änderungen
    $has_changes = false;
    $changelog = "";

    // Überprüfen, ob Änderungen vorgenommen wurden
    if ($selected_user != $ausgangs_uid) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Benutzer: von UID " . $ausgangs_uid . " auf UID " . $selected_user . "\n";
    }
    if ($konto != $ausgangs_konto) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Konto: von " . $ausgangs_konto . " auf " . $konto . "\n";
    }
    if ($kasse != $ausgangs_kasse) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Kasse: von " . $ausgangs_kasse . " auf " . $kasse . "\n";
    }
    if ($betrag != $ausgangs_betrag) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }

        $changelog .= "Betrag: von " . $formatierter_ausgangsbetrag . " € auf " . $formatierter_betrag . " €\n";
    }
    if ($beschreibung != $ausgangs_beschreibung) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Beschreibung: von \"" . $ausgangs_beschreibung . "\" auf \"" . $beschreibung . "\"\n";
    }    
    if ($pfad != $ausgangs_pfad) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
    
        if (empty($ausgangs_pfad)) {
            $changelog .= "Rechnung: neu hochgeladen als \"$pfad\"\n";
        } else {
            $changelog .= "Rechnung: geändert von \"$ausgangs_pfad\" zu \"$pfad\"\n";
        }
    }
        


    // Update-Abfrage nur durchführen, wenn Änderungen vorhanden sind
    if ($has_changes) {
      $query = "UPDATE transfers 
      SET uid = ?, 
          konto = ?, 
          kasse = ?, 
          betrag = ?, 
          beschreibung = ?,
          agent = ?, 
          pfad = ?,
          changelog = CONCAT(IFNULL(changelog, ''), IF(changelog IS NOT NULL, '\n\n', ''), ?) 
      WHERE id = ?";

        $stmt = $conn->prepare($query);

        // Bindet die Parameter an die Abfrage
        $stmt->bind_param("iiidsissi", $selected_user, $konto, $kasse, $formatierter_betrag, $beschreibung, $agent, $pfad, $changelog, $transfer_id);

        // Führt das Update aus
        if ($stmt->execute()) {
          echo '<p style="color: green; text-align: center;">Der Transfer wurde erfolgreich aktualisiert.</p>';
        } else {
          echo '<p style="color: red; text-align: center;">Fehler beim Aktualisieren des Transfers: ' . $conn->error . '</p>';
        }

        // Schließt das Statement
        $stmt->close();
    } else {
      echo '<p style="color: green; text-align: center;">Keine Änderungen vorgenommen.</p>';
    }
}

if (isset($_POST['transfer_upload_speichern'])) {
    echo '<pre style="background-color: #1f1f1f; color: #fff; padding: 10px; border: 1px solid #444;">';
    echo "=== POST-Daten ===\n";
    print_r($_POST);

    echo "\n=== FILE-Daten ===\n";
    print_r($_FILES);
    echo '</pre>';
}



if (isset($_POST['kasse_id'])) {
    $_SESSION['kasse_id'] = $_POST['kasse_id'];
}

if (!isset($_SESSION['kasse_id'])) {
    $_SESSION['kasse_id'] = 72; // Standard: Netzkonto
}

$kid = $_SESSION['kasse_id'];
$zeit = time();
$semester_start = unixtime2startofsemester($zeit);

echo '<form method="post" class="kasse-form">';

// erste Zeile
echo '<div class="kasse-row">';
$buttons_1 = [
    ['id' => 72, 'label' => 'Netzkonto'],
    ['id' => 69, 'label' => 'PayPal'],
    ['id' => 92, 'label' => 'Hauskonto']
];
foreach ($buttons_1 as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:20px; width:150px;">' . $btn['label'] . '</button>';
}
echo '</div>';

// zweite Zeile
echo '<div class="kasse-row">';
$buttons_2 = [
    ['id' => 73, 'label' => 'Netzbarkasse I'],
    ['id' => 74, 'label' => 'Netzbarkasse II'],
    ['id' => 93, 'label' => 'Kassenwart I'],
    ['id' => 94, 'label' => 'Kassenwart II'],
    ['id' => 95, 'label' => 'Tresor']
];
foreach ($buttons_2 as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:15px; width:130px;">' . $btn['label'] . '</button>';
}
echo '</div>';

echo '</form>';



echo '<hr>';



echo '<form method="post" enctype="multipart/form-data">';
echo '<div class="transfer-form-grid">';

// Zeile 1
echo '<input type="number" name="betrag_neu" placeholder="Betrag (€)" step="0.01">';
echo '<input type="text" name="beschreibung_neu" placeholder="Beschreibung">';
echo '<input type="file" name="rechnung_neu" accept=".pdf,.jpg,.jpeg,.png,.gif">';

// Zeile 2
echo '<div style="display: flex; gap: 6px; align-items: center;">';
echo '<input type="text" name="usersuche" id="usersuche" placeholder="Nutzer suchen..." oninput="sucheUser(this.value)" style="flex:1;">';
echo '<div style="display: flex; gap: 4px;">';
echo '<button type="button" class="dummy-btn" onclick="setDummyUser(472, \'NetzAG Dummy\')" title="NetzAG Dummy">NE</button>';
echo '<button type="button" class="dummy-btn" onclick="setDummyUser(492, \'Haussprecher Dummy\')" title="Haussprecher Dummy">HS</button>';
echo '<button type="button" class="dummy-btn" onclick="setDummyUser(2524, \'PayPal Dummy\')" title="PayPal Dummy">PP</button>';
echo '</div>';
echo '</div>';

echo '<div id="usersuchergebnisse" style="padding: 6px; background-color: #2a2a2a; border: 1px solid #444; min-height: 75px;"></div>';
echo '<button type="submit" name="transfer_upload_speichern">Speichern</button>';
echo '<input type="hidden" name="uid_neu" id="uid_neu">';

echo '</div>';
echo '</form>';


echo '<hr>';


$sql = "
    SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
           t.tstamp, t.beschreibung, t.betrag, t.pfad
    FROM transfers t
    JOIN users u ON t.uid = u.uid
    WHERE t.tstamp >= ? AND t.kasse = ?
    ORDER BY t.tstamp DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ii", $semester_start, $kid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Tabelle mit sortierbaren Spalten
echo '<table class="transfer-table" id="transfers-table">';
echo '<thead>
<tr>
    <th onclick="sortTable(0, this)">Datum</th>
    <th onclick="sortTable(1, this)">Name</th>
    <th onclick="sortTable(2, this)">Raum</th>
    <th onclick="sortTable(3, this)">Beschreibung</th>
    <th onclick="sortTable(4, this)">Betrag</th>
    <th onclick="sortTable(5, this)">Rechnung</th>
</tr>
</thead>';
echo '<tbody>';

while ($row = mysqli_fetch_assoc($result)) {
    $name = cutName($row['firstname'], $row['lastname']);
    $tstamp = $row['tstamp'];
    $datum = date("d.m.Y", $tstamp);
    $betrag = number_format($row['betrag'], 2, ',', '.');
    $uid = $row['uid'];
    $room = $row['room'];
    $turm = formatTurm($row['turm']);

    echo "<tr data-id=\"{$row['id']}\" onclick=\"submitEditTransfer(this)\">";
    echo "<td data-sort=\"$tstamp\">$datum</td>";
    echo "<td>$name [$uid]</td>";
    echo "<td>$room [$turm]</td>";
    echo "<td>{$row['beschreibung']}</td>";
    echo "<td data-sort=\"{$row['betrag']}\">$betrag €</td>";

    if (!empty($row['pfad'])) {
        $safe_path = htmlspecialchars($row['pfad'], ENT_QUOTES, 'UTF-8');
        echo "<td onclick=\"event.stopPropagation(); window.open('$safe_path', '_blank', 'noopener');\">Zur Rechnung ➡️</td>";
    } else {
        echo "<td></td>";
    }

    echo "</tr>";
}

    

echo '</tbody></table>';




if (isset($_POST['edit_transfer'])) {
    
    $editable = (isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true) || (isset($_SESSION["Vorstand"]) && $_SESSION["Vorstand"] === true);
    
    // Abfrage für die Transfer-Informationen
    $query = "SELECT t.uid, t.konto, t.kasse, t.betrag, t.tstamp, t.beschreibung, t.changelog, t.pfad FROM transfers t WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_POST['transfer_id']);
    $stmt->execute();
    $stmt->bind_result(
        $selected_transfer_uid, 
        $selected_transfer_konto, 
        $selected_transfer_kasse, 
        $selected_transfer_betrag, 
        $selected_transfer_tstamp,
        $selected_transfer_beschreibung,
        $selected_transfer_changelog,
        $selected_transfer_pfad
    );
    $stmt->fetch();
    $stmt->close();

    // Arrays für Konto und Kasse
    $konto_options = [
        0 => "Kaution",
        1 => "Netzbeitrag",
        2 => "Hausbeitrag",
        3 => "Druckauftrag",
        4 => "Einzahlung",
        5 => "Getränk",
        6 => "Waschmaschine",
        7 => "Spülmaschine"
    ];
    
    $kasse_options = [
        0 => "Haus",
        1 => "NetzAG(bar)-I",
        2 => "NetzAG(bar)-II",
        3 => "imaginäre Schuldbuchung",
        4 => "Netzkonto (alt)",
        5 => "imaginäre Rückzahlung",
        69 => "PayPal",
        72 => "Netzkonto",
        92 => "Hauskonto"
    ];

    // Deutsche Zeitformatierung (Timestamp in Unixtime)
    $formatted_date = date("d.m.Y H:i", $selected_transfer_tstamp);

    // Start des Formulars und der Overlay-Box
    echo ('<div class="overlay"></div>
    <div class="anmeldung-form-container form-container">
      <form method="post">
          <button type="submit" name="close" value="close" class="close-btn">X</button>
      </form>
    <br>');


    echo '<div style="text-align: center;">';

    // Wenn die Felder editierbar sind, erstelle ein Formular, sonst nur Anzeige
    if ($editable) {
      echo '<form method="post" enctype="multipart/form-data">';
      echo '<input type="hidden" name="transfer_id" value="'.$_POST['transfer_id'].'">';
      echo '<input type="hidden" name="ausgangs_betrag" value="'.number_format($selected_transfer_betrag, 2, ".", "").'">';
      echo '<input type="hidden" name="ausgangs_uid" value="'.$selected_transfer_uid.'">';
      echo '<input type="hidden" name="ausgangs_konto" value="'.$selected_transfer_konto.'">';
      echo '<input type="hidden" name="ausgangs_kasse" value="'.$selected_transfer_kasse.'">';
      echo '<input type="hidden" name="ausgangs_beschreibung" value="'.htmlspecialchars($selected_transfer_beschreibung).'">';
      echo '<input type="hidden" name="ausgangs_pfad" value="'.htmlspecialchars($selected_transfer_pfad).'">';
    }
    
    echo '<div style="text-align: center; color: lightgrey;">';
    echo 'Transfer ID: <span style="color:white;">'.$_POST['transfer_id'].'</span>';
    echo '</div>';
    echo '<br><br>';
    

    // Benutzerinformationen oder Auswahl anzeigen
    if (!$editable) {
        $query_user = "SELECT name, room, turm FROM users WHERE uid = ?";
        $stmt_user = $conn->prepare($query_user);
        $stmt_user->bind_param("i", $selected_transfer_uid);
        $stmt_user->execute();
        $stmt_user->bind_result($selected_user_name, $selected_user_room, $selected_user_turm);
        $stmt_user->fetch();
        $stmt_user->close();

        // Formatierung der Turm-Anzeige
        $formatted_turm = ($selected_user_turm == 'tvk') ? 'TvK' : strtoupper($selected_user_turm);

        // Benutzerinformationen anzeigen
        echo '<label for="user_info" style="color:lightgrey;">Benutzerinformationen:</label><br>';
        echo '<p style="color:white !important;">' . htmlspecialchars($selected_user_name) . ' [' . $formatted_turm . ' ' . htmlspecialchars($selected_user_room) . ']</p><br>';
    } else {
        // Wenn die Felder editierbar sind, Dropdown für Benutzer anzeigen
        echo '<label for="selected_user" style="color:lightgrey;">Benutzer auswählen:</label><br>';
        echo '<select name="selected_user" id="selected_user" style="margin-top: 10px; padding: 5px; text-align: center; text-align-last: center; display: block; margin-left: auto; margin-right: auto;">
                <option value="" disabled selected>Wähle einen Benutzer</option>';
        echo '<option value="472" ' . (472 == $selected_transfer_uid ? 'selected' : '') . '>NetzAG-Dummy</option>';
        echo '<option value="492" ' . (492 == $selected_transfer_uid ? 'selected' : '') . '>Vorstand-Dummy</option>';
        
        // Abfrage, um alle Benutzer zu laden
        $sql = "SELECT uid, name, room, turm 
                FROM users 
                WHERE pid = 11 
                ORDER BY FIELD(turm, 'weh', 'tvk'), room";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
        
        // Benutzer in das Dropdown-Menü einfügen
        while (mysqli_stmt_fetch($stmt)) {
            $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
            echo '<option value="' . $uid . '" ' . ($uid == $selected_transfer_uid ? 'selected' : '') . '>' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
        }
        
        echo '</select><br><br>';
        
    }

    echo '<br>';

    // Beschreibung (editierbar oder nicht)
    echo '<label style="color:lightgrey;">Beschreibung:</label><br>';
    if (!$editable) {
        // Prüfen, ob die Beschreibung vorhanden ist, um die Deprecated-Fehlermeldung zu vermeiden
        echo '<p style="color:white !important;">'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'</p><br>';
    } else {
        echo '<input type="text" name="beschreibung" value="'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'" style="text-align: center; width: 80%;"><br><br>';
    }

    echo '<br>';


    echo '<div style="display: flex; gap: 20px; justify-content: space-between; align-items: flex-start;">'; // Flexbox-Container

    // Konto anzeigen (Integer -> Text)
    echo '<div style="flex: 1; max-width: 100%;">'; // Flex-Item für Konto
    echo '<label style="color:lightgrey;">Konto:</label><br>';
    $konto_display = isset($konto_options[$selected_transfer_konto]) ? $konto_options[$selected_transfer_konto] : "Undefiniertes Konto";
    if (!$editable) {
        echo '<p style="color:white !important;">'.$konto_display.'</p><br>';
    } else {
        echo '<select name="konto_update" style="text-align: center; width: 100%;">'; // Volle Breite
        foreach ($konto_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_konto ? 'selected' : '').'>'.$value.'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>'; // Ende von Konto Flex-Item
    
    // Kasse anzeigen (Integer -> Text)
    echo '<div style="flex: 1; max-width: 100%;">'; // Flex-Item für Kasse
    echo '<label style="color:lightgrey;">Kasse:</label><br>';
    $kasse_display = isset($kasse_options[$selected_transfer_kasse]) ? $kasse_options[$selected_transfer_kasse] : "Undefinierte Kasse";
    if (!$editable) {
        echo '<p style="color:white !important;">'.$kasse_display.'</p><br>';
    } else {
        echo '<select name="kasse" style="text-align: center; width: 100%;">'; // Volle Breite
        foreach ($kasse_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_kasse ? 'selected' : '').'>'.$value.'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>'; // Ende von Kasse Flex-Item
    
    echo '</div>'; // Ende Flexbox-Container
    
    

    echo '<br>';

    // Betrag anzeigen
    echo '<label style="color:lightgrey;">Betrag:</label><br>';
    if (!$editable) {
      echo '<p style="color:white !important;">'.number_format($selected_transfer_betrag, 2, ",", ".").' €</p><br>';
    } else {
        echo '<input type="text" name="betrag" value="'.number_format($selected_transfer_betrag, 2, ",", ".").'" style="text-align: center;"><br><br>';
    }

    echo '<br>';

    if (!empty($selected_transfer_pfad)) {
        $safe_path = htmlspecialchars($selected_transfer_pfad, ENT_QUOTES, 'UTF-8');
        echo '<label style="color:lightgrey;"><a href="' . $safe_path . '" target="_blank" style="color: lightgrey;">Rechnung: ➡️</a></label><br>';
    } else {
        echo '<label style="color:lightgrey;">Rechnung</label><br>';
    }
        

    if ($editable) {
        // Upload-Feld für neue Rechnung
        echo '<input type="file" name="rechnung_upload" accept=".pdf,.jpg,.jpeg,.png,.gif" style="margin-top: 8px; color: white;"><br><br>';
    }
        


    // Changelog anzeigen (nicht editierbar) in einem optisch ansprechenden Feld
    if ($editable) {
      echo '<br>';
      echo '<label style="color:lightgrey;">Changelog:</label><br><br>';
      
      // Innerer Container im Konsolenstil mit monospace-Schrift
      echo '<div style="background-color: darkblue; color: white; font-family: monospace; padding: 10px; display: inline-block; text-align: center; width: calc(100% - 30px); max-height: 200px; overflow-y: auto; box-sizing: border-box;">'; 
      
      // Changelog ohne zusätzliche Zeilenumbrüche
      echo '<p style="margin: 0; line-height: 1.4; font-size: 14px; white-space: pre-wrap;">'.(!is_null($selected_transfer_changelog) ? htmlspecialchars($selected_transfer_changelog) : 'Kein Changelog verfügbar').'</p>';
      
      echo '</div>'; // Innerer Container Ende
      echo '<br>';
    }
  
  
  


    // Falls editierbar, Speicher-Button anzeigen und alle Eingaben an POST übergeben
    if ($editable) {
        echo '<div style="display: flex; justify-content: center; margin-top: 20px;">';
        echo '<button type="submit" name="save_transfer_id" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Speichern</button>';
        echo '</form>'; // Hier endet das Hauptformular
        echo '</div>';
    }

    echo '</div>';

}



?>
<script>
let sortDirections = {};
let lastSortedColumn = null;

function sortTable(colIndex, headerEl) {
    const table = document.getElementById("transfers-table");
    const rows = Array.from(table.tBodies[0].rows);
    const dir = sortDirections[colIndex] = !sortDirections[colIndex];

    rows.sort((a, b) => {
        const aVal = a.cells[colIndex].dataset.sort || a.cells[colIndex].innerText;
        const bVal = b.cells[colIndex].dataset.sort || b.cells[colIndex].innerText;
        const aNum = parseFloat(aVal.replace(',', '.'));
        const bNum = parseFloat(bVal.replace(',', '.'));
        const cmp = (!isNaN(aNum) && !isNaN(bNum)) ? (aNum - bNum) : aVal.localeCompare(bVal);
        return dir ? cmp : -cmp;
    });

    for (const row of rows) table.tBodies[0].appendChild(row);

    // Pfeile anzeigen
    const headers = table.tHead.rows[0].cells;
    for (let i = 0; i < headers.length; i++) {
        headers[i].innerText = headers[i].innerText.replace(/[\u25B2\u25BC]/g, '');
    }
    headerEl.innerText += dir ? ' ▲' : ' ▼';
}

function submitEditTransfer(row) {
    const id = row.dataset.id;
    const form = document.createElement('form');
    form.method = 'POST';

    const editFlag = document.createElement('input');
    editFlag.type = 'hidden';
    editFlag.name = 'edit_transfer';
    editFlag.value = '1'; // bool-like

    const transferId = document.createElement('input');
    transferId.type = 'hidden';
    transferId.name = 'transfer_id';
    transferId.value = id;

    form.appendChild(editFlag);
    form.appendChild(transferId);
    document.body.appendChild(form);
    form.submit();
}

function sucheUser(term) {
    const ergebnisContainer = document.getElementById('usersuchergebnisse');
    const hiddenUidField = document.getElementById('uid_neu');

    if (term.trim() === '') {
        ergebnisContainer.innerHTML = '';
        hiddenUidField.value = '';
        return;
    }

    fetch('?search=' + encodeURIComponent(term))
        .then(response => response.json())
        .then(data => {
            ergebnisContainer.innerHTML = '';
            let count = 0;
            const maxResults = 3;

            for (const uid in data) {
                if (count >= maxResults) break;

                const userInfo = data[uid][0];
                let turm = userInfo.turm?.trim().toUpperCase() || '';
                if (turm === 'tvk') {
                    turm = 'TvK';
                }

                const displayText = `${userInfo.name} (${turm} ${userInfo.room})`;

                const div = document.createElement('div');
                div.textContent = displayText;
                div.style.padding = '4px 0';
                div.style.borderBottom = '1px solid #444';
                div.style.cursor = 'pointer';

                div.onclick = () => {
                    document.getElementById('usersuche').value = displayText;
                    hiddenUidField.value = uid;
                    ergebnisContainer.innerHTML = '';
                };

                ergebnisContainer.appendChild(div);
                count++;
            }

            if (count === 0) {
                ergebnisContainer.innerHTML = '<div style="color: #888;">Keine Ergebnisse</div>';
            }
        });
}

function setDummyUser(uid, name) {
    document.getElementById('uid_neu').value = uid;
    document.getElementById('usersuche').value = name;
    document.getElementById('usersuchergebnisse').innerHTML = '';
}

</script>
