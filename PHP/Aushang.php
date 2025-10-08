<?php
// Aushang.php (vereinfachter UI-Flow + fixes)
// - Ein Block mit 3 Switches (Turm, Format, Inhalt) + 1 Button
// - Landscape-Fix via @page size UND Engine-API
// - Mailto-Zeile bei Etagensprechern entfernt

session_start();
require('template.php');
mysqli_set_charset($conn, "utf8");

/* ===================== PDF ENGINE ===================== */
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
        // Orientierung wird später via AddPage/Array + CSS gesetzt
        $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
        return ['engine' => 'mpdf', 'instance' => $mpdf];
    }
    return ['engine' => 'none', 'instance' => null];
}

/* ===================== AG-Funktionen ===================== */
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
$AG_TITLE_EXCLUDE = ['Webmaster','WEH-Hausmeister','TvK-Hausmeister','Vorsitz','Kasse','Schriftführer'];

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

/* ============ PDF EXPORT: AG-Mitglieder ============ */
function export_ag_pdf(array $ag_complete, string $turm, array $globalAGs, string $orientation = 'portrait'): void {
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

    $title = formatTurm($turm) . " AG-Mitglieder";
    $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y');

    $crownSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#000" d="M3 19h18v2H3v-2Zm0-9l4 3 5-7 5 7 4-3v8H3v-8Z"/></svg>';
    $crownDataUri = 'data:image/svg+xml;base64,' . base64_encode($crownSvg);

    $groupsFiltered = [];
    $globalAGsSafe = is_array($globalAGs) ? $globalAGs : [];
    $excludeSafe   = isset($GLOBALS['AG_TITLE_EXCLUDE']) && is_array($GLOBALS['AG_TITLE_EXCLUDE'])
                    ? $GLOBALS['AG_TITLE_EXCLUDE'] : [];

    foreach ($ag_complete as $gid => $g) {
        if ($gid === 26) continue;
        if (!($g['turm'] === $turm || in_array($gid, $globalAGsSafe, true))) continue;

        $origName = isset($g['name']) ? (string)$g['name'] : '';
        if ($origName !== '' && in_array($origName, $excludeSafe, true)) continue;

        $g2 = $g;
        $g2['name'] = normalize_ag_title($origName);
        $g2['mail'] = $g['mail'] ?? '';
        $groupsFiltered[] = ['id' => $gid, 'data' => $g2];
    }

    if ($orientation == "portrait") {
        $COLS = 3;
    } else {
        if ($turm == "weh") {
            $COLS = 4;
        } else {
            $COLS = 5;
        }
    }
    $getTurmColor = function(string $u_turm): string {
        return ($u_turm === 'tvk') ? '#E49B0F' : (($u_turm === 'weh') ? '#11a50d' : '#000000');
    };
    $pageSizeCss = ($orientation === 'landscape') ? 'A4 landscape' : 'A4 portrait';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <style>
            @page { size: <?= $pageSizeCss ?>; margin: 20mm 20mm; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 7pt; line-height: 1.25; color: #000; }
            h1 { font-size: 13pt; margin: 0 0 1mm 0; text-align: center; }
            .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .grid td { vertical-align: top; padding: 1.6mm; }
            .ag { page-break-inside: avoid; border: 0.7pt solid #666666; border-radius: 3mm; padding: 2mm; }
            .members { width: 100%; border-collapse: collapse; table-layout: auto; }
            .members td { padding: 1px 2px; vertical-align: middle; }
            .tagcell  { white-space: nowrap; width: 1%; }
            .namecell { width: 99%; }
            .tag {
                display: inline-flex; align-items: center; justify-content: center; gap: 2px;
                border: 1.2pt solid #000; border-radius: 10px; padding: 2px 6px; line-height: 1;
                background: transparent; transform: translateY(1px); min-width: 43pt; text-align: center;
            }
            .tag-label { width: 9mm; text-align: center; letter-spacing: .2px; }
            .tag-room  { width: 11mm; text-align: right; }
            .crown { height: 10pt; vertical-align: middle; position: relative; top: 1.2pt; margin-left: 2px; }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($today) ?></h1>
        <table class="grid"><tbody>
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
                        echo '<div style="font-size:9pt; text-align:center; font-weight: bold;">'
                           . htmlspecialchars($group['name'])
                           . '</div>';
                    }
                    if (!empty($group['mail'])) {
                        echo '<div style="font-size:7pt; text-align:center; margin-bottom:1mm;">'
                           . htmlspecialchars($group['mail'])
                           . '</div>';
                    }
                    if (!empty($users)) {
                        echo '<table class="members"><tbody>';
                        foreach ($users as $user) {
                            $turm_label = ($user['turm'] === 'tvk') ? 'TvK' : 'WEH';
                            $isSpeaker  = ((string)$user['sprecher'] === (string)$group_id);
                            $room_disp  = ($group_id === 24 || $group_id === 27 || $user['pid'] != 11) ? '-' : str_pad($user['room'], 4, "0", STR_PAD_LEFT);
                            if ($group['name'] == "Netzwerk-AG" || $group['name'] == "Webmaster") { $room_disp = ""; }
                            $borderCol  = $getTurmColor((string)$user['turm']);

                            echo '<tr>';
                            echo   '<td class="tagcell">';
                            echo     '<span class="tag" style="border-color:' . $borderCol . ';">';
                            echo       '<span class="tag-label">' . htmlspecialchars($turm_label) . '</span> ';
                            echo       '<span class="tag-room">'  . htmlspecialchars($room_disp) . '</span>';
                            echo     '</span>';
                            echo   '</td>';
                            echo   '<td class="namecell">';
                            echo     htmlspecialchars($user['name']);
                            #if ($isSpeaker) { echo ' <img class="crown" src="' . $crownDataUri . '" alt="Sprecher">'; }
                            echo   '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<div style="text-align:center; color:#444;">Keine Mitglieder in dieser AG.</div>';
                    }

                    if ($group['name'] == "Netzwerk-AG" && $turm == "weh") {
                        echo "<div style='font-size:7pt; text-align:center; font-weight: bold; margin-top:2mm;'>"
                           . "Don't knock on our doors,<br>visit our consultation hour!"
                           . "</div>";
                    }
                    echo '</div>';
                }
                echo '</td>';
            endfor;
            echo '</tr>';
        endfor;
        ?>
        </tbody></table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();
    $filename = "AGs_" . $turm . "_" . date('Ymd_His') . ".pdf";

    if ($engine === 'dompdf') {} // placeholder to keep structure aligned
    if ($engine === 'dompdf') {
        $inst->setBasePath(__DIR__);
        $inst->loadHtml($html, 'UTF-8');
        $inst->setPaper('A4', ($orientation === 'landscape' ? 'landscape' : 'portrait'));
        $inst->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $inst->output();
        exit;
    }
    if ($engine === 'mpdf') {
        // Orientierung zusätzlich explizit setzen
        if (method_exists($inst, 'AddPageByArray')) {
            $inst->AddPageByArray(['orientation' => ($orientation === 'landscape' ? 'L' : 'P')]);
        } else {
            $inst->AddPage($orientation === 'landscape' ? 'L' : 'P');
        }
        header('Content-Type: application/pdf');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $inst->WriteHTML($html);
        $inst->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }
}

/* ============ PDF EXPORT: Etagensprecher ============ */
function fetch_floor_speakers(mysqli $conn, string $turm, int $maxfloor): array {
    $sql = "SELECT room, firstname, lastname 
            FROM users 
            WHERE turm = ? AND pid = 11 AND etagensprecher <> 0 
            ORDER BY room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $turm);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname);

    $by_floor = [];
    while (mysqli_stmt_fetch($stmt)) {
        $etage = (int) floor($room / 100);
        $fn = strtok($firstname, ' ');
        $ln = strtok($lastname, ' ');
        $name = trim($fn . ' ' . $ln);
        $room4 = str_pad((string)$room, 4, "0", STR_PAD_LEFT);
        $by_floor[$etage][] = [
            'room'   => $room4,
            'name'   => $name,
            'mailto' => 'z' . $room4 . '@' . $turm . '.rwth-aachen.de',
        ];
    }
    mysqli_stmt_close($stmt);

    for ($e = 0; $e <= $maxfloor; $e++) {
        if (!isset($by_floor[$e])) $by_floor[$e] = [];
    }
    ksort($by_floor);
    return $by_floor;
}

function export_floor_pdf(mysqli $conn, string $turm, int $maxfloor, string $orientation = 'portrait'): void {
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

    $title = formatTurm($turm) . " Etagensprecher";
    $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y');
    $borderCol = ($turm === 'tvk') ? '#E49B0F' : (($turm === 'weh') ? '#11a50d' : '#000000');
    $data = fetch_floor_speakers($conn, $turm, $maxfloor);

    if ($orientation == "portrait") {
        $COLS = 3;
    } else {
        if ($turm == "weh") {
            $COLS = 4;
        } else {
            $COLS = 4;
        }
    }
    $pageSizeCss = ($orientation === 'landscape') ? 'A4 landscape' : 'A4 portrait';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <style>
            @page { size: <?= $pageSizeCss ?>; margin: 20mm 20mm; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 7pt; line-height: 1.25; color: #000; }
            h1 { font-size: 13pt; margin: 0 0 4mm 0; text-align: center; }
            .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .grid td { vertical-align: top; padding: 1.6mm; }
            .box { page-break-inside: avoid; border: 0.7pt solid #666666; border-radius: 3mm; padding: 2mm; min-height: 18mm }
            .members { width: 100%; border-collapse: collapse; table-layout: auto; }
            .members td { padding: 1px 2px; vertical-align: middle; }
            .tagcell  { white-space: nowrap; width: 1%; }
            .namecell { width: 99%; }
            .tag { display: inline-flex; align-items: center; justify-content: center; gap: 2px;
                   border: 1.2pt solid <?= $borderCol ?>; border-radius: 10px; padding: 2px 6px; line-height: 1;
                   background: transparent; transform: translateY(1px); min-width: 43pt; text-align: center; }
            .tag-label { width: 9mm; text-align: center; letter-spacing: .2px; }
            .tag-room  { width: 11mm; text-align: right; }
            .muted { color:#444; text-align:center; }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($today) ?></h1>
        <table class="grid"><tbody>
        <?php
        $floors = array_keys($data);
        $count  = count($floors);
        for ($i = 0; $i < $count; $i += $COLS):
            echo '<tr>';
            for ($c = 0; $c < $COLS; $c++):
                $idx = $i + $c;
                echo '<td>';
                if ($idx < $count) {
                    $etage = $floors[$idx];
                    $list  = $data[$etage] ?? [];

                    echo '<div class="box">';
                    echo '<div style="font-size:10pt; text-align:center; font-weight:bold;">Etage ' . (int)$etage . '</div>';

                    if (!empty($list)) {
                        echo '<table class="members"><tbody>';
                        foreach ($list as $u) {
                            echo '<tr>';
                            echo   '<td class="tagcell">';
                            echo     '<span class="tag">';
                            echo       '<span class="tag-label">' . formatTurm(htmlspecialchars($turm)) . '</span> ';
                            echo       '<span class="tag-room">'  . htmlspecialchars($u['room']) . '</span>';
                            echo     '</span>';
                            echo   '</td>';
                            echo   '<td class="namecell">' . htmlspecialchars($u['name']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        // Mailto-Link ENTFERNT (1.)
                    } else {
                        echo '<div class="muted">Kein Etagensprecher</div>';
                    }
                    echo '</div>';
                }
                echo '</td>';
            endfor;
            echo '</tr>';
        endfor;
        ?>
        </tbody></table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();
    $filename = "Etagensprecher_" . $turm . "_" . date('Ymd_His') . ".pdf";

    if ($engine === 'dompdf') {
        $inst->setBasePath(__DIR__);
        $inst->loadHtml($html, 'UTF-8');
        $inst->setPaper('A4', ($orientation === 'landscape' ? 'landscape' : 'portrait')); // (2.) Engine-Flag
        $inst->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $inst->output();
        exit;
    }
    if ($engine === 'mpdf') {
        // (2.) Orientierung sicher setzen
        if (method_exists($inst, 'AddPageByArray')) {
            $inst->AddPageByArray(['orientation' => ($orientation === 'landscape' ? 'L' : 'P')]);
        } else {
            $inst->AddPage($orientation === 'landscape' ? 'L' : 'P');
        }
        header('Content-Type: application/pdf');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $inst->WriteHTML($html);
        $inst->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }
}

/* ================== AUTH + POST (vor JEDEM Echo!) ================== */
if (!(auth($conn) && !empty($_SESSION['valid']))) {
    header("Location: denied.php");
    exit;
}

$canExport = (!empty($_SESSION['NetzAG']) && $_SESSION['NetzAG'] === true)
          || (!empty($_SESSION['Vorstand']) && $_SESSION['Vorstand'] === true)
          || (!empty($_SESSION['TvK-Sprecher']) && $_SESSION['TvK-Sprecher'] === true);

global $ag_complete;
$globalAGs = [7, 9, 66, 61, 62, 63];

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'generate_pdf' && $canExport) {
    // Werte aus dem UI
    $turmSel     = ($_REQUEST['tower'] ?? ($_SESSION['ap_turm_var'] ?? $_SESSION['turm']));
    $turmSel     = ($turmSel === 'tvk') ? 'tvk' : 'weh';
    $_SESSION['ap_turm_var'] = $turmSel;

    $orientation = (($_REQUEST['orientation'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';

    $content     = (($_REQUEST['content'] ?? 'ag') === 'floors') ? 'floors' : 'ag';

    if ($content === 'ag') {
        build_ag_users($ag_complete, $conn);
        export_ag_pdf($ag_complete, $turmSel, $globalAGs, $orientation); // exit
    } else {
        if ($turmSel === "tvk")       { $maxfloor = 15; }
        elseif ($turmSel === "weh")  { $maxfloor = 17; }
        else                         { $maxfloor = 16; }
        export_floor_pdf($conn, $turmSel, $maxfloor, $orientation); // exit
    }
}

/* ================== HTML-AUSGABE ================== */
load_menu();
$turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Aushang</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        /* Dark-UI (Primary: #11a50d). Body-Styling bleibt in WEH.css */
        .wrap { max-width: 400px; margin: 24px auto; padding: 12px; }
        .panel { background:#121212; border:1px solid #1e1e1e; border-radius:14px; padding:18px; color:#e8e8e8; box-shadow:0 4px 16px rgba(0,0,0,.35); }
        .heading { text-align:center; font-size:20px; font-weight:700; color:#cfcfcf; margin-bottom:16px; }
        .accent { color:#11a50d; }

        /* Switches */
        .switches { display:grid; grid-template-columns: 1fr; gap:1px; }
        .switch {
            display:flex; align-items:center; justify-content:space-between;
            background:#0f0f0f; border:1px solid #2a2a2a; border-radius:12px; padding:10px;
        }
        .switch .label { font-size:14px; color:#bdbdbd; margin-right:10px; min-width:40px; }
        .segmented {
            position:relative; display:inline-flex; background:#0a0a0a; border:1px solid #2a2a2a;
            border-radius:9999px; overflow:hidden;
        }
        .segmented button {
            position:relative; z-index:2; appearance:none; border:0; background:transparent; color:#ddd;
            padding:15px 20px; cursor:pointer; font-weight:600; transition:color .18s ease;
        }
        .segmented button.active { color:#fff; }
        .segmented .thumb {
            position:absolute; z-index:1; top:0px; bottom:0px; width:50%;
            background:#11a50d; border-radius:9999px; transition:left .22s ease, width .22s ease;
            box-shadow:0 2px 10px rgba(17,165,13,.35);
        }

        .row-submit { margin-top: 16px; display:flex; justify-content:center; }
        .btn {
            background:#0e0e0e; color:#e8e8e8; border:1px solid #11a50d;
            padding:10px 16px; border-radius:10px; cursor:pointer; font-weight:700;
        }
        .btn:hover { background:#0f1f0f; }
        .hint { text-align:center; margin-top:12px; color:#9e9e9e; font-size:13px; }
        .lock { color:#ffb74d; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <div class="heading">Aushang-Generator <span class="accent">(PDF)</span></div>

        <?php if ($canExport): ?>
        <form id="aushangForm" method="post">
            <input type="hidden" name="action" value="generate_pdf">
            <!-- Hidden Werte, werden durch Switches gesetzt -->
            <input type="hidden" name="tower" id="towerInput" value="<?= $turm==='tvk' ? 'tvk' : 'weh' ?>">
            <input type="hidden" name="orientation" id="orientationInput" value="portrait">
            <input type="hidden" name="content" id="contentInput" value="ag">

            <div class="switches">
                <!-- Turm -->
                <div class="switch">
                    <div class="label">Turm</div>
                    <div class="segmented" data-name="tower">
                        <div class="thumb" style="left:2px;"></div>
                        <button type="button" data-value="weh" class="<?= $turm==='weh' ? 'active' : '' ?>">WEH</button>
                        <button type="button" data-value="tvk" class="<?= $turm==='tvk' ? 'active' : '' ?>">TvK</button>
                    </div>
                </div>

                <!-- Format -->
                <div class="switch">
                    <div class="label">Format</div>
                    <div class="segmented" data-name="orientation">
                        <div class="thumb" style="left:2px;"></div>
                        <button type="button" data-value="portrait" class="active">Senkrecht</button>
                        <button type="button" data-value="landscape">Waagerecht</button>
                    </div>
                </div>

                <!-- Inhalt -->
                <div class="switch">
                    <div class="label">Inhalt</div>
                    <div class="segmented" data-name="content">
                        <div class="thumb" style="left:2px;"></div>
                        <button type="button" data-value="ag" class="active">AG-Mitglieder</button>
                        <button type="button" data-value="floors">Etagensprecher</button>
                    </div>
                </div>
            </div>

            <div class="row-submit">
                <button id="openPdfBtn" type="button" class="btn">PDF erstellen</button>
            </div>
        </form>
        <?php else: ?>
            <div class="hint lock">Keine Berechtigung für PDF-Export. (NetzAG / Vorstand / TvK-Sprecher)</div>
        <?php endif; ?>
    </div>
</div>

<script>
/* Segmented Switch Logic + Animation */
(function() {
    const form = document.getElementById('aushangForm');
    const inputs = {
        tower: document.getElementById('towerInput'),
        orientation: document.getElementById('orientationInput'),
        content: document.getElementById('contentInput'),
    };

    document.querySelectorAll('.segmented').forEach(seg => {
        const name = seg.dataset.name;
        const buttons = Array.from(seg.querySelectorAll('button'));
        const thumb = seg.querySelector('.thumb');

        // initialize thumb position based on active button
        const setThumb = () => {
            const activeIdx = buttons.findIndex(b => b.classList.contains('active'));
            const count = buttons.length;
            const width = 100 / count;
            thumb.style.width = `calc(${width}% - 4px)`;
            thumb.style.left = `calc(${activeIdx * width}% + 2px)`;
        };
        setThumb();

        buttons.forEach((btn, idx) => {
            btn.addEventListener('click', () => {
                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                setThumb();
                // write hidden input
                if (inputs[name]) inputs[name].value = btn.dataset.value;
            });
        });
    });
})();
</script>

<script>
(function() {
  const inputs = {
    tower: document.getElementById('towerInput'),
    orientation: document.getElementById('orientationInput'),
    content: document.getElementById('contentInput'),
  };

  // bestehende Switch-Logik (lassen)
  document.querySelectorAll('.segmented').forEach(seg => {
    const name = seg.dataset.name;
    const buttons = Array.from(seg.querySelectorAll('button'));
    const thumb = seg.querySelector('.thumb');
    const setThumb = () => {
      const i = buttons.findIndex(b => b.classList.contains('active'));
      const w = 100 / buttons.length;
      thumb.style.width = `calc(${w}% - 4px)`;
      thumb.style.left  = `calc(${i * w}% + 2px)`;
    };
    setThumb();
    buttons.forEach(btn => btn.addEventListener('click', () => {
      buttons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      setThumb();
      if (inputs[name]) inputs[name].value = btn.dataset.value;
    }));
  });

  // Statt submit: GET-URL bauen und per window.open in NEUEM Tab öffnen,
  // ohne die aktuelle Seite zu verlassen (kein zusätzliches leeres Tab).
  document.getElementById('openPdfBtn').addEventListener('click', () => {
    const params = new URLSearchParams({
      action: 'generate_pdf',
      tower: inputs.tower.value,
      orientation: inputs.orientation.value,
      content: inputs.content.value,
    });
    window.open(location.pathname + '?' + params.toString(), '_blank', 'noopener');
    // Fokus auf aktueller Seite behalten
    try { window.focus(); } catch(e){}
  });
})();
</script>

<?php $conn->close(); ?>
</body>
</html>
