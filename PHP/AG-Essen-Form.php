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
if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();

    
    $hasAgMembership = false;
    $agMembershipCount = 0;
    foreach ($ag_key2session as $key => $value) {
      if (isset($_SESSION[$value]) && $_SESSION[$value] == true) {            
            $hasAgMembership = true;
            $agMembershipCount++;
        }
    }

#    if ($agMembershipCount == 1 && !isset($_POST["ag"])) {
#        echo '<form id="agForm" method="post" style="display:flex; justify-content:center; align-items:center;">';
#        foreach ($ag_key2session as $key => $value) { #61 kassenwart, 62 schriftführer, 63 vorsitz, 66 dsb, 26 kassenprüfer, 24 hausmeister, 19 etagensprecher (just2besafe)
#            if (isset($_SESSION[$value]) && $_SESSION[$value] == true && $key != 61 && $key != 62 && $key != 63 && $key != 66 && $key != 26 && $key != 24 && $key != 19) {            
#                echo '<input type="hidden" name="ag" value="' . $key . '">';
#                break; // Stoppe die Schleife, sobald ein passender Wert gefunden wurde
#            }
#        }
#        echo '</form>';
#        echo '<script>document.getElementById("agForm").submit();</script>'; // Automatisch Formular senden
#    }

    if (!isset($_POST["ag"])) {

        if (!$hasAgMembership) {
            echo '<h1 style="font-size: 60px; color: white; text-align: center;">You are currently not a member of any AG!</h1>';
        } else {
            $availableAgs = [];

            foreach ($ag_complete as $id => $data) {
                if ((isset($_SESSION[$data['session']]) && $_SESSION[$data['session']] == true) && ($data['agessen'] == 1)) {
                    $availableAgs[$id] = $data['name'];
                }
            }

        
            // Prüfe, ob nur eine AG verfügbar ist
            if (count($availableAgs) === 1) {
                $singleAgKey = array_key_first($availableAgs);
                // Automatisches POST
                echo '<form id="auto-ag-form" method="post">
                        <input type="hidden" name="ag" value="' . $singleAgKey . '">
                      </form>';
                echo '<script>
                        document.getElementById("auto-ag-form").submit();
                      </script>';
            } else {
                echo '<h1 style="font-size: 60px; color: white; text-align: center;">Select AG:</h1>';
                echo '<form method="post" style="display:flex; justify-content:center; align-items:center; flex-wrap: wrap;">';
            
                foreach ($availableAgs as $key => $value) {
                    echo '<button type="submit" name="ag" value="' . $key . '" class="house-button" style="font-size:50px; margin:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">' . $value . '</button>';
                }
            
                echo '</form>';
                echo "<br><br>";
            }
        }
    } else { 
    # AG wurde ausgewählt

        $ag = strval($_POST["ag"]);
        $agname = $ag_key2name[$ag];
        $trinkgeldfaktor = 1.1;
        $zeit = time();
        $startOfSemester = unixtime2startofsemester($zeit);
        $semester = unixtime2semester($zeit);

        $sql = "SELECT SUM(betrag) FROM agessen WHERE ag = ? AND tstamp > ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $ag, $startOfSemester);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $spent);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $schonwaseingetragen = True;
        if ($spent === null) {
            $spent = 0;
            $schonwaseingetragen = False;
        }

        $sql = "SELECT COUNT(uid) FROM users WHERE CONCAT(',', groups, ',') LIKE CONCAT('%,', ?, ',%') AND pid in (11,64) ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        $ag_str = strval($ag);
    mysqli_stmt_bind_param($stmt, "s", $ag_str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count_members);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $sql = "SELECT wert FROM constants WHERE name = 'essen_pp'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $budgetpP);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        
        $sql = "SELECT wert FROM constants WHERE name = 'essen_count'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mindestteilnehmer);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($ag == 7 || $ag == 9) { # NetzAG&Vorstand dürfen doppelten Wert fraisen
            $budgetpP = $budgetpP * 2;
        }
        $offenohnetrinkgeld = ($budgetpP * $count_members) - $spent;
        $offen = $offenohnetrinkgeld * $trinkgeldfaktor;


        if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
            if (isset($_POST["esseneintragen"])) {
                if(isset($_POST['iban'])) {
                    $iban = $_POST['iban'];
                    $iban = rtrim(chunk_split(str_replace(' ', '', $iban), 4, ' '));
                } else {
                    $iban = '';
                }
                
                if(isset($_POST['bar'])) {
                    $bar = $_POST['bar'];
                    if ($bar != "Bar 0") {
                        $iban = $bar;
                    }
                }
                $betrag = $_POST['betrag'];
                $betragstring = number_format($betrag, 2, ',', '.') . ' €';
                $teilnehmer_array = $_POST['selected_users'];
                $count_teilnehmer = count($teilnehmer_array);
                $teilnehmer = $_POST['selected_users_list'];
                $tstamp = $zeit;
                $uid = $_SESSION['user'];
                $limit = $budgetpP * $count_teilnehmer * $trinkgeldfaktor;
                $limitstring = number_format($limit, 2, ',', '.') . ' €';
        
                if ($betrag > $offen) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Der Betrag ist zu hoch!
                    <br>Ihr könnt maximal noch ".number_format($offen, 2, ',', '.') . ' €'." in diesem Semester ausgeben.</p>";
                    echo "</div>";
                } elseif ($count_teilnehmer < $mindestteilnehmer) {                
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Es müssen mindestens $mindestteilnehmer Teilnehmer an einem AG-Essen teilnehmen!</p>";
                    echo "</div>";
                } elseif ($betrag > $limit) {                
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Der Betrag ist zu hoch!
                    <br>Für $count_teilnehmer Teilnehmer ist das Betragslimit bei $limitstring!</p>";
                    echo "</div>";
                } elseif (!isValidIBAN($iban) && strpos($iban, "Bar") === false) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Die IBAN $iban hat ein falsches Format!</p>";
                    echo "</div>";
                } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
                    // Debugging-Ausgaben
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
                    echo "<p>Debug Info:</p>";
                    if (isset($_FILES['file'])) {
                        echo "<p>File Error: " . $_FILES['file']['error'] . "</p>";
                    } else {
                        echo "<p>No file found in \$_FILES array.</p>";
                    }
                    echo "</div>";
                } else {
                    $file_path = null;
        
                    if (!is_dir('rechnungen')) {
                        mkdir('rechnungen', 0777, true);
                    }
        
                    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                        $unixtime = time(); // aktuelle Unixzeit
                        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                        $file_name = 'essen-' . $unixtime . '.' . $file_extension;
                        $file_path = 'rechnungen/' . $file_name;
        
                        // Überprüfung auf erlaubte Dateitypen (Erweiterungen)
                        $allowed_extensions = array('jpg', 'jpeg', 'png', 'pdf');
                        if (!in_array($file_extension, $allowed_extensions)) {
                            echo "<div style='text-align: center;'>";
                            echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) und PDF-Dateien sind erlaubt.</p>";
                            echo "</div>";
                            exit();
                        }
                    
                        // Überprüfung auf erlaubte MIME-Typen
                        $allowed_mime_types = array('image/jpeg', 'image/png', 'application/pdf');
                        $file_mime_type = mime_content_type($_FILES['file']['tmp_name']);
                        if (!in_array($file_mime_type, $allowed_mime_types)) {
                            echo "<div style='text-align: center;'>";
                            echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) und PDF-Dateien sind erlaubt.</p>";
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
        
                    $insert_sql = "INSERT INTO agessen (uid, tstamp, ag, betrag, teilnehmer, iban, pfad) VALUES (?,?,?,?,?,?,?)";
                    $stmt = mysqli_prepare($conn, $insert_sql);
                    mysqli_stmt_bind_param($stmt, "iisssss", $uid, $zeit, $ag, $betrag, $teilnehmer, $iban, $file_path);
                    mysqli_stmt_execute($stmt);
        
                    $message = "Hallo Kassenwarte," . PHP_EOL .
                    "\nEs wurde ein neues AG-Essen eingetragen." . PHP_EOL .
                    "Weitere Verarbeitung auf folgender Seite:" . PHP_EOL .
                    "https://backend.weh.rwth-aachen.de/AG-Essen.php" . PHP_EOL .
                    "\nViele Grüße," . PHP_EOL .
                    "AG-Essen-Form.php";     
                    $to = "kasse@weh.rwth-aachen.de";
                    $subject = "WEH - AG Essen";
                    $headers = "From: " . $mailconfig['address'] . "\r\n";
                    $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
                    if (!mail($to, $subject, $message, $headers)) {
                        echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                                <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                              </div>";
                    }
        
                    echo "<div style='text-align: center; font-size: 50px; color: #66FF99'>";
                    echo "Deine Anfrage wurde gesendet.";
                    echo "</div>";
                    echo "<br><br>";
                    # -------------------------------------------------
                    echo "<br>";
                    echo '<hr style="border-top: 1px solid white;">';
                    echo "<br>";
                    # -------------------------------------------------
        
                    echo '<form id="agForm" method="post" style="display:none;">';
                    echo '<input type="hidden" name="ag" value="' . $ag . '">';
                    echo '</form>';
        
                    echo '<script>
                        setTimeout(function() {
                            document.getElementById("agForm").action = window.location.href;
                            document.getElementById("agForm").submit();
                        }, 0000);
                    </script>';
                }
            }
        }
        
        
        echo '<div style="text-align: center; font-size: 60px; color: white;">';
        echo $agname;
        echo '</div><br><br><br>';
        echo '<div style="text-align: center; font-size: 30px; color: white;">';
        echo 'Offenes Essensbudget im '.$semester.'';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 40px; color: white;">';
        echo number_format($offenohnetrinkgeld, 2, ',', '.') . ' €';
        echo '</div>';           
        echo '<div style="text-align: center; font-size: 25px; color: white;">';
        echo "(".number_format($offen, 2, ',', '.') . ' € mit Trinkgeld)';
        echo '</div>';   

        if ($schonwaseingetragen) {
            # -------------------------------------------------
            echo "<br>";
            echo '<hr style="border-top: 1px solid white;">';
            echo "<br>";
            # -------------------------------------------------

            echo '<div style="text-align: center; font-size: 60px; color: white;">';
            echo "Einträge im $semester";
            echo '</div><br><br><br>'; 

            echo "<style>
                table {
                    border-collapse: collapse;
                    color: white;
                    margin: auto;
                    font-size: 16px;
                }
                th, td {
                    padding: 10px;
                    text-align: center;
                    border-bottom: 1px solid white;
                }
            </style>";
            
            echo "<table class='agessentable'>";
            echo "<tr><th>Datum</th><th>Status</th><th>Betrag</th><th>IBAN</th><th>Teilnehmer</th></tr>";

            $sql = "SELECT a.tstamp, a.status, a.betrag, a.iban, 
            GROUP_CONCAT(
                CONCAT(
                    SUBSTRING_INDEX(u.firstname, ' ', 1), -- Abschneiden bei Leerzeichen
                    ' ',
                    LEFT(u.lastname, 1), -- Erster Buchstabe des Nachnamens
                    '.'
                ) ORDER BY FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) SEPARATOR ', '
            ) AS teilnehmer_namen
            FROM weh.agessen a
            JOIN weh.users u ON FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) > 0
            WHERE a.ag = ? AND a.tstamp > ?
            GROUP BY a.id
            ORDER BY a.tstamp DESC";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ss", $ag, $startOfSemester);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $tstamp, $status, $betrag, $iban, $teilnehmerstring);
            while (mysqli_stmt_fetch($stmt)) {
                $tstamp_show = date('d.m.Y', $tstamp);
                $statusstring = ($status == 0) ? "In Bearbeitung" : (($status == 1) ? "Überwiesen" : "");
                $betrag_show = number_format($betrag, 2, ',', '.') . ' €';
                echo "<tr><td>$tstamp_show</td><td>$statusstring</td><td>$betrag_show</td><td>$iban</td><td>$teilnehmerstring</td></tr>";
            }
            echo "</table>";
            mysqli_stmt_close($stmt);
        }
        echo "<br><br><br><br>";

        # -------------------------------------------------
        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";
        # -------------------------------------------------

        echo '<div style="text-align: center; font-size: 60px; color: white;">';
        echo "Neues AG-Essen eintragen";
        echo '</div><br><br><br>';    
        
        echo '<div style="width: 70%; margin: 0 auto; text-align: center;">';
        echo '<form method="post" enctype="multipart/form-data">';    

        
        $options = array(
            "Bitte auswählen - 0",
            "Netzbarkasse 1 - 1",
            "Netzbarkasse 2 - 2",
            "Kassenwartkasse 1 - 3",
            "Kassenwartkasse 2 - 4"
        );
        
        echo '<div id="ibanContainer" style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="iban" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">IBAN:</label>';
        echo '<input type="text" id="iban" name="iban" style="width: 200px;">';
        echo '</div>';
        
        if ($_SESSION['NetzAG'] || $_SESSION['Vorstand']) {
            echo '<div id="dropdownContainer" style="display: none; text-align: center; margin-bottom: 10px;">';
            echo '<label for="bar" style="display: inline-block; width: 158px; color: white; font-size:25px; text-align: left;">Kasse:</label>';
            echo '<select id="bar" name="bar" style="width: 200px;">';
            foreach ($options as $option) {
                list($label, $value) = explode(" - ", $option);
                echo '<option value="Bar ' . $value . '">' . $label . '</option>';
            }
            echo '</select>';
            echo '</div>';

            echo '<input type="checkbox" id="barkasseCheckbox" onclick="toggleIBAN()" style="color: white;"> <label for="barkasseCheckbox" style="color: white;">Von Barkasse bezahlt</label>';
            echo "<br><br>";
        }

        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="betrag" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Betrag:</label>';
        echo '<input type="number" step="0.01" id="betrag" name="betrag" style="width: 200px;" required><br><br>';
        echo '</div>';

        echo '<div style="align-items: center; margin-bottom: 10px;">';
        echo '<label for="file" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Rechnung:</label>';
        echo '<input type="file" id="file" name="file" style="width: 190px; background-color: white; border: 2px solid black; padding: 5px">';    
        echo '</div>';    
        echo '<br>';

        $sql = "SELECT uid, firstname, lastname FROM users WHERE CONCAT(',', groups, ',') LIKE CONCAT('%,', ?, ',%') AND pid in (11,64) ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        $ag_str = strval($ag);
    mysqli_stmt_bind_param($stmt, "s", $ag_str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $firstName, $lastName);
        while (mysqli_stmt_fetch($stmt)) {
            $name = strtok($firstName, ' ') . ' ' . strtok($lastName, ' ');

            echo '<div style="text-align: center; margin-bottom: 10px;">';
            echo '<label for="user_' . $uid . '" style="display: inline-block; color: white; font-size:25px; text-align: left;">';
            echo '<input type="checkbox" id="user_' . $uid . '" name="selected_users[]" value="' . $uid . '"> ' . $name;
            echo '</label>';
            echo '</div>';

        }
        mysqli_stmt_close($stmt);
        echo '<input type="hidden" id="selected_users_list" name="selected_users_list" value="">';
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    const checkboxes = document.querySelectorAll(\'input[name="selected_users[]"]\');';
        echo '    checkboxes.forEach(function(checkbox) {';
        echo '        checkbox.addEventListener("change", function() {';
        echo '            const selectedUsers = Array.from(checkboxes)';
        echo '                .filter(checkbox => checkbox.checked)';
        echo '                .map(checkbox => checkbox.value)';
        echo '                .join(",");';
        echo '            document.getElementById("selected_users_list").value = selectedUsers;';
        echo '        });';
        echo '    });';
        echo '});';
        echo '</script>';

        echo '<br>';

        
        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="ag" value="' . $ag . '">';
        echo '<input type="hidden" name="reload" value=1>';
        echo '<button type="submit" name="esseneintragen"  class="center-btn" style="display: block; margin: 0 auto;">Absenden</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        
        
        
        echo '<script>
            function toggleIBAN() {
                var ibanContainer = document.getElementById("ibanContainer");
                var dropdownContainer = document.getElementById("dropdownContainer");
                var checkBox = document.getElementById("barkasseCheckbox");
                if (checkBox.checked) {
                    ibanContainer.style.display = "none";
                    dropdownContainer.style.display = "block";
                } else {
                    ibanContainer.style.display = "block";
                    dropdownContainer.style.display = "none";
                }
            }
        </script>';
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