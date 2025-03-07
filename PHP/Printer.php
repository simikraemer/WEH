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
        'drucker_waehlen' => ['next' => 'dokument_upload', 'previous' => null],
        'dokument_upload' => ['next' => 'druckoptionen', 'previous' => 'drucker_waehlen'],
        'druckoptionen' => ['next' => 'vorschau', 'previous' => 'dokument_upload'],
        'vorschau' => ['next' => null, 'previous' => 'druckoptionen']
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
    

            
    echo '<form method="POST" style="display:inline;">';
    echo '<button type="submit" name="previous_step" value="true" style="font-size: 20px;">⬅️ Zurück</button>';
    echo '</form>';
    
    // Inhalt basierend auf dem aktuellen Schritt rendern
    switch ($_SESSION['printer_step']) {
        case 'drucker_waehlen':            
            $drucker = [
                ["id" => 1, "turm" => "WEH", "farbe" => "Schwarz-Weiß", "modell" => "Kyocera ECOSYS P3260dn"],
                ["id" => 2, "turm" => "WEH", "farbe" => "Farbe", "modell" => "Kyocera ECOSYS M8124cidn"],
                ["id" => 3, "turm" => "TvK", "farbe" => "Schwarz-Weiß", "modell" => "Firma MODEL XXX"]
            ];
            
            $nutzerTurm = strtolower($_SESSION["turm"] ?? "");
            
            // Drucker nach Türmen sortieren
            $weh_drucker = array_filter($drucker, fn($d) => strtolower($d["turm"]) === "weh");
            $tvk_drucker = array_filter($drucker, fn($d) => strtolower($d["turm"]) === "tvk");

            // Überschriften für WEH und TvK in einer eigenen Zeile, aber in zwei Spalten
            echo '<div style="display: flex; width: 100vw; justify-content: center; align-items: center;">';
                echo '<div style="flex: 1; text-align: center;">';
                    echo '<h2 style="color: #11a50d; font-size: 100px;">WEH</h2>';
                echo '</div>';
                echo '<div style="flex: 1; text-align: center;">';
                    echo '<h2 style="color: #E49B0F; font-size: 100px;">TvK</h2>';
                echo '</div>';
            echo '</div>';

            // Haupt-Container für die Buttons
            echo '<div style="display: flex; width: 100vw; height: 30vh; justify-content: center; align-items: center;">';
            
            // Linke Spalte (WEH)
            echo '<div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">';
            foreach ($weh_drucker as $d) {
                $disabledClass = ($nutzerTurm !== strtolower($d["turm"])) ? "grayed-out" : "";
                $buttonStyle = "font-size: 30px; display: flex; flex-direction: column; padding: 10px;";
                if (!$disabledClass) $buttonStyle .= " justify-content: center; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s; cursor: pointer;";
                
                echo '<form method="POST" style="margin: 10px 0;">';
                echo '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
                echo '<input type="hidden" name="next_step" value="true">';
                echo '<button type="submit" class="' . $disabledClass . '" style="' . $buttonStyle . '"';
                if (!$disabledClass) echo ' onmouseover="this.style.backgroundColor=\'#11a50d\';" onmouseout="this.style.backgroundColor=\'#fff\';"';
                echo '>';
                echo '<strong>' . htmlspecialchars($d["farbe"]) . '</strong><br>';
                echo htmlspecialchars($d["modell"]);
                echo '</button>';
                echo '</form>';        
            }
            echo '</div>';
            
            // Vertikaler Trenner
            echo '<div style="width: 2px; height: 80%; background-color: white;"></div>';
            
            // Rechte Spalte (TvK)
            echo '<div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">';
            foreach ($tvk_drucker as $d) {
                $disabledClass = ($nutzerTurm !== strtolower($d["turm"])) ? "grayed-out" : "";
                $buttonStyle = "font-size: 30px; display: flex; flex-direction: column; padding: 10px;";
                if (!$disabledClass) $buttonStyle .= " justify-content: center; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s; cursor: pointer;";
                
                echo '<form method="POST" style="margin: 10px 0;">';
                echo '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
                echo '<input type="hidden" name="next_step" value="true">';
                echo '<button type="submit" class="' . $disabledClass . '" style="' . $buttonStyle . '"';
                if (!$disabledClass) echo ' onmouseover="this.style.backgroundColor=\'#11a50d\';" onmouseout="this.style.backgroundColor=\'#fff\';"';
                echo '>';
                echo '<strong>' . htmlspecialchars($d["farbe"]) . '</strong><br>';
                echo htmlspecialchars($d["modell"]);
                echo '</button>';
                echo '</form>';
                
            }
            echo '</div>';            
            echo '</div>';            
            echo '</div>';

            break;
        


            




        
        case 'dokument_upload':
            echo "<div class='printer_container'>";
        
            echo "<h2 class='printer_h2'>Dokument hochladen</h2>";
        
            // Falls keine Session für hochgeladene Dateien existiert, initialisieren
            if (!isset($_SESSION['uploaded_files'])) {
                $_SESSION['uploaded_files'] = [];
            }
        
            // Falls eine Datei gelöscht wird
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
                $index = (int)$_POST['delete_file'];
                if (isset($_SESSION['uploaded_files'][$index])) {
                    unlink($_SESSION['uploaded_files'][$index]['path']); // Löscht Datei vom Server
                    array_splice($_SESSION['uploaded_files'], $index, 1); // Entfernt aus Session
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

                    // Prüfe, ob das PDF verschlüsselt ist
                    if ($fileType === "application/pdf" && is_pdf_encrypted($tmp_name)) {
                        echo "<p class='printer_error-message'>Fehler: '$fileName' ist verschlüsselt und wird nicht akzeptiert!</p>";
                        continue;
                    }

                    // Dateinamen formatieren
                    $formattedFileName = sanitizeFileName($fileName);

                    // Neue Datei speichern
                    $newPath = $uploadsDir . uniqid() . "_" . $formattedFileName;
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
                foreach ($newOrder as $index) {
                    $sortedFiles[] = $_SESSION['uploaded_files'][$index];
                }
                $_SESSION['uploaded_files'] = $sortedFiles;
                exit;
            }
        
            // Tabelle mit hochgeladenen Dateien
            echo '<h3 class="printer_h3">Hochgeladene Dateien</h3>';
            echo '<form method="POST">';
            echo '<table class="printer_table">';
            echo '<thead>';
            echo '<tr><th>Reihenfolge</th><th>Dateiname</th><th>Seitenzahl</th><th>Aktion</th></tr></thead>';
            echo '<tbody id="sortableTable">';
        
            foreach ($_SESSION['uploaded_files'] as $index => $file) {
                echo "<tr data-index='$index'>";
                echo "<td class='printer_drag-handle'>☰</td>";
                echo "<td>" . htmlspecialchars($file['name']) . "</td>";
                echo "<td>" . htmlspecialchars($file['pages']) . "</td>";
                echo "<td><button type='submit' name='delete_file' value='$index' class='printer_delete-button'>Löschen</button></td>";
                echo "</tr>";
            }
        
            echo '</tbody></table>';
            echo '</form>';
        
            // Upload-Formular
            echo '<form method="POST" enctype="multipart/form-data">';
            echo '<input type="file" name="dokumente[]" multiple accept=".jpg,.jpeg,.png,.pdf" required class="printer_file-input">';
            echo '<button type="submit" class="printer_button">Hochladen</button>';
            echo '</form>';
        
            // Weiter-Button (nur wenn Dateien vorhanden sind)
            if (!empty($_SESSION['uploaded_files'])) {
                echo '<form method="POST">';
                echo '<button type="submit" name="next_step" value="true" class="printer_button">Weiter</button>';
                echo '</form>';
            }
            
            // JavaScript für Drag & Drop
            echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>';
            echo '<script>
                new Sortable(document.getElementById("sortableTable"), {
                    handle: ".printer_drag-handle",
                    animation: 150,
                    onEnd: function(evt) {
                        let sortedIndexes = [];
                        document.querySelectorAll("#sortableTable tr").forEach(row => {
                            sortedIndexes.push(row.getAttribute("data-index"));
                        });
        
                        let formData = new FormData();
                        formData.append("order", JSON.stringify(sortedIndexes));
        
                        fetch("", { method: "POST", body: formData });
                    }
                });
            </script>';
        
            echo "</div>"; // Container div schließen
            break;
            
            






            
            
        case 'druckoptionen':
            echo "<div class='printer_container'>";
            echo "<h2 class='printer_h2'>Druckoptionen</h2>";
        
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

            $duplexInfo = "Jede Zweite";
            $simplexInfo = "Jede Seite wird auf ein eigenes Blatt gedruckt.";
        
            // 2️⃣ Simplex / Duplex Auswahl mit Erklärung
            echo '<label for="druckmodus" class="printer_h3">Druckmodus:</label>';
            echo '<select name="druckmodus" id="druckmodus" class="printer_select" onchange="updateDuplexInfo()">';
            echo '<option value="duplex">Duplex</option>';
            echo '<option value="simplex">Simplex</option>';
            echo '</select>';
            echo '<p id="duplexInfo" class="printer_duplex_info">'.$duplexInfo.'</p>';
        
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
                        
            echo '<button type="submit" name="next_step" value="true" class="printer_button">Weiter</button>';
        
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
                echo "<h2 class='printer_h2'>Druckvorschau</h2>";
            
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
            
                    if ($fileType === 'application/pdf') {
                        // **PDF bleibt unverändert**
                        if (file_exists($filePath)) {
                            $pdf_files[] = $filePath;
                        }
                    } elseif (in_array($fileType, ['image/jpeg', 'image/png'])) {
                        // **Bild in PDF umwandeln**
                        $pdfPath = $uploadsDir . uniqid() . ".pdf";
            
                        // **Bild wird auf A4 ausgerichtet**
                        $convertCmd = "convert " . escapeshellarg($filePath) . 
                                      " -resize 595x842 -gravity center -extent 595x842 -background white -density 300 -quality 100 " . 
                                      escapeshellarg($pdfPath) . " 2>&1";
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
                    echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                    echo "</div>";
                    break;
                }
            
                // **Kommando für pdftk mit absolutem Pfad**
                $merged_pdf_path = $uploadsDir . "merged_" . uniqid() . ".pdf";
                $cmd = "pdftk " . implode(" ", array_map(fn($file) => escapeshellarg($file), $pdf_files)) . 
                       " cat output " . escapeshellarg($merged_pdf_path) . " 2>&1";
                shell_exec($cmd);
            
                // **Überprüfen, ob das PDF tatsächlich erstellt wurde**
                if (!file_exists($merged_pdf_path)) {
                    echo "<p class='printer_error-message'>⚠️ Fehler beim Erstellen des PDFs!</p>";
                    echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                    echo "</div>";
                    break;
                }
            
                // **Seitenanzahl berechnen**
                $gesamtseiten = get_pdf_page_count($merged_pdf_path);
            
                // **PDF-Vorschau: Nur die ersten 1-2 Seiten als Bild generieren**
                $previewDir = $uploadsDir . "preview_" . uniqid() . "/"; // Verzeichnis für Vorschaubilder
                mkdir($previewDir, 0777, true); // Ordner erstellen
            
                // **Falls Duplex → 2 Seiten generieren, sonst nur 1**
                $pageCount = ($druckmodus === "duplex") ? 2 : 1;
            
                // **PDF in Bilder konvertieren (nur Seiten 1 & 2)**
                $imagePattern = $previewDir . "seite_%02d.jpg";
                $convertCmd = "convert -density 300 " . escapeshellarg($merged_pdf_path) . 
                              "[0-" . ($pageCount - 1) . "] -quality 100 -resize 1200x " . escapeshellarg($imagePattern) . " 2>&1";
                shell_exec($convertCmd);
            
                // **Erzeuge Dateipfade für Seite 1 & 2**
                $previewImages = glob($previewDir . "*.jpg");
            
                // Falls Simplex & keine zweite Seite → Füge leere weiße Seite als Rückseite hinzu
                if ($druckmodus === "simplex" && count($previewImages) === 1) {
                    $blankPage = $previewDir . "seite_02.jpg";
                    $createWhitePage = "convert -size 800x1120 xc:white " . escapeshellarg($blankPage);
                    shell_exec($createWhitePage);
                    $previewImages[] = $blankPage;
                }
            
                // **Falls keine Bilder erzeugt wurden, Fehler ausgeben**
                if (empty($previewImages)) {
                    echo "<p class='printer_error-message'>⚠️ Fehler beim Erstellen der Vorschau!</p>";
                    echo "<button onclick='window.history.back()' class='printer_button'>Zurück</button>";
                    echo "</div>";
                    break;
                }
            
                // **Vorschau anzeigen**
                echo "<div class='printer_preview_container'>";

                // **Vorderseite**
                echo "<div class='printer_image_box'>";
                echo "<h3 class='printer_h3'>Vorderseite</h3>";
                echo "<img src='/" . str_replace("/WEH/PHP/", "", $previewImages[0]) . "' class='printer_preview_image'>";
                echo "</div>";
                
                // **Rückseite**
                echo "<div class='printer_image_box'>";
                echo "<h3 class='printer_h3'>Rückseite</h3>";
                echo "<img src='/" . str_replace("/WEH/PHP/", "", $previewImages[1]) . "' class='printer_preview_image'>";
                echo "</div>";
                
                echo "</div>";
                
            
                echo "<h3 class='printer_h3'>Gesamtseiten: $gesamtseiten</h3>";
                echo "<h3 class='printer_h3'>Preis: 3,00€</h3>";
            
                // Weiter- und Zurück-Buttons
                echo "<form method='POST'>";
                echo "<button type='submit' name='next_step' value='true' class='printer_button'>Druckauftrag senden</button>";
                echo "</form>";
            
                echo "</div>";
                break;
            
            
                










    }

    
} else {
  header("Location: denied.php");
}
$conn->close();
?>