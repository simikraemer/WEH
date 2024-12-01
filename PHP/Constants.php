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
if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();
    
    echo '<div style="text-align: center; color: white; font-size: 25px;">';

    
    $dezimalnamen = array(
        "hausbeitrag" => "Hausbeitrag",
        "netzbeitrag" => "Netzbeitrag",
        "druckkosten" => "Druckkosten pro Einheit",
        "abmeldekosten" => "Abmeldegebühr",
        "essen_pp" => "AG-Essen Budget p.P",
        "waschpreisaktiv" => "Waschmarkenkosten für Aktive",
        "waschpreisnichtaktiv" => "Waschmarkenkosten für Nichtaktive",
    );

    $intnamen = array(
        "standard_ips" => "Standardanzahl NAT-IPs",
        "essen_count" => "AG-Essen Mindestteilnehmer",
        "eilendersfluch" => "Eilender's Fluch",
    );

    $kassennamen = array(
        "kasse_netz1" => "Netzkasse 1",
        "kasse_netz2" => "Netzkasse 2",
        "kasse_wart1" => "Kassenwart 1",
        "kasse_wart2" => "Kassenwart 2",
        "kasse_tresor" => "Tresor"
    );

	if (isset($_POST["submit"])) {
        $updateData = array();
        foreach ($dezimalnamen as $name => $string) {
            $updateData[$name] = array(
                'wert' => $_POST[$name],
                'dezimal' => true,
            );
        }    
        foreach ($kassennamen as $name => $string) {
            $updateData[$name] = array(
                'wert' => $_POST[$name],
                'dezimal' => false,
            );
        }
        foreach ($intnamen as $name => $string) {
            $updateData[$name] = array(
                'wert' => $_POST[$name],
                'dezimal' => false,
            );
        }
        
        foreach ($updateData as $name => $data) {
            $sql = "UPDATE constants SET wert = ? WHERE name = ?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($data['dezimal']) {
                $wert = floatval($data['wert']);
                mysqli_stmt_bind_param($stmt, "ds", $wert, $name);
            } else {
                $wert = intval($data['wert']);
                mysqli_stmt_bind_param($stmt, "is", $wert, $name);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

		echo "<span style='color: green; font-size: 20px;'>Gespeichert.</span><br><br>";
	}

    $sql = "SELECT name, wert, beschreibung FROM constants";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $wert, $beschreibung);
    $konstanten_array = array();
    while (mysqli_stmt_fetch($stmt)) {
        $konstanten_array[$name] = array(
            'name' => $name,
            'wert' => $wert,
            'beschreibung' => $beschreibung
        );
    }
    mysqli_stmt_close($stmt);

    $sql = "SELECT name, uid, room FROM users WHERE pid = 11 AND (groups LIKE '%,9%' OR groups LIKE '%,7%') ORDER BY room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $uid, $room);
    while (mysqli_stmt_fetch($stmt)) {
        $users_array[$name] = array(
            'name' => $name,
            'uid' => $uid,
            'room' => $room
        );
    }
    mysqli_stmt_close($stmt);

    $sql = "SELECT name, uid, room FROM users WHERE pid = 11 AND (groups LIKE '%,9%') ORDER BY room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $uid, $room);
    while (mysqli_stmt_fetch($stmt)) {
        $vorst_users_array[$name] = array(
            'name' => $name,
            'uid' => $uid,
            'room' => $room
        );
    }
    mysqli_stmt_close($stmt);

    echo "<form method='post'>";
    echo "<table class='userpage-table'>";
    echo "<thead><tr><th>Name</th><th>Beschreibung</th><th>Wert</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($konstanten_array as $name => $data) {
        echo "<tr>";
        if (array_key_exists($name, $intnamen)) {
            echo "<td style='max-width: 250px;'>" . $intnamen[$name] . "</td>";
            echo "<td style='max-width: 500px;'>" . $data['beschreibung'] . "</td>";
            echo "<td><input type='number' name='$name' value='{$data['wert']}' style='font-size: 20px; max-width: 250px;'></td>";
        } elseif (array_key_exists($name, $dezimalnamen)) {
            echo "<td style='max-width: 250px;'>" . $dezimalnamen[$name] . "</td>";
            echo "<td style='max-width: 500px;'>" . $data['beschreibung'] . "</td>";
            echo "<td><input type='number' name='$name' value='{$data['wert']}' step='any' style='font-size: 20px; max-width: 250px;'></td>";
        } elseif (array_key_exists($name, $kassennamen)) {
            echo "<td style='max-width: 250px;'>" . $kassennamen[$name] . "</td>";
            echo "<td style='max-width: 500px;'>" . $data['beschreibung'] . "</td>";
            echo "<td>";
            echo "<select name='$name' style='font-size: 20px; max-width: 250px;'>";
            if ($name != "schriftführer") {
                foreach ($users_array as $user) {
                    $selected = ($user['uid'] == $data['wert']) ? 'selected' : '';
                    echo "<option value='{$user['uid']}' $selected>{$user['name']} [{$user['room']}]</option>";
                }
            } else {
                foreach ($vorst_users_array as $user) {
                    $selected = ($user['uid'] == $data['wert']) ? 'selected' : '';
                    echo "<option value='{$user['uid']}' $selected>{$user['name']} [{$user['room']}]</option>";
                }
            }
            echo "</select>";
            echo "</td>";
        }
        
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";   
    echo "<br><br><input type='submit' name='submit' style='margin: 0 auto; display: inline-block; font-size: 25px;' class='center-btn' value='Speichern'>";
    echo "</form>";

    
    echo '</div>';
    echo '<br><br>';

}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>
</body>
</html>