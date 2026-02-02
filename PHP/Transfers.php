
<?php
  session_start();
  require('conn.php');

  $suche = FALSE;
  
  if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    if (!empty($searchTerm)) {
        $suche = TRUE;
        $searchTerm = mysqli_real_escape_string($conn, $searchTerm);
        if (ctype_digit($searchTerm)) {
          $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                  (name LIKE '%$searchTerm%' OR 
                   username LIKE '%$searchTerm%' OR 
                   geburtsort LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (uid = '$searchTerm') 
                  )
                  AND pid IN (11,12,13,64)";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                    (name LIKE '%$searchTerm%' OR 
                     username LIKE '%$searchTerm%' OR 
                     email LIKE '%$searchTerm%' OR 
                     aliase LIKE '%$searchTerm%' OR 
                     geburtsort LIKE '%$searchTerm%')
                   AND pid IN (11,12,13,64)";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm);
        }

        header('Content-Type: application/json');
        echo json_encode($searchedusers);
    } else {
        echo json_encode([]); // Senden einer leeren JSON-Antwort, wenn der Suchbegriff leer ist
    }
    exit;
  }
?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
        <link rel="stylesheet" href="TRANSFERS.css" media="screen">
    </head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

// 🔐 Zugriffsrecht
$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        !empty($_SESSION["NetzAG"])
        || !empty($_SESSION["Vorstand"])
        || !empty($_SESSION["Kassenpruefer"])
        || !empty($_SESSION["TvK-Sprecher"])
        || !empty($_SESSION["TvK-Kasse"])
        || !empty($_SESSION["Webmaster"])
    );

// ✏️ Bearbeitungsrecht
$admin = !empty($_SESSION["NetzAG"]) || !empty($_SESSION["Vorstand"]);

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

$START_TRANSFER_ID = [
    1  => 89747,
    2  => 55780,
    69 => 55514,
    72 => 55510,
    92 => 55509,
    93 => 55905,
    94 => 55513,
    95 => 89748,
];

$parseEuroInput = function ($s): float {
    $s = trim((string)$s);
    if ($s === '') return 0.0;
    // Tausenderpunkte killen, Komma zu Punkt
    $s = str_replace([' ', "\u{00A0}"], '', $s);
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_kontostand_correction'])) {
    if (empty($admin)) {
        header("Location: denied.php");
        exit;
    }

    $kasse = (int)($_POST['kasse_for_balance'] ?? 0);
    if (!isset($START_TRANSFER_ID[$kasse])) {
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }

    $desired = $parseEuroInput($_POST['desired_balance'] ?? '');
    $desired = round($desired, 2);

    // aktueller System-Stand
    $current = (float)berechneKontostand($conn, $kasse);
    $current = round($current, 2);

    $delta = round($desired - $current, 2);
    if (abs($delta) >= 0.005) {
        $startId = (int)$START_TRANSFER_ID[$kasse];
        $agent   = (int)($_SESSION['uid'] ?? 0);
        $nowStr  = date('d.m.Y H:i');

        $log = "[{$nowStr}] Agent {$agent}\n";
        $log .= "Kontostand-Korrektur über Transfers.php\n";
        $log .= "Ziel: " . number_format($desired, 2, ',', '.') . " € | System vorher: " . number_format($current, 2, ',', '.') . " € | Delta: " . number_format($delta, 2, ',', '.') . " €\n";

        $upd = $conn->prepare("
            UPDATE transfers
            SET betrag = betrag + ?,
                agent  = ?,
                changelog = CONCAT(IFNULL(changelog,''), IF(IFNULL(changelog,'')='', '', '\n\n'), ?)
            WHERE id = ?
            LIMIT 1
        ");
        $upd->bind_param("disi", $delta, $agent, $log, $startId);
        $upd->execute();
        $upd->close();
    }

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (isset($_POST['transfer_upload_speichern'])) {
    $uid = intval($_POST['uid_neu']);
    $beschreibung = trim($_POST['beschreibung_neu']);
    if ($beschreibung === '') {
        $beschreibung = 'Transfer';
    }
    $betrag = floatval(str_replace(',', '.', $_POST['betrag_neu']));
    $zeit = time();

    // Datei hochladen
    $rechnungspfad = null;
    if (isset($_FILES['rechnung_neu']) && $_FILES['rechnung_neu']['error'] === 0) {
        $upload_dir = 'rechnungen/';
        $original_name = $_FILES['rechnung_neu']['name'];

        // Endung extrahieren
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $basename = pathinfo($original_name, PATHINFO_FILENAME);

        // Leerzeichen und Sonderzeichen bereinigen
        $base = str_replace(' ', '_', $basename); // oder komplett: preg_replace('/[^A-Za-z0-9_\-]/', '', $basename);
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '', $base);

        // Kürzen auf max 200 Zeichen
        $max_basename_len = 200;
        $base = substr($base, 0, $max_basename_len);
        $filename = $base . '.' . $extension;
        $zielpfad = $upload_dir . $filename;

        $counter = 1;
        while (file_exists($zielpfad)) {
            // Reserviere Platz für z.B. "_2", "_3" ... also 2-4 zusätzliche Zeichen
            $suffix = '_' . $counter;
            $cut_base = substr($base, 0, $max_basename_len - strlen($suffix));
            $filename = $cut_base . $suffix . '.' . $extension;
            $zielpfad = $upload_dir . $filename;
            $counter++;
        }

        // Datei verschieben
        if (move_uploaded_file($_FILES['rechnung_neu']['tmp_name'], $zielpfad)) {
            $rechnungspfad = $zielpfad;
        }
    }


    // Konto & Kasse
    $konto = ($betrag >= 0) ? 4 : 8;
    $kasse = isset($_POST['kasse_id']) ? intval($_POST['kasse_id']) : 1;
    $agent = $_SESSION["uid"];

    // Changelog
    $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
    $changelog .= "Insert durch Transfers.php\n";

    // Insert in Datenbank (inkl. Agent)
    $sql = "INSERT INTO transfers (uid, beschreibung, betrag, konto, kasse, tstamp, changelog, pfad, agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dsdiiissi", $uid, $beschreibung, $betrag, $konto, $kasse, $zeit, $changelog, $rechnungspfad, $agent);

    $stmt->execute();

    $stmt->close();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); // entfernt evtl. alte Query-Strings
    exit;
}


// CSV-Import-Handler (mit hartem Cutoff & klassischer Duplikatprüfung)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_sparkasse_csv'])) {
    // Saubere JSON-Antwort (keine PHP-Warnings davor)
    while (ob_get_level() > 0) { ob_end_clean(); }
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    if (empty($admin)) {
        echo json_encode(['ok' => false, 'error' => 'not_authorized']);
        exit;
    }

    $csvText = $_POST['csv_text'] ?? '';
    if ($csvText === '') {
        echo json_encode(['ok' => false, 'error' => 'empty_csv']);
        exit;
    }
    if (!mb_detect_encoding($csvText, 'UTF-8', true)) {
        $csvText = mb_convert_encoding($csvText, 'UTF-8');
    }

    // Fix: alles älter als 09.11.2025 ignorieren
    $CUTOFF_TS = mktime(0, 0, 0, 11, 9, 2025); // 09.11.2025 00:00

    // IBANs + Mapping
    $NETZ_IBAN = 'DE90390500001070334600';
    $HAUS_IBAN = 'DE37390500001070334584';

    $kasseMap = [
        $NETZ_IBAN => 72, // Netzkonto
        $HAUS_IBAN => 92, // Hauskonto
    ];
    $netzkontoMap = [
        $NETZ_IBAN => 1,
        $HAUS_IBAN => 0,
    ];

    // Parser
    $parseAmount = function (string $s): float {
        $s = trim($s);
        $neg = false;
        if (substr($s, -1) === '-') { $neg = true; $s = substr($s, 0, -1); }
        $s = str_replace(['.', ' '], '', $s);
        $s = str_replace(',', '.', $s);
        $v = (float)$s;
        return $neg ? -$v : $v;
    };
    $parseDate = function (string $s): int {
        $s = trim($s);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return mktime(12, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2})$/', $s, $m)) {
            $y = (int)$m[3];
            $y = ($y < 70) ? 2000 + $y : 1900 + $y;
            return mktime(12, 0, 0, (int)$m[2], (int)$m[1], $y);
        }
        return time();
    };

    // CSV parsen
    $lines = preg_split('/\R/u', $csvText, -1, PREG_SPLIT_NO_EMPTY);
    if (!$lines || count($lines) < 2) {
        echo json_encode(['ok' => false, 'error' => 'no_rows']);
        exit;
    }

    $header = str_getcsv($lines[0], ';', '"');
    $idx = [];
    foreach ($header as $i => $h) {
        $h = trim($h, " \t\n\r\0\x0B\"");
        $idx[$h] = $i;
    }

    $required = [
        'Auftragskonto',
        'Buchungstag',
        'Valutadatum',
        'Buchungstext',
        'Verwendungszweck',
        'Beguenstigter/Zahlungspflichtiger',
        'Kontonummer/IBAN',
        'Betrag',
    ];

    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            echo json_encode(['ok' => false, 'error' => 'missing_col:' . $col]);
            exit;
        }
    }

    // --- PERFORMANCE: Prefetch + nur noch INSERT Statements (keine SELECTs pro Zeile) ---

    $moneyKey = function ($x): string {
        // stabiler 2-decimal key (wie früher ROUND(...,2))
        $v = round((float)$x, 2);
        return number_format($v, 2, '.', '');
    };

    $existingKnown   = []; // keys: iban|kasse|tstamp|betrag2
    $existingUnknown = []; // keys: iban|tstamp|betrag2|betreff_norm

    // Prefetch: bekannte Transfers (konto=4, ab cutoff, nur relevante kassen)
    $prefKnown = $conn->prepare("
        SELECT iban, kasse, tstamp, betrag
        FROM transfers
        WHERE konto=4
        AND tstamp >= ?
        AND kasse IN (69,72,92)
    ");
    $prefKnown->bind_param("i", $CUTOFF_TS);
    $prefKnown->execute();
    $prefKnown->bind_result($p_iban, $p_kasse, $p_tstamp, $p_betrag);
    while ($prefKnown->fetch()) {
        $k = (string)$p_iban . '|' . (int)$p_kasse . '|' . (int)$p_tstamp . '|' . $moneyKey($p_betrag);
        $existingKnown[$k] = true;
    }
    $prefKnown->close();

    // Prefetch: unknowntransfers (ab cutoff)
    $prefUnk = $conn->prepare("
        SELECT iban, tstamp, betrag, COALESCE(betreff,'') AS betreff_norm
        FROM unknowntransfers
        WHERE tstamp >= ?
    ");
    $prefUnk->bind_param("i", $CUTOFF_TS);
    $prefUnk->execute();
    $prefUnk->bind_result($u_iban, $u_tstamp, $u_betrag, $u_betreff_norm);
    while ($prefUnk->fetch()) {
        $k = (string)$u_iban . '|' . (int)$u_tstamp . '|' . $moneyKey($u_betrag) . '|' . (string)$u_betreff_norm;
        $existingUnknown[$k] = true;
    }
    $prefUnk->close();

    // Nur noch INSERT Statements
    $insKnown = $conn->prepare(
        'INSERT INTO transfers (uid, iban, tstamp, beschreibung, konto, kasse, betrag, agent, changelog) 
        VALUES (?, ?, ?, ?, 4, ?, ?, ?, ?)'
    );

    $insUnknown = $conn->prepare(
        'INSERT INTO unknowntransfers (uid, tstamp, name, betreff, betrag, netzkonto, iban, agent, status) 
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 0)'
    );

    // kleine Helper-Funktion: execute oder JSON-Fehler + rollback
    $execOrFail = function (mysqli_stmt $st) use ($conn) {
        if (!$st->execute()) {
            $err = $st->error ?: 'stmt_execute_failed';
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $err]);
            exit;
        }
    };


    $agent = (int)($_SESSION['uid'] ?? 0);
    $nowStr = date('d.m.Y H:i');
    $inserted = 0; $skipped = 0; $unknown = 0;
    $conn->begin_transaction();
    // Zeilen verarbeiten
    for ($li = 1; $li < count($lines); $li++) {
        $row = str_getcsv($lines[$li], ';', '"');
        if (!$row || count($row) < count($header)) { continue; }

        $auftragskonto = trim($row[$idx['Auftragskonto']] ?? '');
        $valutaStr     = trim($row[$idx['Valutadatum']] ?? '');
        $buchungStr    = trim($row[$idx['Buchungstag']] ?? '');
        $buchungstext  = trim($row[$idx['Buchungstext']] ?? '');
        $verwendung    = trim($row[$idx['Verwendungszweck']] ?? '');
        $name          = trim($row[$idx['Beguenstigter/Zahlungspflichtiger']] ?? '');
        $iban          = trim($row[$idx['Kontonummer/IBAN']] ?? '');
        $betragStr     = trim($row[$idx['Betrag']] ?? '');

        // Kassenausgleich-Überweisungen zwischen Netz- und Hauskonto (beide Richtungen) explizit ignorieren
        if (
            (
                ($auftragskonto === $NETZ_IBAN && $iban === $HAUS_IBAN) ||
                ($auftragskonto === $HAUS_IBAN && $iban === $NETZ_IBAN)
            )
            && stripos($verwendung, 'kassenausgleich') !== false
        ) {
            $skipped++;
            continue;
        }

        // Nur unsere beiden Konten berücksichtigen
        if (!isset($kasseMap[$auftragskonto])) { $skipped++; continue; }

        $betrag = $parseAmount($betragStr);

        // Abmeldungen (Rücküberweisung bei Austritt) ignorieren – werden von anderem Prozess verbucht
        if (
            ($auftragskonto === $NETZ_IBAN || $auftragskonto === $HAUS_IBAN) &&
            stripos($verwendung, 'abmeldung') !== false &&
            $betrag < 0
        ) {
            $skipped++;
            continue;
        }

        // Interne Umbuchungen zwischen NETZ_IBAN <-> HAUS_IBAN über 1000 (abs) ignorieren
        $isInterAccount =
            ($auftragskonto === $NETZ_IBAN && $iban === $HAUS_IBAN) ||
            ($auftragskonto === $HAUS_IBAN && $iban === $NETZ_IBAN);
        if ($isInterAccount && abs($betrag) > 1000) {
            $skipped++;
            continue;
        }

        // Flags für Sonderfälle
        $isEntgeltabschluss = (stripos($buchungstext, 'ENTGELTABSCHLUSS') !== false);
        $isPaypalTransfer   = (
            $auftragskonto === $NETZ_IBAN &&
            stripos($name, 'paypal europe') !== false
        );

        // Nur positive Gutschriften importieren – außer Entgeltabschluss (Bankgebühr)
        if ($betrag <= 0.0 && !$isEntgeltabschluss) {
            $skipped++;
            continue;
        }

        // Datum (Valuta bevorzugt) und harter Cutoff
        $tstamp = $parseDate($valutaStr !== '' ? $valutaStr : $buchungStr);
        if ($tstamp < $CUTOFF_TS) { $skipped++; continue; }

        $kasse     = $kasseMap[$auftragskonto];
        $netzkonto = $netzkontoMap[$auftragskonto];

        // UID & Beschreibung bestimmen
        $uid = null;
        $beschreibung = 'Transfer';

        // Entgeltabschlüsse direkt auf Dummy-UIDs
        if ($isEntgeltabschluss) {
            $beschreibung = 'Entgeltabschluss';
            if ($auftragskonto === $NETZ_IBAN) {
                $uid = 472; // NetzAG Dummy
            } elseif ($auftragskonto === $HAUS_IBAN) {
                $uid = 492; // Haussprecher Dummy
            }
        }
        // Monatlicher PayPal-Transfer direkt auf Netz-Dummy
        elseif ($isPaypalTransfer) {
            $uid = 472; // PayPal-Umsatz -> NetzAG Dummy
            $beschreibung = 'Kassenausgleich PayPal';
        }

        // UID aus "W<uid>H" nur, wenn noch keine Dummy-UID gesetzt ist
        if ($uid === null && preg_match('/W\s*(\d{1,6})\s*H(?!\d)/iu', $verwendung, $m)) {
            $uid = (int)$m[1];
        }

        if ($uid !== null) {

            $betrag2 = round($betrag, 2);
            $keyKnown = $iban . '|' . $kasse . '|' . $tstamp . '|' . $moneyKey($betrag2);

            // Duplikat? -> skip
            if (isset($existingKnown[$keyKnown])) {
                $skipped++;
                continue;
            }

            $changelog = "[{$nowStr}] CSV-Import durch Agent {$agent}\nQuelle: Sparkasse CSV";

            // 1) Normaler Eintrag (Netz/Haus)
            $insKnown->bind_param(
                'isisidis',
                $uid,
                $iban,
                $tstamp,
                $beschreibung,
                $kasse,
                $betrag2,
                $agent,
                $changelog
            );
            $execOrFail($insKnown);
            $inserted++;

            // Key merken (damit Duplikate innerhalb derselben CSV auch erkannt werden)
            $existingKnown[$keyKnown] = true;

            // 2) Zusätzliche Gegenbuchung für den monatlichen PayPal-Transfer
            if ($isPaypalTransfer) {
                $kassePaypal  = 69;
                $betragPaypal = round(-$betrag2, 2);
                $keyPaypal    = $iban . '|' . $kassePaypal . '|' . $tstamp . '|' . $moneyKey($betragPaypal);

                if (!isset($existingKnown[$keyPaypal])) {
                    $insKnown->bind_param(
                        'isisidis',
                        $uid,
                        $iban,
                        $tstamp,
                        $beschreibung, // "Kassenausgleich PayPal"
                        $kassePaypal,
                        $betragPaypal,
                        $agent,
                        $changelog
                    );
                    $execOrFail($insKnown);
                    $inserted++;
                    $existingKnown[$keyPaypal] = true;
                } else {
                    $skipped++;
                }
            }

        } else {

            $betrag2 = round($betrag, 2);
            $betreffNorm = $verwendung ?? '';
            $keyUnk = $iban . '|' . $tstamp . '|' . $moneyKey($betrag2) . '|' . $betreffNorm;

            if (isset($existingUnknown[$keyUnk])) {
                $skipped++;
                continue;
            }

            $insUnknown->bind_param('issdisi', $tstamp, $name, $verwendung, $betrag2, $netzkonto, $iban, $agent);
            $execOrFail($insUnknown);
            $unknown++;

            $existingUnknown[$keyUnk] = true;
        }

    }

    $conn->commit();
    $insKnown->close();
    $insUnknown->close();

    $kidNow = (int)($_SESSION['kasse_id'] ?? 0);
    $newBalance = (float)berechneKontostand($conn, $kidNow);
    $newBalanceFmt = number_format($newBalance, 2, ',', '.');

    echo json_encode([
        'ok' => true,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'unknown' => $unknown,
        'balance' => [
            'kid' => $kidNow,
            'raw' => round($newBalance, 2),
            'fmt' => $newBalanceFmt
        ]
    ]);
    exit;
}



if (isset($_POST['save_transfer_id'])) {
    

    // Alle übergebenen Werte abfangen
    $transfer_id = $_POST['transfer_id'];
    $selected_user = $_POST['selected_user']; // Benutzer-ID
    $konto = $_POST['konto_update']; // Konto
    $kasse = $_POST['kasse']; // Kasse
    $betrag = $_POST['betrag']; // Betrag (als Dezimalzahl mit Punkt)
    $beschreibung = $_POST['beschreibung']; // Neue Beschreibung
    $zeit = time(); // Neuer Timestamp (aktuelle Zeit)
    $agent = $_SESSION['uid']; // Agent, der die Änderung durchführt (aus Session)
    $ausgangs_tstamp = (int)($_POST['ausgangs_tstamp'] ?? 0);
    $orig_h = (int)date('H', $ausgangs_tstamp);
    $orig_i = (int)date('i', $ausgangs_tstamp);
    $orig_s = (int)date('s', $ausgangs_tstamp);
    if (!empty($_POST['tstamp_time']) && preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $_POST['tstamp_time'], $tm)) {
        $orig_h = (int)$tm[1];
        $orig_i = (int)$tm[2];
        $orig_s = (int)$tm[3];
    }
    $new_tstamp = $ausgangs_tstamp;    
    $y  = (int)date('Y', $ausgangs_tstamp);
    $mo = (int)date('m', $ausgangs_tstamp);
    $d  = (int)date('d', $ausgangs_tstamp);
    if (!empty($_POST['tstamp_date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $_POST['tstamp_date'], $m)) {
        $y  = (int)$m[1];
        $mo = (int)$m[2];
        $d  = (int)$m[3];
    }    
    $candidate = mktime($orig_h, $orig_i, $orig_s, $mo, $d, $y);
    if ($candidate !== $ausgangs_tstamp) {
        $new_tstamp = $candidate;
    }

    // Alte Werte abfangen
    $ausgangs_uid = $_POST['ausgangs_uid'];
    $ausgangs_konto = $_POST['ausgangs_konto'];
    $ausgangs_kasse = $_POST['ausgangs_kasse'];
    $ausgangs_betrag = $_POST['ausgangs_betrag'];
    $ausgangs_beschreibung = $_POST['ausgangs_beschreibung'];
    $ausgangs_pfad = $_POST['ausgangs_pfad'];

    $pfad = $ausgangs_pfad; // Standard: alter Pfad bleibt erhalten

    if (isset($_FILES['rechnung_upload']) && $_FILES['rechnung_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'rechnungen/';
        $transfer_id = intval($transfer_id);
        $tmp_name = $_FILES['rechnung_upload']['tmp_name'];
        $original_name = $_FILES['rechnung_upload']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        if (in_array($extension, $allowed_extensions)) {
            $base = $transfer_id;
            $new_filename = $base . '.' . $extension;
            $target_path = $upload_dir . $new_filename;
            $counter = 1;
    
            // Bei Kollision: fortlaufend durchnummerieren
            while (file_exists($target_path)) {
                $new_filename = $base . '_' . $counter . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                $counter++;
            }
    
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
    
            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path; // nur jetzt wird überschrieben
            } else {
                echo "Fehler beim Verschieben der Datei.";
            }
        } else {
            echo "Ungültiges Dateiformat.";
        }
    }

    function formatBetrag($betrag) {
        // Wenn Komma vorhanden, ist Punkt Tausendertrenner
        if (strpos($betrag, ',') !== false) {
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
        }
        return $betrag;
    }
        
    $formatierter_ausgangsbetrag = formatBetrag($ausgangs_betrag);
    $formatierter_betrag = formatBetrag($betrag);
       
    
    // Variable für Änderungen
    $has_changes = false;
    $changelog = "";

    // Überprüfen, ob Änderungen vorgenommen wurden
    if ($selected_user != $ausgangs_uid) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Benutzer: von UID " . $ausgangs_uid . " auf UID " . $selected_user . "\n";
    }
    if ($konto != $ausgangs_konto) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Konto: von " . $ausgangs_konto . " auf " . $konto . "\n";
    }
    if ($new_tstamp !== $ausgangs_tstamp) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Zeit: von "
            . date("d.m.Y H:i", $ausgangs_tstamp) . " (" . $ausgangs_tstamp . ")"
            . " auf "
            . date("d.m.Y H:i", $new_tstamp) . " (" . $new_tstamp . ")\n";
    }
    if ($kasse != $ausgangs_kasse) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Kasse: von " . $ausgangs_kasse . " auf " . $kasse . "\n";
    }
    if ($formatierter_betrag != $formatierter_ausgangsbetrag) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }

        $changelog .= "Betrag: von " . $formatierter_ausgangsbetrag . " € auf " . $formatierter_betrag . " €\n";
    }
    if ($beschreibung != $ausgangs_beschreibung) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
        $changelog .= "Beschreibung: von \"" . $ausgangs_beschreibung . "\" auf \"" . $beschreibung . "\"\n";
    }    
    if ($pfad != $ausgangs_pfad) {
        if (!$has_changes) {
            $changelog .= "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
            $has_changes = true;
        }
    
        if (empty($ausgangs_pfad)) {
            $changelog .= "Rechnung: neu hochgeladen als \"$pfad\"\n";
        } else {
            $changelog .= "Rechnung: geändert von \"$ausgangs_pfad\" zu \"$pfad\"\n";
        }
    }
        


    // Update-Abfrage nur durchführen, wenn Änderungen vorhanden sind
    if ($has_changes) {
        $query = "UPDATE transfers 
        SET uid = ?, 
            konto = ?, 
            kasse = ?, 
            betrag = ?, 
            beschreibung = ?,
            agent = ?, 
            pfad = ?,
            tstamp = ?,
            changelog = CONCAT(IFNULL(changelog, ''), IF(changelog IS NOT NULL, '\n\n', ''), ?) 
        WHERE id = ?";

        $stmt = $conn->prepare($query);

        // Bindet die Parameter an die Abfrage
        $stmt->bind_param("iiidsisisi", $selected_user, $konto, $kasse, $formatierter_betrag, $beschreibung, $agent, $pfad, $new_tstamp, $changelog, $transfer_id);

        // Führt das Update aus
        $stmt->execute();

        // Schließt das Statement
        $stmt->close();
    }
}

load_menu();


if (isset($_POST['kasse_id'])) {
    $_SESSION['kasse_id'] = $_POST['kasse_id'];
}

if (!isset($_SESSION['kasse_id'])) {
    $_SESSION['kasse_id'] = 72; // Standard: Netzkonto
}

$kid = $_SESSION['kasse_id'];
$zeit = time();

if (isset($_POST['semester_start'])) {
    $_SESSION['semester_start'] = intval($_POST['semester_start']);
}

if (!isset($_SESSION['semester_start'])) {
    $_SESSION['semester_start'] = unixtime2startofsemester(time());
}

$semester_start = $_SESSION['semester_start'];

// Semesterende = Start des nächsten Semesters
$month = date('m', $semester_start);
$year = date('Y', $semester_start);

if ($month == 4) {
    // Sommersemester → nächstes Wintersemester beginnt im Oktober
    $semester_ende = strtotime("01-10-$year");
} else {
    // Wintersemester → nächstes Sommersemester im April nächsten Jahres
    $semester_ende = strtotime("01-04-" . ($year + 1));
}

// aktuelle Semesterbasis berechnen
$current_start = unixtime2startofsemester($zeit);


// ▼ HIER war's vorher bei dir vergessen:
$semester_options = [];
$ts = $current_start;
while ($ts >= strtotime('01-10-2022')) {
    $sem = unixtime2semester($ts);
    $semester_options[$sem] = $ts;

    $month = date('m', $ts);
    $year = date('Y', $ts);

    if ($month == 4) {
        $ts = strtotime("01-10-" . ($year - 1));
    } else {
        $ts = strtotime("01-04-$year");
    }
}

if ($admin) { ?>
  <div id="csv_status_banner" class="csv-status-banner"></div>
<?php }

echo '<div class="kasse-semester-grid">';

// Linke 4/6: Kassen-Formular
echo '<form method="post" class="kasse-form" style="margin: 0;">';

echo '<div class="kasse-row">';
$onlinekassen = [
    ['id' => 72, 'label' => 'Netzkonto'],
    ['id' => 69, 'label' => 'PayPal'],
    ['id' => 92, 'label' => 'Hauskonto']
];
foreach ($onlinekassen as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:20px; width:150px;">' . $btn['label'] . '</button>';
}
echo '</div>';


$barkassen = [
    ['id' => 1, 'label' => 'Netzbarkasse I', 'const' => 'kasse_netz1'],
    ['id' => 2, 'label' => 'Netzbarkasse II', 'const' => 'kasse_netz2'],
    ['id' => 93, 'label' => 'Kassenwart I', 'const' => 'kasse_wart1'],
    ['id' => 94, 'label' => 'Kassenwart II', 'const' => 'kasse_wart2'],
    ['id' => 95, 'label' => 'Tresor', 'const' => 'kasse_tresor']
];
$kassen_usernames = [];

$stmt = mysqli_prepare($conn, "SELECT u.firstname FROM constants c JOIN users u ON c.wert = u.uid WHERE c.name = ?");
foreach ($barkassen as $barkasse) {
    $name = $barkasse['const'];
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $firstname);
    if (mysqli_stmt_fetch($stmt)) {
        $kassen_usernames[$barkasse['id']] = $firstname;
    } else {
        $kassen_usernames[$barkasse['id']] = '-';
    }
    mysqli_stmt_free_result($stmt);
}
mysqli_stmt_close($stmt);

echo '<div class="kasse-row">';
foreach ($barkassen as $btn) {
    $active = ($kid == $btn['id']) ? ' active' : '';
    $owner = $kassen_usernames[$btn['id']] ?? '-';

    echo '<button type="submit" name="kasse_id" value="' . $btn['id'] . '" class="kasse-button' . $active . '" style="font-size:13px; width:130px; display: flex; flex-direction: column; align-items: center;">';
    echo '<span>' . $btn['label'] . '</span>';
    echo '<span style="font-size:11px; color:#aaa;">' . htmlspecialchars($owner) . '</span>';
    echo '</button>';
}
echo '</div>';

echo '<div style="width:100%; height:0; border-top:2px solid rgba(255,255,255,0.25); margin:12px 0;"></div>';

echo '<div class="kasse-row">';

$specialBtns = [
    ['id' => -11, 'label' => 'Druckaufträge'],
    ['id' => -12, 'label' => 'Abrechnungen'],
    ['id' => -14, 'label' => 'Waschmarken'],
    ['id' => -13, 'label' => 'Sonstige'],
];

foreach ($specialBtns as $btn) {
    $active = ((int)$kid === (int)$btn['id']) ? ' active' : '';
    echo '<button type="submit" name="kasse_id" value="' . (int)$btn['id'] . '" class="kasse-button' . $active . '" style="font-size:13px; width:150px;">'
       . $btn['label']
       . '</button>';
}

echo '</div>';


echo '</form>';

// Rechte 2/6 (+ CSV bei Admin): Dropdown + Kontostand (+ CSV-Import)
$REGULAR_KASSEN = [72, 69, 92, 1, 2, 93, 94, 95];          // online + barkassen
$showKontostand = in_array((int)$kid, $REGULAR_KASSEN, true);
$showCsvImport  = (!empty($admin) && in_array((int)$kid, [72, 92], true)); // NUR Netzkonto + Hauskonto

echo '<div class="transfers_x_rightbar">';
echo '  <div class="transfers_x_rightgrid">';

//
// Slot 1: CSV (oder hidden placeholder, aber Platz bleibt)
//
$csvSlotClass = $showCsvImport ? '' : ' transfers_x_hidden_keep_space';
echo '    <div class="transfers_x_slot transfers_x_slot_csv' . $csvSlotClass . '">';
echo '      <div class="csv-import" style="display:flex; align-items:center; justify-content:flex-end; gap:10px; text-align:center;">';
echo '        <label for="sparkasse_csv" class="kasse-button"';
echo '               style="font-size:14px; padding:8px 12px; cursor:pointer; user-select:none;"';
echo '               title="Bei der Sparkasse in der Kontoübersicht: Exportieren → Excel (CSV - gefilterte Einträge).&#10;Diese Datei herunterladen und hier hochladen -> die Transfers werden automatisch den Usern zugewiesen.&#10;Doppelte Einträge werden übersprungen, nicht zuordbare Transfers werden später durch die NetzAG zugewiesen.">';
echo '          Sparkassen-CSV importieren';
echo '        </label>';
echo '        <input type="file" id="sparkasse_csv" accept=".csv" style="display:none">';
echo '      </div>';
echo '    </div>';

//
// Slot 2: Kontostand (oder hidden placeholder, aber Platz bleibt)
//
$balSlotClass = $showKontostand ? '' : ' transfers_x_hidden_keep_space';

$kontostand = $showKontostand ? (float)berechneKontostand($conn, (int)$kid) : 0.0;
$kontostandFmt = number_format($kontostand, 2, ',', '.');

$balClickableClass = ($admin && $showKontostand) ? 'transfers_x_balance_clickable' : 'transfers_x_balance_static';
$balOnclick = ($admin && $showKontostand) ? 'onclick="openKontostandModal()"' : '';

echo '    <div class="transfers_x_slot transfers_x_slot_balance' . $balSlotClass . '">';
echo '      <div class="kontostand-box ' . $balClickableClass . '" ' . $balOnclick . '>';
echo '        Aktueller Kontostand:<br><strong id="transfers_x_balance_amount">' . $kontostandFmt . ' €</strong>';
echo '      </div>';
echo '    </div>';

//
// Slot 3: Dropdown (immer sichtbar, immer gleich groß)
//
echo '    <div class="transfers_x_slot transfers_x_slot_dropdown">';
echo '      <form method="post" class="transfers_x_dropdown_form">';
echo '        <select id="semester-select" name="semester_start" class="semester-dropdown transfers_x_dropdown_select" onchange="this.form.submit()">';
foreach ($semester_options as $label => $start_ts) {
    $selected = ($start_ts == $semester_start) ? 'selected' : '';
    echo "<option value=\"$start_ts\" $selected>$label</option>";
}
echo '        </select>';
echo '      </form>';
echo '    </div>';

echo '  </div>';
echo '</div>';

// Modal nur wenn Kontostand grundsätzlich da ist (sonst unnötig DOM)
if ($showKontostand) {
    $prefill = htmlspecialchars($kontostandFmt, ENT_QUOTES, 'UTF-8');

    echo '
      <div id="balance_overlay" class="transfers_x_modal_overlay" onclick="closeKontostandModal()"></div>
      <div id="balance_modal" class="transfers_x_modal">
        <div class="transfers_x_modal_header">
          <div class="transfers_x_modal_title">Kontostand korrigieren</div>
          <button type="button" onclick="closeKontostandModal()" class="close-btn" style="margin:0;">X</button>
        </div>

        <div class="transfers_x_modal_text">
          Der Konto- bzw. Kassenstand berechnet sich in unserem System aus der Summe der Einträge. Vor allem die Abrechnung der PayPal-Transfers ist nicht exakt und muss daher im Backend gelegentlich angepasst werden, damit der Kontostand im Backend korrekt ist. Auch bei Kassenprüfungen können ein paar wenige Cents fehlen.
          <br><br>
          Seit die Kassen im Backend digitalisiert wurden, haben wir einfach mit dem damaligen Startwert angefangen. Hier kannst du den Wert korrigieren:
          <br><br>
          Du kannst für die aktuell ausgewählte Kasse den wahren Kontostand eintragen. Auf allen Seiten wird der Kontostand dann aktualisiert, indem der damalige Startwert angepasst wird. Dies ist keine Methode, um Zahlungen einzutragen!
        </div>

        <div class="transfers_x_modal_panel">
          <div class="transfers_x_modal_hint">
            Aktueller Systemwert (berechnet): <span style="color:#fff;">' . $kontostandFmt . ' €</span>
          </div>

          <form method="post" class="transfers_x_modal_form">
            <input type="hidden" name="save_kontostand_correction" value="1">
            <input type="hidden" name="kasse_for_balance" value="' . (int)$kid . '">

            <label style="color:#aaa; font-size:13px;">Wahrer Kontostand:</label>
            <input type="text" name="desired_balance" value="' . $prefill . '" class="transfers_x_modal_input" ' . (!$admin ? 'disabled' : '') . '>
            ' . ($admin ? '<button type="submit" class="kasse-button" style="font-size:14px; padding:8px 12px;">Speichern</button>' : '') . '
          </form>

          ' . (!$admin ? '<div class="transfers_x_modal_info">Nur Admins können den Kontostand korrigieren.</div>' : '') . '
        </div>
      </div>
    ';
}




echo '</div>';






echo '<hr>';


if ($admin) {    
    echo '<form id="transfer-form" method="post" enctype="multipart/form-data">';
    echo '<div class="transfer-form-grid">'; // angenommen: 3-Spalten-Grid via CSS

    // Zeile 1: Sucheingabe, Betrag, Upload
    echo '<div style="display: flex; gap: 6px; align-items: center;">';
    echo '<input type="text" name="usersuche" id="usersuche" placeholder="Nutzer suchen..." oninput="sucheUser(this.value)" style="flex:1;">';
    echo '<div style="display: flex; gap: 4px;">';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(472, \'NetzAG Dummy\')" title="NetzAG Dummy">Netz</button>';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(492, \'Haussprecher Dummy\')" title="Haussprecher Dummy">Haus</button>';
    echo '</div>';
    echo '</div>';

    echo '<input type="number" name="betrag_neu" placeholder="Betrag (€)" step="0.01">';
    echo '<input type="file" name="rechnung_neu" accept=".pdf,.jpg,.jpeg,.png,.gif">';

    // Zeile 2: Suchausgabe, Beschreibung, Speichern
    echo '<div id="usersuchergebnisse" style="padding: 6px; background-color: #2a2a2a; border: 1px solid #444; min-height: 75px;"></div>';
    echo '<input type="text" name="beschreibung_neu" placeholder="Beschreibung">';
    echo '<button type="submit" name="transfer_upload_speichern">Speichern</button>';

    // Versteckte Felder
    echo '<input type="hidden" name="uid_neu" id="uid_neu">';
    echo '<input type="hidden" name="kasse_id" value="' . intval($kid) . '">';

    echo '</div>'; // .transfer-form-grid
    echo '</form>';
    echo '<hr>';
}



$kid = (int)$kid;

$BASE_NOT_IN = "t.kasse NOT IN (1,2,69,72,92,93,94,95)";

if ($kid === -11) {
    // 1) Druckaufträge
    $sql = "
        SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
               t.tstamp, t.beschreibung, t.betrag, t.pfad
        FROM transfers t
        JOIN users u ON t.uid = u.uid
        WHERE t.tstamp >= ? AND t.tstamp < ?
          AND $BASE_NOT_IN
          AND t.print_id IS NOT NULL AND t.print_id <> 0
        ORDER BY t.tstamp DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $semester_start, $semester_ende);

} elseif ($kid === -12) {
    // 2) Abrechnungen
    $sql = "
        SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
               t.tstamp, t.beschreibung, t.betrag, t.pfad
        FROM transfers t
        JOIN users u ON t.uid = u.uid
        WHERE t.tstamp >= ? AND t.tstamp < ?
          AND $BASE_NOT_IN
          AND (
                t.beschreibung LIKE 'Abrechnung Hausbeitrag%'
             OR t.beschreibung LIKE 'Abrechnung Netzbeitrag%'
          )
        ORDER BY t.tstamp DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $semester_start, $semester_ende);

} elseif ($kid === -14) {
    // 3) Waschmarken (exakt)
    $sql = "
        SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
               t.tstamp, t.beschreibung, t.betrag, t.pfad
        FROM transfers t
        JOIN users u ON t.uid = u.uid
        WHERE t.tstamp >= ? AND t.tstamp < ?
          AND $BASE_NOT_IN
          AND t.beschreibung = 'Waschmarken generiert'
        ORDER BY t.tstamp DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $semester_start, $semester_ende);

} elseif ($kid === -13) {
    // 4) Sonstige: alles außer Druckaufträge, Abrechnungen, Waschmarken
    $sql = "
        SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
               t.tstamp, t.beschreibung, t.betrag, t.pfad
        FROM transfers t
        JOIN users u ON t.uid = u.uid
        WHERE t.tstamp >= ? AND t.tstamp < ?
          AND $BASE_NOT_IN
          AND (t.print_id IS NULL OR t.print_id = 0)
          AND NOT (
                t.beschreibung LIKE 'Abrechnung Hausbeitrag%'
             OR t.beschreibung LIKE 'Abrechnung Netzbeitrag%'
          )
          AND t.beschreibung <> 'Waschmarken generiert'
        ORDER BY t.tstamp DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $semester_start, $semester_ende);

} else {
    // Normal: konkrete Kasse
    $sql = "
        SELECT t.id, u.firstname, u.lastname, u.room, u.turm, u.uid,
               t.tstamp, t.beschreibung, t.betrag, t.pfad
        FROM transfers t
        JOIN users u ON t.uid = u.uid
        WHERE t.tstamp >= ? AND t.tstamp < ? AND t.kasse = ?
        ORDER BY t.tstamp DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iii", $semester_start, $semester_ende, $kid);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);


// Tabelle mit sortierbaren Spalten
echo '<table class="transfer-table" id="transfers-table">';
echo '<thead>
<tr>
    <th onclick="sortTable(0, this)">Datum</th>
    <th onclick="sortTable(1, this)">Name</th>
    <th onclick="sortTable(2, this)">Raum</th>
    <th onclick="sortTable(3, this)">Beschreibung</th>
    <th onclick="sortTable(4, this)">Betrag</th>
    <th onclick="sortTable(5, this)">Rechnung</th>
</tr>
</thead>';
echo '<tbody>';

while ($row = mysqli_fetch_assoc($result)) {
    $name = cutName($row['firstname'], $row['lastname']);
    $tstamp = $row['tstamp'];
    $datum = date("d.m.Y", $tstamp);
    $betrag = number_format($row['betrag'], 2, ',', '.');
    $uid = $row['uid'];
    $room = $row['room'];
    $turm = formatTurm($row['turm']);

    echo "<tr data-id=\"{$row['id']}\" onclick=\"submitEditTransfer(this)\">";
    echo "<td data-sort=\"$tstamp\">$datum</td>";
    echo "<td>$name [$uid]</td>";
    echo "<td>$room [$turm]</td>";
    echo "<td>{$row['beschreibung']}</td>";
    echo "<td data-sort=\"{$row['betrag']}\">$betrag €</td>";

    if (!empty($row['pfad'])) {
        $safe_path = htmlspecialchars($row['pfad'], ENT_QUOTES, 'UTF-8');
        echo "<td onclick=\"event.stopPropagation(); window.open('$safe_path', '_blank', 'noopener');\">Zur Rechnung ➡️</td>";
    } else {
        echo "<td></td>";
    }

    echo "</tr>";
}

    

echo '</tbody></table>';




if (isset($_POST['edit_transfer'])) {
    
    // Abfrage für die Transfer-Informationen
    $query = "SELECT t.uid, t.konto, t.kasse, t.betrag, t.tstamp, t.beschreibung, t.changelog, t.pfad FROM transfers t WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_POST['transfer_id']);
    $stmt->execute();
    $stmt->bind_result(
        $selected_transfer_uid, 
        $selected_transfer_konto, 
        $selected_transfer_kasse, 
        $selected_transfer_betrag, 
        $selected_transfer_tstamp,
        $selected_transfer_beschreibung,
        $selected_transfer_changelog,
        $selected_transfer_pfad
    );
    $stmt->fetch();
    $stmt->close();

    // Arrays für Konto und Kasse
    $konto_options = [
        0 => "Kaution",
        1 => "Netzbeitrag",
        2 => "Hausbeitrag",
        3 => "Druckauftrag",
        4 => "Einzahlung",
        5 => "Getränk",
        6 => "Waschmaschine",
        7 => "Spülmaschine",
        8 => "Undefiniert"
    ];
    
    $kasse_options = [
        72 => "Netzkonto",
        92 => "Hauskonto",
        69 => "PayPal",
        1 => "Netzbarkasse I",
        2 => "Netzbarkasse II",
        93 => "Kassenwart I",
        94 => "Kassenwart II",
        95 => "Tresor",
        3 => "imaginäre Schuldbuchung",
        5 => "imaginäre Rückzahlung",
        0 => "Haus (alt)",
        4 => "Netzkonto (alt)",
    ];

    // Deutsche Zeitformatierung (Timestamp in Unixtime)
    $formatted_date = date("d.m.Y", (int)$selected_transfer_tstamp);

    // Start des Formulars und der Overlay-Box
    echo ('<div class="overlay"></div>
    <div class="anmeldung-form-container form-container">
      <form method="post">
          <button type="submit" name="close" value="close" class="close-btn">X</button>
      </form>
    <br>');


    echo '<div style="text-align: center;">';

    // Wenn die Felder editierbar sind, erstelle ein Formular, sonst nur Anzeige
    if ($admin) {
      echo '<form method="post" enctype="multipart/form-data">';
      echo '<input type="hidden" name="transfer_id" value="'.$_POST['transfer_id'].'">';
      echo '<input type="hidden" name="ausgangs_betrag" value="'.number_format($selected_transfer_betrag, 2, ".", "").'">';
      echo '<input type="hidden" name="ausgangs_uid" value="'.$selected_transfer_uid.'">';
      echo '<input type="hidden" name="ausgangs_konto" value="'.$selected_transfer_konto.'">';
      echo '<input type="hidden" name="ausgangs_kasse" value="'.$selected_transfer_kasse.'">';
      echo '<input type="hidden" name="ausgangs_beschreibung" value="'.htmlspecialchars($selected_transfer_beschreibung).'">';
      echo '<input type="hidden" name="ausgangs_pfad" value="'.htmlspecialchars($selected_transfer_pfad).'">';
      echo '<input type="hidden" name="ausgangs_tstamp" value="'.(int)$selected_transfer_tstamp.'">';
      echo '<input type="hidden" name="ausgangs_hour" value="'.(int)date('H', (int)$selected_transfer_tstamp).'">';
      echo '<input type="hidden" name="ausgangs_min"  value="'.(int)date('i', (int)$selected_transfer_tstamp).'">';
      echo '<input type="hidden" name="ausgangs_sec"  value="'.(int)date('s', (int)$selected_transfer_tstamp).'">';
    }
    
    echo '<div style="text-align: center; color: lightgrey;">';
    echo 'Transfer ID: <span style="color:white;">'.$_POST['transfer_id'].'</span>';
    echo '</div>';
    echo '<br><br>';
    

    // Benutzerinformationen oder Auswahl anzeigen
    if (!$admin) {
        $query_user = "SELECT name, room, turm FROM users WHERE uid = ?";
        $stmt_user = $conn->prepare($query_user);
        $stmt_user->bind_param("i", $selected_transfer_uid);
        $stmt_user->execute();
        $stmt_user->bind_result($selected_user_name, $selected_user_room, $selected_user_turm);
        $stmt_user->fetch();
        $stmt_user->close();

        // Formatierung der Turm-Anzeige
        $formatted_turm = ($selected_user_turm == 'tvk') ? 'TvK' : strtoupper($selected_user_turm);

        // Benutzerinformationen anzeigen
        echo '<label for="user_info" style="color:lightgrey;">Benutzerinformationen:</label><br>';
        echo '<p style="color:white !important;">' . htmlspecialchars($selected_user_name) . ' [' . $formatted_turm . ' ' . htmlspecialchars($selected_user_room) . ']</p><br>';
    } else {
        // Wenn die Felder editierbar sind, Dropdown für Benutzer anzeigen
        echo '<label for="selected_user" style="color:lightgrey;">Benutzer auswählen:</label><br>';
        echo '<select name="selected_user" id="selected_user" style="margin-top: 10px; padding: 5px; text-align: center; text-align-last: center; display: block; margin-left: auto; margin-right: auto;">
                <option value="" disabled selected>Wähle einen Benutzer</option>';
        echo '<option value="472" ' . (472 == $selected_transfer_uid ? 'selected' : '') . '>NetzAG-Dummy</option>';
        echo '<option value="492" ' . (492 == $selected_transfer_uid ? 'selected' : '') . '>Vorstand-Dummy</option>';
        
        // Abfrage, um alle Benutzer zu laden
        $sql = "SELECT uid, name, room, turm 
                FROM users 
                ORDER BY pid, FIELD(turm, 'weh', 'tvk'), room";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);
        
        // Benutzer in das Dropdown-Menü einfügen
        while (mysqli_stmt_fetch($stmt)) {
            $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
            echo '<option value="' . $uid . '" ' . ($uid == $selected_transfer_uid ? 'selected' : '') . '>' . $name . ' [' . $formatted_turm . ' ' . $room . ']</option>';
        }
        
        echo '</select><br><br>';
        
    }

    echo '<br>';

    // Beschreibung (editierbar oder nicht)
    echo '<label style="color:lightgrey;">Beschreibung:</label><br>';
    if (!$admin) {
        // Prüfen, ob die Beschreibung vorhanden ist, um die Deprecated-Fehlermeldung zu vermeiden
        echo '<p style="color:white !important;">'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'</p><br>';
    } else {
        echo '<input type="text" name="beschreibung" value="'.(!is_null($selected_transfer_beschreibung) ? htmlspecialchars($selected_transfer_beschreibung) : '').'" style="text-align: center; width: 80%;"><br><br>';
    }

    echo '<br>';


    echo '<div style="display: flex; gap: 20px; justify-content: space-between; align-items: flex-start;">'; // Flexbox-Container

    // Konto anzeigen (Integer -> Text)
    echo '<div style="flex: 1; max-width: 100%;">'; // Flex-Item für Konto
    echo '<label style="color:lightgrey;">Konto:</label><br>';
    $konto_display = isset($konto_options[$selected_transfer_konto]) ? $konto_options[$selected_transfer_konto] : "Undefiniertes Konto";
    if (!$admin) {
        echo '<p style="color:white !important;">'.$konto_display.'</p><br>';
    } else {
        echo '<select name="konto_update" style="text-align: center; width: 100%;">'; // Volle Breite
        foreach ($konto_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_konto ? 'selected' : '').'>'.$value.'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>'; // Ende von Konto Flex-Item
    
    // Kasse anzeigen (Integer -> Text)
    echo '<div style="flex: 1; max-width: 100%;">'; // Flex-Item für Kasse
    echo '<label style="color:lightgrey;">Kasse:</label><br>';
    $kasse_display = isset($kasse_options[$selected_transfer_kasse]) ? $kasse_options[$selected_transfer_kasse] : "Undefinierte Kasse";
    if (!$admin) {
        echo '<p style="color:white !important;">'.$kasse_display.'</p><br>';
    } else {
        echo '<select name="kasse" style="text-align: center; width: 100%;">'; // Volle Breite
        foreach ($kasse_options as $key => $value) {
            echo '<option value="'.$key.'" '.($key == $selected_transfer_kasse ? 'selected' : '').'>'.$value.'</option>';
        }
        echo '</select><br><br>';
    }
    echo '</div>'; // Ende von Kasse Flex-Item
    
    echo '</div>'; // Ende Flexbox-Container
    

    $date_value = date('Y-m-d', (int)$selected_transfer_tstamp);
    $time_value = date('H:i:s', (int)$selected_transfer_tstamp);

    echo '<label style="color:lightgrey;">Datum & Uhrzeit:</label><br>';

    if (!$admin) {
        echo '<p style="color:white !important;">'
        . date("d.m.Y H:i", (int)$selected_transfer_tstamp)
        . ' <span style="color:#aaa;">(' . (int)$selected_transfer_tstamp . ')</span>'
        . '</p><br>';
    } else {
        echo '<div style="display:flex; gap:10px; justify-content:center; align-items:center;">';
        echo '  <input type="date" name="tstamp_date" value="'.htmlspecialchars($date_value, ENT_QUOTES, "UTF-8").'" style="text-align:center;">';
        echo '  <input type="time" name="tstamp_time" value="'.htmlspecialchars($time_value, ENT_QUOTES, "UTF-8").'" step="1" style="text-align:center; width:150px;">';
        echo '</div><br><br>';
    }
    

    echo '<br>';

    // Betrag anzeigen
    echo '<label style="color:lightgrey;">Betrag:</label><br>';
    if (!$admin) {
      echo '<p style="color:white !important;">'.number_format($selected_transfer_betrag, 2, ",", ".").' €</p><br>';
    } else {
        echo '<input type="text" name="betrag" value="'.number_format($selected_transfer_betrag, 2, ",", ".").'" style="text-align: center;"><br><br>';
    }

    echo '<br>';

    if (!empty($selected_transfer_pfad)) {
        $safe_path = htmlspecialchars($selected_transfer_pfad, ENT_QUOTES, 'UTF-8');
        echo '<label style="color:lightgrey;"><a href="' . $safe_path . '" target="_blank" style="color: lightgrey;">Rechnung: ➡️</a></label><br>';
    } else {
        echo '<label style="color:lightgrey;">Rechnung</label><br>';
    }
        

    if ($admin) {
        // Upload-Feld für neue Rechnung
        echo '<input type="file" name="rechnung_upload" accept=".pdf,.jpg,.jpeg,.png,.gif" style="margin-top: 8px; color: white;"><br><br>';
    }
        


    // Changelog anzeigen (nicht editierbar) in einem optisch ansprechenden Feld
    if ($admin) {
      echo '<br>';
      echo '<label style="color:lightgrey;">Changelog:</label><br><br>';
      
      // Innerer Container im Konsolenstil mit monospace-Schrift
      echo '<div style="background-color: darkblue; color: white; font-family: monospace; padding: 10px; display: inline-block; text-align: center; width: calc(100% - 30px); max-height: 200px; overflow-y: auto; box-sizing: border-box;">'; 
      
      // Changelog ohne zusätzliche Zeilenumbrüche
      echo '<p style="margin: 0; line-height: 1.4; font-size: 14px; white-space: pre-wrap;">'.(!is_null($selected_transfer_changelog) ? htmlspecialchars($selected_transfer_changelog) : 'Kein Changelog verfügbar').'</p>';
      
      echo '</div>'; // Innerer Container Ende
      echo '<br>';
    }
  
  
  


    // Falls editierbar, Speicher-Button anzeigen und alle Eingaben an POST übergeben
    if ($admin) {
        echo '<div style="display: flex; justify-content: center; margin-top: 20px;">';
        echo '<button type="submit" name="save_transfer_id" class="sml-center-btn" style="display: inline-flex; align-items: center; justify-content: center; padding: 10px 20px;">Speichern</button>';
        echo '</form>'; // Hier endet das Hauptformular
        echo '</div>';
    }

    echo '</div>';

}



?>
<script>
let sortDirections = {};
let lastSortedColumn = null;

function sortTable(colIndex, headerEl) {
    const table = document.getElementById("transfers-table");
    const rows = Array.from(table.tBodies[0].rows);
    const dir = sortDirections[colIndex] = !sortDirections[colIndex];

    rows.sort((a, b) => {
        const aVal = a.cells[colIndex].dataset.sort || a.cells[colIndex].innerText;
        const bVal = b.cells[colIndex].dataset.sort || b.cells[colIndex].innerText;
        const aNum = parseFloat(aVal.replace(',', '.'));
        const bNum = parseFloat(bVal.replace(',', '.'));
        const cmp = (!isNaN(aNum) && !isNaN(bNum)) ? (aNum - bNum) : aVal.localeCompare(bVal);
        return dir ? cmp : -cmp;
    });

    for (const row of rows) table.tBodies[0].appendChild(row);

    // Pfeile anzeigen
    const headers = table.tHead.rows[0].cells;
    for (let i = 0; i < headers.length; i++) {
        headers[i].innerText = headers[i].innerText.replace(/[\u25B2\u25BC]/g, '');
    }
    headerEl.innerText += dir ? ' ▲' : ' ▼';
}

function submitEditTransfer(row) {
    const id = row.dataset.id;
    const form = document.createElement('form');
    form.method = 'POST';

    const editFlag = document.createElement('input');
    editFlag.type = 'hidden';
    editFlag.name = 'edit_transfer';
    editFlag.value = '1'; // bool-like

    const transferId = document.createElement('input');
    transferId.type = 'hidden';
    transferId.name = 'transfer_id';
    transferId.value = id;

    form.appendChild(editFlag);
    form.appendChild(transferId);
    document.body.appendChild(form);
    form.submit();
}

function sucheUser(term) {
    const ergebnisContainer = document.getElementById('usersuchergebnisse');
    const hiddenUidField = document.getElementById('uid_neu');

    if (term.trim() === '') {
        ergebnisContainer.innerHTML = '';
        hiddenUidField.value = '';
        return;
    }

    fetch('?search=' + encodeURIComponent(term))
        .then(response => response.json())
        .then(data => {
            ergebnisContainer.innerHTML = '';
            let count = 0;
            const maxResults = 3;

            for (const uid in data) {
                if (count >= maxResults) break;

                const userInfo = data[uid][0];
                let turm = userInfo.turm?.trim().toUpperCase() || '';
                if (turm === 'tvk') {
                    turm = 'TvK';
                }

                const displayText = `${userInfo.name} (${turm} ${userInfo.room})`;

                const div = document.createElement('div');
                div.textContent = displayText;
                div.style.padding = '4px 0';
                div.style.borderBottom = '1px solid #444';
                div.style.cursor = 'pointer';

                div.onclick = () => {
                    document.getElementById('usersuche').value = displayText;
                    hiddenUidField.value = uid;
                    ergebnisContainer.innerHTML = '';
                };

                ergebnisContainer.appendChild(div);
                count++;
            }

            if (count === 0) {
                ergebnisContainer.innerHTML = '<div style="color: #888;">Keine Ergebnisse</div>';
            }
        });
}

function setDummyUser(uid, name) {
    document.getElementById('uid_neu').value = uid;
    document.getElementById('usersuche').value = name;
    document.getElementById('usersuchergebnisse').innerHTML = '';
}

document.getElementById('transfer-form').addEventListener('submit', function(e) {
    const uid = document.getElementById('uid_neu').value.trim();
    const betrag = document.querySelector('input[name="betrag_neu"]').value.trim();

    if (!uid || !betrag) {
        alert("Bitte Nutzer auswählen und gültigen Betrag eingeben.");
        e.preventDefault(); // Verhindert das Absenden
    }
});

</script>

<script>
// CSV-Status oben im Banner anzeigen (bleibt stehen; kein Klick zum Entfernen, kein Auto-Reload)
(() => {
  const banner = document.getElementById('csv_status_banner');
  const input  = document.getElementById('sparkasse_csv');
  if (!input || !banner) return;

  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;

    banner.textContent = 'Import läuft…';

    try {
      const text = await file.text();
      const form = new FormData();
      form.append('import_sparkasse_csv', '1');
      form.append('csv_text', text);

      const res = await fetch(window.location.href, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });

      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) {}

      if (data && data.ok) {
        const msg = [
          'CSV-Import abgeschlossen:',
          `• ${data.inserted} Transfers wurden Benutzern zugewiesen`,
          `• ${data.unknown} Transfers sind unbekannt und müssen noch manuell zugewiesen werden`,
          `• ${data.skipped} Transfers wurden übersprungen, da bereits vorhanden`
        ].join('\n');
        banner.textContent = msg;
        
        if (data.balance && typeof data.balance.fmt === 'string') {
            const el = document.getElementById('transfers_x_balance_amount');
            if (el) el.textContent = `${data.balance.fmt} €`;
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
        // KEIN Reload, KEIN Wegklicken – bleibt stehen bis man die Seite neu lädt
      } else {
        banner.textContent = 'Import-Fehler:\n' + (data?.error || raw.trim() || 'Unbekannter Fehler');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    } catch (err) {
      banner.textContent = 'Fehler:\n' + (err?.message || String(err));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      input.value = '';
    }
  });
})();
</script>
<script>
function openKontostandModal() {
  const ov = document.getElementById('balance_overlay');
  const md = document.getElementById('balance_modal');
  if (!ov || !md) return;
  ov.style.display = 'block';
  md.style.display = 'block';
}
function closeKontostandModal() {
  const ov = document.getElementById('balance_overlay');
  const md = document.getElementById('balance_modal');
  if (!ov || !md) return;
  ov.style.display = 'none';
  md.style.display = 'none';
}
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeKontostandModal();
});
</script>