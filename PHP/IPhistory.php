<?php
session_start();
require('conn.php');
mysqli_set_charset($conn, "utf8mb4");

/**
 * AJAX 1: Nutzersuche
 * GET ?search_user=...
 * Rückgabe: Map uid => [ { uid, name, username, room, oldroom, turm } ]
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_user'])) {
    header('Content-Type: application/json; charset=utf-8');
    $term = trim($_GET['search_user'] ?? '');
    if ($term === '') { echo json_encode([]); exit; }

    $like = "%{$term}%";
    $searchedusers = [];

    if (ctype_digit($term)) {
        $sql = "
            SELECT u.uid, CONCAT(u.firstname,' ',u.lastname) AS name, u.username, u.room, u.oldroom, u.turm
            FROM users u
            WHERE (u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.aliase LIKE ? OR u.geburtsort LIKE ?)
               OR (u.room = ? OR u.oldroom = ? OR u.uid = ?)
            LIMIT 200
        ";
        $stmt = $conn->prepare($sql);
        $asInt = (int)$term;
        $stmt->bind_param('ssssssii', $like, $like, $like, $like, $like, $term, $term, $asInt);
    } else {
        $sql = "
            SELECT u.uid, CONCAT(u.firstname,' ',u.lastname) AS name, u.username, u.room, u.oldroom, u.turm
            FROM users u
            WHERE (u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.aliase LIKE ? OR u.geburtsort LIKE ?)
            LIMIT 200
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $uid = (int)$row['uid'];
        $searchedusers[$uid][] = [
            "uid"      => $uid,
            "name"     => $row['name'],
            "username" => $row['username'],
            "room"     => (int)$row['room'],
            "oldroom"  => (int)$row['oldroom'],
            "turm"     => $row['turm'],
        ];
    }
    echo json_encode($searchedusers);
    exit;
}

/**
 * AJAX 2: IP-Suche
 * GET ?search_ip=...
 * Rückgabe: Liste von IPs (Array mit Strings), substring-fähig (z.B. ".147")
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_ip'])) {
    header('Content-Type: application/json; charset=utf-8');
    $term = trim($_GET['search_ip'] ?? '');
    if ($term === '') { echo json_encode([]); exit; }

    $like = "%{$term}%";
    $sql = "SELECT DISTINCT ip FROM iphistory WHERE ip LIKE ? ORDER BY INET_ATON(ip) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $ips = [];
    while ($row = $res->fetch_assoc()) {
        $ip = trim($row['ip']);
        if ($ip !== '') $ips[] = $ip;
    }
    echo json_encode($ips);
    exit;
}

/* template.php NACH den AJAX-Blocks laden */
require('template.php');

if (!(auth($conn) && $_SESSION['NetzAG'])) {
    header("Location: denied.php");
    exit;
}

load_menu();

/** Datenaufbereitung für Detailansicht (POST uid / POST ip) */
$resultData = [];

if (!empty($_POST['uid'])) {
    $uid = (int)$_POST['uid'];
    $sql = "
        SELECT i.ip, i.starttime, i.endtime, u.firstname, u.lastname, u.room, u.oldroom
        FROM iphistory i
        JOIN users u ON i.uid = u.uid
        WHERE i.uid = ?
        ORDER BY INET_ATON(i.ip) ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['starttime'] ? date("d.m.Y", (int)$row['starttime']) : "-";
        $end   = (empty($row['endtime']) || (int)$row['endtime'] === 0) ? "offen" : date("d.m.Y", (int)$row['endtime']);
        $name  = trim(explode(' ', $row['firstname'])[0] . " " . explode(' ', $row['lastname'])[0]);
        $room  = ((int)$row['room'] !== 0) ? (int)$row['room'] : (((int)$row['oldroom'] !== 0) ? (int)$row['oldroom'] : "-");

        $resultData[] = [
            'uid'   => $uid,
            'ip'    => $row['ip'],
            'start' => $start,
            'end'   => $end,
            'name'  => $name,
            'room'  => $room
        ];
    }
}

if (!empty($_POST['ip'])) {
    $ip = $_POST['ip'];
    $sql = "
        SELECT i.uid, i.starttime, i.endtime, u.firstname, u.lastname, u.room, u.oldroom
        FROM iphistory i
        JOIN users u ON i.uid = u.uid
        WHERE i.ip = ?
        ORDER BY INET_ATON(i.ip) ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['starttime'] ? date("d.m.Y", (int)$row['starttime']) : "-";
        $end   = (empty($row['endtime']) || (int)$row['endtime'] === 0) ? "offen" : date("d.m.Y", (int)$row['endtime']);
        $name  = trim(explode(' ', $row['firstname'])[0] . " " . explode(' ', $row['lastname'])[0]);
        $room  = ((int)$row['room'] !== 0) ? (int)$row['room'] : (((int)$row['oldroom'] !== 0) ? (int)$row['oldroom'] : "-");

        $resultData[] = [
            'uid'   => (int)$row['uid'],
            'ip'    => $ip,
            'start' => $start,
            'end'   => $end,
            'name'  => $name,
            'room'  => $room
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>IP-History</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        :root { --primary: #11a50d; --text: #e8e8e8; --muted: #a3a3a3; --card: #1c1c1c; --card-2: #141414; --line: #2a2a2a; }
        .wrap          { max-width: 1300px; margin: 24px auto; padding: 0 16px; }
        .grid-2        { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 900px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
        .panel         { background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
        .panel-inner   { padding: 12px 14px; }

        .input         { width: 100%; background: var(--card-2); border: 1px solid var(--line);
                         color: var(--text); border-radius: 10px; padding: 10px 12px; outline: none; }
        .input:focus   { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(17,165,13,.2); }

        .table-wrap    { overflow: auto; max-height: 360px; border-radius: 10px; border: 1px solid var(--line); }
        .table         { width: 100%; border-collapse: collapse; font-size: 14.5px; color: var(--text); }
        .table th, .table td { padding: 10px 12px; border-bottom: 1px solid var(--line); white-space: nowrap; text-align: left; }
        .table thead th { background: #0f0f0f; position: sticky; top: 0; z-index: 1; }
        .table tr:hover { background: #111; }
        .table tr.clickable { cursor: pointer; }
        .link          { color: var(--text); text-decoration: none; border-bottom: 1px dotted var(--line); }
        .link:hover    { color: var(--primary); border-bottom-color: var(--primary); }

        .sort-asc::after  { content: " ▲"; }
        .sort-desc::after { content: " ▼"; }

        .badge        { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px;
                        border: 1px solid var(--line); background: var(--card-2); color: var(--muted); }
        .badge.ok     { color: var(--primary); border-color: rgba(17,165,13,.35); }

        .ghost        { color: var(--muted); }
        .stack8 > * + * { margin-top: 8px; }
        .mt8 { margin-top: 8px; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="grid-2">
        <!-- User-Suche -->
        <div class="panel">
            <div class="panel-inner stack8">
                <input id="userSearch" class="input" type="text"
                       placeholder="Nach User suchen" autocomplete="off" />
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>UID</th>
                                <th>Username</th>
                                <th>Name</th>
                                <th>Haus</th>
                                <th>Raum</th>
                            </tr>
                        </thead>
                        <tbody id="userResults"><!-- JS füllt --></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- IP-Suche -->
        <div class="panel">
            <div class="panel-inner stack8">
                <input id="ipSearch" class="input" type="text"
                       placeholder="Nach IP suchen" autocomplete="off" />
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="ipResults"><!-- JS füllt --></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Ergebnisse unten, volle Breite -->
    <div class="panel mt8">
        <div class="panel-inner">
            <?php if (!empty($resultData)): ?>
                <div class="table-wrap">
                    <table class="table" id="dataTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)">Name</th>
                                <th onclick="sortTable(1)">Raum</th>
                                <th onclick="sortTable(2)">IP</th>
                                <th onclick="sortTable(3)">Start</th>
                                <th onclick="sortTable(4)">Ende</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($resultData as $row):
                            $uid   = (int)$row['uid'];
                            $name  = htmlspecialchars($row['name']);
                            $room  = htmlspecialchars((string)$row['room']);
                            $ip    = htmlspecialchars($row['ip']);
                            $start = htmlspecialchars($row['start']);
                            $end   = htmlspecialchars($row['end']);
                            $startSort = strtotime(str_replace('.', '-', $row['start'])) ?: 0;
                            $endSort   = ($row['end'] !== "offen") ? (strtotime(str_replace('.', '-', $row['end'])) ?: 0) : PHP_INT_MAX;
                            $active    = ($row['end'] === "offen");
                        ?>
                            <tr class="clickable" onclick="goToUser(<?= $uid ?>)">
                                <td data-sort="<?= $name ?>"><?= $name ?></td>
                                <td data-sort="<?= $room ?>"><?= $room ?></td>
                                <td data-sort="<?= $ip ?>"><?= $ip ?></td>
                                <td data-sort="<?= $startSort ?>"><?= $start ?></td>
                                <td data-sort="<?= $endSort ?>"><?= $end ?></td>
                                <td>
                                    <?php if ($active): ?>
                                        <span class="badge ok">aktiv</span>
                                    <?php else: ?>
                                        <span class="badge">beendet</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="color:var(--muted);">Keine Daten zum Anzeigen.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
/* ===== Sortier-Logik ===== */
let sortDirection = {};
function sortTable(colIndex) {
    const table = document.getElementById("dataTable");
    if (!table) return;
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const ths = table.tHead.rows[0].cells;
    for (let i = 0; i < ths.length; i++) ths[i].classList.remove("sort-asc", "sort-desc");

    const dir = sortDirection[colIndex] === "asc" ? "desc" : "asc";
    sortDirection[colIndex] = dir;
    ths[colIndex].classList.add("sort-" + dir);

    rows.sort((a, b) => {
        const aCell = a.cells[colIndex];
        const bCell = b.cells[colIndex];
        const aSort = aCell.getAttribute("data-sort") || aCell.textContent.trim().toLowerCase();
        const bSort = bCell.getAttribute("data-sort") || bCell.textContent.trim().toLowerCase();
        const aNum = Number(aSort), bNum = Number(bSort);
        const bothNumeric = !Number.isNaN(aNum) && !Number.isNaN(bNum);

        if (bothNumeric) return dir === "asc" ? aNum - bNum : bNum - aNum;
        return dir === "asc"
            ? aSort.localeCompare(bSort, 'de', { numeric: true, sensitivity: 'base' })
            : bSort.localeCompare(aSort, 'de', { numeric: true, sensitivity: 'base' });
    });
    rows.forEach(r => tbody.appendChild(r));
}

/* ===== Navigation zu User.php (per POST) ===== */
function goToUser(uid) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'User.php';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'id';
    input.value = uid;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

/* ===== AJAX: Nutzersuche ===== */
let userTimer = null;
const userSearch = document.getElementById('userSearch');
const userResults = document.getElementById('userResults');

function renderUserRow(u) {
    const tr = document.createElement('tr');
    tr.className = 'clickable';
    tr.onclick = () => submitUID(u.uid);

    const tdUid  = document.createElement('td');   tdUid.textContent = u.uid;
    const tdUser = document.createElement('td');   tdUser.textContent = u.username;
    const tdName = document.createElement('td');   tdName.textContent = u.name;
    const tdTurm = document.createElement('td');   tdTurm.textContent = (u.turm || '').toUpperCase();
    const tdRoom = document.createElement('td');   tdRoom.textContent = (Number(u.room) === 0 ? Number(u.oldroom) : Number(u.room)) || '-';

    if (Number(u.room) === 0) { tdTurm.classList.add('ghost'); tdRoom.classList.add('ghost'); }

    tr.appendChild(tdUid);
    tr.appendChild(tdUser);
    tr.appendChild(tdName);
    tr.appendChild(tdTurm);
    tr.appendChild(tdRoom);
    return tr;
}

function submitUID(uid) {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '';
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'uid'; hidden.value = uid;
    form.appendChild(hidden);
    document.body.appendChild(form);
    form.submit();
}

async function doUserSearch(q) {
    const url = new URL(window.location.href);
    url.searchParams.set('search_user', q);
    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return;
    const data = await res.json();
    userResults.innerHTML = '';
    Object.keys(data).forEach(uid => {
        data[uid].forEach(u => userResults.appendChild(renderUserRow(u)));
    });
}

if (userSearch) {
    userSearch.addEventListener('input', (e) => {
        const q = e.target.value.trim();
        clearTimeout(userTimer);
        userTimer = setTimeout(() => {
            if (q === '') userResults.innerHTML = '';
            else doUserSearch(q).catch(() => {});
        }, 180);
    });
}

/* ===== AJAX: IP-Suche ===== */
let ipTimer = null;
const ipSearch = document.getElementById('ipSearch');
const ipResults = document.getElementById('ipResults');

function renderIpRow(ip) {
    const tr = document.createElement('tr');
    tr.className = 'clickable';
    tr.onclick = () => submitIP(ip);
    const td = document.createElement('td');
    td.textContent = ip;
    tr.appendChild(td);
    return tr;
}

function submitIP(ip) {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '';
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'ip'; hidden.value = ip;
    form.appendChild(hidden);
    document.body.appendChild(form);
    form.submit();
}

async function doIpSearch(q) {
    const url = new URL(window.location.href);
    url.searchParams.set('search_ip', q);
    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return;
    const list = await res.json();
    ipResults.innerHTML = '';
    list.forEach(ip => ipResults.appendChild(renderIpRow(ip)));
}

if (ipSearch) {
    ipSearch.addEventListener('input', (e) => {
        const q = e.target.value.trim();
        clearTimeout(ipTimer);
        ipTimer = setTimeout(() => {
            if (q === '') ipResults.innerHTML = '';
            else doIpSearch(q).catch(() => {});
        }, 180);
    });
}
</script>
</body>
</html>
<?php $conn->close(); ?>
