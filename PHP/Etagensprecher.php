<?php
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
        $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
        return ['engine' => 'mpdf', 'instance' => $mpdf];
    }
    return ['engine' => 'none', 'instance' => null];
}

/* ====== DATEN HOLEN: Etagensprecher je Etage (0..$maxfloor) ====== */
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
        $mailto = 'z' . $room4 . '@' . $turm . '.rwth-aachen.de';
        $by_floor[$etage][] = [
            'room'   => $room4,
            'name'   => $name,
            'mailto' => $mailto,
        ];
    }
    mysqli_stmt_close($stmt);

    // Stelle sicher, dass alle Etagen-Schlüssel existieren
    for ($e = 0; $e <= $maxfloor; $e++) {
        if (!isset($by_floor[$e])) $by_floor[$e] = [];
    }
    ksort($by_floor);
    return $by_floor;
}

/* ============ PDF EXPORT: Etagensprecher ============ */
function export_floor_pdf(mysqli $conn, string $turm, int $maxfloor): void {
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

    $title = "Etagensprecher (" . strtoupper($turm) . ")";
    $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y');

    // Farbwahl je Turm
    $borderCol = ($turm === 'tvk') ? '#E49B0F' : (($turm === 'weh') ? '#11a50d' : '#000000');

    $data = fetch_floor_speakers($conn, $turm, $maxfloor);
    $COLS = 3;

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
            .box { page-break-inside: avoid; border: 0.7pt solid #c9c9c9; border-radius: 3mm; padding: 2mm; }
            .box h2 { font-size: 9.5pt; margin: 0 0 2mm 0; text-align: center; }
            .members { width: 100%; border-collapse: collapse; table-layout: auto; }
            .members td { padding: 1px 2px; vertical-align: middle; }
            .tagcell  { white-space: nowrap; width: 1%; }
            .namecell { width: 99%; }

            .tag {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 2px;
                border: 1.2pt solid <?= $borderCol ?>;
                border-radius: 10px;
                padding: 2px 6px;
                line-height: 1;
                background: transparent;
                min-height: 12pt;
                transform: translateY(2px);
                min-width: 43pt;
                text-align: center;
            }
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
                            echo       '<span class="tag-label">' . strtoupper(htmlspecialchars($turm)) . '</span> ';
                            echo       '<span class="tag-room">'  . htmlspecialchars($u['room']) . '</span>';
                            echo     '</span>';
                            echo   '</td>';
                            echo   '<td class="namecell">' . htmlspecialchars($u['name']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
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

/* ================== AUTH + POST ACTIONS (vor JEDEM Echo!) ================== */
if (auth($conn) && ($_SESSION['valid'])) {
    // Turm-Wahl / PDF-Export
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'turmchoice_weh') {
            $_SESSION["ap_turm_var"] = 'weh';
        } elseif ($_POST['action'] === 'turmchoice_tvk') {
            $_SESSION["ap_turm_var"] = 'tvk';
        } elseif ($_POST['action'] === 'export_pdf_floors') {
            $turmExp = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
            // maxfloor nach Turm bestimmen (gleich wie HTML-Ansicht)
            if ($turmExp === "tvk")       { $maxfloor = 15; }
            elseif ($turmExp === "weh")  { $maxfloor = 17; }
            else                         { $maxfloor = 16; }
            export_floor_pdf($conn, $turmExp, $maxfloor); // beendet mit exit;
        }
    }
} else {
    header("Location: denied.php");
    exit;
}

/* ================== AB HIER HTML-AUSGABE ================== */
load_menu();

$turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : $_SESSION["turm"];
$weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
$tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
?>
<!DOCTYPE html>
<!-- Fiji -->
<!-- Für den WEH e.V. -->
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>
<body>

<div style="display:flex; justify-content:center; align-items:center;">
    <form method="post" style="display:flex; justify-content:center; align-items:center; gap:0px;">
        <button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; width:200px; <?= $weh_button_color ?> color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>
        <button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; width:200px; <?= $tvk_button_color ?> color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>
    </form>
</div>
<br><br>

<!-- PDF-Button (gleiche Bedingungen wie bei AGs) -->
<div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap;">
<?php if (
    (!empty($_SESSION['NetzAG']) && $_SESSION['NetzAG'] === true) ||
    (!empty($_SESSION['Vorstand']) && $_SESSION['Vorstand'] === true) ||
    (!empty($_SESSION['TvK-Sprecher']) && $_SESSION['TvK-Sprecher'] === true)
): ?>
    <form method="post" style="margin-left:12px;" target="_blank">
        <input type="hidden" name="action" value="export_pdf_floors">
        <button type="submit" class="house-button" style="font-size:20px; padding:10px 16px; border:2px solid #000;">PDF (aktueller Turm)</button>
    </form>
<?php endif; ?>
</div>
<br><br>

<?php
// maxfloor + Anzeige wie zuvor
if ($turm === "tvk") {
    $maxfloor = 15; $zimmerauf0 = 2;
} elseif ($turm === "weh") {
    $maxfloor = 17; $zimmerauf0 = 4;
} else {
    $maxfloor = 16; $zimmerauf0 = 4;
}

// Daten laden (für HTML-Ansicht)
$all_results = fetch_floor_speakers($conn, $turm, $maxfloor);

// Positionierung / 3-Spalten-Layout
$etagen_positionen = [0 => 'left', 1 => 'center', 2 => 'right'];
$count = 0;
$all_etagen = range(0, $maxfloor);

foreach ($all_etagen as $etage) {
    $etage_position = $count % 3;
    if ($count % 3 == 0) { echo "<div style='clear:both;'> <br> </div>"; }
    $count++;

    $cls = $etagen_positionen[$etage_position];
    echo "<div class='ags-{$cls}-table-container'>";
    echo "<table class='my-table ag-table'>";
    echo "<div style='text-align: center;'>";
    echo "<span style='font-size: 30px; color: white;'>Etage {$etage}</span><br>";

    if (empty($all_results[$etage])) {
        echo '<span style="font-size:20px;" class="white-text">Kein Etagensprecher</span><br>';
    } else {
        $emails = [];
        foreach ($all_results[$etage] as $u) {
            $emails[] = $u['mailto'];
            echo "<tr>";
            echo "<td style='color: #888888;'>{$u['room']}</td>";
            echo "<td class='second-column white-text'><a href='mailto: {$u['mailto']}'>{$u['name']}</a></td>";
            echo "</tr>";
        }
        if (!empty($emails)) {
            $mailtoLink = 'mailto:' . implode(',', $emails);
            echo '<div class="center" style="font-size:20px;"><a href="' . htmlspecialchars($mailtoLink) . '" class="white-text">Etagensprecher kontaktieren</a></div>';
        }
    }

    echo "</div>";
    echo "</table>";
    echo "</div>";
}

$conn->close();
?>
</body>
</html>
