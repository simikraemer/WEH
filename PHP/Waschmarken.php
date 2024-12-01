<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["WEH-WaschAG"])) {
  load_menu();
  //Handle accept/decline

  //Display info
 

  //Jede PostVariable wird zur SessionVariable

  if (isset($_POST["waschmarken"])) $_SESSION["waschmarken"] = $_POST["waschmarken"];
  if (isset($_POST["waschmarken_added"])) $_SESSION["waschmarken_added"] = $_POST["waschmarken_added"];
  
    if (!isset($_POST["submit"]) && !isset($_POST["submit2"])) {
      echo('<div class="form-container">
      <form action="Waschmarken.php" method="post" name="formularwasch">');
      if ($_SESSION["NetzAG"] || $_SESSION["Vorstand"]) {
        echo('
          <label class="form-label">Anzahl Waschmarken:</label>
          <input type="number" id="waschmarken" name="waschmarken" class="form-input" value="" required>   
          <br> ');
      } else {
        echo('
            <label class="form-label">Anzahl Waschmarken (maximal 3):</label>
            <select id="waschmarken" name="waschmarken" class="form-input" required>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
            </select>
            <br>');
      }
      echo('
        <p style="color:white;"><sub>Vorauswahl:</sub></p>  
        <input type="checkbox" name="netz">
        <label for="netz" class="form-label" >Netzwerk-AG</label>
        <br>
        <input type="checkbox" name="vorstand">
        <label for="vorstand" class="form-label" >Vorstand</label>
        <br>
        <input type="checkbox" name="wohnzimmer">
        <label for="wohnzimmer" class="form-label" >Wohnzimmer-AG</label>
        <br>
        <input type="checkbox" name="wasch">
        <label for="wasch" class="form-label" >Wasch-AG</label>
        <br>
        <input type="checkbox" name="ba">
        <label for="ba" class="form-label" >Belegungsausschuss</label>
        <br>
        <input type="checkbox" name="werkzeug">
        <label for="werkzeug" class="form-label" >Werkzeug-AG</label>
        <br>
        <input type="checkbox" name="getränke">
        <label for="getränke" class="form-label" >Getränke-AG</label>
        <br>
        <input type="checkbox" name="sport">
        <label for="sport" class="form-label" >Sport-AG</label>
        <br>
        <input type="checkbox" name="fahrrad">
        <label for="fahrrad" class="form-label" >Fahrrad-AG</label>
        <br>
        <input type="checkbox" name="lernraum">
        <label for="lernraum" class="form-label" >Lernraum-AG</label>
        <br>
        <input type="checkbox" name="musik">
        <label for="musik" class="form-label" >Musik-AG</label>
        <br>
        <input type="checkbox" name="essen">
        <label for="essen" class="form-label" >Essen-AG</label>
        <br>
        <input type="checkbox" name="alle">
        <label for="alle" class="form-label" >Alle Bewohner</label>
        <br>
        <br>
        <div class="form-group">
         <input type="submit" name="submit" value="Weiter zur Auswahl von einzelnen Bewohnern" class="form-submit">
        </div>
    </form>
  </div>');
  }

  if (isset($_POST["submit"]) && !isset($_POST["submit2"])) {
    $selectedGroups = array(); // Array initalisieren
  
    // Welche Checkboxen wurden ausgwählt? Ab ins Array
    if (isset($_POST["netz"])) {
      array_push($selectedGroups, "%,7%");
    }
    if (isset($_POST["vorstand"])) {
      array_push($selectedGroups, "%,9%");
    }
    if (isset($_POST["wohnzimmer"])) {
      array_push($selectedGroups, "%,10%");
    }
    if (isset($_POST["wasch"])) {
      array_push($selectedGroups, "%,11%");
    }
    if (isset($_POST["ba"])) {
      array_push($selectedGroups, "%,12%");
    }
    if (isset($_POST["werkzeug"])) {
      array_push($selectedGroups, "%,13%");
    }
    if (isset($_POST["getränke"])) {
      array_push($selectedGroups, "%,14%");
    }
    if (isset($_POST["sport"])) {
      array_push($selectedGroups, "%,23%");
    }
    if (isset($_POST["fahrrad"])) {
      array_push($selectedGroups, "%,25%");
    }
    if (isset($_POST["lernraum"])) {
      array_push($selectedGroups, "%,55%");
    }
    if (isset($_POST["musik"])) {
      array_push($selectedGroups, "%,32%");
    }
    if (isset($_POST["essen"])) {
      array_push($selectedGroups, "%,69%");
    }
    if (isset($_POST["alle"])) {
      array_push($selectedGroups, "%1%");
    }
  
    // Dynamische SQL-Abfrage
    $sql = "";
    foreach ($selectedGroups as $group) {
      if ($sql != "") {
        $sql .= " UNION "; // fügen Sie UNION hinzu, um die Ergebnisse zu kombinieren
      }
      $sql .= "SELECT name, room, uid FROM users WHERE (pid in (11) AND groups LIKE '$group') OR (pid in (12) AND groups LIKE '$group')";
    }
  
    // In Tabelle darstellen
    $conn->set_charset("utf8"); // Zeichenkodierung setzen
    $result = $conn->query($sql);
  
    if ($result->num_rows > 0) {
      echo '<form action="Waschmarken.php" method="post" name="formularwasch2">';
      echo "<table class='grey-table'>";
      echo "<tr><th>Name</th><th>Raum</th><th>Auswählen</th></tr>";
      while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>".$row["name"]."</td>";
        echo "<td>".$row["room"]."</td>";
        echo '<td><input type="checkbox" name="ergebnis[]" value="' . $row["uid"] . '"';
    
        // Hier wird überprüft, ob die Checkbox standardmäßig ausgewählt sein soll.
        if (!isset($_POST["alle"])) {
            echo ' checked';
        }
        
        echo '></td>';
        echo "</tr>";
    }
      echo "</table>";
      echo('
          <div class="form-container">
            <br>
            <div class="form-group">
            <input type="submit" name="submit2" value="Ausgewählten Bewohnern ' . abs($_SESSION["waschmarken"]) . ' Waschmarken ausgeben" class="form-submit">
            </div>
          </div>
        </form>');
    } else {
      echo "Keine Ergebnisse gefunden.";
    }
  }



  if (isset($_POST["submit2"])) {
    // Überprüfen, ob der SQL-Befehl bereits ausgeführt wurde
    if (!isset($_SESSION["waschmarken_added"])) {
        $selectedUids = $_POST['ergebnis'];

        // Dynamische SQL-Abfrage für das Update
        $successCount = 0;
        $time = time();
        foreach ($selectedUids as $uid) {
          $stmt = mysqli_prepare($conn, "INSERT INTO waschsystem2.transfers (nach_uid, von_uid, anzahl, time) VALUES (?, -4, ?, ?)");
          if (!$stmt) {
              die("Prepare failed: " . mysqli_error($conn));
          }
          mysqli_stmt_bind_param($stmt, "iii", $uid, abs($_SESSION['waschmarken']), $time);
          if (!mysqli_stmt_execute($stmt)) {
              printf("Error: %s.\n", mysqli_stmt_error($stmt));
          }
          mysqli_stmt_close($stmt);
  
          $stmt = mysqli_prepare($conn, "UPDATE waschsystem2.waschusers SET waschmarken = waschmarken + ? WHERE uid = ?");
          if (!$stmt) {
              die("Prepare failed: " . mysqli_error($conn));
          }
          mysqli_stmt_bind_param($stmt, "ii", abs($_SESSION['waschmarken']), $uid);
          $result = mysqli_stmt_execute($stmt);
  
          if ($result) {
              $successCount++;
          }
        }

        if ($successCount == count($selectedUids)) {
            echo '<span style="color:green; font-weight: bold;">Alle Waschmarken erfolgreich hinzugefügt.</span>';

            // POST-Daten in Sitzungsvariable speichern
            $_SESSION["waschmarken_added"] = true;
        } else {
            echo '<span style="color:red; font-weight: bold;">Es gab einen Fehler beim Hinzufügen von Waschmarken.</span>';
        }
    } else {
        // SQL-Befehl wurde bereits ausgeführt
        echo '<span style="color:orange; font-weight: bold;">Die Waschmarken wurden bereits hinzugefügt.</span>';
    }
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
