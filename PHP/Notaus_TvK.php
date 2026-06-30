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
  :root {
    --bg1: #090510;
    --bg2: #1b0638;
    --hot: #ff2fd6;
    --acid: #88ff00;
    --ice: #00eaff;
    --warn: #ff3b1f;
    --glass: rgba(255,255,255,.10);
    --edge: rgba(255,255,255,.28);
    --shadow: rgba(0,0,0,.55);
  }

  * { box-sizing: border-box; }
  html, body { min-height: 100%; }

  body {
    margin: 0;
    color: #fff;
    overflow-x: hidden;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background:
      radial-gradient(circle at 18% 12%, rgba(255,47,214,.55), transparent 28rem),
      radial-gradient(circle at 82% 20%, rgba(0,234,255,.45), transparent 30rem),
      radial-gradient(circle at 50% 90%, rgba(136,255,0,.28), transparent 32rem),
      conic-gradient(from 210deg at 50% 50%, #10051f, #27125a, #01394a, #42104a, #10051f);
    background-size: 120% 120%, 130% 130%, 110% 110%, 160% 160%;
    animation: bgDrift 14s ease-in-out infinite alternate, hueTrip 18s linear infinite;
    text-shadow: 0 0 .8rem rgba(255,255,255,.35);
  }

  body::before,
  body::after {
    content: "";
    position: fixed;
    inset: -30vmax;
    pointer-events: none;
    z-index: -1;
  }

  body::before {
    background:
      repeating-conic-gradient(from 0deg, rgba(255,255,255,.06) 0 8deg, transparent 8deg 16deg),
      repeating-linear-gradient(115deg, rgba(255,255,255,.045) 0 1px, transparent 1px 14px);
    mix-blend-mode: overlay;
    filter: blur(.5px) saturate(160%);
    animation: vortex 24s linear infinite;
  }

  body::after {
    background-image:
      radial-gradient(circle, rgba(255,255,255,.85) 0 1px, transparent 1.8px),
      radial-gradient(circle, rgba(0,234,255,.85) 0 1px, transparent 2px),
      radial-gradient(circle, rgba(255,47,214,.75) 0 1px, transparent 2px);
    background-size: 7rem 7rem, 11rem 11rem, 15rem 15rem;
    background-position: 0 0, 3rem 7rem, 9rem 2rem;
    opacity: .30;
    mix-blend-mode: screen;
    animation: starcrawl 30s linear infinite;
  }

  @keyframes bgDrift {
    from { background-position: 0% 0%, 100% 10%, 50% 100%, 0% 50%; }
    to   { background-position: 100% 35%, 0% 80%, 70% 0%, 100% 50%; }
  }
  @keyframes hueTrip { to { filter: hue-rotate(360deg) saturate(1.18); } }
  @keyframes vortex { to { transform: rotate(360deg) scale(1.04); } }
  @keyframes starcrawl { to { transform: translate3d(8rem, -10rem, 0) rotate(-8deg); } }

  div[style*="width: 70%"] {
    position: relative;
    isolation: isolate;
    max-width: 980px;
    padding: clamp(1rem, 3vw, 2rem);
    border: 1px solid var(--edge);
    border-radius: 2rem;
    background:
      linear-gradient(135deg, rgba(255,255,255,.18), rgba(255,255,255,.055)),
      linear-gradient(90deg, rgba(255,47,214,.22), rgba(0,234,255,.18), rgba(136,255,0,.12));
    box-shadow:
      0 1.5rem 4rem var(--shadow),
      inset 0 0 0 1px rgba(255,255,255,.12),
      0 0 3rem rgba(255,47,214,.20);
    backdrop-filter: blur(18px) saturate(155%);
  }

  div[style*="width: 70%"]::before {
    content: "";
    position: absolute;
    inset: -2px;
    z-index: -1;
    border-radius: inherit;
    background: conic-gradient(from var(--angle, 0deg), var(--hot), var(--ice), var(--acid), #fff200, var(--hot));
    filter: blur(1.1rem);
    opacity: .62;
    animation: borderSpin 4s linear infinite;
  }

  @property --angle {
    syntax: "<angle>";
    inherits: false;
    initial-value: 0deg;
  }
  @keyframes borderSpin { to { --angle: 360deg; } }

  div[style*="bounce"] {
    position: relative !important;
    display: inline-block;
    font-weight: 950;
    letter-spacing: .015em;
    line-height: 1.08;
    text-wrap: balance;
    text-transform: uppercase;
    text-shadow:
      .08em .06em 0 rgba(255,47,214,.95),
      -.08em -.05em 0 rgba(0,234,255,.9),
      0 0 1.1rem rgba(255,255,255,.65),
      0 0 2.4rem rgba(136,255,0,.35);
    animation: bounce 2s infinite, disappear 2s infinite, glitchPop 1.35s steps(2,end) infinite;
  }

  div[style*="bounce"]::before,
  div[style*="bounce"]::after {
    position: absolute;
    top: -.9em;
    font-size: .75em;
    filter: drop-shadow(0 0 .6rem #fff);
    animation: orbit 1.9s linear infinite;
  }
  div[style*="bounce"]::before { content: "✦"; left: -1.3em; }
  div[style*="bounce"]::after  { content: "⚠"; right: -1.3em; animation-direction: reverse; }

  @keyframes bounce {
    0%,100% { top: 1.1rem; transform: translateX(0) scale(1) rotate(-.35deg); }
    45% { top: -1.2rem; transform: translateX(.15rem) scale(1.045) rotate(.35deg); }
    50% { transform: translateX(-.15rem) scale(1.06) rotate(-.7deg); }
  }
  @keyframes disappear {
    0%,100% { opacity: .98; }
    46% { opacity: .62; filter: saturate(1.7); }
    53% { opacity: .28; filter: blur(.05rem) saturate(2.1); }
    60% { opacity: .86; }
  }
  @keyframes glitchPop {
    0%, 80%, 100% { clip-path: inset(0 0 0 0); }
    84% { clip-path: inset(0 0 58% 0); transform: translateX(-.08em); }
    88% { clip-path: inset(42% 0 0 0); transform: translateX(.08em); }
    92% { clip-path: inset(18% 0 35% 0); transform: translateX(-.04em); }
  }
  @keyframes orbit { to { transform: rotate(360deg) translateX(.2em) rotate(-360deg); } }

  #notausForm {
    transform-origin: 50% 50%;
    animation: floatPanel 3.2s ease-in-out infinite;
  }
  @keyframes floatPanel {
    0%,100% { transform: translateY(0) rotate(.35deg); }
    50% { transform: translateY(-.65rem) rotate(-.35deg); }
  }

  .switch {
    --w: 9.8rem;
    --h: 5.4rem;
    position: relative;
    display: inline-block;
    width: var(--w);
    height: var(--h);
    filter: drop-shadow(0 1.2rem 1.8rem rgba(0,0,0,.55));
    transform-style: preserve-3d;
    animation: switchSwagger 4.2s ease-in-out infinite;
  }

  .switch::before,
  .switch::after {
    content: "";
    position: absolute;
    inset: -.9rem;
    border-radius: 999px;
    pointer-events: none;
  }
  .switch::before {
    background: conic-gradient(from var(--angle,0deg), var(--hot), var(--ice), var(--acid), #fff, var(--hot));
    filter: blur(1.15rem);
    opacity: .74;
    animation: borderSpin 2.2s linear infinite;
  }
  .switch::after {
    background:
      radial-gradient(.35rem .35rem at 8% 45%, #fff, transparent 65%),
      radial-gradient(.45rem .45rem at 90% 28%, var(--ice), transparent 65%),
      radial-gradient(.38rem .38rem at 74% 90%, var(--hot), transparent 65%),
      radial-gradient(.3rem .3rem at 30% 8%, var(--acid), transparent 65%);
    mix-blend-mode: screen;
    animation: sparkle 1.15s ease-in-out infinite alternate;
  }

  @keyframes switchSwagger {
    0%,100% { transform: rotateZ(-1.5deg) rotateX(7deg); }
    50% { transform: rotateZ(1.5deg) rotateX(-2deg) scale(1.025); }
  }
  @keyframes sparkle {
    from { opacity: .45; transform: scale(.98) rotate(-2deg); }
    to { opacity: .95; transform: scale(1.04) rotate(2deg); }
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
    overflow: hidden;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,.32);
    background:
      linear-gradient(135deg, rgba(255,255,255,.28), transparent 32%),
      linear-gradient(135deg, #16ff7a, #0ea64d 48%, #06451f);
    box-shadow:
      inset 0 .2rem .8rem rgba(255,255,255,.22),
      inset 0 -.65rem 1.2rem rgba(0,0,0,.38),
      0 0 2rem rgba(22,255,122,.45);
    transition: transform .22s ease, background .35s ease, box-shadow .35s ease, filter .35s ease;
  }

  .slider::before {
    content: "";
    position: absolute;
    width: 3.9rem;
    height: 3.9rem;
    left: .75rem;
    top: .72rem;
    border-radius: 50%;
    background:
      radial-gradient(circle at 30% 25%, #fff 0 12%, #e9f2ff 13% 30%, #9ab0c8 62%, #3b4656 100%);
    box-shadow:
      .25rem .45rem 1rem rgba(0,0,0,.55),
      inset -.45rem -.55rem .9rem rgba(0,0,0,.34),
      inset .3rem .25rem .5rem rgba(255,255,255,.85);
    transition: transform .42s cubic-bezier(.16,1.25,.32,1), box-shadow .25s ease;
  }

  .slider::after {
    content: "LIVE";
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    padding-left: 3.1rem;
    color: rgba(255,255,255,.96);
    font-weight: 950;
    font-size: 1.05rem;
    letter-spacing: .18em;
    text-shadow: 0 0 .7rem rgba(255,255,255,.75), .08rem .08rem 0 rgba(0,0,0,.65);
    transition: transform .3s ease, letter-spacing .3s ease;
  }

  .slider:hover {
    transform: scale(1.045) rotate(-.6deg);
    filter: saturate(1.25) contrast(1.08);
  }

  .slider:active::before { transform: translateX(.45rem) scale(.94); }

  input:checked + .slider {
    background:
      linear-gradient(135deg, rgba(255,255,255,.24), transparent 34%),
      linear-gradient(135deg, #ff2f6d, #d00022 48%, #4a000b);
    box-shadow:
      inset 0 .2rem .9rem rgba(255,255,255,.18),
      inset 0 -.8rem 1.4rem rgba(0,0,0,.48),
      0 0 2.5rem rgba(255,47,109,.7),
      0 0 5rem rgba(255,47,214,.28);
    animation: dangerPulse .7s ease-in-out infinite alternate;
  }

  input:checked + .slider::before {
    transform: translateX(4.35rem) rotate(360deg);
    box-shadow:
      -.25rem .45rem 1rem rgba(0,0,0,.62),
      0 0 1.2rem rgba(255,255,255,.38),
      inset -.45rem -.55rem .9rem rgba(0,0,0,.34),
      inset .3rem .25rem .5rem rgba(255,255,255,.85);
  }

  input:checked + .slider::after {
    content: "NOTAUS";
    padding-left: 0;
    padding-right: 3.2rem;
    letter-spacing: .11em;
    transform: skewX(-4deg);
  }

  input:checked + .slider:active::before { transform: translateX(3.9rem) scale(.94) rotate(360deg); }

  @keyframes dangerPulse {
    from { filter: saturate(1.2) brightness(1); }
    to { filter: saturate(1.85) brightness(1.16); }
  }

  .confetti,
  .confetti2 {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 9999;
    opacity: .16;
    mix-blend-mode: screen;
    overflow: hidden;
  }
  .confetti::before,
  .confetti2::before {
    content: "✦ ✧ ⚡ ◆ ✺ ✦ ⚠ ✧ ◆ ✦ ⚡ ✺";
    position: absolute;
    left: -10vw;
    top: 105vh;
    width: 120vw;
    font-size: clamp(1.6rem, 5vw, 4.6rem);
    letter-spacing: 4vw;
    white-space: nowrap;
    animation: glyphRain 16s linear infinite;
  }
  .confetti2 { opacity: .10; filter: blur(.5px); }
  .confetti2::before {
    animation-duration: 23s;
    animation-delay: -8s;
    transform: scaleX(-1);
  }
  @keyframes glyphRain {
    to { transform: translateY(-135vh) rotate(720deg); }
  }

  @keyframes rainbow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
      animation-duration: .001ms !important;
      animation-iteration-count: 1 !important;
      scroll-behavior: auto !important;
    }
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
            $sql = "UPDATE macauth m JOIN users u ON m.uid = u.uid SET m.sublet = 2 WHERE m.sublet = 0 AND u.pid = 11 AND u.groups IN ('1','1,20') AND turm = 'tvk'";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
            
        shell_exec('sudo /etc/credentials/fijinotaus.sh 2>&1');
    }

    $sql = "SELECT COUNT(uid) FROM users WHERE groups IN ('1','1,20') AND pid = 11 AND turm = 'tvk'";
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