<?php
$csvFile = '/WEH/PHP/sekofinale/kategorien.csv';
$categories = [];

if (!file_exists($csvFile)) {
    die("CSV-Datei nicht gefunden: $csvFile");
}

if (($handle = fopen($csvFile, "r")) !== false) {
    $header = fgetcsv($handle, 1000, ";");
    foreach ($header as $col) {
        $categories[trim($col)] = [];
    }
    $lineNumber = 0;
    while (($data = fgetcsv($handle, 1000, ";")) !== false) {
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
            display: flex;
            justify-content: center;
            padding: 40px;
        }
        .container {
            width: 100%;
            max-width: 600px;
            text-align: center;
        }
        h1 {
            color: #4caf50;
        }
        .category-btn, .back-btn, .top-btn {
            background-color: #333;
            color: white;
            padding: 12px 20px;
            margin: 10px 5px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .category-btn:hover, .back-btn:hover, .top-btn:hover {
            background-color: #4caf50;
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
        .select-current-btn {
            background-color: #0a0 !important;
        }
        .select-current-btn:hover {
            background-color: #0f0 !important;
        }


    </style>
</head>
<body>
    



<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center;">
  <h1 style="margin: 0;">Frühstücksmeeting-Finale</h1>
  <button class="top-btn" onclick="resetGame()">🔁 Reset</button>
</div>

    <div class="player-setup" style="margin-bottom: 30px;">
        <h2>Spieler</h2>
        <div id="player-controls"></div>
    </div>

    <div id="category-view"> 
        <p>Wähle eine Kategorie:</p>       

        <?php foreach ($categories as $cat => $items): ?>
            <button class="category-btn" onclick="selectCategory('<?= htmlspecialchars($cat) ?>')">
                <?= htmlspecialchars($cat) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div id="game-view" class="hidden">
        <button class="back-btn" onclick="backToMenu()">Zurück zur Auswahl</button>
        <h2 id="category-title"></h2>

        <div class="top-controls">
            <button class="top-btn" onclick="playSound('countdown')">Countdown</button>
            <button class="top-btn" onclick="playSound('incorrect')">Falsch</button>
        </div>

        <input type="text" id="search" class="search-input" placeholder="Suchbegriff..." oninput="updateList()">
        <div id="item-list" class="grid"></div>
        <p id="done-msg" class="hidden"><strong>Alle Optionen ausgewählt!</strong></p>

        <!-- Sound-Player (dynamisch) -->
        <audio id="audio-player"></audio>
    </div>
</div>

<script>
    const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
    let currentCategory = null;
    let usedItems = [];
    let isCountdownPlaying = false;


    let players = [
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

    // Nur für Countdown toggeln
    if (key === 'countdown') {
        if (isCountdownPlaying) {
            player.pause();
            player.currentTime = 0;
            isCountdownPlaying = false;
            return;
        }
        isCountdownPlaying = true;
        player.onended = () => isCountdownPlaying = false;
    }

    player.pause();
    player.currentTime = 0;
    player.src = sounds[key];
    player.play().catch(e => console.log("Audio blockiert:", e));
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
    currentCategory = ""; // statt null
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
        playSound("correct");
        document.getElementById("search").value = "";
        updateList();
        syncState();
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
        selectBtn.onclick = () => {
            window.currentPlayerIndexState = index;
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


</script>

</body>
</html>
