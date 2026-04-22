<?php
ob_start();
session_start();

require_once('conn.php');     // liefert $conn
require_once('template.php'); // liefert auth(), load_menu(), etc.

mysqli_set_charset($conn, "utf8");

/* -----------------------------
   PRG Flash Helpers
----------------------------- */
function flash_set(string $type, string $text): void {
  $_SESSION['flash_bike'] = ['type' => $type, 'text' => $text];
}
function flash_print(): void {
  if (!empty($_SESSION['flash_bike'])) {
    $f = $_SESSION['flash_bike'];
    unset($_SESSION['flash_bike']);
    $type = ($f['type'] === 'success') ? 'success' : 'error';
    $text = htmlspecialchars($f['text'], ENT_QUOTES, 'UTF-8');
    echo "<div class='weh-flash {$type}'>{$text}</div>";
  }
}

$zeit = time();
$turm = $_SESSION['turm'] ?? 'weh';

$bikePriorityExcludedGroups = [1, 16, 19, 20, 22, 24, 26, 27, 55];

$hasBikeQueuePriority = static function (?string $groupsCsv) use ($bikePriorityExcludedGroups): bool {
  if ($groupsCsv === null || trim($groupsCsv) === '') {
    return false;
  }

  $groupIds = array_filter(array_map('trim', explode(',', $groupsCsv)), static function ($v) {
    return $v !== '';
  });

  foreach ($groupIds as $groupId) {
    $groupId = (int)$groupId;
    if ($groupId > 0 && !in_array($groupId, $bikePriorityExcludedGroups, true)) {
      return true;
    }
  }

  return false;
};

/* -----------------------------
   Auth + Rollencheck
----------------------------- */
if (!(auth($conn) && !empty($_SESSION['valid']))) {
  header("Location: denied.php");
  exit;
}
if (!(($_SESSION["FahrradAG"] ?? false) || ($_SESSION["TvK-Sprecher"] ?? false) || ((isset($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true)))) {
  header("Location: denied.php");
  exit;
}

$agentuid = (int)($_SESSION["uid"] ?? 0);
$SELF_URL = strtok($_SERVER['REQUEST_URI'], '?');

/* -----------------------------
   POST Actions (Modal Confirm)
   -> MUSS VOR JEGLICHEM HTML OUTPUT passieren
----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = (string)$_POST['action'];

  try {
    if ($action === 'remove_stellplatz') {
      $stellplatz = isset($_POST['stellplatz']) ? (int)$_POST['stellplatz'] : 0;
      if ($stellplatz < 1) throw new Exception("Ungültiger Stellplatz.");

      // Aktuellen Bewohner (aktiver Datensatz)
      $sql = "SELECT f.id, f.uid
              FROM fahrrad f
              WHERE f.platz = ? AND f.turm = ? AND (f.endtime IS NULL OR f.endtime > ?)
              ORDER BY f.starttime DESC
              LIMIT 1";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "isi", $stellplatz, $turm, $zeit);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $fid_old, $uid_old);
      $hasOld = mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      if (!$hasOld) throw new Exception("Kein aktiver Eintrag für diesen Stellplatz gefunden.");

      // Nächster aus Warteschlange (aktiver Datensatz)
      $sql = "SELECT f.id, f.uid
              FROM fahrrad f
              INNER JOIN users u ON u.uid = f.uid
              WHERE f.platz = 0
                AND (f.endtime IS NULL OR f.endtime > ?)
                AND f.turm = ?
              ORDER BY
                  CASE
                    WHEN EXISTS (
                      SELECT 1
                      FROM `groups` g
                      WHERE g.turm = u.turm
                        AND FIND_IN_SET(CAST(g.id AS CHAR), REPLACE(COALESCE(u.groups, ''), ' ', '')) > 0
                        AND g.id NOT IN (1,16,19,20,22,24,26,27,55)
                        -- AND g.active = 1
                    ) THEN 0
                    ELSE 1
                  END,
                  f.starttime ASC
              LIMIT 1";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "is", $zeit, $turm);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $fid_new, $uid_new);
      $hasNew = mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      if (!$hasNew) throw new Exception("Warteschlange ist leer – niemand kann nachrücken.");

      mysqli_begin_transaction($conn);

      // Alten Bewohner beenden
      $sql = "UPDATE fahrrad
              SET endtime = ?, status = 2, platz = 0, endagent = ?
              WHERE id = ? AND turm = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iiis", $zeit, $agentuid, $fid_old, $turm);
      if (!mysqli_stmt_execute($stmt)) throw new Exception("Update (alt) fehlgeschlagen.");
      mysqli_stmt_close($stmt);

      // Neuen Bewohner auf Stellplatz setzen
      $sql = "UPDATE fahrrad
              SET platz = ?, platztime = ?, status = 3
              WHERE id = ? AND turm = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iiis", $stellplatz, $zeit, $fid_new, $turm);
      if (!mysqli_stmt_execute($stmt)) throw new Exception("Update (neu) fehlgeschlagen.");
      mysqli_stmt_close($stmt);

      mysqli_commit($conn);

      flash_set('success', "Erfolgreich durchgeführt.");
      header("Location: {$SELF_URL}");
      exit;
    }

    if ($action === 'remove_queue') {
      $queue_uid = isset($_POST['queue_uid']) ? (int)$_POST['queue_uid'] : 0;
      if ($queue_uid < 1) throw new Exception("Ungültiger Nutzer.");

      // Aktiven Queue-Datensatz finden
      $sql = "SELECT f.id
              FROM fahrrad f
              WHERE f.uid = ? AND f.turm = ? AND f.platz = 0 AND (f.endtime IS NULL OR f.endtime > ?)
              ORDER BY f.starttime DESC
              LIMIT 1";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "isi", $queue_uid, $turm, $zeit);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $fid_q);
      $hasQ = mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);

      if (!$hasQ) throw new Exception("Kein aktiver Queue-Eintrag gefunden.");

      $sql = "UPDATE fahrrad
              SET endtime = ?, status = 4, endagent = ?
              WHERE id = ? AND turm = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iiis", $zeit, $agentuid, $fid_q, $turm);
      if (!mysqli_stmt_execute($stmt)) throw new Exception("Update fehlgeschlagen.");
      mysqli_stmt_close($stmt);

      flash_set('success', "Erfolgreich durchgeführt.");
      header("Location: {$SELF_URL}");
      exit;
    }

    if ($action === 'swap_plaetze') {
      $platzA = isset($_POST['platz_a']) ? (int)$_POST['platz_a'] : 0;
      $platzB = isset($_POST['platz_b']) ? (int)$_POST['platz_b'] : 0;

      if ($platzA < 1 || $platzB < 1) throw new Exception("Bitte zwei gültige Stellplätze auswählen.");
      if ($platzA === $platzB) throw new Exception("Bitte zwei verschiedene Stellplätze auswählen.");

      // Datensatz A
      $sql = "SELECT f.id, f.uid
              FROM fahrrad f
              WHERE f.platz = ? AND f.turm = ? AND (f.endtime IS NULL OR f.endtime > ?)
              ORDER BY f.starttime DESC
              LIMIT 1";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "isi", $platzA, $turm, $zeit);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $fidA, $uidA);
      $hasA = mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      if (!$hasA) throw new Exception("Stellplatz {$platzA} ist nicht (mehr) belegt.");

      // Datensatz B
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "isi", $platzB, $turm, $zeit);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_bind_result($stmt, $fidB, $uidB);
      $hasB = mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      if (!$hasB) throw new Exception("Stellplatz {$platzB} ist nicht (mehr) belegt.");

      mysqli_begin_transaction($conn);

      // A -> B
      $sql = "UPDATE fahrrad
              SET platz = ?, platztime = ?, status = 6
              WHERE id = ? AND turm = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iiis", $platzB, $zeit, $fidA, $turm);
      if (!mysqli_stmt_execute($stmt)) throw new Exception("Swap Update A fehlgeschlagen.");
      mysqli_stmt_close($stmt);

      // B -> A
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "iiis", $platzA, $zeit, $fidB, $turm);
      if (!mysqli_stmt_execute($stmt)) throw new Exception("Swap Update B fehlgeschlagen.");
      mysqli_stmt_close($stmt);

      mysqli_commit($conn);

      flash_set('success', "Stellplätze erfolgreich getauscht.");
      header("Location: {$SELF_URL}");
      exit;
    }

    throw new Exception("Unbekannte Aktion.");

  } catch (Exception $e) {
    @mysqli_rollback($conn);
    flash_set('error', $e->getMessage());
    header("Location: {$SELF_URL}");
    exit;
  }
}

/* -----------------------------
   Ab hier: normales Rendering
----------------------------- */

// Top-of-Queue (für Modal-Text)
$queueTopName = null;
$queueTopRoom = null;
$queueTopUid  = null;

$sql = "SELECT users.name, users.room, users.uid
        FROM users
        INNER JOIN fahrrad ON users.uid = fahrrad.uid
        WHERE fahrrad.platz = 0
          AND (fahrrad.endtime IS NULL OR fahrrad.endtime > ?)
          AND fahrrad.turm = ?
        ORDER BY
            CASE
              WHEN EXISTS (
                SELECT 1
                FROM `groups` g
                WHERE g.turm = users.turm
                  AND FIND_IN_SET(CAST(g.id AS CHAR), REPLACE(COALESCE(users.groups, ''), ' ', '')) > 0
                  AND g.id NOT IN (1,16,19,20,22,24,26,27,55)
                  -- AND g.active = 1
              ) THEN 0
              ELSE 1
            END,
            fahrrad.starttime ASC
        LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "is", $zeit, $turm);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $queueTopName, $queueTopRoom, $queueTopUid);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

// Alle aktiven Einträge (Stellplätze + Queue)
$sql = "SELECT users.uid, users.firstname, users.lastname, users.room,
               users.oldroom, fahrrad.starttime, fahrrad.platz, fahrrad.platztime,
               users.username, users.groups
        FROM users
        INNER JOIN fahrrad ON users.uid = fahrrad.uid
        WHERE (fahrrad.endtime IS NULL OR fahrrad.endtime > ?) AND fahrrad.turm = ?
        ORDER BY fahrrad.platz";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) die("Error: " . mysqli_error($conn));
mysqli_stmt_bind_param($stmt, "is", $zeit, $turm);
if (!mysqli_stmt_execute($stmt)) die("Error: " . mysqli_stmt_error($stmt));

mysqli_stmt_store_result($stmt);
mysqli_stmt_bind_result($stmt, $uid, $firstname, $lastname, $room, $oldroom, $tstamp, $bike_storageid, $platztime, $username, $groups);

$users_by_stellplatz = [];
while (mysqli_stmt_fetch($stmt)) {
  $users_by_stellplatz[$bike_storageid][] = [
    "uid" => $uid,
    "firstname" => $firstname,
    "lastname" => $lastname,
    "room" => $room,
    "oldroom" => $oldroom,
    "tstamp" => $tstamp,
    "platztime" => $platztime,
    "username" => $username,
    "groups" => $groups
  ];
}
mysqli_stmt_free_result($stmt);
mysqli_stmt_close($stmt);

$users_queue = [];
$users_stellplatz = [];

foreach ($users_by_stellplatz as $bike_storageid => $users) {
  if ($bike_storageid < 1) {
    usort($users, function ($a, $b) use ($hasBikeQueuePriority) {
      $priorityA = $hasBikeQueuePriority($a["groups"]) ? 0 : 1;
      $priorityB = $hasBikeQueuePriority($b["groups"]) ? 0 : 1;

      if ($priorityA !== $priorityB) {
        return $priorityA <=> $priorityB;
      }

      return $a["tstamp"] <=> $b["tstamp"];
    });
    $users_queue[$bike_storageid] = $users;
  } else {
    $users_stellplatz[$bike_storageid] = $users;
  }
}

// Swap Dropdown Optionen (nur belegte Stellplätze)
$swapOptions = [];
foreach ($users_stellplatz as $stellplatz => $users) {
  if (!empty($users)) {
    $u = $users[0];
    $fn = strtok($u['firstname'], ' ');
    $ln = strtok($u['lastname'], ' ');
    $nm = trim($fn . ' ' . $ln);
    $rm = ($u['room'] === 0 ? $u['oldroom'] : $u['room']);
    $swapOptions[] = [
      'platz' => (int)$stellplatz,
      'name' => $nm,
      'room' => (string)$rm
    ];
  }
}
$swapDisabled = (count($swapOptions) < 2);

?>
<!DOCTYPE html>
<!-- Fiji  -->
<!-- Für den WEH e.V. -->
<html>
<head>
  <meta charset="utf-8">
  <link rel="stylesheet" href="WEH.css" media="screen">

  <style>
    .weh-center-wrap { text-align: center; margin: 14px 0 0 0; }

    .weh-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 9px 14px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(255,255,255,0.10);
      color: #fff;
      cursor: pointer;
      user-select: none;
      transition: transform .06s ease, background .12s ease, border-color .12s ease;
      font-size: 14px;
      line-height: 1;
    }
    .weh-btn:hover { background: rgba(255,255,255,0.16); border-color: rgba(255,255,255,0.26); }
    .weh-btn:active { transform: translateY(1px); }
    .weh-btn[disabled] { opacity: .45; cursor: not-allowed; transform: none; }

    .weh-btn-primary { background: rgba(0, 200, 120, 0.22); border-color: rgba(0, 200, 120, 0.35); }
    .weh-btn-primary:hover { background: rgba(0, 200, 120, 0.28); }

    .weh-btn-danger { background: rgba(230, 70, 70, 0.22); border-color: rgba(230, 70, 70, 0.35); }
    .weh-btn-danger:hover { background: rgba(230, 70, 70, 0.28); }

    .weh-btn-secondary { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.14); }
    .weh-btn-secondary:hover { background: rgba(255,255,255,0.12); }

    .weh-icon-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
      margin: 0;
    }

    .weh-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.55);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 18px;
    }
    .weh-modal-overlay.is-open { display: flex; }

    .weh-modal {
      width: min(560px, 100%);
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(20,20,20,0.92);
      box-shadow: 0 18px 50px rgba(0,0,0,0.55);
      overflow: hidden;
      color: #fff;
      transform: translateY(6px);
      opacity: 0;
      transition: transform .12s ease, opacity .12s ease;
    }
    .weh-modal-overlay.is-open .weh-modal { transform: translateY(0); opacity: 1; }

    .weh-modal-header {
      padding: 14px 16px 10px 16px;
      border-bottom: 1px solid rgba(255,255,255,0.12);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .weh-modal-title { font-size: 16px; font-weight: 700; margin: 0; }
    .weh-modal-close {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.16);
      background: rgba(255,255,255,0.08);
      color: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .weh-modal-close:hover { background: rgba(255,255,255,0.12); }

    .weh-modal-body { padding: 14px 16px 16px 16px; }
    .weh-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      padding: 12px 16px 16px 16px;
      border-top: 1px solid rgba(255,255,255,0.10);
      flex-wrap: wrap;
    }

    .weh-field { display: grid; gap: 6px; margin: 10px 0; }
    .weh-label { font-size: 13px; opacity: 0.9; }
    .weh-select {
      width: 100%;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.16);
      outline: none;
    }
    .weh-select:focus { border-color: rgba(0,200,120,0.45); }

    .weh-summary {
      margin-top: 10px;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.06);
      font-size: 13px;
      line-height: 1.35;
    }

    .weh-flash { text-align: center; margin: 10px 0 18px 0; font-size: 18px; }
    .weh-flash.success { color: #22dd88; }
    .weh-flash.error { color: #ff6b6b; }
  </style>
</head>

<?php
// Navbar etc.
load_menu();

echo "<br><br>";

echo "<div class='bike-container'>";

/* -----------------------------
   Stellplätze (Links)
----------------------------- */
echo "<div class='leftbike-container'>";
echo '<h1 class="center">Stellplätze</h1>';

flash_print();

echo "<table class='leftbike'>";
echo '<tr><th>Stellplatz</th><th>Letzte Änderung</th><th>Raum</th><th>Name</th><th>Mail</th><th>Kick</th></tr>';

foreach ($users_stellplatz as $stellplatz => $users) {
  foreach ($users as $user) {
    $username = $user['username'];
    $firstname = strtok($user['firstname'], ' ');
    $lastname = strtok($user['lastname'], ' ');
    $name = trim($firstname . ' ' . $lastname);
    $user_platztimeform = date('d.m.Y', (int)$user['platztime']);

    $roomDisplay = ($user['room'] === 0)
      ? $user['oldroom'] . " <img src='images/sublet.png' width='20' height='20'>"
      : $user['room'];

    $occNameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $occRoomEsc = htmlspecialchars((string)($user['room'] === 0 ? $user['oldroom'] : $user['room']), ENT_QUOTES, 'UTF-8');

    $nextNameEsc = htmlspecialchars((string)($queueTopName ?? '—'), ENT_QUOTES, 'UTF-8');
    $nextRoomEsc = htmlspecialchars((string)($queueTopRoom ?? '—'), ENT_QUOTES, 'UTF-8');
    $stellEsc    = (int)$stellplatz;

    echo '<tr>';
    echo "<td style='color: white;'>$stellEsc</td>";
    echo "<td style='color: white;'>$user_platztimeform</td>";
    echo "<td style='color: white;'>$roomDisplay</td>";
    echo "<td style='color: white;'>$occNameEsc</td>";
    echo "<td style='text-align: center;'>
            <a href='mailto:\"$occNameEsc\" <$username@weh.rwth-aachen.de>'>
              <img src='images/mail_white.png' alt='Contact Icon' style='width: 24px; height: 24px;'
                  onmouseover=\"this.src='images/mail_green.png';\"
                  onmouseout=\"this.src='images/mail_white.png';\">
            </a>
          </td>";
    echo '<td style="text-align: center;">
            <button type="button"
                    class="weh-icon-btn js-kick-stellplatz"
                    data-stellplatz="'.$stellEsc.'"
                    data-occupant-name="'.$occNameEsc.'"
                    data-occupant-room="'.$occRoomEsc.'"
                    data-next-name="'.$nextNameEsc.'"
                    data-next-room="'.$nextRoomEsc.'">
              <img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">
            </button>
          </td>';
    echo '</tr>';
  }
}
echo "</table>";

// Button Stellplatzwechsel (zentriert unter linker Tabelle)
echo "<div class='weh-center-wrap'>";
echo "<button type='button' id='btnSwap' class='weh-btn weh-btn-primary' ".($swapDisabled ? "disabled" : "").">Stellplatzwechsel</button>";
echo "</div>";

echo "</div>"; // leftbike-container

/* -----------------------------
   Warteschlange (Rechts)
----------------------------- */
echo "<div class='rightbike-container'>";
echo '<h1 class="center">Warteschlange</h1>';
echo "<table class='rightbike'>";
echo '<tr><th>Position</th><th>Anmeldung</th><th>Raum</th><th>Name</th><th>Mail</th><th>Kick</th></tr>';

$position = 1;
foreach ($users_queue as $stellplatz0 => $users) {
  foreach ($users as $user) {
    $uid = (int)$user['uid'];
    $username = $user['username'];
    $firstname = strtok($user['firstname'], ' ');
    $lastname = strtok($user['lastname'], ' ');
    $name = trim($firstname . ' ' . $lastname);
    $user_zeitform = date('d.m.Y', (int)$user['tstamp']);

    $roomDisplay = ($user['room'] === 0)
      ? $user['oldroom'] . " <img src='images/sublet.png' width='20' height='20'>"
      : $user['room'];

    $qNameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $qRoomEsc = htmlspecialchars((string)($user['room'] === 0 ? $user['oldroom'] : $user['room']), ENT_QUOTES, 'UTF-8');

    echo '<tr>';
    echo "<td style='color: white;'>$position</td>";
    echo "<td style='color: white;'>$user_zeitform</td>";
    echo "<td style='color: white;'>$roomDisplay</td>";
    echo "<td style='color: white;'>$qNameEsc</td>";
    echo "<td style='text-align: center;'>
            <a href='mailto:\"$qNameEsc\" <$username@weh.rwth-aachen.de>'>
              <img src='images/mail_white.png' alt='Contact Icon' style='width: 24px; height: 24px;'
                  onmouseover=\"this.src='images/mail_green.png';\"
                  onmouseout=\"this.src='images/mail_white.png';\">
            </a>
          </td>";
    echo '<td style="text-align: center;">
            <button type="button"
                    class="weh-icon-btn js-kick-queue"
                    data-queue-uid="'.$uid.'"
                    data-queue-name="'.$qNameEsc.'"
                    data-queue-room="'.$qRoomEsc.'">
              <img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">
            </button>
          </td>';
    echo '</tr>';
    $position++;
  }
}
echo "</table>";
echo "</div>"; // rightbike-container
echo "</div>"; // bike-container
?>

<!-- =======================
     Global Modal (Reusable)
     ======================= -->
<div id="wehModalOverlay" class="weh-modal-overlay" aria-hidden="true">
  <div class="weh-modal" role="dialog" aria-modal="true" aria-labelledby="wehModalTitle">
    <div class="weh-modal-header">
      <h2 class="weh-modal-title" id="wehModalTitle">Modal</h2>
      <button type="button" class="weh-modal-close" id="wehModalX" aria-label="Schließen">✕</button>
    </div>
    <div class="weh-modal-body" id="wehModalBody"></div>
    <div class="weh-modal-actions" id="wehModalActions"></div>
  </div>
</div>

<script>
(() => {
  const overlay = document.getElementById('wehModalOverlay');
  const modalTitle = document.getElementById('wehModalTitle');
  const modalBody = document.getElementById('wehModalBody');
  const modalActions = document.getElementById('wehModalActions');
  const btnX = document.getElementById('wehModalX');

  function openModal({ title, bodyHtml, actionsHtml }) {
    modalTitle.textContent = title || 'Aktion';
    modalBody.innerHTML = bodyHtml || '';
    modalActions.innerHTML = actionsHtml || '';
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');

    overlay.querySelectorAll('.js-modal-close').forEach(el => {
      el.addEventListener('click', closeModal);
    });
  }

  function closeModal() {
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    modalBody.innerHTML = '';
    modalActions.innerHTML = '';
  }

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) closeModal();
  });
  btnX.addEventListener('click', closeModal);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
  });

  // Kick Stellplatz -> Modal
  document.querySelectorAll('.js-kick-stellplatz').forEach(btn => {
    btn.addEventListener('click', () => {
      const stellplatz = btn.dataset.stellplatz;
      const occName = btn.dataset.occupantName || '—';
      const occRoom = btn.dataset.occupantRoom || '—';
      const nextName = btn.dataset.nextName || '—';
      const nextRoom = btn.dataset.nextRoom || '—';

      const body = `
        <p>
          Soll <b>${occName} (${occRoom})</b> als eingetragener Bewohner für Stellplatz <b>${stellplatz}</b> entfernt werden?
          <br><br>
          <span style="opacity:.92;">
            Bewohner <b>${nextName} (${nextRoom})</b> rückt nach und wird automatisch über seinen/ihren neuen Stellplatz <b>${stellplatz}</b> informiert.
          </span>
        </p>
      `;

      const actions = `
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; width:100%;">
          <input type="hidden" name="action" value="remove_stellplatz">
          <input type="hidden" name="stellplatz" value="${stellplatz}">
          <button type="button" class="weh-btn weh-btn-secondary js-modal-close">Abbrechen</button>
          <button type="submit" class="weh-btn weh-btn-danger">Bestätigen</button>
        </form>
      `;

      openModal({ title: 'Stellplatz entfernen', bodyHtml: body, actionsHtml: actions });
    });
  });

  // Kick Queue -> Modal
  document.querySelectorAll('.js-kick-queue').forEach(btn => {
    btn.addEventListener('click', () => {
      const uid = btn.dataset.queueUid;
      const name = btn.dataset.queueName || '—';
      const room = btn.dataset.queueRoom || '—';

      const body = `<p>Soll <b>${name} (${room})</b> aus der Warteschlange entfernt werden?</p>`;

      const actions = `
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; width:100%;">
          <input type="hidden" name="action" value="remove_queue">
          <input type="hidden" name="queue_uid" value="${uid}">
          <button type="button" class="weh-btn weh-btn-secondary js-modal-close">Abbrechen</button>
          <button type="submit" class="weh-btn weh-btn-danger">Bestätigen</button>
        </form>
      `;

      openModal({ title: 'Warteschlange entfernen', bodyHtml: body, actionsHtml: actions });
    });
  });

  // Stellplatzwechsel -> Modal
  const btnSwap = document.getElementById('btnSwap');
  if (btnSwap && !btnSwap.disabled) {
    btnSwap.addEventListener('click', () => {
      const optionsHtml = `<?php
        $opt = "";
        foreach ($swapOptions as $o) {
          $p = (int)$o['platz'];
          $n = htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8');
          $r = htmlspecialchars($o['room'], ENT_QUOTES, 'UTF-8');
          $label = "Stellplatz {$p} – {$n} ({$r})";
          $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
          $opt .= "<option value=\"{$p}\" data-name=\"{$n}\" data-room=\"{$r}\">{$label}</option>";
        }
        echo $opt;
      ?>`;

      const body = `
        <div class="weh-field">
          <div class="weh-label">Stellplatz A</div>
          <select class="weh-select" id="swapA">${optionsHtml}</select>
        </div>

        <div class="weh-field">
          <div class="weh-label">Stellplatz B</div>
          <select class="weh-select" id="swapB">${optionsHtml}</select>
        </div>

        <div class="weh-summary" id="swapSummary">Bitte zwei verschiedene Stellplätze auswählen.</div>
      `;

      const actions = `
        <form method="post" id="swapForm" style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; width:100%;">
          <input type="hidden" name="action" value="swap_plaetze">
          <input type="hidden" name="platz_a" id="swapAHidden" value="">
          <input type="hidden" name="platz_b" id="swapBHidden" value="">
          <button type="button" class="weh-btn weh-btn-secondary js-modal-close">Abbrechen</button>
          <button type="submit" class="weh-btn weh-btn-primary" id="swapConfirm" disabled>Bestätigen</button>
        </form>
      `;

      openModal({ title: 'Stellplatzwechsel', bodyHtml: body, actionsHtml: actions });

      const selA = document.getElementById('swapA');
      const selB = document.getElementById('swapB');
      const summary = document.getElementById('swapSummary');
      const btnConfirm = document.getElementById('swapConfirm');
      const hiddenA = document.getElementById('swapAHidden');
      const hiddenB = document.getElementById('swapBHidden');

      function getOptData(sel) {
        const opt = sel.options[sel.selectedIndex];
        return {
          platz: sel.value,
          name: opt ? (opt.dataset.name || '—') : '—',
          room: opt ? (opt.dataset.room || '—') : '—'
        };
      }

      function updateSummary() {
        const A = getOptData(selA);
        const B = getOptData(selB);

        hiddenA.value = A.platz;
        hiddenB.value = B.platz;

        if (!A.platz || !B.platz || A.platz === B.platz) {
          summary.textContent = 'Bitte zwei verschiedene Stellplätze auswählen.';
          btnConfirm.disabled = true;
          return;
        }

        summary.innerHTML = `<b>${A.name}</b> (Stellplatz <b>${A.platz}</b>) wechselt mit <b>${B.name}</b> (Stellplatz <b>${B.platz}</b>).<br>Die Personen werden NICHT automatisiert über diesen Wechsel informiert!`;
        btnConfirm.disabled = false;
      }

      selA.addEventListener('change', updateSummary);
      selB.addEventListener('change', updateSummary);

      if (selA.value && selB.value && selA.value === selB.value && selB.options.length > 1) {
        selB.selectedIndex = 1;
      }
      updateSummary();
    });
  }
})();
</script>

<?php
$conn->close();
?>
</body>
</html>