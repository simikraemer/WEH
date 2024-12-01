<?php
session_start();
?>
<!DOCTYPE html>

<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();
    
    echo '<h1 style="font-size: 60px; color: white; text-align: center;">Willkommen im Türme Backend</h1>
    <br>
    <p style="font-size: 30px; color: white; text-align: center;">Alle relevanten Verwaltungsprozesse laufen über diese Seite, 
    die nur aus dem RWTH-Netz erreichbar ist.</p>';

    displayRundmails($conn);


} else {
    header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>
</body>
</html>
