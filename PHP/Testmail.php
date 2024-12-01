<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eilender's Fluch</title>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <style>
        .center-form {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 50vh;
            flex-direction: column;
        }
        .button {
            background-color: #4CAF50;
            color: white;
            padding: 15px 32px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
        }
        .input-field {
            margin-bottom: 10px;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            width: 300px;
        }
    </style>
</head>
<body>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      $to = $_POST['to'];
      $message = "Sehr geehrte Damen und Herren,\n\n";
      $message .= "dies ist eine Testmail, gesendet von der Datei '/WEH/PHP/Testmail.php' auf dem Server 'odin'.\n\n";
      $message .= "Diese Mail dient dazu, die korrekte Anbindung des Webservers an das Mailsystems zu überprüfen.\n\n";
      $message .= "Mit freundlichen Grüßen,\n";
      $message .= "Netzwerk-AG WEH e.V.";

      $subject = "WEH - Testmail";
      $headers = "From: system@weh.rwth-aachen.de\r\n";
      $headers .= "Reply-To: netag@weh.rwth-aachen.de\r\n";
      if (mail($to, $subject, $message, $headers)) {
          echo "<div style='display: flex; justify-content: center; align-items: center; height: 50vh;'>
                  <span style='color: green; font-size: 20px;'>Mail erfolgreich versendet.</span>
                </div>";
      } else {
          echo "<div style='display: flex; justify-content: center; align-items: center; height: 50vh;'>
                  <span style='color: red; font-size: 20px;'>Fehler beim Versenden der Mail.</span>
                </div>";
      }
  } else {
      echo '<div class="center-form">
              <form method="post">
                  <input type="email" name="to" placeholder="Empfänger E-Mail Adresse" class="input-field" required>
                  <button type="submit" class="button">Mail senden</button>
              </form>
            </div>';
  }
}
?>

</body>
</html>