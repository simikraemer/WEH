<?php
// Dateiname aus der GET-Anfrage erhalten
$pdfFileName = $_GET['filename'];

// Pfad zur Datei
$filePath = "userkontopdfs/$pdfFileName";

// Prüfen, ob die Datei existiert und lesbar ist
if (file_exists($filePath) && is_readable($filePath)) {
    // Datei herunterladen
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);

    // Datei nach dem Herunterladen löschen
    unlink($filePath);
} else {
    // Datei nicht gefunden oder nicht lesbar
    echo "Die angeforderte Datei konnte nicht gefunden werden.";
}
?>
