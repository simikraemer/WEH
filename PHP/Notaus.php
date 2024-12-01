<?php
  session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiji Notaus</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>

        /* Regenbogen-Hintergrund und animierter Cursor */
        body {
            background: linear-gradient(90deg, red, orange, yellow, green, blue, indigo, violet);
            background-size: 400% 400%;
            color: white;
            animation: rainbow 10s ease infinite, cursorChange 10s infinite;
        }

        @keyframes rainbow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Animierter Standard-Cursor mit mehreren Cursor-Typen */
        @keyframes cursorChange {
            0% { cursor: default; }        /* Standard */
            10% { cursor: pointer; }       /* Zeigefinger */
            20% { cursor: move; }          /* Bewegung */
            30% { cursor: crosshair; }     /* Fadenkreuz */
            40% { cursor: text; }          /* Textauswahl */
            50% { cursor: wait; }          /* Sanduhr */
            60% { cursor: help; }          /* Hilfe (Fragezeichen) */
            70% { cursor: progress; }      /* Fortschritt */
            80% { cursor: not-allowed; }   /* Nicht erlaubt */
            90% { cursor: col-resize; }    /* Spalten-Resize */
            100% { cursor: zoom-in; }      /* Vergrößern */
        }

        @keyframes bounce {
            0%, 100% { top: 40px; }
            50% { top: -60px; }
        }


/* Schalter-Design */
.switch {
    position: relative;
    display: inline-block;
    width: 120px; /* doppelt so groß wie zuvor */
    height: 68px; /* doppelt so groß wie zuvor */
    animation: rotateSwitch 1s linear infinite; /* Schalter rotiert */
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

/* Slider-Design */
.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #11a50d;
    -webkit-transition: .4s;
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 52px; /* doppelt so groß wie zuvor */
    width: 52px; /* doppelt so groß wie zuvor */
    left: 8px;
    bottom: 8px;
    background-color: white;
    -webkit-transition: .4s;
    transition: .4s;
}

input:checked + .slider {
    background-color: red; /* Hintergrund wird rot bei aktiviertem Schalter */
}

input:checked + .slider:before {
    -webkit-transform: translateX(52px); /* Slider slidet rüber */
    -ms-transform: translateX(52px);
    transform: translateX(52px);
}

/* Schalter rotiert */
@keyframes rotateSwitch {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

    </style>
</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();
    
    if (isset($_POST["execAction"])) {
        if ($_POST["execAction"] == "exec_release") {
            $sql = "UPDATE macauth SET sublet = 0 WHERE sublet = 2";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } elseif ($_POST["execAction"] == "exec_notaus") {
            $sql = "UPDATE macauth m JOIN users u ON m.uid = u.uid SET m.sublet = 2 WHERE m.sublet = 0 AND u.pid = 11 AND u.groups IN ('1','1,19')";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
            
        shell_exec('sudo /etc/credentials/fijinotaus.sh 2>&1');
    }

    $sql = "SELECT COUNT(uid) FROM users WHERE groups IN ('1','1,19') AND pid = 11";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $users2ban_count);  
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);    
    
    $sql = "SELECT sublet FROM macauth WHERE sublet = 2";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $notaus_aktiv = (mysqli_stmt_num_rows($stmt) > 0);
    mysqli_stmt_close($stmt);

    echo '<div style="width: 70%; margin: 50px auto 0; text-align: center; color: white; font-size: 40px;">';
    if ($notaus_aktiv) {
        echo '<div style="position: relative; animation: bounce 2s infinite, disappear 2s infinite;">
                Entsperrt Zugriff von '.$users2ban_count.' Usern.
              </div>';
    } else {
        echo '<div style="position: relative; animation: bounce 2s infinite, disappear 2s infinite;">
                Sperrt IP-Vergabe an '.$users2ban_count.' nicht-aktive User mit sofortiger Wirkung.
              </div>';
    }    
    echo '</div>';

    echo '<div style="display: flex; justify-content: center; align-items: center; height: 20vh;">
        <form method="post" id="notausForm">
            <label class="switch">
                <input type="checkbox" id="notaus" name="notaus" ' . ($notaus_aktiv ? 'checked' : '') . ' onchange="togglePost()">
                <span class="slider"></span>
            </label>
            <input type="hidden" id="execAction" name="execAction" value="">
        </form>
    </div>
    <script>
        function togglePost() {
            var form = document.getElementById("notausForm");
            var execActionInput = document.getElementById("execAction");

            if (document.getElementById("notaus").checked) {
                execActionInput.value = "exec_notaus";
            } else {
                execActionInput.value = "exec_release";
            }

            form.submit();
        }
    </script>';
}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>
