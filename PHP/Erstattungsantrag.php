<?php
  session_start();

?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">

        
    <style>
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: sans-serif;
        }

        .form-wrapper {
            max-width: 600px;
            margin: 3em auto;
            padding: 2em;
            background-color: #1c1c1c;
            border: 2px solid #11a50d;
            border-radius: 8px;
        }

        .form-wrapper h1 {
            color: #11a50d;
            font-size: 1.8em;
            margin-bottom: 0.5em;
        }

        .form-wrapper p {
            color: #cccccc;
            margin-bottom: 1em;
        }

        .form-wrapper ul {
            margin-top: 0.1em;
            margin-bottom: 1.5em;
            padding-left: 1.2em;
        }

        .form-wrapper label {
            display: block;
            margin-top: 1em;
            margin-bottom: 0.3em;
            color: #a0ffa0;
        }

        .form-wrapper input,
        .form-wrapper select,
        .form-wrapper button {
            box-sizing: border-box;
            display: block;
        }


        .form-wrapper input[type="file"],
        .form-wrapper input[type="text"],
        .form-wrapper input[type="number"],
        .form-wrapper select,
        .form-wrapper button {
            width: 100%;
            padding: 0.7em;
            margin-bottom: 1em;
            background-color: #252525;
            border: 1px solid #11a50d;
            color: #e0ffe0;
            border-radius: 4px;
        }

        .form-wrapper button {
            background-color: #11a50d;
            color: #000;
            font-weight: bold;
            transition: background-color 0.2s ease;
        }

        .form-wrapper button:hover {
            background-color: #0e8c0b;
        }
    </style>
    </head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

// 🔐 Zugriffsrecht
$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        (!empty($_SESSION['etagensprecher']) && $_SESSION['etagensprecher'] > 0)
        || (!empty($_SESSION['aktiv']) && $_SESSION['aktiv'] === true)
    );

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}




  
if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    // Variablen vorbereiten
    $uid = intval($_SESSION['uid']);
    $tstamp = time();
    $iban = mysqli_real_escape_string($conn, $_POST['iban']);
    $betrag = floatval(str_replace(",", ".", $_POST['betrag']));
    $einrichtung = $_POST['einheit'];

    // ---------- Datei-Upload ----------
    $pfad = '';
    if (isset($_FILES['rechnung']) && $_FILES['rechnung']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'rechnungen/';
        $tmp_name = $_FILES['rechnung']['tmp_name'];
        $original_name = $_FILES['rechnung']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        if (in_array($extension, $allowed_extensions)) {
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $base = uniqid('r_', true); // zufälliger eindeutiger Name
            $new_filename = $base . '.' . $extension;
            $target_path = $upload_dir . $new_filename;
            $counter = 1;

            // Absichern gegen Zufallskollision
            while (file_exists($target_path)) {
                $new_filename = $base . '_' . $counter . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                $counter++;
            }

            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path;
            } else {
                echo "Fehler beim Verschieben der Datei.";
                exit;
            }
        } else {
            echo "Ungültiges Dateiformat.";
            exit;
        }
    } else {
        echo "Keine Datei empfangen.";
        exit;
    }

    // ---------- INSERT ----------
    $sql = "INSERT INTO erstattung (uid, tstamp, einrichtung, betrag, iban, status, pfad)
            VALUES (?, ?, ?, ?, ?, 0, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iisdss', $uid, $tstamp, $einrichtung, $betrag, $iban, $pfad);
    mysqli_stmt_execute($stmt);
    $inserted_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Erfolgreich
        echo '<div style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0, 0, 0, 0.85);
        padding: 1em 2em;
        color: white;
        font-size: 2em;
        font-weight: bold;
        border: 2px solid #11a50d;
        border-radius: 8px;
        z-index: 9999;
        box-shadow: 0 0 10px #11a50d;
    ">
        Antrag erfolgreich eingereicht.
    </div>';


    
    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
        setTimeout(function() {
        document.forms['reload'].submit();
        }, 2000);
    </script>";
}
load_menu();
?>

<div class="form-wrapper">

    <div style="text-align: center;">
    <h1 style="color: #11a50d; font-size: 1.8em;">
        Überweisung beantragen
    </h1>

    <p style="color: #cccccc; margin-bottom: 1em;">
        <strong>AGs</strong> dürfen nur zweckgebundene Ausgaben geltend machen.<br>
        Bei Unsicherheit oder Ausgaben über 50 € vorab den Vorstand kontaktieren.
    </p>

    <p style="color: #cccccc; margin-bottom: 1em;">
        <strong>Etagensprecher</strong> dürfen nur Erstattungen für die unten aufgeführten Utensilien beantragen und nur wenn der Vorstand zuvor ein Budget freigegeben hat.
    </p>
    </div>


    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5em;">
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Wasserkocher</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Mikrowelle</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Staubsauger</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Kaffeemaschine</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Insektennetz</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Toaster</div>
      <div style="padding: 0.3em; border: 1px solid #11a50d; border-radius: 4px; text-align: center;">Airfryer/Fritteuse</div>
    </div>

    <hr style="border: none; border-top: 2px solid #11a50d; margin: 1.3em 0;">

    <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="reload" value="1">

    <div style="display: flex; gap: 1em; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 200px;">
        <label for="rechnung" style="display: block;">Rechnung hochladen (PDF oder Bild):</label>
        <input type="file" name="rechnung" id="rechnung" accept=".pdf,.jpg,.jpeg,.png" required
                style="width: 100%; box-sizing: border-box;">
        </div>
        
        <div style="flex: 1; min-width: 200px;">
        <label for="einheit" style="display: block;">Für welche AG/Etage ist der Kauf?</label>
        <select name="einheit" id="einheit" required style="width: 100%; box-sizing: border-box;">
            <option value="">-- Bitte wählen --</option>
            <?php
            // AG-Zugehörigkeiten direkt aus DB holen (active + agessen)
            $sql = "
                SELECT id, name, session
                FROM `groups`
                WHERE active = 1
                AND agessen = 1
                ORDER BY prio
            ";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $agId, $agName, $agSessionKey);
            while (mysqli_stmt_fetch($stmt)) {
                if (!empty($agSessionKey) && !empty($_SESSION[$agSessionKey])) {
                    $label = htmlspecialchars($agName);
                    echo "<option value=\"ag:$agId\">$label</option>";
                }
            }
            mysqli_stmt_close($stmt);

            // Etagensprecher-Zugriff
            if (!empty($_SESSION["etagensprecher"]) && !empty($_SESSION["turm"])) {
                $code = $_SESSION["etagensprecher"];
                $etage = substr($code, 0, -1);
                $turm = $_SESSION["turm"];
                $turm_label = formatTurm($turm);

                $value = "etage:{$turm}_{$etage}";
                $label = "{$turm_label} Etage {$etage}";
                echo "<option value=\"$value\">$label</option>";
            }
            ?>
        </select>
        </div>
    </div>

    <div style="display: flex; gap: 1em; flex-wrap: wrap;">        
        <div style="flex: 1; min-width: 200px;">
        <label for="betrag" style="display: block;">Preis in Euro:</label>
        <input type="number" step="0.01" min="0" name="betrag" id="betrag" placeholder="€" required
                style="width: 100%; box-sizing: border-box;">
        </div>
        <div style="flex: 1; min-width: 200px;">
        <label for="iban" style="display: block;">IBAN für Erstattung:</label>
        <input type="text" name="iban" id="iban" placeholder="DE90 3905 0000 1070 3346 00" required
                style="width: 100%; box-sizing: border-box;">
        </div>
    </div>

    <button type="submit">
        Einreichen
    </button>
    </form>


</div>
</body>
</html>