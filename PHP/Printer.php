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
if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();

    $drucker = [
        ["id" => 1, "turm" => "WEH", "farbe" => "Schwarz-Weiß", "modell" => "Kyocera ECOSYS P3260dn"],
        ["id" => 2, "turm" => "WEH", "farbe" => "Farbe", "modell" => "Kyocera ECOSYS M8124cidn"],
        ["id" => 3, "turm" => "TvK", "farbe" => "Schwarz-Weiß", "modell" => "Firma MODEL XXX"]
    ];
    
    $nutzerTurm = strtolower($_SESSION["turm"] ?? "");
    
    // Drucker nach Türmen sortieren
    $weh_drucker = array_filter($drucker, fn($d) => strtolower($d["turm"]) === "weh");
    $tvk_drucker = array_filter($drucker, fn($d) => strtolower($d["turm"]) === "tvk");

    // Überschriften für WEH und TvK in einer eigenen Zeile, aber in zwei Spalten
    echo '<div style="display: flex; width: 100vw; justify-content: center; align-items: center;">';
        echo '<div style="flex: 1; text-align: center;">';
            echo '<h2 style="color: #11a50d; font-size: 100px;">WEH</h2>';
        echo '</div>';
        echo '<div style="flex: 1; text-align: center;">';
            echo '<h2 style="color: #E49B0F; font-size: 100px;">TvK</h2>';
        echo '</div>';
    echo '</div>';

    // Haupt-Container für die Buttons
    echo '<div style="display: flex; width: 100vw; height: 30vh; justify-content: center; align-items: center;">';
    
    // Linke Spalte (WEH)
    echo '<div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">';
    foreach ($weh_drucker as $d) {
        $disabledClass = ($nutzerTurm !== strtolower($d["turm"])) ? "grayed-out" : "";
        $buttonStyle = "font-size: 30px; display: flex; flex-direction: column; padding: 10px;";
        if (!$disabledClass) $buttonStyle .= " justify-content: center; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s; cursor: pointer;";
        
        echo '<form method="POST" style="margin: 10px 0;">';
        echo '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
        echo '<input type="hidden" name="d_selected" value="true">';
        echo '<button type="submit" class="' . $disabledClass . '" style="' . $buttonStyle . '"';
        if (!$disabledClass) echo ' onmouseover="this.style.backgroundColor=\'#11a50d\';" onmouseout="this.style.backgroundColor=\'#fff\';"';
        echo '>';
        echo '<strong>' . htmlspecialchars($d["farbe"]) . '</strong><br>';
        echo htmlspecialchars($d["modell"]);
        echo '</button>';
        echo '</form>';        
    }
    echo '</div>';
    
    // Vertikaler Trenner
    echo '<div style="width: 2px; height: 80%; background-color: white;"></div>';
    
    // Rechte Spalte (TvK)
    echo '<div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">';
    foreach ($tvk_drucker as $d) {
        $disabledClass = ($nutzerTurm !== strtolower($d["turm"])) ? "grayed-out" : "";
        $buttonStyle = "font-size: 30px; display: flex; flex-direction: column; padding: 10px;";
        if (!$disabledClass) $buttonStyle .= " justify-content: center; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s; cursor: pointer;";
        
        echo '<form method="POST" style="margin: 10px 0;">';
        echo '<input type="hidden" name="drucker_id" value="' . htmlspecialchars($d["id"]) . '">';
        echo '<input type="hidden" name="d_selected" value="true">';
        echo '<button type="submit" class="' . $disabledClass . '" style="' . $buttonStyle . '"';
        if (!$disabledClass) echo ' onmouseover="this.style.backgroundColor=\'#11a50d\';" onmouseout="this.style.backgroundColor=\'#fff\';"';
        echo '>';
        echo '<strong>' . htmlspecialchars($d["farbe"]) . '</strong><br>';
        echo htmlspecialchars($d["modell"]);
        echo '</button>';
        echo '</form>';
        
    }
    echo '</div>';
    
    echo '</div>';
    
    echo '</div>';
    
    

} else {
  header("Location: denied.php");
}
$conn->close();
?>