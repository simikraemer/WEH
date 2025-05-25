<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Liveview - Spieler</title>
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
      position: relative;
    }

    .right {
      background-color: #1a1a1a;
      padding: 40px;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
    }

    .right.center-content {
      justify-content: center;
      align-items: center;
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
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  max-width: 400px;
  width: 100%;
  border-collapse: collapse;
  font-size: 18px;
  background-color: #181818;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 0 12px rgba(0, 0, 0, 0.5);
}

.scoreboard th, .scoreboard td {
  padding: 12px 16px;
  border-bottom: 1px solid #333;
}

.scoreboard th {
  background-color: #202020;
  color: #4caf50;
  text-align: left;
  text-transform: uppercase;
  font-size: 14px;
  letter-spacing: 1px;
}

.scoreboard tr:last-child td {
  border-bottom: none;
}

.scoreboard tr:hover {
  background-color: #2a2a2a;
}

.scoreboard td {
  color: #ddd;
  transition: background-color 0.3s;
}

.scoreboard td.name {
  font-weight: bold;
  color: #fff;
}


    .scoreboard td.name {
      font-weight: bold;
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

    .answers-list {
      margin-top: 20px;
    }

    .answer-item {
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

      const stack = document.getElementById("player-stack");
      stack.innerHTML = "";

      const scoreboardBody = document.getElementById("scoreboard").querySelector("tbody");
      scoreboardBody.innerHTML = "";

      // Dynamisch den Abstand der Scoreboard-Tabelle setzen
      const scoreboard = document.getElementById("scoreboard");
      if (scoreboard) {
        const playerCount = (data.players || []).filter(p => !p.out).length;
        // if (playerCount === 1) {
        //   scoreboard.style.bottom = "300px";
        // } else if (playerCount === 2) {
        //   scoreboard.style.bottom = "250px";
        // } else if (playerCount === 3) {
        //   scoreboard.style.bottom = "200px";
        // } else if (playerCount === 4) {
        //   scoreboard.style.bottom = "150px";
        // } else {
        //   scoreboard.style.bottom = "100px";
        // }
        scoreboard.style.bottom = "150px";
      }

      // Dynamisch den oberen Abstand des Player-Stacks anpassen
      const playerStack = document.getElementById("player-stack");
      if (playerStack) {
        const playerCount = (data.players || []).filter(p => !p.out).length;
        if (playerCount === 1) {
          playerStack.style.marginTop = "220px";
        } else if (playerCount === 2) {
          playerStack.style.marginTop = "170px";
        } else if (playerCount === 3) {
          playerStack.style.marginTop = "120px";
        } else if (playerCount === 4) {
          playerStack.style.marginTop = "70px";
        } else {
          playerStack.style.marginTop = "20px";
        }
      }


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

      const right = document.getElementById("right-content");
      const info = document.getElementById("right-info");
      const rightCol = document.querySelector(".right"); // neu

      right.innerHTML = "";
      info.innerHTML = "";
      rightCol.classList.remove("center-content"); // immer zurücksetzen

      if (!data.category || data.category.trim() === "") {
        rightCol.classList.add("center-content"); // nur wenn Kategorie-Auswahl

        const catList = document.createElement("div");
        catList.className = "category-list";

        (data.allCategories || []).forEach(cat => {
          if ((data.usedCategories || []).includes(cat)) return;

          const btn = document.createElement("div");
          btn.className = "category-item";
          btn.textContent = cat;

          btn.onclick = () => {
            document.querySelectorAll('.category-item').forEach(el => {
              el.classList.add("fade");
              el.classList.remove("highlight");
            });
            btn.classList.remove("fade");
            btn.classList.add("highlight");
          };

          catList.appendChild(btn);
        });

        right.appendChild(catList);
      } else {
        const title = document.createElement("div");
        title.className = "category-title";
        title.textContent = data.category;
        right.appendChild(title);

        const answerList = document.createElement("div");
        answerList.className = "answers-list";

        const answers = data.answers || [];
        answers.forEach((ans) => {
          const div = document.createElement("div");
          div.className = "answer-item";
          div.textContent = ans;
          answerList.appendChild(div);
        });

        right.appendChild(answerList);
        const scrollContainer = document.querySelector(".right");
        scrollContainer.scrollTop = scrollContainer.scrollHeight;


        if (data.allOptionsUsed === true) {
          info.textContent = "✅ Alle Optionen gewählt!";
        }
      }
    } catch (err) {
      console.error("Fehler beim Laden von live_state.json:", err);
    }
  }

  setInterval(poll, 1000);
  poll();
</script>

</body>
</html>
