<?php
  session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiji Notaus</title>
    <link rel="stylesheet" href="WEH.css" media="screen">

<style>
  /* ———————— MAXIMUM-QUATSCH MODE ———————— */

  /* Regenbogen + psychedelische Ebenen */
  :root {
    --spin: 12s;
    --pulse: 1.2s;
  }

  html, body {
    height: 100%;
  }

  body {
    margin: 0;
    color: #fff;
    overflow-x: hidden;
    background:
      conic-gradient(from 0deg at 50% 50%, red, orange, yellow, lime, cyan, blue, indigo, violet, red) fixed;
    animation:
      hueShift 8s linear infinite,
      wobble 6s ease-in-out infinite alternate;
    font-family: "Comic Sans MS", "Papyrus", cursive, system-ui, sans-serif;
    text-shadow: 0 0 6px rgba(255,255,255,.4), 0 0 18px rgba(255,255,255,.2);
    cursor: default;
  }

  /* zweiter animierter Layer für extra Chaos */
  body::before,
  body::after {
    content: "";
    position: fixed;
    inset: -20vmax;
    background:
      repeating-conic-gradient(from 0deg, rgba(255,255,255,.06) 0 10deg, transparent 10deg 20deg),
      radial-gradient(60vmax 60vmax at 20% -10%, rgba(255,255,255,.12), transparent 40%),
      radial-gradient(60vmax 60vmax at 120% 110%, rgba(0,0,0,.18), transparent 40%);
    mix-blend-mode: overlay;
    pointer-events: none;
    filter: blur(6px) saturate(140%);
    animation: swirl var(--spin) linear infinite;
  }
  body::after {
    animation: swirlReverse calc(var(--spin) * 1.3) linear infinite;
    opacity: .6;
  }

  @keyframes hueShift {
    0%   { filter: hue-rotate(0deg) saturate(130%); }
    50%  { filter: hue-rotate(180deg) saturate(180%); }
    100% { filter: hue-rotate(360deg) saturate(130%); }
  }
  @keyframes swirl { to { transform: rotate(360deg); } }
  @keyframes swirlReverse { to { transform: rotate(-360deg); } }

  @keyframes wobble {
    0%   { transform: perspective(800px) rotateX(0deg) rotateY(0deg) }
    50%  { transform: perspective(800px) rotateX(6deg) rotateY(-6deg) }
    100% { transform: perspective(800px) rotateX(-6deg) rotateY(6deg) }
  }

  /* Cursor-Zirkus (behält Funktion bei) */
  @keyframes cursorChange {
    0%   { cursor: default; }
    10%  { cursor: pointer; }
    20%  { cursor: move; }
    30%  { cursor: crosshair; }
    40%  { cursor: text; }
    50%  { cursor: wait; }
    60%  { cursor: help; }
    70%  { cursor: progress; }
    80%  { cursor: not-allowed; }
    90%  { cursor: col-resize; }
    100% { cursor: zoom-in; }
  }
  body { animation: hueShift 8s linear infinite, wobble 6s ease-in-out infinite alternate, cursorChange 10s infinite; }

  /* springender Hinweistext: flicker + glitch */
  @keyframes bounce {
    0%, 100% { top: 40px; transform: scale(1) }
    50% { top: -60px; transform: scale(1.08) }
  }
  @keyframes disappear {
    0%, 100% { opacity: .95; filter: drop-shadow(0 0 8px rgba(255,255,255,.6)); }
    40% { opacity: .55; }
    50% { opacity: .15; transform: skewX(6deg); }
    60% { opacity: .55; }
  }
  @keyframes glitchX {
    0% { clip-path: inset(0 0 0 0); transform: translateX(0); }
    10% { clip-path: inset(0 0 30% 0); transform: translateX(-3px); }
    20% { clip-path: inset(40% 0 0 0); transform: translateX(3px); }
    30% { clip-path: inset(0 0 60% 0); transform: translateX(-2px); }
    40% { clip-path: inset(70% 0 0 0); transform: translateX(2px); }
    50%,100% { clip-path: inset(0 0 0 0); transform: translateX(0); }
  }
  /* Ziel: der bestehende inline-div mit bounce/disappear */
  div[style*="bounce"] {
    position: relative !important;
    font-weight: 900;
    letter-spacing: .03em;
    text-shadow:
      0 0 10px rgba(255,255,255,.7),
      2px 2px 0 rgba(0,0,0,.8),
      -2px -2px 0 rgba(0,0,0,.3),
      0 0 30px rgba(255,255,255,.4);
    animation:
      bounce 2s infinite,
      disappear 2s infinite,
      glitchX 1.2s steps(10) infinite;
  }
  /* Neon-Kontur */
  div[style*="bounce"]::after {
    content: "⚡";
    position: absolute;
    right: -1.5ch;
    top: -.8ch;
    font-size: 1.2em;
    animation: spinEmoji 1.4s linear infinite;
    filter: drop-shadow(0 0 8px #fff);
  }
  @keyframes spinEmoji { to { transform: rotate(360deg); } }

  /* ———— Switch: übertriebene 3D-Optik, Glow, Partikelspur ———— */
  .switch {
    position: relative;
    display: inline-block;
    width: 140px;
    height: 80px;
    perspective: 600px;
    filter: drop-shadow(0 10px 25px rgba(0,0,0,.45));
    animation: rotateSwitch 1.4s linear infinite;
  }
  @keyframes rotateSwitch { to { transform: rotate(360deg); } }

  .switch:hover {
    animation-play-state: paused; /* damit man ihn erwischt :) */
    transform: scale(1.06) rotate(1deg);
  }

  .switch input {
    opacity: 0;
    width: 0;
    height: 0;
  }

  .slider {
    position: absolute;
    inset: 0;
    cursor: pointer;
    background:
      linear-gradient(135deg, #2aff00, #0c9100);
    border-radius: 999px;
    box-shadow:
      inset 0 0 20px rgba(0,0,0,.35),
      0 10px 25px rgba(0,0,0,.35),
      0 0 25px rgba(0,255,100,.55);
    transform: rotateX(12deg);
    transition: transform .3s ease, background .4s ease, box-shadow .3s ease, filter .3s ease;
  }

  .slider:before {
    content: "";
    position: absolute;
    height: 60px;
    width: 60px;
    left: 10px;
    bottom: 10px;
    border-radius: 50%;
    background:
      radial-gradient(circle at 30% 30%, #fff, #ddd 40%, #bbb 70%, #999 100%);
    box-shadow:
      0 6px 12px rgba(0,0,0,.45),
      0 0 0 6px rgba(255,255,255,.06) inset;
    transition: transform .4s cubic-bezier(.2,.8,.2,1), box-shadow .2s ease;
  }

  /* Glitschige Labels im Schalter */
  .slider::after {
    content: "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";
    position: absolute;
    left: 50%;
    top: 50%;
    translate: -50% -50%;
    font-weight: 900;
    font-size: 14px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.95);
    text-shadow: 0 0 8px rgba(255,255,255,.75), 2px 2px 0 rgba(0,0,0,.55);
    pointer-events: none;
    transition: transform .4s ease, opacity .3s ease, filter .3s ease;
  }

  /* Partikel-Trail der Kugel */
  .slider:before,
  .slider:after {
    will-change: transform, opacity, filter;
  }

  /* Aktiv-Zustand */
  input:checked + .slider {
    background:
      linear-gradient(135deg, #ff0044, #b30000);
    box-shadow:
      inset 0 0 24px rgba(0,0,0,.5),
      0 10px 30px rgba(255,0,70,.45),
      0 0 32px rgba(255,0,70,.7);
    filter: saturate(140%) contrast(115%);
  }

  input:checked + .slider:before {
    transform: translateX(60px) rotate(18deg);
    box-shadow:
      0 10px 18px rgba(0,0,0,.6),
      0 0 0 8px rgba(255,255,255,.08) inset;
  }

  input:checked + .slider::after {
    content: "☠ NOTAUS ☠";
    transform: scale(1.08) rotate(-2deg);
  }

  /* Hover-Effekte für extra Quatsch */
  .slider:hover {
    transform: rotateX(0deg) rotateZ(-1deg) scale(1.02);
  }
  .slider:hover::after {
    filter: blur(.3px);
  }

  /* Klick-Feedback */
  .slider:active::before {
    transform: translateX(6px) scale(.96);
  }
  input:checked + .slider:active::before {
    transform: translateX(54px) scale(.96) rotate(12deg);
  }

  /* funkelnde Pixel um den Schalter herum */
  .switch::after {
    content: "";
    position: absolute;
    inset: -20px;
    pointer-events: none;
    background:
      radial-gradient(6px 6px at 10% 20%, rgba(255,255,255,.9), transparent 60%),
      radial-gradient(6px 6px at 80% 30%, rgba(255,255,255,.7), transparent 60%),
      radial-gradient(6px 6px at 30% 80%, rgba(255,255,255,.8), transparent 60%),
      radial-gradient(6px 6px at 90% 70%, rgba(255,255,255,.75), transparent 60%);
    animation: sparkle var(--pulse) ease-in-out infinite alternate;
    mix-blend-mode: screen;
    opacity: .9;
  }
  @keyframes sparkle {
    from { filter: blur(0px) brightness(1); transform: translateY(-2px); }
    to   { filter: blur(1px) brightness(1.4); transform: translateY(2px); }
  }

  /* Formular-Zentrierung (falls extern überschrieben wurde) */
  #notausForm {
    transform-origin: 50% 50%;
    animation: bob 3s ease-in-out infinite;
  }
  @keyframes bob {
    0%,100% { transform: translateY(0) rotate(0.2deg) }
    50%     { transform: translateY(-6px) rotate(-0.2deg) }
  }

  /* Große Überschrift-Zone in der Seite noch schillernder */
  div[style*="font-size: 40px"] {
    text-shadow:
      0 0 10px rgba(255,255,255,.9),
      0 0 30px rgba(255,255,255,.6),
      0 0 60px rgba(255,255,255,.4);
    filter: drop-shadow(0 8px 24px rgba(0,0,0,.45));
  }

  /* Bonus: schwebende Emojis global */
  .confetti,
  .confetti2 {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 9999;
    mix-blend-mode: lighten;
    font-size: 5vmin;
    opacity: .15;
    animation: floaties 20s linear infinite;
  }
  .confetti2 { animation-duration: 26s; opacity: .12; }
  .confetti::before,
  .confetti2::before {
    content: "🎉✨🤡🦄🎈🚨💥";
    position: absolute;
    left: 10%;
    top: 10%;
    letter-spacing: 2vmin;
    white-space: pre;
  }
  @keyframes floaties {
    0% { transform: translateY(100vh) rotate(0deg); }
    100% { transform: translateY(-120vh) rotate(720deg); }
  }

  /* Behalte Kompatibilität zu bestehendem Keyframe-Namen */
  @keyframes rainbow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }
</style>


</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();
    
    echo '<div class="confetti"></div>';
    echo '<div class="confetti2"></div>';
    
    if (isset($_POST["execAction"])) {
        if ($_POST["execAction"] == "exec_release") {
            $sql = "UPDATE macauth SET sublet = 0 WHERE sublet = 2";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } elseif ($_POST["execAction"] == "exec_notaus") {
            $sql = "UPDATE macauth m JOIN users u ON m.uid = u.uid SET m.sublet = 2 WHERE m.sublet = 0 AND u.pid = 11 AND u.groups IN ('1','1,19')";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
            
        shell_exec('sudo /etc/credentials/fijinotaus.sh 2>&1');
    }

    $sql = "SELECT COUNT(uid) FROM users WHERE groups IN ('1','1,19') AND pid = 11";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $users2ban_count);  
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);    
    
    $sql = "SELECT sublet FROM macauth WHERE sublet = 2";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $notaus_aktiv = (mysqli_stmt_num_rows($stmt) > 0);
    mysqli_stmt_close($stmt);

    echo '<div style="width: 70%; margin: 50px auto 0; text-align: center; color: white; font-size: 40px;">';
    if ($notaus_aktiv) {
        echo '<div style="position: relative; animation: bounce 2s infinite, disappear 2s infinite;">
                Entsperrt Zugriff von '.$users2ban_count.' Usern.
              </div>';
    } else {
        echo '<div style="position: relative; animation: bounce 2s infinite, disappear 2s infinite;">
                Sperrt IP-Vergabe an '.$users2ban_count.' nicht-aktive User mit sofortiger Wirkung.
              </div>';
    }    
    echo '</div>';

    echo '<div style="display: flex; justify-content: center; align-items: center; height: 20vh;">
        <form method="post" id="notausForm">
            <label class="switch">
                <input type="checkbox" id="notaus" name="notaus" ' . ($notaus_aktiv ? 'checked' : '') . ' onchange="togglePost()">
                <span class="slider"></span>
            </label>
            <input type="hidden" id="execAction" name="execAction" value="">
        </form>
    </div>
    <script>
        function togglePost() {
            var form = document.getElementById("notausForm");
            var execActionInput = document.getElementById("execAction");

            if (document.getElementById("notaus").checked) {
                execActionInput.value = "exec_notaus";
            } else {
                execActionInput.value = "exec_release";
            }

            form.submit();
        }
    </script>';
}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>
