<?php
require('conn.php'); // stellt $conn und formatTurm($turm) bereit
mysqli_set_charset($conn, "utf8");
date_default_timezone_set('Europe/Berlin');

/* Woche (Mo–So) bestimmen */
$now = new DateTime('now');
$weekStart = (clone $now)->setTime(0,0,0);
$weekStart->modify('-' . ((int)$weekStart->format('N') - 1) . ' days'); // Montag 00:00
$weekEnd = (clone $weekStart)->modify('+7 days'); // nächster Montag 00:00
$weekStartUnix = $weekStart->getTimestamp();
$weekEndUnix   = $weekEnd->getTimestamp();

/* Zeitfenster für Darstellung (12:00–24:00) in Minuten ab 00:00 */
$VIEW_START_MIN = 12 * 60;   // 12:00
$VIEW_END_MIN   = 24 * 60;   // 24:00
$VIEW_RANGE_MIN = $VIEW_END_MIN - $VIEW_START_MIN; // 720 Minuten

/* Daten holen: Wohnzimmer room_id=1 in dieser Woche */
$sql = "
SELECT 
  e.id, e.start_time, e.end_time, e.name AS booking_name, e.create_by AS username,
  u.firstname, u.lastname, u.turm, u.room
FROM buchungssystem.mrbs_entry e
LEFT JOIN weh.users u ON u.username = e.create_by
WHERE e.room_id = 1
  AND e.start_time >= ?
  AND e.start_time < ?
ORDER BY e.start_time ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $weekStartUnix, $weekEndUnix);
$stmt->execute();
$res = $stmt->get_result();

/* Helper: erstes Wort (bis erstes Leerzeichen) */
$firstToken = function($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $parts = preg_split('/\s+/', $s);
    return $parts[0] ?? $s;
};

/* Buchungen pro Tag gruppieren (0=Montag … 6=Sonntag) mit Clipping für 12–24
   FIX 24:00 → 00:00: Wenn Endzeit exakt Mitternacht ist, wird das pro Tagessegment als 24:00 behandelt. */
$days = array_fill(0, 7, []);
while ($row = $res->fetch_assoc()) {
    $st = (new DateTime('@'.$row['start_time']))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $et = (new DateTime('@'.$row['end_time']))->setTimezone(new DateTimeZone('Europe/Berlin'));

    if ($et <= $st) {
        $et = (clone $st)->modify('+1 minute');
    }

    $spanStart = (clone $st);
    while ($spanStart < $et) {
        $dayIdx = (int)$spanStart->format('N') - 1; // 0..6
        $dayStart = (clone $spanStart)->setTime(0,0,0);
        $dayEnd   = (clone $dayStart)->modify('+1 day'); // 00:00 des Folgetags

        // Segmentgrenzen pro Tag (Beginn 12:00)
        $segStart = max($spanStart, (clone $dayStart)->setTime(12,0,0));
        $rawSegEnd = min($et, $dayEnd);
        $segEnd = $rawSegEnd;

        if ($segEnd > $segStart) {
            // Minuten seit Mitternacht
            $minsStart = ((int)$segStart->format('H'))*60 + (int)$segStart->format('i');
            $minsEnd   = ($segEnd >= $dayEnd) ? (24*60) : (((int)$segEnd->format('H'))*60 + (int)$segEnd->format('i'));

            // Clipping auf 12:00–24:00
            $startOffsetMin = max(0, $minsStart - $VIEW_START_MIN);
            $endOffsetMin   = min($VIEW_RANGE_MIN, $minsEnd - $VIEW_START_MIN);
            $durMin = max(1, $endOffsetMin - $startOffsetMin);

            if ($durMin > 0) {
                // Anzeigeinformationen
                $fn = $firstToken($row['firstname']);
                $ln = $firstToken($row['lastname']);
                $who = ($fn !== '' ? $fn : '') . ($ln !== '' ? (' ' . $ln) : '');
                if ($who === '') $who = $firstToken($row['username']);

                $room = ($row['room'] && (int)$row['room']>0) ? str_pad((string)$row['room'], 4, '0', STR_PAD_LEFT) : '';
                $turm = strtolower(trim($row['turm'] ?? 'weh'));
                $roomColor = ($turm === 'tvk') ? '#FFA500' : '#11a50d';

                $title = trim((string)$row['booking_name']);

                $days[$dayIdx][] = [
                    'id' => (int)$row['id'],
                    'start' => $segStart,
                    'end'   => min($segEnd, $dayEnd),
                    'top_pct' => ($startOffsetMin / $VIEW_RANGE_MIN) * 100.0,
                    'height_pct' => ($durMin / $VIEW_RANGE_MIN) * 100.0,
                    'who' => $who,
                    'room' => $room,
                    'roomColor' => $roomColor,
                    'title' => $title
                ];
            }
        }
        $spanStart = max($rawSegEnd, $dayEnd);
    }
}
$stmt->close();
$conn->close();

$dayNames = ['Mo','Di','Mi','Do','Fr','Sa','So'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Wohnzimmer – Wochenansicht</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        :root { --bg:#0b0b0b; --fg:#eaeaea; --muted:#9aa0a6; --grid:rgba(255,255,255,0.08); --primary:#11a50d; --accent:#1e90ff; }
        html,body { height:100%; background: var(--bg); color: var(--fg); margin:0; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Ubuntu, 'Helvetica Neue', Arial, 'Noto Sans', 'Liberation Sans', sans-serif; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 10px 14px; position: relative; }
        .title { color: var(--primary); font-weight: 700; text-align:center; margin: 6px 0 2px 0; }
        .subtitle { color: var(--muted); text-align:center; margin-bottom: 10px; }
        .grid {
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr); /* Zeitspalte + 7 Tage */
            gap: 8px;
            height: calc(100vh - 120px);
            min-height: 520px;
        }
        .timecol, .daycol {
            position: relative;
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--grid);
            border-radius: 10px;
            overflow: hidden;
        }
        .timecol .label, .daycol .label {
            position: sticky; top: 0; z-index: 2;
            background: rgba(11,11,11,0.9);
            padding: 6px 8px;
            border-bottom: 1px solid var(--grid);
            font-weight: 600;
            text-align: center;
        }
        .timeline, .daybody { position: absolute; inset: 36px 0 0 0; }
        .hourline { position: absolute; left: 0; right: 0; height: 1px; background: var(--grid); }
        .hourlabel {
            position: absolute; left: 0; right: 0; text-align: center;
            transform: translateY(-50%);
            color: var(--fg); font-size: 16px; font-variant-numeric: tabular-nums; font-weight: bold;
        }
        .event {
            position: absolute; left: 6px; right: 6px;
            border-radius: 8px;
            padding: 1px 2px;
            text-align: center;
            border: 1px solid var(--grid);
            background: rgba(17,165,13,0.10);
            backdrop-filter: blur(2px);
            box-shadow: 0 4px 14px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .event .time  { color: var(--primary); font-weight: 700; font-size: 12px; margin-bottom: 1px; letter-spacing: 0.2px; }
        .event .who   { color: var(--fg); font-weight: 700; font-size: 13px; line-height: 1.1; }
        .event .room  { font-size: 12px; font-weight: 700; line-height: 1; margin-top: 2px; }
        .event .title { color: var(--muted); font-size: 11px; margin-top: 4px; }
        .daycol.weekend .label { color: var(--accent); }

        /* --- Nacht-Ruhe Overlay --- */
        .curfew-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;                /* via JS toggeln */
            align-items: center;
            justify-content: center;
            pointer-events: none;         /* Bedienelemente dahinter bleiben klickbar, falls nötig */
            background: rgba(0,0,0,0.25); /* leicht abdunkeln, Plan bleibt sichtbar */
        }
        .curfew-text {
            font-weight: 900;
            color: #ff2b2b;               /* kräftiges Rot */
            text-align: center;
            line-height: 1.0;
            /* Schwarzer Rand/Outline */
            text-shadow:
                -3px -3px 0 #000,
                 3px -3px 0 #000,
                -3px  3px 0 #000,
                 3px  3px 0 #000,
                 0px  0px 12px #000;
            /* Größe responsiv für kleinen Screen */
            font-size: clamp(36px, 12vw, 140px);
            letter-spacing: 1px;
            padding: 0 2vw;
        }
        @media (max-width: 1200px) {
            .grid { grid-template-columns: 60px repeat(7, 1fr); }
            .event { padding: 4px 6px; }
        }
    </style>
</head>
<body>
<!-- Nacht-Ruhe Overlay -->
<div id="curfew" class="curfew-overlay">
  <div class="curfew-text">Nachtruhe!</div>
</div>

<div class="wrap">
    <h2 class="title">Wohnzimmer – Wochenansicht</h2>
    <div class="subtitle">
        Woche: <?php echo htmlspecialchars($weekStart->format('d.m.Y')); ?> – <?php echo htmlspecialchars($weekEnd->modify('-1 second')->format('d.m.Y')); ?>
    </div>

    <div class="grid">
        <!-- Zeitspalte -->
        <div class="timecol">
            <div class="label">Zeit</div>
            <div class="timeline">
                <?php
                // Linien 12..24
                for ($h = 12; $h <= 24; $h++) {
                    $minute = ($h * 60) - $VIEW_START_MIN; // ab 12:00
                    $topPct = ($minute / $VIEW_RANGE_MIN) * 100.0;
                    if ($h === 24) { $topPct = 100.0; } // Abschlusslinie
                    echo '<div class="hourline" style="top: '. $topPct .'%;"></div>';
                }
                // Labels mittig zwischen Linien (12.5..23.5) – nur Stunde
                for ($h = 12; $h < 24; $h++) {
                    $midMin = (($h + 0.5) * 60) - $VIEW_START_MIN;
                    $midPct = ($midMin / $VIEW_RANGE_MIN) * 100.0;
                    echo '<div class="hourlabel" style="top: '. $midPct .'%; ">'. str_pad($h,2,'0',STR_PAD_LEFT) .'</div>';
                }
                ?>
            </div>
        </div>

        <!-- 7 Tage -->
        <?php for ($d=0; $d<7; $d++): 
            $dayDate = (clone $weekStart)->modify("+{$d} days");
            $isWeekend = ($d >= 5);
        ?>
        <div class="daycol<?php echo $isWeekend ? ' weekend' : ''; ?>">
            <div class="label"><?php echo $dayNames[$d] . ' ' . $dayDate->format('d.m.'); ?></div>
            <div class="daybody">
                <?php
                // Linien 12..24
                for ($h = 12; $h <= 24; $h++) {
                    $minute = ($h * 60) - $VIEW_START_MIN;
                    $topPct = ($minute / $VIEW_RANGE_MIN) * 100.0;
                    if ($h === 24) { $topPct = 100.0; }
                    echo '<div class="hourline" style="top: '. $topPct .'%;"></div>';
                }

                // Events
                foreach ($days[$d] as $ev) {
                    $top = $ev['top_pct'];
                    $height = $ev['height_pct'];
                    $stStr = $ev['start']->format('H:i');
                    $enStr = ($ev['end']->format('H:i') === '00:00' && $ev['end']->format('d.m.Y') !== $ev['start']->format('d.m.Y'))
                        ? '24:00'
                        : $ev['end']->format('H:i');

                    $who   = htmlspecialchars($ev['who']);
                    $room  = htmlspecialchars($ev['room']);
                    $roomColor = htmlspecialchars($ev['roomColor']);
                    $title = htmlspecialchars($ev['title']);
                    ?>
                    <div class="event" style="top: <?php echo $top; ?>%; height: <?php echo $height; ?>%;">
                        <div class="time"><?php echo $stStr . ' – ' . $enStr; ?></div>
                        <div class="who"><?php echo $who; ?></div>
                        <?php if ($room !== ''): ?>
                            <div class="room" style="color: <?php echo $roomColor; ?>;"><?php echo $room; ?></div>
                        <?php endif; ?>
                        <?php if ($title !== ''): ?>
                            <div class="title"><?php echo $title; ?></div>
                        <?php endif; ?>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<script>
/* Nacht-Ruhe Overlay: 22:00–06:00 Europe/Berlin */
function berlinNow() {
  // Erzeuge Date in Berliner Zeit ohne externe Lib
  const str = new Date().toLocaleString('en-US', { timeZone: 'Europe/Berlin' });
  return new Date(str);
}
function updateCurfewOverlay() {
  const d = berlinNow();
  const h = d.getHours();
  const curfew = (h >= 22 || h < 6);
  const el = document.getElementById('curfew');
  if (el) el.style.display = curfew ? 'flex' : 'none';

  // zum nächsten Minutenwechsel erneut prüfen
  const msToNextMinute = (60 - d.getSeconds())*1000 - d.getMilliseconds();
  setTimeout(updateCurfewOverlay, Math.max(500, msToNextMinute));
}
updateCurfewOverlay();

/* Stündlicher Reload: exakt zum nächsten Stundenwechsel */
(function(){
  const d = berlinNow();
  const msToNextHour = (60 - d.getMinutes())*60000 - d.getSeconds()*1000 - d.getMilliseconds();
  setTimeout(() => {
    location.reload();
    setInterval(() => location.reload(), 60*60*1000);
  }, Math.max(1000, msToNextHour));
})();
</script>
</body>
</html>
