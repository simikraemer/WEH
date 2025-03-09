<?php
require('template.php'); // hieraus kommt $conn
mysqli_set_charset($conn, "utf8");

// **1️⃣ Sicherheit: Prüfen, ob die Anfrage vom Server kommt**
if ($_SERVER['REMOTE_ADDR'] !== $_SERVER['SERVER_ADDR']) {
    header("Location: denied.php");
    exit;
}

// **2️⃣ CUPS-Job-ID & Gedruckte Seiten aus GET-Parameter holen**
if (!isset($_GET['cups_id'])) {
    die("❌ Fehler: Keine CUPS-ID angegeben.");
}

$cups_id = intval($_GET['cups_id']); // Absicherung gegen Injection

// **3️⃣ Gedruckte Seiten aus page_log ermitteln**
function getPrintedPages($cups_id) {
    $log_file = "/var/log/cups/page_log";
    if (!file_exists($log_file)) {
        return 0; // Falls der Log nicht existiert, 0 zurückgeben
    }
    $command = "grep 'job-id=$cups_id' $log_file | awk '{print $5}'";
    exec($command, $output);
    return count($output);
}

$gesamtseiten = getPrintedPages($cups_id);

// **4️⃣ Status ermitteln (1 = abgeschlossen, 2 = abgebrochen)**
$status = ($gesamtseiten > 0) ? 1 : 2;

// **5️⃣ Update der printjobs-Tabelle (tatsächlich gedruckte Seiten & Status setzen)**
$update_sql = "UPDATE weh.printjobs SET true_pages = ?, status = ? WHERE cups_id = ?";
$stmt = $conn->prepare($update_sql);
$stmt->bind_param("iii", $gesamtseiten, $status, $cups_id);
$stmt->execute();
$stmt->close();

// **6️⃣ Benutzer-ID, Titel, Druckmodus & Graustufen ermitteln**
$select_sql = "SELECT uid, title, duplex, grey FROM weh.printjobs WHERE cups_id = ?";
$stmt = $conn->prepare($select_sql);
$stmt->bind_param("i", $cups_id);
$stmt->execute();
$stmt->bind_result($uid, $title, $duplex, $grey);
$stmt->fetch();
$stmt->close();

// **7️⃣ Druckmodus & Graustufen richtig setzen**
$druckmodus = ($duplex == 1) ? "duplex" : "simplex";
$graustufen = ($grey == 1);

// **8️⃣ Gesamtpreis berechnen (negativer Betrag für Abrechnung)**
$gesamtpreis = (-1) * berechne_gesamtpreis($gesamtseiten, $druckmodus, $graustufen);

// **9️⃣ Transfer für die Abrechnung in DB eintragen**
$konto = 3; // Standardkonto für Drucker
$kasse = 3; // Standardkasse für Drucker
$print_id = $cups_id;
$beschreibung = $title;
$print_pages = $gesamtseiten;
$tstamp = time(); // Aktueller Unix-Timestamp

$insert_sql = "INSERT INTO weh.transfers 
    (uid, tstamp, beschreibung, konto, kasse, betrag, print_id, print_pages) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($insert_sql);
$stmt->bind_param("iisiidii", $uid, $tstamp, $beschreibung, $konto, $kasse, $gesamtpreis, $print_id, $print_pages);
$stmt->execute();
$stmt->close();

echo "✅ Druckauftrag verarbeitet! Gedruckte Seiten: $gesamtseiten | Betrag: $gesamtpreis €";

$conn->close();
?>
