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
                   (oldroom = '$searchTerm'))";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                    (name LIKE '%$searchTerm%' OR 
                     username LIKE '%$searchTerm%' OR 
                     email LIKE '%$searchTerm%' OR 
                     aliase LIKE '%$searchTerm%' OR 
                     geburtsort LIKE '%$searchTerm%')";
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
    <meta name="format-detection" content="telefon=no">
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid']) {  

  
  $editable = (isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true) || (isset($_SESSION["Vorstand"]) && $_SESSION["Vorstand"] === true);



  require_once('/WEH/PHP/FPDF/fpdf.php');

  function generatePDF($selected_uid, $conn) {
      $sql = "SELECT u.name, SUM(t.betrag) FROM users u JOIN transfers t ON u.uid = t.uid WHERE u.uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      if (!$stmt) {
          die('Prepare failed: ' . mysqli_error($conn));
      }
      mysqli_stmt_bind_param($stmt, "i", $selected_uid);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $name, $summe);
      mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt); // Schließe das Statement nach der Abfrage

      // Zweite Abfrage zur Auswahl der Transfers
      $sql = "SELECT id, tstamp, beschreibung, betrag, konto FROM transfers WHERE uid = ? ORDER BY tstamp DESC";
      $stmt = mysqli_prepare($conn, $sql);
      if (!$stmt) {
          die('Prepare failed: ' . mysqli_error($conn));
      }
      mysqli_stmt_bind_param($stmt, "i", $selected_uid);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $id, $tstamp, $beschreibung, $betrag, $konto);
      
      $kontosingular = array(
        -1 => "Alle Cashflows",
        4 => "Einzahlung",
        0 => "An-/Abmeldung",
        1 => "Netzbeitrag",
        2 => "Hausbeitrag",
        3 => "Drucken",
        5 => "Getränke",
        6 => "Waschmarken",
        8 => "Abrechnung"
      );

      // PDF initialisieren
      $pdf = new FPDF('P', 'mm', 'A4');
      $pdf->AddPage();

      $pdf->SetFont('Arial', 'B', 30);
      $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', $name), 0, 1, 'C');
      $pdf->Ln(2);
      $pdf->SetFont('Arial', '', 14);
      $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', date('d.m.Y')), 0, 1, 'C');
      $pdf->Ln(5);
      $pdf->SetFont('Arial', '', 18);
      $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', 'WEH-Kontostand: '), 0, 1, 'C');
      $pdf->SetFont('Arial', 'B', 18);
      $pdf->Cell(0, 10, iconv('UTF-8', 'windows-1252', number_format($summe, 2, ",", ".").' €'), 0, 1, 'C');
      $pdf->Ln(5);
      
      $pdf->SetFont('Arial', 'B', 12);
      $pdf->Cell(20, 10, iconv('UTF-8', 'windows-1252', 'ID'), 1, 0, 'C');
      $pdf->Cell(25, 10, iconv('UTF-8', 'windows-1252', 'Datum'), 1, 0, 'C');
      $pdf->Cell(35, 10, iconv('UTF-8', 'windows-1252', 'Art'), 1, 0, 'C');
      $pdf->Cell(80, 10, iconv('UTF-8', 'windows-1252', 'Beschreibung'), 1, 0, 'C');
      $pdf->Cell(30, 10, iconv('UTF-8', 'windows-1252', 'Betrag'), 1, 1, 'C');

      $pdf->SetFont('Arial', '', 10);
      while (mysqli_stmt_fetch($stmt)) {
          $pdf->Cell(20, 10, $id, 1, 0, 'C');
          $pdf->Cell(25, 10, date("d.m.Y", $tstamp), 1, 0, 'C');   
          $pdf->Cell(35, 10, iconv('UTF-8', 'windows-1252', $kontosingular[$konto]), 1, 0, 'C');
          $pdf->Cell(80, 10, (strlen($beschreibung) > 50 ? iconv('UTF-8', 'windows-1252', substr(htmlspecialchars($beschreibung), 0, 28)) . "[...]" : iconv('UTF-8', 'windows-1252', htmlspecialchars($beschreibung))), 1, 0, 'C');
          $pdf->Cell(30, 10, ($betrag >= 0 ? "+ " : "- ") . iconv('UTF-8', 'windows-1252', number_format(abs($betrag), 2, ",", ".")) . " \x80", 1, 1, 'C');
      }
  
      // PDF-Dateinamen generieren
      $pdfFileName = 'WEH-Transfers_' .$selected_uid . '.pdf';
  
      // PDF-Datei im Ordner userkontopdfs speichern
      $pdf->Output('F', "userkontopdfs/$pdfFileName");
  
      // Ressourcen freigeben
      mysqli_stmt_close($stmt);
  
      // Den Dateinamen des erstellten PDFs zurückgeben
      return $pdfFileName;
  }
  
  // Wenn das Formular abgeschickt wurde, rufen Sie die Funktion zur PDF-Generierung auf
  if (isset($_POST["printtable"])) {
      $pdfFileName = generatePDF($_POST["uid"], $conn);
  
      // Benutzer auf die Download-Seite weiterleiten und File löschen
      header("Location: UserKontoDownload.php?filename=$pdfFileName");
      exit; // Beenden des Skripts
  }

  load_menu();



#///// START IN WORK /////
#echo '<!DOCTYPE html>';
#echo '<html lang="en">';
#echo '<head>';
#echo '<meta charset="UTF-8">';
#echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
#echo '<style>';
#echo 'body { background-color: #880000; color: white; font-family: Arial, sans-serif; }';
#echo '.center-table { margin: 50px auto; border-collapse: collapse; text-align: left; width: 80%; }';
#echo '.center-table th, .center-table td { border: 1px solid white; padding: 10px; }';
#echo '.center-table th { background-color: #444; }';
#echo '.title { font-size: 2rem; margin-bottom: 10px; text-align: center; }';
#echo '.subtitle { font-size: 1.2rem; margin-bottom: 20px; text-align: center; }';
#echo '</style>';
#echo '<title>POST Variablen</title>';
#echo '</head>';
#echo '<body>';
#echo '<div class="title">Diese Seite ist aktuell in Bearbeitung</div>';
#echo '<div class="subtitle">POST-Daten werden unten angezeigt</div>';
#if (!empty($_POST)) {
#    echo '<table class="center-table">';
#    echo '<tr>';
#    echo '<th>Key</th>';
#    echo '<th>Value</th>';
#    echo '</tr>';
#    foreach ($_POST as $key => $value) {
#        echo '<tr>';
#        echo '<td>' . htmlspecialchars($key) . '</td>';
#        echo '<td>' . htmlspecialchars($value) . '</td>';
#        echo '</tr>';
#    }
#    echo '</table>';
#} else {
#    echo '<p style="text-align: center;">Es wurden keine POST-Daten übermittelt.</p>';
#}
#echo '<br><br><hr><br><br>';
#echo '</body>';
#echo '</html>';
#///// ENDE IN WORK /////


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
    $ausgangs_betrag = str_replace(",", ".", $_POST['ausgangs_betrag']); // Betrag wird wieder in Dezimalform gebracht
    $ausgangs_beschreibung = $_POST['ausgangs_beschreibung']; // Alte Beschreibung

    function formatBetrag($betrag) {
        // Entferne zuerst Tausenderpunkte, nur wenn sie vor einer Dezimaltrennstelle stehen
        $betrag = str_replace('.', '', $betrag); 
        // Wandelt das Dezimalkomma in einen Dezimalpunkt um
        $betrag = str_replace(',', '.', $betrag);
        return $betrag; // MySQL-kompatibler FLOAT-String
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


    // Update-Abfrage nur durchführen, wenn Änderungen vorhanden sind
    if ($has_changes) {
      $query = "UPDATE transfers 
      SET uid = ?, 
          konto = ?, 
          kasse = ?, 
          betrag = ?, 
          beschreibung = ?,
          agent = ?, 
          changelog = CONCAT(IFNULL(changelog, ''), IF(changelog IS NOT NULL, '\n\n', ''), ?) 
      WHERE id = ?";

        $stmt = $conn->prepare($query);

        // Bindet die Parameter an die Abfrage
        $stmt->bind_param("iiidsisi", $selected_user, $konto, $kasse, $formatierter_betrag, $beschreibung, $agent, $changelog, $transfer_id);

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









  echo '<div style="display: flex; flex-direction: row; align-items: flex-start; justify-content: center; font-size: 25px; color: white;">';
  echo '<div style="flex: 1; text-align: center;">';
  echo '<h1>Bank</h1>';    
  echo '<span style="color: red; font-size:20px;">It may take a few days for bank transfers to appear in your WEH account!</span>';  
  echo '<span style="color: #708090; font-size:18px;"><br>For faster processing, consider using the PayPal option.</span><br><br>';  
  echo '<span style="color: white;">Name: </span><span class="white-text">WEH e.V.</span><br>';
  echo '<span style="color: white;">IBAN: </span><span class="white-text">DE90 3905 0000 1070 3346 00</span><br>';
  echo '<span style="color: white;">Überweisungsbetreff: </span><span class="white-text">W' . $_SESSION["user"] . 'H</span><br><br>';
  echo '<span style="color: #708090; font-size:18px;">If you do not set this exact Transfer Reference,<br>we will not be able to assign your payment to your account!</span><br>';
  
  echo '</div>';
  echo '<div style="flex: 1; text-align: center;">';
  echo '<h1>PayPal</h1>';
  
  echo '<form method="post" action="paypal.php" id="paypal_form" name="paypal-form">';
  echo '<label for="paypal-amount" style="color: white; font-size: 25px;">Amount: </label>';
  echo '<select  id="paypal-amount" name="paypal-amount" style="margin-top: 20px; font-size: 20px;">';
  echo '<option value="5">5 € (0.35 € fee)</option>';
  echo '<option value="10">10 € (0.35 € fee)</option>';
  echo '<option value="20" selected>20 € </option>';
  echo '<option value="30">30 € </option>';
  echo '<option value="40">40 € </option>';
  echo '<option value="50">50 € </option>';
  echo '<option value="75">75 € </option>';
  echo '<option value="100">100 € </option>';
  echo '</select>&nbsp&nbsp';
  echo '<button  type="submit" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">TRANSFER</button>';
  echo '</form><br>';
  echo '<span style="color: #708090; font-size:18px;">It can take up to 10 seconds to process your payment!</span><br>';
  echo '</div>';
  echo '</div>';
  
  



  echo "<br><br><br><hr><br><br>";


  $uid = isset($_POST['uid']) ? $_POST['uid'] : $_SESSION["uid"];
  $selected_uid = $uid;


  if ($editable) {
    echo '<div style="margin: 0 auto; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
    echo '<span class="white-text" style="font-size: 35px; cursor: pointer;" onclick="toggleAdminPanel()">Admin Panel</span>';
    echo '<div id="adminPanel" style="display: none;">'; // Beginn des ausklappbaren Bereichs



#    echo '<form method="post">';
#    echo '<label for="uid" style="color: white; font-size: 25px;">UID: </label>';
#    echo '<input type="text" name="uid" id="uid" placeholder="UID" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()" value="' . $selected_uid . '">';
#    echo '</form>';
#
#    echo '<form method="post">';
#    echo '<label for="uid" style="color: white; font-size: 25px;">Bewohner: </label>';
#    
#    echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()">';
#    
#    $sql = "SELECT name, room, pid, turm FROM users WHERE uid = ?";
#    $stmt = mysqli_prepare($conn, $sql);
#    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
#    mysqli_stmt_execute($stmt);
#    #mysqli_set_charset($conn, "utf8");
#    mysqli_stmt_bind_result($stmt, $name, $room, $pid, $turm);
#    mysqli_stmt_fetch($stmt);
#    
#    
#    $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
#    $roomLabel = '';
#    if ($pid == 11) {
#      $roomLabel = ' ['.$formatted_turm.' '.$room.']';
#    } elseif ($pid == 12) {
#        $roomLabel = ' ['.$formatted_turm.' Subletter]';
#    } elseif ($pid == 13) {
#        $roomLabel = ' ['.$formatted_turm.' Ausgezogen]';
#    } elseif ($pid == 14) {
#        $roomLabel = ' ['.$formatted_turm.' Abgemeldet]';
#    } elseif ($pid == 69) {
#      $roomLabel = ' [Dummy]';
#    } else {
#      $roomLabel = ' [Undefined]';
#    }
#    
#    echo '<option value="' . $selected_uid . '">' . $name . $roomLabel . '</option>';
#    mysqli_stmt_free_result($stmt);
#
#    echo '<option value="472">Netzkonto</option>';
#    echo '<option value="492">Hauskonto</option>';
#    echo '<option value="2524">PayPal</option>';
#    
#    $sql = "SELECT uid, name, room, turm 
#        FROM users 
#        WHERE pid = 11 
#        ORDER BY FIELD(turm, 'weh', 'tvk'), room";
#    
#    $stmt = mysqli_prepare($conn, $sql);
#    mysqli_stmt_execute($stmt);
#    mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
#      
#    while (mysqli_stmt_fetch($stmt)) {
#      $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
#      echo '<option value="' . $uid . '">' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
#    }
#
#    echo '</select>';
#    echo '</form>';

    echo '<div style="display:flex; justify-content:center; align-items:center;margin-top:15px;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="uid" value="472" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Netzkonto</button>';
    echo '<button type="submit" name="uid" value="492" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Hauskonto</button>';
    echo '<button type="submit" name="uid" value="2524" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">PayPal</button>';
    echo '</form>';
    echo '</div>';

    echo "<!DOCTYPE html>";
    echo "<html lang='de'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>Dynamische Benutzersuche</title>";
    echo "<style>";
    echo "#searchInput {";
    echo "    display: block;";
    echo "    margin: 20px auto;";
    echo "    height: 30px;";
    echo "    font-size: 25px;"; // Schriftgröße auf 16px setzen
    echo "}";
    echo ".center-table {";
    echo "    margin-left: auto;";
    echo "    margin-right: auto;";
    echo "}";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<input type='text' id='searchInput' placeholder='Suche nach User...' onkeyup='searchUser(this.value)'>";
    echo "</body>";
    echo "</html>";
    

    echo "<div style='margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;'>";
    echo "<table class='center-table'>";
    echo "<tr></tr>";
  
    if (!empty($searchedusers)) {
      foreach ($searchedusers as $uid => $users) {
          foreach ($users as $user) {
              $user_id = htmlspecialchars($user['uid'], ENT_QUOTES);
              $user_name = htmlspecialchars($user["username"], ENT_QUOTES);
              $name_html = htmlspecialchars($user["name"], ENT_QUOTES);
              $room = $user['room'] == 0 ? $user['oldroom'] : $user['room'];
              $color = $user['room'] == 0 ? 'red' : '#7FFF00';
              echo "<tr><td>" . $user_id . "</td><td>" . $user_name . "</td><td><a href='javascript:void(0);' onclick='submitForm(\"$user_id\");' class='white-text' style='user-select: none;'>" . $name_html . "</a></td><td style='color:" . $color . ";'>" . $room . "</td></tr>";
          }
      }
    }
    echo "</table>";
    echo "</div>";

    echo "<script>";
    echo "function searchUser(searchValue) {";
    echo "    fetch('?search=' + encodeURIComponent(searchValue))";
    echo "    .then(response => response.json())";
    echo "    .then(data => {";
    echo "        const table = document.querySelector('.center-table tbody');";
    echo "        table.innerHTML = '';";  // Clear the existing table content
    echo "        Object.keys(data).forEach(uid => {";
    echo "            data[uid].forEach(user => {";
    echo "                const row = table.insertRow();";
    echo "                const cell1 = row.insertCell(0);";  // For 'Turm'
    echo "                const cell2 = row.insertCell(1);";  // For 'uid'
    echo "                const cell3 = row.insertCell(2);";  // For 'username'
    echo "                const cell4 = row.insertCell(3);";  // For 'name'
    echo "                const cell5 = row.insertCell(4);";  // For 'room'
    
                    // UID
    echo "                cell1.textContent = user.uid;";
    
                    // Username
    echo "                cell2.textContent = user.username;";
    
                    // Name with link
    echo "                const link = document.createElement('a');";
    echo "                link.href = 'javascript:void(0);';";
    echo "                link.className = 'white-text';";
    echo "                link.style.userSelect = 'none';";
    echo "                link.textContent = user.name;";
    echo "                link.onclick = function() { submitForm(user.uid); };";
    echo "                cell3.appendChild(link);";
    
                    // Logic for "Turm" and "room" field
    echo "                const room = user.room == 0 ? user.oldroom : user.room;";
    echo "                cell5.textContent = room;";
    echo "                if (user.turm === 'weh') {";
    echo "                    cell4.textContent = 'WEH';";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
    echo "                } else if (user.turm === 'tvk') {";
    echo "                    cell4.textContent = 'TvK';";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
    echo "                } else {";
    echo "                    cell4.textContent = user.turm.toUpperCase();";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
    echo "                }";
    echo "                cell4.style.paddingRight = '15px';";  // Adds padding to the right


    
                    // Room handling
    echo "            });";
    echo "        });";
    echo "    });";
    echo "}";
    
    echo "function submitForm(userId) {";
    echo "    var form = document.createElement('form');";
    echo "    form.method = 'post';";
    echo "    form.action = '';";
    echo "    var hiddenField = document.createElement('input');";
    echo "    hiddenField.type = 'hidden';";
    echo "    hiddenField.name = 'uid';";
    echo "    hiddenField.value = userId;";
    echo "    form.appendChild(hiddenField);";
    echo "    document.body.appendChild(form);";
    echo "    form.submit();";
    echo "}";
    echo "</script>";
    
    
    echo "</body>";
    echo "</html>";

    if (isset($_POST["newtransfer"]) && $editable) {
      $insert_betrag = $_POST['betrag'];
      $insert_uid = $_POST['uid'];
      $insert_beschreibung = isset($_POST['beschreibung']) && $_POST['beschreibung'] ? $_POST['beschreibung'] : "Überweisung";
      $zeit = time();
      $skip = false; // Set the initial value of skip to false
  
      if (is_numeric($insert_betrag)) {
        if ($insert_uid == 492) {
          if ($insert_betrag > 0) {
              $konto = 4;
              $kasse = 92;
          } elseif ($insert_betrag < 0) {
              $konto = 8;
              $kasse = 92;
          } else {
              $skip = true;
              echo "<p style='color:red; text-align:center;'>Betrag ist 0. Kein Eintrag hinzugefügt.</p>";
          }
        } elseif ($insert_uid == 2524) {
          if ($insert_betrag > 0) {
              $konto = 4;
              $kasse = 69;
          } elseif ($insert_betrag < 0) {
              $konto = 8;
              $kasse = 69;
          } else {
              $skip = true;
              echo "<p style='color:red; text-align:center;'>Betrag ist 0. Kein Eintrag hinzugefügt.</p>";
          }
        } else {
          if ($insert_betrag > 0) {
              $konto = 4;
              $kasse = 72;
          } elseif ($insert_betrag < 0) {
              $konto = 8;
              $kasse = 72;
          } else {
              $skip = true;
              echo "<p style='color:red; text-align:center;'>Betrag ist 0. Kein Eintrag hinzugefügt.</p>";
          }
        }
      } else {
          $skip = true;
          echo "<p style='color:red; text-align:center;'>Ungültiger Betrag. Kein Eintrag hinzugefügt.</p>";
      }
  
      if (!$skip) {
          $agent = $_SESSION["uid"];
          $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
          $changelog .= "Insert durch UserKonto.php [Mode3]\n";
          $insert_sql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, konto, kasse, agent, changelog) VALUES (?,?,?,?,?,?,?,?)";
          $insert_var = array($zeit, $insert_uid, $insert_beschreibung, $insert_betrag, $konto, $kasse, $agent, $changelog);
          $stmt = mysqli_prepare($conn, $insert_sql);
          mysqli_stmt_bind_param($stmt, "iisdiiis", ...$insert_var);
          mysqli_stmt_execute($stmt);
          if (mysqli_error($conn)) {
              echo "MySQL Fehler: " . mysqli_error($conn);
          } else {
              echo "<p style='color:green; text-align:center;'>Erfolgreich hinzugefügt.</p>";
          }
          mysqli_stmt_close($stmt);

          $sql = "SELECT DISTINCT users.uid
                  FROM users 
                  JOIN sperre ON users.uid = sperre.uid 
                  WHERE sperre.missedpayment = 1 
                    AND sperre.starttime <= ? 
                    AND sperre.endtime >= ? 
                    AND sperre.uid = ?";
          $stmt = mysqli_prepare($conn, $sql);
          if ($stmt) {
              mysqli_stmt_bind_param($stmt, "iii", $zeit, $zeit, $insert_uid);
              mysqli_stmt_execute($stmt);
              mysqli_stmt_store_result($stmt);
              if (mysqli_stmt_num_rows($stmt) > 0) {
                  exec('python3 /WEH/PHP/skripte/anmeldung.py');
              }              
              mysqli_stmt_close($stmt);
          } else {
              die('Error preparing the SQL statement: ' . mysqli_error($conn));
          }
      }
    }

    $sql = "SELECT name, room, pid, turm FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $name, $room, $pid, $turm);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    echo '<br><br><form method="post">';
    echo '<label for="uid" style="color: white; font-size: 40px;">'.$name.'</label><br>';
    if ($selected_uid == 492) {
        echo '<label for="uid" style="color: lightgrey; font-size: 20px;">(Neuer Eintrag auf Hauskonto)</label><br>';
    } elseif ($selected_uid == 2524) {
        echo '<label for="uid" style="color: lightgrey; font-size: 20px;">(Neuer Eintrag auf PayPal-Konto)</label><br>';
    } else {
        echo '<label for="uid" style="color: lightgrey; font-size: 20px;">(Neuer Eintrag auf Netzkonto)</label><br>';
    }
    echo '<label for="uid" style="color: white; font-size: 25px;">Beschreibung: </label>';
    echo '<input type="text" style="margin-top: 20px; font-size: 20px; text-align: center;" id="beschreibung" name="beschreibung"><br>';
    echo '<label for="uid" style="color: white; font-size: 25px;">Betrag: </label>';
    echo '<input type="number" step="0.01" style="margin-top: 20px; font-size: 20px; text-align: center;" id="betrag" name="betrag" required><br>';
    echo '<input type="hidden" name="uid" value="' . $selected_uid . '"><br>';
    echo '<button type="submit" name="newtransfer" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">'.htmlspecialchars("Eintragen").'</button>';
    echo '</form>';

    echo '</div>'; // Ende des ausklappbaren Bereichs
    echo '</div>';
    echo '</div>';
    
    if (isset($_POST["newtransfer"]) || isset($_POST['uid'])) {
      echo '<script>document.getElementById("adminPanel").style.display = "block";</script>';
    }
    
    echo '<script>
    function toggleAdminPanel() {
        var panel = document.getElementById("adminPanel");
        if (panel.style.display === "none") {
            panel.style.display = "block";
        } else {
            panel.style.display = "none";
        }
    }
    </script>';
  }

  echo "<br><br>";
  

  if ($selected_uid != 472 && $selected_uid != 2524 && $selected_uid != 492) {
    $sql = "SELECT SUM(betrag) FROM transfers WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $summe);
    mysqli_stmt_fetch($stmt);
    if (is_null($summe)) {
      $summe = 0.00;
    }
    $text = "Account Balance: ";
    echo '<div style="text-align: center;"><h2 style="display: inline;">'. $text .'</h2>';
    echo '<h1 style="display: inline;">'.number_format($summe, 2, ",", ".").' €</h1></div>';
    echo "<br>";
    mysqli_stmt_free_result($stmt);
  }

  $kontos = array(
    -1 => "Alle Cashflows",
    4 => "Einzahlungen",
    0 => "An-/Abmeldung",
    1 => "Netzbeiträge",
    2 => "Hausbeiträge",
    3 => "Drucken",
    5 => "Getränke",
    6 => "Waschmarken",
    8 => "Abrechnungen"
  );
  
  echo '<div style="text-align: center;">';
  echo '<form method="post">';
  echo '<input type="hidden" name="uid" value="' . $selected_uid . '">'; // Verstecktes Feld mit dem ursprünglichen Wert
  echo '<select name="konto" onchange="this.form.submit()" style="font-size: 20px;text-align: center;">'; // Entfernt das margin-Styling
  foreach ($kontos as $id => $name) {
    $selected = "";
    if (isset($_POST['konto']) && $_POST['konto'] == $id) {
      $selected = "selected";
    }
    echo '<option value="' . $id . '" ' . $selected . '>' . $name . '</option>';
  }
  echo '</select>';
  echo '</form>';
  echo '</div>';
  echo '<br><br>';
  
  // SQL-Abfrage anpassen
  if (isset($_POST['konto']) && $_POST['konto'] != -1) {
    $selected_konto = $_POST['konto'];
    $sql = "SELECT id, tstamp, beschreibung, betrag FROM transfers WHERE uid = ? AND konto = ? ORDER BY tstamp DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $selected_uid, $selected_konto);
  } else {
    $sql = "SELECT id, tstamp, beschreibung, betrag FROM transfers WHERE uid = ? ORDER BY tstamp DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  }
  
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $id, $tstamp, $beschreibung, $betrag);

  echo "<table class='grey-table'>";
  echo "<tr><th>ID</th><th>Date</th><th>Text</th><th>Amount</th></tr>";
  
  while (mysqli_stmt_fetch($stmt)) {
      echo '<tr onclick="document.getElementById(\'form-'.$id.'\').submit();" style="cursor: pointer;">';  // JavaScript zum Absenden des Formulars bei Klick
      echo '<form id="form-'.$id.'" action="UserKonto.php" method="post">';  // Das Formular für die Zeile
      echo "<td>".$id."</td>";
      echo "<td>".date("d.m.Y", $tstamp)."</td>";
      echo "<td>".(strlen($beschreibung) > 50 ? substr(htmlspecialchars($beschreibung), 0, 50)."[...]" : htmlspecialchars($beschreibung))."</td>";
      echo "<td>".number_format($betrag, 2, ",", ".")." €</td>";
  
      // Verstecktes Input-Feld für die Transfer-ID in jeder Zeile
      echo '<input type="hidden" name="transfer_id" value="'.$id.'">';
      echo '<input type="hidden" name="popup" value="true">';
      echo '<input type="hidden" name="uid" value="' . htmlspecialchars($selected_uid) . '">';
  
      echo '</form>';  // Formular beenden
      echo "</tr>";
  }
  
  echo "</table>";
  
  

  // Free up the resources associated with the second query
  mysqli_stmt_free_result($stmt);
  

  echo '<br><br>';

  echo '
  <div style="display: flex; justify-content: center; align-items: center;">
    <form method="post">
      <input type="hidden" name="uid" value="' . $selected_uid . '"><br>
      <button type="submit" name="printtable" class="center-btn" style="font-size: 20px;">PDF erstellen</button>
    </form>
  </div><br><br>
  ';  





  if (isset($_POST['transfer_id']) && isset($_POST['popup'])) {

    
    // Abfrage für die Transfer-Informationen
    $query = "SELECT t.uid, t.konto, t.kasse, t.betrag, t.tstamp, t.beschreibung, t.changelog FROM transfers t WHERE id = ?";
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
        $selected_transfer_changelog
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
          <input type="hidden" name="uid" value="' . htmlspecialchars($_POST['uid']) . '">
          <button type="submit" name="close" value="close" class="close-btn">X</button>
      </form>
    <br>');


    echo '<div style="text-align: center;">';

    // Wenn die Felder editierbar sind, erstelle ein Formular, sonst nur Anzeige
    if ($editable) {
      echo '<form method="post">';
      echo '<input type="hidden" name="uid" value="' . htmlspecialchars($_POST['uid']) . '">';
      echo '<input type="hidden" name="transfer_id" value="'.$_POST['transfer_id'].'">';
      echo '<input type="hidden" name="ausgangs_betrag" value="'.number_format($selected_transfer_betrag, 2, ".", "").'">';
      echo '<input type="hidden" name="ausgangs_uid" value="'.$selected_transfer_uid.'">';
      echo '<input type="hidden" name="ausgangs_konto" value="'.$selected_transfer_konto.'">';
      echo '<input type="hidden" name="ausgangs_kasse" value="'.$selected_transfer_kasse.'">';
      echo '<input type="hidden" name="ausgangs_beschreibung" value="'.htmlspecialchars($selected_transfer_beschreibung).'">';
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


}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>