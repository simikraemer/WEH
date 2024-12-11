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
if (auth($conn) && $_SESSION["NetzAG"]) {
    load_menu();

    function renderForm($id = "", $uid = "", $tstamp = "", $alert = "", $cn = "", $endtime = "", $services = "", $path_cert = "", $path_inclusion = "", $sn = "", $sign = "", $fingerprint = "", $aussteller = "", $isUpdate = false) {
        // Start des Formulars
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
        
        echo '<div style="text-align: center; display: flex; justify-content: center; align-items: center;">';
        echo '<form method="post">';
        echo '<table style="color: white;">';
        
        // ID - hidden
        echo '<input type="hidden" name="id" value="' . htmlspecialchars($id) . '">';
    
        // UID - hidden
        $uid = $_SESSION["uid"];
        echo '<input type="hidden" name="uid" value="' . htmlspecialchars($uid) . '">';

        if ($isUpdate) {
            // Abfrage, um den Namen des Benutzers basierend auf der UID zu erhalten
            global $conn;
            $stmt = $conn->prepare("SELECT name FROM users WHERE uid = ?");
            $stmt->bind_param("s", $uid);
            $stmt->execute();
            $stmt->bind_result($name);
            $stmt->fetch();
            $stmt->close();
    
            if (!empty($name)) {
                echo '<tr><td>Zuletzt bearbeitet von:</td><td><input type="text" value="' . htmlspecialchars($name) . '" readonly></td></tr>';
            }
    
            // Tstamp - nicht änderbar, Pflichtfeld
            $formatted_tstamp = !empty($tstamp) ? date("d.m.Y", $tstamp) : date("d.m.Y");
            echo '<tr><td>Zuletzt bearbeitet am:</td><td><input type="text" name="tstamp" value="' . $formatted_tstamp . '" readonly required></td></tr>';
        } else {
            // Setze Standardwerte für tstamp und andere Felder, wenn es sich um eine neue Eintragung handelt
            $zeit = time();
            $formatted_tstamp = date("d.m.Y", $zeit);
            echo '<input type="hidden" name="tstamp" value="' . $formatted_tstamp . '">';
        }
    
        if ($isUpdate) {
            // Alert - Alarmstufe, Pflichtfeld (Dropdown-Menü)
            echo '<tr><td>Alarmstufe:</td><td>';
            echo '<select name="alert" required>';
            echo '<option value="0"' . ($alert == 0 ? ' selected' : '') . '>Grün - Aktiv</option>';
            echo '<option value="1"' . ($alert == 1 ? ' selected' : '') . '>Gelb - Noch 1 Monat aktiv</option>';
            echo '<option value="2"' . ($alert == 2 ? ' selected' : '') . '>Rot - Noch 1 Woche aktiv</option>';
            echo '<option value="-1"' . ($alert == -1 ? ' selected' : '') . '>Grau - Inaktiv</option>';
            echo '</select>';
            echo '</td></tr>';
        } else {
            echo '<input type="hidden" name="alert" value="0">';
        }

    
        // CN - Pflichtfeld
        echo '<tr><td>Common Name:</td><td><input type="text" name="cn" value="' . htmlspecialchars($cn) . '" required></td></tr>';
    
        // Endtime - umgewandelter Unixzeitstempel, Pflichtfeld
        $formatted_endtime = !empty($endtime) ? date("Y-m-d", $endtime) : "";
        echo '<tr><td>Endzeit:</td><td><input type="date" name="endtime" value="' . $formatted_endtime . '" required></td></tr>';
    
        // Services - Pflichtfeld
        echo '<tr><td>Services:</td><td><input type="text" name="services" value="' . htmlspecialchars($services) . '" required></td></tr>';
    
        // Path_cert - Pflichtfeld
        echo '<tr><td>Pfad zum Zertifikat:</td><td><input type="text" name="path_cert" value="' . htmlspecialchars($path_cert) . '" required></td></tr>';
    
        // Path_inclusion - Pflichtfeld, mehrzeiliges Textfeld (Mediumtext)
        echo '<tr><td>Pfad zur Einbindung:</td><td><textarea name="path_inclusion" rows="4" required>' . htmlspecialchars($path_inclusion) . '</textarea></td></tr>';
    
        // SN - kein Pflichtfeld
        echo '<tr><td>Seriennummer:</td><td><input type="text" name="sn" value="' . htmlspecialchars($sn) . '"></td></tr>';
    
        // Sign - kein Pflichtfeld
        echo '<tr><td>Signatur:</td><td><input type="text" name="sign" value="' . htmlspecialchars($sign) . '"></td></tr>';
    
        // Fingerprint - kein Pflichtfeld
        echo '<tr><td>Fingerprint:</td><td><input type="text" name="fingerprint" value="' . htmlspecialchars($fingerprint) . '"></td></tr>';

        // Aussteller - kein Pflichtfeld
        $aussteller_optionen = [
            "RWTH & GÉANT",
            "RWTH & DFN",
            "LetsEncrypt",
            "Selbst signiert",
            "" // Leerer String
        ];
        echo '<tr><td>Aussteller:</td><td>
                <select name="aussteller">';
        foreach ($aussteller_optionen as $option) {
            $selected = ($aussteller == $option) ? ' selected' : '';
            $displayText = $option === "" ? "" : htmlspecialchars($option);
            echo '<option value="' . htmlspecialchars($option) . '"' . $selected . '>' . $displayText . '</option>';
        }
        echo '</select>
              </td></tr>';
    
        echo '</table>';
    
        // Submit-Button
        echo '<input type="hidden" name="reload" value="1">';
        $submitLabel = $isUpdate ? "Änderungen speichern" : "Neues Zertifikat hinzufügen";
        $submitName = $isUpdate ? "exec_update" : "exec_new";
        echo '<br><br><input type="submit" name="' . $submitName . '" class="center-btn" value="' . $submitLabel . '">';
        echo '</form>';
        echo '</div>';
    }
    
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt        
        $uid = isset($_POST["uid"]) ? intval($_POST["uid"]) : 0;
        $tstamp = isset($_POST["tstamp"]) ? strtotime($_POST["tstamp"]) : time();
        $alert = isset($_POST["alert"]) ? intval($_POST["alert"]) : 0;
        $cn = isset($_POST["cn"]) ? strval($_POST["cn"]) : '';
        $endtime = isset($_POST["endtime"]) ? strtotime($_POST["endtime"]) : null;
        $services = isset($_POST["services"]) ? strval($_POST["services"]) : '';
        $path_cert = isset($_POST["path_cert"]) ? strval($_POST["path_cert"]) : '';
        $path_inclusion = isset($_POST["path_inclusion"]) ? strval($_POST["path_inclusion"]) : '';
        $sn = isset($_POST["sn"]) ? strval($_POST["sn"]) : '';
        $sign = isset($_POST["sign"]) ? strval($_POST["sign"]) : '';
        $fingerprint = isset($_POST["fingerprint"]) ? strval($_POST["fingerprint"]) : '';
        $aussteller = isset($_POST["aussteller"]) ? strval($_POST["aussteller"]) : '';

        if (isset($_POST["exec_new"])) {
            $sql_insert = "INSERT INTO certs (uid, tstamp, alert, cn, endtime, services, path_cert, path_inclusion, sn, sign, fingerprint, aussteller) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = mysqli_prepare($conn, $sql_insert);
            mysqli_stmt_bind_param($stmt_insert, "iiisisssssss", $uid, $tstamp, $alert, $cn, $endtime, $services, $path_cert, $path_inclusion, $sn, $sign, $fingerprint, $aussteller);
            mysqli_stmt_execute($stmt_insert);
            $stmt_insert->close();    
            reloadpost();
        }
    
        if (isset($_POST["exec_update"]) && isset($_POST["id"])) {
            $id = $_POST["id"];
            $sql = "UPDATE certs SET uid = ?, tstamp = ?, alert = ?, cn = ?, endtime = ?, services = ?, path_cert = ?, path_inclusion = ?, sn = ?, sign = ?, fingerprint = ?, aussteller = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iiisisssssssi", $uid, $tstamp, $alert, $cn, $endtime, $services, $path_cert, $path_inclusion, $sn, $sign, $fingerprint, $aussteller, $id);
            mysqli_stmt_execute($stmt);
            $stmt->close();                 
            reloadpost();
        }
    }

    echo '<div style="text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center;">';
    echo '<form method="post" style="margin-bottom: 40px;">';  // Hier wurde ein margin-bottom hinzugefügt
    echo '<button type="submit" name="menu_add" value="1" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">'.htmlspecialchars("Neues Zertifikat").'</button>';
    echo '</form>';

    // Abfrage der Daten aus der Datenbank
    $sql = "SELECT id, cn, endtime, services, path_cert, alert 
            FROM certs 
            ORDER BY 
                CASE WHEN alert > -1 THEN 0 ELSE 1 END, 
                endtime";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo '<form method="POST" action="" id="updateForm">';  // Formularstart
        echo '<table class="grey-table" style="margin: 0 auto; margin-bottom: 40px; text-align: center; border-collapse: collapse; border: 2px solid white; width: 100%; box-shadow: 0px 0px 30px rgba(255, 255, 255, 0.5); border-radius: 8px;">';
        
        // Tabellenkopf
        echo '<tr style="background-color: transparent; color: #ffffff; font-size: 25px;">';
        echo '<th style="padding: 8px;">Common Name</th>';
        echo '<th style="padding: 8px;">Enddatum</th>';
        echo '<th style="padding: 8px;">Services</th>';
        echo '<th style="padding: 8px;">Pfad zum Zertifikat</th>';
        echo '</tr>';
        
        
        // Tabellendaten
        while($row = $result->fetch_assoc()) {
            $formatted_endtime = date("d.m.Y", $row["endtime"]);  // Unix-Zeitstempel in deutsches Datumformat konvertieren
        
            // Hintergrundfarbe basierend auf dem alert-Wert
            $row_color = "";
            $text_color = "white";
            
            switch($row["alert"]) {
                case -1:
                    $row_color = "#222";
                    break;
                case 0:
                    $row_color = "#1f531e";
                    break;
                case 1:
                    $row_color = "yellow";
                    $text_color = "black";
                    break;
                case 2:
                    $row_color = "red";
                    $text_color = "black";
                    break;
            }
            
            $style = 'style="padding: 8px; background-color: ' . $row_color . '; color: ' . $text_color . '; font-size: 20px; padding: 3px;"';
 
            
        
            echo '<tr class="clickable-row" data-id="' . $row["id"] . '" style="background-color: ' . $row_color . '; cursor: pointer;">';
            echo '<td '.$style.'>' . htmlentities($row["cn"]) . '</td>';
            echo '<td '.$style.'>' . $formatted_endtime . '</td>';
            echo '<td '.$style.'>' . htmlentities($row["services"]) . '</td>';
            echo '<td '.$style.'>' . htmlentities($row["path_cert"]) . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '<input type="hidden" name="id" id="id_field" value="">';  // Verstecktes Feld für die ID
        echo '<input type="hidden" name="menu_update" value="1">';  // Verstecktes Feld für menu_update
        echo '</form>';  // Formularende
        
        // JavaScript für das Klickverhalten
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var rows = document.querySelectorAll(".clickable-row");
                rows.forEach(function(row) {
                    row.addEventListener("click", function() {
                        var id = this.getAttribute("data-id");
                        document.getElementById("id_field").value = id;
                        document.getElementById("updateForm").submit();
                    });
                });
            });
        </script>';
        
        // Inline CSS für den Hover-Effekt
        echo '<style>
            .clickable-row:hover {
                background-color: white !important;  /* Hover-Farbe */
                color: black !important;  /* Textfarbe beim Hover */
            }
        </style>';
        
        
    }
    

    if (isset($_POST["menu_add"])) {
        renderForm();
        
    } elseif (isset($_POST["menu_update"])) {
        $id = $_POST["id"];
    
        $sql = "SELECT uid, tstamp, alert, cn, endtime, services, path_cert, path_inclusion, sn, sign, fingerprint, aussteller FROM certs WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $tstamp, $alert, $cn, $endtime, $services, $path_cert, $path_inclusion, $sn, $sign, $fingerprint, $aussteller);  
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_free_result($stmt);
        mysqli_stmt_close($stmt);
    
        renderForm($id, $uid, $tstamp, $alert, $cn, $endtime, $services, $path_cert, $path_inclusion, $sn, $sign, $fingerprint, $aussteller, true);
    } else {
        echo "<br><br><br><br><br><br><br>";
    }

    echo '</div>'; # Ende Zentrierung

// Verbindung schließen
$conn->close();
}
else {
  header("Location: denied.php");
}

$conn->close();
?>
</html>