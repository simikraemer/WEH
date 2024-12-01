<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji November 2023 -->
<!-- Für den WEH e.V. -->
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION['valid'])) {
  load_menu();
  

  $zeit = time();
  if ($_SESSION['valid']) {
    if (isset($_POST["sendpost"])) {
        $uid = $_SESSION["user"];
        $username = $_SESSION["username"];
        $name = $_SESSION["name"];
        $room = $_SESSION["userroom"];
        $email = $_SESSION["email"];
        $tstamp = time();

        $tools = $_POST['tools'];
        $zumbriefkasten = $_POST['zumbriefkasten'];
        $starttime = strtotime($_POST['starttime']);
        $endtime = strtotime($_POST['endtime']);
        $comment = $_POST['comment'];
    
        if ($starttime > $endtime) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Deine Startzeit liegt hinter der Endzeit!</p>";
            echo "</div>";
        } elseif ($tools == false) {
            echo "<div style='text-align: center;'>";
            echo "<p style='color:red; text-align:center;'>Falsches Format der Tools.</p>";
            echo "</div>";
        } else {    
            $insert_sql = "INSERT INTO werkzeugbuchung (uid, tstamp, tools, zumbriefkasten, starttime, endtime, comment) VALUES (?,?,?,?,?,?,?)";
            $insert_var = array($uid, $tstamp, $tools, $zumbriefkasten, $starttime, $endtime, $comment);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iisiiis", ...$insert_var);
            mysqli_stmt_execute($stmt);

            $message = "Neue Buchungsanfrage von " . $name . "\n\n";
            $message .= "Werkzeuge: " . $tools . "\n";
            $message .= "Lieferung: " . ($zumbriefkasten == 1 ? "Zum Briefkasten" : "Zur Tür") . "\n";
            $message .= "Raum: " . $room . "\n";
            $message .= "Startzeit: " . date('d.m.Y H:i', $starttime) . "\n";
            $message .= "Endzeit: " . date('d.m.Y H:i', $endtime) . "\n";
            $message .= "Kommentar: " . $comment . "\n";            

            $address = $username . "@weh.rwth-aachen.de";
            $to = "werkzeugbuchung@weh.rwth-aachen.de";
            #$to = "werkzeugticket@weh.rwth-aachen.de";
            $subject = "Buchung " . $name;
            $headers = "From: " . $address . "\r\n";
            $headers .= "Content-type: text/plain; charset=UTF-8\r\n";


            if (mail($to, $subject, $message, $headers)) {
                echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                        <span style='color: green; font-size: 20px;'>Deine Anfrage wurde gesendet.</span>
                      </div>";
            } else {
                echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                        <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                      </div>";
            }
        }
    }
    
    echo '<div style="width: 50%; margin: 0 auto; text-align: center;">';
    echo '<h2 style="text-align: center; font-size: 25px;">Hier kannst du Werkzeuge buchen!</h2>';
    echo '<span style="color: white; text-align: center; font-size: 20px;">Die Werkzeug-AG wird dir das Werkzeug zur Startzeit entweder in den Briefkasten werfen oder zur Tür bringen.</span><br>';
    echo "<br><br><br>";

    echo '<form method="post" enctype="multipart/form-data">';  

    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="tools" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Werkzeuge:</label>';
    echo '<input type="text" id="tools" name="tools" style="width: 200px;" required><br><br>';
    echo '</div>';

    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="zumbriefkasten" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Zustellung:</label>';
    echo '<select id="zumbriefkasten" name="zumbriefkasten" style="width: 200px;" required>';
    echo '<option value="0">Zur Tür</option>';
    echo '<option value="1">In den Briefkasten</option>';
    echo '</select><br><br>';
    echo '</div>';   

    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="starttime" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Startzeit:</label>';
    echo '<input type="datetime-local" id="starttime" name="starttime" style="width: 200px;" required><br><br>';
    echo '</div>';
    
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="endtime" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Endzeit:</label>';
    echo '<input type="datetime-local" id="endtime" name="endtime" style="width: 200px;" required><br><br>';
    echo '</div>';

    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<label for="comment" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Kommentar:</label>';
    echo '<textarea id="comment" name="comment" style="width: 200px; height: 100px;"></textarea><br><br>';
    echo '</div>';
    
    echo '<br><br>';
    echo '<div style="text-align: center; margin-bottom: 10px;">';
    echo '<button type="submit" name="sendpost"  class="center-btn" style="display: block; margin: 0 auto;">Buchen</button>';
    echo '</div>';
    echo '</form>';
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