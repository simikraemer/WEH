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
if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["TvK-Sprecher"] || $_SESSION["WEH-BA"] || $_SESSION["TvK-BA"])) {
  load_menu();


    $countryFile = 'flag-icons/country.json';
    $countries = json_decode(file_get_contents($countryFile), true);
    

    function getCountryAndContinentCounts($conn, $countries, $turm) {
        $sql = "SELECT geburtsort FROM users WHERE pid IN (11) AND turm = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $turm);
        $stmt->execute();
        $result = $stmt->get_result();
    
        $countryCounts = [];
        $continentCounts = [];
        $totalUsers = 0;
    
        // Zähle alle Geburtsorte und summiere die Gesamtzahl der Bewohner
        while ($row = $result->fetch_assoc()) {
            $totalUsers++;
            $geburtsort = strtolower($row['geburtsort']);
    
            foreach ($countries as $country) {
                $countryName = strtolower($country['name']);
                if (strpos($geburtsort, $countryName) !== false) {
                    $countryCode = $country['code'];
                    $continent = $country['continent'];
    
                    // Länder zählen
                    if (!isset($countryCounts[$countryCode])) {
                        $countryCounts[$countryCode] = [
                            'name' => $country['name'],
                            'flag' => "flag-icons/" . $country['flag_4x3'],
                            'count' => 0
                        ];
                    }
                    $countryCounts[$countryCode]['count']++;
    
                    // Kontinente zählen
                    if (!isset($continentCounts[$continent])) {
                        $continentCounts[$continent] = [
                            'name' => ucfirst($continent),
                            'count' => 0
                        ];
                    }
                    $continentCounts[$continent]['count']++;
    
                    break;
                }
            }
        }
    
        // Füge den Prozentanteil hinzu
        foreach ($countryCounts as &$country) {
            $country['percent'] = $totalUsers > 0 ? number_format(($country['count'] / $totalUsers) * 100, 1, ',', '') : '0,00';

            // Übersetzungen
            if ($country['name'] === "United States of America") {
                $country['name'] = "USA";
            } elseif ($country['name'] === "United Arab Emirates") {
                $country['name'] = "UAE";
            } elseif ($country['name'] === "Bosnia and Herzegovina") {
                $country['name'] = "Bosnia";
            }
        }
        
        foreach ($continentCounts as &$continent) {
            $continent['percent'] = $totalUsers > 0 ? number_format(($continent['count'] / $totalUsers) * 100, 1, ',', '') : '0,00';
        }
    
        // Sortiere die Länder nach Anzahl
        usort($countryCounts, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Sortiere die Kontinente nach Anzahl
        usort($continentCounts, function ($a, $b) {
            return $b['count'] - $a['count'];
        });    

        return [
            'countryCounts' => $countryCounts,
            'continentCounts' => $continentCounts
        ];
    }
    
    
    $dataWeh = getCountryAndContinentCounts($conn, $countries, "weh");
    $countryCountsweh = $dataWeh['countryCounts'];
    $continentCountsweh = $dataWeh['continentCounts'];
    
    $dataTvk = getCountryAndContinentCounts($conn, $countries, "tvk");
    $countryCountstvk = $dataTvk['countryCounts'];
    $continentCountstvk = $dataTvk['continentCounts'];
    
    ?>
    

        <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Herkunft der User</title>
        <style>
            .flex-container {
                color: white;
                font-family: Arial, sans-serif;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                align-items: flex-start;
                gap: 60px;
            }
            .table-container {
                flex: 1;
                max-width: 600px;
            }
            table {
                width: 100%;
                margin: 0 auto;
                border-collapse: collapse;
                text-align: center;
                font-size: 20px;
            }
            td, th {
                border: 2px solid #444;
                padding: 5px 5px;
            }
            th {
                background-color: #000;
                color: white;
            }
            tr:nth-child(even) {
                background-color: #222;
            }
            tr:nth-child(odd) {
                background-color: #333;
            }
            img {
                width: 32px;
                height: auto;
            }
        </style>
    </head>

    <body>
        <div class="flex-container">
            <div class="table-container">
                <h1 style="text-align: center;">WEH</h1>
                <!-- Kontinent-Tabelle für WEH -->
                <table>
                    <thead>
                        <tr>
                            <th colspan="2">Kontinent</th>
                            <th>Bewohner</th>
                            <th>Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($continentCountsweh as $continent): ?>
                            <tr>
                                <td colspan="2"><?php echo htmlspecialchars($continent['name']); ?></td>
                                <td><?php echo htmlspecialchars($continent['count']); ?></td>
                                <td><?php echo htmlspecialchars($continent['percent']) . "%"; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <br>
                <!-- Länder-Tabelle für WEH -->
                <table>
                    <thead>
                        <tr>
                            <th>Flagge</th>
                            <th>Land</th>
                            <th>Bewohner</th>
                            <th>Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($countryCountsweh as $country): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($country['flag']); ?>" alt="Flag of <?php echo htmlspecialchars($country['name']); ?>"></td>
                                <td><?php echo htmlspecialchars($country['name']); ?></td>
                                <td><?php echo htmlspecialchars($country['count']); ?></td>
                                <td><?php echo htmlspecialchars($country['percent']) . "%"; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-container">
                <h1 style="text-align: center;">TvK</h1>
                <!-- Kontinent-Tabelle für TvK -->
                <table>
                    <thead>
                        <tr>
                            <th colspan="2">Kontinent</th>
                            <th>Bewohner</th>
                            <th>Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($continentCountstvk as $continent): ?>
                            <tr>
                                <td colspan="2"><?php echo htmlspecialchars($continent['name']); ?></td>
                                <td><?php echo htmlspecialchars($continent['count']); ?></td>
                                <td><?php echo htmlspecialchars($continent['percent']) . "%"; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <br>
                <!-- Länder-Tabelle für TvK -->
                <table>
                    <thead>
                        <tr>
                            <th>Flagge</th>
                            <th>Land</th>
                            <th>Bewohner</th>
                            <th>Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($countryCountstvk as $country): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($country['flag']); ?>" alt="Flag of <?php echo htmlspecialchars($country['name']); ?>"></td>
                                <td><?php echo htmlspecialchars($country['name']); ?></td>
                                <td><?php echo htmlspecialchars($country['count']); ?></td>
                                <td><?php echo htmlspecialchars($country['percent']) . "%"; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </body>


    </html>





    <?php


}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>
