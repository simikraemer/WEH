<?php
  session_start();
?>
<!DOCTYPE html>  
<html>
<head>
  <meta charset="UTF-8">
  <link rel="stylesheet" href="WEH.css" media="screen">
  <style>
    /* inline tweaks for dark UI + #11a50d accent */
    .form-container { max-width: 680px; margin: 32px auto; padding: 22px; background:#141414; border:1px solid #222; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.35); }
    .form-label { display:block; color:#ddd; margin:10px 0 6px; font-weight:600; }
    .form-input, select, .form-submit {
      width:100%; padding:12px 0px 12px 2px; border-radius:10px; border:1px solid #2b2b2b; background:#0e0e0e; color:#eaeaea; outline:none;
    }
    .form-input:focus, select:focus { border-color:#11a50d; box-shadow:0 0 0 3px rgba(17,165,13,0.2); }
    .form-submit {
      background:#11a50d; border:none; font-weight:700; margin-top:16px; cursor:pointer; transition:transform .06s ease;
    }
    .form-submit:hover { transform:translateY(-1px); }
    .note { color:#ff7272; font-size:14px; text-align:center; margin-top:10px; }
    .note-fee { color:#ff9a9a; font-size:14px; text-align:center; margin-top:6px; }
    .subtitle { color:#88d887; font-size:14px; text-align:center; margin:-4px 0 18px; }
    .heading { color:#fff; font-size:22px; font-weight:800; text-align:center; margin:6px 0 6px; }
    .divider { height:1px; background:#1e1e1e; margin:14px 0 18px; border:none; }
    .success { text-align:center; color:#11a50d; font-weight:700; }
    .error { color:#ff6b6b; font-weight:700; }
    .warning { color:#ffd166; font-weight:700; }
  </style>
  <script>
    function alumniFunction() {
      const chk = document.getElementById("alumni-check");
      const fwd = document.getElementById("forwardemail");
      const lbl = document.getElementById("forwardemail-label");
      if (!chk) return;
      if (chk.checked) {
        fwd.hidden = false; fwd.required = true; fwd.style.display = "";
        lbl.hidden = false; lbl.style.display = "";
      } else {
        fwd.hidden = true; fwd.required = false; fwd.style.display = "none";
        lbl.hidden = true; lbl.style.display = "none";
      }
    }
    function onTargetChange(sel) {
      const h = document.getElementById("user-heading");
      if (h && sel && sel.options.length) {
        const opt = sel.options[sel.selectedIndex];
        h.textContent = opt.dataset.display || opt.textContent || h.textContent;
      }
    }
  </script>
</head>
<body onload="alumniFunction();">

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

$isAuthed = auth($conn) && !empty($_SESSION['valid']);
$isAdmin  = (!empty($_SESSION["NetzAG"]) && $_SESSION["NetzAG"] === true);

if ($isAuthed) {
  load_menu();

  // Helpers
  function fetch_user_info_by_uid(mysqli $conn, int $uid): array {
    $sql = "SELECT name, turm, room, oldroom FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: [];
    mysqli_stmt_close($stmt);
    return $row;
  }

  function abmeldecheck($conn, $user){
    $sql = "SELECT 1 FROM abmeldungen WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $has = mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);
    return !$has; // true if no abmeldung yet
  }

  // Handle POST (create abmeldung) - password validation removed
  if (isset($_POST["reload"]) && $_POST["reload"] == "1" && isset($_POST["dod"])) {
    $actingUid = (int)$_SESSION["user"];
    $targetUid = $actingUid;
    if ($isAdmin && !empty($_POST["target_uid"])) {
      $targetUid = (int)$_POST["target_uid"];
    }

    // Date can be in the past - no validation needed
    if (!abmeldecheck($conn, $targetUid)) {
      echo '<div class="form-container"><div class="error">ERROR: Selected user is already deregistered.</div></div>';
    } else {
      $sql = "INSERT INTO abmeldungen (uid,endtime,iban,keepemail,alumni,alumnimail,bezahlart,status,betrag,tstamp) VALUES (?,?,?,?,?,?,?,?,?,?)";
      $stmt = mysqli_prepare($conn, $sql);

      $email   = isset($_POST["email_account"]) ? 1 : 0;
      $alumni  = (isset($_POST["alumni"]) && isset($_POST["forwardemail"]) && trim($_POST["forwardemail"]) !== "") ? 1 : 0;
      $bezArt  = 1; // IBAN (cash removed)
      $status  = 0;
      $betrag  = 0;
      $tstamp  = time();
      $iban    = isset($_POST["iban"]) ? trim($_POST["iban"]) : "";
      $alMail  = isset($_POST["forwardemail"]) ? trim($_POST["forwardemail"]) : "";

      mysqli_stmt_bind_param(
        $stmt,
        "iisiisiiii",
        $targetUid,
        strtotime($_POST["dod"]),
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
        echo '<div class="form-container"><div class="success">Erfolgreich durchgeführt.</div></div>';
        echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
        echo "<form name=\"reload\" method=\"post\" action=\"Abmeldung.php\" style=\"display:none;\"><input type=\"hidden\" name=\"reload\" value=\"0\"></form>";
        echo "<script>setTimeout(function(){ document.forms['reload'].submit(); }, 1000);</script>";
      } else {
        echo '<div class="form-container"><div class="error">ERROR, DID YOU ALREADY DEREGISTER?</div></div>';
      }
      mysqli_stmt_close($stmt);
    }


  } else {
    // Render form (GET / initial)
    $sql = "SELECT wert FROM constants WHERE name = 'abmeldekosten'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $abmeldekosten = $row ? (float)$row['wert'] : 0.0;
    mysqli_stmt_close($stmt);

    $actingUid  = (int)$_SESSION["user"];
    $actingInfo = fetch_user_info_by_uid($conn, $actingUid);
    $actingName = $actingInfo['name'] ?? "User";
    $actingTurm = isset($actingInfo['turm']) ? formatTurm((string)$actingInfo['turm']) : '';
    $actingRoom = '';
    if (!empty($actingInfo['room']) && strcasecmp($actingInfo['room'], 'none') !== 0) {
      $actingRoom = $actingInfo['room'];
    } elseif (!empty($actingInfo['oldroom']) && strcasecmp($actingInfo['oldroom'], 'none') !== 0) {
      $actingRoom = $actingInfo['oldroom'];
    }

    if (!$isAdmin && !abmeldecheck($conn, $actingUid)) {
      echo '<div class="form-container"><div style="text-align:center;"><span class="success">Your deregistration has been successfully submitted and is now being processed.</span></div></div>';
    } else {
      // Admin dropdown: pid IN (11,12,13), include turm + resolved room, sort by room then name
      $adminSelectHtml = "";
      $defaultHeadingName = $actingName;

      if ($isAdmin) {
        $sql = "SELECT uid, name, turm, room, oldroom FROM users WHERE pid IN (11,12,13)";
        $res = mysqli_query($conn, $sql);
        $users = [];
        if ($res) {
          while ($u = mysqli_fetch_assoc($res)) {
            $room = '';
            if (!empty($u['room']) && strcasecmp($u['room'], 'none') !== 0) {
              $room = $u['room'];
            } elseif (!empty($u['oldroom']) && strcasecmp($u['oldroom'], 'none') !== 0) {
              $room = $u['oldroom'];
            }
            $turmFmt = isset($u['turm']) ? formatTurm((string)$u['turm']) : '';
            $users[] = [
              'uid'  => (int)$u['uid'],
              'name' => $u['name'],
              'room' => $room,
              'turm' => $turmFmt
            ];
          }
        }
        usort($users, function($a, $b){
          $ra = $a['room'] ?? '';
          $rb = $b['room'] ?? '';
          $cmp = strnatcasecmp($ra, $rb);
          if ($cmp !== 0) return $cmp;
          return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });

        $selfParts = [];
        if ($actingTurm !== '') $selfParts[] = $actingTurm;
        if ($actingRoom !== '') $selfParts[] = $actingRoom;
        $selfSuffix = count($selfParts) ? ' ('.implode(' ', $selfParts).')' : '';

        $options = '<option value="'.$actingUid.'" data-display="'.htmlspecialchars($actingName, ENT_QUOTES).'">- Myself: '.htmlspecialchars($actingName.$selfSuffix).'</option>';
        foreach ($users as $u) {
          $parts = [];
          if (!empty($u['turm'])) $parts[] = $u['turm'];
          if (!empty($u['room'])) $parts[] = $u['room'];
          $suffix = count($parts) ? ' ('.implode(' ', $parts).')' : '';
          $label = $u['name'] . $suffix;
          $options .= '<option value="'.$u['uid'].'" data-display="'.htmlspecialchars($u['name'], ENT_QUOTES).'">'.htmlspecialchars($label).'</option>';
        }

        $adminSelectHtml = '
          <label class="form-label">Act as (Admin):</label>
          <select name="target_uid" onchange="onTargetChange(this)">
            '.$options.'
          </select>
          <hr class="divider">
        ';
      }

      echo '
      <div class="form-container">
        <form action="Abmeldung.php" method="post" autocomplete="off" novalidate>
          <div class="heading" id="user-heading">'.htmlspecialchars($defaultHeadingName).'</div>
          <div class="subtitle"><sub>Your internet access will remain active until you move out</sub></div>

          '. $adminSelectHtml .'

          <label class="form-label">Your move-out date:</label>
          <input type="date" name="dod" class="form-input" required>

          <label id="iban-label" class="form-label">IBAN:</label>
          <input type="text" id="iban" name="iban" class="form-input" value="" required maxlength="34"
                 pattern="[A-Z]{2}[0-9A-Z]{13,32}"
                 title="Please enter a valid IBAN (e.g. DE89... or FR76...)" style="text-transform: uppercase;">

          <div style="margin-top:14px;">
            <input type="checkbox" id="email_account" name="email_account">
            <label for="email_account" class="form-label" style="display:inline; margin-left:6px;">I want to keep my WEH E-Mail account</label>
          </div>

          <div style="margin-top:10px;">
            <input type="checkbox" onclick="alumniFunction()" id="alumni-check" name="alumni">
            <label for="alumni-check" class="form-label" style="display:inline; margin-left:6px;">I want to receive WEH Alumni-Mails (Info/Invitation for Big Events)</label>
          </div>

          <label class="form-label" id="forwardemail-label" hidden style="display:none;">E-Mail for Alumni-Mails:</label>
          <input type="email" name="forwardemail" id="forwardemail" class="form-input" value="" hidden style="display:none;">

          '.($abmeldekosten > 0 ? '<div class="note-fee">Fee: '.htmlspecialchars(number_format($abmeldekosten, 2, ',', '.')).'€</div>' : '').'

          <div class="note">!!! Note that after submitting, your member account will be empty, so you can not print anymore !!!</div>

          <input type="hidden" name="reload" value="1">
          <input type="submit" value="Submit" class="form-submit">
        </form>
      </div>';
    }
  }

} else {
  header("Location: denied.php");
  exit;
}

$conn->close();
?>
</body>
</html>
