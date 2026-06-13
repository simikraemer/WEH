<?php
  ob_start();
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

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($searchedusers);
    } else {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([]);
    }
    exit;
  }
?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
        <link rel="stylesheet" href="TRANSFERS.css" media="screen">
        <style>
            :root {
                --transfer-edit-primary: #11a50d;
                --transfer-edit-bg: #181717;
                --transfer-edit-panel: #202020;
                --transfer-edit-panel-2: #252525;
                --transfer-edit-field: #2b2b2b;
                --transfer-edit-border: #444;
                --transfer-edit-border-strong: rgba(17, 165, 13, 0.55);
                --transfer-edit-text: #f2f2f2;
                --transfer-edit-muted: #aaa;
                --transfer-edit-danger: #ff5252;
                --transfer-edit-radius: 14px;
            }

            .transfer-table th {
                cursor: pointer;
                user-select: none;
            }

            .transfer-table tbody tr.transfer-row-restored {
                outline: 2px solid var(--transfer-edit-primary);
                outline-offset: -2px;
            }

            .transfer-edit-overlay {
                z-index: 9998;
                backdrop-filter: blur(2px);
            }

            .transfer-edit-modal {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 9999;
                width: min(1280px, calc(100vw - 36px));
                max-height: calc(100vh - 36px);
                overflow: hidden;
                box-sizing: border-box;
                background: linear-gradient(180deg, #222 0%, var(--transfer-edit-bg) 100%);
                border: 1px solid var(--transfer-edit-border-strong);
                border-radius: var(--transfer-edit-radius);
                box-shadow: 0 24px 90px rgba(0, 0, 0, 0.75);
                color: var(--transfer-edit-text);
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            .transfer-edit-modal--no-receipt {
                width: min(760px, calc(100vw - 36px));
            }

            .transfer-edit-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 18px;
                padding: 18px 22px;
                border-bottom: 1px solid var(--transfer-edit-border);
                background: rgba(17, 165, 13, 0.08);
            }

            .transfer-edit-title-block {
                min-width: 0;
            }

            .transfer-edit-kicker {
                color: var(--transfer-edit-primary);
                font-size: 12px;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin-bottom: 4px;
            }

            .transfer-edit-title {
                margin: 0;
                color: #fff;
                font-size: 23px;
                line-height: 1.15;
            }

            .transfer-edit-subtitle {
                margin-top: 5px;
                color: var(--transfer-edit-muted);
                font-size: 13px;
            }

            .transfer-edit-close-form {
                margin: 0;
                flex: 0 0 auto;
            }

            .transfer-edit-close {
                width: 38px;
                height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #2b2b2b;
                color: #fff;
                border: 1px solid var(--transfer-edit-border);
                border-radius: 999px;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
            }

            .transfer-edit-close:hover {
                background: rgba(255, 82, 82, 0.12);
                color: var(--transfer-edit-danger);
                border-color: rgba(255, 82, 82, 0.6);
            }

            .transfer-edit-form {
                margin: 0;
            }

            .transfer-edit-body {
                max-height: calc(100vh - 112px);
                overflow-y: auto;
                padding: 20px;
                box-sizing: border-box;
            }

            .transfer-edit-layout {
                display: grid;
                gap: 18px;
                align-items: start;
            }

            .transfer-edit-layout--with-receipt {
                grid-template-columns: minmax(420px, 1.35fr) minmax(360px, 0.95fr);
            }

            .transfer-edit-layout--no-receipt {
                grid-template-columns: 1fr;
            }

            .transfer-edit-card {
                background: rgba(32, 32, 32, 0.96);
                border: 1px solid var(--transfer-edit-border);
                border-radius: 12px;
                box-sizing: border-box;
            }

            .transfer-edit-receipt-card {
                overflow: hidden;
            }

            .transfer-edit-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 13px 15px;
                border-bottom: 1px solid var(--transfer-edit-border);
                background: rgba(255, 255, 255, 0.025);
            }

            .transfer-edit-card-title {
                margin: 0;
                color: #fff;
                font-size: 15px;
                font-weight: 800;
            }

            .transfer-edit-card-note {
                color: var(--transfer-edit-muted);
                font-size: 12px;
                white-space: nowrap;
            }

            .transfer-edit-receipt-preview {
                height: min(72vh, 760px);
                min-height: 520px;
                background: #111;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
            }

            .transfer-edit-receipt-img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                display: block;
                background: #111;
            }

            .transfer-edit-receipt-frame {
                width: 100%;
                height: 100%;
                border: 0;
                display: block;
                background: #111;
            }

            .transfer-edit-receipt-fallback {
                padding: 24px;
                text-align: center;
                color: var(--transfer-edit-muted);
                line-height: 1.5;
            }

            .transfer-edit-receipt-actions {
                display: flex;
                justify-content: center;
                padding: 12px 14px;
                border-top: 1px solid var(--transfer-edit-border);
                background: #1c1c1c;
            }

            .transfer-edit-link-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 13px;
                border: 1px solid var(--transfer-edit-border-strong);
                border-radius: 8px;
                background: rgba(17, 165, 13, 0.12);
                color: #fff;
                font-size: 13px;
                font-weight: 800;
                text-decoration: none;
            }

            .transfer-edit-link-button:hover {
                background: var(--transfer-edit-primary);
                color: #000;
            }

            .transfer-edit-data-card {
                padding: 16px;
            }

            .transfer-edit-fields {
                display: flex;
                flex-direction: column;
                gap: 13px;
            }

            .transfer-edit-field {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .transfer-edit-label {
                color: var(--transfer-edit-muted);
                font-size: 12px;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .transfer-edit-input,
            .transfer-edit-select {
                width: 100%;
                box-sizing: border-box;
                background: var(--transfer-edit-field);
                color: #fff;
                border: 1px solid var(--transfer-edit-border);
                border-radius: 8px;
                padding: 10px 11px;
                font: inherit;
                font-size: 14px;
                outline: none;
            }

            .transfer-edit-input:focus,
            .transfer-edit-select:focus {
                border-color: var(--transfer-edit-primary);
                box-shadow: 0 0 0 3px rgba(17, 165, 13, 0.16);
            }

            .transfer-edit-readonly {
                min-height: 20px;
                background: var(--transfer-edit-field);
                color: #fff;
                border: 1px solid var(--transfer-edit-border);
                border-radius: 8px;
                padding: 10px 11px;
                font-size: 14px;
                line-height: 1.35;
                word-break: break-word;
            }

            .transfer-edit-readonly--muted {
                color: var(--transfer-edit-muted);
            }

            .transfer-edit-duo {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .transfer-edit-file-input {
                padding: 9px;
                cursor: pointer;
            }

            .transfer-edit-file-current {
                color: var(--transfer-edit-muted);
                font-size: 12px;
                line-height: 1.35;
            }

            .transfer-edit-divider {
                height: 1px;
                background: var(--transfer-edit-border);
                margin: 4px 0;
            }

            .transfer-edit-changelog {
                background: #101b10;
                border: 1px solid rgba(17, 165, 13, 0.35);
                color: #e8ffe7;
                border-radius: 10px;
                padding: 11px;
                max-height: 210px;
                overflow-y: auto;
                box-sizing: border-box;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 12px;
                line-height: 1.45;
                white-space: pre-wrap;
                text-align: left;
            }

            .transfer-edit-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid var(--transfer-edit-border);
            }

            .transfer-edit-save {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 150px;
                border: 1px solid var(--transfer-edit-primary);
                border-radius: 9px;
                background: var(--transfer-edit-primary);
                color: #000;
                padding: 10px 16px;
                font-size: 15px;
                font-weight: 900;
                cursor: pointer;
                transition: filter 0.18s ease, transform 0.08s ease;
            }

            .transfer-edit-save:hover {
                filter: brightness(1.12);
            }

            .transfer-edit-save:active {
                transform: translateY(1px);
            }

            @media (max-width: 980px) {
                .transfer-edit-layout--with-receipt {
                    grid-template-columns: 1fr;
                }

                .transfer-edit-receipt-preview {
                    height: 56vh;
                    min-height: 360px;
                }
            }

            @media (max-width: 620px) {
                .transfer-edit-modal,
                .transfer-edit-modal--no-receipt {
                    width: calc(100vw - 18px);
                    max-height: calc(100vh - 18px);
                }

                .transfer-edit-header {
                    padding: 14px 15px;
                }

                .transfer-edit-body {
                    padding: 12px;
                    max-height: calc(100vh - 92px);
                }

                .transfer-edit-duo {
                    grid-template-columns: 1fr;
                }

                .transfer-edit-receipt-preview {
                    height: 48vh;
                    min-height: 280px;
                }
            }
        </style>
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

    $rechnungspfad = null;
    if (isset($_FILES['rechnung_neu']) && $_FILES['rechnung_neu']['error'] === 0) {
        $upload_dir = 'rechnungen/';
        $original_name = $_FILES['rechnung_neu']['name'];

        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $basename = pathinfo($original_name, PATHINFO_FILENAME);

        $base = str_replace(' ', '_', $basename);
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '', $base);

        $max_basename_len = 200;
        $base = substr($base, 0, $max_basename_len);
        $filename = $base . '.' . $extension;
        $zielpfad = $upload_dir . $filename;

        $counter = 1;
        while (file_exists($zielpfad)) {
            $suffix = '_' . $counter;
            $cut_base = substr($base, 0, $max_basename_len - strlen($suffix));
            $filename = $cut_base . $suffix . '.' . $extension;
            $zielpfad = $upload_dir . $filename;
            $counter++;
        }

        if (move_uploaded_file($_FILES['rechnung_neu']['tmp_name'], $zielpfad)) {
            $rechnungspfad = $zielpfad;
        }
    }

    $konto = ($betrag >= 0) ? 4 : 8;
    $kasse = isset($_POST['kasse_id']) ? intval($_POST['kasse_id']) : 1;
    $agent = $_SESSION["uid"];

    $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agent . "\n";
    $changelog .= "Insert durch Transfers.php\n";

    $sql = "INSERT INTO transfers (uid, beschreibung, betrag, konto, kasse, tstamp, changelog, pfad, agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dsdiiissi", $uid, $beschreibung, $betrag, $konto, $kasse, $zeit, $changelog, $rechnungspfad, $agent);

    $stmt->execute();

    $stmt->close();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}


// CSV-Import-Handler mit hartem Cutoff + Counter-basierter Duplikatprüfung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_sparkasse_csv'])) {
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

    $CUTOFF_TS = mktime(0, 0, 0, 11, 9, 2025);

    $NETZ_IBAN = 'DE90390500001070334600';
    $HAUS_IBAN = 'DE37390500001070334584';

    $kasseMap = [
        $NETZ_IBAN => 72,
        $HAUS_IBAN => 92,
    ];
    $netzkontoMap = [
        $NETZ_IBAN => 1,
        $HAUS_IBAN => 0,
    ];

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

    $moneyKey = function ($x): string {
        $v = round((float)$x, 2);
        return number_format($v, 2, '.', '');
    };

    $existingKnown   = []; // key => Anzahl vorhandener DB-Einträge: iban|kasse|tstamp|betrag2
    $existingUnknown = []; // key => Anzahl vorhandener DB-Einträge: iban|tstamp|betrag2|betreff_norm

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
        $existingKnown[$k] = ($existingKnown[$k] ?? 0) + 1;
    }
    $prefKnown->close();

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
        $existingUnknown[$k] = ($existingUnknown[$k] ?? 0) + 1;
    }
    $prefUnk->close();

    $insKnown = $conn->prepare(
        'INSERT INTO transfers (uid, iban, tstamp, beschreibung, konto, kasse, betrag, agent, changelog) 
        VALUES (?, ?, ?, ?, 4, ?, ?, ?, ?)'
    );

    $insUnknown = $conn->prepare(
        'INSERT INTO unknowntransfers (uid, tstamp, name, betreff, betrag, netzkonto, iban, agent, status) 
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 0)'
    );

    $execOrFail = function (mysqli_stmt $st) use ($conn) {
        if (!$st->execute()) {
            $err = $st->error ?: 'stmt_execute_failed';
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $err]);
            exit;
        }
    };

    $seenKnown   = []; // key => Vorkommen im aktuellen CSV-Batch
    $seenUnknown = []; // key => Vorkommen im aktuellen CSV-Batch

    $agent = (int)($_SESSION['uid'] ?? 0);
    $nowStr = date('d.m.Y H:i');
    $inserted = 0; $skipped = 0; $unknown = 0;
    $conn->begin_transaction();

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

        if (!isset($kasseMap[$auftragskonto])) { $skipped++; continue; }

        $betrag = $parseAmount($betragStr);

        if (
            ($auftragskonto === $NETZ_IBAN || $auftragskonto === $HAUS_IBAN) &&
            stripos($verwendung, 'abmeldung') !== false &&
            $betrag < 0
        ) {
            $skipped++;
            continue;
        }

        $isInterAccount =
            ($auftragskonto === $NETZ_IBAN && $iban === $HAUS_IBAN) ||
            ($auftragskonto === $HAUS_IBAN && $iban === $NETZ_IBAN);
        if ($isInterAccount && abs($betrag) > 1000) {
            $skipped++;
            continue;
        }

        $isEntgeltabschluss = (stripos($buchungstext, 'ENTGELTABSCHLUSS') !== false);
        $isPaypalTransfer   = (
            $auftragskonto === $NETZ_IBAN &&
            stripos($name, 'paypal europe') !== false
        );

        if ($betrag <= 0.0 && !$isEntgeltabschluss) {
            $skipped++;
            continue;
        }

        $tstamp = $parseDate($valutaStr !== '' ? $valutaStr : $buchungStr);
        if ($tstamp < $CUTOFF_TS) { $skipped++; continue; }

        $kasse     = $kasseMap[$auftragskonto];
        $netzkonto = $netzkontoMap[$auftragskonto];

        $uid = null;
        $beschreibung = 'Transfer';

        if ($isEntgeltabschluss) {
            $beschreibung = 'Entgeltabschluss';
            if ($auftragskonto === $NETZ_IBAN) {
                $uid = 472;
            } elseif ($auftragskonto === $HAUS_IBAN) {
                $uid = 492;
            }
        } elseif ($isPaypalTransfer) {
            $uid = 472;
            $beschreibung = 'Kassenausgleich PayPal';
        }

        if ($uid === null && preg_match('/W\s*(\d{1,6})\s*H(?!\d)/iu', $verwendung, $m)) {
            $uid = (int)$m[1];
        }

        if ($uid !== null) {
            $betrag2 = round($betrag, 2);
            $keyKnown = $iban . '|' . $kasse . '|' . $tstamp . '|' . $moneyKey($betrag2);

            $seenKnown[$keyKnown] = ($seenKnown[$keyKnown] ?? 0) + 1;
            $knownOccurrenceNo = $seenKnown[$keyKnown];

            if (($existingKnown[$keyKnown] ?? 0) >= $knownOccurrenceNo) {
                $skipped++;
                continue;
            }

            $changelog = "[{$nowStr}] CSV-Import durch Agent {$agent}\nQuelle: Sparkasse CSV";

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

            if ($isPaypalTransfer) {
                $kassePaypal  = 69;
                $betragPaypal = round(-$betrag2, 2);
                $keyPaypal    = $iban . '|' . $kassePaypal . '|' . $tstamp . '|' . $moneyKey($betragPaypal);

                $seenKnown[$keyPaypal] = ($seenKnown[$keyPaypal] ?? 0) + 1;
                $paypalOccurrenceNo = $seenKnown[$keyPaypal];

                if (($existingKnown[$keyPaypal] ?? 0) < $paypalOccurrenceNo) {
                    $insKnown->bind_param(
                        'isisidis',
                        $uid,
                        $iban,
                        $tstamp,
                        $beschreibung,
                        $kassePaypal,
                        $betragPaypal,
                        $agent,
                        $changelog
                    );
                    $execOrFail($insKnown);
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

        } else {
            $betrag2 = round($betrag, 2);
            $betreffNorm = $verwendung ?? '';
            $keyUnk = $iban . '|' . $tstamp . '|' . $moneyKey($betrag2) . '|' . $betreffNorm;

            $seenUnknown[$keyUnk] = ($seenUnknown[$keyUnk] ?? 0) + 1;
            $unknownOccurrenceNo = $seenUnknown[$keyUnk];

            if (($existingUnknown[$keyUnk] ?? 0) >= $unknownOccurrenceNo) {
                $skipped++;
                continue;
            }

            $insUnknown->bind_param('issdisi', $tstamp, $name, $verwendung, $betrag2, $netzkonto, $iban, $agent);
            $execOrFail($insUnknown);
            $unknown++;
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
    if (empty($admin)) {
        header("Location: denied.php");
        exit;
    }

    $transfer_id = $_POST['transfer_id'];
    $selected_user = $_POST['selected_user'];
    $konto = $_POST['konto_update'];
    $kasse = $_POST['kasse'];
    $betrag = $_POST['betrag'];
    $beschreibung = $_POST['beschreibung'];
    $zeit = time();
    $agent = $_SESSION['uid'];
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

    $ausgangs_uid = $_POST['ausgangs_uid'];
    $ausgangs_konto = $_POST['ausgangs_konto'];
    $ausgangs_kasse = $_POST['ausgangs_kasse'];
    $ausgangs_betrag = $_POST['ausgangs_betrag'];
    $ausgangs_beschreibung = $_POST['ausgangs_beschreibung'];
    $ausgangs_pfad = $_POST['ausgangs_pfad'];

    $pfad = $ausgangs_pfad;

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
    
            while (file_exists($target_path)) {
                $new_filename = $base . '_' . $counter . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                $counter++;
            }
    
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
    
            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path;
            } else {
                echo "Fehler beim Verschieben der Datei.";
            }
        } else {
            echo "Ungültiges Dateiformat.";
        }
    }

    function formatBetrag($betrag) {
        if (strpos($betrag, ',') !== false) {
            $betrag = str_replace('.', '', $betrag);
            $betrag = str_replace(',', '.', $betrag);
        }
        return $betrag;
    }
        
    $formatierter_ausgangsbetrag = formatBetrag($ausgangs_betrag);
    $formatierter_betrag = formatBetrag($betrag);
       
    $has_changes = false;
    $changelog = "";

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
        $stmt->bind_param("iiidsisisi", $selected_user, $konto, $kasse, $formatierter_betrag, $beschreibung, $agent, $pfad, $new_tstamp, $changelog, $transfer_id);
        $stmt->execute();
        $stmt->close();
    }
}

load_menu();


if (isset($_POST['kasse_id'])) {
    $_SESSION['kasse_id'] = $_POST['kasse_id'];
}

if (!isset($_SESSION['kasse_id'])) {
    $_SESSION['kasse_id'] = 72;
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

$month = date('m', $semester_start);
$year = date('Y', $semester_start);

if ($month == 4) {
    $semester_ende = strtotime("01-10-$year");
} else {
    $semester_ende = strtotime("01-04-" . ($year + 1));
}

$current_start = unixtime2startofsemester($zeit);

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

$REGULAR_KASSEN = [72, 69, 92, 1, 2, 93, 94, 95];
$showKontostand = in_array((int)$kid, $REGULAR_KASSEN, true);
$showCsvImport  = (!empty($admin) && in_array((int)$kid, [72, 92], true));

echo '<div class="transfers_x_rightbar">';
echo '  <div class="transfers_x_rightgrid">';

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
    echo '<div class="transfer-form-grid">';

    echo '<div style="display: flex; gap: 6px; align-items: center;">';
    echo '<input type="text" name="usersuche" id="usersuche" placeholder="Nutzer suchen..." oninput="sucheUser(this.value)" style="flex:1;">';
    echo '<div style="display: flex; gap: 4px;">';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(472, \'NetzAG Dummy\')" title="NetzAG Dummy">Netz</button>';
    echo '<button type="button" class="dummy-btn" onclick="setDummyUser(492, \'Haussprecher Dummy\')" title="Haussprecher Dummy">Haus</button>';
    echo '</div>';
    echo '</div>';

    echo '<input type="number" name="betrag_neu" placeholder="Betrag (€)" step="0.01">';
    echo '<input type="file" name="rechnung_neu" accept=".pdf,.jpg,.jpeg,.png,.gif">';

    echo '<div id="usersuchergebnisse" style="padding: 6px; background-color: #2a2a2a; border: 1px solid #444; min-height: 75px;"></div>';
    echo '<input type="text" name="beschreibung_neu" placeholder="Beschreibung">';
    echo '<button type="submit" name="transfer_upload_speichern">Speichern</button>';

    echo '<input type="hidden" name="uid_neu" id="uid_neu">';
    echo '<input type="hidden" name="kasse_id" value="' . intval($kid) . '">';

    echo '</div>';
    echo '</form>';
    echo '<hr>';
}



$kid = (int)$kid;

$BASE_NOT_IN = "t.kasse NOT IN (1,2,69,72,92,93,94,95)";

if ($kid === -11) {
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
    $transfer_id_for_modal = (int)($_POST['transfer_id'] ?? 0);

    $query = "SELECT t.uid, t.konto, t.kasse, t.betrag, t.tstamp, t.beschreibung, t.changelog, t.pfad FROM transfers t WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $transfer_id_for_modal);
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

    $h = function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $konto_display = $konto_options[(int)$selected_transfer_konto] ?? "Undefiniertes Konto";
    $kasse_display = $kasse_options[(int)$selected_transfer_kasse] ?? "Undefinierte Kasse";
    $beschreibung_value = !is_null($selected_transfer_beschreibung) ? (string)$selected_transfer_beschreibung : '';
    $changelog_value = !is_null($selected_transfer_changelog) ? (string)$selected_transfer_changelog : 'Kein Changelog verfügbar';
    $date_value = date('Y-m-d', (int)$selected_transfer_tstamp);
    $time_value = date('H:i:s', (int)$selected_transfer_tstamp);
    $date_display = date("d.m.Y H:i", (int)$selected_transfer_tstamp) . ' (' . (int)$selected_transfer_tstamp . ')';
    $betrag_display = number_format((float)$selected_transfer_betrag, 2, ",", ".") . ' €';
    $betrag_input = number_format((float)$selected_transfer_betrag, 2, ",", ".");
    $has_receipt = trim((string)$selected_transfer_pfad) !== '';
    $receipt_path = (string)$selected_transfer_pfad;
    $receipt_url_path = parse_url($receipt_path, PHP_URL_PATH);
    $receipt_ext = strtolower(pathinfo($receipt_url_path ?: $receipt_path, PATHINFO_EXTENSION));
    $receipt_is_pdf = ($receipt_ext === 'pdf');
    $receipt_is_image = in_array($receipt_ext, ['jpg', 'jpeg', 'png', 'gif'], true);
    $modal_class = $has_receipt ? 'transfer-edit-modal' : 'transfer-edit-modal transfer-edit-modal--no-receipt';
    $layout_class = $has_receipt ? 'transfer-edit-layout transfer-edit-layout--with-receipt' : 'transfer-edit-layout transfer-edit-layout--no-receipt';

    $selected_user_name = '';
    $selected_user_room = '';
    $selected_user_turm = '';
    $query_user = "SELECT name, room, turm FROM users WHERE uid = ?";
    $stmt_user = $conn->prepare($query_user);
    $stmt_user->bind_param("i", $selected_transfer_uid);
    $stmt_user->execute();
    $stmt_user->bind_result($selected_user_name, $selected_user_room, $selected_user_turm);
    $stmt_user->fetch();
    $stmt_user->close();
    $selected_user_turm_display = ($selected_user_turm === 'tvk') ? 'TvK' : strtoupper((string)$selected_user_turm);
    $selected_user_display = trim((string)$selected_user_name) !== ''
        ? $selected_user_name . ' [' . $selected_user_turm_display . ' ' . $selected_user_room . ']'
        : 'UID ' . (int)$selected_transfer_uid;

    echo '<div class="overlay transfer-edit-overlay"></div>';
    echo '<div class="' . $modal_class . '" role="dialog" aria-modal="true" aria-labelledby="transfer-edit-title">';

    echo '  <div class="transfer-edit-header">';
    echo '    <div class="transfer-edit-title-block">';
    echo '      <div class="transfer-edit-kicker">Transfer bearbeiten</div>';
    echo '      <h2 id="transfer-edit-title" class="transfer-edit-title">Transfer #' . (int)$transfer_id_for_modal . '</h2>';
    echo '      <div class="transfer-edit-subtitle">' . $h($selected_user_display) . ' · ' . $h($betrag_display) . '</div>';
    echo '    </div>';
    echo '    <form method="post" class="transfer-edit-close-form">';
    echo '      <button type="submit" name="close" value="close" class="transfer-edit-close" aria-label="Modal schließen">&times;</button>';
    echo '    </form>';
    echo '  </div>';

    if ($admin) {
        echo '<form method="post" enctype="multipart/form-data" class="transfer-edit-form">';
        echo '<input type="hidden" name="transfer_id" value="' . (int)$transfer_id_for_modal . '">';
        echo '<input type="hidden" name="ausgangs_betrag" value="' . $h(number_format((float)$selected_transfer_betrag, 2, ".", "")) . '">';
        echo '<input type="hidden" name="ausgangs_uid" value="' . (int)$selected_transfer_uid . '">';
        echo '<input type="hidden" name="ausgangs_konto" value="' . (int)$selected_transfer_konto . '">';
        echo '<input type="hidden" name="ausgangs_kasse" value="' . (int)$selected_transfer_kasse . '">';
        echo '<input type="hidden" name="ausgangs_beschreibung" value="' . $h($beschreibung_value) . '">';
        echo '<input type="hidden" name="ausgangs_pfad" value="' . $h($receipt_path) . '">';
        echo '<input type="hidden" name="ausgangs_tstamp" value="' . (int)$selected_transfer_tstamp . '">';
        echo '<input type="hidden" name="ausgangs_hour" value="' . (int)date('H', (int)$selected_transfer_tstamp) . '">';
        echo '<input type="hidden" name="ausgangs_min" value="' . (int)date('i', (int)$selected_transfer_tstamp) . '">';
        echo '<input type="hidden" name="ausgangs_sec" value="' . (int)date('s', (int)$selected_transfer_tstamp) . '">';
    } else {
        echo '<div class="transfer-edit-form transfer-edit-form--readonly">';
    }

    echo '  <div class="transfer-edit-body">';
    echo '    <div class="' . $layout_class . '">';

    if ($has_receipt) {
        $safe_path = $h($receipt_path);
        echo '      <section class="transfer-edit-card transfer-edit-receipt-card">';
        echo '        <div class="transfer-edit-card-header">';
        echo '          <h3 class="transfer-edit-card-title">Rechnung</h3>';
        echo '          <span class="transfer-edit-card-note">' . $h(strtoupper($receipt_ext ?: 'Datei')) . '</span>';
        echo '        </div>';
        echo '        <div class="transfer-edit-receipt-preview">';

        if ($receipt_is_image) {
            echo '          <img src="' . $safe_path . '" alt="Rechnung zu Transfer #' . (int)$transfer_id_for_modal . '" class="transfer-edit-receipt-img">';
        } elseif ($receipt_is_pdf) {
            echo '          <object data="' . $safe_path . '#toolbar=1&navpanes=0" type="application/pdf" class="transfer-edit-receipt-frame">';
            echo '            <div class="transfer-edit-receipt-fallback">PDF-Vorschau konnte nicht geladen werden.<br><a href="' . $safe_path . '" target="_blank" rel="noopener">Rechnung in neuem Tab öffnen</a></div>';
            echo '          </object>';
        } else {
            echo '          <div class="transfer-edit-receipt-fallback">Für diesen Dateityp ist keine eingebettete Vorschau verfügbar.</div>';
        }

        echo '        </div>';
        echo '        <div class="transfer-edit-receipt-actions">';
        echo '          <a href="' . $safe_path . '" target="_blank" rel="noopener" class="transfer-edit-link-button">Rechnung separat öffnen</a>';
        echo '        </div>';
        echo '      </section>';
    }

    echo '      <section class="transfer-edit-card transfer-edit-data-card">';
    echo '        <div class="transfer-edit-fields">';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Benutzer</label>';
    if ($admin) {
        echo '            <select name="selected_user" id="selected_user" class="transfer-edit-select">';
        echo '              <option value="" disabled>Wähle einen Benutzer</option>';
        echo '              <option value="472" ' . (472 == (int)$selected_transfer_uid ? 'selected' : '') . '>NetzAG-Dummy</option>';
        echo '              <option value="492" ' . (492 == (int)$selected_transfer_uid ? 'selected' : '') . '>Vorstand-Dummy</option>';

        $sql = "SELECT uid, name, room, turm
                FROM users
                ORDER BY pid, FIELD(turm, 'weh', 'tvk'), room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $room, $turm);

        while (mysqli_stmt_fetch($stmt)) {
            $formatted_turm = ($turm === 'tvk') ? 'TvK' : strtoupper((string)$turm);
            $option_text = $name . ' [' . $formatted_turm . ' ' . $room . ']';
            echo '              <option value="' . (int)$uid . '" ' . ((int)$uid == (int)$selected_transfer_uid ? 'selected' : '') . '>' . $h($option_text) . '</option>';
        }
        mysqli_stmt_close($stmt);

        echo '            </select>';
    } else {
        echo '            <div class="transfer-edit-readonly">' . $h($selected_user_display) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Beschreibung</label>';
    if ($admin) {
        echo '            <input type="text" name="beschreibung" value="' . $h($beschreibung_value) . '" class="transfer-edit-input">';
    } else {
        echo '            <div class="transfer-edit-readonly">' . ($beschreibung_value !== '' ? $h($beschreibung_value) : '<span class="transfer-edit-readonly--muted">Keine Beschreibung</span>') . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Konto</label>';
    if ($admin) {
        echo '              <select name="konto_update" class="transfer-edit-select">';
        foreach ($konto_options as $key => $value) {
            echo '                <option value="' . (int)$key . '" ' . ((int)$key == (int)$selected_transfer_konto ? 'selected' : '') . '>' . $h($value) . '</option>';
        }
        echo '              </select>';
    } else {
        echo '              <div class="transfer-edit-readonly">' . $h($konto_display) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Kasse</label>';
    if ($admin) {
        echo '              <select name="kasse" class="transfer-edit-select">';
        foreach ($kasse_options as $key => $value) {
            echo '                <option value="' . (int)$key . '" ' . ((int)$key == (int)$selected_transfer_kasse ? 'selected' : '') . '>' . $h($value) . '</option>';
        }
        echo '              </select>';
    } else {
        echo '              <div class="transfer-edit-readonly">' . $h($kasse_display) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Datum</label>';
    if ($admin) {
        echo '              <input type="date" name="tstamp_date" value="' . $h($date_value) . '" class="transfer-edit-input">';
    } else {
        echo '              <div class="transfer-edit-readonly">' . $h(date("d.m.Y", (int)$selected_transfer_tstamp)) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Uhrzeit</label>';
    if ($admin) {
        echo '              <input type="time" name="tstamp_time" value="' . $h($time_value) . '" step="1" class="transfer-edit-input">';
    } else {
        echo '              <div class="transfer-edit-readonly">' . $h(date("H:i:s", (int)$selected_transfer_tstamp)) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Unix-Zeitstempel</label>';
    echo '            <div class="transfer-edit-readonly transfer-edit-readonly--muted">' . (int)$selected_transfer_tstamp . '</div>';
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Betrag</label>';
    if ($admin) {
        echo '            <input type="text" name="betrag" value="' . $h($betrag_input) . '" class="transfer-edit-input">';
    } else {
        echo '            <div class="transfer-edit-readonly">' . $h($betrag_display) . '</div>';
    }
    echo '          </div>';

    echo '          <div class="transfer-edit-field">';
    echo '            <label class="transfer-edit-label">Rechnung</label>';
    if ($admin) {
        echo '            <input type="file" name="rechnung_upload" accept=".pdf,.jpg,.jpeg,.png,.gif" class="transfer-edit-input transfer-edit-file-input">';
        echo '            <div class="transfer-edit-file-current">' . ($has_receipt ? 'Aktuell: ' . $h($receipt_path) : 'Aktuell ist keine Rechnung hinterlegt.') . '</div>';
    } else {
        if ($has_receipt) {
            echo '            <div class="transfer-edit-readonly"><a href="' . $h($receipt_path) . '" target="_blank" rel="noopener">Rechnung öffnen</a></div>';
        } else {
            echo '            <div class="transfer-edit-readonly transfer-edit-readonly--muted">Keine Rechnung hinterlegt</div>';
        }
    }
    echo '          </div>';

    if ($admin) {
        echo '          <div class="transfer-edit-divider"></div>';
        echo '          <div class="transfer-edit-field">';
        echo '            <label class="transfer-edit-label">Changelog</label>';
        echo '            <div class="transfer-edit-changelog">' . $h($changelog_value) . '</div>';
        echo '          </div>';

        echo '          <div class="transfer-edit-actions">';
        echo '            <button type="submit" name="save_transfer_id" class="transfer-edit-save">Speichern</button>';
        echo '          </div>';
    }

    echo '        </div>';
    echo '      </section>';
    echo '    </div>';
    echo '  </div>';

    if ($admin) {
        echo '</form>';
    } else {
        echo '</div>';
    }

    echo '</div>';
}

?>
<script>
const TRANSFERS_UI_STORAGE_KEY = <?= json_encode('transfers_ui_' . (int)$kid . '_' . (int)$semester_start, JSON_UNESCAPED_SLASHES) ?>;
let transferSortState = { col: null, asc: true };

function readTransfersUiState() {
    try {
        return JSON.parse(sessionStorage.getItem(TRANSFERS_UI_STORAGE_KEY) || '{}');
    } catch (e) {
        return {};
    }
}

function writeTransfersUiState(state) {
    try {
        sessionStorage.setItem(TRANSFERS_UI_STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
        // sessionStorage kann z.B. bei sehr restriktiven Browser-Einstellungen blockiert sein.
    }
}

function storeTransfersUiState(options = {}) {
    const previous = readTransfersUiState();
    const keepScroll = !!options.keepScroll;
    const selectedId = Object.prototype.hasOwnProperty.call(options, 'selectedId')
        ? options.selectedId
        : (previous.selectedId || null);

    const state = {
        sort: {
            col: transferSortState.col,
            asc: transferSortState.asc
        },
        scrollY: keepScroll && Number.isFinite(previous.scrollY) ? previous.scrollY : window.scrollY,
        selectedId: selectedId,
        updatedAt: Date.now()
    };

    writeTransfersUiState(state);
}

function initTransferHeaders() {
    const table = document.getElementById('transfers-table');
    if (!table || !table.tHead || !table.tHead.rows.length) return;

    const headers = table.tHead.rows[0].cells;
    for (let i = 0; i < headers.length; i++) {
        if (!headers[i].dataset.baseLabel) {
            headers[i].dataset.baseLabel = headers[i].innerText.replace(/[\u25B2\u25BC]/g, '').trim();
        }
    }
}

function getCellSortValue(row, colIndex) {
    const cell = row.cells[colIndex];
    if (!cell) return '';
    return (cell.dataset.sort ?? cell.innerText ?? '').toString().trim();
}

function parseSortNumber(value) {
    let normalized = String(value)
        .replace(/€/g, '')
        .replace(/\s/g, '')
        .trim();

    if (/^-?\d+(\.\d+)?$/.test(normalized)) {
        return Number(normalized);
    }

    if (/^-?[\d.]+,\d+$/.test(normalized)) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
        return Number(normalized);
    }

    return NaN;
}

function applyTransferSort(colIndex, ascending, headerEl = null, persist = true) {
    const table = document.getElementById('transfers-table');
    if (!table || !table.tBodies.length) return;

    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);

    rows.sort((a, b) => {
        const aVal = getCellSortValue(a, colIndex);
        const bVal = getCellSortValue(b, colIndex);
        const aNum = parseSortNumber(aVal);
        const bNum = parseSortNumber(bVal);

        let cmp;
        if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) {
            cmp = aNum - bNum;
        } else {
            cmp = aVal.localeCompare(bVal, 'de', { numeric: true, sensitivity: 'base' });
        }

        return ascending ? cmp : -cmp;
    });

    for (const row of rows) {
        tbody.appendChild(row);
    }

    initTransferHeaders();
    const headers = table.tHead?.rows?.[0]?.cells || [];
    for (let i = 0; i < headers.length; i++) {
        headers[i].innerText = headers[i].dataset.baseLabel || headers[i].innerText.replace(/[\u25B2\u25BC]/g, '').trim();
    }

    const activeHeader = headerEl || headers[colIndex];
    if (activeHeader) {
        activeHeader.innerText = (activeHeader.dataset.baseLabel || activeHeader.innerText.replace(/[\u25B2\u25BC]/g, '').trim()) + (ascending ? ' ▲' : ' ▼');
    }

    transferSortState = { col: colIndex, asc: !!ascending };

    if (persist) {
        storeTransfersUiState();
    }
}

function sortTable(colIndex, headerEl) {
    const ascending = transferSortState.col === colIndex ? !transferSortState.asc : true;
    applyTransferSort(colIndex, ascending, headerEl, true);
}

function restoreTransfersUiState() {
    initTransferHeaders();

    const state = readTransfersUiState();
    if (state.sort && Number.isInteger(state.sort.col)) {
        applyTransferSort(state.sort.col, state.sort.asc !== false, null, false);
    }

    if (state.selectedId) {
        const selectedRow = Array.from(document.querySelectorAll('#transfers-table tbody tr'))
            .find(row => row.dataset.id === String(state.selectedId));
        if (selectedRow) {
            selectedRow.classList.add('transfer-row-restored');
        }
    }

    if (Number.isFinite(state.scrollY)) {
        requestAnimationFrame(() => {
            window.scrollTo({ top: state.scrollY, left: 0, behavior: 'auto' });
        });
    }
}

function submitEditTransfer(row) {
    const id = row.dataset.id;
    storeTransfersUiState({ selectedId: id });

    const form = document.createElement('form');
    form.method = 'POST';

    const editFlag = document.createElement('input');
    editFlag.type = 'hidden';
    editFlag.name = 'edit_transfer';
    editFlag.value = '1';

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

    if (!ergebnisContainer || !hiddenUidField) return;

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
    const hiddenUidField = document.getElementById('uid_neu');
    const searchField = document.getElementById('usersuche');
    const results = document.getElementById('usersuchergebnisse');

    if (hiddenUidField) hiddenUidField.value = uid;
    if (searchField) searchField.value = name;
    if (results) results.innerHTML = '';
}

document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    const isEditSaveOrClose = !!form.querySelector('[name="save_transfer_id"], [name="close"]');
    storeTransfersUiState({ keepScroll: isEditSaveOrClose });
}, true);

document.addEventListener('DOMContentLoaded', function() {
    restoreTransfersUiState();

    const transferForm = document.getElementById('transfer-form');
    if (transferForm) {
        transferForm.addEventListener('submit', function(e) {
            const uid = document.getElementById('uid_neu')?.value.trim() || '';
            const betrag = document.querySelector('input[name="betrag_neu"]')?.value.trim() || '';

            if (!uid || !betrag) {
                alert("Bitte Nutzer auswählen und gültigen Betrag eingeben.");
                e.preventDefault();
            }
        });
    }
});

</script>

<script>
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