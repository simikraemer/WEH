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

 
  echo '<div style="text-align: center;">';  
  

  if (isset($_POST['mail_type'])) {
    $mail_type = $_POST['mail_type'];
    $user_id = $_SESSION['user'];
    

    $sql = "UPDATE users SET mailsettings = $mail_type WHERE uid = '$user_id'";
    mysqli_query($conn, $sql); // Führe den SQL-Befehl aus
  }

  if (isset($_POST['mail_address'])) {
    $mail_address = trim($_POST['mail_address']);
    
    if (empty($mail_address)) {
        echo '<p style="color: red; font-weight: bold;">Fehler: Die E-Mail-Adresse darf nicht leer sein.</p>';
    } 
    elseif (!filter_var($mail_address, FILTER_VALIDATE_EMAIL)) {
        echo '<p style="color: red; font-weight: bold;">Fehler: Bitte geben Sie eine gültige E-Mail-Adresse ein.</p>';
    } 
    else {
        $user_id = $_SESSION['user'];
        $sql = "UPDATE users SET email = '$mail_address' WHERE uid = '$user_id'";
        
        mysqli_query($conn, $sql);
    }
  }

  

  if (isset($_POST['mail_forward'])) {
    $forwardemail = $_POST['mail_forward'];
    $user_id = $_SESSION['user'];
    
    $sql = "UPDATE users SET forwardemail = $forwardemail WHERE uid = '$user_id'";
    mysqli_query($conn, $sql); // Führe den SQL-Befehl aus
  }


  $sql = "SELECT mailsettings, forwardemail, email FROM users WHERE uid = $_SESSION[user]";
  $result = mysqli_query($conn, $sql);
  if ($result) {
    $row = mysqli_fetch_assoc($result);
    $mailSettings = $row['mailsettings'];
    $forwardMail = $row['forwardemail'];
    $email = $row['email'];
    
    echo '<form method="post">';

    echo '<h2 style="font-size: 35px; color: white;">Rundmail Subscription Settings:</h2>';
    echo '
    <table class="clear-table">
      <tr>
        <th>Option</th>
        <th>Mailing lists</th>
      </tr>
      <tr>
        <td>
          <label>
            <input type="radio" name="mail_type" value="0" ' . ($mailSettings === "0" ? "checked" : "") . '>
            From every WEH tenant
          </label>
        </td>
        <td>community@, important@, essential@</td>
      </tr>
      <tr>
        <td>
          <label>
            <input type="radio" name="mail_type" value="1" ' . ($mailSettings === "1" ? "checked" : "") . '>
            Only from AG members
          </label>
        </td>
        <td>important@, essential@</td>
      </tr>
      <tr>
        <td>
          <label>
            <input type="radio" name="mail_type" value="2" ' . ($mailSettings === "2" ? "checked" : "") . '>
            Only essential E-Mails
          </label>
        </td>
        <td>essential@</td>
      </tr>
    </table>
    <br><br>';
    
    echo '<h2 style="font-size: 35px; color: white;">Forwarding Settings:</h2>';
    echo '
    <table class="clear-table">      
    <tr>
      <th>Option</th>
      <th>E-Mail Address</th>
    </tr>
    <tr>
      <td>
        <label>
          <input type="radio" name="mail_forward" value="0" ' . ($forwardMail === "0" ? "checked" : "") . '> Send all E-Mails to
        </label>
      </td>
      <td>' . $_SESSION['username'] . '@weh.rwth-aachen.de</td>
    </tr>
    <tr>
      <td>
        <label>
          <input type="radio" name="mail_forward" value="1" ' . ($forwardMail === "1" ? "checked" : "") . '> Forward all E-Mails to
        </label>
      </td>
      <td>
        <input type="text" name="mail_address" value="' . htmlentities($email) . '" style="width: 400px; font-size: 20px; text-align: center;">
      </td>
    </tr>
    </table>
    <br><br><br>';
    
    
    echo "<div style='display: flex; justify-content: center; margin-top: 1%'>";
    echo "<button type='submit' name='save' class='center-btn' >SAVE CHANGES</button>";  
    echo "</div>";
    $text = "All changes need about 1 hour to become active in our mailserver's configuration.";
    echo '<h2 class = "center">'.($text).'</h2>';
    
    echo '</form>';
    
    
    

    
    
  } else {
    echo "<span style='color: white;'>ERROR while trying to request Mailsettings from Database.</span>";
  }
  

  echo '</div>';
}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>