<?php
session_start();
?>
<!DOCTYPE html>
<!-- Fiji -->
<!-- Für den WEH e.V. -->
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 20px;
        }

        .uploadproto-container {
            max-width: 700px;
            margin: 40px auto;
            background-color: #1e1e1e;
            padding: 30px;
            border-radius: 12px;
            border: 2px solid #11a50d;
        }

        .uploadproto-title {
            font-size: 30px;
            text-align: center;
            color: #11a50d;
            margin-bottom: 15px;
        }

        .uploadproto-subtitle {
            font-size: 22px;
            text-align: center;
            margin-bottom: 40px;
        }

        .uploadproto-form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .uploadproto-label {
            display: block;
            font-size: 20px;
            margin-bottom: 8px;
        }

        .uploadproto-input,
        .uploadproto-select {
            width: 100%;
            padding: 12px;
            font-size: 18px;
            border-radius: 6px;
            border: 1px solid #444;
            background-color: #2a2a2a;
            color: white;
        }

        .uploadproto-input[type="file"] {
            padding: 8px;
        }

        .uploadproto-button {
            background-color: #11a50d;
            color: white;
            border: none;
            padding: 14px 26px;
            font-size: 22px;
            border-radius: 6px;
            cursor: pointer;
            display: block;
            margin: 40px auto 0 auto;
        }

        .uploadproto-button:hover {
            background-color: #0e8b0a;
        }

        .uploadproto-message {
            text-align: center;
            margin-top: 20px;
            font-size: 18px;
        }

        .uploadproto-message.error {
            color: red;
        }

        .uploadproto-message.success {
            color: #11a50d;
        }
    </style>
</head>
<body>
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
    


    ?>

    <div class="uploadproto-container">
        <h2 class="uploadproto-title">Protokoll hochladen</h2>
        <p class="uploadproto-subtitle">Nach dem Upload wird das Protokoll direkt veröffentlicht!</p>
        <form method="post" enctype="multipart/form-data">
            <div class="uploadproto-form-group">
                <label for="type" class="uploadproto-label">Versammlungsart:</label>
                <select id="type" name="type" class="uploadproto-select" required>
                    <?php foreach ($typeStrings as $key => $value): ?>
                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="uploadproto-form-group">
                <label for="vzeit" class="uploadproto-label">Datum der Versammlung:</label>
                <input type="date" id="vzeit" name="vzeit" class="uploadproto-input" required>
            </div>
            <div class="uploadproto-form-group">
                <label for="file" class="uploadproto-label">PDF-Datei:</label>
                <input type="file" id="file" name="file" class="uploadproto-input" required>
            </div>
            <button type="submit" name="sendpost" class="uploadproto-button">Hochladen</button>
        </form>
    </div>

<?php
}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>