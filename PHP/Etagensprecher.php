<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji -->
<!-- Für den WEH e.V. -->

<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION['valid'])) {
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

echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';

$turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : 'weh';
$weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
$tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
echo '<div style="display:flex; justify-content:center; align-items:center;">';
echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; margin-right:10px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; margin-right:10px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
echo '</form>';
echo '</div>';
echo "<br><br>";

if ($turm === "tvk") {
    $maxfloor = 15;
    $zimmerauf0 = 2;
} elseif ($turm === "weh") {
    $maxfloor = 17;
    $zimmerauf0 = 4;
} else {
    $maxfloor = 16; // Fallback, falls $turm einen unerwarteten Wert hat
    $zimmerauf0 = 4; // Fallback, falls $turm einen unerwarteten Wert hat
}

  $sql = "SELECT room, firstname, lastname FROM users WHERE turm = ? AND (pid = 11 AND etagensprecher <> 0) ORDER BY room";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, 's', $turm);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname);
  
  $users = array();
  
  $etagen_positionen = array(
      0 => 'left',
      1 => 'center',
      2 => 'right'
  );
  
  $all_results = array(); // Definiere ein neues Array
  
  while (mysqli_stmt_fetch($stmt)) {
      $etage = intval($room / 100); // Extrahiere die Etage aus der Raumnummer

      $firstname = strtok($firstname, ' ');
      $lastname = strtok($lastname, ' ');
      
      $name = $firstname . ' ' . $lastname;

      $user = array(
          'room' => $room,
          'name' => $name,
      );
      $all_results[$etage][] = $user; // Füge den Benutzer dem Array der entsprechenden Etage hinzu
  }
  
  mysqli_stmt_close($stmt);
  
  $count = 0;
  $all_etagen = range(0, $maxfloor);

  foreach ($all_etagen as $etage) {
      $etage_position = $count % 3; // Bestimme die Position der Etage (0, 1 oder 2)
  
      if ($count % 3 == 0) { // Überprüfe, ob die Zählvariable durch 3 teilbar ist, um nach 3 Tabellen eine neue Zeile anzufangen
          echo "<div style='clear:both;'> <br> </div>";
      }
      $count++;
  
      $etagen_position_class = $etagen_positionen[$etage_position];
  
      echo "<div class='ags-" . $etagen_position_class . "-table-container'>";
      echo "<table class='my-table ag-table'>";
      echo '<div style="text-align: center;">';
      echo '<span style="font-size: 30px; color: white;">Etage ' . $etage . '</span><br>';
      
      $etagenSprecherEmails = array(); // Array für gesammelte E-Mail-Adressen
      
      if (!isset($all_results[$etage]) || empty($all_results[$etage])) {
          echo '<span style="font-size:20px;" class="white-text">Kein Etagensprecher</span><br>';
      } else {
          foreach ($all_results[$etage] as $result) {
              $roomformatiert = str_pad($result['room'], 4, "0", STR_PAD_LEFT);
              $etagenSprecherEmails[] = 'z' . $roomformatiert . '@weh.rwth-aachen.de'; // E-Mail-Adresse sammeln
              echo "<tr>";
              echo "<td style='color: #888888;'>$roomformatiert</td>";
              echo "<td class='second-column white-text'><a href='mailto: z$roomformatiert@weh.rwth-aachen.de'>$result[name]</a></td>";
              echo "</tr>";
          }
      }
      
      if (!empty($etagenSprecherEmails)) {
          $mailtoLink = 'mailto:' . implode(',', $etagenSprecherEmails); // Mailto-Link erstellen
          echo '<div class="center" style="font-size:20px;"><a href="' . $mailtoLink . '" class="white-text">Etagensprecher kontaktieren</a></div>';
      }
      
      echo '</div>';
      echo '</table>';
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

