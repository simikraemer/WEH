<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ob_start();
require('template.php');
$templateBootstrap = ob_get_clean();

mysqli_set_charset($conn, "utf8mb4");

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fetchSpotUsers(mysqli $conn, string $turm): array
{
    $sql = "
        SELECT
            users.uid,
            users.username,
            users.firstname,
            users.lastname,
            MIN(fahrrad.platz) AS min_platz,
            GROUP_CONCAT(DISTINCT fahrrad.platz ORDER BY fahrrad.platz SEPARATOR ', ') AS plaetze
        FROM fahrrad
        INNER JOIN users ON users.uid = fahrrad.uid
        WHERE fahrrad.platz > 0
          AND fahrrad.turm = ?
          AND users.turm = ?
          AND users.mailisactive = 1
        GROUP BY users.uid, users.username, users.firstname, users.lastname
        ORDER BY min_platz ASC, users.username ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "ss", $turm, $turm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $displayName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($displayName === '') {
            $displayName = (string)($row['username'] ?? '');
        }

        $plaetze = trim((string)($row['plaetze'] ?? ''));
        $users[] = [
            'uid' => (int)$row['uid'],
            'display_name' => $displayName,
            'plaetze' => $plaetze,
        ];
    }

    mysqli_stmt_close($stmt);

    return $users;
}

function sendSpotMail(mysqli $conn, string $turm, array $uids, string $subject, string $message, string $fromAddress): array
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    })));

    $subject = trim($subject);
    $message = trim($message);

    if ($subject === '') {
        return [
            'ok' => false,
            'message' => 'Bitte einen Betreff eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if ($message === '') {
        return [
            'ok' => false,
            'message' => 'Bitte eine Nachricht eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if (empty($uids)) {
        return [
            'ok' => false,
            'message' => 'Bitte mindestens eine Person auswählen.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $inList = implode(',', $uids);

    $sql = "
        SELECT DISTINCT
            users.uid,
            CONCAT(users.username, '@', users.turm, '.rwth-aachen.de') AS email
        FROM fahrrad
        INNER JOIN users ON users.uid = fahrrad.uid
        WHERE fahrrad.platz > 0
          AND fahrrad.turm = ?
          AND users.turm = ?
          AND users.mailisactive = 1
          AND users.uid IN ($inList)
        ORDER BY users.uid ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [
            'ok' => false,
            'message' => 'Fehler beim Vorbereiten der Empfängerliste.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    mysqli_stmt_bind_param($stmt, "ss", $turm, $turm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $emails = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if (!empty($row['email'])) {
            $emails[] = $row['email'];
        }
    }

    mysqli_stmt_close($stmt);

    $emails = array_values(array_unique($emails));

    if (empty($emails)) {
        return [
            'ok' => false,
            'message' => 'Es wurden keine gültigen Empfänger gefunden.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $headers = [];
    if ($fromAddress !== '') {
        $headers[] = 'From: ' . $fromAddress;
        $headers[] = 'Reply-To: ' . $fromAddress;
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $headerString = implode("\r\n", $headers);

    $sent = 0;
    $total = count($emails);

    foreach ($emails as $email) {
        if (@mail($email, $subject, $message, $headerString)) {
            $sent++;
        }
    }

    if ($sent === $total) {
        return [
            'ok' => true,
            'message' => 'Nachricht erfolgreich versendet.',
            'sent' => $sent,
            'total' => $total,
        ];
    }

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Nachricht teilweise versendet (' . $sent . ' von ' . $total . ').'
            : 'Fehler beim Versenden der Nachricht.',
        'sent' => $sent,
        'total' => $total,
    ];
}

$isWebmaster = !empty($_SESSION['Webmaster']);
$canWeh = !empty($_SESSION['FahrradAG']) || $isWebmaster;
$canTvk = !empty($_SESSION['TvK-Sprecher']) || $isWebmaster;

$isJsonRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    );

if (!(auth($conn) && ($canWeh || $canTvk))) {
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8', true, 403);
        echo json_encode([
            'ok' => false,
            'message' => 'Keine Berechtigung.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    header("Location: denied.php");
    exit;
}

$availableTurms = [];
if ($canWeh) {
    $availableTurms[] = 'weh';
}
if ($canTvk) {
    $availableTurms[] = 'tvk';
}

$requestTurm = isset($_GET['turm']) && $_GET['turm'] === 'tvk' ? 'tvk' : 'weh';
if (!in_array($requestTurm, $availableTurms, true)) {
    $requestTurm = $availableTurms[0];
}
$currentTurm = $requestTurm;

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Ungültige Anfrage.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $action = (string)($payload['action'] ?? '');
    if ($action !== 'send') {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Unbekannte Aktion.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $turm = (string)($payload['turm'] ?? '');
    if (!in_array($turm, $availableTurms, true)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Keine Berechtigung für diesen Turm.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $uids = $payload['uids'] ?? [];
    if (!is_array($uids)) {
        $uids = [];
    }

    $subject = (string)($payload['subject'] ?? '');
    $message = (string)($payload['message'] ?? '');
    $fromAddress = trim((string)($mailconfig['address'] ?? ''));

    $response = sendSpotMail($conn, $turm, $uids, $subject, $message, $fromAddress);
    http_response_code($response['ok'] ? 200 : 400);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$spotUsersByTurm = [];
foreach ($availableTurms as $turm) {
    $spotUsersByTurm[$turm] = fetchSpotUsers($conn, $turm);
}

$spotUsers = $spotUsersByTurm[$currentTurm] ?? [];
$pageTitle = $currentTurm === 'tvk' ? 'TvK Stellplatznachricht' : 'WEH Stellplatznachricht';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title><?php echo esc($pageTitle); ?></title>
    <style>
        .fahrradmail-page {
            display: flex;
            justify-content: center;
            padding: 32px 20px 48px;
        }

        .fahrradmail-shell {
            width: 100%;
            max-width: 1100px;
            background: rgba(10, 10, 10, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 20px;
            padding: 24px;
            box-sizing: border-box;
            backdrop-filter: blur(8px);
        }

        .fahrradmail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .fahrradmail-header h1 {
            margin: 0;
            color: #f3f3f3;
            font-size: 32px;
            line-height: 1.15;
        }

        .fahrradmail-turm {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 64px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(17, 165, 13, 0.14);
            border: 1px solid rgba(17, 165, 13, 0.32);
            color: #d8f6d7;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .fahrradmail-turm--switchable {
            cursor: pointer;
            transition: opacity 0.15s ease, transform 0.15s ease;
        }

        .fahrradmail-turm--switchable:hover {
            transform: translateY(-1px);
            opacity: 0.92;
        }

        .fahrradmail-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            color: rgba(255, 255, 255, 0.72);
            font-size: 15px;
        }

        .mail-status {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 15px;
            line-height: 1.4;
        }

        .mail-status--success {
            background: rgba(22, 22, 22, 0.95);
            border: 1px solid rgba(17, 165, 13, 0.3);
            color: #e8e8e8;
        }

        .mail-status--error {
            background: rgba(22, 22, 22, 0.95);
            border: 1px solid rgba(180, 70, 70, 0.35);
            color: #f0d7d7;
        }

        .spot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .spot-user {
            appearance: none;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.025);
            padding: 16px;
            text-align: left;
            cursor: pointer;
            transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease, opacity 0.15s ease, filter 0.15s ease;
            filter: grayscale(1);
            opacity: 0.4;
        }

        .spot-user:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.04);
        }

        .spot-user.is-selected {
            filter: grayscale(0);
            opacity: 1;
            border-color: rgba(17, 165, 13, 0.62);
            background: rgba(17, 165, 13, 0.12);
        }

        .spot-user__name {
            display: block;
            color: #f2f2f2;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 6px;
        }

        .spot-user__meta {
            display: block;
            color: rgba(255, 255, 255, 0.62);
            font-size: 14px;
            line-height: 1.35;
        }

        .spot-empty {
            border: 1px dashed rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            padding: 22px;
            color: rgba(255, 255, 255, 0.62);
            text-align: center;
            margin-bottom: 24px;
            background: rgba(255, 255, 255, 0.02);
        }

        .composer {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .composer label {
            color: #f2f2f2;
            font-size: 16px;
            font-weight: 700;
        }

        .composer input,
        .composer textarea {
            width: 100%;
            box-sizing: border-box;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            color: #f2f2f2;
            padding: 14px 16px;
        }

        .composer input {
            min-height: 52px;
        }

        .composer textarea {
            min-height: 220px;
            resize: vertical;
        }

        .composer input:focus,
        .composer textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.045);
        }

        .composer input::placeholder,
        .composer textarea::placeholder {
            color: rgba(255, 255, 255, 0.34);
        }

        .composer-actions {
            display: flex;
            justify-content: flex-end;
        }

        .send-button {
            appearance: none;
            border: 1px solid rgba(17, 165, 13, 0.65);
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            background: #11a50d;
            color: #f7fff7;
            transition: opacity 0.15s ease, transform 0.15s ease, background 0.15s ease;
        }

        .send-button:hover:not(:disabled) {
            transform: translateY(-1px);
            background: #149f11;
        }

        .send-button:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .fahrradmail-page.is-sent .fahrradmail-shell {
            display: none;
        }

        .send-success-screen {
            display: none;
            width: 100%;
            max-width: 1100px;
            min-height: 260px;
            align-items: center;
            justify-content: center;
        }

        .fahrradmail-page.is-sent .send-success-screen {
            display: flex;
        }

        .send-success-screen__text {
            color: #f3f3f3;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        @media (max-width: 720px) {
            .fahrradmail-page {
                padding: 20px 12px 36px;
            }

            .fahrradmail-shell {
                padding: 18px;
                border-radius: 16px;
            }

            .fahrradmail-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .fahrradmail-header h1 {
                font-size: 26px;
            }

            .fahrradmail-meta {
                flex-direction: column;
                align-items: flex-start;
            }

            .send-success-screen__text {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
<?php
echo $templateBootstrap;
load_menu();
?>
<main class="fahrradmail-page" id="mailPage">
    <section class="fahrradmail-shell">
        <div class="fahrradmail-header">
            <h1 id="pageHeading"><?php echo esc($pageTitle); ?></h1>
            <button
                type="button"
                id="turmSwitch"
                class="fahrradmail-turm<?php echo count($availableTurms) > 1 ? ' fahrradmail-turm--switchable' : ''; ?>"
                <?php echo count($availableTurms) > 1 ? '' : 'disabled'; ?>
            ><?php echo esc($currentTurm); ?></button>
        </div>

        <div id="mailStatus" class="mail-status" hidden></div>

        <div class="fahrradmail-meta">
            <span id="selectedCount">0 ausgewählt</span>
            <span id="availableCount"><?php echo count($spotUsers); ?> Personen verfügbar</span>
        </div>

        <div id="spotUsersWrap">
            <?php if (!empty($spotUsers)) : ?>
                <div class="spot-grid" id="spotGrid">
                    <?php foreach ($spotUsers as $spotUser) : ?>
                        <?php $platzLabel = strpos($spotUser['plaetze'], ',') !== false ? 'Stellplätze ' : 'Stellplatz '; ?>
                        <button
                            type="button"
                            class="spot-user"
                            data-uid="<?php echo (int)$spotUser['uid']; ?>"
                            aria-pressed="false"
                        >
                            <span class="spot-user__name"><?php echo esc($spotUser['display_name']); ?></span>
                            <span class="spot-user__meta"><?php echo esc($platzLabel . $spotUser['plaetze']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="spot-empty" id="spotGrid">Aktuell sind keine Stellplatznutzer vorhanden.</div>
            <?php endif; ?>
        </div>

        <div class="composer">
            <label for="mailSubject">Betreff</label>
            <input type="text" id="mailSubject" placeholder="Betreff eingeben">

            <label for="mailMessage">Nachricht</label>
            <textarea id="mailMessage" placeholder="Nachricht eingeben"></textarea>

            <div class="composer-actions">
                <button type="button" class="send-button" id="sendButton" disabled>Nachricht senden</button>
            </div>
        </div>
    </section>

    <div class="send-success-screen" id="sendSuccessScreen">
        <div class="send-success-screen__text">Gesendet</div>
    </div>
</main>

<script>
(() => {
    const page = document.getElementById('mailPage');
    const pageHeading = document.getElementById('pageHeading');
    const turmSwitch = document.getElementById('turmSwitch');
    const selectedCount = document.getElementById('selectedCount');
    const availableCount = document.getElementById('availableCount');
    const subjectField = document.getElementById('mailSubject');
    const messageField = document.getElementById('mailMessage');
    const sendButton = document.getElementById('sendButton');
    const statusBox = document.getElementById('mailStatus');
    const spotUsersWrap = document.getElementById('spotUsersWrap');

    const availableTurms = <?php echo json_encode(array_values($availableTurms), JSON_UNESCAPED_UNICODE); ?>;
    const usersByTurm = <?php echo json_encode($spotUsersByTurm, JSON_UNESCAPED_UNICODE); ?>;

    let currentTurm = <?php echo json_encode($currentTurm, JSON_UNESCAPED_UNICODE); ?>;
    const selected = new Set();

    const escapeHtml = (value) => {
        return String(value).replace(/[&<>"']/g, (char) => {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return char;
            }
        });
    };

    const clearStatus = () => {
        statusBox.hidden = true;
        statusBox.textContent = '';
        statusBox.className = 'mail-status';
    };

    const bindCardEvents = () => {
        const cards = Array.from(document.querySelectorAll('.spot-user'));

        cards.forEach((card) => {
            card.addEventListener('click', () => {
                const uid = card.dataset.uid;

                if (selected.has(uid)) {
                    selected.delete(uid);
                    card.classList.remove('is-selected');
                    card.setAttribute('aria-pressed', 'false');
                } else {
                    selected.add(uid);
                    card.classList.add('is-selected');
                    card.setAttribute('aria-pressed', 'true');
                }

                clearStatus();
                updateState();
            });
        });
    };

    const renderUsers = () => {
        const users = Array.isArray(usersByTurm[currentTurm]) ? usersByTurm[currentTurm] : [];
        selected.clear();

        if (users.length === 0) {
            spotUsersWrap.innerHTML = '<div class="spot-empty" id="spotGrid">Aktuell sind keine Stellplatznutzer vorhanden.</div>';
        } else {
            const html = users.map((user) => {
                const platzLabel = String(user.plaetze).includes(',') ? 'Stellplätze ' : 'Stellplatz ';
                return `
                    <button type="button" class="spot-user" data-uid="${escapeHtml(user.uid)}" aria-pressed="false">
                        <span class="spot-user__name">${escapeHtml(user.display_name)}</span>
                        <span class="spot-user__meta">${escapeHtml(platzLabel + user.plaetze)}</span>
                    </button>
                `;
            }).join('');

            spotUsersWrap.innerHTML = `<div class="spot-grid" id="spotGrid">${html}</div>`;
            bindCardEvents();
        }

        pageHeading.textContent = currentTurm === 'tvk' ? 'TvK Stellplatznachricht' : 'WEH Stellplatznachricht';
        turmSwitch.textContent = currentTurm;
        clearStatus();
        updateState();
    };

    const updateState = () => {
        selectedCount.textContent = `${selected.size} ausgewählt`;
        const users = Array.isArray(usersByTurm[currentTurm]) ? usersByTurm[currentTurm] : [];
        availableCount.textContent = `${users.length} Personen verfügbar`;
        sendButton.disabled =
            selected.size === 0 ||
            subjectField.value.trim() === '' ||
            messageField.value.trim() === '';
    };

    const showSentScreenAndReload = () => {
        page.classList.add('is-sent');
        window.setTimeout(() => {
            window.location.reload();
        }, 3000);
    };

    if (availableTurms.length > 1) {
        turmSwitch.addEventListener('click', () => {
            const currentIndex = availableTurms.indexOf(currentTurm);
            const nextIndex = (currentIndex + 1) % availableTurms.length;
            currentTurm = availableTurms[nextIndex];
            renderUsers();
        });
    }

    subjectField.addEventListener('input', () => {
        clearStatus();
        updateState();
    });

    messageField.addEventListener('input', () => {
        clearStatus();
        updateState();
    });

    sendButton.addEventListener('click', () => {
        const subject = subjectField.value.trim();
        const message = messageField.value.trim();
        const uids = Array.from(selected);

        if (!subject || !message || uids.length === 0) {
            updateState();
            return;
        }

        sendButton.disabled = true;
        page.classList.add('is-sent');

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'send',
                turm: currentTurm,
                uids,
                subject,
                message
            })
        }).catch(() => {});

        window.setTimeout(() => {
            window.location.reload();
        }, 3000);
    });

    bindCardEvents();
    updateState();
})();
</script>
</body>
</html>
<?php
$conn->close();
?>