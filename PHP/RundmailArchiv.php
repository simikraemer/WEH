<?php
session_start();
// template.php gibt bereits HTML aus; Header müssen trotzdem vor der Ausgabe erfolgen.
ob_start();
require __DIR__ . '/template.php';
$templateOutput = ob_get_clean();
ob_start();
$authenticated = auth($conn);
ob_end_clean();
if (!$authenticated || empty($_SESSION['valid'])) {
    header('Location: denied.php');
    exit;
}
header('Cache-Control: private, no-store');

function rundmailArchivUrl(int $year, int $mail = 0): string {
    $query = ['year' => $year];
    if ($mail > 0) $query['selected_mail'] = $mail;
    return 'RundmailArchiv.php' . ($query ? '?' . http_build_query($query) : '');
}

function displayRundmailArchiv($conn) {
    $addresses = rundmailOverviewAccess($conn);
    if (!$addresses) return;

    $h = 'rundmailOverviewEscape';
    $timezone = new DateTimeZone('Europe/Berlin');
    $formatDate = static function ($timestamp) use ($timezone): string {
        return (new DateTimeImmutable('@' . (int)$timestamp))->setTimezone($timezone)->format('d.m.Y H:i');
    };
    $from = ' FROM rundmails r LEFT JOIN users u ON r.uid = u.uid';
    $where = ' WHERE r.address IN (' . implode(',', array_fill(0, count($addresses), '?')) . ')';
    $types = str_repeat('s', count($addresses));
    $params = $addresses;
    $years = [];
    // Jahre ausschließlich aus sichtbaren Mails ermitteln (Zeitzone Europe/Berlin).
    $yearRows = rundmailOverviewQuery($conn, 'SELECT DISTINCT r.tstamp' . $from . $where, $types, $params);
    foreach ($yearRows as $row) {
        $y = (int)(new DateTimeImmutable('@' . (int)$row['tstamp']))->setTimezone($timezone)->format('Y');
        $years[$y] = $y;
    }
    if (!$years) {
        $y = (int)(new DateTimeImmutable('now', $timezone))->format('Y');
        $years[$y] = $y;
    }
    rsort($years, SORT_NUMERIC);
    $requestedYear = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
    $year = in_array($requestedYear, $years, true) ? $requestedYear : $years[0];
    $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $timezone);
    $where .= ' AND r.tstamp >= ? AND r.tstamp < ?';
    $types .= 'ii';
    $params[] = $start->getTimestamp();
    $params[] = $start->modify('+1 year')->getTimestamp();

    // Keine historischen Absendergruppen vorhanden. Keine aktuellen Gruppen nachladen.
    $rows = rundmailOverviewQuery($conn, 'SELECT r.id, u.name, r.subject, r.tstamp, r.address' . $from . $where . ' ORDER BY r.tstamp DESC, r.id DESC', $types, $params);
    $months = [];
    foreach ($rows as $row) {
        $month = (int)(new DateTimeImmutable('@' . (int)$row['tstamp']))->setTimezone($timezone)->format('n');
        $months[$month][] = $row;
    }
    $monthNames = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $accent = strtolower((string)($_SESSION['turm'] ?? '')) === 'tvk' ? '#FFA500' : '#11a50d';
    ?>
    <style>
    .rm-overview { width: 94%; max-width: 1500px; margin: 0 auto 60px; color: #fff; --rm-accent: <?= $h($accent) ?>; }
    .rm-overview * { box-sizing: border-box; }
    .rm-overview .rm-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; margin:0 auto 18px; }
    .rm-overview .rm-heading { margin:0; font-size:30px; }
    .rm-overview .rm-filters { display:flex; gap:10px; align-items:center; margin:0; }
    .rm-overview .rm-filter { display:flex; align-items:center; gap:10px; min-width:0; }
    .rm-overview .rm-filter label { color:#ddd; font-size:14px; text-align:left; }
    .rm-overview .rm-filter select { max-width:100%; min-height:42px; min-width:120px; padding:6px 14px; font-size:19px; color:#fff; background:#303030; border:1px solid #777; border-radius:5px; }
    .rm-overview .rm-action { display:inline-flex; justify-content:center; align-items:center; border:1px solid #777; border-radius:5px; padding:9px 17px; background:#f2f2f2; color:#111; cursor:pointer; text-decoration:none; font-size:16px; font-weight:bold; line-height:1.2; }
    .rm-overview .rm-action:hover, .rm-overview .rm-action:focus-visible { background:var(--rm-accent); color:#111; }
    .rm-overview .rm-table-wrap { overflow-x:auto; }
    .rm-overview.rm-archive .rm-table-wrap { border:1px solid #555; border-radius:8px; }
    .rm-overview .rm-table { margin:0 auto; text-align:center; }
    .rm-overview.rm-archive .rm-table { width:100%; table-layout:fixed; border-collapse:collapse; font-size:16px; min-width:650px; }
    .rm-overview .rm-month { margin:0 0 26px; }
    .rm-overview .rm-month-heading { display:flex; align-items:baseline; gap:12px; margin:0 0 10px; padding:0 0 8px; border-bottom:1px solid #505050; font-size:23px; text-align:left; }
    .rm-overview .rm-month-heading::before { content:''; align-self:stretch; width:4px; background:var(--rm-accent); border-radius:2px; }
    .rm-overview .rm-month-heading span { font-size:14px; font-weight:normal; color:#aaa; }
    .rm-overview .rm-sender { overflow-wrap:anywhere; }
    .rm-overview .rm-back { color:#ccc; text-decoration:none; font-size:14px; }
    .rm-overview .rm-back:hover { color:var(--rm-accent); }
    .rm-overview .rm-heading-block { display:flex; flex-direction:column; gap:6px; }
    .rm-overview .rm-toolbar { padding-bottom:16px; border-bottom:1px solid #444; margin-bottom:24px; }
    .rm-overview.rm-archive .rm-table th { background:#262626; color:#eee; padding:12px; }
    .rm-overview.rm-archive .rm-table td { padding:10px 12px; border-bottom:1px solid #414141; }
    .rm-overview .rm-row { cursor:pointer; }
    .rm-overview .rm-row:hover td { filter:brightness(1.2); }
    .rm-overview .rm-date, .rm-overview .rm-building { white-space:nowrap; }
    .rm-overview .rm-subject-link { color:inherit; text-decoration:none; display:block; overflow-wrap:anywhere; }
    .rm-overview .rm-subject-link:focus-visible { outline:2px solid #fff; outline-offset:2px; }
    .rm-overview.rm-archive .rm-subject { text-align:left; }
    .rm-overview .rm-empty { color:#ccc; text-align:center; padding:25px; }
    .rm-overview .rm-modal { color:#fff; background:#202020; border:1px solid #777; border-radius:10px; width:min(900px, 94vw); max-height:88vh; overflow:auto; padding:24px; position:fixed; inset:0; margin:auto; z-index:10001; box-shadow:0 15px 60px #0008; }
    .rm-overview .rm-modal::backdrop { background:rgba(0,0,0,.72); }
    .rm-overview .rm-modal h2 { margin:0 0 14px; padding-right:44px; font-size:clamp(22px, 3vw, 34px); overflow-wrap:anywhere; }
    .rm-overview .rm-modal-close { position:absolute; right:15px; top:15px; }
    .rm-overview .rm-meta { color:#ccc; line-height:1.6; text-align:left; }
    .rm-overview .rm-message { margin-top:20px; background:#303030; border-radius:7px; padding:16px; text-align:left; white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.5; }
    @media(max-width:700px) { .rm-overview { width:95%; } .rm-overview .rm-toolbar { justify-content:center; text-align:center; } .rm-overview .rm-heading-block { flex-basis:100%; } .rm-overview .rm-filters { justify-content:center; } .rm-overview .rm-month-heading { font-size:20px; } .rm-overview .rm-modal { padding:16px; } }
    </style>
    <section class="rm-overview rm-archive">
        <div class="rm-toolbar">
            <div class="rm-heading-block">
                <a class="rm-back" href="Rundmail.php">← Zur Rundmailübersicht</a>
                <h1 class="rm-heading">Rundmail-Archiv</h1>
            </div>
            <form class="rm-filters" method="get" action="RundmailArchiv.php">
                <div class="rm-filter">
                    <label for="rm-year">Jahr</label>
                    <select id="rm-year" name="year" onchange="this.form.submit()">
                        <?php foreach ($years as $optionYear): ?>
                        <option value="<?= $optionYear ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <noscript><button class="rm-action" type="submit">Anzeigen</button></noscript>
            </form>
        </div>
        <?php if (!$months): ?><p class="rm-empty">Keine Rundmails in diesem Jahr vorhanden.</p><?php endif; ?>
        <?php foreach ($months as $month => $monthRows): ?>
        <section class="rm-month" aria-labelledby="rm-month-<?= $month ?>">
        <h2 class="rm-month-heading" id="rm-month-<?= $month ?>"><?= $h($monthNames[$month]) ?> <?= $year ?> <span><?= count($monthRows) ?> <?= count($monthRows) === 1 ? 'Rundmail' : 'Rundmails' ?></span></h2>
        <div class="rm-table-wrap">
        <table class="rm-table">
            <colgroup><col style="width:10%"><col style="width:23%"><col style="width:49%"><col style="width:18%"></colgroup>
            <thead><tr><th>Turm</th><th>Name</th><th>Betreff</th><th>Datum &amp; Uhrzeit</th></tr></thead>
            <tbody>
            <?php foreach ($monthRows as $row):
                $address = (string)$row['address'];
                $building = str_starts_with($address, 'weh-') ? 'WEH' : (str_starts_with($address, 'tvk-') ? 'TvK' : 'Türme');
                $isFiji = in_array($row['name'], ['Simon Krämer', 'Simon Fiji Krämer'], true);
                $name = $isFiji ? '<span style="font-family:Consolas,monospace;font-weight:bold">FIJI</span>' : $h($row['name'] ?? 'Unbekannter Absender');
                $subject = (string)$row['subject'];
                if (str_contains($address, 'community')) { $color = '#333333'; }
                elseif (str_contains($address, 'important')) { $color = '#1f531e'; }
                elseif (str_contains($address, 'ags')) { $color = '#000099'; }
                else { $color = '#0b7309'; }
                $style = 'background-color:' . $color . ';font-size:' . 16 . 'px;';
                $url = rundmailArchivUrl($year, (int)$row['id']);
            ?>
                <tr class="rm-row" data-mail-url="<?= $h($url) ?>">
                    <td class="rm-building" style="<?= $style ?>"><?= $h($building) ?></td>
                    <td class="rm-sender" style="<?= $style ?>"><?= $name ?></td>
                    <td class="rm-subject" style="<?= $style ?>"><a class="rm-subject-link" href="<?= $h($url) ?>"><?= $h($subject) ?></a></td>
                    <td class="rm-date" style="<?= $style ?>"><?= $h($formatDate($row['tstamp'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </section>
        <?php endforeach; ?>
        <?php
        // Keine POST-Inhalte übernehmen. Detailabfrage unter identischen Berechtigungen und Filtern.
        $requestedMail = $_GET['selected_mail'] ?? null;
        if ($requestedMail !== null):
            $mailId = filter_var($requestedMail, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $detail = [];
            if ($mailId !== false) {
                $detailParams = [...$params, $mailId];
                $detail = rundmailOverviewQuery($conn, 'SELECT r.id, u.name, u.turm, u.room, r.subject, r.nachricht, r.tstamp, r.address' . $from . $where . ' AND r.id = ?', $types . 'i', $detailParams);
            }
            $closeUrl = rundmailArchivUrl($year);
            ?>
            <dialog class="rm-modal" id="rm-mail-dialog" aria-labelledby="rm-mail-title">
                <a class="rm-action rm-modal-close" href="<?= $h($closeUrl) ?>" aria-label="Schließen">X</a>
                <?php if ($detail):
                    $mail = $detail[0];
                    $sender = in_array($mail['name'], ['Simon Krämer', 'Simon Fiji Krämer'], true) ? 'Fiji' : ($mail['name'] ?? 'Unbekannter Absender');
                    $room = (string)$mail['room'] === '18992' ? 'Hausmeister' : str_pad((string)$mail['room'], 4, '0', STR_PAD_LEFT);
                    $turm = strtolower((string)$mail['turm']) === 'tvk' ? 'TvK' : strtoupper((string)$mail['turm']);
                ?>
                    <h2 id="rm-mail-title"><?= $h($mail['subject']) ?></h2>
                    <div class="rm-meta"><?= $h($sender) ?><br><?php if ($mail['turm'] !== null): ?><?= $h($turm . ' - ' . $room) ?><br><?php endif; ?><?= $h($formatDate($mail['tstamp'])) ?><br>Verteiler: <?= $h($mail['address']) ?></div>
                    <div class="rm-message"><?= $h($mail['nachricht']) ?></div>
                <?php else: ?>
                    <h2 id="rm-mail-title">Rundmail nicht verfügbar</h2>
                    <p>Diese Rundmail ist nicht vorhanden oder für dich nicht sichtbar.</p>
                <?php endif; ?>
            </dialog>
            <noscript><style>#rm-mail-dialog { display:block; position:relative; inset:auto; margin:20px auto; }</style></noscript>
            <script>
            (() => {
                const d = document.getElementById('rm-mail-dialog');
                const close = () => { location.href = d.querySelector('.rm-modal-close').href; };
                if (typeof d.showModal === 'function') d.showModal();
                else d.setAttribute('open', '');

                const isBackdrop = event => {
                    const rect = d.getBoundingClientRect();
                    return event.target === d && (
                        event.clientX < rect.left || event.clientX >= rect.right ||
                        event.clientY < rect.top || event.clientY >= rect.bottom
                    );
                };
                let backdropPressed = false;
                d.addEventListener('pointerdown', event => { backdropPressed = isBackdrop(event); });
                d.addEventListener('pointercancel', () => { backdropPressed = false; });
                d.addEventListener('click', event => {
                    if (backdropPressed && isBackdrop(event)) close();
                    backdropPressed = false;
                });
                d.addEventListener('cancel', event => { event.preventDefault(); close(); });
            })();
            </script>
        <?php endif; ?>
    </section>
    <script>
    document.querySelectorAll('.rm-overview .rm-row').forEach(row => {
        row.addEventListener('click', event => {
            if (event.target.closest('a') || window.getSelection().toString()) return;
            location.href = row.dataset.mailUrl;
        });
    });
    </script>
    <?php
}


?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rundmail-Archiv</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>
<body>
<?php
 echo $templateOutput;
 load_menu();
 displayRundmailArchiv($conn);
 $conn->close();
?>
</body>
</html>
