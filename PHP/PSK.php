<?php
session_start();
// template.php writes a title during authentication. Do not send headers yet.
ob_start();
require __DIR__ . '/conn.php';
require __DIR__ . '/template.php';
mysqli_set_charset($conn, 'utf8');
$authenticated = auth($conn);
ob_end_clean();

header('Cache-Control: no-store, private');
if (!$authenticated || empty($_SESSION['valid']) || empty($_SESSION['uid'])) {
    if (isset($_GET['search'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo '[]';
    } else {
        header('Location: denied.php');
    }
    exit;
}

$ownUid = (int) $_SESSION['uid'];
$isAdmin = !empty($_SESSION['NetzAG']);
function psk_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function psk_id($value) {
    if (!is_string($value) && !is_int($value)) return false;
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
}
function psk_query($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Database prepare failed.');
    if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
        $stmt->close();
        throw new RuntimeException('Database bind failed.');
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Database execute failed.');
    }
    return $stmt;
}
function psk_user($conn, $uid) {
    $stmt = psk_query($conn, 'SELECT u.name, u.username, u.room, u.pid, u.turm FROM users u WHERE u.uid = ? LIMIT 1', 'i', [$uid]);
    $stmt->bind_result($name, $username, $room, $pid, $turm);
    $user = $stmt->fetch() ? compact('name', 'username', 'room', 'pid', 'turm') : null;
    $stmt->close();
    return $user;
}
if (isset($_GET['search'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$isAdmin) {
        http_response_code(403);
        echo '[]';
        exit;
    }
    $term = is_string($_GET['search']) ? trim($_GET['search']) : '';
    $results = [];
    if ($term !== '' && strlen($term) <= 200) {
        $like = '%' . $term . '%';
        $stmt = psk_query($conn, "SELECT uid, name, username, room, oldroom, turm FROM users WHERE name LIKE ? OR username LIKE ? OR email LIKE ? OR aliase LIKE ? OR geburtsort LIKE ? OR (? REGEXP '^[0-9]+$' AND (room = ? OR uid = ? OR oldroom = ?)) ORDER BY name", 'sssssssss', [$like, $like, $like, $like, $like, $term, $term, $term, $term]);
        $stmt->bind_result($id, $name, $username, $room, $oldroom, $turm);
        while ($stmt->fetch()) $results[] = ['uid' => $id, 'name' => $name, 'username' => $username, 'room' => $room, 'oldroom' => $oldroom, 'turm' => $turm];
        $stmt->close();
    }
    echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function psk_token($actor, $target, $isAdmin) {
    return hash_hmac('sha256', 'PSK|' . $actor . '|' . $target . '|' . (int)$isAdmin, $_SESSION['psk_secret']);
}
function psk_hidden($uid, $ownUid, $csrf) {
    echo '<input type="hidden" name="uid" value="' . $uid . '">';
    echo '<input type="hidden" name="form_actor" value="' . $ownUid . '">';
    echo '<input type="hidden" name="csrf" value="' . psk_h($csrf) . '">';
}
function psk_text($key) {
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}
if (empty($_SESSION['psk_secret'])) $_SESSION['psk_secret'] = bin2hex(random_bytes(32));
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$target = psk_id($_POST['uid'] ?? $_GET['uid'] ?? $ownUid);
$uid = $isAdmin && $target !== false ? $target : $ownUid;
$user = psk_user($conn, $uid);
$error = '';
$message = '';
$mac = $device = $hersteller = '';
if (!$user) {
    $uid = $ownUid;
    $user = psk_user($conn, $uid);
    $error = 'Der ausgewählte Benutzer existiert nicht.';
}
if (!$user) { http_response_code(403); exit; }
$csrf = psk_token($ownUid, $uid, $isAdmin);
if (isset($_SESSION['psk_flash'])) {
    $flash = $_SESSION['psk_flash'];
    unset($_SESSION['psk_flash']);
    if ((int)$flash['actor'] === $ownUid && (int)$flash['target'] === $uid) $message = $flash['message'];
}
if ($posted && (isset($_POST['sendpost']) || isset($_POST['toggleAdminPanel']))) {
    $token = $_POST['csrf'] ?? null;
    $actor = psk_id($_POST['form_actor'] ?? null);
    if ($target === false || $target !== $uid || $actor !== $ownUid || !is_string($token) || !hash_equals($csrf, $token)) {
        $error = 'Das Formular ist nicht mehr gültig. Bitte erneut ausfüllen. Es wurde keine Anfrage gespeichert.';
        error_log('PSK: stale or invalid form, actor=' . $ownUid);
    } elseif (isset($_POST['toggleAdminPanel'])) {
        if (!$isAdmin || isset($_POST['sendpost'])) {
            $error = 'Diese Aktion ist nicht erlaubt.';
        } else {
            $_SESSION['AdminPanelToggleState'] = ($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block' ? 'none' : 'block';
        }
    } else {
        $mac = strtoupper(psk_text('mac'));
        $device = psk_text('device');
        $hersteller = psk_text('hersteller');
        $description = $uid . ' - ' . $device . ' (' . $hersteller . ')';
        $filePath = null;
        try {
            if (!preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/D', $mac)) {
                throw new InvalidArgumentException('Ungültige MAC-Adresse. Beispiel: 12:34:56:78:9A:BC');
            }
            if ($device === '' || $hersteller === '' || preg_match('/[\x00-\x1F\x7F]/', $description)) {
                throw new InvalidArgumentException('Bitte gib Gerät und Hersteller an, ohne Steuerzeichen.');
            }
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Bitte lade ein Bild hoch. Prüfe gegebenenfalls die Dateigröße.');
            }
            $info = @getimagesize($file['tmp_name']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($formats[$mime]) || !$info || ($info['mime'] ?? '') !== $mime) {
                throw new InvalidArgumentException('Nur gültige JPG-, JPEG- und PNG-Bilder sind erlaubt.');
            }
            $directory = __DIR__ . '/pskpictures';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Upload directory unavailable.');
            }
            $zeit = time();
            // Keep timestamp-based filenames, but avoid overwriting concurrent uploads.
            $fileName = $zeit . '-' . bin2hex(random_bytes(8)) . '.' . $formats[$mime];
            $relative = 'pskpictures/' . $fileName;
            $destination = __DIR__ . '/' . $relative;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('Upload failed.');
            }
            $filePath = $destination;
            $stmt = psk_query($conn, 'INSERT INTO pskonly (uid, tstamp, mac, beschreibung, pfad) VALUES (?,?,?,?,?)', 'iisss', [$uid, $zeit, $mac, $description, $relative]);
            $stmt->close();
            $filePath = null;
            $_SESSION['psk_flash'] = ['actor' => $ownUid, 'target' => $uid, 'message' => 'Die Anfrage wurde erfolgreich gesendet.'];
            header('Location: PSK.php' . ($isAdmin ? '?uid=' . $uid : ''), true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('PSK: registration failed: ' . $e->getMessage());
            $error = 'Die Anfrage konnte nicht gespeichert werden. Bitte versuche es erneut.';
        } finally {
            if ($filePath !== null) @unlink($filePath);
        }
    }
} elseif ($posted && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'Die Anfrage war zu groß oder konnte nicht gelesen werden. Bitte prüfe die Dateigröße.';
}
$stmt = psk_query($conn, 'SELECT beschreibung, mac, status FROM pskonly WHERE uid = ? ORDER BY tstamp DESC', 'i', [$uid]);
$stmt->bind_result($description, $registeredMac, $status);
$registrations = [];
while ($stmt->fetch()) $registrations[] = ['description' => $description, 'mac' => $registeredMac, 'status' => $status];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WEH Backend</title>
<link rel="stylesheet" href="WEH.css" media="screen">
<style>
#pskPage { width:94%; max-width:1450px; margin:18px auto 30px; color:#fff; --psk-accent:<?= ($_SESSION['turm'] ?? '') === 'tvk' ? '#FFA500' : '#11a50d' ?>; --psk-muted:#bdbdbd; }
#pskPage * { box-sizing:border-box; }
#pskPage .psk-grid { display:grid; grid-template-columns:minmax(0, 1.25fr) minmax(0, 1fr); gap:18px; align-items:start; }
#pskPage .psk-panel { min-width:0; border:1px solid #666; border-radius:10px; padding:20px 24px; background:#1c1b1b; }
#pskPage h1 { font-size:29px; margin:0 0 6px; }
#pskPage h2 { font-size:23px; margin:0 0 12px; }
#pskPage p { margin:6px 0; line-height:1.45; }
#pskPage .psk-muted { color:var(--psk-muted); font-size:15px; }
#pskPage .psk-title { margin-bottom:18px; }
#pskPage .psk-target { color:var(--psk-accent); font-weight:600; }
#pskPage .psk-table-wrap { overflow-x:auto; }
#pskPage .psk-table { width:100%; border-collapse:separate; border-spacing:0; text-align:left; }
#pskPage .psk-table th { padding:11px 12px; font-size:15px; text-align:left; background:#292828; border-bottom:1px solid #555; color:#fff; }
#pskPage .psk-table td { padding:13px 12px; vertical-align:middle; border-bottom:1px solid #393939; color:#eee; }
#pskPage .psk-table tbody tr:hover { background:#252424; }
#pskPage .psk-description { font-size:17px; font-weight:600; overflow-wrap:anywhere; }
#pskPage .psk-mac { font:15px monospace; white-space:nowrap; }
#pskPage .psk-status { font-weight:600; font-size:15px; white-space:nowrap; }
#pskPage .psk-approved { color:#52d548; }
#pskPage .psk-rejected { color:#ff8781; }
#pskPage .psk-pending { color:#ddd; }
#pskPage .psk-empty { padding:18px 0; color:var(--psk-muted); }
#pskPage .psk-instructions { margin:0 0 18px; padding:0 0 15px; border-bottom:1px solid #444; font-size:16px; }
#pskPage .psk-fields { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
#pskPage .psk-field { min-width:0; display:flex; flex-direction:column; gap:6px; }
#pskPage .psk-wide { grid-column:1/-1; }
#pskPage .psk-field label { font-size:15px; font-weight:600; }
#pskPage .psk-input { width:100%; min-width:0; border:1px solid #5d5d5d; border-radius:4px; background:#252424; color:#fff; font:16px/1.2 Arial,sans-serif; padding:10px 12px; outline:none; }
#pskPage .psk-input:focus { border-color:var(--psk-accent); box-shadow:0 0 0 1px var(--psk-accent); }
#pskPage .psk-input::placeholder { color:#999; }
#pskPage .psk-file::file-selector-button { background:#fff; color:#111; border:0; border-radius:3px; padding:7px 10px; margin-right:10px; cursor:pointer; }
#pskPage .psk-submit { display:block; width:100%; margin-top:18px; background:var(--psk-accent); color:#000; border:2px solid #000; border-radius:4px; font:600 19px Arial,sans-serif; padding:11px 16px; cursor:pointer; }
#pskPage .psk-submit:hover { filter:brightness(1.15); }
#pskPage .psk-alert { padding:12px 16px; border:1px solid; border-radius:6px; margin:0 0 14px; line-height:1.4; }
#pskPage .psk-alert-ok { color:#baf4ae; background:#20331d; border-color:#46743c; }
#pskPage .psk-alert-error { color:#ffb9b4; background:#402522; border-color:#a34d43; }
@media (max-width:1050px) { #pskPage .psk-grid { grid-template-columns:minmax(0,1fr); } }
@media (max-width:540px) {
    #pskPage { width:95%; }
    #pskPage .psk-panel { padding:15px; }
    #pskPage .psk-fields { grid-template-columns:minmax(0,1fr); }
    #pskPage .psk-table, #pskPage .psk-table tbody { display:block; }
    #pskPage .psk-table thead { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
    #pskPage .psk-table tr { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; padding:12px 0; border-bottom:1px solid #393939; }
    #pskPage .psk-table td { display:block; padding:0; border:0; min-width:0; }
    #pskPage .psk-description { grid-column:1/-1; }
    #pskPage .psk-mac { white-space:normal; overflow-wrap:anywhere; }
}
</style>
</head>
<body>
<?php load_menu(); ?>
<?php if ($isAdmin): ?>
<div style="margin:0 auto 16px;text-align:center;">
    <div style="border:2px solid white;border-radius:10px;display:inline-block;padding:20px;text-align:center;background-color:transparent;max-width:95%;">
        <form method="post" action="PSK.php" style="margin:0;">
            <?php psk_hidden($uid, $ownUid, $csrf); ?>
            <input type="hidden" name="toggleAdminPanel" value="1">
            <span class="white-text" role="button" tabindex="0" aria-expanded="<?= ($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block' ? 'true' : 'false' ?>" style="font-size:35px;cursor:pointer;display:inline-block;" onclick="this.closest('form').requestSubmit()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.closest('form').requestSubmit();}">Admin Panel</span>
        </form>
        <?php if (($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block'): ?>
        <div id="adminPanel">
            <?php renderUserPostButtons($conn, $uid); ?>
            <form method="get" action="PSK.php" id="pskUidForm">
                <input type="number" min="1" name="uid" id="pskUid" placeholder="UID" value="<?= $uid ?>" style="margin-top:20px;font-size:20px;text-align:center;max-width:100%;" onchange="this.form.requestSubmit()">
            </form>
            <form method="get" action="PSK.php">
                <select name="uid" style="margin-top:20px;font-size:20px;text-align:center;max-width:100%;" onchange="this.form.requestSubmit()">
                    <?php
                    $labels = [12 => 'Subletter', 13 => 'Ausgezogen', 14 => 'Abgemeldet', 64 => 'Dummy'];
                    $label = (int)$user['pid'] === 11 ? ($user['turm'] === 'tvk' ? 'TvK' : strtoupper((string)$user['turm'])) . ' ' . $user['room'] : ($labels[$user['pid']] ?? 'Undefined');
                    echo '<option value="' . $uid . '" selected>' . psk_h($user['name'] . ' [' . $label . ']') . '</option>';
                    $stmt = psk_query($conn, "SELECT uid, name, room, turm FROM users WHERE pid=11 AND uid<>? ORDER BY FIELD(turm,'weh','tvk'),room", 'i', [$uid]);
                    $stmt->bind_result($idOption, $name, $room, $turm);
                    while ($stmt->fetch()) echo '<option value="' . (int)$idOption . '">' . psk_h($name . ' [' . ($turm === 'tvk' ? 'TvK' : strtoupper((string)$turm)) . ' ' . $room . ']') . '</option>';
                    $stmt->close();
                    ?>
                </select>
            </form>
            <input type="search" id="pskSearch" placeholder="Suche nach User..." autocomplete="off" style="display:block;margin:20px auto;height:30px;font-size:25px;max-width:100%;">
            <div style="margin:0 auto;overflow-x:auto;"><table class="center-table" style="margin:0 auto;"><tbody id="pskSearchResults"></tbody></table></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<main id="pskPage">
    <div class="psk-title">
        <h1>PSK-only Registrierung</h1>
        <?php if ($isAdmin): ?><p class="psk-muted">Registrierungen für <span class="psk-target"><?= psk_h($user['name']) ?></span> (UID <?= $uid ?>)</p><?php endif; ?>
    </div>
    <?php if ($error !== ''): ?><div class="psk-alert psk-alert-error" role="alert"><?= psk_h($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="psk-alert psk-alert-ok" role="status"><?= psk_h($message) ?></div><?php endif; ?>
    <div class="psk-grid">
        <section class="psk-panel">
            <h2>Registrierte Geräte</h2>
            <?php if (!$registrations): ?>
                <p class="psk-empty">Noch keine PSK-only-Registrierungen vorhanden.</p>
            <?php else: ?>
            <div class="psk-table-wrap">
                <table class="psk-table">
                    <thead><tr><th>Beschreibung</th><th>MAC-Adresse</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($registrations as $row):
                        $statuses = [-1 => ['Abgelehnt','psk-rejected'], 0 => ['Ausstehend','psk-pending'], 1 => ['Angenommen','psk-approved']];
                        [$label, $class] = $statuses[$row['status'] ?? ''] ?? ['Unbekannt','psk-pending'];
                        // The stored description includes the UID for the WLC. Hide that redundant prefix only in the UI.
                        $displayDescription = (string)$row['description'];
                        $prefix = $uid . ' - ';
                        if (strpos($displayDescription, $prefix) === 0) $displayDescription = substr($displayDescription, strlen($prefix));
                    ?>
                        <tr><td class="psk-description"><?= psk_h($displayDescription) ?></td><td class="psk-mac"><?= psk_h($row['mac']) ?></td><td><span class="psk-status <?= $class ?>"><?= psk_h($label) ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <section class="psk-panel">
            <h2>Neues Gerät registrieren</h2>
            <div class="psk-instructions">
                <p>Hier kannst du ein Gerät für <strong>weh-pskonly</strong> registrieren. Lade ein Bild hoch, auf dem die MAC-Adresse eindeutig dem Gerät zugeordnet werden kann.</p>
                <p class="psk-muted">Wir versuchen, die Anfrage innerhalb einer Woche zu beantworten. Die Zugangsdaten werden per E-Mail mitgeteilt.</p>
            </div>
            <form method="post" action="PSK.php" enctype="multipart/form-data">
                <?php psk_hidden($uid, $ownUid, $csrf); ?>
                <input type="hidden" name="sendpost" value="1">
                <div class="psk-fields">
                    <div class="psk-field psk-wide"><label for="mac">MAC-Adresse</label><input class="psk-input" id="mac" name="mac" value="<?= psk_h($mac) ?>" placeholder="12:34:56:78:9A:BC" required autocomplete="off" spellcheck="false" maxlength="17"></div>
                    <div class="psk-field"><label for="device">Gerät</label><input class="psk-input" id="device" name="device" value="<?= psk_h($device) ?>" placeholder="z. B. Echo Dot" required></div>
                    <div class="psk-field"><label for="hersteller">Hersteller</label><input class="psk-input" id="hersteller" name="hersteller" value="<?= psk_h($hersteller) ?>" placeholder="z. B. Amazon" required></div>
                    <div class="psk-field psk-wide"><label for="file">Bild als Nachweis</label><input class="psk-input psk-file" type="file" id="file" name="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required><span class="psk-muted">JPG, JPEG oder PNG</span></div>
                </div>
                <button type="submit" class="psk-submit">Registrieren</button>
            </form>
        </section>
    </div>
</main>
<script>
(() => {
    const search = document.getElementById('pskSearch');
    if (!search) return;
    const results = document.getElementById('pskSearchResults');
    let controller, timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        if (controller) controller.abort();
        const value = search.value.trim();
        if (!value) { results.replaceChildren(); return; }
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch('PSK.php?search=' + encodeURIComponent(value), { signal: controller.signal, cache: 'no-store' });
                if (!response.ok) throw new Error('Search failed');
                const users = await response.json();
                if (search.value.trim() !== value) return;
                results.replaceChildren();
                for (const user of users) {
                    const row = results.insertRow();
                    row.insertCell().textContent = user.uid;
                    row.insertCell().textContent = user.username;
                    const name = row.insertCell();
                    const link = document.createElement('a');
                    link.href = 'PSK.php?uid=' + encodeURIComponent(user.uid);
                    link.className = 'white-text';
                    link.textContent = user.name;
                    name.append(link);
                    const tower = row.insertCell();
                    tower.textContent = user.turm === 'tvk' ? 'TvK' : String(user.turm).toUpperCase();
                    const room = row.insertCell();
                    room.textContent = Number(user.room) === 0 ? user.oldroom : user.room;
                    tower.style.color = room.style.color = Number(user.room) === 0 ? '#aaa' : user.turm === 'tvk' ? '#FFA500' : '#18ec13';
                }
            } catch (error) {
                if (error.name !== 'AbortError') results.replaceChildren();
            }
        }, 180);
    });
})();
</script>
<?php $conn->close(); ?>
</body>
</html>
