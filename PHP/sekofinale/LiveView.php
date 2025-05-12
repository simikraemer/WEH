<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Liveview – Spieler</title>
  <style>
    body {
      margin: 0;
      background-color: #121212;
      color: #eee;
      font-family: Arial, sans-serif;
      display: flex;
      height: 100vh;
    }

    .left, .right {
      flex: 1;
      padding: 40px;
      box-sizing: border-box;
      overflow-y: auto;
    }

    .left {
      background-color: #181818;
      border-right: 2px solid #333;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .right {
      background-color: #1a1a1a;
    }

    .player-stack {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
      margin-top: 40px;
      position: relative;
    }

    .player-block {
      padding: 12px 24px;
      border: 2px solid white;
      border-radius: 6px;
      font-size: 20px;
      min-width: 160px;
      text-align: center;
      background-color: transparent;
      transition: background-color 0.3s;
    }

    .player-block.active {
      background-color: #1a4d1a;
      border-color: #4caf50;
    }

    .arrow {
      font-size: 32px;
      color: white;
    }

    .scoreboard {
      margin-top: 60px;
      width: 100%;
      max-width: 400px;
      border-collapse: collapse;
      font-size: 18px;
    }

    .scoreboard th, .scoreboard td {
      padding: 12px 16px;
      border-bottom: 1px solid #444;
    }

    .scoreboard th {
      background-color: #222;
      color: #4caf50;
      text-align: left;
    }

    .scoreboard tr:hover {
      background-color: #2a2a2a;
    }

    .scoreboard td {
      color: #ddd;
    }

    .scoreboard td.name {
      font-weight: bold;
    }

    .category-list, .answers-list {
      margin-top: 0px;
    }

    .category-item, .answer-item {
      background: #333;
      color: #eee;
      padding: 12px 20px;
      border-radius: 6px;
      margin-bottom: 10px;
      text-align: center;
    }

    .category-title {
      font-size: 24px;
      margin-bottom: 20px;
      color: #4caf50;
    }

    #right-info {
      margin-top: 20px;
      text-align: center;
      font-size: 20px;
      color: #4caf50;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="left">
    <h2>Aktuelle Reihenfolge</h2>
    <div id="player-stack" class="player-stack"></div>

    <table class="scoreboard" id="scoreboard">
      <thead>
        <tr>
          <th>Spieler</th>
          <th>Punkte</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <div class="right">
    <div id="right-content"></div>
    <div id="right-info"></div>
  </div>

  <script>
    async function poll() {
      try {
        const res = await fetch('live_state.json');
        const data = await res.json();

        // ========== LINKER BEREICH ==========
        const stack = document.getElementById("player-stack");
        stack.innerHTML = "";

        const scoreboardBody = document.getElementById("scoreboard").querySelector("tbody");
        scoreboardBody.innerHTML = "";

        const allPlayers = (data.players || []).map((p, i) => ({ ...p, index: i }));
        const activePlayers = allPlayers.filter(p => !p.out);
        const currentPlayerIndex = data.currentPlayerIndex ?? 0;

        activePlayers.forEach((player, idx) => {
          const div = document.createElement("div");
          div.className = "player-block";
          if (player.index === currentPlayerIndex) {
            div.classList.add("active");
          }
          div.textContent = player.name.trim() !== "" ? player.name : `Spieler ${player.index + 1}`;
          stack.appendChild(div);

          if (idx < activePlayers.length - 1) {
            const arrow = document.createElement("div");
            arrow.className = "arrow";
            arrow.textContent = "↓";
            stack.appendChild(arrow);
          }
        });

        allPlayers
          .filter(p => !p.out)
          .sort((a, b) => (b.score ?? 0) - (a.score ?? 0))
          .forEach(p => {
            const tr = document.createElement("tr");
            const name = document.createElement("td");
            const score = document.createElement("td");
            name.className = "name";
            name.textContent = p.name.trim() !== "" ? p.name : `Spieler ${p.index + 1}`;
            score.textContent = p.score ?? 0;
            tr.appendChild(name);
            tr.appendChild(score);
            scoreboardBody.appendChild(tr);
          });

        // ========== RECHTER BEREICH ==========
        const right = document.getElementById("right-content");
        const info = document.getElementById("right-info");
        right.innerHTML = "";
        info.innerHTML = "";

        if (!data.category || data.category.trim() === "") {
          const catList = document.createElement("div");
          catList.className = "category-list";

(data.allCategories || []).forEach(cat => {
  // bereits verwendete Kategorien rausfiltern
  const used = (data.usedCategories || []);
  if (used.includes(cat)) return;

  const div = document.createElement("div");
  div.className = "category-item";
  div.textContent = cat;
  catList.appendChild(div);
});


          right.appendChild(catList);
        } else {
          const title = document.createElement("div");
          title.className = "category-title";
          title.textContent = data.category;
          right.appendChild(title);

          const answerList = document.createElement("div");
          answerList.className = "answers-list";

const reversedAnswers = (data.answers || []).slice().reverse();

reversedAnswers.forEach((ans, i) => {
  const div = document.createElement("div");
  div.className = "answer-item";
  div.textContent = `${reversedAnswers.length - i} – ${ans}`;
  answerList.appendChild(div);
});


          right.appendChild(answerList);

          // ✅ Anzeige: Alle Begriffe gewählt (über Backend-Status)
          if (data.allOptionsUsed === true) {
            info.textContent = "✅ Alle Optionen gewählt!";
          }
        }

      } catch (err) {
        console.error("Fehler beim Laden von live_state.json:", err);
      }
    }

    setInterval(poll, 500);
    poll();
  </script>
</body>
</html>
