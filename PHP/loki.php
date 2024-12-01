<?php
session_start();
require('conn.php');

// Query, um die Pfade der aktiven Bilder aus der Datenbank zu holen
$sql = "SELECT pfad FROM infopics WHERE aktiv = 1";
$result = mysqli_query($conn, $sql);

// Fehlerbehandlung
if (!$result) {
    die("Fehler beim Abrufen der Bilder: " . mysqli_error($conn));
}

// Pfade sammeln
$imagePaths = [];
while ($row = mysqli_fetch_assoc($result)) {
    $imagePaths[] = $row['pfad'];
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Swing Shit Slide Show</title>
  <style>
    body {
      margin: 0;
      cursor: none;
    }
    img {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  </style>
  <meta http-equiv="refresh" content="600">
</head>
<body>

<div id="slideshow"></div>

<script>
  // PHP-Array mit Bildpfaden in JavaScript umwandeln
  var images = <?php echo json_encode($imagePaths); ?>;

  if (images.length === 0) {
    console.error("Keine Bilder gefunden.");
  }

  var currentImage = 0;
  var slideshow = document.getElementById('slideshow');

  function nextSlide() {
    slideshow.innerHTML = ''; // Entferne das aktuelle Bild
    var img = new Image(); // Erstelle ein neues Bild
    img.onload = function() {
      img.style.display = 'block'; // Das Bild wird sichtbar, wenn es geladen ist
    };
    img.src = images[currentImage]; // Setze den Pfad des Bildes
    slideshow.appendChild(img); // Füge das Bild in den Slideshow-Div ein
    currentImage = (currentImage + 1) % images.length; // Gehe zum nächsten Bild
    setTimeout(nextSlide, 15000); // Wechsle alle 15 Sekunden das Bild
  }

  // Starte die Slideshow
  if (images.length > 0) {
    nextSlide();
  } else {
    console.error("Keine Bilder zum Anzeigen.");
  }
</script>

</body>
</html>
