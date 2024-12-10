<?php
session_start();

// Zugriffsbeschränkungen basierend auf Rollen
if (!(
    (isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true) ||
    (isset($_SESSION["Vorstand"]) && $_SESSION["Vorstand"] === true) ||
    (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)
)) {
    http_response_code(403);
    die("Zugriff verweigert.");
}

// Datei aus der URL-Query extrahieren
$file = $_GET['file'];
$filePath = realpath(__DIR__ . "/" . $file);

// Sicherstellen, dass die Datei existiert und innerhalb des erlaubten Verzeichnisses liegt
if (!$filePath || strpos($filePath, realpath(__DIR__)) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    die("Datei nicht gefunden.");
}

// Datei sicher ausliefern
header('Content-Type: ' . mime_content_type($filePath));
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
