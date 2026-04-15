<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require('conn.php');
mysqli_set_charset($conn, "utf8mb4");

const AGMAIL_IGNORED_GROUP_IDS = [1, 16, 19, 20, 22, 24, 27];

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bindDynamicParams(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types === '' || empty($params)) {
        return true;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function safeHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function safeAttachmentFilename(string $filename): string
{
    $filename = basename($filename);
    $filename = preg_replace('/[^\pL\pN\.\-_ ]/u', '_', $filename);
    $filename = trim((string)$filename);

    return $filename !== '' ? $filename : 'attachment';
}

function encodeMailSubject(string $subject): string
{
    $subject = safeHeaderValue($subject);

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    return $subject;
}

function getUsersColumnConfig(mysqli $conn): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $existing = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $existing[strtolower((string)$row['Field'])] = true;
        }
        mysqli_free_result($result);
    }

    $pick = static function (array $candidates) use ($existing): ?string {
        foreach ($candidates as $candidate) {
            if (isset($existing[strtolower($candidate)])) {
                return $candidate;
            }
        }
        return null;
    };

    $config = [
        'uid'         => $pick(['uid']),
        'username'    => $pick(['username']),
        'name'        => $pick(['name']),
        'firstname'   => $pick(['firstname', 'vorname', 'first_name']),
        'lastname'    => $pick(['lastname', 'nachname', 'last_name']),
        'room'        => $pick(['room', 'raum', 'zimmer']),
        'oldroom'     => $pick(['oldroom', 'old_room']),
        'turm'        => $pick(['turm']),
        'mail_active' => $pick(['mailisactive', 'mailactive', 'mail_active']),
        'pid'         => $pick(['pid']),
    ];

    return $config;
}

function getResidentStatusLabel(int $pid): string
{
    if ($pid === 12) {
        return 'Untervermieter';
    }
    if ($pid === 13) {
        return 'Ausgezogen';
    }
    if ($pid === 14) {
        return 'Abgemeldet';
    }

    return '';
}

function buildUserDisplayName(array $row): string
{
    $name      = trim((string)($row['name'] ?? ''));
    $firstname = trim((string)($row['firstname'] ?? ''));
    $lastname  = trim((string)($row['lastname'] ?? ''));
    $username  = trim((string)($row['username'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $fullName = trim($firstname . ' ' . $lastname);
    if ($fullName !== '') {
        return $fullName;
    }

    if ($username !== '') {
        return $username;
    }

    return 'Unbekannt';
}

function buildUserRoom(array $row): string
{
    $pid = (int)($row['pid'] ?? 0);

    $roomRaw = $row['room'] ?? '';
    $room = trim((string)$roomRaw);

    $oldRoomRaw = $row['oldroom'] ?? '';
    $oldRoom = trim((string)$oldRoomRaw);

    $roomIsZero = ($room === '0' || $room === '' || $roomRaw === 0 || $roomRaw === '0');

    if ($roomIsZero && in_array($pid, [12, 13, 14], true) && $oldRoom !== '' && $oldRoom !== '0') {
        return $oldRoom;
    }

    if ($room !== '' && $room !== '0') {
        return $room;
    }

    if ($oldRoom !== '' && $oldRoom !== '0' && in_array($pid, [12, 13, 14], true)) {
        return $oldRoom;
    }

    return '-';
}

function normalizeUserRow(array $row, string $extraStatus = ''): array
{
    $pid = (int)($row['pid'] ?? 0);

    return [
        'uid'             => (int)($row['uid'] ?? 0),
        'name'            => buildUserDisplayName($row),
        'room'            => buildUserRoom($row),
        'turm'            => strtolower(trim((string)($row['turm'] ?? ''))),
        'pid'             => $pid,
        'resident_status' => getResidentStatusLabel($pid),
        'plaetze'         => trim((string)($row['plaetze'] ?? '')),
        'extra_status'    => $extraStatus,
    ];
}

function fetchAllowedSenderGroups(mysqli $conn): array
{
    $ignoredIds = implode(',', array_map('intval', AGMAIL_IGNORED_GROUP_IDS));

    $sql = "
        SELECT
            `id`,
            `name`,
            `mail`,
            `turm`,
            `session`
        FROM `groups`
        WHERE `active` = 1
          AND `session` IS NOT NULL
          AND `session` <> ''
          AND `mail` IS NOT NULL
          AND `mail` <> ''
          AND `id` NOT IN ($ignoredIds)
        ORDER BY
            CASE `turm` WHEN 'weh' THEN 0 WHEN 'tvk' THEN 1 ELSE 2 END,
            COALESCE(`prio`, 999999) ASC,
            `name` ASC
    ";

    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        return [];
    }

    $groups = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $sessionName = (string)($row['session'] ?? '');
        if ($sessionName === '' || empty($_SESSION[$sessionName])) {
            continue;
        }

        $groups[] = [
            'id'      => (int)$row['id'],
            'name'    => trim((string)$row['name']),
            'mail'    => trim((string)$row['mail']),
            'turm'    => strtolower(trim((string)$row['turm'])),
            'session' => $sessionName,
        ];
    }

    mysqli_free_result($result);

    return $groups;
}

function findSenderGroupById(array $groups, int $groupId): ?array
{
    foreach ($groups as $group) {
        if ((int)$group['id'] === $groupId) {
            return $group;
        }
    }

    return null;
}

function buildUserSelectParts(array $cfg): array
{
    return [
        "users.`{$cfg['uid']}` AS uid",
        $cfg['username']  ? "users.`{$cfg['username']}` AS username"   : "'' AS username",
        $cfg['name']      ? "users.`{$cfg['name']}` AS name"           : "'' AS name",
        $cfg['firstname'] ? "users.`{$cfg['firstname']}` AS firstname" : "'' AS firstname",
        $cfg['lastname']  ? "users.`{$cfg['lastname']}` AS lastname"   : "'' AS lastname",
        $cfg['room']      ? "users.`{$cfg['room']}` AS room"           : "'' AS room",
        $cfg['oldroom']   ? "users.`{$cfg['oldroom']}` AS oldroom"     : "'' AS oldroom",
        "users.`{$cfg['turm']}` AS turm",
        $cfg['pid']       ? "users.`{$cfg['pid']}` AS pid"             : "0 AS pid",
    ];
}

function searchUsers(mysqli $conn, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid']) ||
        empty($cfg['mail_active'])
    ) {
        return [];
    }

    $selectParts = buildUserSelectParts($cfg);

    $like = '%' . $query . '%';
    $conditions = [];
    $params = [];
    $types = '';

    $addCondition = static function (string $sqlPart) use (&$conditions, &$params, &$types, $like): void {
        $conditions[] = $sqlPart;
        $params[] = $like;
        $types .= 's';
    };

    $addCondition("CAST(users.`{$cfg['uid']}` AS CHAR) LIKE ?");

    if (!empty($cfg['username'])) {
        $addCondition("users.`{$cfg['username']}` LIKE ?");
        $addCondition("CONCAT(users.`{$cfg['username']}`, '@', users.`{$cfg['turm']}`, '.rwth-aachen.de') LIKE ?");
    }

    if (!empty($cfg['name'])) {
        $addCondition("users.`{$cfg['name']}` LIKE ?");
    }

    if (!empty($cfg['firstname']) && !empty($cfg['lastname'])) {
        $addCondition("CONCAT_WS(' ', users.`{$cfg['firstname']}`, users.`{$cfg['lastname']}`) LIKE ?");
    }

    if (!empty($cfg['firstname'])) {
        $addCondition("users.`{$cfg['firstname']}` LIKE ?");
    }

    if (!empty($cfg['lastname'])) {
        $addCondition("users.`{$cfg['lastname']}` LIKE ?");
    }

    if (!empty($cfg['room'])) {
        $addCondition("CAST(users.`{$cfg['room']}` AS CHAR) LIKE ?");
    }

    if (!empty($cfg['oldroom'])) {
        $addCondition("CAST(users.`{$cfg['oldroom']}` AS CHAR) LIKE ?");
    }

    $addCondition("users.`{$cfg['turm']}` LIKE ?");

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM users
        WHERE users.`{$cfg['turm']}` IN ('weh', 'tvk')
          AND users.`{$cfg['mail_active']}` = 1
          AND users.`{$cfg['pid']}` IN (11, 12, 13, 14)
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY
            CASE users.`{$cfg['turm']}` WHEN 'weh' THEN 0 WHEN 'tvk' THEN 1 ELSE 2 END,
            CASE
                WHEN users.`{$cfg['room']}` = 0 AND users.`{$cfg['pid']}` IN (12, 13, 14) THEN users.`{$cfg['oldroom']}`
                ELSE users.`{$cfg['room']}`
            END ASC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    if (!bindDynamicParams($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user = normalizeUserRow($row);
        if ($user['uid'] > 0) {
            $users[] = $user;
        }
    }

    mysqli_stmt_close($stmt);

    return $users;
}

function fetchBikeModeUsers(mysqli $conn, string $turm): array
{
    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid'])
    ) {
        return [
            'spot' => [],
            'queue' => [],
        ];
    }

    $selectParts = buildUserSelectParts($cfg);
    $selectParts[] = "fahrrad.starttime AS bike_starttime";
    $selectParts[] = "fahrrad.platz AS bike_platz";

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM users
        INNER JOIN fahrrad
            ON users.`{$cfg['uid']}` = fahrrad.uid
        WHERE (fahrrad.endtime IS NULL OR fahrrad.endtime > ?)
          AND fahrrad.turm = ?
          AND users.`{$cfg['turm']}` = ?
    ";

    if (!empty($cfg['mail_active'])) {
        $sql .= " AND users.`{$cfg['mail_active']}` = 1";
    }

    $sql .= " AND users.`{$cfg['pid']}` IN (11, 12, 13, 14)";
    $sql .= " ORDER BY fahrrad.platz ASC, fahrrad.starttime ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [
            'spot' => [],
            'queue' => [],
        ];
    }

    $zeit = time();
    mysqli_stmt_bind_param($stmt, "iss", $zeit, $turm, $turm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $spotByUid = [];
    $queueByUid = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            continue;
        }

        $platz = (int)($row['bike_platz'] ?? 0);

        if ($platz > 0) {
            if (!isset($spotByUid[$uid])) {
                $spotByUid[$uid] = normalizeUserRow($row);
                $spotByUid[$uid]['plaetze'] = '';
            }

            $existing = $spotByUid[$uid]['plaetze'] !== ''
                ? array_map('trim', explode(',', $spotByUid[$uid]['plaetze']))
                : [];

            if (!in_array((string)$platz, $existing, true)) {
                $existing[] = (string)$platz;
            }

            natsort($existing);
            $spotByUid[$uid]['plaetze'] = implode(', ', $existing);
            $spotByUid[$uid]['extra_status'] = (count($existing) > 1 ? 'Stellplätze ' : 'Stellplatz ') . $spotByUid[$uid]['plaetze'];
        } else {
            if (!isset($queueByUid[$uid])) {
                $queueByUid[$uid] = normalizeUserRow($row, 'Warteschlange');
            }
        }
    }

    mysqli_stmt_close($stmt);

    return [
        'spot'  => array_values($spotByUid),
        'queue' => array_values($queueByUid),
    ];
}

function fetchFitnessModeUsers(mysqli $conn): array
{
    $cfg = getUsersColumnConfig($conn);

    if (
        empty($cfg['uid']) ||
        empty($cfg['turm']) ||
        empty($cfg['pid'])
    ) {
        return [];
    }

    $selectParts = buildUserSelectParts($cfg);
    $selectParts[] = "fitness.status AS fitness_status";

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM fitness
        INNER JOIN users
            ON fitness.uid = users.`{$cfg['uid']}`
        WHERE users.`{$cfg['pid']}` IN (11, 12)
        ORDER BY
            FIELD(users.`{$cfg['turm']}`, 'weh', 'tvk'),
            CASE
                WHEN users.`{$cfg['room']}` = 0 AND users.`{$cfg['pid']}` IN (12, 13, 14) THEN users.`{$cfg['oldroom']}`
                ELSE users.`{$cfg['room']}`
            END ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $statusValue = (int)($row['fitness_status'] ?? 0);
        $statusText = $statusValue === 1 ? 'Fitness bestätigt' : 'Fitness-Check offen';

        $user = normalizeUserRow($row, $statusText);
        if ($user['uid'] > 0) {
            $users[] = $user;
        }
    }

    mysqli_stmt_close($stmt);

    return $users;
}

function sanitizeMailHtmlFallback(string $html): string
{
    $html = strip_tags($html, '<p><br><div><b><strong><i><em><u><s><ul><ol><li><a><blockquote>');
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
    $html = preg_replace('/\s+style\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
    $html = preg_replace('/href\s*=\s*("|\')\s*javascript:.*?\1/is', 'href="#"', $html);
    $html = preg_replace('/<(div|p)>\s*<\/\1>/i', '', $html);

    return trim($html);
}

function sanitizeMailHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return sanitizeMailHtmlFallback($html);
    }

    $previous = libxml_use_internal_errors(true);

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="mail-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $allowedTags = [
        'div', 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's',
        'ul', 'ol', 'li', 'a', 'blockquote'
    ];

    $clean = static function (DOMNode $node) use (&$clean, $allowedTags): void {
        if ($node->nodeType === XML_COMMENT_NODE) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
            return;
        }

        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);

            if (!in_array($tag, $allowedTags, true)) {
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            if ($node->hasAttributes()) {
                $remove = [];
                foreach ($node->attributes as $attribute) {
                    $attrName = strtolower($attribute->nodeName);
                    $allowed = ($tag === 'a' && $attrName === 'href');

                    if (!$allowed) {
                        $remove[] = $attribute->nodeName;
                        continue;
                    }

                    $href = trim((string)$attribute->nodeValue);
                    if (
                        $href === '' ||
                        !preg_match('~^(https?://|mailto:)~i', $href)
                    ) {
                        $remove[] = $attribute->nodeName;
                    }
                }

                foreach ($remove as $attrName) {
                    $node->removeAttribute($attrName);
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $clean($child);
        }
    };

    $root = $doc->getElementById('mail-root');
    if ($root) {
        $clean($root);

        $htmlOut = '';
        foreach ($root->childNodes as $child) {
            $htmlOut .= $doc->saveHTML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $htmlOut = sanitizeMailHtmlFallback($htmlOut);
        return trim($htmlOut);
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return sanitizeMailHtmlFallback($html);
}

function htmlToPlainText(string $html): string
{
    $text = $html;
    $text = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text);
    $text = preg_replace('~</\s*(p|div|li|blockquote|ul|ol)\s*>~i', "\n", $text);
    $text = preg_replace('~<li[^>]*>~i', "- ", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

function normalizeUploadedAttachments(array $files): array
{
    if (
        !isset($files['name'], $files['type'], $files['tmp_name'], $files['error'], $files['size']) ||
        !is_array($files['name'])
    ) {
        return [];
    }

    $attachments = [];
    $totalSize = 0;
    $maxFiles = 12;
    $maxTotalSize = 20 * 1024 * 1024;

    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        if (count($attachments) >= $maxFiles) {
            break;
        }

        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName = (string)($files['tmp_name'][$i] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            continue;
        }

        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) {
            continue;
        }

        if (($totalSize + $size) > $maxTotalSize) {
            continue;
        }

        $content = @file_get_contents($tmpName);
        if ($content === false) {
            continue;
        }

        $filename = safeAttachmentFilename((string)($files['name'][$i] ?? 'attachment'));
        $mimeType = trim((string)($files['type'][$i] ?? ''));

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_file($finfo, $tmpName);
                if (is_string($detected) && $detected !== '') {
                    $mimeType = $detected;
                }
                @finfo_close($finfo);
            }
        }

        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        $attachments[] = [
            'name'    => $filename,
            'type'    => $mimeType,
            'content' => $content,
        ];

        $totalSize += $size;
    }

    return $attachments;
}

function buildMailPayload(string $htmlMessage, array $attachments): array
{
    $htmlMessage = trim($htmlMessage);
    $plainTextMessage = htmlToPlainText($htmlMessage);

    if ($plainTextMessage === '') {
        $plainTextMessage = trim(html_entity_decode(strip_tags($htmlMessage), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (empty($attachments)) {
        $boundaryAlt = '=_alt_' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"',
        ];

        $body = '';
        $body .= '--' . $boundaryAlt . "\r\n";
        $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($plainTextMessage)) . "\r\n";

        $body .= '--' . $boundaryAlt . "\r\n";
        $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlMessage)) . "\r\n";

        $body .= '--' . $boundaryAlt . "--\r\n";

        return [
            'headers' => $headers,
            'body' => $body,
        ];
    }

    $boundaryMixed = '=_mixed_' . bin2hex(random_bytes(12));
    $boundaryAlt = '=_alt_' . bin2hex(random_bytes(12));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"',
    ];

    $body = '';
    $body .= '--' . $boundaryMixed . "\r\n";
    $body .= 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n";

    $body .= '--' . $boundaryAlt . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($plainTextMessage)) . "\r\n";

    $body .= '--' . $boundaryAlt . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlMessage)) . "\r\n";

    $body .= '--' . $boundaryAlt . "--\r\n";

    foreach ($attachments as $attachment) {
        $filename = safeAttachmentFilename((string)($attachment['name'] ?? 'attachment'));
        $mimeType = trim((string)($attachment['type'] ?? 'application/octet-stream'));
        $content = (string)($attachment['content'] ?? '');

        $body .= '--' . $boundaryMixed . "\r\n";
        $body .= 'Content-Type: ' . $mimeType . '; name="' . addcslashes($filename, '"\\') . '"' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename) . "\r\n\r\n";
        $body .= chunk_split(base64_encode($content)) . "\r\n";
    }

    $body .= '--' . $boundaryMixed . "--\r\n";

    return [
        'headers' => $headers,
        'body' => $body,
    ];
}

function sendMailToUsers(
    mysqli $conn,
    array $uids,
    string $subject,
    string $htmlMessage,
    string $fromAddress,
    array $attachments = []
): array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    })));

    $subject = trim($subject);
    $fromAddress = safeHeaderValue(trim($fromAddress));
    $htmlMessage = sanitizeMailHtml($htmlMessage);

    if ($subject === '') {
        return [
            'ok' => false,
            'message' => 'Bitte einen Betreff eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if (trim(htmlToPlainText($htmlMessage)) === '') {
        return [
            'ok' => false,
            'message' => 'Bitte eine Nachricht eingeben.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if ($fromAddress === '') {
        return [
            'ok' => false,
            'message' => 'Bitte eine gültige Sender-Adresse auswählen.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    if (empty($uids)) {
        return [
            'ok' => false,
            'message' => 'Bitte mindestens eine Person auswählen.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $cfg = getUsersColumnConfig($conn);

    if (empty($cfg['uid']) || empty($cfg['username']) || empty($cfg['turm'])) {
        return [
            'ok' => false,
            'message' => 'Benutzerdaten konnten nicht gelesen werden.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $inList = implode(',', $uids);

    $sql = "
        SELECT DISTINCT
            CONCAT(users.`{$cfg['username']}`, '@', users.`{$cfg['turm']}`, '.rwth-aachen.de') AS email
        FROM users
        WHERE users.`{$cfg['uid']}` IN ($inList)
          AND users.`{$cfg['username']}` IS NOT NULL
          AND users.`{$cfg['username']}` <> ''
          AND users.`{$cfg['turm']}` IN ('weh', 'tvk')
    ";

    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        return [
            'ok' => false,
            'message' => 'Fehler beim Laden der Empfänger.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $emails = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    mysqli_free_result($result);

    $emails = array_values(array_unique($emails));

    if (empty($emails)) {
        return [
            'ok' => false,
            'message' => 'Es wurden keine gültigen Empfänger gefunden.',
            'sent' => 0,
            'total' => 0,
        ];
    }

    $mailPayload = buildMailPayload($htmlMessage, $attachments);
    $baseHeaders = [
        'From: ' . $fromAddress,
        'Reply-To: ' . $fromAddress,
    ];
    $headerString = implode("\r\n", array_merge($baseHeaders, $mailPayload['headers']));
    $encodedSubject = encodeMailSubject($subject);

    $sent = 0;
    $total = count($emails);

    foreach ($emails as $email) {
        if (@mail($email, $encodedSubject, $mailPayload['body'], $headerString)) {
            $sent++;
        }
    }

    if ($sent === $total) {
        return [
            'ok' => true,
            'message' => 'Nachricht erfolgreich versendet.',
            'sent' => $sent,
            'total' => $total,
        ];
    }

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Nachricht teilweise versendet (' . $sent . ' von ' . $total . ').'
            : 'Fehler beim Versenden der Nachricht.',
        'sent' => $sent,
        'total' => $total,
    ];
}

$senderGroups = fetchAllowedSenderGroups($conn);
$hasSessionAccess = !empty($_SESSION['valid']) && !empty($senderGroups);

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$isJsonRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && strpos($contentType, 'application/json') !== false;
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$bikeAllowedTurms = [];
if (!empty($_SESSION['FahrradAG'])) {
    $bikeAllowedTurms[] = 'weh';
}
if (!empty($_SESSION['TvK-Sprecher'])) {
    $bikeAllowedTurms[] = 'tvk';
}
$bikeAllowedTurms = array_values(array_unique($bikeAllowedTurms));

$canUseFitnessMode = !empty($_SESSION['SportAG']);

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$hasSessionAccess) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Keine Berechtigung.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Ungültige Anfrage.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $action = (string)($payload['action'] ?? '');

    if ($action === 'search') {
        $query = (string)($payload['query'] ?? '');
        $users = searchUsers($conn, $query);

        echo json_encode([
            'ok' => true,
            'users' => $users,
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Unbekannte Aktion.',
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !$isJsonRequest &&
    $isAjaxRequest &&
    (string)($_POST['action'] ?? '') === 'send'
) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$hasSessionAccess) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Keine Berechtigung.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $senderGroupId = (int)($_POST['sender_group_id'] ?? 0);
    $senderGroup = findSenderGroupById($senderGroups, $senderGroupId);

    if ($senderGroup === null) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Ungültige Sender-Adresse.',
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    $uids = $_POST['uids'] ?? [];
    if (!is_array($uids)) {
        $uids = [];
    }

    $subject = (string)($_POST['subject'] ?? '');
    $messageHtml = (string)($_POST['message_html'] ?? '');
    $attachments = normalizeUploadedAttachments($_FILES['attachments'] ?? []);

    $response = sendMailToUsers(
        $conn,
        $uids,
        $subject,
        $messageHtml,
        (string)$senderGroup['mail'],
        $attachments
    );

    http_response_code($response['ok'] ? 200 : 400);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

ob_start();
require('template.php');
$templateBootstrap = ob_get_clean();

$canAccess = auth($conn) && !empty($_SESSION['valid']) && !empty($senderGroups);

if (!$canAccess) {
    header('Location: denied.php');
    exit;
}

$bikeUsersByTurm = [];
foreach ($bikeAllowedTurms as $turm) {
    $bikeUsersByTurm[$turm] = fetchBikeModeUsers($conn, $turm);
}

$fitnessUsers = $canUseFitnessMode ? fetchFitnessModeUsers($conn) : [];

$pageTitle = 'AG-Mail';
$hasSingleSender = count($senderGroups) === 1;
$singleSender = $hasSingleSender ? $senderGroups[0] : null;
$hasBikeModes = !empty($bikeAllowedTurms);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
    <title><?php echo esc($pageTitle); ?></title>
    <style>
        .agmail-page {
            display: flex;
            justify-content: center;
            padding: 32px 20px 48px;
            background: transparent;
        }

        .agmail-shell {
            width: 100%;
            max-width: 1120px;
            background: #211f1f;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 24px;
            box-sizing: border-box;
        }

        .agmail-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .agmail-header h1 {
            margin: 0;
            color: #f1eeee;
            font-size: 32px;
            line-height: 1.15;
        }

        .agmail-modebar,
        .agmail-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .agmail-modebtn,
        .agmail-toolbtn,
        .agmail-uploadbtn {
            appearance: none;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            padding: 10px 14px;
            background: #2a2727;
            color: #f1eeee;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.15s ease, opacity 0.15s ease, border-color 0.15s ease, background 0.15s ease;
            margin-bottom: 10px;
        }

        .agmail-modebtn:hover,
        .agmail-toolbtn:hover,
        .agmail-uploadbtn:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }

        .agmail-modebtn.is-active,
        .agmail-toolbtn.is-active {
            border-color: rgba(17, 165, 13, 0.62);
            background: rgba(17, 165, 13, 0.14);
            color: #dff8df;
        }

        .agmail-section {
            margin-bottom: 22px;
        }

        .agmail-label {
            display: block;
            margin-bottom: 10px;
            color: #f1eeee;
            font-size: 16px;
            font-weight: 700;
        }

        .agmail-select,
        .agmail-input {
            width: 100%;
            box-sizing: border-box;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: #2a2727;
            color: #f1eeee;
            padding: 14px 16px;
            min-height: 52px;
        }

        .agmail-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            color-scheme: dark;
        }

        .agmail-select option,
        .agmail-select optgroup {
            background: #2a2727;
            color: #f1eeee;
        }

        .agmail-select:focus,
        .agmail-input:focus,
        .agmail-editor:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.18);
            background: #312e2e;
        }

        .agmail-input::placeholder {
            color: rgba(255, 255, 255, 0.38);
        }

        .agmail-sender-card {
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            background: #2a2727;
            padding: 14px 16px;
            color: #f1eeee;
            line-height: 1.45;
        }

        .agmail-sender-card strong {
            display: block;
            margin-bottom: 3px;
        }

        .agmail-results {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
        }

        .agmail-recipients,
        .agmail-attachments {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
            gap: 14px;
        }

        .agmail-result {
            appearance: none;
            width: 100%;
            min-width: 0;
            text-align: left;
            cursor: pointer;
            box-sizing: border-box;
            padding: 10px 11px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: #262323;
            transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease, opacity 0.15s ease;
        }

        .agmail-result:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.16);
            background: #2d2929;
        }

        .agmail-result.is-selected {
            border-color: rgba(17, 165, 13, 0.52);
            background: rgba(17, 165, 13, 0.10);
        }

        .agmail-result__name {
            display: block;
            color: #f1eeee;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .agmail-result__meta {
            display: block;
            color: rgba(255, 255, 255, 0.70);
            font-size: 11px;
            line-height: 1.35;
            word-break: break-word;
        }

        .agmail-user,
        .agmail-attachment {
            position: relative;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            background: #2a2727;
            padding: 14px 16px;
            text-align: left;
            transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease, opacity 0.15s ease;
            box-sizing: border-box;
        }

        .agmail-user {
            appearance: none;
            cursor: pointer;
        }

        .agmail-user:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.16);
            background: #312e2e;
        }

        .agmail-user.is-selected,
        .agmail-attachment.is-selected {
            border-color: rgba(17, 165, 13, 0.62);
            background: rgba(17, 165, 13, 0.14);
        }

        .agmail-user__name,
        .agmail-attachment__name {
            display: block;
            color: #f1eeee;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 6px;
        }

        .agmail-user__meta,
        .agmail-attachment__meta {
            display: block;
            color: rgba(255, 255, 255, 0.74);
            font-size: 14px;
            line-height: 1.45;
        }

        .agmail-remove {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.18);
            color: #f1eeee;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }

        .agmail-remove:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .agmail-empty {
            border: 1px dashed rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            padding: 18px;
            color: rgba(255, 255, 255, 0.66);
            text-align: center;
            background: #262323;
        }

        .agmail-recipients-head,
        .agmail-attachments-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 10px;
        }

        .agmail-recipients-head h2,
        .agmail-attachments-head h2 {
            margin: 0;
            color: #f1eeee;
            font-size: 20px;
            line-height: 1.2;
        }

        .agmail-count {
            color: rgba(255, 255, 255, 0.72);
            font-size: 14px;
        }

        .agmail-editorwrap {
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            background: #2a2727;
            overflow: hidden;
        }

        .agmail-toolbar {
            padding: 12px 12px 0 12px;
        }

        .agmail-editor {
            min-height: 240px;
            padding: 14px 16px 16px;
            color: #f1eeee;
            box-sizing: border-box;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .agmail-editor[contenteditable="true"]:empty:before {
            content: attr(data-placeholder);
            color: rgba(255, 255, 255, 0.38);
            pointer-events: none;
        }

        .agmail-editor p,
        .agmail-editor ul,
        .agmail-editor ol,
        .agmail-editor blockquote {
            margin: 0 0 12px 0;
        }

        .agmail-editor a {
            color: #9fd8ff;
        }

        .agmail-hidden-input {
            display: none;
        }

        .agmail-actions {
            display: flex;
            justify-content: flex-end;
        }

        .agmail-send {
            appearance: none;
            border: 1px solid rgba(17, 165, 13, 0.65);
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            background: #11a50d;
            color: #f7fff7;
            transition: opacity 0.15s ease, transform 0.15s ease, background 0.15s ease;
        }

        .agmail-send:hover:not(:disabled) {
            transform: translateY(-1px);
            background: #149f11;
        }

        .agmail-send:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .agmail-page.is-sent .agmail-shell {
            display: none;
        }

        .agmail-success {
            display: none;
            width: 100%;
            max-width: 1100px;
            min-height: 260px;
            align-items: center;
            justify-content: center;
        }

        .agmail-page.is-sent .agmail-success {
            display: flex;
        }

        .agmail-success__text {
            color: #f1eeee;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .agmail-divider {
            border: 0;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 10px 0 32px 0;
        }

        @media (max-width: 1100px) {
            .agmail-results {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .agmail-results {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .agmail-page {
                padding: 20px 12px 36px;
            }

            .agmail-shell {
                padding: 18px;
                border-radius: 16px;
            }

            .agmail-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agmail-header h1 {
                font-size: 26px;
            }

            .agmail-results {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .agmail-success__text {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
<?php
echo $templateBootstrap;
load_menu();
?>
<main class="agmail-page" id="agMailPage">
    <section class="agmail-shell">
        <div class="agmail-header">
            <h1>AG-Mail</h1>

            <div class="agmail-modebar" id="modeBar">
                <button type="button" class="agmail-modebtn is-active" data-mode="regular">Suche</button>
                <?php if ($hasBikeModes) : ?>
                    <button type="button" class="agmail-modebtn" data-mode="bike_spot">Stellplätze</button>
                    <button type="button" class="agmail-modebtn" data-mode="bike_queue">Warteschlange</button>
                <?php endif; ?>
                <?php if ($canUseFitnessMode) : ?>
                    <button type="button" class="agmail-modebtn" data-mode="fitness">Fitness</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="agmail-section">
            <label class="agmail-label" for="senderSelect">Sender</label>

            <?php if ($hasSingleSender && $singleSender !== null) : ?>
                <div class="agmail-sender-card" id="singleSenderCard" data-sender-id="<?php echo (int)$singleSender['id']; ?>">
                    <strong><?php echo esc($singleSender['name']); ?></strong>
                    <span><?php echo esc($singleSender['mail']); ?> | <?php echo esc(strtoupper($singleSender['turm'])); ?></span>
                </div>
            <?php else : ?>
                <select class="agmail-select" id="senderSelect">
                    <?php foreach ($senderGroups as $group) : ?>
                        <option value="<?php echo (int)$group['id']; ?>">
                            <?php echo esc($group['name'] . ' | ' . $group['mail'] . ' | ' . strtoupper($group['turm'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="agmail-section" id="searchSection">
            <label class="agmail-label" for="userSearch">Empfänger suchen</label>
            <input
                type="text"
                id="userSearch"
                class="agmail-input"
                placeholder="Raum, Name, Mail, Username ..."
                autocomplete="off"
                spellcheck="false"
            >
        </div>

        <div class="agmail-section">
            <div id="resultsWrap" class="agmail-results"></div>
            <div id="resultsEmpty" class="agmail-empty" hidden></div>
        </div>

        <hr class="agmail-divider">

        <div class="agmail-section">
            <div class="agmail-recipients-head">
                <h2>Empfänger</h2>
                <span class="agmail-count" id="recipientCount">0 ausgewählt</span>
            </div>
            <div id="recipientsWrap" class="agmail-recipients"></div>
            <div id="recipientsEmpty" class="agmail-empty">Noch keine Empfänger ausgewählt.</div>
        </div>

        <div class="agmail-section">
            <label class="agmail-label" for="mailSubject">Betreff</label>
            <input type="text" id="mailSubject" class="agmail-input" placeholder="Betreff eingeben">
        </div>

        <div class="agmail-section">
            <label class="agmail-label">Nachricht</label>

            <div class="agmail-editorwrap">
                <div class="agmail-toolbar" id="mailToolbar">
                    <button type="button" class="agmail-toolbtn" data-command="bold"><strong>B</strong></button>
                    <button type="button" class="agmail-toolbtn" data-command="italic"><em>I</em></button>
                    <button type="button" class="agmail-toolbtn" data-command="underline"><u>U</u></button>
                    <button type="button" class="agmail-toolbtn" data-command="insertUnorderedList">• Liste</button>
                    <button type="button" class="agmail-toolbtn" data-command="insertOrderedList">1. Liste</button>
                    <button type="button" class="agmail-toolbtn" data-command="createLink">Link</button>
                    <button type="button" class="agmail-toolbtn" data-command="removeFormat">Format löschen</button>
                </div>

                <div
                    id="mailEditor"
                    class="agmail-editor"
                    contenteditable="true"
                    data-placeholder="Nachricht eingeben"
                ></div>
            </div>
        </div>

        <div class="agmail-section">
            <div class="agmail-attachments-head">
                <h2>Anhänge</h2>
                <span class="agmail-count" id="attachmentCount">0 angehängt</span>
            </div>

            <div class="agmail-toolbar" style="padding: 0 0 12px 0;">
                <label class="agmail-uploadbtn" for="mailAttachments">Dateien/Bilder hinzufügen</label>
                <input type="file" id="mailAttachments" class="agmail-hidden-input" multiple>
            </div>

            <div id="attachmentsWrap" class="agmail-attachments"></div>
            <div id="attachmentsEmpty" class="agmail-empty">Noch keine Anhänge hochgeladen.</div>
        </div>

        <div class="agmail-actions">
            <button type="button" id="sendButton" class="agmail-send" disabled>Nachricht senden</button>
        </div>
    </section>

    <div class="agmail-success" id="sendSuccessScreen">
        <div class="agmail-success__text">Gesendet</div>
    </div>
</main>

<script>
(() => {
    const page = document.getElementById('agMailPage');
    const modeBar = document.getElementById('modeBar');
    const senderSelect = document.getElementById('senderSelect');
    const singleSenderCard = document.getElementById('singleSenderCard');
    const searchSection = document.getElementById('searchSection');
    const userSearch = document.getElementById('userSearch');
    const resultsWrap = document.getElementById('resultsWrap');
    const resultsEmpty = document.getElementById('resultsEmpty');
    const recipientsWrap = document.getElementById('recipientsWrap');
    const recipientsEmpty = document.getElementById('recipientsEmpty');
    const recipientCount = document.getElementById('recipientCount');
    const attachmentsWrap = document.getElementById('attachmentsWrap');
    const attachmentsEmpty = document.getElementById('attachmentsEmpty');
    const attachmentCount = document.getElementById('attachmentCount');
    const attachmentInput = document.getElementById('mailAttachments');
    const subjectField = document.getElementById('mailSubject');
    const mailToolbar = document.getElementById('mailToolbar');
    const mailEditor = document.getElementById('mailEditor');
    const sendButton = document.getElementById('sendButton');

    const senderGroups = <?php echo json_encode(array_values($senderGroups), JSON_UNESCAPED_UNICODE); ?>;
    const bikeUsersByTurm = <?php echo json_encode($bikeUsersByTurm, JSON_UNESCAPED_UNICODE); ?>;
    const bikeAllowedTurms = <?php echo json_encode(array_values($bikeAllowedTurms), JSON_UNESCAPED_UNICODE); ?>;
    const fitnessUsers = <?php echo json_encode(array_values($fitnessUsers), JSON_UNESCAPED_UNICODE); ?>;

    let currentMode = 'regular';
    let regularResults = [];
    let selectedRecipients = new Map();
    let selectedAttachments = [];
    let searchRequestCounter = 0;
    let searchDebounceTimer = null;

    const escapeHtml = (value) => {
        return String(value).replace(/[&<>"']/g, (char) => {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return char;
            }
        });
    };

    const formatBytes = (bytes) => {
        const value = Number(bytes) || 0;
        if (value < 1024) {
            return `${value} B`;
        }
        if (value < 1024 * 1024) {
            return `${(value / 1024).toFixed(1)} KB`;
        }
        return `${(value / (1024 * 1024)).toFixed(1)} MB`;
    };

    const getSelectedSenderGroupId = () => {
        if (senderSelect) {
            return parseInt(senderSelect.value, 10) || 0;
        }
        if (singleSenderCard) {
            return parseInt(singleSenderCard.dataset.senderId, 10) || 0;
        }
        return 0;
    };

    const getCurrentSenderGroup = () => {
        const senderGroupId = getSelectedSenderGroupId();
        return senderGroups.find((group) => Number(group.id) === Number(senderGroupId)) || senderGroups[0] || null;
    };

    const buildMetaParts = (user) => {
        const parts = [
            String(user.room || '-'),
            String(user.turm || '').toUpperCase()
        ];

        const residentStatus = String(user.resident_status || '').trim();
        if (residentStatus !== '') {
            parts.push(residentStatus);
        }

        const extraStatus = String(user.extra_status || '').trim();
        if (extraStatus !== '') {
            parts.push(extraStatus);
        }

        return parts;
    };

    const formatUserMeta = (user) => buildMetaParts(user).join(' | ');

    const getCurrentBikeUsers = (bucket) => {
        const senderGroup = getCurrentSenderGroup();
        if (!senderGroup) {
            return null;
        }

        const turm = String(senderGroup.turm || '').toLowerCase();
        if (!bikeAllowedTurms.includes(turm)) {
            return null;
        }

        if (!bikeUsersByTurm[turm] || !Array.isArray(bikeUsersByTurm[turm][bucket])) {
            return [];
        }

        return bikeUsersByTurm[turm][bucket];
    };

    const getCurrentModeUsers = () => {
        if (currentMode === 'bike_spot') {
            return getCurrentBikeUsers('spot');
        }
        if (currentMode === 'bike_queue') {
            return getCurrentBikeUsers('queue');
        }
        if (currentMode === 'fitness') {
            return fitnessUsers;
        }
        return regularResults;
    };

    const getModeUnavailableMessage = () => {
        if (currentMode === 'bike_spot' || currentMode === 'bike_queue') {
            return 'Für den aktuell gewählten Sender ist dieser Modus nicht verfügbar.';
        }
        return '';
    };

    const getEditorText = () => {
        return String(mailEditor.textContent || '').replace(/\u00a0/g, ' ').trim();
    };

    const getEditorHtml = () => {
        let html = String(mailEditor.innerHTML || '').trim();

        if (
            html === '' ||
            html === '<br>' ||
            html === '<div><br></div>' ||
            html === '<p><br></p>'
        ) {
            return '';
        }

        return html;
    };

    const updateSendState = () => {
        sendButton.disabled =
            selectedRecipients.size === 0 ||
            subjectField.value.trim() === '' ||
            getEditorText() === '' ||
            !getCurrentSenderGroup();
    };

    const renderRecipients = () => {
        const recipients = Array.from(selectedRecipients.values());
        recipientCount.textContent = `${recipients.length} ausgewählt`;

        if (recipients.length === 0) {
            recipientsWrap.innerHTML = '';
            recipientsEmpty.hidden = false;
            updateSendState();
            return;
        }

        recipientsEmpty.hidden = true;
        recipientsWrap.innerHTML = recipients.map((user) => {
            const uid = escapeHtml(user.uid);
            return `
                <button type="button" class="agmail-user is-selected" data-role="recipient-remove" data-uid="${uid}">
                    <span class="agmail-user__name">${escapeHtml(user.name)}</span>
                    <span class="agmail-user__meta">${escapeHtml(formatUserMeta(user))}</span>
                </button>
            `;
        }).join('');

        Array.from(recipientsWrap.querySelectorAll('[data-role="recipient-remove"]')).forEach((button) => {
            button.addEventListener('click', () => {
                const uid = String(button.dataset.uid || '');
                selectedRecipients.delete(uid);
                renderRecipients();
                renderCurrentResults();
            });
        });

        updateSendState();
    };

    const syncAttachmentInput = () => {
        const dt = new DataTransfer();
        selectedAttachments.forEach((file) => dt.items.add(file));
        attachmentInput.files = dt.files;
    };

    const renderAttachments = () => {
        attachmentCount.textContent = `${selectedAttachments.length} angehängt`;

        if (selectedAttachments.length === 0) {
            attachmentsWrap.innerHTML = '';
            attachmentsEmpty.hidden = false;
            return;
        }

        attachmentsEmpty.hidden = true;
        attachmentsWrap.innerHTML = selectedAttachments.map((file, index) => `
            <div class="agmail-attachment is-selected">
                <button type="button" class="agmail-remove" data-role="attachment-remove" data-index="${index}" aria-label="Anhang entfernen">×</button>
                <span class="agmail-attachment__name">${escapeHtml(file.name)}</span>
                <span class="agmail-attachment__meta">${escapeHtml(formatBytes(file.size))}${file.type ? ' | ' + escapeHtml(file.type) : ''}</span>
            </div>
        `).join('');

        Array.from(attachmentsWrap.querySelectorAll('[data-role="attachment-remove"]')).forEach((button) => {
            button.addEventListener('click', () => {
                const index = parseInt(button.dataset.index || '-1', 10);
                if (index < 0 || index >= selectedAttachments.length) {
                    return;
                }

                selectedAttachments.splice(index, 1);
                syncAttachmentInput();
                renderAttachments();
            });
        });
    };

    const mergeNewAttachments = (files) => {
        Array.from(files).forEach((file) => {
            const exists = selectedAttachments.some((existing) =>
                existing.name === file.name &&
                existing.size === file.size &&
                existing.lastModified === file.lastModified
            );

            if (!exists) {
                selectedAttachments.push(file);
            }
        });

        syncAttachmentInput();
        renderAttachments();
    };

    const toggleRecipient = (user) => {
        const key = String(user.uid);

        if (selectedRecipients.has(key)) {
            selectedRecipients.delete(key);
        } else {
            selectedRecipients.set(key, {
                uid: Number(user.uid),
                name: String(user.name || ''),
                room: String(user.room || '-'),
                turm: String(user.turm || ''),
                pid: Number(user.pid || 0),
                resident_status: String(user.resident_status || ''),
                plaetze: String(user.plaetze || ''),
                extra_status: String(user.extra_status || ''),
            });
        }

        renderRecipients();
        renderCurrentResults();
    };

    const renderResultCards = (users) => {
        resultsWrap.innerHTML = users.map((user) => {
            const uid = String(user.uid);
            const isSelected = selectedRecipients.has(uid);

            return `
                <button type="button" class="agmail-result${isSelected ? ' is-selected' : ''}" data-role="result-user" data-uid="${escapeHtml(uid)}">
                    <span class="agmail-result__name">${escapeHtml(user.name)}</span>
                    <span class="agmail-result__meta">${escapeHtml(formatUserMeta(user))}</span>
                </button>
            `;
        }).join('');

        Array.from(resultsWrap.querySelectorAll('[data-role="result-user"]')).forEach((button) => {
            button.addEventListener('click', () => {
                const uid = String(button.dataset.uid || '');
                const source = getCurrentModeUsers();

                if (!Array.isArray(source)) {
                    return;
                }

                const user = source.find((entry) => String(entry.uid) === uid);
                if (!user) {
                    return;
                }

                toggleRecipient(user);
            });
        });
    };

    const renderCurrentResults = () => {
        if (currentMode === 'regular') {
            if (userSearch.value.trim() === '') {
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = true;
                return;
            }

            if (regularResults.length === 0) {
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = false;
                resultsEmpty.textContent = 'Keine Treffer.';
                return;
            }

            resultsEmpty.hidden = true;
            renderResultCards(regularResults);
            return;
        }

        const users = getCurrentModeUsers();

        if (users === null) {
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent = getModeUnavailableMessage();
            return;
        }

        if (!Array.isArray(users) || users.length === 0) {
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent =
                currentMode === 'bike_spot' ? 'Keine Stellplatznutzer gefunden.' :
                currentMode === 'bike_queue' ? 'Keine Personen auf der Warteschlange gefunden.' :
                'Keine Fitness-Nutzer gefunden.';
            return;
        }

        resultsEmpty.hidden = true;
        renderResultCards(users);
    };

    const performSearch = () => {
        if (currentMode !== 'regular') {
            return;
        }

        const query = userSearch.value.trim();
        const requestId = ++searchRequestCounter;

        if (query === '') {
            regularResults = [];
            renderCurrentResults();
            return;
        }

        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'search',
                query
            }),
            credentials: 'same-origin'
        })
        .then((response) => response.text())
        .then((text) => {
            if (requestId !== searchRequestCounter || currentMode !== 'regular') {
                return null;
            }

            try {
                return JSON.parse(text);
            } catch (error) {
                regularResults = [];
                resultsWrap.innerHTML = '';
                resultsEmpty.hidden = false;
                resultsEmpty.textContent = 'Suche fehlgeschlagen.';
                return null;
            }
        })
        .then((data) => {
            if (!data || requestId !== searchRequestCounter || currentMode !== 'regular') {
                return;
            }

            regularResults = Array.isArray(data.users) ? data.users : [];
            renderCurrentResults();
        })
        .catch(() => {
            if (requestId !== searchRequestCounter || currentMode !== 'regular') {
                return;
            }

            regularResults = [];
            resultsWrap.innerHTML = '';
            resultsEmpty.hidden = false;
            resultsEmpty.textContent = 'Suche fehlgeschlagen.';
        });
    };

    const scheduleSearch = () => {
        window.clearTimeout(searchDebounceTimer);
        searchDebounceTimer = window.setTimeout(performSearch, 180);
    };

    const renderMode = () => {
        Array.from(modeBar.querySelectorAll('[data-mode]')).forEach((button) => {
            button.classList.toggle('is-active', button.dataset.mode === currentMode);
        });

        searchSection.hidden = currentMode !== 'regular';
        renderCurrentResults();
        renderRecipients();
        updateSendState();
    };

    const execEditorCommand = (command) => {
        mailEditor.focus();

        if (command === 'createLink') {
            let url = window.prompt('Link eingeben');
            if (!url) {
                return;
            }

            url = String(url).trim();
            if (url !== '' && !/^(https?:\/\/|mailto:)/i.test(url)) {
                url = 'https://' + url;
            }

            document.execCommand('createLink', false, url);
            updateToolbarState();
            updateSendState();
            return;
        }

        if (command === 'removeFormat') {
            document.execCommand('removeFormat', false, null);
            document.execCommand('unlink', false, null);
            updateToolbarState();
            updateSendState();
            return;
        }

        document.execCommand(command, false, null);
        updateToolbarState();
        updateSendState();
    };

    const updateToolbarState = () => {
        Array.from(mailToolbar.querySelectorAll('[data-command]')).forEach((button) => {
            const command = String(button.dataset.command || '');
            if (!command || command === 'createLink' || command === 'removeFormat') {
                button.classList.remove('is-active');
                return;
            }

            try {
                button.classList.toggle('is-active', document.queryCommandState(command));
            } catch (error) {
                button.classList.remove('is-active');
            }
        });
    };

    Array.from(modeBar.querySelectorAll('[data-mode]')).forEach((button) => {
        button.addEventListener('click', () => {
            currentMode = String(button.dataset.mode || 'regular');
            renderMode();
        });
    });

    if (senderSelect) {
        senderSelect.addEventListener('change', () => {
            renderMode();
        });
    }

    userSearch.addEventListener('input', scheduleSearch);
    subjectField.addEventListener('input', updateSendState);
    mailEditor.addEventListener('input', updateSendState);
    mailEditor.addEventListener('keyup', updateToolbarState);
    mailEditor.addEventListener('mouseup', updateToolbarState);
    mailEditor.addEventListener('focus', updateToolbarState);

    Array.from(mailToolbar.querySelectorAll('[data-command]')).forEach((button) => {
        button.addEventListener('click', () => {
            execEditorCommand(String(button.dataset.command || ''));
        });
    });

    attachmentInput.addEventListener('change', () => {
        mergeNewAttachments(attachmentInput.files);
    });

    sendButton.addEventListener('click', () => {
        const senderGroup = getCurrentSenderGroup();
        const subject = subjectField.value.trim();
        const messageHtml = getEditorHtml();
        const uids = Array.from(selectedRecipients.keys()).map((uid) => Number(uid)).filter((uid) => uid > 0);

        if (!senderGroup || !subject || getEditorText() === '' || uids.length === 0) {
            updateSendState();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('sender_group_id', String(Number(senderGroup.id)));
        formData.append('subject', subject);
        formData.append('message_html', messageHtml);

        uids.forEach((uid) => {
            formData.append('uids[]', String(uid));
        });

        selectedAttachments.forEach((file) => {
            formData.append('attachments[]', file, file.name);
        });

        sendButton.disabled = true;
        page.classList.add('is-sent');

        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData,
            credentials: 'same-origin'
        }).catch(() => {});

        window.setTimeout(() => {
            window.location.reload();
        }, 3000);
    });

    renderRecipients();
    renderAttachments();
    renderMode();
    updateToolbarState();
})();
</script>
</body>
</html>
<?php
$conn->close();
?>