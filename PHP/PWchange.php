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
    echo '<div style="width: 70%; margin: 0 auto; text-align: center;">';
  
    if (isset($_POST["newhousepw"])) {
      $hauspw = $_POST["hauspw"];
      $hauspwnew1 = $_POST["hauspwnew1"];
      $hauspwnew2 = $_POST["hauspwnew2"];
      $uid = $_SESSION["uid"];

      $sql = "SELECT pwhaus FROM users WHERE uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $uid);
      mysqli_stmt_execute($stmt);
      #mysqli_set_charset($conn, "utf8");
      mysqli_stmt_bind_result($stmt, $hauspwDB);
      mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);
      
      if (crypt($hauspw,$hauspwDB) == $hauspwDB) {
        if ($hauspwnew1 == $hauspwnew2) {
            $newHashedPassword = crypt($hauspwnew1,$hauspwDB);
            $updateSql = "UPDATE users SET pwhaus = ? WHERE uid = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "si", $newHashedPassword, $uid);
            mysqli_stmt_execute($updateStmt);
            
            echo '<p style="color: green;">Änderung erfolgreich! Das Passwort wurde aktualisiert.</p>';
        } else {
            echo '<p style="color: red;">Die neuen Passwörter stimmen nicht überein.</p>';
        }
    } else {
        echo '<p style="color: red;">Das eingegebene alte Passwort ist nicht korrekt.</p>';
    }

    } elseif (isset($_POST["newwifipw"])) {
      $hauspw = $_POST["hauspw"];
      $wifipwnew1 = $_POST["wifipwnew1"];
      $wifipwnew2 = $_POST["wifipwnew2"];
      $uid = $_SESSION["uid"];

      $sql = "SELECT pwhaus FROM users WHERE uid = ?";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, "i", $uid);
      mysqli_stmt_execute($stmt);
      #mysqli_set_charset($conn, "utf8");
      mysqli_stmt_bind_result($stmt, $hauspwDB);
      mysqli_stmt_fetch($stmt);
      mysqli_stmt_close($stmt);

      #echo '<p style="color: white;">Eingegeben: '.$hauspw.'</p>';
      #echo '<p style="color: white;">DB: '.$hauspwDB.'</p>';
      #echo '<p style="color: white;">Crypt: '.crypt($hauspw, $hauspwDB).'</p>';

      
      if (crypt($hauspw,$hauspwDB) == $hauspwDB) {
        if ($wifipwnew1 == $wifipwnew2) {
            $updateSql = "UPDATE users SET pwwifi = ? WHERE uid = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "si", $wifipwnew1, $uid);
            mysqli_stmt_execute($updateStmt);
            
            echo '<p style="color: green;">Änderung erfolgreich! Das Passwort wurde aktualisiert.</p>';
        } else {
            echo '<p style="color: red;">Die neuen Passwörter stimmen nicht überein.</p>';
        }
      } else {
          echo '<p style="color: red;">Das eingegebene alte Passwort ist nicht korrekt.</p>';
      }
    }
    echo '<h2 style="text-align: center; font-size: 30px; color: white;">On this page you can change your House or WiFi-Password!</h2>';
    echo '<p style="text-align: center; font-size: 20px;color: white;">Your WiFi-Password is ONLY used for connecting to tuermeroam, the House-Password is used for every other authentification.</p>';
    echo '<p style="text-align: center; font-size: 20px;color: white;">If you forgot your House-Password, please visit our consultation hour with an ID or an official passport!</p><br><br><br>';

    echo '<div style="border: 2px solid white; border-radius: 5px; padding: 5px; background-color: #2a2a2a;">';
    echo '<h2 style="text-align: center; font-size: 30px;">House-Password</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="hauspw" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">Current House-Password:</label>';
    echo '<input type="password" id="hauspw" name="hauspw" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="hauspwnew1" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">New House-Password:</label>';
    echo '<input type="password" id="hauspwnew1" name="hauspwnew1" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="hauspwnew2" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">Confirm New House-Password:</label>';
    echo '<input type="password" id="hauspwnew2" name="hauspwnew2" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<br>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<button type="submit" name="newhousepw" class="center-btn" style="display: block; margin: 0 auto; font-size: 30px;">Change House-Password</button>';
    echo '</div>';
    echo '</form>';    
    echo '</div>';
    
    echo "<br><br><br><br>";

    echo '<div style="border: 2px solid white; border-radius: 5px; padding: 5px; background-color: #2a2a2a;">';    
    echo '<h2 style="text-align: center; font-size: 30px;">WiFi-Password</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="hauspw" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">House-Password:</label>';
    echo '<input type="password" id="hauspw" name="hauspw" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="wifipwnew1" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">New WiFi-Password:</label>';
    echo '<input type="password" id="wifipwnew1" name="wifipwnew1" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<label for="wifipwnew2" style="display: inline-block; width: 450px; color: white; font-size: 25px; text-align: left; margin-bottom: 10px;">Confirm New WiFi-Password:</label>';
    echo '<input type="password" id="wifipwnew2" name="wifipwnew2" style="width: 350px; font-size: 25px;" required><br>';
    echo '</div>';
    echo '<br>';
    echo '<div style="text-align: center; margin-bottom: 15px;">';
    echo '<button type="submit" name="newwifipw" class="center-btn" style="display: block; margin: 0 auto; font-size: 30px;">Change WiFi-Password</button>';
    echo '</div>';
    echo '</form>';    
    echo '</div>';

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