<?php
session_start();

require_once('conn.php');
mysqli_set_charset($conn, "utf8");

$fijionlyaccess = true;

$bannerMessage = '';
$redirectAfterSubmit = false;

if (!function_exists('erstattung_h')) {
    function erstattung_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('erstattung_format_turm')) {
    function erstattung_format_turm(string $turm): string {
        $turm = strtolower(trim($turm));

        if ($turm === 'weh') {
            return 'WEH';
        }

        if ($turm === 'tvk') {
            return 'TvK';
        }

        return strtoupper($turm);
    }
}

if (!function_exists('erstattung_get_turm_accent')) {
    function erstattung_get_turm_accent(string $turm): array {
        $turm = strtolower(trim($turm));

        if ($turm === 'tvk') {
            return [
                'accent' => '#FFA500',
                'hover' => '#cc8400',
            ];
        }

        return [
            'accent' => '#11a50d',
            'hover' => '#0e8c0b',
        ];
    }
}

if (!function_exists('erstattung_bind_params')) {
    function erstattung_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool {
        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => &$value) {
            $refs[] = &$value;
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('erstattung_parse_money')) {
    function erstattung_parse_money($value): float {
        $value = trim((string)$value);
        $value = str_replace(' ', '', $value);
        $value = str_replace(',', '.', $value);

        return floatval($value);
    }
}

if (!function_exists('erstattung_get_user_ag_options')) {
    function erstattung_get_user_ag_options(mysqli $conn): array {
        $options = [];

        $sql = "
            SELECT id, name, session
            FROM `groups`
            WHERE active = 1
              AND agessen = 1
            ORDER BY prio, name
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return $options;
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $agId, $agName, $agSessionKey);

        while (mysqli_stmt_fetch($stmt)) {
            if (!empty($agSessionKey) && !empty($_SESSION[$agSessionKey])) {
                $options[] = [
                    'id' => intval($agId),
                    'name' => (string)$agName,
                    'session' => (string)$agSessionKey,
                ];
            }
        }

        mysqli_stmt_close($stmt);

        return $options;
    }
}

if (!function_exists('erstattung_get_ag_option_by_id')) {
    function erstattung_get_ag_option_by_id(array $agOptions, int $agId): ?array {
        foreach ($agOptions as $agOption) {
            if (intval($agOption['id']) === $agId) {
                return $agOption;
            }
        }

        return null;
    }
}

if (!function_exists('erstattung_get_ag_name')) {
    function erstattung_get_ag_name(mysqli $conn, int $agId): string {
        $fallback = 'AG #' . $agId;

        $sql = "
            SELECT name
            FROM `groups`
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return $fallback;
        }

        mysqli_stmt_bind_param($stmt, 'i', $agId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $agName);

        if (mysqli_stmt_fetch($stmt)) {
            $fallback = (string)$agName;
        }

        mysqli_stmt_close($stmt);

        return $fallback;
    }
}

if (!function_exists('erstattung_format_einrichtung')) {
    function erstattung_format_einrichtung(mysqli $conn, string $einrichtung): string {
        if (preg_match('/^ag:([0-9]+)$/', $einrichtung, $matches)) {
            return erstattung_get_ag_name($conn, intval($matches[1]));
        }

        if (preg_match('/^etage:(weh|tvk)_([0-9]+)$/', $einrichtung, $matches)) {
            return erstattung_format_turm($matches[1]) . ' Etage ' . intval($matches[2]);
        }

        return $einrichtung;
    }
}

if (!function_exists('erstattung_format_tstamp')) {
    function erstattung_format_tstamp($tstamp): string {
        $tstamp = intval($tstamp);

        if ($tstamp <= 0) {
            return '-';
        }

        return date('d.m.Y H:i', $tstamp);
    }
}

if (!function_exists('erstattung_approval_count')) {
    function erstattung_approval_count(array $row): int {
        $count = 0;

        for ($i = 1; $i <= 5; $i++) {
            $uidKey = 'vorstand_uid_' . $i;
            $decisionKey = 'vorstand_decision_' . $i;

            $vorstandUid = intval($row[$uidKey] ?? 0);
            $decision = (string)($row[$decisionKey] ?? '');

            if ($vorstandUid > 0 && ($decision === 'accepted' || $decision === '')) {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('erstattung_decline_count')) {
    function erstattung_decline_count(array $row): int {
        $count = 0;

        for ($i = 1; $i <= 5; $i++) {
            $uidKey = 'vorstand_uid_' . $i;
            $decisionKey = 'vorstand_decision_' . $i;

            $vorstandUid = intval($row[$uidKey] ?? 0);
            $decision = (string)($row[$decisionKey] ?? '');

            if ($vorstandUid > 0 && $decision === 'declined') {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('erstattung_is_purchase_request_approved')) {
    function erstattung_is_purchase_request_approved(array $row): bool {
        return erstattung_approval_count($row) >= 3
            && erstattung_decline_count($row) < 3;
    }
}

if (!function_exists('erstattung_is_purchase_request_declined')) {
    function erstattung_is_purchase_request_declined(array $row): bool {
        return erstattung_decline_count($row) >= 3;
    }
}

if (!function_exists('erstattung_declined_sql_condition')) {
    function erstattung_declined_sql_condition(string $alias = 'ea'): string {
        return "
            (
                (CASE WHEN {$alias}.vorstand_decision_1 = 'declined' THEN 1 ELSE 0 END)
              + (CASE WHEN {$alias}.vorstand_decision_2 = 'declined' THEN 1 ELSE 0 END)
              + (CASE WHEN {$alias}.vorstand_decision_3 = 'declined' THEN 1 ELSE 0 END)
              + (CASE WHEN {$alias}.vorstand_decision_4 = 'declined' THEN 1 ELSE 0 END)
              + (CASE WHEN {$alias}.vorstand_decision_5 = 'declined' THEN 1 ELSE 0 END)
            )
        ";
    }
}

if (!function_exists('erstattung_fetch_open_purchase_requests')) {
    function erstattung_fetch_open_purchase_requests(mysqli $conn, array $allowedAgIds): array {
        $allowedAgIds = array_values(array_unique(array_map('intval', $allowedAgIds)));

        if (count($allowedAgIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($allowedAgIds), '?'));
        $declinedSql = erstattung_declined_sql_condition('ea');

        $sql = "
            SELECT
                ea.id,
                ea.uid,
                ea.ag_id,
                ea.tstamp,
                ea.titel,
                ea.beschreibung,
                ea.maxbetrag,
                ea.status,

                ea.vorstand_uid_1,
                ea.vorstand_uid_1_tstamp,
                ea.vorstand_decision_1,

                ea.vorstand_uid_2,
                ea.vorstand_uid_2_tstamp,
                ea.vorstand_decision_2,

                ea.vorstand_uid_3,
                ea.vorstand_uid_3_tstamp,
                ea.vorstand_decision_3,

                ea.vorstand_uid_4,
                ea.vorstand_uid_4_tstamp,
                ea.vorstand_decision_4,

                ea.vorstand_uid_5,
                ea.vorstand_uid_5_tstamp,
                ea.vorstand_decision_5,

                u.name AS submitter_name,
                g.name AS ag_name
            FROM einkaufantraege ea
            LEFT JOIN users u
                ON u.uid = ea.uid
            LEFT JOIN `groups` g
                ON g.id = ea.ag_id
            WHERE ea.status = 'gestellt'
              AND ea.ag_id IN ($placeholders)
              AND {$declinedSql} < 3
              AND NOT EXISTS (
                    SELECT 1
                    FROM erstattung e
                    WHERE e.einkaufantrag_id = ea.id
              )
            ORDER BY ea.tstamp DESC, ea.id DESC
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return [];
        }

        $types = str_repeat('i', count($allowedAgIds));
        $params = $allowedAgIds;
        erstattung_bind_params($stmt, $types, $params);

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $rows = [];

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }

            mysqli_free_result($result);
        }

        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('erstattung_fetch_purchase_request_for_refund')) {
    function erstattung_fetch_purchase_request_for_refund(mysqli $conn, int $purchaseRequestId): ?array {
        $declinedSql = erstattung_declined_sql_condition('ea');

        $sql = "
            SELECT
                ea.id,
                ea.uid,
                ea.ag_id,
                ea.tstamp,
                ea.titel,
                ea.beschreibung,
                ea.maxbetrag,
                ea.status,

                ea.vorstand_uid_1,
                ea.vorstand_uid_1_tstamp,
                ea.vorstand_decision_1,

                ea.vorstand_uid_2,
                ea.vorstand_uid_2_tstamp,
                ea.vorstand_decision_2,

                ea.vorstand_uid_3,
                ea.vorstand_uid_3_tstamp,
                ea.vorstand_decision_3,

                ea.vorstand_uid_4,
                ea.vorstand_uid_4_tstamp,
                ea.vorstand_decision_4,

                ea.vorstand_uid_5,
                ea.vorstand_uid_5_tstamp,
                ea.vorstand_decision_5,

                u.name AS submitter_name,
                g.name AS ag_name
            FROM einkaufantraege ea
            LEFT JOIN users u
                ON u.uid = ea.uid
            LEFT JOIN `groups` g
                ON g.id = ea.ag_id
            WHERE ea.id = ?
              AND ea.status = 'gestellt'
              AND {$declinedSql} < 3
              AND NOT EXISTS (
                    SELECT 1
                    FROM erstattung e
                    WHERE e.einkaufantrag_id = ea.id
              )
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $purchaseRequestId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $row = null;

        if ($result) {
            $row = mysqli_fetch_assoc($result) ?: null;
            mysqli_free_result($result);
        }

        mysqli_stmt_close($stmt);

        return $row;
    }
}

if (!function_exists('sendErstattungNotificationMail')) {
    function sendErstattungNotificationMail(
        bool $fijionlyaccess,
        int $antragId,
        int $uid,
        string $name,
        string $turm,
        $room,
        string $einrichtungLabel,
        float $betrag,
        string $iban,
        string $pfad,
        string $extraInfo = ''
    ): bool {
        $from = 'system@weh.rwth-aachen.de';
        $backendLink = 'https://backend.weh.rwth-aachen.de/Erstattung.php';

        $recipients = ['kasse@weh.rwth-aachen.de'];

        if ($fijionlyaccess) {
            $recipients[] = 'fiji@weh.rwth-aachen.de';
        }

        $to = implode(',', $recipients);
        $subject = 'New AG reimbursement request #' . $antragId;

        $message = "Hallo,\n\n"
            . "es wurde ein neuer Erstattungsantrag für eine AG eingereicht und muss bearbeitet bzw. erstattet werden.\n\n"
            . "Link zur Bearbeitung:\n"
            . $backendLink . "\n\n"
            . "Antragsdaten:\n"
            . "ID: #" . $antragId . "\n"
            . "Name: " . $name . "\n"
            . "UID: " . $uid . "\n"
            . "Turm: " . erstattung_format_turm($turm) . "\n"
            . "Raum: " . $room . "\n"
            . "AG: " . $einrichtungLabel . "\n"
            . "Betrag: " . number_format($betrag, 2, ',', '.') . " EUR\n"
            . "IBAN: " . $iban . "\n"
            . "Rechnung: " . $pfad . "\n";

        if ($extraInfo !== '') {
            $message .= "\n"
                . "Zugehöriger Einkaufsantrag:\n"
                . $extraInfo . "\n";
        }

        if ($fijionlyaccess) {
            $message .= "\n"
                . "Hinweis:\n"
                . "Aktuell hat nur Fiji Zugriff auf das Hauskonto, also muss Fiji die Überweisung erledigen. "
                . "Die Kassenwarte sind zur Information ebenfalls in dieser Mail.\n";
        }

        $message .= "\n"
            . "Diese Nachricht wurde automatisch vom Backend versendet.\n";

        $headers  = "From: WEH Backend <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        return mail($to, $subject, $message, $headers);
    }
}

$isAuthenticated = !empty($_SESSION['valid']) && !empty($_SESSION['uid']);
$uid = intval($_SESSION['uid'] ?? 0);

$agOptions = $isAuthenticated ? erstattung_get_user_ag_options($conn) : [];
$allowedAgIds = array_map(static function ($agOption) {
    return intval($agOption['id']);
}, $agOptions);

$berechtigt = $isAuthenticated && $uid > 0 && count($agOptions) > 0;

if (!$berechtigt) {
    header("Location: denied.php");
    exit;
}

$singleAgMode = count($agOptions) === 1;
$singleAg = $singleAgMode ? $agOptions[0] : null;

$sessionTurm = strtolower(trim((string)($_SESSION['turm'] ?? 'weh')));
$turmColors = erstattung_get_turm_accent($sessionTurm);
$turmAccent = $turmColors['accent'];
$turmAccentHover = $turmColors['hover'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string)($_POST['form_action'] ?? ''));

    if ($formAction === 'create_einkaufantrag') {
        $agId = intval($_POST['einkauf_ag_id'] ?? 0);
        $titel = trim((string)($_POST['einkauf_titel'] ?? ''));
        $beschreibung = trim((string)($_POST['einkauf_beschreibung'] ?? ''));
        $maxbetrag = erstattung_parse_money($_POST['einkauf_maxbetrag'] ?? '0');

        $agOption = erstattung_get_ag_option_by_id($agOptions, $agId);

        if (!$agOption) {
            $bannerMessage = 'Ungültige AG-Auswahl.';
        } elseif ($titel === '') {
            $bannerMessage = 'Bitte gib einen Titel für den Einkaufsantrag an.';
        } elseif ($beschreibung === '') {
            $bannerMessage = 'Bitte beschreibe den geplanten Einkauf.';
        } elseif ($maxbetrag <= 0) {
            $bannerMessage = 'Bitte gib einen gültigen Maximalbetrag an.';
        } else {
            $tstamp = time();

            $sql = "
                INSERT INTO einkaufantraege
                    (uid, ag_id, tstamp, titel, beschreibung, maxbetrag, status)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'gestellt')
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {
                $bannerMessage = 'Datenbankfehler beim Vorbereiten des Einkaufsantrags.';
            } else {
                mysqli_stmt_bind_param($stmt, 'iiissd', $uid, $agId, $tstamp, $titel, $beschreibung, $maxbetrag);
                $insertOk = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                if ($insertOk) {
                    $bannerMessage = 'Einkaufsantrag erfolgreich eingereicht.';
                    $redirectAfterSubmit = true;
                } else {
                    $bannerMessage = 'Datenbankfehler beim Speichern des Einkaufsantrags.';
                }
            }
        }
    }

    if ($formAction === 'create_erstattung') {
        $selectedPurchaseRequestId = intval($_POST['einkaufantrag_id'] ?? 0);
        $agId = intval($_POST['ag_id'] ?? 0);
        $iban = trim((string)($_POST['iban'] ?? ''));
        $betrag = erstattung_parse_money($_POST['betrag'] ?? '0');

        $linkedPurchaseRequest = null;
        $linkedPurchaseRequestId = 0;
        $betragLimit = 100.0;
        $einrichtung = '';
        $mailExtraInfo = '';

        if ($selectedPurchaseRequestId > 0) {
            $linkedPurchaseRequest = erstattung_fetch_purchase_request_for_refund($conn, $selectedPurchaseRequestId);

            if (!$linkedPurchaseRequest) {
                $bannerMessage = 'Der ausgewählte Einkaufsantrag existiert nicht mehr, wurde abgelehnt oder wurde bereits verwendet.';
            } elseif (!in_array(intval($linkedPurchaseRequest['ag_id']), $allowedAgIds, true)) {
                $bannerMessage = 'Du hast keinen Zugriff auf die AG dieses Einkaufsantrags.';
            } elseif (erstattung_is_purchase_request_declined($linkedPurchaseRequest)) {
                $bannerMessage = 'Dieser Einkaufsantrag wurde vom Vorstand abgelehnt.';
            } elseif (!erstattung_is_purchase_request_approved($linkedPurchaseRequest)) {
                $bannerMessage = 'Dieser Einkaufsantrag wurde noch nicht von drei Vorstandsmitgliedern bestätigt.';
            } else {
                $linkedPurchaseRequestId = intval($linkedPurchaseRequest['id']);
                $agId = intval($linkedPurchaseRequest['ag_id']);
                $betragLimit = (float)$linkedPurchaseRequest['maxbetrag'];
                $einrichtung = 'ag:' . $agId;

                $mailExtraInfo = "Einkaufsantrag-ID: #" . $linkedPurchaseRequestId . "\n"
                    . "Titel: " . $linkedPurchaseRequest['titel'] . "\n"
                    . "Maximal genehmigter Betrag: " . number_format($betragLimit, 2, ',', '.') . " EUR\n";
            }
        } else {
            $agOption = erstattung_get_ag_option_by_id($agOptions, $agId);

            if (!$agOption) {
                $bannerMessage = 'Bitte wähle eine gültige AG aus.';
            } else {
                $betragLimit = 100.0;
                $einrichtung = 'ag:' . $agId;
            }
        }

        if ($bannerMessage === '') {
            if ($uid <= 0 || $einrichtung === '' || $iban === '' || $betrag <= 0) {
                $bannerMessage = 'Ungültige Eingabe.';
            } elseif ($betrag > $betragLimit + 0.00001) {
                $bannerMessage = 'Der Betrag überschreitet den erlaubten Maximalbetrag von '
                    . number_format($betragLimit, 2, ',', '.')
                    . ' €.';
            } elseif (!isset($_FILES['rechnung']) || $_FILES['rechnung']['error'] !== UPLOAD_ERR_OK) {
                $bannerMessage = 'Keine Datei empfangen.';
            } else {
                $submitterName = $_SESSION['name'] ?? '';
                $submitterTurm = $_SESSION['turm'] ?? '';
                $submitterRoom = $_SESSION['userroom'] ?? '';

                $userStmt = mysqli_prepare($conn, "
                    SELECT name, turm, room
                    FROM users
                    WHERE uid = ?
                    LIMIT 1
                ");

                if ($userStmt) {
                    mysqli_stmt_bind_param($userStmt, "i", $uid);
                    mysqli_stmt_execute($userStmt);
                    mysqli_stmt_bind_result($userStmt, $dbName, $dbTurm, $dbRoom);

                    if (mysqli_stmt_fetch($userStmt)) {
                        $submitterName = (string)$dbName;
                        $submitterTurm = (string)$dbTurm;
                        $submitterRoom = $dbRoom;
                    }

                    mysqli_stmt_close($userStmt);
                }

                $upload_dir = 'rechnungen/';
                $tmp_name = $_FILES['rechnung']['tmp_name'];
                $original_name = $_FILES['rechnung']['name'];
                $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

                if (!in_array($extension, $allowed_extensions, true)) {
                    $bannerMessage = 'Ungültiges Dateiformat.';
                } else {
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $base = uniqid('r_', true);
                    $new_filename = $base . '.' . $extension;
                    $target_path = $upload_dir . $new_filename;
                    $counter = 1;

                    while (file_exists($target_path)) {
                        $new_filename = $base . '_' . $counter . '.' . $extension;
                        $target_path = $upload_dir . $new_filename;
                        $counter++;
                    }

                    if (!move_uploaded_file($tmp_name, $target_path)) {
                        $bannerMessage = 'Fehler beim Verschieben der Datei.';
                    } else {
                        $pfad = $target_path;
                        $tstamp = time();

                        if ($linkedPurchaseRequestId > 0) {
                            $sql = "
                                INSERT INTO erstattung
                                    (uid, tstamp, einrichtung, betrag, iban, status, pfad, einkaufantrag_id)
                                VALUES
                                    (?, ?, ?, ?, ?, 0, ?, ?)
                            ";

                            $stmt = mysqli_prepare($conn, $sql);

                            if (!$stmt) {
                                $bannerMessage = 'Datenbankfehler beim Vorbereiten.';
                            } else {
                                mysqli_stmt_bind_param(
                                    $stmt,
                                    'iisdssi',
                                    $uid,
                                    $tstamp,
                                    $einrichtung,
                                    $betrag,
                                    $iban,
                                    $pfad,
                                    $linkedPurchaseRequestId
                                );

                                $insertOk = mysqli_stmt_execute($stmt);
                                $inserted_id = mysqli_insert_id($conn);
                                mysqli_stmt_close($stmt);

                                if (!$insertOk || $inserted_id <= 0) {
                                    $bannerMessage = 'Datenbankfehler beim Speichern.';
                                } else {
                                    $updateStmt = mysqli_prepare($conn, "
                                        UPDATE einkaufantraege
                                        SET status = 'geschlossen'
                                        WHERE id = ?
                                        LIMIT 1
                                    ");

                                    if ($updateStmt) {
                                        mysqli_stmt_bind_param($updateStmt, 'i', $linkedPurchaseRequestId);
                                        mysqli_stmt_execute($updateStmt);
                                        mysqli_stmt_close($updateStmt);
                                    }

                                    $einrichtungLabel = erstattung_format_einrichtung($conn, $einrichtung);

                                    $mailSent = sendErstattungNotificationMail(
                                        $fijionlyaccess,
                                        $inserted_id,
                                        $uid,
                                        $submitterName,
                                        $submitterTurm,
                                        $submitterRoom,
                                        $einrichtungLabel,
                                        $betrag,
                                        $iban,
                                        $pfad,
                                        $mailExtraInfo
                                    );

                                    if ($mailSent) {
                                        $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Kasse wurde informiert.';
                                    } else {
                                        $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Mail konnte nicht versendet werden.';
                                    }

                                    $redirectAfterSubmit = true;
                                }
                            }
                        } else {
                            $sql = "
                                INSERT INTO erstattung
                                    (uid, tstamp, einrichtung, betrag, iban, status, pfad, einkaufantrag_id)
                                VALUES
                                    (?, ?, ?, ?, ?, 0, ?, NULL)
                            ";

                            $stmt = mysqli_prepare($conn, $sql);

                            if (!$stmt) {
                                $bannerMessage = 'Datenbankfehler beim Vorbereiten.';
                            } else {
                                mysqli_stmt_bind_param(
                                    $stmt,
                                    'iisdss',
                                    $uid,
                                    $tstamp,
                                    $einrichtung,
                                    $betrag,
                                    $iban,
                                    $pfad
                                );

                                $insertOk = mysqli_stmt_execute($stmt);
                                $inserted_id = mysqli_insert_id($conn);
                                mysqli_stmt_close($stmt);

                                if (!$insertOk || $inserted_id <= 0) {
                                    $bannerMessage = 'Datenbankfehler beim Speichern.';
                                } else {
                                    $einrichtungLabel = erstattung_format_einrichtung($conn, $einrichtung);

                                    $mailSent = sendErstattungNotificationMail(
                                        $fijionlyaccess,
                                        $inserted_id,
                                        $uid,
                                        $submitterName,
                                        $submitterTurm,
                                        $submitterRoom,
                                        $einrichtungLabel,
                                        $betrag,
                                        $iban,
                                        $pfad
                                    );

                                    if ($mailSent) {
                                        $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Kasse wurde informiert.';
                                    } else {
                                        $bannerMessage = 'Antrag erfolgreich eingereicht.<br>Mail konnte nicht versendet werden.';
                                    }

                                    $redirectAfterSubmit = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$openPurchaseRequests = erstattung_fetch_open_purchase_requests($conn, $allowedAgIds);

$approvedPurchaseRequests = array_values(array_filter($openPurchaseRequests, static function ($request) {
    return erstattung_is_purchase_request_approved($request)
        && !erstattung_is_purchase_request_declined($request);
}));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        :root {
            --turm-accent: <?= erstattung_h($turmAccent) ?>;
            --turm-accent-hover: <?= erstattung_h($turmAccentHover) ?>;
            --nav-accent: <?= erstattung_h($turmAccent) ?>;
            --card-bg: #1c1c1c;
            --card-bg-soft: #181818;
            --field-bg: #252525;
            --text-main: #e0e0e0;
            --text-muted: #cccccc;
            --border-soft: #333333;
            --decline-bg: #9b1c1c;
            --decline-border: #d83a3a;
        }

        body {
            background-color: #121212;
            color: var(--text-main);
            font-family: sans-serif;
        }

        .ag-refund-page {
            max-width: 1250px;
            margin: 3em auto;
            padding: 0 1.5em;
        }

        .ag-intro-card,
        .ag-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.02);
        }

        .ag-intro-card {
            padding: 1.5em 1.8em;
            margin-bottom: 1.5em;
            border-left: 4px solid var(--turm-accent);
        }

        .ag-intro-card h1 {
            color: var(--turm-accent);
            margin: 0 0 0.7em 0;
            font-size: 1.9em;
        }

        .ag-intro-card p {
            color: var(--text-muted);
            line-height: 1.5;
            margin: 0.65em 0;
        }

        .ag-intro-card strong {
            color: #ffffff;
        }

        .ag-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(360px, 0.95fr);
            gap: 1.5em;
            align-items: start;
        }

        .ag-card {
            padding: 1.4em;
        }

        .ag-card + .ag-card {
            margin-top: 1.5em;
        }

        .ag-card h2 {
            color: var(--turm-accent);
            margin: 0 0 0.8em 0;
            font-size: 1.45em;
        }

        .ag-card p {
            color: var(--text-muted);
            line-height: 1.45;
        }

        .ag-card label {
            display: block;
            margin-top: 1em;
            margin-bottom: 0.35em;
            color: #a0ffa0;
            font-weight: 600;
        }

        .ag-card input,
        .ag-card select,
        .ag-card textarea,
        .ag-card button {
            box-sizing: border-box;
            display: block;
            width: 100%;
            padding: 0.75em;
            background-color: var(--field-bg);
            border: 1px solid var(--turm-accent);
            color: #e0ffe0;
            border-radius: 5px;
            font: inherit;
        }

        .ag-card select:disabled {
            opacity: 0.75;
            cursor: not-allowed;
        }

        .ag-card textarea {
            min-height: 150px;
            resize: vertical;
        }

        .ag-card input[type="file"] {
            padding: 0.65em;
        }

        .ag-card button {
            margin-top: 1.2em;
            background-color: var(--turm-accent);
            color: #000000;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .ag-card button:hover {
            background-color: var(--turm-accent-hover);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1em;
        }

        .form-grid-full {
            grid-column: 1 / -1;
        }

        .static-field {
            box-sizing: border-box;
            width: 100%;
            min-height: 43px;
            padding: 0.75em;
            background-color: var(--card-bg-soft);
            border: 1px solid var(--turm-accent);
            color: #e0ffe0;
            border-radius: 5px;
            display: flex;
            align-items: center;
            font-weight: 600;
        }

        .limit-hint,
        .selected-purchase-info {
            margin-top: 0.75em;
            padding: 0.75em;
            border-radius: 6px;
            background-color: var(--card-bg-soft);
            border: 1px solid var(--border-soft);
            color: var(--text-muted);
            font-size: 0.95em;
        }

        .selected-purchase-info {
            display: none;
            border-color: var(--turm-accent);
            color: #e0ffe0;
        }

        .purchase-list {
            display: flex;
            flex-direction: column;
            gap: 1em;
        }

        .purchase-card {
            padding: 1em;
            border-radius: 8px;
            background-color: var(--card-bg-soft);
            border: 1px solid var(--border-soft);
        }

        .purchase-card.is-approved {
            border-color: var(--turm-accent);
        }

        .purchase-card-header {
            display: flex;
            justify-content: space-between;
            gap: 1em;
            align-items: flex-start;
            margin-bottom: 0.5em;
        }

        .purchase-title {
            color: #ffffff;
            font-weight: bold;
            font-size: 1.08em;
        }

        .purchase-meta {
            color: var(--text-muted);
            font-size: 0.92em;
            line-height: 1.45;
            margin-top: 0.35em;
        }

        .purchase-description {
            white-space: normal;
            margin: 0.8em 0;
            color: var(--text-muted);
        }

        .status-badge {
            flex: 0 0 auto;
            padding: 0.25em 0.6em;
            border-radius: 999px;
            background-color: #303030;
            color: var(--text-muted);
            border: 1px solid #555555;
            font-size: 0.85em;
            white-space: nowrap;
        }

        .status-badge.approved {
            background-color: var(--turm-accent);
            color: #000000;
            border-color: var(--turm-accent);
            font-weight: bold;
        }

        .status-badge.has-declines:not(.approved) {
            border-color: var(--decline-border);
            color: #ffcccc;
        }

        .approval-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0.5em;
            margin-top: 0.8em;
        }

        .approval-box {
            padding: 0.55em 0.4em;
            text-align: center;
            border-radius: 6px;
            background-color: #2a2a2a;
            border: 1px solid #555555;
            color: #aaaaaa;
            font-size: 0.84em;
            line-height: 1.25;
        }

        .approval-box.approved {
            background-color: var(--turm-accent);
            border-color: var(--turm-accent);
            color: #000000;
            font-weight: bold;
        }

        .approval-box.declined {
            background-color: var(--decline-bg);
            border-color: var(--decline-border);
            color: #ffffff;
            font-weight: bold;
        }

        .empty-state {
            padding: 1em;
            border-radius: 8px;
            background-color: var(--card-bg-soft);
            border: 1px dashed #555555;
            color: var(--text-muted);
        }

        .success-banner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            min-width: 320px;
            max-width: 90vw;
            background: rgba(0, 0, 0, 0.9);
            padding: 1em 2em;
            color: #ffffff;
            font-size: 1.8em;
            font-weight: bold;
            border: 2px solid var(--turm-accent);
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 0 10px var(--turm-accent);
            text-align: center;
        }

        .navbar-welcome-name {
            color: var(--turm-accent) !important;
        }

        .navbar-menu-wrapper .header-menu .center-btn:hover,
        .navbar-menu-wrapper .header-submenu button:hover,
        .header-submenu button:hover {
            background-color: var(--turm-accent) !important;
            color: #000 !important;
        }

        @media (max-width: 900px) {
            .ag-layout {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .ag-refund-page {
                margin: 1.5em auto;
                padding: 0 1em;
            }

            .ag-intro-card,
            .ag-card {
                padding: 1.2em;
            }

            .purchase-card-header {
                flex-direction: column;
            }

            .approval-row {
                grid-template-columns: 1fr;
            }

            .success-banner {
                font-size: 1.25em;
            }
        }
    </style>
</head>

<body>
<?php
require_once('template.php');
load_menu();
?>

<?php if ($bannerMessage !== ''): ?>
    <div class="success-banner">
        <?= $bannerMessage ?>
    </div>
<?php endif; ?>

<?php if ($redirectAfterSubmit): ?>
    <style>
        html,
        body {
            cursor: wait;
        }
    </style>

    <script>
        setTimeout(function() {
            window.location.href = 'Erstattungsantrag.php';
        }, 1800);
    </script>
<?php endif; ?>

<div class="ag-refund-page">
    <section class="ag-intro-card">
        <h1>Erstattungen und Einkaufsanträge für AGs</h1>

        <p>
            Hier kannst du Einkäufe für deine AG von den Kassenwarten des WEH e.V. erstatten lassen.
        </p>

        <p>
            Wenn ein Einkauf eindeutig mit den Aufgaben deiner AG zu tun hat und unter <strong>100,00 €</strong> liegt,
            kann er ohne weitere Vorabklärung gekauft werden. Danach kannst du hier direkt einen Erstattungsantrag mit
            Rechnung hochladen.
        </p>

        <p>
            Wenn du über <strong>100,00 €</strong> einkaufen willst oder der Zweck des Einkaufs nicht eindeutig mit den
            Aufgaben der AG zusammenhängt, muss der Vorstand den Kauf erst genehmigen. Dafür kannst du links
            einen Einkaufsantrag stellen. Sobald drei Vorstandsmitglieder den Antrag bestätigen, wird die AG via Mail informiert und der Kauf kann regulär getätigt werden.
            Wenn drei Vorstandsmitglieder ablehnen, wird eure AG via Mail informiert.
        </p>
    </section>

    <div class="ag-layout">
        <div>
            <section class="ag-card">
                <h2>Offene Einkaufsanträge</h2>

                <?php if (count($openPurchaseRequests) === 0): ?>
                    <div class="empty-state">
                        Aktuell gibt es für deine AGs keine offenen Einkaufsanträge.
                    </div>
                <?php else: ?>
                    <div class="purchase-list">
                        <?php foreach ($openPurchaseRequests as $request): ?>
                            <?php
                            $approvalCount = erstattung_approval_count($request);
                            $declineCount = erstattung_decline_count($request);
                            $isApproved = erstattung_is_purchase_request_approved($request);
                            ?>
                            <div class="purchase-card <?= $isApproved ? 'is-approved' : '' ?>">
                                <div class="purchase-card-header">
                                    <div>
                                        <div class="purchase-title">
                                            <?= erstattung_h($request['titel']) ?>
                                        </div>

                                        <div class="purchase-meta">
                                            AG: <?= erstattung_h($request['ag_name'] ?: ('AG #' . intval($request['ag_id']))) ?><br>
                                            Eingereicht von:
                                            <?= erstattung_h($request['submitter_name'] ?: ('UID ' . intval($request['uid']))) ?><br>
                                            Eingereicht am:
                                            <?= erstattung_h(erstattung_format_tstamp($request['tstamp'])) ?><br>
                                            Maximalbetrag:
                                            <?= number_format((float)$request['maxbetrag'], 2, ',', '.') ?> €
                                        </div>
                                    </div>

                                    <div class="status-badge <?= $isApproved ? 'approved' : '' ?> <?= $declineCount > 0 ? 'has-declines' : '' ?>">
                                        <?= intval($approvalCount) ?>/3 Zusagen
                                        <?php if ($declineCount > 0): ?>
                                            · <?= intval($declineCount) ?>/3 Ablehnungen
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="purchase-description">
                                    <?= nl2br(erstattung_h($request['beschreibung'])) ?>
                                </div>

                                <div class="approval-row">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php
                                        $uidKey = 'vorstand_uid_' . $i;
                                        $decisionKey = 'vorstand_decision_' . $i;

                                        $vorstandUid = intval($request[$uidKey] ?? 0);
                                        $decision = (string)($request[$decisionKey] ?? '');

                                        $boxClass = '';
                                        $boxLabel = 'Offen';

                                        if ($vorstandUid > 0 && ($decision === 'accepted' || $decision === '')) {
                                            $boxClass = 'approved';
                                            $boxLabel = 'Zusage';
                                        } elseif ($vorstandUid > 0 && $decision === 'declined') {
                                            $boxClass = 'declined';
                                            $boxLabel = 'Ablehnung';
                                        }
                                        ?>
                                        <div class="approval-box <?= erstattung_h($boxClass) ?>">
                                            Vorstand <?= intval($i) ?><br>
                                            <?= erstattung_h($boxLabel) ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="ag-card">
                <h2>Einkaufsantrag stellen</h2>

                <form method="post">
                    <input type="hidden" name="form_action" value="create_einkaufantrag">

                    <div class="form-grid">
                        <div>
                            <label for="einkauf_ag_id">AG</label>

                            <?php if ($singleAgMode && $singleAg): ?>
                                <input
                                    type="hidden"
                                    name="einkauf_ag_id"
                                    id="einkauf_ag_id"
                                    value="<?= intval($singleAg['id']) ?>"
                                >
                                <div class="static-field">
                                    <?= erstattung_h($singleAg['name']) ?>
                                </div>
                            <?php else: ?>
                                <select name="einkauf_ag_id" id="einkauf_ag_id" required>
                                    <option value="">-- Bitte wählen --</option>

                                    <?php foreach ($agOptions as $agOption): ?>
                                        <option value="<?= intval($agOption['id']) ?>">
                                            <?= erstattung_h($agOption['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="einkauf_maxbetrag">Maximalbetrag in Euro</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                name="einkauf_maxbetrag"
                                id="einkauf_maxbetrag"
                                placeholder="€"
                                required
                            >
                        </div>

                        <div class="form-grid-full">
                            <label for="einkauf_titel">Titel</label>
                            <input
                                type="text"
                                name="einkauf_titel"
                                id="einkauf_titel"
                                maxlength="255"
                                placeholder=""
                                required
                            >
                        </div>

                        <div class="form-grid-full">
                            <label for="einkauf_beschreibung">Beschreibung und Begründung</label>
                            <textarea
                                name="einkauf_beschreibung"
                                id="einkauf_beschreibung"
                                placeholder=""
                                required
                            ></textarea>
                        </div>
                    </div>

                    <button type="submit">
                        Einkaufsantrag einreichen
                    </button>
                </form>
            </section>
        </div>

        <section class="ag-card">
            <h2>Erstattungsantrag stellen</h2>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_action" value="create_erstattung">

                <label for="einkaufantrag_id">Genehmigter Einkaufsantrag</label>
                <select name="einkaufantrag_id" id="einkaufantrag_id">
                    <option
                        value="0"
                        data-ag-id=""
                        data-maxbetrag="100.00"
                        data-title=""
                    >
                        Kein Einkaufsantrag — normaler AG-Einkauf bis 100,00 €
                    </option>

                    <?php foreach ($approvedPurchaseRequests as $request): ?>
                        <option
                            value="<?= intval($request['id']) ?>"
                            data-ag-id="<?= intval($request['ag_id']) ?>"
                            data-maxbetrag="<?= erstattung_h(number_format((float)$request['maxbetrag'], 2, '.', '')) ?>"
                            data-title="<?= erstattung_h($request['titel']) ?>"
                        >
                            #<?= intval($request['id']) ?>
                            —
                            <?= erstattung_h($request['titel']) ?>
                            —
                            max.
                            <?= number_format((float)$request['maxbetrag'], 2, ',', '.') ?> €
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="selected_purchase_info" class="selected-purchase-info"></div>

                <label for="ag_id">AG</label>

                <?php if ($singleAgMode && $singleAg): ?>
                    <input
                        type="hidden"
                        name="ag_id"
                        id="ag_id"
                        value="<?= intval($singleAg['id']) ?>"
                    >
                    <div class="static-field" id="ag_static_display">
                        <?= erstattung_h($singleAg['name']) ?>
                    </div>
                <?php else: ?>
                    <select name="ag_id" id="ag_id" required>
                        <option value="">-- Bitte wählen --</option>

                        <?php foreach ($agOptions as $agOption): ?>
                            <option value="<?= intval($agOption['id']) ?>">
                                <?= erstattung_h($agOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-grid-full">
                        <label for="rechnung">Rechnung hochladen (PDF oder Bild)</label>
                        <input
                            type="file"
                            name="rechnung"
                            id="rechnung"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >
                    </div>

                    <div>
                        <label for="betrag">Preis in Euro</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            max="100.00"
                            name="betrag"
                            id="betrag"
                            placeholder="€"
                            required
                        >
                    </div>

                    <div>
                        <label for="iban">IBAN für Erstattung</label>
                        <input
                            type="text"
                            name="iban"
                            id="iban"
                            placeholder="DE37 3905 0000 1070 3345 84"
                            required
                        >
                    </div>
                </div>

                <div id="betrag_limit_label" class="limit-hint">
                    Maximal erstattbarer Betrag: 100,00 €
                </div>

                <button type="submit">
                    Erstattungsantrag einreichen
                </button>
            </form>
        </section>
    </div>
</div>

<script>
    (function() {
        const purchaseSelect = document.getElementById('einkaufantrag_id');
        const agField = document.getElementById('ag_id');
        const amountInput = document.getElementById('betrag');
        const limitLabel = document.getElementById('betrag_limit_label');
        const selectedInfo = document.getElementById('selected_purchase_info');

        function formatEuro(value) {
            return value.toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' €';
        }

        function updateRefundFormByPurchaseRequest() {
            if (!purchaseSelect || !agField || !amountInput || !limitLabel || !selectedInfo) {
                return;
            }

            const selectedOption = purchaseSelect.options[purchaseSelect.selectedIndex];
            const purchaseRequestId = parseInt(selectedOption.value || '0', 10);
            const maxbetrag = parseFloat(selectedOption.getAttribute('data-maxbetrag') || '100.00');
            const agId = selectedOption.getAttribute('data-ag-id') || '';
            const title = selectedOption.getAttribute('data-title') || '';

            amountInput.max = maxbetrag.toFixed(2);
            limitLabel.textContent = 'Maximal erstattbarer Betrag: ' + formatEuro(maxbetrag);

            if (amountInput.value !== '' && parseFloat(amountInput.value) > maxbetrag) {
                amountInput.value = maxbetrag.toFixed(2);
            }

            if (purchaseRequestId > 0 && agId !== '') {
                agField.value = agId;

                if (agField.tagName.toLowerCase() === 'select') {
                    agField.disabled = true;
                }

                selectedInfo.textContent = 'Erstattung für genehmigten Einkaufsantrag: ' + title;
                selectedInfo.style.display = 'block';
            } else {
                if (agField.tagName.toLowerCase() === 'select') {
                    agField.disabled = false;
                }

                selectedInfo.textContent = '';
                selectedInfo.style.display = 'none';
            }
        }

        if (purchaseSelect) {
            purchaseSelect.addEventListener('change', updateRefundFormByPurchaseRequest);
            updateRefundFormByPurchaseRequest();
        }
    })();
</script>

</body>
</html>