<?php
session_start();

require_once('conn.php');
mysqli_set_charset($conn, "utf8");

$fijionlyaccess = true;

$bannerMessage = '';
$redirectAfterSubmit = false;

if (!function_exists('erstattung_h')) {
    function erstattung_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('erstattung_format_turm')) {
    function erstattung_format_turm(string $turm): string {
        $turm = strtolower(trim($turm));

        if ($turm === 'weh') {
            return 'WEH';
        }

        if ($turm === 'tvk') {
            return 'TvK';
        }

        return strtoupper($turm);
    }
}

if (!function_exists('erstattung_format_einrichtung')) {
    function erstattung_format_einrichtung(string $einrichtung): string {
        if (preg_match('/^etage:(weh|tvk)_([0-9]+)$/', $einrichtung, $matches)) {
            return erstattung_format_turm($matches[1]) . ' Etage ' . intval($matches[2]);
        }

        return $einrichtung;
    }
}

if (!function_exists('getTurmAccent')) {
    function getTurmAccent(string $turm): array {
        $turm = strtolower(trim($turm));

        if ($turm === 'tvk') {
            return [
                'accent' => '#FFA500',
                'hover' => '#cc8400',
            ];
        }

        return [
            'accent' => '#11a50d',
            'hover' => '#0e8c0b',
        ];
    }
}

if (!function_exists('getEtagensprecherFloor')) {
    function getEtagensprecherFloor(): ?int {
        if (isset($_SESSION['floor']) && is_numeric($_SESSION['floor'])) {
            return intval($_SESSION['floor']);
        }

        if (!empty($_SESSION['etagensprecher'])) {
            $code = trim((string)$_SESSION['etagensprecher']);

            /*
             * Fallback für altes Format:
             * bisher wurde substr($_SESSION["etagensprecher"], 0, -1) als Etage genutzt.
             */
            if (strlen($code) > 1 && is_numeric($code)) {
                $floorPart = substr($code, 0, -1);

                if ($floorPart !== '' && is_numeric($floorPart)) {
                    return intval($floorPart);
                }
            }

            if (is_numeric($code)) {
                return intval($code);
            }
        }

        return null;
    }
}

if (!function_exists('sendErstattungNotificationMail')) {
    function sendErstattungNotificationMail(
        bool $fijionlyaccess,
        int $antragId,
        int $uid,
        string $name,
        string $turm,
        $room,
        string $einrichtungLabel,
        float $betrag,
        string $iban,
        string $pfad
    ): bool {
        $from = 'system@weh.rwth-aachen.de';
        $backendLink = 'https://backend.weh.rwth-aachen.de/Erstattung.php';

        $recipients = ['kasse@weh.rwth-aachen.de'];

        if ($fijionlyaccess) {
            $recipients[] = 'fiji@weh.rwth-aachen.de';
        }

        $to = implode(',', $recipients);
        $subject = 'New floor reimbursement request #' . $antragId;

        $message = "Hallo,\n\n"
            . "es wurde ein neuer Erstattungsantrag eines Etagensprechers eingereicht und muss bearbeitet bzw. erstattet werden.\n\n"
            . "Link zur Bearbeitung:\n"
            . $backendLink . "\n\n"
            . "Antragsdaten:\n"
            . "ID: #" . $antragId . "\n"
            . "Name: " . $name . "\n"
            . "UID: " . $uid . "\n"
            . "Turm: " . erstattung_format_turm($turm) . "\n"
            . "Raum: " . $room . "\n"
            . "Einrichtung: " . $einrichtungLabel . "\n"
            . "Betrag: " . number_format($betrag, 2, ',', '.') . " EUR\n"
            . "IBAN: " . $iban . "\n"
            . "Rechnung: " . $pfad . "\n";

        if ($fijionlyaccess) {
            $message .= "\n"
                . "Hinweis:\n"
                . "Aktuell hat nur Fiji Zugriff auf das Hauskonto, also muss Fiji die Überweisung erledigen. "
                . "Die Kassenwarte sind zur Information ebenfalls in dieser Mail.\n";
        }

        $message .= "\n"
            . "Diese Nachricht wurde automatisch vom Backend versendet.\n";

        $headers  = "From: WEH Backend <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        return mail($to, $subject, $message, $headers);
    }
}

/*
 * Kein template.php hier laden.
 * template.php erzeugt HTML und darf erst später im Body geladen werden.
 */

$isAuthenticated = !empty($_SESSION['valid']);
$isEtagensprecher = !empty($_SESSION['etagensprecher']) && intval($_SESSION['etagensprecher']) > 0;

$turm = strtolower(trim((string)($_SESSION['turm'] ?? '')));
$floor = getEtagensprecherFloor();

$berechtigt = $isAuthenticated
    && $isEtagensprecher
    && in_array($turm, ['weh', 'tvk'], true)
    && is_numeric($floor)
    && intval($floor) > 0;

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

$floor = intval($floor);
$einrichtungsKey = sprintf('etage:%s_%d', $turm, $floor);

$turmColors = getTurmAccent($turm);
$turmAccent = $turmColors['accent'];
$turmAccentHover = $turmColors['hover'];

$betragGenehmigt = 0.0;
$betragInBearbeitung = 0.0;
$flooractionbudget = 0.0;

$sql = "
    SELECT COALESCE(SUM(e.betrag), 0) AS summe
    FROM erstattung e
    WHERE e.status = 1
      AND e.einrichtung = ?
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $einrichtungsKey);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $betragGenehmigt);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

$sql = "
    SELECT COALESCE(SUM(e.betrag), 0) AS summe
    FROM erstattung e
    WHERE e.status = 0
      AND e.einrichtung = ?
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $einrichtungsKey);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $betragInBearbeitung);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

$sql = "
    SELECT wert
    FROM constants
    WHERE name = 'flooractionbudget'
    LIMIT 1
";
$res = mysqli_query($conn, $sql);

if ($row = mysqli_fetch_assoc($res)) {
    $flooractionbudget = (float)$row['wert'];
}

if ($res) {
    mysqli_free_result($res);
}

if ($turm !== "weh") {
    $flooractionbudget = 0.0;
}

$betragOffen = max(0, $flooractionbudget - $betragGenehmigt - $betragInBearbeitung);

if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    $uid = intval($_SESSION['uid'] ?? 0);
    $tstamp = time();
    $iban = trim($_POST['iban'] ?? '');
    $betrag = floatval(str_replace(",", ".", $_POST['betrag'] ?? '0'));

    /*
     * Einrichtung absichtlich serverseitig.
     * Kein Dropdown, kein POST-Wert, keine manipulierbare AG-/Etagen-Auswahl.
     */
    $einrichtung = $einrichtungsKey;

    if ($uid <= 0 || $iban === '' || $betrag <= 0) {
        $bannerMessage = 'Ungültige Eingabe.';
    } elseif ($betrag > $betragOffen) {
        $bannerMessage = 'Der Betrag ist höher als das verfügbare Budget dieser Etage.';
    } elseif (!isset($_FILES['rechnung']) || $_FILES['rechnung']['error'] !== UPLOAD_ERR_OK) {
        $bannerMessage = 'Keine Datei empfangen.';
    } else {
        $submitterName = $_SESSION['name'] ?? '';
        $submitterTurm = $_SESSION['turm'] ?? '';
        $submitterRoom = $_SESSION['userroom'] ?? '';

        $userStmt = mysqli_prepare($conn, "
            SELECT name, turm, room
            FROM users
            WHERE uid = ?
            LIMIT 1
        ");

        if ($userStmt) {
            mysqli_stmt_bind_param($userStmt, "i", $uid);
            mysqli_stmt_execute($userStmt);
            mysqli_stmt_bind_result($userStmt, $dbName, $dbTurm, $dbRoom);

            if (mysqli_stmt_fetch($userStmt)) {
                $submitterName = $dbName;
                $submitterTurm = $dbTurm;
                $submitterRoom = $dbRoom;
            }

            mysqli_stmt_close($userStmt);
        }

        $upload_dir = 'rechnungen/';
        $tmp_name = $_FILES['rechnung']['tmp_name'];
        $original_name = $_FILES['rechnung']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($extension, $allowed_extensions, true)) {
            $bannerMessage = 'Ungültiges Dateiformat.';
        } else {
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $base = uniqid('r_', true);
            $new_filename = $base . '.' . $extension;
            $target_path = $upload_dir . $new_filename;
            $counter = 1;

            while (file_exists($target_path)) {
                $new_filename = $base . '_' . $counter . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                $counter++;
            }

            if (!move_uploaded_file($tmp_name, $target_path)) {
                $bannerMessage = 'Fehler beim Verschieben der Datei.';
            } else {
                $pfad = $target_path;

                $sql = "
                    INSERT INTO erstattung
                        (uid, tstamp, einrichtung, betrag, iban, status, pfad)
                    VALUES
                        (?, ?, ?, ?, ?, 0, ?)
                ";
                $stmt = mysqli_prepare($conn, $sql);

                if (!$stmt) {
                    $bannerMessage = 'Datenbankfehler beim Vorbereiten.';
                } else {
                    mysqli_stmt_bind_param($stmt, 'iisdss', $uid, $tstamp, $einrichtung, $betrag, $iban, $pfad);
                    $insertOk = mysqli_stmt_execute($stmt);
                    $inserted_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    if (!$insertOk || $inserted_id <= 0) {
                        $bannerMessage = 'Datenbankfehler beim Speichern.';
                    } else {
                        $einrichtungLabelForMail = erstattung_format_einrichtung($einrichtung);

                        $mailSent = sendErstattungNotificationMail(
                            $fijionlyaccess,
                            $inserted_id,
                            $uid,
                            $submitterName,
                            $submitterTurm,
                            $submitterRoom,
                            $einrichtungLabelForMail,
                            $betrag,
                            $iban,
                            $pfad
                        );

                        if ($mailSent) {
                            $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Kasse wurde informiert.';
                        } else {
                            $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Mail konnte nicht versendet werden.';
                        }

                        $redirectAfterSubmit = true;
                    }
                }
            }
        }
    }
}

$einrichtungLabel = erstattung_format_einrichtung($einrichtungsKey);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title>WEH Backend</title>
    <style>
        :root {
            --turm-accent: <?= erstattung_h($turmAccent) ?>;
            --turm-accent-hover: <?= erstattung_h($turmAccentHover) ?>;
            --nav-accent: <?= erstattung_h($turmAccent) ?>;
        }

        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: sans-serif;
        }

        .form-wrapper {
            max-width: 600px;
            margin: 3em auto;
            padding: 2em;
            background-color: #1c1c1c;
            border: 2px solid var(--turm-accent);
            border-radius: 8px;
        }

        .form-wrapper h1 {
            color: var(--turm-accent);
            font-size: 1.8em;
            margin-bottom: 0.5em;
        }

        .form-wrapper p {
            color: #cccccc;
            margin-bottom: 1em;
        }

        .form-wrapper label {
            display: block;
            margin-top: 1em;
            margin-bottom: 0.3em;
            color: #a0ffa0;
        }

        .form-wrapper input,
        .form-wrapper button {
            box-sizing: border-box;
            display: block;
        }

        .form-wrapper input[type="file"],
        .form-wrapper input[type="text"],
        .form-wrapper input[type="number"],
        .form-wrapper button {
            width: 100%;
            padding: 0.7em;
            margin-bottom: 1em;
            background-color: #252525;
            border: 1px solid var(--turm-accent);
            color: #e0ffe0;
            border-radius: 4px;
        }

        .form-wrapper button {
            background-color: var(--turm-accent);
            color: #000;
            font-weight: bold;
            transition: background-color 0.2s ease;
            cursor: pointer;
        }

        .form-wrapper button:hover {
            background-color: var(--turm-accent-hover);
        }

        .allowed-items-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5em;
            margin-top: 1em;
        }

        .allowed-item {
            padding: 0.3em;
            border: 1px solid var(--turm-accent);
            border-radius: 4px;
            text-align: center;
        }

        .target-floor-box {
            margin: 1.5em 0;
            padding: 1em;
            border: 1px solid var(--turm-accent);
            border-radius: 6px;
            background: #181818;
            color: #cccccc;
            text-align: center;
        }

        .target-floor-box strong {
            color: #e0ffe0;
        }

        .budget-table {
            margin: 0 auto;
            color: #cccccc;
        }

        .budget-table td {
            padding: 0.25em 0;
        }

        .budget-table td:first-child {
            text-align: left;
            padding-right: 2em;
        }

        .budget-table td:last-child {
            text-align: right;
            padding-left: 2em;
        }

        .budget-table hr {
            border: none;
            border-top: 1px solid #555;
        }

        .turm-separator {
            border: none;
            border-top: 2px solid var(--turm-accent);
            margin: 1.3em 0;
        }

        .form-row {
            display: flex;
            gap: 1em;
            flex-wrap: wrap;
        }

        .form-field {
            flex: 1;
            min-width: 200px;
        }

        .success-banner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            min-width: 320px;
            max-width: 90vw;
            background: rgba(0, 0, 0, 0.88);
            padding: 1em 2em;
            color: white;
            font-size: 2em;
            font-weight: bold;
            border: 2px solid var(--turm-accent);
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 0 10px var(--turm-accent);
            text-align: center;
        }

        .navbar-welcome-name {
            color: var(--turm-accent) !important;
        }

        .navbar-menu-wrapper .header-menu .center-btn:hover,
        .navbar-menu-wrapper .header-submenu button:hover,
        .header-submenu button:hover {
            background-color: var(--turm-accent) !important;
            color: #000 !important;
        }

        @media (max-width: 640px) {
            .allowed-items-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-wrapper {
                margin: 1.5em 1em;
                padding: 1.5em;
            }

            .success-banner {
                font-size: 1.3em;
            }
        }
    </style>
</head>

<body>
<?php
require_once('template.php');
load_menu();
?>

<?php if ($bannerMessage !== ''): ?>
    <div class="success-banner">
        <?= $bannerMessage ?>
    </div>
<?php endif; ?>

<?php if ($redirectAfterSubmit): ?>
    <style>
        html,
        body {
            cursor: wait;
        }
    </style>

    <script>
        setTimeout(function() {
            window.location.href = 'Erstattungsantrag-Etagensprecher.php';
        }, 2000);
    </script>
<?php endif; ?>

<div class="form-wrapper">
    <div style="text-align: center;">
        <h1>Kostenerstattung für Etagensprecher</h1>

        <p style="color: #cccccc; margin-top: -0.5em; margin-bottom: 1.5em;">
            <strong>Etagensprecher</strong> können ausschließlich diese ausgewählten Artikel beantragen:
        </p>

        <div class="allowed-items-grid">
            <div class="allowed-item">Wasserkocher</div>
            <div class="allowed-item">Mikrowelle</div>
            <div class="allowed-item">Staubsauger</div>
            <div class="allowed-item">Kaffeemaschine</div>
            <div class="allowed-item">Fliegengitter</div>
            <div class="allowed-item">Toaster</div>
            <div class="allowed-item">Airfryer</div>
            <div class="allowed-item">Mixer</div>
            <div class="allowed-item">Wischmopp</div>
        </div>

        <div class="target-floor-box">
            Antrag für:<br>
            <strong><?= erstattung_h($einrichtungLabel) ?></strong>
        </div>

        <div style="text-align: center; color: #cccccc;">
            <div style="margin-bottom: 0.3em; font-weight: bold;">
                Etage <?= erstattung_h($floor) ?> <?= erstattung_h(erstattung_format_turm((string)$turm)) ?>
            </div>

            <table class="budget-table" cellspacing="0">
                <tr>
                    <td>Gesamt</td>
                    <td>
                        <strong><?= number_format($flooractionbudget, 2, ',', '.') ?> €</strong>
                    </td>
                </tr>
                <tr>
                    <td>Genehmigte Anträge</td>
                    <td>
                        - <?= number_format((float)$betragGenehmigt, 2, ',', '.') ?> €
                    </td>
                </tr>
                <tr>
                    <td>In Bearbeitung</td>
                    <td>
                        - <?= number_format((float)$betragInBearbeitung, 2, ',', '.') ?> €
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <hr>
                    </td>
                </tr>
                <tr>
                    <td>Verfügbares Budget</td>
                    <td>
                        <strong><?= number_format($betragOffen, 2, ',', '.') ?> €</strong>
                    </td>
                </tr>
            </table>
        </div>

        <hr class="turm-separator">

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="reload" value="1">

            <div class="form-row">
                <div class="form-field">
                    <label for="rechnung">Rechnung hochladen (PDF oder Bild):</label>
                    <input
                        type="file"
                        name="rechnung"
                        id="rechnung"
                        accept=".pdf,.jpg,.jpeg,.png"
                        required
                    >
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="betrag">Preis in Euro:</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="betrag"
                        id="betrag"
                        max="<?= erstattung_h(number_format($betragOffen, 2, '.', '')) ?>"
                        placeholder="€"
                        required
                    >
                </div>

                <div class="form-field">
                    <label for="iban">IBAN für Erstattung:</label>
                    <input
                        type="text"
                        name="iban"
                        id="iban"
                        placeholder="DE90 3905 0000 1070 3346 00"
                        required
                    >
                </div>
            </div>

            <br>

            <button type="submit">
                Einreichen
            </button>
        </form>
    </div>
</div>

</body>
</html>
