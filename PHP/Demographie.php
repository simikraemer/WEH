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
    
    function getCountryCounts($conn, $countries, $turm) {
        $sql = "SELECT geburtsort FROM users WHERE pid IN (11) AND turm = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $turm);
        $stmt->execute();
        $result = $stmt->get_result();
    
        $countryCounts = [];
        $totalUsers = 0;
    
        // Zähle alle Geburtsorte und summiere die Gesamtzahl der Bewohner
        while ($row = $result->fetch_assoc()) {
            $totalUsers++;
            $geburtsort = strtolower($row['geburtsort']);
    
            foreach ($countries as $country) {
                $countryName = strtolower($country['name']);
                if (strpos($geburtsort, $countryName) !== false) {
                    $countryCode = $country['code'];
                    if (!isset($countryCounts[$countryCode])) {
                        $countryCounts[$countryCode] = [
                            'name' => $country['name'],
                            'flag' => "flag-icons/" . $country['flag_4x3'],
                            'count' => 0
                        ];
                    }
                    $countryCounts[$countryCode]['count']++;
                    break;
                }
            }
        }
    
        // Füge den Prozentanteil hinzu
        foreach ($countryCounts as &$country) {
            $country['percent'] = $totalUsers > 0 ? number_format(($country['count'] / $totalUsers) * 100, 1, ',', '') : '0,00';
        }
        
    
        usort($countryCounts, function ($a, $b) {
            return $b['count'] - $a['count'];
        });
    
        return $countryCounts;
    }
    
    
    
    $countryCountsweh = getCountryCounts($conn, $countries, "weh");
    $countryCountstvk = getCountryCounts($conn, $countries, "tvk");
    ?>
    

    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Herkunft der User</title>
    <style>
        .flex-container {
            color: white;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 40px;
            padding: 20px;
        }
        .table-container {
            flex: 1;
            max-width: 600px;
        }
        table {
            width: 100%;
            margin: 0 auto;
            border-collapse: collapse;
            text-align: left;
        }
        td, th {
            border: 1px solid #444;
            padding: 10px 15px;
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
