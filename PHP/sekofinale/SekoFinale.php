<?php
session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <meta name="format-detection" content="telefon=no">
    <link rel="stylesheet" href="../WEH.css" media="screen">
    </head>

<?php
require('../template.php');
if (auth($conn) && (!$_SESSION["Webmaster"]) ) {
    header("Location: denied.php");
}

$csvFile = '/WEH/PHP/sekofinale/kategorien.csv';
$categories = [];

if (!file_exists($csvFile)) {
    die("CSV-Datei nicht gefunden: $csvFile");
}

if (($handle = fopen($csvFile, "r")) !== false) {
    $header = fgetcsv($handle, 10000, ";");
    foreach ($header as $col) {
        $categories[trim($col)] = [];
    }
    $lineNumber = 0;
    while (($data = fgetcsv($handle, 10000, ";")) !== false) {
        #if ($lineNumber++ === 0) continue;
        foreach ($header as $index => $col) {
            if (!empty($data[$index])) {
                $categories[trim($col)][] = trim($data[$index]);
            }
        }
    }
    fclose($handle);
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Frühstücksmeeting-Finale</title>
    <style>
        body {
            background-color: #121212;
            color: #eee;
            font-family: Arial, sans-serif;
        }
        .ersatzbody {
            display: flex;
            justify-content: center;            
        }
        .container {
            width: 100%;
            max-width: 800px;
            text-align: center;
        }
        h1 {
            color: #4caf50;
        }
        .back-btn, .top-btn {
            background-color: #333;
            color: white;
            padding: 12px 20px;
            margin: 10px 5px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .back-btn:hover, .top-btn:hover {
            background-color: #4caf50;
        }
        .category-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
        justify-content: center;
        }

        .category-item {
        background-color: #333;
        color: #eee;
        padding: 10px 16px;
        border: 1px solid #555;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        }

        .category-item:hover {
        background-color: #4caf50;
        color: #000;
        }

        .category-item.highlight {
        background-color: #4caf50;
        color: #000;
        }

        .grid {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .search-input {
            padding: 10px;
            width: 100%;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .item-button {
            padding: 8px;
            border: none;
            border-radius: 4px;
            background-color: #333;
            color: #eee;
            cursor: pointer;
        }
        .item-button.used {
            background-color: #1e1e1e;
            color: #777;
            cursor: default;
        }
        .hidden { display: none; }
        .top-controls {
            margin-bottom: 20px;
        }
        .player-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 8px 0;
        }
        .player-row input {
            padding: 6px 10px;
            font-size: 16px;
        }
        .player-row button {
            padding: 6px 12px;
            background-color: #a00;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .player-row button:hover {
            background-color: #d00;
        }

#player-count-selector {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 20px;
}

#player-count-selector .top-btn {
  background-color: #2c2c2c;
  color: #eee;
  padding: 10px 18px;
  font-size: 16px;
  font-weight: bold;
  border: 2px solid #555;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

#player-count-selector .top-btn:hover {
  background-color: #4caf50;
  color: #000;
  border-color: #4caf50;
  transform: translateY(-1px);
}

#player-count-selector .top-btn:active {
  background-color: #388e3c;
  transform: scale(0.98);
  box-shadow: none;
}

#player-count-selector .top-btn.selected {
  background-color: #4caf50;
  color: #000;
  border-color: #4caf50;
}

.hold-btn {
  position: relative;
  display: inline-block;
}

.hold-btn .progress-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  height: 4px;
  width: 0%;
  background-color: #4caf50;
  transition: width 1s linear;
}



        .select-current-btn {
        background-color: #555 !important; /* Standard grau */
        }

        .select-current-btn.active {
        background-color: #0a0 !important; /* Nur der aktuelle Spieler wird grün */
        }

        .select-current-btn:hover {
        background-color: #0f0 !important;
        }



    </style>
</head>
<body>
    

<div class="ersatzbody">
    <div style="display: flex; gap: 40px; align-items: flex-start; padding: 20px;" class="hidden">
        <div style="flex-grow: 1;">
            <div class="container">
                <div style="align-items: center;">
                    <h1 style="margin: 0;">Frühstücksmeeting-Finale</h1>
                    <h2 style="margin: 0;">Gamemaster Ansicht</h2>
                </div>
                
                <button class="top-btn" onclick="resetGame()">🔁 Reset</button>

                <div id="player-count-selector">
                    <div class="hold-btn" data-count="3">
                        <button class="top-btn">3</button>
                        <div class="progress-bar"></div>
                    </div>
                    <div class="hold-btn" data-count="4">
                        <button class="top-btn">4</button>
                        <div class="progress-bar"></div>
                    </div>
                    <div class="hold-btn" data-count="5">
                        <button class="top-btn">5</button>
                        <div class="progress-bar"></div>
                    </div>
                </div>

                <div class="player-setup" style="margin-bottom: 30px;">
                    <h2>Spieler</h2>
                    <div id="player-controls"></div>
                </div>

                <div style="position: relative; display: inline-block; margin-top: 20px;">
                    <button class="back-btn" id="back-btn-hold">🔙 Zurück zur Auswahl</button>
                    <div id="back-progress" style="
                        position: absolute;
                        bottom: 0;
                        left: 0;
                        height: 4px;
                        background-color: #4caf50;
                        width: 0%;
                        transition: width 1s linear;">
                    </div>
                </div>

                <h2 id="category-title"></h2>

                <div class="top-controls" style="margin-top: 10px;">
                    <button class="top-btn" onclick="playSound('countdown')">⏳ Countdown</button>
                    <button class="top-btn" onclick="playSound('incorrect')">❌ Falsch</button>
                </div>
            </div>
        </div>

        <div id="category-view" style="width: 60%; flex-shrink: 0; margin-left: auto;"> 

            <div class="category-list">
            <?php foreach ($categories as $cat => $items): ?>
                <div class="category-item" onclick="selectCategory('<?= htmlspecialchars($cat) ?>')">
                    <?= htmlspecialchars($cat) ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div id="game-view" class="hidden" style="width: 60%; flex-shrink: 0; margin-left: auto;">
            <input type="text" id="search" class="search-input" placeholder="Suchbegriff..." oninput="updateList()" style="width: 100%; margin-bottom: 10px;">
            <div id="item-list" class="grid"></div>
            <p id="done-msg" class="hidden"><strong>Alle Optionen ausgewählt!</strong></p>
            <audio id="audio-player"></audio>
        </div>
    </div>
</div>


<script>
    const categories = JSON.parse(<?= json_encode(json_encode($categories, JSON_UNESCAPED_UNICODE)) ?>);
    console.log("✅ Kategorien geladen:", categories); // Debug-Ausgabe

    let currentCategory = null;
    let usedItems = [];
    let isCountdownPlaying = false;


    let players = [
        { name: "", score: 0, out: false },
        { name: "", score: 0, out: false },
        { name: "", score: 0, out: false },
        { name: "", score: 0, out: false },
        { name: "", score: 0, out: false }
    ];

    const sounds = {
        correct:    "Sounds/correct.mp3",
        incorrect:  "Sounds/incorrect.mp3",
        countdown:  "Sounds/countdown.wav",
        success:    "Sounds/success.wav"
    };

    const player = document.getElementById("audio-player");


function playSound(key) {
    if (!sounds[key]) return;

    const currentIndex = window.currentPlayerIndexState ?? 0;

    const excludeCurrentPlayer = () => {
        players[currentIndex].out = true;

        const active = players
            .map((p, i) => ({ ...p, i }))
            .filter(p => !p.out);

        if (active.length > 0) {
            const nextIndex = active.find(p => p.i > currentIndex)?.i ?? active[0].i;
            window.currentPlayerIndexState = nextIndex;
        } else {
            window.currentPlayerIndexState = 0;
        }

        renderPlayerControls();
        syncState();
    };

    if (key === 'countdown') {
        if (isCountdownPlaying) {
            player.pause();
            player.currentTime = 0;
            isCountdownPlaying = false;
            player.onended = null;
            return;
        }

        isCountdownPlaying = true;
        player.onended = () => {
            isCountdownPlaying = false;
            excludeCurrentPlayer();
            player.onended = null;
        };
    } else {
        player.onended = null;
    }

    if (key === 'incorrect') {
        excludeCurrentPlayer();
    }

    player.pause();
    player.currentTime = 0;
    player.src = sounds[key];
    player.play().catch(() => {});
}




function selectCategory(cat) {
    currentCategory = cat;
    usedItems = [];
    document.getElementById("category-view").classList.add("hidden");
    document.getElementById("game-view").classList.remove("hidden");
    document.getElementById("category-title").textContent = cat;
    document.getElementById("search").value = "";
    updateList();
    syncState(); // hier hinzufügen
}


function backToMenu() {
    // Punkte +1 für aktive Spieler
    players.forEach(p => {
        if (!p.out) {
            p.score = (p.score ?? 0) + 1;
        }
    });

    currentCategory = "";
    document.getElementById("category-view").classList.remove("hidden");
    document.getElementById("game-view").classList.add("hidden");

    players.forEach(p => p.out = false);
    window.currentPlayerIndexState = 0;

    renderPlayerControls();
    syncState();
}





function updateList() {
    const query = document.getElementById("search").value.toLowerCase();
    const list = document.getElementById("item-list");
    const doneMsg = document.getElementById("done-msg");
    list.innerHTML = "";
    let anyShown = false;

    categories[currentCategory].forEach(item => {
        if (item.toLowerCase().includes(query)) {
            const btn = document.createElement("button");
            btn.textContent = item;
            btn.className = "item-button";
            if (usedItems.includes(item)) {
                btn.classList.add("used");
                btn.disabled = true;
            } else {
                btn.onclick = () => markUsed(item);
            }
            list.appendChild(btn);
            anyShown = true;
        }
    });

    if (!anyShown) {
        list.innerHTML = "<p>Keine Ergebnisse.</p>";
    }

    const allUsed = categories[currentCategory].every(item => usedItems.includes(item));
    window.allOptionsUsed = allUsed; // 👈 speicher Zustand global
    if (allUsed) {
        doneMsg.classList.remove("hidden");
        playSound("success");
    } else {
        doneMsg.classList.add("hidden");
    }

    syncState(); // 👈 sendet den neuen Zustand mit
}


function markUsed(item) {
    if (!usedItems.includes(item)) {
        usedItems.push(item);
        // Bestimme aktive Spieler
        const activeIndexes = players
            .map((p, i) => ({ p, i }))
            .filter(entry => !entry.p.out)
            .map(entry => entry.i);

        // Erhöhe Index des aktuellen Spielers
        if (typeof window.currentPlayerIndexState !== "number") {
            window.currentPlayerIndexState = activeIndexes[0];
        } else {
            const current = window.currentPlayerIndexState;
            const next = activeIndexes[(activeIndexes.indexOf(current) + 1) % activeIndexes.length];
            window.currentPlayerIndexState = next;
        }
        renderPlayerControls();
        playSound("correct");
        document.getElementById("search").value = "";
        updateList();
        syncState();

        // Fokus zurück ins Suchfeld
        document.getElementById("search").focus();
    }
}

function renderPlayerControls() {
    const container = document.getElementById("player-controls");
    container.innerHTML = "";
    players.forEach((player, index) => {
        const row = document.createElement("div");
        row.className = "player-row";

        // Name-Feld
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.placeholder = `Spieler ${index + 1}`;
        nameInput.value = player.name;
        nameInput.oninput = () => {
            players[index].name = nameInput.value;
            syncState();
        };

        // Score-Feld
        const scoreInput = document.createElement("input");
        scoreInput.type = "number";
        scoreInput.min = "0";
        scoreInput.value = player.score ?? 0;
        scoreInput.style.width = "60px";
        scoreInput.oninput = () => {
            players[index].score = parseInt(scoreInput.value) || 0;
            syncState();
        };

        // Neuer Button: Aktuellen Spieler festlegen
        const selectBtn = document.createElement("button");
        selectBtn.textContent = "O";
        selectBtn.className = "select-current-btn";
        if (index === window.currentPlayerIndexState) {
            selectBtn.classList.add("active");
        }
        selectBtn.onclick = () => {
            window.currentPlayerIndexState = index;
            renderPlayerControls(); // neu rendern, damit der Buttonstatus aktualisiert wird
            syncState();
        };

        // Out-Button
        const btn = document.createElement("button");
        btn.textContent = player.out ? "Reaktivieren" : "X";
        btn.onclick = () => {
            const wasActive = index === window.currentPlayerIndexState;

            players[index].out = !players[index].out;

            renderPlayerControls();

            if (players[index].out && wasActive) {
                const active = players
                    .map((p, i) => ({ ...p, i }))
                    .filter(p => !p.out);

                if (active.length > 0) {
                    const current = index;
                    const nextIndex = active.find(p => p.i > current)?.i ?? active[0].i;
                    window.currentPlayerIndexState = nextIndex;
                } else {
                    window.currentPlayerIndexState = 0;
                }
                renderPlayerControls();
            }


            syncState();
        };

        row.appendChild(nameInput);
        row.appendChild(scoreInput);
        row.appendChild(selectBtn);
        row.appendChild(btn);
        container.appendChild(row);
    });
}


    renderPlayerControls();

function syncState() {
    fetch('update_state.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            category: currentCategory,
            answers: usedItems,
            players: players,
            currentPlayerIndex: window.currentPlayerIndexState || 0,
            allOptionsUsed: window.allOptionsUsed || false // 👈 hier mit rein
        })
    }).catch(err => console.error("State-Sync fehlgeschlagen:", err));
}

function setPlayerCount(n) {
  const template = { name: "", score: 0, out: false };
  players = Array.from({ length: n }, () => ({ ...template }));
  window.currentPlayerIndexState = 0;
  renderPlayerControls();
  syncState();

  // Button visuell hervorheben
  document.querySelectorAll("#player-count-selector .top-btn").forEach(btn => {
    btn.classList.remove("selected");
  });
  const btn = [...document.querySelectorAll("#player-count-selector .top-btn")]
    .find(b => b.textContent.includes(n));
  if (btn) btn.classList.add("selected");
}



// Initialwert für currentPlayerIndexState und Spieler aus JSON holen
fetch('live_state.json')
  .then(res => res.json())
  .then(data => {
    if (typeof data.currentPlayerIndex === "number") {
      window.currentPlayerIndexState = data.currentPlayerIndex;
    }
    if (Array.isArray(data.players)) {
      players = data.players;
    }
    renderPlayerControls();
  })
  .catch(err => console.error("Index-Laden fehlgeschlagen:", err));

  function resetGame() {
    if (!confirm("Wirklich alles zurücksetzen?")) return;

    fetch('reset_state.php', { method: 'POST' })
      .then(() => {
        window.location.reload();
      })
      .catch(err => console.error("Reset fehlgeschlagen:", err));
}

// Halten für 1 Sekunde + Ladebalken
const backBtn = document.getElementById("back-btn-hold");
const progressBar = document.getElementById("back-progress");

let holdTimeout;
let holdActive = false;

backBtn.addEventListener("mousedown", () => {
  holdActive = true;
  progressBar.style.transition = "width 1s linear";
  progressBar.style.width = "100%";

  holdTimeout = setTimeout(() => {
    if (holdActive) {
      backToMenu();
    }
  }, 1000);
});

["mouseup", "mouseleave", "touchend", "touchcancel"].forEach(evt => {
  backBtn.addEventListener(evt, () => {
    holdActive = false;
    clearTimeout(holdTimeout);
    progressBar.style.transition = "none";
    progressBar.style.width = "0%";
  });
});


document.querySelectorAll('.hold-btn').forEach(wrapper => {
  const btn = wrapper.querySelector("button");
  const progress = wrapper.querySelector(".progress-bar");
  const count = parseInt(wrapper.dataset.count, 10);
  let timeout;
  let active = false;

  const reset = () => {
    clearTimeout(timeout);
    active = false;
    progress.style.transition = "none";
    progress.style.width = "0%";
  };

  btn.addEventListener("mousedown", () => {
    active = true;
    progress.style.transition = "width 1s linear";
    progress.style.width = "100%";

    timeout = setTimeout(() => {
      if (active) {
        setPlayerCount(count);
      }
    }, 1000);
  });

  ["mouseup", "mouseleave", "touchend", "touchcancel"].forEach(evt => {
    btn.addEventListener(evt, reset);
  });
});


</script>

</body>
</html>
