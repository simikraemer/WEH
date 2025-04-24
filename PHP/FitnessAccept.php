<?php
    session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid']) {
    load_menu();    
    $zeit = time();    

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmuser'])) {
      $id = intval($_POST['confirmuser']);
      $tstamp = time(); // Falls $zeit global definiert ist, kannst du stattdessen $zeit verwenden
  
      // Update status & accept timestamp
      $sql = "UPDATE fitness SET status = 1, accepttstamp = ? WHERE id = ?";
      $stmt = mysqli_prepare($conn, $sql);
      if ($stmt) {
          mysqli_stmt_bind_param($stmt, "ii", $tstamp, $id);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_close($stmt);
      }
  
      // Abfrage der Benutzerdaten zum Eintrag
      $name = $room = $turm = "";
      $sql = "SELECT u.name, u.room, u.turm, u.username
              FROM users u 
              JOIN fitness f ON u.uid = f.uid 
              WHERE f.id = ?";
      $stmt = mysqli_prepare($conn, $sql);
      if ($stmt) {
          mysqli_stmt_bind_param($stmt, "i", $id);
          mysqli_stmt_execute($stmt);
          mysqli_stmt_bind_result($stmt, $name, $room, $turm, $username);
          mysqli_stmt_fetch($stmt);
          mysqli_stmt_close($stmt);
      }
  
      // Mail an Sport-AG senden
      $to = "sport@weh.rwth-aachen.de";
      $address = $mailconfig['address'];
      $subject = "[FYI] New Fitness User";
      $headers = "From: " . $address . "\r\n";
      $headers .= "Reply-To: " . $username . "@" . $turm . ".rwth-aachen.de\r\n";
  
      $message = "A new user has confirmed their participation and is now approved to use the fitness equipment:\n\n";
      $message .= "Name: $name\n";
      $message .= "Room: $room\n";
      $message .= "Tower: $turm\n\n";
      $message .= "You can view all confirmed users here:\n";
      $message .= "https://backend.weh.rwth-aachen.de/FitnessUsers.php\n";
  
      mail($to, $subject, $message, $headers);
    }
  

    $sql = "SELECT uid, status, id FROM fitness";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $status, $id);
    
    $user_found = false;
    $user_status = null;
    $user_entry_id = null; // <--- Neue Variable
    
    while (mysqli_stmt_fetch($stmt)) {
        if ($_SESSION["uid"] !== null && intval($_SESSION["uid"]) === intval($uid)) {
            $user_found = true;
            $user_status = $status;
            $user_entry_id = $id; // <--- Speichere die Datenbank-ID
            break;
        }
    }
    
    
    mysqli_stmt_close($stmt);
    
    if (!$user_found) {
        // Fall 1: Noch nicht freigeschaltet
        echo '
            <div style="color: white; font-size: 22px; line-height: 1.6; max-width: 700px; margin: 100px auto 0 auto; text-align: center; padding: 20px;">
                <p style="margin-bottom: 20px;">
                    You are not yet authorized to use the fitness equipment at WEH.
                </p>
                <p style="margin-bottom: 20px;">
                    To gain access, please attend a <strong>Sport-AG fitness orientation</strong>.
                </p>
                <p style="margin-bottom: 30px;">
                    Upcoming Sport-AG events can be found here:<br>
                    <a href="https://www2.weh.rwth-aachen.de/" target="_blank" style="color: #11a50d; font-weight: bold; text-decoration: none;">
                        www2.weh.rwth-aachen.de
                    </a>
                </p>
                <p style="font-size: 16px; font-style: italic; color: #ccc;">
                    Once you have completed the introduction, you can confirm your participation here to unlock access.
                </p>
            </div>
        ';
    } elseif ($user_status == 0) {
        // Fall 2: Freigeschaltet, aber noch nicht bestätigt
        echo '
            <div style="color: white; font-size: 18px; line-height: 1.5; max-width: 720px; margin: 0 auto; padding: 20px; text-align: left;">
                <p style="font-size: 40px; font-weight: bold; text-align: center; margin-bottom: 25px;">
                    ✅ Fitness Access Confirmation
                </p>

                <p style="margin-bottom: 15px;">
                    By confirming below, I acknowledge that I have participated in the official fitness introduction conducted by the Sport-AG at WEH.
                </p>

                <ol style="padding-left: 20px; font-size: 20px; margin-bottom: 25px;">
                    <li style="margin-bottom: 12px;">
                        I acknowledge that I use the fitness equipment and facilities at <strong>my own risk</strong>.
                    </li>
                    <li style="margin-bottom: 12px;">
                        I agree to <strong>follow all safety instructions and guidelines</strong> provided by Sport-AG.
                    </li>
                    <li style="margin-bottom: 12px;">
                        I hereby <strong>waive any liability</strong> and hold harmless the Sport-AG, the WEH e.V., and all related personnel for <strong>any injuries, damages, or losses</strong> resulting from the use of the fitness facilities.
                    </li>
                </ol>

                <p style="font-size: 30px; font-style: italic; text-align: center; margin-top: 10px;">
                    💡 Be mindful. Train responsibly. Stay safe.
                </p>

                <form method="POST" style="display: flex; justify-content: center; margin-top: 30px;">
                    <input type="hidden" name="confirmuser" value="' . htmlspecialchars($user_entry_id) . '">
                    <button type="submit" class="center-btn" style="font-size: 18px; padding: 12px 30px; cursor: pointer;">
                        Confirm and Accept
                    </button>
                </form>
            </div>
        ';





    } elseif ($user_status == 1) {
        // Fall 3: Bereits bestätigt
        echo '
            <div style="color: white; font-size: 22px; line-height: 1.6; max-width: 700px; margin: 100px auto 0 auto; text-align: center; padding: 20px;">
                <p style="margin-bottom: 20px;">
                    ✅ <strong>Access Granted</strong>
                </p>
                <p style="margin-bottom: 20px; color: #11a50d;">
                    You have successfully completed the fitness introduction.
                </p>
                <p>
                    You are now officially authorized to use the fitness equipment at WEH.
                </p>
            </div>
        ';

    }

    $conn->close();
} else {
    header("Location: denied.php");
}
?>
</body>
</html>