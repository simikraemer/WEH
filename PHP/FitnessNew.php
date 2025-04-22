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
          $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE
                  (pid in (11,12) AND (name LIKE '%$searchTerm%' OR 
                   (room = '$searchTerm') OR 
                   (oldroom = '$searchTerm')))
                   ORDER BY room ASC";
        } else {
            // Wenn $searchTerm keine gültige Zahl ist, keine Suche in room und oldroom durchführen
            $sql = "SELECT uid, name, username, room, oldroom, turm, groups FROM users WHERE 
                    (pid in (11,12) AND (name LIKE '%$searchTerm%'))
                   ORDER BY room ASC";
        }
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $name, $username, $room, $oldroom, $turm, $groups);
        $searchedusers = array();
        while (mysqli_stmt_fetch($stmt)) {
            $searchedusers[$uid][] = array("uid" => $uid, "name" => $name, "username" => $username, "room" => $room, "oldroom" => $oldroom, "turm" => $turm, "groups" => $groups);
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
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && $_SESSION['valid'] && ($_SESSION['SportAG'] || $_SESSION['Webmaster'])) {
    load_menu();    
    $zeit = time();

    if (isset($_POST["reload"]) && $_POST["reload"] == 1) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adduser'])) {
            $uid = intval($_POST['adduser']); // Nur int zulassen
            $tstamp = time(); // Falls $zeit nicht global ist, direkt hier holen
        
            $sql = "INSERT INTO fitness (uid, tstamp) VALUES (?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
        
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ii", $uid, $tstamp);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $to = $_POST["username"] . "@" . $_POST["turm"] . ".rwth-aachen.de";
            $address = $mailconfig['address'];
            $subject = "Sport-AG Fitness Equipment Access Confirmation";
            $headers = "From: " . $address . "\r\n";
            $headers .= "Reply-To: sport@weh.rwth-aachen.de\r\n";
            
            $message = "Hello " . $_POST["username"] . ",\n\n";
            $message .= "you successfully participated in the fitness introduction.\n\n";
            $message .= "To complete the process and gain access to the fitness equipment at WEH, please submit your confirmation using the following link:\n\n";
            $message .= "https://backend.weh.rwth-aachen.de/FitnessAccept.php\n\n";
            $message .= "Best regards,\n";
            $message .= "Sport-AG";            
            
            mail($to, $subject, $message, $headers);            
        }
        echo '<div style="text-align: center;">
        <span style="color: green; font-size: 20px;">User wurde per Mail informiert.</span><br><br>
        </div>';
        echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
        echo "<script>
          setTimeout(function() {
            document.forms['reload'].submit();
          }, 2000);
        </script>";
    }
    

    # Bereits freigeschaltete User abfragen
    $sql = "SELECT uid FROM fitness";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $uid);
    $freigeschaltet = [];
    while (mysqli_stmt_fetch($stmt)) {
        $freigeschaltet[] = $uid;
    }

    echo '<h2 class="center">Wähle User aus, die in die Nutzung der Fitnessgeräte eingewiesen wurde.</h2>';

    echo '
    <script>const freigeschalteteUIDs = ' . json_encode($freigeschaltet) . ';</script>

    <style>
        #userSearch {
            display: block;
            margin: 20px auto;
            height: 30px;
            font-size: 25px;
        }
        .center-table {
            margin-left: auto;
            margin-right: auto;
        }
        .disabled {
            color: gray;
            text-decoration: none;
        }
        .white-text {
            color: white;
            text-decoration: none;
        }
    </style>

    <input type="text" id="userSearch" placeholder="Name oder Raum..." autocomplete="off">

    <div style="margin-left: auto; margin-right: auto; margin-bottom: 2%; display: table; table-layout: fixed; padding: 10px;">
        <table class="center-table">
            <thead id="tableHeader" style="display: none;">
                <tr><th>Name</th><th>Raum</th></tr>
            </thead>
            <tbody id="userTableBody">
            </tbody>
        </table>
    </div>

    <form id="addUserForm" method="POST" style="display:none;">
        <input type="hidden" name="adduser" id="addUserUid">
        <input type="hidden" name="reload" value="1">
        <input type="hidden" name="username" id="addUserName">
        <input type="hidden" name="turm" id="addUserTurm">
    </form>

    <script>
    document.getElementById("userSearch").addEventListener("input", function () {
        const query = this.value.trim();
        const tableBody = document.getElementById("userTableBody");
        const tableHeader = document.getElementById("tableHeader");

        if (query.length === 0) {
            tableBody.innerHTML = "";
            tableHeader.style.display = "none";
            return;
        }

        fetch("?search=" + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                tableBody.innerHTML = "";
                if (Object.keys(data).length === 0) {
                    tableHeader.style.display = "none";
                    return;
                }
                tableHeader.style.display = "table-header-group";

                Object.values(data).forEach(userArray => {
                    userArray.forEach(user => {
                        const row = document.createElement("tr");
                        const cellName = document.createElement("td");
                        const cellRoom = document.createElement("td");

                        const link = document.createElement("span");
                        link.textContent = user.name;

                        if (freigeschalteteUIDs.includes(parseInt(user.uid))) {
                            // User bereits freigeschaltet -> ausgrauen und deaktivieren
                            link.classList.add("disabled");
                            link.style.cursor = "default";
                        } else {
                            // User noch nicht freigeschaltet -> aktiv
                            link.classList.add("white-text");
                            link.style.cursor = "pointer";
                            link.onclick = function () {
                                document.getElementById("addUserUid").value = user.uid;
                                document.getElementById("addUserName").value = user.username;
                                document.getElementById("addUserTurm").value = user.turm;
                                document.getElementById("addUserForm").submit();
                            };
                        }

                        cellName.appendChild(link);
                        cellRoom.textContent = user.room == 0 ? user.oldroom : user.room;

                        // Raumfarbe anhand des Turms
                        const roomColor = user.turm === "tvk" ? "#E49B0F" : "#11a50d";
                        cellRoom.style.color = roomColor;

                        row.appendChild(cellName);
                        row.appendChild(cellRoom);
                        tableBody.appendChild(row);
                    });
                });
            });
    });
    </script>
    ';



    $conn->close();
} else {
    header("Location: denied.php");
}
?>
</body>
</html>