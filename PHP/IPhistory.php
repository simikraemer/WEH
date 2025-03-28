<?php
  session_start();
  require('conn.php');

  $suche = FALSE;
  
  if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    if (!empty($searchTerm)) {
        $suche = TRUE;
        $searchTerm = mysqli_real_escape_string($conn, $searchTerm);
        if (ctype_digit($searchTerm)) {
          $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                  (name LIKE '%$searchTerm%' OR 
                   username LIKE '%$searchTerm%' OR 
                   geburtsort LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (uid = '$searchTerm') OR 
                   (oldroom = '$searchTerm'))";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm FROM users WHERE 
                    (name LIKE '%$searchTerm%' OR 
                     username LIKE '%$searchTerm%' OR 
                     email LIKE '%$searchTerm%' OR 
                     aliase LIKE '%$searchTerm%' OR 
                     geburtsort LIKE '%$searchTerm%')";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm);
        }

        header('Content-Type: application/json');
        echo json_encode($searchedusers);
    } else {
        echo json_encode([]); // Senden einer leeren JSON-Antwort, wenn der Suchbegriff leer ist
    }
    exit;
  }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="WEH.css" media="screen">
</head>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && $_SESSION['NetzAG']) {
    load_menu();


    $resultData = [];

    if (isset($_POST["uid"])) {   
        $uid = $_POST["uid"];
        $select_uid_ips = "SELECT i.ip, i.starttime, i.endtime, u.firstname, u.lastname, u.room, u.oldroom 
                           FROM iphistory i 
                           JOIN users u ON i.uid = u.uid 
                           WHERE i.uid = ? 
                           ORDER BY INET_ATON(i.ip) ASC";
    
        $stmt = $conn->prepare($select_uid_ips);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
    
        while ($row = $res->fetch_assoc()) {
            $start = date("d.m.Y", $row['starttime']);
            $end = (empty($row['endtime']) || $row['endtime'] == 0) ? "offen" : date("d.m.Y", $row['endtime']);
            $first = explode(' ', $row['firstname'])[0];
            $last = explode(' ', $row['lastname'])[0];
            $name = $first . " " . $last;
    
            $room = ($row['room'] != 0) ? $row['room'] : (($row['oldroom'] != 0) ? $row['oldroom'] : "-");
    
            $resultData[] = [                
                'uid' => $uid,
                'ip' => $row['ip'],
                'start' => $start,
                'end' => $end,
                'name' => $name,
                'room' => $room
            ];
        }
    }
    
    if (isset($_POST["ip"])) {
        $ip = $_POST["ip"];
        $select_ip_ips = "SELECT i.uid, i.starttime, i.endtime, u.firstname, u.lastname, u.room, u.oldroom 
                          FROM iphistory i 
                          JOIN users u ON i.uid = u.uid 
                          WHERE i.ip = ? 
                          ORDER BY INET_ATON(i.ip) ASC";
    
        $stmt = $conn->prepare($select_ip_ips);
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $res = $stmt->get_result();
    
        while ($row = $res->fetch_assoc()) {
            $start = date("d.m.Y", $row['starttime']);
            $end = (empty($row['endtime']) || $row['endtime'] == 0) ? "offen" : date("d.m.Y", $row['endtime']);
            $first = explode(' ', $row['firstname'])[0];
            $last = explode(' ', $row['lastname'])[0];
            $name = $first . " " . $last;
    
            $room = ($row['room'] != 0) ? $row['room'] : (($row['oldroom'] != 0) ? $row['oldroom'] : "-");
    
            $resultData[] = [
                'uid' => $row['uid'],
                'ip' => $ip,
                'start' => $start,
                'end' => $end,
                'name' => $name,
                'room' => $room
            ];
        }
    }
    

    echo "<!DOCTYPE html>";
    echo "<html lang='de'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>POST Daten</title>";
    echo "<style>";
    echo "body { color: white; text-align: center; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";

    if (!empty($_POST)) {
        foreach ($_POST as $key => $value) {
            echo "<div class='post-entry'><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</div>";
        }
    } else {
        echo "<p>Keine POST-Daten empfangen.</p>";
    }

    echo "</body>";
    echo "</html>";


    echo "<!DOCTYPE html>";
    echo "<html lang='de'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>Dynamische Benutzersuche</title>";
    echo "</head>";
    echo "<body>";
    echo "<input type='text' id='searchInput' placeholder='Suche nach User...' onkeyup='searchUser(this.value)'>";
    echo "</body>";
    echo "</html>";
    

    echo "<div style='margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;'>";
    echo "<table>";
    echo "<tr></tr>";
    
    if (!empty($searchedusers)) {
        foreach ($searchedusers as $uid => $users) {
            foreach ($users as $user) {
                $user_id = htmlspecialchars($user['uid'], ENT_QUOTES);
                $user_name = htmlspecialchars($user["username"], ENT_QUOTES);
                $name_html = htmlspecialchars($user["name"], ENT_QUOTES);
                $room = $user['room'] == 0 ? $user['oldroom'] : $user['room'];
                $color = $user['room'] == 0 ? 'red' : '#7FFF00';
                echo "<tr><td>" . $user_id . "</td><td>" . $user_name . "</td><td><a href='javascript:void(0);' onclick='submitForm(\"$user_id\");' class='white-text' style='user-select: none;'>" . $name_html . "</a></td><td style='color:" . $color . ";'>" . $room . "</td></tr>";
            }
        }
    }
    echo "</table>";
    echo "</div>";

    echo "<script>";
    echo "function searchUser(searchValue) {";
    echo "    fetch('?search=' + encodeURIComponent(searchValue))";
    echo "    .then(response => response.json())";
    echo "    .then(data => {";
    echo "        const table = document.querySelector('tbody');";
    echo "        table.innerHTML = '';";
    echo "        Object.keys(data).forEach(uid => {";
    echo "            data[uid].forEach(user => {";
    echo "                const row = table.insertRow();";
    echo "                const cell1 = row.insertCell(0);";
    echo "                const cell2 = row.insertCell(1);";
    echo "                const cell3 = row.insertCell(2);";
    echo "                const cell4 = row.insertCell(3);";
    echo "                const cell5 = row.insertCell(4);";
    echo "                cell1.textContent = user.uid;";
    echo "                cell2.textContent = user.username;";
    echo "                const link = document.createElement('a');";
    echo "                link.href = 'javascript:void(0);';";
    echo "                link.className = 'white-text';";
    echo "                link.style.userSelect = 'none';";
    echo "                link.textContent = user.name;";
    echo "                link.onclick = function() { submitForm(user.uid); };";
    echo "                cell3.appendChild(link);";
    echo "                const room = user.room == 0 ? user.oldroom : user.room;";
    echo "                cell5.textContent = room;";
    echo "                if (user.turm === 'weh') {";
    echo "                    cell4.textContent = 'WEH';";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#18ec13';";
    echo "                } else if (user.turm === 'tvk') {";
    echo "                    cell4.textContent = 'TvK';";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFA500';";
    echo "                } else {";
    echo "                    cell4.textContent = user.turm.toUpperCase();";
    echo "                    cell4.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
    echo "                    cell5.style.color = user.room == 0 ? '#a9a9a9' : '#FFFFFF';";
    echo "                }";
    echo "                cell4.style.paddingRight = '15px';";
    echo "            });";
    echo "        });";
    echo "    });";
    echo "}";
    
    echo "function submitForm(userId) {";
    echo "    var form = document.createElement('form');";
    echo "    form.method = 'post';";
    echo "    form.action = '';";
    echo "    var hiddenField = document.createElement('input');";
    echo "    hiddenField.type = 'hidden';";
    echo "    hiddenField.name = 'uid';";
    echo "    hiddenField.value = userId;";
    echo "    form.appendChild(hiddenField);";
    echo "    document.body.appendChild(form);";
    echo "    form.submit();";
    echo "}";
    echo "</script>";
        
    echo "</body>";
    echo "</html>";



    $select_allhistory_ips = "SELECT ip FROM iphistory ORDER BY INET_ATON(ip) ASC";
    $result = $conn->query($select_allhistory_ips);
    
    $ips = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $ips[] = $row['ip'];
        }
    }
    
    
    echo "<!DOCTYPE html>";
    echo "<html lang='de'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>IP Suche</title>";
    echo "</head>";
    echo "<body>";
    
    // Formular mit Dropdown
    echo "<form method='POST' id='ipForm'>";
    echo "<select name='ip' id='searchInput' onchange='document.getElementById(\"ipForm\").submit();'>";
    echo "<option value=''>-- IP auswählen --</option>";
    foreach ($ips as $ip) {
        echo "<option value='" . htmlspecialchars($ip) . "'>" . htmlspecialchars($ip) . "</option>";
    }
    echo "</select>";
    echo "</form>";
    
    echo "</body>";
    echo "</html>";

    echo "<br><br><hr><br><br>";


    if (!empty($resultData)) {
        echo "<!DOCTYPE html>";
        echo "<html lang='de'>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<title>IP-History Tabelle</title>";
        echo "<style>";
        echo "th.sort-asc::after { content: ' ▲'; }";
        echo "th.sort-desc::after { content: ' ▼'; }";
        echo "tr.clickable-row { cursor: pointer; }";
        echo "</style>";
        echo "</head>";
        echo "<body>";
    
        // JavaScript für POST-Redirect
        echo <<<JS
    <script>
    function goToUser(uid) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'User.php';
    
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = uid;
        form.appendChild(input);
    
        document.body.appendChild(form);
        form.submit();
    }
    </script>
    JS;
    
        echo "<table class='grey-table' id='dataTable' style='border-collapse: collapse; width: 80%; font-size:18px; margin: 40px auto; color: white; font-family: Arial, sans-serif;'>";
        echo "<thead><tr>";
        echo "<th onclick='sortTable(0)' style='cursor: pointer; padding: 10px; border: 1px solid #888; background-color: #333;'>Name</th>";
        echo "<th onclick='sortTable(1)' style='cursor: pointer; padding: 10px; border: 1px solid #888; background-color: #333;'>Raum</th>";
        echo "<th onclick='sortTable(2)' style='cursor: pointer; padding: 10px; border: 1px solid #888; background-color: #333;'>IP</th>";
        echo "<th onclick='sortTable(3)' style='cursor: pointer; padding: 10px; border: 1px solid #888; background-color: #333;'>Start</th>";
        echo "<th onclick='sortTable(4)' style='cursor: pointer; padding: 10px; border: 1px solid #888; background-color: #333;'>Ende</th>";
        echo "</tr></thead>";
        echo "<tbody>";
        
        foreach ($resultData as $row) {
            $uid = htmlspecialchars($row['uid']);
            $startDisplay = htmlspecialchars($row['start']);
            $endDisplay = (empty($row['end']) || $row['end'] === "0") ? "offen" : htmlspecialchars($row['end']);
            $startSort = strtotime(str_replace('.', '-', $row['start']));
            $endSort = (!empty($row['end']) && $row['end'] !== "0") ? strtotime(str_replace('.', '-', $row['end'])) : 9999999999;
    
            echo "<tr class='clickable-row' onclick='goToUser(\"{$uid}\")'>";
            echo "<td style='padding: 8px; border: 1px solid #666;'>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #666;'>" . htmlspecialchars($row['room']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #666;'>" . htmlspecialchars($row['ip']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #666;' data-sort='{$startSort}'>{$startDisplay}</td>";
            echo "<td style='padding: 8px; border: 1px solid #666;' data-sort='{$endSort}'>{$endDisplay}</td>";
            echo "</tr>";
        }
    
        echo "</tbody>";
        echo "</table>";
        
    
        echo <<<JS
    <script>
    let sortDirection = {};
    
    function sortTable(colIndex) {
        const table = document.getElementById("dataTable");
        const tbody = table.tBodies[0];
        const rows = Array.from(tbody.querySelectorAll("tr"));
        const ths = table.tHead.rows[0].cells;
    
        // Reset sort icons
        for (let i = 0; i < ths.length; i++) {
            ths[i].classList.remove("sort-asc", "sort-desc");
        }
    
        const direction = sortDirection[colIndex] === "asc" ? "desc" : "asc";
        sortDirection[colIndex] = direction;
        ths[colIndex].classList.add("sort-" + direction);
    
        rows.sort((a, b) => {
            const aSort = a.cells[colIndex].getAttribute("data-sort") || a.cells[colIndex].textContent.trim().toLowerCase();
            const bSort = b.cells[colIndex].getAttribute("data-sort") || b.cells[colIndex].textContent.trim().toLowerCase();
    
            if (!isNaN(aSort) && !isNaN(bSort)) {
                return direction === "asc"
                    ? parseInt(aSort) - parseInt(bSort)
                    : parseInt(bSort) - parseInt(aSort);
            }
    
            return direction === "asc"
                ? aSort.localeCompare(bSort, 'de', { numeric: true })
                : bSort.localeCompare(aSort, 'de', { numeric: true });
        });
    
        rows.forEach(row => tbody.appendChild(row));
    }
    </script>
    JS;
    
        echo "</body></html>";
    } else {
        echo "<p style='text-align:center;'>Keine Daten zum Anzeigen.</p>";
    }
    


}
else {
  header("Location: denied.php");
}
$conn->close();
?>
</body>
</html>