<?php
session_start();

// ================== Bootstrap / DB ==================
require('template.php');
mysqli_set_charset($conn, "utf8");


















// =============== PDF Engine Loader ==================
function _pdf_init(): array {
    $autoloads = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/vendor/dompdf/dompdf/autoload.inc.php',
        '/usr/share/php/dompdf/autoload.inc.php',
        '/usr/share/php/mpdf/autoload.php',
    ];
    foreach ($autoloads as $f) { if (is_file($f)) { require_once $f; } }

    if (class_exists('\Dompdf\Dompdf')) {
        $opts = null;
        if (class_exists('\Dompdf\Options')) {
            $opts = new \Dompdf\Options();
            $opts->set('isRemoteEnabled', true);
            $opts->set('isHtml5ParserEnabled', true);
        }
        $dompdf = $opts ? new \Dompdf\Dompdf($opts) : new \Dompdf\Dompdf();
        return ['engine' => 'dompdf', 'instance' => $dompdf];
    }
    if (class_exists('\Mpdf\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
        return ['engine' => 'mpdf', 'instance' => $mpdf];
    }
    return ['engine' => 'none', 'instance' => null];
}

// =============== Users in AGs laden =================
function build_ag_users(array &$ag_complete, mysqli $conn): void {
    foreach ($ag_complete as $id => &$data_user_erweiterung) {
        $data_user_erweiterung['users'] = [];
    }
    $sql = "SELECT room, firstname, lastname, groups, sprecher, uid, turm, pid, username 
            FROM users 
            WHERE pid IN (11,12,64)
            ORDER BY room ASC";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $user_groups = explode(",", $row['groups']);
            foreach ($user_groups as $group_id) {
                if (isset($ag_complete[$group_id])) {
                    $ag_complete[$group_id]['users'][] = [
                        "room"     => $row['room'],
                        "name"     => explode(' ', $row['firstname'])[0] . ' ' . explode(' ', $row['lastname'])[0],
                        "turm"     => $row['turm'],
                        "pid"      => $row['pid'],
                        "username" => $row['username'],
                        "sprecher" => $row['sprecher']
                    ];
                }
            }
        }
    }
}

function normalize_ag_title(string $name): string {
    static $map = [
        'Datenschutz'   => 'Datenschutzbeauftragter',
        'WEH-BA'        => 'Belegungsausschuss',
        'WEH-Wasch-AG'  => 'Wasch-AG',
        'TvK-WaschAG'   => 'Wasch-AG',
        'TvK-BA'        => 'Belegungsausschuss',
        'Vorstand'      => 'Vorstand WEH e.V.'
    ];
    return $map[$name] ?? $name;
}

// global nutzbar machen (in export_ag_pdf per $GLOBALS abrufen)
$AG_TITLE_EXCLUDE = [
    'Webmaster',
    'WEH-Hausmeister',
    'TvK-Hausmeister',
    'Vorsitz',
    'Kasse',
    'Schriftführer'
];

// =============== PDF Export (INLINE im neuen Tab) ===
function export_ag_pdf(array $ag_complete, string $turm, array $globalAGs): void {
    while (ob_get_level() > 0) { ob_end_clean(); }

    $pdf = _pdf_init();
    if ($pdf['engine'] === 'none') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "PDF-Renderer nicht gefunden. Bitte Dompdf (dompdf/dompdf) oder mPDF (mpdf/mpdf) via Composer installieren.";
        exit;
    }

    $engine = $pdf['engine'];
    $inst   = $pdf['instance'];

    $title = "AG-Mitglieder";
    $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y');

    // schwarzes Sprecher-Icon (SVG, hinter dem Namen)
    $crownSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#000" d="M3 19h18v2H3v-2Zm0-9l4 3 5-7 5 7 4-3v8H3v-8Z"/></svg>';
    $crownDataUri = 'data:image/svg+xml;base64,' . base64_encode($crownSvg);

    // AGs filtern
    $groupsFiltered = [];
    $globalAGsSafe = is_array($globalAGs) ? $globalAGs : [];              // robust gegen NULL
    $excludeSafe   = isset($GLOBALS['AG_TITLE_EXCLUDE']) && is_array($GLOBALS['AG_TITLE_EXCLUDE'])
                    ? $GLOBALS['AG_TITLE_EXCLUDE'] : [];

    foreach ($ag_complete as $gid => $g) {
        if ($gid === 26) continue;

        // nur aktueller Turm ODER global
        if (!($g['turm'] === $turm || in_array($gid, $globalAGsSafe, true))) continue;

        // Ausschlüsse am Originaltitel prüfen
        $origName = isset($g['name']) ? (string)$g['name'] : '';
        if ($origName !== '' && in_array($origName, $excludeSafe, true)) continue;

        // Titel normalisieren
        $g2 = $g;
        $g2['name'] = normalize_ag_title($origName);
        $g2['mail'] = $g['mail'] ?? '';

        $groupsFiltered[] = ['id' => $gid, 'data' => $g2];
    }

    $COLS = 3;

    // Farben je Turm
    $getTurmColor = function(string $u_turm): string {
        return ($u_turm === 'tvk') ? '#E49B0F' : (($u_turm === 'weh') ? '#11a50d' : '#000000');
    };

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <style>
            @page { size: A4; margin: 10mm 8mm; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.2pt; line-height: 1.25; color: #000; }
            h1 { font-size: 13pt; margin: 0 0 4mm 0; text-align: center; }
            .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .grid td { vertical-align: top; padding: 1.6mm; }
            .ag { page-break-inside: avoid; border: 0.7pt solid #c9c9c9; border-radius: 3mm; padding: 2mm; }
            .ag h2 { font-size: 9.5pt; margin: 0 0 2mm 0; text-align: center; }

            /* Mitgliederliste: automatische Breitenberechnung (kein fixed Layout) */
            .members { width: 100%; border-collapse: collapse; table-layout: auto; }
            .members td { 
                /*border-bottom: 0.15pt solid #eee; */
                padding: 1px 2px; 
                vertical-align: middle; 
            }

            /* >> WICHTIG: Tag-Zelle soll MINIMAL sein (nur so breit wie Inhalt) */
            .tagcell  { white-space: nowrap; width: 1%; }  /* nimmt so wenig Platz wie möglich */
            .namecell { width: 99%; }                      /* restliche Breite für Namen */

            /* Farbrand-Block (Turm + Raum) – ohne Füllung, exakt mittig, sehr kompakt */
            .tag {
                display: inline-flex;
                align-items: center;          /* vertikal zentriert */
                justify-content: center;       /* horizontal zentriert */
                gap: 2px;
                border: 1.2pt solid #000;      /* echte Farbe via inline-style */
                border-radius: 10px;
                padding: 2px 6px;              /* kompakt, +1–2px Luft */
                line-height: 1;
                background: transparent;       /* KEINE Füllung */
                min-height: 12pt;
                transform: translateY(2px);
                min-width: 43pt;
                text-align: center;
            }
            .tag-label {
                width: 9mm;                    /* fixe Breite -> Raum beginnt immer gleich */
                text-align: center;
                font-weight: normal;           /* nicht fett */
                letter-spacing: .2px;
            }
            .tag-room {
                width: 11mm;                   /* fixe Breite, rechtsbündig */
                text-align: right;
            }

            .crown { height: 10pt; vertical-align: middle; position: relative; top: 1.2pt; margin-left: 2px; }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($today) ?></h1>

        <table class="grid">
            <tbody>
            <?php
            $count = count($groupsFiltered);
            for ($i = 0; $i < $count; $i += $COLS):
                echo '<tr>';
                for ($c = 0; $c < $COLS; $c++):
                    $idx = $i + $c;
                    echo '<td>';
                    if ($idx < $count) {
                        $group_id = $groupsFiltered[$idx]['id'];
                        $group    = $groupsFiltered[$idx]['data'];
                        $users    = $group['users'] ?? [];

                        echo '<div class="ag">';
                        if (!empty($group['name'])) {
                            echo '<div style="font-size:10pt; text-align:center; font-weight: bold;">'
                            . htmlspecialchars($group['name'])
                            . '</div>';
                        }
                        if (!empty($group['mail'])) {
                            echo '<div style="font-size:8pt; text-align:center; margin-bottom:1mm;">'
                            . htmlspecialchars($group['mail'])
                            . '</div>';
                        }
                        if (!empty($users)) {
                            echo '<table class="members"><tbody>';
                            foreach ($users as $user) {
                                $turm_label = ($user['turm'] === 'tvk') ? 'TvK' : 'WEH';
                                $isSpeaker  = ((string)$user['sprecher'] === (string)$group_id);
                                $room_disp  = ($group_id === 24 || $group_id === 27 || $user['pid'] != 11)
                                    ? '-'
                                    : str_pad($user['room'], 4, "0", STR_PAD_LEFT);
                                if ($group['name'] == "Netzwerk-AG" || $group['name'] == "Webmaster") {
                                    $room_disp = "";
                                }
                                $borderCol  = $getTurmColor((string)$user['turm']);

                                echo '<tr>';
                                // ultrakompakter Tag-Block (nimmt minimal Breite)
                                echo '<td class="tagcell">';
                                echo   '<span class="tag" style="border-color:' . $borderCol . ';">';
                                echo     '<span class="tag-label">' . htmlspecialchars($turm_label) . '</span> ';
                                echo     '<span class="tag-room">'  . htmlspecialchars($room_disp) . '</span>';
                                echo   '</span>';
                                echo '</td>';

                                // Name füllt den Rest, Icon HINTER dem Namen
                                echo '<td class="namecell">';
                                echo htmlspecialchars($user['name']);
                                if ($isSpeaker) {
                                    echo ' <img class="crown" src="' . $crownDataUri . '" alt="Sprecher">';
                                }
                                echo '</td>';
                                echo '</tr>';
                            }
                            echo '</tbody></table>';
                        } else {
                            echo '<div style="text-align:center; color:#444;">Keine Mitglieder in dieser AG.</div>';
                        }
                            
                        if ($group['name'] == "Netzwerk-AG") {
                            echo "<div style='font-size:7pt; text-align:center; font-weight: bold; margin-top:2mm;'>"
                            . "Don't knock on our doors,"
                            . "<br>"
                            . "visit our consultation hour!"
                            . "</div>";
                        }
                        echo '</div>';
                    }
                    echo '</td>';
                endfor;
                echo '</tr>';
            endfor;
            ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $filename = "AGs_" . $turm . "_" . date('Ymd_His') . ".pdf";

    if ($engine === 'dompdf') {
        $inst->setBasePath(__DIR__);
        $inst->loadHtml($html, 'UTF-8');
        $inst->setPaper('A4', 'portrait');
        $inst->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $inst->output();
        exit;
    }

    if ($engine === 'mpdf') {
        header('Content-Type: application/pdf');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $inst->WriteHTML($html);
        $inst->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }
}





















// =============== AUTH + direkte POST-Action ===============
// Wichtig: PDF-Export vor JEDER HTML-Ausgabe behandeln!
if (auth($conn) && $_SESSION['valid']) {
    $globalAGs = [7, 9, 66, 61, 62, 63];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'turmchoice_weh') {
            $_SESSION["ap_turm_var"] = 'weh';
        } elseif ($_POST['action'] === 'turmchoice_tvk') {
            $_SESSION["ap_turm_var"] = 'tvk';
        } elseif ($_POST['action'] === 'export_pdf') {
            // Users laden und SOFORT exportieren (kein vorheriges Echo!)
            build_ag_users($ag_complete, $conn);
            $turmExp = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
            export_ag_pdf($ag_complete, $turmExp, $globalAGs);
            // export_ag_pdf() beendet mit exit;
        }
    }
} else {
    header("Location: denied.php");
    exit;
}

// =============== Ab hier erst HTML ausgeben ===============
load_menu();
$globalAGs = [7, 9, 66, 61, 62, 63];

$turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
$weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
$tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
    <body>
<?php
echo '<div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap;">';
echo '<form method="post" action="AGs.php" style="display:flex; justify-content:center; align-items:center; gap:0px;">';
echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; width:200px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; width:200px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
echo '</form>';
echo '</div>';
echo "<br><br>";

echo '<div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap;">';
// PDF-Button nur für NetzAG / Vorstand
if ((!empty($_SESSION['NetzAG']) && $_SESSION['NetzAG'] === true) ||
    (!empty($_SESSION['Vorstand']) && $_SESSION['Vorstand'] === true) ||
    (!empty($_SESSION['TvK-Sprecher']) && $_SESSION['TvK-Sprecher'] === true)) {
    // target="_blank" -> PDF im NEUEN TAB
    echo '<form method="post" action="AGs.php" style="margin-left:12px;" target="_blank">';
    echo '  <input type="hidden" name="action" value="export_pdf">';
    echo '  <button type="submit" class="house-button" style="font-size:20px; padding:10px 16px; border:2px solid #000;">PDF (aktueller Turm)</button>';
    echo '</form>';
}
echo '</div>';
echo "<br><br>";

echo '<table class="clear-table" style="width:20%;">';
echo '<thead>';
echo '<tr>';
echo '<th style="width:50%; text-align:center;">AG Name</th>';
echo '<th style="width:50%; text-align:center;">Vacancies</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($ag_complete as $group_id => $group) {
    if ($group["turm"] != $turm && !in_array($group_id, $globalAGs)) continue;
    if (!empty($group["vacancy"])) {
        $randfarbe = in_array($group_id, $globalAGs) 
            ? "white" 
            : (["weh" => "#11a50d", "tvk" => "#E49B0F"][$group["turm"]] ?? "white");

        echo '<tr onclick="highlightContainer(\'container_' . $group_id . '\', \'' . $randfarbe . '\'); location.href=\'#container_' . $group_id . '\';" style="cursor: pointer;">';
        echo '<td style="width:50%;">' . htmlspecialchars($group["name"]) . '</td>';
        echo '<td style="width:50%;">' . intval($group["vacancy"]) . '</td>';
        echo '</tr>';
    }
}
echo '</tbody>';
echo '</table>';

// Users für HTML-Ansicht laden
build_ag_users($ag_complete, $conn);

// Ausgabe AG-Boxen
echo '<div style="display:flex; flex-wrap:wrap; justify-content:center; margin:10px; padding:0px 60px 60px 60px;">';
foreach ($ag_complete as $group_id => $group) {
    if ($group["turm"] != $turm && !in_array($group_id, $globalAGs)) continue;
    if ($group_id === 26) continue;

    $randfarbe = in_array($group_id, $globalAGs) 
        ? "white" 
        : (["weh" => "#11a50d", "tvk" => "#E49B0F"][$group["turm"]] ?? "white");
    
    echo '<div id="container_' . $group_id . '" class="ag-box" style="border: 20px outset ' . $randfarbe . '; transition: background-color 1s ease;">';
    echo '<div style="margin-bottom:20px;">';
    echo '<h2 class="white-text" style="font-size:28px; font-weight:bold; text-align:center; margin-bottom:10px;">';
    echo '<a href="' . htmlspecialchars($group['link']) . '" class="white-text">' . $group['name'] . '</a>';
    echo '</h2>';
    echo '<p style="text-align:center; margin:0;"><a href="mailto:' . htmlspecialchars($group['mail']) . '" class="white-text" style="font-style:italic;">' . htmlspecialchars($group['mail']) . '</a></p>';
    echo '</div>';

    if (!empty($group['users'])) {
        echo '<div style="display: flex; flex-direction: column; align-items: center; justify-content: flex-start; height: 100%;">';
        echo '<table style="width:75%; border-collapse:collapse; margin: 0 auto; text-align:center;">';
        foreach ($group['users'] as $user) {
            $turm_form = ($user['turm'] === 'tvk') ? 'TvK' : strtoupper($user['turm']);
            $isSpeaker = ($user['sprecher'] == $group_id);
            $room_color = ($user['turm'] === 'tvk') ? '#E49B0F' : '#11a50d';

            if ($group_id === 24 || $group_id === 27 ) {
                $mailto = 'hausmeister@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                $output = '<strong>' . $turm_form . '</strong>';
            } elseif ($user["pid"] != 11) {                         
                $mailto = $user["username"] . '@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                $output = '<strong>' . $turm_form . '</strong>';
            } else {
                $formatted_room = str_pad($user['room'], 4, "0", STR_PAD_LEFT);
                $mailto = 'z' . $formatted_room . '@' . htmlspecialchars($user['turm']) . '.rwth-aachen.de';
                $output = $turm_form . ' <strong> - ' . htmlspecialchars($user['room']) . '</strong>'; 
            }
            
            echo '<tr>';
            echo '<td style="padding:4px 8px; color:' . $room_color . ';">' . $output . '</td>';
            if ($isSpeaker) {
                echo '<td style="padding:4px 8px;" class="white-text">
                        <img src="images/ags/vorstand.png" width="16" height="16" alt="Sprecher Icon" style="vertical-align:">
                        <a href="mailto:' . $mailto . '" class="white-text">' . htmlspecialchars($user['name']) . '</a>
                     </td>';
            } else {
                echo '<td style="padding:4px 8px;" class="white-text">
                        <a href="mailto:' . $mailto . '" class="white-text">' . htmlspecialchars($user['name']) . '</a>
                     </td>';
            }                         
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    } else {
        echo '<p class="white-text" style="text-align:center;">Keine Mitglieder in dieser AG.</p>';
    }

    if (!empty($group["vacancy"])) {
        echo '<p style="text-align:center; color:gold; margin-top:20px; font-size:16px;">';
        $vacancy = intval($group["vacancy"]);
        echo ($vacancy === 1)
            ? '1 open spot for a new member.<br>Contact the AG if you want to join.'
            : $vacancy . ' open spots for new members.<br>Contact the AG if you want to join.';
        echo '</p>';
    }
    echo '</div>';
}
echo '</div>';

$conn->close();
?>
<script>
function highlightContainer(containerId, $randfarbe) {
    const container = document.getElementById(containerId);
    if (container) {
        const originalColor = container.style.backgroundColor;
        container.style.backgroundColor = $randfarbe;
        setTimeout(() => { container.style.backgroundColor = originalColor; }, 600);
    }
}
</script>
</body>
</html>
