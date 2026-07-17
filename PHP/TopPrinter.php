<?php
ob_start();
session_start();

require('conn.php');
require('template.php');

mysqli_set_charset($conn, 'utf8mb4');

$berechtigt = auth($conn)
    && !empty($_SESSION['valid'])
    && (
        !empty($_SESSION['DruckerAG'])
        || !empty($_SESSION['NetzAG'])
        || !empty($_SESSION['Webmaster'])
    );

if (!$berechtigt) {
    header('Location: denied.php');
    exit;
}

$topLimit = 15;
$oldestSemester = mktime(0, 0, 0, 4, 1, 2025);

function topprinter_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function topprinter_execute_stmt(
    mysqli_stmt $stmt,
    string $types = '',
    array $params = []
): void {
    if ($types !== '') {
        $bindParams = [$types];

        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    $stmt->execute();
}

function topprinter_first_word($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

    return isset($parts[0]) ? (string)$parts[0] : '';
}

function topprinter_user_name(array $row): string
{
    $firstname = topprinter_first_word($row['firstname'] ?? '');
    $lastname = topprinter_first_word($row['lastname'] ?? '');
    $name = trim($firstname . ' ' . $lastname);

    if ($name !== '') {
        return $name;
    }

    $username = trim((string)($row['username'] ?? ''));

    if ($username !== '') {
        return $username;
    }

    return 'UID ' . (int)($row['uid'] ?? 0);
}

function topprinter_room($room, $oldroom = null): string
{
    $room = (int)$room;
    $oldroom = (int)$oldroom;

    if ($room > 0) {
        return sprintf('%04d', $room);
    }

    if ($oldroom > 0) {
        return sprintf('%04d', $oldroom);
    }

    return '-';
}

function topprinter_tower($tower): string
{
    $tower = strtolower(trim((string)$tower));

    if ($tower === 'weh') {
        return 'WEH';
    }

    if ($tower === 'tvk') {
        return 'TvK';
    }

    return $tower !== '' ? strtoupper($tower) : '-';
}

function topprinter_tower_class($tower): string
{
    $tower = strtolower(trim((string)$tower));

    if ($tower === 'weh') {
        return 'topprinter-tower-weh';
    }

    if ($tower === 'tvk') {
        return 'topprinter-tower-tvk';
    }

    return 'topprinter-tower-other';
}

function topprinter_semester_start(int $timestamp): int
{
    $month = (int)date('m', $timestamp);
    $year = (int)date('Y', $timestamp);

    if ($month >= 4 && $month <= 9) {
        return mktime(0, 0, 0, 4, 1, $year);
    }

    if ($month >= 10) {
        return mktime(0, 0, 0, 10, 1, $year);
    }

    return mktime(0, 0, 0, 10, 1, $year - 1);
}

function topprinter_semester_end(int $semesterStart): int
{
    $month = (int)date('m', $semesterStart);
    $year = (int)date('Y', $semesterStart);

    if ($month === 4) {
        return mktime(0, 0, 0, 10, 1, $year);
    }

    return mktime(0, 0, 0, 4, 1, $year + 1);
}

function topprinter_previous_semester(int $semesterStart): int
{
    $month = (int)date('m', $semesterStart);
    $year = (int)date('Y', $semesterStart);

    if ($month === 4) {
        return mktime(0, 0, 0, 10, 1, $year - 1);
    }

    return mktime(0, 0, 0, 4, 1, $year);
}

function topprinter_semester_label(int $semesterStart): string
{
    $month = (int)date('m', $semesterStart);
    $year = (int)date('Y', $semesterStart);

    if ($month === 4) {
        return 'SoSe ' . $year;
    }

    return 'WiSe ' . $year . '/' . substr((string)($year + 1), -2);
}

$currentYear = (int)date('Y');
$oldestYear = (int)date('Y', $oldestSemester);
$yearOptions = [];

for ($year = $currentYear; $year >= $oldestYear; $year--) {
    $yearOptions[] = $year;
}

$currentSemesterStart = topprinter_semester_start(time());
$semesterOptions = [];
$semesterCursor = $currentSemesterStart;

while ($semesterCursor >= $oldestSemester) {
    $semesterOptions[$semesterCursor] = topprinter_semester_label($semesterCursor);
    $semesterCursor = topprinter_previous_semester($semesterCursor);
}

$period = (string)($_GET['period'] ?? 'semester');

if (!in_array($period, ['year', 'semester'], true)) {
    $period = 'semester';
}

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

if (!in_array($selectedYear, $yearOptions, true)) {
    $selectedYear = $currentYear;
}

$selectedSemester = isset($_GET['semester_start'])
    ? (int)$_GET['semester_start']
    : $currentSemesterStart;

if (!array_key_exists($selectedSemester, $semesterOptions)) {
    $selectedSemester = $currentSemesterStart;
}

if ($period === 'year') {
    $rangeStart = mktime(0, 0, 0, 1, 1, $selectedYear);

    if ($rangeStart < $oldestSemester) {
        $rangeStart = $oldestSemester;
    }

    $rangeEnd = mktime(0, 0, 0, 1, 1, $selectedYear + 1);
    $periodTitle = (string)$selectedYear;
} else {
    $rangeStart = $selectedSemester;
    $rangeEnd = topprinter_semester_end($selectedSemester);
    $periodTitle = topprinter_semester_label($selectedSemester);
}

$rangeLabel = date('d.m.Y', $rangeStart)
    . ' – '
    . date('d.m.Y', $rangeEnd - 1);

$sql = "
    SELECT
        job_data.uid,
        SUM(job_data.pages) AS total_pages,
        COUNT(*) AS printjob_count,
        u.firstname,
        u.lastname,
        u.username,
        u.room,
        u.oldroom,
        u.turm
    FROM (
        SELECT
            p.id,
            p.uid,
            MAX(
                COALESCE(
                    p.true_pages,
                    t.print_pages,
                    p.planned_pages,
                    0
                )
            ) AS pages
        FROM printjobs p
        LEFT JOIN transfers t
            ON t.print_id = p.id
        WHERE
            p.tstamp >= ?
            AND p.tstamp < ?
            AND p.status IN (1, 4)
        GROUP BY
            p.id,
            p.uid
    ) job_data
    LEFT JOIN users u
        ON u.uid = job_data.uid
    GROUP BY
        job_data.uid,
        u.firstname,
        u.lastname,
        u.username,
        u.room,
        u.oldroom,
        u.turm
    HAVING SUM(job_data.pages) > 0
    ORDER BY
        total_pages DESC,
        printjob_count DESC,
        job_data.uid ASC
";

$stmt = $conn->prepare($sql);

topprinter_execute_stmt(
    $stmt,
    'ii',
    [$rangeStart, $rangeEnd]
);

$result = $stmt->get_result();
$printerRows = [];

while ($row = $result->fetch_assoc()) {
    $row['uid'] = (int)$row['uid'];
    $row['total_pages'] = (int)$row['total_pages'];
    $row['printjob_count'] = (int)$row['printjob_count'];
    $printerRows[] = $row;
}

$stmt->close();

$totalUsers = count($printerRows);
$totalPages = 0;
$totalPrintjobs = 0;

foreach ($printerRows as $row) {
    $totalPages += (int)$row['total_pages'];
    $totalPrintjobs += (int)$row['printjob_count'];
}

$topRows = array_slice($printerRows, 0, $topLimit);
$restRows = array_slice($printerRows, $topLimit);

$topPagesTotal = 0;
$restPages = 0;
$restPrintjobs = 0;

foreach ($topRows as $row) {
    $topPagesTotal += (int)$row['total_pages'];
}

foreach ($restRows as $row) {
    $restPages += (int)$row['total_pages'];
    $restPrintjobs += (int)$row['printjob_count'];
}

$chartColors = [
    '#11a50d',
    '#2f7ed8',
    '#f28f43',
    '#910000',
    '#8bbc21',
    '#1aadce',
    '#492970',
    '#f28f9d',
    '#77a1e5',
    '#c42525',
    '#a6c96a',
    '#7cb5ec',
    '#434348',
    '#90ed7d',
    '#f7a35c',
    '#8085e9',
    '#f15c80',
    '#e4d354',
    '#2b908f',
    '#f45b5b',
];

$chartEntries = [];

foreach ($topRows as $index => $row) {
    $chartEntries[] = [
        'type' => 'user',
        'row' => $row,
        'pages' => (int)$row['total_pages'],
        'color' => $chartColors[$index % count($chartColors)],
    ];
}

if ($restPages > 0) {
    $chartEntries[] = [
        'type' => 'rest',
        'row' => null,
        'pages' => $restPages,
        'color' => '#666666',
    ];
}

$gradientParts = [];
$gradientPosition = 0.0;

if ($totalPages > 0) {
    foreach ($chartEntries as $entry) {
        $percentage = ((int)$entry['pages'] / $totalPages) * 100;
        $gradientEnd = $gradientPosition + $percentage;

        $gradientParts[] = $entry['color']
            . ' '
            . number_format($gradientPosition, 4, '.', '')
            . '% '
            . number_format($gradientEnd, 4, '.', '')
            . '%';

        $gradientPosition = $gradientEnd;
    }
}

$chartGradient = !empty($gradientParts)
    ? 'conic-gradient(' . implode(', ', $gradientParts) . ')'
    : '#333';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Top Printer</title>

    <link rel="stylesheet" href="WEH.css" media="screen">
    <link rel="stylesheet" href="TRANSFERS.css" media="screen">

    <style>
        :root {
            --topprinter-primary: #11a50d;
            --topprinter-panel: #222;
            --topprinter-field: #2b2b2b;
            --topprinter-border: #444;
            --topprinter-text: #f2f2f2;
            --topprinter-muted: #aaa;
        }

        .topprinter-page {
            width: min(1500px, calc(100% - 30px));
            margin: 0 auto 40px;
        }

        .topprinter-header {
            margin: 18px 0 14px;
        }

        .topprinter-title {
            margin: 0;
            color: #fff;
            font-size: 28px;
            line-height: 1.2;
        }

        .topprinter-subtitle {
            margin: 6px 0 0;
            color: var(--topprinter-muted);
            font-size: 14px;
        }

        .topprinter-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: stretch;
            padding: 14px;
            margin-bottom: 14px;
            background: var(--topprinter-panel);
            border: 1px solid var(--topprinter-border);
            border-radius: 12px;
        }

        .topprinter-period-control {
            display: inline-flex;
            align-items: stretch;
        }

        .topprinter-period-button {
            min-height: 42px;
            padding: 8px 14px;
            color: #fff;
            background: var(--topprinter-field);
            border: 1px solid var(--topprinter-border);
            border-radius: 8px 0 0 8px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .topprinter-period-select {
            min-height: 42px;
            min-width: 155px;
            padding: 7px 34px 7px 11px;
            color: #fff;
            background: var(--topprinter-field);
            border: 1px solid var(--topprinter-border);
            border-left: 0;
            border-radius: 0 8px 8px 0;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            outline: none;
        }

        .topprinter-period-button:hover,
        .topprinter-period-select:hover {
            border-color: var(--topprinter-primary);
        }

        .topprinter-period-control.active
        .topprinter-period-button {
            border-color: var(--topprinter-primary);
            background: rgba(17, 165, 13, 0.16);
        }

        .topprinter-period-control.active
        .topprinter-period-select {
            border-color: var(--topprinter-primary);
            background: var(--topprinter-field);
        }

        .topprinter-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .topprinter-summary-card,
        .topprinter-panel {
            background: var(--topprinter-panel);
            border: 1px solid var(--topprinter-border);
            border-radius: 12px;
        }

        .topprinter-summary-card {
            padding: 16px;
        }

        .topprinter-summary-label {
            display: block;
            margin-bottom: 5px;
            color: var(--topprinter-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .topprinter-summary-value {
            color: #fff;
            font-size: 25px;
            font-weight: 900;
        }

        .topprinter-content {
            display: grid;
            grid-template-columns: minmax(360px, 0.9fr) minmax(520px, 1.4fr);
            gap: 14px;
            align-items: start;
        }

        .topprinter-panel {
            min-width: 0;
            padding: 18px;
        }

        .topprinter-panel-title {
            margin: 0 0 15px;
            color: #fff;
            font-size: 19px;
        }

        .topprinter-chart-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 10px 0 20px;
        }

        .topprinter-chart {
            position: relative;
            width: min(380px, 85vw);
            aspect-ratio: 1;
            border-radius: 50%;
            box-shadow:
                0 0 0 1px var(--topprinter-border),
                0 15px 35px rgba(0, 0, 0, 0.3);
        }

        .topprinter-chart-center {
            position: absolute;
            inset: 24%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: #1e1e1e;
            border: 1px solid var(--topprinter-border);
            border-radius: 50%;
            text-align: center;
        }

        .topprinter-chart-center strong {
            color: #fff;
            font-size: clamp(22px, 4vw, 36px);
            line-height: 1;
        }

        .topprinter-chart-center span {
            margin-top: 6px;
            color: var(--topprinter-muted);
            font-size: 13px;
        }

        .topprinter-legend {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .topprinter-legend-row {
            display: grid;
            grid-template-columns: 14px minmax(0, 1fr) auto;
            gap: 9px;
            align-items: center;
            padding: 7px 8px;
            border-radius: 7px;
        }

        .topprinter-legend-row:hover {
            background: rgba(255, 255, 255, 0.035);
        }

        .topprinter-legend-color {
            width: 12px;
            height: 12px;
            background: var(--legend-color);
            border-radius: 3px;
        }

        .topprinter-legend-user {
            min-width: 0;
            overflow: hidden;
            color: #fff;
            font-size: 13px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .topprinter-legend-value {
            color: var(--topprinter-muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .topprinter-user-meta {
            color: var(--topprinter-muted);
            font-weight: 400;
        }

        .topprinter-tower {
            font-weight: 800;
        }

        .topprinter-tower-weh {
            color: #11a50d;
        }

        .topprinter-tower-tvk {
            color: #ffa500;
        }

        .topprinter-tower-other {
            color: #bbb;
        }

        .topprinter-table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--topprinter-border);
            border-radius: 9px;
        }

        .topprinter-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .topprinter-table th {
            padding: 11px 10px;
            color: #fff;
            background: #242424;
            border-bottom: 1px solid var(--topprinter-border);
            font-size: 13px;
            text-align: left;
            white-space: nowrap;
        }

        .topprinter-table td {
            padding: 11px 10px;
            color: var(--topprinter-text);
            border-bottom: 1px solid #363636;
            vertical-align: middle;
        }

        .topprinter-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .topprinter-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }

        .topprinter-rank {
            width: 54px;
            font-weight: 900;
            text-align: center;
        }

        .topprinter-rank-badge {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-width: 32px;
            height: 32px;
            padding: 0 5px;
            background: #303030;
            border: 1px solid #555;
            border-radius: 999px;
        }

        .topprinter-rank-1 {
            color: #ffd700;
            border-color: #ffd700;
        }

        .topprinter-rank-2 {
            color: #c0c0c0;
            border-color: #c0c0c0;
        }

        .topprinter-rank-3 {
            color: #cd7f32;
            border-color: #cd7f32;
        }

        .topprinter-user {
            font-weight: 700;
            white-space: nowrap;
        }

        .topprinter-number {
            text-align: right;
            white-space: nowrap;
        }

        .topprinter-pages {
            color: #fff;
            font-weight: 900;
        }

        .topprinter-progress {
            min-width: 220px;
        }

        .topprinter-progress-layout {
            display: grid;
            grid-template-columns: 56px minmax(140px, 1fr);
            gap: 9px;
            align-items: center;
        }

        .topprinter-progress-percentage {
            color: var(--topprinter-muted);
            font-size: 12px;
            text-align: right;
            white-space: nowrap;
        }

        .topprinter-progress-track {
            width: 100%;
            height: 9px;
            overflow: hidden;
            background: #333;
            border-radius: 999px;
        }

        .topprinter-progress-bar {
            height: 100%;
            min-width: 2px;
            background: var(--topprinter-primary);
            border-radius: 999px;
        }

        .topprinter-rest-row {
            background: rgba(255, 255, 255, 0.025);
        }

        .topprinter-rest-share {
            color: var(--topprinter-muted);
            font-size: 12px;
            text-align: center;
        }

        .topprinter-empty {
            padding: 35px 20px;
            color: var(--topprinter-muted);
            background: var(--topprinter-panel);
            border: 1px solid var(--topprinter-border);
            border-radius: 12px;
            text-align: center;
        }

        @media (max-width: 1050px) {
            .topprinter-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .topprinter-page {
                width: calc(100% - 16px);
            }

            .topprinter-summary {
                grid-template-columns: 1fr;
            }

            .topprinter-filter {
                flex-direction: column;
            }

            .topprinter-period-control {
                width: 100%;
            }

            .topprinter-period-button {
                flex: 0 0 auto;
            }

            .topprinter-period-select {
                min-width: 0;
                flex: 1;
            }

            .topprinter-panel {
                padding: 12px;
            }
        }
    </style>
</head>
<body>

<?php load_menu(); ?>

<div class="topprinter-page">
    <div class="topprinter-header">
        <h1 class="topprinter-title">Top Printer</h1>

        <p class="topprinter-subtitle">
            Auswertung nach gedruckten Seiten ·
            <?= topprinter_h($periodTitle) ?> ·
            <?= topprinter_h($rangeLabel) ?>
        </p>
    </div>

    <form
        method="get"
        id="topprinter-filter-form"
        class="topprinter-filter"
    >
        <input
            type="hidden"
            name="period"
            id="topprinter-period"
            value="<?= topprinter_h($period) ?>"
        >

        <div
            class="topprinter-period-control <?= $period === 'year' ? 'active' : '' ?>"
        >
            <button
                type="button"
                class="topprinter-period-button"
                data-period="year"
            >
                Jahr
            </button>

            <select
                name="year"
                id="topprinter-year"
                class="topprinter-period-select"
            >
                <?php foreach ($yearOptions as $year): ?>
                    <option
                        value="<?= $year ?>"
                        <?= $selectedYear === $year ? 'selected' : '' ?>
                    >
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div
            class="topprinter-period-control <?= $period === 'semester' ? 'active' : '' ?>"
        >
            <button
                type="button"
                class="topprinter-period-button"
                data-period="semester"
            >
                Semester
            </button>

            <select
                name="semester_start"
                id="topprinter-semester"
                class="topprinter-period-select"
            >
                <?php foreach ($semesterOptions as $start => $label): ?>
                    <option
                        value="<?= (int)$start ?>"
                        <?= $selectedSemester === (int)$start ? 'selected' : '' ?>
                    >
                        <?= topprinter_h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="topprinter-summary">
        <div class="topprinter-summary-card">
            <span class="topprinter-summary-label">Seiten</span>
            <span class="topprinter-summary-value">
                <?= number_format($totalPages, 0, ',', '.') ?>
            </span>
        </div>

        <div class="topprinter-summary-card">
            <span class="topprinter-summary-label">Druckjobs</span>
            <span class="topprinter-summary-value">
                <?= number_format($totalPrintjobs, 0, ',', '.') ?>
            </span>
        </div>

        <div class="topprinter-summary-card">
            <span class="topprinter-summary-label">Benutzer</span>
            <span class="topprinter-summary-value">
                <?= number_format($totalUsers, 0, ',', '.') ?>
            </span>
        </div>
    </div>

    <?php if ($totalPages > 0): ?>
        <div class="topprinter-content">
            <section class="topprinter-panel">
                <h2 class="topprinter-panel-title">Seitenverteilung</h2>

                <div class="topprinter-chart-wrap">
                    <div
                        class="topprinter-chart"
                        style="background: <?= topprinter_h($chartGradient) ?>;"
                        role="img"
                        aria-label="Kreisdiagramm der gedruckten Seiten"
                    >
                        <div class="topprinter-chart-center">
                            <strong>
                                <?= number_format($totalPages, 0, ',', '.') ?>
                            </strong>
                            <span>Seiten gesamt</span>
                        </div>
                    </div>
                </div>

                <div class="topprinter-legend">
                    <?php foreach ($chartEntries as $entry): ?>
                        <?php
                        $entryPercentage = $totalPages > 0
                            ? ((int)$entry['pages'] / $totalPages) * 100
                            : 0;
                        ?>

                        <div class="topprinter-legend-row">
                            <span
                                class="topprinter-legend-color"
                                style="--legend-color: <?= topprinter_h($entry['color']) ?>;"
                            ></span>

                            <span class="topprinter-legend-user">
                                <?php if ($entry['type'] === 'user'): ?>
                                    <?php $legendRow = $entry['row']; ?>

                                    <?= topprinter_h(topprinter_user_name($legendRow)) ?>

                                    <span class="topprinter-user-meta">
                                        [<?= topprinter_h(topprinter_room(
                                            $legendRow['room'] ?? 0,
                                            $legendRow['oldroom'] ?? 0
                                        )) ?>

                                        <span
                                            class="topprinter-tower <?= topprinter_h(
                                                topprinter_tower_class(
                                                    $legendRow['turm'] ?? ''
                                                )
                                            ) ?>"
                                        >
                                            <?= topprinter_h(topprinter_tower(
                                                $legendRow['turm'] ?? ''
                                            )) ?>
                                        </span>]
                                    </span>
                                <?php else: ?>
                                    Rest
                                    <span class="topprinter-user-meta">
                                        (<?= count($restRows) ?> weitere Benutzer)
                                    </span>
                                <?php endif; ?>
                            </span>

                            <span class="topprinter-legend-value">
                                <?= number_format((int)$entry['pages'], 0, ',', '.') ?>
                                ·
                                <?= number_format($entryPercentage, 1, ',', '.') ?> %
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="topprinter-panel">
                <h2 class="topprinter-panel-title">
                    Top <?= $topLimit ?>
                </h2>

                <div class="topprinter-table-wrap">
                    <table class="topprinter-table">
                        <thead>
                            <tr>
                                <th class="topprinter-rank">Rang</th>
                                <th>Benutzer</th>
                                <th class="topprinter-number">Druckjobs</th>
                                <th class="topprinter-number">Seiten</th>
                                <th>Anteil</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($topRows as $index => $row): ?>
                            <?php
                            $rank = $index + 1;

                            $percentage = $totalPages > 0
                                ? ((int)$row['total_pages'] / $totalPages) * 100
                                : 0;

                            $barPercentage = $topPagesTotal > 0
                                ? ((int)$row['total_pages'] / $topPagesTotal) * 100
                                : 0;

                            $rankClass = $rank <= 3
                                ? ' topprinter-rank-' . $rank
                                : '';
                            ?>

                            <tr>
                                <td class="topprinter-rank">
                                    <span
                                        class="topprinter-rank-badge<?= topprinter_h($rankClass) ?>"
                                    >
                                        <?= $rank ?>
                                    </span>
                                </td>

                                <td class="topprinter-user">
                                    <?= topprinter_h(topprinter_user_name($row)) ?>

                                    <span class="topprinter-user-meta">
                                        [<?= topprinter_h(topprinter_room(
                                            $row['room'] ?? 0,
                                            $row['oldroom'] ?? 0
                                        )) ?>

                                        <span
                                            class="topprinter-tower <?= topprinter_h(
                                                topprinter_tower_class(
                                                    $row['turm'] ?? ''
                                                )
                                            ) ?>"
                                        >
                                            <?= topprinter_h(topprinter_tower(
                                                $row['turm'] ?? ''
                                            )) ?>
                                        </span>]
                                    </span>
                                </td>

                                <td class="topprinter-number">
                                    <?= number_format(
                                        (int)$row['printjob_count'],
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </td>

                                <td class="topprinter-number topprinter-pages">
                                    <?= number_format(
                                        (int)$row['total_pages'],
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </td>

                                <td class="topprinter-progress">
                                    <div class="topprinter-progress-layout">
                                        <span class="topprinter-progress-percentage">
                                            <?= number_format(
                                                $percentage,
                                                1,
                                                ',',
                                                '.'
                                            ) ?> %
                                        </span>

                                        <div
                                            class="topprinter-progress-track"
                                            title="<?= number_format(
                                                $percentage,
                                                1,
                                                ',',
                                                '.'
                                            ) ?> % aller Seiten"
                                        >
                                            <div
                                                class="topprinter-progress-bar"
                                                style="width: <?= number_format(
                                                    $barPercentage,
                                                    4,
                                                    '.',
                                                    ''
                                                ) ?>%;"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($restPages > 0): ?>
                            <?php
                            $restPercentage = ($restPages / $totalPages) * 100;
                            ?>

                            <tr class="topprinter-rest-row">
                                <td class="topprinter-rank">…</td>

                                <td class="topprinter-user">
                                    Rest
                                    <span class="topprinter-user-meta">
                                        (<?= count($restRows) ?> weitere Benutzer)
                                    </span>
                                </td>

                                <td class="topprinter-number">
                                    <?= number_format($restPrintjobs, 0, ',', '.') ?>
                                </td>

                                <td class="topprinter-number topprinter-pages">
                                    <?= number_format($restPages, 0, ',', '.') ?>
                                </td>

                                <td class="topprinter-progress">
                                    <div class="topprinter-progress-layout">
                                        <span class="topprinter-progress-percentage">
                                            <?= number_format(
                                                $restPercentage,
                                                1,
                                                ',',
                                                '.'
                                            ) ?> %
                                        </span>

                                        <span class="topprinter-rest-share">—</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php else: ?>
        <div class="topprinter-empty">
            Für den gewählten Zeitraum wurden keine gedruckten Seiten gefunden.
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const form = document.getElementById('topprinter-filter-form');
    const periodInput = document.getElementById('topprinter-period');
    const yearSelect = document.getElementById('topprinter-year');
    const semesterSelect = document.getElementById('topprinter-semester');
    const periodButtons = Array.from(
        document.querySelectorAll(
            '.topprinter-period-button[data-period]'
        )
    );

    function submitPeriod(period) {
        periodInput.value = period;
        form.submit();
    }

    periodButtons.forEach(button => {
        button.addEventListener('click', () => {
            submitPeriod(button.dataset.period);
        });
    });

    yearSelect.addEventListener('change', () => {
        submitPeriod('year');
    });

    semesterSelect.addEventListener('change', () => {
        submitPeriod('semester');
    });
})();
</script>

</body>
</html>