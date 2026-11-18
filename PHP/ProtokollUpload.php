<?php
ob_start(); // template.php und auth() erzeugen sonst HTML vor dem DOCTYPE.
// ProtokollUpload.php – gemeinsamer Upload für WEH und TvK
require_once __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8');

if (!auth($conn) || empty($_SESSION['valid']) || !(
    !empty($_SESSION['Schrift']) ||
    !empty($_SESSION['TvK-Sprecher']) ||
    !empty($_SESSION['Webmaster'])
)) {
    ob_end_clean();
    http_response_code(403);
    echo '<p style="color:white; text-align:center;">Keine Berechtigung zum Hochladen von Protokollen.</p>';
    exit;
}

$templateOutput = str_replace('<title>WEH Backend</title>', '', ob_get_clean());

$typeStrings = [
    'weh' => [
        0 => 'Ordentliche Vollversammlung',
        1 => 'Außerordentliche Vollversammlung',
        2 => 'Ordentlicher Haussenat',
        3 => 'Außerordentlicher Haussenat',
    ],
    'tvk' => [
        2 => 'Ordentlicher Haussenat',
        3 => 'Außerordentlicher Haussenat',
        4 => 'Aktiventreffen',
    ],
];

$defaultTurm = strtolower((string)($_SESSION['turm'] ?? 'weh')) === 'tvk' ? 'tvk' : 'weh';
$selectedTurm = strtolower((string)($_POST['turm'] ?? $_GET['turm'] ?? $defaultTurm));
if (!isset($typeStrings[$selectedTurm])) {
    $selectedTurm = $defaultTurm;
}

if (empty($_SESSION['protokoll_upload_csrf'])) {
    $_SESSION['protokoll_upload_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';
$selectedType = isset($_POST['type']) && ctype_digit((string)$_POST['type'])
    ? (int)$_POST['type']
    : ($selectedTurm === 'tvk' ? 2 : 0);
$selectedDate = (string)($_POST['vzeit'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sendpost'])) {
    if (!hash_equals($_SESSION['protokoll_upload_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    }

    $postedTurm = strtolower(trim((string)($_POST['turm'] ?? '')));
    if (!in_array($postedTurm, ['weh', 'tvk'], true)
        || !isset($_POST['type'])
        || !ctype_digit((string)$_POST['type'])
        || !array_key_exists($selectedType, $typeStrings[$selectedTurm])) {
        $errors[] = 'Ungültige Auswahl von Turm oder Versammlungsart.';
    }

    // Datum ohne automatische Korrektur unzulässiger Angaben prüfen.
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
    if (!$date || $date->format('Y-m-d') !== $selectedDate) {
        $errors[] = 'Bitte ein gültiges Versammlungsdatum auswählen.';
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Es wurde keine PDF-Datei erfolgreich hochgeladen.';
    } else {
        $upload = $_FILES['file'];
        if (!is_uploaded_file($upload['tmp_name']) || (int)$upload['size'] <= 0) {
            $errors[] = 'Die hochgeladene Datei ist leer oder ungültig.';
        } else {
            $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $handle = fopen($upload['tmp_name'], 'rb');
            $header = $handle ? fread($handle, 5) : false;
            if ($handle) {
                fclose($handle);
            }
            if ($extension !== 'pdf' || $mime !== 'application/pdf' || $header !== '%PDF-') {
                $errors[] = 'Nur gültige PDF-Dateien sind erlaubt.';
            }
        }
    }

    if (!$errors) {
        $uploadDir = __DIR__ . '/protokolle';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $errors[] = 'Der Protokollordner konnte nicht angelegt werden.';
        } elseif (!is_writable($uploadDir)) {
            $errors[] = 'Der Protokollordner ist nicht beschreibbar.';
        }
    }

    if (!$errors) {
        // Eindeutige Dateinamen vermeiden Kollisionen mit vorhandenen WEH-Dateien
        // sowie erneuten Uploads zum selben Versammlungsdatum.
        $nameParts = [0 => 'VV', 1 => 'VV', 2 => 'Senat', 3 => 'Senat', 4 => 'Aktiventreffen'];
        $fileName = $date->format('Ymd') . '_' . strtoupper($selectedTurm) . '_'
            . $nameParts[$selectedType] . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $relativePath = 'protokolle/' . $fileName;
        $absolutePath = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $absolutePath)) {
            $errors[] = 'Fehler beim Speichern der PDF-Datei.';
        } else {
            $uid = (int)$_SESSION['uid'];
            $now = time();
            $versammlung = $date->getTimestamp();
            $stmt = mysqli_prepare($conn,
                'INSERT INTO protokolle (uid, tstamp, type, versammlungszeit, pfad, turm) VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                $errors[] = 'Datenbankfehler beim Vorbereiten des Eintrags.';
                unlink($absolutePath);
            } else {
                mysqli_stmt_bind_param($stmt, 'iiiiss',
                    $uid, $now, $selectedType, $versammlung, $relativePath, $selectedTurm
                );
                if (!mysqli_stmt_execute($stmt)) {
                    $errors[] = 'Der Datenbankeintrag konnte nicht gespeichert werden.';
                    unlink($absolutePath);
                } else {
                    $success = 'Protokoll erfolgreich für ' . ($selectedTurm === 'tvk' ? 'TvK' : 'WEH') . ' veröffentlicht.';
                    $selectedDate = '';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$accent = $selectedTurm === 'tvk' ? '#FFA500' : '#11a50d';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Protokoll hochladen – WEH/TvK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .uploadproto-container {
            --proto-accent: <?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;
            max-width: 700px;
            margin: 40px auto;
            background-color: #1e1e1e;
            color: #fff;
            padding: 30px;
            border-radius: 12px;
            border: 2px solid var(--proto-accent);
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }
        .uploadproto-title {
            font-size: 30px;
            text-align: center;
            color: var(--proto-accent);
            margin: 0 0 15px;
        }
        .uploadproto-subtitle {
            font-size: 22px;
            text-align: center;
            margin: 0 0 30px;
        }
        .uploadproto-form-group {
            margin-bottom: 25px;
        }
        .uploadproto-label {
            display: block;
            font-size: 20px;
            margin-bottom: 8px;
        }
        .uploadproto-input, .uploadproto-select {
            width: 100%;
            padding: 12px;
            font-size: 18px;
            border-radius: 6px;
            border: 1px solid #444;
            background-color: #2a2a2a;
            color: white;
            box-sizing: border-box;
        }
        .uploadproto-input[type="file"] { padding: 8px; }
        .uploadproto-button {
            background-color: var(--proto-accent);
            color: #000;
            border: none;
            padding: 14px 26px;
            font-size: 22px;
            border-radius: 6px;
            cursor: pointer;
            display: block;
            margin: 35px auto 0;
        }
        .uploadproto-button:hover { filter: brightness(1.15); }
        .uploadproto-message {
            max-width: 700px;
            margin: 15px auto;
            font-size: 18px;
            text-align: center;
        }
        .uploadproto-message.error { color: #ff7272; }
        .uploadproto-message.success { color: #77d979; }
        @media (max-width: 740px) {
            .uploadproto-container { margin: 24px 12px; padding: 20px; }
        }
    </style>
</head>
<body>
<?= $templateOutput ?>
<?php load_menu(); ?>
<?php foreach ($errors as $error): ?>
    <p class="uploadproto-message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endforeach; ?>
<?php if ($success !== ''): ?>
    <p class="uploadproto-message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="uploadproto-container" id="uploadproto-container">
    <h1 class="uploadproto-title">Protokoll hochladen</h1>
    <p class="uploadproto-subtitle">Nach dem Upload wird das Protokoll direkt veröffentlicht!</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['protokoll_upload_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="uploadproto-form-group">
            <label for="turm" class="uploadproto-label">Turm:</label>
            <select id="turm" name="turm" class="uploadproto-select" required>
                <option value="weh" <?= $selectedTurm === 'weh' ? 'selected' : '' ?>>WEH</option>
                <option value="tvk" <?= $selectedTurm === 'tvk' ? 'selected' : '' ?>>TvK</option>
            </select>
        </div>
        <div class="uploadproto-form-group">
            <label for="type" class="uploadproto-label">Versammlungsart:</label>
            <select id="type" name="type" class="uploadproto-select" required>
                <?php foreach ($typeStrings[$selectedTurm] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $selectedType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="uploadproto-form-group">
            <label for="vzeit" class="uploadproto-label">Datum der Versammlung:</label>
            <input type="date" id="vzeit" name="vzeit" class="uploadproto-input" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="uploadproto-form-group">
            <label for="file" class="uploadproto-label">PDF-Datei:</label>
            <input type="file" id="file" name="file" accept=".pdf,application/pdf" class="uploadproto-input" required>
        </div>
        <button type="submit" name="sendpost" value="1" class="uploadproto-button">Hochladen</button>
    </form>
</div>
<script>
    const typesByTurm = <?= json_encode($typeStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const turmSelect = document.getElementById('turm');
    const typeSelect = document.getElementById('type');
    const uploadContainer = document.getElementById('uploadproto-container');
    turmSelect.addEventListener('change', function () {
        const turm = this.value;
        typeSelect.replaceChildren();
        Object.entries(typesByTurm[turm]).forEach(([type, label]) => {
            typeSelect.add(new Option(label, type));
        });
        uploadContainer.style.setProperty('--proto-accent', turm === 'tvk' ? '#FFA500' : '#11a50d');
    });
</script>
</body>
</html>
<?php $conn->close(); ?>
