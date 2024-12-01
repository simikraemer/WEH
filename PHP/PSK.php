<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji  -->
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
    

    $zeit = time();
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
        if (isset($_POST["sendpost"])) {
            $mac = $_POST['mac'];
            $hersteller = $_POST['hersteller'];
            $device = $_POST['device'];
            $zeit = time();
            $uid = $_SESSION['user'];
        
            $mac_valid = filter_var($mac, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/^([0-9A-Fa-f]{2}:){5}([0-9A-Fa-f]{2})$/")));
            if ($mac_valid === false) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>Deine MAC hat das falsche Format!<br>Beispielformatierung: 12:34:56:78:9A:BC</p>";
                echo "</div>";
            } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
                echo "</div>";
            } else {
                $file_path = null;

                if (!is_dir('pskpictures')) {
                    mkdir('pskpictures', 0777, true);
                }

                if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                    $unixtime = time(); // aktuelle Unixzeit
                    $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                    $file_name = $unixtime . '.' . $file_extension;
                    $file_path = 'pskpictures/' . $file_name;
                
                    // Überprüfung auf erlaubte Dateitypen (Erweiterungen)
                    $allowed_extensions = array('jpg', 'jpeg', 'png');
                    if (!in_array($file_extension, $allowed_extensions)) {
                        echo "<div style='text-align: center;'>";
                        echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) sind erlaubt.</p>";
                        echo "</div>";
                        exit();
                    }
                
                    // Überprüfung auf erlaubte MIME-Typen
                    $allowed_mime_types = array('image/jpeg', 'image/png');
                    $file_mime_type = $_FILES['file']['type'];
                    if (!in_array($file_mime_type, $allowed_mime_types)) {
                        echo "<div style='text-align: center;'>";
                        echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) sind erlaubt.</p>";
                        echo "</div>";
                        exit();
                    }
                
                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $file_path)) {
                        echo "<div style='text-align: center;'>";
                        echo "<p style='color:red; text-align:center;'>Fehler beim Hochladen der Datei.</p>";
                        echo "</div>";
                        exit();
                    }
                }
            
        
                $beschreibung = $uid . " - " . $device . " (" . $hersteller . ")";
        
                $insert_sql = "INSERT INTO pskonly (uid, tstamp, mac, beschreibung, pfad) VALUES (?,?,?,?,?)";
                $insert_var = array($uid, $zeit, $mac, $beschreibung, $file_path);
                $stmt = mysqli_prepare($conn, $insert_sql);
                mysqli_stmt_bind_param($stmt, "iisss", ...$insert_var);
                mysqli_stmt_execute($stmt);
        
                echo "<div style='text-align: center;'>";
                echo "<p style='color:green; text-align:center;'>Deine Anfrage wurde gesendet.</p>";
                echo "</div>";
            }
        }

        echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
        echo "<script>
          setTimeout(function() {
            document.forms['reload'].submit();
          }, 2000);
        </script>";
    }


    
    $sql = "SELECT beschreibung, mac, status FROM pskonly WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["uid"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Prüfe, ob es Einträge gibt
    if (mysqli_num_rows($result) > 0) {
        echo '<div style="width: 50%; margin: 0 auto; text-align: center;">';
        echo '<h2 style="text-align: center; font-size: 25px;">Übersicht deiner pskonly Registrierungen:</h2>';
    
        echo '<table style="margin: 0 auto; border-collapse: collapse; width: 100%; text-align: center; color: white;">';
        echo '<tr style="border: 1px solid white;">
                <th style="border: 1px solid white; padding: 10px;">Beschreibung</th>
                <th style="border: 1px solid white; padding: 10px;">MAC</th>
                <th style="border: 1px solid white; padding: 10px;">Status</th>
              </tr>';
    
        // Iteriere durch die Ergebnisse und füge sie zur Tabelle hinzu
        while ($row = mysqli_fetch_assoc($result)) {
            $statusText = '';
            $statusColor = '';
    
            // Statusübersetzung und Farbzuweisung
            switch ($row['status']) {
                case -1:
                    $statusText = 'Abgelehnt';
                    $statusColor = 'red';
                    break;
                case 0:
                    $statusText = 'Ausstehend';
                    $statusColor = 'white';
                    break;
                case 1:
                    $statusText = 'Angenommen';
                    $statusColor = 'green';
                    break;
            }
    
            echo '<tr style="border: 1px solid white;">';
            echo '<td style="border: 1px solid white; padding: 10px;">' . htmlspecialchars($row['beschreibung']) . '</td>';
            echo '<td style="border: 1px solid white; padding: 10px;">' . htmlspecialchars($row['mac']) . '</td>';
            echo '<td style="border: 1px solid white; padding: 10px; color: ' . $statusColor . ';">' . $statusText . '</td>';
            echo '</tr>';
        }
    
        echo '</table>';
        echo '</div>';

        echo "<br><br><hr><br><br>";
    }

    mysqli_stmt_close($stmt);
    


    echo '<div style="width: 50%; margin: 0 auto; text-align: center;">';
    echo '<h2 style="text-align: center; font-size: 25px;">Hier kannst du ein Gerät für das Netzwerk weh-pskonly registrieren!</h2>';
    echo '<span style="color: white; text-align: center; font-size: 20px;">Bitte lade ein Bild hoch, in dem man eindeutig sehen kann, dass die MAC-Adresse zum Gerät gehört.</span><br>';
    echo '<span style="color: white; text-align: center; font-size: 20px;">Wir versuchen deine Anfrage innerhalb einer Woche zu beantworten.</span><br>';
    echo '<span style="color: white; text-align: center; font-size: 20px;">Du wirst per E-Mail über die Zugangsdaten informiert.</span><br><br><br>';
    
    

    echo '<form method="post" enctype="multipart/form-data">';    
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="mac" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">MAC:</label>';
    echo '<input type="text" id="mac" name="mac" style="width: 200px;" required><br><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="device" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Gerät:</label>';
    echo '<input type="text" id="device" name="device" style="width: 200px;" required><br><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="hersteller" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Hersteller:</label>';
    echo '<input type="text" id="hersteller" name="hersteller" style="width: 200px;" required><br><br>';
    echo '</div>';
    echo '<div style="align-items: center; margin-bottom: 10px;">';
    echo '<label for="file" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Bild:</label>';
    echo '<input type="file" id="file" name="file" style="width: 190px; background-color: white; border: 2px solid black; padding: 5px">';    
    echo '</div>';    
    echo '<br><br>';
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<button type="submit" name="sendpost"  class="center-btn" style="display: block; margin: 0 auto;">Registrieren</button>';            
    echo "<input type='hidden' name='reload' value='1'>";
    echo '</div>';
    echo '</form>';
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