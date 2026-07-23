<?php
ob_start();
session_start();

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjax) {
    /*
     * PHP-Warnungen dürfen die JSON-Antwort nicht mit HTML vermischen.
     * Fehler werden weiterhin im Server-Log erfasst.
     */
    ini_set('display_errors', '0');
}

register_shutdown_function(function () use ($isAjax) {
    if (!$isAjax) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);

    if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            array(
                'success' => false,
                'message' => 'Interner PHP-Fehler. Details stehen im Server-Log.'
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
});

require_once('template.php');
mysqli_set_charset($conn, 'utf8');

define('COMMUNITY_RECIPIENT', 'community@weh.rwth-aachen.de');

$allowedGroupIds = array(10, 11, 23);


function sendJson($data, $statusCode = 200)
{
    /*
     * template.php oder andere eingebundene Dateien können Whitespace,
     * Warnungen oder sonstige Ausgabe erzeugen. Für AJAX wird alles
     * verworfen, damit ausschließlich gültiges JSON zurückkommt.
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function stopRequest($message, $statusCode, $isAjax)
{
    if ($isAjax) {
        sendJson(
            array(
                'success' => false,
                'message' => $message
            ),
            $statusCode
        );
    }

    http_response_code($statusCode);
    die(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
}


function getTemplateForGroup($conn, $templateId, $groupId)
{
    $sql = "
        SELECT
            id,
            name,
            subject,
            body
        FROM ag_mail_templates
        WHERE id = ?
          AND group_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $templateId, $groupId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result(
        $stmt,
        $id,
        $name,
        $subject,
        $body
    );

    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'id' => (int)$id,
        'name' => (string)$name,
        'subject' => (string)$subject,
        'body' => (string)$body
    );
}


/*
|--------------------------------------------------------------------------
| Anmeldung prüfen
|--------------------------------------------------------------------------
*/

if (!auth($conn) || empty($_SESSION['valid'])) {
    if ($isAjax) {
        sendJson(
            array(
                'success' => false,
                'message' => 'Die Sitzung ist nicht mehr gültig.'
            ),
            401
        );
    }

    header('Location: denied.php');
    exit;
}

$currentUid = isset($_SESSION['uid'])
    ? (int)$_SESSION['uid']
    : 0;

if ($currentUid <= 0) {
    stopRequest(
        'Es konnte keine gültige Benutzer-ID ermittelt werden.',
        403,
        $isAjax
    );
}



/*
|--------------------------------------------------------------------------
| Verfügbare AGs laden und Auswahl bestimmen
|--------------------------------------------------------------------------
|
| Diese Seite ist ausschließlich für die Gruppen 10, 11 und 23 vorgesehen.
| Normale Benutzer sehen nur Gruppen, deren groups.session bei ihnen true ist.
| Webmaster dürfen frei zwischen allen drei Gruppen wählen.
|
*/

$allowedGroupIdsSql = implode(
    ',',
    array_map('intval', $allowedGroupIds)
);

$sql = "
    SELECT
        id,
        name,
        mail,
        session
    FROM `groups`
    WHERE id IN (" . $allowedGroupIdsSql . ")
    ORDER BY FIELD(id, 10, 11, 23)
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    stopRequest(
        'Die AGs konnten nicht geladen werden: '
        . mysqli_error($conn),
        500,
        $isAjax
    );
}

$allGroupsById = array();

while ($row = mysqli_fetch_assoc($result)) {
    $loadedGroupId = (int)$row['id'];

    $allGroupsById[$loadedGroupId] = array(
        'id' => $loadedGroupId,
        'name' => trim((string)$row['name']),
        'mail' => trim((string)$row['mail']),
        'session' => trim((string)$row['session'])
    );
}

mysqli_free_result($result);

$hasWebmasterPermission =
    isset($_SESSION['Webmaster'])
    && $_SESSION['Webmaster'] == true;

$availableGroups = array();

foreach ($allGroupsById as $availableGroupId => $availableGroup) {
    if ($hasWebmasterPermission) {
        $availableGroups[$availableGroupId] = $availableGroup;
        continue;
    }

    $sessionName = $availableGroup['session'];

    if (
        $sessionName !== ''
        && isset($_SESSION[$sessionName])
        && $_SESSION[$sessionName] == true
    ) {
        $availableGroups[$availableGroupId] = $availableGroup;
    }
}

if (count($availableGroups) === 0) {
    if ($isAjax) {
        sendJson(
            array(
                'success' => false,
                'message' => 'Du hast keinen Zugriff auf eine der freigegebenen AGs.'
            ),
            403
        );
    }

    header('Location: denied.php');
    exit;
}

$pagePath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!is_string($pagePath) || $pagePath === '') {
    $pagePath = $_SERVER['PHP_SELF'];
}

$requestedGroupId = isset($_GET['group_id'])
    ? (int)$_GET['group_id']
    : 0;

$showGroupSelection = false;
$selectionMessage = '';

if ($requestedGroupId > 0 && isset($availableGroups[$requestedGroupId])) {
    $groupId = $requestedGroupId;
} elseif (!$hasWebmasterPermission && count($availableGroups) === 1) {
    $availableGroupIds = array_keys($availableGroups);
    $groupId = (int)$availableGroupIds[0];

    if (!$isAjax) {
        header(
            'Location: '
            . $pagePath
            . '?group_id='
            . $groupId
        );
        exit;
    }
} else {
    $groupId = 0;
    $showGroupSelection = true;

    if ($requestedGroupId > 0) {
        $selectionMessage =
            'Die gewählte AG ist nicht verfügbar oder du hast dafür keine Berechtigung.';
    }
}

if ($showGroupSelection) {
    if ($isAjax) {
        sendJson(
            array(
                'success' => false,
                'message' => 'Bitte zuerst eine AG auswählen.'
            ),
            400
        );
    }

    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="format-detection" content="telephone=no">
        <title>AG-Rundmails – AG auswählen</title>
        <link rel="stylesheet" href="WEH.css" media="screen">

        <style>
            :root {
                --agmail-primary: #11a50d;
                --agmail-panel: #222;
                --agmail-border: #444;
                --agmail-text: #f2f2f2;
                --agmail-muted: #aaa;
            }

            .agmail-select-page {
                width: min(900px, calc(100% - 30px));
                margin: 35px auto 50px;
                color: var(--agmail-text) !important;
            }

            .agmail-select-page h1,
            .agmail-select-page p,
            .agmail-select-page span,
            .agmail-select-page div {
                color: inherit;
            }

            .agmail-select-title {
                margin: 0;
                color: var(--agmail-text) !important;
                font-size: 28px;
            }

            .agmail-select-subtitle {
                margin: 8px 0 22px;
                color: var(--agmail-muted) !important;
            }

            .agmail-select-message {
                padding: 11px 13px;
                margin-bottom: 15px;
                border: 1px solid #c33737;
                border-radius: 4px;
                background: var(--agmail-panel);
                color: #ff8d8d !important;
            }

            .agmail-group-list {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 14px;
            }

            .agmail-group-card {
                display: block;
                padding: 18px;
                border: 1px solid var(--agmail-border);
                border-radius: 5px;
                background: var(--agmail-panel);
                color: var(--agmail-text) !important;
                text-decoration: none;
            }

            .agmail-group-card:hover,
            .agmail-group-card:focus {
                border-color: var(--agmail-primary);
                outline: none;
            }

            .agmail-group-name {
                display: block;
                margin-bottom: 8px;
                color: var(--agmail-text) !important;
                font-size: 20px;
                font-weight: bold;
            }

            .agmail-group-mail {
                display: block;
                color: var(--agmail-muted) !important;
                overflow-wrap: anywhere;
            }

            .agmail-group-action {
                display: inline-block;
                margin-top: 16px;
                padding: 7px 12px;
                border-radius: 3px;
                background: var(--agmail-primary);
                color: #fff !important;
                font-weight: bold;
            }
        </style>
    </head>

    <body>

    <?php load_menu(); ?>

    <main class="agmail-select-page">
        <h1 class="agmail-select-title">AG auswählen</h1>

        <p class="agmail-select-subtitle">
            Wähle die AG aus, deren Rundmail-Vorlagen du verwalten möchtest.
        </p>

        <?php if ($selectionMessage !== '') { ?>
            <div class="agmail-select-message">
                <?php
                    echo htmlspecialchars(
                        $selectionMessage,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                ?>
            </div>
        <?php } ?>

        <div class="agmail-group-list">
            <?php foreach ($availableGroups as $selectableGroup) { ?>
                <a
                    class="agmail-group-card"
                    href="<?php
                        echo htmlspecialchars(
                            $pagePath
                            . '?group_id='
                            . (int)$selectableGroup['id'],
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>"
                >
                    <span class="agmail-group-name">
                        <?php
                            echo htmlspecialchars(
                                $selectableGroup['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            );
                        ?>
                    </span>

                    <span class="agmail-group-mail">
                        <?php
                            echo htmlspecialchars(
                                $selectableGroup['mail'],
                                ENT_QUOTES,
                                'UTF-8'
                            );
                        ?>
                    </span>

                    <span class="agmail-group-action">
                        Auswählen
                    </span>
                </a>
            <?php } ?>
        </div>
    </main>

    </body>
    </html>
    <?php

    $conn->close();
    exit;
}

$selectedGroup = $availableGroups[$groupId];

$groupName = $selectedGroup['name'];
$groupMail = $selectedGroup['mail'];
$groupSession = $selectedGroup['session'];

$showGroupBackButton =
    $hasWebmasterPermission
    || count($availableGroups) > 1;


/*
|--------------------------------------------------------------------------
| CSRF-Schutz
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['ag_mail_templates_csrf'])
    || !is_string($_SESSION['ag_mail_templates_csrf'])
) {
    $_SESSION['ag_mail_templates_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['ag_mail_templates_csrf'];


/*
|--------------------------------------------------------------------------
| AJAX
|--------------------------------------------------------------------------
*/

if ($isAjax) {
    $action = '';

    if (isset($_GET['action'])) {
        $action = (string)$_GET['action'];
    } elseif (isset($_POST['action'])) {
        $action = (string)$_POST['action'];
    }


    /*
    |--------------------------------------------------------------------------
    | Vorlagen laden
    |--------------------------------------------------------------------------
    */

    if ($action === 'list') {
        $sql = "
            SELECT
                t.id,
                t.name,
                t.subject,
                t.body,

                t.created_at,
                t.created_by,
                COALESCE(
                    NULLIF(created_user.name, ''),
                    NULLIF(created_user.username, ''),
                    CONCAT('UID ', t.created_by)
                ) AS created_by_name,

                t.updated_at,
                t.updated_by,
                COALESCE(
                    NULLIF(updated_user.name, ''),
                    NULLIF(updated_user.username, ''),
                    CONCAT('UID ', t.updated_by)
                ) AS updated_by_name,

                t.last_sent_at,
                t.last_sent_by,
                CASE
                    WHEN t.last_sent_by IS NULL THEN NULL
                    ELSE COALESCE(
                        NULLIF(sent_user.name, ''),
                        NULLIF(sent_user.username, ''),
                        CONCAT('UID ', t.last_sent_by)
                    )
                END AS last_sent_by_name

            FROM ag_mail_templates AS t

            LEFT JOIN users AS created_user
                ON created_user.uid = t.created_by

            LEFT JOIN users AS updated_user
                ON updated_user.uid = t.updated_by

            LEFT JOIN users AS sent_user
                ON sent_user.uid = t.last_sent_by

            WHERE t.group_id = ?

            ORDER BY
                t.name ASC,
                t.id ASC
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlagenabfrage konnte nicht vorbereitet werden: '
                        . mysqli_error($conn)
                ),
                500
            );
        }

        mysqli_stmt_bind_param($stmt, 'i', $groupId);

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlagen konnten nicht geladen werden: '
                        . $errorMessage
                ),
                500
            );
        }

        mysqli_stmt_bind_result(
            $stmt,
            $templateId,
            $templateName,
            $templateSubject,
            $templateBody,

            $createdAt,
            $createdBy,
            $createdByName,

            $updatedAt,
            $updatedBy,
            $updatedByName,

            $lastSentAt,
            $lastSentBy,
            $lastSentByName
        );

        $templates = array();

        while (mysqli_stmt_fetch($stmt)) {
            $templates[] = array(
                'id' => (int)$templateId,
                'name' => (string)$templateName,
                'subject' => (string)$templateSubject,
                'body' => (string)$templateBody,

                'created_at' => (int)$createdAt,
                'created_by' => (int)$createdBy,
                'created_by_name' => (string)$createdByName,

                'updated_at' => (int)$updatedAt,
                'updated_by' => (int)$updatedBy,
                'updated_by_name' => (string)$updatedByName,

                'last_sent_at' => $lastSentAt !== null
                    ? (int)$lastSentAt
                    : null,

                'last_sent_by' => $lastSentBy !== null
                    ? (int)$lastSentBy
                    : null,

                'last_sent_by_name' => $lastSentByName !== null
                    ? (string)$lastSentByName
                    : null
            );
        }

        mysqli_stmt_close($stmt);

        sendJson(
            array(
                'success' => true,
                'templates' => $templates
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Verändernde Aktionen nur per POST
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(
            array(
                'success' => false,
                'message' => 'Ungültige Anfrage.'
            ),
            405
        );
    }

    $submittedCsrf = isset($_POST['csrf'])
        ? (string)$_POST['csrf']
        : '';

    if (
        $submittedCsrf === ''
        || !hash_equals($csrfToken, $submittedCsrf)
    ) {
        sendJson(
            array(
                'success' => false,
                'message' => 'Die Sicherheitsprüfung ist fehlgeschlagen.'
            ),
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Vorlage anlegen oder bearbeiten
    |--------------------------------------------------------------------------
    */

    if ($action === 'save') {
        $templateId = isset($_POST['id'])
            ? (int)$_POST['id']
            : 0;

        $templateName = isset($_POST['name'])
            ? trim((string)$_POST['name'])
            : '';

        $templateSubject = isset($_POST['subject'])
            ? trim((string)$_POST['subject'])
            : '';

        $templateBody = isset($_POST['body'])
            ? trim((string)$_POST['body'])
            : '';

        if ($templateName === '') {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Bitte einen Namen für die Vorlage eingeben.'
                ),
                422
            );
        }

        if ($templateSubject === '') {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Bitte einen Betreff eingeben.'
                ),
                422
            );
        }

        if ($templateBody === '') {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Bitte eine Nachricht eingeben.'
                ),
                422
            );
        }

        if (mb_strlen($templateName, 'UTF-8') > 100) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Der Vorlagenname darf maximal 100 Zeichen lang sein.'
                ),
                422
            );
        }

        if (mb_strlen($templateSubject, 'UTF-8') > 255) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Der Betreff darf maximal 255 Zeichen lang sein.'
                ),
                422
            );
        }

        $currentTime = time();

        if ($templateId > 0) {
            $existingTemplate = getTemplateForGroup(
                $conn,
                $templateId,
                $groupId
            );

            if ($existingTemplate === false) {
                sendJson(
                    array(
                        'success' => false,
                        'message' => 'Die Vorlage konnte nicht geprüft werden: '
                            . mysqli_error($conn)
                    ),
                    500
                );
            }

            if ($existingTemplate === null) {
                sendJson(
                    array(
                        'success' => false,
                        'message' => 'Die Vorlage wurde nicht gefunden oder gehört nicht zu dieser AG.'
                    ),
                    404
                );
            }

            $sql = "
                UPDATE ag_mail_templates
                SET
                    name = ?,
                    subject = ?,
                    body = ?,
                    updated_at = ?,
                    updated_by = ?
                WHERE id = ?
                  AND group_id = ?
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {
                sendJson(
                    array(
                        'success' => false,
                        'message' => 'Die Änderung konnte nicht vorbereitet werden: '
                            . mysqli_error($conn)
                    ),
                    500
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'sssiiii',
                $templateName,
                $templateSubject,
                $templateBody,
                $currentTime,
                $currentUid,
                $templateId,
                $groupId
            );

            if (!mysqli_stmt_execute($stmt)) {
                $errorMessage = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);

                sendJson(
                    array(
                        'success' => false,
                        'message' => 'Die Vorlage konnte nicht gespeichert werden: '
                            . $errorMessage
                    ),
                    500
                );
            }

            mysqli_stmt_close($stmt);

            sendJson(
                array(
                    'success' => true,
                    'message' => 'Die Vorlage wurde aktualisiert.'
                )
            );
        }

        $sql = "
            INSERT INTO ag_mail_templates (
                group_id,
                name,
                subject,
                body,
                created_at,
                created_by,
                updated_at,
                updated_by,
                last_sent_at,
                last_sent_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die neue Vorlage konnte nicht vorbereitet werden: '
                        . mysqli_error($conn)
                ),
                500
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'isssiiii',
            $groupId,
            $templateName,
            $templateSubject,
            $templateBody,
            $currentTime,
            $currentUid,
            $currentTime,
            $currentUid
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage konnte nicht angelegt werden: '
                        . $errorMessage
                ),
                500
            );
        }

        $newTemplateId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        sendJson(
            array(
                'success' => true,
                'message' => 'Die Vorlage wurde angelegt.',
                'id' => (int)$newTemplateId
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Vorlage löschen
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {
        $templateId = isset($_POST['id'])
            ? (int)$_POST['id']
            : 0;

        if ($templateId <= 0) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Ungültige Vorlagen-ID.'
                ),
                422
            );
        }

        $existingTemplate = getTemplateForGroup(
            $conn,
            $templateId,
            $groupId
        );

        if ($existingTemplate === false) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage konnte nicht geprüft werden.'
                ),
                500
            );
        }

        if ($existingTemplate === null) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage wurde nicht gefunden oder gehört nicht zu dieser AG.'
                ),
                404
            );
        }

        $sql = "
            DELETE FROM ag_mail_templates
            WHERE id = ?
              AND group_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Löschung konnte nicht vorbereitet werden: '
                        . mysqli_error($conn)
                ),
                500
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ii',
            $templateId,
            $groupId
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage konnte nicht gelöscht werden: '
                        . $errorMessage
                ),
                500
            );
        }

        mysqli_stmt_close($stmt);

        sendJson(
            array(
                'success' => true,
                'message' => 'Die Vorlage wurde gelöscht.'
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Vorlage versenden
    |--------------------------------------------------------------------------
    */

    if ($action === 'send') {
        $templateId = isset($_POST['id'])
            ? (int)$_POST['id']
            : 0;

        if ($templateId <= 0) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Ungültige Vorlagen-ID.'
                ),
                422
            );
        }

        if (
            $groupMail === ''
            || !filter_var($groupMail, FILTER_VALIDATE_EMAIL)
        ) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Für diese AG ist keine gültige Absenderadresse hinterlegt.'
                ),
                422
            );
        }

        $template = getTemplateForGroup(
            $conn,
            $templateId,
            $groupId
        );

        if ($template === false) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage konnte nicht geladen werden.'
                ),
                500
            );
        }

        if ($template === null) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die Vorlage wurde nicht gefunden oder gehört nicht zu dieser AG.'
                ),
                404
            );
        }

        /*
         * Schutz gegen Header-Injection.
         */
        $safeGroupName = str_replace(
            array("\r", "\n"),
            ' ',
            $groupName
        );

        $safeGroupMail = str_replace(
            array("\r", "\n"),
            '',
            $groupMail
        );

        $safeSubject = str_replace(
            array("\r", "\n"),
            ' ',
            $template['subject']
        );

        $encodedGroupName = mb_encode_mimeheader(
            $safeGroupName,
            'UTF-8',
            'B',
            "\r\n"
        );

        $encodedSubject = mb_encode_mimeheader(
            $safeSubject,
            'UTF-8',
            'B',
            "\r\n"
        );

        $htmlBody = nl2br(
            htmlspecialchars(
                $template['body'],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
        );

        $mailHeaders = array(
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: '
                . $encodedGroupName
                . ' <'
                . $safeGroupMail
                . '>',
            'Reply-To: '
                . $encodedGroupName
                . ' <'
                . $safeGroupMail
                . '>'
        );

        $mailSent = mail(
            COMMUNITY_RECIPIENT,
            $encodedSubject,
            $htmlBody,
            implode("\r\n", $mailHeaders)
        );

        if (!$mailSent) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die E-Mail konnte nicht versendet werden.'
                ),
                500
            );
        }

        $currentTime = time();

        $sql = "
            UPDATE ag_mail_templates
            SET
                last_sent_at = ?,
                last_sent_by = ?
            WHERE id = ?
              AND group_id = ?
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die E-Mail wurde versendet, aber der Versandzeitpunkt konnte nicht gespeichert werden: '
                        . mysqli_error($conn)
                ),
                500
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'iiii',
            $currentTime,
            $currentUid,
            $templateId,
            $groupId
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            sendJson(
                array(
                    'success' => false,
                    'message' => 'Die E-Mail wurde versendet, aber der Versandzeitpunkt konnte nicht gespeichert werden: '
                        . $errorMessage
                ),
                500
            );
        }

        mysqli_stmt_close($stmt);

        sendJson(
            array(
                'success' => true,
                'message' => 'Die E-Mail wurde an '
                    . COMMUNITY_RECIPIENT
                    . ' versendet.'
            )
        );
    }

    sendJson(
        array(
            'success' => false,
            'message' => 'Unbekannte Aktion.'
        ),
        400
    );
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="format-detection" content="telephone=no">

    <title>
        AG-Rundmails –
        <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>
    </title>

    <link rel="stylesheet" href="WEH.css" media="screen">

    <style>
        :root {
            --agmail-primary: #11a50d;
            --agmail-panel: #222;
            --agmail-panel-light: #2b2b2b;
            --agmail-border: #444;
            --agmail-text: #f2f2f2;
            --agmail-muted: #aaa;
            --agmail-danger: #a52222;
        }

        .agmail-page {
            width: min(1450px, calc(100% - 30px));
            margin: 20px auto 50px;
            color: var(--agmail-text) !important;
        }

        .agmail-page h1,
        .agmail-page h2,
        .agmail-page h3,
        .agmail-page p,
        .agmail-page label,
        .agmail-page span,
        .agmail-page div,
        .agmail-page th,
        .agmail-page td {
            color: inherit;
        }

        .agmail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 16px;
        }

        .agmail-title {
            margin: 0;
            color: var(--agmail-text) !important;
            font-size: 28px;
            line-height: 1.2;
        }

        .agmail-subtitle {
            margin: 7px 0 0;
            color: var(--agmail-muted) !important;
            font-size: 14px;
        }

        .agmail-header-actions {
            flex-shrink: 0;
        }

        .agmail-header-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
        }

        .agmail-back-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            box-sizing: border-box;
            border: 1px solid var(--agmail-border);
            border-radius: 3px;
            background: var(--agmail-panel);
            color: var(--agmail-text) !important;
            text-decoration: none;
            font-size: 24px;
            line-height: 1;
        }

        .agmail-back-button:hover,
        .agmail-back-button:focus {
            border-color: var(--agmail-primary);
            outline: none;
        }

        .agmail-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(240px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .agmail-info-box,
        .agmail-panel {
            background: var(--agmail-panel);
            border: 1px solid var(--agmail-border);
            border-radius: 5px;
            color: var(--agmail-text) !important;
        }

        .agmail-info-box {
            padding: 12px 14px;
        }

        .agmail-info-label {
            display: block;
            margin-bottom: 5px;
            color: var(--agmail-muted) !important;
            font-size: 12px;
            text-transform: uppercase;
        }

        .agmail-info-value {
            color: var(--agmail-text) !important;
            overflow-wrap: anywhere;
            font-size: 16px;
        }

        .agmail-panel {
            padding: 16px;
            margin-bottom: 16px;
        }

        .agmail-editor {
            display: none;
        }

        .agmail-editor.is-visible {
            display: block;
        }

        .agmail-editor-title {
            margin: 0 0 15px;
            color: var(--agmail-text) !important;
            font-size: 21px;
        }

        .agmail-form-grid {
            display: grid;
            grid-template-columns: minmax(220px, 0.7fr) minmax(300px, 1.3fr);
            gap: 13px;
        }

        .agmail-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .agmail-field-full {
            grid-column: 1 / -1;
        }

        .agmail-field label {
            color: var(--agmail-text) !important;
            font-size: 14px;
            font-weight: bold;
        }

        .agmail-field input,
        .agmail-field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 9px 10px;
            border: 1px solid var(--agmail-border);
            border-radius: 3px;
            background: var(--agmail-panel-light) !important;
            color: var(--agmail-text) !important;
            caret-color: var(--agmail-text);
            font: inherit;
        }

        .agmail-field input::placeholder,
        .agmail-field textarea::placeholder {
            color: var(--agmail-muted) !important;
        }

        .agmail-field textarea {
            min-height: 230px;
            resize: vertical;
            line-height: 1.45;
        }

        .agmail-field input:focus,
        .agmail-field textarea:focus {
            border-color: var(--agmail-primary);
            outline: 1px solid var(--agmail-primary);
        }

        .agmail-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-top: 14px;
        }

        .agmail-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 36px;
            padding: 7px 13px;
            border: 1px solid transparent;
            border-radius: 3px;
            color: #fff !important;
            font: inherit;
            font-weight: bold;
            cursor: pointer;
        }

        .agmail-button:hover {
            filter: brightness(1.12);
        }

        .agmail-button:disabled {
            cursor: wait;
            opacity: 0.55;
            filter: none;
        }

        .agmail-button-primary {
            background: var(--agmail-primary);
        }

        .agmail-button-secondary {
            background: #4b4b4b;
            border-color: #666;
        }

        .agmail-button-danger {
            background: var(--agmail-danger);
        }

        .agmail-button-small {
            min-height: 31px;
            padding: 5px 10px;
            font-size: 13px;
        }

        .agmail-status {
            display: none;
            padding: 11px 13px;
            margin-bottom: 16px;
            border: 1px solid var(--agmail-border);
            border-radius: 4px;
            background: var(--agmail-panel);
            color: var(--agmail-text) !important;
        }

        .agmail-status.is-visible {
            display: block;
        }

        .agmail-status.is-success {
            border-color: var(--agmail-primary);
        }

        .agmail-status.is-error {
            border-color: #c33737;
            color: #ff8d8d !important;
        }

        .agmail-table-wrap {
            overflow-x: auto;
        }

        .agmail-table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
            color: var(--agmail-text) !important;
        }

        .agmail-table th,
        .agmail-table td {
            padding: 10px;
            border-bottom: 1px solid var(--agmail-border);
            color: var(--agmail-text) !important;
            text-align: left;
            vertical-align: top;
        }

        .agmail-table th {
            color: #c9d8e5 !important;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .agmail-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.025);
        }

        .agmail-template-name {
            color: var(--agmail-text) !important;
            font-weight: bold;
        }

        .agmail-subject {
            margin-bottom: 5px;
            color: var(--agmail-text) !important;
            font-weight: bold;
        }

        .agmail-preview {
            max-width: 440px;
            color: var(--agmail-muted) !important;
            font-size: 13px;
            line-height: 1.35;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .agmail-audit-date {
            color: var(--agmail-text) !important;
            white-space: nowrap;
        }

        .agmail-audit-user {
            margin-top: 4px;
            color: var(--agmail-muted) !important;
            font-size: 12px;
            white-space: nowrap;
        }

        .agmail-action-group {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .agmail-send-cell {
            text-align: right !important;
            white-space: nowrap;
        }

        .agmail-empty,
        .agmail-loading {
            padding: 30px 15px !important;
            color: var(--agmail-muted) !important;
            text-align: center !important;
        }


        .agmail-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 20px;
            box-sizing: border-box;
            background: rgba(0, 0, 0, 0.72);
        }

        .agmail-modal.is-visible {
            display: flex;
        }

        .agmail-modal-dialog {
            width: min(680px, 100%);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            padding: 18px;
            box-sizing: border-box;
            border: 1px solid var(--agmail-border);
            border-radius: 5px;
            background: var(--agmail-panel);
            color: var(--agmail-text) !important;
            box-shadow: 0 12px 38px rgba(0, 0, 0, 0.45);
        }

        .agmail-modal-title {
            margin: 0 0 8px;
            color: var(--agmail-text) !important;
            font-size: 22px;
        }

        .agmail-modal-text {
            margin: 0 0 15px;
            color: var(--agmail-muted) !important;
        }

        .agmail-modal-details {
            display: grid;
            grid-template-columns: 125px minmax(0, 1fr);
            gap: 8px 12px;
            padding: 13px;
            border: 1px solid var(--agmail-border);
            border-radius: 4px;
            background: var(--agmail-panel-light);
        }

        .agmail-modal-label {
            color: var(--agmail-muted) !important;
            font-size: 13px;
            font-weight: bold;
        }

        .agmail-modal-value {
            color: var(--agmail-text) !important;
            overflow-wrap: anywhere;
        }

        .agmail-modal-preview {
            max-height: 210px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .agmail-modal-error {
            display: none;
            margin-top: 12px;
            padding: 10px 12px;
            border: 1px solid #c33737;
            border-radius: 4px;
            color: #ff8d8d !important;
        }

        .agmail-modal-error.is-visible {
            display: block;
        }

        .agmail-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-top: 16px;
        }

        @media (max-width: 800px) {
            .agmail-header {
                flex-direction: column;
            }

            .agmail-info,
            .agmail-form-grid {
                grid-template-columns: 1fr;
            }

            .agmail-field-full {
                grid-column: auto;
            }

            .agmail-header-actions,
            .agmail-header-actions .agmail-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<?php load_menu(); ?>

<main class="agmail-page">

    <div class="agmail-header">
        <div class="agmail-header-main">
            <?php if ($showGroupBackButton) { ?>
                <a
                    class="agmail-back-button"
                    href="<?php
                        echo htmlspecialchars(
                            $pagePath,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>"
                    title="Andere AG auswählen"
                    aria-label="Andere AG auswählen"
                >
                    &#8592;
                </a>
            <?php } ?>

            <div>
                <h1 class="agmail-title">
                    Rundmail-Vorlagen:
                    <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>
                </h1>
            </div>
        </div>

        <div class="agmail-header-actions">
            <button
                type="button"
                class="agmail-button agmail-button-primary"
                id="new-template-button"
            >
                Neue Vorlage
            </button>
        </div>
    </div>

    <div class="agmail-info">
        <div class="agmail-info-box">
            <span class="agmail-info-label">Absender</span>

            <span class="agmail-info-value">
                <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>
                &lt;<?php echo htmlspecialchars($groupMail, ENT_QUOTES, 'UTF-8'); ?>&gt;
            </span>
        </div>

        <div class="agmail-info-box">
            <span class="agmail-info-label">Empfänger</span>

            <span class="agmail-info-value">
                Community-WEH
                &lt;<?php echo htmlspecialchars(COMMUNITY_RECIPIENT, ENT_QUOTES, 'UTF-8'); ?>&gt;
            </span>
        </div>
    </div>

    <div id="status-message" class="agmail-status"></div>

    <section id="template-editor" class="agmail-panel agmail-editor">
        <h2 id="editor-title" class="agmail-editor-title">
            Neue Vorlage
        </h2>

        <form id="template-form">
            <input type="hidden" name="id" id="template-id" value="0">

            <div class="agmail-form-grid">
                <div class="agmail-field">
                    <label for="template-name">Vorlagenname</label>

                    <input
                        type="text"
                        name="name"
                        id="template-name"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="agmail-field">
                    <label for="template-subject">Betreff</label>

                    <input
                        type="text"
                        name="subject"
                        id="template-subject"
                        maxlength="255"
                        required
                    >
                </div>

                <div class="agmail-field agmail-field-full">
                    <label for="template-body">Nachricht</label>

                    <textarea
                        name="body"
                        id="template-body"
                        required
                    ></textarea>
                </div>
            </div>

            <div class="agmail-form-actions">
                <button
                    type="button"
                    class="agmail-button agmail-button-secondary"
                    id="cancel-editor-button"
                >
                    Abbrechen
                </button>

                <button
                    type="submit"
                    class="agmail-button agmail-button-primary"
                    id="save-template-button"
                >
                    Vorlage speichern
                </button>
            </div>
        </form>
    </section>

    <section class="agmail-panel">
        <div class="agmail-table-wrap">
            <table class="agmail-table">
                <thead>
                    <tr>
                        <th>Vorlage</th>
                        <th>Betreff / Nachricht</th>
                        <th>Erstellt</th>
                        <th>Geändert</th>
                        <th>Letzter Versand</th>
                        <th>Verwaltung</th>
                        <th class="agmail-send-cell">Senden</th>
                    </tr>
                </thead>

                <tbody id="template-table-body">
                    <tr>
                        <td colspan="7" class="agmail-loading">
                            Vorlagen werden geladen …
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

</main>


<div
    id="send-modal"
    class="agmail-modal"
    aria-hidden="true"
>
    <div
        class="agmail-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="send-modal-title"
    >
        <h2 id="send-modal-title" class="agmail-modal-title">
            Rundmail wirklich senden?
        </h2>

        <div class="agmail-modal-details">
            <div class="agmail-modal-label">Vorlage</div>
            <div id="send-modal-template" class="agmail-modal-value"></div>

            <div class="agmail-modal-label">Absender</div>
            <div id="send-modal-sender" class="agmail-modal-value"></div>

            <div class="agmail-modal-label">Empfänger</div>
            <div id="send-modal-recipient" class="agmail-modal-value"></div>

            <div class="agmail-modal-label">Betreff</div>
            <div id="send-modal-subject" class="agmail-modal-value"></div>

            <div class="agmail-modal-label">Nachricht</div>
            <div
                id="send-modal-body"
                class="agmail-modal-value agmail-modal-preview"
            ></div>
        </div>

        <div id="send-modal-error" class="agmail-modal-error"></div>

        <div class="agmail-modal-actions">
            <button
                type="button"
                class="agmail-button agmail-button-secondary"
                id="send-modal-cancel"
            >
                Abbrechen
            </button>

            <button
                type="button"
                class="agmail-button agmail-button-primary"
                id="send-modal-confirm"
            >
                Jetzt senden
            </button>
        </div>
    </div>
</div>

<script>
'use strict';

const csrfToken = <?php
    echo json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
?>;

const recipientAddress = <?php
    echo json_encode(
        COMMUNITY_RECIPIENT,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
?>;

const selectedGroupId = <?php echo (int)$groupId; ?>;

const selectedGroupName = <?php
    echo json_encode(
        $groupName,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
?>;

const selectedGroupMail = <?php
    echo json_encode(
        $groupMail,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
?>;

/*
 * Nur den Pfad der aktuell geöffneten PHP-Datei verwenden.
 * Die ausgewählte group_id wird bei jedem AJAX-Request mitgegeben.
 */
const requestPath = window.location.pathname;

function buildAjaxUrl(action)
{
    let url =
        requestPath
        + '?group_id='
        + encodeURIComponent(selectedGroupId)
        + '&ajax=1';

    if (action) {
        url += '&action=' + encodeURIComponent(action);
    }

    return url;
}

const templateEditor = document.getElementById('template-editor');
const templateForm = document.getElementById('template-form');
const templateTableBody = document.getElementById('template-table-body');

const editorTitle = document.getElementById('editor-title');
const templateIdInput = document.getElementById('template-id');
const templateNameInput = document.getElementById('template-name');
const templateSubjectInput = document.getElementById('template-subject');
const templateBodyInput = document.getElementById('template-body');

const newTemplateButton = document.getElementById('new-template-button');
const cancelEditorButton = document.getElementById('cancel-editor-button');
const saveTemplateButton = document.getElementById('save-template-button');

const statusMessage = document.getElementById('status-message');

const sendModal = document.getElementById('send-modal');
const sendModalTemplate = document.getElementById('send-modal-template');
const sendModalSender = document.getElementById('send-modal-sender');
const sendModalRecipient = document.getElementById('send-modal-recipient');
const sendModalSubject = document.getElementById('send-modal-subject');
const sendModalBody = document.getElementById('send-modal-body');
const sendModalError = document.getElementById('send-modal-error');
const sendModalCancel = document.getElementById('send-modal-cancel');
const sendModalConfirm = document.getElementById('send-modal-confirm');

let pendingSendTemplate = null;
let pendingSendButton = null;
let sendInProgress = false;


function setStatus(message, type)
{
    statusMessage.textContent = message;
    statusMessage.className = 'agmail-status is-visible';

    if (type === 'success') {
        statusMessage.classList.add('is-success');
    }

    if (type === 'error') {
        statusMessage.classList.add('is-error');
    }
}


function clearStatus()
{
    statusMessage.textContent = '';
    statusMessage.className = 'agmail-status';
}


function formatTimestamp(timestamp)
{
    if (!timestamp) {
        return 'Noch nie';
    }

    const date = new Date(timestamp * 1000);

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short'
    }).format(date);
}


function shortenText(text, maximumLength)
{
    maximumLength = maximumLength || 170;

    const normalizedText = String(text)
        .replace(/\s+/g, ' ')
        .trim();

    if (normalizedText.length <= maximumLength) {
        return normalizedText;
    }

    return normalizedText.substring(0, maximumLength).trim() + ' …';
}


function createTextElement(tagName, className, text)
{
    const element = document.createElement(tagName);

    if (className) {
        element.className = className;
    }

    element.textContent = text;

    return element;
}


async function parseResponse(response)
{
    const responseText = await response.text();
    let data;

    try {
        data = JSON.parse(responseText);
    } catch (error) {
        let diagnosticText = responseText
            .replace(/\s+/g, ' ')
            .trim();

        if (diagnosticText.length > 350) {
            diagnosticText = diagnosticText.substring(0, 350) + ' …';
        }

        throw new Error(
            diagnosticText !== ''
                ? 'Ungültige Serverantwort: ' + diagnosticText
                : 'Der Server hat eine leere Antwort geliefert.'
        );
    }

    if (!response.ok || !data.success) {
        throw new Error(
            data.message || 'Die Anfrage konnte nicht ausgeführt werden.'
        );
    }

    return data;
}


async function loadTemplates()
{
    templateTableBody.innerHTML = '';

    const loadingRow = document.createElement('tr');
    const loadingCell = document.createElement('td');

    loadingCell.colSpan = 7;
    loadingCell.className = 'agmail-loading';
    loadingCell.textContent = 'Vorlagen werden geladen …';

    loadingRow.appendChild(loadingCell);
    templateTableBody.appendChild(loadingRow);

    try {
        const response = await fetch(
            buildAjaxUrl('list'),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }
        );

        const data = await parseResponse(response);

        renderTemplates(data.templates);
    } catch (error) {
        templateTableBody.innerHTML = '';

        const errorRow = document.createElement('tr');
        const errorCell = document.createElement('td');

        errorCell.colSpan = 7;
        errorCell.className = 'agmail-empty';
        errorCell.textContent = error.message;

        errorRow.appendChild(errorCell);
        templateTableBody.appendChild(errorRow);

        setStatus(error.message, 'error');
    }
}


function renderTemplates(templates)
{
    templateTableBody.innerHTML = '';

    if (!Array.isArray(templates) || templates.length === 0) {
        const emptyRow = document.createElement('tr');
        const emptyCell = document.createElement('td');

        emptyCell.colSpan = 7;
        emptyCell.className = 'agmail-empty';
        emptyCell.textContent =
            'Für diese AG wurden noch keine Vorlagen angelegt.';

        emptyRow.appendChild(emptyCell);
        templateTableBody.appendChild(emptyRow);

        return;
    }

    templates.forEach(function (template) {
        const row = document.createElement('tr');

        const nameCell = document.createElement('td');

        nameCell.appendChild(
            createTextElement(
                'div',
                'agmail-template-name',
                template.name
            )
        );

        row.appendChild(nameCell);

        const subjectCell = document.createElement('td');

        subjectCell.appendChild(
            createTextElement(
                'div',
                'agmail-subject',
                template.subject
            )
        );

        subjectCell.appendChild(
            createTextElement(
                'div',
                'agmail-preview',
                shortenText(template.body)
            )
        );

        row.appendChild(subjectCell);

        row.appendChild(
            createAuditCell(
                template.created_at,
                template.created_by_name
            )
        );

        row.appendChild(
            createAuditCell(
                template.updated_at,
                template.updated_by_name
            )
        );

        row.appendChild(
            createAuditCell(
                template.last_sent_at,
                template.last_sent_by_name
            )
        );

        const managementCell = document.createElement('td');
        const managementGroup = document.createElement('div');

        managementGroup.className = 'agmail-action-group';

        const editButton = document.createElement('button');

        editButton.type = 'button';
        editButton.className =
            'agmail-button agmail-button-secondary agmail-button-small';
        editButton.textContent = 'Bearbeiten';

        editButton.addEventListener('click', function () {
            openEditor(template);
        });

        const deleteButton = document.createElement('button');

        deleteButton.type = 'button';
        deleteButton.className =
            'agmail-button agmail-button-danger agmail-button-small';
        deleteButton.textContent = 'Löschen';

        deleteButton.addEventListener('click', function () {
            deleteTemplate(template, deleteButton);
        });

        managementGroup.appendChild(editButton);
        managementGroup.appendChild(deleteButton);
        managementCell.appendChild(managementGroup);
        row.appendChild(managementCell);

        const sendCell = document.createElement('td');

        sendCell.className = 'agmail-send-cell';

        const sendButton = document.createElement('button');

        sendButton.type = 'button';
        sendButton.className =
            'agmail-button agmail-button-primary agmail-button-small';
        sendButton.textContent = 'Senden';

        sendButton.addEventListener('click', function () {
            openSendModal(template, sendButton);
        });

        sendCell.appendChild(sendButton);
        row.appendChild(sendCell);

        templateTableBody.appendChild(row);
    });
}


function createAuditCell(timestamp, userName)
{
    const cell = document.createElement('td');

    cell.appendChild(
        createTextElement(
            'div',
            'agmail-audit-date',
            formatTimestamp(timestamp)
        )
    );

    if (timestamp && userName) {
        cell.appendChild(
            createTextElement(
                'div',
                'agmail-audit-user',
                userName
            )
        );
    }

    return cell;
}


function openEditor(template)
{
    clearStatus();

    if (template) {
        editorTitle.textContent = 'Vorlage bearbeiten';

        templateIdInput.value = template.id;
        templateNameInput.value = template.name;
        templateSubjectInput.value = template.subject;
        templateBodyInput.value = template.body;
    } else {
        editorTitle.textContent = 'Neue Vorlage';

        templateForm.reset();
        templateIdInput.value = '0';
    }

    templateEditor.classList.add('is-visible');

    templateEditor.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

    window.setTimeout(function () {
        templateNameInput.focus();
    }, 250);
}


function closeEditor()
{
    templateEditor.classList.remove('is-visible');

    templateForm.reset();
    templateIdInput.value = '0';
    editorTitle.textContent = 'Neue Vorlage';
}


async function saveTemplate(event)
{
    event.preventDefault();
    clearStatus();

    saveTemplateButton.disabled = true;
    saveTemplateButton.textContent = 'Speichert …';

    const formData = new FormData(templateForm);

    formData.append('action', 'save');
    formData.append('csrf', csrfToken);

    try {
        const response = await fetch(
            buildAjaxUrl(''),
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }
        );

        const data = await parseResponse(response);

        closeEditor();
        setStatus(data.message, 'success');

        await loadTemplates();
    } catch (error) {
        setStatus(error.message, 'error');
    } finally {
        saveTemplateButton.disabled = false;
        saveTemplateButton.textContent = 'Vorlage speichern';
    }
}


async function deleteTemplate(template, button)
{
    const confirmed = window.confirm(
        'Soll die Vorlage "' +
        template.name +
        '" wirklich gelöscht werden?'
    );

    if (!confirmed) {
        return;
    }

    clearStatus();

    button.disabled = true;
    button.textContent = 'Löscht …';

    const formData = new FormData();

    formData.append('action', 'delete');
    formData.append('csrf', csrfToken);
    formData.append('id', template.id);

    try {
        const response = await fetch(
            buildAjaxUrl(''),
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }
        );

        const data = await parseResponse(response);

        setStatus(data.message, 'success');

        if (
            Number(templateIdInput.value) === Number(template.id)
        ) {
            closeEditor();
        }

        await loadTemplates();
    } catch (error) {
        setStatus(error.message, 'error');

        button.disabled = false;
        button.textContent = 'Löschen';
    }
}


function openSendModal(template, button)
{
    pendingSendTemplate = template;
    pendingSendButton = button;
    sendInProgress = false;

    sendModalTemplate.textContent = template.name;
    sendModalSender.textContent =
        selectedGroupName + ' <' + selectedGroupMail + '>';
    sendModalRecipient.textContent =
        'Community-WEH <' + recipientAddress + '>';
    sendModalSubject.textContent = template.subject;
    sendModalBody.textContent = template.body;

    sendModalError.textContent = '';
    sendModalError.classList.remove('is-visible');

    sendModalCancel.disabled = false;
    sendModalConfirm.disabled = false;
    sendModalConfirm.textContent = 'Jetzt senden';

    sendModal.classList.add('is-visible');
    sendModal.setAttribute('aria-hidden', 'false');

    window.setTimeout(function () {
        sendModalConfirm.focus();
    }, 50);
}


function closeSendModal()
{
    if (sendInProgress) {
        return;
    }

    sendModal.classList.remove('is-visible');
    sendModal.setAttribute('aria-hidden', 'true');

    pendingSendTemplate = null;
    pendingSendButton = null;

    sendModalError.textContent = '';
    sendModalError.classList.remove('is-visible');
}


async function sendTemplate()
{
    if (
        sendInProgress
        || !pendingSendTemplate
        || !pendingSendButton
    ) {
        return;
    }

    clearStatus();

    const template = pendingSendTemplate;
    const button = pendingSendButton;

    sendInProgress = true;

    button.disabled = true;
    button.textContent = 'Sendet …';

    sendModalCancel.disabled = true;
    sendModalConfirm.disabled = true;
    sendModalConfirm.textContent = 'Wird gesendet …';

    sendModalError.textContent = '';
    sendModalError.classList.remove('is-visible');

    const formData = new FormData();

    formData.append('action', 'send');
    formData.append('csrf', csrfToken);
    formData.append('id', template.id);

    try {
        const response = await fetch(
            buildAjaxUrl(''),
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }
        );

        const data = await parseResponse(response);

        sendInProgress = false;
        closeSendModal();

        setStatus(data.message, 'success');

        await loadTemplates();
    } catch (error) {
        sendInProgress = false;

        sendModalCancel.disabled = false;
        sendModalConfirm.disabled = false;
        sendModalConfirm.textContent = 'Jetzt senden';

        sendModalError.textContent = error.message;
        sendModalError.classList.add('is-visible');

        button.disabled = false;
        button.textContent = 'Senden';
    }
}


newTemplateButton.addEventListener('click', function () {
    openEditor(null);
});

cancelEditorButton.addEventListener('click', function () {
    closeEditor();
});

sendModalCancel.addEventListener('click', function () {
    closeSendModal();
});

sendModalConfirm.addEventListener('click', function () {
    sendTemplate();
});

sendModal.addEventListener('click', function (event) {
    if (event.target === sendModal) {
        closeSendModal();
    }
});

templateForm.addEventListener('submit', saveTemplate);

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (sendModal.classList.contains('is-visible')) {
        closeSendModal();
        return;
    }

    if (templateEditor.classList.contains('is-visible')) {
        closeEditor();
    }
});

loadTemplates();
</script>

</body>
</html>

<?php
$conn->close();
?>