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
if (auth($conn) && ($_SESSION['NetzAG'] || $_SESSION['Vorstand'] || $_SESSION["aktiv"])) {
#if (auth($conn) && ($_SESSION['NetzAG'])) {
  load_menu();
  $zeit = time();
  #echo '<pre style="color: white;">';print_r($_POST);echo '</pre>';
  

    if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
        if (isset($_POST["upload_infopic"])) {
            $beschreibung = $_POST["beschreibung"];
            $aktiv = 0;
            $uid = $_SESSION['user'];
            
            if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
                echo "</div>";
            } else {
                $file_path = null;

                if (!is_dir('infopics')) {
                    mkdir('infopics', 0777, true);
                }

                if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                    $unixtime = time(); // aktuelle Unixzeit
                    $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                    $file_name = $unixtime . '.' . $file_extension;
                    $file_path = 'infopics/' . $file_name;
                
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
            
                $insert_sql = "INSERT INTO infopics (uid, tstamp, aktiv, beschreibung, pfad, turm) VALUES (?,?,?,?,?,?)";
                $insert_var = array($uid, $zeit, $aktiv, $beschreibung, $file_path, 'weh');
                $stmt = mysqli_prepare($conn, $insert_sql);
                mysqli_stmt_bind_param($stmt, "iiisss", ...$insert_var);
                mysqli_stmt_execute($stmt);
            
                echo "<div style='text-align: center;'>";
                echo "<p style='color:green; text-align:center;'>Hochgeladen.</p>";
                echo "</div>";
            }
        }     
        
        if (isset($_POST["change_status"])) {
            $aktiv = $_POST["aktiv"];
            $id = $_POST["id"];
            $agent = $_SESSION['uid'];
        
            // Toggle aktiv Status
            if ($aktiv == 0) {
                $update_aktiv = 1;
                $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . " : Bild aktiviert\n";
            } else {
                $update_aktiv = 0;
                $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . " : Bild deaktiviert\n";
            }
        
            // Update Query
            $update_sql = "UPDATE infopics SET aktiv = ?, changelog = CONCAT(IFNULL(changelog, ''), ?) WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($stmt, "isi", $update_aktiv, $changelog, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }        
        
        if (isset($_POST["delete_infopic"])) {
            $id = $_POST["id"];
            $agent = $_SESSION['uid'];
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . " : Bild entfernt\n";
        
            $update_sql = "UPDATE infopics SET aktiv = -1, changelog = CONCAT(IFNULL(changelog, ''), ?) WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($stmt, "si", $changelog, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
        echo "<script>
          setTimeout(function() {
            document.forms['reload'].submit();
          }, 0000);
        </script>";
    }

    
    echo '<div style="width: 50%; margin: 0 auto; text-align: center;">';
    echo '<h2 style="text-align: center; font-size: 25px;">Neues Bild hochladen</h2>';
    echo '<p style="color: white; text-align: center; font-size: 20px; margin: 10px 0;">Bitte achte darauf, dass dein Bild im Breitbildformat vorliegt, um Verzerrungen zu vermeiden.</p>';
    echo '<p style="color: white; text-align: center; font-size: 20px; margin: 10px 0;">Hinweis: Nur relevante Inhalte hochladen. Die NetzAG kann alle Uploads zurückverfolgen!</p>';
    echo '<br><br>';

    echo '<form method="post" enctype="multipart/form-data">';    
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="beschreibung" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Beschreibung:</label>';
    echo '<input type="text" id="beschreibung" name="beschreibung" style="width: 200px;" required><br><br>';
    echo '</div>';
    echo '<div style="align-items: center; margin-bottom: 10px;">';
    echo '<label for="file" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Bild:</label>';
    echo '<input type="file" id="file" name="file" style="width: 190px; background-color: white; border: 2px solid black; padding: 5px">';    
    echo '</div>';    
    echo '<br><br>';
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo "<input type='hidden' name='reload' value=1>";
    echo '<button type="submit" name="upload_infopic" class="center-btn" style="display: block; margin: 0 auto;">Hochladen</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo "<br><br><hr><br><br>";

    
    $sql = "SELECT id, beschreibung, aktiv, pfad, changelog FROM infopics WHERE aktiv > -1 AND turm = 'weh'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Prüfe, ob es Einträge gibt
    if (mysqli_num_rows($result) > 0) {
        echo '<div style="width: 50%; margin: 0 auto; text-align: center;">';
        #echo '<h2 style="text-align: center; font-size: 25px;">Übersicht Bilder</h2>';

        echo '<table class="grey-table-singular" style="margin: 0 auto 30px auto; text-align: center;">';
        echo '<tr style="border: 1px solid white;">
                <th style="border: 1px solid white; padding: 10px;">Bild</th>
                <th style="border: 1px solid white; padding: 10px;">Beschreibung</th>
                <th style="border: 1px solid white; padding: 10px;">Status</th>
              </tr>';
    
        // Iteriere durch die Ergebnisse und füge sie zur Tabelle hinzu
        while ($row = mysqli_fetch_assoc($result)) {
            $statusText = '';
            $statusColor = '';
    
            // Statusübersetzung und Farbzuweisung
            switch ($row['aktiv']) {
                case 0:
                    $statusText = 'Inaktiv';
                    $statusColor = 'grey';
                    break;
                case 1:
                    $statusText = 'Aktiv';
                    $statusColor = 'green';
                    break;
            }
    
            echo '<tr style="border: 1px solid white;">';

            // Thumbnail-Zelle
            echo '<td style="border: 1px solid white; padding: 10px; cursor: pointer;" 
                    onclick="submitForm(\'form_pic_show_' . $row['id'] . '\');">';
            echo '<form id="form_pic_show_' . $row['id'] . '" method="POST" style="display: none;">
                    <input type="hidden" name="pfad" value="' . $row['pfad'] . '">
                    <input type="hidden" name="pic_show" value="1">
                  </form>';
            echo '<img src="' . htmlspecialchars($row['pfad']) . '" alt="Thumbnail" style="max-width: 100px; max-height: 100px;">';
            echo '</td>';
            
            // Beschreibung-Zelle
            echo '<td style="border: 1px solid white; padding: 10px; cursor: pointer;" 
                    onclick="submitForm(\'form_menu_show_' . $row['id'] . '\');">';
            echo '<form id="form_menu_show_' . $row['id'] . '" method="POST" style="display: none;">
                    <input type="hidden" name="id" value="' . $row['id'] . '">
                    <input type="hidden" name="beschreibung" value="' . $row['beschreibung'] . '">
                    <input type="hidden" name="aktiv" value="' . $row['aktiv'] . '">
                    <input type="hidden" name="pfad" value="' . $row['pfad'] . '">
                    <input type="hidden" name="changelog" value="' . $row['changelog'] . '">
                    <input type="hidden" name="menu_show" value="1">
                  </form>';
            echo htmlspecialchars($row['beschreibung']);
            echo '</td>';
            
            // Status-Zelle
            echo '<td style="border: 1px solid white; padding: 10px; color: ' . $statusColor . '; cursor: pointer;" 
                    onclick="submitForm(\'form_change_status_' . $row['id'] . '\');">';
            echo '<form id="form_change_status_' . $row['id'] . '" method="POST" style="display: none;">
                    <input type="hidden" name="id" value="' . $row['id'] . '">
                    <input type="hidden" name="aktiv" value="' . $row['aktiv'] . '">
                    <input type="hidden" name="change_status" value="1">
                    <input type="hidden" name="reload" value="1">
                  </form>';
                  echo '<span style="font-family: Consolas, monospace; font-weight: bold; display: inline-block;">' . $statusText . '</span>';
            echo '</td>';
            
            echo '</tr>';
            
            
        }
    
        echo '</table>';
        echo '</div>';
    }
    
    mysqli_stmt_close($stmt);
    echo "<script>
    function submitForm(formId) {
        document.getElementById(formId).submit();
    }
</script>";
    

    if (isset($_POST["pic_show"])) {
        $pfad = $_POST["pfad"];
    
        echo ('
        <div class="overlay"></div>
        <div class="lightbox">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
            </form>
            <br>
            <div">
                <img src="' . htmlspecialchars($pfad) . '" alt="Bild" class="full-size-image">
            </div>
        </div>');
    }

    if (isset($_POST["menu_show"])) {
        $id = $_POST["id"];
        $beschreibung = $_POST["beschreibung"];
        $aktiv = $_POST["aktiv"];
        $pfad = $_POST["pfad"];
        $changelog = $_POST["changelog"];
    
        // Aktiv-Status lesbar machen
        $statusText = ($aktiv == 1) ? 'Aktiv' : 'Inaktiv';
    
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
            </form>
            <br>
            <div style="text-align: center; color: white; font-family: Arial, sans-serif;">
                <h2 style="margin-bottom: 20px;">Bild-Details</h2>
                <p><strong>Beschreibung:</strong> ' . htmlspecialchars($beschreibung) . '</p>
                <p><strong>Status:</strong> ' . htmlspecialchars($statusText) . '</p>
            </div>
            <div style="text-align: center; margin: 20px;">
                <img src="' . htmlspecialchars($pfad) . '" alt="Bild" style="max-width: 90%; max-height: 400px; border-radius: 10px; margin-bottom: 20px;">
            </div>            
            <label style="color: lightgrey; text-align: center; display: block; margin-bottom: 10px;">Changelog:</label>
            <div style="background-color: darkblue; color: white; font-family: monospace; padding: 10px; display: inline-block; text-align: center; width: calc(100% - 30px); max-height: 200px; overflow-y: auto; box-sizing: border-box;">
                <p style="margin: 0; line-height: 1.4; font-size: 14px; white-space: pre-wrap;">' . (!empty($changelog) ? htmlspecialchars($changelog) : 'Kein Changelog verfügbar') . '</p>
            </div>
            <br><br><br>            
            <form method="post" style="text-align: center;">
                <input type="hidden" name="id" value="' . htmlspecialchars($id) . '">
                <input type="hidden" name="reload" value="1">
                <button type="submit" name="delete_infopic" class="red-center-btn" style="display: inline-block; margin: 0 auto; padding: 10px 20px; font-size: 25px; cursor: pointer;">Bild entfernen</button>
            </form>
        </div>');
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