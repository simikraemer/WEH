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
if (auth($conn) && $_SESSION['SpieleAG']) {
    load_menu();

    $mysqltitle = "SpieleAGCode";  

    $currentCode = "";
    $stmt = $conn->prepare("SELECT code FROM codes WHERE title = ? AND active = 1 LIMIT 1");
    $stmt->bind_param("s", $mysqltitle);
    $stmt->execute();
    $stmt->bind_result($currentCode);
    $stmt->fetch();
    $stmt->close();

    $message = "";
    $messageColor = "white"; // Default fallback

    if (isset($_POST["newCode"])) {  
        $newCode = $_POST["newCode"];

        if (trim($newCode) === trim($currentCode)) {
            $message = "Der neue Code ist identisch mit dem aktuellen Code. Kein Update erforderlich.";
            $messageColor = "grey";
        } else {
            // Zuerst alle bisherigen Codes für diesen Titel deaktivieren
            $sql = "UPDATE codes SET active = 0 WHERE title = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $mysqltitle);
            $stmt->execute();
            $stmt->close();

            // Dann den neuen Code einfügen
            $insert_sql = "INSERT INTO weh.codes 
            (uid, tstamp, title, code) 
            VALUES (?, ?, ?, ?)";

            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("iiss", 
                $_SESSION["uid"],       
                time(),                 
                $mysqltitle,            
                $newCode     
            );

            if ($stmt->execute()) {
                $message = "Neuer Code wurde erfolgreich gespeichert.";
                $messageColor = "green";
                $currentCode = $newCode; // Damit das neue auch direkt im Feld steht
            } else {
                $message = "Fehler beim Speichern des Codes: " . $stmt->error;            
                $messageColor = "red";
            }

            $stmt->close();
        }
    }

    echo '
    <div class="center-table-container" style="margin-top: 40px;">
        <form method="POST" style="text-align: center;">
            <input 
                type="text" 
                id="newCode" 
                name="newCode" 
                value="' . htmlspecialchars($currentCode) . '" 
                style="padding: 10px; font-size: 16px; width: 250px; margin-bottom: 20px;" 
            />
            <div style="display: flex; justify-content: center;">
                <button 
                    type="submit" 
                    class="center-btn" 
                    style="padding: 10px 20px; font-size: 16px; cursor: pointer;"
                >
                    Code aktualisieren
                </button>
            </div>
        </form>';
    
    if (!empty($message)) {
        echo '<p style="text-align: center; margin-top: 20px; font-size: 16px; color: ' . $messageColor . ';">' . htmlspecialchars($message) . '</p>';
    }
    
    echo '</div>';
    
}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>