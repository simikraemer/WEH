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

# Offline genommen von Fiji am 01.05.2025 - Von nun an alles via Transfers.php
if (auth($conn) && (($_SESSION["Webmaster"]))) {
#if (auth($conn) && (($_SESSION["Webmaster"]) || ($_SESSION['Kassenpruefer']) || ($_SESSION['Kassenwart']))) {
  load_menu();


    
  if(isset($_POST['save2'])){
    $kasse_nummer = $_POST['kasse_nummer'];
    $text = $_POST['text'];
    $uid = $_SESSION['user'];
    $zeit = time();
    $insert_sql = "UPDATE barkasse SET status = 2, pruefzeit = $zeit, pruefer = $uid WHERE status = 1 AND kasse = $kasse_nummer";
    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_execute($stmt);
    if(mysqli_error($conn)) {
      echo "MySQL Fehler: " . mysqli_error($conn);
    } else {
      echo "<p style='color:green; text-align:center;'>Kassenprüfung für ".$text." erfolgreich abgeschlossen.</p>";    }
    mysqli_stmt_close($stmt);
  }

  $kassen = array(
    'Netzwerk-AG Barkasse I' => 1,
    'Netzwerk-AG Barkasse II' => 2,
    'Kassenwart-Barkasse I' => 3,
    'Kassenwart-Barkasse II' => 4,
    'Tresor' => 5
  );

  foreach ($kassen as $text => $kasse_nummer) {
    $sql = "SELECT betrag FROM barkasse WHERE kasse = $kasse_nummer AND status = 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
      die('Fehler beim Vorbereiten der Anweisung: ' . mysqli_error($conn));
    }
    if (!mysqli_stmt_execute($stmt)) {
      die('Fehler beim Ausführen der Anweisung: ' . mysqli_error($stmt));
    }
    if(mysqli_stmt_fetch($stmt) === null) {   
      $nixneues = True;
    } else {
      $nixneues = False;
    }   
    mysqli_stmt_close($stmt);
    $sql = "SELECT SUM(betrag) FROM barkasse WHERE kasse = $kasse_nummer";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
      die('Fehler beim Vorbereiten der Anweisung: ' . mysqli_error($conn));
    }
    if (!mysqli_stmt_execute($stmt)) {
      die('Fehler beim Ausführen der Anweisung: ' . mysqli_error($stmt));
    }
    $result = get_result($stmt);
    $stand = $result[0]['SUM(betrag)']; // Extrahiere den Wert aus dem Array
    echo '<h1 class="center">' . strtoupper($text) . '</h1>';
    echo '<h2 class="center">Aktueller Kassenstand: ' . $stand . ' €</h2>';
    if ($nixneues) {
      echo '<h2 class = "center">Keine neuen Einträge für '.$text.'.<br><br></h2>';
    } else {
    
      $sql = "SELECT barkasse.tstamp, barkasse.beschreibung, barkasse.betrag, barkasse.pfad, users.name, users.uid
      FROM users 
      JOIN barkasse ON barkasse.uid = users.uid
      WHERE barkasse.status = 1 AND kasse = $kasse_nummer";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $tstamp_unform, $beschreibung, $betrag, $path, $name, $uid);
    
      echo '<form action="Kassenprüfung.php" method="post" name="kassenprüfung">';
      echo '<table class="grey-table">
        <tr>
          <th>Zeitstempel</th>
          <th>User</th>
          <th>Grund</th>
          <th>Betrag</th>
        </tr>';
    
      while (mysqli_stmt_fetch($stmt)) {
        $tstamp = date('d.m.Y', $tstamp_unform);
      
        echo "<tr>";    
        echo "<td>" . $tstamp . "</td>";
        echo "<td>" . $name . " [" . $uid . "]</td>";
        if ($path !== null && $path !== '') {
          $beschreibung = $beschreibung . ' <br><a href="'.$path.'" target="_blank" class="grey-text">[Zur Rechnung]</a>';
        }
        echo "<td>" . $beschreibung . "</td>";
        echo "<td>" . $betrag . "€</td>";    
        echo "</tr>";
      }
      mysqli_stmt_close($stmt);
    
      echo '</table>';
      echo "<br><br><br>";
      
      if ($_SESSION["Kassenpruefer"]) {
        echo "<div style='display: flex; justify-content: center; margin-top: 1%'>";
        echo "<input type='hidden' name='kasse_nummer' value=".$kasse_nummer.">";
        echo "<input type='hidden' name='text' value='$text'>";
        echo "<button type='submit' name='save' class='center-btn'>".$text." - Prüfung abgeschlossen</button>";
        echo "</div>";
        echo "<br><br><br><br><br>";
        echo "</form>";
      }
    }
  }
   
  if(isset($_POST['save'])){
    $kasse_nummer = $_POST['kasse_nummer'];
    $text = $_POST['text'];
    echo '<div style="display: flex; justify-content: center; align-items: center; height: 100vh; text-align: center;">';
    echo '<form method="post">';
    echo "<input type='hidden' name='kasse_nummer' value=" . $kasse_nummer . ">";
    echo "<input type='hidden' name='text' value=" . $text . ">";
    echo "<button type='submit' name='save2' class='center-btn'>Ganz Sicher?</button>";
    echo "</form>";
    echo '</div>';
    echo '<div class="overlay"></div>
    <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>';
    
    echo '<form method="post">';
    echo "<input type='hidden' name='kasse_nummer' value=" . $kasse_nummer . ">";
    echo "<input type='hidden' name='text' value=" . $text . ">";
    echo "<button type='submit' name='save2' class='center-btn'>Ganz Sicher? ".$text." abgeschlossen?</button>";
    echo "</form>";
    
    echo '</div>'; // Schließe das äußere DIV
  }

} else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>