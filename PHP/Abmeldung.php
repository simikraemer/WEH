<?php
session_start();

require('template.php');
mysqli_set_charset($conn, 'utf8');

$isAuthed = auth($conn) && !empty($_SESSION['valid']);
$isAdmin  = (!empty($_SESSION['NetzAG']) && $_SESSION['NetzAG'] === true);

if (!$isAuthed) {
    header('Location: denied.php');
    exit;
}

function fetch_user_info_by_uid(mysqli $conn, int $uid): array
{
    $sql = 'SELECT name, turm, room, oldroom FROM users WHERE uid = ?';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: [];
    mysqli_stmt_close($stmt);

    return $row;
}

function abmeldecheck(mysqli $conn, int $user): bool
{
    $sql = 'SELECT 1 FROM abmeldungen WHERE uid = ?';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $has = mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);

    return !$has; // true if no abmeldung yet
}

function validate_iban(string $iban): bool
{
    $iban = strtoupper(preg_replace('/\s+/', '', $iban));

    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }

    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $chunk = '';

    foreach (str_split($rearranged) as $ch) {
        $chunk .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;

        if (strlen($chunk) > 9) {
            $chunk = (string)((int)$chunk % 97);
        }
    }

    return ((int)$chunk % 97) === 1;
}

function is_allowed_admin_target(mysqli $conn, int $uid): bool
{
    $sql = 'SELECT 1 FROM users WHERE uid = ? AND pid IN (11,12,13) LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $allowed = mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);

    return $allowed;
}

function resolve_room(array $user): string
{
    if (isset($user['room']) && $user['room'] !== null && (int)$user['room'] > 0) {
        return (string)(int)$user['room'];
    }

    if (isset($user['oldroom']) && $user['oldroom'] !== null && (int)$user['oldroom'] > 0) {
        return (string)(int)$user['oldroom'];
    }

    return '';
}

$actingUid  = (int)$_SESSION['user'];
$actingInfo = fetch_user_info_by_uid($conn, $actingUid);
$actingName = trim((string)($actingInfo['name'] ?? ''));
$actingName = $actingName !== '' ? $actingName : 'User';
$actingRoom = resolve_room($actingInfo);
$pageAction = htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8');

$postMessage = '';
$postMessageClass = '';
$postSuccess = false;

if (isset($_POST['reload']) && $_POST['reload'] === '1' && isset($_POST['dod'])) {
    $targetUid = $actingUid;
    $targetIsValid = true;

    if ($isAdmin && isset($_POST['target_uid']) && $_POST['target_uid'] !== '') {
        $targetUid = (int)$_POST['target_uid'];
        $targetIsValid = $targetUid > 0 && ($targetUid === $actingUid || is_allowed_admin_target($conn, $targetUid));
    }

    if (!$targetIsValid) {
        $postMessage = 'ERROR: Selected user is not allowed for deregistration.';
        $postMessageClass = 'error';
    } elseif (!abmeldecheck($conn, $targetUid)) {
        $postMessage = 'ERROR: Selected user is already deregistered.';
        $postMessageClass = 'error';
    } else {
        $ibanRaw = isset($_POST['iban']) ? trim((string)$_POST['iban']) : '';
        $iban = strtoupper(preg_replace('/\s+/', '', $ibanRaw));

        if ($iban === '' || !validate_iban($iban)) {
            $postMessage = 'Bitte eine gültige IBAN angeben (Leerzeichen sind erlaubt, z. B. DE89 3704 0044 0532 0130 00).';
            $postMessageClass = 'error';
        } else {
            $sql = 'INSERT INTO abmeldungen (uid,endtime,iban,keepemail,alumni,alumnimail,bezahlart,status,betrag,tstamp) VALUES (?,?,?,?,?,?,?,?,?,?)';
            $stmt = mysqli_prepare($conn, $sql);

            $email   = isset($_POST['email_account']) ? 1 : 0;
            $alumni  = (isset($_POST['alumni']) && isset($_POST['forwardemail']) && trim((string)$_POST['forwardemail']) !== '') ? 1 : 0;
            $bezArt  = 1;
            $status  = 0;
            $betrag  = 0;
            $tstamp  = time();
            $alMail  = isset($_POST['forwardemail']) ? trim((string)$_POST['forwardemail']) : '';
            $endtime = strtotime((string)$_POST['dod']);

            mysqli_stmt_bind_param(
                $stmt,
                'iisiisiiii',
                $targetUid,
                $endtime,
                $iban,
                $email,
                $alumni,
                $alMail,
                $bezArt,
                $status,
                $betrag,
                $tstamp
            );

            if (mysqli_stmt_execute($stmt)) {
                $postMessage = 'Erfolgreich durchgeführt.';
                $postMessageClass = 'success';
                $postSuccess = true;
            } else {
                $postMessage = 'ERROR, DID YOU ALREADY DEREGISTER?';
                $postMessageClass = 'error';
            }

            mysqli_stmt_close($stmt);
        }
    }
}

$sql = "SELECT wert FROM constants WHERE name = 'abmeldekosten'";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
$abmeldekosten = $row ? (float)$row['wert'] : 0.0;
mysqli_stmt_close($stmt);

$adminUsers = [];
if ($isAdmin) {
    $sql = 'SELECT uid, name, firstname, lastname, room, oldroom FROM users WHERE pid IN (11,12,13)';
    $res = mysqli_query($conn, $sql);

    if ($res) {
        while ($u = mysqli_fetch_assoc($res)) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($u['firstname'] ?? '') . ' ' . (string)($u['lastname'] ?? ''));
            }
            if ($name === '') {
                $name = 'Unbekannter Name';
            }

            $room = resolve_room($u);

            $adminUsers[] = [
                'uid'       => (int)$u['uid'],
                'name'      => $name,
                'firstname' => trim((string)($u['firstname'] ?? '')),
                'lastname'  => trim((string)($u['lastname'] ?? '')),
                'room'      => $room,
                'oldroom'   => isset($u['oldroom']) && (int)$u['oldroom'] > 0 ? (string)(int)$u['oldroom'] : ''
            ];
        }
    }

    usort($adminUsers, static function (array $a, array $b): int {
        $cmp = strnatcasecmp($a['name'], $b['name']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strnatcasecmp($a['room'], $b['room']);
    });
}

$adminUsersJson = json_encode(
    $adminUsers,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$selfSelectionLabel = $actingRoom !== '' ? $actingName . ' · Room ' . $actingRoom : $actingName;
$alreadySubmitted = !$isAdmin && !abmeldecheck($conn, $actingUid);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .form-container { max-width:680px; margin:32px auto; padding:22px; background:#141414; border:1px solid #222; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.35); }
        .form-label { display:block; color:#ddd; margin:10px 0 6px; font-weight:600; }
        .form-input, .form-submit { box-sizing:border-box; width:100%; padding:12px; border-radius:10px; border:1px solid #2b2b2b; background:#0e0e0e; color:#eaeaea; outline:none; }
        .form-input:focus { border-color:#11a50d; box-shadow:0 0 0 3px rgba(17,165,13,.2); }
        .form-submit { background:#11a50d; border:none; font-weight:700; margin-top:16px; cursor:pointer; transition:transform .06s ease; }
        .form-submit:hover { transform:translateY(-1px); }
        .note { color:#ff7272; font-size:14px; text-align:center; margin-top:10px; }
        .note-fee { color:#ff9a9a; font-size:14px; text-align:center; margin-top:6px; }
        .subtitle { color:#88d887; font-size:14px; text-align:center; margin:-4px 0 18px; }
        .heading { color:#fff; font-size:22px; font-weight:800; text-align:center; margin:6px 0; }
        .divider { height:1px; background:#1e1e1e; margin:14px 0 18px; border:none; }
        .success { text-align:center; color:#11a50d; font-weight:700; }
        .error { color:#ff6b6b; font-weight:700; }
        .warning { color:#ffd166; font-weight:700; }

        .admin-user-search { margin-bottom:18px; }
        .admin-user-search-wrap { position:relative; }
        .admin-user-search-input { padding-right:42px; }
        .admin-user-search-icon { position:absolute; right:13px; top:50%; transform:translateY(-50%); color:#777; pointer-events:none; font-size:18px; }
        .admin-user-results { display:none; position:absolute; z-index:50; top:calc(100% + 6px); left:0; right:0; max-height:320px; overflow-y:auto; background:#0e0e0e; border:1px solid #2b2b2b; border-radius:10px; box-shadow:0 14px 30px rgba(0,0,0,.45); }
        .admin-user-results.is-open { display:block; }
        .admin-user-result { width:100%; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:11px 12px; border:0; border-bottom:1px solid #1d1d1d; background:transparent; color:#eaeaea; text-align:left; cursor:pointer; }
        .admin-user-result:last-child { border-bottom:0; }
        .admin-user-result:hover, .admin-user-result.is-active { background:#191919; }
        .admin-user-result-name { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:650; }
        .admin-user-result-room { flex:0 0 auto; color:#88d887; font-size:13px; }
        .admin-user-empty { padding:12px; color:#999; font-size:14px; }
        .admin-user-selected { margin-top:9px; padding:10px 12px; border:1px solid #252525; border-radius:10px; background:#111; color:#bbb; font-size:13px; }
        .admin-user-selected strong { color:#fff; font-weight:700; }
        .admin-user-selected.is-unset { border-color:#5b4220; color:#ffd166; }
        .admin-user-help { color:#888; font-size:12px; margin-top:6px; }
    </style>
    <script>
        const adminUsers = <?= $adminUsersJson ?: '[]' ?>;
        const actingUser = {
            uid: <?= (int)$actingUid ?>,
            name: <?= json_encode($actingName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            room: <?= json_encode($actingRoom, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        };

        function alumniFunction() {
            const chk = document.getElementById('alumni-check');
            const fwd = document.getElementById('forwardemail');
            const lbl = document.getElementById('forwardemail-label');
            if (!chk || !fwd || !lbl) return;

            if (chk.checked) {
                fwd.hidden = false;
                fwd.required = true;
                fwd.style.display = '';
                lbl.hidden = false;
                lbl.style.display = '';
            } else {
                fwd.hidden = true;
                fwd.required = false;
                fwd.style.display = 'none';
                lbl.hidden = true;
                lbl.style.display = 'none';
            }
        }

        function normalizeSearch(value) {
            return String(value || '')
                .toLocaleLowerCase('de-DE')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim();
        }

        function initAdminUserSearch() {
            const input = document.getElementById('admin-user-search-input');
            const results = document.getElementById('admin-user-results');
            const targetUid = document.getElementById('target-uid');
            const selected = document.getElementById('admin-user-selected');
            const heading = document.getElementById('user-heading');
            const form = document.getElementById('deregistration-form');

            if (!input || !results || !targetUid || !selected || !heading || !form) return;

            let activeIndex = -1;
            let currentMatches = [];

            function selectedLabel(user) {
                return user.room ? `${user.name} · Room ${user.room}` : user.name;
            }

            function setSelectedUser(user) {
                targetUid.value = String(user.uid);
                selected.classList.remove('is-unset');
                selected.innerHTML = 'Selected: <strong></strong>';
                selected.querySelector('strong').textContent = selectedLabel(user);
                heading.textContent = user.name;
                input.value = '';
                results.classList.remove('is-open');
                results.innerHTML = '';
                currentMatches = [];
                activeIndex = -1;
            }

            function clearSelectionForSearch() {
                targetUid.value = '';
                selected.classList.add('is-unset');
                selected.textContent = 'Select a person from the search results.';
            }

            function renderResults() {
                const query = normalizeSearch(input.value);
                const terms = query.split(/\s+/).filter(Boolean);

                currentMatches = adminUsers.filter((user) => {
                    if (terms.length === 0) return true;

                    const haystack = normalizeSearch([
                        user.name,
                        user.firstname,
                        user.lastname,
                        user.room,
                        user.oldroom
                    ].join(' '));

                    return terms.every((term) => haystack.includes(term));
                }).slice(0, 40);

                results.innerHTML = '';
                activeIndex = -1;

                if (currentMatches.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'admin-user-empty';
                    empty.textContent = 'No matching person found.';
                    results.appendChild(empty);
                    results.classList.add('is-open');
                    return;
                }

                currentMatches.forEach((user, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'admin-user-result';
                    button.dataset.index = String(index);

                    const name = document.createElement('span');
                    name.className = 'admin-user-result-name';
                    name.textContent = user.name;

                    const room = document.createElement('span');
                    room.className = 'admin-user-result-room';
                    room.textContent = user.room ? `Room ${user.room}` : 'No room';

                    button.appendChild(name);
                    button.appendChild(room);
                    button.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        setSelectedUser(user);
                    });

                    results.appendChild(button);
                });

                results.classList.add('is-open');
            }

            function setActiveResult(index) {
                const buttons = results.querySelectorAll('.admin-user-result');
                buttons.forEach((button) => button.classList.remove('is-active'));

                if (index < 0 || index >= buttons.length) {
                    activeIndex = -1;
                    return;
                }

                activeIndex = index;
                buttons[activeIndex].classList.add('is-active');
                buttons[activeIndex].scrollIntoView({ block: 'nearest' });
            }

            input.addEventListener('focus', renderResults);
            input.addEventListener('input', () => {
                clearSelectionForSearch();
                renderResults();
            });

            input.addEventListener('keydown', (event) => {
                if (!results.classList.contains('is-open')) return;

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActiveResult(Math.min(activeIndex + 1, currentMatches.length - 1));
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActiveResult(Math.max(activeIndex - 1, 0));
                } else if (event.key === 'Enter' && activeIndex >= 0 && currentMatches[activeIndex]) {
                    event.preventDefault();
                    setSelectedUser(currentMatches[activeIndex]);
                } else if (event.key === 'Escape') {
                    results.classList.remove('is-open');
                }
            });

            document.addEventListener('mousedown', (event) => {
                if (!event.target.closest('.admin-user-search-wrap')) {
                    results.classList.remove('is-open');
                }
            });

            form.addEventListener('submit', (event) => {
                if (!targetUid.value) {
                    event.preventDefault();
                    input.focus();
                    renderResults();
                }
            });

            setSelectedUser(actingUser);
        }

        document.addEventListener('DOMContentLoaded', function () {
            alumniFunction();
            initAdminUserSearch();
        });
    </script>
</head>
<body>
<?php load_menu(); ?>

<?php if ($postMessage !== ''): ?>
    <div class="form-container">
        <div class="<?= htmlspecialchars($postMessageClass, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($postMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($postSuccess): ?>
    <style>html, body { height:100%; margin:0; padding:0; cursor:wait; }</style>
    <form name="reload" method="post" action="<?= $pageAction ?>" style="display:none;">
        <input type="hidden" name="reload" value="0">
    </form>
    <script>
        setTimeout(function () {
            document.forms.reload.submit();
        }, 1000);
    </script>
<?php elseif ($alreadySubmitted): ?>
    <div class="form-container">
        <div style="text-align:center;">
            <span class="success">Your deregistration has been successfully submitted and is now being processed.</span>
        </div>
    </div>
<?php else: ?>
    <div class="form-container">
        <form id="deregistration-form" action="<?= $pageAction ?>" method="post" autocomplete="off">
            <div class="heading" id="user-heading"><?= htmlspecialchars($actingName, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="subtitle"><sub>Your internet access will remain active until you move out</sub></div>

            <?php if ($isAdmin): ?>
                <div class="admin-user-search">
                    <label class="form-label" for="admin-user-search-input">Act as (Admin):</label>
                    <div class="admin-user-search-wrap">
                        <input
                            type="text"
                            id="admin-user-search-input"
                            class="form-input admin-user-search-input"
                            placeholder="Search by name or room..."
                            autocomplete="off"
                        >
                        <span class="admin-user-search-icon">⌕</span>
                        <div id="admin-user-results" class="admin-user-results"></div>
                    </div>
                    <input type="hidden" id="target-uid" name="target_uid" value="<?= (int)$actingUid ?>">
                    <div id="admin-user-selected" class="admin-user-selected">
                        Selected: <strong><?= htmlspecialchars($selfSelectionLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="admin-user-help">Searchable users: residents, subletters and moved-out residents.</div>
                </div>
                <hr class="divider">
            <?php endif; ?>

            <label class="form-label" for="dod">Your move-out date:</label>
            <input type="date" id="dod" name="dod" class="form-input" required>

            <label id="iban-label" class="form-label" for="iban">IBAN:</label>
            <input
                type="text"
                id="iban"
                name="iban"
                class="form-input"
                value=""
                required
                maxlength="42"
                pattern="[A-Za-z]{2}[0-9A-Za-z ]{13,40}"
                title="Please enter a valid IBAN. Spaces are allowed (e.g. DE89 3704 0044 0532 0130 00)"
                style="text-transform:uppercase;"
            >

            <div style="margin-top:14px;">
                <input type="checkbox" id="email_account" name="email_account">
                <label for="email_account" class="form-label" style="display:inline; margin-left:6px;">I want to keep my WEH E-Mail account</label>
            </div>

            <div style="margin-top:10px;">
                <input type="checkbox" onclick="alumniFunction()" id="alumni-check" name="alumni">
                <label for="alumni-check" class="form-label" style="display:inline; margin-left:6px;">I want to receive WEH Alumni-Mails (Info/Invitation for Big Events)</label>
            </div>

            <label class="form-label" id="forwardemail-label" for="forwardemail" hidden style="display:none;">E-Mail for Alumni-Mails:</label>
            <input type="email" name="forwardemail" id="forwardemail" class="form-input" value="" hidden style="display:none;">

            <?php if ($abmeldekosten > 0): ?>
                <div class="note-fee">Fee: <?= htmlspecialchars(number_format($abmeldekosten, 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>€</div>
            <?php endif; ?>

            <div class="note">!!! Note that after submitting, your member account will be empty, so you can not print anymore !!!</div>

            <input type="hidden" name="reload" value="1">
            <input type="submit" value="Submit" class="form-submit">
        </form>
    </div>
<?php endif; ?>

<?php $conn->close(); ?>
</body>
</html>
