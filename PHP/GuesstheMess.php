<?php
session_start();
require('template.php');
if (auth($conn) && (!$_SESSION["Webmaster"]) ) {
    header("Location: denied.php");
}

    // Standardwerte setzen
    $mode = $_POST['mode'] ?? 'Name the Game';
    switch ($mode) {
        case 'Spot the Shot':
            $videoDir = "videos/spottheshot/";
            $unmuteTime = 30;
            break;
        case 'Pick the Hit':
            $videoDir = "videos/pickthehit/";
            $unmuteTime = 30;
            break;
        default:
            $videoDir = "videos/namethegame/";
            $unmuteTime = 30;
    }


    $videos = array_diff(scandir($videoDir), array('..', '.'));

    $videoFiles = array_values(array_filter($videos, function($file) {
        return preg_match('/^\d+\.\s.*\.(mp4|webm|ogg)$/i', $file);
    }));
    
    // Sortieren der Dateien basierend auf der Nummerierung am Anfang des Namens
    usort($videoFiles, function($a, $b) {
        return intval(explode('.', $a)[0]) - intval(explode('.', $b)[0]);
    });
    
    if (empty($videoFiles)) {
        die("Keine Videos im Ordner vorhanden!");
    }
    
    // Entferne die Nummerierung aus den Dateinamen für die Ausgabe
    $cleanVideoFiles = array_map(function($file) {
        return preg_replace('/^\d+\.\s/', '', $file);
    }, $videoFiles);

    $videoNumbers = array_flip(array_values($videoFiles)); // Erzeugt eine Map: filename -> Nummer
    $totalVideos = count($videoFiles);    
?>




<!DOCTYPE html>
<html lang="de">
<head>    
    <title>Guess the Mess</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css">
    <style>
        body { text-align: center; font-family: Arial, sans-serif; }
        .videoContainer { position: relative; display: inline-block; }
        canvas { background: black; display: block; margin: auto; }
        #controls { margin-top: 20px; }
        button { margin: 5px; padding: 10px; font-size: 16px; }

        #timerContainer {
            width: 100%;
            max-width: 650px;
            height: 25px;
            background: white;
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

    <form method="POST" id="gameModeButtons">
        <button type="submit" name="mode" value="Name the Game" class="weh-btn" 
            style="<?php echo ($mode == 'Name the Game') ? 'background:green; color:white;' : ''; ?>">
            Name the Game
        </button>
        
        <button type="submit" name="mode" value="Pick the Hit" class="weh-btn" 
            style="<?php echo ($mode == 'Pick the Hit') ? 'background:green; color:white;' : ''; ?>">
            Pick the Hit
        </button>
        
        <button type="submit" name="mode" value="Spot the Shot" class="weh-btn" 
            style="<?php echo ($mode == 'Spot the Shot') ? 'background:green; color:white;' : ''; ?>">
            Spot the Shot
        </button>
    </form>



    <div id="videoNav">
    <button class="weh-btn" onclick="prevVideo()" style="width: 320px;">
        ← Vorheriges Video
    </button>
    
    <button class="weh-btn" onclick="nextVideo()" style="width: 320px;">
        Nächstes Video →
    </button>
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
        const videoNumbers = <?php echo json_encode($videoNumbers); ?>;
        const videoDir = "<?php echo $videoDir; ?>";
        const totalVideos = <?php echo $totalVideos; ?>;
        let currentVideoIndex = 0;


        const canvas = document.getElementById("gameCanvas");
        const ctx = canvas.getContext("2d");
        const video = document.getElementById("gameVideo");
        const videoBanner = document.getElementById("videoBanner");
        const timerBar = document.getElementById("timerBar");
        const timerText = document.getElementById("timerText");        

        let pixelSize = 80;
        let interval;
        let isRevealed = false;
        let timer;
        let countdown = 60;
        let unmuteTime = <?php echo $unmuteTime; ?>;


        canvas.width = 1200;
        canvas.height = 675;

        function drawStartScreen() {
            ctx.fillStyle = "black";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "white";
            ctx.font = "bold 40px Arial";
            ctx.textAlign = "center";

            let videoFilename = videoFiles[currentVideoIndex];
            let videoNumber = videoNumbers[videoFilename] + 1; // Nummer basiert auf der Shuffle-Reihenfolge
            let text = `Video ${videoNumber} von ${totalVideos}`;
            ctx.fillText(text, canvas.width / 2, canvas.height / 2);

            ctx.font = "bold 30px Arial";
            ctx.fillText("Klick zum Starten", canvas.width / 2, canvas.height / 2 + 50);
        }



        function loadVideo(index) {
            countdown = 60;
            pixelSize = 80;
            video.src = videoDir + videoFiles[index];
            video.load();
            isRevealed = false;
            videoBanner.style.display = "none";
            video.muted = true;

            updateTimerDisplay();
            resetTimerBar();

            video.oncanplay = () => {
                //video.play();
                //drawPixelatedVideo();       
                //startPixelReduction();     
                //if (!isRevealed) {
                //    startTimer();
                //}    
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
                if (!video.paused && !video.ended && pixelSize > 1) {
                    pixelSize -= 15;
                } else {
                    clearInterval(interval);
                }
            }, 10000);
        }


        function startTimer() {
            clearInterval(timer);
            timer = setInterval(() => {
                countdown--;
                updateTimerDisplay();

                if (countdown === unmuteTime && video.muted) {
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
            timerText.innerText = countdown + " Punkte";

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
                drawStartScreen();
            }
        }

        function nextVideo() {
            if (currentVideoIndex < videoFiles.length - 1) {
                currentVideoIndex++;
                loadVideo(currentVideoIndex);
                resetCanvas();
                drawStartScreen();
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

        canvas.addEventListener("click", () => {
            if (video.paused && countdown === 60) { // Nur wenn das Video noch nicht gestartet ist
                ctx.clearRect(0, 0, canvas.width, canvas.height); // Startscreen entfernen
                video.play();
                drawPixelatedVideo();
                startPixelReduction();
                startTimer();
            } else {
                togglePlayPause(); // Falls das Video schon läuft, normale Pause-Logik nutzen
            }
        });


        loadVideo(currentVideoIndex);
        drawStartScreen();
    </script>

</body>
</html>





<?php

$conn->close();
?>