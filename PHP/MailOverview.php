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

  // Gruppenabfrage und Speicherung in einem Array
  $sql_groups = "SELECT id, aliase, name, turm FROM groups WHERE id > 1 ORDER by prio";
  $result_groups = mysqli_query($conn, $sql_groups);

  $groups = [];
  if ($result_groups) {
      while ($row = mysqli_fetch_assoc($result_groups)) {
          $groups[$row['id']] = [
              'aliase' => $row['aliase'],
              'name' => $row['name'],
              'turm' => $row['turm']
          ];
      }
  }

  // Benutzerabfrage und Verarbeitung
  $sql_users = "SELECT pid, room, firstname, lastname, groups, sprecher, uid, turm FROM users WHERE pid IN (11,12,64) ORDER BY FIELD(turm, 'weh', 'tvk'), room ASC";
  $result_users = mysqli_query($conn, $sql_users);

  $users_by_group = [];
  $etage_users = []; // Etagen-Nutzer pro Turm
  if ($result_users) {
      while ($row = mysqli_fetch_assoc($result_users)) {
          // Ermittlung der Etage aus dem Raum (room)
          $etage = intval(substr($row['room'], 0, strlen($row['room']) - 2));
          $etage = str_pad($etage, 2, "0", STR_PAD_LEFT); // Zweistellig formatieren

          // Speichere Benutzer basierend auf der Etage und dem Turm
          if ($row["pid"] == 11) {
            if (($row['turm'] === 'weh' && $etage <= 17) || ($row['turm'] === 'tvk' && $etage <= 15)) {
              // Speichere Benutzer basierend auf der Etage und dem Turm
              $fullname = strtok($row['firstname'], ' ') . ' ' . mb_substr($row['lastname'], 0, 1, 'UTF-8') . '.';
              $turm_form = ($row['turm'] === 'tvk') ? 'TvK' : strtoupper($row['turm']);
              $inklammern = ($row['room'] == 0) ? "Extern" : "{$turm_form} - {$row['room']}";
              $etage_users[$row['turm']][$etage][] = "{$fullname} [{$row['room']}]";
            }
          }

          // Zuweisung von Benutzern zu Gruppen
          $user_groups = explode(",", $row['groups']);
          foreach ($user_groups as $group_id) {
              if (isset($groups[$group_id])) {
                  $fullname = strtok($row['firstname'], ' ') . ' ' . strtok($row['lastname'], ' ');
                  $turm_form = ($row['turm'] === 'tvk') ? 'TvK' : strtoupper($row['turm']);
                  $locationstring = (strpos($groups[$group_id]['name'], "Etagensprecher") !== false) ? "{$row['room']}" : "{$turm_form} - {$row['room']}";
                  $inklammern = ($row['room'] == 0 || $row['pid'] != 11) ? "Extern" : "{$locationstring}";
                  $users_by_group[$group_id][] = "{$fullname} [{$inklammern}]";
              }
          }
      }
  }

  // Tabelle zentrieren
  echo '<div style="text-align: center; margin-bottom: 40px;">';
  #echo '<table border="1" cellspacing="0" cellpadding="5" style="margin: auto; color: white; background-color: black; box-shadow: 0px 0px 30px rgba(255, 255, 255, 0.5);">';
  echo "<table class='clear-table'>";
  echo '<tr><th>E-Mail-Adressen</th><th>Name</th><th>Benutzer</th></tr>';

  // Verarbeitung der Gruppen und Benutzer
  foreach ($groups as $id => $group) {
      // Sonderregeln für bestimmte IDs
      if ($id == 7) {
          $localparts = [            
              "net", "netag", "netz-ag", "netzag", "netz", "netzwerk-ag", "netzwerkag", "netzwerk", 
              "info", "buchungssystem", "cloud", "system", "verwaltung", "kamera", "lernraum",
          ];
      } elseif ($id == 9) {
          $localparts = ["haussprecher", "vorstand", "sprecher"];
      } elseif ($id == 13) {
          $localparts = ["wag", "werkzeuge", "werkzeugbuchung", "werkzeug"];
      } elseif ($id == 11) {
          $localparts = ["wasch", "waschen", "spuelen"];
      } else {
          $localparts = strpos($group['aliase'], ',') !== false ? explode(',', $group['aliase']) : [$group['aliase']];
      }

      // Erstelle eine Liste von E-Mail-Adressen mit mailto-Links
      $mailaddresses = array_map(function ($localpart) use ($group) {
        $email = trim($localpart) . "@{$group['turm']}.rwth-aachen.de";
        return "<a href='mailto:$email' class='white-text'>$email</a>";
      }, $localparts);

      // Verbinde die E-Mail-Adressen mit einem Zeilenumbruch
      $mailaddresses_text = implode('<br>', $mailaddresses);

      // Benutzer für diese Gruppe abrufen
      $group_users = isset($users_by_group[$id]) ? implode('<br>', $users_by_group[$id]) : 'Keine Benutzer';

      // Tabelle mit Name, E-Mail-Adressen und Benutzern
      echo "<tr>
            <td>$mailaddresses_text</td>
            <td>{$group['name']}</td>
            <td>$group_users</td>
          </tr>";

  }

  // Verarbeitung der Etagen und Benutzer
  foreach ($etage_users as $turm => $etagen) {
    foreach ($etagen as $etage => $users) {
        $mailaddress = "etage{$etage}@$turm.rwth-aachen.de";
        $name = strtoupper($turm) . " Etage $etage";
        $users_list = implode('<br>', $users);

        echo "<tr>
                <td><a href='mailto:$mailaddress' class='white-text'>$mailaddress</a></td>
                <td>$name</td>
                <td>$users_list</td>
              </tr>";
    }
  }

  echo '</table>';
  echo '</div>';
}






else {
  header("Location: denied.php");
}

// Close the connection to the database
$conn->close();

?>
</body>
</html>

