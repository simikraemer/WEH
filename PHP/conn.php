<?php
$config = json_decode(file_get_contents('/etc/credentials/config.json'), true);

$mysql_config = $config['wehphp'];
$conn = mysqli_connect(
    $mysql_config['host'],
    $mysql_config['user'],
    $mysql_config['password'],
    $mysql_config['database']
);
#mysqli_set_charset($conn,"utf8");

$mysql_wehconfig = $config['wehphp'];
$conn = mysqli_connect(
    $mysql_wehconfig['host'],
    $mysql_wehconfig['user'],
    $mysql_wehconfig['password'],
    $mysql_wehconfig['database']
);
#mysqli_set_charset($conn,"utf8");

$mysql_waschconfig = $config['mysqlphpwasch'];
$waschconn = mysqli_connect(
    $mysql_waschconfig['host'],
    $mysql_waschconfig['user'],
    $mysql_waschconfig['password'],
    $mysql_waschconfig['database']
);
#mysqli_set_charset($waschconn,"utf8");

$mailconfig = $config['mail'];

?>