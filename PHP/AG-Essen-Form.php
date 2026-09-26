<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .agessen-action-cell {
            white-space: nowrap;
        }

        .agessen-action-buttons {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .agessen-action-buttons form {
            display: inline-flex;
            margin: 0;
        }

        .agessen-small-btn {
            appearance: none;
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 7px;
            padding: 7px 11px;
            min-width: 0;
            margin: 0;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.1;
            cursor: pointer;
            transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
        }

        .agessen-edit-btn {
            background: #11a50d;
            border-color: #11a50d;
            color: #fff;
        }

        .agessen-delete-btn {
            background: #7e1717;
            border-color: #a82a2a;
            color: #fff;
        }

        .agessen-small-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.08);
        }

        .agessen-small-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
            transform: none;
        }

        .agessen-edit-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .78);
            z-index: 9998;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .agessen-edit-modal {
            position: relative;
            width: min(560px, 94vw);
            background: #171717;
            color: #fff;
            border: 1px solid rgba(17,165,13,.65);
            border-radius: 16px;
            padding: 24px 26px 26px;
            box-sizing: border-box;
            box-shadow: 0 24px 80px rgba(0,0,0,.7);
        }

        .agessen-edit-modal h2 {
            margin: 0 42px 6px;
            text-align: center;
            font-size: 27px;
        }

        .agessen-edit-note {
            margin: 0 0 22px;
            text-align: center;
            color: rgba(255,255,255,.65);
            font-size: 14px;
        }

        .agessen-edit-close {
            position: absolute;
            top: 13px;
            right: 13px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 50%;
            background: #fff;
            color: #111;
            font-size: 22px;
            line-height: 30px;
            font-weight: 700;
            cursor: pointer;
        }

        .agessen-edit-row {
            display: grid;
            grid-template-columns: 92px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            margin: 13px 0;
        }

        .agessen-edit-row label {
            color: rgba(255,255,255,.78);
            font-size: 14px;
        }

        .agessen-edit-row input {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 8px;
            background: #0e0e0e;
            color: #fff;
            padding: 10px 12px;
            font: inherit;
        }

        .agessen-edit-row input:focus {
            outline: none;
            border-color: #11a50d;
            box-shadow: 0 0 0 2px rgba(17,165,13,.2);
        }

        .agessen-edit-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .agessen-modal-btn {
            appearance: none;
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 8px;
            padding: 9px 16px;
            min-width: 110px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .agessen-modal-cancel {
            background: #2b2b2b;
            color: #fff;
        }

        .agessen-modal-save {
            background: #11a50d;
            border-color: #11a50d;
            color: #fff;
        }

        @media (max-width: 620px) {
            .agessen-action-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .agessen-small-btn {
                width: 100%;
            }

            .agessen-edit-row {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .agessen-edit-actions {
                justify-content: stretch;
            }

            .agessen-modal-btn {
                flex: 1;
            }
        }
    </style>
</head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();

    $hasAgMembership = false;
    foreach ($ag_complete as $num => $data) {
        if (isset($_SESSION[$data["session"]]) && $_SESSION[$data["session"]] == true) {
            $hasAgMembership = true;
        }
    }

    if (!isset($_POST["ag"])) {
        if (!$hasAgMembership) {
            echo '<h1 style="font-size: 60px; color: white; text-align: center;">You are currently not a member of any AG!</h1>';
        } else {
            $availableAgs = [];

            foreach ($ag_complete as $id => $data) {
                if ((isset($_SESSION[$data['session']]) && $_SESSION[$data['session']] == true) && ($data['agessen'] == 1)) {
                    $availableAgs[$id] = $data['name'];
                }
            }

            if (count($availableAgs) === 1) {
                $singleAgKey = array_key_first($availableAgs);
                echo '<form id="auto-ag-form" method="post">
                        <input type="hidden" name="ag" value="' . htmlspecialchars($singleAgKey, ENT_QUOTES, 'UTF-8') . '">
                      </form>';
                echo '<script>
                        document.getElementById("auto-ag-form").submit();
                      </script>';
            } else {
                echo '<h1 style="font-size: 60px; color: white; text-align: center;">Select AG:</h1>';
                echo '<form method="post" style="display:flex; justify-content:center; align-items:center; flex-wrap: wrap;">';

                foreach ($availableAgs as $key => $value) {
                    echo '<button type="submit" name="ag" value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" class="house-button" style="font-size:50px; margin:10px; background-color:#fff; color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</button>';
                }

                echo '</form>';
                echo "<br><br>";
            }
        }
    } else {
        $ag = strval($_POST["ag"]);

        if (!isset($ag_complete[$ag]) || !isset($_SESSION[$ag_complete[$ag]['session']]) || !$_SESSION[$ag_complete[$ag]['session']] || $ag_complete[$ag]['agessen'] != 1) {
            echo '<div style="text-align:center; color:red; font-size:30px;">Ungültige oder nicht erlaubte AG.</div>';
            $conn->close();
            exit();
        }

        $agname = $ag_complete[$ag]["name"];
        $trinkgeldfaktor = 1.1;
        $zeit = time();
        $lockDuration = 30 * 60;
        $lockCutoff = $zeit - $lockDuration;
        $startOfSemester = unixtime2startofsemesteragessen($zeit);
        $semester = unixtime2semesteragessen($zeit);
        $currentUserUid = (int)$_SESSION['user'];

        $sql = "SELECT COUNT(uid) FROM users WHERE CONCAT(',', groups, ',') LIKE CONCAT('%,', ?, ',%') AND pid in (11,64) ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        $ag_str = strval($ag);
        mysqli_stmt_bind_param($stmt, "s", $ag_str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count_members);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $sql = "SELECT wert FROM constants WHERE name = 'essen_pp'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $budgetpP);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $sql = "SELECT wert FROM constants WHERE name = 'essen_count'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mindestteilnehmer);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $mindestteilnehmer_effektiv = min((int)$mindestteilnehmer, (int)$count_members);
        if ($mindestteilnehmer_effektiv < 1) {
            $mindestteilnehmer_effektiv = 1;
        }

        if ($ag == 7 || $ag == 9 || $ag == 11 || $ag == 25) {
            $budgetpP = $budgetpP * 2;
        }

        $actionMessage = null;
        $actionError = null;

        /*
         * Offenen eigenen Antrag bearbeiten.
         * Veränderbar sind nur Betrag und IBAN.
         * Teilnehmer und Rechnung bleiben unverändert.
         */
        if (isset($_POST['edit_agessen'])) {
            $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
            $newBetrag = isset($_POST['edit_betrag']) ? (float)$_POST['edit_betrag'] : 0;
            $newIban = isset($_POST['edit_iban']) ? $_POST['edit_iban'] : '';
            $newIban = rtrim(chunk_split(str_replace(' ', '', $newIban), 4, ' '));

            $sql = "SELECT betrag, iban, teilnehmer, pfad, status, lock_tstamp, lock_uid
                    FROM agessen
                    WHERE id = ? AND ag = ? AND uid = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "isi", $editId, $ag, $currentUserUid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $oldBetrag, $oldIban, $teilnehmer, $pfad, $status, $lockTstamp, $lockUid);

            if (!mysqli_stmt_fetch($stmt)) {
                $actionError = 'Der Antrag wurde nicht gefunden oder gehört nicht dir.';
                mysqli_stmt_close($stmt);
            } else {
                mysqli_stmt_close($stmt);

                if ((int)$status !== 0) {
                    $actionError = 'Der Antrag wurde bereits angenommen und kann nicht mehr geändert werden.';
                } elseif ($lockTstamp !== null && (int)$lockTstamp >= $lockCutoff) {
                    $actionError = 'Der Antrag wird gerade vom Kassenwart geprüft und kann momentan nicht geändert werden.';
                } else {
                    $teilnehmerArray = array_values(array_filter(array_map('trim', explode(',', (string)$teilnehmer)), 'strlen'));
                    $count_teilnehmer = count($teilnehmerArray);
                    $limit = $budgetpP * $count_teilnehmer * $trinkgeldfaktor;
                    $limitstring = number_format($limit, 2, ',', '.') . ' €';

                    $sql = "SELECT COALESCE(SUM(betrag), 0) FROM agessen WHERE ag = ? AND tstamp > ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "ss", $ag, $startOfSemester);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_bind_result($stmt, $spentForEdit);
                    mysqli_stmt_fetch($stmt);
                    mysqli_stmt_close($stmt);

                    $spentWithoutCurrent = (float)$spentForEdit - (float)$oldBetrag;
                    $offenWithoutCurrent = (($budgetpP * $count_members) - $spentWithoutCurrent) * $trinkgeldfaktor;
                    $offenWithoutCurrentAnzeige = max(0, $offenWithoutCurrent);

                    if ($newBetrag <= 0) {
                        $actionError = 'Der Betrag muss größer als 0 sein!';
                    } elseif ($newBetrag > $offenWithoutCurrent) {
                        $actionError = 'Der Betrag ist zu hoch! Ihr könnt für diesen Antrag maximal ' . number_format($offenWithoutCurrentAnzeige, 2, ',', '.') . ' € eintragen.';
                    } elseif ($count_teilnehmer < $mindestteilnehmer_effektiv) {
                        $actionError = 'Der Antrag erfüllt die aktuelle Mindestteilnehmerzahl nicht mehr und kann daher nicht geändert werden.';
                    } elseif ($newBetrag > $limit) {
                        $actionError = 'Der Betrag ist zu hoch! Für ' . $count_teilnehmer . ' Teilnehmer ist das Betragslimit bei ' . $limitstring . '!';
                    } elseif (!isValidIBAN($newIban) || strpos($newIban, "Bar ") === 0) {
                        $actionError = 'Die IBAN ' . htmlspecialchars($newIban, ENT_QUOTES, 'UTF-8') . ' hat ein falsches Format!';
                    } elseif (abs((float)$oldBetrag - $newBetrag) < 0.00001 && trim((string)$oldIban) === trim($newIban)) {
                        $actionMessage = 'Es wurden keine Änderungen vorgenommen.';
                    } else {
                        $sql = "UPDATE agessen
                                SET betrag = ?, iban = ?, lock_tstamp = NULL, lock_uid = NULL
                                WHERE id = ?
                                  AND ag = ?
                                  AND uid = ?
                                  AND status = 0
                                  AND (lock_tstamp IS NULL OR lock_tstamp < ?)";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "dsisii", $newBetrag, $newIban, $editId, $ag, $currentUserUid, $lockCutoff);
                        $saveOk = mysqli_stmt_execute($stmt);
                        $affected = mysqli_stmt_affected_rows($stmt);
                        mysqli_stmt_close($stmt);

                        if (!$saveOk) {
                            $actionError = 'Fehler beim Speichern der Änderung.';
                        } elseif ($affected < 1) {
                            $actionError = 'Der Antrag konnte nicht geändert werden. Er wurde möglicherweise gerade vom Kassenwart geöffnet oder bereits angenommen.';
                        } else {
                            $actionMessage = 'Der offene Antrag wurde geändert.';
                        }
                    }
                }
            }
        }

        /* Offenen eigenen Antrag löschen. */
        if (isset($_POST['delete_agessen'])) {
            $deleteId = isset($_POST['delete_id']) ? (int)$_POST['delete_id'] : 0;
            $deletePfad = null;

            $sql = "SELECT pfad, status, lock_tstamp
                    FROM agessen
                    WHERE id = ? AND ag = ? AND uid = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "isi", $deleteId, $ag, $currentUserUid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $deletePfad, $deleteStatus, $deleteLockTstamp);

            if (!mysqli_stmt_fetch($stmt)) {
                $actionError = 'Der Antrag wurde nicht gefunden oder gehört nicht dir.';
                mysqli_stmt_close($stmt);
            } else {
                mysqli_stmt_close($stmt);

                if ((int)$deleteStatus !== 0) {
                    $actionError = 'Der Antrag wurde bereits angenommen und kann nicht mehr gelöscht werden.';
                } elseif ($deleteLockTstamp !== null && (int)$deleteLockTstamp >= $lockCutoff) {
                    $actionError = 'Der Antrag wird gerade vom Kassenwart geprüft und kann momentan nicht gelöscht werden.';
                } else {
                    $sql = "DELETE FROM agessen
                            WHERE id = ?
                              AND ag = ?
                              AND uid = ?
                              AND status = 0
                              AND (lock_tstamp IS NULL OR lock_tstamp < ?)";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "isii", $deleteId, $ag, $currentUserUid, $lockCutoff);
                    $deleteOk = mysqli_stmt_execute($stmt);
                    $affected = mysqli_stmt_affected_rows($stmt);
                    mysqli_stmt_close($stmt);

                    if (!$deleteOk) {
                        $actionError = 'Fehler beim Löschen des Antrags.';
                    } elseif ($affected < 1) {
                        $actionError = 'Der Antrag konnte nicht gelöscht werden. Er wurde möglicherweise gerade vom Kassenwart geöffnet oder bereits angenommen.';
                    } else {
                        if ($deletePfad && strpos($deletePfad, 'rechnungen/') === 0 && is_file($deletePfad)) {
                            @unlink($deletePfad);
                        }
                        $actionMessage = 'Der offene Antrag wurde gelöscht.';
                    }
                }
            }
        }

        $sql = "SELECT SUM(betrag) FROM agessen WHERE ag = ? AND tstamp > ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $ag, $startOfSemester);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $spent);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $schonwaseingetragen = true;
        if ($spent === null) {
            $spent = 0;
            $schonwaseingetragen = false;
        }

        $offenohnetrinkgeld = ($budgetpP * $count_members) - $spent;
        $offen = $offenohnetrinkgeld * $trinkgeldfaktor;
        $offenohnetrinkgeld_anzeige = max(0, $offenohnetrinkgeld);
        $offen_anzeige = max(0, $offen);

        if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
            if (isset($_POST["esseneintragen"])) {
                if (isset($_POST['iban'])) {
                    $iban = $_POST['iban'];
                    $iban = rtrim(chunk_split(str_replace(' ', '', $iban), 4, ' '));
                } else {
                    $iban = '';
                }

                $isBarAntrag = false;
                if (isset($_POST['barkasseCheckbox']) && $_POST['barkasseCheckbox'] == '1') {
                    if (isset($_POST['bar']) && $_POST['bar'] != "") {
                        $iban = trim($_POST['bar']);
                        $isBarAntrag = true;
                    } else {
                        $iban = '';
                    }
                }

                $betrag = (float)$_POST['betrag'];
                $betragstring = number_format($betrag, 2, ',', '.') . ' €';
                $teilnehmer_array = isset($_POST['selected_users']) && is_array($_POST['selected_users']) ? $_POST['selected_users'] : [];
                $count_teilnehmer = count($teilnehmer_array);
                $teilnehmer = implode(',', $teilnehmer_array);
                $tstamp = $zeit;
                $uid = $_SESSION['user'];
                $limit = $budgetpP * $count_teilnehmer * $trinkgeldfaktor;
                $limitstring = number_format($limit, 2, ',', '.') . ' €';

                if ($betrag > $offen) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Der Betrag ist zu hoch!
                    <br>Ihr könnt maximal noch " . number_format($offen_anzeige, 2, ',', '.') . ' €' . " in diesem Semester ausgeben.</p>";
                    echo "</div>";
                } elseif ($count_teilnehmer < $mindestteilnehmer_effektiv) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Es müssen mindestens $mindestteilnehmer_effektiv Teilnehmer an einem AG-Essen teilnehmen!</p>";
                    echo "</div>";
                } elseif ($betrag <= 0) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Der Betrag muss größer als 0 sein!</p>";
                    echo "</div>";
                } elseif ($betrag > $limit) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Der Betrag ist zu hoch!
                    <br>Für $count_teilnehmer Teilnehmer ist das Betragslimit bei $limitstring!</p>";
                    echo "</div>";
                } elseif (!isValidIBAN($iban) && strpos($iban, "Bar ") !== 0) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Die IBAN $iban hat ein falsches Format!</p>";
                    echo "</div>";
                } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
                    echo "<div style='text-align: center;'>";
                    echo "<p style='color:red; text-align:center;'>Es wurde keine Datei hochgeladen.</p>";
                    echo "<p>Debug Info:</p>";
                    if (isset($_FILES['file'])) {
                        echo "<p>File Error: " . $_FILES['file']['error'] . "</p>";
                    } else {
                        echo "<p>No file found in \$_FILES array.</p>";
                    }
                    echo "</div>";
                } else {
                    $file_path = null;

                    if (!is_dir('rechnungen')) {
                        mkdir('rechnungen', 0777, true);
                    }

                    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                        $unixtime = time();
                        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                        $file_name = 'essen-' . $unixtime . '.' . $file_extension;
                        $file_path = 'rechnungen/' . $file_name;

                        $allowed_extensions = array('jpg', 'jpeg', 'png', 'pdf');
                        if (!in_array($file_extension, $allowed_extensions)) {
                            echo "<div style='text-align: center;'>";
                            echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) und PDF-Dateien sind erlaubt.</p>";
                            echo "</div>";
                            exit();
                        }

                        $allowed_mime_types = array('image/jpeg', 'image/png', 'application/pdf');
                        $file_mime_type = mime_content_type($_FILES['file']['tmp_name']);
                        if (!in_array($file_mime_type, $allowed_mime_types)) {
                            echo "<div style='text-align: center;'>";
                            echo "<p style='color:red; text-align:center;'>Nur Bilder (JPG, JPEG, PNG) und PDF-Dateien sind erlaubt.</p>";
                            echo "</div>";
                            exit();
                        }

                        if (!move_uploaded_file($_FILES['file']['tmp_name'], $file_path)) {
                            echo "<div style='text-align: center;'>";
                            echo "<p style='color:red; text-align:center;'>Fehler beim Hochladen der Datei.</p>";
                            echo "</div>";
                            exit();
                        }
                    }

                    $mail_ok = true;
                    $save_ok = true;

                    if ($isBarAntrag) {
                        mysqli_begin_transaction($conn);

                        $status = 1;
                        $insert_sql = "INSERT INTO agessen (uid, tstamp, ag, betrag, teilnehmer, iban, pfad, status) VALUES (?,?,?,?,?,?,?,?)";
                        $stmt = mysqli_prepare($conn, $insert_sql);
                        mysqli_stmt_bind_param($stmt, "iisdsssi", $uid, $zeit, $ag, $betrag, $teilnehmer, $iban, $file_path, $status);
                        $save_ok = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        if ($save_ok) {
                            $insert_betrag = (-1) * $betrag;
                            $dummy_uid = 492;
                            $agent = $_SESSION["uid"];
                            $insert_beschreibung = "AG-Essen " . $ag_complete[$ag]['name'];

                            $x_string = substr($iban, strpos($iban, "Bar") + strlen("Bar"));
                            $kasse = intval(trim($x_string));
                            $konto = ($insert_betrag >= 0) ? 4 : 8;
                            $zeitstempel = date("d.m.Y H:i", $zeit);
                            $changelog = "[" . $zeitstempel . "] Agent " . $_SESSION["uid"] . "\nAG-Essen bestätigt\n";

                            $insert_sql = "
                                INSERT INTO transfers (tstamp, uid, beschreibung, betrag, kasse, konto, pfad, agent, changelog)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $insert_sql);
                            mysqli_stmt_bind_param($stmt, "iisdiisss", $zeit, $dummy_uid, $insert_beschreibung, $insert_betrag, $kasse, $konto, $file_path, $agent, $changelog);
                            $save_ok = mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }

                        if ($save_ok) {
                            mysqli_commit($conn);
                        } else {
                            mysqli_rollback($conn);
                        }

                        if ($save_ok) {
                            $message = "Hallo Kassenwarte," . PHP_EOL .
                            "\nEs wurde ein Barkassen-AG-Essen eingetragen und direkt genehmigt." . PHP_EOL .
                            "Weitere Verarbeitung / Einsicht auf folgender Seite:" . PHP_EOL .
                            "https://backend.weh.rwth-aachen.de/AG-Essen.php" . PHP_EOL .
                            "\nViele Grüße," . PHP_EOL .
                            "AG-Essen-Form.php";
                            $to = "kasse@weh.rwth-aachen.de";
                            $subject = "WEH - AG Essen (Barkasse direkt genehmigt)";
                            $headers = "From: " . $mailconfig['address'] . "\r\n";
                            $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
                            $mail_ok = mail($to, $subject, $message, $headers);
                        }
                    } else {
                        $insert_sql = "INSERT INTO agessen (uid, tstamp, ag, betrag, teilnehmer, iban, pfad) VALUES (?,?,?,?,?,?,?)";
                        $stmt = mysqli_prepare($conn, $insert_sql);
                        mysqli_stmt_bind_param($stmt, "iisdsss", $uid, $zeit, $ag, $betrag, $teilnehmer, $iban, $file_path);
                        $save_ok = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        if ($save_ok) {
                            $message = "Hallo Kassenwarte," . PHP_EOL .
                            "\nEs wurde ein neues AG-Essen eingetragen." . PHP_EOL .
                            "Weitere Verarbeitung auf folgender Seite:" . PHP_EOL .
                            "https://backend.weh.rwth-aachen.de/AG-Essen.php" . PHP_EOL .
                            "\nViele Grüße," . PHP_EOL .
                            "AG-Essen-Form.php";
                            $to = "kasse@weh.rwth-aachen.de";
                            $subject = "WEH - AG Essen";
                            $headers = "From: " . $mailconfig['address'] . "\r\n";
                            $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
                            $mail_ok = mail($to, $subject, $message, $headers);
                        }
                    }

                    if (!$save_ok) {
                        echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                                <span style='color: red; font-size: 20px;'>Fehler beim Speichern des Eintrags.</span>
                              </div>";
                    } else {
                        if (!$mail_ok) {
                            echo "<div style='display: flex; justify-content: center; align-items: center; height: 100vh;'>
                                    <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                                  </div>";
                        }

                        echo "<div style='text-align: center; font-size: 50px; color: #66FF99'>";
                        if ($isBarAntrag) {
                            echo "Deine Anfrage wurde direkt genehmigt.";
                        } else {
                            echo "Deine Anfrage wurde gesendet.";
                        }
                        echo "</div>";
                        echo "<br><br>";
                        echo "<br>";
                        echo '<hr style="border-top: 1px solid white;">';
                        echo "<br>";

                        echo '<form id="agForm" method="post" style="display:none;">';
                        echo '<input type="hidden" name="ag" value="' . htmlspecialchars($ag, ENT_QUOTES, 'UTF-8') . '">';
                        echo '</form>';

                        echo '<script>
                            setTimeout(function() {
                                document.getElementById("agForm").action = window.location.href;
                                document.getElementById("agForm").submit();
                            }, 0);
                        </script>';
                    }
                }
            }
        }

        if ($actionMessage !== null) {
            echo '<div style="text-align:center; font-size:28px; color:#66FF99; margin:20px 0;">' . htmlspecialchars($actionMessage, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($actionError !== null) {
            echo '<div style="text-align:center; font-size:24px; color:red; margin:20px 0;">' . $actionError . '</div>';
        }

        echo '<div style="text-align: center; font-size: 60px; color: white;">';
        echo htmlspecialchars($agname, ENT_QUOTES, 'UTF-8');
        echo '</div><br><br><br>';
        echo '<div style="text-align: center; font-size: 30px; color: white;">';
        echo 'Offenes Essensbudget im ' . htmlspecialchars($semester, ENT_QUOTES, 'UTF-8');
        echo '</div>';
        echo '<div style="text-align: center; font-size: 40px; color: white;">';
        echo number_format($offenohnetrinkgeld_anzeige, 2, ',', '.') . ' €';
        echo '</div>';
        echo '<div style="text-align: center; font-size: 25px; color: white;">';
        echo "(" . number_format($offen_anzeige, 2, ',', '.') . ' € mit Trinkgeld)';
        echo '</div>';

        if ($schonwaseingetragen) {
            echo "<br>";
            echo '<hr style="border-top: 1px solid white;">';
            echo "<br>";

            echo '<div style="text-align: center; font-size: 60px; color: white;">';
            echo "Einträge im " . htmlspecialchars($semester, ENT_QUOTES, 'UTF-8');
            echo '</div><br><br><br>';

            echo "<style>
                table {
                    border-collapse: collapse;
                    color: white;
                    margin: auto;
                    font-size: 16px;
                }
                th, td {
                    padding: 10px;
                    text-align: center;
                    border-bottom: 1px solid white;
                }
            </style>";

            echo "<table class='agessentable'>";
            echo "<tr><th>Datum</th><th>Status</th><th>Betrag</th><th>IBAN</th><th>Rechnung</th><th>Teilnehmer</th><th>Aktion</th></tr>";

            $sql = "SELECT a.id, a.uid, a.tstamp, a.status, a.betrag, a.iban, a.pfad, a.lock_tstamp, a.lock_uid,
            GROUP_CONCAT(
                CONCAT(
                    SUBSTRING_INDEX(u.firstname, ' ', 1),
                    ' ',
                    LEFT(u.lastname, 1),
                    '.'
                ) ORDER BY FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) SEPARATOR ', '
            ) AS teilnehmer_namen
            FROM weh.agessen a
            JOIN weh.users u ON FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) > 0
            WHERE a.ag = ? AND a.tstamp > ?
            GROUP BY a.id
            ORDER BY a.tstamp DESC";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ss", $ag, $startOfSemester);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $entryId, $entryUid, $tstamp, $status, $betrag, $iban, $pfad, $lockTstamp, $lockUid, $teilnehmerstring);

            while (mysqli_stmt_fetch($stmt)) {
                $tstamp_show = date('d.m.Y', $tstamp);
                $statusstring = ($status == 0) ? "In Bearbeitung" : (($status == 1) ? "Überwiesen" : "");
                $betrag_show = number_format($betrag, 2, ',', '.') . ' €';
                $invoiceLink = '<a href="' . htmlspecialchars($pfad, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="white-text">[Link]</a>';
                $isEditableOpen = ((int)$status === 0);
                $isLocked = ($lockTstamp !== null && (int)$lockTstamp >= (time() - $lockDuration));

                echo '<tr>';
                echo '<td>' . htmlspecialchars($tstamp_show, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($statusstring, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($betrag_show, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($iban, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . $invoiceLink . '</td>';
                echo '<td>' . htmlspecialchars($teilnehmerstring, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="agessen-action-cell">';

                if ($isEditableOpen) {
                    if ($isLocked) {
                        echo '<span title="Agent ' . (int)$lockUid . '">Wird gerade geprüft</span>';
                    } else {
                        echo '<div class="agessen-action-buttons">';
                        echo '<button type="button" class="agessen-small-btn agessen-edit-btn edit-agessen-btn"'
                            . ' data-id="' . (int)$entryId . '"'
                            . ' data-betrag="' . htmlspecialchars((string)$betrag, ENT_QUOTES, 'UTF-8') . '"'
                            . ' data-iban="' . htmlspecialchars($iban, ENT_QUOTES, 'UTF-8') . '">Bearbeiten</button>';

                        echo '<form method="post" onsubmit="return confirm(\'Diesen offenen Antrag wirklich löschen? Die Rechnung wird ebenfalls gelöscht.\');">';
                        echo '<input type="hidden" name="ag" value="' . htmlspecialchars($ag, ENT_QUOTES, 'UTF-8') . '">';
                        echo '<input type="hidden" name="delete_id" value="' . (int)$entryId . '">';
                        echo '<button type="submit" name="delete_agessen" class="agessen-small-btn agessen-delete-btn">Löschen</button>';
                        echo '</form>';
                        echo '</div>';
                    }
                } else {
                    echo '—';
                }

                echo '</td>';
                echo '</tr>';
            }
            echo "</table>";
            mysqli_stmt_close($stmt);
        }

        echo "<br><br><br><br>";
        echo "<br>";
        echo '<hr style="border-top: 1px solid white;">';
        echo "<br>";

        echo '<div style="text-align: center; font-size: 60px; color: white;">';
        echo "Neues AG-Essen eintragen";
        echo '</div><br><br><br>';

        echo '<div style="width: 70%; margin: 0 auto; text-align: center;">';
        echo '<form method="post" enctype="multipart/form-data">';

        $options = [
            1  => "Netzbarkasse 1",
            2  => "Netzbarkasse 2",
            93 => "Kassenwartkasse 1",
            94 => "Kassenwartkasse 2",
            95 => "Tresor"
        ];

        echo '<div id="ibanContainer" style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="iban" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">IBAN:</label>';
        echo '<input type="text" id="iban" name="iban" style="width: 200px;">';
        echo '</div>';

        if ($_SESSION['NetzAG'] || $_SESSION['Vorstand']) {
            echo '<div id="dropdownContainer" style="display: none; text-align: center; margin-bottom: 10px;">';
            echo '<label for="bar" style="display: inline-block; width: 158px; color: white; font-size:25px; text-align: left;">Kasse:</label>';
            echo '<select id="bar" name="bar" style="width: 200px;">';
            echo '<option value="">Bitte auswählen</option>';
            foreach ($options as $value => $label) {
                echo '<option value="Bar ' . $value . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select>';
            echo '</div>';

            echo '<input type="checkbox" id="barkasseCheckbox" name="barkasseCheckbox" value="1" onclick="toggleIBAN()" style="color: white;"> <label for="barkasseCheckbox" style="color: white;">Von Barkasse bezahlt</label>';
            echo "<br><br>";
        }

        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<label for="betrag" style="display: inline-block; width: 150px; color: white; font-size:25px; text-align: left;">Betrag:</label>';
        echo '<input type="number" step="0.01" id="betrag" name="betrag" style="width: 200px;" required><br><br>';
        echo '</div>';

        echo '<div style="align-items: center; margin-bottom: 10px;">';
        echo '<label for="file" style="display: inline-block; width: 150px; color: white; font-size: 25px; text-align: left;">Rechnung:</label>';
        echo '<input type="file" id="file" name="file" style="width: 190px; background-color: white; border: 2px solid black; padding: 5px">';
        echo '</div>';
        echo '<br>';

        $sql = "SELECT uid, firstname, lastname FROM users WHERE CONCAT(',', groups, ',') LIKE CONCAT('%,', ?, ',%') AND pid in (11,64) ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        $ag_str = strval($ag);
        mysqli_stmt_bind_param($stmt, "s", $ag_str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $firstName, $lastName);
        while (mysqli_stmt_fetch($stmt)) {
            $name = strtok($firstName, ' ') . ' ' . strtok($lastName, ' ');

            echo '<div style="text-align: center; margin-bottom: 10px;">';
            echo '<label for="user_' . $uid . '" style="display: inline-block; color: white; font-size:25px; text-align: left;">';
            echo '<input type="checkbox" id="user_' . $uid . '" name="selected_users[]" value="' . $uid . '"> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            echo '</label>';
            echo '</div>';
        }
        mysqli_stmt_close($stmt);

        echo '<input type="hidden" id="selected_users_list" name="selected_users_list" value="">';
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    const checkboxes = document.querySelectorAll(\'input[name="selected_users[]"]\');';
        echo '    checkboxes.forEach(function(checkbox) {';
        echo '        checkbox.addEventListener("change", function() {';
        echo '            const selectedUsers = Array.from(checkboxes)';
        echo '                .filter(checkbox => checkbox.checked)';
        echo '                .map(checkbox => checkbox.value)';
        echo '                .join(",");';
        echo '            document.getElementById("selected_users_list").value = selectedUsers;';
        echo '        });';
        echo '    });';
        echo '});';
        echo '</script>';

        echo '<br>';

        echo '<div style="text-align: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="ag" value="' . htmlspecialchars($ag, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="reload" value="1">';
        echo '<button type="submit" name="esseneintragen" class="center-btn" style="display: block; margin: 0 auto;">Absenden</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<script>
            function toggleIBAN() {
                var ibanContainer = document.getElementById("ibanContainer");
                var dropdownContainer = document.getElementById("dropdownContainer");
                var checkBox = document.getElementById("barkasseCheckbox");
                if (checkBox.checked) {
                    ibanContainer.style.display = "none";
                    dropdownContainer.style.display = "block";
                } else {
                    ibanContainer.style.display = "block";
                    dropdownContainer.style.display = "none";
                }
            }
        </script>';

        ?>
        <div id="agessenEditBackdrop" class="agessen-edit-backdrop">
            <div class="agessen-edit-modal" role="dialog" aria-modal="true" aria-labelledby="agessenEditTitle">
                <button type="button" class="agessen-edit-close" id="closeAgessenEdit" aria-label="Schließen">×</button>
                <h2 id="agessenEditTitle">AG-Essen bearbeiten</h2>
                <p class="agessen-edit-note">Rechnung und Teilnehmer bleiben unverändert.</p>

                <form method="post" id="agessenEditForm">
                    <input type="hidden" name="ag" value="<?php echo htmlspecialchars($ag, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="edit_id" id="editAgessenId">

                    <div class="agessen-edit-row">
                        <label for="editAgessenBetrag"><strong>Betrag:</strong></label>
                        <input type="number" step="0.01" min="0.01" name="edit_betrag" id="editAgessenBetrag" required>
                    </div>

                    <div class="agessen-edit-row">
                        <label for="editAgessenIban"><strong>IBAN:</strong></label>
                        <input type="text" name="edit_iban" id="editAgessenIban" required>
                    </div>

                    <div class="agessen-edit-actions">
                        <button type="button" class="agessen-modal-btn agessen-modal-cancel" id="cancelAgessenEdit">Abbrechen</button>
                        <button type="submit" name="edit_agessen" class="agessen-modal-btn agessen-modal-save">Speichern</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (() => {
            const backdrop = document.getElementById('agessenEditBackdrop');
            const idInput = document.getElementById('editAgessenId');
            const betragInput = document.getElementById('editAgessenBetrag');
            const ibanInput = document.getElementById('editAgessenIban');

            document.querySelectorAll('.edit-agessen-btn').forEach(button => {
                button.addEventListener('click', () => {
                    idInput.value = button.dataset.id;
                    betragInput.value = button.dataset.betrag;
                    ibanInput.value = button.dataset.iban;
                    backdrop.style.display = 'flex';
                });
            });

            document.getElementById('closeAgessenEdit').addEventListener('click', () => {
                backdrop.style.display = 'none';
            });

            document.getElementById('cancelAgessenEdit').addEventListener('click', () => {
                backdrop.style.display = 'none';
            });

            backdrop.addEventListener('click', event => {
                if (event.target === backdrop) {
                    backdrop.style.display = 'none';
                }
            });
        })();
        </script>
        <?php
    }
} else {
    header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>
