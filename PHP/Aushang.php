<?php
// Aushang.php (vereinfachter UI-Flow + fixes)
// - Ein Block mit 3 Switches (Turm, Format, Inhalt) + 1 Button
// - Landscape-Fix via @page size UND Engine-API
// - Mailto-Zeile bei Etagensprechern entfernt
// - Zimmernummern global geschuetzt fuer Mitglieder der konfigurierten AGs
// - Groesseres, responsives Generator-Interface

session_start();
require('template.php');
mysqli_set_charset($conn, "utf8");

// Zimmernummern dieser AG-Mitglieder im gesamten Aushang verbergen.
// NetzAG (7), Vorstand (9). Weitere AG-IDs bei Bedarf hier ergaenzen.
$AUSHANG_PROTECTED_GROUPS = [7, 9];

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
$AG_TITLE_EXCLUDE = ['WEH-Hausmeister','TvK-Hausmeister','Vorsitz','Kasse','Schriftführer'];

/**
 * Zimmernummern fuer ALLE Fundstellen im Aushang ausblenden, sobald
 * eine Person einer AG aus $AUSHANG_PROTECTED_GROUPS angehoert.
 * Webmaster (8) ist keine Schutzgruppe und wird als AG nicht ausgegeben.
 */
function aushang_hide_room_for_groups(?string $groups): bool {
    global $AUSHANG_PROTECTED_GROUPS;
    foreach (explode(',', (string)$groups) as $id) {
        if (in_array((int)trim($id), $AUSHANG_PROTECTED_GROUPS, true)) {
            return true;
        }
    }
    return false;
}

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
            $user_groups = explode(',', (string)$row['groups']);
            $hideRoom = aushang_hide_room_for_groups($row['groups']);
            foreach ($user_groups as $group_id) {
                $group_id = (int)trim($group_id);
                if (isset($ag_complete[$group_id])) {
                    $ag_complete[$group_id]['users'][] = [
                        'room'      => $row['room'],
                        'name'      => explode(' ', $row['firstname'])[0] . ' ' . explode(' ', $row['lastname'])[0],
                        'turm'      => $row['turm'],
                        'pid'       => $row['pid'],
                        'username'  => $row['username'],
                        'sprecher'  => $row['sprecher'],
                        'hide_room' => $hideRoom
                    ];
                }
            }
        }
    }
}

/* ============ PDF EXPORT: AG-Mitglieder ============ */
function export_ag_pdf(array $ag_complete, string $turm, array $globalAGs, string $orientation = 'portrait'): void {
    global $AUSHANG_PROTECTED_GROUPS;
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
        // Webmaster-AG (8) komplett vom PDF ausschliessen.
        if (in_array((int)$gid, [8, 26], true)) continue;
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
            .legend {
                position: fixed;
                right: 0mm;
                bottom: 0mm;
                font-size: 10pt;
            }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($title)?></h1>
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
                    if ($group['name'] == "Netzwerk-AG" && $turm == "weh") {
                        echo "<div style='font-size:7pt; text-align:center; font-weight: bold; margin-top:2mm; margin-bottom:2mm;'>"
                           . "Don't knock on our doors,<br>visit our consultation hour!"
                           . "</div>";
                    }
                    if (!empty($users)) {
                        echo '<table class="members"><tbody>';
                        foreach ($users as $user) {
                            $turm_label = ($user['turm'] === 'tvk') ? 'TvK' : 'WEH';
                            $isSpeaker  = ((string)$user['sprecher'] === (string)$group_id);
                            // Zimmer nur personenbezogen ausblenden, Layout unveraendert.
                            $room_disp  = ($group_id === 24 || $group_id === 27 || $user['pid'] != 11) ? '-' : str_pad($user['room'], 4, "0", STR_PAD_LEFT);
                            if (!empty($user['hide_room']) || in_array((int)$group_id, $AUSHANG_PROTECTED_GROUPS, true)) {
                                $room_disp = '';
                            }
                            $borderCol  = $getTurmColor((string)$user['turm']);

                            echo '<tr>';
                            echo   '<td class="tagcell">';
                            echo     '<span class="tag" style="border-color:' . $borderCol . ';">';
                            echo       '<span class="tag-label">' . htmlspecialchars($turm_label) . '</span> ';
                            echo       '<span class="tag-room">'  . htmlspecialchars($room_disp) . '</span>';
                            echo     '</span>';
                            echo   '</td>';
                            echo   '<td class="namecell">';                            
                            $name = htmlspecialchars($user['name']);
                            #if (!empty($isSpeaker) || $group_id === 8 || $group_id === 66) {
                            if (!empty($isSpeaker)) {
                                $name = '<strong>' . $name . '</strong>';
                            }
                            echo $name;
                            #if ($isSpeaker) { echo ' <img class="crown" src="' . $crownDataUri . '" alt="Sprecher">'; }
                            echo   '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<div style="text-align:center; color:#444;">Keine Mitglieder in dieser AG.</div>';
                    }
                    echo '</div>';
                }
                echo '</td>';
            endfor;
            echo '</tr>';
        endfor;
        ?>
        </tbody></table>
        <div class="legend">
            <strong>Fett</strong> = Sprecher
            <br>
            Stand: <?= htmlspecialchars($today) ?>
        </div>

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
    $sql = "SELECT room, firstname, lastname, `groups`
            FROM users 
            WHERE turm = ? AND pid = 11 AND etagensprecher <> 0 
            ORDER BY room";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $turm);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $room, $firstname, $lastname, $userGroups);

    $by_floor = [];
    while (mysqli_stmt_fetch($stmt)) {
        $etage = (int) floor($room / 100);
        $fn = strtok($firstname, ' ');
        $ln = strtok($lastname, ' ');
        $name = trim($fn . ' ' . $ln);
        $hideRoom = aushang_hide_room_for_groups($userGroups);
        $by_floor[$etage][] = [
            'room' => $hideRoom ? '' : str_pad((string)$room, 4, "0", STR_PAD_LEFT),
            'name' => $name,
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
            $COLS = 3;
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
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8pt; line-height: 1.25; color: #000; }
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
            .legend {
                position: fixed;
                right: 0mm;
                top: 0mm;
                font-size: 10pt;
            }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?></h1>
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
        <div class="legend">
            Stand: <?= htmlspecialchars($today) ?>
        </div>
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
$turm = ($_SESSION['ap_turm_var'] ?? $_SESSION['turm'] ?? 'weh') === 'tvk' ? 'tvk' : 'weh';
$accent = ($turm === 'tvk') ? '#FFA500' : '#11a50d';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aushang</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        /* Nur fuer diesen Generator: keine Veraenderung an WEH.css. */
        .aushang-wrap {
            width: min(760px, calc(100% - 32px));
            margin: 34px auto 64px;
        }
        .aushang-panel {
            --aushang-accent: #11a50d;
            background: #242323;
            border: 1px solid #424242;
            border-radius: 18px;
            padding: 34px;
            color: #ededed;
            box-shadow: 0 14px 38px rgba(0,0,0,.27);
            box-sizing: border-box;
        }
        .aushang-heading {
            font-size: 28px;
            line-height: 1.2;
            font-weight: 750;
            text-align: center;
            margin: 0 0 8px;
            color: white;
        }
        .aushang-heading span { color: var(--aushang-accent); }
        .aushang-description {
            font-size: 15px;
            line-height: 1.5;
            text-align: center;
            color: #bcbcbc;
            margin: 0 0 28px;
        }
        .aushang-switches { display: grid; gap: 14px; }
        .aushang-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
            min-height: 64px;
            background: #1b1b1b;
            border: 1px solid #383838;
            border-radius: 12px;
            padding: 12px 16px;
            box-sizing: border-box;
        }
        .aushang-label {
            font-size: 17px;
            font-weight: 600;
            white-space: nowrap;
        }
        .aushang-segmented {
            position: relative;
            display: flex;
            flex: 0 1 450px;
            min-width: 0;
            border: 1px solid #414141;
            border-radius: 10px;
            background: #101010;
            padding: 4px;
            overflow: hidden;
            box-sizing: border-box;
        }
        .aushang-thumb {
            position: absolute;
            z-index: 0;
            top: 4px;
            bottom: 4px;
            left: 4px;
            width: calc(50% - 4px);
            border-radius: 7px;
            background: var(--aushang-accent);
            transition: left .2s ease, background-color .2s ease;
        }
        .aushang-segmented button {
            flex: 1 1 50%;
            min-width: 0;
            position: relative;
            z-index: 1;
            border: 0;
            border-radius: 7px;
            padding: 12px 10px;
            background: transparent;
            color: #c9c9c9;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition: color .18s ease;
        }
        .aushang-segmented button.active { color: #101010; }
        .aushang-segmented button:focus-visible,
        .aushang-submit:focus-visible { outline: 2px solid white; outline-offset: 2px; }
        .aushang-submit-row { margin-top: 24px; }
        .aushang-submit {
            display: block;
            width: 100%;
            padding: 16px 24px;
            background: var(--aushang-accent);
            border: 0;
            border-radius: 11px;
            color: #101010;
            font-size: 19px;
            font-weight: 750;
            cursor: pointer;
            transition: filter .18s ease, transform .18s ease;
        }
        .aushang-submit:hover { filter: brightness(1.13); }
        .aushang-submit:active { transform: translateY(1px); }
        .aushang-hint {
            margin: 18px 0 0;
            color: #adadad;
            font-size: 13px;
            line-height: 1.5;
            text-align: center;
        }
        .aushang-lock { color: #ffb74d; }
        @media (max-width: 650px) {
            .aushang-panel { padding: 22px 16px; }
            .aushang-heading { font-size: 24px; }
            .aushang-switch { flex-direction: column; align-items: stretch; gap: 10px; }
            .aushang-segmented { flex: none; width: 100%; }
            .aushang-segmented button { font-size: 14px; padding: 12px 5px; }
        }
    </style>
</head>
<body>
<div class="aushang-wrap">
    <div class="aushang-panel" id="aushangPanel" style="--aushang-accent: <?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;">
        <h1 class="aushang-heading">Aushang-Generator <span>(PDF)</span></h1>
        <p class="aushang-description">Erstelle einen druckfertigen Aushang für das WEH oder TvK.</p>

        <?php if ($canExport): ?>
        <form id="aushangForm" method="get">
            <input type="hidden" name="action" value="generate_pdf">
            <input type="hidden" name="tower" id="towerInput" value="<?= htmlspecialchars($turm, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="orientation" id="orientationInput" value="portrait">
            <input type="hidden" name="content" id="contentInput" value="ag">

            <div class="aushang-switches">
                <div class="aushang-switch">
                    <span class="aushang-label">Turm</span>
                    <div class="aushang-segmented" data-name="tower" role="group" aria-label="Turm">
                        <div class="aushang-thumb" aria-hidden="true"></div>
                        <button type="button" data-value="weh" class="<?= $turm === 'weh' ? 'active' : '' ?>">WEH</button>
                        <button type="button" data-value="tvk" class="<?= $turm === 'tvk' ? 'active' : '' ?>">TvK</button>
                    </div>
                </div>
                <div class="aushang-switch">
                    <span class="aushang-label">Format</span>
                    <div class="aushang-segmented" data-name="orientation" role="group" aria-label="Seitenformat">
                        <div class="aushang-thumb" aria-hidden="true"></div>
                        <button type="button" data-value="portrait" class="active">Senkrecht</button>
                        <button type="button" data-value="landscape">Waagerecht</button>
                    </div>
                </div>
                <div class="aushang-switch">
                    <span class="aushang-label">Inhalt</span>
                    <div class="aushang-segmented" data-name="content" role="group" aria-label="Inhalt">
                        <div class="aushang-thumb" aria-hidden="true"></div>
                        <button type="button" data-value="ag" class="active">AG-Mitglieder</button>
                        <button type="button" data-value="floors">Etagensprecher</button>
                    </div>
                </div>
            </div>

            <div class="aushang-submit-row">
                <button id="openPdfBtn" type="button" class="aushang-submit">PDF erstellen</button>
            </div>
        </form>
        <p class="aushang-hint">Zimmernummern von Mitgliedern aus Vorstand, NetzAG und DruckerAG sind turmübergreifend ausgeblendet.</p>
        <?php else: ?>
            <p class="aushang-hint aushang-lock">Keine Berechtigung für den PDF-Export (NetzAG / Vorstand / TvK-Sprecher).</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('aushangForm');
    if (!form) return;

    const inputs = {
        tower: document.getElementById('towerInput'),
        orientation: document.getElementById('orientationInput'),
        content: document.getElementById('contentInput')
    };
    const panel = document.getElementById('aushangPanel');

    document.querySelectorAll('.aushang-segmented').forEach(seg => {
        const name = seg.dataset.name;
        const buttons = Array.from(seg.querySelectorAll('button'));
        const thumb = seg.querySelector('.aushang-thumb');

        function setThumb() {
            const selected = Math.max(0, buttons.findIndex(b => b.classList.contains('active')));
            const count = buttons.length;
            thumb.style.width = `calc(${100 / count}% - 4px)`;
            thumb.style.left = `calc(${selected * (100 / count)}% + 4px)`;
            buttons.forEach(b => b.setAttribute('aria-pressed', String(b.classList.contains('active'))));
        }

        setThumb();
        buttons.forEach(btn => btn.addEventListener('click', () => {
            buttons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            inputs[name].value = btn.dataset.value;
            if (name === 'tower') {
                panel.style.setProperty('--aushang-accent', btn.dataset.value === 'tvk' ? '#FFA500' : '#11a50d');
            }
            setThumb();
        }));
    });

    document.getElementById('openPdfBtn').addEventListener('click', () => {
        const params = new URLSearchParams({
            action: 'generate_pdf',
            tower: inputs.tower.value,
            orientation: inputs.orientation.value,
            content: inputs.content.value
        });
        window.open(location.pathname + '?' + params.toString(), '_blank', 'noopener');
    });
})();
</script>

<?php $conn->close(); ?>
</body>
</html>
