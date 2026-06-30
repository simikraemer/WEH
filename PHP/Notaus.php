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
    --space-0: #01020a;
    --space-1: #050817;
    --space-2: #0b1238;
    --cyan: #37e6ff;
    --blue: #4b7cff;
    --violet: #9b5cff;
    --magenta: #ff4fd8;
    --rose: #ff3868;
    --green: #36ff9a;
    --glass: rgba(7, 11, 34, .70);
    --glass-soft: rgba(255,255,255,.075);
    --edge: rgba(195,225,255,.26);
  }

  * {
    box-sizing: border-box;
  }

  html,
  body {
    width: 100vw;
    height: 100vh;
    min-width: 100vw;
    min-height: 100vh;
    max-width: 100vw;
    max-height: 100vh;
    margin: 0;
    padding: 0;
    overflow: hidden !important;
  }

  html {
    background: var(--space-0);
  }

  body {
    position: fixed;
    inset: 0;
    color: #fff;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background:
      radial-gradient(circle at 18% 16%, rgba(75,124,255,.20), transparent 22vmax),
      radial-gradient(circle at 84% 22%, rgba(255,79,216,.18), transparent 24vmax),
      radial-gradient(circle at 50% 92%, rgba(55,230,255,.11), transparent 30vmax),
      radial-gradient(circle at 50% 50%, #10194a 0%, #070b24 38%, #02030d 76%, #01020a 100%);
  }

  body::before,
  body::after {
    content: "";
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    overflow: hidden;
  }

  body::before {
    z-index: -4;
    background:
      radial-gradient(ellipse at center, rgba(255,255,255,.32) 0%, rgba(255,255,255,.16) 3%, transparent 8%),
      radial-gradient(ellipse at center, rgba(55,230,255,.24) 0%, rgba(55,230,255,.12) 15%, transparent 42%),
      radial-gradient(ellipse at center, rgba(155,92,255,.28) 0%, rgba(155,92,255,.17) 24%, transparent 55%),
      radial-gradient(ellipse at center, rgba(255,79,216,.18) 0%, transparent 62%);
    width: 92vmin;
    height: 36vmin;
    left: calc(50vw - 46vmin);
    top: calc(50vh - 18vmin);
    border-radius: 50%;
    filter: blur(.25rem) saturate(1.25);
    transform-origin: 50% 50%;
    animation: galaxyRotate 180s linear infinite;
  }

  body::after {
    z-index: -5;
    background:
      conic-gradient(
        from 18deg at 50% 50%,
        transparent 0deg,
        rgba(55,230,255,.06) 18deg,
        transparent 44deg,
        rgba(155,92,255,.11) 82deg,
        transparent 126deg,
        rgba(255,79,216,.08) 174deg,
        transparent 218deg,
        rgba(75,124,255,.09) 270deg,
        transparent 330deg,
        transparent 360deg
      );
    filter: blur(2.2rem);
    transform: scale(1.35);
    animation: galaxyHaloRotate 260s linear infinite;
  }

  @keyframes galaxyRotate {
    from {
      transform: rotate(0deg) scale(1);
    }
    to {
      transform: rotate(360deg) scale(1);
    }
  }

  @keyframes galaxyHaloRotate {
    from {
      transform: scale(1.35) rotate(0deg);
    }
    to {
      transform: scale(1.35) rotate(360deg);
    }
  }

  .confetti,
  .confetti2 {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    overflow: hidden;
    contain: strict;
    z-index: 1;
  }

  .confetti::before,
  .confetti::after,
  .confetti2::before,
  .confetti2::after {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    border-radius: 999px;
    pointer-events: none;
  }

  .confetti::before {
    width: .10rem;
    height: .10rem;
    background: rgba(255,255,255,.95);
    box-shadow:
      3vw 8vh 0 rgba(255,255,255,.42),
      7vw 63vh 0 rgba(255,255,255,.78),
      11vw 27vh 0 rgba(180,220,255,.68),
      14vw 91vh 0 rgba(255,255,255,.50),
      19vw 13vh 0 rgba(255,255,255,.88),
      23vw 76vh 0 rgba(140,180,255,.58),
      27vw 39vh 0 rgba(255,255,255,.62),
      31vw 5vh 0 rgba(255,255,255,.47),
      34vw 84vh 0 rgba(210,235,255,.72),
      38vw 19vh 0 rgba(255,255,255,.54),
      41vw 58vh 0 rgba(255,255,255,.90),
      45vw 31vh 0 rgba(165,210,255,.62),
      48vw 94vh 0 rgba(255,255,255,.45),
      52vw 11vh 0 rgba(255,255,255,.76),
      56vw 73vh 0 rgba(210,230,255,.58),
      59vw 44vh 0 rgba(255,255,255,.68),
      63vw 6vh 0 rgba(255,255,255,.46),
      67vw 87vh 0 rgba(175,215,255,.72),
      70vw 24vh 0 rgba(255,255,255,.61),
      74vw 66vh 0 rgba(255,255,255,.84),
      79vw 37vh 0 rgba(190,225,255,.55),
      83vw 12vh 0 rgba(255,255,255,.69),
      87vw 79vh 0 rgba(255,255,255,.51),
      91vw 49vh 0 rgba(155,205,255,.77),
      96vw 21vh 0 rgba(255,255,255,.58),
      98vw 93vh 0 rgba(255,255,255,.40);
    filter: drop-shadow(0 0 .28rem rgba(255,255,255,.75));
    animation: starTwinkleA 5.5s ease-in-out infinite alternate;
  }

  .confetti::after {
    width: .145rem;
    height: .145rem;
    background: rgba(210,235,255,.90);
    box-shadow:
      5vw 47vh 0 rgba(255,255,255,.80),
      13vw 34vh 0 rgba(55,230,255,.62),
      18vw 71vh 0 rgba(255,255,255,.62),
      26vw 18vh 0 rgba(155,92,255,.62),
      29vw 89vh 0 rgba(255,255,255,.74),
      36vw 52vh 0 rgba(255,79,216,.48),
      44vw 8vh 0 rgba(255,255,255,.64),
      51vw 83vh 0 rgba(55,230,255,.54),
      57vw 26vh 0 rgba(255,255,255,.82),
      65vw 61vh 0 rgba(155,92,255,.50),
      72vw 15vh 0 rgba(255,255,255,.72),
      77vw 92vh 0 rgba(255,79,216,.44),
      86vw 29vh 0 rgba(255,255,255,.65),
      93vw 68vh 0 rgba(55,230,255,.58);
    filter: drop-shadow(0 0 .42rem rgba(180,220,255,.72));
    animation: starTwinkleB 7s ease-in-out infinite alternate;
  }

  .confetti2::before {
    width: .065rem;
    height: .065rem;
    background: rgba(255,255,255,.58);
    box-shadow:
      2vw 38vh 0 rgba(255,255,255,.22),
      6vw 17vh 0 rgba(255,255,255,.34),
      9vw 82vh 0 rgba(255,255,255,.28),
      16vw 56vh 0 rgba(255,255,255,.44),
      21vw 96vh 0 rgba(180,210,255,.30),
      25vw 7vh 0 rgba(255,255,255,.26),
      33vw 69vh 0 rgba(255,255,255,.36),
      39vw 41vh 0 rgba(160,205,255,.31),
      46vw 17vh 0 rgba(255,255,255,.24),
      50vw 63vh 0 rgba(255,255,255,.39),
      54vw 96vh 0 rgba(190,225,255,.30),
      61vw 33vh 0 rgba(255,255,255,.26),
      68vw 55vh 0 rgba(255,255,255,.42),
      73vw 4vh 0 rgba(255,255,255,.28),
      81vw 84vh 0 rgba(175,210,255,.32),
      88vw 59vh 0 rgba(255,255,255,.25),
      94vw 7vh 0 rgba(255,255,255,.37),
      99vw 41vh 0 rgba(255,255,255,.22);
    animation: starTwinkleC 9s ease-in-out infinite alternate;
  }

  .confetti2::after {
    width: 76vmin;
    height: 24vmin;
    left: calc(50vw - 38vmin);
    top: calc(50vh - 12vmin);
    border-radius: 50%;
    background:
      radial-gradient(ellipse at center, rgba(255,255,255,.18) 0%, transparent 7%),
      repeating-radial-gradient(ellipse at center, rgba(255,255,255,.045) 0 1px, transparent 1px 11px),
      radial-gradient(ellipse at center, rgba(55,230,255,.10), transparent 58%);
    filter: blur(.55rem);
    opacity: .62;
    transform: rotate(-13deg);
    animation: dustPulse 10s ease-in-out infinite alternate;
  }

  @keyframes starTwinkleA {
    from {
      opacity: .52;
      transform: scale(.96);
    }
    to {
      opacity: .96;
      transform: scale(1.04);
    }
  }

  @keyframes starTwinkleB {
    from {
      opacity: .42;
      transform: scale(1.02);
    }
    to {
      opacity: .88;
      transform: scale(.97);
    }
  }

  @keyframes starTwinkleC {
    from {
      opacity: .28;
    }
    to {
      opacity: .64;
    }
  }

  @keyframes dustPulse {
    from {
      opacity: .42;
      filter: blur(.75rem) saturate(1);
    }
    to {
      opacity: .72;
      filter: blur(.45rem) saturate(1.25);
    }
  }

  .notaus-page {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    display: grid;
    grid-template-rows: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    justify-items: center;
    padding: clamp(1rem, 3vh, 2rem);
    z-index: 2;
  }

  .notaus-card {
    position: relative;
    isolation: isolate;
    width: min(74vw, 980px);
    max-width: calc(100vw - 2rem);
    max-height: 42vh;
    margin: 0 auto;
    padding: clamp(1.1rem, 2.8vw, 2.15rem);
    border-radius: clamp(1.25rem, 2.4vw, 2.25rem);
    border: 1px solid rgba(195,225,255,.25);
    background:
      linear-gradient(145deg, rgba(255,255,255,.12), rgba(255,255,255,.035)),
      radial-gradient(circle at 24% 18%, rgba(55,230,255,.12), transparent 36%),
      radial-gradient(circle at 82% 78%, rgba(255,79,216,.10), transparent 40%),
      rgba(6, 10, 32, .72);
    box-shadow:
      0 1.5rem 4.5rem rgba(0,0,0,.62),
      inset 0 0 0 1px rgba(255,255,255,.07),
      inset 0 0 4rem rgba(55,230,255,.045),
      0 0 4rem rgba(75,124,255,.18);
    backdrop-filter: blur(20px) saturate(1.35);
    overflow: visible;
    animation: cardFloat 6s ease-in-out infinite alternate;
  }

  .notaus-card::before {
    content: "";
    position: absolute;
    inset: -1px;
    z-index: -1;
    border-radius: inherit;
    background:
      linear-gradient(120deg,
        rgba(55,230,255,.78),
        rgba(155,92,255,.72),
        rgba(255,79,216,.60),
        rgba(55,230,255,.78)
      );
    background-size: 260% 260%;
    opacity: .46;
    filter: blur(.85rem);
    animation: auroraBorder 9s ease-in-out infinite alternate;
  }

  .notaus-card::after {
    content: "";
    position: absolute;
    inset: .08rem;
    border-radius: inherit;
    pointer-events: none;
    background:
      radial-gradient(circle at 14% 24%, rgba(255,255,255,.20) 0 .075rem, transparent .13rem),
      radial-gradient(circle at 76% 18%, rgba(255,255,255,.16) 0 .055rem, transparent .105rem),
      radial-gradient(circle at 91% 72%, rgba(55,230,255,.20) 0 .065rem, transparent .12rem),
      radial-gradient(circle at 32% 81%, rgba(255,79,216,.16) 0 .055rem, transparent .11rem);
    opacity: .78;
  }

  .notaus-message {
    position: relative;
    display: block;
    max-width: 100%;
    color: white;
    font-size: clamp(1.4rem, 3.15vw, 2.55rem);
    font-weight: 920;
    line-height: 1.08;
    letter-spacing: .02em;
    text-align: center;
    text-wrap: balance;
    text-transform: uppercase;
    overflow-wrap: anywhere;
    text-shadow:
      0 0 .7rem rgba(255,255,255,.50),
      0 0 1.4rem rgba(55,230,255,.34),
      0 0 2.8rem rgba(155,92,255,.38);
    animation: messageGlow 5s ease-in-out infinite alternate;
  }

  .notaus-message::before {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    width: min(58vw, 48rem);
    height: min(18vw, 14rem);
    transform: translate(-50%, -50%);
    z-index: -1;
    border-radius: 999px;
    background:
      radial-gradient(ellipse at 50% 50%, rgba(55,230,255,.13), transparent 55%),
      radial-gradient(ellipse at 50% 50%, rgba(155,92,255,.15), transparent 70%);
    filter: blur(1.4rem);
    animation: haloPulse 6.5s ease-in-out infinite alternate;
  }

  .notaus-switch-wrap {
    position: relative;
    z-index: 3;
    display: grid;
    place-items: center;
    width: 100vw;
    max-width: 100vw;
    min-height: clamp(8rem, 22vh, 12rem);
    overflow: visible;
  }

  #notausForm {
    position: relative;
    transform-origin: 50% 50%;
    animation: switchFloat 7s ease-in-out infinite alternate;
  }

  .switch {
    --w: clamp(7.8rem, 16vw, 10rem);
    --h: clamp(4.3rem, 8.8vw, 5.45rem);
    position: relative;
    display: inline-block;
    width: var(--w);
    height: var(--h);
    max-width: calc(100vw - 2rem);
    filter:
      drop-shadow(0 1.1rem 1.8rem rgba(0,0,0,.58))
      drop-shadow(0 0 1.8rem rgba(55,230,255,.20));
    transform-style: preserve-3d;
  }

  .switch::before {
    content: "";
    position: absolute;
    inset: -.78rem;
    border-radius: 999px;
    pointer-events: none;
    background:
      radial-gradient(circle at 30% 36%, rgba(255,255,255,.24), transparent 20%),
      linear-gradient(100deg, rgba(55,230,255,.58), rgba(155,92,255,.65), rgba(255,79,216,.48));
    filter: blur(1rem);
    opacity: .54;
    animation: switchAura 6s ease-in-out infinite alternate;
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
    border: 1px solid rgba(210,235,255,.34);
    background:
      radial-gradient(circle at 22% 50%, rgba(255,255,255,.24), transparent 20%),
      linear-gradient(135deg, rgba(255,255,255,.16), transparent 34%),
      linear-gradient(135deg, #0d6b4a, #0a2b33 54%, #081126);
    box-shadow:
      inset 0 .22rem .9rem rgba(255,255,255,.20),
      inset 0 -.72rem 1.3rem rgba(0,0,0,.44),
      0 0 2rem rgba(54,255,154,.28);
    transition: transform .22s ease, background .35s ease, box-shadow .35s ease, filter .35s ease;
  }

  .slider::before {
    content: "";
    position: absolute;
    width: calc(var(--h) - 1.45rem);
    height: calc(var(--h) - 1.45rem);
    left: .72rem;
    top: .72rem;
    border-radius: 50%;
    background:
      radial-gradient(circle at 32% 24%, rgba(255,255,255,1) 0 10%, rgba(225,242,255,.95) 11% 26%, transparent 27%),
      radial-gradient(circle at 50% 50%, #b8d2ff 0 38%, #526b9d 58%, #17223f 100%);
    box-shadow:
      .25rem .45rem 1rem rgba(0,0,0,.55),
      inset -.45rem -.55rem .9rem rgba(0,0,0,.34),
      inset .3rem .25rem .5rem rgba(255,255,255,.85),
      0 0 1.2rem rgba(255,255,255,.24);
    transition: transform .42s cubic-bezier(.16,1.25,.32,1), box-shadow .25s ease;
  }

  .slider::after {
    content: "ONLINE";
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    padding-left: calc(var(--w) * .31);
    color: rgba(255,255,255,.96);
    font-weight: 900;
    font-size: clamp(.78rem, 1.65vw, 1rem);
    letter-spacing: .16em;
    text-shadow:
      0 0 .65rem rgba(255,255,255,.62),
      0 0 1.2rem rgba(54,255,154,.36),
      .08rem .08rem 0 rgba(0,0,0,.65);
    transition: transform .3s ease, letter-spacing .3s ease;
  }

  .slider:hover {
    transform: scale(1.032);
    filter: saturate(1.14) brightness(1.04);
  }

  .slider:active::before {
    transform: translateX(.35rem) scale(.94);
  }

  input:checked + .slider {
    background:
      radial-gradient(circle at 78% 50%, rgba(255,255,255,.23), transparent 20%),
      linear-gradient(135deg, rgba(255,255,255,.16), transparent 34%),
      linear-gradient(135deg, #7d001b, #3a0827 54%, #10051f);
    box-shadow:
      inset 0 .22rem .9rem rgba(255,255,255,.17),
      inset 0 -.76rem 1.35rem rgba(0,0,0,.50),
      0 0 2.4rem rgba(255,56,104,.48),
      0 0 4.4rem rgba(255,79,216,.20);
    animation: redDwarfPulse 2.2s ease-in-out infinite alternate;
  }

  input:checked + .slider::before {
    transform: translateX(calc(var(--w) - var(--h))) rotate(360deg);
    background:
      radial-gradient(circle at 34% 24%, rgba(255,255,255,1) 0 10%, rgba(255,225,236,.95) 11% 26%, transparent 27%),
      radial-gradient(circle at 50% 50%, #ffb3c4 0 38%, #a33758 58%, #330817 100%);
    box-shadow:
      -.25rem .45rem 1rem rgba(0,0,0,.62),
      0 0 1.4rem rgba(255,255,255,.32),
      0 0 2.2rem rgba(255,56,104,.28),
      inset -.45rem -.55rem .9rem rgba(0,0,0,.34),
      inset .3rem .25rem .5rem rgba(255,255,255,.85);
  }

  input:checked + .slider::after {
    content: "NOTAUS";
    padding-left: 0;
    padding-right: calc(var(--w) * .30);
    letter-spacing: .11em;
    text-shadow:
      0 0 .65rem rgba(255,255,255,.64),
      0 0 1.4rem rgba(255,56,104,.52),
      .08rem .08rem 0 rgba(0,0,0,.70);
  }

  input:checked + .slider:active::before {
    transform: translateX(calc(var(--w) - var(--h) - .35rem)) scale(.94) rotate(360deg);
  }

  @keyframes cardFloat {
    from {
      transform: translate3d(0, .18rem, 0);
    }
    to {
      transform: translate3d(0, -.34rem, 0);
    }
  }

  @keyframes auroraBorder {
    from {
      background-position: 0% 50%;
      opacity: .34;
    }
    to {
      background-position: 100% 50%;
      opacity: .62;
    }
  }

  @keyframes messageGlow {
    from {
      filter: brightness(.96);
      text-shadow:
        0 0 .55rem rgba(255,255,255,.44),
        0 0 1.2rem rgba(55,230,255,.26),
        0 0 2.4rem rgba(155,92,255,.30);
    }
    to {
      filter: brightness(1.10);
      text-shadow:
        0 0 .8rem rgba(255,255,255,.56),
        0 0 1.7rem rgba(55,230,255,.38),
        0 0 3rem rgba(155,92,255,.42);
    }
  }

  @keyframes haloPulse {
    from {
      opacity: .48;
      transform: translate(-50%, -50%) scale(.96);
    }
    to {
      opacity: .82;
      transform: translate(-50%, -50%) scale(1.06);
    }
  }

  @keyframes switchFloat {
    from {
      transform: translateY(.12rem);
    }
    to {
      transform: translateY(-.28rem);
    }
  }

  @keyframes switchAura {
    from {
      opacity: .38;
      transform: scale(.98);
    }
    to {
      opacity: .68;
      transform: scale(1.045);
    }
  }

  @keyframes redDwarfPulse {
    from {
      filter: saturate(1.10) brightness(1);
    }
    to {
      filter: saturate(1.42) brightness(1.10);
    }
  }

  @media (max-height: 620px) {
    .notaus-page {
      grid-template-rows: minmax(0, .8fr) auto minmax(0, .8fr);
      padding: .75rem;
    }

    .notaus-card {
      width: min(82vw, 900px);
      padding: .95rem 1.15rem;
      max-height: 38vh;
    }

    .notaus-message {
      font-size: clamp(1.1rem, 3.6vh, 1.9rem);
    }

    .notaus-switch-wrap {
      min-height: 7.5rem;
    }

    .switch {
      --w: 7.8rem;
      --h: 4.3rem;
    }
  }

  @media (max-width: 700px) {
    .notaus-card {
      width: calc(100vw - 2rem);
    }

    .notaus-message {
      font-size: clamp(1.15rem, 7vw, 2rem);
    }

    .switch {
      --w: 7.8rem;
      --h: 4.3rem;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      animation-duration: .001ms !important;
      animation-iteration-count: 1 !important;
      scroll-behavior: auto !important;
      transition-duration: .001ms !important;
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
            $sql = "UPDATE macauth m JOIN users u ON m.uid = u.uid SET m.sublet = 2 WHERE m.sublet = 0 AND u.pid = 11 AND u.groups IN ('1','1,19') AND u.turm = 'weh'";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
            
        shell_exec('sudo /etc/credentials/fijinotaus.sh 2>&1');
    }

    $sql = "SELECT COUNT(uid) FROM users WHERE groups IN ('1','1,19') AND pid = 11 AND turm = 'weh'";
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

    echo '<main class="notaus-page">';
    echo '<section class="notaus-card">';
    if ($notaus_aktiv) {
        echo '<div class="notaus-message">
                Entsperrt Zugriff von '.$users2ban_count.' Usern.
              </div>';
    } else {
        echo '<div class="notaus-message">
                Sperrt IP-Vergabe an '.$users2ban_count.' nicht-aktive User mit sofortiger Wirkung.
              </div>';
    }    
    echo '</section>';

    echo '<section class="notaus-switch-wrap">
        <form method="post" id="notausForm">
            <label class="switch">
                <input type="checkbox" id="notaus" name="notaus" ' . ($notaus_aktiv ? 'checked' : '') . ' onchange="togglePost()">
                <span class="slider"></span>
            </label>
            <input type="hidden" id="execAction" name="execAction" value="">
        </form>
    </section>
    </main>
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