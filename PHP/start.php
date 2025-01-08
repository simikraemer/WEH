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
    <p style="font-size: 20px; color: white; text-align: center;">Alle relevanten Verwaltungsprozesse laufen über diese Seite, 
    die nur aus dem RWTH-Netz erreichbar ist.</p>';


    $functions = [
        'displayRandomCountryWEH' => '$conn',
        'displayRandomCountryTvK' => '$conn',
        'displayRandomContinentWEH' => '$conn',
        'displayRandomContinentTvK' => '$conn',
        'displayAmountPrintedPages' => '$conn',
        'displayAmountUsers' => '$conn',
        'displayWashingSlots' => '$waschconn'
    ];
    $randomFunction = array_rand($functions);
    $connType = $functions[$randomFunction];
    if ($connType == '$conn') {
        $stringie = $randomFunction($conn);
    } else {
        $stringie = $randomFunction($waschconn);
    }    


    echo '
    <div style="
        display: flex; 
        justify-content: center; 
        align-items: center; 
        overflow: hidden; 
        width: 100%; 
        height: auto;
    ">
        <p style="
            font-size: 30px; 
            color: white; 
            text-align: center; 
            animation: zoom-in-out 3s infinite;
            display: inline-block;
        ">Fun-Fact:<br>' . $stringie . '</p>
    </div>
    <style>
    @keyframes zoom-in-out {
        0% {
            transform: scale(1.2);
        }
        50% {
            transform: scale(1.5);
        }
        100% {
            transform: scale(1.2);
        }
    }
    </style>';

    displayRundmails($conn);


} else {
    header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>
</body>
</html>
