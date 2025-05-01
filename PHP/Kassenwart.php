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
if (auth($conn) && ($_SESSION["Webmaster"] || $_SESSION["Vorstand"] || $_SESSION["TvK-Sprecher"]) ) {
    load_menu();

    
    $sql = "SELECT name, wert FROM constants WHERE name IN ('netzbeitrag', 'hausbeitrag')";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $wert);
    while (mysqli_stmt_fetch($stmt)) {
        if ($name == 'hausbeitrag') {
            $hausbeitrag = $wert;
        } elseif ($name == 'netzbeitrag') {
            $netzbeitrag = $wert;
        }
    }
    mysqli_stmt_close($stmt);

    $sum_beiträge = $hausbeitrag + $netzbeitrag;

    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="action" value="wehkonten" class="house-button" style="font-size:50px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">Vereins-Konten';
    echo '</button>';
    echo '<button type="submit" name="action" value="userkonten" class="house-button" style="font-size:50px; margin-right:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">User-Konten';
    echo '</button>';
    echo '</form>';
    echo "<br><br>";    

    if (isset($_POST["action"]) && $_POST["action"] == "userkonten") {
        
        # -------------------------------------------------
        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";
        # -------------------------------------------------


        $zeit = time();
        $sql = "SELECT COUNT(id) FROM weh.sperre WHERE endtime >= ? AND starttime <= ? AND internet = 1 AND missedpayment = 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $zeit, $zeit);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $anzahlgesperrteuser);
        mysqli_stmt_fetch($stmt);
        $stmt->close();

        $sql = "SELECT COUNT(u.uid) FROM weh.users u WHERE u.pid IN (11,12)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $anzahlzahlendeuser);
        mysqli_stmt_fetch($stmt);
        $stmt->close();

        $sql_users = "SELECT
            u.uid, 
            u.name, 
            u.turm,
            u.room,            
            u.pid,
            COALESCE(SUM(t.betrag), 0) AS gesamt_betrag,
            CASE 
                WHEN u.groups LIKE '1' OR u.groups LIKE '1,19' THEN 0
                ELSE 1
            END AS aktiv,
            CASE 
                WHEN u.groups LIKE '%,7%' THEN 1 
                ELSE 0 
            END AS netzag,
            CASE 
                WHEN EXISTS (
                    SELECT 1 
                    FROM sperre s 
                    WHERE u.uid = s.uid 
                      AND s.missedpayment = 1 
                      AND s.starttime <= UNIX_TIMESTAMP() 
                      AND s.endtime >= UNIX_TIMESTAMP()
                ) THEN 1 
                ELSE 0 
            END AS gesperrt
        FROM users u 
        LEFT JOIN transfers t ON u.uid = t.uid
        WHERE u.pid IN (11,12)
        GROUP BY u.uid, u.name
        ORDER BY gesamt_betrag ASC;
        ";
            
        $result = mysqli_query($conn, $sql_users);
        $anzahlgefährdeteuser = 0;
        $userArray = array();
        while ($row = mysqli_fetch_assoc($result)) {
            $uid = $row["uid"];
            $name = $row["name"];
            $turm = $row["turm"];
            $room = $row["room"];
            $kontostand = round($row["gesamt_betrag"], 2);
            $userbeiträge = $sum_beiträge - ($row["aktiv"] == 1 || $row["pid"] == 12 ? $hausbeitrag : 0) - ($row["netzag"] == 1 || $row["pid"] == 12 ? $netzbeitrag : 0);
            if ($row["gesperrt"] == 1) {
                $underpressure = "red";
            } elseif ($kontostand < $userbeiträge) {
                $anzahlgefährdeteuser++;
                $underpressure = "yellow";
            } else {
                $underpressure = "#00A800";
            }            
            $userArray[] = array("name" => $name, "uid" => $uid, "turm" => $turm, "room" => $room,  "kontostand" => $kontostand, "underpressure" => $underpressure);
        }

        echo '<div style="display: flex; justify-content: space-around;">';   


        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 30px; color: white;">';
        echo 'Aktuell wegen fehlender Zahlung gesperrt:';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 50px; color: red">';
        echo $anzahlgesperrteuser . " User";
        echo '</div>';     
        echo '</div>';

        $aktuellesDatum = date('Y-m-d'); // Aktuelles Datum im Format JJJJ-MM-TT
        $nächsterMonat = date('m', strtotime('first day of next month', strtotime($aktuellesDatum))); // Monatsindex (01 bis 12)
        $nächstesJahr = date('Y', strtotime('first day of next month', strtotime($aktuellesDatum))); // Jahr des nächsten Monats

        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 30px; color: white;">';
        echo 'Werden zum 01.' . str_pad($nächsterMonat, 2, '0', STR_PAD_LEFT) . '.' . $nächstesJahr . ' gesperrt:';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 50px; color: yellow">';
        echo $anzahlgefährdeteuser . " User";
        echo '</div>';    
        echo '</div>';        

        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 30px; color: white;">';
        echo 'Können zum 01.' . str_pad($nächsterMonat, 2, '0', STR_PAD_LEFT) . '.' . $nächstesJahr . ' zahlen:';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 50px; color: #00A800">';
        echo $anzahlzahlendeuser - $anzahlgefährdeteuser - $anzahlgesperrteuser . " User";
        echo '</div>';
        echo '</div>';


        echo '</div>';


        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";

        echo '<table class="grey-table" style="margin: 0 auto; text-align: center;">
        <tr>
        <th>UID</th>
        <th>Name</th>
        <th>Turm</th>
        <th>Raum</th>
        <th>Kontostand</th>
        </tr>';
        
        foreach ($userArray as $user) {
            $gerundeterKontostand = number_format($user["kontostand"], 2, ',', '.') . ' €';
            $turm4ausgabe = ($user['turm'] == 'tvk') ? 'TvK' : strtoupper($user['turm']);
            
            // Create a form for each row
            echo "<form method='POST' action='User.php' target='_blank' style='display: none;' id='form_{$user['uid']}'>
              <input type='hidden' name='id' value='{$user['uid']}'>
            </form>";
            
            // Create the table row and make it clickable
            echo "<tr onclick='document.getElementById(\"form_{$user['uid']}\").submit();' style='cursor: pointer;'>
                    <td>{$user['uid']}</td>
                    <td>{$user['name']}</td>
                    <td>{$turm4ausgabe}</td>
                    <td>{$user['room']}</td>
                    <td style='color: {$user['underpressure']};'>$gerundeterKontostand</td>
                  </tr>";
        }
        
        echo '</table>';
        

        mysqli_free_result($result);

    } else {
        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";

        $onlinekassen = [
                72 => ["name" => "Netzkonto"],
                69 => ["name" => "PayPal"],
                92 => ["name" => "Hauskonto"]
            ];
            
            $barkassen = [
                1 => ["name" => "Netzbarkasse 1", "db_name" => "kasse_netz1"],
                2 => ["name" => "Netzbarkasse 2", "db_name" => "kasse_netz2"],
                93 => ["name" => "Kassenwartkasse 1", "db_name" => "kasse_wart1"],
                94 => ["name" => "Kassenwartkasse 2", "db_name" => "kasse_wart2"],
                95 => ["name" => "Tresor", "db_name" => "kasse_tresor"]
            ];
            
            $gesamtsumme = 0;
            $rücklagen_haus = 10000;
            $rücklagen_netz = 30000;
            
            // Onlinekassen-Summen
            foreach ($onlinekassen as $key => &$kasse) {
                $summe = berechneKontostand($conn, $key);
                $kasse["summe"] = $summe;
                $gesamtsumme += $summe;
            }
            unset($kasse);
            
            // Barkassen-Summen
            foreach ($barkassen as $key => &$kasse) {
                // Besitzername laden
                $sql = "SELECT u.name FROM constants c JOIN users u ON c.wert = u.uid WHERE c.name = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "s", $kasse["db_name"]);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $kassenusername);
                if (mysqli_stmt_fetch($stmt)) {
                    $kasse["username"] = $kassenusername;
                }
                $stmt->close();
            
                $summe = berechneKontostand($conn, $key);
                $kasse["summe"] = $summe;
                $gesamtsumme += $summe;
            }
            unset($kasse);
            

        $sql = "
            SELECT SUM(subquery.gesamtsumme) AS gesamtsumme_aller_benutzer
            FROM (
                SELECT SUM(t.betrag) AS gesamtsumme
                FROM weh.users u
                JOIN weh.transfers t ON t.uid = u.uid
                WHERE u.pid IN (11, 12, 13)
                GROUP BY u.uid
                HAVING gesamtsumme > 0
            ) AS subquery;
        ";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $userboundsumme);
        mysqli_stmt_fetch($stmt);
        $stmt->close();
            
            
        echo '<div style="text-align: center; color: white; padding: 10px;">';
        echo '<div style="font-size: 60px;">Gesamt</div>';
        echo '</div>';

        echo '<div style="display: flex; justify-content: space-around;">';  
        echo '<div style="text-align: center; color: white;">';
        echo '<div style="font-size: 70px;">' . number_format($gesamtsumme, 2, ',', '.') . ' €' . '</div>';
        echo '</div>';
        echo '</div>';

        echo "<br>";
        echo "<br>";
        echo "<br>";
            
        echo '<div style="display: flex; justify-content: space-around;">';   
        foreach ($onlinekassen as $key => $kasse) {
            echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
            echo '<div style="font-size: 40px;">' . $kasse["name"] . '</div>';
            echo '<div style="font-size: 50px;">' . number_format($kasse["summe"], 2, ',', '.') . ' €' . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo "<br>";
        echo "<br>";

        echo '<div style="display: flex; justify-content: space-around;">';   
        foreach ($barkassen as $key => $kasse) {
            echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
            echo '<div style="font-size: 25px;">' . $kasse["name"] . '</div>';
            echo '<div style="font-size: 20px;">' . $kasse["username"] . '</div>';
            echo '<div style="font-size: 35px;">' . number_format($kasse["summe"], 2, ',', '.') . ' €' . '</div>';
            echo '</div>';
        }
        echo '</div>';


        # -------------------------------------------------
        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";
        # -------------------------------------------------


        // 💰 Hausbudget (Hauskonto + Barkassen – Rücklagen)
        $hausbudget =
            $onlinekassen[92]["summe"]     // Hauskonto
            - $rücklagen_haus              // Rücklagen für Notfälle (Waschmaschinen, etc.)
            + $barkassen[93]["summe"]      // Kassenwart I
            + $barkassen[94]["summe"]      // Kassenwart II
            + $barkassen[95]["summe"];     // Tresor

        // 🌐 Netzbudget (Netzkonto + Barkassen + PayPal – Rücklagen – Userguthaben)
        $netzbudget =
            $onlinekassen[72]["summe"]     // Netzkonto
            + $barkassen[1]["summe"]       // Netzkasse I
            + $barkassen[2]["summe"]       // Netzkasse II
            + $onlinekassen[69]["summe"]   // PayPal
            - $rücklagen_netz              // Rücklagen für Notfälle (Infrastruktur)
            - $userboundsumme;             // Guthaben der User (müsste im Ernstfall zurückgezahlt werden)

        // 📊 Gesamtbudget
        $gesamtbudget = $hausbudget + $netzbudget;



        echo '<div style="display: flex; justify-content: space-around;">';   

        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 40px; color: white;">';
        echo 'Netzwerk-AG Budget';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 20px; color: white;">';
        echo number_format($userboundsumme, 2, ',', '.') . '€ Userkonten + ' . number_format($rücklagen_netz, 0, ',', '.') . '€ Rücklagen';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 50px; color: white;">';
        echo number_format($netzbudget, 2, ',', '.') . ' €';
        echo '</div>';   
        echo '</div>';

        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 50px; color: white;">';
        echo 'Gesamt Budget';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 60px; color: white;">';
        echo number_format($gesamtbudget, 2, ',', '.') . ' €';
        echo '</div>';   
        echo '</div>';

        echo '<div style="text-align: center; color: white; padding: 10px; flex: 1;">';
        echo '<div style="text-align: center; font-size: 40px; color: white;">';
        echo 'Haus Budget';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 20px; color: white;">';
        echo number_format($rücklagen_haus, 0, ',', '.') . '€ Rücklagen';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 50px; color: white;">';
        echo number_format($hausbudget, 2, ',', '.') . ' €';
        echo '</div>';   
        echo '</div>';

        echo '</div>';
        echo "<br><br>";
    }

}
else {
  header("Location: denied.php");
}




// Close the connection to the database
$conn->close();
?>
</html>