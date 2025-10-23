<?php
// Wasch-Admin: Waschmarken hinzufügen (Usersuche + AJAX Insert/Update)

session_start();

// --- EARLY DB ACCESS FOR AJAX ENDPOINTS ---
require_once('conn.php'); // defines $conn (DB "weh") and $waschconn (DB "waschsystem2")
mysqli_set_charset($conn, "utf8");

$isAuthedEarly = !empty($_SESSION['valid']);
$isAdminEarly  = ((!empty($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)
               || (!empty($_SESSION["WEH-WaschAG"])   && $_SESSION["WEH-WaschAG"]   === true));

// Helper: JSON response + exit
function json_exit($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

// --- AJAX: SEARCH & ADD ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if (!$isAuthedEarly || !$isAdminEarly) {
        json_exit(['ok'=>false, 'error'=>'Nicht autorisiert.'], 403);
    }

    if ($action === 'search') {
        $term = trim($_GET['term'] ?? '');
        // allow 1+ character
        $like = '%' . $term . '%';

        // Only active or subletters in WEH
        $sql = "
            SELECT uid, firstname, lastname, username, room
            FROM users
            WHERE pid IN (11,12)
              AND turm = 'weh'
              AND (
                    username LIKE ?
                 OR firstname LIKE ?
                 OR lastname  LIKE ?
                 OR CAST(room AS CHAR) LIKE ?
              )
            ORDER BY lastname, firstname
            LIMIT 3
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssss', $like, $like, $like, $like);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $out = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $label = trim(($row['firstname'] ?? '').' '.($row['lastname'] ?? ''));
            $label = $label !== '' ? $label : $row['username'];
            $room  = isset($row['room']) && $row['room'] ? (' • Zimmer '.$row['room']) : '';
            $out[] = [
                'uid'   => (int)$row['uid'],
                'label' => $label.' ('.$row['username'].')'.$room
            ];
        }
        json_exit(['ok'=>true, 'results'=>$out]);
    }
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Read JSON body
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $uid     = isset($data['uid']) ? (int)$data['uid'] : 0;
        $amount  = isset($data['amount']) ? (int)$data['amount'] : 0;

        if ($uid <= 0)                        json_exit(['ok'=>false, 'error'=>'Kein Nutzer gewählt.'], 422);
        if ($amount < 1 || $amount > 5)       json_exit(['ok'=>false, 'error'=>'Anzahl muss 1–5 sein.'], 422);

        // Validate that user exists and is selectable (WEH DB)
        $vsql = "SELECT uid, firstname, lastname, username, room
                 FROM users WHERE uid=? AND pid IN (11,12) AND turm='weh' LIMIT 1";
        $vstm = mysqli_prepare($conn, $vsql);
        mysqli_stmt_bind_param($vstm, 'i', $uid);
        mysqli_stmt_execute($vstm);
        $vres = mysqli_stmt_get_result($vstm);
        $userRow = mysqli_fetch_assoc($vres);
        if (!$userRow) {
            json_exit(['ok'=>false, 'error'=>'Nutzer nicht gefunden oder nicht berechtigt.'], 404);
        }

        // --- TRANSACTION in waschsystem2 ---
        mysqli_begin_transaction($waschconn);

        // 1) Prüfen, ob waschusers-Record existiert (KEIN INSERT, KEIN UPDATE anderer Felder)
        $lock = mysqli_prepare($waschconn, "SELECT waschmarken FROM waschusers WHERE uid=? FOR UPDATE");
        mysqli_stmt_bind_param($lock, 'i', $uid);
        mysqli_stmt_execute($lock);
        $locked = mysqli_stmt_get_result($lock);
        $rowWU  = mysqli_fetch_assoc($locked);

        if (!$rowWU) {
            mysqli_rollback($waschconn);
            json_exit(['ok'=>false, 'error'=>'Nutzer ist im Waschsystem nicht registriert.'], 404);
        }

        // 2) Nur waschmarken erhöhen
        $up = mysqli_prepare($waschconn, "UPDATE waschusers SET waschmarken = waschmarken + ? WHERE uid=?");
        mysqli_stmt_bind_param($up, 'ii', $amount, $uid);
        if (!mysqli_stmt_execute($up)) {
            mysqli_rollback($waschconn);
            json_exit(['ok'=>false, 'error'=>'Fehler beim Aktualisieren der Waschmarken.'], 500);
        }

        // 3) Transfer protokollieren: von_uid = -2 (Waschmarken-Gutschrift)
        $ts = time();
        $von_uid = -2;
        $tsql = "INSERT INTO transfers (von_uid, nach_uid, anzahl, time) VALUES (?, ?, ?, ?)";
        $tstm = mysqli_prepare($waschconn, $tsql);
        mysqli_stmt_bind_param($tstm, 'iiii', $von_uid, $uid, $amount, $ts);
        if (!mysqli_stmt_execute($tstm)) {
            mysqli_rollback($waschconn);
            json_exit(['ok'=>false, 'error'=>'Fehler beim Protokollieren des Transfers.'], 500);
        }

        // 4) Neue Summe laden
        $bsql = "SELECT waschmarken FROM waschusers WHERE uid=? LIMIT 1";
        $bstm = mysqli_prepare($waschconn, $bsql);
        mysqli_stmt_bind_param($bstm, 'i', $uid);
        mysqli_stmt_execute($bstm);
        $bres = mysqli_stmt_get_result($bstm);
        $bal  = ($r = mysqli_fetch_assoc($bres)) ? (int)$r['waschmarken'] : null;

        mysqli_commit($waschconn);

        $userLabel = trim(($userRow['firstname'] ?? '').' '.($userRow['lastname'] ?? ''));
        if ($userLabel === '') $userLabel = $userRow['username'];
        json_exit([
            'ok'        => true,
            'message'   => "Erfolgreich {$amount} Waschmarke(n) an {$userLabel} erstattet.",
            'new_total' => $bal
        ]);
    }


    json_exit(['ok'=>false, 'error'=>'Unbekannte Aktion.'], 400);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Waschmarken erstatten</title>
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1" /> -->
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        :root {
            --primary: #11a50d;
            --bg-elev:rgb(43, 43, 43);
            --text: #f0f0f0;
            --muted: #a6a6a6;
            --border: #2a2a2a;
            --danger: #ff5f5f;
        }
        .wrap { max-width: 760px; margin: 36px auto; padding: 0 16px; }
        .card {
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.35);
        }
        h1 { margin: 0 0 16px; font-size: 22px; color: var(--text); }
        p.lead { color: var(--muted); margin: 0 0 24px; }

        .field { margin-bottom: 18px; }
        .label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 8px; }

        .searchbox {
            position: relative;
        }
        .input {
            width: 90%;
            background: #0f0f0f;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 12px;
            padding: 12px 14px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(17,165,13,0.2); }

        .suggestions {
            position: absolute;
            left: 0; right: 0; top: calc(100% + 6px);
            background: #101010;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            z-index: 50;
        }
        .suggestions.hidden { display: none; }
        .sugg-item {
            padding: 10px 12px;
            color: var(--text);
            cursor: pointer;
            border-bottom: 1px solid var(--border);
        }
        .sugg-item:last-child { border-bottom: none; }
        .sugg-item:hover { background: #141414; }

        .selected {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(17,165,13,0.12);
            border: 1px solid rgba(17,165,13,0.4);
            color: var(--text);
            padding: 8px 12px;
            border-radius: 9999px;
            margin-top: 8px;
            font-size: 14px;
        }
        .remove { cursor: pointer; color: var(--muted); }
        .remove:hover { color: #fff; }

        .seg {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .seg input { display: none; }
        .seg label {
            cursor: pointer;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #0f0f0f;
            color: var(--text);
            user-select: none;
            transition: transform .05s ease, border-color .2s;
        }
        .seg input:checked + label {
            border-color: var(--primary);
            box-shadow: inset 0 0 0 2px rgba(17,165,13,0.45);
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 10px; min-width: 180px;
            background: var(--primary); color: #041204; font-weight: 700;
            border: none; border-radius: 12px; padding: 12px 16px;
            cursor: pointer; transition: transform .06s ease-out, filter .2s;
        }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .btn:active { transform: translateY(1px); }

        .note { margin-top: 10px; font-size: 13px; color: var(--muted); }

        .toast {
            position: fixed; right: 18px; bottom: 18px;
            background: #0d0d0d; color: var(--text);
            border: 1px solid var(--border);
            border-left: 4px solid var(--primary);
            padding: 12px 14px; border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,.5);
            opacity: 0; transform: translateY(12px); pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
            max-width: 60ch; z-index: 999;
        }
        .toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
        .toast.err  { border-left-color: var(--danger); }
    </style>
</head>
<body>
<?php
require('template.php'); // defines $conn/$waschconn again (ok), outputs layout, provides auth($conn)

$isAuthed = auth($conn) && !empty($_SESSION['valid']);
$isAdmin  = ((!empty($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)
          || (!empty($_SESSION["WaschAG"])   && $_SESSION["WaschAG"]   === true));

if (!$isAuthed || !$isAdmin) {
    header("Location: denied.php");
    exit;
}
load_menu();
?>
<div class="wrap">
    <div class="card">
        <h1>Waschmarken erstatten</h1>

        <!-- 1) USER SEARCH -->
        <div class="field">
            <span class="label">Nutzer suchen (UID, Username, Vorname, Nachname, Zimmer)</span>
            <div class="searchbox">
                <input id="user-search" class="input" type="text" placeholder="Tippe, um zu suchen …" autocomplete="off" />
                <div id="sugg" class="suggestions hidden"></div>
            </div>
            <div id="selected" class="selected" style="display:none">
                <span id="sel-label"></span>
                <span id="sel-remove" class="remove" title="Auswahl entfernen">✕</span>
            </div>
        </div>

        <!-- 2) AMOUNT -->
        <div class="field">
            <span class="label">Anzahl Waschmarken</span>
            <div class="seg" id="amount-group">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <input type="radio" name="amount" id="amount-<?= $i ?>" value="<?= $i ?>">
                    <label for="amount-<?= $i ?>"><?= $i ?></label>
                <?php endfor; ?>
            </div>
        </div>

        <!-- 3) SUBMIT -->
        <div class="field">
            <button id="submit" class="btn" disabled>Waschmarken erstatten</button>
        </div>
    </div>
</div>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
(() => {
    const elSearch   = document.getElementById('user-search');
    const elSugg     = document.getElementById('sugg');
    const elSelected = document.getElementById('selected');
    const elSelLabel = document.getElementById('sel-label');
    const elSelRemove= document.getElementById('sel-remove');
    const elSubmit   = document.getElementById('submit');
    const elToast    = document.getElementById('toast');

    let selectedUser = null; // { uid, label }
    let fetchCtr = 0;

    function showToast(msg, isErr=false) {
        elToast.textContent = msg;
        elToast.classList.toggle('err', !!isErr);
        elToast.classList.add('show');
        setTimeout(() => elToast.classList.remove('show'), 3200);
    }

    function updateSubmitState() {
        const amount = getSelectedAmount();
        elSubmit.disabled = !(selectedUser && amount);
    }

    function getSelectedAmount() {
        const checked = document.querySelector('input[name="amount"]:checked');
        if (!checked) return 0;
        const val = parseInt(checked.value, 10);
        return (val >= 1 && val <= 5) ? val : 0;
    }

    function renderSuggestions(items) {
        if (!items || !items.length) {
            elSugg.classList.add('hidden');
            elSugg.innerHTML = '';
            return;
        }
        elSugg.innerHTML = items.map(it => `
            <div class="sugg-item" data-uid="${it.uid}" data-label="${it.label}">${it.label}</div>
        `).join('');
        elSugg.classList.remove('hidden');
    }

    function selectUser(uid, label) {
        selectedUser = { uid: parseInt(uid,10), label };
        elSelLabel.textContent = label;
        elSelected.style.display = 'inline-flex';
        elSugg.classList.add('hidden');
        elSugg.innerHTML = '';
        elSearch.value = '';
        updateSubmitState();
    }

    elSelRemove.addEventListener('click', () => {
        selectedUser = null;
        elSelected.style.display = 'none';
        updateSubmitState();
        elSearch.focus();
    });

    elSugg.addEventListener('click', (e) => {
        const item = e.target.closest('.sugg-item');
        if (!item) return;
        selectUser(item.dataset.uid, item.dataset.label);
    });

    elSearch.addEventListener('input', async () => {
        const term = elSearch.value.trim();
        fetchCtr++;
        const id = fetchCtr;

        if (term.length < 1) {
            renderSuggestions([]);
            return;
        }
        try {
            const res = await fetch(`<?= basename(__FILE__) ?>?action=search&term=`+encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) throw new Error('HTTP '+res.status);
            const data = await res.json();
            if (id !== fetchCtr) return; // drop stale
            renderSuggestions(data.ok ? data.results : []);
        } catch (err) {
            renderSuggestions([]);
        }
    });

    document.getElementById('amount-group').addEventListener('change', updateSubmitState);

    elSubmit.addEventListener('click', async () => {
        const amount = getSelectedAmount();
        if (!selectedUser || !amount) return;
        elSubmit.disabled = true;

        try {
            const res = await fetch(`<?= basename(__FILE__) ?>?action=add`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ uid: selectedUser.uid, amount })
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                showToast(data.error || 'Fehler beim Hinzufügen.', true);
            } else {
                showToast(data.message || 'Erfolgreich hinzugefügt.');
            }
        } catch (e) {
            showToast('Netzwerk-/Serverfehler.', true);
        } finally {
            elSubmit.disabled = false;
        }
    });

    // Keyboard UX: Enter selects first suggestion
    elSearch.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const first = elSugg.querySelector('.sugg-item');
            if (first) {
                e.preventDefault();
                selectUser(first.dataset.uid, first.dataset.label);
            }
        }
    });
})();
</script>
</body>
</html>
