<?php
$config = json_decode(file_get_contents('/etc/credentials/config.json'), true);

$mysql_config = $config['wehphp'];
$conn = mysqli_connect(
    $mysql_config['host'],
    $mysql_config['user'],
    $mysql_config['password'],
    $mysql_config['database']
);
#mysqli_set_charset($conn,"utf8");

$mysql_wehconfig = $config['wehphp'];
$conn = mysqli_connect(
    $mysql_wehconfig['host'],
    $mysql_wehconfig['user'],
    $mysql_wehconfig['password'],
    $mysql_wehconfig['database']
);
#mysqli_set_charset($conn,"utf8");

$mysql_waschconfig = $config['mysqlphpwasch'];
$waschconn = mysqli_connect(
    $mysql_waschconfig['host'],
    $mysql_waschconfig['user'],
    $mysql_waschconfig['password'],
    $mysql_waschconfig['database']
);
#mysqli_set_charset($waschconn,"utf8");

$mailconfig = $config['mail'];

// House.php Ajax request Subnetz
if (isset($_GET['action']) && $_GET['action'] === 'getFreeSubnet') {
    echo getFreeSubnet($conn);
    // Beenden des Skripts nach der Ausgabe des Ergebnisses
    exit;
}

echo '<form action="' . $_SERVER['PHP_SELF'] . '" method="post" name="reload">';
echo '<input type="hidden" name="reload" value=0>';
echo "</form>";


######################
### AGs definieren ###
######################

$sql = "SELECT id, name, mail, session FROM groups WHERE active = TRUE ORDER BY prio;";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $id, $name, $mail, $session);

$ag_complete = array();
$ag_session = array();
while (mysqli_stmt_fetch($stmt)) {

    if (!empty($mail)) {
        $rowData_ag_complete = array(
            "name" => $name,
            "mail" => $mail,
            "session" => $session
        );
    }

    $rowData_ag_session = array(
        "name" => $name,
        "mail" => $mail,
        "session" => $session
    );

    $ag_complete[intval($id)] = $rowData_ag_complete;
    $ag_session[intval($id)] = $rowData_ag_session;
}
$stmt->close();

# Diese Übersetzungen werden auf diversen Seiten verwendet!
foreach ($ag_complete as $key => $value) {
    $ags[$key] = $value["name"];
    $ag_name2mail[$value["name"]] = $value["mail"];
    $ag_key2mail[$key] = $value["mail"];
    $ag_key2name[$key] = $value["name"];
}
foreach ($ag_session as $key => $value) {
    $ag_key2session[$key] = $value["session"];
}

$buchungroomsarray = array(
	1 => 'Wohnzimmer [0.Etage]',
	2 => 'Konferenzraum [0. Etage]',
	3 => 'Tischtennisraum [EG]',
	4 => 'Musikraum [Tiefkeller]',
	5 => 'Grill [Keller]',
	6 => 'Getränke [Bestellung]'
);

$buchungsroomgrouparray = array(
	1 => 'WohnzimmerAG',
	2 => 'WohnzimmerAG',
	3 => 'SportAG',
	4 => 'MusikAG',
	5 => 'GetränkeAG'
);

function setupUserSession($user, $isRoaming = true) {
    global $ag_key2session;

    $_SESSION['valid'] = true;
    $_SESSION['turm'] = $user['turm'];
    $_SESSION['floor'] = intval($user['room'] / 100);
    $_SESSION['uid'] = $user['uid'];
    $_SESSION['user'] = $user['uid'];
    $_SESSION['agent'] = ($user['uid'] == 2136) ? 'fiji' : $user['uid'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['sprecher'] = intval($user['sprecher']);
    $_SESSION['userroom'] = $user['room'];
    $_SESSION['firstname'] = $user['firstname'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['tuermeroam'] = $isRoaming;
    
    // AG Session settings
    foreach ($ag_key2session as $num => $agName) {
        $_SESSION[$agName] = strpos(',,'.$user['groups'].',', ','.$num.',') !== false;
    }

    // Barkassenträger
    global $conn;
    $sql = "SELECT name, wert FROM constants WHERE name IN ('kasse_netz1', 'kasse_netz2', 'kasse_wart1', 'kasse_wart2', 'kasse_tresor')";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name, $wert);
    $_SESSION['kasse'] = false;
    while (mysqli_stmt_fetch($stmt)) {
        if ($wert == $_SESSION['uid']) {
            $_SESSION['kasse'] = true;
            break;
        }
    }
    mysqli_stmt_close($stmt);
    
    // Set expiration time
    $_SESSION['expired'] = time() + ($isRoaming ? 600 : 3600); // 10 minutes for roaming, 60 minutes for non-roaming
}

// In der auth-Funktion:
function auth($conn) {
    session_regenerate_id();
    global $ag_key2session;
    global $buchungsroomgrouparray;
    global $buchungsroomarray;

    if (isset($_SESSION['valid']) && $_SESSION['valid'] && isset($_SESSION['ip']) && $_SESSION['ip'] == $_SERVER['REMOTE_ADDR'] && isset($_SESSION['expired']) && $_SESSION['expired'] > time()) {
        echo "<title>WEH Backend</title>";
        return true;
    } else {
        echo "<title>WEH Backend</title>";
        $ip = $_SERVER['REMOTE_ADDR'];

        $subnet = getNATSubnet($conn, $ip);
        $sql = "SELECT users.uid, users.username, users.groups, users.sprecher, users.firstname, users.room, users.name, users.email, users.turm
        FROM users INNER JOIN macauth
        ON macauth.uid = users.uid 
        WHERE (macauth.ip = ? and macauth.sublet = 0) OR users.subnet = ? LIMIT 1;";
        $stmt = mysqli_prepare($conn, $sql);
        $ip = trim($ip);
        $subnet = trim($subnet);
        mysqli_stmt_bind_param($stmt, "ss", $ip, $subnet);
        mysqli_stmt_execute($stmt);
        $result = get_result($stmt);
        $stmt->close();
    
        if($user = array_shift($result)) {
            if (strpos(',,'.$user['groups'].',', ',1,') != "") {
                setupUserSession($user);
                return true;
            } else {
                $_SESSION['valid'] = false; 
            }
        }
    }

    return false;
}

// In der auth_from_outside-Funktion:
function auth_from_outside($conn, $uid) {
    session_regenerate_id();
    global $ag_key2session;
    echo "<title>WEH Backend</title>";

    $sql = "SELECT users.uid, users.username, users.groups, users.sprecher, users.firstname, users.room, users.name, users.turm
    FROM users
    WHERE uid = ? LIMIT 1;";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $uid);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();

    if($user = array_shift($result)) {
        if (strpos(',,'.$user['groups'].',', ',1,') != "") {
            setupUserSession($user, false);
            return true;
        } else {
            $_SESSION['valid'] = false; 
        }
    }

    return false;
}


function load_menu() {
    global $ag_key2session;
    global $conn;

    $userIP = $_SESSION['ip'];
    if (isset($_SESSION['hostname'])) {
        $hostname = $_SESSION['hostname'];
    }		
    
    $eduroamIPRanges = [
        '134.61.64.0/19',
        '134.61.104.0/21',
        '134.61.128.0/18',
        '137.226.7.0/24',
        '137.226.75.160/27',
        '137.226.79.224/27',
        '134.61.0.0/16' # VPN
    ];

    $itcIPs = '134.130.0.0/23';
    $publicIPs = '137.226.140.0/22';
    $wehIPs = '137.226.140.0/23';
    $tvkIPs = '137.226.142.0/23';
    $privateIPs = '10.2.0.0/15';
    
    $inEduroam = false;
    foreach ($eduroamIPRanges as $range) {
        if (ip_in_range($userIP, $range)) {
            $inEduroam = true;
            break;
        }
    }
    
    // if-Baum
    if ($inEduroam) {
        $rangestring = 'Eduroam [' . htmlspecialchars($userIP) . ']';
    } elseif (ip_in_range($userIP, $itcIPs)) {
        $ipToHostname = array(
            '134.130.1.56' => 'ITC20103'
        );

        if (array_key_exists($userIP, $ipToHostname)) {
            $rangestring = 'IT Center [' . $ipToHostname[$userIP] . ']';
        } else {
            $rangestring = 'IT Center [' . htmlspecialchars($userIP) . ']';
        }
    } elseif (ip_in_range($userIP, $publicIPs) || ip_in_range($userIP, $privateIPs)) {
        if (!empty($hostname) && $hostname !== '0' && $hostname !== '') {
            $stringinklammern = $hostname;
        } else {
            $stringinklammern = htmlspecialchars($userIP);
        }
        if (ip_in_range($userIP, $wehIPs)) {
            $rangestring = 'WEH [' . $stringinklammern . ']';
        } elseif (ip_in_range($userIP, $tvkIPs)) { // Prüfung auf TvK-Bereich
            $rangestring = 'TvK [' . $stringinklammern . ']';
        }
    } else {
        $rangestring = 'Unknown [' . htmlspecialchars($userIP) . ']';
    }

    echo "<br>";
    echo '<div style="text-align: center;">
            <span style="color:white;">Welcome</span>
            <span style="color:#11a50d;">' . trim(htmlspecialchars($_SESSION['firstname'])) . '</span>
            <span style="color:white;"> - you are connecting from</span>
            <span style="color:#11a50d;">' . $rangestring;
        
    if (!$_SESSION["tuermeroam"]) {
        echo '<span id="countdown" style="margin-left: 10px; color: yellow;"></span>';
        echo ' <span style="margin-left: 10px;">
                  <form style="display: inline;" action="denied.php" method="post">
                      <button class="center-button" type="submit" name="logout" id="logout">Logout</button>
                  </form>
              </span>';
    }
    echo '</span>
        </div>';
    echo "<br>";
    
    echo '<script>
    var expiredTime = ' . $_SESSION['expired'] . ';
    var countdownElement = document.getElementById("countdown");

    function updateCountdown() {
        var currentTime = Math.floor(Date.now() / 1000);
        var remainingTime = expiredTime - currentTime;

        if (remainingTime > 0) {
            var minutes = Math.floor(remainingTime / 60);
            var seconds = remainingTime % 60;
            countdownElement.textContent = " Login expires in: " + minutes + "m " + seconds + "s ";
        } else {
            countdownElement.textContent = " Login expired ";
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
    </script>';


    echo '<div style="text-align: center;">';
    if ($_SESSION['valid']) {
        if ($_SESSION['NetzAG']) {
            $sql = "SELECT cn, endtime FROM certs WHERE alert = 2 ORDER BY endtime";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $cn, $endtime);
    
            while (mysqli_stmt_fetch($stmt)) {
                $formatted_endtime = date("d.m.Y", $endtime); // Unix-Zeitstempel in deutsches Datumformat konvertieren
                echo '<div style="color: red; font-weight: bold; margin-bottom: 10px;">';
                echo "Warnung! $cn läuft am $formatted_endtime ab!";
                echo '</div>';
            }
    
            mysqli_stmt_close($stmt);
        }

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Home</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'start.php\';">Start</button> ';
        echo '<button onclick="window.location.href=\'BA-Voting.php\';"style="white-space: nowrap;">Belegungsvote</button> ';
        echo '<button onclick="window.location.href=\'Protokolle.php\';">Protokolle</button> ';
        echo '<button onclick="window.location.href=\'Fahrrad.php\';">Fahrradstellplatz</button> ';
        echo '<button onclick="window.location.href=\'WerkzeugBuchen.php\';"style="white-space: nowrap;">Werkzeugverleih</button> ';
        #echo '<button onclick="window.location.href=\'BuyDrinks.php\';">Getränkekauf</button> ';
        // Ich habe das hier erstmal ausgehashed, bis Jonatan wieder daran arbeitet. Wenn er weitermacht wird er das hier wahrscheinlich finden :-)
        //echo '<button onclick="window.location.href=\'Sublet.php\';">Subletting</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';     

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Kontakt</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'Rundmail.php\';">Rundmail</button> ';
        echo '<button onclick="window.location.href=\'AGs.php\';">AGs</button> ';
        echo '<button onclick="window.location.href=\'Etagensprecher.php\';">Etagensprecher</button> ';
        echo '<button onclick="window.location.href=\'Individuen.php\';"style="white-space: nowrap;">Übersicht Bewohner</button> ';
        echo '<button onclick="window.location.href=\'Alumni.php\';"style="white-space: nowrap;">Alumni-Newsletter</button>'; 
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Netz</button>';
        echo '<div class="header-submenu">'; 
        echo '<button onclick="window.location.href=\'IPverwaltung.php\';" style="white-space: nowrap;">IP-Verwaltung</button>'; 
        echo '<button onclick="window.location.href=\'Troubleshoot.php\';"style="white-space: nowrap;">Troubleshoot</button> ';
        echo '<button onclick="window.location.href=\'PSK.php\';"style="white-space: nowrap;">PSK Netz</button> ';
        echo '<button onclick="window.location.href=\'Minecraft.php\';"style="white-space: nowrap;">Minecraft Server</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Account</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'UserKonto.php\';">Mitgliedskonto</button>';       
        echo '<button onclick="window.location.href=\'Mail.php\';">E-Mail Settings</button> ';
        echo '<button onclick="window.location.href=\'PWchange.php\';">Passwort ändern</button> ';
        echo '<button onclick="window.location.href=\'Abmeldung.php\';">Abmeldung</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        #if ($_SESSION["Etage05"] && $_SESSION["uid"] == 2136) {
        #    echo '<span class="vertical-line"></span>'; 
        #    echo '<div class="header-menu">';
        #    echo '<button class="center-btn" onclick="window.location.href=\'Etage05.php\';">5. Etage</button>'; 
        #    echo '</div>';
        #}
        
        if ($_SESSION['Webmaster']) {
            echo '<span class="vertical-line"></span>';    
            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Webmaster</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'Constants.php\';" style="white-space: nowrap;">Konstanten</button> ';
            echo '<button onclick="window.location.href=\'SetSessions.php\';" style="white-space: nowrap;">Sessions verwalten</button> ';
            echo '<button onclick="window.location.href=\'EilendersFluch.php\';" style="white-space: nowrap;">Eilender\'s Fluch</button>';
            echo '<button onclick="window.location.href=\'Notaus.php\';" style="white-space: nowrap;">Notaus</button>';
            echo '<button onclick="window.location.href=\'WaschmarkenExchange.php\';" style="white-space: nowrap;">Waschmarken Exchange</button> ';
            echo '<button onclick="window.location.href=\'loki.php\';" style="white-space: nowrap;">Loki View</button> ';
            echo '<button onclick="window.location.href=\'Testmail.php\';" style="white-space: nowrap;">Testmail</button>';
            echo '<button onclick="window.location.href=\'MACtranslate.php\';" style="white-space: nowrap;">MAC Übersetzung</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        
        $_SESSION["aktiv"] = false;

        # 61 kassenwart, 62 schriftführer, 63 vorsitz, 66 dsb, 26 kassenprüfer, 24 hausmeister, 19 etagensprecher
        $excludedAGs = [61, 62, 63, 66, 26, 24, 19];
        
        foreach ($ag_key2session as $num => $agName) {
            if ($_SESSION[$agName]) {
                // Wenn die AG-Nummer nicht in der Liste der ausgeschlossenen Nummern ist, setze $_SESSION["aktiv"] auf true
                if (!in_array($num, $excludedAGs)) {
                    $_SESSION["aktiv"] = true;
                }
            }
        }

        if ($_SESSION['Kassenpruefer']) {
            echo '<span class="vertical-line"></span>';                
            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Kassenprüfung</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'Kassenprüfung.php\';" style="white-space: nowrap;">Barkassen validieren</button> ';
            echo '<button onclick="window.location.href=\'AG-Essen.php\';" style="white-space: nowrap;">AG-Essen Übersicht</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        if ($_SESSION['Hausmeister']) {
            echo '<span class="vertical-line"></span>';    
            echo '<div class="header-menu">';
            echo '<button class="center-btn" onclick="window.location.href=\'Schluessel.php\';">Schlüsselverwaltung</button>'; 
            echo '</div>';
        }

        if ($_SESSION["aktiv"]) {            
            echo '<span class="vertical-line"></span>';                
            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Aktiv</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'AGedit.php\';" style="white-space: nowrap;">AG-Mitgliedschaft</button> ';
            echo '<button onclick="window.location.href=\'AG-Essen-Form.php\';" style="white-space: nowrap;">AG-Essen</button> ';
            echo '<button onclick="window.location.href=\'LokiManagement.php\';" style="white-space: nowrap;">Infoterminal</button> ';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        $firstag = true;

        foreach ($ag_key2session as $num => $agName) {
            if ($_SESSION[$agName]) {
                if ($num != 7 && $num != 9 && $num != 12 && $num != 61 && $num != 11 && $num != 25 && $num != 69 && $num != 13 && $num != 53) {
                    continue;
                }                
                if ($firstag === true) {
                    echo '<span class="vertical-line"></span>'; 
                    $firstag = false;
                }
                echo '<div class="header-menu">';
                echo '<div class="header-menu-item">';
                echo '<button class="center-btn">' . $agName . '</button>';
                echo '<div class="header-submenu">';
                #echo '<button onclick="window.location.href=\''.$agName.'.php\';" style="white-space: nowrap;">'.$agName.'</button>';
                if ($agName === 'NetzAG') {
                    echo '<button onclick="window.location.href=\'Anmeldung.php\';">Anmeldung</button> ';
                    echo '<button onclick="window.location.href=\'House.php\';">Haus</button> ';
                    if ($_SESSION['kasse']) {
                        echo '<button onclick="window.location.href=\'Kasse.php\';">Barkasse</button> ';
                    }
                    echo '<button onclick="window.location.href=\'Lastradius.php\';" style="white-space: nowrap;">Last Radius</button> ';
                    echo '<button onclick="window.location.href=\'Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'APs.php\';">APs</button> ';
                    echo '<button onclick="window.location.href=\'Certs.php\';">SSL-Zertifikate</button> ';
                    echo '<button onclick="window.location.href=\'Switches.php\';">Switches</button> ';
                    echo '<button onclick="window.location.href=\'mailblacklist.php\';" style="white-space: nowrap;">E-Mail Blacklist</button> ';
                    echo '<button onclick="window.location.href=\'IPs.php\';" style="white-space: nowrap;">IP Übersicht</button> ';
                }
                if ($agName === 'Vorstand') {                                        
                    echo '<button onclick="window.location.href=\'House.php\';">Haus</button> ';
                    if ($_SESSION['Schrift']) {
                        echo '<button onclick="window.location.href=\'ProtokollUpload.php\';" style="white-space: nowrap;">Protokoll hochladen</button> ';
                    }
                    echo '<button onclick="window.location.href=\'Kassenwart.php\';" style="white-space: nowrap;">Konto Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'Schluessel.php\';" style="white-space: nowrap;">Schlüssel</button>';
                    echo '<button onclick="window.location.href=\'EditEtagensprecher.php\';" style="white-space: nowrap;">Etagensprecher bearbeiten</button> ';
                    echo '<button onclick="window.location.href=\'Waschmarken.php\';" style="white-space: nowrap;">Waschmarken verteilen</button> ';
                }
                if ($agName === 'TvK-Sprecher') {                                        
                    echo '<button onclick="window.location.href=\'House.php\';">Haus</button> ';
                    echo '<button onclick="window.location.href=\'Kassenwart.php\';" style="white-space: nowrap;">Konto Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'EditEtagensprecher.php\';" style="white-space: nowrap;">Etagensprecher bearbeiten</button> ';
                }
                if ($agName === 'Kassenwart') {
                    if ($_SESSION['kasse']) {
                        echo '<button onclick="window.location.href=\'Kassenwart.php\';">Konten-Übersicht</button> ';
                        echo '<button onclick="window.location.href=\'Kasse.php\';">Barkasse</button> ';
                    }
                    echo '<button onclick="window.location.href=\'Kassenprüfung.php\';">Kassenprüfung</button> ';
                    echo '<button onclick="window.location.href=\'AG-Essen.php\';" style="white-space: nowrap;">AG-Essen Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'Kassenprüfer.php\';">Kassenprüfer</button> ';
                }
                if ($agName === 'WEH-BA') {                                        
                    echo '<button onclick="window.location.href=\'BA-Administration.php\';" style="white-space: nowrap;">Belegung</button> ';
                    echo '<button onclick="window.location.href=\'BA-Voting.php\';" style="white-space: nowrap;">Neueinzieher Voting</button> ';
                }
                if ($agName === 'WEH-WaschAG') {
                    echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/waschen\';">Waschsystem</button> ';
                    echo '<button onclick="window.location.href=\'Waschmarken.php\';" style="white-space: nowrap;">Waschmarken erstatten</button> ';
                }
                if ($agName === 'WerkzeugAG') {
                    echo '<button onclick="window.location.href=\'WerkzeugAdministration.php\';">Buchungen</button> ';
                    echo '<button onclick="window.location.href=\'WerkzeugBuchen.php\';">Buchen</button> ';
                }
                if ($agName === 'FahrradAG') {
                    echo '<button onclick="window.location.href=\'FahrradAdministration.php\';">Stellplatz Administration</button> ';
                    echo '<button onclick="window.location.href=\'Fahrradmail.php\';">Nachricht an Stellplatzuser</button> ';
                    echo '<button onclick="window.location.href=\'Fahrrad.php\';">Fahrradstellplatz</button> ';
                }
                if ($agName === 'PartyAG') {
                    echo '<button onclick="window.location.href=\'AlumniListe.php\';" style="white-space: nowrap;">Alumni Liste</button> ';
                    #echo '<button onclick="window.location.href=\'PartyNEP.php\';" style="white-space: nowrap;">NEP Orgateam</button> ';
                    #echo '<button onclick="window.location.href=\'PartyWEHnachten.php\';" style="white-space: nowrap;">WEHnachten Orgateam</button> ';
                    echo '<button onclick="window.location.href=\'NEPmail.php\';">NEP-Mail</button> ';
                }
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
        }
        
        echo '<span class="vertical-line"></span>';    
        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Links</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'https://www2.weh.rwth-aachen.de\';" style="white-space: nowrap;">Frontend</button> ';
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/buchen\';">Buchungssystem</button> ';
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/waschen\';">Waschsystem</button> ';
        echo '<button onclick="window.location.href=\'https://www2.weh.rwth-aachen.de/printer.php\';">Web-Printer</button> ';
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/webmail\';">Web-Mail</button> ';
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/cloud\';">Web-Cloud</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';

    }



    echo '</div>';
    echo '<br><br>';
}

function get_result( $Statement ) {
    $RESULT = array();
    $Statement->store_result();
    for ( $i = 0; $i < $Statement->num_rows; $i++ ) {
        $Metadata = $Statement->result_metadata();
        $PARAMS = array();
        while ( $Field = $Metadata->fetch_field() ) {
            $PARAMS[] = &$RESULT[ $i ][ $Field->name ];
        }
        call_user_func_array( array( $Statement, 'bind_result' ), $PARAMS );
        $Statement->fetch();
    }
    return $RESULT;
}

  function getNATSubnet($conn, $ip) {
    $sql = "SELECT subnet FROM natmapping WHERE ip = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
  
    if ($data = array_shift($result)) {
      return $data['subnet'];
    } else {
      return 'No NAT found';
    }
}

function checkUsername($conn, $username) {
    $sql = "SELECT username FROM users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
  
    if ($data = array_shift($result)) {
      return '<p style="color:red">USED</p>';
    } else {
      return '<p style="color:green">FREE</p>';
    }
  }
  
  
  function isUsed($conn, $username) {
    $sql = "SELECT username FROM users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
  
    if ($data = array_shift($result)) {
      return True;
    } else {
      return False;
    }
  }

  function getCurrent($conn, $room) {
    $sql = "SELECT username FROM users WHERE room = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $room);
    mysqli_stmt_execute($stmt);
    $result = get_result($stmt);
    $stmt->close();
  
    if ($data = array_shift($result)) {
      return htmlspecialchars($data['username']);
    } else {
      return "ROOM EMPTY";
    }
  }

  function convert($res) {
    $array = array();
    $i=0;
    $row = mysqli_fetch_assoc($res);
    while ($row != NULL) {
      $array[$i]=$row;
      $i++;
      $row = mysqli_fetch_assoc($res);
    }
    return $array;
  }

  function readconfig($wert) {
    $config = file_get_contents('/opt/local/WEH_Verwaltung/skripte/config.json');
    $config = json_decode($config, true);
    $retconfig = $config[$wert];
    return $retconfig;
}

function getAgsIcons($ags, $icon_size) {
    $ags_icons = '';
    $ag_icons_mapping = array(
        "2136" => "fiji",
        "7" => "netzwerk",
        "8" => "webmaster",
        "9" => "vorstand",
        "10" => "wohnzimmer",
        "11" => "wasch",
        "12" => "ba",
        "13" => "werkzeug",
        "23" => "sport",
        "25" => "fahrrad",
        "32" => "musik",
        "69" => "party",
        "66" => "datenschutz",
        "19" => "etagensprecher",
        "24" => "hausmeister"
    );

    // Zerlege den $ags-String anhand von Kommas in ein Array.
    $ags_array = explode(',', $ags);

    foreach ($ag_icons_mapping as $ag => $icon) {
        // Überprüfe, ob die exakte AG-Nummer im Array enthalten ist.
        if (in_array($ag, $ags_array)) {
            $ags_icons .= "<img src='images/ags/$icon.png' width='$icon_size' height='$icon_size'>";
        }
    }

    return $ags_icons;
}


function getFreeSubnet($conn) { // wurde bei Anmeldung.php und House.php durch getRoomSubnet($conn, $room) ersetzt, da Room-Subnet-Mapping eingeführt wurde
    // Alle möglichen Subnets in ein Array speichern
    $alleSubs = [];
    for ($i = 0; $i < 256; $i++) {
        $ip = "10.2." . $i . ".0";
        $alleSubs[] = $ip;
    }
    
    for ($i = 0; $i < 33; $i++) {
        $ip = "10.3." . $i . ".0";
        $alleSubs[] = $ip;
    }
    
    // Alle belegten Subnets abfragen
    $sql = "SELECT subnet FROM users WHERE subnet <> ''";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $subnet);
    $belegteSubs = [];
    while (mysqli_stmt_fetch($stmt)) {
        $belegteSubs[] = $subnet;
    }
    mysqli_stmt_close($stmt);

    // Alle möglichen Subnets minus belegte Subnets ergibt freie Subnets
    $freieSubs = array_diff($alleSubs, $belegteSubs);

    if (empty($freieSubs)) {
        return false;
    }

    // Den ersten Eintrag der freien Subnets ausgeben
    $ersterEintrag = reset($freieSubs);
    return $ersterEintrag;
}

function getRoomSubnet($conn, $room, $turm) {
    $sql = "SELECT subnet FROM natmapping WHERE room = ? AND turm = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "is", $room, $turm);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $subnet);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $subnet;
}

function pwgen() {
    $pw_length = 10;
    $zeichen = "ACDEFGHJKLMNPQRTUVWXYabcdefghkmnpqrstuvwxyz34679";
    $password = '';
    for ($i = 0; $i < $pw_length; $i++) {
        $password .= $zeichen[mt_rand(0, strlen($zeichen) - 1)];
    }
    return $password;
}

function pwhash($pw) {
    $salt = '$1$abcdefgh$'; // Festgelegtes Salt für den Hash
    return crypt($pw, $salt);
}

function roomcheck($conn, $room, $turm) {
    $zeit = time();
    $sql = "SELECT * FROM users WHERE room = ? AND turm = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $room, $turm);
    mysqli_stmt_execute($stmt);
    $raumcheck = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt); // Statement schließen
    if ($raumcheck) {
        $datum = date("d.m.Y");
        $nachmieter_string = $datum . " Als Ausgezogen eingetragen, da Nachmieter angemeldet (roomcheck)";
        $update_sql = "UPDATE users SET pid = 13, room = 0, oldroom = ?, ausgezogen = ?, subnet = '', mailisactive = 0, historie = CONCAT_WS('\n', historie, ?) WHERE room = ? AND turm = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "sssss", $room, $zeit, $nachmieter_string, $room, $turm);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
}

function subletcheck($conn, $room, $turm, $subletterend) {
    $zeit = time();
    $sql = "SELECT * FROM users WHERE room = ? AND turm = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $room, $turm);
    mysqli_stmt_execute($stmt);
    $raumcheck = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if ($raumcheck) {
        $datum = date("d.m.Y");
        $sublet_string = $datum . " Als Untervermieter eingetragen, da Untermieter angemeldet (subletcheck)";
        $update_sql = "UPDATE users SET pid = 12, room = 0, oldroom = ?, subletterstart = ?, subnet = '', subletterend = ?, historie = CONCAT_WS('\n', historie, ?) WHERE room = ? AND turm = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ssssss", $room, $zeit, $subletterend, $sublet_string, $room, $turm);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
}

#function addPrivateIPs($conn, $uid, $subnet) {
#    $zeit = time();
#    $mac1 = '';
#    $mac2 = '';
#    $mac3 = '';
#    $hostname = '';
#  
#    for ($x = 1; $x <= 5; $x++) {
#        $ip = substr($subnet, 0, -1) . $x;
#
#        $sql = "INSERT INTO macauth SET uid = ?, tstamp = ?, ip = ?, hostname = ?, mac1 = ?, mac2 = ?, mac3 = ?";
#        $stmt = mysqli_prepare($conn, $sql);
#        mysqli_stmt_bind_param($stmt, "iisssss", $uid, $zeit, $ip, $hostname, $mac1, $mac2, $mac3);
#        mysqli_stmt_execute($stmt);
#        $stmt->close();
#    }
#}

function addPrivateIPs($conn, $uid, $subnet) {
    $zeit = time();
    $mac1 = '';
    $mac2 = '';
    $mac3 = '';
    $hostname = '';

    $selectedIPs = array();
    $select_sql = "SELECT ip FROM macauth WHERE ip LIKE ?";
    $select_stmt = mysqli_prepare($conn, $select_sql);
    $subnet_pattern = substr($subnet, 0, -1) . '%';
    mysqli_stmt_bind_param($select_stmt, "s", $subnet_pattern);
    mysqli_stmt_execute($select_stmt);
    mysqli_stmt_bind_result($select_stmt, $selected_ip);
    while (mysqli_stmt_fetch($select_stmt)) {
        $selectedIPs[] = $selected_ip;
    }
    mysqli_stmt_close($select_stmt);

    $base_ip = substr($subnet, 0, -1);  // Basis des Subnetzes ohne letzte Ziffer
    $x = 1;
    $count = 0;
    while ($count < 5) {
        $ip = $base_ip . $x;

        if (!in_array($ip, $selectedIPs)) {
            $insert_sql = "INSERT INTO macauth (uid, tstamp, ip, hostname, mac1, mac2, mac3) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "iisssss", $uid, $zeit, $ip, $hostname, $mac1, $mac2, $mac3);
            mysqli_stmt_execute($insert_stmt);
            mysqli_stmt_close($insert_stmt);

            $selectedIPs[] = $ip;
            $count++;  // Zähle nur erfolgreiche Inserts
        }
        $x++;  // Erhöhe $x, unabhängig davon, ob die IP verwendet wurde oder nicht
    }
}

  
function reloadpost(){
    echo '<div style="text-align: center;">
        <span style="color: green; font-size: 20px;">Erfolgreich durchgeführt.</span><br><br>
    </div>';

    echo '<script>
        setTimeout(function() {
            document.forms[\'reload\'].submit();
        }, 1500);
    </script>';
}

function ip_in_range($ip, $range) {
    list($subnet, $mask) = explode('/', $range);
    return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet);
}

function isValidIBAN($iban) {
    // Entferne Leerzeichen aus der IBAN
    $iban = str_replace(' ', '', $iban);

    // Prüfe, ob die IBAN aus Großbuchstaben und Zahlen besteht
    if (!preg_match('/^[A-Z0-9]+$/', $iban)) {
        return false;
    }

    // Länderspezifische IBAN-Längen
    $ibanLengths = array(
        'AL' => 28, 'AD' => 24, 'AT' => 20, 'AZ' => 28, 'BH' => 22, 'BE' => 16,
        'BA' => 20, 'BR' => 29, 'BG' => 22, 'CR' => 21, 'HR' => 21, 'CY' => 28,
        'CZ' => 24, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'FO' => 18, 'FI' => 18,
        'FR' => 27, 'GE' => 22, 'DE' => 22, 'GI' => 23, 'GR' => 27, 'GL' => 18,
        'GT' => 28, 'HU' => 28, 'IS' => 26, 'IE' => 22, 'IL' => 23, 'IT' => 27,
        'JO' => 30, 'KZ' => 20, 'KW' => 30, 'LV' => 21, 'LB' => 28, 'LI' => 21,
        'LT' => 20, 'LU' => 20, 'MK' => 19, 'MT' => 31, 'MR' => 27, 'MU' => 30,
        'MC' => 27, 'MD' => 24, 'ME' => 22, 'NL' => 18, 'NO' => 15, 'PK' => 24,
        'PS' => 29, 'PL' => 28, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'SM' => 27,
        'SA' => 24, 'RS' => 22, 'SK' => 24, 'SI' => 19, 'ES' => 24, 'SE' => 24,
        'CH' => 21, 'TN' => 24, 'TR' => 26, 'AE' => 23, 'GB' => 22, 'VG' => 24
    );

    $countryCode = substr($iban, 0, 2);

    // Prüfe die Länge der IBAN für das entsprechende Land
    if (!array_key_exists($countryCode, $ibanLengths) || strlen($iban) != $ibanLengths[$countryCode]) {
        return false;
    }

    // Verschiebe die ersten vier Zeichen an das Ende der IBAN
    $iban = substr($iban, 4) . substr($iban, 0, 4);

    // Ersetze Buchstaben durch Zahlen (A=10, B=11, ..., Z=35)
    $iban = str_replace(
        range('A', 'Z'),
        range(10, 35),
        $iban
    );

    // Berechne den Modulo 97 der IBAN
    $checkSum = intval(substr($iban, 0, 1));
    for ($i = 1; $i < strlen($iban); $i++) {
        $checkSum = intval($checkSum . substr($iban, $i, 1)) % 97;
    }

    return $checkSum === 1;
}


function unixtime2semester($tstamp) {
    $year = date('Y', $tstamp);
    $month = date('m', $tstamp);

    if ($month >= 4 && $month <= 9) {
        $semester = "SS" . substr($year, -2);
    } elseif ($month >= 1 && $month <= 3) {
        $previousYear = substr($year - 1, -2);
        $semester = "WS" . $previousYear . "/" . substr($year, -2);
    } elseif ($month >= 10 && $month <= 12) {
        $nextYear = substr($year + 1, -2);
        $semester = "WS" . substr($year, -2) . "/" . $nextYear;
    }

    return $semester;
}

function unixtime2startofsemester($tstamp) {
    $year = date('Y', $tstamp);
    $month = date('m', $tstamp);

    if ($month >= 4 && $month <= 9) {
        $start_of_semester = strtotime("01-04-$year");
    } elseif ($month >= 1 && $month <= 3) {
        $start_of_semester = strtotime("01-10-" . ($year - 1));
    } elseif ($month >= 10 && $month <= 12) {
        $start_of_semester = strtotime("01-10-$year");
    } 

    return $start_of_semester;
}

function displayRundmails($conn) {
    echo '<br><br><br><br><hr><br><br><br><br><br><br>';
    echo '<h1 style="font-size: 40px; color: white; text-align: center;">Übersicht aktuelle Rundmails</h1><br><br>';

    // Berechne den Unix-Timestamp für vor einem Monat
    $one_month_ago = time() - (30 * 24 * 60 * 60); // 30 Tage in Sekunden

    // Erstelle die SQL-Abfrage basierend auf den Bedingungen
    $sql_users = "SELECT r.id, u.name, u.turm, u.room, u.groups, r.subject, r.nachricht, r.tstamp, r.address
                  FROM rundmails r 
                  JOIN users u ON r.uid = u.uid 
                  WHERE r.tstamp >= ? AND r.address NOT LIKE '%fiji%' AND (
                    (r.address = 'essential' OR r.address = 'important')";

    // Filtere basierend auf dem Turm - aktuell auskommentiert, sodass alle alles sehen können
    // if ($_SESSION['turm'] == 'weh' || $_SESSION["Vorstand"] || $_SESSION['sprecher'] != 0 || $_SESSION["NetzAG"] || $_SESSION["Webmaster"]) {
    //     $sql_users .= " OR r.address = 'weh-essential'
    //     OR r.address = 'weh-important'
    //     OR r.address = 'weh-community'";
    // }

    // if ($_SESSION['turm'] == 'tvk' || $_SESSION["Vorstand"] || $_SESSION['sprecher'] != 0 || $_SESSION["NetzAG"] || $_SESSION["Webmaster"]) {
    //     $sql_users .= " OR r.address = 'tvk-essential'
    //     OR r.address = 'tvk-important'
    //     OR r.address = 'tvk-community'";
    // }

    // Alle User sehen alle Rundmails. Falls Änderung gewünscht, Code oben wieder einschieben und Code unten löschen
    $sql_users .= " OR r.address = 'weh-essential'
    OR r.address = 'weh-important'
    OR r.address = 'weh-community'
    OR r.address = 'tvk-essential'
    OR r.address = 'tvk-important'
    OR r.address = 'tvk-community'";


    if ($_SESSION['aktiv'] == true) {
        $sql_users .= " OR r.address LIKE '%ags%'";
    }
    $sql_users .= ") ORDER BY r.id DESC";

    // Bereite die Abfrage vor
    $stmt_users = mysqli_prepare($conn, $sql_users);

    // Binde den Parameter $one_month_ago
    mysqli_stmt_bind_param($stmt_users, 'i', $one_month_ago); // 'i' steht für einen Ganzzahl-Parameter

    mysqli_stmt_execute($stmt_users);
    mysqli_stmt_bind_result($stmt_users, $id, $name, $turm, $room, $groups, $subject, $nachricht, $tstamp, $address);


    // Generiere die Tabelle
    echo '<table class="grey-table" style="margin: 0 auto; text-align: center;">';
    echo "<tr>
            <th>Turm</th> <!-- Neue Spalte für Turm -->
            <th>Name</th>
            <th>Betreff</th>
            <th>Datum & Uhrzeit</th>
        </tr>";

    while (mysqli_stmt_fetch($stmt_users)) {
        $date_time = date('d.m.Y H:i', $tstamp);
        $ags_icons = getAgsIcons($groups, 20);
        if ($name == "Simon Krämer" || $name == "Simon Fiji Krämer") {
            $icon_size = 20;
            $icon = "fiji";
            $name = "Fiji";
            $name_out = "<span style='font-family: \"Lucida Console\", \"Consolas\", monospace; font-weight: bold;'>FIJI</span>";
        } elseif ($ags_icons != "") {
            $name_out = $name;
        } else {
            $name_out = $name;
        }

        // Bestimme den Turm-Wert
        $display_turm = 'Türme'; // Default
        if (strpos($address, 'weh') !== false) {
            $display_turm = 'WEH';
        } elseif (strpos($address, 'tvk') !== false) {
            $display_turm = 'TvK';
        } elseif (strpos($address, 'ags') !== false) {
            $display_turm = 'AGs';
        }

        $display_subject = (strlen($subject) > 80) ? substr($subject, 0, 80) . ' [...]' : $subject;

        // Farbe der Zeile basierend auf address
        $row_color = '#0b7309';
        $text_size = '28px'; // Standard Textgröße
        if (strpos($address, 'community') !== false) {
            $row_color = '#333';
            $text_size = '16px'; // Community
        } elseif (strpos($address, 'important') !== false) {
            $row_color = '#1f531e';
            $text_size = '22px'; // Important
        }

        // Hidden form for each mail
        echo "<form method='POST' style='display: none;' id='form_$id'>
        <input type='hidden' name='selected_mail' value='$id'>
        <input type='hidden' name='name' value='$name'>
        <input type='hidden' name='turm' value='$turm'>
        <input type='hidden' name='room' value='$room'>
        <input type='hidden' name='groups' value='$groups'>
        <input type='hidden' name='subject' value='$subject'>
        <input type='hidden' name='nachricht' value='".htmlspecialchars($nachricht)."'>
        <input type='hidden' name='tstamp' value='$date_time'>
        </form>";

        // Inline style für jedes td
        echo "<tr onclick='document.getElementById(\"form_$id\").submit();' style='cursor: pointer;'>";
        echo "<td style='background-color: $row_color; font-size: $text_size;'>$display_turm</td>"; // Zeige den Turm-Wert an
        echo "<td style='background-color: $row_color; font-size: $text_size;'>$name_out $ags_icons</td>";
        echo "<td style='background-color: $row_color; font-size: $text_size;'>$display_subject</td>";
        echo "<td style='background-color: $row_color; font-size: $text_size;'>$date_time</td>";
        echo "</tr>";
    }
    echo "</table>";



    // Anzeige des ausgewählten Mails
    if (isset($_POST['selected_mail'])) {
        $selected_name = htmlspecialchars($_POST['name']);
        $selected_turm = htmlspecialchars($_POST['turm']);
        $formatted_turm = ($selected_turm == 'tvk') ? 'TvK' : strtoupper($selected_turm);
        $selected_room = htmlspecialchars($_POST['room']);
        $selected_groups = htmlspecialchars($_POST['groups']);
        $selected_subject = htmlspecialchars($_POST['subject']);
        $selected_nachricht = nl2br(htmlspecialchars($_POST['nachricht']));
        $selected_tstamp = htmlspecialchars($_POST['tstamp']);

        echo ('<div class="overlay"></div>
        <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <div class="mail-details" style="color: white; text-align: center; padding: 20px;">
            <h2 style="margin-bottom: 20px;">' . $selected_subject . '</h2>
            <div style="display: flex; justify-content: space-around; margin-bottom: 20px;">
                <span>' . $selected_name . '</span>
                <span>' . $formatted_turm . '</span>
                <span>' . $selected_room . '</span>
                <span>' . $selected_tstamp . '</span>
            </div>
            <div style="margin-top: 20px; text-align: left; padding: 10px; background-color: #333; border-radius: 10px;">
                <p><strong>Message:</strong></p>
                <p>' . $selected_nachricht . '</p>
            </div>
        </div>
      </div>');
    }

    mysqli_stmt_close($stmt_users);
}

function display_ba_results($conn, $id, $anzahl_kandidaten, $formatted_turm, $room, $starttime, $endtime, $pfad) {
    // SQL-Abfrage, um die Summe der Punkte (count) für jeden Kandidaten zu ermitteln
    $sql = "SELECT kandidat, SUM(count) as total_points
            FROM bavotes 
            WHERE pollid = ? 
            GROUP BY kandidat";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Initialisiere die Daten für das Diagramm
    $votes_data = array_fill(1, $anzahl_kandidaten, 0);
    while ($row = mysqli_fetch_assoc($result)) {
        $kandidat = $row['kandidat'];
        $total_points = $row['total_points'];
        $votes_data[$kandidat] = $total_points;
    }

    // Bestimme den maximalen Wert für das Säulendiagramm
    $max_votes = max($votes_data);

    echo ('<div class="overlay"></div>
    <div class="anmeldung-form-container form-container">
        <form method="post">
            <button type="submit" name="close" value="close" class="close-btn">X</button>
        </form>
        <div style="color: white; text-align: center; padding: 20px;">
            <h2>Übersicht</h2>
            <p>Turm: ' . htmlspecialchars($formatted_turm) . '</p>
            <p>Room: ' . htmlspecialchars($room) . '</p>
            <p>Anzahl Kandidaten: ' . htmlspecialchars($anzahl_kandidaten) . '</p>
            <p>Startzeit: ' . htmlspecialchars($starttime) . '</p>
            <p>Endzeit: ' . htmlspecialchars($endtime) . '</p>

            <div style="display: flex; justify-content: center; margin-top: 40px; margin-bottom: 40px;">
                <form action="' . htmlspecialchars($pfad) . '" method="get" target="_blank">
                    <button type="submit" class="center-btn">View PDF</button>
                </form>
            </div>

            <h3>Ergebnisse</h3>
            <div style="margin-top: 20px; display: flex; flex-direction: column; align-items: left;">');

    // Zeichne das Balkendiagramm
    foreach ($votes_data as $kandidat => $total_points) {
        // Berechne die Breite in Pixel basierend auf total_points und max_votes, mit einer Mindestbreite
        $width = ($max_votes > 0) ? max(($total_points / $max_votes) * 250, 10) : 10; // 250px ist die maximale Breite, 10px ist die Mindestbreite
        echo '<div style="margin: 10px 0; display: flex; align-items: center;">
                <div style="width: 100px; text-align: right; color: white; margin-right: 10px;">Kandidat ' . $kandidat . '</div>
                <div style="background-color: #0b7309; width: ' . $width . 'px; min-width: 15px; height: 30px; display: flex; justify-content: center; align-items: center;">
                    <span style="color: white; font-size: 12px;">' . $total_points . '</span>
                </div>
              </div>';
    }


    echo '</div>'; // Ende des Balkendiagramm-Containers
    echo '</div>'; // Ende der Anmeldung-Form-Container

    echo ('
        </div>
    </div>');
}



// Funktion zur Ausführung einer vorbereiteten Abfrage und Rückgabe des Ergebnisses
function executePreparedQuery($conn, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die('Fehler beim Vorbereiten des SQL-Statements: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);

    // Ergebnis binden und zurückgeben
    $result = [];
    $meta = $stmt->result_metadata();
    while ($field = $meta->fetch_field()) {
        $result[$field->name] = null;
        $bind_result[] = &$result[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind_result);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return $result;
}
  
?>