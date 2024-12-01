<!--
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .error-box {
            width: 80%;
            margin: 0 auto;
            margin-top: 10px;
            border: 20px solid red;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            background-color: #181717;
        }
        
        .error-text {
            color: red;
            font-size: 75px;
            margin: 0;
        }
        
        .error-description {
            color: white;
            font-size: 30px;
            margin: 0;
        }
        
        .error-note {
            color: white;
            font-size: 20px;
            margin: 0;
        }
        
        .back-link {
            font-size: 30px;
        }
    </style>
</head>

<body>
    <p class="error-text"></p>
    <div class="error-box">
        <p class="error-text">Access denied!<br></p>
        <p class="error-description"><br>You either do not have permission to access this page or you are trying to connect from outside of tuermeroam.</p>
        <p class="error-note">(You need to connect from the dormitory!)<br><br></p>
        <p><a href="https://backend.weh.rwth-aachen.de/start.php" class="back-link grey-text">=> To WEH Backend Startpage</a></p>
        <p><a href="https://www2.weh.rwth-aachen.de/" class="back-link grey-text">=> To WEH Public Webpage</a></p>
    </div>
</body>

</html>
-->

<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .error-box {
            width: 80%;
            margin: 0 auto;
            margin-top: 100px;
            border: 20px solid red;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            background-color: #181717;
        }
        
        .error-text {
            color: red;
            font-size: 75px;
            margin: 0;
        }
        
        .error-description {
            color: white;
            font-size: 30px;
            margin: 0;
        }
        
        .error-note {
            color: white;
            font-size: 20px;
            margin: 0;
        }
        
        .back-link {
            font-size: 30px;
        }
    </style>

    </head>

<?php

require('template.php');
mysqli_set_charset($conn, "utf8");
echo "<title>WEH Backend</title>";

#$pwlimit = 8;
#$tanlimit = 5;

# Erste Woche TvK Anmeldung
$pwlimit = 200;
$tanlimit = 200;

// Funktion zum Überprüfen, ob die IP im RWTH-Bereich liegt
function isRWTHIP($ip, $ranges) {
    foreach ($ranges as $range) {
        if (ip_in_range_denied($ip, $range)) {
            return true;
        }
    }
    return false;
}

// Funktion zur Überprüfung, ob die IP in einem bestimmten Bereich liegt
function ip_in_range_denied($ip, $range) {
    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask; // Netzwerkteil der Subnetzmaske

    return ($ip & $mask) == $subnet;
}

function generateRandomTan($length = 6) {
    $characters = '0123456789';
    $tan = '';
    for ($i = 0; $i < $length; $i++) {
        $tan .= $characters[mt_rand(0, strlen($characters) - 1)];
    }
    return $tan;
}

function logFailedAttempt($conn,$type,$uid) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $zeit = time();

    $sql = "INSERT INTO backend_loginfails (uid, ip, tstamp, type) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issi", $uid, $ip, $zeit, $type);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function checkFailedAttemptsAndSendMail($conn, $mailconfig, $pwlimit, $tanlimit) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $time_threshold = time() - (24 * 60 * 60); // 24 Stunden in Sekunden
    $usernamelimit = 10;
    $banned = false;
    $attempt_counts = array();
    $sql_check_attempts = "SELECT type, COUNT(*) FROM backend_loginfails WHERE ip = ? AND tstamp > ? GROUP BY type";
    $stmt_check_attempts = mysqli_prepare($conn, $sql_check_attempts);
    if (!$stmt_check_attempts) {
        die("Error in preparing SQL statement: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt_check_attempts, "si", $ip, $time_threshold);
    if (!mysqli_stmt_execute($stmt_check_attempts)) {
        die("Error executing SQL statement: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_result($stmt_check_attempts, $type, $count);
    $attempt_counts = array();
    while (mysqli_stmt_fetch($stmt_check_attempts)) {
        $attempt_counts[$type] = $count;
    }
    mysqli_stmt_close($stmt_check_attempts);
    
    $sender = $mailconfig['address'];
    $banMessage = "Achtung,\n\n".$ip." hat mehr als ";
    $subject = "WEH Backend - IP Login Sperre";
    $to = "fiji@weh.rwth-aachen.de";
    $headers = "From: " . $sender . "\r\n";
    $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
    
    if (isset($attempt_counts[0]) && $attempt_counts[0] >= $usernamelimit) {
        $banMessage .= $usernamelimit." fehlgeschlagene Benutzername-Versuche für das Backend-Login.\n\n";
        $banned = true;
    } elseif (isset($attempt_counts[1]) && $attempt_counts[1] >= $pwlimit) {
        $banMessage .= $pwlimit." fehlgeschlagene Passwort-Versuche für das Backend-Login.\n\n";
        $banned = true;
    } elseif (isset($attempt_counts[2]) && $attempt_counts[2] >= $tanlimit) {
        $banMessage .= $tanlimit." fehlgeschlagene TAN-Versuche für das Backend-Login.\n\n";
        $banned = true;
    }

    $message = $banMessage .
        "Die IP wurde für einen Tag von allen Backend-Login Schritten gesperrt.\n\n" .
        "denied.php\ni.A. Netzwerk-AG WEH e.V.";

    if ($banned) {
        mail($to, $subject, $message, $headers);
    }

    return $banned;
}

function justCheckFailedAttempts($conn, $pwlimit, $tanlimit) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $time_threshold = time() - (24 * 60 * 60); // 24 Stunden in Sekunden
    $usernamelimit = 10;
    $banned = false;
    $attempt_counts = array();
    $sql_check_attempts = "SELECT type, COUNT(*) FROM backend_loginfails WHERE ip = ? AND tstamp > ? GROUP BY type";
    $stmt_check_attempts = mysqli_prepare($conn, $sql_check_attempts);
    if (!$stmt_check_attempts) {
        die("Error in preparing SQL statement: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt_check_attempts, "si", $ip, $time_threshold);
    if (!mysqli_stmt_execute($stmt_check_attempts)) {
        die("Error executing SQL statement: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_result($stmt_check_attempts, $type, $count);
    $attempt_counts = array();
    while (mysqli_stmt_fetch($stmt_check_attempts)) {
        $attempt_counts[$type] = $count;
    }
    mysqli_stmt_close($stmt_check_attempts);
    
    if (isset($attempt_counts[0]) && $attempt_counts[0] >= $usernamelimit) {
        $banned = true;
    } elseif (isset($attempt_counts[1]) && $attempt_counts[1] >= $pwlimit) {
        $banned = true;
    } elseif (isset($attempt_counts[2]) && $attempt_counts[2] >= $tanlimit) {
        $banned = true;
    }

    return $banned;
}




if (isset($_POST["logout"])) {
    $_SESSION["valid"] = false;
    echo '<br><br><br><br><div style="text-align: center;"><h2 style="display: inline;">Logout successful</h2>';
    echo '<script>
    setTimeout(function() {
        window.location.href = "denied.php";
    }, 2000);
  </script>';
} elseif (auth($conn) && ($_SESSION['valid'])) {
    header('Location: start.php');
} elseif (justCheckFailedAttempts($conn, $pwlimit, $tanlimit)) {
    echo '<body>
        <p class="error-text"></p>
        <div class="error-box">
            <p class="error-text">Banned!<br></p>
            <p class="error-description"><br>Due to multiple unsuccessful login attempts, you have been temporarily banned from accessing backend resources outside the tower network for a day.</p>
            <p><a href="https://www2.weh.rwth-aachen.de/" class="back-link grey-text">=> To WEH Public Webpage</a></p>
        </div>
    </body>';
} else {

    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    $login_with_PW_ip_ranges = array(
        # IT Center Administration Installationsraum [Für Fiji]
        '134.130.0.13/32',        
        '134.130.0.18/32',        
        '134.130.0.68/32',       
        '134.130.0.79/32'
    );    

    $login_with_extra_TAN_ip_ranges = array(
        # Alle 3 Klasse-B Netzwerke der RWTH
        '134.61.0.0/16',
        '137.226.0.0/16',
        '134.130.0.0/16'
    );

    $rwth_ip_ranges = array_merge($login_with_PW_ip_ranges, $login_with_extra_TAN_ip_ranges);


    if (isRWTHIP($user_ip, $rwth_ip_ranges) || $_SERVER['REMOTE_ADDR'] == "137.226.141.203") {
        echo '<div style="text-align: center; margin-top: 100px;">';
        echo '<h2 style="color: white; font-size: 40px; margin-top: 20px;">WEH Backend Login</h2>';
        echo '</div>';
        $usernameErrorMessage = "<br>";
        $passwordErrorMessage = "<br>";
        $tanErrorMessage = "<br>";

        $loginfeld = true;
        $tanfeld = false;
        if (isset($_POST["loginPW"])) {
            $username = $_POST["username"];
            $password = $_POST["password"];
            $sql = "SELECT COUNT(username), uid FROM users WHERE username = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            #mysqli_set_charset($conn, "utf8");
            mysqli_stmt_bind_result($stmt, $count_usernames, $uid);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            if ($count_usernames == 1){
                $usernamefound = true;
            } else {
                $usernamefound = false;
            }
        
            $sql = "SELECT pwhaus FROM users WHERE username = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            #mysqli_set_charset($conn, "utf8");
            mysqli_stmt_bind_result($stmt, $hauspwDB);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            if (checkFailedAttemptsAndSendMail($conn,$mailconfig, $pwlimit, $tanlimit)) {
                $passwordErrorMessage = "<div style='color: red; font-size: 20px;'>Too many failed attempts. Please try again later.</div><br>";
            } elseif (crypt($password,$hauspwDB) == $hauspwDB) {

                $user_ip_inarray = $user_ip . "/32";
                if (in_array($user_ip_inarray, $login_with_PW_ip_ranges)) {
                    $_SESSION["itcenter"] = true;
                    auth_from_outside($conn, $uid);
                    header('Location: start.php');
                } else {
                    $sender = $mailconfig['address'];
                    $tan = generateRandomTan();
                    $sql = "SELECT email, firstname FROM users WHERE uid = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $uid);
                    mysqli_stmt_execute($stmt);
                    #mysqli_set_charset($conn, "utf8");
                    mysqli_stmt_bind_result($stmt, $to, $firstname);
                    mysqli_stmt_fetch($stmt);
                    mysqli_stmt_close($stmt);
                    $message = "Dear " . $firstname . ",\nthis is your tan for logging into the WEH Backend:\n\n" . $tan .
                    "\n\nIf you didn't try to log in, please contact the Netzwerk-AG.\n\n" .
                    "Best Regards\n" .
                    "Netzwerk-AG WEH e.V.";
                    $subject = "WEH Backend - TAN";
                    $headers = "From: " . $sender . "\r\n";
                    $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
                    mail($to, $subject, $message, $headers);
                    $loginfeld = false;
                    $tanfeld = true;
    
                    $emailParts = explode('@', $to);
                    $localPart = $emailParts[0];
                    $maxLength = strlen($localPart) - 3;
                    $zensierteLocalPart = substr_replace($localPart, str_repeat('*', $maxLength), 3);
                    $domain = $emailParts[1];
                    $zensierteaddresse = $zensierteLocalPart . '@' . $domain;
                    $tanErrorMessage = "<div style='color: red; font-size: 20px;'>TAN-Mail was sent to ".$zensierteaddresse."</div><br>";
                }

            } elseif (!$usernamefound) {
                $passwordErrorMessage = "<div style='color: red; font-size: 20px;'>Wrong credentials.</div><br>";
                logFailedAttempt($conn,0,0);
            } else {
                $passwordErrorMessage = "<div style='color: red; font-size: 20px;'>Wrong credentials.</div><br>";
                logFailedAttempt($conn,1,$uid);
            }
        } elseif (isset($_POST["loginTAN"])) {
            $loginfeld = false;
            $tanfeld = true;
            $tan = $_POST["tan"];
            $taneingabe = $_POST["taneingabe"];
            $uid = $_POST["uid"];
            if (checkFailedAttemptsAndSendMail($conn,$mailconfig, $pwlimit, $tanlimit)) {
                $tanErrorMessage = "<div style='color: red; font-size: 20px;'>Too many failed attempts. Please try again later.</div><br>";
            } elseif ($taneingabe === $tan) {
                auth_from_outside($conn, $uid);
                header('Location: start.php');
            } else {
                $tanErrorMessage = "<div style='color: red; font-size: 20px;'>Wrong TAN.</div><br>";
                logFailedAttempt($conn,2,$uid);
            }
        }
        if ($loginfeld) {
            echo '<div style="border: 2px solid white; border-radius: 5px; padding: 5px; background-color: #2a2a2a; width: 500px; margin: 100px auto 0; text-align: center;">';
            echo '<form method="post" enctype="multipart/form-data">';
            echo '<br>';
            echo '<label for="username" style="display: inline-block; width: 100%; color: white; font-size: 25px; text-align: center; margin-bottom: 10px;">Username:</label>';
            echo '<input type="text" id="username" name="username" style="width: 100%; font-size: 25px;" required><br>';
            echo '<br>';
            echo $usernameErrorMessage;
            echo '<label for="password" style="display: inline-block; width: 100%; color: white; font-size: 25px; text-align: center; margin-bottom: 10px;">New House-Password:</label>';
            echo '<input type="password" id="password" name="password" style="width: 100%; font-size: 25px;" required><br>';
            echo '<br>';
            echo $passwordErrorMessage;
            echo '<button type="submit" name="loginPW" class="center-btn" style="display: block; margin: 0 auto; font-size: 30px;">Login</button>';
            echo '</form>';    
            echo '</div>';
        } elseif ($tanfeld) {
            echo '<div style="border: 2px solid white; border-radius: 5px; padding: 5px; background-color: #2a2a2a; width: 500px; margin: 100px auto 0; text-align: center;">';
            echo '<form method="post" enctype="multipart/form-data">';
            echo '<br>';
            echo '<label for="taneingabe" style="display: inline-block; width: 100%; color: white; font-size: 25px; text-align: center; margin-bottom: 10px;">TAN:</label>';
            echo '<input type="text" id="taneingabe" name="taneingabe" style="width: 100%; font-size: 25px;" required><br>';
            echo '<input type="hidden" id="tan" name="tan" value="'.$tan.'">';
            echo '<input type="hidden" id="uid" name="uid" value="'.$uid.'">';
            echo '<br>';
            echo $tanErrorMessage;
            echo '<button type="submit" name="loginTAN" class="center-btn" style="display: block; margin: 0 auto; font-size: 30px;">Enter</button>';
            echo '</form>';    
            echo '</div>';
        }
    } else {
        echo '<body>
            <p class="error-text"></p>
            <div class="error-box">
                <p class="error-text">Access denied!<br></p>
                <p class="error-description"><br>You are attempting to access from outside the authorized networks.</p>
                <p><a href="https://www2.weh.rwth-aachen.de/" class="back-link grey-text">=> To WEH Public Webpage</a></p>
            </div>
        </body>';
    }
}

?>
</body>
</html>