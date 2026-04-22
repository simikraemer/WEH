<?php
session_start();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function createTableFromArray(array $data, string $title): void {
    echo '<div>';
    echo '<h2 style="text-align: center;">' . h($title) . '</h2>';
    echo '<table class="grey-table">
        <tr>            
            <th>Name</th>
            <th>Agent</th>
            <th>Delete</th>
        </tr>';

    foreach ($data as $row) {
        $rowId = (int)$row['id'];

        echo "<form method='POST' style='display: none;' id='form_{$rowId}'>";
        echo "<input type='hidden' name='edit_blacklist' value='{$rowId}'>";
        echo "</form>";

        echo "<tr onclick='document.getElementById(\"form_{$rowId}\").submit();' style='cursor: pointer;'>";
        echo '<td>' . h($row['name']) . '</td>';
        echo '<td>' . h($row['agent']) . '</td>';

        echo "<td>";
        echo "<form method='post' style='margin: 0;' onclick='event.stopPropagation();'>";
        echo "<button type='submit' name='delete_blacklist' value='{$rowId}' style='background: none; border: none; cursor: pointer; padding: 0;'>";
        echo '<img src="images/trash_white.png" class="animated-trash-icon" style="width: 24px; height: 24px;">';
        echo "</button>";
        echo "</form>";
        echo "</td>";

        echo "</tr>";
    }

    echo '</table>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <script>
    function updateButton(button) {
        button.style.backgroundColor = "green";
        button.innerHTML = "Saved";
        setTimeout(function() {
            button.style.backgroundColor = "";
            button.innerHTML = "Speichern";
        }, 2000);
    }
    </script>
</head>
<body>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && !empty($_SESSION['NetzAG'])) {
    load_menu();

    if (isset($_POST['edit_blacklist'])) {
        $id = (int)$_POST['edit_blacklist'];

        $sql = "SELECT m.id, m.type, m.name
                FROM mail_blacklist m
                WHERE m.id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $id, $type, $adr);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        echo '<div class="blacklist_container">';
        echo '<form method="POST" class="sperre_form">';
        echo '<input type="hidden" name="id" value="' . (int)$id . '">';
        echo '<label for="type" class="sperre_form-label">Typ:</label>';
        echo '<select name="type" id="type" class="sperre_form-select">';
        echo '<option value="0"' . ((int)$type === 0 ? ' selected' : '') . '>Domain</option>';
        echo '<option value="1"' . ((int)$type === 1 ? ' selected' : '') . '>Adresse</option>';
        echo '</select><br><br>';
        echo '<label for="name" class="sperre_form-label">Mail/Domain:</label>';
        echo '<input type="text" name="name" id="name" required class="sperre_form-control" value="' . h($adr) . '"><br><br>';
        echo '<div style="display: flex; justify-content: center;">';
        echo '<input type="submit" name="exec_edit_blacklist" value="Speichern" class="center-btn">';
        echo '</div>';
        echo '</form>';
        echo '</div>';

    } else {

        if (isset($_POST['add_blacklist'])) {
            $type = isset($_POST['type']) ? (int)$_POST['type'] : null;
            $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
            $agent = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

            $insert_sql = "INSERT INTO mail_blacklist (type, name, agent) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $insert_sql);
            if (!$stmt) {
                die('Error: ' . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "isi", $type, $name, $agent);
            $execute_result = mysqli_stmt_execute($stmt);
            if (!$execute_result) {
                die('Execution failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }

        if (isset($_POST['exec_edit_blacklist'])) {
            $id = (int)$_POST['id'];
            $type = (int)$_POST['type'];
            $name = trim((string)$_POST['name']);
            $agent = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

            $update_sql = "UPDATE mail_blacklist SET type = ?, name = ?, agent = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            if (!$stmt) {
                die('Error: ' . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "isii", $type, $name, $agent, $id);
            $execute_result = mysqli_stmt_execute($stmt);
            if (!$execute_result) {
                die('Execution failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }

        if (isset($_POST['delete_blacklist'])) {
            $id = (int)$_POST['delete_blacklist'];

            $delete_sql = "DELETE FROM mail_blacklist WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_sql);
            if (!$stmt) {
                die('Error: ' . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "i", $id);
            $execute_result = mysqli_stmt_execute($stmt);
            if (!$execute_result) {
                die('Execution failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }

        echo '<div class="blacklist_container">';
        echo '<form method="POST" class="sperre_form">';
        echo '<label for="type" class="sperre_form-label">Typ:</label>';
        echo '<select name="type" id="type" class="sperre_form-select" required>';
        echo '<option value="">Bitte wählen</option>';
        echo '<option value="0">Domain</option>';
        echo '<option value="1">Adresse</option>';
        echo '</select><br><br>';
        echo '<label for="name" class="sperre_form-label">Mail/Domain:</label>';
        echo '<input type="text" name="name" id="name" required class="sperre_form-control"><br><br>';
        echo '<div style="display: flex; justify-content: center;">';
        echo '<input type="submit" name="add_blacklist" value="Zur Blacklist hinzufügen" class="center-btn">';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<br><br><br>';

        $sql = "
            SELECT
                m.id,
                m.type,
                m.name,
                CASE
                    WHEN m.agent = 472 THEN 'Ticketsystem'
                    WHEN u.name IS NOT NULL AND u.name <> '' THEN u.name
                    ELSE CONCAT('UID ', m.agent)
                END AS agent_name
            FROM mail_blacklist m
            LEFT JOIN users u
                ON m.agent = u.uid
            ORDER BY m.type ASC, m.name ASC
        ";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $id, $type, $adr, $agentName);

        $domains = [];
        $addresses = [];

        while (mysqli_stmt_fetch($stmt)) {
            if ((int)$type === 0) {
                $domains[] = [
                    'id'    => (int)$id,
                    'type'  => 'Domain',
                    'name'  => $adr,
                    'agent' => $agentName,
                ];
            } elseif ((int)$type === 1) {
                $addresses[] = [
                    'id'    => (int)$id,
                    'type'  => 'Adresse',
                    'name'  => $adr,
                    'agent' => $agentName,
                ];
            }
        }

        mysqli_stmt_close($stmt);

        echo '<div style="display: flex; justify-content: center; gap: 40px; align-items: flex-start; flex-wrap: wrap;">';
        createTableFromArray($domains, 'Domains');
        createTableFromArray($addresses, 'Adressen');
        echo '</div>';
    }
} else {
    header("Location: denied.php");
    exit;
}

$conn->close();
?>
</body>
</html>