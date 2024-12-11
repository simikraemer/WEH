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
            $userbeiträge = $sum_beiträge - ($row["aktiv"] == 1 ? $hausbeitrag : 0) - ($row["netzag"] == 1 || $row["pid"] == 12 ? $netzbeitrag : 0);
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
            echo "<form method='POST' action='House.php' target='_blank' style='display: none;' id='form_{$user['uid']}'>
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


        $onlinekassen = array(
            "72" => array(
                "name" => "Netzkonto"
            ),
            "69" => array(
                "name" => "PayPal"
            ),
            "92" => array(
                "name" => "Hauskonto"
            )
        );

        $barkassen = array(
            "1" => array(
                "name" => "Netzbarkasse 1",
                "db_name" => "kasse_netz1"
            ),
            "2" => array(
                "name" => "Netzbarkasse 2",
                "db_name" => "kasse_netz2"
            ),
            "3" => array(
                "name" => "Kassenwartkasse 1",
                "db_name" => "kasse_wart1"
            ),
            "4" => array(
                "name" => "Kassenwartkasse 2",
                "db_name" => "kasse_wart2"
            ),
            "5" => array(
                "name" => "Tresor",
                "db_name" => "kasse_tresor"
            )
        );

        $gesamtsumme = 0;
        $rücklagen_haus = 10000;
        $rücklagen_netz = 30000;

        foreach ($onlinekassen as $key => $kasse) {
            if ($key == "69") { 
                # PayPal Tax: 0,35€ + 1,5*Betrag 
                # Bei 5€ und 10€ Überweisungen müssen die User 0,35€ absichtlich überweisen
                # Da nur diese 8 Cases definiert sind, werden die Übersetzungen auch hier nur über das Case definiert :^) 
                $sql = "SELECT SUM(
                    CASE
                        WHEN betrag = 5 THEN 4.92
                        WHEN betrag = 10 THEN 9.84
                        WHEN betrag = 20 THEN 19.35
                        WHEN betrag = 30 THEN 29.20
                        WHEN betrag = 40 THEN 39.05
                        WHEN betrag = 50 THEN 48.90
                        WHEN betrag = 75 THEN 73.53
                        WHEN betrag = 100 THEN 98.15
                        ELSE betrag
                    END
                ) FROM weh.transfers WHERE kasse = ?";      
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $key);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $summe);
                if (mysqli_stmt_fetch($stmt)) {
                    $gesamtsumme += $summe;
                    $onlinekassen[$key]["summe"] = $summe;
                }
                $stmt->close();
            } else {
                $sql = "SELECT SUM(betrag) FROM weh.transfers WHERE kasse = ?";        
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $key);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $summe);
                if (mysqli_stmt_fetch($stmt)) {
                    $gesamtsumme += $summe;
                    $onlinekassen[$key]["summe"] = $summe;
                }
                $stmt->close();
            }
        }

        foreach ($barkassen as $key => $kasse) {
            $sql = "SELECT u.name FROM weh.constants c JOIN weh.users u ON c.wert = u.uid WHERE c.name = ?";        
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "s", $kasse["db_name"]);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $kassenusername);
            if (mysqli_stmt_fetch($stmt)) {
                $barkassen[$key]["username"] = $kassenusername;
            }
            $stmt->close();

            $sql = "SELECT SUM(betrag) FROM weh.barkasse WHERE kasse = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $summe);
            if (mysqli_stmt_fetch($stmt)) {
                $gesamtsumme += $summe;
                $barkassen[$key]["summe"] = $summe;
            }
            $stmt->close();
        }

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


        $hausbudget = $onlinekassen["92"]["summe"] - $rücklagen_haus + $barkassen["3"]["summe"] + $barkassen["4"]["summe"] + $barkassen["5"]["summe"];
        $netzbudget = $onlinekassen["72"]["summe"] - $rücklagen_netz - $userboundsumme + $barkassen["1"]["summe"] + $barkassen["2"]["summe"] + $onlinekassen["69"]["summe"];
        $gesamtbudget = $netzbudget + $hausbudget;

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