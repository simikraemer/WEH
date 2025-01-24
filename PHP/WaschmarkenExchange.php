<?php
  session_start();
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
if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {

  load_menu();


  $uid = isset($_POST['uid']) ? $_POST['uid'] : $_SESSION["uid"];
  $selected_uid = $uid;


  if (isset($_POST["execute"]) && $_POST["execute"] == "thatshit") {
    $exec_uid = $_POST["uid"];
    $exec_waschmarkenpreis = $_POST["waschmarkenpreis"];
    $exec_aktuellewaschmarken = $_POST["aktuellewaschmarken"];

    if (isset($_POST["wasch2money"])) {
        $marken = $_POST["marken"];
        $marken_neg = (-1) * $marken;
        $zeit = time();
        $betrag = $marken * $exec_waschmarkenpreis;

        if ($marken < 0) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Markenanzahl muss positiv sein!</p>";
            echo "</div>";
        } else {
            // Insert into waschsystem2.transfers
            $insert_sql = "INSERT INTO transfers (von_uid, nach_uid, anzahl, time) VALUES (-1,?,?,?)";
            $insert_var = array($exec_uid, $marken_neg, $zeit);
            $stmt = mysqli_prepare($waschconn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iii", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Insert into weh.transfers
            $beschreibung = $marken . " Waschmarken zurückgetauscht";
            $konto = 6;
            $kasse = 5;
            $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $_SESSION["uid"] . "\n";
            $changelog .= "Insert durch WaschmarkenExchange.php [Mode1]\n";
            $insert_sql = "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, agent, changelog) VALUES (?,?,?,?,?,?,?,?)";
            $insert_var = array($exec_uid, $zeit, $beschreibung, $konto, $kasse, $betrag, $_SESSION["uid"], $changelog);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iisiidis", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Update waschsystem2.waschusers
            $new_waschmarken = $exec_aktuellewaschmarken - $marken;
            $update_sql = "UPDATE waschusers SET waschmarken = ? WHERE uid = ?";
            $update_stmt = mysqli_prepare($waschconn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ii", $new_waschmarken, $exec_uid);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
    }

    if (isset($_POST["money2wasch"])) {
        echo "yo";
        $betrag = $_POST["betrag"];
        $betrag_neg = (-1) * $betrag;
        $zeit = time();
        $marken = $betrag / $exec_waschmarkenpreis;

        // Verwende fmod() für Modulo-Berechnung mit Gleitkommazahlen
        if (fmod($betrag, $exec_waschmarkenpreis) != 0) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Betrag ist kein Vielfaches des Waschmarkenpreises! Verwende im Zweifel die Pfeile neben der Eingabe, um den Wert zu bestimmen.</p>";
            echo "</div>";
        } elseif ($betrag < 0) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Betrag muss positiv sein!</p>";
            echo "</div>";
        } else {
            // Insert into waschsystem2.transfers
            $insert_sql = "INSERT INTO transfers (von_uid, nach_uid, anzahl, time) VALUES (-1,?,?,?)";
            $insert_var = array($exec_uid, $marken, $zeit);
            $stmt = mysqli_prepare($waschconn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iii", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Insert into weh.transfers
            $beschreibung = $marken . " Waschmarken generiert";
            $konto = 6;
            $kasse = 5;
            $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $_SESSION["uid"] . "\n";
            $changelog .= "Insert durch WaschmarkenExchange.php [Mode2]\n";
            $insert_sql = "INSERT INTO transfers (uid, tstamp, beschreibung, konto, kasse, betrag, agent, changelog) VALUES (?,?,?,?,?,?,?,?)";
            $insert_var = array($exec_uid, $zeit, $beschreibung, $konto, $kasse, $betrag_neg, $_SESSION["uid"], $changelog);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iisiidis", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Update waschsystem2.waschusers
            $new_waschmarken = $exec_aktuellewaschmarken + $marken;
            $update_sql = "UPDATE waschusers SET waschmarken = ? WHERE uid = ?";
            $update_stmt = mysqli_prepare($waschconn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ii", $new_waschmarken, $exec_uid);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
    }
  }

  
    if (!isset($_SESSION["AdminPanelToggleState"])) {
      $_SESSION["AdminPanelToggleState"] = "none"; // Standardmäßig eingeklappt
    }

    // Wenn ein Toggle-Request erfolgt, den Zustand der Session-Variable umschalten
    if (isset($_POST["toggleAdminPanel"])) {
        $_SESSION["AdminPanelToggleState"] = $_SESSION["AdminPanelToggleState"] === "none" ? "block" : "none";
    }



    if ($_SESSION['NetzAG']) {
        echo '<div style="margin: 0 auto; text-align: center;">';
        echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
        echo '<span class="white-text" style="font-size: 35px; cursor: pointer;" onclick="toggleAdminPanel()">Admin Panel</span>';
        echo '<div id="adminPanel" style="display: ' . $_SESSION["AdminPanelToggleState"] . ';">'; // Beginn des ausklappbaren Bereichs

        echo '<form method="post">';
        echo '<label for="uid" style="color: white; font-size: 25px;">UID: </label>';
        echo '<input type="text" name="uid" id="uid" placeholder="UID" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()" value="' . $uid . '">';
        echo '</form>';

        echo '<form method="post">';
        echo '<label for="uid" style="color: white; font-size: 25px;">Bewohner: </label>';

        echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;" onchange="this.form.submit()">';

        $sql = "SELECT name, room, pid FROM users WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $uid);
        mysqli_stmt_execute($stmt);
        #mysqli_set_charset($conn, "utf8");
        mysqli_stmt_bind_result($stmt, $name, $room, $pid);
        mysqli_stmt_fetch($stmt);

        $roomLabel = '';
        if ($pid == 11) {
          $roomLabel = ' ['.$room.']';
        } elseif ($pid == 12) {
            $roomLabel = ' [Subletter]';
        } elseif ($pid == 13) {
            $roomLabel = ' [Ausgezogen]';
        } elseif ($pid == 14) {
            $roomLabel = ' [Abgemeldet]';
        } elseif ($pid == 64) {
          $roomLabel = ' [Dummy]';
        } else {
          $roomLabel = ' [Undefined]';
        }

        echo '<option value="' . $uid . '">' . $name . $roomLabel . '</option>';
        mysqli_stmt_free_result($stmt);

        $sql = "SELECT uid, name, room FROM users WHERE pid = 11 ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        #mysqli_set_charset($conn, "utf8");
        mysqli_stmt_bind_result($stmt, $listuid, $name, $room);
        while (mysqli_stmt_fetch($stmt)) {
          echo '<option value="' . $listuid . '">' . $name . ' [' . $room . ']</option>';
        }
        echo '</select>';
        echo '</form>';

        echo '</div>'; // Ende des ausklappbaren Bereichs
        echo '</div>';
        echo '</div>';

        echo '<script>
          function toggleAdminPanel() {
              // Unsichtbares Formular erstellen und absenden, um den Zustand zu speichern
              var form = document.createElement("form");
              form.method = "POST";
              form.action = ""; // Seite wird neu geladen
    
              // Hidden Field für toggleAdminPanel
              var inputToggle = document.createElement("input");
              inputToggle.type = "hidden";
              inputToggle.name = "toggleAdminPanel";
              inputToggle.value = "1";
              form.appendChild(inputToggle);
    
              // Hidden Field für UID
              var inputUid = document.createElement("input");
              inputUid.type = "hidden";
              inputUid.name = "uid";
              inputUid.value = "' . $selected_uid . '";
              form.appendChild(inputUid);
    
              document.body.appendChild(form);
              form.submit();
          }
      </script>';

        echo "<br><br>";
    }

  echo "<br><br>";
  
  $sql = "SELECT name, room, groups FROM users WHERE uid = ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $name, $room, $groups);
  mysqli_stmt_fetch($stmt);
  mysqli_stmt_free_result($stmt);
  
  $sql = "SELECT waschmarken FROM waschusers WHERE uid = ?";
  $stmt = mysqli_prepare($waschconn, $sql);
  mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $aktuellewaschmarken);
  mysqli_stmt_fetch($stmt);
  mysqli_stmt_free_result($stmt);

  $sql = "SELECT SUM(betrag) FROM transfers WHERE uid = ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, "i", $selected_uid);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $summe);
  mysqli_stmt_fetch($stmt);
  mysqli_stmt_free_result($stmt);
    
  if ($groups != "1" && $groups != "1,19") { // Aktiv
    $sql = "SELECT wert FROM constants WHERE name = 'waschpreisaktiv'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $waschmarkenpreis);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_free_result($stmt);
  } else { // Nichtaktiv
    $sql = "SELECT wert FROM constants WHERE name = 'waschpreisnichtaktiv'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $waschmarkenpreis);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_free_result($stmt);
  }




  #echo '<div style="text-align: center;">';
  #echo '<h1 style="display: inline;">' . $name . ' (' . $room . ')</h1>';
  #echo '</div>';
  
  echo '<div style="display: flex; justify-content: space-between;">';
  echo '    <div style="text-align: center; flex-grow: 1;">';
  echo '        <h2 style="display: inline;">Waschmarken</h2><br>';
  echo '        <h1 style="display: inline;">' . $aktuellewaschmarken . '</h1>';
  echo '    </div>';
  
  echo '    <div style="text-align: center; flex-grow: 1;">';
  echo '        <h2 style="display: inline;">Account Balance</h2><br>';
  echo '        <h1 style="display: inline;">' . number_format($summe, 2, ",", ".") . ' €</h1>';
  echo '    </div>';
  echo '</div>';
  

  echo "<br><br><br><hr><br><br>";


  echo '<div style="display: flex; justify-content: space-between;">';
    echo '<div style="text-align: center; flex-grow: 1;">';  
        echo '<div style="text-align: center;">';
        echo '<h1 style="display: inline;">Waschmarken zu Geld</h1>';
        echo '</div>';

        echo '<form method="post" enctype="multipart/form-data">';    
        echo '<br>';
        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="marken" style="color: white; font-size:25px; text-align: left;">Waschmarken:</label><br>';
        echo '<input type="number" step="1" id="marken" name="marken" style="width: 80px; font-size: 25px;" required min="0" multiple="1"><br><br>';
        echo '</div>';        
        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="uid" value="' . $selected_uid . '">';
        echo '<input type="hidden" name="aktuellewaschmarken" value="' . $aktuellewaschmarken . '">';
        echo '<input type="hidden" name="waschmarkenpreis" value="' . $waschmarkenpreis . '">';
        echo '<input type="hidden" name="execute" value="thatshit">';
        echo '<button type="submit" name="wasch2money"  class="center-btn" style="display: block; margin: 0 auto;">Exchange</button>';
        echo '</div>';
        echo '</form>';
    echo '</div>';
    echo '<div style="text-align: center; flex-grow: 1;">';
        echo '<div style="text-align: center;">';
        echo '<h1 style="display: inline;">Geld zu Waschmarken</h1>';
        echo '</div>';

        echo '<form method="post" enctype="multipart/form-data">';    
        echo '<br>'; 
        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="betrag" style="color: white; font-size:25px; text-align: left;">Betrag [€]:</label><br>';
        echo '<input type="number" step="'.$waschmarkenpreis.'" id="betrag" name="betrag" style="width: 80px; font-size: 25px;" required min="0" multiple="'.$waschmarkenpreis.'"><br><br>';
        echo '</div>';        
        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="uid" value="' . $selected_uid . '">';
        echo '<input type="hidden" name="aktuellewaschmarken" value="' . $aktuellewaschmarken . '">';
        echo '<input type="hidden" name="waschmarkenpreis" value="' . $waschmarkenpreis . '">';
        echo '<input type="hidden" name="execute" value="thatshit">';
        echo '<button type="submit" name="money2wasch"  class="center-btn" style="display: block; margin: 0 auto;">Exchange</button>';
        echo '</div>';
        echo '</form>';
    echo '</div>';
  echo '</div>';


}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>