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
if (auth($conn) && $_SESSION['valid'] && ($_SESSION['SportAG'] || $_SESSION['Webmaster'])) {
    load_menu();
    $zeit = time();
    
    

    // Userdaten abrufen
    $sql = "SELECT u.username, u.name, u.room, u.turm, f.status 
            FROM fitness f 
            JOIN users u ON f.uid = u.uid 
            WHERE u.pid NOT IN (13,14)
            ORDER BY FIELD(u.turm, 'weh', 'tvk'), u.room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $username, $name, $room, $turm, $status);

    // Start HTML-Ausgabe
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Confirmed Fitness Users</title>
        <link rel="stylesheet" href="WEH.css">
    </head>
    <body>
        <h2 class="center" style="margin-bottom: 30px;">Confirmed Fitness Users</h2>
        <table class="grey-table" style="margin: auto; font-size: 18px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Room</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

    while (mysqli_stmt_fetch($stmt)) {
        $roomDisplay = htmlspecialchars(formatTurm($turm) . ' ' . $room);
        $statusText = ($status == 1)
            ? '✅ Confirmed'
            : '🕒 Awaiting confirmation';
        $mailto = 'mailto:' . htmlspecialchars($username . '@' . $turm . '.rwth-aachen.de');
    
        echo '<tr onclick="window.location.href=\'' . $mailto . '\'" style="cursor: pointer;">
                <td>' . htmlspecialchars($name) . '</td>
                <td>' . $roomDisplay . '</td>
                <td>' . $statusText . '</td>
            </tr>';
    }
            

    echo '  </tbody>
        </table>
    </body>
    </html>';

    mysqli_stmt_close($stmt);
    $conn->close();

    

    $conn->close();
} else {
    header("Location: denied.php");
}
?>
</body>
</html>