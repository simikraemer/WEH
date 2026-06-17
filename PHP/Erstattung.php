<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: sans-serif;
        }

        .requests-container {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1em;
            padding: 2em;
        }

        .request-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35em;
            min-width: 260px;
            padding: 1em 2em;
            background: #252525;
            color: #e0ffe0;
            border: 1px solid #11a50d;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
            text-align: center;
        }

        .request-button:hover {
            background-color: #11a50d;
        }

        .request-button-main {
            font-size: 1em;
            line-height: 1.25;
        }

        .request-button-sub {
            font-size: 0.88em;
            line-height: 1.25;
            color: #bdbdbd;
            font-weight: 600;
        }

        .empty-requests {
            width: 100%;
            text-align: center;
            color: #ffffff;
            font-size: 1.2em;
            padding: 2em 0;
        }

        .refund-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.72);
            z-index: 1000;
        }

        .refund-overlay.is-open {
            display: block;
        }

        .refund-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(1450px, calc(100vw - 40px));
            max-height: calc(100vh - 40px);
            background: #1c1c1c;
            border: 2px solid #11a50d;
            border-radius: 12px;
            z-index: 1001;
            box-shadow: 0 0 24px rgba(17, 165, 13, 0.35);
            overflow: hidden;
        }

        .refund-modal.is-open {
            display: flex;
            flex-direction: column;
        }

        .refund-modal-close {
            position: absolute;
            top: 14px;
            right: 18px;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.9em;
            line-height: 1;
            cursor: pointer;
            z-index: 2;
        }

        .refund-modal-close:hover {
            color: #ff5555;
        }

        .refund-modal-body {
            display: grid;
            grid-template-columns: minmax(420px, 1.6fr) minmax(360px, 1fr);
            gap: 28px;
            align-items: center;
            padding: 46px 32px 24px 32px;
            min-height: 620px;
            overflow: auto;
        }

        .refund-document-panel,
        .refund-data-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #151515;
            border: 1px solid #333;
            border-radius: 10px;
            min-height: 560px;
            box-sizing: border-box;
        }

        .refund-document-panel {
            padding: 14px;
        }

        .refund-data-panel {
            align-items: center;
            padding: 28px;
        }

        .refund-file-viewer {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 500px;
            width: 100%;
            background: #0f0f0f;
            border: 1px solid #2f2f2f;
            border-radius: 8px;
            overflow: hidden;
        }

        .refund-file-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: #fff;
        }

        .refund-file-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .refund-file-empty {
            color: #aaa;
            text-align: center;
            padding: 2em;
        }

        .refund-data-content {
            width: 100%;
            max-width: 430px;
            margin: auto;
        }

        .refund-meta {
            color: #fff;
            text-align: center;
            margin-bottom: 20px;
            line-height: 1.45;
            font-size: 1em;
        }

        .refund-copy-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
        }

        .refund-copy-row {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            width: 100%;
        }

        .refund-copy-label {
            color: #a0ffa0;
            font-size: 0.95em;
            text-align: right;
            white-space: nowrap;
        }

        .refund-copy-button,
        .refund-mail-button {
            width: 100%;
            min-height: 40px;
            padding: 0.72em 0.9em;
            background: #252525;
            border: 1px solid #11a50d;
            color: #fff;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            text-align: left;
            transition: background-color 0.2s ease, color 0.2s ease;
            word-break: break-word;
            box-sizing: border-box;
            font-size: 0.95em;
        }

        .refund-copy-button:hover,
        .refund-mail-button:hover {
            background: #11a50d;
        }

        .refund-copy-button.copied {
            background: #11a50d;
            color: #000;
        }

        .refund-mail-button {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .refund-modal-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 18px;
            padding: 0 32px 30px 32px;
        }

        .refund-action-button {
            min-width: 170px;
            padding: 0.8em 1.6em;
            font-weight: bold;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1em;
            transition: transform 0.08s ease, filter 0.2s ease;
        }

        .refund-action-button:hover {
            filter: brightness(1.1);
        }

        .refund-action-button:active {
            transform: translateY(1px);
        }

        .refund-accept-button {
            background: #11a50d;
            color: #000;
        }

        .refund-decline-button {
            background: #a50d11;
            color: #fff;
        }

        .processed-banner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.9);
            padding: 1em 2em;
            color: white;
            font-size: 2em;
            font-weight: bold;
            border: 2px solid #11a50d;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 0 10px #11a50d;
        }

        .charts-wrapper {
            display: flex;
            justify-content: center;
            margin: 2em 0;
            padding: 0 2em;
        }

        .charts-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2em;
            width: 100%;
            max-width: 1500px;
        }

        .chart-box {
            flex: 1 1 calc(50% - 1em);
            min-width: 420px;
            height: 250px;
            background: #181818;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 12px;
            box-sizing: border-box;
        }

        .chart-box-wide {
            flex-basis: 100%;
        }

        .chart-box canvas {
            width: 100% !important;
            height: 100% !important;
        }

        @media (max-width: 900px) {
            .refund-modal-body {
                grid-template-columns: 1fr;
                min-height: unset;
            }

            .refund-document-panel,
            .refund-data-panel {
                min-height: unset;
            }

            .refund-file-viewer {
                height: 420px;
            }

            .refund-copy-row {
                grid-template-columns: 1fr;
            }

            .refund-copy-label {
                text-align: left;
            }

            .refund-modal-actions {
                flex-wrap: wrap;
            }

            .chart-box {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (!function_exists('erstattung_h')) {
    function erstattung_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('buildUserRoomEmail')) {
    function buildUserRoomEmail($room, $turm): string {
        $roomPart = str_pad((string)intval($room), 4, '0', STR_PAD_LEFT);
        $turmPart = strtolower(trim((string)$turm));

        return 'z' . $roomPart . '@' . $turmPart . '.rwth-aachen.de';
    }
}

if (!function_exists('sendRefundMail')) {
    function sendRefundMail(string $to, string $subject, string $message): bool {
        $from = 'vorstand@weh.rwth-aachen.de';

        $headers  = "From: WEH Vorstand <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        return mail($to, $subject, $message, $headers);
    }
}

$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        (!empty($_SESSION['Vorstand']) && $_SESSION['Vorstand'] > 0)
        || (!empty($_SESSION['Webmaster']) && $_SESSION['Webmaster'] > 0)
    );

if (!$berechtigt) {
    echo '<script>window.location.href = "denied.php";</script>';
    exit;
}

$processedMessage = '';

if (
    isset($_POST["reload"], $_POST['action'], $_POST['request_id'])
    && $_POST["reload"] == 1
    && in_array($_POST['action'], ['accept', 'decline'], true)
) {
    $reqId = intval($_POST['request_id']);
    $action = $_POST['action'];

    if ($action === 'accept') {
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.name, u.turm, u.room, e.einrichtung, e.betrag, e.iban, e.pfad
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ? AND e.status = 0
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, "i", $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $userUid, $name, $turm, $room, $einrichtung, $betrag, $iban, $pfad);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($found) {
            $formattedEinrichtung = formatEinrichtung($einrichtung, $conn);

            $upd = mysqli_prepare($conn, "
                UPDATE erstattung
                SET status = 1
                WHERE id = ? AND status = 0
            ");
            mysqli_stmt_bind_param($upd, "i", $reqId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            $transferUid  = 472;
            $ts           = time();
            $beschreibung = sprintf("Erstattung: %s, IBAN: %s", $formattedEinrichtung, $iban);
            $konto        = 8;
            $kasse        = 92;
            $negBetrag    = -1 * (float)$betrag;

            $insert = mysqli_prepare($conn, "
                INSERT INTO transfers
                    (uid, tstamp, beschreibung, konto, kasse, betrag, pfad)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param(
                $insert,
                "iisiids",
                $transferUid,
                $ts,
                $beschreibung,
                $konto,
                $kasse,
                $negBetrag,
                $pfad
            );
            mysqli_stmt_execute($insert);
            mysqli_stmt_close($insert);

            $to = buildUserRoomEmail($room, $turm);
            $subject = "Reimbursement request approved";
            $message = "Hello {$name},\n\n"
                . "Your reimbursement request for " . number_format((float)$betrag, 2, ',', '.') . " EUR has been approved.\n"
                . "The amount will be transferred to your IBAN shortly.\n\n"
                . "IBAN: {$iban}\n"
                . "Purpose: {$formattedEinrichtung} reimbursement\n\n"
                . "Best regards\n"
                . "WEH Vorstand/Kasse";

            $mailSent = sendRefundMail($to, $subject, $message);

            $processedMessage = $mailSent
                ? 'Antrag genehmigt. Mail versendet.'
                : 'Antrag genehmigt. Mail konnte nicht versendet werden.';
        } else {
            $processedMessage = 'Antrag nicht gefunden oder bereits verarbeitet.';
        }
    }

    if ($action === 'decline') {
        $stmt = mysqli_prepare($conn, "
            SELECT e.uid, u.name, u.turm, u.room
            FROM erstattung e
            JOIN users u ON e.uid = u.uid
            WHERE e.id = ? AND e.status = 0
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, "i", $reqId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $turm, $room);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($found) {
            $upd = mysqli_prepare($conn, "
                UPDATE erstattung
                SET status = -1
                WHERE id = ? AND status = 0
            ");
            mysqli_stmt_bind_param($upd, "i", $reqId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            $to = buildUserRoomEmail($room, $turm);
            $subject = "Reimbursement request declined";
            $message = "Hello {$name},\n\n"
                . "Your reimbursement request has been declined.\n"
                . "Please contact the Vorstand at vorstand@weh.rwth-aachen.de if you have any questions.\n\n"
                . "Best regards\n"
                . "WEH Vorstand/Kasse";

            $mailSent = sendRefundMail($to, $subject, $message);

            $processedMessage = $mailSent
                ? 'Antrag abgelehnt. Mail versendet.'
                : 'Antrag abgelehnt. Mail konnte nicht versendet werden.';
        } else {
            $processedMessage = 'Antrag nicht gefunden oder bereits verarbeitet.';
        }
    }
}

load_menu();

if ($processedMessage !== '') {
    echo '<div class="processed-banner">' . erstattung_h($processedMessage) . '</div>';
    echo '<style>html, body { cursor: wait; }</style>';
    echo '<script>
        setTimeout(function() {
            window.location.href = window.location.pathname;
        }, 1200);
    </script>';
}

$openRequests = [];

$sql = "
    SELECT e.id, e.uid, u.name, e.tstamp, e.einrichtung, e.betrag, e.iban, e.pfad, u.turm, u.room
    FROM erstattung e
    JOIN users u ON e.uid = u.uid
    WHERE e.status = 0
    ORDER BY e.tstamp DESC
";
$res = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($res)) {
    $formattedEinrichtung = formatEinrichtung($row['einrichtung'], $conn);
    $datum = date("d.m.Y", (int)$row['tstamp']);
    $betragDisplay = number_format((float)$row['betrag'], 2, ',', '.') . ' €';
    $mailto = buildUserRoomEmail($row['room'], $row['turm']);

    $pfad = (string)$row['pfad'];
    $pathOnly = parse_url($pfad, PHP_URL_PATH);

    if ($pathOnly === null || $pathOnly === false) {
        $pathOnly = $pfad;
    }

    $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
    $fileType = 'other';

    if ($ext === 'pdf') {
        $fileType = 'pdf';
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $fileType = 'image';
    }

    $openRequests[] = [
        'id' => (int)$row['id'],
        'label' => $datum . ' • ' . $formattedEinrichtung,
        'datum' => $datum,
        'name' => (string)$row['name'],
        'iban' => (string)$row['iban'],
        'betrag' => (float)$row['betrag'],
        'betragDisplay' => $betragDisplay,
        'einrichtung' => $formattedEinrichtung,
        'verwendungszweck' => $formattedEinrichtung . ' Erstattung',
        'pfad' => $pfad,
        'fileType' => $fileType,
        'email' => $mailto,
        'mailto' => 'mailto:' . $mailto,
    ];
}

if ($res) {
    mysqli_free_result($res);
}

$agLabels = [];
$agData   = [];

$sql = "
    SELECT id, name
    FROM `groups`
    WHERE active = 1
      AND agessen = 1
    ORDER BY prio
";
$res = mysqli_query($conn, $sql);

while ($ag = mysqli_fetch_assoc($res)) {
    $agId = (int)$ag['id'];
    $agLabels[] = $ag['name'];

    $sumSql = "
        SELECT COALESCE(SUM(betrag), 0) AS summe
        FROM erstattung
        WHERE status = 1
          AND einrichtung = 'ag:{$agId}'
    ";
    $sumRes = mysqli_query($conn, $sumSql);
    $sumRow = mysqli_fetch_assoc($sumRes);
    $agData[] = (float)$sumRow['summe'];
    mysqli_free_result($sumRes);
}

if ($res) {
    mysqli_free_result($res);
}

$wehSums = array_fill(0, 18, 0.0);

$sql = "
    SELECT e.einrichtung, SUM(e.betrag) AS summe
    FROM erstattung e
    WHERE e.status = 1
      AND e.einrichtung LIKE 'etage:weh\\_%'
    GROUP BY e.einrichtung
";
$res = mysqli_query($conn, $sql);

while ($r = mysqli_fetch_assoc($res)) {
    if (preg_match('/^etage:weh_(\d+)$/', $r['einrichtung'], $m)) {
        $idx = intval($m[1]);
        if ($idx >= 0 && $idx <= 17) {
            $wehSums[$idx] = (float)$r['summe'];
        }
    }
}

if ($res) {
    mysqli_free_result($res);
}

$tvkSums = array_fill(0, 16, 0.0);

$sql = "
    SELECT e.einrichtung, SUM(e.betrag) AS summe
    FROM erstattung e
    WHERE e.status = 1
      AND e.einrichtung LIKE 'etage:tvk\\_%'
    GROUP BY e.einrichtung
";
$res = mysqli_query($conn, $sql);

while ($r = mysqli_fetch_assoc($res)) {
    if (preg_match('/^etage:tvk_(\d+)$/', $r['einrichtung'], $m)) {
        $idx = intval($m[1]);
        if ($idx >= 0 && $idx <= 15) {
            $tvkSums[$idx] = (float)$r['summe'];
        }
    }
}

if ($res) {
    mysqli_free_result($res);
}
?>

<div class="requests-container">
    <?php if (count($openRequests) === 0): ?>
        <div class="empty-requests">Keine neuen Anträge</div>
    <?php else: ?>
        <?php foreach ($openRequests as $request): ?>
            <button
                type="button"
                class="request-button"
                onclick="openRequestModal(<?= (int)$request['id'] ?>)"
            >
                <span class="request-button-main">
                    <?= erstattung_h($request['label']) ?>
                </span>
                <span class="request-button-sub">
                    <?= erstattung_h($request['name']) ?> · <?= erstattung_h($request['betragDisplay']) ?>
                </span>
            </button>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="refundOverlay" class="refund-overlay" onclick="closeRequestModal()"></div>

<div id="refundModal" class="refund-modal" role="dialog" aria-modal="true">
    <button type="button" class="refund-modal-close" onclick="closeRequestModal()">×</button>

    <div class="refund-modal-body">
        <div class="refund-document-panel">
            <div id="refundFileViewer" class="refund-file-viewer">
                <div class="refund-file-empty">Keine Rechnung ausgewählt.</div>
            </div>
        </div>

        <div class="refund-data-panel">
            <div class="refund-data-content">
                <div class="refund-meta">
                    <div id="modalDate"></div>
                    <div id="modalFacility"></div>
                </div>

                <div class="refund-copy-list">
                    <div class="refund-copy-row">
                        <div class="refund-copy-label">Name</div>
                        <button type="button" class="refund-copy-button" id="copyName"></button>
                    </div>

                    <div class="refund-copy-row">
                        <div class="refund-copy-label">IBAN</div>
                        <button type="button" class="refund-copy-button" id="copyIban"></button>
                    </div>

                    <div class="refund-copy-row">
                        <div class="refund-copy-label">Betrag</div>
                        <button type="button" class="refund-copy-button" id="copyAmount"></button>
                    </div>

                    <div class="refund-copy-row">
                        <div class="refund-copy-label">Zweck</div>
                        <button type="button" class="refund-copy-button" id="copyPurpose"></button>
                    </div>

                    <div class="refund-copy-row">
                        <div class="refund-copy-label">E-Mail</div>
                        <a class="refund-mail-button" id="modalMailLink" href="#">Mail an Nutzer</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="post" class="refund-modal-actions" id="refundActionForm">
        <input type="hidden" name="request_id" id="modalRequestId" value="">
        <input type="hidden" name="reload" value="1">

        <button type="submit" name="action" value="accept" class="refund-action-button refund-accept-button">
            Überwiesen
        </button>

        <button type="submit" name="action" value="decline" class="refund-action-button refund-decline-button">
            Ablehnen
        </button>
    </form>
</div>

<div class="charts-wrapper">
    <div class="charts-grid">
        <div class="chart-box">
            <canvas id="chartWeh"></canvas>
        </div>

        <div class="chart-box">
            <canvas id="chartTvk"></canvas>
        </div>

        <div class="chart-box chart-box-wide">
            <canvas id="chartAG"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const refundRequests = <?= json_encode(
    $openRequests,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;

const refundRequestsById = {};
refundRequests.forEach(function(request) {
    refundRequestsById[String(request.id)] = request;
});

function setCopyButton(buttonId, value) {
    const button = document.getElementById(buttonId);
    button.textContent = value || '-';
    button.dataset.copy = value || '';
    button.classList.remove('copied');
}

function copyTextWithFallback(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    return new Promise(function(resolve, reject) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            document.execCommand('copy');
            document.body.removeChild(textarea);
            resolve();
        } catch (error) {
            document.body.removeChild(textarea);
            reject(error);
        }
    });
}

document.querySelectorAll('.refund-copy-button').forEach(function(button) {
    button.addEventListener('click', function() {
        const value = button.dataset.copy || '';
        const oldText = button.textContent;

        copyTextWithFallback(value).then(function() {
            button.classList.add('copied');
            button.textContent = 'Kopiert!';

            setTimeout(function() {
                button.classList.remove('copied');
                button.textContent = oldText;
            }, 800);
        });
    });
});

function getSafeDocumentUrl(url) {
    const cleaned = String(url || '').trim();

    if (
        cleaned === ''
        || /^javascript:/i.test(cleaned)
        || /^data:/i.test(cleaned)
        || /^vbscript:/i.test(cleaned)
    ) {
        return '';
    }

    return cleaned;
}

function renderDocument(request) {
    const viewer = document.getElementById('refundFileViewer');
    const fileUrl = getSafeDocumentUrl(request.pfad);

    viewer.innerHTML = '';

    if (!fileUrl) {
        const empty = document.createElement('div');
        empty.className = 'refund-file-empty';
        empty.textContent = 'Keine Rechnung hinterlegt.';
        viewer.appendChild(empty);
        return;
    }

    if (request.fileType === 'image') {
        const img = document.createElement('img');
        img.className = 'refund-file-image';
        img.src = fileUrl;
        img.alt = 'Rechnung';
        viewer.appendChild(img);
        return;
    }

    const frame = document.createElement('iframe');
    frame.className = 'refund-file-frame';
    frame.src = request.fileType === 'pdf' ? fileUrl + '#toolbar=1&navpanes=0' : fileUrl;
    frame.title = 'Rechnung';
    viewer.appendChild(frame);
}

function openRequestModal(id) {
    const request = refundRequestsById[String(id)];

    if (!request) {
        return;
    }

    document.getElementById('modalRequestId').value = request.id;
    document.getElementById('modalDate').textContent = 'Datum: ' + request.datum;
    document.getElementById('modalFacility').textContent = 'Einrichtung: ' + request.einrichtung;

    setCopyButton('copyName', request.name);
    setCopyButton('copyIban', request.iban);
    setCopyButton('copyAmount', request.betragDisplay);
    setCopyButton('copyPurpose', request.verwendungszweck);

    const mailLink = document.getElementById('modalMailLink');
    mailLink.href = request.mailto || '#';
    mailLink.textContent = request.email ? 'Mail an ' + request.email : 'Mail an Nutzer';

    renderDocument(request);

    document.getElementById('refundOverlay').classList.add('is-open');
    document.getElementById('refundModal').classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeRequestModal() {
    document.getElementById('refundOverlay').classList.remove('is-open');
    document.getElementById('refundModal').classList.remove('is-open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeRequestModal();
    }
});

const agLabels = <?= json_encode($agLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const agData = <?= json_encode($agData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const wehLabels = Array.from({ length: 18 }, function(_, i) {
    return i.toString();
});
const wehData = <?= json_encode(array_values($wehSums), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const tvkLabels = Array.from({ length: 16 }, function(_, i) {
    return i.toString();
});
const tvkData = <?= json_encode(array_values($tvkSums), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

Chart.defaults.color = '#e0e0e0';
Chart.defaults.borderColor = '#333';

function makeBarChart(ctxId, labels, data, title, color) {
    return new Chart(document.getElementById(ctxId).getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '€',
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: title
                },
                legend: {
                    display: false
                }
            }
        }
    });
}

new Chart(document.getElementById('chartWeh').getContext('2d'), {
    type: 'bar',
    data: {
        labels: wehLabels,
        datasets: [
            {
                label: '€',
                data: wehData,
                backgroundColor: '#11a50d',
                borderColor: '#11a50d',
                borderWidth: 1
            },
            {
                type: 'line',
                label: 'Limit 170€',
                data: wehLabels.map(function() {
                    return 170;
                }),
                borderColor: 'red',
                borderWidth: 2,
                fill: false,
                pointRadius: 0
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        scales: {
            x: {
                ticks: {
                    maxRotation: 45,
                    minRotation: 0
                }
            },
            y: {
                beginAtZero: true
            }
        },
        plugins: {
            title: {
                display: true,
                text: 'WEH Etagen 0-17 (€)'
            },
            legend: {
                display: false
            }
        }
    }
});

new Chart(document.getElementById('chartTvk').getContext('2d'), {
    type: 'bar',
    data: {
        labels: tvkLabels,
        datasets: [
            {
                label: '€',
                data: tvkData,
                backgroundColor: '#E49B0F',
                borderColor: '#E49B0F',
                borderWidth: 1
            },
            {
                type: 'line',
                label: 'Limit 170€',
                data: tvkLabels.map(function() {
                    return 170;
                }),
                borderColor: 'red',
                borderWidth: 2,
                fill: false,
                pointRadius: 0
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        scales: {
            x: {
                ticks: {
                    maxRotation: 45,
                    minRotation: 0
                }
            },
            y: {
                beginAtZero: true
            }
        },
        plugins: {
            title: {
                display: true,
                text: 'TVK Etagen 0-15 (€)'
            },
            legend: {
                display: false
            }
        }
    }
});

makeBarChart('chartAG', agLabels, agData, 'AG-Erstattungen (€)', '#007bff');
</script>

</body>
</html>