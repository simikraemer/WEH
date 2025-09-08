<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    </head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["NetzAG"] || $_SESSION["Vorstand"] || $_SESSION["TvK-Sprecher"] || $_SESSION["WEH-BA"] || $_SESSION["TvK-BA"])) {
  load_menu();

  $countryFile = 'flag-icons/country.json';
  $countries = json_decode(file_get_contents($countryFile), true);

  // Mapping vorbereiten
  $countryMap = [];
  $continentMap = [];
  foreach ($countries as $entry) {
      $countryMap[strtolower($entry['name'])] = $entry['name'];
      $continentMap[strtolower($entry['continent'])] = $entry['continent'];
  }

  // Userdaten auslesen
  $stmt = $conn->prepare("SELECT firstname, lastname, room, geburtsort FROM users WHERE pid IN (11) AND turm = 'weh'");
  $stmt->execute();
  $res = $stmt->get_result();

  $data = [];
  $optionsUsed = [];

  while ($row = $res->fetch_assoc()) {
      $room = (int)$row['room'];
      $etage = (int)floor($room / 100);
      if ($etage < 1 || $etage > 17) continue;

      $geburtsort = strtolower($row['geburtsort']);
      $matchCountry = null;
      $matchContinent = null;

      foreach ($countryMap as $key => $val) {
          if (strpos($geburtsort, $key) !== false) {
              $matchCountry = $val;
              break;
          }
      }

      foreach ($continentMap as $key => $val) {
          if (strpos($geburtsort, $key) !== false) {
              $matchContinent = $val;
              break;
          }
      }

      if (!$matchCountry && !$matchContinent) continue;

      foreach (['country' => $matchCountry, 'continent' => $matchContinent] as $type => $val) {
          if (!$val) continue;
          $optionsUsed[$type][$val] = true;
          $data[$type][$val][$etage][] = [
              'name' => $row['firstname'] . ' ' . $row['lastname'],
              'room' => $row['room']
          ];
      }
  }

  ksort($optionsUsed['continent']);
  ksort($optionsUsed['country']);
  ?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Etagenverteilung nach Herkunft</title>
    <style>
        body {
            background-color: #121212;
            color: #eee;
            font-family: Arial, sans-serif;
        }
        .container {
            padding: 2rem;
            max-width: 900px;
            margin: auto;
        }
        select {
            background-color: #222;
            color: #eee;
            border: 1px solid #333;
            padding: 0.5rem;
            font-size: 1rem;
            width: 100%;
            border-radius: 5px;
            margin-bottom: 2rem;
        }
        h1 {
            text-align: center;
            color: #11a50d;
        }
        canvas {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 10px;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Etagenverteilung nach Herkunft</h1>

    <select id="selector">
        <option value="" selected disabled>Bitte Herkunft auswählen...</option>
        <?php foreach ($optionsUsed['continent'] ?? [] as $name => $_): ?>
            <option value="continent::<?php echo htmlspecialchars($name); ?>">🌍 <?php echo htmlspecialchars($name); ?></option>
        <?php endforeach; ?>
        <?php foreach ($optionsUsed['country'] ?? [] as $name => $_): ?>
            <option value="country::<?php echo htmlspecialchars($name); ?>">🏳️ <?php echo htmlspecialchars($name); ?></option>
        <?php endforeach; ?>
    </select>

    <canvas id="chart" height="300"></canvas>
</div>

<script>
const rawData = <?php echo json_encode($data); ?>;
const chart = new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: Array.from({length: 17}, (_, i) => `${i + 1}. Etage`),
        datasets: [{
            label: 'Bewohner',
            backgroundColor: '#11a50d',
            data: Array(17).fill(0)
        }]
    },
    options: {
        responsive: true,
        plugins: {
            tooltip: {
                callbacks: {
                    afterLabel: function(ctx) {
                        const etage = ctx.dataIndex + 1;
                        const val = document.getElementById('selector').value;
                        if (!val) return '';
                        const [type, name] = val.split("::");
                        const eintraege = rawData?.[type]?.[name]?.[etage] ?? [];
                        return eintraege.map(e => e.name + " (" + e.room + ")");
                    }
                }
            },
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1,
                    color: '#ccc'
                },
                grid: { color: '#333' }
            },
            x: {
                ticks: { color: '#ccc' },
                grid: { color: '#333' }
            }
        }
    }
});

document.getElementById('selector').addEventListener('change', function () {
    const val = this.value;
    if (!val) return;
    const [type, name] = val.split("::");
    const data = [];

    for (let i = 1; i <= 17; i++) {
        data.push(rawData?.[type]?.[name]?.[i]?.length ?? 0);
    }

    chart.data.datasets[0].data = data;
    chart.update();
});
</script>

</body>
</html>

<?php
} else {
  header("Location: denied.php");
}
$conn->close();
?>
</html>
