<?php
  session_start();
  require('conn.php');
  
  $paypalactive = 0; // fallback: aus
  if ($st = $conn->prepare("SELECT wert FROM constants WHERE name='paypalactive' LIMIT 1")) {
      $st->execute();
      $st->bind_result($w);
      if ($st->fetch()) {
          $paypalactive = (int)$w;
      }
      $st->close();
  }

  $DEBUG_PAYPAL = ($paypalactive !== 1);  

  // 2026 - PayPal mittelfristig deaktiviert.
  // Auf false setzen, wenn die Kassenwarte das PayPal-Konto wiederhergestellt haben und die Kassenprüfung durch ist!
  $PAYPAL_TEMPORARILY_DISABLED = true;

  $suche = FALSE;
  
  if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    header('Content-Type: application/json');

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
            $searchedusers[$uid][] = array(
                "uid" => $uid,
                "name" => $name,
                "username" => $username,
                "room" => $room,
                "oldroom" => $oldroom,
                "turm" => $turm
            );
        }

        echo json_encode($searchedusers);
    } else {
        echo json_encode([]);
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
  $isWebmaster   = !empty($_SESSION['Webmaster']);
  // PayPal nur erlauben, wenn es nicht temporär deaktiviert ist.
  // Falls es nicht temporär deaktiviert ist, dürfen Webmaster im Debug-Modus weiterhin testen.
  $paypalAllowed = !$PAYPAL_TEMPORARILY_DISABLED && ((!$DEBUG_PAYPAL) || $isWebmaster);

  function tail_bytes(string $path, int $maxBytes = 200000): string {
      if (!is_file($path) || !is_readable($path)) return '';
      $size = @filesize($path);
      if ($size === false) return '';
      $start = max(0, $size - $maxBytes);
      $fp = @fopen($path, 'rb');
      if (!$fp) return '';
      @fseek($fp, $start);
      $data = stream_get_contents($fp);
      @fclose($fp);
      return (string)$data;
  }

  function tail_lines(string $path, int $lines = 200, int $maxBytes = 200000): string {
      $data = tail_bytes($path, $maxBytes);
      if ($data === '') return '';
      $arr = preg_split("/\r\n|\n|\r/", $data);
      $arr = array_values(array_filter($arr, static fn($v) => $v !== ''));
      $arr = array_slice($arr, -$lines);
      return implode("\n", $arr);
  }

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
      mysqli_stmt_close($stmt);

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
          $pdf->Cell(35, 10, iconv('UTF-8', 'windows-1252', $kontosingular[$konto] ?? 'Unbekannt'), 1, 0, 'C');
          $pdf->Cell(80, 10, (strlen($beschreibung) > 50 ? iconv('UTF-8', 'windows-1252', substr(htmlspecialchars($beschreibung), 0, 28)) . "[...]" : iconv('UTF-8', 'windows-1252', htmlspecialchars($beschreibung))), 1, 0, 'C');
          $pdf->Cell(30, 10, ($betrag >= 0 ? "+ " : "- ") . iconv('UTF-8', 'windows-1252', number_format(abs($betrag), 2, ",", ".")) . " \x80", 1, 1, 'C');
      }
  
      $pdfFileName = 'WEH-Transfers_' .$selected_uid . '.pdf';
      $pdf->Output('F', "userkontopdfs/$pdfFileName");
      mysqli_stmt_close($stmt);
  
      return $pdfFileName;
  }
  
  if (isset($_POST["printtable"])) {
      $pdfFileName = generatePDF($_POST["uid"], $conn);
      header("Location: UserKontoDownload.php?filename=$pdfFileName");
      exit;
  }

  load_menu();

  if (isset($_POST['save_transfer_id'])) {
    $transfer_id = $_POST['transfer_id'];
    $selected_user = $_POST['selected_user'];
    $konto = $_POST['konto_update'];
    $kasse = $_POST['kasse'];
    $betrag = $_POST['betrag'];
    $beschreibung = $_POST['beschreibung'];
    $zeit = time();
    $agent = $_SESSION['uid'];

    $ausgangs_uid = $_POST['ausgangs_uid'];
    $ausgangs_konto = $_POST['ausgangs_konto'];
    $ausgangs_kasse = $_POST['ausgangs_kasse'];
    $ausgangs_betrag = $_POST['ausgangs_betrag'];
    $ausgangs_beschreibung = $_POST['ausgangs_beschreibung'];

    function formatBetrag($betrag) {
        if (strpos($betrag, ',') !== false) {
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
        }
        return $betrag;
    }
    
    $formatierter_ausgangsbetrag = formatBetrag($ausgangs_betrag);
    $formatierter_betrag = formatBetrag($betrag);
    
    $has_changes = false;
    $changelog = "";

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
        $stmt->bind_param("iiidsisi", $selected_user, $konto, $kasse, $formatierter_betrag, $beschreibung, $agent, $changelog, $transfer_id);

        if ($stmt->execute()) {
          echo '<p style="color: green; text-align: center;">Der Transfer wurde erfolgreich aktualisiert.</p>';
        } else {
          echo '<p style="color: red; text-align: center;">Fehler beim Aktualisieren des Transfers: ' . htmlspecialchars($conn->error) . '</p>';
        }

        $stmt->close();
    } else {
      echo '<p style="color: green; text-align: center;">Keine Änderungen vorgenommen.</p>';
    }
  }

  $uid = isset($_POST['uid']) ? $_POST['uid'] : $_SESSION["uid"];
  $selected_uid = $uid;

  if ($isWebmaster && $DEBUG_PAYPAL) {
    if (!isset($_SESSION["PayPalDebugPanelToggleState"])) {
      $_SESSION["PayPalDebugPanelToggleState"] = "none";
    }

    if (isset($_POST["togglePayPalDebugPanel"])) {
      $_SESSION["PayPalDebugPanelToggleState"] = $_SESSION["PayPalDebugPanelToggleState"] === "none" ? "block" : "none";
    }
  }

  if ($editable) {
    if (!isset($_SESSION["AdminPanelToggleState"])) {
      $_SESSION["AdminPanelToggleState"] = "none";
    }

    if (isset($_POST["toggleAdminPanel"])) {
        $_SESSION["AdminPanelToggleState"] = $_SESSION["AdminPanelToggleState"] === "none" ? "block" : "none";
    }

    echo '<div style="margin-bottom: 20px; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
    echo '<span class="white-text" style="font-size: 35px; cursor: pointer; display: inline-block;" onclick="toggleAdminPanel()">Admin Panel</span>';
    echo '<div id="adminPanel" style="display: ' . $_SESSION["AdminPanelToggleState"] . ';">';

    renderUserPostButtons($conn, $selected_uid);

    echo '<div style="display:flex; justify-content:center; align-items:center;margin-top:15px;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="uid" value="472" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Netzkonto</button>';
    echo '<button type="submit" name="uid" value="492" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Hauskonto</button>';
    echo '<button type="submit" name="uid" value="2524" class="sml-center-btn" style="font-size:20px; margin-right:10px; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">PayPal</button>';
    echo '</form>';
    echo '</div>';

    echo "<style>";
    echo "#searchInput {";
    echo "    display: block;";
    echo "    margin: 20px auto;";
    echo "    height: 30px;";
    echo "    font-size: 25px;";
    echo "}";
    echo ".center-table {";
    echo "    margin-left: auto;";
    echo "    margin-right: auto;";
    echo "}";
    echo "</style>";

    echo "<input type='text' id='searchInput' placeholder='Suche nach User...' onkeyup='searchUser(this.value)'>";

    echo "<div style='margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;'>";
    echo "<table class='center-table'>";
    echo "<tbody>";
  
    if (!empty($searchedusers)) {
      foreach ($searchedusers as $uid => $users) {
          foreach ($users as $user) {
              $user_id = htmlspecialchars($user['uid'], ENT_QUOTES);
              $user_name = htmlspecialchars($user["username"], ENT_QUOTES);
              $name_html = htmlspecialchars($user["name"], ENT_QUOTES);
              $room = $user['room'] == 0 ? $user['oldroom'] : $user['room'];
              $color = $user['room'] == 0 ? 'red' : '#7FFF00';
              echo "<tr><td>" . $user_id . "</td><td>" . $user_name . "</td><td><a href='javascript:void(0);' onclick='submitForm(\"$user_id\");' class='white-text' style='user-select: none;'>" . $name_html . "</a></td><td style='color:" . $color . ";'>" . htmlspecialchars((string)$room) . "</td></tr>";
          }
      }
    }

    echo "</tbody>";
    echo "</table>";
    echo "</div>";

    echo "<script>";
    echo "function searchUser(searchValue) {";
    echo "    fetch('?search=' + encodeURIComponent(searchValue))";
    echo "    .then(response => response.json())";
    echo "    .then(data => {";
    echo "        const table = document.querySelector('.center-table tbody');";
    echo "        table.innerHTML = '';";
    echo "        Object.keys(data).forEach(uid => {";
    echo "            data[uid].forEach(user => {";
    echo "                const row = table.insertRow();";
    echo "                const cell1 = row.insertCell(0);";
    echo "                const cell2 = row.insertCell(1);";
    echo "                const cell3 = row.insertCell(2);";
    echo "                const cell4 = row.insertCell(3);";
    echo "                const cell5 = row.insertCell(4);";
    echo "                cell1.textContent = user.uid;";
    echo "                cell2.textContent = user.username;";
    echo "                const link = document.createElement('a');";
    echo "                link.href = 'javascript:void(0);';";
    echo "                link.className = 'white-text';";
    echo "                link.style.userSelect = 'none';";
    echo "                link.textContent = user.name;";
    echo "                link.onclick = function() { submitForm(user.uid); };";
    echo "                cell3.appendChild(link);";
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
    echo "                cell4.style.paddingRight = '15px';";
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

    if (isset($_POST["newtransfer"]) && $editable) {
      $insert_betrag = $_POST['betrag'];
      $insert_uid = $_POST['uid'];
      $insert_beschreibung = isset($_POST['beschreibung']) && $_POST['beschreibung'] ? $_POST['beschreibung'] : "Überweisung";
      $zeit = time();
      $skip = false;
  
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
              $kasse = 5;
          } elseif ($insert_betrag < 0) {
              $konto = 8;
              $kasse = 3;
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
              echo "MySQL Fehler: " . htmlspecialchars(mysqli_error($conn));
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
    mysqli_stmt_bind_result($stmt, $name, $room, $pid, $turm);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    echo '<br><br><form method="post">';
    echo '<label for="uid" style="color: white; font-size: 40px;">'.htmlspecialchars((string)$name).'</label><br>';

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
    echo '<input type="hidden" name="uid" value="' . htmlspecialchars((string)$selected_uid) . '"><br>';
    echo '<button type="submit" name="newtransfer" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">'.htmlspecialchars("Eintragen").'</button>';
    echo '</form>';

    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>
        function toggleAdminPanel() {
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "";

            var inputToggle = document.createElement("input");
            inputToggle.type = "hidden";
            inputToggle.name = "toggleAdminPanel";
            inputToggle.value = "1";
            form.appendChild(inputToggle);

            var inputUid = document.createElement("input");
            inputUid.type = "hidden";
            inputUid.name = "uid";
            inputUid.value = ' . json_encode((string)$selected_uid) . ';
            form.appendChild(inputUid);

            document.body.appendChild(form);
            form.submit();
        }
    </script>';
  }
  
  echo '<style>
.payment-method-layout {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    justify-content: center;
    gap: 28px;
    width: min(1500px, 96%);
    margin: 0 auto;
    color: white;
    --pay-primary: #11a50d;
    --pay-primary-soft: rgba(17, 165, 13, 0.16);
    --pay-primary-border: rgba(17, 165, 13, 0.75);
    --pay-card-bg: rgba(255, 255, 255, 0.05);
    --pay-inner-bg: rgba(255, 255, 255, 0.07);
    --pay-border: rgba(255, 255, 255, 0.18);
    --pay-muted: rgba(255, 255, 255, 0.64);
    --pay-text-soft: rgba(255, 255, 255, 0.86);
}

.payment-method-column {
    flex: 1 1 0;
    min-width: 0;
    text-align: center;
    box-sizing: border-box;
}

.payment-card {
    height: 100%;
    max-width: 860px;
    margin: 0 auto;
    border: 2px solid var(--pay-primary-border);
    border-radius: 16px;
    background: var(--pay-card-bg);
    overflow: hidden;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08);
    box-sizing: border-box;
}

.payment-card-header {
    padding: 16px 20px;
    background: rgba(17, 165, 13, 0.22);
    border-bottom: 2px solid var(--pay-primary-border);
}

.payment-card-title {
    margin: 0;
    font-size: 36px;
    line-height: 1.15;
    color: white;
}

.payment-card-body {
    padding: 20px;
    box-sizing: border-box;
}

.payment-card-grid {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) minmax(260px, 1fr);
    gap: 18px;
    align-items: start;
}

.payment-section {
    text-align: left;
}

.payment-section-title {
    margin-bottom: 12px;
    color: var(--pay-primary);
    font-size: 15px;
    font-weight: 900;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.payment-item-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.payment-item {
    padding: 12px 14px;
    border: 1px solid var(--pay-border);
    border-radius: 10px;
    background: var(--pay-inner-bg);
}

.payment-note {
    border-color: var(--pay-primary-border);
    border-left: 7px solid var(--pay-primary);
    background: var(--pay-primary-soft);
}

.payment-label {
    margin-bottom: 4px;
    color: var(--pay-muted);
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.payment-value {
    color: white;
    font-size: 22px;
    font-weight: 900;
    line-height: 1.25;
    word-break: break-word;
}

.payment-note-title {
    margin-bottom: 4px;
    color: white;
    font-size: 17px;
    font-weight: 900;
}

.payment-note-text {
    color: var(--pay-text-soft);
    font-size: 16px;
    font-weight: 650;
    line-height: 1.4;
}

.paypal-form {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 14px;
    margin: 0;
}

.paypal-form-row {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
}

.paypal-label {
    color: var(--pay-muted);
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    text-align: left;
}

.paypal-select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--pay-border);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.95);
    color: #111;
    font-size: 20px;
    font-weight: 800;
    text-align: center;
    box-sizing: border-box;
}

.paypal-button {
    display: none !important;
    align-items: center;
    justify-content: center;
    width: 100%;
    margin: 0;
    font-size: 20px;
}

.paypal-button.is-visible {
    display: inline-flex !important;
}

.paypal-status {
    min-height: 22px;
    margin-top: 4px;
    font-size: 16px;
    font-weight: 900;
    line-height: 1.35;
    color: #ffd27d;
}

.paypal-status.is-ok {
    color: var(--pay-primary);
}

.paypal-status.is-fail {
    color: #ff3b3b;
}

.paypal-debug-note {
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid rgba(255, 210, 125, 0.65);
    border-left: 7px solid #ffd27d;
    border-radius: 10px;
    background: rgba(255, 210, 125, 0.08);
    color: #ffd27d;
    font-size: 16px;
    font-weight: 900;
    line-height: 1.4;
    text-align: left;
}

.payment-unavailable {
    padding: 12px 14px;
    border: 1px solid rgba(255, 59, 59, 0.75);
    border-left: 7px solid #ff3b3b;
    border-radius: 10px;
    background: rgba(255, 59, 59, 0.10);
    color: #ffb0b0;
    font-size: 17px;
    font-weight: 900;
    line-height: 1.4;
    text-align: left;
}

@media (max-width: 1150px) {
    .payment-method-layout {
        flex-direction: column;
        align-items: stretch;
    }

    .payment-card {
        max-width: 900px;
    }
}

@media (max-width: 760px) {
    .payment-card-grid {
        grid-template-columns: 1fr;
    }

    .payment-card-title {
        font-size: 30px;
    }

    .payment-card-body {
        padding: 16px;
    }
}
</style>';

echo '<div class="payment-method-layout">';

/* BANK */
echo '<div class="payment-method-column">';
echo '<div class="payment-card">';

echo '<div class="payment-card-header">';
echo '<h1 class="payment-card-title">Bank Transfer</h1>';
echo '</div>';

echo '<div class="payment-card-body">';
echo '<div class="payment-card-grid">';

echo '<div class="payment-section">';
echo '<div class="payment-section-title">Transfer details</div>';
echo '<div class="payment-item-list">';

echo '<div class="payment-item">';
echo '<div class="payment-label">Recipient name</div>';
echo '<div class="payment-value">WEH E.V. AACHEN</div>';
echo '</div>';

echo '<div class="payment-item">';
echo '<div class="payment-label">IBAN</div>';
echo '<div class="payment-value">DE90 3905 0000 1070 3346 00</div>';
echo '</div>';

echo '<div class="payment-item">';
echo '<div class="payment-label">Transfer reference</div>';
echo '<div class="payment-value">W' . htmlspecialchars((string)$_SESSION["user"], ENT_QUOTES) . 'H</div>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div class="payment-section">';
echo '<div class="payment-section-title">Important notes</div>';
echo '<div class="payment-item-list">';

echo '<div class="payment-item payment-note">';
echo '<div class="payment-note-title">Recipient warning</div>';
echo '<div class="payment-note-text">Some banking apps may show a warning that the recipient name does not match. You can ignore this warning if the IBAN is correct.</div>';
echo '</div>';

echo '<div class="payment-item payment-note">';
echo '<div class="payment-note-title">Transfer reference required</div>';
echo '<div class="payment-note-text">Use the exact transfer reference shown on the left. Otherwise, we cannot assign your payment to your account.</div>';
echo '</div>';

echo '<div class="payment-item payment-note">';
echo '<div class="payment-note-title">Processing time</div>';
echo '<div class="payment-note-text">Bank transfers can take a few days to appear in your WEH account.</div>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '</div>';
echo '</div>';

/* PAYPAL */
echo '<div class="payment-method-column">';
echo '<div class="payment-card">';

echo '<div class="payment-card-header">';
echo '<h1 class="payment-card-title">PayPal</h1>';
echo '</div>';

echo '<div class="payment-card-body">';
echo '<div class="payment-section">';
echo '<div class="payment-section-title">Fast payment</div>';
echo '<div class="payment-item-list">';

if ($paypalAllowed) {
    $www2HealthUrl = 'https://www2.weh.rwth-aachen.de/paypal_healthcheck.php?sandbox=' . ($DEBUG_PAYPAL ? '1' : '0');

    echo '<div class="payment-item">';
    echo '<form method="post" action="paypal.php" id="paypal_form" name="paypal-form" class="paypal-form">';

    echo '<div class="paypal-form-row">';
    echo '<label for="paypal-amount" class="paypal-label">Amount</label>';
    echo '<select id="paypal-amount" name="paypal-amount" class="paypal-select">';
    echo '<option value="5">5 € (0.35 € fee)</option>';
    echo '<option value="10">10 € (0.35 € fee)</option>';
    echo '<option value="20" selected>20 €</option>';
    echo '<option value="30">30 €</option>';
    echo '<option value="40">40 €</option>';
    echo '<option value="50">50 €</option>';
    echo '<option value="75">75 €</option>';
    echo '<option value="100">100 €</option>';
    echo '</select>';
    echo '</div>';

    echo '<button type="submit" id="paypal_transfer_btn" class="center-btn paypal-button" disabled>TRANSFER</button>';
    echo '<div id="paypal_health_status" class="paypal-status">PayPal-Verbindung wird geprüft…</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="payment-item payment-note">';
    echo '<div class="payment-note-title">Processing time</div>';
    echo '<div class="payment-note-text">PayPal payments usually appear in your WEH account within 1-2 minutes.</div>';
    echo '</div>';

    if ($DEBUG_PAYPAL && $isWebmaster) {
        echo '<div class="paypal-debug-note">';
        echo 'PayPal wurde mittelfristig deaktiviert. Hintergrund sind Accountprobleme, die durch die Kassenwarte behoben werden müssen. Diese Meldung ist nur für Webmaster sichtbar.';
        echo '</div>';
    }

    echo '<script>
    (function(){
        const healthUrl = ' . json_encode($www2HealthUrl) . ';
        const btn = document.getElementById("paypal_transfer_btn");
        const status = document.getElementById("paypal_health_status");
        const form = document.getElementById("paypal_form");

        function setFail(msg){
            btn.classList.remove("is-visible");
            btn.disabled = true;
            status.classList.remove("is-ok");
            status.classList.add("is-fail");
            status.textContent = msg;
        }

        function setOk(){
            btn.classList.add("is-visible");
            btn.disabled = false;
            status.classList.remove("is-fail");
            status.classList.add("is-ok");
            status.textContent = "";
        }

        form.addEventListener("submit", function(e){
            if (btn.disabled) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        const controller = new AbortController();
        const t = setTimeout(() => controller.abort(), 3500);

        fetch(healthUrl, { method: "GET", cache: "no-store", credentials: "omit", signal: controller.signal })
            .then(r => {
                clearTimeout(t);
                return r.json().catch(() => null).then(j => ({status:r.status, ok:r.ok, json:j}));
            })
            .then(res => {
                const j = res.json || {};
                if (res.ok && j && j.ok === true) {
                    setOk();
                    return;
                }

                if (res.status === 0) {
                    setFail("PayPal aktuell nicht verfügbar (www2 nicht erreichbar). Bitte später erneut versuchen.");
                } else if (res.status >= 300 && res.status < 400) {
                    setFail("PayPal aktuell nicht verfügbar (www2 Redirect). Bitte später erneut versuchen.");
                } else if (res.status === 403) {
                    setFail("PayPal aktuell nicht verfügbar (www2 Zugriff verweigert). Bitte später erneut versuchen.");
                } else {
                    setFail("PayPal aktuell nicht verfügbar (www2 Healthcheck nicht OK). Bitte später erneut versuchen.");
                }
            })
            .catch(err => {
                clearTimeout(t);
                if (err && err.name === "AbortError") {
                    setFail("PayPal aktuell nicht verfügbar (www2 Timeout). Bitte später erneut versuchen.");
                } else {
                    setFail("PayPal aktuell nicht verfügbar (www2 nicht erreichbar / TLS-Fehler). Bitte später erneut versuchen.");
                }
            });
    })();
    </script>';

} else {
    echo '<div class="payment-unavailable">';
    echo 'PayPal transfers are unavailable until further notice.';
    echo '</div>';
}

echo '</div>';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '</div>';

  if ($DEBUG_PAYPAL && $isWebmaster && !$PAYPAL_TEMPORARILY_DISABLED) {
    echo '<div style="margin: 20px auto 0 auto; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent; max-width: 95%;">';
    echo '<span class="white-text" style="font-size: 35px; cursor: pointer; display: inline-block;" onclick="togglePayPalDebugPanel()">PayPal Debug</span>';
    echo '<div id="paypalDebugPanel" style="display: ' . $_SESSION["PayPalDebugPanelToggleState"] . '; margin-top:14px;">';

    echo '<div style="padding:12px; border:2px dashed #ffd27d; max-width:900px; margin-left:auto; margin-right:auto; text-align:left; font-family:monospace; font-size:13px; color:#ffd27d; overflow-x:auto;">';
    echo '<div style="font-weight:900; margin-bottom:8px;">PAYPAL DEBUG</div>';

    echo 'HTTP_HOST: ' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? '-', ENT_QUOTES) . "<br>";
    echo 'SESSION[user]: ' . htmlspecialchars((string)($_SESSION['user'] ?? '-'), ENT_QUOTES) . "<br>";
    echo 'SESSION[uid]: ' . htmlspecialchars((string)($_SESSION['uid'] ?? '-'), ENT_QUOTES) . "<br>";
    echo 'selected_uid: ' . htmlspecialchars((string)($selected_uid ?? '-'), ENT_QUOTES) . "<br>";
    echo 'paypalAllowed: ' . ($paypalAllowed ? 'true' : 'false') . "<br>";
    echo '<hr style="border:0; border-top:1px solid #ffd27d; margin:10px 0;">';

    echo '<div style="font-weight:900; margin-bottom:6px;">Last rows in `paypal`</div>';

    $debugUid = null;
    if (isset($_SESSION['user']) && ctype_digit((string)$_SESSION['user'])) {
        $debugUid = (int)$_SESSION['user'];
    }

    if ($debugUid !== null) {
        $q = "SELECT id, uid, amount, request_time, txn_id, status, payer_email, complete_time
              FROM paypal
              WHERE uid = ?
              ORDER BY id DESC
              LIMIT 12";
        $st = $conn->prepare($q);
        if ($st) {
            $st->bind_param("i", $debugUid);
            $st->execute();
            $r = $st->get_result();

            if ($r && $r->num_rows > 0) {
                echo '<table style="width:100%; border-collapse:collapse; color:#ffd27d;">';
                echo '<tr><th style="border-bottom:1px solid #ffd27d; text-align:left;">id</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">amt</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">req</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">status</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">txn</th></tr>';

                while ($row = $r->fetch_assoc()) {
                    $req = !empty($row['request_time']) ? date('d.m H:i', (int)$row['request_time']) : '-';
                    $cmp = !empty($row['complete_time']) ? date('d.m H:i', (int)$row['complete_time']) : '-';

                    echo '<tr>';
                    echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)$row['id'], ENT_QUOTES) . '</td>';
                    echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)$row['amount'], ENT_QUOTES) . '</td>';
                    echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars($req, ENT_QUOTES) . '</td>';
                    echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)($row['status'] ?? '-'), ENT_QUOTES) . '</td>';
                    echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)($row['txn_id'] ?? '-'), ENT_QUOTES) . '</td>';
                    echo '</tr>';

                    if (!empty($row['payer_email']) || !empty($row['complete_time'])) {
                        echo '<tr><td colspan="5" style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25); opacity:0.9;">';
                        echo 'payer=' . htmlspecialchars((string)($row['payer_email'] ?? '-'), ENT_QUOTES) . ' | complete=' . htmlspecialchars($cmp, ENT_QUOTES);
                        echo '</td></tr>';
                    }
                }

                echo '</table>';
            } else {
                echo 'No rows for uid=' . htmlspecialchars((string)$debugUid, ENT_QUOTES) . '<br>';
            }

            $st->close();
        } else {
            echo 'Prepare failed (paypal debug query): ' . htmlspecialchars($conn->error, ENT_QUOTES) . '<br>';
        }
    } else {
        echo 'SESSION[user] is not numeric -> cannot filter `paypal` by uid. (check what SESSION[user] contains)<br>';
    }

    echo '<hr style="border:0; border-top:1px solid #ffd27d; margin:10px 0;">';

    echo '<div style="font-weight:900; margin-bottom:6px;">Last transfers with kasse=69</div>';
    $qt = "SELECT id, uid, tstamp, betrag, beschreibung
           FROM transfers
           WHERE kasse = 69
           ORDER BY id DESC
           LIMIT 12";
    $st2 = $conn->prepare($qt);

    if ($st2) {
        $st2->execute();
        $r2 = $st2->get_result();

        if ($r2 && $r2->num_rows > 0) {
            echo '<table style="width:100%; border-collapse:collapse; color:#ffd27d;">';
            echo '<tr><th style="border-bottom:1px solid #ffd27d; text-align:left;">id</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">uid</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">time</th><th style="border-bottom:1px solid #ffd27d; text-align:left;">amt</th></tr>';

            while ($row = $r2->fetch_assoc()) {
                $ts = !empty($row['tstamp']) ? date('d.m H:i', (int)$row['tstamp']) : '-';

                echo '<tr>';
                echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)$row['id'], ENT_QUOTES) . '</td>';
                echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)$row['uid'], ENT_QUOTES) . '</td>';
                echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars($ts, ENT_QUOTES) . '</td>';
                echo '<td style="padding:4px 6px; border-bottom:1px solid rgba(255,210,125,0.25);">' . htmlspecialchars((string)$row['betrag'], ENT_QUOTES) . '</td>';
                echo '</tr>';
            }

            echo '</table>';
        } else {
            echo 'No transfers with kasse=69 found.<br>';
        }

        $st2->close();
    } else {
        echo 'Prepare failed (transfers debug query): ' . htmlspecialchars($conn->error, ENT_QUOTES) . '<br>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<script>
        function togglePayPalDebugPanel() {
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "";

            var inputToggle = document.createElement("input");
            inputToggle.type = "hidden";
            inputToggle.name = "togglePayPalDebugPanel";
            inputToggle.value = "1";
            form.appendChild(inputToggle);

            var inputUid = document.createElement("input");
            inputUid.type = "hidden";
            inputUid.name = "uid";
            inputUid.value = ' . json_encode((string)$selected_uid) . ';
            form.appendChild(inputUid);

            document.body.appendChild(form);
            form.submit();
        }
    </script>';
  }

  echo "<br><br><br><hr><br><br>";
  
  if ($selected_uid != 472 && $selected_uid != 2524 && $selected_uid != 492) {
    $sql = "SELECT SUM(betrag) FROM transfers WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_uid);
    mysqli_stmt_execute($stmt);
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
  echo '<input type="hidden" name="uid" value="' . htmlspecialchars((string)$selected_uid) . '">';
  echo '<select name="konto" onchange="this.form.submit()" style="font-size: 20px;text-align: center;">';

  foreach ($kontos as $id => $name) {
    $selected = "";
    if (isset($_POST['konto']) && $_POST['konto'] == $id) {
      $selected = "selected";
    }
    echo '<option value="' . $id . '" ' . $selected . '>' . htmlspecialchars($name) . '</option>';
  }

  echo '</select>';
  echo '</form>';
  echo '</div>';
  echo '<br><br>';
  
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
  mysqli_stmt_bind_result($stmt, $id, $tstamp, $beschreibung, $betrag);

  echo "<table class='grey-table'>";
  echo "<tr><th>ID</th><th>Date</th><th>Text</th><th>Amount</th></tr>";
  
  while (mysqli_stmt_fetch($stmt)) {
      echo '<tr onclick="document.getElementById(\'form-'.$id.'\').submit();" style="cursor: pointer;">';
      echo '<form id="form-'.$id.'" action="UserKonto.php" method="post">';
      echo "<td>".htmlspecialchars((string)$id)."</td>";
      echo "<td>".date("d.m.Y", $tstamp)."</td>";
      echo "<td>".(strlen($beschreibung) > 50 ? substr(htmlspecialchars($beschreibung), 0, 50)."[...]" : htmlspecialchars($beschreibung))."</td>";
      echo "<td>".number_format($betrag, 2, ",", ".")." €</td>";
      echo '<input type="hidden" name="transfer_id" value="'.htmlspecialchars((string)$id).'">';
      echo '<input type="hidden" name="popup" value="true">';
      echo '<input type="hidden" name="uid" value="' . htmlspecialchars((string)$selected_uid) . '">';
      echo '</form>';
      echo "</tr>";
  }
  
  echo "</table>";
  mysqli_stmt_free_result($stmt);
  
  echo '<br><br>';

  echo '
  <div style="display: flex; justify-content: center; align-items: center;">
    <form method="post">
      <input type="hidden" name="uid" value="' . htmlspecialchars((string)$selected_uid) . '"><br>
      <button type="submit" name="printtable" class="center-btn" style="font-size: 20px;">PDF erstellen</button>
    </form>
  </div><br><br>
  ';  

  if (isset($_POST['transfer_id']) && isset($_POST['popup'])) {
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

    $formatted_date = date("d.m.Y H:i", $selected_transfer_tstamp);

    echo ('<div class="overlay"></div>
    <div class="anmeldung-form-container form-container">
      <form method="post">
          <input type="hidden" name="uid" value="' . htmlspecialchars($_POST['uid']) . '">
          <button type="submit" name="close" value="close" class="close-btn">X</button>
      </form>
    <br>');

    echo '<div style="text-align: center;">';

    if ($editable) {
      echo '<form method="post">';
      echo '<input type="hidden" name="uid" value="' . htmlspecialchars($_POST['uid']) . '">';
      echo '<input type="hidden" name="transfer_id" value="'.htmlspecialchars($_POST['transfer_id']).'">';
      echo '<input type="hidden" name="ausgangs_betrag" value="'.number_format($selected_transfer_betrag, 2, ".", "").'">';
      echo '<input type="hidden" name="ausgangs_uid" value="'.htmlspecialchars((string)$selected_transfer_uid).'">';
      echo '<input type="hidden" name="ausgangs_konto" value="'.htmlspecialchars((string)$selected_transfer_konto).'">';
      echo '<input type="hidden" name="ausgangs_kasse" value="'.htmlspecialchars((string)$selected_transfer_kasse).'">';
      echo '<input type="hidden" name="ausgangs_beschreibung" value="'.htmlspecialchars((string)$selected_transfer_beschreibung).'">';
    }
    
    echo '<div style="text-align: center; color: lightgrey;">';
    echo 'Transfer ID: <span style="color:white;">'.htmlspecialchars($_POST['transfer_id']).'</span>';
    echo '</div>';
    echo '<br><br>';
    
    if (!$editable) {
        $query_user = "SELECT name, room, turm FROM users WHERE uid = ?";
        $stmt_user = $conn->prepare($query_user);
        $stmt_user->bind_param("i", $selected_transfer_uid);
        $stmt_user->execute();
        $stmt_user->bind_result($selected_user_name, $selected_user_room, $selected_user_turm);
        $stmt_user->fetch();
        $stmt_user->close();

        $formatted_turm = ($selected_user_turm == 'tvk') ? 'TvK' : strtoupper($selected_user_turm);

        echo '<label for="user_info" style="color:lightgrey;">Benutzerinformationen:</label><br>';
        echo '<p style="color:white !important;">' . htmlspecialchars($selected_user_name) . ' [' . htmlspecialchars($formatted_turm) . ' ' . htmlspecialchars($selected_user_room) . ']</p><br>';
    } else {
        echo '<label for="selected_user" style="color:lightgrey;">Benutzer auswählen:</label><br>';
        echo '<select name="selected_user" id="selected_user" style="margin-top: 10px; padding: 5px; text-align: center; text-align-last: center; display: block; margin-left: auto; margin-right: auto;">
                <option value="" disabled selected>Wähle einen Benutzer</option>';
        echo '<option value="472" ' . (472 == $selected_transfer_uid ? 'selected' : '') . '>NetzAG-Dummy</option>';
        echo '<option value="492" ' . (492 == $selected_transfer_uid ? 'selected' : '') . '>Vorstand-Dummy</option>';
        
        $sql = "SELECT uid, name, room, turm 
                FROM users 
                ORDER BY pid, FIELD(turm, 'weh', 'tvk'), room";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
        
        while (mysqli_stmt_fetch($stmt)) {
            $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
            echo '<option value="' . htmlspecialchars((string)$uid) . '" ' . ($uid == $selected_transfer_uid ? 'selected' : '') . '>' . htmlspecialchars($name) . ' [' . htmlspecialchars($formatted_turm) . ' ' . htmlspecialchars($room) . ']</option>';
        }
        
        echo '</select><br><br>';
        mysqli_stmt_close($stmt);
    }

    echo '<br>';

    echo '<label style="color:lightgrey;">Beschreibung:</label><br>';
    if (!$editable) {
        echo '<p style="color:white !important;">'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'</p><br>';
    } else {
        echo '<input type="text" name="beschreibung" value="'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'" style="text-align: center; width: 80%;"><br><br>';
    }

    echo '<br>';

    echo '<div style="display: flex; gap: 20px; justify-content: space-between; align-items: flex-start;">';

    echo '<div style="flex: 1; max-width: 100%;">';
    echo '<label style="color:lightgrey;">Konto:</label><br>';
    $konto_display = isset($konto_options[$selected_transfer_konto]) ? $konto_options[$selected_transfer_konto] : "Undefiniertes Konto";

    if (!$editable) {
        echo '<p style="color:white !important;">'.htmlspecialchars($konto_display).'</p><br>';
    } else {
        echo '<select name="konto_update" style="text-align: center; width: 100%;">';
        foreach ($konto_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_konto ? 'selected' : '').'>'.htmlspecialchars($value).'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>';
    
    echo '<div style="flex: 1; max-width: 100%;">';
    echo '<label style="color:lightgrey;">Kasse:</label><br>';
    $kasse_display = isset($kasse_options[$selected_transfer_kasse]) ? $kasse_options[$selected_transfer_kasse] : "Undefinierte Kasse";

    if (!$editable) {
        echo '<p style="color:white !important;">'.htmlspecialchars($kasse_display).'</p><br>';
    } else {
        echo '<select name="kasse" style="text-align: center; width: 100%;">';
        foreach ($kasse_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_kasse ? 'selected' : '').'>'.htmlspecialchars($value).'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>';
    
    echo '</div>';
    echo '<br>';

    echo '<label style="color:lightgrey;">Betrag:</label><br>';
    if (!$editable) {
      echo '<p style="color:white !important;">'.number_format($selected_transfer_betrag, 2, ",", ".").' €</p><br>';
    } else {
      echo '<input type="text" name="betrag" value="'.number_format($selected_transfer_betrag, 2, ",", ".").'" style="text-align: center;"><br><br>';
    }

    if ($editable) {
      echo '<br>';
      echo '<label style="color:lightgrey;">Changelog:</label><br><br>';
      echo '<div style="background-color: darkblue; color: white; font-family: monospace; padding: 10px; display: inline-block; text-align: center; width: calc(100% - 30px); max-height: 200px; overflow-y: auto; box-sizing: border-box;">'; 
      echo '<p style="margin: 0; line-height: 1.4; font-size: 14px; white-space: pre-wrap;">'.(!is_null($selected_transfer_changelog) ? htmlspecialchars($selected_transfer_changelog) : 'Kein Changelog verfügbar').'</p>';
      echo '</div>';
      echo '<br>';
    }

    if ($editable) {
        echo '<div style="display: flex; justify-content: center; margin-top: 20px;">';
        echo '<button type="submit" name="save_transfer_id" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Speichern</button>';
        echo '</form>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
  }
} else {
  header("Location: denied.php");
}

$conn->close();
?>
</body>
</html>