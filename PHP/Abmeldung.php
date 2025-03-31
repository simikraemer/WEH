<?php
  session_start();
?>
<!DOCTYPE html>  
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
        <script>
          function alumniFunction() {
            if (document.getElementById("alumni-check").checked) {
                document.getElementById("forwardemail").hidden = false;
                document.getElementById("forwardemail").required = true;
                document.getElementById("forwardemail-label").hidden = false;
            } else {
                document.getElementById("forwardemail").hidden = true;
                document.getElementById("forwardemail").required = false;
                document.getElementById("forwardemail-label").hidden = true;
            }
          }

          function ibanFunction() {
            if (document.getElementById("iban-check").checked) {
                document.getElementById("iban").hidden = true;
                document.getElementById("iban").required = false;
                document.getElementById("iban-label").hidden = true;
            } else {
                document.getElementById("iban").hidden = false;
                document.getElementById("iban").required = true;
                document.getElementById("iban-label").hidden = false;
            }
          }
          </script>
    </head>
    <body onload="alumniFunction(); ibanFunction();">

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid']) {
  load_menu();
  //Display info

  if (abmeldecheck($conn, $_SESSION['user'])) {
      if (isset($_POST["reload"]) && $_POST["reload"] == 1) { // Notwendig, damit Seite aktualisieren nicht den Post erneut schickt
        if (isset($_POST["dod"])) {
          $sql = "SELECT pwhaus FROM users WHERE uid = ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, "i", $_SESSION["user"]);
          mysqli_stmt_execute($stmt);
          $result = get_result($stmt);
          $hash = array_shift($result)["pwhaus"];
          if (crypt($_POST["pwhaus"], $hash) == $hash) {
              if (strtotime("today") <= strtotime($_POST["dod"])) {
                  $sql = "INSERT INTO abmeldungen (uid,endtime,iban,keepemail,alumni,alumnimail,bezahlart,status,betrag,tstamp) VALUES (?,?,?,?,?,?,?,?,?,?)";
                  $stmt = mysqli_prepare($conn, $sql);
                  if (isset($_POST["email_account"])) {
                      $email = 1;
                  } else {
                      $email = 0;
                  }
                  if (isset($_POST["alumni"])) {
                      $alumni = 1;
                  } else {
                      $alumni = 0;
                  }
                  if (isset($_POST["iban-check"])) {
                    $bezahlart = 0;
                  } else {
                    $bezahlart = 1;
                  }
                  $status = 0;
                  $betrag = 0;
                  $tstamp = time();
                  mysqli_stmt_bind_param($stmt, "iisiisiiii", $_SESSION["user"], strtotime($_POST["dod"]), $_POST["iban"], $email, $alumni, $_POST["forwardemail"], $bezahlart, $status, $betrag, $tstamp);
                  if (mysqli_stmt_execute($stmt)) {
                      echo '<div style="text-align: center;">
                      <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
                      </div>';
                      echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
                      echo "<script>
                        setTimeout(function() {
                          document.forms['reload'].submit();
                        }, 1000);
                      </script>";
                  } else {
                      echo('<span style="color:red; font-weight: bold;">ERROR, DID YOU ALREADY DEREGISTER?</span>');
                  }
                  $stmt->close();
              } else {
                  echo('<span style="color:red; font-weight: bold;">YOU CAN NOT CHOOSE A DATE IN THE PAST</span>');
              }
          } else {
              echo('<span style="color:red; font-weight: bold;">WRONG PASSWORD</span>');
          }
        }
      } else {

        $sql = "SELECT wert FROM constants WHERE name = 'abmeldekosten'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        $abmeldekosten = $row['wert'];
        $stmt->close();

        $user_id = $_SESSION["user"];
        $sql = "SELECT name FROM users WHERE uid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        $stmt->close();
      
        echo('<div class="form-container">
        <form action="Abmeldung.php" method="post">
          <p style="color:white; font-size:24px; font-weight: bold; text-align: center;">'.htmlspecialchars($user["name"]).'</p>
          <p style="color:green; font-size: 20px; text-align: center;"><sub>Your internet access will remain active until you move out</sub></p>
          <label class="form-label">Your move-out date:</label>
          <input type="date" name="dod" class="form-input" required>
          <br>
          <br>
          <label id="iban-label" class="form-label">IBAN:</label>
          <input type="text" id="iban" name="iban" class="form-input" value="" required>
          <input type="checkbox" name="email_account">
          <label for="email_account" class="form-label" >I want to keep my WEH E-Mail account</label>
          <br>
          <br>
          <input type="checkbox" onclick="alumniFunction()" id="alumni-check" name="alumni">
          <label for="alumni" id="alumni" class="form-label" >I want to receive WEH Alumni-Mails (Info/Invitation for Big Events)</label>
          <br>
          <br>
          <label class="form-label" id="forwardemail-label" hidden>E-Mail for Alumni-Mails:</label>
          <input type="text" name="forwardemail" id="forwardemail" class="form-input" value="" hidden>
          <label class="form-label">Password:</label>
          <input type="password" name="pwhaus" class="form-input" required>
          <div class="form-group">
          <p style="color:red; font-size: 20px; text-align: center;"><sub>!!!Note that after submitting, your member account will be empty, so you can not print anymore!!!</sub></p>
          <input type="submit" value="Submit" class="form-submit">
          <input type="hidden" name="reload" value=1>
          </div>
        </form>
      </div>');
      }
  } else {
    echo '<div style="text-align: center;">';
    Echo('<span style="color:green; font-weight: bold;">Your deregistration has been successfully submitted and is now being processed.</span>');
    echo '</div>';
  }
}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();

function abmeldecheck($conn, $user){
  $sql = "SELECT * FROM abmeldungen WHERE uid = '$user'";
  $result = mysqli_query($conn, $sql);
  if(mysqli_num_rows($result) > 0) {
      return false;
  } else {
      return true;
  }
}

?>
</body>
</html>