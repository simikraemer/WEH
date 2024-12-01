<?php
  session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eilender's Fluch</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");


if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();

    
    echo '<div style="text-align: center;">';
    
    if (isset($_POST["fluchbeenden"])) {
        $id = $_POST["fluchbeenden"];
        $sql = "UPDATE eilendersfluch SET aufgehoben = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            echo "<p style='color: green;'>Der Fluch wurde erfolgreich aufgehoben.</p>";
        } else {
            echo "<p style='color: red;'>Fehler: Der Fluch konnte nicht aufgehoben werden.</p>";
        }
        mysqli_stmt_close($stmt);
    } elseif (isset($_POST["exec_add"])) {
        $uid = $_POST["uid"];
        $beschreibung = $_POST["beschreibung"];
        $zeit = time();
        $sql = "INSERT INTO eilendersfluch (uid, beschreibung, tstamp) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'isi', $uid, $beschreibung, $zeit);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            echo "<p style='color: green;'>Der User wurde erfolgreich verflucht.</p>";
        } else {
            echo "<p style='color: red;'>Fehler: Der User konnte nicht verflucht werden.</p>";
        }
        mysqli_stmt_close($stmt);
    }

    echo('<form method="post">');
    echo '<button type="submit" name="verfluchen" value="0" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">User verfluchen</button><br>';
    echo('</form>');
    echo '</div>';

    echo "<br><br><hr><br><br>";
            
    $sql = "SELECT e.id, u.name, e.tstamp, e.beschreibung, u.room, u.turm
    FROM eilendersfluch e JOIN users u ON e.uid = u.uid
    WHERE e.aufgehoben = 0
    ORDER BY u.room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $tstamp, $beschreibung, $room, $turm);
    
    $users_array = [];
    while (mysqli_stmt_fetch($stmt)) {
        $users_array[] = [
            'id' => $id,
            'name' => $name,
            'tstamp' => $tstamp,
            'beschreibung' => $beschreibung,
            'room' => $room,
            'turm' => $turm
        ];
    }
    mysqli_stmt_close($stmt);
    
    echo "<table class='userpage-table'>";
    echo "<thead><tr><th>Raum</th><th>Turm</th><th>Name</th><th>Beschreibung</th><th>Eintragungsdatum</th><th>Aufheben</th></tr></thead>";
    echo "<tbody>";
    foreach ($users_array as $user) {
        echo "<tr>";
        echo "<td style='max-width: 150px;'>" . $user['room'] . "</td>";
        echo "<td style='max-width: 150px;'>" . $user['turm'] . "</td>";
        echo "<td style='max-width: 250px;'>" . $user['name'] . "</td>";
        echo "<td style='max-width: 500px;'>" . $user['beschreibung'] . "</td>";
        echo "<td style='max-width: 250px;'>" . date('d.m.Y', $user['tstamp']) . "</td>";
        echo '<td>';
        echo '<form method="post" action="">';
        echo '<button type="submit" name="fluchbeenden" value="' . $user['id'] . '" class="red-center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">Aufheben</button>';
        echo '</form>';
        echo '</td>';
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    if (isset($_POST["verfluchen"])) {
        echo '<div class="overlay"></div>';
        echo '<div class="anmeldung-form-container form-container">';
        echo '<form method="post">';
        echo '<button type="submit" name="close" value="close" class="close-btn">X</button>';
        echo '</form><br>';
        echo '<div style="text-align: center; justify-content: center; align-items: center;">';
        echo '<form method="post">';
        
        echo '<label for="uid" style="color: white; font-size: 25px;">User: </label>';
        echo '<select name="uid" style="margin-top: 20px; font-size: 20px; text-align: center;">';
    
        $sql = "SELECT uid, name, room, turm FROM users WHERE pid = 11 ORDER BY FIELD(turm, 'weh', 'tvk'), room";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $listuid, $name, $room, $turm);
        while (mysqli_stmt_fetch($stmt)) {
            $formatted_turm = ($turm == 'tvk') ? 'TvK' : strtoupper($turm);
            echo '<option value="' . $listuid . '">' . $name . ' [' .$formatted_turm . ' ' . $room . ']</option>';
        }
        echo '</select><br>';
    
        echo '<label for="beschreibung" style="color: white; font-size: 25px;">Beschreibung: </label>';
        echo '<input type="text" id="beschreibung" name="beschreibung" style="margin-top: 20px; font-size: 20px; text-align: center;"><br>';
    
        echo '<div style="text-align: center; margin-top: 20px;">';
        echo '<input type="submit" name="exec_add" class="center-btn" value="Verfluchen" style="display: inline-block;">';    
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
    
    

}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>