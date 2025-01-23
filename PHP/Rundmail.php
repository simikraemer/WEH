<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <meta name="format-detection" content="telefon=no">
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid']) {
  load_menu();

  
  echo '
  <p style="font-size: 25px; color: white; text-align: center;">
    You can use this page to send an email to all residents subscribed to the respective mailing lists.<br><br>
  </p>
  
  <div style="display: flex; justify-content: center; align-items: center; gap: 20px;">
    <a href="https://backend.weh.rwth-aachen.de/AGs.php" style="text-decoration: none;">
      <button class="center-btn" style="font-size: 30px; background-color: #fff; color: #000; border: 2px solid #000; padding: 10px 20px; transition: background-color 0.2s;">
        Contact AGs
      </button>
    </a>
    
    <a href="https://backend.weh.rwth-aachen.de/Individuen.php" style="text-decoration: none;">
      <button class="center-btn" style="font-size: 30px; background-color: #fff; color: #000; border: 2px solid #000; padding: 10px 20px; transition: background-color 0.2s;">
        Contact Individuals
      </button>
    </a>
    
    <a href="https://backend.weh.rwth-aachen.de/Mail.php" style="text-decoration: none;">
      <button class="center-btn" style="font-size: 30px; background-color: #fff; color: #000; border: 2px solid #000; padding: 10px 20px; transition: background-color 0.2s;">
        Mail Settings
      </button>
    </a>
  </div>
  
  <br><br><br><br>
  <hr>
  <br><br><br><br>
';




  $turm = $_SESSION['turm'];
  $user_address = mb_encode_mimeheader($_SESSION['username']) . "@" . $turm . ".rwth-aachen.de";

  $hasAgMembership = false;
  foreach ($ag_complete as $num => $data) {
    if (isset($_SESSION[$data["session"]]) && $_SESSION[$data["session"]] == true) {            
          $hasAgMembership = true;
          break;
      }
  }

  $sql = "SELECT COUNT(id) FROM rundmails WHERE uid=? AND tstamp > ?";
  $time = time() - 30*24*60*60; //one month ago
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, "ii", $_SESSION['uid'], $time);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $count);
  mysqli_stmt_fetch($stmt);
  mysqli_stmt_close($stmt);

  $sql = "SELECT uid FROM sperre WHERE uid = ? AND mail = 1 AND starttime < UNIX_TIMESTAMP() AND endtime > UNIX_TIMESTAMP()";
  $stmt = mysqli_prepare($conn, $sql);
  if (!$stmt) {
      die('Prepare failed: ' . mysqli_error($conn));
  }
  mysqli_stmt_bind_param($stmt, "i", $_SESSION['uid']);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_store_result($stmt);
  $mailban_active = mysqli_stmt_num_rows($stmt) > 0;
  mysqli_stmt_close($stmt);
  
  // Ausgabe, wenn eine Sperre aktiv ist
  if ($mailban_active) {    
      echo '<div style="margin: 0 auto; text-align: center;">';
      echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
      echo "<p style='color:red; text-align:center;'>You are banned from sending Mails!</p>";
      echo '</div>';
      echo '</div>';
  } elseif ($count >= 4 && !$hasAgMembership) {
    echo '<div style="margin: 0 auto; text-align: center;">';
    echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
    echo "<p style='color:red; text-align:center;'>You have already sent 4 circular mails in the past 30 days and have exceeded your limit!</p>";
    echo '</div>';
    echo '</div>';
  } else {
    $sent = false;
    if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
      if (isset($_POST['address']) && isset($_POST['titel']) && isset($_POST['body'])) {
        $selectedReplyTo = $_SESSION['name'] . " <" . $user_address . ">"; // Standard "Reply-To"
    
        if (isset($_POST['replyto']) && $_POST['replyto'] !== 'user') {
          $selectedReplyTo = $ag_complete[$_POST['replyto']]['name'] . " <" . $ag_complete[$_POST['replyto']]['mail'] . ">";
        }
    
        $encodedReplyTo = mb_encode_mimeheader($selectedReplyTo);
    
        $mailheader = "";
        $mailheader .= "Reply-To: " . $encodedReplyTo . "\r\n";
        $mailheader .= "From: " . mb_encode_mimeheader($_SESSION['name']) . " <rundmail@weh.rwth-aachen.de>\r\n";
        $mailheader .= "Bcc: " . mb_encode_mimeheader($_SESSION['name']) . " <" . $user_address . ">\r\n";
        $mailheader .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mailheader .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    
        $body = nl2br($_POST['body']);
        $body .= "<br><br>--------------------------------------------------<br>This mail was sent using the Rundmail-Webpage of WEH Backend:<br><a href='https://backend.weh.rwth-aachen.de/Rundmail.php'>https://backend.weh.rwth-aachen.de/Rundmail.php</a><br><br>To receive only important or essential Rundmails, you can adjust your preferences here:<br><a href='https://backend.weh.rwth-aachen.de/Mail.php'>https://backend.weh.rwth-aachen.de/Mail.php</a>";
    
        $recipients = [
            'essential' => 'essential@tuerme.rwth-aachen.de',
            'tvk-essential' => 'essential@tvk.rwth-aachen.de',
            'weh-essential' => 'essential@weh.rwth-aachen.de',
            'important' => 'important@tuerme.rwth-aachen.de',
            'tvk-important' => 'important@tvk.rwth-aachen.de',
            'weh-important' => 'important@weh.rwth-aachen.de',
            'tvk-community' => 'community@tvk.rwth-aachen.de',
            'weh-community' => 'community@weh.rwth-aachen.de',
            'ags' => 'ags@tuerme.rwth-aachen.de',
            'tvk-ags' => 'ags@tvk.rwth-aachen.de',
            'weh-ags' => 'ags@weh.rwth-aachen.de',
            'webmaster' => 'webmaster@weh.rwth-aachen.de'
        ];
    
        if (array_key_exists($_POST['address'], $recipients)) {
            $sent = mail($recipients[$_POST['address']], $_POST['titel'], $body, $mailheader);
        }
    
        if ($sent) {
            $sql = "INSERT INTO rundmails (uid, address, subject, tstamp, nachricht) VALUES (?,?,?,?,?)";
            $time = time();
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "issis", $_SESSION['uid'], $_POST['address'], $_POST['titel'], $time, $_POST['body']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($_POST['address'] == "essential" || $_POST['address'] == "tvk-essential" || $_POST['address'] == "weh-essential") {
                post2frontend($_POST['titel'], $_POST['body']);
            }
            echo '<div style="margin: 0 auto; text-align: center;">';
            echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
            echo "<p style='color:green; text-align:center;'>Mail versendet.</p>";
            echo '</div>';
            echo '</div>';
            echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
            echo "<script>
                setTimeout(function() {
                  document.forms['reload'].submit();
                }, 1500);
              </script>";
        } else {
            echo '<div style="margin: 0 auto; text-align: center;">';
            echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
            echo "<p style='color:red; text-align:center;'>ERROR: Mail konnte nicht versendet werden!</p>";
            echo '</div>';
            echo '</div>';
        }
      }
    
    } else {
      echo '<div style="margin: 0 auto; text-align: center;">';
      echo '<div style="border: 2px solid white; border-radius: 10px; display: inline-block; padding: 20px; text-align: center; background-color: transparent;">';
      $addressOptions = array(
        'webmaster' => 'Webmaster Test', // Testing
        'essential' => 'Essential [Türme]',
        'important' => 'Important [Türme]',
        'ags' => 'AG Mitglieder [Türme]',
        'weh-essential' => 'Essential [WEH]',
        'weh-important' => 'Important [WEH]',
        'weh-community' => 'Community [WEH]',
        'weh-ags' => 'AG Mitglieder [WEH]',
        'tvk-essential' => 'Essential [TvK]',
        'tvk-important' => 'Important [TvK]',
        'tvk-community' => 'Community [TvK]',
        'tvk-ags' => 'AG Mitglieder [TvK]',
      );
      echo '<form method="post" action="Rundmail.php" id="mail_form" name="mail-form" style="text-align: center;">';

      echo '<label for="address" style="color: white; font-size: 25px;">Send to: </label>';
      echo '<select name="address" id="address" style="margin-top: 20px; font-size: 20px;">';


      foreach ($addressOptions as $addressID => $addressName) {
        if ($addressID === 'webmaster' && !$_SESSION["Webmaster"]) {
          continue;
        }
        if (($addressID === 'essential' || $addressID === "tvk-essential" || $addressID === "weh-essential") 
        && ($_SESSION["sprecher"] == 0 && !$_SESSION["Vorstand"] && !$_SESSION["NetzAG"])) {
          continue;
        }        
        if (($addressID === 'important' || $addressID === "tvk-important" || $addressID === "weh-important" 
        || $addressID === "ags" || $addressID === "tvk-ags" || $addressID === "weh-ags") 
        && !$hasAgMembership) {
          continue;
        }
        if ($addressID === 'weh-community' && ($_SESSION["sprecher"] == 0 && !$_SESSION["Vorstand"] && !$_SESSION["NetzAG"] && $_SESSION["turm"] != 'weh'))  {
          continue;
        }
        if ($addressID === 'tvk-community' && ($_SESSION["sprecher"] == 0 && !$_SESSION["Vorstand"] && !$_SESSION["NetzAG"] && $_SESSION["turm"] != 'tvk'))  {
          continue;
        }
        $selected = (($addressID === 'weh-community' && $_SESSION["turm"] == 'weh') || ($addressID === 'tvk-community' && $_SESSION["turm"] == 'tvk')) ? 'selected' : '';
        echo '<option value="' . htmlspecialchars($addressID) . '" ' . $selected . '>' . htmlspecialchars($addressName) . '</option>';
      }
      echo '</select><br>';

      if ($hasAgMembership) {
        echo '<label for="replyto" style="color: white; font-size: 25px; cursor: help;" title="Recipients will send replies to the chosen AG instead of the sender.">Reply-To: </label>';
        echo '<select id="replyto" name="replyto" style="margin-top: 20px; font-size: 20px;" required>';
        echo '<option disabled selected value> -- Select AG -- </option>';
        echo '<option value="user">Your address</option>';
        foreach ($ag_complete as $num => $data) {
            if (isset($_SESSION[$data["session"]]) && $_SESSION[$data["session"]] == true) {
                echo '<option value="' . $num . '">' . $data["name"] . '</option>';
            }
        }
        echo '</select><br>';
      }
      echo '<label for="titel" style="color: white; font-size: 25px;">Subject: </label>';
      echo '<input type="text" name="titel" id="titel" style="margin-top: 20px; font-size: 20px; text-align: center;" required><br><br>';
      echo '<label for="body" style="color: white; font-size: 25px;">Message: </label><br>';
      echo '<textarea name="body" id="body" cols="120" rows="20" required></textarea><br>';      
      echo '<input type="hidden" name="reload" value=1>';
      echo '<button  type="submit" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">SEND</button>';
      echo '</form>';
      echo '</div>';
      echo '</div>';
    }
  }
  
  displayRundmails($conn);
  
} else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();


function post2frontend($titel, $body) {
  $config = json_decode(file_get_contents('/etc/credentials/config.json'), true);
  $mysql_rundmailphpconfig = $config['rundmailphp'];
  $conn = mysqli_connect(
      $mysql_rundmailphpconfig['host'],
      $mysql_rundmailphpconfig['user'],
      $mysql_rundmailphpconfig['password'],
      $mysql_rundmailphpconfig['database']
  );
  #mysqli_set_charset($conn,"utf8");
  if (!$conn) {
    die("Verbindung fehlgeschlagen: " . mysqli_connect_error());
  }
  $currenttime = time();
  $post_date = date('Y-m-d H:i:s', $currenttime);
  $post_date_gmt = gmdate('Y-m-d H:i:s', $currenttime);
  $post_name = strval($currenttime);

  $sql = "INSERT INTO wp_posts (post_date, post_date_gmt, post_content, post_title, post_name, post_modified, post_modified_gmt) VALUES (?, ?, ?, ?, ?, ?, ?)";
  $stmt = mysqli_prepare($conn, $sql);
  if ($stmt) {
      mysqli_stmt_bind_param($stmt, "sssssss", $post_date, $post_date_gmt, $body, $titel, $post_name, $post_date, $post_date_gmt);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
  } else {
      die("Fehler bei der Vorbereitung der Abfrage: " . mysqli_error($conn));
  }
}

?>
</body>
</html>
