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
if (auth($conn) && ($_SESSION['NetzAG'])) {
  load_menu();


  if(isset($_POST['edit_blacklist'])){
    $id = $_POST['edit_blacklist'];
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
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<label for="type" class="sperre_form-label">Typ:</label>';
    echo '<select name="type" id="type" class="sperre_form-select">';
    echo '<option value="0" ' . ($type == 0 ? 'selected' : '') . '>Domain</option>';
    echo '<option value="1" ' . ($type == 1 ? 'selected' : '') . '>Adresse</option>';
    echo '</select><br>';
    echo "<br>";
    echo '<label for="name" class="sperre_form-label">Mail/Domain:</label>';
    echo '<input type="text" name="name" id="name" required class="sperre_form-control" value="' . $adr . '"><br><br>';
    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="exec_edit_blacklist" value="Speichern" class="center-btn">';
    echo '</div>';
    echo '</form>';
    echo '</div>';

  } else {

    if(isset($_POST['add_blacklist'])){
      $zeit = time();
      $type = $_POST['type'];
      $name = isset($_POST['name']) ? $_POST['name'] : "";
      $agent = $_SESSION['uid'];
      $insert_sql = "INSERT INTO mail_blacklist (type, name, agent) VALUES (?,?,?)";
      $insert_var = array($type, $name, $agent);
      $stmt = mysqli_prepare($conn, $insert_sql);
      if (!$stmt) {
          die('Error: ' . mysqli_error($conn));
      }
      mysqli_stmt_bind_param($stmt, "isi", ...$insert_var);
      $execute_result = mysqli_stmt_execute($stmt);
      if (!$execute_result) {
          die('Execution failed: ' . mysqli_stmt_error($stmt));
      }
      mysqli_stmt_close($stmt);
    }

    if(isset($_POST['exec_edit_blacklist'])){
        $id = $_POST['id'];
        $type = $_POST['type'];
        $name = $_POST['name'];
        $agent = $_SESSION['uid'];
        $update_sql = "UPDATE mail_blacklist SET type=?, name=?, agent=? WHERE id=?";
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

    if(isset($_POST['delete_blacklist'])){
        $id = $_POST['delete_blacklist'];
        $delete_sql = "DELETE FROM mail_blacklist WHERE id=?";
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
    echo '<select name="type" id="type" class="sperre_form-select">';
    echo '<option value="">Bitte wählen</option>';
    echo '<option value=0>Domain</option>';
    echo '<option value=1>Addresse</option>';
    echo '</select><br>';
    echo "<br>";
    echo '<label for="name" class="sperre_form-label">Mail/Domain:</label>';
    echo '<input type="text" name="name" id="name" required class="sperre_form-control"><br><br>';
    echo '<div style="display: flex; justify-content: center;">';
    echo '<input type="submit" name="add_blacklist" value="Zur Blacklist hinzufügen" class="center-btn">';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    
    echo '<br><br><br>';

    $zeit = time();

    $sql = "SELECT m.id, m.type, m.name, u.name
    FROM mail_blacklist m LEFT JOIN users u 
    ON m.agent = u.uid";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $type, $adr, $name);

    // Assoziatives Array für Domains und Adressen
    $domains = [];
    $addresses = [];

    while (mysqli_stmt_fetch($stmt)) {
      if ($type == 0) {
          $domains[] = ['id' => $id, 'type' => 'Domain', 'name' => $adr, 'agent' => $name];
      } elseif ($type == 1) {
          $addresses[] = ['id' => $id, 'type' => 'Adresse', 'name' => $adr, 'agent' => $name];
      }
    }

    mysqli_stmt_close($stmt);

    // Funktion zur Erstellung einer Tabelle aus dem Array
    function createTableFromArray($data, $title){
    echo '<div>';
    echo '<h2 style="text-align: center;">'.$title.'</h2>';
      echo '<table class="grey-table">
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Agent</th>
        <th></th>
        <th></th>
      </tr>';

      foreach ($data as $row) {
          echo "<tr>";
          echo "<td>" . $row['id'] . "</td>";
          echo "<td>" . $row['name'] . "</td>";
          echo "<td>" . $row['agent'] . "</td>";
          echo '<td>';
          echo '<form method="post" action="">';
          echo '<button type="submit" name="edit_blacklist" value="' . $row['id'] . '" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">Edit</button>';
          echo '</form>';
          echo '</td>';
          echo '<td>';
          echo '<form method="post" action="">';
          echo '<button type="submit" name="delete_blacklist" value="' . $row['id'] . '" class="center-btn" style="margin: 0 auto; display: inline-block; font-size: 20px;">Delete</button>';
          echo '</form>';
          echo '</td>';
          echo "</tr>";
      }

      echo '</table>';
      echo '</div>';
    }

    echo '<div style="display: flex; justify-content: center;">';
    createTableFromArray($domains, 'Domains');
    createTableFromArray($addresses, 'Adressen');
    echo '</div>';


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
