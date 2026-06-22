<?php
session_start();

require_once('conn.php');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

mysqli_set_charset($conn, "utf8");
mysqli_set_charset($waschconn, "utf8");
mysqli_set_charset($tvkwaschconn, "utf8");

function wmx_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wmx_json($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function wmx_fail($message, $status = 400) {
    wmx_json([
        'success' => false,
        'message' => $message
    ], $status);
}

function wmx_ajax_allowed() {
    return isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true;
}

function wmx_nav_accent($turm) {
    return match ($turm) {
        'tvk' => '#FFA500',
        'weh' => '#11a50d',
        default => '#11a50d',
    };
}

function wmx_wasch_db_label($turm) {
    return ($turm === 'tvk') ? 'waschsystem_tvk' : 'waschsystem2';
}

function wmx_wasch_conn($waschconn, $tvkwaschconn, $turm) {
    return ($turm === 'tvk') ? $tvkwaschconn : $waschconn;
}

function wmx_fetch_user($conn, $uid) {
    $uid = (int)$uid;

    $sql = "SELECT uid, name, room, pid, groups, turm FROM users WHERE uid = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $dbUid, $name, $room, $pid, $groups, $turm);

    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }

    mysqli_stmt_close($stmt);

    return [
        'uid' => (int)$dbUid,
        'name' => (string)$name,
        'room' => $room,
        'pid' => (int)$pid,
        'groups' => (string)$groups,
        'turm' => (string)$turm
    ];
}

function wmx_fetch_waschmarken($selectedWaschConn, $uid) {
    $uid = (int)$uid;

    $sql = "SELECT waschmarken FROM waschusers WHERE uid = ? LIMIT 1";
    $stmt = mysqli_prepare($selectedWaschConn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $waschmarken);

    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }

    mysqli_stmt_close($stmt);

    return (int)$waschmarken;
}

function wmx_fetch_balance($conn, $uid) {
    $uid = (int)$uid;

    $sql = "SELECT COALESCE(SUM(betrag), 0) FROM transfers WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $summe);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (float)$summe;
}

function wmx_fetch_price($conn, $groups) {
    if ($groups != "1" && $groups != "1,19") {
        $constantName = 'waschpreisaktiv';
    } else {
        $constantName = 'waschpreisnichtaktiv';
    }

    $sql = "SELECT wert FROM constants WHERE name = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $constantName);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $wert);

    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception("Waschmarkenpreis nicht gefunden.");
    }

    mysqli_stmt_close($stmt);

    return (float)$wert;
}

function wmx_user_label($user) {
    $name = trim($user['name']) !== '' ? $user['name'] : ('UID ' . $user['uid']);
    $room = ($user['room'] !== null && $user['room'] !== '') ? ' · ' . $user['room'] : '';
    return $name . $room . ' · ' . strtoupper($user['turm']);
}

function wmx_user_payload($conn, $waschconn, $tvkwaschconn, $uid) {
    $user = wmx_fetch_user($conn, $uid);

    if (!$user) {
        throw new Exception('User nicht gefunden.');
    }

    $selectedWaschConn = wmx_wasch_conn($waschconn, $tvkwaschconn, $user['turm']);
    $waschmarken = wmx_fetch_waschmarken($selectedWaschConn, $user['uid']);
    $balance = wmx_fetch_balance($conn, $user['uid']);
    $price = wmx_fetch_price($conn, $user['groups']);

    return [
        'uid' => $user['uid'],
        'name' => trim($user['name']) !== '' ? $user['name'] : ('UID ' . $user['uid']),
        'room' => $user['room'],
        'turm' => $user['turm'],
        'label' => wmx_user_label($user),
        'accent' => wmx_nav_accent($user['turm']),
        'waschmarken' => $waschmarken,
        'hasWaschuser' => $waschmarken !== null,
        'balance' => $balance,
        'balanceFormatted' => number_format($balance, 2, ",", ".") . " €",
        'price' => $price,
        'priceFormatted' => number_format($price, 2, ",", ".") . " €"
    ];
}

function wmx_search_users($conn, $query) {
    $query = trim((string)$query);

    if ($query === '') {
        return [];
    }

    $like = '%' . $query . '%';

    $sql = "
        SELECT uid, name, room, pid, turm
        FROM users
        WHERE name LIKE ?
           OR CAST(room AS CHAR) LIKE ?
        ORDER BY
            CASE WHEN pid = 11 THEN 0 ELSE 1 END,
            turm,
            room,
            name
        LIMIT 15
    ";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $like, $like);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $name, $room, $pid, $turm);

    $results = [];

    while (mysqli_stmt_fetch($stmt)) {
        $name = trim((string)$name) !== '' ? $name : ('UID ' . (int)$uid);
        $roomText = ($room !== null && $room !== '') ? ' · ' . $room : '';

        $results[] = [
            'uid' => (int)$uid,
            'label' => $name . $roomText,
            'turm' => (string)$turm
        ];
    }

    mysqli_stmt_close($stmt);

    return $results;
}

function wmx_positive_int($value, $label) {
    if (!is_numeric($value)) {
        throw new Exception($label . ' muss eine Zahl sein.');
    }

    $number = (int)$value;

    if ((float)$value != $number || $number <= 0) {
        throw new Exception($label . ' muss eine positive ganze Zahl sein.');
    }

    return $number;
}

function wmx_positive_float($value, $label) {
    $value = str_replace(',', '.', trim((string)$value));

    if ($value === '' || !is_numeric($value)) {
        throw new Exception($label . ' muss eine Zahl sein.');
    }

    $number = (float)$value;

    if ($number <= 0) {
        throw new Exception($label . ' muss positiv sein.');
    }

    return $number;
}

function wmx_exchange($conn, $waschconn, $tvkwaschconn, $uid, $direction, $rawAmount, $agentUid) {
    $uid = (int)$uid;
    $agentUid = (int)$agentUid;

    $user = wmx_fetch_user($conn, $uid);

    if (!$user) {
        throw new Exception('User nicht gefunden.');
    }

    $selectedWaschConn = wmx_wasch_conn($waschconn, $tvkwaschconn, $user['turm']);
    $currentWaschmarken = wmx_fetch_waschmarken($selectedWaschConn, $uid);

    if ($currentWaschmarken === null) {
        throw new Exception('Kein waschusers-Eintrag für diesen User.');
    }

    $price = wmx_fetch_price($conn, $user['groups']);

    if ($price <= 0) {
        throw new Exception('Ungültiger Waschmarkenpreis.');
    }

    $zeit = time();
    $konto = 6;
    $kasse = 5;

    if ($direction === 'wasch2money') {
        $marken = wmx_positive_int($rawAmount, 'Waschmarken');

        if ($marken > $currentWaschmarken) {
            throw new Exception('Nicht genug Waschmarken vorhanden.');
        }

        $waschDelta = -1 * $marken;
        $wehBetrag = $marken * $price;
        $beschreibung = $marken . " Waschmarken zurückgetauscht";
        $mode = "Mode1";
    } elseif ($direction === 'money2wasch') {
        $betrag = wmx_positive_float($rawAmount, 'Betrag');
        $markenFloat = $betrag / $price;
        $marken = (int)round($markenFloat);

        if (abs($markenFloat - $marken) > 0.00001) {
            throw new Exception('Betrag ist kein Vielfaches von ' . number_format($price, 2, ",", ".") . ' €.');
        }

        if ($marken <= 0) {
            throw new Exception('Es muss mindestens eine Waschmarke entstehen.');
        }

        $waschDelta = $marken;
        $wehBetrag = -1 * $betrag;
        $beschreibung = $marken . " Waschmarken generiert";
        $mode = "Mode2";
    } else {
        throw new Exception('Ungültige Richtung.');
    }

    try {
        mysqli_begin_transaction($selectedWaschConn);
        mysqli_begin_transaction($conn);

        $sql = "INSERT INTO transfers (von_uid, nach_uid, anzahl, time) VALUES (-1, ?, ?, ?)";
        $stmt = mysqli_prepare($selectedWaschConn, $sql);
        mysqli_stmt_bind_param($stmt, "iii", $uid, $waschDelta, $zeit);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $sql = "UPDATE waschusers SET waschmarken = waschmarken + ? WHERE uid = ?";
        $stmt = mysqli_prepare($selectedWaschConn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $waschDelta, $uid);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new Exception('Waschmarken konnten nicht aktualisiert werden.');
        }

        mysqli_stmt_close($stmt);

        $changelog = "[" . date("d.m.Y H:i", $zeit) . "] Agent " . $agentUid . "\n";
        $changelog .= "Insert durch WaschmarkenExchange.php [$mode]\n";
        $changelog .= "Turm: " . $user['turm'] . "\n";
        $changelog .= "Wasch-DB: " . wmx_wasch_db_label($user['turm']) . "\n";

        $sql = "
            INSERT INTO transfers
                (uid, tstamp, beschreibung, konto, kasse, betrag, agent, changelog)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "iisiidis",
            $uid,
            $zeit,
            $beschreibung,
            $konto,
            $kasse,
            $wehBetrag,
            $agentUid,
            $changelog
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($selectedWaschConn);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        try { mysqli_rollback($selectedWaschConn); } catch (Throwable $ignore) {}
        try { mysqli_rollback($conn); } catch (Throwable $ignore) {}
        throw $e;
    }

    return wmx_user_payload($conn, $waschconn, $tvkwaschconn, $uid);
}

if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    if (!wmx_ajax_allowed()) {
        wmx_fail('Nicht autorisiert.', 403);
    }

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'search_users') {
            wmx_json([
                'success' => true,
                'results' => wmx_search_users($conn, $_POST['query'] ?? '')
            ]);
        }

        if ($action === 'get_user') {
            $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;

            wmx_json([
                'success' => true,
                'user' => wmx_user_payload($conn, $waschconn, $tvkwaschconn, $uid)
            ]);
        }

        if ($action === 'exchange') {
            $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
            $direction = $_POST['direction'] ?? '';
            $amount = $_POST['amount'] ?? '';

            $user = wmx_exchange($conn, $waschconn, $tvkwaschconn, $uid, $direction, $amount, (int)$_SESSION["uid"]);

            wmx_json([
                'success' => true,
                'message' => 'Gebucht.',
                'user' => $user
            ]);
        }

        wmx_fail('Unbekannte Aktion.');
    } catch (Throwable $e) {
        wmx_fail($e->getMessage());
    }
}

ob_start();
require('template.php');
$templateOutput = ob_get_clean();

if (!(auth($conn) && isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    header("Location: denied.php");
    exit;
}

$selectedUid = isset($_GET['uid']) ? (int)$_GET['uid'] : (int)$_SESSION["uid"];

try {
    $initialUser = wmx_user_payload($conn, $waschconn, $tvkwaschconn, $selectedUid);
} catch (Throwable $e) {
    $initialUser = wmx_user_payload($conn, $waschconn, $tvkwaschconn, (int)$_SESSION["uid"]);
}

$initialAccent = $initialUser['accent'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="format-detection" content="telefon=no">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        .wmx-page {
            --wmx-primary: <?= wmx_h($initialAccent) ?>;
            --wmx-bg: #1f1f1f;
            --wmx-bg-soft: #252525;
            --wmx-bg-input: #151515;
            --wmx-border: #3a3a3a;
            --wmx-text: #fff;
            --wmx-muted: #aaa;
            --wmx-error: #ff5555;

            width: min(980px, calc(100vw - 28px));
            margin: 30px auto 70px auto;
            color: var(--wmx-text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .wmx-card {
            background: var(--wmx-bg);
            border: 1px solid var(--wmx-border);
            border-radius: 18px;
            padding: 20px;
        }

        .wmx-search-wrap {
            position: relative;
            margin-bottom: 18px;
        }

        .wmx-search {
            width: 100%;
            box-sizing: border-box;
            background: var(--wmx-bg-input);
            color: #fff;
            border: 2px solid var(--wmx-border);
            border-radius: 16px;
            padding: 16px 18px;
            font-size: 24px;
            outline: none;
            text-align: center;
        }

        .wmx-search:focus {
            border-color: var(--wmx-primary);
        }

        .wmx-results {
            display: none;
            position: absolute;
            z-index: 20;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #181818;
            border: 1px solid var(--wmx-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45);
        }

        .wmx-results.show {
            display: block;
        }

        .wmx-result {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px 16px;
            color: #fff;
            cursor: pointer;
            border-bottom: 1px solid #2c2c2c;
            font-size: 19px;
        }

        .wmx-result:last-child {
            border-bottom: none;
        }

        .wmx-result:hover {
            background: var(--wmx-primary);
            color: #000;
        }

        .wmx-result-turm {
            font-weight: 800;
            opacity: 0.85;
            text-transform: uppercase;
        }

        .wmx-selected {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 0 0 20px 0;
            font-size: 24px;
            font-weight: 800;
            text-align: center;
        }

        .wmx-selected span {
            color: var(--wmx-primary);
        }

        .wmx-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 18px;
        }

        .wmx-stat {
            background: var(--wmx-bg);
            border: 1px solid var(--wmx-border);
            border-radius: 18px;
            padding: 26px 20px;
            text-align: center;
        }

        .wmx-stat-label {
            color: var(--wmx-muted);
            font-size: 18px;
            margin-bottom: 10px;
        }

        .wmx-stat-value {
            color: #fff;
            font-size: 52px;
            font-weight: 900;
            line-height: 1;
        }

        .wmx-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }

        .wmx-switch-btn {
            background: var(--wmx-bg-soft);
            color: #fff;
            border: 1px solid var(--wmx-border);
            border-radius: 16px;
            padding: 18px 12px;
            font-size: 22px;
            font-weight: 850;
            cursor: pointer;
        }

        .wmx-switch-btn:hover {
            border-color: var(--wmx-primary);
        }

        .wmx-switch-btn.active {
            background: var(--wmx-primary);
            color: #000;
            border-color: var(--wmx-primary);
        }

        .wmx-panel {
            display: none;
            background: var(--wmx-bg);
            border: 1px solid var(--wmx-border);
            border-radius: 18px;
            padding: 24px;
        }

        .wmx-panel.active {
            display: block;
        }

        .wmx-panel-title {
            margin: 0 0 18px 0;
            color: #fff;
            font-size: 32px;
            text-align: center;
        }

        .wmx-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
        }

        .wmx-input {
            width: 100%;
            box-sizing: border-box;
            background: var(--wmx-bg-input);
            color: #fff;
            border: 2px solid var(--wmx-border);
            border-radius: 16px;
            padding: 16px 18px;
            font-size: 34px;
            font-weight: 800;
            text-align: center;
            outline: none;
        }

        .wmx-input:focus {
            border-color: var(--wmx-primary);
        }

        .wmx-btn {
            background: var(--wmx-primary);
            color: #000;
            border: 2px solid var(--wmx-primary);
            border-radius: 16px;
            padding: 16px 28px;
            font-size: 24px;
            font-weight: 900;
            cursor: pointer;
        }

        .wmx-btn:hover {
            filter: brightness(1.08);
        }

        .wmx-btn:disabled,
        .wmx-input:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .wmx-toast {
            display: none;
            margin-bottom: 18px;
            background: #151515;
            border: 1px solid var(--wmx-border);
            border-left: 6px solid var(--wmx-primary);
            border-radius: 14px;
            color: #fff;
            padding: 13px 15px;
            font-size: 18px;
            text-align: center;
        }

        .wmx-toast.show {
            display: block;
        }

        .wmx-toast.error {
            border-left-color: var(--wmx-error);
            color: #ffbbbb;
        }

        .wmx-loading {
            opacity: 0.65;
            pointer-events: none;
        }

        @media (max-width: 750px) {
            .wmx-stats,
            .wmx-switch,
            .wmx-form {
                grid-template-columns: 1fr;
            }

            .wmx-stat-value {
                font-size: 42px;
            }

            .wmx-search,
            .wmx-input {
                font-size: 26px;
            }
        }
    </style>
</head>

<?php
echo $templateOutput;
load_menu();
?>

<div
    class="wmx-page"
    id="wmxPage"
    data-uid="<?= (int)$initialUser['uid'] ?>"
>
    <div class="wmx-search-wrap">
        <input
            type="text"
            class="wmx-search"
            id="wmxSearch"
            placeholder="Name oder Raum"
            autocomplete="off"
        >
        <div class="wmx-results" id="wmxResults"></div>
    </div>

    <div class="wmx-selected" id="wmxSelected">
        <span>Ausgewählt:</span>
        <div id="wmxSelectedName"><?= wmx_h($initialUser['label']) ?></div>
    </div>

    <div class="wmx-toast" id="wmxToast"></div>

    <div class="wmx-stats">
        <div class="wmx-stat">
            <div class="wmx-stat-label">Waschmarken</div>
            <div class="wmx-stat-value" id="wmxWaschmarken">
                <?= $initialUser['hasWaschuser'] ? (int)$initialUser['waschmarken'] : '—' ?>
            </div>
        </div>

        <div class="wmx-stat">
            <div class="wmx-stat-label">Guthaben</div>
            <div class="wmx-stat-value" id="wmxBalance">
                <?= wmx_h($initialUser['balanceFormatted']) ?>
            </div>
        </div>

        <div class="wmx-stat">
            <div class="wmx-stat-label">Wechselkurs</div>
            <div class="wmx-stat-value" id="wmxPrice">
                <?= wmx_h($initialUser['priceFormatted']) ?>
            </div>
        </div>
    </div>

    <div class="wmx-switch">
        <button type="button" class="wmx-switch-btn active" data-mode="wasch2money">
            Waschmarken → Geld
        </button>
        <button type="button" class="wmx-switch-btn" data-mode="money2wasch">
            Geld → Waschmarken
        </button>
    </div>

    <div class="wmx-panel active" data-panel="wasch2money">
        <h2 class="wmx-panel-title">Waschmarken → Geld</h2>

        <form class="wmx-form" id="wmxWasch2MoneyForm">
            <input
                type="number"
                class="wmx-input"
                id="wmxMarken"
                min="1"
                step="1"
                placeholder="Marken"
                required
            >
            <button type="submit" class="wmx-btn">Exchange</button>
        </form>
    </div>

    <div class="wmx-panel" data-panel="money2wasch">
        <h2 class="wmx-panel-title">Geld → Waschmarken</h2>

        <form class="wmx-form" id="wmxMoney2WaschForm">
            <input
                type="number"
                class="wmx-input"
                id="wmxBetrag"
                min="0"
                step="<?= wmx_h($initialUser['price']) ?>"
                placeholder="€"
                required
            >
            <button type="submit" class="wmx-btn">Exchange</button>
        </form>
    </div>
</div>

<script>
(function () {
    const initialUser = <?= json_encode($initialUser, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const page = document.getElementById('wmxPage');
    const search = document.getElementById('wmxSearch');
    const results = document.getElementById('wmxResults');
    const selectedName = document.getElementById('wmxSelectedName');
    const toast = document.getElementById('wmxToast');
    const price = document.getElementById('wmxPrice');

    const waschmarken = document.getElementById('wmxWaschmarken');
    const balance = document.getElementById('wmxBalance');
    const markenInput = document.getElementById('wmxMarken');
    const betragInput = document.getElementById('wmxBetrag');

    let currentUser = initialUser;
    let searchTimer = null;

    function ajax(payload) {
        const body = new URLSearchParams();
        body.set('ajax', '1');

        Object.keys(payload).forEach(function (key) {
            body.set(key, payload[key]);
        });

        return fetch('WaschmarkenExchange.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.success) {
                    throw new Error(json.message || 'Fehler.');
                }

                return json;
            });
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function showToast(message, error) {
        toast.textContent = message;
        toast.classList.toggle('error', !!error);
        toast.classList.add('show');
    }

    function hideToast() {
        toast.classList.remove('show', 'error');
        toast.textContent = '';
    }

    function setLoading(isLoading) {
        page.classList.toggle('wmx-loading', !!isLoading);
    }

    function renderUser(user) {
        currentUser = user;

        page.dataset.uid = user.uid;
        page.style.setProperty('--wmx-primary', user.accent);

        selectedName.textContent = user.label;
        waschmarken.textContent = user.hasWaschuser ? user.waschmarken : '—';
        balance.textContent = user.balanceFormatted;
        price.textContent = user.priceFormatted;

        markenInput.value = '';
        markenInput.disabled = !user.hasWaschuser;
        markenInput.max = user.hasWaschuser ? user.waschmarken : '';

        betragInput.value = '';
        betragInput.disabled = !user.hasWaschuser;
        betragInput.step = user.price;

        document.querySelectorAll('.wmx-btn').forEach(function (button) {
            button.disabled = !user.hasWaschuser;
        });

        const url = new URL(window.location.href);
        url.searchParams.set('uid', user.uid);
        window.history.replaceState({}, '', url.toString());

        if (!user.hasWaschuser) {
            showToast('Kein waschusers-Eintrag für diesen User.', true);
        } else {
            hideToast();
        }
    }

    function renderResults(items) {
        results.innerHTML = '';

        if (!items.length) {
            results.classList.remove('show');
            return;
        }

        items.forEach(function (item) {
            const row = document.createElement('div');
            row.className = 'wmx-result';
            row.dataset.uid = item.uid;
            row.innerHTML =
                '<div>' + escapeHtml(item.label) + '</div>' +
                '<div class="wmx-result-turm">' + escapeHtml(item.turm) + '</div>';

            row.addEventListener('click', function () {
                selectUser(item.uid);
            });

            results.appendChild(row);
        });

        results.classList.add('show');
    }

    function runSearch() {
        const query = search.value.trim();

        if (query === '') {
            results.classList.remove('show');
            results.innerHTML = '';
            return;
        }

        ajax({
            action: 'search_users',
            query: query
        }).then(function (json) {
            renderResults(json.results);
        }).catch(function (error) {
            showToast(error.message, true);
        });
    }

    function selectUser(uid) {
        setLoading(true);

        ajax({
            action: 'get_user',
            uid: uid
        }).then(function (json) {
            renderUser(json.user);
            search.value = '';
            results.classList.remove('show');
            results.innerHTML = '';
        }).catch(function (error) {
            showToast(error.message, true);
        }).finally(function () {
            setLoading(false);
        });
    }

    function exchange(direction, amount) {
        if (!currentUser || !currentUser.uid) {
            showToast('Kein User ausgewählt.', true);
            return;
        }

        setLoading(true);

        ajax({
            action: 'exchange',
            uid: currentUser.uid,
            direction: direction,
            amount: amount
        }).then(function (json) {
            renderUser(json.user);
            showToast(json.message, false);
        }).catch(function (error) {
            showToast(error.message, true);
        }).finally(function () {
            setLoading(false);
        });
    }

    search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 180);
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.wmx-search-wrap')) {
            results.classList.remove('show');
        }
    });

    document.querySelectorAll('.wmx-switch-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const mode = button.dataset.mode;

            document.querySelectorAll('.wmx-switch-btn').forEach(function (item) {
                item.classList.toggle('active', item.dataset.mode === mode);
            });

            document.querySelectorAll('.wmx-panel').forEach(function (panel) {
                panel.classList.toggle('active', panel.dataset.panel === mode);
            });

            hideToast();
        });
    });

    document.getElementById('wmxWasch2MoneyForm').addEventListener('submit', function (event) {
        event.preventDefault();
        exchange('wasch2money', markenInput.value);
    });

    document.getElementById('wmxMoney2WaschForm').addEventListener('submit', function (event) {
        event.preventDefault();
        exchange('money2wasch', betragInput.value);
    });

    renderUser(initialUser);
})();
</script>

<?php
$conn->close();
?>
</body>
</html>