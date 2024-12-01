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
if (auth($conn) && ($_SESSION['PartyAG'] || $_SESSION["Webmaster"])) {
    load_menu();

    $sql = "SELECT users.uid, users.firstname, users.lastname, users.room, users.oldroom, alumnimail.email, users.endtime, users.honory
    FROM alumnimail
    INNER JOIN users ON users.uid = alumnimail.uid 
    ORDER BY users.uid";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Error: " . mysqli_error($conn));
    }
    if (!mysqli_stmt_execute($stmt)) {
        die("Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_store_result($stmt);
    mysqli_stmt_bind_result($stmt, $uid, $firstname, $lastname, $room, $oldroom, $email, $endtime, $honory);
    $users_by_stellplatz = array();
    while (mysqli_stmt_fetch($stmt)){
      $users_by_stellplatz[$uid][] = array("uid" => $uid, "firstname" => $firstname, "lastname" => $lastname, "room" => $room, "oldroom" => $oldroom, "email" => $email, "endtime" => $endtime, "honory" => $honory);
    }
    mysqli_stmt_free_result($stmt);
    mysqli_stmt_close($stmt);      
    
    echo '<h2 class="center">Den Alumni-Newsletter erreicht ihr mit einer Mail von einer WEH/TvK-Mailadresse an alumni-list@weh.rwth-aachen.de</h2>';
    echo "<br>";
    echo '<div style="text-align: center;">';
    echo '<span style="font-size: 40px; color:white;">Empfänger:</span>';
    echo '</div>';
    echo '<h3 class="center">Wenn ihr Einträge ändern wollt, meldet euch bei der Netzwerk-AG :-)</h3>';
    echo '<table class="grey-table">';
    echo '<tr><th>UID</th><th>Name</th><th>Raum</th><th>Austritt</th><th>E-Mail</th></tr>';
    foreach ($users_by_stellplatz as $uid => $users) {
        foreach ($users as $user) {
            echo '<tr>';
            echo '<td>' . $user["uid"] . '</td>';
    
            // Combine first and last name into a single column
            $name = explode(" ", $user["firstname"])[0] . " " . explode(" ", $user["lastname"])[0];
            echo '<td>' . $name . '</td>';
            echo '<td>' . ($user["room"] !== 0 ? $user["room"] : $user["oldroom"]) . '</td>';
    
            if ($user["honory"]==1) {
                echo '<td>Ehrenmitglied</td>';
            } elseif ($user["endtime"]==0) {
                echo '<td>Mitglied</td>';
            } else {
                $endtimeFormatted = date('d.m.Y', $user["endtime"]);
                echo '<td>' . $endtimeFormatted . '</td>';
            }
    
            echo '<td>' . $user["email"] . '</td>';
            echo '</tr>';
        }
    }
    
    echo "</table>";    
        
}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();

?>
</body>
</html>
