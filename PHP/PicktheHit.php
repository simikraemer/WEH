<?php
session_start();
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && isset($_SESSION['Webmaster']) && $_SESSION['Webmaster'] === true) {
    
    $videoDir = "videos/nametheframe/";
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
    <title>Pick the Hit</title>
    <style>
        body { text-align: center; font-family: Arial, sans-serif; }
        .videoContainer { position: relative; display: inline-block; }
        canvas { background: black; display: block; margin: auto; }
        #controls { margin-top: 20px; }
        button { margin: 5px; padding: 10px; font-size: 16px; }

        /* Navigation-Buttons immer sichtbar */
        #videoNav {
            margin-bottom: 10px;
        }

        /* Banner für den Spieletitel */
        #videoBanner {
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            height: 50px;
            background: rgba(0, 0, 0, 0.5); /* Schwarzer Hintergrund mit 50% Transparenz */
            color: white;
            font-size: 30px;
            text-align: center;
            line-height: 50px;
            display: none; /* Erst sichtbar bei Auflösung */
        }
    </style>
</head>
<body>

    <h1>Pick the Hit</h1>

    <!-- Buttons für Video-Navigation -->
    <div id="videoNav">
        <button onclick="prevVideo()">⬅️ Vorheriges Video</button>
        <button onclick="nextVideo()">Nächstes Video ➡️</button>
    </div>

    <div class="videoContainer">
        <canvas id="gameCanvas"></canvas>
        <div id="videoBanner"></div>
    </div>

    <div id="controls">
        <button onclick="reveal()">Auflösen</button>
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
        const playPauseButton = document.getElementById("playPauseButton");

        let pixelSize = 40;
        let interval;
        let isRevealed = false;

        canvas.width = 1040;
        canvas.height = 585;

        function loadVideo(index) {
            video.src = videoDir + videoFiles[index];
            video.load();
            pixelSize = 40;
            isRevealed = false;
            videoBanner.style.display = "none"; // Banner ausblenden

            video.oncanplay = () => {
                video.play(); // Startet automatisch, sobald das Video bereit ist
                drawPixelatedVideo();
                startPixelReduction();
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

        function togglePlayPause() {
            if (video.paused) {
                if (isRevealed) {
                    reveal();
                } else {
                    video.play();
                    playPauseButton.innerText = "Pause";
                }
            } else {
                video.pause();
                playPauseButton.innerText = "Play";
            }
        }

        function reveal() {            
            if (video.paused) {
                video.play();
            }

            clearInterval(interval);
            isRevealed = true;
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