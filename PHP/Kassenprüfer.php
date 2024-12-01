<?php
  session_start();
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
<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
$agName = "Kassenprüfer";
$ag = 26;
$sprecherAGnumber = 26;

if (auth($conn) && ($_SESSION["Kassenwart"] || $_SESSION["Webmaster"])) {
    load_menu();
	echo '<div style="text-align: center;">';

	if (isset($_POST["process_austritt"]) && $_POST["reload"] == 1) {
		$uid = $_SESSION["uid"];

        if ($_SESSION["sprecher"] === $ag) {
		    $sql = "UPDATE users SET sprecher = 0, groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', ? , ','), ',')) WHERE uid = ?";
            $_SESSION["sprecher"] = 0;
        } else {
            $sql = "UPDATE users SET groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', ? , ','), ',')) WHERE uid = ?";
        }			
		$stmt = mysqli_prepare($conn, $sql);
		mysqli_stmt_bind_param($stmt, "ii", $ag, $uid);	
		mysqli_stmt_execute($stmt);
		mysqli_stmt_free_result($stmt);
		mysqli_stmt_close($stmt);

        $_SESSION[$agName] = false;

		echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
		echo "<script>
			setTimeout(function() {
				document.forms['reload'].submit();
			}, 0000);
		</script>";

		echo "<span style='color: green; font-size: 20px;'>Erfolgreich ausgetreten.</span><br><br>";

	} elseif (isset($_POST["process_transfer"]) && $_POST["reload"] == 1) {
		$uidNewSprecher = $_POST["uid"];
		$uidOldSprecher = $_SESSION["uid"];

		$sql = "UPDATE users SET sprecher = ? WHERE uid = ?";
		$stmt = mysqli_prepare($conn, $sql);
		mysqli_stmt_bind_param($stmt, "ii", $sprecherAGnumber, $uidNewSprecher);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_free_result($stmt);
		mysqli_stmt_close($stmt);
	
		if (isset($_POST["leave_ag"])) {
			$sql = "UPDATE users SET sprecher = 0, groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', ? , ','), ',')) WHERE uid = ?";			
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "ii", $sprecherAGnumber, $uidOldSprecher);			
		} else {
			$sql = "UPDATE users SET sprecher = 0 WHERE uid = ?";
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "i", $uidOldSprecher);
		}
		mysqli_stmt_execute($stmt);
		mysqli_stmt_free_result($stmt);
		mysqli_stmt_close($stmt);

		$_SESSION["sprecher"] = 0;
		isset($_POST["leave_ag"]) && $_SESSION[$agName] = false;			
		
		echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
		echo "<script>
			setTimeout(function() {
				document.forms['reload'].submit();
			}, 0000);
		</script>";

		echo "<span style='color: green; font-size: 20px;'>Erfolgreich abgetreten.</span><br><br>";

	} else {
		if (isset($_POST["process_kick"]) && $_POST["reload"] == 1) {
			$uid = $_POST["uid"];
			
			$sql = "UPDATE users SET groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', ? , ','), ',')) WHERE uid = ?";			
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "ii", $sprecherAGnumber, $uid);	
			mysqli_stmt_execute($stmt);
			mysqli_stmt_free_result($stmt);
			mysqli_stmt_close($stmt);
			echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
			echo "<script>
				setTimeout(function() {
					document.forms['reload'].submit();
				}, 0000);
			</script>";
	
			echo "<span style='color: green; font-size: 20px;'>Der User wurde erfolgreich entfernt.</span><br><br>";
		} elseif (isset($_POST["process_add"]) && $_POST["reload"] == 1) {
			$uid = $_POST["uid"];
			
			$sql = "UPDATE users SET groups = CONCAT(groups, ',{$sprecherAGnumber}') WHERE uid = ?";
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "i", $uid);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_free_result($stmt);
			mysqli_stmt_close($stmt);
			echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
			echo "<script>
				setTimeout(function() {
					document.forms['reload'].submit();
				}, 0000);
			</script>";

	
			echo "<span style='color: green; font-size: 20px;'>Der User wurde erfolgreich hinzugefügt.</span><br><br>";
		} elseif (isset($_POST["id_update"]) && $_POST["reload"] == 1) {
			$id = $_POST['id2'];
			$status = $_POST['status'];
			$nachricht = $_POST['nachricht'];
			$agent = $_SESSION['uid'];
		  
			$sql = "UPDATE buchungen SET status=?, agent=?, kommentar=? WHERE id=?";
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "iisi", $status, $agent, $nachricht, $id);
			mysqli_stmt_execute($stmt);
			$stmt->close();
	
			echo "<style>html, body { height: 100%; margin: 0; padding: 0; cursor: wait; }</style>";
			echo "<script>
				setTimeout(function() {
					document.forms['reload'].submit();
				}, 0000);
			</script>";
			
			echo "<span style='color: green; font-size: 20px;'>Erfolgreich bearbeitet.</span><br><br>";

		}

		echo "<span style='color: white; font-size: 50px;'>$agName</span><br><br>";
        echo "<br>";
        
        echo "<span style='color: white; font-size: 25px;'>
        Die Kassenprüfer kurz vor der Kassenprüfung hier hinzufügen,<br>
        damit sie Zugriff auf Kassenprüfung.php bekommen (An PHP-Session gebunden)<br>
        und die digitale Dokumentation der Barkassen überprüfen und bestätigen können!
        </span><br><br>"; 
		echo '<div style="text-align: center;">';
		echo "<br>";
		echo('<form method="post">');
		echo('<input type="hidden" name="action" value="run">');
		 echo '<button type="submit" name="button_add" value="0" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">Neuen Kassenprüfer hinzufügen</button><br>';
		echo('</form>');
		echo('<br>');

        $sql = "SELECT uid, firstname, lastname, room, sprecher, pid FROM users WHERE CONCAT(',', groups, ',') LIKE CONCAT('%,', ?, ',%') AND (pid=11 OR pid=12 OR pid=13 OR pid=14) ORDER BY room";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            echo "Fehler beim Vorbereiten der SQL-Abfrage: " . mysqli_error($conn);
            die();
        }
        $ag_str = strval($ag);
    mysqli_stmt_bind_param($stmt, "s", $ag_str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $uid, $firstName, $lastName, $room, $sprecher, $pid);

		echo '<table class="grey-table">';	
		echo '<tr>';
		echo '<th>Name</th>';
		echo '<th>Raum</th>';            
		echo '<th>Kick</th>';
		echo '</tr>';

        while (mysqli_stmt_fetch($stmt)) {		
            $firstname = strtok($firstName, ' ');
            $lastname = strtok($lastName, ' ');
            $name = $firstname . ' ' . $lastname;
            echo '<tr>';
            echo '<td>' . htmlspecialchars($name) . '</td>';
			$roomText = '';
			if ($pid == 12) {
				$roomText = 'Subletter';
			} elseif ($pid == 13) {
				$roomText = 'Ausgezogen';
			} elseif ($pid == 14) {
				$roomText = 'Abgemeldet';
			} else {
				$roomText = htmlspecialchars($room);
			}
			
			echo '<td>' . htmlspecialchars($roomText) . '</td>';
            echo '<td>';
            echo '<form method="post">';
            echo '<input type="hidden" name="uid" value="'.$uid.'">';
            echo '<input type="hidden" name="name" value="'.$name.'">';
            echo '<button type="submit" name="button_kick" value="2" class="red-center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">Mitglied entfernen</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

		echo '</table>';
		$stmt->close();

		if (isset($_POST['button_add'])) {
			echo('<div class="overlay"></div>
			<div class="anmeldung-form-container form-container">
			  <form method="post"">
				<button type="submit" name="close" value="close" class="close-btn">X</button>
			  </form>
			  <br>
			  <form method="post">
				<label class="form-label" style="font-size:25px;">Room Number:</label>
				<br><br>
				<input type="text" name="room" class="form-input" value="">
				<br><br>
				<div class="center-container">
				  <input type="submit" name="really_add" value="Let´s go!" class="center-btn">
				</div>
			  </form>
			</div>');
		} elseif (isset($_POST['button_kick'])) {
			$name = $_POST["name"];		
			$uid = $_POST["uid"];

			echo('<div class="overlay"></div>
			<div class="anmeldung-form-container form-container">
			  <form method="post"">
				<button type="submit" name="close" value="close" class="close-btn">X</button>
			  </form>
			  <br>
			  <form method="post">
				<span style="font-size:25px; color:white;">Du bist gerade dabei '.$name.' als Kassenprüfer zu entfernen.</span>
				<br><br><br>
				<span style="font-size:25px; color:white;">Bitte bestätige den Vorgang:</span>
				<input type="hidden" name="uid" class="form-input" value="'.$uid.'">
				<br><br>
				<div class="center-container">		  
				  <input type="hidden" name="reload" value=1>
				  <input type="submit" name="process_kick" value="Yeah!" class="center-btn">
				</div>
			  </form>
			</div>');
		} elseif (isset($_POST['really_add'])) {
			$room = $_POST["room"];

			$sql = "SELECT name, uid FROM users WHERE room = ?";
			$stmt = mysqli_prepare($conn, $sql);
			mysqli_stmt_bind_param($stmt, "s", $room);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_bind_result($stmt, $name, $uid);
			mysqli_stmt_fetch($stmt);
			$stmt->close();


			echo('<div class="overlay"></div>
			<div class="anmeldung-form-container form-container">
			  <form method="post"">
				<button type="submit" name="close" value="close" class="close-btn">X</button>
			  </form>
			  <br>
			  <form method="post">
				<span style="font-size:25px; color:white;">Aktuell lebt '.$name.' in '.$room.'.</span>
				<br><br>
				<span style="font-size:25px; color:white;">Ist das der User, den du hinzufügen willst?</span>
				<br><br><br>
				<span style="font-size:25px; color:white;">Bitte bestätige den Vorgang:</span>
				<input type="hidden" name="uid" class="form-input" value="'.$uid.'">
				<br><br>
				<div class="center-container">		  
				  <input type="hidden" name="reload" value=1>
				  <input type="submit" name="process_add" value="Yeah!" class="center-btn">
				</div>
			  </form>
			</div>');
		}

	}
}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();
?>
</body>
</html>