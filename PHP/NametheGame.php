<?php
session_start();
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && isset($_SESSION['Webmaster']) && $_SESSION['Webmaster'] === true) {
    
    $videoDir = "videos/namethegame/";
    $videos = array_diff(scandir($videoDir), array('..', '.'));
    $videoFiles = array_values(array_filter($videos, function($file) {
        return preg_match('/\.(mp4|webm|ogg)$/i', $file);
    }));

    if (empty($videoFiles)) {
        die("Keine Videos im Ordner vorhanden!");
    }
    shuffle($videoFiles);
?>




<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title>Name the Game</title>
    <style>
        body { text-align: center; font-family: Arial, sans-serif; }
        .videoContainer { position: relative; display: inline-block; }
        canvas { background: black; display: block; margin: auto; }
        #controls { margin-top: 20px; }
        button { margin: 5px; padding: 10px; font-size: 16px; }

        #timerContainer {
            width: 100%;
            max-width: 600px;
            height: 25px;
            background: #ddd;
            border-radius: 5px;
            margin: 10px auto;
            position: relative;
        }
        
        #timerBar {
            width: 0%;
            height: 100%;
            background: #11a50d; /* Grüner Balken */
            border-radius: 5px;
            transition: width 1s linear;
        }

        #timerText {
            position: absolute;
            width: 100%;
            text-align: center;
            top: 2px;
            font-size: 18px;
            font-weight: bold;
        }

        #videoNav {
            margin-bottom: 10px;
        }

        #videoBanner {
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            height: 50px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            font-size: 30px;
            text-align: center;
            line-height: 50px;
            display: none; 
        }
    </style>
</head>
<body>

    <h1>Name the Game</h1>

    <div id="videoNav">
        <button class="weh-btn" onclick="prevVideo()">← Vorheriges Video</button>
        <button class="weh-btn" onclick="nextVideo()">Nächstes Video →</button>
    </div>

    <div id="timerContainer">
        <div id="timerBar"></div>
        <div id="timerText">60s</div>
    </div>

    <div class="videoContainer">
        <canvas id="gameCanvas"></canvas>
        <div id="videoBanner"></div>
    </div>

    <div id="controls">
        <button class="weh-btn" onclick="reveal()">Auflösen</button>
    </div>

    <video id="gameVideo" style="display:none;"></video>

    <script>
        const videoFiles = <?php echo json_encode($videoFiles); ?>;
        const videoDir = "<?php echo $videoDir; ?>";
        let currentVideoIndex = 0;

        const canvas = document.getElementById("gameCanvas");
        const ctx = canvas.getContext("2d");
        const video = document.getElementById("gameVideo");
        const videoBanner = document.getElementById("videoBanner");
        const timerBar = document.getElementById("timerBar");
        const timerText = document.getElementById("timerText");

        let pixelSize = 40;
        let interval;
        let isRevealed = false;
        let timer;
        let countdown = 60;

        canvas.width = 1040;
        canvas.height = 585;

        function loadVideo(index) {
            video.src = videoDir + videoFiles[index];
            video.load();
            pixelSize = 60;
            isRevealed = false;
            videoBanner.style.display = "none";
            video.muted = true;
            countdown = 60;
            updateTimerDisplay();
            resetTimerBar();

            video.oncanplay = () => {
                video.play();
                drawPixelatedVideo();
                startPixelReduction();            
                if (!isRevealed) {
                    startTimer();
                }
            };
        }


        function drawPixelatedVideo() {
            if (!isRevealed && !video.paused && !video.ended) {
                ctx.drawImage(video, 0, 0, canvas.width / pixelSize, canvas.height / pixelSize);
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(canvas, 0, 0, canvas.width / pixelSize, canvas.height / pixelSize, 0, 0, canvas.width, canvas.height);
                requestAnimationFrame(drawPixelatedVideo);
            }
        }

        function startPixelReduction() {
            clearInterval(interval);
            interval = setInterval(() => {
                if (pixelSize > 1) {
                    pixelSize -= 1;
                } else {
                    clearInterval(interval);
                }
            }, 1000);
        }

        function startTimer() {
            clearInterval(timer);
            timer = setInterval(() => {
                countdown--;
                updateTimerDisplay();

                // Nach 30s: Ton aktivieren
                if (countdown === 30) {
                    video.muted = false;
                }

                // Nach 60s: Automatische Auflösung
                if (countdown <= 0) {
                    clearInterval(timer);
                    reveal();
                }

                updateTimerBar();
            }, 1000);
        }

        function updateTimerDisplay() {
            timerText.innerText = countdown + "s";
        }

        function updateTimerBar() {
            let progress = (60 - countdown) / 60 * 100;
            timerBar.style.width = progress + "%";
        }

        function resetTimerBar() {
            timerBar.style.width = "0%";
        }

        function pauseTimer() {
            if (!isRevealed) {
                clearInterval(timer); // Timer pausieren
            }
        }

        function resumeTimer() {
            if (!isRevealed) {
                startTimer(); // Timer erneut starten
            }
        }

        function togglePlayPause() {
            if (video.paused) {
                if (isRevealed) {
                    reveal();
                } else {
                    video.play();
                    resumeTimer();
                }
            } else {
                video.pause();
                pauseTimer();
            }
        }

        function reveal() {            
            if (video.paused) {
                video.play();
            }

            video.muted = false;
            clearInterval(interval);
            clearInterval(timer);
            isRevealed = true;
            
            timerBar.style.width = "100%";
            timerText.innerText = "Finito!";

            videoBanner.innerText = videoFiles[currentVideoIndex].replace(/\.[^/.]+$/, ""); // Titel anzeigen
            videoBanner.style.display = "block"; // Banner einblenden

            function drawClearVideoFrame() {
                if (!video.paused && !video.ended && isRevealed) {
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    requestAnimationFrame(drawClearVideoFrame); // Nächstes Frame zeichnen
                }
            }

            drawClearVideoFrame(); // Starte den Rendering-Loop
        }


        function prevVideo() {
            if (currentVideoIndex > 0) {
                currentVideoIndex--;
                loadVideo(currentVideoIndex);
                resetCanvas();
            }
        }

        function nextVideo() {
            if (currentVideoIndex < videoFiles.length - 1) {
                currentVideoIndex++;
                loadVideo(currentVideoIndex);
                resetCanvas();
            }
        }

        function resetCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height); // Canvas löschen
            videoBanner.style.display = "none"; // Banner ausblenden
            isRevealed = false; // Verpixelung wieder aktivieren
        }

        video.addEventListener("play", () => {
            if (!isRevealed) {
                drawPixelatedVideo();
                startPixelReduction();
            }
        });

        
        video.addEventListener("ended", () => {
            video.currentTime = 0; // Zurück an den Anfang
            video.play(); // Direkt wieder starten

            if (!isRevealed) {
                drawPixelatedVideo(); // Falls noch verpixelt, erneut pixeln
                startPixelReduction(); // Reduzierung läuft weiter
                startTimer();
            } else {
                drawClearVideoFrame(); // Falls schon "Aufgelöst", weiter klar rendern
            }
        });

        canvas.addEventListener("click", togglePlayPause);

        loadVideo(currentVideoIndex);
    </script>

</body>
</html>





<?php
} else {
    header("Location: denied.php");
    exit();
}

$conn->close();
?>