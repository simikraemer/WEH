<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        :root {
            --turm-accent: #11a50d;
            --turm-accent-hover: #0e8c0b;
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

        .form-wrapper ul {
            margin-top: 0.1em;
            margin-bottom: 1.5em;
            padding-left: 1.2em;
        }

        .form-wrapper label {
            display: block;
            margin-top: 1em;
            margin-bottom: 0.3em;
            color: #a0ffa0;
        }

        .form-wrapper input,
        .form-wrapper select,
        .form-wrapper button {
            box-sizing: border-box;
            display: block;
        }

        .form-wrapper input[type="file"],
        .form-wrapper input[type="text"],
        .form-wrapper input[type="number"],
        .form-wrapper select,
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
        }

        .allowed-item {
            padding: 0.3em;
            border: 1px solid var(--turm-accent);
            border-radius: 4px;
            text-align: center;
        }

        .turm-separator {
            border: none;
            border-top: 2px solid var(--turm-accent);
            margin: 1.3em 0;
        }

        .success-banner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.85);
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
    </style>
</head>

<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

$fijionlyaccess = true;

if (!function_exists('erstattung_h')) {
    function erstattung_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

if (!function_exists('userHasRefundAgAccess')) {
    function userHasRefundAgAccess(mysqli $conn): bool {
        $sql = "
            SELECT session
            FROM `groups`
            WHERE active = 1
              AND agessen = 1
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $agSessionKey);

        $hasAccess = false;

        while (mysqli_stmt_fetch($stmt)) {
            if (!empty($agSessionKey) && !empty($_SESSION[$agSessionKey])) {
                $hasAccess = true;
                break;
            }
        }

        mysqli_stmt_close($stmt);
        return $hasAccess;
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
        $subject = 'New reimbursement request #' . $antragId;

        $message = "Hallo,\n\n"
            . "es wurde ein neuer Erstattungsantrag eingereicht und muss bearbeitet bzw. erstattet werden.\n\n"
            . "Link zur Bearbeitung:\n"
            . $backendLink . "\n\n"
            . "Antragsdaten:\n"
            . "ID: #" . $antragId . "\n"
            . "Name: " . $name . "\n"
            . "UID: " . $uid . "\n"
            . "Turm: " . formatTurm($turm) . "\n"
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

$isAuthenticated = auth($conn) && !empty($_SESSION['valid']);

$sessionTurm = strtolower(trim($_SESSION['turm'] ?? 'weh'));
$turmColors = getTurmAccent($sessionTurm);
$turmAccent = $turmColors['accent'];
$turmAccentHover = $turmColors['hover'];

echo '<style>
    :root {
        --turm-accent: ' . erstattung_h($turmAccent) . ';
        --turm-accent-hover: ' . erstattung_h($turmAccentHover) . ';
        --nav-accent: ' . erstattung_h($turmAccent) . ';
    }
</style>';

$isEtagensprecher = !empty($_SESSION['etagensprecher']) && $_SESSION['etagensprecher'] > 0;
$isAG = $isAuthenticated && userHasRefundAgAccess($conn);
$isFiji = !empty($_SESSION['uid']) && intval($_SESSION['uid']) === 2136;

$berechtigt = $isAuthenticated && ($isEtagensprecher || $isAG || $isFiji);

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

$turm = $_SESSION['turm'] ?? null;
$floor = $_SESSION['floor'] ?? null;
$betragGenehmigt = 0.0;
$betragInBearbeitung = 0.0;
$flooractionbudget = 0.0;

if (in_array($turm, ['weh', 'tvk'], true) && is_numeric($floor)) {
    $einrichtungsKey = sprintf('etage:%s_%d', $turm, intval($floor));

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
}

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

if ($_SESSION["turm"] !== "weh") {
    $flooractionbudget = 0.0;
}

if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
    $uid = intval($_SESSION['uid']);
    $tstamp = time();
    $iban = trim($_POST['iban'] ?? '');
    $betrag = floatval(str_replace(",", ".", $_POST['betrag'] ?? '0'));
    $einrichtung = trim($_POST['einheit'] ?? '');

    if ($einrichtung === '' || $iban === '' || $betrag <= 0) {
        echo '<div class="success-banner">Ungültige Eingabe.</div>';
        exit;
    }

    $submitterName = $_SESSION['name'] ?? '';
    $submitterTurm = $_SESSION['turm'] ?? '';
    $submitterRoom = $_SESSION['userroom'] ?? '';

    $userStmt = mysqli_prepare($conn, "
        SELECT name, turm, room
        FROM users
        WHERE uid = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($userStmt, "i", $uid);
    mysqli_stmt_execute($userStmt);
    mysqli_stmt_bind_result($userStmt, $dbName, $dbTurm, $dbRoom);

    if (mysqli_stmt_fetch($userStmt)) {
        $submitterName = $dbName;
        $submitterTurm = $dbTurm;
        $submitterRoom = $dbRoom;
    }

    mysqli_stmt_close($userStmt);

    $pfad = '';

    if (isset($_FILES['rechnung']) && $_FILES['rechnung']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'rechnungen/';
        $tmp_name = $_FILES['rechnung']['tmp_name'];
        $original_name = $_FILES['rechnung']['name'];
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];

        if (in_array($extension, $allowed_extensions, true)) {
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

            if (move_uploaded_file($tmp_name, $target_path)) {
                $pfad = $target_path;
            } else {
                echo '<div class="success-banner">Fehler beim Verschieben der Datei.</div>';
                exit;
            }
        } else {
            echo '<div class="success-banner">Ungültiges Dateiformat.</div>';
            exit;
        }
    } else {
        echo '<div class="success-banner">Keine Datei empfangen.</div>';
        exit;
    }

    $sql = "
        INSERT INTO erstattung
            (uid, tstamp, einrichtung, betrag, iban, status, pfad)
        VALUES
            (?, ?, ?, ?, ?, 0, ?)
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iisdss', $uid, $tstamp, $einrichtung, $betrag, $iban, $pfad);
    mysqli_stmt_execute($stmt);
    $inserted_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $einrichtungLabel = formatEinrichtung($einrichtung, $conn);

    $mailSent = sendErstattungNotificationMail(
        $fijionlyaccess,
        $inserted_id,
        $uid,
        $submitterName,
        $submitterTurm,
        $submitterRoom,
        $einrichtungLabel,
        $betrag,
        $iban,
        $pfad
    );

    if ($mailSent) {
        echo '<div class="success-banner">Antrag erfolgreich eingereicht.<br>Kasse wurde informiert.</div>';
    } else {
        echo '<div class="success-banner">Antrag erfolgreich eingereicht.<br>Mail konnte nicht versendet werden.</div>';
    }

    echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
    echo "<script>
        setTimeout(function() {
            document.forms['reload'].submit();
        }, 2000);
    </script>";
}

load_menu();
?>

<div class="form-wrapper">
    <div style="text-align: center;">
        <h1>Kostenerstattung beantragen</h1>

        <?php if ($isAG): ?>
            <p style="color: #cccccc; margin-bottom: 1em;">
                <strong>AGs</strong> dürfen nur zweckgebundene Ausgaben geltend machen.<br>
                Bei Unsicherheit bitte vorab den Vorstand kontaktieren.
            </p>
        <?php endif; ?>

        <?php if ($isEtagensprecher || $isFiji): ?>
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

            <?php $betragOffen = max(0, $flooractionbudget - $betragGenehmigt - $betragInBearbeitung); ?>

            <br>

            <div style="text-align: center; color: #cccccc;">
                <div style="margin-bottom: 0.3em; font-weight: bold;">
                    Etage <?= erstattung_h($floor) ?> <?= erstattung_h(formatTurm((string)$turm)) ?>
                </div>

                <table cellspacing="0" style="margin: 0 auto;">
                    <tr>
                        <td style="text-align: left; padding-right: 2em;">Gesamt</td>
                        <td style="text-align: right; padding-left: 2em;">
                            <strong><?= number_format($flooractionbudget, 2, ',', '.') ?> €</strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: left; padding-right: 2em;">Genehmigte Anträge</td>
                        <td style="text-align: right; padding-left: 2em;">
                            - <?= number_format((float)$betragGenehmigt, 2, ',', '.') ?> €
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: left; padding-right: 2em;">In Bearbeitung</td>
                        <td style="text-align: right; padding-left: 2em;">
                            - <?= number_format((float)$betragInBearbeitung, 2, ',', '.') ?> €
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <hr style="border: none; border-top: 1px solid #555;">
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: left; padding-right: 2em;">Verfügbares Budget</td>
                        <td style="text-align: right; padding-left: 2em;">
                            <strong><?= number_format($betragOffen, 2, ',', '.') ?> €</strong>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endif; ?>

        <hr class="turm-separator">

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="reload" value="1">

            <div style="display: flex; gap: 1em; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="rechnung" style="display: block;">Rechnung hochladen (PDF oder Bild):</label>
                    <input
                        type="file"
                        name="rechnung"
                        id="rechnung"
                        accept=".pdf,.jpg,.jpeg,.png"
                        required
                        style="width: 100%; box-sizing: border-box;"
                    >
                </div>

                <div style="flex: 1; min-width: 200px;">
                    <label for="einheit" style="display: block;">Für welche AG/Etage ist der Kauf?</label>
                    <select name="einheit" id="einheit" required style="width: 100%; box-sizing: border-box;">
                        <option value="">-- Bitte wählen --</option>

                        <?php
                        $sql = "
                            SELECT id, name, session
                            FROM `groups`
                            WHERE active = 1
                              AND agessen = 1
                            ORDER BY prio
                        ";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_bind_result($stmt, $agId, $agName, $agSessionKey);

                        while (mysqli_stmt_fetch($stmt)) {
                            if (!empty($agSessionKey) && !empty($_SESSION[$agSessionKey])) {
                                $label = erstattung_h($agName);
                                $value = 'ag:' . intval($agId);
                                echo "<option value=\"" . erstattung_h($value) . "\">{$label}</option>";
                            }
                        }

                        mysqli_stmt_close($stmt);

                        if (!empty($_SESSION["etagensprecher"]) && !empty($_SESSION["turm"])) {
                            $code = $_SESSION["etagensprecher"];
                            $etage = substr((string)$code, 0, -1);
                            $turm = $_SESSION["turm"];
                            $turm_label = formatTurm((string)$turm);

                            $value = "etage:{$turm}_{$etage}";
                            $label = "{$turm_label} Etage {$etage}";

                            echo "<option value=\"" . erstattung_h($value) . "\">" . erstattung_h($label) . "</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 1em; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="betrag" style="display: block;">Preis in Euro:</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="betrag"
                        id="betrag"
                        placeholder="€"
                        required
                        style="width: 100%; box-sizing: border-box;"
                    >
                </div>

                <div style="flex: 1; min-width: 200px;">
                    <label for="iban" style="display: block;">IBAN für Erstattung:</label>
                    <input
                        type="text"
                        name="iban"
                        id="iban"
                        placeholder="DE90 3905 0000 1070 3346 00"
                        required
                        style="width: 100%; box-sizing: border-box;"
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