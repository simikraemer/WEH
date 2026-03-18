<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const WEBMASTERTEST = false;
const WP2_A4_WIDTH_PT = 595.0;
const WP2_A4_HEIGHT_PT = 842.0;
const WP2_A4_TOLERANCE_PT = 5.0;

require_once __DIR__ . '/conn.php';
mysqli_set_charset($conn, 'utf8mb4');

$isAjax = (
    (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') ||
    (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1')
);

/*
 * template.php immer gepuffert laden:
 * - Funktionen stehen zur Verfügung
 * - HTML aus template.php landet bei AJAX nicht vor JSON
 */
ob_start();
require_once __DIR__ . '/template.php';
$templateBootstrap = ob_get_clean();

/**
 * =========================
 * Konfiguration
 * =========================
 */
$drucker = [
    [
        'id' => 1,
        'turm' => 'WEH',
        'farbe' => 'Black & White',
        'modell' => 'WEH Black & White',
        'ip' => '137.226.141.5',
        'name' => 'WEHsw',
        'supports_color' => false,
        'visible' => true,
    ],
    [
        'id' => 2,
        'turm' => 'WEH',
        'farbe' => 'Color',
        'modell' => 'WEH Color',
        'ip' => '137.226.141.193',
        'name' => 'WEHfarbe',
        'supports_color' => true,
        'visible' => true,
    ],
    [
        'id' => 3,
        'turm' => 'TvK',
        'farbe' => 'Black & White',
        'modell' => 'TvK Black & White',
        'ip' => 'todo',
        'name' => 'TvKsw',
        'supports_color' => false,
        'visible' => false, // bewusst behalten, aber nicht anzeigen
    ],
];

/**
 * =========================
 * Helper
 * =========================
 */
function wp2_is_authorized(mysqli $conn): bool
{
    if (!function_exists('auth')) {
        return false;
    }

    ob_start();
    $authOk = auth($conn);
    ob_end_clean();

    if (!$authOk) {
        return false;
    }

    if (WEBMASTERTEST) {
        return !empty($_SESSION['Webmaster']);
    }

    return !empty($_SESSION['valid']);
}

function wp2_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wp2_boot_state(): void
{
    if (!isset($_SESSION['webprinter_v2']) || !is_array($_SESSION['webprinter_v2'])) {
        $_SESSION['webprinter_v2'] = [
            'selected_printer_id' => null,
            'documents' => [],
        ];
    }

    $_SESSION['webprinter_v2']['selected_printer_id'] ??= null;
    $_SESSION['webprinter_v2']['documents'] ??= [];
}

function wp2_temp_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weh_webprinter_v2';
    if (!is_dir($root)) {
        @mkdir($root, 0700, true);
    }
    return $root;
}

function wp2_session_dir(): string
{
    $sid = preg_replace('/[^a-zA-Z0-9_-]/', '_', session_id());
    $dir = wp2_temp_root() . DIRECTORY_SEPARATOR . $sid;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function wp2_rrmdir(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            wp2_rrmdir($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function wp2_cleanup_old_temp_dirs(int $maxAgeSeconds = 43200): void
{
    $root = wp2_temp_root();
    $items = scandir($root);
    if ($items === false) {
        return;
    }

    $now = time();
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $root . DIRECTORY_SEPARATOR . $item;
        $mtime = @filemtime($path);
        if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
            wp2_rrmdir($path);
        }
    }
}

function wp2_normalize_files_array(array $files): array
{
    $normalized = [];

    if (!isset($files['name'])) {
        return $normalized;
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }

    return $normalized;
}

function wp2_sanitize_filename(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[^\pL\pN\.\-_ ]/u', '_', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') {
        $name = 'Document.pdf';
    }
    if (!preg_match('/\.pdf$/i', $name)) {
        $name .= '.pdf';
    }
    return $name;
}

function wp2_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = max(0, $bytes);
    $i = 0;

    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $i === 0 ? 0 : 1, '.', ',') . ' ' . $units[$i];
}

function wp2_format_money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function wp2_format_rate_cents(float $value): string
{
    return rtrim(rtrim(number_format($value * 100, 2, '.', ','), '0'), '.');
}

function wp2_command_exists(string $command): bool
{
    $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

function wp2_is_a4_size(float $width, float $height): bool
{
    $portrait = (
        abs($width - WP2_A4_WIDTH_PT) <= WP2_A4_TOLERANCE_PT &&
        abs($height - WP2_A4_HEIGHT_PT) <= WP2_A4_TOLERANCE_PT
    );

    $landscape = (
        abs($width - WP2_A4_HEIGHT_PT) <= WP2_A4_TOLERANCE_PT &&
        abs($height - WP2_A4_WIDTH_PT) <= WP2_A4_TOLERANCE_PT
    );

    return $portrait || $landscape;
}

function wp2_read_pdf_page_count(string $path): ?int
{
    if (!wp2_command_exists('pdfinfo')) {
        return null;
    }

    exec('pdfinfo ' . escapeshellarg($path) . ' 2>&1', $output, $returnVar);
    if ($returnVar !== 0) {
        return null;
    }

    $text = implode("\n", $output);
    if (preg_match('/Pages:\s+(\d+)/i', $text, $m)) {
        return (int)$m[1];
    }

    return null;
}

function wp2_read_pdf_page_sizes(string $path): array
{
    if (!wp2_command_exists('pdfinfo')) {
        return [];
    }

    exec('pdfinfo -box ' . escapeshellarg($path) . ' 2>&1', $output, $returnVar);
    if ($returnVar !== 0) {
        return [];
    }

    $text = implode("\n", $output);
    $sizes = [];

    if (preg_match_all('/Page\s+(\d+)\s+size:\s+([0-9.]+)\s+x\s+([0-9.]+)/i', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $sizes[] = [
                'page' => (int)$match[1],
                'width' => (float)$match[2],
                'height' => (float)$match[3],
            ];
        }
        return $sizes;
    }

    if (preg_match('/Page size:\s+([0-9.]+)\s+x\s+([0-9.]+)/i', $text, $match)) {
        $sizes[] = [
            'page' => 1,
            'width' => (float)$match[1],
            'height' => (float)$match[2],
        ];
    }

    return $sizes;
}

function wp2_validate_pdf_a4_only(string $path): array
{
    $sizes = wp2_read_pdf_page_sizes($path);

    if (empty($sizes)) {
        return [
            'ok' => false,
            'details' => ['Page size could not be determined.'],
        ];
    }

    $details = [];

    foreach ($sizes as $size) {
        $width = (float)$size['width'];
        $height = (float)$size['height'];
        $page = (int)$size['page'];

        if (!wp2_is_a4_size($width, $height)) {
            $details[] = 'Page ' . $page . ' is not A4 (' . $width . ' × ' . $height . ' pt).';
        }
    }

    return [
        'ok' => empty($details),
        'details' => $details,
    ];
}

function wp2_validate_pdf(string $tmpPath, string $originalName, int $size): array
{
    $errors = [];
    $details = [];

    if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
        $errors[] = 'The uploaded file is empty or incomplete.';
        return ['ok' => false, 'errors' => $errors];
    }

    if ($size > 100 * 1024 * 1024) {
        $errors[] = 'File size exceeds 100 MB.';
    }

    if (!preg_match('/\.pdf$/i', $originalName)) {
        $errors[] = 'Only PDF files are allowed.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpPath);
    if ($mime !== 'application/pdf') {
        $errors[] = 'Detected MIME type is not application/pdf.';
    }

    $head = @file_get_contents($tmpPath, false, null, 0, 8);
    if ($head === false || strpos($head, '%PDF-') !== 0) {
        $errors[] = 'Missing valid PDF header.';
    }

    if (!wp2_command_exists('pdfinfo')) {
        $errors[] = 'Server validation tool pdfinfo is not available.';
        return ['ok' => false, 'errors' => $errors];
    }

    exec('pdfinfo ' . escapeshellarg($tmpPath) . ' 2>&1', $pdfinfoOutput, $pdfinfoCode);
    $pdfinfoText = trim(implode("\n", $pdfinfoOutput));

    if ($pdfinfoCode !== 0) {
        $errors[] = 'pdfinfo could not read the document.';
        if ($pdfinfoText !== '') {
            $details[] = $pdfinfoText;
        }
    } else {
        if (preg_match('/Encrypted:\s+yes/i', $pdfinfoText)) {
            $errors[] = 'Encrypted PDFs are not accepted.';
        }

        if (preg_match('/Pages:\s+(\d+)/i', $pdfinfoText, $m)) {
            $pages = (int)$m[1];
            if ($pages < 1) {
                $errors[] = 'PDF contains no printable pages.';
            }
            if ($pages > 500) {
                $errors[] = 'PDF has more than 500 pages.';
            }
        } else {
            $errors[] = 'Page count could not be determined.';
        }

        $a4Check = wp2_validate_pdf_a4_only($tmpPath);
        if (!$a4Check['ok']) {
            $errors[] = 'Only A4 PDFs are allowed.';
            foreach ($a4Check['details'] as $detail) {
                $details[] = $detail;
            }
        }
    }

    if (wp2_command_exists('qpdf')) {
        exec('qpdf --check ' . escapeshellarg($tmpPath) . ' 2>&1', $qpdfOutput, $qpdfCode);
        $qpdfText = trim(implode("\n", $qpdfOutput));
        if ($qpdfCode !== 0) {
            $errors[] = 'qpdf reports structural problems.';
            if ($qpdfText !== '') {
                $details[] = $qpdfText;
            }
        }
    }

    if (!empty($errors)) {
        return [
            'ok' => false,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    return [
        'ok' => true,
        'pages' => wp2_read_pdf_page_count($tmpPath) ?? 0,
        'details' => $details,
    ];
}

function wp2_public_documents(array $documents): array
{
    $result = [];
    foreach ($documents as $doc) {
        $result[] = [
            'id' => $doc['id'],
            'name' => $doc['name'],
            'pages' => (int)$doc['pages'],
            'size' => (int)$doc['size'],
            'size_label' => wp2_format_bytes((int)$doc['size']),
        ];
    }
    return $result;
}

function wp2_find_document_index(string $id): ?int
{
    $documents = $_SESSION['webprinter_v2']['documents'] ?? [];
    foreach ($documents as $idx => $doc) {
        if (($doc['id'] ?? '') === $id) {
            return $idx;
        }
    }
    return null;
}

function wp2_find_printer_by_id(array $drucker, int $printerId): ?array
{
    foreach ($drucker as $printer) {
        if ((int)$printer['id'] === $printerId) {
            return $printer;
        }
    }
    return null;
}

function wp2_snmp_int(string $ip, string $oid, string $community = 'public'): int
{
    if (!function_exists('snmpget')) {
        return 0;
    }

    $raw = @snmpget($ip, $community, $oid);
    if ($raw === false || $raw === null) {
        return 0;
    }

    preg_match('/-?\d+/', (string)$raw, $m);
    return isset($m[0]) ? (int)$m[0] : 0;
}

function wp2_snmp_string(string $ip, string $oid, string $community = 'public'): string
{
    if (!function_exists('snmpget')) {
        return '';
    }

    $raw = @snmpget($ip, $community, $oid);
    if ($raw === false || $raw === null) {
        return '';
    }

    $raw = preg_replace('/^[A-Z\- ]+:\s*/', '', (string)$raw);
    return trim((string)$raw, " \t\n\r\0\x0B\"");
}

function wp2_percent(int $current, int $max): int
{
    if ($max <= 0) {
        return 0;
    }

    $pct = (int)round(($current / $max) * 100);
    return max(0, min(100, $pct));
}

function wp2_collect_printer_state(array $printer): array
{
    $community = 'public';
    $ip = (string)$printer['ip'];

    $statusCode = 0;
    $statusText = 'Unknown';
    $statusClass = 'unknown';
    $canSelect = false;
    $statusDetail = '';

    if (function_exists('snmpget') && $ip !== '' && $ip !== 'todo') {
        $statusCode = wp2_snmp_int($ip, '1.3.6.1.2.1.25.3.5.1.1.1', $community);
        $statusDetail = wp2_snmp_string($ip, '1.3.6.1.2.1.43.16.5.1.2.1.1', $community);

        switch ($statusCode) {
            case 2:
            case 3:
                $statusText = 'Ready';
                $statusClass = 'ready';
                $canSelect = true;
                break;
            case 4:
                $statusText = 'Printing';
                $statusClass = 'busy';
                $canSelect = true;
                break;
            case 5:
                $statusText = 'Out of paper';
                $statusClass = 'error';
                break;
            case 6:
                $statusText = 'Paper jam';
                $statusClass = 'error';
                break;
            case 7:
                $statusText = 'Toner empty';
                $statusClass = 'error';
                break;
            case 8:
                $statusText = 'Cover open';
                $statusClass = 'error';
                break;
            case 9:
                $statusText = 'Offline';
                $statusClass = 'offline';
                break;
            default:
                $statusText = 'Unknown';
                $statusClass = 'unknown';
                break;
        }
    }

    $paperA4Current = 0;
    $paperA4Max = 0;
    $toner = [];

    if ($ip === '137.226.141.5') {
        $paperA4Current = 0;
        for ($i = 2; $i <= 6; $i++) {
            $paperA4Current += wp2_snmp_int($ip, "1.3.6.1.2.1.43.8.2.1.10.1.$i", $community);
        }
        $paperA4Max = 2500;

        $black = wp2_snmp_int($ip, '1.3.6.1.2.1.43.11.1.1.9.1.1', $community);
        $toner[] = [
            'label' => 'Black',
            'percent' => wp2_percent($black, 40000),
            'css' => 'black',
        ];
    } elseif ($ip === '137.226.141.193') {
        $paperA4Current =
            wp2_snmp_int($ip, '1.3.6.1.2.1.43.8.2.1.10.1.2', $community) +
            wp2_snmp_int($ip, '1.3.6.1.2.1.43.8.2.1.10.1.3', $community) +
            wp2_snmp_int($ip, '1.3.6.1.2.1.43.8.2.1.10.1.4', $community);
        $paperA4Max = 1500;

        $cyan = wp2_snmp_int($ip, '1.3.6.1.2.1.43.11.1.1.9.1.1', $community);
        $magenta = wp2_snmp_int($ip, '1.3.6.1.2.1.43.11.1.1.9.1.2', $community);
        $yellow = wp2_snmp_int($ip, '1.3.6.1.2.1.43.11.1.1.9.1.3', $community);
        $black = wp2_snmp_int($ip, '1.3.6.1.2.1.43.11.1.1.9.1.4', $community);

        $toner[] = ['label' => 'Black', 'percent' => wp2_percent($black, 12000), 'css' => 'black'];
        $toner[] = ['label' => 'Cyan', 'percent' => wp2_percent($cyan, 6000), 'css' => 'cyan'];
        $toner[] = ['label' => 'Magenta', 'percent' => wp2_percent($magenta, 6000), 'css' => 'magenta'];
        $toner[] = ['label' => 'Yellow', 'percent' => wp2_percent($yellow, 6000), 'css' => 'yellow'];
    }

    return [
        'id' => (int)$printer['id'],
        'modell' => (string)$printer['modell'],
        'name' => (string)$printer['name'],
        'farbe' => (string)$printer['farbe'],
        'supports_color' => !empty($printer['supports_color']),
        'status_text' => $statusText,
        'status_class' => $statusClass,
        'status_detail' => $statusDetail,
        'can_select' => $canSelect,
        'paper' => [
            'current' => $paperA4Current,
            'max' => $paperA4Max,
            'percent' => wp2_percent($paperA4Current, $paperA4Max),
        ],
        'toner' => $toner,
    ];
}

function wp2_allowed_billing_targets(): array
{
    $targets = [
        [
            'uid' => (int)($_SESSION['uid'] ?? 0),
            'label' => 'Charge my own account',
        ],
    ];

    if (!empty($_SESSION['Vorstand']) || !empty($_SESSION['TvK-Sprecher'])) {
        array_unshift($targets, [
            'uid' => 492,
            'label' => 'Charge Vorstand',
        ]);
    }

    if (!empty($_SESSION['NetzAG'])) {
        array_unshift($targets, [
            'uid' => 472,
            'label' => 'Charge NetzAG',
        ]);
    }

    return $targets;
}

function wp2_is_allowed_billing_uid(int $uid): bool
{
    foreach (wp2_allowed_billing_targets() as $target) {
        if ((int)$target['uid'] === $uid) {
            return true;
        }
    }
    return false;
}

function wp2_get_price_config(): array
{
    if (function_exists('get_druckpreis_konfiguration')) {
        return get_druckpreis_konfiguration();
    }

    return [
        'sw' => [
            'simplex' => 0.02,
            'duplex'  => 0.015,
        ],
        'farbe' => [
            'simplex' => 0.08,
            'duplex'  => 0.06,
        ],
    ];
}

function wp2_calculate_price_breakdown(int $totalPages, string $billingMode, bool $grayscale): array
{
    $prices = wp2_get_price_config();
    $priceMode = $grayscale ? 'sw' : 'farbe';

    $simplexRate = (float)$prices[$priceMode]['simplex'];
    $duplexRate = (float)$prices[$priceMode]['duplex'];

    if ($billingMode === 'duplex') {
        $duplexRatedPages = (int)(floor($totalPages / 2) * 2);
        $simplexRatedPages = $totalPages % 2;
    } else {
        $duplexRatedPages = 0;
        $simplexRatedPages = $totalPages;
    }

    $duplexCost = $duplexRatedPages * $duplexRate;
    $simplexCost = $simplexRatedPages * $simplexRate;
    $totalCost = (float)berechne_gesamtpreis($totalPages, $billingMode, $grayscale);

    return [
        'price_mode' => $priceMode,
        'price_mode_label' => $grayscale ? 'Black & White' : 'Color',
        'billing_mode_label' => $billingMode === 'duplex' ? 'Duplex' : 'Simplex',
        'duplex_rated_pages' => $duplexRatedPages,
        'simplex_rated_pages' => $simplexRatedPages,
        'duplex_rate' => $duplexRate,
        'simplex_rate' => $simplexRate,
        'duplex_rate_ct_label' => wp2_format_rate_cents($duplexRate),
        'simplex_rate_ct_label' => wp2_format_rate_cents($simplexRate),
        'duplex_cost' => $duplexCost,
        'simplex_cost' => $simplexCost,
        'duplex_cost_formatted' => wp2_format_money($duplexCost),
        'simplex_cost_formatted' => wp2_format_money($simplexCost),
        'total_cost' => $totalCost,
        'total_cost_formatted' => wp2_format_money($totalCost),
    ];
}

function wp2_calculate_document_stats(array $documents, string $mode): array
{
    $originalPages = 0;
    $insertedBlankPages = 0;
    $docCount = count($documents);

    foreach ($documents as $idx => $doc) {
        $pages = (int)($doc['pages'] ?? 0);
        $originalPages += $pages;

        if ($mode === 'duplex_document' && $idx < ($docCount - 1) && ($pages % 2) === 1) {
            $insertedBlankPages++;
        }
    }

    return [
        'documents' => $docCount,
        'original_pages' => $originalPages,
        'inserted_blank_pages' => $insertedBlankPages,
        'effective_pages_per_copy' => $originalPages + $insertedBlankPages,
    ];
}

function wp2_write_blank_a4_pdf(string $path): bool
{
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << >> >>\nendobj\n",
        "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 5\n";
    $pdf .= sprintf("%010d 65535 f \n", 0);
    for ($i = 1; $i <= 4; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    return @file_put_contents($path, $pdf) !== false;
}

function wp2_merge_pdfs(array $sources, string $outputPath): array
{
    if (count($sources) === 1) {
        return [
            'ok' => @copy($sources[0], $outputPath),
            'message' => 'Single file copied.',
        ];
    }

    if (wp2_command_exists('pdftk')) {
        $cmd = 'pdftk ' . implode(' ', array_map('escapeshellarg', $sources)) . ' cat output ' . escapeshellarg($outputPath) . ' 2>&1';
        exec($cmd, $out, $code);
        return [
            'ok' => $code === 0 && is_file($outputPath),
            'message' => trim(implode("\n", $out)),
        ];
    }

    if (wp2_command_exists('qpdf')) {
        $parts = [];
        foreach ($sources as $src) {
            $parts[] = escapeshellarg($src) . ' 1-z';
        }
        $cmd = 'qpdf --empty --pages ' . implode(' ', $parts) . ' -- ' . escapeshellarg($outputPath) . ' 2>&1';
        exec($cmd, $out, $code);
        return [
            'ok' => $code === 0 && is_file($outputPath),
            'message' => trim(implode("\n", $out)),
        ];
    }

    return [
        'ok' => false,
        'message' => 'Neither pdftk nor qpdf is available.',
    ];
}

function wp2_sanitize_pdf_for_print(string $inputPath, string $outputPath): array
{
    if (!wp2_command_exists('gs')) {
        return [
            'ok' => @copy($inputPath, $outputPath),
            'message' => 'Ghostscript not available, original file is used.',
        ];
    }

    $cmd = 'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile=' .
        escapeshellarg($outputPath) . ' ' . escapeshellarg($inputPath) . ' 2>&1';

    exec($cmd, $out, $code);

    return [
        'ok' => $code === 0 && is_file($outputPath),
        'message' => trim(implode("\n", $out)),
    ];
}

function wp2_build_compiled_pdf(string $mode): array
{
    $documents = $_SESSION['webprinter_v2']['documents'] ?? [];
    if (empty($documents)) {
        return ['ok' => false, 'error' => 'No documents available.'];
    }

    $stats = wp2_calculate_document_stats($documents, $mode);
    $sessionDir = wp2_session_dir();
    $sourceList = [];

    $blankPath = $sessionDir . DIRECTORY_SEPARATOR . 'blank_a4.pdf';
    if ($mode === 'duplex_document' && !is_file($blankPath)) {
        if (!wp2_write_blank_a4_pdf($blankPath)) {
            return ['ok' => false, 'error' => 'Could not generate the required blank page.'];
        }
    }

    $docCount = count($documents);
    foreach ($documents as $idx => $doc) {
        $path = (string)($doc['path'] ?? '');
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'A previously uploaded PDF was not found anymore. Please upload again.'];
        }

        $sourceList[] = $path;

        if ($mode === 'duplex_document' && $idx < ($docCount - 1) && (((int)$doc['pages']) % 2) === 1) {
            $sourceList[] = $blankPath;
        }
    }

    $mergedPath = $sessionDir . DIRECTORY_SEPARATOR . 'merged_' . bin2hex(random_bytes(8)) . '.pdf';
    $merge = wp2_merge_pdfs($sourceList, $mergedPath);
    if (!$merge['ok']) {
        return [
            'ok' => false,
            'error' => 'Could not merge the PDFs.',
            'details' => $merge['message'] ?? '',
        ];
    }

    $sanitizedPath = $sessionDir . DIRECTORY_SEPARATOR . 'print_' . bin2hex(random_bytes(8)) . '.pdf';
    $sanitize = wp2_sanitize_pdf_for_print($mergedPath, $sanitizedPath);

    $finalPath = $sanitize['ok'] ? $sanitizedPath : $mergedPath;

    $a4Check = wp2_validate_pdf_a4_only($finalPath);
    if (!$a4Check['ok']) {
        @unlink($mergedPath);
        if (is_file($sanitizedPath)) {
            @unlink($sanitizedPath);
        }

        return [
            'ok' => false,
            'error' => 'Only A4 PDFs may be sent to the printer.',
            'details' => implode(' | ', $a4Check['details']),
        ];
    }

    if ($sanitize['ok']) {
        @unlink($mergedPath);
        return [
            'ok' => true,
            'path' => $sanitizedPath,
            'stats' => $stats,
        ];
    }

    return [
        'ok' => true,
        'path' => $mergedPath,
        'stats' => $stats,
        'warning' => 'Sanitization failed, merged original is used.',
        'details' => $sanitize['message'] ?? '',
    ];
}

function wp2_stream_pdf(string $path, string $filename = 'preview.pdf'): never
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('PDF not found.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($path);
    exit;
}

function wp2_quote(array $drucker, int $printerId, string $mode, int $copies, bool $grayscale, int $billingUid): array
{
    $printer = wp2_find_printer_by_id($drucker, $printerId);
    if ($printer === null || empty($printer['visible'])) {
        return [
            'ok' => false,
            'message' => 'Please select a valid printer.',
        ];
    }

    $documents = $_SESSION['webprinter_v2']['documents'] ?? [];
    if (empty($documents)) {
        return [
            'ok' => false,
            'message' => 'Please upload at least one PDF.',
        ];
    }

    $stats = wp2_calculate_document_stats($documents, $mode);
    $effectivePagesPerCopy = (int)$stats['effective_pages_per_copy'];
    $totalPages = $effectivePagesPerCopy * $copies;
    $billingMode = $mode === 'simplex' ? 'simplex' : 'duplex';

    $priceRaw = (float)berechne_gesamtpreis($totalPages, $billingMode, $grayscale);
    $sheets = (int)berechneBlaetterausSeiten($totalPages, $billingMode);
    $breakdown = wp2_calculate_price_breakdown($totalPages, $billingMode, $grayscale);

    $isOrganizationDummy = in_array($billingUid, [472, 492], true);

    $check = checkifprintjobispossible(
        $GLOBALS['conn'],
        $billingUid,
        $printerId,
        $drucker,
        $sheets,
        $priceRaw
    );

    /*
    * Für AG-Dummyuser kein Guthaben prüfen.
    */
    if ($isOrganizationDummy && $check === 2) {
        $check = 1;
    }

    $possible = false;
    $reasonText = '';
    switch ($check) {
        case 1:
            $possible = true;
            $reasonText = 'Ready to print.';
            break;
        case 2:
            $reasonText = 'Not enough balance.';
            break;
        case 3:
            $reasonText = 'Not enough paper available in the printer.';
            break;
        default:
            $reasonText = 'Printing is currently not possible.';
            break;
    }

    return [
        'ok' => true,
        'possible' => $possible,
        'reason_code' => $check,
        'reason_text' => $reasonText,
        'summary' => [
            'documents' => (int)$stats['documents'],
            'original_pages' => (int)$stats['original_pages'],
            'inserted_blank_pages' => (int)$stats['inserted_blank_pages'],
            'effective_pages_per_copy' => $effectivePagesPerCopy,
            'copies' => $copies,
            'total_pages' => $totalPages,
            'sheets' => $sheets,
            'price_raw' => $priceRaw,
            'price_formatted' => wp2_format_money($priceRaw),
            'billing_mode' => $billingMode,
            'printer_name' => $printer['modell'],
            'grayscale' => $grayscale,
            'mode' => $mode,
            'price_breakdown' => $breakdown,
        ],
    ];
}

wp2_cleanup_old_temp_dirs();
wp2_boot_state();

if (!wp2_is_authorized($conn)) {
    if ($isAjax) {
        wp2_json(['ok' => false, 'message' => 'Access denied.'], 403);
    }
    header('Location: denied.php');
    exit;
}

/**
 * =========================
 * AJAX
 * =========================
 */
if ($isAjax) {
    $action = (string)($_REQUEST['action'] ?? '');

    switch ($action) {
        case 'set_selected_printer': {
            $printerId = (int)($_POST['printer_id'] ?? 0);
            $printer = wp2_find_printer_by_id($drucker, $printerId);

            if ($printer === null || empty($printer['visible'])) {
                wp2_json(['ok' => false, 'message' => 'Invalid printer.'], 400);
            }

            $_SESSION['webprinter_v2']['selected_printer_id'] = $printerId;
            wp2_json(['ok' => true]);
        }

        case 'upload_documents': {
            if (empty($_FILES['documents'])) {
                wp2_json(['ok' => false, 'message' => 'No uploaded files received.'], 400);
            }

            $files = wp2_normalize_files_array($_FILES['documents']);
            $documents = $_SESSION['webprinter_v2']['documents'];
            $errors = [];
            $added = [];

            foreach ($files as $file) {
                $originalName = (string)($file['name'] ?? 'Document.pdf');
                $tmpName = (string)($file['tmp_name'] ?? '');
                $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                $size = (int)($file['size'] ?? 0);

                if ($error !== UPLOAD_ERR_OK) {
                    $errors[] = [
                        'file' => $originalName,
                        'message' => 'Upload failed with PHP error code ' . $error . '.',
                    ];
                    continue;
                }

                $validation = wp2_validate_pdf($tmpName, $originalName, $size);
                if (!$validation['ok']) {
                    $message = implode(' ', $validation['errors'] ?? []);
                    $detail = !empty($validation['details']) ? ' Details: ' . implode(' | ', $validation['details']) : '';
                    $errors[] = [
                        'file' => $originalName,
                        'message' => trim($message . $detail),
                    ];
                    continue;
                }

                $safeName = wp2_sanitize_filename($originalName);
                $storedPath = wp2_session_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.pdf';

                if (!@move_uploaded_file($tmpName, $storedPath)) {
                    $errors[] = [
                        'file' => $originalName,
                        'message' => 'Validated PDF could not be stored in the temporary print area.',
                    ];
                    continue;
                }

                $doc = [
                    'id' => bin2hex(random_bytes(8)),
                    'name' => $safeName,
                    'path' => $storedPath,
                    'pages' => (int)($validation['pages'] ?? 0),
                    'size' => $size,
                ];

                $documents[] = $doc;
                $added[] = $doc;
            }

            $_SESSION['webprinter_v2']['documents'] = array_values($documents);

            wp2_json([
                'ok' => true,
                'documents' => wp2_public_documents($_SESSION['webprinter_v2']['documents']),
                'added' => wp2_public_documents($added),
                'errors' => $errors,
            ]);
        }

        case 'delete_document': {
            $docId = (string)($_POST['doc_id'] ?? '');
            $idx = wp2_find_document_index($docId);

            if ($idx === null) {
                wp2_json(['ok' => false, 'message' => 'Document not found.'], 404);
            }

            $doc = $_SESSION['webprinter_v2']['documents'][$idx];
            if (!empty($doc['path']) && is_file($doc['path'])) {
                @unlink($doc['path']);
            }

            array_splice($_SESSION['webprinter_v2']['documents'], $idx, 1);

            wp2_json([
                'ok' => true,
                'documents' => wp2_public_documents($_SESSION['webprinter_v2']['documents']),
            ]);
        }

        case 'reorder_documents': {
            $orderRaw = (string)($_POST['order'] ?? '');
            $order = json_decode($orderRaw, true);

            if (!is_array($order)) {
                wp2_json(['ok' => false, 'message' => 'Invalid order payload.'], 400);
            }

            $current = $_SESSION['webprinter_v2']['documents'];
            $byId = [];
            foreach ($current as $doc) {
                $byId[$doc['id']] = $doc;
            }

            $sorted = [];
            foreach ($order as $id) {
                if (isset($byId[$id])) {
                    $sorted[] = $byId[$id];
                    unset($byId[$id]);
                }
            }

            foreach ($byId as $leftover) {
                $sorted[] = $leftover;
            }

            $_SESSION['webprinter_v2']['documents'] = array_values($sorted);

            wp2_json([
                'ok' => true,
                'documents' => wp2_public_documents($_SESSION['webprinter_v2']['documents']),
            ]);
        }

        case 'quote': {
            $printerId = (int)($_POST['printer_id'] ?? ($_SESSION['webprinter_v2']['selected_printer_id'] ?? 0));
            $mode = (string)($_POST['mode'] ?? 'duplex');
            $copies = max(1, min(500, (int)($_POST['copies'] ?? 1)));
            $grayscale = !empty($_POST['grayscale']);
            $billingUid = (int)($_POST['billing_uid'] ?? ($_SESSION['uid'] ?? 0));

            if (!in_array($mode, ['simplex', 'duplex', 'duplex_document'], true)) {
                $mode = 'duplex';
            }

            if (!wp2_is_allowed_billing_uid($billingUid)) {
                $billingUid = (int)($_SESSION['uid'] ?? 0);
            }

            $_SESSION['webprinter_v2']['selected_printer_id'] = $printerId;

            $quote = wp2_quote($drucker, $printerId, $mode, $copies, $grayscale, $billingUid);
            wp2_json($quote, $quote['ok'] ? 200 : 400);
        }

        case 'preview_single': {
            $docId = (string)($_GET['doc_id'] ?? '');
            $idx = wp2_find_document_index($docId);
            if ($idx === null) {
                http_response_code(404);
                exit('Document not found.');
            }

            $doc = $_SESSION['webprinter_v2']['documents'][$idx];
            wp2_stream_pdf((string)$doc['path'], (string)$doc['name']);
        }

        case 'preview_compiled': {
            $mode = (string)($_GET['mode'] ?? 'duplex');
            if (!in_array($mode, ['simplex', 'duplex', 'duplex_document'], true)) {
                $mode = 'duplex';
            }

            $build = wp2_build_compiled_pdf($mode);
            if (!$build['ok']) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo $build['error'] ?? 'Preview generation failed.';
                exit;
            }

            wp2_stream_pdf((string)$build['path'], 'Webprinter-preview.pdf');
        }

        case 'submit_print': {
            $printerId = (int)($_POST['printer_id'] ?? 0);
            $mode = (string)($_POST['mode'] ?? 'duplex');
            $copies = max(1, min(500, (int)($_POST['copies'] ?? 1)));
            $billingUid = (int)($_POST['billing_uid'] ?? ($_SESSION['uid'] ?? 0));
            $grayscale = !empty($_POST['grayscale']);

            if (!in_array($mode, ['simplex', 'duplex', 'duplex_document'], true)) {
                $mode = 'duplex';
            }

            if (!wp2_is_allowed_billing_uid($billingUid)) {
                $billingUid = (int)($_SESSION['uid'] ?? 0);
            }

            $_SESSION['webprinter_v2']['selected_printer_id'] = $printerId;

            $quote = wp2_quote($drucker, $printerId, $mode, $copies, $grayscale, $billingUid);
            if (!$quote['ok'] || empty($quote['possible'])) {
                wp2_json([
                    'ok' => false,
                    'message' => $quote['reason_text'] ?? $quote['message'] ?? 'Print job is not possible.',
                    'quote' => $quote,
                ], 400);
            }

            $printer = wp2_find_printer_by_id($drucker, $printerId);
            if ($printer === null) {
                wp2_json(['ok' => false, 'message' => 'Printer not found.'], 400);
            }

            $build = wp2_build_compiled_pdf($mode);
            if (!$build['ok']) {
                wp2_json([
                    'ok' => false,
                    'message' => $build['error'] ?? 'Could not generate final print PDF.',
                    'details' => $build['details'] ?? '',
                ], 500);
            }

            $compiledPath = (string)$build['path'];

            $compiledA4Check = wp2_validate_pdf_a4_only($compiledPath);
            if (!$compiledA4Check['ok']) {
                wp2_json([
                    'ok' => false,
                    'message' => 'Only A4 PDFs may be sent to CUPS.',
                    'details' => implode(' | ', $compiledA4Check['details']),
                ], 400);
            }

            $stats = $build['stats'];
            $effectivePagesPerCopy = (int)$stats['effective_pages_per_copy'];
            $totalPages = $effectivePagesPerCopy * $copies;
            $billingMode = $mode === 'simplex' ? 'simplex' : 'duplex';
            $priceRaw = (float)berechne_gesamtpreis($totalPages, $billingMode, $grayscale);

            $uploadedFileNames = array_map(
                static fn(array $doc): string => (string)$doc['name'],
                $_SESSION['webprinter_v2']['documents']
            );

            $printJobTitle = trim(implode('__', $uploadedFileNames));
            if ($printJobTitle === '') {
                $printJobTitle = 'Unnamed print job';
            }
            $printJobTitle = mb_substr($printJobTitle, 0, 200);

            $printjobUser = 'UID' . $billingUid;

            $lpOptions = [];
            $lpOptions[] = '/usr/bin/lp';
            $lpOptions[] = '-d ' . escapeshellarg((string)$printer['name']);
            $lpOptions[] = '-n ' . (int)$copies;
            $lpOptions[] = '-o media=A4';
            $lpOptions[] = '-o PageSize=A4';
            $lpOptions[] = '-o page-size=A4';
            $lpOptions[] = '-o sides=' . ($mode === 'simplex' ? 'one-sided' : 'two-sided-long-edge');

            if (!empty($printer['supports_color']) && $grayscale) {
                $lpOptions[] = '-o ColorModel=Gray';
                $lpOptions[] = '-o print-color-mode=monochrome';
            }

            $lpOptions[] = '-U ' . escapeshellarg($printjobUser);
            $lpOptions[] = '-t ' . escapeshellarg($printJobTitle);
            $lpOptions[] = '-- ' . escapeshellarg($compiledPath);

            $printCommand = implode(' ', $lpOptions) . ' 2>&1';

            exec($printCommand, $output, $returnVar);

            $cupsId = 0;
            if ($returnVar === 0 && !empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/request id is .*?-(\d+)/i', (string)$line, $m)) {
                        $cupsId = (int)$m[1];
                        break;
                    }
                }
            }

            if ($returnVar !== 0) {
                wp2_json([
                    'ok' => false,
                    'message' => 'CUPS did not accept the print job.',
                    'details' => trim(implode("\n", $output)),
                ], 500);
            }

            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO weh.printjobs
                    (uid, tstamp, status, title, planned_pages, duplex, grey, din, cups_id, drucker, real_uid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if ($stmt === false) {
                wp2_json(['ok' => false, 'message' => 'Could not prepare DB insert for weh.printjobs.'], 500);
            }

            $uidForBilling = $billingUid;
            $tstamp = time();
            $status = 0;
            $plannedPages = $totalPages;
            $duplexFlag = $mode === 'simplex' ? 0 : 1;
            $greyFlag = $grayscale ? 1 : 0;
            $din = 'A4';
            $printerName = (string)$printer['name'];
            $realUid = (int)($_SESSION['uid'] ?? 0);

            mysqli_stmt_bind_param(
                $stmt,
                'iiisiiisisi',
                $uidForBilling,
                $tstamp,
                $status,
                $printJobTitle,
                $plannedPages,
                $duplexFlag,
                $greyFlag,
                $din,
                $cupsId,
                $printerName,
                $realUid
            );

            $dbOk = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if (!$dbOk) {
                wp2_json(['ok' => false, 'message' => 'CUPS job was created, but DB insert into weh.printjobs failed.'], 500);
            }

            foreach (($_SESSION['webprinter_v2']['documents'] ?? []) as $doc) {
                if (!empty($doc['path']) && is_file($doc['path'])) {
                    @unlink($doc['path']);
                }
            }

            $_SESSION['webprinter_v2']['documents'] = [];
            $_SESSION['webprinter_v2']['selected_printer_id'] = null;

            wp2_json([
                'ok' => true,
                'message' => 'Print job accepted.',
                'cups_id' => $cupsId,
                'price_formatted' => wp2_format_money($priceRaw),
                'planned_pages' => $plannedPages,
                'inserted_blank_pages' => (int)$stats['inserted_blank_pages'],
            ]);
        }

        default:
            wp2_json(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
}

/**
 * =========================
 * Render
 * =========================
 */
if (!$isAjax) {
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Webprinter</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>
<?php
    echo $templateBootstrap;
}

if (function_exists('load_menu')) {
    load_menu();
}

$visiblePrinters = [];
foreach ($drucker as $printer) {
    if (!empty($printer['visible'])) {
        $visiblePrinters[] = wp2_collect_printer_state($printer);
    }
}

$boot = [
    'selected_printer_id' => $_SESSION['webprinter_v2']['selected_printer_id'],
    'documents' => wp2_public_documents($_SESSION['webprinter_v2']['documents']),
    'printers' => $visiblePrinters,
    'billing_targets' => wp2_allowed_billing_targets(),
    'current_uid' => (int)($_SESSION['uid'] ?? 0),
];
?>

<style>
  .wp2-wrap{
    max-width: 1120px;
    margin: 24px auto 56px;
    padding: 0 16px;
    color: #f2f6f2;
  }

  .wp2-card{
    background: linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.04));
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 22px;
    box-shadow: 0 18px 36px rgba(0,0,0,0.22);
  }

  .wp2-hero{
    padding: 22px 22px 18px;
    margin-bottom: 18px;
  }

  .wp2-title{
    margin: 0 0 8px;
    font-size: clamp(1.7rem, 2.7vw, 2.5rem);
    line-height: 1.08;
    font-weight: 900;
  }

  .wp2-subtitle{
    margin: 0;
    color: rgba(255,255,255,0.82);
    line-height: 1.5;
  }

  .wp2-chip-row{
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }

  .wp2-chip{
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 11px;
    border-radius: 999px;
    background: rgba(17,165,13,0.14);
    border: 1px solid rgba(17,165,13,0.36);
    font-size: .88rem;
    font-weight: 800;
    color: #ddffdb;
  }

  .wp2-steps{
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 10px;
    margin-bottom: 18px;
  }

  .wp2-step-btn{
    appearance: none;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.05);
    color: #f4f8f4;
    border-radius: 16px;
    padding: 13px 14px;
    text-align: left;
    cursor: pointer;
    transition: .18s ease;
    font-weight: 800;
  }

  .wp2-step-btn small{
    display: block;
    opacity: .72;
    font-size: .76rem;
    margin-bottom: 4px;
  }

  .wp2-step-btn:hover{
    transform: translateY(-1px);
    border-color: rgba(17,165,13,0.5);
  }

  .wp2-step-btn.is-active{
    background: linear-gradient(180deg, rgba(17,165,13,0.26), rgba(17,165,13,0.10));
    border-color: rgba(17,165,13,0.78);
  }

  .wp2-step-btn.is-disabled{
    opacity: .48;
    cursor: not-allowed;
  }

  .wp2-panel{
    padding: 18px;
  }

  .wp2-panel + .wp2-panel{
    margin-top: 16px;
  }

  .wp2-panel-head{
    margin-bottom: 16px;
  }

  .wp2-panel-title{
    margin: 0 0 6px;
    font-size: 1.14rem;
    font-weight: 900;
  }

  .wp2-panel-text{
    margin: 0;
    color: rgba(255,255,255,0.75);
    line-height: 1.45;
  }

  .wp2-step{
    display: none;
    animation: wp2Fade .22s ease;
  }

  .wp2-step.is-active{
    display: block;
  }

  @keyframes wp2Fade{
    from{ opacity:0; transform: translateY(6px); }
    to{ opacity:1; transform: translateY(0); }
  }

  .wp2-printer-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
    gap: 14px;
    align-items: stretch;
  }

  .wp2-printer{
    appearance: none;
    width: 100%;
    min-height: 100%;
    text-align: left;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.04);
    color: #fff;
    border-radius: 20px;
    padding: 16px;
    cursor: pointer;
    transition: .18s ease;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: stretch;
    vertical-align: top;
  }

  .wp2-printer:hover{
    transform: translateY(-1px);
    border-color: rgba(17,165,13,0.5);
  }

  .wp2-printer.is-selected{
    border-color: rgba(17,165,13,0.82);
    background: linear-gradient(180deg, rgba(17,165,13,0.16), rgba(255,255,255,0.04));
    box-shadow: inset 0 0 0 1px rgba(17,165,13,0.24);
  }

  .wp2-printer.is-disabled{
    opacity: .56;
    cursor: not-allowed;
  }

  .wp2-printer-top{
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    align-items: flex-start;
  }

  .wp2-printer-main{
    min-width: 0;
  }

  .wp2-printer-title{
    margin: 0 0 4px;
    font-size: 1.06rem;
    font-weight: 900;
  }

  .wp2-printer-sub{
    color: rgba(255,255,255,0.7);
    font-size: .92rem;
  }

  .wp2-status{
    display: inline-flex;
    align-items: center;
    padding: 7px 10px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 800;
    border: 1px solid transparent;
    white-space: nowrap;
    flex: 0 0 auto;
  }

  .wp2-status.ready{ background: rgba(17,165,13,0.18); color:#ddffdb; border-color: rgba(17,165,13,0.42); }
  .wp2-status.busy{ background: rgba(94,170,255,0.14); color:#dcecff; border-color: rgba(94,170,255,0.36); }
  .wp2-status.error{ background: rgba(255,120,120,0.16); color:#ffe2e2; border-color: rgba(255,120,120,0.34); }
  .wp2-status.offline,
  .wp2-status.unknown{ background: rgba(255,255,255,0.10); color:#f2f2f2; border-color: rgba(255,255,255,0.16); }

  .wp2-printer-detail{
    min-height: 18px;
    margin: 0 0 12px;
    font-size: .9rem;
    color: rgba(255,255,255,0.72);
  }

  .wp2-meter{
    margin-top: 10px;
  }

  .wp2-meter-head{
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
    font-size: .88rem;
  }

  .wp2-meter-track{
    height: 10px;
    border-radius: 999px;
    background: rgba(255,255,255,0.08);
    overflow: hidden;
  }

  .wp2-meter-fill{
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, rgba(17,165,13,0.55), rgba(17,165,13,0.95));
  }

  .wp2-meter-fill.black{ background: linear-gradient(90deg, #919191, #e1e1e1); }
  .wp2-meter-fill.cyan{ background: linear-gradient(90deg, #07bdd6, #74f0ff); }
  .wp2-meter-fill.magenta{ background: linear-gradient(90deg, #d12bb2, #ff95ea); }
  .wp2-meter-fill.yellow{ background: linear-gradient(90deg, #bba300, #ffe86e); }

  .wp2-upload-area{
    display: grid;
    gap: 14px;
  }

  .wp2-dropzone{
    position: relative;
    border: 2px dashed rgba(17,165,13,0.52);
    border-radius: 22px;
    padding: 28px 18px;
    text-align: center;
    background: linear-gradient(180deg, rgba(17,165,13,0.12), rgba(255,255,255,0.03));
    transition: .18s ease;
    cursor: pointer;
  }

  .wp2-dropzone:hover,
  .wp2-dropzone.is-drag{
    border-color: rgba(17,165,13,0.92);
    transform: translateY(-1px);
  }

  .wp2-dropzone input{
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
  }

  .wp2-drop-title{
    margin: 0 0 8px;
    font-size: 1.12rem;
    font-weight: 900;
  }

  .wp2-drop-text{
    margin: 0;
    color: rgba(255,255,255,0.78);
    line-height: 1.45;
  }

  .wp2-alerts{
    display: grid;
    gap: 10px;
  }

  .wp2-alert{
    padding: 12px 14px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.06);
    color: #fff;
    line-height: 1.45;
  }

  .wp2-alert.error{
    background: rgba(255,120,120,0.12);
    border-color: rgba(255,120,120,0.30);
    color: #ffe4e4;
  }

  .wp2-alert.success{
    background: rgba(17,165,13,0.14);
    border-color: rgba(17,165,13,0.34);
    color: #e3ffe1;
  }

  .wp2-docs{
    display: grid;
    gap: 10px;
  }

  .wp2-empty{
    padding: 16px;
    text-align: center;
    border-radius: 16px;
    border: 1px dashed rgba(255,255,255,0.16);
    color: rgba(255,255,255,0.72);
  }

  .wp2-doc{
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 14px 15px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.04);
  }

  .wp2-doc-title{
    margin: 0 0 5px;
    font-weight: 900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .wp2-doc-meta{
    color: rgba(255,255,255,0.72);
    font-size: .92rem;
  }

  .wp2-doc-actions{
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
  }

  .wp2-form-grid{
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 16px;
    align-items: start;
  }

  .wp2-field{
    display: grid;
    gap: 8px;
    align-content: start;
  }

  .wp2-label{
    font-weight: 800;
    font-size: .95rem;
  }

  .wp2-select-wrap{
    position: relative;
  }

  .wp2-select-wrap::after{
    content: "▾";
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: rgba(255,255,255,0.82);
    font-size: .95rem;
  }

  .wp2-input,
  .wp2-select{
    width: 100%;
    box-sizing: border-box;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.08);
    color: #fff;
    padding: 0 14px;
    font-size: 1rem;
    height: 56px;
    min-height: 56px;
    line-height: 56px;
  }

  .wp2-select{
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    padding-right: 44px;
    cursor: pointer;
  }

  .wp2-select option{
    color: #111;
    background: #f2f2f2;
  }

  .wp2-help{
    color: rgba(255,255,255,0.70);
    line-height: 1.45;
    font-size: .92rem;
  }

  .wp2-toggle{
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 16px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    min-height: 56px;
    box-sizing: border-box;
  }

  .wp2-toggle input{
    width: 18px;
    height: 18px;
    accent-color: #11a50d;
  }

  .wp2-mode-box{
    padding: 14px 15px;
    border-radius: 16px;
    background: rgba(17,165,13,0.12);
    border: 1px solid rgba(17,165,13,0.28);
    color: #ebffea;
    line-height: 1.45;
  }

  .wp2-review-grid{
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(320px, .9fr);
    gap: 16px;
    align-items: start;
  }

  .wp2-preview-box{
    position: relative;
    min-height: 560px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.10);
    overflow: hidden;
    background: rgba(0,0,0,0.20);
  }

  .wp2-preview{
    width: 100%;
    height: 560px;
    border: none;
    background: #111;
  }

  .wp2-preview-loading{
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.45);
    color: #fff;
    z-index: 2;
    font-weight: 800;
  }

  .wp2-side{
    display: grid;
    gap: 14px;
  }

  .wp2-price{
    font-size: 2rem;
    line-height: 1;
    font-weight: 900;
    margin: 0 0 10px;
  }

  .wp2-price-table{
    width: 100%;
    border-collapse: collapse;
    border-spacing: 0;
    border: 1px solid rgba(255,255,255,0.10);
    overflow: hidden;
    background: rgba(255,255,255,0.04);
  }

  .wp2-price-table th,
  .wp2-price-table td{
    padding: 10px 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-size: .92rem;
  }

  .wp2-price-table th{
    width: 44%;
    color: rgba(255,255,255,0.75);
    font-weight: 700;
  }

  .wp2-price-table tr:last-child th,
  .wp2-price-table tr:last-child td{
    border-bottom: none;
  }

  .wp2-price-note{
    color: rgba(255,255,255,0.78);
    font-size: .92rem;
    line-height: 1.45;
  }

  .wp2-radio-list{
    display: grid;
    gap: 10px;
  }

  .wp2-radio{
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 15px;
    border-radius: 16px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    cursor: pointer;
  }

  .wp2-radio input{
    width: 18px;
    height: 18px;
    accent-color: #11a50d;
  }

  .wp2-actions{
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
  }

  .wp2-btn{
    appearance: none;
    border: none;
    border-radius: 16px;
    padding: 12px 16px;
    font-weight: 900;
    cursor: pointer;
    transition: .18s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
  }

  .wp2-btn:hover{
    transform: translateY(-1px);
  }

  .wp2-btn:disabled{
    opacity: .5;
    cursor: not-allowed;
    transform: none;
  }

  .wp2-btn-primary{
    background: linear-gradient(180deg, #16c111, #11a50d);
    color: #fff;
    box-shadow: 0 10px 24px rgba(17,165,13,0.24);
  }

  .wp2-btn-secondary{
    background: rgba(255,255,255,0.08);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.12);
  }

  .wp2-btn-danger{
    background: rgba(255,120,120,0.14);
    color: #ffe7e7;
    border: 1px solid rgba(255,120,120,0.30);
  }

  .wp2-btn-ghost{
    background: transparent;
    color: #fff;
    border: 1px solid rgba(255,255,255,0.14);
  }

  .wp2-mini-btn{
    min-width: 42px;
    height: 42px;
    padding: 0 13px;
  }

  .wp2-loader{
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    background: rgba(0,0,0,0.52);
  }

  .wp2-loader.is-visible{
    display: flex;
  }

  .wp2-loader-box{
    min-width: 280px;
    max-width: calc(100vw - 40px);
    padding: 22px 24px;
    border-radius: 22px;
    text-align: center;
    background: rgba(18,20,18,0.96);
    color: #ffffff;
    border: 1px solid rgba(255,255,255,0.10);
    box-shadow: 0 18px 48px rgba(0,0,0,0.35);
  }

  .wp2-loader-box *{
    color: #ffffff !important;
  }

  .wp2-spinner{
    width: 42px;
    height: 42px;
    margin: 0 auto 14px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.18);
    border-top-color: #11a50d;
    animation: wp2Spin .9s linear infinite;
  }

  @keyframes wp2Spin{
    to { transform: rotate(360deg); }
  }

  .wp2-toast{
    position: fixed;
    right: 18px;
    bottom: 18px;
    z-index: 9998;
    display: grid;
    gap: 10px;
    max-width: min(420px, calc(100vw - 36px));
  }

  .wp2-toast .wp2-alert{
    box-shadow: 0 16px 36px rgba(0,0,0,0.28);
  }

  @media (max-width: 900px){
    .wp2-review-grid{
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 760px){
    .wp2-wrap{
      padding: 0 12px;
    }

    .wp2-steps{
      grid-template-columns: repeat(2, minmax(0,1fr));
    }

    .wp2-form-grid{
      grid-template-columns: 1fr;
    }

    .wp2-doc{
      grid-template-columns: 1fr;
    }

    .wp2-doc-actions{
      justify-content: flex-start;
    }

    .wp2-preview-box,
    .wp2-preview{
      min-height: 420px;
      height: 420px;
    }

    .wp2-actions{
      flex-direction: column-reverse;
    }

    .wp2-btn{
      width: 100%;
    }
  }
</style>

<div id="countdown" style="display:none;"></div>
<div id="countdownElement" style="display:none;"></div>

<div class="wp2-wrap">
  <!-- <section class="wp2-card wp2-hero">
    <h1 class="wp2-title">Webprinter 2.0</h1>
    <p class="wp2-subtitle">
      Upload valid PDFs, choose print settings, preview the compiled result, and send the job to CUPS.
    </p>
    <div class="wp2-chip-row">
      <span class="wp2-chip">PDF only</span>
      <span class="wp2-chip">SNMP live printer status</span>
      <span class="wp2-chip">Document-separated duplex available</span>
    </div>
  </section> -->

  <div class="wp2-steps" id="wp2Steps">
    <button type="button" class="wp2-step-btn" data-step-target="1">
      <small>Step 1</small>
      Printer
    </button>
    <button type="button" class="wp2-step-btn" data-step-target="2">
      <small>Step 2</small>
      PDFs
    </button>
    <button type="button" class="wp2-step-btn" data-step-target="3">
      <small>Step 3</small>
      Options
    </button>
    <button type="button" class="wp2-step-btn" data-step-target="4">
      <small>Step 4</small>
      Review
    </button>
  </div>

  <section class="wp2-step is-active" data-step="1">
    <div class="wp2-card wp2-panel">
      <div class="wp2-panel-head">
        <h2 class="wp2-panel-title">Choose printer</h2>
        <!-- <p class="wp2-panel-text">Click anywhere on a printer card to select it.</p> -->
      </div>

      <div class="wp2-printer-grid" id="wp2PrinterGrid"></div>

      <div class="wp2-actions">
        <div></div>
        <button type="button" class="wp2-btn wp2-btn-primary" id="wp2Next1">Continue</button>
      </div>
    </div>
  </section>

  <section class="wp2-step" data-step="2">
    <div class="wp2-card wp2-panel">
      <div class="wp2-panel-head">
        <h2 class="wp2-panel-title">Upload PDFs</h2>
        <!-- <p class="wp2-panel-text">Only valid A4 PDF files are accepted.</p> -->
      </div>

      <div class="wp2-upload-area">
        <label class="wp2-dropzone" id="wp2Dropzone">
          <input type="file" id="wp2FileInput" name="documents[]" multiple accept=".pdf,application/pdf">
          <p class="wp2-drop-title">Drop A4 PDFs here or click to browse</p>
          <p class="wp2-drop-text">Only A4 PDF files are allowed. The document order below is the print order.</p>
        </label>

        <div id="wp2UploadErrors" class="wp2-alerts"></div>
        <div id="wp2Documents" class="wp2-docs"></div>
      </div>

      <div class="wp2-actions">
        <button type="button" class="wp2-btn wp2-btn-secondary" id="wp2Back2">Back</button>
        <button type="button" class="wp2-btn wp2-btn-primary" id="wp2Next2">Continue</button>
      </div>
    </div>
  </section>

  <section class="wp2-step" data-step="3">
    <div class="wp2-card wp2-panel">
      <div class="wp2-panel-head">
        <h2 class="wp2-panel-title">Print options</h2>
        <!-- <p class="wp2-panel-text">Simplex, duplex, or document-separated duplex.</p> -->
      </div>

      <div class="wp2-form-grid">
        <div class="wp2-field">
          <label class="wp2-label" for="wp2Mode">Mode</label>
          <div class="wp2-select-wrap">
            <select class="wp2-select" id="wp2Mode">
              <option value="duplex">Duplex</option>
              <option value="duplex_document">Duplex (document-separated)</option>
              <option value="simplex">Simplex</option>
            </select>
          </div>
          <div class="wp2-mode-box" id="wp2ModeInfo"></div>
        </div>

        <div class="wp2-field">
          <label class="wp2-label" for="wp2Copies">Copies</label>
          <input class="wp2-input" id="wp2Copies" type="number" min="1" max="500" value="1">
          <div class="wp2-help">Changes the total page count and price.</div>
        </div>

        <div class="wp2-field">
          <label class="wp2-label">Color mode</label>
          <label class="wp2-toggle">
            <input type="checkbox" id="wp2Grayscale">
            <span id="wp2GrayscaleLabel">Print in black & white</span>
          </label>
          <div class="wp2-help" id="wp2GrayscaleHelp"></div>
        </div>
      </div>

      <div class="wp2-actions">
        <button type="button" class="wp2-btn wp2-btn-secondary" id="wp2Back3">Back</button>
        <button type="button" class="wp2-btn wp2-btn-primary" id="wp2Next3">Review</button>
      </div>
    </div>
  </section>

  <section class="wp2-step" data-step="4">
    <div class="wp2-card wp2-panel">
      <div class="wp2-panel-head">
        <h2 class="wp2-panel-title">Review and print</h2>
        <!-- <p class="wp2-panel-text">The preview shows the compiled PDF that will be sent to CUPS.</p> -->
      </div>

      <div class="wp2-review-grid">
        <div class="wp2-preview-box">
          <div class="wp2-preview-loading" id="wp2PreviewLoading">Generating preview…</div>
          <iframe class="wp2-preview" id="wp2Preview" title="PDF preview"></iframe>
        </div>

        <div class="wp2-side">
          <div class="wp2-card wp2-panel">
            <div class="wp2-price" id="wp2Price">–</div>
            <div id="wp2QuoteDetails" class="wp2-alerts"></div>
          </div>

          <div class="wp2-card wp2-panel">
            <div class="wp2-panel-head">
              <h2 class="wp2-panel-title">Billing</h2>
            </div>
            <div id="wp2Billing" class="wp2-radio-list"></div>
          </div>

          <div id="wp2ReviewStatus" class="wp2-alerts"></div>
        </div>
      </div>

      <div class="wp2-actions">
        <button type="button" class="wp2-btn wp2-btn-secondary" id="wp2Back4">Back</button>
        <button type="button" class="wp2-btn wp2-btn-primary" id="wp2Submit">Send print job</button>
      </div>
    </div>
  </section>
</div>

<div class="wp2-loader" id="wp2Loader">
  <div class="wp2-loader-box">
    <div class="wp2-spinner"></div>
    <div id="wp2LoaderText">Please wait…</div>
  </div>
</div>

<div class="wp2-toast" id="wp2Toast"></div>

<script>
(() => {
  const boot = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const state = {
    step: 1,
    printers: Array.isArray(boot.printers) ? boot.printers : [],
    documents: Array.isArray(boot.documents) ? boot.documents : [],
    selectedPrinterId: boot.selected_printer_id ? Number(boot.selected_printer_id) : null,
    billingTargets: Array.isArray(boot.billing_targets) ? boot.billing_targets : [],
    currentUid: Number(boot.current_uid || 0),
    quote: null,
    options: {
      mode: 'duplex',
      copies: 1,
      grayscale: false,
      billingUid: Number(boot.current_uid || 0),
    },
  };

  const modeDescriptions = {
    simplex: 'Each PDF page is printed on its own sheet.',
    duplex: 'Standard double-sided printing across all uploaded documents.',
    duplex_document: 'Double-sided printing with automatic blank pages between documents when needed, so each new document starts on a front page.',
  };

  const el = {
    steps: document.getElementById('wp2Steps'),
    printerGrid: document.getElementById('wp2PrinterGrid'),
    dropzone: document.getElementById('wp2Dropzone'),
    fileInput: document.getElementById('wp2FileInput'),
    uploadErrors: document.getElementById('wp2UploadErrors'),
    documents: document.getElementById('wp2Documents'),
    mode: document.getElementById('wp2Mode'),
    modeInfo: document.getElementById('wp2ModeInfo'),
    copies: document.getElementById('wp2Copies'),
    grayscale: document.getElementById('wp2Grayscale'),
    grayscaleLabel: document.getElementById('wp2GrayscaleLabel'),
    grayscaleHelp: document.getElementById('wp2GrayscaleHelp'),
    price: document.getElementById('wp2Price'),
    quoteDetails: document.getElementById('wp2QuoteDetails'),
    billing: document.getElementById('wp2Billing'),
    reviewStatus: document.getElementById('wp2ReviewStatus'),
    preview: document.getElementById('wp2Preview'),
    previewLoading: document.getElementById('wp2PreviewLoading'),
    loader: document.getElementById('wp2Loader'),
    loaderText: document.getElementById('wp2LoaderText'),
    toast: document.getElementById('wp2Toast'),
    next1: document.getElementById('wp2Next1'),
    next2: document.getElementById('wp2Next2'),
    next3: document.getElementById('wp2Next3'),
    back2: document.getElementById('wp2Back2'),
    back3: document.getElementById('wp2Back3'),
    back4: document.getElementById('wp2Back4'),
    submit: document.getElementById('wp2Submit'),
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function api(action, payload = {}, files = null) {
    const form = new FormData();
    form.append('ajax', '1');
    form.append('action', action);

    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      form.append(key, String(value));
    });

    if (files) {
      Array.from(files).forEach(file => {
        form.append('documents[]', file);
      });
    }

    const response = await fetch(window.location.href, {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
    });

    const text = await response.text();
    let data = null;

    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      throw new Error(text || 'Invalid server response.');
    }

    if (!response.ok) {
      throw new Error(data.message || 'Request failed.');
    }

    return data;
  }

  function showLoader(text = 'Please wait…') {
    el.loaderText.textContent = text;
    el.loader.classList.add('is-visible');
  }

  function hideLoader() {
    el.loader.classList.remove('is-visible');
  }

  function toast(message, type = 'error', timeout = 3600) {
    const node = document.createElement('div');
    node.className = `wp2-alert ${type}`;
    node.textContent = message;
    el.toast.appendChild(node);
    window.setTimeout(() => node.remove(), timeout);
  }

  function getSelectedPrinter() {
    return state.printers.find(p => Number(p.id) === Number(state.selectedPrinterId)) || null;
  }

  function renderStepButtons() {
    el.steps.querySelectorAll('[data-step-target]').forEach(btn => {
      const step = Number(btn.dataset.stepTarget || 1);
      const disabled =
        (step > 1 && !state.selectedPrinterId) ||
        (step > 2 && state.documents.length === 0);

      btn.classList.toggle('is-active', step === state.step);
      btn.classList.toggle('is-disabled', disabled);
    });
  }

  function renderPrinters() {
    el.printerGrid.innerHTML = state.printers.map(printer => {
      const isSelected = Number(printer.id) === Number(state.selectedPrinterId);
      const classes = [
        'wp2-printer',
        isSelected ? 'is-selected' : '',
        printer.can_select ? '' : 'is-disabled',
      ].join(' ').trim();

      const tonerHtml = Array.isArray(printer.toner)
        ? printer.toner.map(item => `
            <div class="wp2-meter">
              <div class="wp2-meter-head">
                <span>${escapeHtml(item.label)}</span>
                <strong>${Number(item.percent)}%</strong>
              </div>
              <div class="wp2-meter-track">
                <div class="wp2-meter-fill ${escapeHtml(item.css)}" style="width:${Number(item.percent)}%"></div>
              </div>
            </div>
          `).join('')
        : '';

      const detail = printer.status_detail ? escapeHtml(printer.status_detail) : '&nbsp;';

      return `
        <button type="button"
                class="${classes}"
                data-printer-id="${Number(printer.id)}"
                ${printer.can_select ? '' : 'disabled'}>
          <div class="wp2-printer-top">
            <div class="wp2-printer-main">
              <div class="wp2-printer-title">${escapeHtml(printer.modell)}</div>
              <div class="wp2-printer-sub">${escapeHtml(printer.farbe)}</div>
            </div>
            <span class="wp2-status ${escapeHtml(printer.status_class)}">${escapeHtml(printer.status_text)}</span>
          </div>

          <p class="wp2-printer-detail">${detail}</p>

          <div class="wp2-meter">
            <div class="wp2-meter-head">
              <span>A4 paper</span>
              <strong>${Number(printer.paper.current)} / ${Number(printer.paper.max)}</strong>
            </div>
            <div class="wp2-meter-track">
              <div class="wp2-meter-fill" style="width:${Number(printer.paper.percent)}%"></div>
            </div>
          </div>

          ${tonerHtml}
        </button>
      `;
    }).join('');
  }

  function renderUploadErrors(errors = []) {
    if (!errors.length) {
      el.uploadErrors.innerHTML = '';
      return;
    }

    el.uploadErrors.innerHTML = errors.map(item => `
      <div class="wp2-alert error">
        <strong>${escapeHtml(item.file || 'File')}</strong><br>
        ${escapeHtml(item.message || 'Unknown error')}
      </div>
    `).join('');
  }

  function renderDocuments() {
    if (!state.documents.length) {
      el.documents.innerHTML = '<div class="wp2-empty">No PDFs uploaded yet.</div>';
      return;
    }

    el.documents.innerHTML = state.documents.map((doc, index) => `
      <div class="wp2-doc">
        <div>
          <div class="wp2-doc-title">${escapeHtml(doc.name)}</div>
          <div class="wp2-doc-meta">${Number(doc.pages)} page(s) · ${escapeHtml(doc.size_label || '')}</div>
        </div>
        <div class="wp2-doc-actions">
          <a class="wp2-btn wp2-btn-ghost" href="?ajax=1&action=preview_single&doc_id=${encodeURIComponent(doc.id)}" target="_blank" rel="noopener">Open</a>
          <button type="button" class="wp2-btn wp2-btn-secondary wp2-mini-btn" data-doc-up="${escapeHtml(doc.id)}" ${index === 0 ? 'disabled' : ''}>↑</button>
          <button type="button" class="wp2-btn wp2-btn-secondary wp2-mini-btn" data-doc-down="${escapeHtml(doc.id)}" ${index === state.documents.length - 1 ? 'disabled' : ''}>↓</button>
          <button type="button" class="wp2-btn wp2-btn-danger" data-doc-delete="${escapeHtml(doc.id)}">Remove</button>
        </div>
      </div>
    `).join('');
  }

  function updateModeInfo() {
    el.modeInfo.textContent = modeDescriptions[state.options.mode] || '';
  }

  function renderBilling() {
    if (!state.billingTargets.length) {
      el.billing.innerHTML = '<div class="wp2-empty">No billing options available.</div>';
      return;
    }

    el.billing.innerHTML = state.billingTargets.map(target => `
      <label class="wp2-radio">
        <input type="radio"
               name="wp2BillingUid"
               value="${Number(target.uid)}"
               ${Number(target.uid) === Number(state.options.billingUid) ? 'checked' : ''}>
        <span>${escapeHtml(target.label)}</span>
      </label>
    `).join('');
  }

  function renderReviewStatus(message = '', type = '') {
    if (!message) {
      el.reviewStatus.innerHTML = '';
      return;
    }

    el.reviewStatus.innerHTML = `<div class="wp2-alert ${escapeHtml(type)}">${escapeHtml(message)}</div>`;
  }

  function updateGrayscaleUI() {
    const printer = getSelectedPrinter();

    if (!printer) {
      state.options.grayscale = false;
      el.grayscale.checked = false;
      el.grayscale.disabled = false;
      el.grayscaleLabel.textContent = 'Print in black & white';
      el.grayscaleHelp.textContent = 'Optional on the color printer.';
      return;
    }

    if (!printer.supports_color) {
      state.options.grayscale = true;
      el.grayscale.checked = true;
      el.grayscale.disabled = true;
      el.grayscaleLabel.textContent = 'Black & white only';
      el.grayscaleHelp.textContent = 'This printer supports black & white output only.';
    } else {
      el.grayscale.disabled = false;
      el.grayscale.checked = Boolean(state.options.grayscale);
      el.grayscaleLabel.textContent = 'Print in black & white';
      el.grayscaleHelp.textContent = 'Use monochrome output on the color printer.';
    }
  }

  function collectOptions() {
    state.options.mode = el.mode.value;
    state.options.copies = Math.max(1, Math.min(500, Number(el.copies.value || 1)));
    state.options.grayscale = Boolean(el.grayscale.checked);
    el.copies.value = String(state.options.copies);

    const checkedBilling = el.billing.querySelector('input[name="wp2BillingUid"]:checked');
    if (checkedBilling) {
      state.options.billingUid = Number(checkedBilling.value);
    }
  }

  async function selectPrinter(printerId) {
    const printer = state.printers.find(p => Number(p.id) === Number(printerId));
    if (!printer || !printer.can_select) return;

    const previousPrinterId = state.selectedPrinterId;
    state.selectedPrinterId = Number(printerId);

    if (Number(previousPrinterId) !== Number(printerId)) {
      state.options.grayscale = printer.supports_color ? false : true;
    }

    renderPrinters();
    updateGrayscaleUI();

    try {
      await api('set_selected_printer', { printer_id: state.selectedPrinterId });
    } catch (error) {
      toast(error.message || 'Could not save selected printer.');
    }
  }

  async function setStep(step) {
    if (step > 1 && !state.selectedPrinterId) {
      toast('Please select a printer first.');
      return;
    }

    if (step > 2 && state.documents.length === 0) {
      toast('Please upload at least one PDF first.');
      return;
    }

    if (step === 4) {
      collectOptions();
      await refreshQuoteAndPreview();
    }

    state.step = step;

    document.querySelectorAll('.wp2-step').forEach(section => {
      section.classList.toggle('is-active', Number(section.dataset.step) === Number(step));
    });

    renderStepButtons();
  }

  async function uploadFiles(fileList) {
    if (!fileList || !fileList.length) return;

    const files = Array.from(fileList);
    if (!files.length) return;

    try {
      showLoader('Uploading and validating A4 PDFs…');
      const result = await api('upload_documents', {}, files);
      state.documents = Array.isArray(result.documents) ? result.documents : [];
      renderDocuments();
      renderUploadErrors(result.errors || []);

      if (result.added && result.added.length) {
        toast(`${result.added.length} file(s) uploaded.`, 'success', 2600);
      }

      if (result.errors && result.errors.length && !result.added.length) {
        toast('Upload finished with errors.');
      }
    } catch (error) {
      renderUploadErrors([{ file: 'Upload', message: error.message || 'Upload failed.' }]);
      toast(error.message || 'Upload failed.');
    } finally {
      hideLoader();
      el.fileInput.value = '';
    }
  }

  async function saveOrder(order) {
    try {
      showLoader('Saving document order…');
      const result = await api('reorder_documents', {
        order: JSON.stringify(order),
      });
      state.documents = Array.isArray(result.documents) ? result.documents : [];
      renderDocuments();

      if (state.step === 4) {
        await refreshQuoteAndPreview();
      }
    } catch (error) {
      toast(error.message || 'Could not save document order.');
    } finally {
      hideLoader();
    }
  }

  async function deleteDocument(docId) {
    try {
      showLoader('Removing document…');
      const result = await api('delete_document', { doc_id: docId });
      state.documents = Array.isArray(result.documents) ? result.documents : [];
      renderDocuments();

      if (!state.documents.length) {
        state.quote = null;
        el.price.textContent = '–';
        el.quoteDetails.innerHTML = '';
        el.preview.src = 'about:blank';
      } else if (state.step === 4) {
        await refreshQuoteAndPreview();
      }
    } catch (error) {
      toast(error.message || 'Could not remove document.');
    } finally {
      hideLoader();
    }
  }

  async function refreshQuote() {
    collectOptions();

    const result = await api('quote', {
      printer_id: state.selectedPrinterId,
      mode: state.options.mode,
      copies: state.options.copies,
      grayscale: state.options.grayscale ? '1' : '',
      billing_uid: state.options.billingUid,
    });

    state.quote = result;
    const summary = result.summary;
    const pb = summary.price_breakdown;

    el.price.textContent = `${summary.price_formatted} €`;
    el.quoteDetails.innerHTML = `
      <div class="wp2-alert ${result.possible ? 'success' : 'error'}">${escapeHtml(result.reason_text)}</div>

      <table class="wp2-price-table">
        <tbody>
          <tr><th>Documents</th><td>${summary.documents}</td></tr>
          <tr><th>Original pages</th><td>${summary.original_pages}</td></tr>
          <tr><th>Inserted blank pages</th><td>${summary.inserted_blank_pages}</td></tr>
          <tr><th>Pages per copy</th><td>${summary.effective_pages_per_copy}</td></tr>
          <tr><th>Copies</th><td>${summary.copies}</td></tr>
          <tr><th>Total printed pages</th><td>${summary.total_pages}</td></tr>
          <tr><th>Pricing mode</th><td>${escapeHtml(pb.price_mode_label)} / ${escapeHtml(pb.billing_mode_label)}</td></tr>
        </tbody>
      </table>

      <table class="wp2-price-table">
        <tbody>
          <tr>
            <th>Duplex-rated pages</th>
            <td>${pb.duplex_rated_pages} × ${escapeHtml(pb.duplex_rate_ct_label)} ct = €${escapeHtml(pb.duplex_cost_formatted)}</td>
          </tr>
          <tr>
            <th>Simplex-rated pages</th>
            <td>${pb.simplex_rated_pages} × ${escapeHtml(pb.simplex_rate_ct_label)} ct = €${escapeHtml(pb.simplex_cost_formatted)}</td>
          </tr>
          <tr>
            <th>Total</th>
            <td><strong>€${escapeHtml(pb.total_cost_formatted)}</strong></td>
          </tr>
        </tbody>
      </table>
    `;

    return result;
  }

  async function refreshQuoteAndPreview() {
    renderReviewStatus();
    el.previewLoading.style.display = 'flex';

    try {
      const quote = await refreshQuote();
      el.preview.src = `?ajax=1&action=preview_compiled&mode=${encodeURIComponent(state.options.mode)}&t=${Date.now()}`;

      if (!quote.possible) {
        renderReviewStatus(quote.reason_text, 'error');
      }
    } catch (error) {
      el.price.textContent = '–';
      el.quoteDetails.innerHTML = `<div class="wp2-alert error">${escapeHtml(error.message || 'Quote failed.')}</div>`;
      el.preview.src = 'about:blank';
      el.previewLoading.style.display = 'none';
      renderReviewStatus(error.message || 'Preview failed.', 'error');
    }
  }

  async function submitPrint() {
    collectOptions();

    try {
      showLoader('Sending A4 job to printer…');
      const result = await api('submit_print', {
        printer_id: state.selectedPrinterId,
        mode: state.options.mode,
        copies: state.options.copies,
        grayscale: state.options.grayscale ? '1' : '',
        billing_uid: state.options.billingUid,
      });

      renderReviewStatus(
        `Print job accepted${result.cups_id ? ` (CUPS ID ${result.cups_id})` : ''}.`,
        'success'
      );

      state.documents = [];
      state.selectedPrinterId = null;
      state.quote = null;

      renderDocuments();
      renderPrinters();
      el.price.textContent = '–';
      el.quoteDetails.innerHTML = '';
      el.preview.src = 'about:blank';

      window.setTimeout(() => {
        window.location.reload();
      }, 2200);
    } catch (error) {
      renderReviewStatus(error.message || 'Print job failed.', 'error');
      toast(error.message || 'Print job failed.');
    } finally {
      hideLoader();
    }
  }

  function bindEvents() {
    el.steps.addEventListener('click', async (event) => {
      const btn = event.target.closest('[data-step-target]');
      if (!btn) return;
      const step = Number(btn.dataset.stepTarget || 1);
      await setStep(step);
    });

    el.printerGrid.addEventListener('click', async (event) => {
      const card = event.target.closest('[data-printer-id]');
      if (!card) return;
      await selectPrinter(Number(card.dataset.printerId));
    });

    el.fileInput.addEventListener('change', async (event) => {
      await uploadFiles(event.target.files);
    });

    ['dragenter', 'dragover'].forEach(type => {
      el.dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        el.dropzone.classList.add('is-drag');
      });
    });

    ['dragleave', 'drop'].forEach(type => {
      el.dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        el.dropzone.classList.remove('is-drag');
      });
    });

    el.dropzone.addEventListener('drop', async (event) => {
      const files = event.dataTransfer?.files;
      await uploadFiles(files);
    });

    el.documents.addEventListener('click', async (event) => {
      const del = event.target.closest('[data-doc-delete]');
      if (del) {
        await deleteDocument(del.dataset.docDelete);
        return;
      }

      const up = event.target.closest('[data-doc-up]');
      if (up) {
        const index = state.documents.findIndex(doc => doc.id === up.dataset.docUp);
        if (index > 0) {
          const nextOrder = [...state.documents];
          [nextOrder[index - 1], nextOrder[index]] = [nextOrder[index], nextOrder[index - 1]];
          await saveOrder(nextOrder.map(doc => doc.id));
        }
        return;
      }

      const down = event.target.closest('[data-doc-down]');
      if (down) {
        const index = state.documents.findIndex(doc => doc.id === down.dataset.docDown);
        if (index >= 0 && index < state.documents.length - 1) {
          const nextOrder = [...state.documents];
          [nextOrder[index], nextOrder[index + 1]] = [nextOrder[index + 1], nextOrder[index]];
          await saveOrder(nextOrder.map(doc => doc.id));
        }
      }
    });

    el.mode.addEventListener('change', async () => {
      collectOptions();
      updateModeInfo();
      if (state.step === 4) {
        await refreshQuoteAndPreview();
      }
    });

    el.copies.addEventListener('change', async () => {
      collectOptions();
      if (state.step === 4) {
        await refreshQuoteAndPreview();
      }
    });

    el.grayscale.addEventListener('change', async () => {
      collectOptions();
      if (state.step === 4) {
        await refreshQuoteAndPreview();
      }
    });

    el.billing.addEventListener('change', async () => {
      collectOptions();
      if (state.step === 4) {
        try {
          await refreshQuote();
        } catch (error) {
          el.price.textContent = '–';
          el.quoteDetails.innerHTML = `<div class="wp2-alert error">${escapeHtml(error.message || 'Quote failed.')}</div>`;
          renderReviewStatus(error.message || 'Quote failed.', 'error');
        }
      }
    });

    el.preview.addEventListener('load', () => {
      el.previewLoading.style.display = 'none';
    });

    el.next1.addEventListener('click', () => setStep(2));
    el.next2.addEventListener('click', () => setStep(3));
    el.next3.addEventListener('click', () => setStep(4));
    el.back2.addEventListener('click', () => setStep(1));
    el.back3.addEventListener('click', () => setStep(2));
    el.back4.addEventListener('click', () => setStep(3));
    el.submit.addEventListener('click', submitPrint);
  }

  function init() {
    document.title = 'Webprinter';
    renderStepButtons();
    renderPrinters();
    renderDocuments();
    renderBilling();
    updateModeInfo();
    updateGrayscaleUI();
  }

  bindEvents();
  init();
})();
</script>

<?php
$conn->close();
?>