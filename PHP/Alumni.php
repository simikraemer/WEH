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
  echo "<br><br>";
 
  echo '<div style="text-align: center;">';  
  

    if (isset($_POST['mail_address'])) {
        $mail_address = $_POST['mail_address'];
        $user_id = $_SESSION['user'];
        $vorhanden = $_POST['vorhanden'] === 'true' ? true : false;
        $zeit = time();
        // Schritt 1: Datenbank-Abfrage vorbereiten
        if ($vorhanden) {
            $sql = "UPDATE alumnimail SET email = ? WHERE uid = ?";
        } else {
            $sql = "INSERT INTO alumnimail (email, uid, tstamp) VALUES (?, ?, ?)";
        }

        // Schritt 2: Prepared Statement erstellen
        $stmt = mysqli_prepare($conn, $sql);

        // Schritt 3: Parameter binden
        if ($vorhanden) {
            mysqli_stmt_bind_param($stmt, "si", $mail_address, $user_id);
        } else {
            mysqli_stmt_bind_param($stmt, "sii", $mail_address, $user_id, $zeit);
        }

        // Schritt 4: Abfrage ausführen
        mysqli_stmt_execute($stmt);

        // Schritt 5: Prepared Statement schließen
        mysqli_stmt_close($stmt);
    }
  

    $sql = "SELECT email FROM alumnimail WHERE uid = $_SESSION[user]";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $email = $row['email'];
        $vorhanden = true;
    } else {
        $vorhanden = false;
    }
  

    if ($vorhanden) {
        echo '<span style="color: white; font-size: 25px;">E-Mail-Adresse im Alumni-Newsletter aktualisieren:<br><br></span>';
    } else {
        echo '<span style="color: white; font-size: 25px;">Du kannst deine E-Mail-Adresse in den Alumni-Verteiler eintragen.<br>Als Teil des Alumni-Verteilers erhältst du weiterhin Einladungen zu den großen Veranstaltungen des WEHs sowie zu speziellen Alumni-Treffen.<br><br></span>';
    }    
  
    echo '<form method="post">';
    echo '<label style="color: white; display: block; font-size: 24px;">';
    if ($vorhanden) {
        echo '<input type="text" name="mail_address" value="' . htmlentities($email) . '" style="width: 400px; font-size: 20px; text-align: center;" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">';  
    } else {
        echo '<input type="text" name="mail_address" value="" style="width: 400px; font-size: 20px; text-align: center;" placeholder="example@example.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">';    
    }
    echo '</label>';
    echo '<input type="hidden" name="vorhanden" value="' . ($vorhanden ? 'true' : 'false') . '">';  
    echo "<div style='display: flex; justify-content: center; margin-top: 1%'>";
    if ($vorhanden) {
        echo "<button type='submit' name='save' class='center-btn' >Update</button>";  
    } else {
        echo "<button type='submit' name='save' class='center-btn' >Einfügen</button>";  
    }
    echo "</div>";
    
    echo '</form>';
    
    

  

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