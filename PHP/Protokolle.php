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
if (auth($conn) && ($_SESSION['valid'])) {
    load_menu();

    
  if ($_SESSION["Schrift"]) {
    if(isset($_POST['really_delete_protokoll'])){
      $id = $_POST['id'];
      $delete_sql = "DELETE FROM protokolle WHERE id=?";
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
    } elseif (isset($_POST['delete_protokoll'])) {
      $id = $_POST['delete_protokoll'];
			echo('<div class="overlay"></div>
			<div class="anmeldung-form-container form-container">
			  <form method="post"">
				<button type="submit" name="close" value="close" class="close-btn">X</button>
			  </form>
			  <br>
			  <form method="post">
				<span style="font-size:25px; color:white;">Protokoll wirklich löschen?</span>
				<input type="hidden" name="id" class="form-input" value="'.$id.'">
				<br><br>
				<div class="center-container">		  
				  <input type="hidden" name="reload" value=1>
				  <input type="submit" name="really_delete_protokoll" value="Yeah!" class="center-btn">
				</div>
			  </form>
			</div>');
		}
  }


    
$typeStrings = [
    0 => "Ordentliche Vollversammlung",
    1 => "Außerordentliche Vollversammlung",
    2 => "Ordentlicher Haussenat",
    3 => "Außerordentlicher Haussenat"
];

$sql = "SELECT id, type, versammlungszeit, pfad FROM protokolle ORDER BY versammlungszeit DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $id, $type, $vzeit, $path);
?>

<style>
    .uploadproto-table {
        width: 90%;
        max-width: 900px;
        margin: 40px auto;
        border-collapse: collapse;
        background-color: #1e1e1e;
        color: white;
        border: 2px solid #11a50d;
        border-radius: 8px;
        overflow: hidden;
    }

    .uploadproto-table th,
    .uploadproto-table td {
        padding: 14px 20px;
        text-align: left;
        border-bottom: 1px solid #333;
        font-size: 16px;
    }

    .uploadproto-table th {
        background-color: #11a50d;
        color: #1e1e1e;
        font-size: 18px;
    }

    .uploadproto-table tr:hover {
        background-color: #292929;
    }

    .uploadproto-trash-button {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
    }

    .uploadproto-trash-icon {
        width: 24px;
        height: 24px;
    }
</style>

<table class="uploadproto-table">
    <tr>
        <th>Veranstaltung</th>
        <th>Datum</th>
        <?php if ($_SESSION["Schrift"]) echo '<th></th>'; ?>
    </tr>

    <?php while (mysqli_stmt_fetch($stmt)) : ?>
        <tr onclick="window.open('<?php echo $path; ?>', '_blank')" style="cursor: pointer;">
            <td><?php echo $typeStrings[$type]; ?></td>
            <td><?php echo date('d.m.Y', $vzeit); ?></td>
            <?php if ($_SESSION["Schrift"]) : ?>
                <td>
                    <form method="post" action="" onClick="event.stopPropagation();" style="margin: 0;">
                        <button type="submit" name="delete_protokoll" value="<?php echo $id; ?>" class="uploadproto-trash-button">
                            <img src="images/trash_white.png" class="uploadproto-trash-icon">
                        </button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
    <?php endwhile; ?>
</table>

<?php
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

