<?php
require('template.php');

mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Sicherheit
|--------------------------------------------------------------------------
*/

if (php_sapi_name() !== 'cli') {
    if ($_SERVER['REMOTE_ADDR'] !== $_SERVER['SERVER_ADDR']) {
        header('Location: denied.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| CUPS-ID aus Parameter
|--------------------------------------------------------------------------
*/

$cups_id = 0;

if (isset($argv[1])) {
    $cups_id = (int)$argv[1];
}

if ($cups_id <= 0) {
    die("❌ Fehler: Keine gültige CUPS-ID angegeben.\n");
}

echo "✅ Verarbeitung für CUPS-Job-ID: {$cups_id}\n";

/*
|--------------------------------------------------------------------------
| Gedruckte Seiten aus page_log ermitteln
|--------------------------------------------------------------------------
*/

function getPrintedPages(int $cupsId): int
{
    $logFile = '/var/log/cups/page_log';

    if (!file_exists($logFile)) {
        return 0;
    }

    $command = sprintf(
        "grep %s %s",
        escapeshellarg("CUPS_ID:{$cupsId} "),
        escapeshellarg($logFile)
    );

    $output = [];
    exec($command, $output);

    if (empty($output)) {
        return 0;
    }

    /*
     * Bei mehreren Treffern wird der letzte Eintrag verwendet.
     */
    $output = array_reverse($output);

    foreach ($output as $line) {
        if (
            preg_match(
                '/PAGES:\s*([0-9]+)/',
                $line,
                $matches
            )
        ) {
            return (int)$matches[1];
        }
    }

    return 0;
}

$gesamtseiten = getPrintedPages($cups_id);

echo "📄 Gedruckte Seiten für CUPS_ID {$cups_id}: {$gesamtseiten}\n";

/*
|--------------------------------------------------------------------------
| Printjob anhand der CUPS-ID laden
|
| Wichtig:
| cups_id dient nur zum Auffinden.
| transfers.print_id erhält anschließend printjobs.id.
|--------------------------------------------------------------------------
*/

$selectSql = "
    SELECT
        id,
        uid,
        title,
        duplex,
        grey,
        status
    FROM weh.printjobs
    WHERE cups_id = ?
    ORDER BY id DESC
    LIMIT 1
";

$stmt = $conn->prepare($selectSql);
$stmt->bind_param('i', $cups_id);
$stmt->execute();

$result = $stmt->get_result();
$printjob = $result->fetch_assoc();

$stmt->close();

if (!$printjob) {
    die(
        "❌ Fehler: Kein Printjob für CUPS_ID {$cups_id} gefunden.\n"
    );
}

$printjob_id = (int)$printjob['id'];
$uid = (int)$printjob['uid'];
$title = (string)$printjob['title'];
$duplex = (int)$printjob['duplex'];
$grey = (int)$printjob['grey'];
$currentStatus = (int)$printjob['status'];

echo "🔗 Gefundene Printjob-ID: {$printjob_id}\n";

/*
|--------------------------------------------------------------------------
| Bereits erstattete Jobs nicht erneut abrechnen
|--------------------------------------------------------------------------
*/

if ($currentStatus === 4) {
    die(
        "⚠️ Printjob {$printjob_id} wurde bereits erstattet. "
        . "Keine erneute Verarbeitung.\n"
    );
}

/*
|--------------------------------------------------------------------------
| Status und Preis berechnen
|--------------------------------------------------------------------------
*/

$status = $gesamtseiten > 0
    ? 1
    : 2;

$druckmodus = $duplex === 1
    ? 'duplex'
    : 'simplex';

$graustufen = $grey === 1;

$gesamtpreis = (-1) * berechne_gesamtpreis(
    $gesamtseiten,
    $druckmodus,
    $graustufen
);

$konto = 3;
$kasse = 3;
$print_pages = $gesamtseiten;
$tstamp = time();

/*
|--------------------------------------------------------------------------
| Printjob und Transfer gemeinsam verarbeiten
|--------------------------------------------------------------------------
*/

try {
    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Printjob aktualisieren
    |
    | Update jetzt über die echte printjobs.id, nicht erneut über cups_id.
    |--------------------------------------------------------------------------
    */

    $updateSql = "
        UPDATE weh.printjobs
        SET
            true_pages = ?,
            status = ?
        WHERE id = ?
    ";

    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param(
        'iii',
        $gesamtseiten,
        $status,
        $printjob_id
    );
    $stmt->execute();
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Doppelte Abrechnung verhindern
    |--------------------------------------------------------------------------
    */

    $existingTransferSql = "
        SELECT id
        FROM weh.transfers
        WHERE print_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($existingTransferSql);
    $stmt->bind_param('i', $printjob_id);
    $stmt->execute();

    $existingTransferResult = $stmt->get_result();
    $existingTransfer = $existingTransferResult->fetch_assoc();

    $stmt->close();

    if ($existingTransfer) {
        $conn->commit();

        echo
            "⚠️ Für Printjob {$printjob_id} existiert bereits "
            . "Transfer {$existingTransfer['id']}. "
            . "Kein weiterer Transfer angelegt.\n";

        $conn->close();
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer anlegen
    |
    | Entscheidende Änderung:
    | print_id = printjobs.id
    | NICHT print_id = cups_id
    |--------------------------------------------------------------------------
    */

    $insertSql = "
        INSERT INTO weh.transfers
        (
            uid,
            tstamp,
            beschreibung,
            konto,
            kasse,
            betrag,
            print_id,
            print_pages
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insertSql);
    $stmt->bind_param(
        'iisiidii',
        $uid,
        $tstamp,
        $title,
        $konto,
        $kasse,
        $gesamtpreis,
        $printjob_id,
        $print_pages
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo
        "✅ Druckauftrag verarbeitet!\n"
        . "Printjob-ID: {$printjob_id}\n"
        . "CUPS-ID: {$cups_id}\n"
        . "Gedruckte Seiten: {$gesamtseiten}\n"
        . "Betrag: {$gesamtpreis} €\n";
} catch (Throwable $exception) {
    $conn->rollback();

    die(
        "❌ Fehler bei der Verarbeitung: "
        . $exception->getMessage()
        . "\n"
    );
}

$conn->close();
?>