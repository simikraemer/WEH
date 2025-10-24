<?php
session_start();
require('conn.php');
mysqli_set_charset($conn, "utf8mb4");

/**
 * JSON-Suche (AJAX): /dieses-script.php?search=...
 * Gibt eine Map uid => [ { uid, name, username, room, oldroom, turm } ] zurück
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $term = trim($_GET['search'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if ($term === '') {
        echo json_encode([]);
        exit;
    }

    // prepared LIKEs
    $like = "%{$term}%";
    $searchedusers = [];

    if (ctype_digit($term)) {
        // numeric: auch exakte Vergleiche auf room/oldroom/uid zulassen
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

/** IP-Liste für Dropdown */
$ips = [];
$resIps = $conn->query("SELECT DISTINCT ip FROM iphistory ORDER BY INET_ATON(ip) ASC");
if ($resIps && $resIps->num_rows) {
    while ($r = $resIps->fetch_assoc()) {
        $ips[] = $r['ip'];
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
        /* kein body styling hier! (kommt aus WEH.css) */

        .wrap            { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
        .stack           { display: grid; gap: 16px; }
        .panel           { background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
        .panel-inner     { padding: 16px 18px; }
        .toolbar         { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .pill            { background: var(--card-2); border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; color: var(--text); }
        .input           { background: var(--card-2); border: 1px solid var(--line); color: var(--text); border-radius: 10px; padding: 10px 12px; outline: none; min-width: 260px; }
        .input:focus     { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(17,165,13,.2); }
        .select          { background: var(--card-2); border: 1px solid var(--line); color: var(--text); border-radius: 10px; padding: 10px 12px; outline: none; }
        .btn             { background: var(--primary); color: #071307; border: none; border-radius: 10px; padding: 10px 14px; font-weight: 600; cursor: pointer; }
        .btn:disabled    { opacity: .6; cursor: not-allowed; }

        .table-wrap      { overflow-x: auto; }
        .table           { width: 100%; border-collapse: collapse; font-size: 15px; color: var(--text); }
        .table th, .table td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        .table thead th  { background: #0f0f0f; position: sticky; top: 0; z-index: 1; cursor: pointer; }
        .table tr:hover  { background: #111; }
        .badge           { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; border: 1px solid var(--line); background: var(--card-2); color: var(--muted); }
        .badge.ok        { color: var(--primary); border-color: rgba(17,165,13,.35); }
        .ghost           { color: var(--muted); }

        .grid            { display: grid; gap: 12px; grid-template-columns: 1fr; }
        @media (min-width: 720px) {
            .grid { grid-template-columns: 1fr 1fr; }
        }

        .note            { color: var(--muted); font-size: 13px; }
        .spacer          { height: 4px; }

        .sort-asc::after  { content: " ▲"; }
        .sort-desc::after { content: " ▼"; }

        .clickable       { cursor: pointer; }
        .link            { color: var(--text); text-decoration: none; border-bottom: 1px dotted var(--line); }
        .link:hover      { color: var(--primary); border-bottom-color: var(--primary); }
    </style>
</head>
<body>
<div class="wrap stack">

    <!-- Suche & Auswahl -->
    <div class="panel">
        <div class="panel-inner stack">
            <div class="toolbar">
                <div class="pill">IP-History</div>
                <div class="note">Suche Nutzer oder wähle eine IP aus.</div>
            </div>

            <div class="grid">
                <div class="stack">
                    <input id="searchInput" class="input" type="text" placeholder="Name, Nutzername, E-Mail, Raum, UID ..." autocomplete="off" />
                    <div class="table-wrap panel" style="max-height: 320px;">
                        <div class="panel-inner" style="padding:0;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="cursor:default;">UID</th>
                                        <th style="cursor:default;">Username</th>
                                        <th style="cursor:default;">Name</th>
                                        <th style="cursor:default;">Haus</th>
                                        <th style="cursor:default;">Raum</th>
                                    </tr>
                                </thead>
                                <tbody id="userResults">
                                    <!-- JS füllt hier -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="stack">
                    <form method="POST" id="ipForm" class="stack">
                        <select name="ip" id="ipSelect" class="select" onchange="document.getElementById('ipForm').submit();">
                            <option value="">~ IP auswählen ~</option>
                            <?php foreach ($ips as $ip): ?>
                                <option value="<?= htmlspecialchars($ip) ?>"><?= htmlspecialchars($ip) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <div class="spacer"></div>

                    <!-- <form method="POST" id="uidForm" class="stack">
                        <div class="note">…oder UID direkt öffnen</div>
                        <div class="toolbar">
                            <input class="input" type="number" name="uid" placeholder="UID" min="1">
                            <button class="btn" type="submit">Öffnen</button>
                        </div>
                    </form> -->
                </div>
            </div>

        </div>
    </div>

    <!-- Ergebnis-Tabelle -->
    <div class="panel">
        <div class="panel-inner stack">
            <div class="toolbar">
                <div class="pill">Ergebnisse</div>
                <?php if (empty($resultData)): ?>
                    <div class="note">Keine Daten zum Anzeigen.</div>
                <?php else: ?>
                    <div class="note"><?= count($resultData) ?> Einträge</div>
                <?php endif; ?>
            </div>

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
                                <th style="cursor:default;">Status</th>
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
                                <td><?php if ($active): ?>
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
            <?php endif; ?>

        </div>
    </div>

</div>

<script>
/** Sortier-Logik für Ergebnistabelle */
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

/** POST-Redirect zu User.php mit UID */
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

/** Live-Suche (AJAX) */
let searchTimer = null;
const searchInput = document.getElementById('searchInput');
const userResults = document.getElementById('userResults');

function renderUserRow(u) {
    const tr = document.createElement('tr');

    const tdUid  = document.createElement('td');   tdUid.textContent = u.uid;
    const tdUser = document.createElement('td');   tdUser.textContent = u.username;

    const tdName = document.createElement('td');
    const link = document.createElement('a');
    link.href = 'javascript:void(0);';
    link.className = 'link';
    link.textContent = u.name;
    link.onclick = () => submitUID(u.uid);
    tdName.appendChild(link);

    const tdTurm = document.createElement('td');
    const turmText = (u.turm || '').toUpperCase();
    tdTurm.textContent = turmText;

    const tdRoom = document.createElement('td');
    const room = (Number(u.room) === 0 ? Number(u.oldroom) : Number(u.room)) || '-';
    tdRoom.textContent = room;

    // Farbliche Akzente anhand Belegung
    if (Number(u.room) === 0) {
        tdTurm.classList.add('ghost');
        tdRoom.classList.add('ghost');
    }

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

async function doSearch(q) {
    const url = new URL(window.location.href);
    url.searchParams.set('search', q);
    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return;
    const data = await res.json();

    userResults.innerHTML = '';
    Object.keys(data).forEach(uid => {
        data[uid].forEach(u => {
            userResults.appendChild(renderUserRow(u));
        });
    });
}

if (searchInput) {
    searchInput.addEventListener('input', (e) => {
        const q = e.target.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (q === '') {
                userResults.innerHTML = '';
            } else {
                doSearch(q).catch(() => {});
            }
        }, 200);
    });
}
</script>
</body>
</html>
<?php $conn->close(); ?>
