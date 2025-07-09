<?php
session_start();

require('template.php');
// Verarbeite POST-Daten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["update_session"])) {
        foreach ($_POST['session_values'] as $key => $value) {
            // Nur nicht-Array-Werte speichern
            if (!is_array($_SESSION[$key])) {
                $_SESSION[$key] = $value;
            }
        }
    
        // Redirect zur selben Seite, um die Änderungen sichtbar zu machen
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if (isset($_POST["toggle_ag"])) {
        $sessionKey = $_POST["toggle_ag"];
        $_SESSION[$sessionKey] = !isset($_SESSION[$sessionKey]) || !$_SESSION[$sessionKey];

        // Umleitung zur Vermeidung von POST-Resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST["set_all_sessions"])) {
        // Setzt alle AG-Sessions auf true
        foreach ($ag_complete as $num => $data) {
            $agName = $data['session'];
            $_SESSION[$agName] = true;
        }

        // Umleitung nach der Verarbeitung der POST-Daten
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if (isset($_POST["unset_session"])) {
        $agToUnset = $_POST["ag_name"];
        $_SESSION[$agToUnset] = false;

        // Umleitung nach der Verarbeitung der POST-Daten
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">

    <script>
    function updateButton(button) {
        button.style.backgroundColor = "green";
        button.innerHTML = "Saved";
        setTimeout(function() {
            button.style.backgroundColor = "";
            button.innerHTML = "Speichern";
        }, 2000);
    }
    </script>
</head>
<?php
mysqli_set_charset($conn, "utf8");

if (auth($conn) && $_SESSION["Webmaster"]) {
    load_menu();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'turmchoice_weh') {
                $_SESSION["turm"] = 'weh';
            } elseif ($_POST['action'] === 'turmchoice_tvk') {
                $_SESSION["turm"] = 'tvk';
            }
        }
    }
    
    $turm = isset($_SESSION["turm"]) ? $_SESSION["turm"] : 'weh';
    $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
    $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
    echo '<div style="display:flex; justify-content:center; align-items:center;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; margin-right:10px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
    echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; margin-right:10px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
    echo '</form>';
    echo '</div>';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
    </head>


    <div style="margin-bottom: 30px;"></div>    
    <hr>
    <div style="margin-top: 40px;"></div>

    <body>

    




<div class="ag-toggle-container">
    <?php foreach ($ag_complete as $num => $data) : 
        $isActive = isset($_SESSION[$data["session"]]) && $_SESSION[$data["session"]] === true;
        ?>
        <form method="POST" action="">
            <input type="hidden" name="toggle_ag" value="<?php echo htmlspecialchars($data["session"]); ?>">
            <button 
                type="submit" 
                class="ag-toggle-btn <?php echo $isActive ? 'on' : ''; ?>">
                <?php echo htmlspecialchars($data["name"]); ?>
            </button>
        </form>
    <?php endforeach; ?>
</div>






        <div style="margin-bottom: 30px;"></div>
        <hr>
        <div style="margin-top: 40px;"></div>

        <!-- Tabelle mit allen aktiven Sessions und Button zum Zurücksetzen
        <div style="text-align: center;">
            <h2 style="color: white;">AG-Sessions deaktivieren</h2>
            <table style="margin: 0 auto; color: white; font-size: 18px; border-collapse: collapse; text-align: center;">
                <thead>
                    <tr>
                        <th style="padding: 10px; border: 1px solid white;">AG Name</th>
                        <th style="padding: 10px; border: 1px solid white;">Session Status</th>
                        <th style="padding: 10px; border: 1px solid white;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ag_complete as $num => $data): ?>
                    <?php if (isset($_SESSION[$data["session"]]) && $_SESSION[$data["session"]] == true): ?>
                        <tr>
                            <td style="padding: 10px; border: 1px solid white;"><?php echo $data["name"]; ?></td>
                            <td style="padding: 10px; border: 1px solid white;">Aktiv</td>            
                            <td style="padding: 10px; border: 1px solid white; vertical-align: middle;">
                                <form method="POST" action="">
                                    <input type="hidden" name="ag_name" value="<?php echo $data["session"]; ?>">
                                    <br><button type="submit" name="unset_session" class="red-center-btn" style="font-size: 16px;">Session deaktivieren</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        

        <div style="margin-bottom: 30px;"></div>
        <hr>
        <div style="margin-top: 40px;"></div> -->

        <div style="text-align: center;">
            <h2 style="color: white;">Nonchalante Übersicht aller Session Variablen</h2>
            <form method="post">
                <input type="submit" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;" name="update_session" value="Save Changes">
                <br><br>
                <table border="1" style="margin: 0 auto; color: white; text-align: center;">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($_SESSION as $key => $value) {
                            // Arrays überspringen
                            if (is_array($value)) {
                                continue;
                            }
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($key) . "</td>";
                            echo "<td><input type='text' name='session_values[" . htmlspecialchars($key) . "]' value='" . htmlspecialchars($value) . "'></td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>

    <div style="margin-bottom: 30px;"></div>    
    <hr>
    <div style="margin-top: 40px;"></div>

    <!-- Button zum Setzen aller Sessions -->
    <div style="text-align: center;display: flex; justify-content: center;">
        <form method="POST" action="">
            <button type="submit" class="center-btn" style="font-size: 50px;" name="set_all_sessions">Alle Sessions setzen</button>
        </form>
    </div>

    <div style="margin-bottom: 30px;"></div>


    </body>
    </html>
    
    <?php
    } else {
        header("Location: denied.php");
        exit();
    }
    
    // Schließe die Datenbankverbindung
    $conn->close();
    ?>