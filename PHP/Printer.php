<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    
    <script>
    function handleFileUpload() {
        document.getElementById("loader").style.display = "block";
        // Kurze Verzögerung, damit der Loader sichtbar wird
        setTimeout(function () {
            document.getElementById("uploadForm").submit();
        }, 100);
    }
    </script>
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();

    $step = isset($_POST['step']) ? $_POST['step'] : 'drucker_waehlen';
    
    $drucker = [
        ["id" => 1, "turm" => "WEH", "farbe" => "Schwarz-Weiß", "modell" => "WEH Schwarz-Weiß", "ip" => "137.226.141.5", "name" => "WEHsw"],
        ["id" => 2, "turm" => "WEH", "farbe" => "Farbe", "modell" => "WEH Farbe", "ip" => "137.226.141.193", "name" => "WEHfarbe"],
        ["id" => 3, "turm" => "TvK", "farbe" => "Schwarz-Weiß", "modell" => "TvK Schwarz-Weiß", "ip" => "todo", "name" => "TvKsw"]
    ];

    if ($step == 'drucker_waehlen') {
       
        $nutzerTurm = strtolower($_SESSION["turm"] ?? "");
        $community = "public";

        // Drucker nach Türmen sortieren
        $turmGruppen = ["WEH" => [], "TvK" => []];
        foreach ($drucker as $d) {
            $turmGruppen[$d["turm"]][] = $d;
        }

        // Eigener Turm kommt zuerst
        $turmReihenfolge = ($nutzerTurm === "weh") ? ["WEH", "TvK"] : ["TvK", "WEH"];

        $output = '<div class="printer_container">';

        foreach ($turmReihenfolge as $turm) {
            if (empty($turmGruppen[$turm])) continue;

            // Zeile für den Turm    
            $output .= '<div class="printer_turm_row">';      
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

                $status_message = "⚠ Wartung erforderlich<br>$snmp_error_message";
                
                switch ($printer_status) {
                    case 2:
                    case 3:
                        $status_message = "✅ Drucker ist bereit";
                        $printer_ready = true;
                        break;
                    case 4:
                        $status_message = "⏳ Drucker druckt...";
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
                        $status_message = "🚪 Klappe offen!";
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

                    # Vor DIN A3 add
                    $papier_A4_aktuell = get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.2") + 
                                        get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.3") + 
                                        get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.4");
                    $papier_A4_max = 1500;
                    $papier_A3_aktuell = "-";
                    $papier_A3_max = "-";

                    ## NACH DIN A3 add
                    #$papier_A4_aktuell = get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.2") + 
                    #                    get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.3");
                    #$papier_A4_max = 1000; // 2 Kassetten mit je 500 Blättern
                    #$papier_A3_aktuell = get_snmp_value($ip, "1.3.6.1.2.1.43.8.2.1.10.1.4");
                    #$papier_A3_max = 500; // 1 Kassette mit 500 Blättern
                }

                // Button-Formular
                $output .= '<form method="POST" class="printer_form">';
                $output .= '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
                $output .= '<input type="hidden" name="step" value="dokument_upload">';

                $druckerTurm = strtolower($d["turm"]);
                #$istTurmdesUsers = ($druckerTurm === $nutzerTurm || $_SESSION["uid"] == 2626);
                // TEMPORÄR ZUGRIFF FÜR TVK AKTIVIERT, SOLANGE TVK KEINE DRUCKER AKTIV HAT
                $istTurmdesUsers = true;
                $style = ($istTurmdesUsers && $printer_ready) ? "" : "opacity: 0.5; pointer-events: none;";
                
                $output .= '<button type="submit" class="printer_button printer_flex_button" style="' . $style . '">';
                $output .= '<div class="printer_content">';
                $output .= '<strong>' . htmlspecialchars($d["modell"]) . '</strong><br>';

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

    } elseif ($step == 'dokument_upload') {
        
        echo "<div class='printer_container'>";

        if (!empty($_POST['drucker_id'])) {
            $_SESSION["drucker_id"] = $_POST['drucker_id'];
        }
        $druID = $_SESSION["drucker_id"] ?? null;
        $aktueller_drucker = "Kein Drucker gewählt"; // Default-Text
        foreach ($drucker as $d) {
            if ($d['id'] == $druID) {
                $aktueller_drucker = "Drucker: " . $d["modell"];
                break;
            }
        }        
        $output = '<div class="printer_back_container">';
        $output .= '<div class="printer_header">';
        $output .= '<form method="POST" action="">';
        $output .= '<button type="submit" name="step" value="drucker_waehlen" class="printer_button">⬅ Zurück</button>';
        $output .= '</form>';
        $output .= '<div class="printer_button printer_name">' . ($aktueller_drucker ? htmlspecialchars($aktueller_drucker) : 'Kein Drucker gewählt') . '</div>';
        $output .= '</div>';
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
            echo "<!-- DEBUG: Upload-Handler aktiviert -->";

            $uploadsDir = "printuploads/";
            $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];

            foreach ($_FILES['dokumente']['tmp_name'] as $key => $tmp_name) {
                $fileName = $_FILES['dokumente']['name'][$key];
                $fileType = mime_content_type($tmp_name);

                echo "<!-- DEBUG: Datei erkannt: $fileName ($fileType) -->";

                if (!in_array($fileType, $allowedTypes)) {
                    echo "<p class='printer_error-message'>Fehler: '$fileName' hat ein ungültiges Format!</p>";
                    continue;
                }

                if ($fileType === "application/pdf") {
                    echo "<!-- DEBUG: PDF-Verarbeitung gestartet für $fileName -->";

                    if (is_pdf_encrypted($tmp_name)) {
                        echo "<p class='printer_error-message'>Fehler: '$fileName' ist verschlüsselt und wird nicht akzeptiert!</p>";
                        continue;
                    }

                    if (!is_valid_pdf($tmp_name)) {
                        echo "<p class='printer_error-message'>Fehler: '$fileName' ist beschädigt oder falsch formatiert!</p>";
                        continue;
                    }

                    foreach ($drucker as $d) {
                        if ($d['id'] == $druID) {
                            $druName = $d['name'];
                            $druIP = $d['ip'];
                            break;
                        }
                    }

                    // 1. Prüfen ob PDF potenziell druckbar ist
                    $infoCmd = "pdfinfo " . escapeshellarg($tmp_name) . " 2>&1";
                    $pdfInfo = shell_exec($infoCmd);
                    echo "<!-- DEBUG: pdfinfo-Ausgabe:\n" . htmlentities($pdfInfo) . " -->";

                    // Seitenanzahl prüfen
                    $pageCount = get_pdf_page_count($tmp_name);
                    $maxPages = 500;

                    if ($pageCount === false) {
                        echo "<p class='printer_error-message'>Fehler: Die Seitenanzahl von '$fileName' konnte nicht ermittelt werden - ungültiges oder beschädigtes PDF?</p>";
                        continue;
                    }

                    if ($pageCount > $maxPages) {
                        echo "<p class='printer_error-message'>Fehler: '$fileName' hat zu viele Seiten ($pageCount) - maximal erlaubt: $maxPages.</p>";
                        continue;
                    }

                    echo "<!-- DEBUG: PDF hat $pageCount Seiten und gilt als druckbar -->";

                    
                    // 2. Prüfen ob Format A4 ist → sonst konvertieren!
                    if (preg_match('/Page size:\s+([\d.]+)\s+x\s+([\d.]+)/', $pdfInfo, $matches)) {
                        $width = floatval($matches[1]);
                        $height = floatval($matches[2]);
                        $isA4 = abs($width - 595) < 5 && abs($height - 842) < 5;

                        if (!$isA4) {
                            $a4FixedPath = $uploadsDir . uniqid("a4_") . ".pdf";

                            $convertToA4Cmd = "gs -sDEVICE=pdfwrite -dPDFFitPage -dCompatibilityLevel=1.4 " .
                                "-dNOPAUSE -dQUIET -dBATCH -sPAPERSIZE=a4 " .
                                "-sOutputFile=" . escapeshellarg($a4FixedPath) . " " . escapeshellarg($tmp_name);

                            shell_exec($convertToA4Cmd);
                            echo "<!-- DEBUG: PDF war nicht A4 - wurde konvertiert nach $a4FixedPath -->";

                            // tmp_name durch neues File ersetzen
                            $tmp_name = $a4FixedPath;
                        } else {
                            echo "<!-- DEBUG: PDF ist A4 - keine Konvertierung nötig -->";
                        }
                    } else {
                        echo "<!-- WARNUNG: Page size konnte nicht erkannt werden - keine Formatprüfung durchgeführt -->";
                    }
                }

                // Dateinamen formatieren
                $formattedFileName = sanitizeFileName($fileName);
                $shortFileName = shortenFileName($formattedFileName, 20);
                $newPath = $uploadsDir . uniqid() . "_" . $shortFileName;

                if (is_uploaded_file($tmp_name)) {
                    $moveSuccess = move_uploaded_file($tmp_name, $newPath);
                    echo "<!-- DEBUG: move_uploaded_file verwendet -->";
                } else {
                    $moveSuccess = rename($tmp_name, $newPath);
                    echo "<!-- DEBUG: rename verwendet -->";
                }

                if ($moveSuccess) {
                    $_SESSION['uploaded_files'][] = [
                        'name' => $formattedFileName,
                        'path' => $newPath,
                        'type' => $fileType,
                        'pages' => ($fileType === "application/pdf") ? get_pdf_page_count($newPath) : 1
                    ];
                    echo "<!-- DEBUG: Datei erfolgreich gespeichert unter $newPath -->";
                } else {
                    echo "<p class='printer_error-message'>Fehler beim Speichern der Datei '$fileName'.</p>";
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
            echo '<input type="hidden" name="step" value="dokument_upload">';
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
                            formData.append("step", "dokument_upload");  // HIER EINFÜGEN

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
        echo '<form method="POST" enctype="multipart/form-data" id="uploadForm" class="printer_upload_button" onsubmit="showLoader()">';
        echo '<input type="hidden" name="step" value="dokument_upload">';
        echo '<input type="file" name="dokumente[]" multiple accept=".jpg,.jpeg,.png,.pdf" required class="printer_file-input" onchange="handleFileUpload()">';
        echo '<span>Klicke hier oder ziehe eine Datei hinein</span>';
        echo '</form>';

        // Lade-Overlay
        echo '
        <div id="loader" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(255,255,255,0.8); z-index:1000; text-align:center; padding-top:20%;">
            <div style="font-size:1.5em;">Dateien werden hochgeladen...</div>
            <div class=\"lds-dual-ring\" style=\"margin-top:20px;\"></div>
        </div>';


        
        // Weiter-Button (nur wenn Dateien vorhanden sind)
        if (!empty($_SESSION['uploaded_files'])) {
            echo '<form method="POST">';
            echo '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($druID) . '">';
            echo '<button type="submit" name="step" value="druckoptionen" class="printer_button">Weiter ➡</button>';
            echo '</form>';
        }        
    
        echo "</div>"; // Container div schließen

    } elseif ($step == 'druckoptionen') {
    
        echo "<div class='printer_container'>";

        $druID = $_SESSION['drucker_id'] ?? null;
        $aktueller_drucker = "Kein Drucker gewählt"; // Default-Text
        foreach ($drucker as $d) {
            if ($d['id'] == $druID) {
                $aktueller_drucker = "Drucker: " . $d["modell"];
                break;
            }
        }        
        $output = '<div class="printer_back_container">';
        $output .= '<div class="printer_header">';
        $output .= '<form method="POST" action="">';
        $output .= '<button type="submit" name="step" value="dokument_upload" class="printer_button">⬅ Zurück</button>';
        $output .= '</form>';
        $output .= '<div class="printer_button printer_name">' . ($aktueller_drucker ? htmlspecialchars($aktueller_drucker) : 'Kein Drucker gewählt') . '</div>';
        $output .= '</div>';
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
        echo '<input type="hidden" name="papierformat" value="A4">';
        
        // // A3 noch nicht implementiert
        // echo '<label for="papierformat" class="printer_h3">Papierformat:</label>';
        // echo '<select name="papierformat" id="papierformat" class="printer_select">';

        // // A4 (immer vorhanden)
        // echo '<option value="A4" ' . ($A4empty ? 'disabled class="printer_option_disabled"' : '') . 
        // ($defaultSelection == "A4" ? ' selected' : '') . '>A4' . ($A4empty ? ' (nicht verfügbar)' : '') . '</option>';

        // // A3 nur anzeigen, aber deaktivieren für Drucker != 2
        // if ($drucker_id == 2) {
        //     echo '<option value="A3" ' . ($A3empty ? 'disabled class="printer_option_disabled"' : '') . 
        //         ($defaultSelection == "A3" ? ' selected' : '') . '>A3' . ($A3empty ? ' (nicht verfügbar)' : '') . '</option>';
        // } else {
        //     echo '<option value="A3" class="printer_option_disabled" disabled>A3 (nicht verfügbar)</option>';
        // }

        // echo '</select>';


        $duplexInfo = "Beidseitiger Druck: Kostengünstiger als Simplex und spart Papier.";
        $simplexInfo = "Simplex-Druck: Jede Seite wird auf ein eigenes Blatt gedruckt.";         
    
        // 2️⃣ Simplex / Duplex Auswahl mit Erklärung
        echo '<label for="druckmodus" class="printer_h3">Druckmodus:</label>';
        echo '<select name="druckmodus" id="druckmodus" class="printer_select"">';
        echo '<option value="duplex">Duplex</option>';
        echo '<option value="simplex">Simplex</option>';
        echo '</select>';
    
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
                    
        echo '<button type="submit" name="step" value="vorschau" class="printer_button" style="margin-bottom: 50px;">Weiter ➡</button>';
    
        echo '</form>';
        echo "</div>";

    } elseif ($step == 'vorschau') {

        echo "<div class='printer_container'>";


        $druID = $_SESSION['drucker_id'] ?? null;
        $aktueller_drucker = "Kein Drucker gewählt"; // Default-Text
        foreach ($drucker as $d) {
            if ($d['id'] == $druID) {
                $aktueller_drucker = "Drucker: " . $d["modell"];
                break;
            }
        }        
        $output = '<div class="printer_back_container">';
        $output .= '<div class="printer_header">';
        $output .= '<form method="POST" action="">';
        $output .= '<button type="submit" name="step" value="druckoptionen" class="printer_button">⬅ Zurück</button>';
        $output .= '</form>';
        $output .= '<div class="printer_button printer_name">' . ($aktueller_drucker ? htmlspecialchars($aktueller_drucker) : 'Kein Drucker gewählt') . '</div>';
        $output .= '</div>';
        $output .= '</div>';        
        echo $output;                 
        
        echo "<h2 class='printer_h2'>Vorschau 1. Blatt</h2>";
    
        if (!isset($_SESSION['uploaded_files']) || empty($_SESSION['uploaded_files'])) {
            echo "<p class='printer_error-message'>⚠️ Keine Dateien zum Drucken hochgeladen!</p>";
            echo "</div>";
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
                $tmpPdf = $uploadsDir . uniqid("tmp_") . ".pdf";
                $finalPdf = $uploadsDir . uniqid("a4_") . ".pdf";
            
                // Schritt 1: Bild zu einfachem PDF
                $convertCmd = "convert " . escapeshellarg($filePath) . " " . escapeshellarg($tmpPdf);
                shell_exec($convertCmd);
            
                // Schritt 2: Mit Ghostscript auf A4 zwingen
                $gsCmd = "gs -sDEVICE=pdfwrite -dPDFFitPage -dCompatibilityLevel=1.4 " .
                         "-dNOPAUSE -dQUIET -dBATCH -sPAPERSIZE=a4 " .
                         "-sOutputFile=" . escapeshellarg($finalPdf) . " " . escapeshellarg($tmpPdf);
                shell_exec($gsCmd);
            
                if (file_exists($finalPdf)) {
                    chmod($finalPdf, 0644);
                    $pdf_files[] = $finalPdf;
                    unlink($tmpPdf);
                } else {
                    echo "<!-- FEHLER: PDF-Konvertierung fehlgeschlagen für $filePath -->";
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

        $isVorstand = ((isset($_SESSION["Vorstand"]) && $_SESSION["Vorstand"]) || (isset($_SESSION["TvK-Sprecher"]) && $_SESSION["TvK-Sprecher"])) === true;
        $isNetzAG = isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true;
        
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
        
        $blätter = berechneBlaetterausSeiten($gesamtseiten, $druckmodus);
        $printjobcheck = checkifprintjobispossible($conn, $_SESSION["uid"], $_SESSION['drucker_id'], $drucker, $blätter, $gesamtpreis);
        $possible = False;

        echo "<h2 class='printer_h2'>$gesamtpreis €</h2>";

        if ($printjobcheck === 1) {
            $possible = True;
        } elseif ($printjobcheck === 2) {
            echo "<h3 class='printer_h3'>❌ Nicht genug Geld auf dem Konto!</h3>";
        } elseif ($printjobcheck === 3) {
            echo "<h3 class='printer_h3'>❌ Nicht genug Papier im Drucker!</h3>";
        }
    
        echo "<form method='POST' class='printer_form'>";

        echo "<div class='printer_option_container'>";
        if ($isVorstand || $isNetzAG) {            
            if ($isVorstand) {
                echo '<label class="printer_label">
                        <input type="radio" name="dummyuser" value="492" class="printer_radio">
                        <span class="printer_h1">Abrechnen über Vorstand</span>
                    </label>';
            }
            if ($isNetzAG) {
                echo '<label class="printer_label">
                        <input type="radio" name="dummyuser" value="472" class="printer_radio">
                        <span class="printer_h1">Abrechnen über NetzAG</span>
                    </label>';
            }
            echo '<label class="printer_label">
                    <input type="radio" name="dummyuser" value="'.$_SESSION["uid"].'" class="printer_radio" checked>
                    <span class="printer_h1">Abrechnen über deinen User</span>
                </label>';
        }
        echo "</div>"; // Ende printer_option_container

        echo "<input type='hidden' name='papierformat' value='" . htmlspecialchars($papierformat, ENT_QUOTES, 'UTF-8') . "'>";
        echo "<input type='hidden' name='druckmodus' value='" . htmlspecialchars($druckmodus, ENT_QUOTES, 'UTF-8') . "'>";
        echo "<input type='hidden' name='anzahl' value='" . (int)$anzahl_kopien . "'>";
        echo "<input type='hidden' name='seiten' value='" . (int)$seiten . "'>";
        echo "<input type='hidden' name='graustufen' value='" . ($graustufen ? '1' : '0') . "'>";
        echo "<input type='hidden' name='merged_pdf_path' value='" . htmlspecialchars($merged_pdf_path, ENT_QUOTES, 'UTF-8') . "'>";
        echo "<input type='hidden' name='gesamtpreis' value='" . htmlspecialchars($gesamtpreis, ENT_QUOTES, 'UTF-8') . "'>";
        
        // Button deaktivieren oder entfernen
        if ($possible) {
            echo "<button type='submit' name='step' value='drucken' class='printer_button' style='margin-bottom: 50px;'>Druckauftrag senden ➡</button>";
        }
        
        echo "</form>";
    
        echo "</div>";
    
    } elseif ($step == 'drucken') {
    
        // Hauptcontainer für Inhalte
        echo "<div class='printer_container'>";
        echo "<div class='printer_body'>"; // Gesamtes Druck-Frontend in 'printer_body' für zentrierte Ansicht
    
        // Druckdetails aus dem Formular holen
        $papierformat = $_POST['papierformat'] ?? 'A4';
        $druckmodus = $_POST['druckmodus'] ?? 'simplex';
        $anzahl_kopien = $_POST['anzahl'] ?? 1;
        $anzahl_seiten = $_POST['seiten'] ?? 1;
        $gesamtseiten = $anzahl_seiten * $anzahl_kopien;
        $graustufen = isset($_POST['graustufen']) && $_POST['graustufen'] == '1';
        $merged_pdf_path = $_POST['merged_pdf_path'] ?? '';
        $gesamtpreis = $_POST['gesamtpreis'] ?? '';
        $druID = $_SESSION['drucker_id'] ?? null;
        $printjob_uid = $_POST['dummyuser'] ?? $_SESSION['uid'];
    
        foreach ($drucker as $d) {
            if ($d['id'] == $druID) {
                $druName = $d['name'];
                $druIP = $d['ip'];
                break;
            }
        }
        
        $blätter = berechneBlaetterausSeiten($gesamtseiten, $druckmodus);
        $printjobcheck = checkifprintjobispossible($conn, $_SESSION["uid"], $_SESSION['drucker_id'], $drucker, $blätter, $gesamtpreis);
        $possible = False;
        $statusMessage = "";

        echo "<h2 class='printer_h2'>$gesamtpreis €</h2>";

        if ($printjobcheck === 1) {
            $possible = true;
        } elseif ($printjobcheck === 2) {
            $statusMessage = "<h3 class='printer_h3'>❌ Nicht genug Geld auf dem Konto!</h3>";
        } elseif ($printjobcheck === 3) {
            $statusMessage = "<h3 class='printer_h3'>❌ Nicht genug Papier im Drucker!</h3>";
        }

        if ($possible) {    
            $uploadedFileNames = array_column($_SESSION['uploaded_files'], 'name');
            $printJobTitle = !empty($uploadedFileNames) ? implode("__", $uploadedFileNames) : "Unbenannter Druckauftrag";
            $printjobUser = "UID" . $printjob_uid;
            
            // CUPS Druckbefehl aufbauen
            $print_command = "/usr/bin/lp -d " . escapeshellarg($druName) .
                " -n $anzahl_kopien -o media=$papierformat -o sides=" .
                ($druckmodus === 'duplex' ? 'two-sided-long-edge' : 'one-sided') .
                " -U " . escapeshellarg($printjobUser) .
                " -t " . escapeshellarg($printJobTitle) .
                " -- " . escapeshellarg($merged_pdf_path);
            
            // Druckauftrag an CUPS senden
            exec($print_command . " 2>&1", $output, $return_var);
            
            // cups_id initialisieren
            $cups_id = null;
            
            // Wenn erfolgreich → Job-ID aus der Ausgabe holen
            if ($return_var === 0 && !empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/request id is .*?-(\d+)/i', $line, $matches)) {
                        $cups_id = intval($matches[1]);
                        break;
                    }
                }
            }
            

            $insert_sql = "INSERT INTO weh.printjobs 
            (uid, tstamp, status, title, planned_pages, duplex, grey, din, cups_id, drucker, real_uid) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
            $insert_var = array(
                $printjob_uid,                   
                time(),                             
                0,                                  
                $printJobTitle,                     
                $gesamtseiten,                      
                ($druckmodus === "duplex") ? 1 : 0,
                $graustufen ? 1 : 0,               
                $papierformat,                     
                $cups_id,                          
                $druName,
                $_SESSION["uid"]       
            );
            
            // Prepared Statement ausführen
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iiisiiisisi", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            unset($_SESSION['drucker_id'], $_SESSION['uploaded_files']);
        }
    
        // Alles am Ende, übersichtlich zusammen
        if ($possible && $return_var === 0) {
            echo "<h3>✅ Ihr Druckauftrag wurde erfolgreich angenommen!</h3>";
            echo "<p>Ihr Dokument wird nun gedruckt.</p>";
        } else {
            // Falls vorher schon eine Message gesetzt wurde (Papier/Geld)
            if (!empty($statusMessage)) {
                echo $statusMessage;
            } else {
                echo "<h3 style='color: red;'>❌ Fehler beim Drucken!</h3>";
                echo "<p>Leider konnte der Druckauftrag nicht gesendet werden.</p>";
            }

            // Optional: Debug-Ausgabe
            // echo "<pre style='background:#fdd; padding:1em; border:1px solid #f99;'>
            // <b>🔧 Druckbefehl:</b>\n" . htmlentities($print_command) . "
            // <b>🔧 Rückgabecode:</b> $return_var
            // <b>🔧 CUPS-Ausgabe:</b>\n" . htmlentities(implode("\n", $output)) . "</pre>";
        }
    
        
        // Ladeanimation
        echo "<div class='printer_loading'>
            <p>Sie werden weitergeleitet...</p>
            <div class='printer_spinner'></div>
        </div>";

        // JavaScript für den automatischen Weiterleitungs-POST nach 2 Sekunden
        echo "<script>
            setTimeout(function() {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href; // Auf gleiche Seite zurück
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'step';
                input.value = 'drucker_waehlen';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }, 3000);
        </script>";

        echo "</div>"; // Schließt printer_container
        echo "</div>"; // Schließt printer_body
            
        echo "</div>";

    }
    
    
} else {
  header("Location: denied.php");
}
$conn->close();
?>
