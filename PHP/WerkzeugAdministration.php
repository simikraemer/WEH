<?php
  session_start();
?>
<!DOCTYPE html>
<!-- Fiji September 2023 -->
<!-- Für den WEH e.V. -->

<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["WerkzeugAG"])) {
    load_menu();


    if (isset($_POST["id_update"]) && $_POST["id_update"] == "Speichern") {
        $id = $_POST['id2'];
        $comment = $_POST['comment'];
      
        $sql = "UPDATE werkzeugbuchung SET comment=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $comment, $id);
        mysqli_stmt_execute($stmt);
        $stmt->close();
      
        echo "<p style='color:green; text-align:center;'>Änderungen erfolgreich eingetragen.</p>";
    }

    $sql = "SELECT w.id, (SELECT u.name FROM users u WHERE u.uid = w.uid) AS name, (SELECT u.room FROM users u WHERE u.uid = w.uid) AS room, w.tstamp, w.tools, w.starttime, w.endtime, w.zumbriefkasten, w.comment
    FROM werkzeugbuchung w
    ORDER BY w.tstamp DESC;
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    #mysqli_set_charset($conn, "utf8");
    mysqli_stmt_bind_result($stmt, $id, $name, $room, $tstamp, $tools, $starttime, $endtime, $zumbriefkasten, $comment);

    $drinks = array(); 
    while (mysqli_stmt_fetch($stmt)) {
        $drinks[] = array(
            "id" => $id,
            "name" => $name . " (" . $room . ")",
            "tstamp" => $tstamp,
            "tools" => $tools,
            "starttime" => $starttime,
            "endtime" => $endtime,
            "zumbriefkasten" => $zumbriefkasten,
            "comment" => $comment
        );
    }
    echo '<form method="post" name="lager">';
    echo '<table class="grey-table">
            <tr>
                <th>ID</th>
                <th>Name (Room)</th>
                <th>Buchungszeit</th>
                <th>Werkzeuge</th>
                <th>Button</th>
            </tr>';
    
    foreach ($drinks as $drink) {
        $id = $drink["id"];
        $name = htmlspecialchars($drink["name"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        $tstamp = ($drink["tstamp"] !== null) ? date("d.m.Y H:i", $drink["tstamp"]) : "N/A";
        $tools = $drink["tools"];
    
        echo "<tr>";
        echo "<td>$id</td>";
        echo "<td>$name</td>";
        echo "<td>$tstamp</td>";
        echo "<td>$tools</td>";
        echo "<td>
                <form method='post' action=''>
                    <button type='submit' name='id' value='$id' class='center-btn' style='margin: 0 auto; display: inline-block; font-size: 20px;'>Edit</button>
                </form>
              </td>";
        echo "</tr>";
    }
    
    echo '</table>';
    echo "</form>";

    if (isset($_POST["id"])) {
        
        $id = $_POST['id'];
    
        $sql = "SELECT uid, tstamp, tools, starttime, endtime, zumbriefkasten, comment FROM werkzeugbuchung WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        #mysqli_set_charset($conn, "utf8");
        mysqli_stmt_bind_result($stmt, $uid, $tstamp, $tools, $starttime, $endtime, $zumbriefkasten, $comment);
        mysqli_stmt_fetch($stmt);
        
        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post"">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <br>');
        echo "<div style='text-align: center;'>";
        echo "<table class='grey-table'>";
        echo "<form method='post'>";      
        echo "<input type='hidden' name='id2' value='$id'>";
        echo "<div style='width: 100%; text-align: center;'>";
        echo "<div style='display: inline-block;'>";
        echo "<input type='submit' name='id_update' class='center-btn' value='Speichern'>";
        echo "</div></div><br><br>";
        echo '<tr><td>UID</td><td><input type="text" name="uid" style="width: 200px; height: 30px;" value="' . $uid . '" readonly></td></tr>';
        echo '<tr><td>Zeitstempel</td><td><input type="text" name="tstamp" style="width: 200px; height: 30px;" value="' . date("d.m.Y H:i", $tstamp) . '" readonly></td></tr>';
        echo '<tr><td>Werkzeuge</td><td><input type="text" name="tools" style="width: 200px; height: 30px;" value="'.$tools.'" readonly></td></tr>';
        echo '<tr><td>Startzeit</td><td><input type="text" name="starttime" style="width: 200px; height: 30px;" value="'.date("d.m.Y H:i", $starttime).'" readonly></td></tr>';
        echo '<tr><td>Endzeit</td><td><input type="text" name="endtime" style="width: 200px; height: 30px;" value="'.date("d.m.Y H:i", $endtime).'" readonly></td></tr>';
        echo '<tr><td>Lieferung</td><td><input type="text" name="zumbriefkasten" style="width: 200px; height: 30px;" value="'.($zumbriefkasten == 1 ? "Zum Briefkasten" : "Zur Tür").'" readonly></td></tr>';
        echo '<tr><td>Kommentar</td><td><textarea name="comment" style="width: 200px; height: 100px;">'.$comment.'</textarea></td></tr>';
        echo "</form>";
        echo "</table>";
        echo "</div>";
        echo "</div>";
    
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

