
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
                   (uid = '$searchTerm') 
                  )
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

// 🔐 Zugriffsschutz: nur NetzAG, Vorstand oder Kassenprüfer
$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        !empty($_SESSION["NetzAG"])
        || !empty($_SESSION["Vorstand"])
        || !empty($_SESSION["Kassenpruefer"])
    );

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

if (isset($_POST['transfer_upload_speichern'])) {
    $uid = intval($_POST['uid_neu']);
    $beschreibung = trim($_POST['beschreibung_neu']);
    if ($beschreibung === '') {
        $beschreibung = 'Transfer';
    }
    $betrag = floatval(str_replace(',', '.', $_POST['betrag_neu']));
    $zeit = time();

    // Datei hochladen
    $rechnungspfad = null;
    if (isset($_FILES['rechnung_neu']) && $_FILES['rechnung_neu']['error'] === 0) {
        $upload_dir = 'rechnungen/';
        $original_name = $_FILES['rechnung_neu']['name'];

        // Endung extrahieren
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $basename = pathinfo($original_name, PATHINFO_FILENAME);

        // Leerzeichen und Sonderzeichen bereinigen
        $base = str_replace(' ', '_', $basename); // oder komplett: preg_replace('/[^A-Za-z0-9_\-]/', '', $basename);
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '', $base);

        // Kürzen auf max 200 Zeichen
        $max_basename_len = 200;
        $base = substr($base, 0, $max_basename_len);
        $filename = $base . '.' . $extension;
        $zielpfad = $upload_dir . $filename;

        $counter = 1;
        while (file_exists($zielpfad)) {
            // Reserviere Platz für z.B. "_2", "_3" ... also 2-4 zusätzliche Zeichen
            $suffix = '_' . $counter;
            $cut_base = substr($base, 0, $max_basename_len - strlen($suffix));
            $filename = $cut_base . $suffix . '.' . $extension;
            $zielpfad = $upload_dir . $filename;
            $counter++;
        }

        // Datei verschieben
        if (move_uploaded_file($_FILES['rechnung_neu']['tmp_name'], $zielpfad)) {
            $rechnungspfad = $zielpfad;
        }
    }


    // Konto & Kasse
    $konto = ($betrag >= 0) ? 4 : 8;
    $kasse = isset($_POST['kasse_id']) ? intval($_POST['kasse_id']) : 1;
    $agent = $_SESSION["uid"];

    // Changelog
    $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
    $changelog .= "Insert durch Transfers.php\n";

    // Insert in Datenbank (inkl. Agent)
    $sql = "INSERT INTO transfers (uid, beschreibung, betrag, konto, kasse, tstamp, changelog, pfad, agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dsdiiissi", $uid, $beschreibung, $betrag, $konto, $kasse, $zeit, $changelog, $rechnungspfad, $agent);

    $stmt->execute();

    $stmt->close();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); // entfernt evtl. alte Query-Strings
    exit;
}


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

    $pfad = $ausgangs_pfad; // Standard: alter Pfad bleibt erhalten

    if (isset($_FILES['rechnung_upload']) && $_FILES['rechnung_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'rechnungen/';
        $transfer_id = intval($transfer_id);
        $tmp_name = $_FILES['rechnung_upload']['tmp_name'];
        $original_name = $_FILES['rechnung_upload']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        if (in_array($extension, $allowed_extensions)) {
            $base = $transfer_id;
            $new_filename = $base . '.' . $extension;
            $target_path = $upload_dir . $new_filename;
            $counter = 1;
    
            // Bei Kollision: fortlaufend durchnummerieren
            while (file_exists($target_path)) {
                $new_filename = $base . '_' . $counter . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                $counter++;
            }
    
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
    
            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path; // nur jetzt wird überschrieben
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
    if ($formatierter_betrag != $formatierter_ausgangsbetrag) {
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
        $stmt->execute();

        // Schließt das Statement
        $stmt->close();
    }
}

load_menu();

// ✏️ Bearbeitungsrecht: nur NetzAG oder Vorstand
$admin = !empty($_SESSION["NetzAG"]) || !empty($_SESSION["Vorstand"]);


if (isset($_POST['kasse_id'])) {
    $_SESSION['kasse_id'] = $_POST['kasse_id'];
}

if (!isset($_SESSION['kasse_id'])) {
    $_SESSION['kasse_id'] = 72; // Standard: Netzkonto
}

$kid = $_SESSION['kasse_id'];
$zeit = time();

if (isset($_POST['semester_start'])) {
    $_SESSION['semester_start'] = intval($_POST['semester_start']);
}

if (!isset($_SESSION['semester_start'])) {
    $_SESSION['semester_start'] = unixtime2startofsemester(time());
}

$semester_start = $_SESSION['semester_start'];

// Semesterende = Start des nächsten Semesters
$month = date('m', $semester_start);
$year = date('Y', $semester_start);

if ($month == 4) {
    // Sommersemester → nächstes Wintersemester beginnt im Oktober
    $semester_ende = strtotime("01-10-$year");
} else {
    // Wintersemester → nächstes Sommersemester im April nächsten Jahres
    $semester_ende = strtotime("01-04-" . ($year + 1));
}

// aktuelle Semesterbasis berechnen
$current_start = unixtime2startofsemester($zeit);


// ▼ HIER war's vorher bei dir vergessen:
$semester_options = [];
$ts = $current_start;
while ($ts >= strtotime('01-10-2023')) {
    $sem = unixtime2semester($ts);
    $semester_options[$sem] = $ts;

    $month = date('m', $ts);
    $year = date('Y', $ts);

    if ($month == 4) {
        $ts = strtotime("01-10-" . ($year - 1));
    } else {
        $ts = strtotime("01-04-$year");
    }
}

echo '<div class="kasse-semester-grid">';

// Linke 4/6: Kassen-Formular
echo '<form method="post" class="kasse-form" style="margin: 0;">';

echo '<div class="kasse-row">';
$onlinekassen = [
    ['id' => 72, 'label' => 'Netzkonto'],
    ['id' => 69, 'label' => 'PayPal'],
    ['id' => 92, 'label' => 'Hauskonto']
];
foreach ($onlinekassen as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:20px; width:150px;">' . $btn['label'] . '</button>';
}
echo '</div>';


$barkassen = [
    ['id' => 1, 'label' => 'Netzbarkasse I', 'const' => 'kasse_netz1'],
    ['id' => 2, 'label' => 'Netzbarkasse II', 'const' => 'kasse_netz2'],
    ['id' => 93, 'label' => 'Kassenwart I', 'const' => 'kasse_wart1'],
    ['id' => 94, 'label' => 'Kassenwart II', 'const' => 'kasse_wart2'],
    ['id' => 95, 'label' => 'Tresor', 'const' => 'kasse_tresor']
];
$kassen_usernames = [];

$stmt = mysqli_prepare($conn, "SELECT u.firstname FROM constants c JOIN users u ON c.wert = u.uid WHERE c.name = ?");
foreach ($barkassen as $barkasse) {
    $name = $barkasse['const'];
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $firstname);
    if (mysqli_stmt_fetch($stmt)) {
        $kassen_usernames[$barkasse['id']] = $firstname;
    } else {
        $kassen_usernames[$barkasse['id']] = '–';
    }
    mysqli_stmt_free_result($stmt);
}
mysqli_stmt_close($stmt);

echo '<div class="kasse-row">';
foreach ($barkassen as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    $owner = $kassen_usernames[$btn['id']] ?? '–';

    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:13px; width:130px; display: flex; flex-direction: column; align-items: center;">';
    echo '<span>' . $btn['label'] . '</span>';
    echo '<span style="font-size:11px; color:#aaa;">' . htmlspecialchars($owner) . '</span>';
    echo '</button>';
}
echo '</div>';


echo '</form>';

// Rechte 2/6: Semester-Dropdown + Kontostand
echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: center;">';

// Kontostand
$kontostand = berechneKontostand($conn, $kid);
echo '<div class="kontostand-box">';
echo 'Aktueller Kontostand:<br><strong>' . number_format($kontostand, 2, ',', '.') . ' €</strong>';
echo '</div>';

// Dropdown
echo '<form method="post" style="margin: 0;">';
echo '<select id="semester-select" name="semester_start" class="semester-dropdown" onchange="this.form.submit()">';
foreach ($semester_options as $label => $start_ts) {
    $selected = ($start_ts == $semester_start) ? 'selected' : '';
    echo "<option value=\"$start_ts\" $selected>$label</option>";
}
echo '</select>';
echo '</form>';

echo '</div>'; // Ende rechte 2/6




echo '</div>';






echo '<hr>';


if ($admin) {    

    echo '<form id="transfer-form" method="post" enctype="multipart/form-data">';
    echo '<div class="transfer-form-grid">';

    // Zeile 1
    echo '<input type="number" name="betrag_neu" placeholder="Betrag (€)" step="0.01">';
    echo '<input type="text" name="beschreibung_neu" placeholder="Beschreibung">';
    echo '<input type="file" name="rechnung_neu" accept=".pdf,.jpg,.jpeg,.png,.gif">';

    // Zeile 2
    echo '<div style="display: flex; gap: 6px; align-items: center;">';
    echo '<input type="text" name="usersuche" id="usersuche" placeholder="Nutzer suchen..." oninput="sucheUser(this.value)" style="flex:1;">';
    echo '<div style="display: flex; gap: 4px;">';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(472, \'NetzAG Dummy\')" title="NetzAG Dummy">Netz</button>';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(492, \'Haussprecher Dummy\')" title="Haussprecher Dummy">Haus</button>';
    echo '</div>';
    echo '</div>';

    echo '<div id="usersuchergebnisse" style="padding: 6px; background-color: #2a2a2a; border: 1px solid #444; min-height: 75px;"></div>';
    echo '<button type="submit" name="transfer_upload_speichern">Speichern</button>';
    echo '<input type="hidden" name="uid_neu" id="uid_neu">';
    echo '<input type="hidden" name="kasse_id" value="' . intval($kid) . '">';

    echo '</div>';
    echo '</form>';


    echo '<hr>';
}


$sql = "
    SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
           t.tstamp, t.beschreibung, t.betrag, t.pfad
    FROM transfers t
    JOIN users u ON t.uid = u.uid
    WHERE t.tstamp >= ? AND t.tstamp < ? AND t.kasse = ?
    ORDER BY t.tstamp DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iii", $semester_start, $semester_ende, $kid);
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
        7 => "Spülmaschine",
        8 => "Undefiniert"
    ];
    
    $kasse_options = [
        72 => "Netzkonto",
        92 => "Hauskonto",
        69 => "PayPal",
        1 => "Netzbarkasse I",
        2 => "Netzbarkasse II",
        93 => "Kassenwart I",
        94 => "Kassenwart II",
        95 => "Tresor",
        3 => "imaginäre Schuldbuchung",
        5 => "imaginäre Rückzahlung",
        0 => "Haus (alt)",
        4 => "Netzkonto (alt)",
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
    if ($admin) {
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
    if (!$admin) {
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
                ORDER BY pid, FIELD(turm, 'weh', 'tvk'), room";
        
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
    if (!$admin) {
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
    if (!$admin) {
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
    if (!$admin) {
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
    if (!$admin) {
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
        

    if ($admin) {
        // Upload-Feld für neue Rechnung
        echo '<input type="file" name="rechnung_upload" accept=".pdf,.jpg,.jpeg,.png,.gif" style="margin-top: 8px; color: white;"><br><br>';
    }
        


    // Changelog anzeigen (nicht editierbar) in einem optisch ansprechenden Feld
    if ($admin) {
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
    if ($admin) {
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

document.getElementById('transfer-form').addEventListener('submit', function(e) {
    const uid = document.getElementById('uid_neu').value.trim();
    const betrag = document.querySelector('input[name="betrag_neu"]').value.trim();

    if (!uid || !betrag) {
        alert("Bitte Nutzer auswählen und gültigen Betrag eingeben.");
        e.preventDefault(); // Verhindert das Absenden
    }
});

</script>
