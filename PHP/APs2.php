<?php
session_start();
require_once('conn.php');
mysqli_set_charset($conn, 'utf8');
date_default_timezone_set('Europe/Berlin');

function ap2_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap2_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ap2_require_ajax_auth(): void
{
    if (empty($_SESSION['NetzAG'])) {
        ap2_json(['ok' => false, 'error' => 'Nicht berechtigt.'], 403);
    }
}

function ap2_status_labels(): array
{
    return [
        'in_betrieb' => 'In Betrieb',
        'testbetrieb' => 'Testbetrieb',
        'designiert' => 'Designiert',
        'reserve' => 'Reserve',
        'veraltet_verschollen' => 'Veraltet/Verschollen',
    ];
}

function ap2_location_labels(): array
{
    return [
        'bewohnerzimmer' => 'Bewohnerzimmer',
        'technikkamin' => 'Technikkamin',
        'sonstiges' => 'Sonstiges',
    ];
}

function ap2_switches(): array
{
    return [
        'weh' => [
            'c4k-weh-1' => 'c4k-weh-1 [Coreswitch]',
            'c4k-weh-2' => 'c4k-weh-2 [Etage 9-17]',
            'c4k-weh-3' => 'c4k-weh-3 [Etage 1-8]',
            'c3560-weh-1' => 'c3560-weh-1 [Wohnzimmer]',
        ],
        'tvk' => [
            'c4k-tvk-1' => 'c4k-tvk-1 [Etage 8-15]',
            'c4k-tvk-2' => 'c4k-tvk-2 [Etage 1-7]',
        ],
        'far' => [
            'c3560-farue-1' => 'c3560-farue-1',
            'c3750-farue-2' => 'c3750-farue-2',
            'c3560-farue-3' => 'c3560-farue-3',
        ],
    ];
}

function ap2_all_switches(): array
{
    $all = [];
    foreach (ap2_switches() as $switches) {
        foreach ($switches as $value => $label) {
            $all[$value] = $label;
        }
    }
    return $all;
}

function ap2_is_active_status(string $status): bool
{
    return in_array($status, ['in_betrieb', 'testbetrieb', 'designiert'], true);
}

function ap2_legacy_nagios_from_status(string $status): int
{
    return match ($status) {
        'in_betrieb' => 1,
        'testbetrieb' => 2,
        'designiert' => 3,
        default => 0,
    };
}

function ap2_normalize_turm(?string $turm): ?string
{
    $turm = strtolower(trim((string)$turm));
    if ($turm === '') {
        return null;
    }
    return in_array($turm, ['weh', 'tvk', 'far'], true) ? $turm : null;
}

function ap2_normalize_nullable_string($value): ?string
{
    $value = trim((string)($value ?? ''));
    return $value === '' ? null : $value;
}

function ap2_normalize_mac(?string $value): ?string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return null;
    }

    $hex = strtolower(preg_replace('/[^a-f0-9]/i', '', $value));

    if (strlen($hex) !== 12) {
        return $value;
    }

    return implode(':', str_split($hex, 2));
}

function ap2_normalize_room_string($value): ?int
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,4}$/', $value)) {
        return null;
    }
    return (int)$value;
}

function ap2_is_valid_bewohnerzimmer(string $turm, int $room): bool
{
    $floor = intdiv($room, 100);
    $zimmer = $room % 100;

    if ($turm === 'weh') {
        if ($floor === 0) {
            return $zimmer >= 1 && $zimmer <= 4;
        }
        return $floor >= 1 && $floor <= 17 && $zimmer >= 1 && $zimmer <= 16;
    }

    if ($turm === 'tvk') {
        if ($floor === 0) {
            return $zimmer >= 1 && $zimmer <= 2;
        }
        return $floor >= 1 && $floor <= 15 && $zimmer >= 1 && $zimmer <= 16;
    }

    return false;
}

function ap2_max_floor(string $turm): int
{
    return $turm === 'tvk' ? 15 : 17;
}

function ap2_read_existing_ap(mysqli $conn, int $id): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT id, room, turm, status, location, parentswitch FROM aps WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $rowId, $room, $turm, $status, $location, $parentswitch);

    $row = null;
    if (mysqli_stmt_fetch($stmt)) {
        $row = [
            'id' => (int)$rowId,
            'room' => $room === null ? null : (int)$room,
            'turm' => $turm,
            'status' => $status,
            'location' => $location,
            'parentswitch' => $parentswitch,
        ];
    }

    mysqli_stmt_close($stmt);
    return $row;
}

function ap2_room_assignment_taken(mysqli $conn, int $room, string $turm, string $location, int $ignoreId): bool
{
    $activeA = 'in_betrieb';
    $activeB = 'testbetrieb';
    $activeC = 'designiert';

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) FROM aps
         WHERE room = ?
           AND turm = ?
           AND location = ?
           AND status IN (?, ?, ?)
           AND id <> ?'
    );
    mysqli_stmt_bind_param($stmt, 'isssssi', $room, $turm, $location, $activeA, $activeB, $activeC, $ignoreId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$count > 0;
}

function ap2_search(mysqli $conn): void
{
    ap2_require_ajax_auth();

    $q = trim((string)($_GET['q'] ?? ''));

    if (strlen($q) < 2) {
        ap2_json(['ok' => true, 'results' => []]);
    }

    $qLower = strtolower($q);
    $qStatus = str_replace([' ', '/', '-'], ['_', '_', '_'], $qLower);
    $qTurm = str_replace(['farü', 'farue'], 'far', $qLower);

    // MAC-Suche: akzeptiert colon-, dash-, Cisco- und raw-Format.
    // Beispiel: 28:6f:7f:2a:b6:30, 28-6f-7f-2a-b6-30, 286f.7f2a.b630, 286f7f2ab630
    $qMac = preg_replace('/[^a-f0-9]/i', '', $qLower);

    $like = '%' . $qLower . '%';
    $likeStatus = '%' . $qStatus . '%';
    $likeTurm = '%' . $qTurm . '%';
    $likeMac = '%' . ($qMac !== '' ? $qMac : $qLower) . '%';

    $sql = "
        SELECT id, room, turm, hostname, ip, mac, beschreibung, produkt, nagios, parentswitch, status, location
        FROM aps
        WHERE LOWER(COALESCE(hostname, '')) LIKE ?
           OR LOWER(COALESCE(ip, '')) LIKE ?
           OR LOWER(COALESCE(mac, '')) LIKE ?
           OR LOWER(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(COALESCE(mac, ''), ':', ''),
                        '-', ''),
                    '.', ''),
                ' ', '')
              ) LIKE ?
           OR LOWER(COALESCE(beschreibung, '')) LIKE ?
           OR LOWER(COALESCE(produkt, '')) LIKE ?
           OR LOWER(COALESCE(parentswitch, '')) LIKE ?
           OR LOWER(COALESCE(turm, '')) LIKE ?
           OR LOWER(COALESCE(turm, '')) LIKE ?
           OR LOWER(COALESCE(status, '')) LIKE ?
           OR LOWER(COALESCE(location, '')) LIKE ?
           OR CAST(COALESCE(room, '') AS CHAR) LIKE ?
           OR LPAD(CAST(COALESCE(room, 0) AS CHAR), 4, '0') LIKE ?
        ORDER BY
            FIELD(status, 'in_betrieb', 'testbetrieb', 'designiert', 'reserve', 'veraltet_verschollen'),
            FIELD(turm, 'weh', 'tvk', 'far'),
            room,
            hostname
        LIMIT 40
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        ap2_json(['ok' => false, 'error' => 'Suche konnte nicht vorbereitet werden.'], 500);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssssss',
        $like,
        $like,
        $like,
        $likeMac,
        $like,
        $like,
        $like,
        $like,
        $likeTurm,
        $likeStatus,
        $like,
        $like,
        $like
    );

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['id'] = (int)$row['id'];
        $row['room'] = $row['room'] === null ? null : (int)$row['room'];
        $row['nagios'] = $row['nagios'] === null ? null : (int)$row['nagios'];
        $row['turm'] = $row['turm'] === null ? null : strtolower(trim((string)$row['turm']));
        $row['status'] = $row['status'] ?: 'reserve';
        $row['location'] = $row['location'] ?: null;
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    ap2_json([
        'ok' => true,
        'results' => $rows,
    ]);
}

function ap2_validate_and_save(mysqli $conn): void
{
    ap2_require_ajax_auth();

    $id = (int)($_POST['id'] ?? 0);
    $existing = $id > 0 ? ap2_read_existing_ap($conn, $id) : null;

    if ($id > 0 && !$existing) {
        ap2_json(['ok' => false, 'error' => 'AP nicht gefunden.'], 404);
    }

    $status = trim((string)($_POST['status'] ?? ''));
    $statusLabels = ap2_status_labels();

    if (!array_key_exists($status, $statusLabels)) {
        ap2_json(['ok' => false, 'error' => 'Ungültiger Status.'], 422);
    }

    $turm = ap2_normalize_turm($_POST['turm'] ?? null);
    $location = trim((string)($_POST['location'] ?? ''));
    $hostname = ap2_normalize_nullable_string($_POST['hostname'] ?? null);
    $ip = ap2_normalize_nullable_string($_POST['ip'] ?? null);
    $mac = ap2_normalize_mac($_POST['mac'] ?? null);
    $beschreibung = ap2_normalize_nullable_string($_POST['beschreibung'] ?? null);
    $produkt = ap2_normalize_nullable_string($_POST['produkt'] ?? null);
    $parentswitch = ap2_normalize_nullable_string($_POST['parentswitch'] ?? null);
    $room = $existing['room'] ?? null;

    if ($hostname === null) {
        ap2_json(['ok' => false, 'error' => 'Hostname fehlt.'], 422);
    }

    if (!ap2_is_active_status($status)) {
        $location = null;

        if (!$existing) {
            $turm = null;
            $room = null;
        } else {
            $turm = $existing['turm'];
            $room = $existing['room'];
        }
    } else {
        if ($turm === null) {
            ap2_json(['ok' => false, 'error' => 'Turm fehlt oder ist ungültig.'], 422);
        }

        if ($turm === 'far') {
            $location = null;
            $room = $existing['room'] ?? null;
        } else {
            $locationLabels = ap2_location_labels();

            if (!array_key_exists($location, $locationLabels)) {
                ap2_json(['ok' => false, 'error' => 'Location fehlt oder ist ungültig.'], 422);
            }

            if ($location === 'bewohnerzimmer') {
                $room = ap2_normalize_room_string($_POST['room'] ?? null);

                if ($room === null || !ap2_is_valid_bewohnerzimmer($turm, $room)) {
                    $bereich = $turm === 'tvk'
                        ? 'TvK: 0001-0002, 0101-1516 strukturell gültig'
                        : 'WEH: 0001-0004, 0101-1716 strukturell gültig';

                    ap2_json(['ok' => false, 'error' => 'Ungültiger Bewohnerzimmer-Raum. Erlaubt: ' . $bereich . '.'], 422);
                }
            } elseif ($location === 'technikkamin') {
                $floor = (int)($_POST['kamin_floor'] ?? 0);
                $maxFloor = ap2_max_floor($turm);

                if ($floor < 1 || $floor > $maxFloor) {
                    ap2_json(['ok' => false, 'error' => 'Ungültige Kamin-Etage.'], 422);
                }

                // Technikkamine sind keine echten Räume. xx00 ist ein interner Etagenmarker, z. B. 0900 für Etage 9.
                $room = $floor * 100;
            } else {
                $room = $existing['room'] ?? null;
            }

            if (in_array($location, ['bewohnerzimmer', 'technikkamin'], true)) {
                if (ap2_room_assignment_taken($conn, (int)$room, $turm, $location, $id)) {
                    ap2_json(['ok' => false, 'error' => 'Diese Raum-/Kamin-Zuordnung ist bereits vergeben.'], 409);
                }
            }
        }
    }

    $allSwitches = ap2_all_switches();

    if ($parentswitch !== null && !array_key_exists($parentswitch, $allSwitches)) {
        ap2_json(['ok' => false, 'error' => 'Ungültiger Parent Switch.'], 422);
    }

    $nagios = ap2_legacy_nagios_from_status($status);

    if ($id > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE aps
             SET room = ?, turm = ?, status = ?, location = ?, hostname = ?, ip = ?, mac = ?, beschreibung = ?, produkt = ?, nagios = ?, parentswitch = ?
             WHERE id = ?'
        );

        mysqli_stmt_bind_param(
            $stmt,
            'issssssssisi',
            $room,
            $turm,
            $status,
            $location,
            $hostname,
            $ip,
            $mac,
            $beschreibung,
            $produkt,
            $nagios,
            $parentswitch,
            $id
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO aps (room, turm, status, location, hostname, ip, mac, beschreibung, produkt, nagios, parentswitch)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        mysqli_stmt_bind_param(
            $stmt,
            'issssssssis',
            $room,
            $turm,
            $status,
            $location,
            $hostname,
            $ip,
            $mac,
            $beschreibung,
            $produkt,
            $nagios,
            $parentswitch
        );
    }

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt) ?: mysqli_error($conn);
        mysqli_stmt_close($stmt);
        ap2_json(['ok' => false, 'error' => 'Speichern fehlgeschlagen: ' . $error], 500);
    }

    mysqli_stmt_close($stmt);
    ap2_json(['ok' => true]);
}

if (isset($_GET['ap2api'])) {
    if ($_GET['ap2api'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ap2_validate_and_save($conn);
    }

    if ($_GET['ap2api'] === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        ap2_search($conn);
    }

    ap2_json(['ok' => false, 'error' => 'Unbekannte API-Aktion.'], 404);
}

require_once('template.php');

if (!auth($conn) || empty($_SESSION['NetzAG'])) {
    header('Location: denied.php');
    exit;
}

function ap2_collect_aps(mysqli $conn): array
{
    $rows = [];

    $sql = 'SELECT id, room, turm, hostname, ip, mac, beschreibung, coord_x, coord_y, coord_z, produkt, nagios, parentswitch, status, location
            FROM aps
            ORDER BY FIELD(status, "in_betrieb", "testbetrieb", "designiert", "reserve", "veraltet_verschollen"), FIELD(turm, "weh", "tvk", "far"), room, hostname';

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return [];
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $row['id'] = (int)$row['id'];
        $row['room'] = $row['room'] === null ? null : (int)$row['room'];
        $row['nagios'] = $row['nagios'] === null ? null : (int)$row['nagios'];
        $row['turm'] = $row['turm'] === null ? null : strtolower(trim((string)$row['turm']));
        $row['status'] = $row['status'] ?: 'reserve';
        $row['location'] = $row['location'] ?: null;
        $rows[] = $row;
    }

    mysqli_free_result($result);

    return $rows;
}

function ap2_collect_nagios_states(?array $nagiosconfig): array
{
    $states = [];
    $error = '';

    if (!is_array($nagiosconfig) || empty($nagiosconfig['host'])) {
        return ['states' => $states, 'error' => 'Nagios-Konfiguration fehlt'];
    }

    $url = rtrim((string)$nagiosconfig['host'], '/') . '/cgi-bin/statusjson.cgi?query=hostlist&details=true';
    $context = stream_context_create([
        'http' => [
            'header' => 'Authorization: Basic ' . base64_encode(($nagiosconfig['user'] ?? '') . ':' . ($nagiosconfig['password'] ?? '')),
            'timeout' => 8,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $data = $response ? json_decode($response, true) : null;

    if (!isset($data['data']['hostlist']) || !is_array($data['data']['hostlist'])) {
        return ['states' => $states, 'error' => 'Nagios nicht erreichbar oder JSON ungültig'];
    }

    foreach ($data['data']['hostlist'] as $host => $info) {
        $hostname = strtolower(trim((string)$host));

        if ($hostname === '') {
            continue;
        }

        $isHardDown = isset($info['status'])
            && (int)$info['status'] === 4
            && (int)($info['scheduled_downtime_depth'] ?? 0) === 0;

        $states[$hostname] = $isHardDown ? 'offline' : 'online';
    }

    return ['states' => $states, 'error' => $error];
}

function ap2_nagios_live_for_ap(array $ap, array $nagiosStates, string $nagiosError): string
{
    $hostname = strtolower(trim((string)($ap['hostname'] ?? '')));

    if ($hostname === '') {
        return 'not_found';
    }

    if ($nagiosError !== '') {
        return 'error';
    }

    return $nagiosStates[$hostname] ?? 'not_found';
}

function ap2_color_key(array $ap, string $nagiosLive): string
{
    $status = (string)($ap['status'] ?? 'reserve');

    if ($status === 'in_betrieb') {
        return match ($nagiosLive) {
            'online' => 'green',
            'offline' => 'red',
            'error' => 'orange',
            default => 'gray',
        };
    }

    if ($status === 'testbetrieb') {
        return match ($nagiosLive) {
            'online' => 'yellow',
            'offline' => 'red',
            'error' => 'orange',
            default => 'blue',
        };
    }

    return match ($status) {
        'designiert' => 'blue',
        'reserve' => 'gray',
        'veraltet_verschollen' => 'red',
        default => 'gray',
    };
}

function ap2_room_label(?int $room): string
{
    if ($room === null) {
        return '';
    }

    return (string)$room;
}

function ap2_kamin_floor_from_room(?int $room): ?int
{
    if ($room === null || $room < 100 || $room > 1700 || $room % 100 !== 0) {
        return null;
    }

    return intdiv($room, 100);
}

function ap2_card_label(array $ap): string
{
    $hostname = trim((string)($ap['hostname'] ?? ''));

    return $hostname !== '' ? $hostname : ('AP #' . (int)$ap['id']);
}

function ap2_cell_attrs(?array $ap, array $prefill): string
{
    if ($ap) {
        return 'data-ap2-open="' . ap2_h($ap['id']) . '"';
    }

    return 'data-ap2-add="' . ap2_h(json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
}

function ap2_render_grid(string $turm, array $apsByKey): void
{
    $maxFloor = ap2_max_floor($turm);
    ?>
    <div class="ap2-grid-wrap">
        <table class="ap2-grid">
            <tr>
                <th class="ap2-floor-head"></th>
                <?php for ($zimmer = 1; $zimmer <= 16; $zimmer++): ?>
                    <th><?= ap2_h($zimmer) ?></th>
                    <?php if ($zimmer === 8): ?>
                        <th class="ap2-gap"></th>
                        <th class="ap2-kamin-head">Kamin</th>
                        <th class="ap2-gap"></th>
                    <?php endif; ?>
                <?php endfor; ?>
            </tr>

            <?php for ($floor = $maxFloor; $floor >= 0; $floor--): ?>
                <tr>
                    <th class="ap2-floor-head"><?= ap2_h($floor) ?></th>

                    <?php for ($zimmer = 1; $zimmer <= 16; $zimmer++): ?>
                        <?php
                        $validCell = true;

                        if ($floor === 0 && $turm === 'weh' && $zimmer > 4) {
                            $validCell = false;
                        }

                        if ($floor === 0 && $turm === 'tvk' && $zimmer > 2) {
                            $validCell = false;
                        }

                        $room = $floor * 100 + $zimmer;
                        $key = $turm . '|bewohnerzimmer|' . $room;
                        $ap = $apsByKey[$key] ?? null;
                        ?>

                        <?php if ($validCell): ?>
                            <td class="ap2-cell <?= $ap ? 'ap2-status-' . ap2_h($ap['colorKey']) : 'ap2-empty' ?>"
                                <?= ap2_cell_attrs($ap, [
                                    'status' => 'in_betrieb',
                                    'turm' => $turm,
                                    'location' => 'bewohnerzimmer',
                                    'room' => sprintf('%04d', $room),
                                ]) ?>><?= $ap ? ap2_h(ap2_room_label($ap['room'])) : '' ?></td>
                        <?php else: ?>
                            <td class="ap2-cell ap2-disabled"></td>
                        <?php endif; ?>

                        <?php if ($zimmer === 8): ?>
                            <td class="ap2-gap"></td>

                            <?php if ($floor > 0): ?>
                                <?php
                                $kaminRoom = $floor * 100;
                                $kaminKey = $turm . '|technikkamin|' . $kaminRoom;
                                $kaminAp = $apsByKey[$kaminKey] ?? null;
                                ?>

                                <td class="ap2-cell ap2-kamin-cell <?= $kaminAp ? 'ap2-status-' . ap2_h($kaminAp['colorKey']) : 'ap2-empty' ?>"
                                    <?= ap2_cell_attrs($kaminAp, [
                                        'status' => 'in_betrieb',
                                        'turm' => $turm,
                                        'location' => 'technikkamin',
                                        'kamin_floor' => $floor,
                                    ]) ?>><?= $kaminAp ? ap2_h(sprintf('%02d', $floor)) : '' ?></td>
                            <?php else: ?>
                                <td class="ap2-cell ap2-disabled"></td>
                            <?php endif; ?>

                            <td class="ap2-gap"></td>
                        <?php endif; ?>
                    <?php endfor; ?>
                </tr>
            <?php endfor; ?>
        </table>
    </div>
    <?php
}

function ap2_render_list(array $items, string $emptyText): void
{
    if (empty($items)) {
        echo '<div class="ap2-empty-list">' . ap2_h($emptyText) . '</div>';
        return;
    }

    echo '<div class="ap2-card-list">';

    foreach ($items as $ap) {
        $sub = [];

        if (!empty($ap['turm']) && function_exists('formatTurm')) {
            $sub[] = formatTurm($ap['turm']);
        }

        if (!empty($ap['statusLabel'])) {
            $sub[] = $ap['statusLabel'];
        }

        echo '<button type="button" class="ap2-card ap2-status-' . ap2_h($ap['colorKey']) . '" data-ap2-open="' . ap2_h($ap['id']) . '">';
        echo '<strong>' . ap2_h(ap2_card_label($ap)) . '</strong>';

        if (!empty($sub)) {
            echo '<span>' . ap2_h(implode(' · ', $sub)) . '</span>';
        }

        echo '</button>';
    }

    echo '</div>';
}

$aps = ap2_collect_aps($conn);
$nagios = ap2_collect_nagios_states($config['nagios'] ?? null);
$nagiosStates = $nagios['states'];
$nagiosError = $nagios['error'];
$statusLabels = ap2_status_labels();
$locationLabels = ap2_location_labels();
$switchesByTurm = ap2_switches();
$allSwitches = ap2_all_switches();
$turmLabels = [
    'weh' => formatTurm('weh'),
    'tvk' => formatTurm('tvk'),
    'far' => formatTurm('far'),
];

foreach ($aps as &$ap) {
    $ap['nagiosLive'] = ap2_nagios_live_for_ap($ap, $nagiosStates, $nagiosError);
    $ap['colorKey'] = ap2_color_key($ap, $ap['nagiosLive']);
    $ap['statusLabel'] = $statusLabels[$ap['status']] ?? $ap['status'];
    $ap['locationLabel'] = $ap['location'] ? ($locationLabels[$ap['location']] ?? $ap['location']) : '';
    $ap['kamin_floor'] = $ap['location'] === 'technikkamin' ? ap2_kamin_floor_from_room($ap['room']) : null;
}
unset($ap);

$apsByKey = [];
$wehSonstiges = [];
$tvkSonstiges = [];
$farItems = [];
$reserveItems = [];
$veraltetItems = [];

foreach ($aps as $ap) {
    if ($ap['status'] === 'reserve') {
        $reserveItems[] = $ap;
        continue;
    }

    if ($ap['status'] === 'veraltet_verschollen') {
        $veraltetItems[] = $ap;
        continue;
    }

    if ($ap['turm'] === 'far') {
        $farItems[] = $ap;
        continue;
    }

    if (
        in_array($ap['turm'], ['weh', 'tvk'], true)
        && in_array($ap['location'], ['bewohnerzimmer', 'technikkamin'], true)
        && $ap['room'] !== null
    ) {
        $apsByKey[$ap['turm'] . '|' . $ap['location'] . '|' . $ap['room']] = $ap;
        continue;
    }

    if ($ap['turm'] === 'weh' && $ap['location'] === 'sonstiges') {
        $wehSonstiges[] = $ap;
        continue;
    }

    if ($ap['turm'] === 'tvk' && $ap['location'] === 'sonstiges') {
        $tvkSonstiges[] = $ap;
        continue;
    }
}

$initialTab = 'weh';

if (isset($_GET['tab']) && in_array($_GET['tab'], ['weh', 'tvk', 'far', 'reserve', 'veraltet'], true)) {
    $initialTab = $_GET['tab'];
} elseif (!empty($_SESSION['turm']) && in_array(strtolower((string)$_SESSION['turm']), ['weh', 'tvk'], true)) {
    $initialTab = strtolower((string)$_SESSION['turm']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title>Access Points</title>
    <style>
        .ap2-page {
            width: min(1500px, calc(100vw - 32px));
            margin: 24px auto 70px auto;
            color: #f3f3f3;
            font-family: Arial, sans-serif;
        }

        .ap2-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .ap2-title {
            margin: 0;
            font-size: 34px;
            line-height: 1.1;
        }

        .ap2-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .ap2-button,
        .ap2-tab,
        .ap2-card {
            border: 1px solid #3a3a3a;
            background: #232323;
            color: #f5f5f5;
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.08s ease;
        }

        .ap2-button {
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 700;
        }

        .ap2-button:hover,
        .ap2-tab:hover,
        .ap2-card:hover {
            border-color: #11a50d;
            background: #2b2b2b;
        }

        .ap2-button:active,
        .ap2-card:active {
            transform: translateY(1px);
        }

        .ap2-button-primary {
            background: #0f7e0b;
            border-color: #11a50d;
            color: #fff;
        }

        .ap2-button-primary:hover {
            background: #11a50d;
            color: #101010;
        }

        .ap2-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 22px 0;
            padding: 8px;
            border: 1px solid #303030;
            background: #1f1f1f;
            border-radius: 12px;
        }

        .ap2-tab {
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
        }

        .ap2-tab.ap2-active {
            background: #11a50d;
            border-color: #11a50d;
            color: #080808;
        }

        .ap2-panel {
            display: none;
        }

        .ap2-panel.ap2-active {
            display: block;
        }

        .ap2-section-title {
            margin: 26px 0 12px 0;
            text-align: center;
            font-size: 22px;
            color: #fff;
        }

        .ap2-grid-wrap {
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .ap2-grid {
            border-collapse: separate;
            border-spacing: 3px;
            margin: 0 auto;
            color: #fff;
        }

        .ap2-grid th {
            min-width: 36px;
            height: 22px;
            color: #fff;
            font-weight: 700;
            text-align: center;
            font-size: 14px;
        }

        .ap2-grid .ap2-floor-head {
            min-width: 28px;
            text-align: right;
            padding-right: 5px;
        }

        .ap2-grid .ap2-kamin-head {
            min-width: 48px;
        }

        .ap2-gap {
            min-width: 8px !important;
            width: 8px;
            background: transparent !important;
            border: 0 !important;
            cursor: default !important;
        }

        .ap2-cell {
            width: 40px;
            height: 22px;
            border: 1px solid #202020;
            text-align: center;
            vertical-align: middle;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: #050505;
            cursor: pointer;
            user-select: none;
        }

        .ap2-cell:hover {
            outline: 2px solid #11a50d;
            outline-offset: -2px;
            filter: brightness(1.16);
        }

        .ap2-empty {
            background: #050505;
        }

        .ap2-disabled {
            background: transparent;
            border-color: transparent;
            cursor: default;
        }

        .ap2-disabled:hover {
            outline: none;
            filter: none;
        }

        .ap2-status-green { background: darkgreen; color: #fff; }
        .ap2-status-red { background: #ff1744; color: #fff; }
        .ap2-status-yellow { background: yellow; color: #080808; }
        .ap2-status-blue { background: #155fc0; color: #fff; }
        .ap2-status-gray { background: #686868; color: #fff; }
        .ap2-status-orange { background: #b36b00; color: #fff; }

        .ap2-card-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin: 0 auto;
            max-width: 1100px;
        }

        .ap2-card {
            min-width: 170px;
            max-width: 280px;
            padding: 10px 12px;
            border-radius: 9px;
            text-align: left;
            font: inherit;
        }

        .ap2-card strong,
        .ap2-card span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ap2-card strong {
            font-size: 15px;
        }

        .ap2-card span {
            margin-top: 4px;
            font-size: 12px;
            opacity: 0.88;
        }

        .ap2-empty-list {
            color: #aaa;
            text-align: center;
            padding: 16px;
            border: 1px dashed #3a3a3a;
            border-radius: 9px;
            max-width: 520px;
            margin: 0 auto;
        }

        .ap2-legend {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin: 28px 0 0 0;
            color: #ddd;
            font-size: 13px;
        }

        .ap2-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .ap2-dot {
            width: 13px;
            height: 13px;
            border-radius: 3px;
            display: inline-block;
        }

        .ap2-modal-root.ap2-hidden {
            display: none;
        }

        .ap2-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.68);
            z-index: 9998;
        }

        .ap2-modal {
            position: fixed;
            z-index: 9999;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: min(760px, calc(100vw - 28px));
            max-height: calc(100vh - 34px);
            overflow-y: auto;
            background: #1f1f1f;
            color: #fff;
            border: 1px solid #3b3b3b;
            border-radius: 14px;
            box-shadow: 0 18px 60px rgba(0,0,0,0.55);
        }

        .ap2-search-modal {
            width: min(900px, calc(100vw - 28px));
        }

        .ap2-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid #333;
        }

        .ap2-modal-title {
            font-size: 20px;
            font-weight: 800;
        }

        .ap2-modal-close {
            border: 0;
            background: transparent;
            color: #fff;
            font-size: 26px;
            cursor: pointer;
            line-height: 1;
        }

        .ap2-modal-close:hover {
            color: #ff4d4d;
        }

        .ap2-form,
        .ap2-search-content {
            padding: 18px;
        }

        .ap2-form-grid {
            display: grid;
            grid-template-columns: 190px minmax(0, 1fr);
            gap: 12px 14px;
            align-items: center;
        }

        .ap2-form-row-hidden {
            display: none;
        }

        .ap2-form label {
            color: #d8d8d8;
            font-weight: 700;
        }

        .ap2-form input,
        .ap2-form select,
        .ap2-form textarea,
        .ap2-search-input {
            width: 100%;
            box-sizing: border-box;
            background: #2a2a2a;
            color: #fff;
            border: 1px solid #444;
            border-radius: 7px;
            padding: 9px 10px;
            font-size: 15px;
        }

        .ap2-form textarea {
            min-height: 84px;
            resize: vertical;
        }

        .ap2-readonly-box {
            min-height: 38px;
            display: flex;
            align-items: center;
            padding: 8px 10px;
            box-sizing: border-box;
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 7px;
            color: #eee;
        }

        .ap2-switch-wrap {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .ap2-switch-warning {
            display: none;
            width: 26px;
            height: 26px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #b36b00;
            color: #fff;
            font-weight: 900;
            cursor: help;
        }

        .ap2-switch-warning.ap2-visible {
            display: inline-flex;
        }

        .ap2-error {
            display: none;
            margin: 0 0 14px 0;
            padding: 10px 12px;
            border: 1px solid #7b1f1f;
            background: #361818;
            color: #ffd6d6;
            border-radius: 8px;
        }

        .ap2-error.ap2-visible {
            display: block;
        }

        .ap2-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .ap2-search-help {
            color: #aaa;
            font-size: 13px;
            margin: 8px 0 14px 0;
        }

        .ap2-search-results {
            display: grid;
            gap: 8px;
            max-height: min(58vh, 560px);
            overflow-y: auto;
            padding-right: 4px;
        }

        .ap2-search-row {
            width: 100%;
            display: grid;
            grid-template-columns: 1.3fr 1.4fr 1fr;
            gap: 10px;
            align-items: center;
            border: 1px solid #383838;
            background: #242424;
            color: #fff;
            text-align: left;
            border-radius: 9px;
            padding: 10px 12px;
            cursor: pointer;
            font: inherit;
        }

        .ap2-search-row:hover {
            border-color: #11a50d;
            background: #2d2d2d;
        }

        .ap2-search-main,
        .ap2-search-meta,
        .ap2-search-extra {
            min-width: 0;
        }

        .ap2-search-main strong,
        .ap2-search-main span,
        .ap2-search-meta span,
        .ap2-search-extra span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ap2-search-main strong {
            font-size: 15px;
        }

        .ap2-search-main span,
        .ap2-search-meta span,
        .ap2-search-extra span {
            font-size: 12px;
            color: #cfcfcf;
            margin-top: 3px;
        }

        .ap2-search-status {
            display: inline-block;
            width: fit-content;
            max-width: 100%;
            padding: 4px 7px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 700;
        }

        .ap2-search-message {
            color: #aaa;
            text-align: center;
            border: 1px dashed #3a3a3a;
            border-radius: 9px;
            padding: 18px;
        }

        @media (max-width: 760px) {
            .ap2-form-grid {
                grid-template-columns: 1fr;
            }

            .ap2-header {
                align-items: stretch;
            }

            .ap2-actions {
                width: 100%;
            }

            .ap2-button {
                flex: 1;
            }

            .ap2-search-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<?php load_menu(); ?>

<div class="ap2-page" id="ap2-page">
    <div class="ap2-header">
        <h1 class="ap2-title">Access Points</h1>
        <div class="ap2-actions">
            <button type="button" class="ap2-button" id="ap2-search-button">AP suchen</button>
            <button type="button" class="ap2-button ap2-button-primary" id="ap2-add-button">Neuer AP</button>
        </div>
    </div>

    <div class="ap2-tabs" role="tablist">
        <button type="button" class="ap2-tab <?= $initialTab === 'weh' ? 'ap2-active' : '' ?>" data-ap2-tab="weh"><?= ap2_h($turmLabels['weh']) ?></button>
        <button type="button" class="ap2-tab <?= $initialTab === 'tvk' ? 'ap2-active' : '' ?>" data-ap2-tab="tvk"><?= ap2_h($turmLabels['tvk']) ?></button>
        <button type="button" class="ap2-tab <?= $initialTab === 'far' ? 'ap2-active' : '' ?>" data-ap2-tab="far"><?= ap2_h($turmLabels['far']) ?></button>
        <button type="button" class="ap2-tab <?= $initialTab === 'reserve' ? 'ap2-active' : '' ?>" data-ap2-tab="reserve">Vorrat</button>
        <button type="button" class="ap2-tab <?= $initialTab === 'veraltet' ? 'ap2-active' : '' ?>" data-ap2-tab="veraltet">Veraltet/Verschollen</button>
    </div>

    <section class="ap2-panel <?= $initialTab === 'weh' ? 'ap2-active' : '' ?>" data-ap2-panel="weh">
        <?php ap2_render_grid('weh', $apsByKey); ?>
        <h2 class="ap2-section-title">Sonstiges</h2>
        <?php ap2_render_list($wehSonstiges, 'Keine sonstigen APs für WEH.'); ?>
    </section>

    <section class="ap2-panel <?= $initialTab === 'tvk' ? 'ap2-active' : '' ?>" data-ap2-panel="tvk">
        <?php ap2_render_grid('tvk', $apsByKey); ?>
        <h2 class="ap2-section-title">Sonstiges</h2>
        <?php ap2_render_list($tvkSonstiges, 'Keine sonstigen APs für TvK.'); ?>
    </section>

    <section class="ap2-panel <?= $initialTab === 'far' ? 'ap2-active' : '' ?>" data-ap2-panel="far">
        <h2 class="ap2-section-title"><?= ap2_h($turmLabels['far']) ?></h2>
        <?php ap2_render_list($farItems, 'Keine APs für FaRü.'); ?>
    </section>

    <section class="ap2-panel <?= $initialTab === 'reserve' ? 'ap2-active' : '' ?>" data-ap2-panel="reserve">
        <h2 class="ap2-section-title">Vorrat</h2>
        <?php ap2_render_list($reserveItems, 'Keine APs im Vorrat.'); ?>
    </section>

    <section class="ap2-panel <?= $initialTab === 'veraltet' ? 'ap2-active' : '' ?>" data-ap2-panel="veraltet">
        <h2 class="ap2-section-title">Veraltet/Verschollen</h2>
        <?php ap2_render_list($veraltetItems, 'Keine veralteten oder verschollenen APs.'); ?>
    </section>

    <div class="ap2-legend">
        <span class="ap2-legend-item"><span class="ap2-dot ap2-status-green"></span>In Betrieb online</span>
        <span class="ap2-legend-item"><span class="ap2-dot ap2-status-yellow"></span>Testbetrieb online</span>
        <span class="ap2-legend-item"><span class="ap2-dot ap2-status-blue"></span>Designiert / Testbetrieb nicht gefunden</span>
        <span class="ap2-legend-item"><span class="ap2-dot ap2-status-gray"></span>Reserve / nicht gefunden</span>
        <span class="ap2-legend-item"><span class="ap2-dot ap2-status-red"></span>Offline / Veraltet</span>
    </div>
</div>

<div class="ap2-modal-root ap2-hidden" id="ap2-search-modal-root">
    <div class="ap2-modal-backdrop" data-ap2-search-close="1"></div>
    <div class="ap2-modal ap2-search-modal">
        <div class="ap2-modal-head">
            <div class="ap2-modal-title">AP suchen</div>
            <button type="button" class="ap2-modal-close" data-ap2-search-close="1">×</button>
        </div>

        <div class="ap2-search-content">
            <input type="text" class="ap2-search-input" id="ap2-search-input" placeholder="Hostname, MAC, IP, Raum, Beschreibung, Produkt, Switch ...">
            <div class="ap2-search-help">Die Suche startet automatisch ab 2 Zeichen. MAC-Adressen funktionieren auch ohne Doppelpunkte.</div>
            <div class="ap2-search-results" id="ap2-search-results">
                <div class="ap2-search-message">Suchbegriff eingeben.</div>
            </div>
        </div>
    </div>
</div>

<div class="ap2-modal-root ap2-hidden" id="ap2-modal-root">
    <div class="ap2-modal-backdrop" data-ap2-close="1"></div>
    <div class="ap2-modal">
        <div class="ap2-modal-head">
            <div class="ap2-modal-title" id="ap2-modal-title">AP bearbeiten</div>
            <button type="button" class="ap2-modal-close" data-ap2-close="1">×</button>
        </div>

        <form class="ap2-form" id="ap2-form">
            <div class="ap2-error" id="ap2-error"></div>
            <input type="hidden" name="id" id="ap2-id">

            <div class="ap2-form-grid">
                <label for="ap2-status">Status</label>
                <select name="status" id="ap2-status" required></select>

                <label for="ap2-turm" data-row-for="turm">Turm</label>
                <select name="turm" id="ap2-turm" data-row-for="turm"></select>

                <label for="ap2-location" data-row-for="location">Location</label>
                <select name="location" id="ap2-location" data-row-for="location"></select>

                <label for="ap2-room" data-row-for="room">Room</label>
                <input type="text" name="room" id="ap2-room" data-row-for="room" inputmode="numeric" placeholder="z. B. 0101">

                <label for="ap2-kamin-floor" data-row-for="kamin_floor">Etage</label>
                <select name="kamin_floor" id="ap2-kamin-floor" data-row-for="kamin_floor"></select>

                <label for="ap2-hostname">Hostname</label>
                <input type="text" name="hostname" id="ap2-hostname" required>

                <label for="ap2-ip">IP</label>
                <input type="text" name="ip" id="ap2-ip">

                <label for="ap2-mac">MAC</label>
                <input type="text" name="mac" id="ap2-mac">

                <label for="ap2-beschreibung">Beschreibung</label>
                <textarea name="beschreibung" id="ap2-beschreibung"></textarea>

                <label for="ap2-produkt">Produkt</label>
                <input type="text" name="produkt" id="ap2-produkt">

                <label>Nagios</label>
                <div class="ap2-readonly-box" id="ap2-nagios-display"></div>

                <label for="ap2-parentswitch">Parent Switch</label>
                <div class="ap2-switch-wrap">
                    <select name="parentswitch" id="ap2-parentswitch"></select>
                    <span class="ap2-switch-warning" id="ap2-switch-warning" title="Switch passt nicht zum Turm!">!</span>
                </div>
            </div>

            <div class="ap2-modal-actions">
                <button type="button" class="ap2-button" data-ap2-close="1">Abbrechen</button>
                <button type="submit" class="ap2-button ap2-button-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
const AP2 = {
    aps: <?= json_encode($aps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    statuses: <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    locations: <?= json_encode($locationLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    turmLabels: <?= json_encode($turmLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    switches: <?= json_encode($switchesByTurm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    allSwitches: <?= json_encode($allSwitches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    nagiosError: <?= json_encode($nagiosError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
};

const ap2ById = new Map(AP2.aps.map(ap => [String(ap.id), ap]));
const activeStatuses = new Set(['in_betrieb', 'testbetrieb', 'designiert']);

const modalRoot = document.getElementById('ap2-modal-root');
const form = document.getElementById('ap2-form');
const errorBox = document.getElementById('ap2-error');
const modalTitle = document.getElementById('ap2-modal-title');

const searchModalRoot = document.getElementById('ap2-search-modal-root');
const searchInput = document.getElementById('ap2-search-input');
const searchResults = document.getElementById('ap2-search-results');
let searchTimer = null;
let searchController = null;

const fields = {
    id: document.getElementById('ap2-id'),
    status: document.getElementById('ap2-status'),
    turm: document.getElementById('ap2-turm'),
    location: document.getElementById('ap2-location'),
    room: document.getElementById('ap2-room'),
    kaminFloor: document.getElementById('ap2-kamin-floor'),
    hostname: document.getElementById('ap2-hostname'),
    ip: document.getElementById('ap2-ip'),
    mac: document.getElementById('ap2-mac'),
    beschreibung: document.getElementById('ap2-beschreibung'),
    produkt: document.getElementById('ap2-produkt'),
    nagiosDisplay: document.getElementById('ap2-nagios-display'),
    parentswitch: document.getElementById('ap2-parentswitch'),
    switchWarning: document.getElementById('ap2-switch-warning'),
};

let currentAp = null;

function ap2Option(value, label, selected = false) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    option.selected = selected;
    return option;
}

function ap2NormalizeMacInputValue(value) {
    const hex = String(value || '').toLowerCase().replace(/[^a-f0-9]/g, '');

    if (hex.length !== 12) {
        return value;
    }

    return hex.match(/.{1,2}/g).join(':');
}

function ap2FillStaticSelects() {
    fields.status.innerHTML = '';
    Object.entries(AP2.statuses).forEach(([value, label]) => {
        fields.status.appendChild(ap2Option(value, label));
    });

    fields.turm.innerHTML = '';
    fields.turm.appendChild(ap2Option('', 'Bitte wählen'));
    Object.entries(AP2.turmLabels).forEach(([value, label]) => {
        fields.turm.appendChild(ap2Option(value, label));
    });

    fields.location.innerHTML = '';
    fields.location.appendChild(ap2Option('', 'Bitte wählen'));
    Object.entries(AP2.locations).forEach(([value, label]) => {
        fields.location.appendChild(ap2Option(value, label));
    });
}

function ap2SetRowsVisible(rowName, visible) {
    document.querySelectorAll(`[data-row-for="${rowName}"]`).forEach(element => {
        element.classList.toggle('ap2-form-row-hidden', !visible);
    });
}

function ap2MaxFloor(turm) {
    return turm === 'tvk' ? 15 : 17;
}

function ap2PadRoom(room) {
    if (room === null || room === undefined || room === '') {
        return '';
    }

    return String(parseInt(room, 10)).padStart(4, '0');
}

function ap2KaminFloorFromRoom(room) {
    const value = parseInt(room, 10);

    if (!Number.isFinite(value) || value < 100 || value > 1700 || value % 100 !== 0) {
        return '';
    }

    return String(Math.floor(value / 100));
}

function ap2FillKaminFloors() {
    const turm = fields.turm.value;
    const oldValue = fields.kaminFloor.dataset.pendingValue
        || fields.kaminFloor.value
        || (currentAp ? String(currentAp.kamin_floor || '') : '');

    const maxFloor = ap2MaxFloor(turm);

    fields.kaminFloor.innerHTML = '';
    fields.kaminFloor.appendChild(ap2Option('', 'Bitte wählen'));

    for (let floor = 1; floor <= maxFloor; floor++) {
        fields.kaminFloor.appendChild(ap2Option(String(floor), `${floor}. Etage`, String(floor) === oldValue));
    }
}

function ap2NagiosRegistrationText(status) {
    return (status === 'in_betrieb' || status === 'testbetrieb') ? 'eingetragen' : 'ausgetragen';
}

function ap2NagiosLiveText(live) {
    if (AP2.nagiosError) {
        return AP2.nagiosError;
    }

    if (live === 'online') {
        return 'Online';
    }

    if (live === 'offline') {
        return 'Offline';
    }

    if (live === 'error') {
        return 'Nagios-Fehler';
    }

    return 'Nicht in Nagios gefunden';
}

function ap2StatusDefaultLive(status) {
    if (!currentAp) {
        return 'not_found';
    }

    return currentAp.nagiosLive || 'not_found';
}

function ap2UpdateNagiosDisplay() {
    const status = fields.status.value;
    const live = ap2StatusDefaultLive(status);

    fields.nagiosDisplay.textContent = `${ap2NagiosRegistrationText(status)} · Live: ${ap2NagiosLiveText(live)}`;
}

function ap2SwitchBelongsToTurm(value, turm) {
    if (!value) {
        return true;
    }

    return Boolean(AP2.switches[turm] && AP2.switches[turm][value]);
}

function ap2FillParentSwitches() {
    const turm = fields.turm.value;
    const currentValue = fields.parentswitch.value || (currentAp ? (currentAp.parentswitch || '') : '');

    fields.parentswitch.innerHTML = '';
    fields.parentswitch.appendChild(ap2Option('', 'Nicht gesetzt'));

    const allowed = AP2.switches[turm] || {};

    Object.entries(allowed).forEach(([value, label]) => {
        fields.parentswitch.appendChild(ap2Option(value, label, value === currentValue));
    });

    if (currentValue && !allowed[currentValue]) {
        const label = AP2.allSwitches[currentValue] || currentValue;
        fields.parentswitch.appendChild(ap2Option(currentValue, `${label} [passt nicht]`, true));
    }

    ap2UpdateSwitchWarning();
}

function ap2UpdateSwitchWarning() {
    const mismatch = fields.parentswitch.value
        && fields.turm.value
        && !ap2SwitchBelongsToTurm(fields.parentswitch.value, fields.turm.value);

    fields.switchWarning.classList.toggle('ap2-visible', Boolean(mismatch));
}

function ap2ApplyFieldCascade() {
    const status = fields.status.value;
    const turm = fields.turm.value;
    const location = fields.location.value;

    const needsTurm = activeStatuses.has(status);
    const needsLocation = needsTurm && (turm === 'weh' || turm === 'tvk');
    const needsRoom = needsLocation && location === 'bewohnerzimmer';
    const needsKamin = needsLocation && location === 'technikkamin';

    ap2SetRowsVisible('turm', needsTurm);
    ap2SetRowsVisible('location', needsLocation);
    ap2SetRowsVisible('room', needsRoom);
    ap2SetRowsVisible('kamin_floor', needsKamin);

    fields.turm.required = needsTurm;
    fields.location.required = needsLocation;
    fields.room.required = needsRoom;
    fields.kaminFloor.required = needsKamin;

    if (!needsTurm) {
        fields.turm.value = '';
    }

    if (!needsLocation) {
        fields.location.value = '';
    }

    ap2FillKaminFloors();
    ap2FillParentSwitches();
    ap2UpdateNagiosDisplay();
}

function ap2ShowError(message) {
    errorBox.textContent = message || '';
    errorBox.classList.toggle('ap2-visible', Boolean(message));
}

function ap2OpenModal(ap = null, prefill = {}) {
    currentAp = ap;
    ap2ShowError('');
    form.reset();

    const isEdit = Boolean(ap);
    modalTitle.textContent = isEdit ? 'AP bearbeiten' : 'Neuer AP';

    fields.id.value = isEdit ? ap.id : '';
    fields.status.value = ap?.status || prefill.status || 'reserve';
    fields.turm.value = ap?.turm || prefill.turm || '';
    fields.location.value = ap?.location || prefill.location || '';
    fields.room.value = ap?.location === 'bewohnerzimmer' ? ap2PadRoom(ap.room) : (prefill.room || '');

    const pendingKaminFloor = ap?.location === 'technikkamin'
        ? String(ap.kamin_floor || ap2KaminFloorFromRoom(ap.room))
        : (prefill.kamin_floor ? String(prefill.kamin_floor) : '');

    fields.kaminFloor.dataset.pendingValue = pendingKaminFloor;
    fields.kaminFloor.value = pendingKaminFloor;
    fields.hostname.value = ap?.hostname || '';
    fields.ip.value = ap?.ip || '';
    fields.mac.value = ap?.mac || '';
    fields.beschreibung.value = ap?.beschreibung || '';
    fields.produkt.value = ap?.produkt || '';
    fields.parentswitch.value = ap?.parentswitch || '';

    ap2ApplyFieldCascade();

    if (pendingKaminFloor) {
        fields.kaminFloor.value = pendingKaminFloor;
    }

    delete fields.kaminFloor.dataset.pendingValue;

    modalRoot.classList.remove('ap2-hidden');
    fields.status.focus();
}

function ap2CloseModal() {
    modalRoot.classList.add('ap2-hidden');
    currentAp = null;
}

function ap2OpenSearchModal() {
    searchInput.value = '';
    ap2RenderSearchMessage('Suchbegriff eingeben.');
    searchModalRoot.classList.remove('ap2-hidden');

    window.setTimeout(() => {
        searchInput.focus();
    }, 30);
}

function ap2CloseSearchModal() {
    searchModalRoot.classList.add('ap2-hidden');

    if (searchController) {
        searchController.abort();
        searchController = null;
    }
}

function ap2RenderSearchMessage(message) {
    searchResults.innerHTML = '';
    const box = document.createElement('div');
    box.className = 'ap2-search-message';
    box.textContent = message;
    searchResults.appendChild(box);
}

function ap2SearchLabelForRoom(row) {
    if (row.room === null || row.room === undefined || row.room === '') {
        return '—';
    }

    if (row.location === 'technikkamin') {
        const floor = ap2KaminFloorFromRoom(row.room);
        return floor ? `Kamin ${floor}. Etage` : String(row.room);
    }

    return ap2PadRoom(row.room);
}

function ap2RenderSearchResults(rows) {
    searchResults.innerHTML = '';

    if (!rows.length) {
        ap2RenderSearchMessage('Keine Treffer.');
        return;
    }

    rows.forEach(row => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ap2-search-row';
        button.dataset.ap2SearchOpen = String(row.id);

        const main = document.createElement('div');
        main.className = 'ap2-search-main';

        const title = document.createElement('strong');
        title.textContent = row.hostname || `AP #${row.id}`;

        const subtitle = document.createElement('span');
        subtitle.textContent = [
            row.mac ? `MAC: ${row.mac}` : 'MAC: —',
            row.ip ? `IP: ${row.ip}` : 'IP: —',
        ].join(' · ');

        main.appendChild(title);
        main.appendChild(subtitle);

        const meta = document.createElement('div');
        meta.className = 'ap2-search-meta';

        const statusBadge = document.createElement('span');
        statusBadge.className = 'ap2-search-status';
        statusBadge.textContent = AP2.statuses[row.status] || row.status || '—';

        const statusClass = row.status === 'in_betrieb'
            ? 'ap2-status-green'
            : row.status === 'testbetrieb'
                ? 'ap2-status-yellow'
                : row.status === 'designiert'
                    ? 'ap2-status-blue'
                    : row.status === 'veraltet_verschollen'
                        ? 'ap2-status-red'
                        : 'ap2-status-gray';

        statusBadge.classList.add(statusClass);

        const loc = document.createElement('span');
        const turm = row.turm ? (AP2.turmLabels[row.turm] || row.turm) : '—';
        const location = row.location ? (AP2.locations[row.location] || row.location) : '—';
        loc.textContent = `${turm} · ${location} · Raum: ${ap2SearchLabelForRoom(row)}`;

        meta.appendChild(statusBadge);
        meta.appendChild(loc);

        const extra = document.createElement('div');
        extra.className = 'ap2-search-extra';

        const beschreibung = document.createElement('span');
        beschreibung.textContent = row.beschreibung ? `Beschreibung: ${row.beschreibung}` : 'Beschreibung: —';

        const produkt = document.createElement('span');
        produkt.textContent = row.produkt ? `Produkt: ${row.produkt}` : (row.parentswitch ? `Switch: ${row.parentswitch}` : 'Produkt: —');

        extra.appendChild(beschreibung);
        extra.appendChild(produkt);

        button.appendChild(main);
        button.appendChild(meta);
        button.appendChild(extra);

        searchResults.appendChild(button);
    });
}

async function ap2RunSearch() {
    const q = searchInput.value.trim();

    if (q.length < 2) {
        ap2RenderSearchMessage('Mindestens 2 Zeichen eingeben.');
        return;
    }

    if (searchController) {
        searchController.abort();
    }

    searchController = new AbortController();
    ap2RenderSearchMessage('Suche läuft ...');

    try {
        const response = await fetch(`APs2.php?ap2api=search&q=${encodeURIComponent(q)}`, {
            method: 'GET',
            credentials: 'same-origin',
            signal: searchController.signal,
        });

        const result = await response.json();

        if (!response.ok || !result.ok) {
            ap2RenderSearchMessage(result.error || 'Suche fehlgeschlagen.');
            return;
        }

        result.results.forEach(row => {
            const existing = ap2ById.get(String(row.id));
            if (existing) {
                Object.assign(existing, row);
            } else {
                ap2ById.set(String(row.id), row);
            }
        });

        ap2RenderSearchResults(result.results || []);
    } catch (error) {
        if (error.name !== 'AbortError') {
            ap2RenderSearchMessage('Suche fehlgeschlagen.');
        }
    }
}

function ap2SwitchTab(tab) {
    document.querySelectorAll('[data-ap2-tab]').forEach(button => {
        button.classList.toggle('ap2-active', button.dataset.ap2Tab === tab);
    });

    document.querySelectorAll('[data-ap2-panel]').forEach(panel => {
        panel.classList.toggle('ap2-active', panel.dataset.ap2Panel === tab);
    });

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url.toString());
}

function ap2CurrentTabDefaultStatus() {
    const activeTab = document.querySelector('.ap2-tab.ap2-active')?.dataset.ap2Tab || 'weh';

    if (activeTab === 'reserve') {
        return { status: 'reserve' };
    }

    if (activeTab === 'veraltet') {
        return { status: 'veraltet_verschollen' };
    }

    if (activeTab === 'far') {
        return { status: 'in_betrieb', turm: 'far' };
    }

    if (activeTab === 'tvk') {
        return { status: 'in_betrieb', turm: 'tvk', location: 'sonstiges' };
    }

    return { status: 'in_betrieb', turm: 'weh', location: 'sonstiges' };
}

ap2FillStaticSelects();

document.addEventListener('click', event => {
    const tabButton = event.target.closest('[data-ap2-tab]');

    if (tabButton) {
        ap2SwitchTab(tabButton.dataset.ap2Tab);
        return;
    }

    const openButton = event.target.closest('[data-ap2-open]');

    if (openButton) {
        const ap = ap2ById.get(String(openButton.dataset.ap2Open));

        if (ap) {
            ap2OpenModal(ap);
        }

        return;
    }

    const addCell = event.target.closest('[data-ap2-add]');

    if (addCell) {
        let prefill = {};

        try {
            prefill = JSON.parse(addCell.dataset.ap2Add || '{}');
        } catch (e) {
            prefill = {};
        }

        ap2OpenModal(null, prefill);
        return;
    }

    const searchOpenButton = event.target.closest('[data-ap2-search-open]');

    if (searchOpenButton) {
        const ap = ap2ById.get(String(searchOpenButton.dataset.ap2SearchOpen));

        if (ap) {
            ap2CloseSearchModal();
            ap2OpenModal(ap);
        }

        return;
    }

    if (event.target.closest('[data-ap2-close]')) {
        ap2CloseModal();
        return;
    }

    if (event.target.closest('[data-ap2-search-close]')) {
        ap2CloseSearchModal();
    }
});

fields.status.addEventListener('change', ap2ApplyFieldCascade);
fields.turm.addEventListener('change', ap2ApplyFieldCascade);
fields.location.addEventListener('change', ap2ApplyFieldCascade);
fields.parentswitch.addEventListener('change', ap2UpdateSwitchWarning);
fields.mac.addEventListener('blur', () => {
    fields.mac.value = ap2NormalizeMacInputValue(fields.mac.value);
});

document.getElementById('ap2-add-button').addEventListener('click', () => {
    ap2OpenModal(null, ap2CurrentTabDefaultStatus());
});

document.getElementById('ap2-search-button').addEventListener('click', () => {
    ap2OpenSearchModal();
});

searchInput.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(ap2RunSearch, 180);
});

form.addEventListener('submit', async event => {
    event.preventDefault();
    ap2ShowError('');

    const formData = new FormData(form);

    try {
        const response = await fetch('APs2.php?ap2api=save', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });

        const result = await response.json();

        if (!response.ok || !result.ok) {
            ap2ShowError(result.error || 'Speichern fehlgeschlagen.');
            return;
        }

        window.location.reload();
    } catch (error) {
        ap2ShowError('Speichern fehlgeschlagen.');
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        if (!modalRoot.classList.contains('ap2-hidden')) {
            ap2CloseModal();
            return;
        }

        if (!searchModalRoot.classList.contains('ap2-hidden')) {
            ap2CloseSearchModal();
        }
    }
});
</script>
</html>