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
if (auth($conn) && ($_SESSION["Schrift"] || $_SESSION["Webmaster"])) {
  load_menu();
  
  $typeStrings = [
    0 => "Ordentliche Vollversammlung",
    1 => "Außerordentliche Vollversammlung",
    2 => "Ordentlicher Haussenat",
    3 => "Außerordentlicher Haussenat"
  ];
  $zeit = time();

    if (isset($_POST["sendpost"])) {
        $vzeitUnix = strtotime($_POST['vzeit'] . ' 00:00:00');
        $type = $_POST['type'];
        $zeit = time();
        $uid = $_SESSION['user'];
        if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
            echo "</div>";
        } else {
            $file_path = null;

            if (!is_dir('protokolle')) {
                mkdir('protokolle', 0777, true);
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
                echo "<div style='text-align: center;'>";
                echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
                echo "</div>";
            } else {
                $file_path = null;
            
                if (!is_dir('protokolle')) {
                    mkdir('protokolle', 0777, true);
                }
            
                if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                    $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                    if ($type < 2) {
                        $file_name = date('Ymd', $vzeitUnix) . ' VV.' . $file_extension;
                    } elseif ($type > 1) {
                        $file_name = date('Ymd', $vzeitUnix) . ' Senat.' . $file_extension;
                    }
                    $file_path = 'protokolle/' . $file_name;
            
                    // Überprüfung auf erlaubte Dateitypen (Erweiterungen)
                    $allowed_extensions = array('pdf');
                    if (!in_array($file_extension, $allowed_extensions)) {
                        echo "<div style='text-align: center;'>";
                        echo "<p style='color:red; text-align:center;'>Nur PDF-Dateien sind erlaubt.</p>";
                        echo "</div>";
                        exit();
                    }
            
                    // Überprüfung auf erlaubte MIME-Typen
                    $allowed_mime_types = array('application/pdf');
                    $file_mime_type = $_FILES['file']['type'];
                    if (!in_array($file_mime_type, $allowed_mime_types)) {
                        echo "<div style='text-align: center;'>";
                        echo "<p style='color:red; text-align:center;'>Nur PDF-Dateien sind erlaubt.</p>";
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
            }


    
            $insert_sql = "INSERT INTO protokolle (uid, tstamp, type, versammlungszeit, pfad) VALUES (?,?,?,?,?)";
            $insert_var = array($uid, $zeit, $type, $vzeitUnix, $file_path);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iiiss", ...$insert_var);
            mysqli_stmt_execute($stmt);
    
            echo "<div style='text-align: center;'>";
            echo "<p style='color:green; text-align:center;'>Hochgeladen.</p>";
            echo "</div>";
        }
    }
    


    echo '<div style="width: 70%; margin: 0 auto; text-align: center;">';
    echo '<h2 style="text-align: center; font-size: 30px;">Hier kannst du die Protokolle von Versammlungen hochladen.</h2>';
    echo '<span style="color: white; text-align: center; font-size: 25px;">Nach dem Upload wird das Protokoll direkt veröffentlicht!</span><br><br><br><br>';
    
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="type" style="display: inline-block; width: 350px; color: white; font-size: 30px; text-align: left; margin-bottom: 10px;">Versammlungsart:</label>';
    echo '<select id="type" name="type" style="width: 350px; font-size: 25px;" required>';
    foreach ($typeStrings as $key => $value) {
        echo '<option value="' . $key . '">' . $value . '</option>';
    }
    echo '</select><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="vzeit" style="display: inline-block; width: 350px; color: white; font-size: 30px; text-align: left; margin-bottom: 10px;">Datum der Versammlung:</label>';
    echo '<input type="date" id="vzeit" name="vzeit" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="file" style="display: inline-block; width: 350px; color: white; font-size: 30px; text-align: left; margin-bottom: 10px;">PDF:</label>';
    echo '<input type="file" id="file" name="file" style="width: 350px; font-size: 25px;">';
    echo '</div>';
    echo '<br><br>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<button type="submit" name="sendpost" class="center-btn" style="display: block; margin: 0 auto; font-size: 30px;">Hochladen</button>';
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