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
$canSeeSublet = !empty($_SESSION['Webmaster']);

function ipv_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ipv_id($value) {
    if (!is_string($value) && !is_int($value)) return false;
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
}
function ipv_mac($value) {
    if (!is_string($value)) throw new InvalidArgumentException('Invalid MAC address.');
    $value = strtoupper(trim($value));
    if ($value === '') return '';
    // Accept common MAC formats, then save the canonical colon-separated form.
    if (preg_match('/^(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/D', $value) ||
        preg_match('/^(?:[0-9A-F]{2}[ .]){5}[0-9A-F]{2}$/D', $value) ||
        preg_match('/^(?:[0-9A-F]{4}\.){2}[0-9A-F]{4}$/D', $value) ||
        preg_match('/^[0-9A-F]{12}$/D', $value)) {
        return implode(':', str_split(str_replace([':', '-', '.', ' '], '', $value), 2));
    }
    throw new InvalidArgumentException('Invalid MAC address: ' . $value . '. Use XX:XX:XX:XX:XX:XX.');
}
function ipv_ip($value) {
    return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}
function ipv_query($conn, $sql, $types = '', $params = []) {
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
function ipv_user($conn, $uid) {
    $stmt = ipv_query($conn, 'SELECT u.name, u.username, u.room, u.pid, u.turm, u.subnet, n.ip FROM users u LEFT JOIN natmapping n ON u.subnet = n.subnet WHERE u.uid = ? LIMIT 1', 'i', [$uid]);
    $stmt->bind_result($name, $username, $room, $pid, $turm, $subnet, $public_ip);
    $user = $stmt->fetch() ? compact('name', 'username', 'room', 'pid', 'turm', 'subnet', 'public_ip') : null;
    $stmt->close();
    return $user;
}
function ipv_rows($conn, $uid, $lock = false) {
    $stmt = ipv_query($conn, 'SELECT id, ip, mac1, mac2, mac3, hostname, sublet FROM macauth WHERE uid = ? ORDER BY INET_ATON(ip), id' . ($lock ? ' FOR UPDATE' : ''), 'i', [$uid]);
    $stmt->bind_result($id, $ip, $mac1, $mac2, $mac3, $hostname, $sublet);
    $rows = [];
    while ($stmt->fetch()) {
        $rows[(int)$id] = ['ip' => (string)$ip, 'mac1' => (string)$mac1, 'mac2' => (string)$mac2, 'mac3' => (string)$mac3, 'hostname' => (string)$hostname, 'sublet' => (int)$sublet];
    }
    $stmt->close();
    return $rows;
}
function ipv_version($row) {
    return hash('sha256', serialize($row));
}
function ipv_pool($user, $private) {
    $pool = [];
    if ($private) {
        $subnet = (string)($user['subnet'] ?? '');
        // Preserve the existing private-IP pool; require the expected IPv4 .0 subnet.
        if (!ipv_ip($subnet) || substr($subnet, -2) !== '.0') return [];
        $prefix = substr($subnet, 0, -1);
        for ($i = 1; $i <= 255; $i++) $pool[] = $prefix . $i;
    } else {
        $turm = $user['turm'];
        if ($turm !== 'tvk') {
            foreach (array_merge(range(180, 199), range(207, 254)) as $i) $pool[] = '137.226.141.' . $i;
        }
        if ($turm !== 'weh') {
            foreach (range($turm === 'tvk' ? 129 : 1, 254) as $i) $pool[] = '137.226.143.' . $i;
        }
    }
    return $pool;
}
function ipv_free_ips($conn, $user, $private) {
    $pool = ipv_pool($user, $private);
    if (!$pool) return [];
    $stmt = ipv_query($conn, $private ? 'SELECT ip FROM macauth' : 'SELECT ip FROM macauth UNION SELECT ip FROM aps');
    $stmt->bind_result($ip);
    $used = [];
    while ($stmt->fetch()) $used[(string)$ip] = true;
    $stmt->close();
    return array_values(array_filter($pool, static function ($ip) use ($used) { return !isset($used[$ip]); }));
}
function ipv_check_free($conn, $ip, $exceptId = 0) {
    $stmt = ipv_query($conn, 'SELECT id FROM macauth WHERE ip = ? AND id <> ? LIMIT 1', 'si', [$ip, $exceptId]);
    $stmt->store_result();
    $used = $stmt->num_rows > 0;
    $stmt->close();
    $stmt = ipv_query($conn, 'SELECT ip FROM aps WHERE ip = ? LIMIT 1', 's', [$ip]);
    $stmt->store_result();
    $used = $used || $stmt->num_rows > 0;
    $stmt->close();
    if ($used) throw new InvalidArgumentException('IP address ' . $ip . ' is already assigned.');
    if (preg_match('/^137\.226\.141\.(20[0-6])$/D', $ip)) {
        throw new InvalidArgumentException('This NoProxy IP address must be assigned directly in the database.');
    }
}
function ipv_security($actor, $target, $reason, $notify = false) {
    // Only minimal audit data; never send full POST/session dumps.
    $line = 'IPverwaltung: actor=' . $actor . ' target=' . $target . ' reason=' . $reason;
    error_log($line);
    if ($notify && (int)($_SESSION['ipv_last_alert'] ?? 0) + 300 < time()) {
        $_SESSION['ipv_last_alert'] = time();
        if (!mail('webmaster@weh.rwth-aachen.de', 'IPverwaltung: blockierter unberechtigter Zugriff', $line . "\nDie Aktion wurde blockiert. Bitte bei Bedarf anhand der Serverlogs pruefen.", 'From: webmaster@weh.rwth-aachen.de')) {
            error_log('IPverwaltung: alert mail could not be sent');
        }
    }
}
function ipv_token($actor, $target, $isAdmin) {
    return hash_hmac('sha256', 'IPverwaltung|' . $actor . '|' . $target . '|' . (int)$isAdmin, $_SESSION['ipv_secret']);
}
function ipv_row_proof($actor, $target, $id, $version) {
    return hash_hmac('sha256', 'IPverwaltung-row|' . $actor . '|' . $target . '|' . $id . '|' . $version, $_SESSION['ipv_secret']);
}
function ipv_redirect($uid, $ownUid, $isAdmin, $message) {
    $_SESSION['ipv_flash'] = ['actor' => $ownUid, 'target' => $uid, 'message' => $message];
    header('Location: IPverwaltung.php' . ($isAdmin && $uid !== $ownUid ? '?uid=' . $uid : ''), true, 303);
    exit;
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
        $stmt = ipv_query($conn, "SELECT uid, name, username, room, oldroom, turm FROM users WHERE name LIKE ? OR username LIKE ? OR email LIKE ? OR aliase LIKE ? OR geburtsort LIKE ? OR (? REGEXP '^[0-9]+$' AND (room = ? OR uid = ? OR oldroom = ?)) ORDER BY name", 'sssssssss', [$like, $like, $like, $like, $like, $term, $term, $term, $term]);
        $stmt->bind_result($id, $name, $username, $room, $oldroom, $turm);
        while ($stmt->fetch()) $results[] = ['uid' => $id, 'name' => $name, 'username' => $username, 'room' => $room, 'oldroom' => $oldroom, 'turm' => $turm];
        $stmt->close();
    }
    echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['ipv_secret'])) $_SESSION['ipv_secret'] = bin2hex(random_bytes(32));
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$target = ipv_id($_POST['uid'] ?? $_GET['uid'] ?? $ownUid);
$uid = $isAdmin && $target !== false ? $target : $ownUid;
$user = ipv_user($conn, $uid);
$message = '';
$error = '';
$draft = [];
$validSaveForm = false;
if (!$user) {
    $uid = $ownUid;
    $user = ipv_user($conn, $uid);
    $error = 'The selected user does not exist.';
}
if (!$user) { http_response_code(403); exit; }

if (isset($_SESSION['ipv_flash'])) {
    $flash = $_SESSION['ipv_flash'];
    unset($_SESSION['ipv_flash']);
    if ((int)$flash['actor'] === $ownUid && (int)$flash['target'] === $uid) $message = $flash['message'];
}

// Keep compatibility with user-selection POSTs from other backend pages, but
// require a session/actor/target-bound token for every write or panel toggle.
$action = null;
if ($posted) {
    foreach (['save', 'remove', 'addprivateip', 'addpublicip', 'toggleAdminPanel'] as $candidate) {
        if (isset($_POST[$candidate])) {
            if ($action !== null) { $action = 'invalid'; break; }
            $action = $candidate;
        }
    }
}
if ($action !== null) {
    $token = $_POST['csrf'] ?? null;
    $actor = ipv_id($_POST['form_actor'] ?? null);
    if ($target === false || $target !== $uid || $actor !== $ownUid || !is_string($token) || !hash_equals(ipv_token($ownUid, $uid, $isAdmin), $token)) {
        ipv_security($ownUid, $target === false ? 0 : $target, 'stale_or_invalid_form');
        $error = 'This form is no longer valid. Your login or permissions may have changed. No changes were saved. Please use the current page.';
    } elseif ($action === 'invalid') {
        ipv_security($ownUid, $uid, 'multiple_actions', true);
        $error = 'Invalid request. No changes were saved.';
    } elseif ($action !== 'save' && !$isAdmin) {
        ipv_security($ownUid, $uid, 'admin_action_without_permission', true);
        $error = 'You are not allowed to perform this action.';
    } elseif ($action === 'toggleAdminPanel') {
        $_SESSION['AdminPanelToggleState'] = ($_SESSION['AdminPanelToggleState'] ?? 'none') === 'none' ? 'block' : 'none';
    } else {
        $inTransaction = false;
        $ipLock = false;
        try {
            // Serialize IP allocation/editing from this page, including add/remove.
            // External writers still need their own coordination or a DB constraint.
            if ($isAdmin) {
                $stmt = ipv_query($conn, "SELECT GET_LOCK('weh.ipverwaltung.write', 5)");
                $stmt->bind_result($locked);
                $stmt->fetch();
                $stmt->close();
                if ((int)$locked !== 1) throw new InvalidArgumentException('IP management is busy. Please try again.');
                $ipLock = true;
            }
            if (!$conn->begin_transaction()) throw new RuntimeException('Cannot start transaction.');
            $inTransaction = true;
            $current = ipv_rows($conn, $uid, true);
            $success = '';
            if ($action === 'save') {
                $validSaveForm = true;
                $submitted = $_POST['rows'] ?? null;
                if (!is_array($submitted)) throw new InvalidArgumentException('Incomplete form. No changes were saved.');
                $updates = [];
                $newIps = [];
                foreach ($submitted as $key => $row) {
                    $id = ipv_id($key);
                    if (!is_array($row) || !is_string($row['version'] ?? null) || !is_string($row['proof'] ?? null)) throw new InvalidArgumentException('Incomplete form. No changes were saved.');
                    if ($id === false || !hash_equals(ipv_row_proof($ownUid, $uid, $id, $row['version']), $row['proof'])) {
                        ipv_security($ownUid, $uid, 'invalid_row_reference', true);
                        throw new InvalidArgumentException('An IP entry could not be verified. No changes were saved.');
                    }
                    // A previously issued, signed row may have been deleted or reassigned.
                    // Treat this as stale data, not a confirmed unauthorized request.
                    if (!isset($current[$id])) throw new InvalidArgumentException('The IP list has changed. Please review the current data.');
                    $old = $current[$id];
                    if (!hash_equals(ipv_version($old), $row['version'])) {
                        throw new InvalidArgumentException('An entry has changed since this page was opened. Please review the current data before saving again.');
                    }
                    foreach (['mac1', 'mac2', 'mac3', 'hostname'] as $field) {
                        if (!isset($row[$field]) || !is_string($row[$field])) throw new InvalidArgumentException('Incomplete form. No changes were saved.');
                    }
                    if (!$isAdmin && array_key_exists('ip', $row)) {
                        ipv_security($ownUid, $uid, 'ip_edit_without_permission', true);
                        throw new InvalidArgumentException('Only NetzAG members may change IP addresses.');
                    }
                    if ($isAdmin && (!isset($row['ip']) || !is_string($row['ip']))) throw new InvalidArgumentException('Incomplete IP address.');
                    $new = $old;
                    foreach (['mac1', 'mac2', 'mac3', 'hostname'] as $field) $new[$field] = $row[$field];
                    if ($isAdmin) $new['ip'] = trim($row['ip']);
                    $draft[$id] = $new;
                    // Validate even unused MAC slots; existing unchanged rows are left alone.
                    foreach (['mac1', 'mac2', 'mac3'] as $field) {
                        if ($new[$field] !== $old[$field]) $new[$field] = ipv_mac($new[$field]);
                    }
                    if ($new['hostname'] !== $old['hostname']) {
                        if (!preg_match('//u', $new['hostname']) || preg_match('/[\x00-\x1F\x7F]/', $new['hostname']) || strlen($new['hostname']) > 765 || preg_match('/^.{256}/us', $new['hostname'])) {
                            throw new InvalidArgumentException('Description must be valid text with at most 255 characters and no line breaks.');
                        }
                    }
                    if ($isAdmin && $new['ip'] !== $old['ip']) {
                        if (!ipv_ip($new['ip'])) throw new InvalidArgumentException('Invalid IPv4 address: ' . $new['ip']);
                        if (isset($newIps[$new['ip']])) throw new InvalidArgumentException('The same IP address was entered more than once.');
                        ipv_check_free($conn, $new['ip'], $id);
                        $newIps[$new['ip']] = true;
                    }
                    if ($new !== $old) $updates[$id] = $new;
                }
                // Detect PHP max_input_vars truncation / deleted rows instead of saving a partial form.
                if (count($submitted) !== count($current)) throw new InvalidArgumentException('The IP list has changed or the form is incomplete. No changes were saved.');
                foreach ($updates as $id => $new) {
                    if ($isAdmin) {
                        $stmt = ipv_query($conn, 'UPDATE macauth SET mac1=?, mac2=?, mac3=?, hostname=?, ip=? WHERE id=? AND uid=?', 'sssssii', [$new['mac1'], $new['mac2'], $new['mac3'], $new['hostname'], $new['ip'], $id, $uid]);
                    } else {
                        $stmt = ipv_query($conn, 'UPDATE macauth SET mac1=?, mac2=?, mac3=?, hostname=? WHERE id=? AND uid=?', 'ssssii', [$new['mac1'], $new['mac2'], $new['mac3'], $new['hostname'], $id, $ownUid]);
                    }
                    $stmt->close();
                }
                $success = $updates ? 'Changes saved.' : 'No changes to save.';
            } elseif ($action === 'remove') {
                $id = ipv_id($_POST['remove']);
                $entry = $id !== false ? ($_POST['rows'][$id] ?? null) : null;
                $version = is_array($entry) ? ($entry['version'] ?? null) : null;
                $proof = is_array($entry) ? ($entry['proof'] ?? null) : null;
                if ($id === false || !is_string($version) || !is_string($proof) || !hash_equals(ipv_row_proof($ownUid, $uid, $id, $version), $proof)) {
                    ipv_security($ownUid, $uid, 'invalid_remove_reference', true);
                    throw new InvalidArgumentException('This IP entry could not be verified.');
                }
                if (!isset($current[$id]) || !hash_equals(ipv_version($current[$id]), $version)) throw new InvalidArgumentException('This entry has changed. Please review it before removing it.');
                $stmt = ipv_query($conn, 'DELETE FROM macauth WHERE id=? AND uid=?', 'ii', [$id, $uid]);
                $stmt->close();
                $success = 'IP address removed.';
            } else {
                $private = $action === 'addprivateip';
                $ip = $_POST[$private ? 'selected_ip_priv' : 'selected_ip'] ?? null;
                $userNow = ipv_user($conn, $uid);
                if (!$userNow || !ipv_ip($ip) || !in_array($ip, ipv_pool($userNow, $private), true)) throw new InvalidArgumentException('This IP address is not in the available pool.');
                ipv_check_free($conn, $ip);
                $stmt = ipv_query($conn, 'INSERT INTO macauth (uid, tstamp, ip) VALUES (?,?,?)', 'iis', [$uid, time(), $ip]);
                $stmt->close();
                $success = 'IP address added.';
            }
            if (!$conn->commit()) throw new RuntimeException('Commit failed.');
            $inTransaction = false;
            if ($ipLock) {
                $stmt = ipv_query($conn, "SELECT RELEASE_LOCK('weh.ipverwaltung.write')");
                $stmt->close();
                $ipLock = false;
            }
            ipv_redirect($uid, $ownUid, $isAdmin, $success);
        } catch (InvalidArgumentException $e) {
            if ($inTransaction) $conn->rollback();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($inTransaction) $conn->rollback();
            error_log('IPverwaltung DB error: ' . $e->getMessage());
            $error = 'The changes could not be saved. Please try again.';
        } finally {
            if ($ipLock) {
                try { $stmt = ipv_query($conn, "SELECT RELEASE_LOCK('weh.ipverwaltung.write')"); $stmt->close(); }
                catch (Throwable $e) { error_log('IPverwaltung lock release failed'); }
            }
        }
    }
}

$rows = ipv_rows($conn, $uid);
$csrf = ipv_token($ownUid, $uid, $isAdmin);
// Preserve the submitted values on ordinary validation failures, but never
// carry a stale row forward onto a different DB version.
if ($validSaveForm && $error !== '' && is_array($_POST['rows'] ?? null)) {
    foreach ($rows as $id => $row) {
        $sent = $_POST['rows'][$id] ?? null;
        if (!is_array($sent) || !is_string($sent['version'] ?? null) || !is_string($sent['proof'] ?? null) || !hash_equals(ipv_row_proof($ownUid, $uid, $id, $sent['version']), $sent['proof']) || !hash_equals(ipv_version($row), $sent['version'])) { unset($draft[$id]); continue; }
        foreach (['mac1', 'mac2', 'mac3', 'hostname'] as $field) {
            if (is_string($sent[$field] ?? null)) $draft[$id][$field] = $sent[$field];
        }
        if ($isAdmin && is_string($sent['ip'] ?? null)) $draft[$id]['ip'] = $sent['ip'];
    }
}
$freePrivate = $isAdmin ? ipv_free_ips($conn, $user, true) : [];
$freePublic = $isAdmin ? ipv_free_ips($conn, $user, false) : [];
function ipv_hidden($uid, $ownUid, $csrf) {
    echo '<input type="hidden" name="uid" value="' . $uid . '">';
    echo '<input type="hidden" name="form_actor" value="' . $ownUid . '">';
    echo '<input type="hidden" name="csrf" value="' . ipv_h($csrf) . '">';
}
function ipv_field($id, $field, $row, $draft, $label, $class = '', $max = 255) {
    $value = $draft[$id][$field] ?? $row[$field];
    echo '<input class="ipv-input ' . ipv_h($class) . '" type="text" name="rows[' . $id . '][' . $field . ']" value="' . ipv_h($value) . '" data-original="' . ipv_h($row[$field]) . '" data-track aria-label="' . ipv_h($label) . '" placeholder="' . ipv_h($field === 'hostname' ? 'e.g. Laptop' : ($field === 'ip' ? 'IPv4 address' : 'XX:XX:XX:XX:XX:XX')) . '" maxlength="' . $max . '" autocomplete="off" spellcheck="false">';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WEH Backend</title>
<link rel="stylesheet" href="WEH.css" media="screen">
<style>
#ipManagement { width:94%; max-width:1520px; margin:18px auto 30px; color:#fff; --ipv-border:#444; --ipv-muted:#bdbdbd; --ipv-dirty:#11a50d; --ipv-accent:<?= ($_SESSION['turm'] ?? '') === 'tvk' ? '#FFA500' : '#11a50d' ?>; }
#ipManagement * { box-sizing:border-box; }
#ipManagement .ipv-panel { border:1px solid #666; border-radius:10px; padding:18px 22px; background:#1c1b1b; }
#ipManagement .ipv-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:15px; }
#ipManagement h1 { font-size:29px; margin:0 0 5px; }
#ipManagement h2 { font-size:20px; margin:0 0 8px; }
#ipManagement p { margin:5px 0; line-height:1.45; }
#ipManagement .ipv-muted { color:var(--ipv-muted); font-size:15px; }
#ipManagement .ipv-save-area { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
#ipManagement .ipv-btn { border:2px solid #000; border-radius:4px; color:#000; background:#fff; font:16px Arial,sans-serif; padding:9px 16px; cursor:pointer; white-space:nowrap; }
#ipManagement .ipv-btn:hover { background:var(--ipv-accent); }
#ipManagement .ipv-save { font-size:20px; font-weight:600; min-width:155px; background:var(--ipv-accent); }
#ipManagement .ipv-save:hover { filter:brightness(1.14); }
#ipManagement .ipv-save:disabled { opacity:.5; cursor:default; background:#ddd; filter:none; }
#ipManagement .ipv-save.ipv-pending { background:var(--ipv-dirty); }
#ipManagement .ipv-status { font-size:15px; color:var(--ipv-muted); }
#ipManagement .ipv-status.ipv-pending { color:var(--ipv-dirty); font-weight:bold; }
#ipManagement .ipv-table-wrap { overflow-x:auto; }
#ipManagement .ipv-table { width:100%; border-collapse:separate; border-spacing:0; table-layout:fixed; }
#ipManagement .ipv-table th { background:#292828; color:#fff; text-align:left; font-size:16px; padding:11px 12px; border-bottom:1px solid #555; }
#ipManagement .ipv-table th:first-child { border-radius:6px 0 0 0; }
#ipManagement .ipv-table th:last-child { border-radius:0 6px 0 0; }
#ipManagement .ipv-table td { padding:11px 12px; vertical-align:middle; border-bottom:1px solid #393939; }
#ipManagement .ipv-table tbody tr:hover { background:#242323; }
#ipManagement .ipv-table tbody tr.ipv-row-dirty { background:#20311e; }
#ipManagement .ipv-table tbody tr.ipv-sublet { background:#421e1b; }
#ipManagement .ipv-ip { display:block; font:16px/36px monospace; white-space:nowrap; color:#f0f0f0; }
#ipManagement .ipv-input { width:100%; min-width:0; border:1px solid #5d5d5d; border-radius:4px; background:#252424; color:#fff; font:16px/1.2 Arial,sans-serif; padding:9px 10px; outline:none; }
#ipManagement .ipv-input::placeholder { color:#898989; opacity:1; }
#ipManagement .ipv-input:focus { border-color:var(--ipv-accent); box-shadow:0 0 0 1px var(--ipv-accent); }
#ipManagement .ipv-input.ipv-dirty { border-color:var(--ipv-dirty); background:#253b23; box-shadow:0 0 0 1px var(--ipv-dirty); }
#ipManagement .ipv-description { background:#30302f; border-color:#777; font-size:17px; font-weight:600; }
#ipManagement .ipv-mac { font-family:monospace; letter-spacing:.1px; }
#ipManagement .ipv-extra { min-width:0; }
#ipManagement .ipv-extra summary { color:#bcbcbc; font:14px/36px Arial,sans-serif; cursor:pointer; white-space:nowrap; }
#ipManagement .ipv-extra[open] summary { line-height:23px; margin-bottom:5px; }
#ipManagement .ipv-extra.ipv-has-extra summary { color:#eee; }
#ipManagement .ipv-extra.ipv-extra-dirty summary { color:var(--ipv-dirty); }
#ipManagement .ipv-extra-fields { display:grid; gap:6px; }
#ipManagement .ipv-extra-field { display:grid; grid-template-columns:46px minmax(0,1fr); align-items:center; gap:6px; color:var(--ipv-muted); font:12px Arial,sans-serif; }
#ipManagement .ipv-extra-field .ipv-input { font-size:14px; padding:7px; background:#202020; }
#ipManagement .ipv-extra-field .ipv-input.ipv-dirty { background:#253b23; }
#ipManagement .ipv-delete { background:none; padding:7px; border:0; cursor:pointer; border-radius:4px; }
#ipManagement .ipv-delete:hover { background:#632b25; }
#ipManagement .ipv-delete img { width:22px; height:22px; display:block; }
#ipManagement .ipv-note { margin-top:16px; display:grid; grid-template-columns:1fr 1fr; gap:14px; }
#ipManagement .ipv-note .ipv-panel { padding:14px 18px; }
#ipManagement .ipv-address { color:var(--ipv-accent); font-weight:bold; white-space:nowrap; }
#ipManagement .ipv-alert { padding:12px 16px; border:1px solid; border-radius:6px; margin:0 0 14px; line-height:1.4; }
#ipManagement .ipv-alert-ok { color:#baf4ae; background:#20331d; border-color:#46743c; }
#ipManagement .ipv-alert-error { color:#ffb9b4; background:#402522; border-color:#a34d43; }
#ipManagement .ipv-mobile-label { display:none; }
#ipManagement .ipv-sublet-label { display:block; font-size:12px; color:#ffb3ad; }
.ipv-admin-add { display:flex; justify-content:center; flex-wrap:wrap; gap:25px; margin:15px 0 0; }
.ipv-admin-add form { display:flex; flex-direction:column; gap:7px; }
.ipv-admin-add select { font-size:19px; text-align:center; padding:5px; }
@media (max-width:900px) {
    #ipManagement { width:95%; }
    #ipManagement .ipv-panel { padding:14px; }
    #ipManagement .ipv-table, #ipManagement .ipv-table tbody { display:block; }
    #ipManagement .ipv-table colgroup, #ipManagement .ipv-table thead { display:none; }
    #ipManagement .ipv-table tr { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:10px; padding:12px; border:1px solid #555; border-radius:7px; margin-bottom:10px; }
    #ipManagement .ipv-table td { display:block; padding:0; border:0; min-width:0; }
    #ipManagement .ipv-mobile-label { display:block; font-size:13px; color:var(--ipv-muted); margin-bottom:5px; }
    #ipManagement .ipv-desc-cell, #ipManagement .ipv-extra-cell { grid-column:1/-1; }
    #ipManagement .ipv-delete-cell { grid-column:1/-1; text-align:right; }
    #ipManagement .ipv-note { grid-template-columns:1fr; }
}
@media (max-width:430px) {
    #ipManagement .ipv-table tr { grid-template-columns:minmax(0,1fr); }
    #ipManagement .ipv-save-area { width:100%; }
    #ipManagement .ipv-save { flex:1; }
}
</style>
</head>
<body>
<?php load_menu(); ?>
<?php if ($isAdmin): ?>
<div style="margin:0 auto 16px;text-align:center;">
    <div style="border:2px solid white;border-radius:10px;display:inline-block;padding:20px;text-align:center;background-color:transparent;max-width:95%;">
        <form method="post" action="IPverwaltung.php" style="margin:0;">
            <?php ipv_hidden($uid, $ownUid, $csrf); ?>
            <input type="hidden" name="toggleAdminPanel" value="1">
            <span class="white-text" role="button" tabindex="0" aria-expanded="<?= ($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block' ? 'true' : 'false' ?>" style="font-size:35px;cursor:pointer;display:inline-block;" onclick="this.closest('form').requestSubmit()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.closest('form').requestSubmit();}">Admin Panel</span>
        </form>
        <?php if (($_SESSION['AdminPanelToggleState'] ?? 'none') === 'block'): ?>
        <div id="adminPanel">
            <?php renderUserPostButtons($conn, $uid); ?>
            <form method="get" action="IPverwaltung.php" id="ipvUidForm">
                <input type="number" min="1" name="uid" id="ipvUid" placeholder="UID" value="<?= $uid ?>" style="margin-top:20px;font-size:20px;text-align:center;max-width:100%;" onchange="this.form.requestSubmit()">
            </form>
            <form method="get" action="IPverwaltung.php">
                <select name="uid" style="margin-top:20px;font-size:20px;text-align:center;max-width:100%;" onchange="this.form.requestSubmit()">
                    <?php
                    $labels = [12 => 'Subletter', 13 => 'Ausgezogen', 14 => 'Abgemeldet', 64 => 'Dummy'];
                    $label = (int)$user['pid'] === 11 ? ($user['turm'] === 'tvk' ? 'TvK' : strtoupper((string)$user['turm'])) . ' ' . $user['room'] : ($labels[$user['pid']] ?? 'Undefined');
                    echo '<option value="' . $uid . '" selected>' . ipv_h($user['name'] . ' [' . $label . ']') . '</option>';
                    $stmt = ipv_query($conn, "SELECT uid, name, room, turm FROM users WHERE pid=11 AND uid<>? ORDER BY FIELD(turm,'weh','tvk'),room", 'i', [$uid]);
                    $stmt->bind_result($idOption, $name, $room, $turm);
                    while ($stmt->fetch()) echo '<option value="' . (int)$idOption . '">' . ipv_h($name . ' [' . ($turm === 'tvk' ? 'TvK' : strtoupper((string)$turm)) . ' ' . $room . ']') . '</option>';
                    $stmt->close();
                    ?>
                </select>
            </form>
            <input type="search" id="ipvSearch" placeholder="Suche nach User..." autocomplete="off" style="display:block;margin:20px auto;height:30px;font-size:25px;max-width:100%;">
            <div style="margin:0 auto;overflow-x:auto;"><table class="center-table" style="margin:0 auto;"><tbody id="ipvSearchResults"></tbody></table></div>
            <div class="ipv-admin-add">
                <form method="post" action="IPverwaltung.php">
                    <?php ipv_hidden($uid, $ownUid, $csrf); ?>
                    <select name="selected_ip_priv" aria-label="Neue private IP" <?= $freePrivate ? '' : 'disabled' ?>>
                        <?php if (!$freePrivate): ?><option>Keine freie private IP</option><?php endif; ?>
                        <?php foreach ($freePrivate as $ip): ?><option value="<?= ipv_h($ip) ?>"><?= ipv_h($ip) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" name="addprivateip" value="1" class="center-btn" <?= $freePrivate ? '' : 'disabled' ?>>Neue Private IP</button>
                </form>
                <form method="post" action="IPverwaltung.php">
                    <?php ipv_hidden($uid, $ownUid, $csrf); ?>
                    <select name="selected_ip" aria-label="Neue öffentliche IP" <?= $freePublic ? '' : 'disabled' ?>>
                        <?php if (!$freePublic): ?><option>Keine freie öffentliche IP</option><?php endif; ?>
                        <?php foreach ($freePublic as $ip): ?><option value="<?= ipv_h($ip) ?>"><?= ipv_h($ip) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" name="addpublicip" value="1" class="center-btn" <?= $freePublic ? '' : 'disabled' ?>>Neue Public IP</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<main id="ipManagement">
    <?php if ($error !== ''): ?><div class="ipv-alert ipv-alert-error" role="alert"><?= ipv_h($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="ipv-alert ipv-alert-ok" role="status"><?= ipv_h($message) ?></div><?php endif; ?>
    <section class="ipv-panel">
        <form action="IPverwaltung.php" method="post" id="ipvEditForm" name="formularipverwaltung">
            <?php ipv_hidden($uid, $ownUid, $csrf); ?>
            <div class="ipv-top">
                <div>
                    <h1>IP Management<?php if ($uid !== $ownUid): ?> · <?= ipv_h($user['name']) ?><?php endif; ?></h1>
                    <p class="ipv-muted">Register your devices and give them a description to find them easily.</p>
                </div>
                <div class="ipv-save-area">
                    <span id="ipvSaveStatus" class="ipv-status" aria-live="polite">No unsaved changes</span>
                    <button type="submit" name="save" value="1" id="ipvSave" class="ipv-btn ipv-save" <?= $rows ? '' : 'disabled' ?>>Save changes</button>
                </div>
            </div>
            <div class="ipv-table-wrap">
                <table class="ipv-table">
                    <colgroup><col style="width:18%"><col style="width:31%"><col style="width:25%"><col<?= $isAdmin ? ' style="width:22%"' : '' ?>><?php if ($isAdmin): ?><col style="width:4%"><?php endif; ?></colgroup>
                    <thead><tr><th scope="col">IP address</th><th scope="col">Description</th><th scope="col">MAC 1</th><th scope="col">Additional MACs</th><?php if ($isAdmin): ?><th scope="col" aria-label="Remove"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $id => $row):
                        $extra = ($draft[$id]['mac2'] ?? $row['mac2']) !== '' || ($draft[$id]['mac3'] ?? $row['mac3']) !== '' || $row['mac2'] !== '' || $row['mac3'] !== '';
                    ?>
                    <tr<?= $canSeeSublet && $row['sublet'] === 1 ? ' class="ipv-sublet"' : '' ?>>
                        <td class="ipv-ip-cell">
                            <span class="ipv-mobile-label">IP address</span>
                            <input type="hidden" name="rows[<?= $id ?>][version]" value="<?= ipv_version($row) ?>">
                            <input type="hidden" name="rows[<?= $id ?>][proof]" value="<?= ipv_row_proof($ownUid, $uid, $id, ipv_version($row)) ?>">
                            <?php if ($isAdmin): ipv_field($id, 'ip', $row, $draft, 'IP address', 'ipv-mac', 15); else: ?><span class="ipv-ip"><?= ipv_h($row['ip']) ?></span><?php endif; ?>
                            <?php if ($canSeeSublet && $row['sublet'] === 1): ?><span class="ipv-sublet-label">Sublet</span><?php endif; ?>
                        </td>
                        <td class="ipv-desc-cell"><span class="ipv-mobile-label">Description</span><?php ipv_field($id, 'hostname', $row, $draft, 'Description', 'ipv-description'); ?></td>
                        <td><span class="ipv-mobile-label">MAC 1</span><?php ipv_field($id, 'mac1', $row, $draft, 'MAC 1', 'ipv-mac', 32); ?></td>
                        <td class="ipv-extra-cell">
                            <details class="ipv-extra<?= $extra ? ' ipv-has-extra' : '' ?>"<?= $extra ? ' open' : '' ?>>
                                <summary>Additional MACs</summary>
                                <div class="ipv-extra-fields">
                                    <label class="ipv-extra-field"><span>MAC 2</span><?php ipv_field($id, 'mac2', $row, $draft, 'MAC 2', 'ipv-mac', 32); ?></label>
                                    <label class="ipv-extra-field"><span>MAC 3</span><?php ipv_field($id, 'mac3', $row, $draft, 'MAC 3', 'ipv-mac', 32); ?></label>
                                </div>
                            </details>
                        </td>
                        <?php if ($isAdmin): ?><td class="ipv-delete-cell"><button type="submit" name="remove" value="<?= $id ?>" class="ipv-delete" formnovalidate title="Remove IP address" aria-label="Remove <?= ipv_h($row['ip']) ?>"><img src="images/trash_white.png" class="animated-trash-icon" alt=""></button></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="<?= $isAdmin ? 5 : 4 ?>" class="ipv-muted">No IP addresses assigned.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </section>
    <div class="ipv-note">
        <section class="ipv-panel">
            <h2>Registering your devices</h2>
            <p>Only one MAC address per IP can be used at a time. Use MAC 2 and MAC 3 for alternative devices or network adapters sharing the same IP.</p>
            <p class="ipv-muted">Allow a few minutes for DHCP changes to take effect. Reconnect your device if necessary.</p>
        </section>
        <section class="ipv-panel">
            <h2>Your IP addresses</h2>
            <?php if (!empty($user['subnet']) && !empty($user['public_ip'])): ?><p>Local subnet <span class="ipv-address"><?= ipv_h($user['subnet']) ?></span> · Public NAT IP <span class="ipv-address"><?= ipv_h($user['public_ip']) ?></span></p><?php endif; ?>
            <p class="ipv-muted">Every user starts with 5 private IPs and 1 assigned public IP. If you need more IPs, please visit the Netzwerk-AG consultation hour.</p>
        </section>
    </div>
</main>
<script>
(() => {
    const form = document.getElementById('ipvEditForm');
    const save = document.getElementById('ipvSave');
    const status = document.getElementById('ipvSaveStatus');
    const fields = Array.from(form.querySelectorAll('[data-track]'));
    let dirty = false;
    let submitting = false;
    function refresh() {
        let count = 0;
        for (const field of fields) {
            const changed = field.value !== field.dataset.original;
            field.classList.toggle('ipv-dirty', changed);
            if (changed) count++;
        }
        for (const row of form.querySelectorAll('tbody tr')) {
            row.classList.toggle('ipv-row-dirty', !!row.querySelector('.ipv-dirty'));
        }
        for (const extra of form.querySelectorAll('.ipv-extra')) {
            const populated = Array.from(extra.querySelectorAll('[data-track]')).some(el => el.value.trim() !== '');
            extra.classList.toggle('ipv-has-extra', populated);
            extra.classList.toggle('ipv-extra-dirty', !!extra.querySelector('.ipv-dirty'));
        }
        dirty = count > 0;
        save.disabled = !dirty;
        save.classList.toggle('ipv-pending', dirty);
        status.classList.toggle('ipv-pending', dirty);
        status.textContent = dirty ? count + (count === 1 ? ' unsaved change' : ' unsaved changes') : 'No unsaved changes';
    }
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target.matches('[data-track]')) {
            event.preventDefault();
            if (!save.disabled) form.requestSubmit(save);
        }
    });
    form.addEventListener('submit', event => {
        if (event.submitter && event.submitter.name === 'remove') {
            if (!confirm('Remove this IP address?' + (dirty ? '\nUnsaved changes will be discarded.' : ''))) { event.preventDefault(); return; }
        }
        submitting = true;
    });
    window.addEventListener('beforeunload', event => {
        if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', () => { submitting = false; refresh(); });
    refresh();
    const search = document.getElementById('ipvSearch');
    if (!search) return;
    const results = document.getElementById('ipvSearchResults');
    let controller, timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        if (controller) controller.abort();
        const value = search.value.trim();
        if (!value) { results.replaceChildren(); return; }
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch('IPverwaltung.php?search=' + encodeURIComponent(value), { signal: controller.signal, cache: 'no-store' });
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
                    link.href = 'IPverwaltung.php?uid=' + encodeURIComponent(user.uid);
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
