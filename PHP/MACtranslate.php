<?php
  session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Translate MAC</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");


if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $mac_dots = $_POST['mac_dots'] ?? '';
        $mac_colons = $_POST['mac_colons'] ?? '';

        if (!empty($mac_dots)) {
            $mac_colons = str_replace('.', '', $mac_dots);
            $mac_colons = chunk_split($mac_colons, 2, ':');
            $mac_colons = rtrim($mac_colons, ':');
        } elseif (!empty($mac_colons)) {
            $mac_dots = str_replace(':', '', $mac_colons);
            $mac_dots = chunk_split($mac_dots, 4, '.');
            $mac_dots = rtrim($mac_dots, '.');
        }
    }


    echo '<div style="text-align: center; color: white; padding: 20px;">';
    echo '<form method="post" action="">';
    echo '<label for="mac_dots">MAC mit Punkten (z.B. 2c54.2dd2.d83f):</label><br>';
    echo '<input type="text" id="mac_dots" name="mac_dots" value="' . htmlspecialchars($mac_dots ?? '') . '" style="color: black;"><br><br>';
    echo '<label for="mac_colons">MAC mit Doppelpunkten (z.B. 2c:54:2d:d2:d8:3f):</label><br>';
    echo '<input type="text" id="mac_colons" name="mac_colons" value="' . htmlspecialchars($mac_colons ?? '') . '" style="color: black;"><br><br>';
    echo '<button type="submit">Umformen</button>';
    echo '</form>';
    echo '</div>';

}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>