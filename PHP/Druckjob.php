<?php
ob_start();
session_start();

require('conn.php');
require('template.php');

mysqli_set_charset($conn, 'utf8mb4');

/*
|--------------------------------------------------------------------------
| Zugriff
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Hilfsfunktionen
|--------------------------------------------------------------------------
*/

function druckjobs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function druckjobs_execute_stmt(
    mysqli_stmt $stmt,
    string $types = '',
    array $params = []
): void {
    if ($types !== '') {
        $bindParams = [$types];

        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bindParams
        );
    }

    $stmt->execute();
}

function druckjobs_turm($turm): string
{
    $turm = trim((string)$turm);

    if ($turm === '') {
        return '-';
    }

    if (strtolower($turm) === 'tvk') {
        return 'TvK';
    }

    return strtoupper($turm);
}

function druckjobs_room($room, $oldroom = null): string
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

function druckjobs_short_name(
    $firstname,
    $lastname,
    int $uid = 0
): string {
    $firstnameParts = preg_split(
        '/\s+/u',
        trim((string)$firstname),
        2,
        PREG_SPLIT_NO_EMPTY
    );

    $lastnameParts = preg_split(
        '/\s+/u',
        trim((string)$lastname),
        2,
        PREG_SPLIT_NO_EMPTY
    );

    $shortFirstname = $firstnameParts[0] ?? '';
    $shortLastname = $lastnameParts[0] ?? '';

    $name = trim(
        $shortFirstname . ' ' . $shortLastname
    );

    if ($name === '') {
        return $uid > 0
            ? 'UID ' . $uid
            : '-';
    }

    return $name;
}

function druckjobs_user_label(array $row): string
{
    $uid = (int)($row['uid'] ?? 0);

    $name = druckjobs_short_name(
        $row['firstname'] ?? '',
        $row['lastname'] ?? '',
        $uid
    );

    $turm = druckjobs_turm($row['turm'] ?? '');
    $room = druckjobs_room(
        $row['room'] ?? 0,
        $row['oldroom'] ?? 0
    );

    return $name . ' [' . $turm . ' ' . $room . ']';
}

function druckjobs_semester_end(int $semesterStart): int
{
    $month = (int)date('m', $semesterStart);
    $year = (int)date('Y', $semesterStart);

    if ($month === 4) {
        return mktime(0, 0, 0, 10, 1, $year);
    }

    return mktime(0, 0, 0, 4, 1, $year + 1);
}

function druckjobs_url(array $changes = []): string
{
    $params = $_GET;

    unset(
        $params['ajax'],
        $params['term']
    );

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $url = $_SERVER['PHP_SELF'];

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function druckjobs_pagination_items(
    int $currentPage,
    int $totalPages
): array {
    if ($totalPages <= 1) {
        return [1];
    }

    $pages = [];

    $ranges = [
        [1, min(3, $totalPages)],
        [
            max(1, $currentPage - 5),
            min($totalPages, $currentPage + 5),
        ],
        [
            max(1, $totalPages - 2),
            $totalPages,
        ],
    ];

    foreach ($ranges as [$start, $end]) {
        for ($pageNumber = $start; $pageNumber <= $end; $pageNumber++) {
            $pages[$pageNumber] = true;
        }
    }

    ksort($pages);

    $items = [];
    $previousPage = null;

    foreach (array_keys($pages) as $pageNumber) {
        if (
            $previousPage !== null
            && $pageNumber > $previousPage + 1
        ) {
            $items[] = 'ellipsis';
        }

        $items[] = $pageNumber;
        $previousPage = $pageNumber;
    }

    return $items;
}


/*
|--------------------------------------------------------------------------
| Rückerstattung
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['druckjobs_refund_csrf'])) {
    $_SESSION['druckjobs_refund_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$refundCsrfToken = (string)$_SESSION[
    'druckjobs_refund_csrf'
];

$refundFlash = $_SESSION['druckjobs_refund_flash']
    ?? null;

unset($_SESSION['druckjobs_refund_flash']);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['refund_printjob'])
) {
    $redirectUrl = druckjobs_url();

    $printjobId = max(
        0,
        (int)($_POST['printjob_id'] ?? 0)
    );

    $postedCsrfToken = (string)(
        $_POST['csrf_token'] ?? ''
    );

    if (
        $printjobId <= 0
        || $postedCsrfToken === ''
        || !hash_equals(
            $refundCsrfToken,
            $postedCsrfToken
        )
    ) {
        $_SESSION['druckjobs_refund_flash'] = [
            'type' => 'error',
            'text' => 'Rückerstattung konnte nicht ausgeführt werden.',
        ];

        header('Location: ' . $redirectUrl);
        exit;
    }

    $agent = (int)($_SESSION['uid'] ?? 0);

    if ($agent <= 0) {
        $_SESSION['druckjobs_refund_flash'] = [
            'type' => 'error',
            'text' => 'Keine gültige Agent-UID in der Session gefunden.',
        ];

        header('Location: ' . $redirectUrl);
        exit;
    }

    try {
        $conn->begin_transaction();

        $printjobStmt = $conn->prepare("
            SELECT status
            FROM printjobs
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$printjobStmt) {
            throw new RuntimeException(
                'Printjob-Abfrage konnte nicht vorbereitet werden.'
            );
        }

        $printjobStmt->bind_param(
            'i',
            $printjobId
        );

        if (!$printjobStmt->execute()) {
            throw new RuntimeException(
                'Printjob konnte nicht geladen werden.'
            );
        }

        $printjobResult = $printjobStmt->get_result();
        $printjobRow = $printjobResult->fetch_assoc();
        $printjobStmt->close();

        if (!$printjobRow) {
            throw new RuntimeException(
                'Druckjob wurde nicht gefunden.'
            );
        }

        if ((int)$printjobRow['status'] === 4) {
            $conn->rollback();

            $_SESSION['druckjobs_refund_flash'] = [
                'type' => 'info',
                'text' => 'Druckjob #' . $printjobId
                    . ' wurde bereits erstattet.',
            ];

            header('Location: ' . $redirectUrl);
            exit;
        }

        $transferStmt = $conn->prepare("
            SELECT
                id,
                betrag
            FROM transfers
            WHERE print_id = ?
            ORDER BY id ASC
            FOR UPDATE
        ");

        if (!$transferStmt) {
            throw new RuntimeException(
                'Transfer-Abfrage konnte nicht vorbereitet werden.'
            );
        }

        $transferStmt->bind_param(
            'i',
            $printjobId
        );

        if (!$transferStmt->execute()) {
            throw new RuntimeException(
                'Transfers konnten nicht geladen werden.'
            );
        }

        $transferResult = $transferStmt->get_result();
        $refundTransfers = [];

        while (
            $transferRow = $transferResult->fetch_assoc()
        ) {
            $refundTransfers[] = $transferRow;
        }

        $transferStmt->close();

        if (empty($refundTransfers)) {
            throw new RuntimeException(
                'Zu diesem Druckjob wurde kein Transfer gefunden.'
            );
        }

        $updateTransferStmt = $conn->prepare("
            UPDATE transfers
            SET
                betrag = 0,
                changelog = CONCAT(
                    IFNULL(changelog, ''),
                    IF(
                        IFNULL(changelog, '') = '',
                        '',
                        '\n\n'
                    ),
                    ?
                )
            WHERE id = ?
            LIMIT 1
        ");

        if (!$updateTransferStmt) {
            throw new RuntimeException(
                'Transfer-Update konnte nicht vorbereitet werden.'
            );
        }

        $refundTotal = 0.0;
        $refundTime = time();

        foreach ($refundTransfers as $refundTransfer) {
            $transferId = (int)$refundTransfer['id'];
            $oldAmount = (float)$refundTransfer['betrag'];

            $refundTotal += $oldAmount;

            $formattedOldAmount = number_format(
                $oldAmount,
                2,
                ',',
                '.'
            );

            $refundLog =
                '['
                . date('d.m.Y H:i', $refundTime)
                . '] Agent '
                . $agent
                . "\n"
                . "Rückerstattung über Druckjobs.php\n"
                . 'Printjob #'
                . $printjobId
                . "\n"
                . 'Betrag: von '
                . $formattedOldAmount
                . " € auf 0,00 €\n";

            $updateTransferStmt->bind_param(
                'si',
                $refundLog,
                $transferId
            );

            if (!$updateTransferStmt->execute()) {
                throw new RuntimeException(
                    'Transfer #' . $transferId
                    . ' konnte nicht aktualisiert werden.'
                );
            }
        }

        $updateTransferStmt->close();

        $updatePrintjobStmt = $conn->prepare("
            UPDATE printjobs
            SET status = 4
            WHERE id = ?
            LIMIT 1
        ");

        if (!$updatePrintjobStmt) {
            throw new RuntimeException(
                'Printjob-Update konnte nicht vorbereitet werden.'
            );
        }

        $updatePrintjobStmt->bind_param(
            'i',
            $printjobId
        );

        if (!$updatePrintjobStmt->execute()) {
            throw new RuntimeException(
                'Printjob-Status konnte nicht aktualisiert werden.'
            );
        }

        $updatePrintjobStmt->close();

        $conn->commit();

        $_SESSION['druckjobs_refund_flash'] = [
            'type' => 'success',
            'text' => 'Druckjob #'
                . $printjobId
                . ' wurde erstattet. '
                . count($refundTransfers)
                . ' Transfer'
                . (
                    count($refundTransfers) === 1
                        ? ''
                        : 's'
                )
                . ' auf 0,00 € gesetzt; vorheriger Gesamtbetrag: '
                . number_format(
                    $refundTotal,
                    2,
                    ',',
                    '.'
                )
                . ' €.',
        ];
    } catch (Throwable $exception) {
        $conn->rollback();

        $_SESSION['druckjobs_refund_flash'] = [
            'type' => 'error',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: ' . $redirectUrl);
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX: Benutzersuche
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax'])
    && $_GET['ajax'] === 'user_search'
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    $term = trim((string)($_GET['term'] ?? ''));
    $term = mb_substr($term, 0, 100, 'UTF-8');

    if ($term !== '') {
        $like = '%' . $term . '%';

        $sql = "
            SELECT
                uid,
                username,
                name,
                firstname,
                lastname,
                room,
                oldroom,
                turm
            FROM users
            WHERE
                username LIKE ?
                OR name LIKE ?
                OR firstname LIKE ?
                OR lastname LIKE ?
                OR CAST(uid AS CHAR) LIKE ?
                OR CAST(room AS CHAR) LIKE ?
                OR CAST(oldroom AS CHAR) LIKE ?
            ORDER BY
                pid,
                CASE
                    WHEN room IS NOT NULL AND room > 0
                        THEN room
                    WHEN oldroom IS NOT NULL AND oldroom > 0
                        THEN oldroom
                    ELSE 999999
                END ASC,
                FIELD(turm, 'weh', 'tvk') ASC,
                lastname ASC,
                firstname ASC,
                username ASC
            LIMIT 15
        ";

        $stmt = $conn->prepare($sql);

        druckjobs_execute_stmt(
            $stmt,
            'sssssss',
            [
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
            ]
        );
    } else {
        $sql = "
            SELECT
                uid,
                username,
                name,
                firstname,
                lastname,
                room,
                oldroom,
                turm
            FROM users
            ORDER BY
                pid,
                CASE
                    WHEN room IS NOT NULL AND room > 0
                        THEN room
                    WHEN oldroom IS NOT NULL AND oldroom > 0
                        THEN oldroom
                    ELSE 999999
                END ASC,
                FIELD(turm, 'weh', 'tvk') ASC,
                lastname ASC,
                firstname ASC,
                username ASC
            LIMIT 15
        ";

        $stmt = $conn->prepare($sql);
        druckjobs_execute_stmt($stmt);
    }

    $result = $stmt->get_result();
    $users = [];

    while ($user = $result->fetch_assoc()) {
        $users[] = [
            'uid' => (int)$user['uid'],
            'label' => druckjobs_user_label($user),
        ];
    }

    $stmt->close();

    echo json_encode(
        ['users' => $users],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Semester
|--------------------------------------------------------------------------
*/

$currentSemesterStart = unixtime2startofsemester(time());

$semesterOptions = [];
$semesterTs = $currentSemesterStart;
$oldestSemester = mktime(0, 0, 0, 04, 1, 2025);

while ($semesterTs >= $oldestSemester) {
    $semesterOptions[
        unixtime2semester($semesterTs)
    ] = $semesterTs;

    $month = (int)date('m', $semesterTs);
    $year = (int)date('Y', $semesterTs);

    if ($month === 4) {
        $semesterTs = mktime(
            0,
            0,
            0,
            10,
            1,
            $year - 1
        );
    } else {
        $semesterTs = mktime(
            0,
            0,
            0,
            4,
            1,
            $year
        );
    }
}

$semesterStart = isset($_GET['semester_start'])
    ? (int)$_GET['semester_start']
    : $currentSemesterStart;

if (
    !in_array(
        $semesterStart,
        array_values($semesterOptions),
        true
    )
) {
    $semesterStart = $currentSemesterStart;
}

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/

$allowedPeriods = [
    'today',
    '7',
    '30',
    'semester',
    'all',
];

$period = (string)($_GET['period'] ?? '7');

if (!in_array($period, $allowedPeriods, true)) {
    $period = '7';
}

$todayStart = strtotime('today');
$tomorrowStart = strtotime('tomorrow');

$rangeStart = null;
$rangeEnd = null;

switch ($period) {
    case 'today':
        $rangeStart = $todayStart;
        $rangeEnd = $tomorrowStart;
        break;

    case '7':
        $rangeStart = strtotime('-6 days', $todayStart);
        $rangeEnd = $tomorrowStart;
        break;

    case '30':
        $rangeStart = strtotime('-29 days', $todayStart);
        $rangeEnd = $tomorrowStart;
        break;

    case 'semester':
        $rangeStart = $semesterStart;
        $rangeEnd = druckjobs_semester_end($semesterStart);
        break;

    case 'all':
        $rangeStart = null;
        $rangeEnd = null;
        break;
}

$search = trim((string)($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 150, 'UTF-8');

$selectedUid = isset($_GET['uid'])
    ? max(0, (int)$_GET['uid'])
    : 0;

$selectedPrinter = trim(
    (string)($_GET['printer'] ?? '')
);

$selectedPrinter = mb_substr(
    $selectedPrinter,
    0,
    100,
    'UTF-8'
);

$selectedStatus = '';

if (
    isset($_GET['status'])
    && in_array(
        (string)$_GET['status'],
        ['0', '1', '2', '3', '4'],
        true
    )
) {
    $selectedStatus = (string)$_GET['status'];
}

$allowedPageSizes = [
    50,
    100,
    250,
];

$perPage = isset($_GET['per_page'])
    ? (int)$_GET['per_page']
    : 100;

if (!in_array($perPage, $allowedPageSizes, true)) {
    $perPage = 100;
}

$page = isset($_GET['page'])
    ? max(1, (int)$_GET['page'])
    : 1;

$sortColumns = [
    'date' => 'p.tstamp',
    'user' => "CONCAT_WS(' ', u.firstname, u.lastname)",
    'room' => "
        CASE
            WHEN u.room IS NOT NULL AND u.room > 0
                THEN u.room
            WHEN u.oldroom IS NOT NULL AND u.oldroom > 0
                THEN u.oldroom
            ELSE 999999
        END
    ",
    'title' => 'p.title',
    'printer' => 'p.drucker',
    'pages' => '
        COALESCE(
            p.true_pages,
            MAX(t.print_pages),
            p.planned_pages
        )
    ',
    'mode' => 'p.din',
    'status' => 'p.status',
    'amount' => 'SUM(COALESCE(t.betrag, 0))',
];

$sort = (string)($_GET['sort'] ?? 'date');

if (!isset($sortColumns[$sort])) {
    $sort = 'date';
}

$direction = strtolower(
    (string)($_GET['dir'] ?? 'desc')
);

if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'desc';
}

$sortSql = $sortColumns[$sort];
$directionSql = strtoupper($direction);

/*
|--------------------------------------------------------------------------
| Ausgewählten Benutzer laden
|--------------------------------------------------------------------------
*/

$selectedUserLabel = '';

if ($selectedUid > 0) {
    $stmt = $conn->prepare("
        SELECT
            uid,
            firstname,
            lastname,
            room,
            oldroom,
            turm
        FROM users
        WHERE uid = ?
        LIMIT 1
    ");

    druckjobs_execute_stmt(
        $stmt,
        'i',
        [$selectedUid]
    );

    $selectedUserResult = $stmt->get_result();

    if ($selectedUser = $selectedUserResult->fetch_assoc()) {
        $selectedUserLabel = druckjobs_user_label(
            $selectedUser
        );
    } else {
        $selectedUid = 0;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Druckerliste
|--------------------------------------------------------------------------
*/

$printerOptions = [];

$printerResult = $conn->query("
    SELECT DISTINCT drucker
    FROM printjobs
    WHERE
        drucker IS NOT NULL
        AND TRIM(drucker) <> ''
    ORDER BY drucker ASC
");

if ($printerResult) {
    while ($printerRow = $printerResult->fetch_assoc()) {
        $printerOptions[] = (string)$printerRow['drucker'];
    }
}

/*
|--------------------------------------------------------------------------
| SQL-Filter
|--------------------------------------------------------------------------
*/

$whereParts = [];
$whereParams = [];
$whereTypes = '';

$whereParts[] = 't.print_id IS NOT NULL';
$whereParts[] = 't.print_id <> 0';

if ($rangeStart !== null && $rangeEnd !== null) {
    $whereParts[] = 'p.tstamp >= ?';
    $whereParts[] = 'p.tstamp < ?';

    $whereTypes .= 'ii';
    $whereParams[] = $rangeStart;
    $whereParams[] = $rangeEnd;
}

if ($selectedUid > 0) {
    $whereParts[] = 'p.uid = ?';

    $whereTypes .= 'i';
    $whereParams[] = $selectedUid;
}

if ($selectedPrinter !== '') {
    $whereParts[] = 'p.drucker = ?';

    $whereTypes .= 's';
    $whereParams[] = $selectedPrinter;
}

if ($selectedStatus !== '') {
    $whereParts[] = 'p.status = ?';

    $whereTypes .= 'i';
    $whereParams[] = (int)$selectedStatus;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $whereParts[] = "
        (
            CAST(p.id AS CHAR) LIKE ?
            OR CAST(t.id AS CHAR) LIKE ?
            OR CAST(p.uid AS CHAR) LIKE ?
            OR CAST(u.room AS CHAR) LIKE ?
            OR CAST(u.oldroom AS CHAR) LIKE ?
            OR u.username LIKE ?
            OR u.name LIKE ?
            OR u.firstname LIKE ?
            OR u.lastname LIKE ?
            OR p.title LIKE ?
            OR p.drucker LIKE ?
            OR t.beschreibung LIKE ?
        )
    ";

    $whereTypes .= str_repeat('s', 12);

    for ($i = 0; $i < 12; $i++) {
        $whereParams[] = $like;
    }
}

$whereSql = implode(
    ' AND ',
    $whereParts
);

/*
|--------------------------------------------------------------------------
| Zusammenfassung über alle gefilterten Druckjobs
|--------------------------------------------------------------------------
*/

$summarySql = "
    SELECT
        COUNT(*) AS total_jobs,
        COALESCE(SUM(summary_data.shown_pages), 0) AS total_pages,
        COALESCE(SUM(summary_data.amount), 0) AS total_amount

    FROM (
        SELECT
            p.id,

            MAX(
                COALESCE(
                    p.true_pages,
                    t.print_pages,
                    p.planned_pages,
                    0
                )
            ) AS shown_pages,

            SUM(
                COALESCE(t.betrag, 0)
            ) AS amount

        FROM printjobs p

        INNER JOIN transfers t
            ON t.print_id = p.id

        LEFT JOIN users u
            ON u.uid = p.uid

        WHERE $whereSql

        GROUP BY p.id
    ) summary_data
";

$summaryStmt = $conn->prepare($summarySql);

druckjobs_execute_stmt(
    $summaryStmt,
    $whereTypes,
    $whereParams
);

$summaryResult = $summaryStmt->get_result();
$summaryRow = $summaryResult->fetch_assoc();

$totalCount = (int)($summaryRow['total_jobs'] ?? 0);
$totalPrintedPages = (int)($summaryRow['total_pages'] ?? 0);
$totalAmount = (float)($summaryRow['total_amount'] ?? 0);

$summaryStmt->close();

/*
|--------------------------------------------------------------------------
| Seitennummern berechnen
|--------------------------------------------------------------------------
*/

$totalPageCount = max(
    1,
    (int)ceil($totalCount / $perPage)
);

$page = min($page, $totalPageCount);
$offset = ($page - 1) * $perPage;

$hasPreviousPage = $page > 1;
$hasNextPage = $page < $totalPageCount;

$paginationItems = druckjobs_pagination_items(
    $page,
    $totalPageCount
);

/*
|--------------------------------------------------------------------------
| Aktuelle Seite laden
|
| Durch GROUP BY p.id wird jeder Druckjob nur einmal angezeigt.
| Mehrere zugehörige Transfers werden beim Betrag zusammengefasst.
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id AS printjob_id,
        p.uid,
        p.tstamp AS print_tstamp,
        p.status,
        p.drucker,
        p.title,
        p.planned_pages,
        p.true_pages,
        p.duplex,
        p.grey,
        p.din,
        p.cups_id,

        MIN(t.id) AS transfer_id,
        MAX(t.tstamp) AS transfer_tstamp,
        GROUP_CONCAT(
            DISTINCT t.beschreibung
            ORDER BY t.id
            SEPARATOR ' | '
        ) AS transfer_beschreibung,
        SUM(COALESCE(t.betrag, 0)) AS betrag,
        MAX(t.print_pages) AS print_pages,

        u.username,
        u.name,
        u.firstname,
        u.lastname,
        u.room,
        u.oldroom,
        u.turm

    FROM printjobs p

    INNER JOIN transfers t
        ON t.print_id = p.id

    LEFT JOIN users u
        ON u.uid = p.uid

    WHERE $whereSql

    GROUP BY
        p.id,
        p.uid,
        p.tstamp,
        p.status,
        p.drucker,
        p.title,
        p.planned_pages,
        p.true_pages,
        p.duplex,
        p.grey,
        p.din,
        p.cups_id,
        u.username,
        u.name,
        u.firstname,
        u.lastname,
        u.room,
        u.oldroom,
        u.turm

    ORDER BY
        $sortSql $directionSql,
        p.id DESC

    LIMIT ?
    OFFSET ?
";

$dataParams = $whereParams;
$dataParams[] = $perPage;
$dataParams[] = $offset;

$dataTypes = $whereTypes . 'ii';

$stmt = $conn->prepare($sql);

druckjobs_execute_stmt(
    $stmt,
    $dataTypes,
    $dataParams
);

$result = $stmt->get_result();
$rows = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$stmt->close();

$statusLabels = [
    0 => 'Ausstehend',
    1 => 'Gedruckt',
    2 => 'Abgebrochen',
    3 => 'Gelöscht',
    4 => 'Erstattet',
];

/*
|--------------------------------------------------------------------------
| Seitenzahl für die angezeigten Tabellenzeilen bestimmen
|--------------------------------------------------------------------------
*/

foreach ($rows as &$row) {
    $truePages = $row['true_pages'] !== null
        ? (int)$row['true_pages']
        : null;

    $transferPages = $row['print_pages'] !== null
        ? (int)$row['print_pages']
        : null;

    $plannedPages = (int)$row['planned_pages'];

    if ($truePages !== null) {
        $row['shown_pages'] = $truePages;
    } elseif ($transferPages !== null) {
        $row['shown_pages'] = $transferPages;
    } else {
        $row['shown_pages'] = $plannedPages;
    }
}

unset($row);

/*
|--------------------------------------------------------------------------
| Sortierlinks
|--------------------------------------------------------------------------
*/

function druckjobs_sort_url(string $column): string
{
    $currentSort = (string)($_GET['sort'] ?? 'date');
    $currentDirection = strtolower(
        (string)($_GET['dir'] ?? 'desc')
    );

    if (
        $currentSort === $column
        && $currentDirection === 'asc'
    ) {
        $newDirection = 'desc';
    } else {
        $newDirection = 'asc';
    }

    return druckjobs_url([
        'sort' => $column,
        'dir' => $newDirection,
        'page' => 1,
    ]);
}

function druckjobs_sort_marker(string $column): string
{
    $currentSort = (string)($_GET['sort'] ?? 'date');
    $currentDirection = strtolower(
        (string)($_GET['dir'] ?? 'desc')
    );

    if ($currentSort !== $column) {
        return '';
    }

    return $currentDirection === 'asc'
        ? ' ▲'
        : ' ▼';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Druckjobs</title>

    <link
        rel="stylesheet"
        href="WEH.css"
        media="screen"
    >

    <link
        rel="stylesheet"
        href="TRANSFERS.css"
        media="screen"
    >

    <style>
        :root {
            --printjobs-primary: #11a50d;
            --printjobs-panel: #222;
            --printjobs-field: #2b2b2b;
            --printjobs-border: #444;
            --printjobs-text: #f2f2f2;
            --printjobs-muted: #aaa;
        }

        .printjobs-page {
            width: min(1800px, calc(100% - 30px));
            margin: 0 auto 40px;
        }

        .printjobs-toolbar {
            display: grid;
            grid-template-columns:
                minmax(230px, 1.4fr)
                minmax(230px, 1.2fr)
                minmax(180px, 0.8fr)
                minmax(160px, 0.7fr)
                auto;
            gap: 10px;
            align-items: end;
            padding: 14px;
            margin: 18px 0 12px;
            box-sizing: border-box;
            background: var(--printjobs-panel);
            border: 1px solid var(--printjobs-border);
            border-radius: 12px;
        }

        .printjobs-filter {
            position: relative;
            min-width: 0;
        }

        .printjobs-filter-label {
            display: block;
            margin: 0 0 5px;
            color: var(--printjobs-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .printjobs-input,
        .printjobs-select {
            width: 100%;
            height: 40px;
            box-sizing: border-box;
            padding: 8px 10px;
            color: var(--printjobs-text);
            background: var(--printjobs-field);
            border: 1px solid var(--printjobs-border);
            border-radius: 8px;
            font: inherit;
            outline: none;
        }

        .printjobs-input:focus,
        .printjobs-select:focus {
            border-color: var(--printjobs-primary);
            box-shadow: 0 0 0 3px rgba(17, 165, 13, 0.16);
        }

        .printjobs-search-group {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 6px;
        }

        .printjobs-submit,
        .printjobs-reset,
        .printjobs-page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            box-sizing: border-box;
            padding: 8px 13px;
            color: #fff;
            background: var(--printjobs-field);
            border: 1px solid var(--printjobs-border);
            border-radius: 8px;
            font: inherit;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .printjobs-submit:hover,
        .printjobs-reset:hover,
        .printjobs-page-link:hover {
            border-color: var(--printjobs-primary);
        }

        .printjobs-filter-actions {
            display: flex;
            gap: 7px;
        }

        .printjobs-user-picker {
            position: relative;
        }

        .printjobs-user-control {
            position: relative;
            min-height: 40px;
        }

        .printjobs-user-selected {
            min-height: 40px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-sizing: border-box;
            padding: 6px 7px 6px 11px;
            background: var(--printjobs-field);
            border: 1px solid var(--printjobs-primary);
            border-radius: 8px;
        }

        .printjobs-user-selected-text {
            min-width: 0;
            flex: 1;
            overflow: hidden;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .printjobs-user-clear {
            width: 28px;
            height: 28px;
            flex: 0 0 auto;
            padding: 0;
            color: #fff;
            background: #3a3a3a;
            border: 1px solid #555;
            border-radius: 999px;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
        }

        .printjobs-user-clear:hover {
            border-color: #ff6666;
            color: #ff8888;
        }

        .printjobs-user-results {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            z-index: 100;
            display: none;
            max-height: 330px;
            overflow-y: auto;
            box-sizing: border-box;
            padding: 5px;
            background: #202020;
            border: 1px solid var(--printjobs-border);
            border-radius: 8px;
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.55);
        }

        .printjobs-user-results.visible {
            display: block;
        }

        .printjobs-user-result {
            width: 100%;
            display: block;
            padding: 9px 10px;
            color: #fff;
            background: transparent;
            border: 0;
            border-radius: 6px;
            text-align: left;
            font: inherit;
            cursor: pointer;
        }

        .printjobs-user-result:hover,
        .printjobs-user-result.active {
            background: rgba(17, 165, 13, 0.18);
        }

        .printjobs-user-empty {
            padding: 10px;
            color: var(--printjobs-muted);
            font-size: 13px;
            text-align: center;
        }

        .printjobs-period-row {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin: 0 0 12px;
        }

        .printjobs-period-button {
            min-height: 38px;
            padding: 7px 13px;
            color: #fff;
            background: var(--printjobs-field);
            border: 1px solid var(--printjobs-border);
            border-radius: 8px;
            font: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }

        .printjobs-period-button:hover,
        .printjobs-period-button.active {
            border-color: var(--printjobs-primary);
            background: rgba(17, 165, 13, 0.16);
        }

        .printjobs-semester-control {
            display: inline-flex;
            align-items: stretch;
        }

        .printjobs-semester-control .printjobs-period-button {
            border-radius: 8px 0 0 8px;
        }

        .printjobs-semester-select {
            min-height: 38px;
            max-width: 165px;
            padding: 6px 28px 6px 9px;
            color: #fff;
            background: var(--printjobs-field);
            border: 1px solid var(--printjobs-border);
            border-left: 0;
            border-radius: 0 8px 8px 0;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            outline: none;
        }

        .printjobs-semester-control.active .printjobs-semester-select {
            border-color: var(--printjobs-primary);
            background: var(--printjobs-field);
        }

        .printjobs-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 8px 0 12px;
        }

        .printjobs-summary {
            color: var(--printjobs-muted);
            font-size: 14px;
        }

        .printjobs-summary strong {
            color: #fff;
        }

        .printjobs-page-size {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--printjobs-muted);
            font-size: 13px;
        }

        .printjobs-page-size select {
            width: 90px;
            height: 34px;
            padding: 5px 8px;
        }

        .printjobs-table-wrap {
            width: 100%;
            overflow-x: auto;
            background: #181717;
            border: 1px solid var(--printjobs-border);
            border-radius: 10px;
        }

        .printjobs-table {
            width: 100%;
            min-width: 1260px;
            border-collapse: collapse;
        }

        .printjobs-table th {
            padding: 11px 10px;
            background: #242424;
            border-bottom: 1px solid var(--printjobs-border);
            color: #fff;
            text-align: left;
            white-space: nowrap;
        }

        .printjobs-table th a {
            display: block;
            color: inherit;
            text-decoration: none;
        }

        .printjobs-table th a:hover {
            color: #9ae697;
        }

        .printjobs-table td {
            padding: 10px;
            border-bottom: 1px solid #363636;
            color: var(--printjobs-text);
            vertical-align: middle;
        }

        .printjobs-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }

        .printjobs-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .printjobs-title-cell {
            max-width: 360px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .printjobs-secondary {
            display: block;
            margin-top: 2px;
            color: var(--printjobs-muted);
            font-size: 11px;
        }

        .printjobs-room-tower {
            margin-right: 4px;
            font-weight: 800;
        }

        .printjobs-room-tower-weh {
            color: #11a50d;
        }

        .printjobs-room-tower-tvk {
            color: #ffa500;
        }

        .printjobs-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 82px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .printjobs-status-0 {
            color: #ffd45a;
            background: rgba(255, 193, 7, 0.14);
            border: 1px solid rgba(255, 193, 7, 0.4);
        }

        .printjobs-status-1 {
            color: #8ee889;
            background: rgba(17, 165, 13, 0.16);
            border: 1px solid rgba(17, 165, 13, 0.45);
        }

        .printjobs-status-2 {
            color: #ff8a8a;
            background: rgba(255, 82, 82, 0.13);
            border: 1px solid rgba(255, 82, 82, 0.4);
        }

        .printjobs-status-3 {
            color: #bbb;
            background: rgba(160, 160, 160, 0.12);
            border: 1px solid rgba(160, 160, 160, 0.35);
        }

        .printjobs-status-4 {
            color: #8fd8ff;
            background: rgba(33, 150, 243, 0.14);
            border: 1px solid rgba(33, 150, 243, 0.42);
        }

        .printjobs-flash {
            box-sizing: border-box;
            padding: 11px 14px;
            margin: 18px 0 12px;
            border: 1px solid var(--printjobs-border);
            border-radius: 9px;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .printjobs-flash-success {
            background: rgba(17, 165, 13, 0.16);
            border-color: rgba(17, 165, 13, 0.55);
        }

        .printjobs-flash-error {
            background: rgba(255, 82, 82, 0.14);
            border-color: rgba(255, 82, 82, 0.55);
        }

        .printjobs-flash-info {
            background: rgba(33, 150, 243, 0.14);
            border-color: rgba(33, 150, 243, 0.48);
        }

        .printjobs-refund-form {
            margin: 0;
        }

        .printjobs-refund-button {
            min-height: 34px;
            padding: 6px 11px;
            color: #fff;
            background: rgba(255, 152, 0, 0.14);
            border: 1px solid rgba(255, 152, 0, 0.55);
            border-radius: 7px;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
            cursor: pointer;
        }

        .printjobs-refund-button:hover {
            background: rgba(255, 152, 0, 0.24);
            border-color: #ff9800;
        }

        .printjobs-refund-button:disabled {
            color: #aaa;
            background: rgba(160, 160, 160, 0.10);
            border-color: rgba(160, 160, 160, 0.30);
            cursor: default;
            opacity: 0.7;
        }

        .printjobs-empty {
            padding: 28px 16px;
            color: var(--printjobs-muted);
            background: var(--printjobs-panel);
            border: 1px solid var(--printjobs-border);
            border-radius: 10px;
            text-align: center;
        }

        .printjobs-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 14px;
        }

        .printjobs-pagination-pages {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .printjobs-page-number {
            min-width: 40px;
            padding-left: 10px;
            padding-right: 10px;
        }

        .printjobs-page-number.active {
            color: #fff;
            background: rgba(17, 165, 13, 0.22);
            border-color: var(--printjobs-primary);
            cursor: default;
            pointer-events: none;
        }

        .printjobs-page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        .printjobs-page-ellipsis {
            min-width: 24px;
            color: var(--printjobs-muted);
            font-weight: 800;
            text-align: center;
        }

        .printjobs-page-info {
            width: 100%;
            margin-top: 2px;
            color: var(--printjobs-muted);
            font-size: 13px;
            text-align: center;
        }

        @media (max-width: 1250px) {
            .printjobs-toolbar {
                grid-template-columns:
                    repeat(2, minmax(220px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .printjobs-page {
                width: calc(100% - 16px);
            }

            .printjobs-toolbar {
                grid-template-columns: 1fr;
                padding: 11px;
            }

            .printjobs-filter-actions {
                width: 100%;
            }

            .printjobs-filter-actions > * {
                flex: 1;
            }
        }
    </style>
</head>

<body>

<?php load_menu(); ?>

<div class="printjobs-page">

    <?php if (is_array($refundFlash)): ?>
        <?php
        $refundFlashType = (string)(
            $refundFlash['type'] ?? 'info'
        );

        if (
            !in_array(
                $refundFlashType,
                ['success', 'error', 'info'],
                true
            )
        ) {
            $refundFlashType = 'info';
        }
        ?>

        <div
            class="printjobs-flash printjobs-flash-<?= druckjobs_h($refundFlashType) ?>"
        >
            <?= druckjobs_h(
                $refundFlash['text'] ?? ''
            ) ?>
        </div>
    <?php endif; ?>

    <form
        method="get"
        id="printjobs-filter-form"
        class="printjobs-toolbar"
        autocomplete="off"
    >
        <input
            type="hidden"
            name="period"
            id="printjobs-period"
            value="<?= druckjobs_h($period) ?>"
        >

        <input
            type="hidden"
            name="uid"
            id="printjobs-user-uid"
            value="<?= $selectedUid > 0 ? $selectedUid : '' ?>"
        >

        <input
            type="hidden"
            name="sort"
            value="<?= druckjobs_h($sort) ?>"
        >

        <input
            type="hidden"
            name="dir"
            value="<?= druckjobs_h($direction) ?>"
        >

        <div class="printjobs-filter">
            <label
                class="printjobs-filter-label"
                for="printjobs-search"
            >
                Suche
            </label>

            <div class="printjobs-search-group">
                <input
                    type="search"
                    name="q"
                    id="printjobs-search"
                    class="printjobs-input"
                    value="<?= druckjobs_h($search) ?>"
                    placeholder="Titel, Drucker, UID, ID …"
                >

                <button
                    type="submit"
                    class="printjobs-submit"
                >
                    Suchen
                </button>
            </div>
        </div>

        <div
            class="printjobs-filter printjobs-user-picker"
            id="printjobs-user-picker"
        >
            <label
                class="printjobs-filter-label"
                for="printjobs-user-search"
            >
                Benutzer
            </label>

            <div class="printjobs-user-control">

                <div
                    id="printjobs-user-selected"
                    class="printjobs-user-selected"
                    style="<?= $selectedUid > 0 ? '' : 'display:none;' ?>"
                >
                    <span
                        id="printjobs-user-selected-text"
                        class="printjobs-user-selected-text"
                    >
                        <?= druckjobs_h($selectedUserLabel) ?>
                    </span>

                    <button
                        type="button"
                        id="printjobs-user-clear"
                        class="printjobs-user-clear"
                        aria-label="Benutzerfilter entfernen"
                    >
                        ×
                    </button>
                </div>

                <input
                    type="search"
                    id="printjobs-user-search"
                    class="printjobs-input"
                    placeholder="Benutzer suchen …"
                    style="<?= $selectedUid > 0 ? 'display:none;' : '' ?>"
                >

                <div
                    id="printjobs-user-results"
                    class="printjobs-user-results"
                ></div>

            </div>
        </div>

        <div class="printjobs-filter">
            <label
                class="printjobs-filter-label"
                for="printjobs-printer"
            >
                Drucker
            </label>

            <select
                name="printer"
                id="printjobs-printer"
                class="printjobs-select"
            >
                <option value="">Alle Drucker</option>

                <?php foreach ($printerOptions as $printer): ?>
                    <option
                        value="<?= druckjobs_h($printer) ?>"
                        <?= $selectedPrinter === $printer ? 'selected' : '' ?>
                    >
                        <?= druckjobs_h($printer) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="printjobs-filter">
            <label
                class="printjobs-filter-label"
                for="printjobs-status"
            >
                Status
            </label>

            <select
                name="status"
                id="printjobs-status"
                class="printjobs-select"
            >
                <option value="">Alle Status</option>

                <?php foreach ($statusLabels as $statusId => $statusLabel): ?>
                    <option
                        value="<?= $statusId ?>"
                        <?= $selectedStatus === (string)$statusId ? 'selected' : '' ?>
                    >
                        <?= druckjobs_h($statusLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="printjobs-filter-actions">
            <button
                type="submit"
                class="printjobs-submit"
            >
                Anwenden
            </button>

            <a
                href="<?= druckjobs_h($_SERVER['PHP_SELF']) ?>"
                class="printjobs-reset"
            >
                Zurücksetzen
            </a>
        </div>

    </form>

    <div class="printjobs-period-row">

        <button
            type="button"
            class="printjobs-period-button <?= $period === 'today' ? 'active' : '' ?>"
            data-period="today"
        >
            Heute
        </button>

        <button
            type="button"
            class="printjobs-period-button <?= $period === '7' ? 'active' : '' ?>"
            data-period="7"
        >
            7 Tage
        </button>

        <button
            type="button"
            class="printjobs-period-button <?= $period === '30' ? 'active' : '' ?>"
            data-period="30"
        >
            30 Tage
        </button>

        <div
            class="printjobs-semester-control <?= $period === 'semester' ? 'active' : '' ?>"
        >
            <button
                type="button"
                class="printjobs-period-button <?= $period === 'semester' ? 'active' : '' ?>"
                data-period="semester"
            >
                Semester
            </button>

            <select
                name="semester_start"
                id="printjobs-semester-select"
                class="printjobs-semester-select"
                form="printjobs-filter-form"
            >
                <?php foreach ($semesterOptions as $label => $startTs): ?>
                    <option
                        value="<?= (int)$startTs ?>"
                        <?= $semesterStart === (int)$startTs ? 'selected' : '' ?>
                    >
                        <?= druckjobs_h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button
            type="button"
            class="printjobs-period-button <?= $period === 'all' ? 'active' : '' ?>"
            data-period="all"
        >
            Alle
        </button>

    </div>

    <div class="printjobs-summary-row">

        <div class="printjobs-summary">
            <strong>
                <?= number_format($totalCount, 0, ',', '.') ?>
            </strong>
            Druckjobs ·

            <strong>
                <?= number_format($totalPrintedPages, 0, ',', '.') ?>
            </strong>
            Seiten ·

            <strong>
                <?= number_format($totalAmount, 2, ',', '.') ?> €
            </strong>
        </div>

        <label
            class="printjobs-page-size"
            for="printjobs-page-size"
        >
            Pro Seite

            <select
                name="per_page"
                id="printjobs-page-size"
                class="printjobs-select"
                form="printjobs-filter-form"
            >
                <?php foreach ($allowedPageSizes as $pageSize): ?>
                    <option
                        value="<?= $pageSize ?>"
                        <?= $perPage === $pageSize ? 'selected' : '' ?>
                    >
                        <?= $pageSize ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

    </div>

    <?php if (!empty($rows)): ?>

        <div class="printjobs-table-wrap">

            <table class="printjobs-table">
                <thead>
                    <tr>
                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('date')) ?>">
                                Zeitpunkt<?= druckjobs_sort_marker('date') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('user')) ?>">
                                Benutzer<?= druckjobs_sort_marker('user') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('room')) ?>">
                                Raum<?= druckjobs_sort_marker('room') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('title')) ?>">
                                Titel<?= druckjobs_sort_marker('title') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('printer')) ?>">
                                Drucker<?= druckjobs_sort_marker('printer') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('pages')) ?>">
                                Seiten<?= druckjobs_sort_marker('pages') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('mode')) ?>">
                                Druck<?= druckjobs_sort_marker('mode') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('status')) ?>">
                                Status<?= druckjobs_sort_marker('status') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= druckjobs_h(druckjobs_sort_url('amount')) ?>">
                                Betrag<?= druckjobs_sort_marker('amount') ?>
                            </a>
                        </th>

                        <th>
                            Aktion
                        </th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($rows as $row): ?>

                    <?php
                    $status = (int)$row['status'];

                    $statusLabel = $statusLabels[$status]
                        ?? 'Unbekannt';

                    $userDisplay = druckjobs_short_name(
                        $row['firstname'],
                        $row['lastname'],
                        (int)$row['uid']
                    );

                    $turm = druckjobs_turm(
                        $row['turm']
                    );

                    $room = druckjobs_room(
                        $row['room'],
                        $row['oldroom']
                    );

                    $turmClass = match ($turm) {
                        'WEH' => 'printjobs-room-tower-weh',
                        'TvK' => 'printjobs-room-tower-tvk',
                        default => '',
                    };

                    $printer = trim(
                        (string)$row['drucker']
                    );

                    if ($printer === '') {
                        $printer = '-';
                    }

                    $title = trim(
                        (string)$row['title']
                    );

                    if ($title === '') {
                        $title = '-';
                    }

                    $din = trim(
                        (string)$row['din']
                    );

                    if ($din === '') {
                        $din = '-';
                    } else {
                        $din = strtoupper($din);
                    }

                    $printMode =
                        $din
                        . ' · '
                        . (
                            (int)$row['duplex'] === 1
                                ? 'Duplex'
                                : 'Simplex'
                        )
                        . ' · '
                        . (
                            (int)$row['grey'] === 1
                                ? 'S/W'
                                : 'Farbe'
                        );

                    $shownPages = (int)$row['shown_pages'];
                    $plannedPages = (int)$row['planned_pages'];

                    $pagesDisplay = (string)$shownPages;

                    if ($shownPages !== $plannedPages) {
                        $pagesDisplay .= ' / ' . $plannedPages;
                    }
                    ?>

                    <tr>
                        <td>
                            <?= date(
                                'd.m.Y H:i',
                                (int)$row['print_tstamp']
                            ) ?>

                            <span class="printjobs-secondary">
                                #<?= (int)$row['printjob_id'] ?>
                            </span>
                        </td>

                        <td>
                            <?= druckjobs_h($userDisplay) ?>
                        </td>

                        <td>
                            <span
                                class="printjobs-room-tower <?= druckjobs_h($turmClass) ?>"
                            >
                                <?= druckjobs_h($turm) ?>
                            </span>
                            <?= druckjobs_h($room) ?>
                        </td>

                        <td
                            class="printjobs-title-cell"
                            title="<?= druckjobs_h($title) ?>"
                        >
                            <?= druckjobs_h($title) ?>
                        </td>

                        <td>
                            <?= druckjobs_h($printer) ?>
                        </td>

                        <td>
                            <?= druckjobs_h($pagesDisplay) ?>
                        </td>

                        <td>
                            <?= druckjobs_h($printMode) ?>
                        </td>

                        <td>
                            <span
                                class="printjobs-badge printjobs-status-<?= $status ?>"
                            >
                                <?= druckjobs_h($statusLabel) ?>
                            </span>
                        </td>

                        <td>
                            <?= number_format(
                                (float)$row['betrag'],
                                2,
                                ',',
                                '.'
                            ) ?>
                            €
                        </td>

                        <td>
                            <form
                                method="post"
                                action="<?= druckjobs_h(druckjobs_url()) ?>"
                                class="printjobs-refund-form"
                                onsubmit="return confirm('Druckjob #<?= (int)$row['printjob_id'] ?> wirklich erstatten? Der zugehörige Transferbetrag wird auf 0,00 € gesetzt.');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= druckjobs_h($refundCsrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="printjob_id"
                                    value="<?= (int)$row['printjob_id'] ?>"
                                >

                                <button
                                    type="submit"
                                    name="refund_printjob"
                                    value="1"
                                    class="printjobs-refund-button"
                                    <?= $status === 4 ? 'disabled' : '' ?>
                                >
                                    <?= $status === 4 ? 'Erstattet' : 'Refund' ?>
                                </button>
                            </form>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        </div>

    <?php else: ?>

        <div class="printjobs-empty">
            Keine Druckjobs gefunden.
        </div>

    <?php endif; ?>

    <?php if ($totalCount > 0): ?>

        <div class="printjobs-pagination">

            <?php if ($hasPreviousPage): ?>
                <a
                    class="printjobs-page-link"
                    href="<?= druckjobs_h(
                        druckjobs_url([
                            'page' => $page - 1,
                        ])
                    ) ?>"
                >
                    Zurück
                </a>
            <?php else: ?>
                <span class="printjobs-page-link disabled">
                    Zurück
                </span>
            <?php endif; ?>

            <div class="printjobs-pagination-pages">

                <?php foreach ($paginationItems as $paginationItem): ?>

                    <?php if ($paginationItem === 'ellipsis'): ?>
                        <span class="printjobs-page-ellipsis">
                            …
                        </span>
                    <?php else: ?>
                        <?php $pageNumber = (int)$paginationItem; ?>

                        <?php if ($pageNumber === $page): ?>
                            <span
                                class="printjobs-page-link printjobs-page-number active"
                                aria-current="page"
                            >
                                <?= $pageNumber ?>
                            </span>
                        <?php else: ?>
                            <a
                                class="printjobs-page-link printjobs-page-number"
                                href="<?= druckjobs_h(
                                    druckjobs_url([
                                        'page' => $pageNumber,
                                    ])
                                ) ?>"
                            >
                                <?= $pageNumber ?>
                            </a>
                        <?php endif; ?>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>

            <?php if ($hasNextPage): ?>
                <a
                    class="printjobs-page-link"
                    href="<?= druckjobs_h(
                        druckjobs_url([
                            'page' => $page + 1,
                        ])
                    ) ?>"
                >
                    Weiter
                </a>
            <?php else: ?>
                <span class="printjobs-page-link disabled">
                    Weiter
                </span>
            <?php endif; ?>

            <div class="printjobs-page-info">
                Seite <?= $page ?> von <?= $totalPageCount ?>
            </div>

        </div>

    <?php endif; ?>

</div>

<script>
(() => {
    const filterForm = document.getElementById(
        'printjobs-filter-form'
    );

    const periodInput = document.getElementById(
        'printjobs-period'
    );

    const periodButtons = Array.from(
        document.querySelectorAll(
            '.printjobs-period-button[data-period]'
        )
    );

    const semesterSelect = document.getElementById(
        'printjobs-semester-select'
    );

    const printerSelect = document.getElementById(
        'printjobs-printer'
    );

    const statusSelect = document.getElementById(
        'printjobs-status'
    );

    const pageSizeSelect = document.getElementById(
        'printjobs-page-size'
    );

    function submitFilters() {
        const pageField = filterForm.querySelector(
            'input[name="page"]'
        );

        if (pageField) {
            pageField.remove();
        }

        filterForm.submit();
    }

    periodButtons.forEach(button => {
        button.addEventListener('click', () => {
            periodInput.value = button.dataset.period;
            submitFilters();
        });
    });

    semesterSelect.addEventListener('change', () => {
        periodInput.value = 'semester';
        submitFilters();
    });

    printerSelect.addEventListener(
        'change',
        submitFilters
    );

    statusSelect.addEventListener(
        'change',
        submitFilters
    );

    pageSizeSelect.addEventListener(
        'change',
        submitFilters
    );
})();
</script>

<script>
(() => {
    const filterForm = document.getElementById(
        'printjobs-filter-form'
    );

    const picker = document.getElementById(
        'printjobs-user-picker'
    );

    const searchInput = document.getElementById(
        'printjobs-user-search'
    );

    const hiddenUid = document.getElementById(
        'printjobs-user-uid'
    );

    const resultBox = document.getElementById(
        'printjobs-user-results'
    );

    const selectedBox = document.getElementById(
        'printjobs-user-selected'
    );

    const selectedText = document.getElementById(
        'printjobs-user-selected-text'
    );

    const clearButton = document.getElementById(
        'printjobs-user-clear'
    );

    let searchTimeout = null;
    let requestController = null;
    let activeResultIndex = -1;

    function closeResults() {
        resultBox.classList.remove('visible');
        activeResultIndex = -1;
    }

    function submitUserFilter() {
        filterForm.submit();
    }

    function selectUser(uid, label) {
        hiddenUid.value = String(uid);
        selectedText.textContent = label;

        searchInput.value = '';
        searchInput.style.display = 'none';

        selectedBox.style.display = 'flex';

        closeResults();
        submitUserFilter();
    }

    function renderResults(users) {
        resultBox.innerHTML = '';
        activeResultIndex = -1;

        if (!Array.isArray(users) || users.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'printjobs-user-empty';
            empty.textContent = 'Keine Benutzer gefunden';

            resultBox.appendChild(empty);
            resultBox.classList.add('visible');
            return;
        }

        users.forEach(user => {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'printjobs-user-result';
            button.textContent = user.label;

            button.addEventListener('click', () => {
                selectUser(
                    user.uid,
                    user.label
                );
            });

            resultBox.appendChild(button);
        });

        resultBox.classList.add('visible');
    }

    async function loadUsers(term = '') {
        if (requestController) {
            requestController.abort();
        }

        requestController = new AbortController();

        const url = new URL(
            window.location.href
        );

        url.search = '';
        url.searchParams.set(
            'ajax',
            'user_search'
        );

        url.searchParams.set(
            'term',
            term
        );

        try {
            const response = await fetch(
                url.toString(),
                {
                    credentials: 'same-origin',
                    signal: requestController.signal,
                    headers: {
                        Accept: 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error(
                    'Benutzersuche fehlgeschlagen'
                );
            }

            const data = await response.json();

            renderResults(
                data.users || []
            );
        } catch (error) {
            if (error.name !== 'AbortError') {
                resultBox.innerHTML = '';

                const empty = document.createElement('div');
                empty.className = 'printjobs-user-empty';
                empty.textContent =
                    'Benutzersuche fehlgeschlagen';

                resultBox.appendChild(empty);
                resultBox.classList.add('visible');
            }
        }
    }

    function updateActiveResult(direction) {
        const results = Array.from(
            resultBox.querySelectorAll(
                '.printjobs-user-result'
            )
        );

        if (results.length === 0) {
            return;
        }

        results.forEach(result => {
            result.classList.remove('active');
        });

        activeResultIndex += direction;

        if (activeResultIndex < 0) {
            activeResultIndex = results.length - 1;
        }

        if (activeResultIndex >= results.length) {
            activeResultIndex = 0;
        }

        results[
            activeResultIndex
        ].classList.add('active');

        results[
            activeResultIndex
        ].scrollIntoView({
            block: 'nearest'
        });
    }

    searchInput.addEventListener('focus', () => {
        loadUsers(
            searchInput.value.trim()
        );
    });

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);

        searchTimeout = setTimeout(() => {
            loadUsers(
                searchInput.value.trim()
            );
        }, 180);
    });

    searchInput.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            updateActiveResult(1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            updateActiveResult(-1);
            return;
        }

        if (event.key === 'Enter') {
            const activeResult = resultBox.querySelector(
                '.printjobs-user-result.active'
            );

            if (activeResult) {
                event.preventDefault();
                activeResult.click();
            }

            return;
        }

        if (event.key === 'Escape') {
            closeResults();
        }
    });

    clearButton.addEventListener('click', () => {
        hiddenUid.value = '';

        selectedText.textContent = '';
        selectedBox.style.display = 'none';

        searchInput.style.display = 'block';
        searchInput.value = '';

        closeResults();
        submitUserFilter();
    });

    document.addEventListener('click', event => {
        if (!picker.contains(event.target)) {
            closeResults();
        }
    });
})();
</script>

</body>
</html>