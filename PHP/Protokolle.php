<?php
ob_start(); // template.php und auth() erzeugen sonst HTML vor dem DOCTYPE.
// Protokolle.php – getrennte Übersicht WEH (links) und TvK (rechts)
require_once __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8');

if (!auth($conn) || empty($_SESSION['valid'])) {
    ob_end_clean();
    http_response_code(403);
    echo '<p style="color:white; text-align:center;">Kein Zugriff.</p>';
    exit;
}

$templateOutput = str_replace('<title>WEH Backend</title>', '', ob_get_clean());

$typeStrings = [
    0 => 'Ordentliche Vollversammlung',
    1 => 'Außerordentliche Vollversammlung',
    2 => 'Ordentlicher Haussenat',
    3 => 'Außerordentlicher Haussenat',
    4 => 'Aktiventreffen',
];

$isSchrift = !empty($_SESSION['Schrift']);
$isTvKSprecher = !empty($_SESSION['TvK-Sprecher']);
$isWebmaster = !empty($_SESSION['Webmaster']);
$isUploader = $isSchrift || $isTvKSprecher || $isWebmaster;
$currentUid = (int)($_SESSION['uid'] ?? 0);

// Schrift darf WEH-Protokolle löschen, TvK-Sprecher TvK-Protokolle.
// Eigene Uploads in den jeweils anderen Turm dürfen ebenfalls gelöscht werden.
// Webmaster dürfen beides verwalten.
function canDeleteProtokoll(string $turm, int $uploadUid, bool $isSchrift, bool $isTvKSprecher, bool $isWebmaster, int $currentUid): bool
{
    return $isWebmaster
        || ($isSchrift && $turm === 'weh')
        || ($isTvKSprecher && $turm === 'tvk')
        || (($isSchrift || $isTvKSprecher) && $uploadUid === $currentUid);
}

if (empty($_SESSION['protokoll_delete_csrf'])) {
    $_SESSION['protokoll_delete_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$notice = '';
$confirmRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_protokoll'], $_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['protokoll_delete_csrf'], (string)$_POST['csrf_token'])) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } elseif (!$isUploader) {
        $error = 'Keine Berechtigung zum Löschen.';
    } else {
        $id = filter_var($_POST['delete_protokoll'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $error = 'Ungültige Protokoll-ID.';
        } else {
            $stmt = mysqli_prepare($conn, 'SELECT id, uid, turm, type, versammlungszeit FROM protokolle WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $pId, $pUid, $pTurm, $pType, $pDate);
            if (mysqli_stmt_fetch($stmt)) {
                $turm = strtolower((string)$pTurm);
                if (canDeleteProtokoll($turm, (int)$pUid, $isSchrift, $isTvKSprecher, $isWebmaster, $currentUid)) {
                    $confirmRow = ['id' => (int)$pId, 'turm' => $turm, 'type' => (int)$pType, 'date' => (int)$pDate];
                } else {
                    $error = 'Keine Berechtigung, dieses Protokoll zu löschen.';
                }
            } else {
                $error = 'Protokoll nicht gefunden.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['really_delete_protokoll'])) {
    if (!hash_equals($_SESSION['protokoll_delete_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } elseif (!$isUploader) {
        $error = 'Keine Berechtigung zum Löschen.';
    } else {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            $error = 'Ungültige Protokoll-ID.';
        } else {
            // Berechtigung vor der endgültigen Löschung serverseitig erneut prüfen.
            $stmt = mysqli_prepare($conn, 'SELECT uid, turm FROM protokolle WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $pUid, $pTurm);
            $found = mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);

            if (!$found) {
                $error = 'Protokoll nicht gefunden.';
            } elseif (!canDeleteProtokoll(strtolower((string)$pTurm), (int)$pUid, $isSchrift, $isTvKSprecher, $isWebmaster, $currentUid)) {
                $error = 'Keine Berechtigung, dieses Protokoll zu löschen.';
            } else {
                // Wie bisher wird der DB-Eintrag gelöscht; die Datei bleibt für vorhandene Links liegen.
                $stmt = mysqli_prepare($conn, 'DELETE FROM protokolle WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $id);
                if (mysqli_stmt_execute($stmt)) {
                    $notice = 'Protokoll aus der Übersicht gelöscht.';
                } else {
                    $error = 'Fehler beim Löschen des Protokolls.';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$protokolle = ['weh' => [], 'tvk' => []];
$stmt = mysqli_prepare($conn,
    'SELECT id, uid, turm, type, versammlungszeit, pfad FROM protokolle ORDER BY versammlungszeit DESC, id DESC'
);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $id, $uid, $turm, $type, $vzeit, $path);
while (mysqli_stmt_fetch($stmt)) {
    $turm = strtolower((string)$turm);
    if (!isset($protokolle[$turm])) {
        continue;
    }
    $protokolle[$turm][] = [
        'id' => (int)$id,
        'uid' => (int)$uid,
        'type' => (int)$type,
        'date' => (int)$vzeit,
        'path' => (string)$path,
    ];
}
mysqli_stmt_close($stmt);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Protokolle – WEH/TvK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .protokolle-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 28px;
            width: min(95%, 1700px);
            margin: 40px auto;
        }
        .protokolle-panel {
            --proto-accent: #11a50d;
            min-width: 0;
        }
        .protokolle-panel.tvk { --proto-accent: #FFA500; }
        .protokolle-panel h2 {
            text-align: center;
            color: var(--proto-accent);
            font-size: 30px;
            margin: 0 0 15px;
        }
        .uploadproto-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #1e1e1e;
            color: white;
            border: 2px solid var(--proto-accent);
            border-radius: 8px;
            overflow: hidden;
        }
        .uploadproto-table th,
        .uploadproto-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #333;
            font-size: 16px;
        }
        .uploadproto-table th {
            background: var(--proto-accent);
            color: #1e1e1e;
            font-size: 18px;
        }
        .uploadproto-table tr:last-child td { border-bottom: none; }
        .uploadproto-table tbody tr:hover { background: #292929; }
        .uploadproto-table .date-cell { white-space: nowrap; }
        .uploadproto-table .delete-cell { width: 30px; padding: 10px; }
        .uploadproto-table a {
            color: white;
            text-decoration: none;
            display: block;
        }
        .uploadproto-table a:hover { color: var(--proto-accent); }
        .uploadproto-trash-button {
            background: transparent;
            border: 0;
            padding: 0;
            cursor: pointer;
        }
        .uploadproto-trash-button:hover { opacity: 0.65; }
        .uploadproto-trash-icon { width: 24px; height: 24px; display: block; }
        .protokolle-message { text-align: center; color: white; font-size: 18px; margin: 12px auto; }
        .protokolle-error { color: #ff7777; }
        .protokolle-empty { text-align: center !important; color: #aaa; }
        .proto-confirm {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            width: min(90vw, 450px);
            box-sizing: border-box;
            text-align: center;
            color: #fff;
        }
        .proto-confirm p { font-size: 23px; }
        .proto-confirm-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .proto-confirm-actions button { font-size: 20px; }
        @media (max-width: 1000px) {
            .protokolle-grid { grid-template-columns: 1fr; width: min(95%, 750px); }
        }
        @media (max-width: 480px) {
            .uploadproto-table th, .uploadproto-table td { padding: 10px 8px; font-size: 14px; }
            .uploadproto-table th { font-size: 15px; }
        }
    </style>
</head>
<body>
<?= $templateOutput ?>
<?php load_menu(); ?>
<?php if ($error !== ''): ?>
    <p class="protokolle-message protokolle-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php elseif ($notice !== ''): ?>
    <p class="protokolle-message"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="protokolle-grid">
    <?php foreach (['weh' => 'WEH', 'tvk' => 'TvK'] as $tower => $title): ?>
        <section class="protokolle-panel <?= $tower ?>">
            <h2><?= $title ?></h2>
            <table class="uploadproto-table">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th>Datum</th>
                        <?php if ($isUploader): ?><th aria-label="Aktionen"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$protokolle[$tower]): ?>
                        <tr><td class="protokolle-empty" colspan="<?= $isUploader ? 3 : 2 ?>">Keine Protokolle vorhanden.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($protokolle[$tower] as $row): ?>
                        <?php
                            $typeLabel = $typeStrings[$row['type']] ?? 'Unbekannte Versammlungsart';
                            $canDelete = canDeleteProtokoll($tower, $row['uid'], $isSchrift, $isTvKSprecher, $isWebmaster, $currentUid);
                            // Nur im Protokollordner abgelegte PDFs verlinken.
                            $hasValidPath = (bool)preg_match('~^protokolle/[a-zA-Z0-9_ .-]+\.pdf$~i', $row['path']);
                        ?>
                        <tr>
                            <td>
                                <?php if ($hasValidPath): ?>
                                    <a href="<?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <td class="date-cell">
                                <?php if ($hasValidPath): ?>
                                    <a href="<?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= date('d.m.Y', $row['date']) ?></a>
                                <?php else: ?>
                                    <?= date('d.m.Y', $row['date']) ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($isUploader): ?>
                                <td class="delete-cell">
                                    <?php if ($canDelete): ?>
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['protokoll_delete_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" name="delete_protokoll" value="<?= $row['id'] ?>" class="uploadproto-trash-button" title="Protokoll löschen" aria-label="Protokoll löschen">
                                                <img src="images/trash_white.png" class="uploadproto-trash-icon" alt="">
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
</div>

<?php if ($confirmRow !== null): ?>
    <div class="overlay"></div>
    <div class="anmeldung-form-container form-container proto-confirm">
        <form method="get" action="Protokolle.php">
            <button type="submit" class="close-btn" aria-label="Schließen">X</button>
        </form>
        <p>Protokoll wirklich löschen?</p>
        <p style="font-size:17px;">
            <?= $confirmRow['turm'] === 'tvk' ? 'TvK' : 'WEH' ?> –
            <?= htmlspecialchars($typeStrings[$confirmRow['type']] ?? 'Protokoll', ENT_QUOTES, 'UTF-8') ?><br>
            <?= date('d.m.Y', $confirmRow['date']) ?>
        </p>
        <div class="proto-confirm-actions">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['protokoll_delete_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $confirmRow['id'] ?>">
                <button type="submit" name="really_delete_protokoll" value="1" class="red-center-btn">Löschen</button>
            </form>
            <form method="get" action="Protokolle.php">
                <button type="submit" class="center-btn">Abbrechen</button>
            </form>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>
