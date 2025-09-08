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

  // --- Turm-Auswahl (WEH / TvK) ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (isset($_POST['action'])) {
          if ($_POST['action'] === 'turmchoice_weh') {
              $_SESSION["ap_turm_var"] = 'weh';
          } elseif ($_POST['action'] === 'turmchoice_tvk') {
              $_SESSION["ap_turm_var"] = 'tvk';
          }
      }
  }

  $turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : ($_SESSION["turm"] ?? 'weh');
  $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
  $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
  $maxEtage = ($turm === 'tvk') ? 15 : 17;

  // --- Länder/Kontinente laden ---
  $countryFile = 'flag-icons/country.json';
  $countries = [];
  if (is_readable($countryFile)) {
      $json = file_get_contents($countryFile);
      $parsed = json_decode($json, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
          $countries = $parsed;
      }
  }

  // --- Mapping vorbereiten ---
  $countryMap = [];
  $continentMap = [];
  $countryToContinent = [];
  $countryToCode = [];

  foreach ($countries as $entry) {
      if (!is_array($entry)) continue;

      if (!empty($entry['name'])) {
          $name = strtolower(trim($entry['name']));
          $prettyName = $entry['name'];
          $countryMap[$name] = $prettyName;

          if (!empty($entry['continent'])) {
              $countryToContinent[$name] = $entry['continent'];
          }
          if (!empty($entry['code'])) {
              $countryToCode[$prettyName] = $entry['code'];
          }
      }

      if (!empty($entry['continent'])) {
          $cont = strtolower(trim($entry['continent']));
          $continentMap[$cont] = $entry['continent'];
      }
  }

  // --- Userdaten auslesen (nach Turm gefiltert) ---
  $stmt = $conn->prepare("SELECT firstname, lastname, room, geburtsort FROM users WHERE pid IN (11) AND turm = ?");
  $stmt->bind_param('s', $turm);
  $stmt->execute();
  $res = $stmt->get_result();

  // --- Datencontainer ---
  $data = ['country' => [], 'continent' => []];
  $optionsUsed = ['country' => [], 'continent' => []];

  while ($row = $res->fetch_assoc()) {
      $room = (int)$row['room'];
      $etage = (int)floor($room / 100);
      if ($etage < 1 || $etage > $maxEtage) continue;

      $geburtsort = strtolower((string)($row['geburtsort'] ?? ''));

      $matchCountry = null;
      $matchContinent = null;

      if ($geburtsort !== '') {
          foreach ($countryMap as $key => $val) {
              if (strpos($geburtsort, $key) !== false) {
                  $matchCountry = $val;
                  break;
              }
          }
          if ($matchCountry) {
              $lcCountry = strtolower($matchCountry);
              if (isset($countryToContinent[$lcCountry])) {
                  $matchContinent = $countryToContinent[$lcCountry];
              }
          }
      }

      if (!$matchCountry && !$matchContinent) continue;

      foreach (['country' => $matchCountry, 'continent' => $matchContinent] as $type => $val) {
          if (!$val) continue;
          $optionsUsed[$type][$val] = true;

          if (!isset($data[$type][$val][$etage])) {
              $data[$type][$val][$etage] = [];
          }
          $data[$type][$val][$etage][] = [
              'name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
              'room' => $row['room'] ?? ''
          ];
      }
  }

  // --- Counts für Sortierung ---
  $continentCounts = [];
  foreach ($optionsUsed['continent'] as $name => $_) {
      $count = 0;
      foreach ($data['continent'][$name] ?? [] as $etage => $eintraege) {
          $count += count($eintraege);
      }
      $continentCounts[$name] = $count;
  }
  arsort($continentCounts);

  $countryCounts = [];
  foreach ($optionsUsed['country'] as $name => $_) {
      $count = 0;
      foreach ($data['country'][$name] ?? [] as $etage => $eintraege) {
          $count += count($eintraege);
      }
      $countryCounts[$name] = $count;
  }
  arsort($countryCounts);
  ?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Etagenverteilung nach Herkunft</title>
    <style>
        .container {
            padding: 2rem;
            max-width: 900px;
            margin: auto;
        }
        canvas {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 10px;
        }
        .house-button:hover { filter: brightness(0.95); }

        /* Custom Select mit Flaggen */
        .custom-select { position: relative; width: 100%; font-family: inherit; }
        .select-trigger {
            width: 100%; text-align: left; padding: .6rem .8rem;
            background: #222; color: #eee; border:1px solid #333; border-radius:6px;
            display:flex; align-items:center; justify-content:space-between; cursor:pointer;
        }
        .select-trigger .selected { display:flex; align-items:center; gap:.6rem; }
        .select-caret { margin-left:.5rem; fill:#aaa; width:16px; height:16px; }
        .select-menu {
            position:absolute; left:0; right:0; margin-top:.25rem; background:#1a1a1a; border:1px solid #333;
            border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,.4); max-height:320px; overflow:auto; display:none; z-index:50;
        }
        .custom-select.open .select-menu { display:block; }
        .select-section { padding:.4rem .6rem; font-size:.9rem; color:#aaa; position:sticky; top:0; background:#1a1a1a; border-bottom:1px solid #2a2a2a; }
        .option { padding:.45rem .8rem; display:flex; align-items:center; gap:.6rem; cursor:pointer; }
        .option:hover { background:#242424; }
        .flag { width:20px; height:15px; object-fit:cover; border:1px solid #333; border-radius:2px; flex:0 0 auto; }
        .label { color:#eee; }
        .count { color:#9aa0a6; margin-left:.25rem; }
        .emoji { width:18px; display:inline-flex; justify-content:center; }
    </style>
</head>

<body>
<div class="container">

    <?php
    // --- Buttons statt Überschrift ---
    echo '<div style="display:flex; justify-content:center; align-items:center;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center; gap:0px;">';
    echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:30px; width:200px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
    echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:30px; width:200px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
    echo '</form>';
    echo '</div>';
    echo "<br><br>";
    ?>

    <!-- Custom Dropdown mit Flaggen in den Optionen -->
    <div class="custom-select" id="selector">
        <button type="button" class="select-trigger" aria-haspopup="listbox" aria-expanded="false">
            <span class="selected"><span class="label">Bitte Herkunft auswählen…</span></span>
            <svg class="select-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>
        </button>
        <div class="select-menu" role="listbox">

            <?php if (!empty($continentCounts)) : ?>
                <div class="select-section">Kontinente</div>
                <?php
                $continentEmojis = [
                    'Europe'   => '🌍',
                    'Africa'   => '🌍',
                    'Asia'     => '🌏',
                    'Oceania'  => '🌏',
                    'Australia'=> '🌏',
                    'North America' => '🌎',
                    'South America' => '🌎',
                    'America'  => '🌎',
                    'Antarctica' => '🌍' // neutral fallback
                ];
                ?>
                <?php foreach ($continentCounts as $name => $count): 
                    $emoji = $continentEmojis[$name] ?? '🌍';
                ?>
                    <div class="option" role="option"
                        data-type="continent"
                        data-name="<?php echo htmlspecialchars($name); ?>">
                        <span class="emoji"><?php echo $emoji; ?></span>
                        <span class="label"><?php echo htmlspecialchars($name); ?></span>
                        <span class="count">(<?php echo (int)$count; ?>)</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>


            <?php if (!empty($countryCounts)) : ?>
                <div class="select-section">Länder</div>
                <?php foreach ($countryCounts as $name => $count):
                    $code = $countryToCode[$name] ?? null;
                    ?>
                    <div class="option" role="option"
                         data-type="country"
                         data-name="<?php echo htmlspecialchars($name); ?>">
                        <?php if ($code): ?>
                            <img class="flag" src="flag-icons/flags/4x3/<?php echo htmlspecialchars(strtolower($code)); ?>.svg" alt="">
                        <?php else: ?>
                            <span class="emoji">🏳️</span>
                        <?php endif; ?>
                        <span class="label"><?php echo htmlspecialchars($name); ?></span>
                        <span class="count">(<?php echo (int)$count; ?>)</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

    <br>
    <canvas id="chart" style="height: 150px;"></canvas>
</div>

<script>
const MAX_ETAGE = <?php echo (int)$maxEtage; ?>;
const rawData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;

let currentSelection = null;

const selector = document.getElementById('selector');
const trigger  = selector.querySelector('.select-trigger');
const menu     = selector.querySelector('.select-menu');
const options  = selector.querySelectorAll('.option');

function closeMenu() {
    selector.classList.remove('open');
    trigger.setAttribute('aria-expanded','false');
}
function openMenu() {
    selector.classList.add('open');
    trigger.setAttribute('aria-expanded','true');
}

trigger.addEventListener('click', () => {
    if (selector.classList.contains('open')) closeMenu(); else openMenu();
});

document.addEventListener('click', (e) => {
    if (!selector.contains(e.target)) closeMenu();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
});

options.forEach(opt => {
    opt.addEventListener('click', () => {
        const type = opt.dataset.type;
        const name = opt.dataset.name;

        currentSelection = { type, name };

        // Trigger-Label aktualisieren (inkl. Flagge)
        const selEl = trigger.querySelector('.selected');
        selEl.innerHTML = ''; // clear

        if (type === 'country') {
            const flag = opt.querySelector('.flag');
            if (flag) {
                const img = document.createElement('img');
                img.src = flag.getAttribute('src');
                img.alt = name;
                img.className = 'flag';
                selEl.appendChild(img);
            } else {
                const span = document.createElement('span');
                span.className = 'emoji';
                span.textContent = '🏳️';
                selEl.appendChild(span);
            }
        } else {
            const span = document.createElement('span');
            span.className = 'emoji';
            span.textContent = '🌍';
            selEl.appendChild(span);
        }

        const label = document.createElement('span');
        label.className = 'label';
        label.textContent = name;
        selEl.appendChild(label);

        // Chart-Daten setzen
        const dataset = [];
        for (let i = 1; i <= MAX_ETAGE; i++) {
            dataset.push((rawData?.[type]?.[name]?.[i]?.length) || 0);
        }
        chart.data.datasets[0].data = dataset;
        chart.update();

        closeMenu();
    });
});

const chart = new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: Array.from({length: MAX_ETAGE}, (_, i) => `${i + 1}. Etage`),
        datasets: [{
            label: 'Bewohner',
            backgroundColor: '#11a50d',
            data: Array(MAX_ETAGE).fill(0)
        }]
    },
    options: {
        responsive: true,
        plugins: {
            tooltip: {
                callbacks: {
                    afterLabel: function(ctx) {
                        const etage = ctx.dataIndex + 1;
                        if (!currentSelection) return '';
                        const { type, name } = currentSelection;
                        const eintraege = (rawData?.[type]?.[name]?.[etage]) || [];
                        eintraege.sort((a, b) => (parseInt(a.room,10) || 0) - (parseInt(b.room,10) || 0));
                        return eintraege.map(e => e.name + " (" + e.room + ")");
                    }
                }
            },
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, color: '#ccc' },
                grid: { color: '#333' }
            },
            x: {
                ticks: { color: '#ccc' },
                grid: { color: '#333' }
            }
        }
    }
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
