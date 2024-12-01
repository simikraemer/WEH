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
  

  $zeit = time();
  if ($_SESSION['valid']) {

    echo '
    <div style="color: white; text-align: center; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px;">
    <h1 style="font-size: 32px; margin-bottom: 20px;">Minecraft Server</h1>
    <p style="font-size: 20px; margin-bottom: 8px;">IP: 137.226.37.174</p>
    <p style="font-size: 20px; margin-bottom: 8px;">Version: 1.19.2 Java</p>

    <p style="font-size: 20px; margin-bottom: 8px;">Das WEH hat auch einen eigenen Discord Server, dem ihr gerne beitreten könnt:</p>
    <a href="https://discord.gg/FSu72j2Q" target="_blank">https://discord.gg/FSu72j2Q</a>
    <h2 style="font-size: 24px; margin-top: 40px; margin-bottom: 16px;">Regeln</h2>
    <div style="text-align: center; margin-left: auto; margin-right: auto; max-width: 600px;">
        <ul style="list-style: none; padding: 0; margin: 0; text-align: center;">
            <li style="margin-bottom: 8px;">1. Achtet darauf den Besitz anderer Personen nicht zu beschädigen, zu stehlen oder in sonstiger Art und Weise zu zerstören. Wir loggen alles, erstellen Backups und werden dich bei Regelmissachtung bannen.</li>
            <li style="margin-bottom: 8px;">2. Für die Chatregeln verwendet bitte euren gesunden Menschenverstand: Es gibt keine vorgefertigten Regeln, aber wenn eure Nutzung des Chats extrem negative Auswirkungen auf andere Personen hat, werden wir euch bannen müssen.</li>
            <li style="margin-bottom: 8px;">3. PvP ist angeschaltet. Es ist jedoch verboten jemanden zu töten, solange vorher kein Duell auf Leben und Tod ausgemacht wurde.</li>
            <li style="margin-bottom: 8px;">4. Achtet auf Feuer! Ihr könntet ungewollt andere Gebäude oder ganze Wälder abfackeln.</li>
            <li style="margin-bottom: 8px;">5. Eure Mitspieler sind eure Mitbewohner, keine zufälligen Leute aus dem Internet. Behandelt sie bitte dementsprechend.</li>
        </ul>
    </div>
</div>


';

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