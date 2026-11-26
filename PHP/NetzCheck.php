<?php
// Netzwerk-AG: Übersicht über eingerichtete Zugänge.
// Nur diese Datei ist erforderlich; AJAX verwendet ausschließlich conn.php.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function netz_ist_mitglied(mysqli $conn, int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }

    $stmt = mysqli_prepare($conn, "
        SELECT FIND_IN_SET('7', REPLACE(COALESCE(`groups`, ''), ' ', ''))
        FROM users
        WHERE uid = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $mitglied);
    $gefunden = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (bool)$gefunden && (int)$mitglied > 0;
}

function netz_json(array $daten, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// AJAX muss VOR template.php behandelt werden, weil template.php HTML ausgibt.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['netz_ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        require_once __DIR__ . '/conn.php';
        mysqli_set_charset($conn, 'utf8mb4');

        $uid = (int)($_SESSION['uid'] ?? 0);
        $sessionGueltig = !empty($_SESSION['valid'])
            && !empty($_SESSION['NetzAG'])
            && $uid > 0
            && isset($_SESSION['ip'], $_SESSION['expired'])
            && hash_equals((string)$_SESSION['ip'], (string)($_SERVER['REMOTE_ADDR'] ?? ''))
            && (int)$_SESSION['expired'] > time()
            && netz_ist_mitglied($conn, $uid);

        if (!$sessionGueltig) {
            netz_json(['ok' => false, 'error' => 'Keine Berechtigung oder Sitzung abgelaufen.'], 403);
        }

        $token = (string)($_POST['csrf'] ?? '');
        if (!isset($_SESSION['netz_csrf']) || !hash_equals($_SESSION['netz_csrf'], $token)) {
            netz_json(['ok' => false, 'error' => 'Ungültige Anfrage. Seite neu laden.'], 403);
        }

        $aktion = (string)($_POST['aktion'] ?? '');

        if ($aktion === 'laden') {
            $eintraege = [];
            $result = mysqli_query($conn, '
                SELECT id, titel, beschreibung, sortierung
                FROM netz_items
                ORDER BY sortierung ASC, id ASC
            ');
            while ($row = mysqli_fetch_assoc($result)) {
                $row['id'] = (int)$row['id'];
                $row['sortierung'] = (int)$row['sortierung'];
                $eintraege[] = $row;
            }
            mysqli_free_result($result);

            // Aktuelle Mitglieder zuerst (nach starttime), danach Ehemalige mit offenen Haken.
            $mitglieder = [];
            $result = mysqli_query($conn, "
                SELECT u.uid, u.firstname, u.lastname, u.name, u.username, u.starttime,
                       COALESCE(p.alias, '') AS alias, COALESCE(p.publickey, '') AS publickey,
                       (FIND_IN_SET('7', REPLACE(COALESCE(u.`groups`, ''), ' ', '')) > 0) AS aktiv
                FROM users u
                LEFT JOIN netz_profile p ON p.uid = u.uid
                WHERE FIND_IN_SET('7', REPLACE(COALESCE(u.`groups`, ''), ' ', '')) > 0
                   OR EXISTS (SELECT 1 FROM netz_checks c WHERE c.uid = u.uid)
                ORDER BY aktiv DESC, (u.starttime = 0) ASC, u.starttime ASC, u.uid ASC
            ");
            while ($row = mysqli_fetch_assoc($result)) {
                $row['uid'] = (int)$row['uid'];
                $row['starttime'] = (int)$row['starttime'];
                $row['aktiv'] = (bool)$row['aktiv'];
                $mitglieder[] = $row;
            }
            mysqli_free_result($result);

            $haken = [];
            $result = mysqli_query($conn, '
                SELECT c.item_id, c.uid, c.gesetzt_am, c.gesetzt_von, u.name AS von_name
                FROM netz_checks c
                LEFT JOIN users u ON u.uid = c.gesetzt_von
            ');
            while ($row = mysqli_fetch_assoc($result)) {
                $row['item_id'] = (int)$row['item_id'];
                $row['uid'] = (int)$row['uid'];
                $row['gesetzt_von'] = (int)$row['gesetzt_von'];
                $haken[] = $row;
            }
            mysqli_free_result($result);

            netz_json([
                'ok' => true,
                'eintraege' => $eintraege,
                'mitglieder' => $mitglieder,
                'haken' => $haken
            ]);
        }

        if ($aktion === 'profil') {
            $zielUid = filter_var($_POST['uid'] ?? null, FILTER_VALIDATE_INT);
            $feld = $_POST['feld'] ?? '';
            $wert = trim((string)($_POST['wert'] ?? ''));
            if (!$zielUid || $zielUid <= 0 || !in_array($feld, ['alias', 'publickey'], true)) {
                netz_json(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
            }
            $stmt = mysqli_prepare($conn, "SELECT uid FROM users WHERE uid = ? AND (FIND_IN_SET('7', REPLACE(COALESCE(`groups`, ''), ' ', '')) > 0 OR EXISTS (SELECT 1 FROM netz_checks WHERE uid = users.uid))");
            mysqli_stmt_bind_param($stmt, 'i', $zielUid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $sichtbar = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            if (!$sichtbar) netz_json(['ok' => false, 'error' => 'Person nicht verfügbar.'], 403);
            if ($feld === 'alias') {
                if (strlen($wert) > 160 || preg_match('/[\r\n]/', $wert)) {
                    netz_json(['ok' => false, 'error' => 'Name zu lang oder ungültig.'], 400);
                }
                $sql = 'INSERT INTO netz_profile (uid, alias) VALUES (?, ?) ON DUPLICATE KEY UPDATE alias = VALUES(alias)';
            } else {
                // Nur öffentliche OpenSSH-Schlüssel, niemals private Schlüssel.
                if ($wert !== '') {
                    $teile = preg_split('/[ \t]+/', $wert, 3);
                    $blob = isset($teile[1]) ? base64_decode($teile[1], true) : false;
                    $typ = $teile[0];
                    if (strlen($wert) > 16384 || preg_match('/[\r\n]/', $wert) ||
                        !preg_match('/^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)$/D', $typ) ||
                        $blob === false || strlen($blob) < 4 + strlen($typ) ||
                        substr($blob, 0, 4) !== pack('N', strlen($typ)) || substr($blob, 4, strlen($typ)) !== $typ) {
                        netz_json(['ok' => false, 'error' => 'Bitte einen öffentlichen OpenSSH-Key einfügen.'], 400);
                    }
                }
                $sql = 'INSERT INTO netz_profile (uid, publickey) VALUES (?, ?) ON DUPLICATE KEY UPDATE publickey = VALUES(publickey)';
            }
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'is', $zielUid, $wert);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            netz_json(['ok' => true]);
        }

        if ($aktion === 'haken') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $zielUid = filter_var($_POST['uid'] ?? null, FILTER_VALIDATE_INT);
            $gesetzt = (string)($_POST['gesetzt'] ?? '');
            if (!$itemId || $itemId <= 0 || !$zielUid || $zielUid <= 0 || !in_array($gesetzt, ['0', '1'], true)) {
                netz_json(['ok' => false, 'error' => 'Ungültiger Eintrag.'], 400);
            }

            if ($gesetzt === '1') {
                // Ehemalige dürfen ausschließlich abgehakt, nicht erneut berechtigt werden.
                if (!netz_ist_mitglied($conn, (int)$zielUid)) {
                    netz_json(['ok' => false, 'error' => 'Für ehemalige Mitglieder können keine neuen Haken gesetzt werden.'], 403);
                }

                $stmt = mysqli_prepare($conn, '
                    INSERT INTO netz_checks (item_id, uid, gesetzt_von)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        gesetzt_von = VALUES(gesetzt_von),
                        gesetzt_am = CURRENT_TIMESTAMP
                ');
                mysqli_stmt_bind_param($stmt, 'iii', $itemId, $zielUid, $uid);
            } else {
                $stmt = mysqli_prepare($conn, 'DELETE FROM netz_checks WHERE item_id = ? AND uid = ?');
                mysqli_stmt_bind_param($stmt, 'ii', $itemId, $zielUid);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            netz_json(['ok' => true]);
        }

        if ($aktion === 'speichern') {
            $itemId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $titel = trim((string)($_POST['titel'] ?? ''));
            $beschreibung = trim((string)($_POST['beschreibung'] ?? ''));
            $titelLaenge = function_exists('mb_strlen') ? mb_strlen($titel, 'UTF-8') : strlen($titel);
            $beschreibungLaenge = function_exists('mb_strlen') ? mb_strlen($beschreibung, 'UTF-8') : strlen($beschreibung);

            if ($titel === '' || $titelLaenge > 160 || $beschreibungLaenge > 500) {
                netz_json(['ok' => false, 'error' => 'Bezeichnung (max. 160 Zeichen) und Beschreibung (max. 500 Zeichen) prüfen.'], 400);
            }

            if ($itemId && $itemId > 0) {
                $stmt = mysqli_prepare($conn, '
                    UPDATE netz_items SET titel = ?, beschreibung = ? WHERE id = ?
                ');
                mysqli_stmt_bind_param($stmt, 'ssi', $titel, $beschreibung, $itemId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                // Neue Einträge automatisch an die letzte Position setzen.
                $stmt = mysqli_prepare($conn, '
                    INSERT INTO netz_items (titel, beschreibung, sortierung)
                    SELECT ?, ?, COALESCE(MAX(sortierung), 0) + 1 FROM netz_items
                ');
                mysqli_stmt_bind_param($stmt, 'ss', $titel, $beschreibung);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            netz_json(['ok' => true]);
        }

        if ($aktion === 'loeschen') {
            $itemId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$itemId || $itemId <= 0) {
                netz_json(['ok' => false, 'error' => 'Ungültiger Eintrag.'], 400);
            }
            // Zugehörige Haken werden per Fremdschlüssel automatisch gelöscht.
            $stmt = mysqli_prepare($conn, 'DELETE FROM netz_items WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $itemId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            netz_json(['ok' => true]);
        }

        if ($aktion === 'sortieren') {
            $ids = json_decode((string)($_POST['ids'] ?? ''), true);
            if (!is_array($ids)) {
                netz_json(['ok' => false, 'error' => 'Ungültige Reihenfolge.'], 400);
            }

            $sortIds = [];
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    netz_json(['ok' => false, 'error' => 'Ungültige Eintrags-ID.'], 400);
                }
                $sortIds[] = $id;
            }
            if (count($sortIds) !== count(array_unique($sortIds))) {
                netz_json(['ok' => false, 'error' => 'Doppelte Einträge in der Reihenfolge.'], 400);
            }

            mysqli_begin_transaction($conn);
            try {
                // Verhindert, dass ein veralteter Browser Einträge unbemerkt überspringt.
                $vorhanden = [];
                $result = mysqli_query($conn, 'SELECT id FROM netz_items ORDER BY id FOR UPDATE');
                while ($row = mysqli_fetch_assoc($result)) {
                    $vorhanden[] = (int)$row['id'];
                }
                mysqli_free_result($result);

                $vergleich = $sortIds;
                sort($vergleich, SORT_NUMERIC);
                if ($vergleich !== $vorhanden) {
                    throw new RuntimeException('Die Liste wurde zwischenzeitlich verändert. Bitte neu laden.');
                }

                $stmt = mysqli_prepare($conn, 'UPDATE netz_items SET sortierung = ? WHERE id = ?');
                foreach ($sortIds as $index => $id) {
                    $sortierung = $index + 1;
                    mysqli_stmt_bind_param($stmt, 'ii', $sortierung, $id);
                    mysqli_stmt_execute($stmt);
                }
                mysqli_stmt_close($stmt);
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                throw $e;
            }
            netz_json(['ok' => true]);
        }

        netz_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    } catch (Throwable $e) {
        error_log('NetzZugaenge.php: ' . $e->getMessage());
        $meldung = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Datenbankfehler. Bitte erneut versuchen.';
        netz_json(['ok' => false, 'error' => $meldung], 500);
    }
}

// Normale Seitenansicht: Authentifizierung und Navigation über das vorhandene Template.
ob_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NetzAG Zugangscheckliste</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .netz-zugaenge {
            --netz-gruen: #11a50d;
            width: min(98%, 1800px);
            margin: 0 auto 55px;
            color: #f3f3f3;
            font-family: Arial, Helvetica, sans-serif;
        }
        .netz-zugaenge *, .netz-modal * { box-sizing: border-box; }
        .netz-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin: 4px 0 22px;
        }
        .netz-toolbar h1 { margin: 0; font-size: clamp(24px, 2.2vw, 34px); }
        .netz-toolbar-rechts { display: flex; align-items: center; gap: 12px; }
        .netz-aktion {
            border: 1px solid var(--netz-gruen);
            background: var(--netz-gruen);
            color: #081108;
            padding: 10px 18px;
            border-radius: 7px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s ease, border-color .15s ease;
        }
        .netz-aktion:hover { background: #28cc24; border-color: #28cc24; }
        .netz-aktion:disabled { opacity: .45; cursor: not-allowed; }
        .netz-aktion-sekundaer { background: #343434; color: white; border-color: #555; }
        .netz-aktion-sekundaer:hover { background: #484848; border-color: #6a6a6a; }
        .netz-aktion-loeschen { background: #3d2020; color: #ffc8c8; border-color: #8c4444; }
        .netz-aktion-loeschen:hover { background: #622c2c; border-color: #bc5757; }
        .netz-status { color: #ddd; font-size: 13px; min-height: 16px; }
        .netz-status.fehler { color: #ff9898; }
        .netz-tabellenrahmen { width: 100%; border: 1px solid #424242; border-radius: 9px; overflow: hidden; background: #242424; }
        .netz-tabelle { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; color: #eee; }
        .netz-tabelle col.netz-beschreibungsspalte { width: 28%; }
        .netz-tabelle th, .netz-tabelle td { border-right: 1px solid #414141; border-bottom: 1px solid #414141; }
        .netz-tabelle th:last-child, .netz-tabelle td:last-child { border-right: 0; }
        .netz-tabelle tbody tr:last-child td { border-bottom: 0; }
        .netz-tabelle thead th { background: #292929; }
        .netz-tabelle th.netz-erster-kopf { text-align: left; padding: 14px 16px; vertical-align: middle; font-size: 16px; }
        .netz-tabelle th.netz-person-kopf { padding: 14px 5px; vertical-align: middle; text-align: center; }
        .netz-person-name {
            display: block;
            font-weight: 600;
            font-size: clamp(12px, 1vw, 16px);
            line-height: 1.35;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .netz-name-button { background: none; border: 0; color: inherit; font: inherit; cursor: pointer; width: 100%; padding: 0; overflow-wrap: anywhere; }
        .netz-name-button:hover { color: var(--netz-gruen); }
        .netz-realname { display: block; font-size: 11px; font-weight: 400; color: #aaa; margin-top: 4px; }
        .netz-key-row th { padding: 7px 3px; text-align: center; }
        .netz-key-row th:first-child { text-align: left; padding-left: 16px; font-size: 14px; }
        .netz-key-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px; }
        .netz-key-button { border: 1px solid #555; border-radius: 4px; background: #343434; color: #eee; padding: 5px 8px; font-size: 12px; cursor: pointer; }
        .netz-key-button:hover { background: var(--netz-gruen); border-color: var(--netz-gruen); color: #081108; }
        .netz-key-button.netz-key-new { border-color: var(--netz-gruen); color: var(--netz-gruen); }
        .netz-key-button.netz-key-new:hover { color: #081108; }
        .netz-tabelle tbody tr:nth-child(odd) td { background: #292929; }
        .netz-tabelle tbody tr:nth-child(even) td { background: #202020; }
        .netz-tabelle tbody tr:hover td { background: #303d30; }
        .netz-tabelle td.netz-eintrag { padding: 0 12px; height: 47px; text-align: left; }
        .netz-eintrag-inhalt { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .netz-griff {
            flex: none; display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 32px; color: #8d8d8d;
            cursor: grab; user-select: none; font-size: 23px; line-height: 1;
        }
        .netz-griff:hover { color: var(--netz-gruen); }
        .netz-griff:active { cursor: grabbing; }
        .netz-eintrag-button {
            min-width: 0; width: 100%; padding: 11px 0; border: 0;
            text-align: left; background: transparent; color: #f0f0f0;
            font: inherit; font-size: clamp(13px, 1.1vw, 17px);
            overflow-wrap: anywhere; cursor: pointer;
        }
        .netz-eintrag-button:hover, .netz-eintrag-button:focus-visible { color: var(--netz-gruen); }
        .netz-tabelle td.netz-feld { height: 47px; padding: 0; text-align: center; }
        .netz-feld-label { display: flex; width: 100%; height: 100%; min-height: 47px; align-items: center; justify-content: center; cursor: pointer; }
        .netz-feld-label:hover { background: rgba(17, 165, 13, .2); }
        .netz-haken { width: 19px; height: 19px; min-width: 14px; margin: 0; accent-color: var(--netz-gruen); cursor: pointer; }
        .netz-haken:disabled { cursor: not-allowed; opacity: .6; }
        .netz-tabelle .netz-ehemalig { background-color: #353535; color: #a6a6a6; }
        .netz-tabelle tbody tr:nth-child(odd) td.netz-ehemalig { background: #323232; }
        .netz-tabelle tbody tr:nth-child(even) td.netz-ehemalig { background: #2c2c2c; }
        .netz-tabelle tbody tr:hover td.netz-ehemalig { background: #414141; }
        .netz-tabelle .netz-ehemalig-beginn { border-left: 3px solid #858585; }
        .netz-tabelle tr.netz-ziehen td { opacity: .5; }
        .netz-leer { padding: 32px; color: #bdbdbd; text-align: center; }
        .netz-modal-hintergrund { position: fixed; inset: 0; background: rgba(0,0,0,.78); z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .netz-modal-hintergrund[hidden] { display: none; }
        .netz-modal {
            --netz-gruen: #11a50d;
            width: min(460px, 100%); max-height: 95vh; overflow-y: auto;
            background: #242424; border: 1px solid #515151; border-radius: 11px;
            padding: 24px; box-shadow: 0 15px 55px rgba(0,0,0,.6); color: white;
            font-family: Arial, Helvetica, sans-serif;
        }
        .netz-modal-kopf { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 21px; }
        .netz-modal h2 { margin: 0; color: white; font-size: 25px; }
        .netz-modal-schliessen { background: none; border: 0; color: #ccc; font-size: 27px; cursor: pointer; padding: 0 4px; }
        .netz-modal-schliessen:hover { color: #fff; }
        .netz-modal label { display: block; margin: 0 0 7px; color: #ddd; font-size: 14px; }
        .netz-modal input, .netz-modal textarea {
            width: 100%; border: 1px solid #555; border-radius: 6px;
            background: #181818; color: white; padding: 11px;
            font: inherit; font-size: 16px; margin: 0 0 18px;
        }
        .netz-modal textarea { resize: vertical; min-height: 90px; }
        .netz-modal input:focus, .netz-modal textarea:focus { outline: none; border-color: #11a50d; }
        .netz-modal-fuss { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
        .netz-modal-fuss .netz-aktion-loeschen { margin-right: auto; }
        .netz-modal-fehler { color: #ff9898; min-height: 18px; font-size: 13px; margin: 0 0 10px; }
        @media (max-width: 650px) {
            .netz-zugaenge { width: 99%; }
            .netz-tabelle col.netz-beschreibungsspalte { width: 40%; }
            .netz-tabelle th.netz-erster-kopf { padding: 10px 6px; }
            .netz-tabelle td.netz-eintrag { padding: 0 4px; }
            .netz-eintrag-inhalt { gap: 3px; }
            .netz-griff { width: 17px; font-size: 18px; }
            .netz-tabelle th.netz-person-kopf { padding: 8px 2px; }
            .netz-person-name { font-size: 10px; }
            .netz-haken { width: 15px; height: 15px; }
        }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8mb4');

if (!auth($conn) || empty($_SESSION['NetzAG']) || !netz_ist_mitglied($conn, (int)($_SESSION['uid'] ?? 0))) {
    ob_end_clean();
    header('Location: denied.php');
    exit;
}

if (!isset($_SESSION['netz_csrf'])) {
    $_SESSION['netz_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['netz_csrf'];
load_menu();
?>
<main class="netz-zugaenge">
    <div class="netz-toolbar">
        <h1>NetzAG Zugangscheckliste</h1>
        <div class="netz-toolbar-rechts">
            <span class="netz-status" id="netz-status" role="status"></span>
            <button type="button" class="netz-aktion" id="netz-neu">+ Eintrag</button>
        </div>
    </div>

    <div class="netz-tabellenrahmen" id="netz-tabellenrahmen">
        <table class="netz-tabelle" id="netz-tabelle">
            <colgroup id="netz-spalten"><col class="netz-beschreibungsspalte"></colgroup>
            <thead><tr id="netz-kopf"><th class="netz-erster-kopf" scope="col">Zugang / Berechtigung</th></tr><tr class="netz-key-row" id="netz-key-row"></tr></thead>
            <tbody id="netz-zeilen"></tbody>
        </table>
        <div class="netz-leer" id="netz-leer">Lade Einträge …</div>
    </div>
</main>

<div class="netz-modal-hintergrund" id="netz-modal-hintergrund" hidden>
    <div class="netz-modal" role="dialog" aria-modal="true" aria-labelledby="netz-modal-titel">
        <form id="netz-form">
            <div class="netz-modal-kopf">
                <h2 id="netz-modal-titel">Eintrag anlegen</h2>
                <button type="button" class="netz-modal-schliessen" id="netz-modal-schliessen" aria-label="Schließen">×</button>
            </div>
            <input type="hidden" id="netz-form-id" value="0">
            <label for="netz-form-titel">Bezeichnung</label>
            <input type="text" id="netz-form-titel" maxlength="160" required autocomplete="off">
            <label for="netz-form-beschreibung">Beschreibung (optional)</label>
            <textarea id="netz-form-beschreibung" maxlength="500" rows="3"></textarea>
            <div class="netz-modal-fehler" id="netz-modal-fehler" role="alert"></div>
            <div class="netz-modal-fuss">
                <button type="button" class="netz-aktion netz-aktion-loeschen" id="netz-loeschen" hidden>Löschen</button>
                <button type="button" class="netz-aktion netz-aktion-sekundaer" id="netz-abbrechen">Abbrechen</button>
                <button type="submit" class="netz-aktion" id="netz-speichern">Speichern</button>
            </div>
        </form>
    </div>
</div>

<div class="netz-modal-hintergrund" id="netz-profil-modal" hidden>
    <div class="netz-modal" role="dialog" aria-modal="true" aria-labelledby="netz-profil-titel">
        <form id="netz-profil-form">
            <div class="netz-modal-kopf">
                <h2 id="netz-profil-titel"></h2>
                <button type="button" class="netz-modal-schliessen" id="netz-profil-schliessen" aria-label="Schließen">×</button>
            </div>
            <label for="netz-profil-wert" id="netz-profil-label"></label>
            <textarea id="netz-profil-wert" rows="4" spellcheck="false"></textarea>
            <div class="netz-modal-fehler" id="netz-profil-fehler" role="alert"></div>
            <div class="netz-modal-fuss">
                <button type="button" class="netz-aktion netz-aktion-sekundaer" id="netz-profil-abbrechen">Abbrechen</button>
                <button type="submit" class="netz-aktion" id="netz-profil-save">Speichern</button>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
    'use strict';

    const csrf = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const tabelle = document.getElementById('netz-tabelle');
    const zeilen = document.getElementById('netz-zeilen');
    const kopf = document.getElementById('netz-kopf');
    const keyRow = document.getElementById('netz-key-row');
    const spalten = document.getElementById('netz-spalten');
    const leer = document.getElementById('netz-leer');
    const status = document.getElementById('netz-status');
    const modal = document.getElementById('netz-modal-hintergrund');
    const form = document.getElementById('netz-form');
    const formId = document.getElementById('netz-form-id');
    const formTitel = document.getElementById('netz-form-titel');
    const formBeschreibung = document.getElementById('netz-form-beschreibung');
    const formFehler = document.getElementById('netz-modal-fehler');
    const loeschen = document.getElementById('netz-loeschen');
    const speichern = document.getElementById('netz-speichern');

    let daten = {eintraege: [], mitglieder: [], haken: []};
    let gezogeneZeile = null;
    let vorherigerFokus = null;
    let statusTimeout = null;

    async function anfrage(aktion, parameter = {}) {
        const body = new URLSearchParams({netz_ajax: '1', csrf, aktion, ...parameter});
        const antwort = await fetch(window.location.pathname, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        let json;
        try {
            json = await antwort.json();
        } catch (_) {
            throw new Error('Ungültige Serverantwort. Anmeldung prüfen.');
        }
        if (!antwort.ok || !json.ok) {
            throw new Error(json.error || 'Anfrage fehlgeschlagen.');
        }
        return json;
    }

    function meldung(text, fehler = false) {
        if (statusTimeout) clearTimeout(statusTimeout);
        status.textContent = text;
        status.classList.toggle('fehler', fehler);
        if (text && !fehler) {
            statusTimeout = setTimeout(() => { status.textContent = ''; }, 2300);
        }
    }

    function anzeigeName(person) {
        const einzelteile = [person.firstname, person.lastname].map(s => (s || '').trim()).filter(Boolean);
        return einzelteile.join(' ') || (person.name || '').trim() || person.username || ('UID ' + person.uid);
    }

    function element(tag, klasse, text) {
        const el = document.createElement(tag);
        if (klasse) el.className = klasse;
        if (text !== undefined) el.textContent = text;
        return el;
    }

    async function aktualisieren() {
        const antwort = await anfrage('laden');
        daten = antwort;
        tabelleAufbauen();
    }

    function tabelleAufbauen() {
        zeilen.replaceChildren();
        spalten.replaceChildren();
        kopf.replaceChildren();
        keyRow.replaceChildren(element('th', '', 'Public Key'));
        spalten.append(element('col', 'netz-beschreibungsspalte'));
        kopf.append(element('th', 'netz-erster-kopf', 'Zugang / Berechtigung'));
        kopf.firstChild.scope = 'col';

        const checks = new Map(daten.haken.map(h => [`${h.item_id}:${h.uid}`, h]));
        const mitglieder = daten.mitglieder;
        let ersterEhemaliger = true;
        const ehemaligeBeginn = new Set();

        for (const person of mitglieder) {
            spalten.append(document.createElement('col'));
            const th = element('th', 'netz-person-kopf');
            th.scope = 'col';
            const name = anzeigeName(person);
            th.title = name + (person.aktiv ? '' : ' (nicht mehr Mitglied der Netzwerk-AG)');
            const nameButton = element('button', 'netz-name-button netz-person-name', person.alias || name);
            nameButton.type = 'button';
            if (person.alias) nameButton.append(element('span', 'netz-realname', '(' + name + ')'));
            nameButton.addEventListener('click', () => profilOeffnen(person, 'alias'));
            th.append(nameButton);
            if (!person.aktiv) {
                th.classList.add('netz-ehemalig');
                if (ersterEhemaliger) {
                    th.classList.add('netz-ehemalig-beginn');
                    ehemaligeBeginn.add(person.uid);
                    ersterEhemaliger = false;
                }
            }
            kopf.append(th);
            const keyCell = element('th', '');
            if (!person.aktiv) keyCell.classList.add('netz-ehemalig');
            if (ehemaligeBeginn.has(person.uid)) keyCell.classList.add('netz-ehemalig-beginn');
            const actions = element('div', 'netz-key-actions');
            if (person.publickey) {
                const copy = element('button', 'netz-key-button', 'Copy');
                copy.type = 'button';
                copy.addEventListener('click', async () => {
                    try { await navigator.clipboard.writeText(person.publickey); meldung('Public Key kopiert'); }
                    catch (_) { profilOeffnen(person, 'publickey'); document.getElementById('netz-profil-wert').select(); meldung('Bitte Schlüssel manuell kopieren.', true); }
                });
                actions.append(copy);
            }
            const edit = element('button', 'netz-key-button' + (person.publickey ? '' : ' netz-key-new'), person.publickey ? 'Edit' : 'New');
            edit.type = 'button';
            edit.addEventListener('click', () => profilOeffnen(person, 'publickey'));
            actions.append(edit);
            keyCell.append(actions);
            keyRow.append(keyCell);
        }

        for (const eintrag of daten.eintraege) {
            const tr = document.createElement('tr');
            tr.dataset.itemId = String(eintrag.id);
            const nameCell = element('td', 'netz-eintrag');
            const inhalt = element('div', 'netz-eintrag-inhalt');
            const griff = element('span', 'netz-griff', '⠿');
            griff.draggable = true;
            griff.title = 'Ziehen, um Eintrag zu verschieben';
            griff.setAttribute('aria-label', 'Eintrag verschieben');
            griff.addEventListener('dragstart', event => {
                gezogeneZeile = tr;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(eintrag.id));
                requestAnimationFrame(() => tr.classList.add('netz-ziehen'));
            });
            griff.addEventListener('dragend', () => {
                tr.classList.remove('netz-ziehen');
                gezogeneZeile = null;
            });

            const bearbeiten = element('button', 'netz-eintrag-button', eintrag.titel);
            bearbeiten.type = 'button';
            bearbeiten.title = eintrag.beschreibung || 'Eintrag bearbeiten';
            bearbeiten.addEventListener('click', () => modalOeffnen(eintrag));
            inhalt.append(griff, bearbeiten);
            nameCell.append(inhalt);
            tr.append(nameCell);

            for (const person of mitglieder) {
                const td = element('td', 'netz-feld');
                const label = element('label', 'netz-feld-label');
                const box = element('input', 'netz-haken');
                const check = checks.get(`${eintrag.id}:${person.uid}`);
                box.type = 'checkbox';
                box.checked = !!check;
                box.disabled = !person.aktiv && !check;
                box.setAttribute('aria-label', `${eintrag.titel}: ${anzeigeName(person)}`);
                if (check) {
                    box.title = 'Gesetzt am ' + check.gesetzt_am + (check.von_name ? ' von ' + check.von_name : '');
                }
                if (!person.aktiv) {
                    td.classList.add('netz-ehemalig');
                    if (ehemaligeBeginn.has(person.uid)) td.classList.add('netz-ehemalig-beginn');
                }
                box.addEventListener('change', async () => {
                    const soll = box.checked;
                    box.disabled = true;
                    try {
                        await anfrage('haken', {
                            item_id: String(eintrag.id),
                            uid: String(person.uid),
                            gesetzt: soll ? '1' : '0'
                        });
                        if (!person.aktiv && !soll) {
                            // Nach dem letzten entfernten Haken verschwindet der Ehemalige.
                            await aktualisieren();
                        }
                        meldung('Gespeichert');
                    } catch (fehler) {
                        box.checked = !soll;
                        meldung(fehler.message, true);
                    } finally {
                        box.disabled = !person.aktiv && !box.checked;
                    }
                });
                label.append(box);
                td.append(label);
                tr.append(td);
            }
            zeilen.append(tr);
        }

        const keineEintraege = daten.eintraege.length === 0;
        tabelle.hidden = false;
        leer.hidden = !keineEintraege;
        leer.textContent = keineEintraege ? 'Noch keine Einträge angelegt.' : '';
    }

    // Die gesamte Tabellenzeile wird beim Ziehen an der Griffmarkierung verschoben.
    zeilen.addEventListener('dragover', event => {
        if (!gezogeneZeile) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const ziel = event.target.closest('tr[data-item-id]');
        if (!ziel || ziel === gezogeneZeile) return;
        const rect = ziel.getBoundingClientRect();
        if (event.clientY < rect.top + rect.height / 2) {
            zeilen.insertBefore(gezogeneZeile, ziel);
        } else {
            zeilen.insertBefore(gezogeneZeile, ziel.nextSibling);
        }
    });

    zeilen.addEventListener('drop', async event => {
        if (!gezogeneZeile) return;
        event.preventDefault();
        gezogeneZeile.classList.remove('netz-ziehen');
        gezogeneZeile = null;
        const ids = [...zeilen.querySelectorAll('tr[data-item-id]')].map(tr => Number(tr.dataset.itemId));
        const bisher = daten.eintraege.map(e => e.id);
        if (ids.every((id, index) => id === bisher[index])) return;
        try {
            await anfrage('sortieren', {ids: JSON.stringify(ids)});
            await aktualisieren();
            meldung('Reihenfolge gespeichert');
        } catch (fehler) {
            meldung(fehler.message, true);
            try { await aktualisieren(); } catch (_) { /* Status bleibt sichtbar. */ }
        }
    });

    const profilModal = document.getElementById('netz-profil-modal');
    const profilForm = document.getElementById('netz-profil-form');
    const profilWert = document.getElementById('netz-profil-wert');
    const profilFehler = document.getElementById('netz-profil-fehler');
    const profilSave = document.getElementById('netz-profil-save');
    let profilPerson, profilFeld, profilFokus;
    function profilOeffnen(person, feld) {
        profilPerson = person;
        profilFeld = feld;
        profilFokus = document.activeElement;
        document.getElementById('netz-profil-titel').textContent = anzeigeName(person);
        document.getElementById('netz-profil-label').textContent = feld === 'alias' ? 'Servername / Alias' : 'Public Key (OpenSSH)';
        profilWert.rows = feld === 'alias' ? 1 : 5;
        profilWert.maxLength = feld === 'alias' ? 160 : 16384;
        profilWert.value = person[feld] || '';
        profilFehler.textContent = '';
        profilModal.hidden = false;
        profilWert.focus();
    }
    function profilSchliessen() {
        profilModal.hidden = true;
        if (profilFokus && profilFokus.isConnected) profilFokus.focus();
    }
    document.getElementById('netz-profil-schliessen').addEventListener('click', profilSchliessen);
    document.getElementById('netz-profil-abbrechen').addEventListener('click', profilSchliessen);
    profilModal.addEventListener('click', e => { if (e.target === profilModal) profilSchliessen(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !profilModal.hidden) profilSchliessen(); });
    profilForm.addEventListener('submit', async e => {
        e.preventDefault();
        profilSave.disabled = true;
        profilFehler.textContent = '';
        try {
            await anfrage('profil', {uid: String(profilPerson.uid), feld: profilFeld, wert: profilWert.value.trim()});
            await aktualisieren();
            profilSchliessen();
            meldung('Gespeichert');
        } catch (fehler) { profilFehler.textContent = fehler.message; }
        finally { profilSave.disabled = false; }
    });

    function modalOeffnen(eintrag = null) {
        vorherigerFokus = document.activeElement;
        form.reset();
        formFehler.textContent = '';
        formId.value = eintrag ? String(eintrag.id) : '0';
        formTitel.value = eintrag ? eintrag.titel : '';
        formBeschreibung.value = eintrag ? eintrag.beschreibung : '';
        document.getElementById('netz-modal-titel').textContent = eintrag ? 'Eintrag bearbeiten' : 'Eintrag anlegen';
        loeschen.hidden = !eintrag;
        modal.hidden = false;
        formTitel.focus();
    }

    function modalSchliessen() {
        modal.hidden = true;
        if (vorherigerFokus && vorherigerFokus.isConnected) vorherigerFokus.focus();
    }

    document.getElementById('netz-neu').addEventListener('click', () => modalOeffnen());
    document.getElementById('netz-abbrechen').addEventListener('click', modalSchliessen);
    document.getElementById('netz-modal-schliessen').addEventListener('click', modalSchliessen);
    modal.addEventListener('click', event => {
        if (event.target === modal) modalSchliessen();
    });
    document.addEventListener('keydown', event => {
        if (!modal.hidden && event.key === 'Escape') modalSchliessen();
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        speichern.disabled = true;
        formFehler.textContent = '';
        try {
            await anfrage('speichern', {
                id: formId.value,
                titel: formTitel.value.trim(),
                beschreibung: formBeschreibung.value.trim()
            });
            modalSchliessen();
            await aktualisieren();
            meldung('Gespeichert');
        } catch (fehler) {
            formFehler.textContent = fehler.message;
        } finally {
            speichern.disabled = false;
        }
    });

    loeschen.addEventListener('click', async () => {
        const id = Number(formId.value);
        const titel = formTitel.value.trim();
        if (!id || !window.confirm(`„${titel}“ wirklich löschen? Alle zugehörigen Haken werden entfernt.`)) return;
        loeschen.disabled = true;
        formFehler.textContent = '';
        try {
            await anfrage('loeschen', {id: String(id)});
            modalSchliessen();
            await aktualisieren();
            meldung('Eintrag gelöscht');
        } catch (fehler) {
            formFehler.textContent = fehler.message;
        } finally {
            loeschen.disabled = false;
        }
    });

    aktualisieren().catch(fehler => {
        meldung(fehler.message, true);
        leer.textContent = 'Die Tabelle konnte nicht geladen werden.';
    });
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>
