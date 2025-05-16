<?php
ob_start();
require_once('template.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use setasign\Fpdi\Tcpdf\Fpdi;

// Eingabe prüfen
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id']) || empty($_POST['admin'])) {
    die('Ungültiger Aufruf.');
}

$id = (int)$_POST['id'];
$admin = htmlspecialchars($_POST['admin']);
$typ = $_POST['typ'] ?? 'ausgabe'; // fallback

// Datenbankabfrage
$stmt = mysqli_prepare($conn, "SELECT neugerät, altgerät, name FROM installation WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    die("Eintrag nicht gefunden.");
}

// Basiswerte
$mitarbeiter = $row['name'];
$datum = date('d.m.Y');

// Abhängig vom Typ: Gerät auswählen
if ($typ === 'rueckgabe') {
    $geraet = $row['altgerät'];
} else {
    $geraet = $row['neugerät'];
}

// PDF initialisieren
$pdf = new Fpdi();
$pdf->AddPage();

$pdf->setSourceFile('quittung_vorlage.pdf');
$templateId = $pdf->importPage(1);
$pdf->useTemplate($templateId);

$pdf->SetFont('Helvetica', '', 12);

// Gemeinsame Positionen

$pdf->SetXY(67, 80);  // Mitarbeiter
$pdf->Write(0, $mitarbeiter);

$pdf->SetXY(86, 89.5);  // Admin
$pdf->Write(0, $admin);

$pdf->SetXY(114, 70.5);  
// Unterschiedliche Positionierung je nach Typ
if ($typ === 'rueckgabe') {
    // 🔙 Rückgabe
    $pdf->Write(0, $geraet); // Altgerät

    $pdf->SetXY(76, 113);  // Datum 1
    $pdf->Write(0, $datum);

    $pdf->SetXY(11, 267); // Datum 2
    $pdf->Write(0, $datum);
} else {
    // 🆕 Ausgabe
    $pdf->Write(0, $geraet); // Neugerät

    $pdf->SetXY(74, 104);  // Datum 1
    $pdf->Write(0, $datum);

    $pdf->SetXY(11, 238); // Datum 2
    $pdf->Write(0, $datum);
}

// Output vorher löschen
if (ob_get_length()) {
    ob_end_clean();
}

// Dateiname
$name_parts = explode(' ', trim($mitarbeiter));
$nachname = array_pop($name_parts);
$vorname_kombi = implode('', $name_parts);
$namereversed = $nachname . $vorname_kombi;
$filename = $geraet . '_' . $namereversed . '.pdf';

// PDF ausgeben
$pdf->Output($filename, 'I');
exit;
