<?php
  session_start();
?>
<!DOCTYPE html>

<html>
    <head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    </head>
    <style>
    select {
        font-size: 19px;
        width: 300px;
    }
</style>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");
if (auth($conn) && ($_SESSION["Webmaster"] || $_SESSION["Vorstand"] || $_SESSION["TvK-Sprecher"])) {
  load_menu();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'turmchoice_weh') {
                $_SESSION["ap_turm_var"] = 'weh';
            } elseif ($_POST['action'] === 'turmchoice_tvk') {
                $_SESSION["ap_turm_var"] = 'tvk';
            }
        }
    }

    $turm = isset($_SESSION["ap_turm_var"]) ? $_SESSION["ap_turm_var"] : 'weh';
    $weh_button_color = ($turm === 'weh') ? 'background-color:#18ec13;' : 'background-color:#fff;';
    $tvk_button_color = ($turm === 'tvk') ? 'background-color:#FFA500;' : 'background-color:#fff;';
    echo '<div style="display:flex; justify-content:center; align-items:center;">';
    echo '<form method="post" style="display:flex; justify-content:center; align-items:center;">';
    echo '<button type="submit" name="action" value="turmchoice_weh" class="house-button" style="font-size:50px; margin-right:10px; ' . $weh_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">WEH</button>';
    echo '<button type="submit" name="action" value="turmchoice_tvk" class="house-button" style="font-size:50px; margin-right:10px; ' . $tvk_button_color . ' color:#000; border:2px solid #000; padding:10px 20px; transition:background-color 0.2s;">TvK</button>';
    echo '</form>';
    echo '</div>';
    echo "<br><br>";

    if ($turm === "tvk") {
        $maxfloor = 15;
        $zimmerauf0 = 2;
    } elseif ($turm === "weh") {
        $maxfloor = 17;
        $zimmerauf0 = 4;
    } else {
        $maxfloor = 16; // Fallback, falls $turm einen unerwarteten Wert hat
        $zimmerauf0 = 4; // Fallback, falls $turm einen unerwarteten Wert hat
    }

 
    if (isset($_POST["speichern"])) {
        $sqlDelete = "UPDATE users SET etagensprecher = 0, groups = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', groups, ','), CONCAT(',', '19' , ','), ',')) WHERE etagensprecher = ? and turm = ?";
        $stmtDelete = mysqli_prepare($conn, $sqlDelete);
        mysqli_stmt_bind_param($stmtDelete, "is", $etagensprecher, $turm);

        $sqlUpdate = "UPDATE users SET etagensprecher = ?, groups = CONCAT(groups, ',19') WHERE uid = ?";
        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
        mysqli_stmt_bind_param($stmtUpdate, "ii", $etagensprecher, $uid);

        for ($etage = 0; $etage <= 17; $etage++) {
            $etagensprecher1Key = "etagensprecher1_" . $etage;
            $etagensprecher2Key = "etagensprecher2_" . $etage;

            if (isset($_POST[$etagensprecher1Key])) {
                $etagensprecher = $etage . "1";
                $uid = intval($_POST[$etagensprecher1Key]);
                mysqli_stmt_execute($stmtDelete);
                mysqli_stmt_execute($stmtUpdate);
            }

            if (isset($_POST[$etagensprecher2Key])) {
                $etagensprecher = $etage . "2";
                $uid = intval($_POST[$etagensprecher2Key]);
                mysqli_stmt_execute($stmtDelete);
                mysqli_stmt_execute($stmtUpdate);
            }
        }

        mysqli_stmt_close($stmtDelete);
        mysqli_stmt_close($stmtUpdate);
    }

  $sql = "SELECT room, oldroom, firstname, lastname, pid, uid, etagensprecher FROM users WHERE turm = ? AND ((pid = 11) OR (pid = 12 AND etagensprecher <> 0)) ORDER BY room";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, 's', $turm);
  mysqli_stmt_execute($stmt);
  #mysqli_set_charset($conn, "utf8");
  mysqli_stmt_bind_result($stmt, $room, $oldroom, $firstname, $lastname, $pid, $uid, $etagensprecher);
  $users = array();
  
  while (mysqli_stmt_fetch($stmt)) {
    $etage = ($etagensprecher == 0) ? intval($room / 100) : intval($etagensprecher / 10);
    $rang = substr($etagensprecher, -1);
    $firstname = strtok($firstname, ' ');
    $lastname = strtok($lastname, ' ');
    if ($room == 0) {
        $room = $oldroom;
        $name = $firstname . ' ' . $lastname . ' [Subletter ' . $room . ']';
    } else {
        $name = $firstname . ' ' . $lastname . ' [' . $room . ']';
    }
    
    $user = array(
          'uid' => $uid,
          'pid' => $pid,
          'name' => $name,
          'etage' => $etage,
          'rang' => $rang
    );
    $users[] = $user;
  }  
  mysqli_stmt_close($stmt);
  
  $etagensprecherTabelle = array();
  foreach ($users as $user) {
      $etage = $user['etage'];
      $rang = $user['rang'];
      $name = $user['name'];
      if (!isset($etagensprecherTabelle[$etage])) {
          $etagensprecherTabelle[$etage] = array();
      }
      $etagensprecherTabelle[$etage][$rang] = $name;
  }


  echo '<table class="userpage-table">';
  echo '<tr><th>Etage</th><th>1. Etagensprecher</th><th>2. Etagensprecher</th></tr>';
  
  for ($etage = 0; $etage <= $maxfloor; $etage++) {
      echo '<tr>';
      echo '<td>' . $etage . '</td>';
  
      echo '<td>';
      echo '<form method="post">';
      echo '<select name="etagensprecher1_' . $etage . '" onchange="this.form.submit();">';
      echo '<option value="0">Kein Etagensprecher</option>';
      foreach ($users as $user) {
          if ($user['etage'] == $etage) {
              $selected = ($user['rang'] == 1) ? 'selected' : '';
              echo '<option value="' . $user['uid'] . '" ' . $selected . '>' . $user['name'] . '</option>';
          }
      }
      echo '</select>';
      echo '<input type="hidden" name="speichern" value="1">';
      echo '</form>';
      echo '</td>';
  
      echo '<td>';
      echo '<form method="post">';
      echo '<select name="etagensprecher2_' . $etage . '" onchange="this.form.submit();">';
      echo '<option value="0"><font color="gray">Kein Etagensprecher</font></option>';
      foreach ($users as $user) {
          if ($user['etage'] == $etage) {
              $selected = ($user['rang'] == 2) ? 'selected' : '';
              echo '<option value="' . $user['uid'] . '" ' . $selected . '>' . $user['name'] . '</option>';
          }
      }
      echo '</select>';
      echo '<input type="hidden" name="speichern" value="1">';
      echo '</form>';
      echo '</td>';
  
      echo '</tr>';
  }
  
  echo '</table>';
  
  

}
else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();

?>
</body>
</html>

