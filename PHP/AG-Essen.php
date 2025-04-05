<?php
  session_start();
?>
<!DOCTYPE html>
<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["Webmaster"] || $_SESSION["Kassenwart"] || $_SESSION['Kassenpruefer']) ) {
    load_menu();

    if (isset($_POST["confirm"])) {
        $id = $_POST["id"];
        $pfad = $_POST["pfad"];
        $insert_betrag = (-1) * $_POST["betrag"];
        $iban = $_POST["iban"];
        $ag = $_POST["ag"];
        $dummy_uid = 492;
        $uid = $_POST["uid"];
        $zeit = time();
        $insert_beschreibung = "AG-Essen ". $ag_complete[$ag]['name'];
        $agent = $_SESSION["uid"];

        // AG Essen eintragen
        $sql = "UPDATE agessen SET status = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);


        if (strpos($iban, "Bar") === false) {
            $konto = 8; // Nicht so wichtig, lol. Sowas wie "Diverses"
            $kasse = 92; // Hauskonto DE37 3905 0000 1070 3345 84

            // Überweisung in transfers eintragen für Übersicht in Kassenwart.php
            $insert_sql = "INSERT INTO transfers (tstamp, uid, beschreibung, betrag, konto, kasse, agent) VALUES (?,?,?,?,?,?,?)";
            $insert_var = array($zeit, $dummy_uid, $insert_beschreibung, $insert_betrag, $konto, $kasse, $agent);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iisdiii", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $x_string = substr($iban, strpos($iban, "Bar") + strlen("Bar"));
            $kasse = intval(trim($x_string));

            // In Barkassendokumentation eintragen
            $insert_sql = "INSERT INTO barkasse (tstamp, uid, beschreibung, betrag, kasse, pfad) VALUES (?,?,?,?,?,?)";
            $insert_var = array($zeit, $uid, $insert_beschreibung, $insert_betrag, $kasse, $pfad);
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "iisdis", ...$insert_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

    }

    $zeit = time();
    $startOfCurrentSemester = unixtime2startofsemester($zeit);
    $semester = unixtime2semester($zeit);

    echo '<div style="text-align: center; font-size: 50px; color: white;">';
    echo 'Offene Zahlungen';
    echo '</div>';
    echo "<br>";

    echo "<table class='agessentable'>";
    echo "<tr><th>Datum</th><th>AG</th><th>Betrag</th><th>IBAN</th><th>Kontoinhaber</th><th>Rechnung</th><th>Teilnehmer</th><th>Bestätigen</th></tr>";

    $sql = "SELECT a.id, a.tstamp, a.pfad, a.betrag, a.iban, a.ag, a.uid,
    (SELECT CONCAT(SUBSTRING_INDEX(u.firstname, ' ', 1), ' ', SUBSTRING_INDEX(u.lastname, ' ', 1))
     FROM weh.users u
     WHERE u.uid = a.uid) AS full_name,
    GROUP_CONCAT(
        CONCAT(
            SUBSTRING_INDEX(u.firstname, ' ', 1), -- Abschneiden bei Leerzeichen
            ' ',
            LEFT(u.lastname, 1), -- Erster Buchstabe des Nachnamens
            '.'
        ) ORDER BY FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) SEPARATOR ', '
    ) AS teilnehmer_namen
    FROM weh.agessen a
    JOIN weh.users u ON FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) > 0
    WHERE a.status = 0
    GROUP BY a.id
    ORDER BY a.tstamp DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $tstamp, $pfad, $betrag, $iban, $ag, $uid, $kontoinhaber, $teilnehmerstring);
    while (mysqli_stmt_fetch($stmt)) {
        $tstamp_show = date('d.m.Y', $tstamp);
        $pfad_show = '<a href="'.$pfad.'" target="_blank" class="white-text">[Link]</a>';
        $agname = $ag_complete[$ag]['name'];
        $betrag_show = number_format($betrag, 2, ',', '.') . ' €';
        echo "<tr><td>$tstamp_show</td><td>$agname</td><td>$betrag_show</td><td>$iban</td><td>$kontoinhaber</td><td>$pfad_show</td><td>$teilnehmerstring</td>";
        echo "<td><form method='post'>";
        echo "<input type='hidden' name='id' value='$id'>";
        echo "<input type='hidden' name='tstamp' value='$tstamp'>";
        echo "<input type='hidden' name='pfad' value='$pfad'>";
        echo "<input type='hidden' name='betrag' value='$betrag'>";
        echo "<input type='hidden' name='iban' value='$iban'>";
        echo "<input type='hidden' name='uid' value='$uid'>";
        echo "<input type='hidden' name='ag' value='$ag'>";
        echo "<button type='submit' name='confirm' class='center-btn'>Überwiesen</button>";
        echo "</form></td></tr>";
    }
    echo "</table>";
    mysqli_stmt_close($stmt);
    echo "<br><br><br><br>";
    
    # -------------------------------------------------
    echo "<br>";
    echo '<hr style="border-top: 1px solid white;">';
    echo "<br>";
    # -------------------------------------------------

    echo '<div style="text-align: center; font-size: 50px; color: white;">';
    echo 'Übersicht bezahlte Rechnungen';
    echo '</div>';
    echo "<br>";

    $bisherige_semester = array();
    $sql = "SELECT DISTINCT tstamp FROM agessen ORDER BY tstamp DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $tstamp);
    while (mysqli_stmt_fetch($stmt)) {
        $startOfSemester = unixtime2startofsemester($tstamp);
        $semester = unixtime2semester($tstamp);
        
        if (!isset($bisherige_semester[$semester])) {
            $bisherige_semester[$semester] = $startOfSemester;
        }
    }    

    // Aktuelles Semester bestimmen
    $current_time = time();
    $current_semester = unixtime2semester($current_time);
    $current_semester_start = unixtime2startofsemester($current_time);

    // Falls über POST ein Semester ausgewählt wurde, dieses verwenden
    $selected_semester_start = isset($_POST["selected_semester"]) ? $_POST["selected_semester"] : $current_semester_start;

    echo '<div style="display: flex; justify-content: center;">';
    echo '<form action="" method="POST">';
    echo '<div style="display: flex;">';
    echo '<select name="selected_semester" class="form-control" style="font-size: 30px;" onchange="this.form.submit()">';

    foreach ($bisherige_semester as $semester => $startOfSemester) {
        // Prüfen, ob das aktuelle Semester ausgewählt ist
        $selected = ($startOfSemester == $selected_semester_start) ? "selected" : "";
        echo '<option value="' . $startOfSemester . '" ' . $selected . '>' . $semester . '</option>';
    }

    echo '</select>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo "<br><br><br>";

    // Zeitraum für das ausgewählte oder das aktuelle Semester festlegen
    if (isset($_POST["selected_semester"])) {
        $table_start = $_POST["selected_semester"];
    } else {
        $table_start = $current_semester_start;
    }
    $table_start_date = date("Y-m-d", $table_start);

    if (date("m", $table_start) == 4) {
        $table_end_date = date("Y-m-d", strtotime("01-10-" . date("Y", $table_start)));
    } elseif (date("m", $table_start) == 10) {
        $table_end_date = date("Y-m-d", strtotime("01-04-" . (date("Y", $table_start) + 1)));
    }
    $table_end = strtotime($table_end_date);

    echo "<table class='agessentable' style='margin-bottom: 60px;'>";
    echo "<tr><th>Datum</th><th>AG</th><th>Betrag</th><th>IBAN</th><th>Rechnung</th><th>Teilnehmer</th></tr>";

    $sql = "SELECT a.id, a.tstamp, a.pfad, a.betrag, a.iban, a.ag, 
    GROUP_CONCAT(
        CONCAT(
            SUBSTRING_INDEX(u.firstname, ' ', 1), -- Abschneiden bei Leerzeichen
            ' ',
            LEFT(u.lastname, 1), -- Erster Buchstabe des Nachnamens
            '.'
        ) ORDER BY FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) SEPARATOR ', '
    ) AS teilnehmer_namen
    FROM weh.agessen a
    JOIN weh.users u ON FIND_IN_SET(u.uid, REPLACE(a.teilnehmer, ',', ',')) > 0
    WHERE a.status = 1 AND a.tstamp > ? AND a.tstamp < ?
    GROUP BY a.id
    ORDER BY a.tstamp DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $table_start, $table_end);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $tstamp, $pfad, $betrag, $iban, $ag, $teilnehmerstring);
    while (mysqli_stmt_fetch($stmt)) {
        $tstamp_show = date('d.m.Y', $tstamp);
        $pfad_show = '<a href="'.$pfad.'" target="_blank" class="white-text">[Link]</a>';
        $agname = $ag_complete[$ag]['name'];
        $betrag_show = number_format($betrag, 2, ',', '.') . ' €';
        echo "<tr><td>$tstamp_show</td><td>$agname</td><td>$betrag_show</td><td>$iban</td><td>$pfad_show</td><td>$teilnehmerstring</td></tr>";
    }
    echo "</table>";
    mysqli_stmt_close($stmt);


}
else {
  header("Location: denied.php");
}
// Close the connection to the database
$conn->close();
?>
</body>
</html>