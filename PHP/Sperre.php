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
      setTimeout(function () {
        button.style.backgroundColor = "";
        button.innerHTML = "Speichern";
      }, 2000);
    }
  </script>
</head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && ($_SESSION['NetzAG'] || $_SESSION['Vorstand'] || $_SESSION["TvK-Sprecher"])) {
  load_menu();

  function sperroptionen() {
    echo '<label for="starttime" class="sperre_form-label">Startzeit:</label>';
    echo '<input type="datetime-local" name="starttime" id="starttime" class="sperre_form-control">';
    echo '<button type="button" onclick="setLocalTime()">Aktuelle Zeit</button><br><br>';

    echo '<script>
      function setLocalTime() {
        var now = new Date();
        var timezoneOffset = now.getTimezoneOffset() * 60000;
        var localISOTime = new Date(Date.now() - timezoneOffset).toISOString().slice(0, 16);
        document.getElementById("starttime").value = localISOTime;
      }
    </script>';

    echo "<br>";

    echo '<label for="endtime" class="sperre_form-label">Endzeit:</label>';
    echo '<input type="datetime-local" name="endtime" id="endtime" class="sperre_form-control"><br><br>';

    if ($_SESSION['NetzAG']) {
      echo '<table class="sperre-table">';
      echo '<tr><td><label for="mail" class="sperre_form-label">Rundmails:</label></td><td><input type="checkbox" name="mail" id="mail" value="1" size="2"></td></tr>';
    }

    if ($_SESSION['NetzAG']) {
      echo '<tr><td><label for="internet" class="sperre_form-label">Internet:</label></td><td><input type="checkbox" name="internet" id="internet" value="1" size="2"></td></tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WEH-WaschAG']) {
      echo '<tr><td><label for="waschen" class="sperre_form-label">Waschen:</label></td><td><input type="checkbox" name="waschen" id="waschen" value="1" size="2"></td></tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WohnzimmerAG'] || $_SESSION['SportAG']) {
      echo '<tr><td><label for="buchen" class="sperre_form-label">Buchen:</label></td><td><input type="checkbox" name="buchen" id="buchen" value="1" size="3"></td></tr>';
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WerkzeugAG']) {
      echo '<tr><td><label for="werkzeugbuchen" class="sperre_form-label">Werkzeug buchen:</label></td><td><input type="checkbox" name="werkzeugbuchen" id="werkzeugbuchen" value="1" size="3"></td></tr>';
    }

    if ($_SESSION['NetzAG']) {
      echo '<tr><td><label for="drucken" class="sperre_form-label">Drucken:</label></td><td><input type="checkbox" name="drucken" id="drucken" value="1" size="2"></td></tr>';
    }

    echo '</table>';
  }

  if (isset($_POST['sperre_new'])) {
    echo '<div class="sperre_container">';
    echo '<form method="POST" class="sperre_form">';

    echo '<label for="uid" class="sperre_form-label">Benutzer auswählen:</label>';
    echo '<select name="uid" id="uid" class="sperre_form-select">
            <option value="" disabled selected>Wähle einen Benutzer</option>';

    $sql = "SELECT uid, name, room, turm
            FROM users
            WHERE pid = 11
            ORDER BY FIELD(turm, 'weh', 'tvk'), room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);

    while (mysqli_stmt_fetch($stmt)) {
      $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
      echo '<option value="' . (int)$uid . '">' . htmlspecialchars($name) . ' [' . $formatted_turm . ' ' . (int)$room . ']</option>';
    }
    mysqli_stmt_close($stmt);

    echo "</select>";
    echo "<br><br><br>";

    SperrOptionen();
    echo "<br>";

    echo '<label for="beschreibung" class="sperre_form-label">Grund:</label>';
    echo '<input type="text" name="beschreibung" id="beschreibung" required class="sperre_form-control"><br><br>';

    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="sperre_exec" value="Sperre erstellen" class="center-btn">';
    echo '</div>';

    echo '</form>';
    echo '</div>';
  } elseif (isset($_POST['sperre_etage'])) {
    echo '<div class="sperre_container">';
    echo '<form method="POST" class="sperre_form">';

    echo '<label for="turm" class="sperre_form-label">Turm:</label>';
    echo '<select name="turm" id="turm" class="sperre_form-select">';
    echo '<option value="">Bitte wählen</option>';
    echo '<option value="weh">WEH</option>';
    echo '<option value="tvk">TvK</option>';
    echo '</select><br>';

    echo '<label for="etage" class="sperre_form-label">Etage:</label>';
    echo '<select name="etage" id="etage" class="sperre_form-select">';
    echo '<option value="">Bitte wählen</option>';
    for ($i = 0; $i <= 17; $i++) {
      echo '<option value="' . (int)$i . '">' . (int)$i . '</option>';
    }
    echo '</select><br>';

    echo "<br>";

    SperrOptionen();
    echo "<br>";

    echo '<label for="beschreibung" class="sperre_form-label">Grund:</label>';
    echo '<input type="text" name="beschreibung" id="beschreibung" required class="sperre_form-control"><br><br>';

    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="sperre_etage_exec" value="Sperren erstellen" class="center-btn">';
    echo '</div>';

    echo '</form>';
    echo '</div>';
  } elseif (isset($_POST['sperre_ruhestoerung'])) {
    echo '<div class="sperre_container">';
    echo '<form method="POST" class="sperre_form">';

    echo '<label for="uid_ruhe" class="sperre_form-label">Benutzer auswählen:</label>';
    echo '<select name="uid" id="uid_ruhe" class="sperre_form-select" required>
            <option value="" disabled selected>Wähle einen Benutzer</option>';

    $sql = "SELECT uid, name, room, turm
            FROM users
            WHERE pid = 11
            ORDER BY FIELD(turm, 'weh', 'tvk'), room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);

    while (mysqli_stmt_fetch($stmt)) {
      $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
      echo '<option value="' . (int)$uid . '">' . htmlspecialchars($name) . ' [' . $formatted_turm . ' ' . (int)$room . ']</option>';
    }
    mysqli_stmt_close($stmt);

    echo "</select>";
    echo "<br><br>";

    echo '<label for="starttime_ruhe" class="sperre_form-label">Startzeit:</label>';
    echo '<input type="datetime-local" name="starttime" id="starttime_ruhe" class="sperre_form-control" required>';
    echo '<button type="button" onclick="setLocalTimeRuhe()">Aktuelle Zeit</button><br><br>';

    echo '<script>
      function setLocalTimeRuhe() {
        var now = new Date();
        var timezoneOffset = now.getTimezoneOffset() * 60000;
        var localISOTime = new Date(Date.now() - timezoneOffset).toISOString().slice(0, 16);
        document.getElementById("starttime_ruhe").value = localISOTime;
      }
    </script>';

    echo '<div style="color: white; font-size: 16px; line-height: 1.35; margin: 10px 0 20px 0; text-align:center;">';
    echo 'Der User wird von allen Vereinseinrichtungen gesperrt und erhält 50€ Strafgeld.<br>';
    echo 'Die Sperre endet, sobald sein Konto wieder ausgeglichen ist.<br>';
    echo 'Der User wird automatisch über die Sperre und die Möglichkeit zum Entsperren via Mail informiert.';
    echo '</div>';

    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="sperre_ruhestoerung_exec" value="Sperre erstellen" class="center-btn">';
    echo '</div>';

    echo '</form>';
    echo '</div>';
  } else {

    if (isset($_POST['sperre_ruhestoerung_exec'])) {
      $zeit = time();
      $user_id = (int)($_POST['uid'] ?? 0);
      $agent = (int)($_SESSION['uid'] ?? 0);
      $starttime = strtotime($_POST['starttime'] ?? '') ?: 0;

      $endtime = 2147483647;
      $beschreibung = "Ruhestörung";
      $mail = 1;
      $internet = 1;
      $waschen = 1;
      $buchen = 1;
      $drucken = 1;
      $werkzeugbuchen = 1;
      $missedpayment = 1;

      if ($user_id <= 0 || $starttime <= 0) {
        echo "<p style='color:red; text-align:center;'>Fehler: Bitte Benutzer und Startzeit wählen.</p>";
      } else {

        $date = date("d.m.Y");
        $stringie = utf8_decode("\n" . $date . " Sperre " . $beschreibung . " (" . ($_SESSION['username'] ?? 'Agent') . ")");
        $sql = "UPDATE users SET historie = CONCAT(historie, ?) WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $stringie, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $insert_sql = "INSERT INTO sperre
          (uid, tstamp, starttime, endtime, agent, beschreibung, mail, internet, waschen, buchen, drucken, werkzeugbuchen, missedpayment)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param(
          $stmt,
          "iiiiisiiiiiii",
          $user_id, $zeit, $starttime, $endtime, $agent, $beschreibung,
          $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen, $missedpayment
        );
        mysqli_stmt_execute($stmt);
        $sperre_ok = (mysqli_stmt_errno($stmt) === 0);
        $sperre_err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        $dt_de = date('d.m.Y H:i');
        $changelog = "[" . $dt_de . "] Sperre wegen Ruhestörung, Agent " . $agent;

        $tr_sql = "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, agent, changelog)
                  VALUES (?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $tr_sql);
        $tr_beschr = "Ruhestörung";
        $konto = 0;
        $kasse = 3;
        $betrag = -50.0;
        mysqli_stmt_bind_param($stmt, "iisiidis", $user_id, $zeit, $tr_beschr, $konto, $kasse, $betrag, $agent, $changelog);
        mysqli_stmt_execute($stmt);
        $tr_ok = (mysqli_stmt_errno($stmt) === 0);
        $tr_err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        $sum = 0.0;
        $sum_sql = "SELECT COALESCE(SUM(betrag), 0) FROM transfers WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sum_sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $sum);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $need = max(0.0, -1.0 * (float)$sum);
        $need_str = number_format($need, 2, '.', '');

        $username = '';
        $turm = '';
        $u_sql = "SELECT username, turm, firstname FROM users WHERE uid = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $u_sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $username, $turm, $userfirstname);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $username = trim((string)$username);
        $turm = trim((string)$turm);
        $userfirstname = trim((string)$userfirstname);

        $to = ($username !== '' && $turm !== '') ? ($username . "@" . $turm . ".rwth-aachen.de") : '';
        $subject = "Suspension due to noise disturbance";

        $vwz = "W" . $user_id . "H";
        $start_de = date('d.m.Y H:i', $starttime);
        $msgLines = [
          "Hello {$userfirstname},",
          "",
          "your WEH account has been suspended due to noise disturbance. The suspension becomes effective on {$start_de}. From that time on, you are blocked from all WEH facilities and services.",
          "",
          "This measure is based on the Vollversammlung decision dated 02.07.2025 and the corresponding measures catalog:",
          "\"Bei einem Verstoß gegen die Nachtruhe in Wohnzimmer oder Lernraum wird eine Sperre für Internet-, Wasch- und Raumbuchungen verhängt sowie eine Geldstrafe in Höhe von 50 € auf das Vereinskonto erhoben. Die Sperre bleibt solange bestehen, bis der fällige Betrag vollständig beglichen wurde.\"",
          "",
          "To restore access, please settle your outstanding balance by transferring {$need_str} € to:",
          "",
          "IBAN: DE90 3905 0000 1070 3346 00",
          "Reference: {$vwz}",
          "",
          "Once your balance is fully settled, the suspension will be lifted automatically.",
          "",
          "Best regards,",
          "WEH Administration",
        ];

        $msg = implode("\n", $msgLines);

        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "From: system@weh.rwth-aachen.de";
        $headers[] = "Reply-To: haussprecher@weh.rwth-aachen.de";
        $headers[] = "Cc: netag@weh.rwth-aachen.de, wohnzimmer@weh.rwth-aachen.de, haussprecher@weh.rwth-aachen.de";

        $mail_ok = false;
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
          $mail_ok = mail($to, $subject, $msg, implode("\r\n", $headers));
        }

        if (!$sperre_ok) {
          echo "MySQL Fehler (sperre): " . htmlspecialchars($sperre_err);
        } elseif (!$tr_ok) {
          echo "MySQL Fehler (transfers): " . htmlspecialchars($tr_err);
        } else {
          echo "<p style='color:green; text-align:center;'>Sperre wegen Ruhestörung erfolgreich eingestellt. (E-Mail: " . ($mail_ok ? "gesendet" : "nicht gesendet") . ")</p>";
        }

      }
    }

    if (isset($_POST['sperre_exec'])) {
      $zeit = time();
      $user_id = (int)($_POST['uid'] ?? 0);
      $agent = (int)($_SESSION['uid'] ?? 0);
      $starttime = strtotime($_POST['starttime'] ?? '') ?: 0;
      $endtime = strtotime($_POST['endtime'] ?? '') ?: 0;
      $mail = isset($_POST['mail']) ? (int)$_POST['mail'] : 0;
      $internet = isset($_POST['internet']) ? (int)$_POST['internet'] : 0;
      $waschen = isset($_POST['waschen']) ? (int)$_POST['waschen'] : 0;
      $buchen = isset($_POST['buchen']) ? (int)$_POST['buchen'] : 0;
      $werkzeugbuchen = isset($_POST['werkzeugbuchen']) ? (int)$_POST['werkzeugbuchen'] : 0;
      $drucken = isset($_POST['drucken']) ? (int)$_POST['drucken'] : 0;
      $beschreibung = (string)($_POST['beschreibung'] ?? "");

      $date = date("d.m.Y");
      $stringie = utf8_decode("\n" . $date . " Sperre " . $beschreibung . " (" . ($_SESSION['username'] ?? '') . ")");
      $sql = "UPDATE users SET historie = CONCAT(historie, ?) WHERE uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "si", $stringie, $user_id);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);

      $insert_sql = "INSERT INTO sperre (uid, tstamp, starttime, endtime, agent, beschreibung, mail, internet, waschen, buchen, drucken, werkzeugbuchen)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_bind_param($stmt, "iiiiisiiiiii", $user_id, $zeit, $starttime, $endtime, $agent, $beschreibung, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen);
      mysqli_stmt_execute($stmt);

      if (mysqli_stmt_errno($stmt) !== 0) {
        echo "MySQL Fehler: " . htmlspecialchars(mysqli_stmt_error($stmt));
      } else {
        echo "<p style='color:green; text-align:center;'>Sperre erfolgreich eingestellt.</p>";
      }
      mysqli_stmt_close($stmt);
    }

    if (isset($_POST['sperre_etage_exec'])) {
      $zeit = time();
      $turm = (string)($_POST['turm'] ?? '');
      $etage = (string)($_POST['etage'] ?? '');
      $agent = (int)($_SESSION['uid'] ?? 0);
      $starttime = strtotime($_POST['starttime'] ?? '') ?: 0;
      $endtime = strtotime($_POST['endtime'] ?? '') ?: 0;
      $mail = isset($_POST['mail']) ? (int)$_POST['mail'] : 0;
      $internet = isset($_POST['internet']) ? (int)$_POST['internet'] : 0;
      $waschen = isset($_POST['waschen']) ? (int)$_POST['waschen'] : 0;
      $buchen = isset($_POST['buchen']) ? (int)$_POST['buchen'] : 0;
      $drucken = isset($_POST['drucken']) ? (int)$_POST['drucken'] : 0;
      $werkzeugbuchen = isset($_POST['werkzeugbuchen']) ? (int)$_POST['werkzeugbuchen'] : 0;
      $beschreibung = (string)($_POST['beschreibung'] ?? "");

      $zimmernummern = range(1, 16);
      $zimmer_array = array_map(function($zimmer) use ($etage) {
        return intval($etage . sprintf("%02d", $zimmer));
      }, $zimmernummern);

      $select_sql = "SELECT uid FROM users WHERE room=? AND turm=? AND pid in (11,12,13,14) ORDER BY room ASC";
      $stmt = mysqli_prepare($conn, $select_sql);

      $uids = [];
      foreach ($zimmer_array as $zimmer) {
        mysqli_stmt_bind_param($stmt, "is", $zimmer, $turm);
        mysqli_stmt_bind_result($stmt, $uid);
        mysqli_stmt_execute($stmt);
        while (mysqli_stmt_fetch($stmt)) {
          $uids[] = (int)$uid;
        }
      }
      mysqli_stmt_close($stmt);

      $success = true;
      foreach ($uids as $uid) {
        $insert_sql = "INSERT INTO sperre (uid, tstamp, starttime, endtime, mail, internet, waschen, buchen, drucken, beschreibung, agent, werkzeugbuchen)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt2 = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($stmt2, "iiiiiiiiisii", $uid, $zeit, $starttime, $endtime, $mail, $internet, $waschen, $buchen, $drucken, $beschreibung, $agent, $werkzeugbuchen);
        mysqli_stmt_execute($stmt2);

        if (mysqli_stmt_errno($stmt2) !== 0) {
          $success = false;
          mysqli_stmt_close($stmt2);
          break;
        }
        mysqli_stmt_close($stmt2);
      }

      if ($success) {
        echo "<p style='color:green; text-align:center;'>Etagensperre erfolgreich eingestellt.</p>";
      } else {
        echo "MySQL Fehler: " . htmlspecialchars(mysqli_error($conn));
      }
    }

    if (isset($_POST['sperre_end'])) {
      $zeit = time() - 10;
      $id = (int)$_POST['sperre_end'];

      $sql = "UPDATE sperre SET endtime = ? WHERE id = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "ii", $zeit, $id);
      mysqli_stmt_execute($stmt);

      if (mysqli_stmt_errno($stmt) !== 0) {
        echo "MySQL Fehler: " . htmlspecialchars(mysqli_stmt_error($stmt));
      } else {
        echo "<p style='color:green; text-align:center;'>Sperre erfolgreich beendet.</p>";
      }
      mysqli_stmt_close($stmt);
    }

    if ($_SESSION['NetzAG'] || $_SESSION['WerkzeugAG'] || $_SESSION['WohnzimmerAG'] || $_SESSION['SportAG'] || $_SESSION['WEH-WaschAG']) {
      echo "<form method='post'><div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'><button type='submit' name='sperre_new' class='center-btn'>Neue Usersperre</button></div></form>";
      echo "<form method='post'><div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'><button type='submit' name='sperre_etage' class='center-btn'>Neue Etagensperre</button></div></form>";
      echo "<form method='post'><div style='display: flex; justify-content: center; margin-top: 1%; margin-bottom: 1%'><button type='submit' name='sperre_ruhestoerung' class='center-btn'>Neue Sperre wegen Ruhestörung</button></div></form>";
      echo '<br><br><hr style="border-color: #fff;"><br><br>';
    }

    echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
    echo '<div style="text-align: center; font-size: 40px; color: white;">Manuelle Sperren:</div>';
    echo '</div>';

    $zeit = time();
    $sql = "SELECT sperre.id, sperre.beschreibung, sperre.starttime, sperre.endtime, users.room, users.turm, users.name, users.uid,
                   sperre.mail, sperre.internet, sperre.waschen, sperre.buchen, sperre.drucken, sperre.werkzeugbuchen
            FROM users
            JOIN sperre ON users.uid = sperre.uid
            WHERE sperre.endtime >= ? AND sperre.missedconsultation != 1 AND sperre.missedpayment != 1
            ORDER BY FIELD(users.turm, 'weh', 'tvk'), users.room ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $zeit);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) > 0) {
      mysqli_stmt_bind_result($stmt, $id, $beschreibung, $starttime, $endtime, $room, $turm, $name, $uid, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen);

      echo '<table class="grey-table">
        <tr>
          <th>Name</th>
          <th>Turm</th>
          <th>Raum</th>
          <th>Grund</th>
          <th>Art</th>
          <th>Startzeit</th>
          <th>Endzeit</th>
          <th>Beenden</th>
        </tr>';

      while (mysqli_stmt_fetch($stmt)) {
        $turm4ausgabe = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);

        echo "<form method='POST' action='User.php' target='_blank' style='display: none;' id='form_{$uid}'>
                <input type='hidden' name='id' value='{$uid}'>
              </form>";

        echo "<tr onclick='document.getElementById(\"form_{$uid}\").submit();' style='cursor: pointer;'>";
        echo "<td>" . htmlspecialchars($name) . "</td>";
        echo "<td>" . htmlspecialchars($turm4ausgabe) . "</td>";
        echo "<td>" . (int)$room . "</td>";
        echo "<td>" . htmlspecialchars($beschreibung) . "</td>";

        $types = [
          $mail == 1 ? "Rundmails" : null,
          $internet == 1 ? "Internet" : null,
          $waschen == 1 ? "Waschen" : null,
          $buchen == 1 ? "Buchen" : null,
          $drucken == 1 ? "Drucken" : null,
          $werkzeugbuchen == 1 ? "Werkzeugbuchen" : null
        ];
        echo "<td>" . implode(', ', array_filter($types)) . "</td>";

        $formattedStartTime = date('d.m.Y H:i', $starttime);
        $formattedEndTime = date('d.m.Y H:i', $endtime);

        echo "<td>" . $formattedStartTime . "</td>";
        echo "<td>" . ($endtime == 2147483647 ? "Unbegrenzt" : $formattedEndTime) . "</td>";

        echo '<td>';
        echo '<form method="post" action="" style="margin: 0;" onClick="event.stopPropagation();">';
        echo '<button type="submit" name="sperre_end" value="' . (int)$id . '" style="background: none; border: none; cursor: pointer; padding: 0;">';
        echo '<img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">';
        echo '</button>';
        echo '</form>';
        echo '</td>';

        echo "</tr>";
      }

      echo '</table>';
    } else {
      echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
      echo '<div style="text-align: center; font-size: 25px; color: white;">Aktuell sind keine manuellen Sperren eingetragen.</div>';
      echo '</div>';
    }
    mysqli_stmt_close($stmt);

    echo '<br><br><hr style="border-color: #fff;"><br><br>';

    echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
    echo '<div style="text-align: center; font-size: 40px; color: white;">Automatische Sperren:</div>';
    echo '<br>';
    echo '<div style="text-align: center; font-size: 25px; color: white;">';
    echo 'Sperren wegen fehlendem Guthaben oder Fernbleiben der Anmeldung.<br>Entsperrung erfolgt durch Besuch der Sprechstunde oder Auffüllen des Guthabens automatisch.';
    echo '</div>';
    echo '</div>';
    echo '<br>';

    $sql = "SELECT sperre.id, sperre.beschreibung, sperre.starttime, sperre.endtime, users.room, users.turm, users.name, users.uid,
                   sperre.mail, sperre.internet, sperre.waschen, sperre.buchen, sperre.drucken, sperre.werkzeugbuchen, sperre.missedconsultation, sperre.missedpayment
            FROM users
            JOIN sperre ON users.uid = sperre.uid
            WHERE sperre.endtime >= ? AND (sperre.missedconsultation = 1 OR sperre.missedpayment = 1)
            ORDER BY users.room ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $zeit);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $beschreibung, $starttime, $endtime, $room, $turm, $name, $uid, $mail, $internet, $waschen, $buchen, $drucken, $werkzeugbuchen, $mc, $mp);

    echo '<table class="grey-table">
      <tr>
        <th>Name</th>
        <th>Turm</th>
        <th>Raum</th>
        <th>Grund</th>
        <th>Startzeit</th>
      </tr>';

    while (mysqli_stmt_fetch($stmt)) {
      $turm4ausgabe = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);

      echo "<form method='POST' action='User.php' target='_blank' style='display: none;' id='form_auto_{$uid}'>
              <input type='hidden' name='id' value='{$uid}'>
            </form>";

      echo "<tr onclick='document.getElementById(\"form_auto_{$uid}\").submit();' style='cursor: pointer;'>";
      echo "<td>" . htmlspecialchars($name) . "</td>";
      echo "<td>" . htmlspecialchars($turm4ausgabe) . "</td>";
      echo "<td>" . (int)$room . "</td>";
      echo "<td>" . htmlspecialchars($beschreibung) . "</td>";
      echo "<td>" . date('d.m.Y H:i', $starttime) . "</td>";
      echo "</tr>";
    }

    mysqli_stmt_close($stmt);
    echo '</table>';
  }
} else {
  header("Location: denied.php");
}

$conn->close();
?>
</body>
</html>