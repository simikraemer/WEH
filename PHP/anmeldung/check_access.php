<?php
session_start();

if (!(
    (isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true) ||
    (isset($_SESSION["Vorstand"]) && $_SESSION["Vorstand"] === true) ||
    (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)
)) {
    http_response_code(403);
    die("Zugriff verweigert.");
}

if (!isset($_GET['file']) || $_GET['file'] === '') {
    http_response_code(400);
    die("Keine Datei angegeben.");
}

$basePath = realpath(__DIR__);
$filePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . $_GET['file']);

if (
    $basePath === false ||
    $filePath === false ||
    strpos($filePath, $basePath . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($filePath)
) {
    http_response_code(404);
    die("Datei nicht gefunden.");
}

$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
$fileSize = filesize($filePath);
$start = 0;
$end = $fileSize - 1;
$statusCode = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
    if ($matches[1] === '' && $matches[2] !== '') {
        $suffixLength = (int)$matches[2];
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int)$matches[1];
    }

    if ($matches[2] !== '') {
        $end = min((int)$matches[2], $fileSize - 1);
    }

    if ($start > $end || $start >= $fileSize) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }

    $statusCode = 206;
}

if (ob_get_level()) {
    ob_end_clean();
}

http_response_code($statusCode);
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('X-Content-Type-Options: nosniff');

if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false) {
        break;
    }

    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}

fclose($handle);
exit;
