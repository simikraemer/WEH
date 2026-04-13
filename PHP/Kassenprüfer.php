<?php
session_start();

require('conn.php');
mysqli_set_charset($conn, "utf8");

/*
 * template.php kann Output erzeugen.
 * Deshalb puffern wir es, damit AJAX trotzdem sauber funktioniert.
 */
ob_start();
require('template.php');
$templateOutput = ob_get_clean();

$agName = "Kassenprüfer";
$ag = 26;
$agStr = (string)$ag;

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function buildDisplayName($firstname, $lastname, $name, $username): string {
    $firstname = trim((string)$firstname);
    $lastname  = trim((string)$lastname);
    $name      = trim((string)$name);
    $username  = trim((string)$username);

    $full = trim($firstname . ' ' . $lastname);
    if ($full !== '') {
        return $full;
    }
    if ($name !== '') {
        return $name;
    }
    if ($username !== '') {
        return $username;
    }

    return 'Unbekannt';
}

function getRoomValue($pid, $room, $oldroom): string {
    $pid = (int)$pid;
    $room = (int)$room;
    $oldroom = (int)$oldroom;

    if ($pid === 12 || $pid === 13) {
        if ($oldroom > 0) {
            return (string)$oldroom;
        }
    }

    if ($room > 0) {
        return (string)$room;
    }

    if ($oldroom > 0) {
        return (string)$oldroom;
    }

    return '-';
}

function userHasGroup($groups, $groupId): bool {
    $groups = trim(str_replace(' ', '', (string)$groups), ',');
    if ($groups === '') {
        return false;
    }

    $parts = explode(',', $groups);
    foreach ($parts as $part) {
        if ((string)$part === (string)$groupId) {
            return true;
        }
    }

    return false;
}

function redirectToSelf(string $notice = '', string $search = ''): void {
    $target = $_SERVER['PHP_SELF'];
    $params = [];

    if ($notice !== '') {
        $params[] = 'notice=' . urlencode($notice);
    }
    if ($search !== '') {
        $params[] = 'search=' . urlencode($search);
    }

    if (!empty($params)) {
        $target .= '?' . implode('&', $params);
    }

    header('Location: ' . $target);
    exit;
}

function renderSearchResults(array $searchResults, int $ag, string $search): string {
    ob_start();

    if ($search === '') {
        return ob_get_clean();
    }

    if (empty($searchResults)) {
        echo '<div class="kp-empty">Keine passenden User gefunden.</div>';
        return ob_get_clean();
    }

    echo '<div class="kp-table-wrap">';
    echo '<table class="kp-table">';
    echo '<tr>';
    echo '<th>Name</th>';
    echo '<th>Raum</th>';
    echo '<th>Turm</th>';
    echo '<th></th>';
    echo '</tr>';

    foreach ($searchResults as $user) {
        $displayName = buildDisplayName($user['firstname'], $user['lastname'], $user['name'], $user['username']);
        $roomValue   = getRoomValue($user['pid'], $user['room'], $user['oldroom']);
        $turmLabel   = formatTurm((string)$user['turm']);
        $isMember    = userHasGroup($user['groups'], $ag);

        echo '<tr>';
        echo '<td>' . h($displayName) . '</td>';
        echo '<td>' . h($roomValue) . '</td>';
        echo '<td>' . h($turmLabel) . '</td>';
        echo '<td class="kp-action-cell">';

        if ($isMember) {
            echo '<button type="button" class="kp-btn kp-btn-disabled">Schon drin</button>';
        } else {
            echo '<form method="post" class="kp-action-form">';
            echo '<input type="hidden" name="uid" value="' . h($user['uid']) . '">';
            echo '<input type="hidden" name="search" value="' . h($search) . '">';
            echo '<button type="submit" name="process_add" value="1" class="kp-btn kp-btn-add">Hinzufügen</button>';
            echo '</form>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</div>';

    return ob_get_clean();
}

if (!auth($conn) || (empty($_SESSION["Kassenwart"]) && empty($_SESSION["Webmaster"]))) {
    header("Location: denied.php");
    exit;
}

/*
 * AJAX-Suche
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    $search = trim((string)($_GET['q'] ?? ''));
    $searchResults = [];

    if ($search !== '') {
        $like = '%' . $search . '%';

        $sqlSearch = "
            SELECT uid, username, firstname, lastname, name, room, oldroom, turm, pid, groups
            FROM users
            WHERE pid IN (11, 12, 13)
              AND (
                    username LIKE ?
                 OR firstname LIKE ?
                 OR lastname LIKE ?
                 OR name LIKE ?
                 OR email LIKE ?
                 OR telefon LIKE ?
                 OR aliase LIKE ?
                 OR LOWER(COALESCE(turm, '')) LIKE LOWER(?)
                 OR CAST(uid AS CHAR) LIKE ?
                 OR CAST(room AS CHAR) LIKE ?
                 OR CAST(oldroom AS CHAR) LIKE ?
              )
            ORDER BY
                CASE
                    WHEN CAST(room AS CHAR) = ? OR CAST(oldroom AS CHAR) = ? THEN 0
                    WHEN LOWER(COALESCE(lastname, '')) LIKE LOWER(?) OR LOWER(COALESCE(firstname, '')) LIKE LOWER(?) OR LOWER(COALESCE(name, '')) LIKE LOWER(?) THEN 1
                    ELSE 2
                END,
                CASE
                    WHEN pid = 11 THEN 0
                    WHEN pid = 12 THEN 1
                    WHEN pid = 13 THEN 2
                    ELSE 3
                END,
                LOWER(COALESCE(turm, '')) ASC,
                CASE
                    WHEN room IS NOT NULL AND room > 0 THEN room
                    WHEN oldroom IS NOT NULL AND oldroom > 0 THEN oldroom
                    ELSE 999999
                END ASC,
                LOWER(COALESCE(lastname, '')) ASC,
                LOWER(COALESCE(firstname, '')) ASC,
                LOWER(COALESCE(username, '')) ASC
            LIMIT 30
        ";

        $stmtSearch = mysqli_prepare($conn, $sqlSearch);
        if ($stmtSearch) {
            mysqli_stmt_bind_param(
                $stmtSearch,
                "ssssssssssssssss",
                $like,   // username
                $like,   // firstname
                $like,   // lastname
                $like,   // name
                $like,   // email
                $like,   // telefon
                $like,   // aliase
                $like,   // turm
                $like,   // uid
                $like,   // room
                $like,   // oldroom
                $search, // exact room
                $search, // exact oldroom
                $like,   // lastname
                $like,   // firstname
                $like    // name
            );

            mysqli_stmt_execute($stmtSearch);
            mysqli_stmt_bind_result(
                $stmtSearch,
                $sUid,
                $sUsername,
                $sFirstname,
                $sLastname,
                $sName,
                $sRoom,
                $sOldroom,
                $sTurm,
                $sPid,
                $sGroups
            );

            while (mysqli_stmt_fetch($stmtSearch)) {
                $searchResults[] = [
                    'uid'       => $sUid,
                    'username'  => $sUsername,
                    'firstname' => $sFirstname,
                    'lastname'  => $sLastname,
                    'name'      => $sName,
                    'room'      => $sRoom,
                    'oldroom'   => $sOldroom,
                    'turm'      => $sTurm,
                    'pid'       => $sPid,
                    'groups'    => $sGroups
                ];
            }

            mysqli_stmt_close($stmtSearch);
        }
    }

    echo renderSearchResults($searchResults, $ag, $search);
    $conn->close();
    exit;
}

$search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
$notice = (string)($_GET['notice'] ?? '');

if (isset($_POST['process_add'])) {
    $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;

    if ($uid > 0) {
        $sql = "
            UPDATE users
            SET groups = CASE
                WHEN TRIM(REPLACE(COALESCE(groups, ''), ' ', '')) = '' THEN ?
                WHEN CONCAT(',', REPLACE(COALESCE(groups, ''), ' ', ''), ',') LIKE CONCAT('%,', ?, ',%') THEN TRIM(BOTH ',' FROM REPLACE(COALESCE(groups, ''), ' ', ''))
                ELSE CONCAT(TRIM(BOTH ',' FROM REPLACE(COALESCE(groups, ''), ' ', '')), ',', ?)
            END
            WHERE uid = ?
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssi", $agStr, $agStr, $agStr, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    redirectToSelf('added', $search);
}

if (isset($_POST['process_kick'])) {
    $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;

    if ($uid > 0) {
        $sql = "
            UPDATE users
            SET groups = TRIM(
                BOTH ','
                FROM REPLACE(
                    CONCAT(',', REPLACE(COALESCE(groups, ''), ' ', ''), ','),
                    CONCAT(',', ?, ','),
                    ','
                )
            )
            WHERE uid = ?
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $agStr, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    redirectToSelf('removed', $search);
}

$currentMembers = [];
$sqlMembers = "
    SELECT uid, username, firstname, lastname, name, room, oldroom, turm, pid
    FROM users
    WHERE CONCAT(',', REPLACE(COALESCE(groups, ''), ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')
      AND pid IN (11, 12, 13, 14)
    ORDER BY
        LOWER(COALESCE(turm, '')) ASC,
        CASE
            WHEN room IS NOT NULL AND room > 0 THEN room
            WHEN oldroom IS NOT NULL AND oldroom > 0 THEN oldroom
            ELSE 999999
        END ASC,
        LOWER(COALESCE(lastname, '')) ASC,
        LOWER(COALESCE(firstname, '')) ASC,
        LOWER(COALESCE(username, '')) ASC
";
$stmtMembers = mysqli_prepare($conn, $sqlMembers);
if ($stmtMembers) {
    mysqli_stmt_bind_param($stmtMembers, "s", $agStr);
    mysqli_stmt_execute($stmtMembers);
    mysqli_stmt_bind_result(
        $stmtMembers,
        $mUid,
        $mUsername,
        $mFirstname,
        $mLastname,
        $mName,
        $mRoom,
        $mOldroom,
        $mTurm,
        $mPid
    );

    while (mysqli_stmt_fetch($stmtMembers)) {
        $currentMembers[] = [
            'uid'       => $mUid,
            'username'  => $mUsername,
            'firstname' => $mFirstname,
            'lastname'  => $mLastname,
            'name'      => $mName,
            'room'      => $mRoom,
            'oldroom'   => $mOldroom,
            'turm'      => $mTurm,
            'pid'       => $mPid
        ];
    }

    mysqli_stmt_close($stmtMembers);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        body {
            color: white;
        }

        .kp-page {
            max-width: 980px;
            margin: 0 auto;
            padding: 18px 14px 40px 14px;
        }

        .kp-title {
            text-align: center;
            font-size: 42px;
            margin: 22px 0 10px 0;
        }

        .kp-subtitle {
            text-align: center;
            font-size: 20px;
            line-height: 1.4;
            margin: 0 auto 22px auto;
            max-width: 820px;
        }

        .kp-notice {
            margin: 0 auto 18px auto;
            padding: 12px 14px;
            border-radius: 12px;
            text-align: center;
        }

        .kp-notice.success {
            background: rgba(24, 112, 43, 0.28);
            border: 1px solid rgba(72, 180, 102, 0.55);
            color: #c7ffd4;
        }

        .kp-card {
            background: rgba(20, 20, 20, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 16px;
            margin: 0 auto 18px auto;
            box-sizing: border-box;
        }

        .kp-card h2 {
            margin: 0 0 12px 0;
            font-size: 26px;
        }

        .kp-search-input {
            width: 100%;
            padding: 12px 14px;
            font-size: 18px;
            border-radius: 12px;
            border: 1px solid #444;
            background: #111;
            color: white;
            box-sizing: border-box;
        }

        .kp-table-wrap {
            width: 100%;
            overflow-x: auto;
            margin-top: 12px;
        }

        .kp-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kp-table th,
        .kp-table td {
            padding: 11px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.10);
            text-align: left;
            vertical-align: middle;
        }

        .kp-empty {
            color: #d3d3d3;
            padding: 12px 0 2px 0;
        }

        .kp-action-form {
            margin: 0;
        }

        .kp-action-cell {
            text-align: right;
            white-space: nowrap;
        }

        .kp-btn {
            appearance: none;
            border: none;
            border-radius: 10px;
            padding: 9px 12px;
            cursor: pointer;
            white-space: nowrap;
        }

        .kp-btn-add {
            background: #1f8f48;
            color: white;
        }

        .kp-btn-add:hover {
            background: #25a853;
        }

        .kp-btn-remove {
            background: #b93838;
            color: white;
        }

        .kp-btn-remove:hover {
            background: #cf4242;
        }

        .kp-btn-disabled {
            background: #555;
            color: #ddd;
            cursor: default;
        }

        .kp-loading {
            opacity: 0.7;
        }

        @media (max-width: 780px) {
            .kp-title {
                font-size: 34px;
            }

            .kp-subtitle {
                font-size: 18px;
            }

            .kp-card h2 {
                font-size: 22px;
            }

            .kp-table th,
            .kp-table td {
                padding: 10px 6px;
            }

            .kp-btn {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
<?php
echo $templateOutput;
load_menu();
?>

<div class="kp-page">
    <div class="kp-title"><?php echo h($agName); ?></div>

    <div class="kp-subtitle">
        Die Kassenprüfer hier hinzufügen, damit sie Zugriff auf <b>Kassenprüfung.php</b> bekommen.
    </div>

    <?php if ($notice === 'added') { ?>
        <div class="kp-notice success">Kassenprüfer hinzugefügt.</div>
    <?php } elseif ($notice === 'removed') { ?>
        <div class="kp-notice success">Kassenprüfer entfernt.</div>
    <?php } ?>

    <div class="kp-card">
        <h2>Hinzufügen</h2>
        <input
            type="text"
            id="liveSearch"
            class="kp-search-input"
            placeholder="User suchen ..."
            value="<?php echo h($search); ?>"
            autocomplete="off"
        >
        <div id="searchResults"></div>
    </div>

    <div class="kp-card">
        <h2>Aktuelle Kassenprüfer</h2>

        <?php if (empty($currentMembers)) { ?>
            <div class="kp-empty">Aktuell sind keine Kassenprüfer eingetragen.</div>
        <?php } else { ?>
            <div class="kp-table-wrap">
                <table class="kp-table">
                    <tr>
                        <th>Name</th>
                        <th>Raum</th>
                        <th>Turm</th>
                        <th></th>
                    </tr>

                    <?php foreach ($currentMembers as $member) { ?>
                        <?php
                            $displayName = buildDisplayName($member['firstname'], $member['lastname'], $member['name'], $member['username']);
                            $roomValue = getRoomValue($member['pid'], $member['room'], $member['oldroom']);
                            $turmLabel = formatTurm((string)$member['turm']);
                        ?>
                        <tr>
                            <td><?php echo h($displayName); ?></td>
                            <td><?php echo h($roomValue); ?></td>
                            <td><?php echo h($turmLabel); ?></td>
                            <td class="kp-action-cell">
                                <form method="post" class="kp-action-form" onsubmit="return confirm('Diesen Kassenprüfer entfernen?');">
                                    <input type="hidden" name="uid" value="<?php echo h($member['uid']); ?>">
                                    <input type="hidden" name="search" value="<?php echo h($search); ?>">
                                    <button type="submit" name="process_kick" value="1" class="kp-btn kp-btn-remove">Entfernen</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        <?php } ?>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('liveSearch');
    const results = document.getElementById('searchResults');
    let debounceTimer = null;
    let activeRequest = 0;

    function loadResults(query) {
        const requestId = ++activeRequest;
        const trimmed = query.trim();

        if (trimmed === '') {
            results.innerHTML = '';
            return;
        }

        results.classList.add('kp-loading');

        fetch('<?php echo h($_SERVER['PHP_SELF']); ?>?ajax=search&q=' + encodeURIComponent(trimmed), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(function (response) {
            return response.text();
        })
        .then(function (html) {
            if (requestId !== activeRequest) {
                return;
            }
            results.innerHTML = html;
        })
        .catch(function () {
            if (requestId !== activeRequest) {
                return;
            }
            results.innerHTML = '<div class="kp-empty">Suche fehlgeschlagen.</div>';
        })
        .finally(function () {
            if (requestId === activeRequest) {
                results.classList.remove('kp-loading');
            }
        });
    }

    input.addEventListener('input', function () {
        const value = this.value;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            loadResults(value);
        }, 180);
    });

    if (input.value.trim() !== '') {
        loadResults(input.value);
    }
})();
</script>

<?php
$conn->close();
?>
</body>
</html>