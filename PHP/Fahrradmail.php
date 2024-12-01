<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji  -->
<!-- Für den WEH e.V. -->
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["FahrradAG"] || $_SESSION["Webmaster"])) {
  load_menu();

    $address = $mailconfig['address'];
    $user = $mailconfig['user'];
    $password = $mailconfig['password'];
    $mailserverIP = $mailconfig['ip'];
    
    if (isset($_POST["sendmail"])) {
        $sql = "SELECT CONCAT(users.username, '@weh.rwth-aachen.de') AS email FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.platz > 0";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        mysqli_stmt_bind_result($stmt, $email);
    
        // Array zum Speichern der E-Mail-Adressen
        $emailAddresses = array();
    
        while (mysqli_stmt_fetch($stmt)) {
            $emailAddresses[] = $email;
        }
        
    
        $to = implode(',', $emailAddresses);
        $subject = "WEH Fahrrad-AG";
        $message = $_POST["nachricht"];
        $headers = "From: " . $address;
    

        if (mail($to, $subject, $message, $headers)) {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                  </div>";
        } else {
            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                    <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                  </div>";
        }
        
    }    
    else {
        echo '<div style="display: flex; flex-direction: column; align-items: center; text-align: center;">';
        echo "<div style='color: white; font-size: 30px;'>Dieser Text wird an alle Besitzer eines Stellplatzes gesendet.</div>";
        
        echo '<form method="post">';
        echo '<textarea name="nachricht" rows="30" cols="100"></textarea><br><br>';
        echo "<div style='display: flex; justify-content: center;'>";
        echo "<button type='submit' name='sendmail' class='center-btn'>Absenden</button>";  
        echo "</div>";
        echo "</form>";
        
        echo '</div>';
        
        
        
        

    }
    
    



}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>