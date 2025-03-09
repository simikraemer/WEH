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

    $states = [
        'drucker_waehlen' => ['next' => 'dokument_upload', 'previous' => 'drucker_waehlen'],
        'dokument_upload' => ['next' => 'druckoptionen', 'previous' => 'drucker_waehlen'],
        'druckoptionen' => ['next' => 'vorschau', 'previous' => 'dokument_upload'],
        'vorschau' => ['next' => 'drucken', 'previous' => 'druckoptionen'],
        'drucken' => ['next' => 'drucker_wahlen', 'previous' => null],
    ];        
    
    $drucker = [
        ["id" => 1, "turm" => "WEH", "farbe" => "Schwarz-Weiß", "modell" => "Kyocera ECOSYS P3260dn", "ip" => "137.226.141.5", "name" => "WEHsw"],
        ["id" => 2, "turm" => "WEH", "farbe" => "Farbe", "modell" => "Kyocera ECOSYS M8124cidn", "ip" => "137.226.141.193", "name" => "WEHfarbe"],
        ["id" => 3, "turm" => "TvK", "farbe" => "Schwarz-Weiß", "modell" => "Firma MODEL XXX", "ip" => "137.226.141.5", "name" => "TvKfarbe"]
    ];
    

    // Standardmäßig auf den ersten Schritt setzen
    if (!isset($_SESSION['printer_step'])) {
        $_SESSION['printer_step'] = 'drucker_waehlen';
    }

    // Verarbeitung von Formularen zur Steuerung des Workflows
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['drucker_id'])) {
            $_SESSION['drucker_id'] = $_POST['drucker_id']; // Drucker-ID speichern
        }

        if (isset($_POST['next_step'])) {
            $_SESSION['printer_step'] = $states[$_SESSION['printer_step']]['next'];
        } elseif (isset($_POST['previous_step'])) {
            $_SESSION['printer_step'] = $states[$_SESSION['printer_step']]['previous'];
        }
    }   

    // Inhalt basierend auf dem aktuellen Schritt rendern
    switch ($_SESSION['printer_step']) {    
        case 'drucker_waehlen':
            
            $nutzerTurm = strtolower($_SESSION["turm"] ?? "");
            $community = "public";

            function get_snmp_value($ip, $oid) {
                $value = snmpget($ip, "public", $oid);
                return preg_replace('/[^0-9]/', '', $value); // Entfernt alles außer Zahlen
            }

            // Drucker nach Türmen sortieren
            $turmGruppen = ["WEH" => [], "TvK" => []];
            foreach ($drucker as $d) {
                $turmGruppen[$d["turm"]][] = $d;
            }

            // Eigener Turm kommt zuerst
            $turmReihenfolge = ($nutzerTurm === "weh") ? ["WEH", "TvK"] : ["TvK", "WEH"];

            $output .= '<div class="printer_container">';

            foreach ($turmReihenfolge as $turm) {
                if (empty($turmGruppen[$turm])) continue;

                // Zeile für den Turm    
                $output .= '<div class="printer_turm_row">';
                $output .= '<h3 class="printer_h3">' . htmlspecialchars($turm) . '</h3>';
                $output .= '</div>';
                $output .= '<div class="printer_flexbox">';

                foreach ($turmGruppen[$turm] as $d) {
                    $ip = $d["ip"];

                    // Falls IP nicht gesetzt, überspringen
                    if ($ip === "todo") continue;

                    $printer_status = snmpget($ip, $community, "1.3.6.1.2.1.25.3.5.1.1.1");
                    $printer_status = preg_replace('/[^0-9]/', '', $printer_status);
                    
                    $status_message = "Unbekannter Status"; // Standardwert
                    $printer_ready = false;
                    
                    // Zusätzliche SNMP-Abfrage für Fehlerbeschreibung
                    $snmp_error_message = snmpget($ip, $community, "1.3.6.1.2.1.43.16.5.1.2.1.1");
                    $snmp_error_message = trim(str_replace("STRING:", "", $snmp_error_message)); // Bereinigen

                    $status_message = "⚠ Unbekannter Fehler oder Wartung erforderlich ($printer_status)";

                    // Überprüfen, ob die Fehlermeldung "Load Paper" enthält
                    if (strpos($snmp_error_message, "Load paper") !== false) {
                        $status_message = "🛑 Kein Papier!";
                    }
                    if (strpos($snmp_error_message, "Check waste toner") !== false) {
                        $status_message = "🔥 Waste toner voll!";
                    }
                    if (strpos($snmp_error_message, "Paper jammed") !== false) {
                        $status_message = "🚨 Papierstau!";
                    }
                    if (strpos($snmp_error_message, "Replace the toner") !== false) {
                        $status_message = "🔥 Toner leer!";
                    }
                    if (strpos($snmp_error_message, "Close") !== false) {
                        $status_message = "🚪 Klappe offen!";
                    }
                    
                    switch ($printer_status) {
                        case 2:
                        case 3:
                            $status_message = "✅ Drucker ist bereit";
                            $printer_ready = true;
                            break;
                        case 5:
                            $status_message = "🛑 Kein Papier!";
                            break;
                        case 6:
                            $status_message = "🚨 Papierstau!";
                            break;
                        case 7:
                            $status_message = "🔥 Toner leer!";
                            break;
                        case 8:
                            $status_message = "🚪 Tür offen!";
                            break;
                        case 9:
                            $status_message = "❌ Drucker offline!";
                            break;
                    }
                    

                    // Papier-Kapazitäten
                    if ($ip === "137.226.141.5") { // SW Drucker (5x A4)
                        $toner_black_raw = snmpget($ip, $community, "1.3.6.1.2.1.43.11.1.1.9.1.1");
                        $toner_black_value = preg_replace('/[^0-9]/', '', $toner_black_raw);
                        $toner_black = round(($toner_black_value / 40000) * 100); // Skalierung auf 100%

                        $papier_A4_aktuell = 0;
                        for ($i = 2; $i <= 6; $i++) {
                            $papier_A4_aktuell += get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.$i"); 
                        }
                        $papier_A4_max = 2500; // 5 Kassetten mit 500 Blättern
                        $papier_A3_aktuell = "-";
                        $papier_A3_max = "-";
                    } elseif ($ip === "137.226.141.193") { // Farb-Drucker (2x A4, 1x A3)
                        // Richtige OIDs verwenden!
                        $toner_cyan_raw = snmpget($ip, $community, "1.3.6.1.2.1.43.11.1.1.9.1.1");
                        $toner_magenta_raw = snmpget($ip, $community, "1.3.6.1.2.1.43.11.1.1.9.1.2");
                        $toner_yellow_raw = snmpget($ip, $community, "1.3.6.1.2.1.43.11.1.1.9.1.3");
                        $toner_black_raw = snmpget($ip, $community, "1.3.6.1.2.1.43.11.1.1.9.1.4");
                        $toner_cyan_value = preg_replace('/[^0-9]/', '', $toner_cyan_raw);
                        $toner_magenta_value = preg_replace('/[^0-9]/', '', $toner_magenta_raw);
                        $toner_yellow_value = preg_replace('/[^0-9]/', '', $toner_yellow_raw);
                        $toner_black_value = preg_replace('/[^0-9]/', '', $toner_black_raw);
                        $toner_cyan = round(($toner_cyan_value / 6000) * 100);
                        $toner_magenta = round(($toner_magenta_value / 6000) * 100);
                        $toner_yellow = round(($toner_yellow_value / 6000) * 100);
                        $toner_black = round(($toner_black_value / 12000) * 100);

                        # NACH DIN A3 add
                        $papier_A4_aktuell = get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.2") + 
                                            get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.3");
                        $papier_A4_max = 1000; // 2 Kassetten mit je 500 Blättern
                        $papier_A3_aktuell = get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.4");
                        $papier_A3_max = 500; // 1 Kassette mit 500 Blättern
                    }

                    // Button-Formular
                    $output .= '<form method="POST" class="printer_form">';
                    $output .= '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
                    $output .= '<input type="hidden" name="next_step" value="true">';

                    $druckerTurm = strtolower($d["turm"]);
                    $istTurmdesUsers = ($druckerTurm === $nutzerTurm);
                    $style = ($istTurmdesUsers && $printer_ready) ? "" : "opacity: 0.5; pointer-events: none;";
                    
                    $output .= '<button type="submit" class="printer_button printer_flex_button" style="' . $style . '">';
                    $output .= '<div class="printer_content">';
                    $output .= htmlspecialchars($d["modell"]).'<br>';
                    $output .= '<strong>' . htmlspecialchars($d["farbe"]) . '</strong><br>';

                    // Papieranzeige (A4)
                    $output .= '<div class="printer_bar_container">';
                    $output .= '<div class="printer_label">Papier A4: ' . $papier_A4_aktuell . ' / ' . $papier_A4_max . '</div>';
                    $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_paper" style="width:' . ($papier_A4_aktuell / $papier_A4_max * 100) . '%"></div></div>';
                    $output .= '</div>';

                    // Papieranzeige (A3, falls vorhanden)
                    if ($papier_A3_max !== "-") {
                        $output .= '<div class="printer_bar_container">';
                        $output .= '<div class="printer_label">Papier A3: ' . $papier_A3_aktuell . ' / ' . $papier_A3_max . '</div>';
                        $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_paper" style="width:' . ($papier_A3_aktuell / $papier_A3_max * 100) . '%"></div></div>';
                        $output .= '</div>';
                    }

                    // Toner Schwarz
                    $output .= '<div class="printer_bar_container">';
                    $output .= '<div class="printer_label">Schwarz: ' . $toner_black . '%</div>';
                    $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_black" style="width:' . $toner_black . '%"></div></div>';
                    $output .= '</div>';

                    // Farbtoner nur für Farbdrucker anzeigen
                    if ($d["farbe"] === "Farbe") {
                        // Cyan
                        $output .= '<div class="printer_bar_container">';
                        $output .= '<div class="printer_label">Cyan: ' . $toner_cyan . '%</div>';
                        $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_cyan" style="width:' . $toner_cyan . '%"></div></div>';
                        $output .= '</div>';
                        
                        // Magenta
                        $output .= '<div class="printer_bar_container">';
                        $output .= '<div class="printer_label">Magenta: ' . $toner_magenta . '%</div>';
                        $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_magenta" style="width:' . $toner_magenta . '%"></div></div>';
                        $output .= '</div>';
                        
                        // Gelb
                        $output .= '<div class="printer_bar_container">';
                        $output .= '<div class="printer_label">Gelb: ' . $toner_yellow . '%</div>';
                        $output .= '<div class="printer_bar_bg"><div class="printer_bar printer_yellow" style="width:' . $toner_yellow . '%"></div></div>';
                        $output .= '</div>';
                    }

                    $output .= '</div>'; // Ende des flex-grow Inhalts
                    $output .= '<div class="printer_status">' . $status_message . '</div>';

                    $output .= '</button>';

                    $output .= '</form>';
                }

                $output .= '</div>'; // Flexbox-Ende für den Turm
            }

            $output .= '</div>';
            echo $output;
            break;
        


            




        
        case 'dokument_upload':
            echo "<div class='printer_container'>";

            $output = '<div class="printer_back_container">';
            $output .= '<form method="POST" action="">';
            $output .= '<button type="submit" name="previous_step" class="printer_button">⬅ Zurück</button>';
            $output .= '</form>';
            $output .= '</div>';
            echo $output;        
        
            // Falls keine Session für hochgeladene Dateien existiert, initialisieren
            if (!isset($_SESSION['uploaded_files'])) {
                $_SESSION['uploaded_files'] = [];
            }
        
            // Falls eine Datei gelöscht wird
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
                $index = (int)$_POST['delete_file'];
                if (isset($_SESSION['uploaded_files'][$index])) {
                    unlink($_SESSION['uploaded_files'][$index]['path']); // Löscht Datei vom Server
                    array_splice($_SESSION['uploaded_files'], $index, 0); // Entfernt aus Session
                }
            }
        

            // Falls eine neue Datei hochgeladen wird
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dokumente'])) {
                $uploadsDir = "printuploads/";
                $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];

                foreach ($_FILES['dokumente']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['dokumente']['name'][$key];
                    $fileType = mime_content_type($tmp_name);

                    if (!in_array($fileType, $allowedTypes)) {
                        echo "<p class='printer_error-message'>Fehler: '$fileName' hat ein ungültiges Format!</p>";
                        continue;
                    }

                    // Prüfen, ob die PDF beschädigt ist und ob es verschlüsselt ist
                    if ($fileType === "application/pdf") {
                        if (is_pdf_encrypted($tmp_name)) {
                            echo "<p class='printer_error-message'>Fehler: '$fileName' ist verschlüsselt und wird nicht akzeptiert!</p>";
                            continue;
                        }

                        // Prüfen, ob das PDF korrekt formatiert ist
                        if (!is_valid_pdf($tmp_name)) {
                            echo "<p class='printer_error-message'>Fehler: '$fileName' ist beschädigt oder falsch formatiert!</p>";
                            continue;
                        }
                    }

                    // Dateinamen formatieren
                    $formattedFileName = sanitizeFileName($fileName);
                    $shortFileName = shortenFileName($formattedFileName, 20);

                    // Neue Datei speichern
                    $newPath = $uploadsDir . uniqid() . "_" . $shortFileName;
                    if (move_uploaded_file($tmp_name, $newPath)) {
                        $_SESSION['uploaded_files'][] = [
                            'name' => $formattedFileName,
                            'path' => $newPath,
                            'type' => $fileType,
                            'pages' => ($fileType === "application/pdf") ? get_pdf_page_count($newPath) : 1
                        ];
                    }
                }
            }        

            // Falls eine neue Reihenfolge gespeichert wurde
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
                $newOrder = json_decode($_POST['order'], true);
                $sortedFiles = [];

                // Sortieren basierend auf Dateinamen
                foreach ($newOrder as $filename) {
                    foreach ($_SESSION['uploaded_files'] as $file) {
                        if ($file['name'] === $filename) {
                            $sortedFiles[] = $file;
                            break;
                        }
                    }
                }

                $_SESSION['uploaded_files'] = $sortedFiles;

                // Erfolgsnachricht zurückgeben
                echo json_encode(['status' => 'success']);
                exit;
            }

            // Falls eine Datei gelöscht werden soll
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
                $index = (int) $_POST['delete_file'];
                if (isset($_SESSION['uploaded_files'][$index])) {
                    array_splice($_SESSION['uploaded_files'], $index, 1);
                }
            }

            // HTML als echo ausgeben
            echo '<!DOCTYPE html>
            <head>
                <title>Dateien verwalten</title>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
                <link rel="stylesheet" href="styles.css"> <!-- Hier wird dein vorhandenes CSS eingebunden -->
            </head>
            <body>

                <h3 class="printer_h3">Hochgeladene Dateien</h3>

                <form method="POST">
                    <table class="printer_table">
                        <thead>
                            <tr><th>Reihenfolge</th><th>Dateiname</th><th>Seitenzahl</th><th>Aktion</th></tr>
                        </thead>
                        <tbody id="sortableTable">';

            // Dateien aus der Session ausgeben
            foreach ($_SESSION['uploaded_files'] as $index => $file) {
                echo '<tr data-index="' . $index . '">';
                echo '<td class="printer_drag-handle">☰</td>';
                echo '<td>' . htmlspecialchars($file['name']) . '</td>';
                echo '<td>' . htmlspecialchars($file['pages']) . '</td>';
                echo '<td><button type="submit" name="delete_file" value="' . $index . '" class="printer_delete-button">Löschen</button></td>';
                echo '</tr>';
            }

            echo '      </tbody>
                    </table>
                </form>

                <script>
                    document.addEventListener("DOMContentLoaded", function () {
                        new Sortable(document.getElementById("sortableTable"), {
                            handle: ".printer_drag-handle",
                            animation: 150,
                            onEnd: function () {
                                let sortedFilenames = [];
                                document.querySelectorAll("#sortableTable tr").forEach(row => {
                                    let filename = row.querySelector("td:nth-child(2)").textContent.trim();
                                    sortedFilenames.push(filename);
                                });

                                let formData = new FormData();
                                formData.append("order", JSON.stringify(sortedFilenames));

                                fetch("", { method: "POST", body: formData })
                                    .then(response => response.json())
                                    .then(data => console.log("Neuanordnung gespeichert:", data))
                                    .catch(error => console.error("Fehler:", error));
                            }
                        });
                    });
                </script>

            </body>
            </html>';            
        
            // Upload-Formular (kompletter Bereich ist klickbar)
            echo '<form method="POST" enctype="multipart/form-data" id="uploadForm" class="printer_upload_button">';
            echo '<input type="file" name="dokumente[]" multiple accept=".jpg,.jpeg,.png,.pdf" required class="printer_file-input" onchange="this.form.submit()">';
            echo '<span>Klicke hier oder ziehe eine Datei hinein</span>';
            echo '</form>';

            
            // Weiter-Button (nur wenn Dateien vorhanden sind)
            if (!empty($_SESSION['uploaded_files'])) {
                echo '<form method="POST">';
                echo '<button type="submit" name="next_step" value="true" class="printer_button">Weiter ➡</button>';
                echo '</form>';
            }
        
            echo "</div>"; // Container div schließen
            break;
            
            






            
            
        case 'druckoptionen':
            echo "<div class='printer_container'>";

            $output = '<div class="printer_back_container">';
            $output .= '<form method="POST" action="">';
            $output .= '<button type="submit" name="previous_step" class="printer_button">⬅ Zurück</button>';
            $output .= '</form>';
            $output .= '</div>';
            echo $output;        
        
            // Standardwerte (werden später dynamisch gesetzt)
            $A4empty = false; // A4 leer
            $A3empty = true; // A3 leer
            $drucker_id = $_SESSION['drucker_id'] ?? null;
            $A3available = ($drucker_id == 2 && !$A3empty);
            
            $defaultSelection = "A4"; // Standard A4
            if ($A4empty && $A3available) {
                $defaultSelection = "A3"; // Falls A4 leer ist und A3 verfügbar → Standard A3
            }
        
            echo '<form method="POST" class="printer_form">';
        
            // 1️⃣ Papierformat (Dropdown für alle Drucker)
            echo '<label for="papierformat" class="printer_h3">Papierformat:</label>';
            echo '<select name="papierformat" id="papierformat" class="printer_select">';

            // A4 (immer vorhanden)
            echo '<option value="A4" ' . ($A4empty ? 'disabled class="printer_option_disabled"' : '') . 
            ($defaultSelection == "A4" ? ' selected' : '') . '>A4' . ($A4empty ? ' (nicht verfügbar)' : '') . '</option>';

            // A3 nur anzeigen, aber deaktivieren für Drucker != 2
            if ($drucker_id == 2) {
                echo '<option value="A3" ' . ($A3empty ? 'disabled class="printer_option_disabled"' : '') . 
                    ($defaultSelection == "A3" ? ' selected' : '') . '>A3' . ($A3empty ? ' (nicht verfügbar)' : '') . '</option>';
            } else {
                echo '<option value="A3" class="printer_option_disabled" disabled>A3 (nicht verfügbar)</option>';
            }

            echo '</select>';

            $duplexInfo = "Beidseitiger Druck: Kostengünstiger als Simplex und spart Papier.";
            $simplexInfo = "Simplex-Druck: Jede Seite wird auf ein eigenes Blatt gedruckt.";         
        
            // 2️⃣ Simplex / Duplex Auswahl mit Erklärung
            echo '<label for="druckmodus" class="printer_h3">Druckmodus:</label>';
            echo '<select name="druckmodus" id="druckmodus" class="printer_select" onchange="updateDuplexInfo()">';
            echo '<option value="duplex">Duplex</option>';
            echo '<option value="simplex">Simplex</option>';
            echo '</select>';
            #echo '<p id="duplexInfo" class="printer_duplex_info">'.$duplexInfo.'</p>';
        
            // 3️⃣ Blattaufteilung (Dropdown)
            echo '<label for="seiten_pro_blatt" class="printer_h3">Seiten pro Blatt:</label>';
            echo '<select name="seiten_pro_blatt" id="seiten_pro_blatt" class="printer_select">';
            echo '<option value="1">Ganze Seite</option>';
            echo '<option value="2">2 Seiten pro Blatt</option>';
            echo '<option value="4">4 Seiten pro Blatt</option>';
            echo '</select>';

            // 4️⃣ Anzahl der Kopien (1 bis 500)
            echo '<label for="anzahl" class="printer_h3">Anzahl Kopien:</label>';
            echo '<input type="number" name="anzahl" id="anzahl" class="printer_input printer_input-small" min="1" max="500" value="1">';
        
            // 5️⃣ Graustufen-Option (Immer sichtbar, aber für Nicht-ID-2 ausgegraut & fixiert)
            echo '<label class="printer_h3">Schwarz-Weiß:</label>';
            if ($drucker_id == 2) {
                echo '<input type="checkbox" name="graustufen" class="printer_checkbox">';
            } else {
                echo '<input type="checkbox" name="graustufen" class="printer_checkbox" checked disabled style="cursor: not-allowed;">';
                echo '<input type="hidden" name="graustufen" value="true">';
            }
                        
            echo '<button type="submit" name="next_step" value="true" class="printer_button">Weiter ➡</button>';
        
            echo '</form>';
            echo "</div>";
        
            // JavaScript zur Aktualisierung der Duplex-Erklärung            
            echo '<script>
                function updateDuplexInfo() {
                    var mode = document.getElementById("druckmodus").value;
                    var infoText = mode === "duplex" 
                        ? "' . addslashes($duplexInfo) . '" 
                        : "' . addslashes($simplexInfo) . '";
                    document.getElementById("duplexInfo").innerText = infoText;
                }
            </script>';
            
            break;
            


            



        case 'vorschau':
            echo "<div class='printer_container'>";

            $output = '<div class="printer_back_container">';
            $output .= '<form method="POST" action="">';
            $output .= '<button type="submit" name="previous_step" class="printer_button">⬅ Zurück</button>';
            $output .= '</form>';
            $output .= '</div>';
            echo $output;        
        
            if (!isset($_SESSION['uploaded_files']) || empty($_SESSION['uploaded_files'])) {
                echo "<p class='printer_error-message'>⚠️ Keine Dateien zum Drucken hochgeladen!</p>";
                echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                echo "</div>";
                break;
            }  


            // Absoluter Pfad zu den Uploads
            $uploadsDir = "/WEH/PHP/printuploads/";

            // Druckoptionen auslesen
            $papierformat = $_POST['papierformat'] ?? 'A4';
            $druckmodus = $_POST['druckmodus'] ?? 'simplex';
            $seiten_pro_blatt = $_POST['seiten_pro_blatt'] ?? 1;
            $anzahl_kopien = $_POST['anzahl'] ?? 1;
            $graustufen = isset($_POST['graustufen']);

            // **Alle hochgeladenen Dateien durchgehen**
            $pdf_files = [];

            foreach ($_SESSION['uploaded_files'] as $file) {
                $filePath = $uploadsDir . basename($file['path']);
                $fileType = $file['type'];

                // PDF-Dateien direkt hinzufügen
                if ($fileType === 'application/pdf') {
                    $pdf_files[] = $filePath;
                } elseif (in_array($fileType, ['image/jpeg', 'image/png'])) {
                    // **Bild in PDF umwandeln mit Graustufen**
                    $pdfPath = $uploadsDir . uniqid() . "_grayscale.pdf";
                    
                    $convertCmd = "convert " . escapeshellarg($filePath) . " -resize 595x842 -gravity center -extent 595x842 -background white -density 300 -quality 100 ";
                    
                    $convertCmd .= escapeshellarg($pdfPath);
                    shell_exec($convertCmd);

                    if (file_exists($pdfPath)) {
                        chmod($pdfPath, 0644);
                        $pdf_files[] = $pdfPath;
                    }
                }
            }

            // **PDFs zusammenfügen**
            if (empty($pdf_files)) {
                echo "<p class='printer_error-message'>⚠️ Keine validen Dateien zum Drucken!</p>";
                echo "<p class='printer_error-message'>❗ Falls der Upload zu lange gedauert hat, wurden die Dateien möglicherweise automatisch gelöscht.</p>";
                echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                echo "</div>";
                exit;
            }

            // **PDFs mit pdftk zusammenführen**
            $merged_pdf_path = $uploadsDir . "merged_" . uniqid() . ".pdf";
            $cmd = "pdftk " . implode(" ", array_map(fn($file) => escapeshellarg($file), $pdf_files)) . 
                " cat output " . escapeshellarg($merged_pdf_path) . " 2>&1";
            shell_exec($cmd);

            // **Seiten pro Blatt mit pdfjam anpassen**
            $final_pdf_path = $merged_pdf_path;
            $nup = "2x1";

            if ($seiten_pro_blatt == 4 || $seiten_pro_blatt == 2) {

                // **Erster Durchlauf mit pdfjam**
                $pdfjamCmd = "pdfjam " . escapeshellarg($merged_pdf_path) . 
                            " --nup 2x1 --landscape --outfile " . escapeshellarg($final_pdf_path);
                exec($pdfjamCmd . " 2>&1", $output, $return_var);
            }

            if ($seiten_pro_blatt == 4) {
                // **PDF nach erstem pdfjam-Durchlauf um 90 Grad drehen**
                $rotated_pdf_path = $uploadsDir . "rotated_" . uniqid() . ".pdf";
                $rotateCmd = "pdftk " . escapeshellarg($final_pdf_path) . " cat 1-endleft output " . escapeshellarg($rotated_pdf_path);
                exec($rotateCmd . " 2>&1", $rotate_output, $rotate_return_var);

                // **Zweiter Durchlauf mit pdfjam**
                $pdfjamCmd = "pdfjam " . escapeshellarg($rotated_pdf_path) . 
                            " --nup 2x1 --landscape --outfile " . escapeshellarg($final_pdf_path);
                exec($pdfjamCmd . " 2>&1", $output, $return_var);

                // **Nach der Verarbeitung wieder senkrecht drehen**
                $final_rotated_pdf_path = $uploadsDir . "final_rotated_" . uniqid() . ".pdf";
                $finalRotateCmd = "pdftk " . escapeshellarg($final_pdf_path) . " cat 1-endright output " . escapeshellarg($final_rotated_pdf_path);
                exec($finalRotateCmd . " 2>&1", $final_rotate_output, $final_rotate_return_var);

                // **Das finale, wieder senkrechte PDF als endgültige Datei setzen**
                rename($final_rotated_pdf_path, $final_pdf_path);
            }


            $previewDir = $uploadsDir . "preview_" . uniqid() . "/"; // Verzeichnis für Vorschaubilder
            mkdir($previewDir, 0777, true); // Ordner erstellen

            // **Falls Duplex → 2 Seiten generieren, sonst nur 1**
            $pageCount = ($druckmodus === "duplex") ? 2 : 1;

            // **Maximale Seitenanzahl im PDF prüfen**
            $maxPages = min($pageCount, get_pdf_page_count($merged_pdf_path)); // Nicht mehr Seiten als vorhanden

            // **PDF in Bilder konvertieren (Seite 1 & 2 separat!)**
            for ($i = 0; $i < $maxPages; $i++) {
                $imagePath = sprintf($previewDir . "seite_%02d.jpg", $i);

                $convertCmd = "convert -density 300 " . escapeshellarg($merged_pdf_path) . 
                            "[$i] " . // **Konvertiere immer genau eine Seite**
                            "-background white -alpha remove -flatten " . 
                            "-colorspace sRGB ";

                // Falls Graustufen aktiviert sind
                if ($graustufen) {
                    $convertCmd .= "-colorspace Gray ";
                }

                $convertCmd .= "-quality 100 " . escapeshellarg($imagePath) . " 2>&1";
                shell_exec($convertCmd);
            }

            // **Erzeuge Dateipfade für die Bilder**
            $previewImages = glob($previewDir . "*.jpg");

            // **Falls keine Bilder erzeugt wurden, Fehler ausgeben**
            if (empty($previewImages)) {
                echo "<p class='printer_error-message'>⚠️ Fehler beim Erstellen der Vorschau!</p>";
                echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                echo "</div>";
                break;
            }

            // **Vorschau anzeigen**
            echo "<div class='printer_preview_container'>";

            // **Rückseite nur anzeigen, wenn sie existiert**
            if ($druckmodus === "duplex" && isset($previewImages[1])) {                    
                echo "<div class='printer_image_box'>";
                echo "<h3 class='printer_h3'>Vorderseite</h3>";
                echo "<img src='/" . str_replace("/WEH/PHP/", "", $previewImages[0]) . "' class='printer_preview_image'>";
                echo "</div>";

                echo "<div class='printer_image_box'>";
                echo "<h3 class='printer_h3'>Rückseite</h3>";
                echo "<img src='/" . str_replace("/WEH/PHP/", "", $previewImages[1]) . "' class='printer_preview_image'>";
                echo "</div>";
            } else {                    
                echo "<div class='printer_image_box'>";
                echo "<img src='/" . str_replace("/WEH/PHP/", "", $previewImages[0]) . "' class='printer_preview_image'>";
                echo "</div>";
            }

            echo "</div>";


            
            // **Seitenanzahl aus PDF holen**
            $seiten = get_pdf_page_count($merged_pdf_path);

            // Gesamtseiten berechnen            
            $gesamtseiten = $seiten * $anzahl_kopien;

            // Gesamtpreis berechnen
            $gesamtpreis = berechne_gesamtpreis($gesamtseiten, $druckmodus, $graustufen);

            // **Preis auf zwei Nachkommastellen runden**
            $gesamtpreis = number_format($gesamtpreis, 2, ',', '.');

            // **String für Seitenanzahl (Singular oder Plural) erstellen**
            $seiten_string = ($seiten > 1) ? "$seiten Seiten" : "1 Seite";

            // **String mit Modus & Seitenanzahl anzeigen**
            if ($anzahl_kopien > 1) {
                echo "<h3 class='printer_h3'>$anzahl_kopien x $seiten_string</h3>";
            } else {
                echo "<h3 class='printer_h3'>$seiten_string</h3>";
            }

            echo "<h2 class='printer_h2'>$gesamtpreis €</h2>";

        
            // Weiter- und Zurück-Buttons
            echo "<form method='POST'>";
            echo "<input type='hidden' name='papierformat' value='" . htmlspecialchars($papierformat, ENT_QUOTES, 'UTF-8') . "'>";
            echo "<input type='hidden' name='druckmodus' value='" . htmlspecialchars($druckmodus, ENT_QUOTES, 'UTF-8') . "'>";
            echo "<input type='hidden' name='anzahl' value='" . (int)$anzahl_kopien . "'>";
            echo "<input type='hidden' name='seiten' value='" . (int)$gesamtseiten . "'>";
            echo "<input type='hidden' name='graustufen' value='" . ($graustufen ? '1' : '0') . "'>";
            echo "<input type='hidden' name='merged_pdf_path' value='" . htmlspecialchars($merged_pdf_path, ENT_QUOTES, 'UTF-8') . "'>";
            echo "<input type='hidden' name='gesamtpreis' value='" . htmlspecialchars($gesamtpreis, ENT_QUOTES, 'UTF-8') . "'>";
            echo "<button type='submit' name='next_step' value='true' class='printer_button'>Druckauftrag senden ➡</button>";
            echo "</form>";
        
            echo "</div>";
            break;

            
        case 'drucken':
            echo "<div class='printer_container'>";

            $output = '<div class="printer_back_container">';
            $output .= '<form method="POST" action="">';
            $output .= '<button type="submit" name="previous_step" class="printer_button">⬅ Zurück</button>';
            $output .= '</form>';
            $output .= '</div>';
            echo $output;        

            $papierformat = $_POST['papierformat'] ?? 'A4';
            $druckmodus = $_POST['druckmodus'] ?? 'simplex';
            $anzahl_kopien = $_POST['anzahl'] ?? 1;
            $anzahl_seiten = $_POST['seiten'] ?? 1;
            $graustufen = isset($_POST['graustufen']) && $_POST['graustufen'] == '1';
            $merged_pdf_path = $_POST['merged_pdf_path'] ?? '';
            $gesamtpreis = $_POST['gesamtpreis'] ?? '';
            $druID = $_SESSION['drucker_id'] ?? null;
            foreach ($drucker as $d) {
                if ($d['id'] == $druID) {
                    $druName = $d['name']; // Modell als Druckername
                    $druIP = $d['ip']; // Drucker-IP
                    break;
                }
            }

            $uploadedFileNames = array_column($_SESSION['uploaded_files'], 'name');

            if (!empty($uploadedFileNames)) {
                $printJobTitle = implode(" + ", $uploadedFileNames);
            } else {
                echo "<p style='color:white; background-color:black;'>Keine Dateien hochgeladen.</p>";
            }

            echo "<p style='color:white; background-color:black;'><strong>Printjob Titel:</strong> $printJobTitle</p>";
            echo "<p style='color:white; background-color:black;'>Papierformat: $papierformat</p>";
            echo "<p style='color:white; background-color:black;'>Druckmodus: $druckmodus</p>";
            echo "<p style='color:white; background-color:black;'>Anzahl Kopien: $anzahl_kopien</p>";
            echo "<p style='color:white; background-color:black;'>Anzahl Seiten: $anzahl_seiten</p>";
            
            $gesamtseiten = $anzahl_kopien * $anzahl_seiten;
            echo "<p style='color:white; background-color:black;'>Gesamtseiten: $gesamtseiten</p>";
            echo "<p style='color:white; background-color:black;'>Graustufen: " . ($graustufen ? 'Ja' : 'Nein') . "</p>";
            echo "<p style='color:white; background-color:black;'>PDF-Pfad: $merged_pdf_path</p>";
            echo "<p style='color:white; background-color:black;'>Gesamtpreis: $gesamtpreis</p>";

            // Debugging: Überprüfung der Drucker-Variablen
            echo "<p style='color:white; background-color:red;'><strong>DEBUG:</strong> Druckername: $druName</p>";
            echo "<p style='color:white; background-color:red;'><strong>DEBUG:</strong> Drucker-IP: $druIP</p>";

            // CUPS Druckbefehl
            $print_command = "/usr/bin/lp -d $druName -n $anzahl_kopien -o media=$papierformat -o sides=" .
            ($druckmodus === 'duplex' ? 'two-sided-long-edge' : 'one-sided') . " -- " . escapeshellarg($merged_pdf_path);
        

            // Debugging: Ausgabe des Druckbefehls
            echo "<p style='color:white; background-color:blue;'><strong>DEBUG:</strong> Print Command: $print_command</p>";

            // Druckauftrag an CUPS senden
            exec($print_command . " 2>&1", $output, $return_var);

            // Debugging: CUPS Rückgabe überprüfen
            echo "<p style='color:white; background-color:red;'><strong>DEBUG:</strong> Return Code: $return_var</p>";
            echo "<p style='color:white; background-color:red;'><strong>DEBUG:</strong> Output: " . implode("<br>", $output) . "</p>";

            if ($return_var === 0) {
                $tries = 5;
                while ($tries-- > 0) {
                    exec("lpstat -o $druName 2>/dev/null", $job_output);
                    if (!empty($job_output)) {
                        $first_line = explode(" ", trim($job_output[0]));
                        $cups_id = isset($first_line[0]) ? intval(preg_replace('/[^0-9]/', '', $first_line[0])) : null;
                        if ($cups_id) break;
                    }
                    sleep(1); // Warte 1 Sekunde
                }                
            } else {
                echo "<p style='color:white; background-color:red;'>Druckauftrag fehlgeschlagen: " . implode("<br>", $output) . "</p>";
                exit;
            }


        
            echo '<form method="POST">';
            echo '<button type="submit" name="next_step" value="true" class="printer_button">Weiter ➡</button>';
            echo '</form>';



            $insert_sql = "INSERT INTO weh.printjobs 
            (uid, tstamp, status, title, planned_pages, duplex, grey, din, cups_id, drucker) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
            $insert_var = array(
                $_SESSION["uid"],                 // Benutzer-ID
                time(),                            // Timestamp (aktuelle Zeit)
                0,                                 // Status (default auf 0)
                $printJobTitle,                    // Printjob Titel (Dateinamen kombiniert)
                $gesamtseiten,                     // Gesamtseitenzahl (Anzahl Kopien * Seitenanzahl)
                ($druckmodus === "duplex") ? 1 : 0, // Duplex (1 = Ja, 0 = Nein)
                $graustufen ? 1 : 0,               // Graustufen (1 = Ja, 0 = Nein)
                $papierformat,                     // Papierformat (z. B. A4)
                $cups_id,                          // CUPS Job-ID
                $druName                           // Druckermodell speichern
            );
            
            // Prepared Statement ausführen
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iiisiiisis", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
            echo "<p>Printjob erfolgreich an '$druName' gesendet und in die DB eingetragen! (CUPS-ID: $cups_id)</p>";
        


                
            echo "</div>";
            break;
    }

    
} else {
  header("Location: denied.php");
}
$conn->close();
?>