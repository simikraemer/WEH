<?php
$config = json_decode(file_get_contents('/etc/credentials/config.json'), true);

$mysql_config = $config['wehphp'];
$conn = mysqli_connect(
    $mysql_config['host'],
    $mysql_config['user'],
    $mysql_config['password'],
    $mysql_config['database']
);
mysqli_set_charset($conn,"utf8");

$mysql_waschconfig = $config['mysqlphpwasch'];
$waschconn = mysqli_connect(
    $mysql_waschconfig['host'],
    $mysql_waschconfig['user'],
    $mysql_waschconfig['password'],
    $mysql_waschconfig['database']
);
#mysqli_set_charset($waschconn,"utf8");

$mailconfig = $config['mail'];

echo '<form action="' . $_SERVER['PHP_SELF'] . '" method="post" name="reload">';
echo '<input type="hidden" name="reload" value=0>';
echo "</form>";


######################
### AGs definieren ###
######################

$sql = "SELECT id, name, mail, session, agessen, link, turm, menu, vacancy FROM groups WHERE active = TRUE ORDER BY prio;";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $id, $name, $mail, $session, $agessen, $link, $turm, $menu, $vacancy);

$ag_complete = array();
while (mysqli_stmt_fetch($stmt)) {

    if (!empty($mail)) {
        $rowData_ag_complete = array(
            "name" => $name,
            "mail" => $mail,
            "session" => $session,
            "agessen" => $agessen,
            "link" => $link,
            "turm" => $turm,
            "menu" => $menu,
            "vacancy" => $vacancy
        );
    }

    $rowData_ag_session = array(
        "name" => $name,
        "mail" => $mail,
        "session" => $session
    );

    $ag_complete[intval($id)] = $rowData_ag_complete;
}
$stmt->close();

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
    global $ag_complete;

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
    foreach ($ag_complete as $num => $data) {
        $agName = $data['session'];
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
    global $ag_complete;
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
        WHERE ((macauth.ip = ? and macauth.sublet = 0) OR users.subnet = ?) AND users.pid IN (11,12,13) LIMIT 1;";
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
    global $ag_complete;
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
    global $ag_complete;
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
    
    $rangestring = 'Unknown [' . htmlspecialchars($userIP) . ']';

    // if-Baum
    if ($inEduroam) {
        $rangestring = 'Eduroam [' . htmlspecialchars($userIP) . ']';
    } elseif (ip_in_range($userIP, $itcIPs)) {
        $ipToHostname = array(
            '134.130.0.99' => 'ITC20103'
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
        echo '<button onclick="window.location.href=\'/start.php\';">Start</button> ';
        echo '<button onclick="window.location.href=\'/BA-Voting.php\';"style="white-space: nowrap;">Belegungsvote</button> ';
        echo '<button onclick="window.location.href=\'/Protokolle.php\';">Protokolle</button> ';
        echo '<button onclick="window.location.href=\'/Fahrrad.php\';">Fahrradstellplatz</button> ';
        echo '<button onclick="window.location.href=\'/FitnessAccept.php\';" style="white-space: nowrap;">Fitness Equipment</button>';
        echo '<button onclick="window.location.href=\'/WerkzeugBuchen.php\';"style="white-space: nowrap;">Werkzeugverleih</button> ';
        #echo '<button onclick="window.location.href=\'/BuyDrinks.php\';">Getränkekauf</button> ';
        // Ich habe das hier erstmal ausgehashed, bis Jonatan wieder daran arbeitet. Wenn er weitermacht wird er das hier wahrscheinlich finden :-)
        //echo '<button onclick="window.location.href=\'/Sublet.php\';">Subletting</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';     

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Kontakt</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'/Rundmail.php\';">Rundmail</button> ';
        echo '<button onclick="window.location.href=\'/AGs.php\';">AGs</button> ';
        echo '<button onclick="window.location.href=\'/Etagensprecher.php\';">Etagensprecher</button> ';
        echo '<button onclick="window.location.href=\'/Individuen.php\';"style="white-space: nowrap;">Übersicht Bewohner</button> ';
        echo '<button onclick="window.location.href=\'/MailOverview.php\';"style="white-space: nowrap;">Übersicht Mailadressen</button> ';
        echo '<button onclick="window.location.href=\'/Alumni.php\';"style="white-space: nowrap;">Alumni-Newsletter</button>'; 
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Netz</button>';
        echo '<div class="header-submenu">'; 
        echo '<button onclick="window.location.href=\'/IPverwaltung.php\';" style="white-space: nowrap;">IP-Verwaltung</button>'; 
        echo '<button onclick="window.location.href=\'/Troubleshoot.php\';"style="white-space: nowrap;">Troubleshoot</button> ';
        echo '<button onclick="window.location.href=\'/Printer.php\';">Web-Printer</button> ';
        echo '<button onclick="window.location.href=\'/PSK.php\';"style="white-space: nowrap;">PSK Netz</button> ';
        echo '<button onclick="window.location.href=\'/Minecraft.php\';"style="white-space: nowrap;">Minecraft Server</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="header-menu">';
        echo '<div class="header-menu-item">';
        echo '<button class="center-btn">Account</button>';
        echo '<div class="header-submenu">';
        echo '<button onclick="window.location.href=\'/UserKonto.php\';">Mitgliedskonto</button>';       
        echo '<button onclick="window.location.href=\'/Mail.php\';">E-Mail Settings</button> ';
        echo '<button onclick="window.location.href=\'/PWchange.php\';">Passwort ändern</button> ';
        echo '<button onclick="window.location.href=\'/Abmeldung.php\';">Abmeldung</button> ';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        #if ($_SESSION["Etage05"] && $_SESSION["uid"] == 2136) {
        #    echo '<span class="vertical-line"></span>'; 
        #    echo '<div class="header-menu">';
        #    echo '<button class="center-btn" onclick="window.location.href=\'/Etage05.php\';">5. Etage</button>'; 
        #    echo '</div>';
        #}
        
        if ($_SESSION['Webmaster']) {
            echo '<span class="vertical-line"></span>';    


            # Seiten im Aufbau ##
            // echo '<div class="header-menu">';
            // echo '<div class="header-menu-item">';
            // echo '<button class="center-btn" onclick="window.location.href=\'/Transfers.php\';" style="white-space: nowrap;">Transfers</button>';
            // echo '</div>';
            // echo '</div>';


            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Webmaster</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'/Constants.php\';" style="white-space: nowrap;">Konstanten</button> ';
            echo '<button onclick="window.location.href=\'/SetSessions.php\';" style="white-space: nowrap;">Sessions verwalten</button> ';
            #echo '<button onclick="window.location.href=\'/EilendersFluch.php\';" style="white-space: nowrap;">Eilender\'s Fluch</button>';
            echo '<button onclick="window.location.href=\'/Notaus.php\';" style="white-space: nowrap;">Notaus</button>';
            echo '<button onclick="window.location.href=\'/WaschmarkenExchange.php\';" style="white-space: nowrap;">Waschmarken Exchange</button> ';
            echo '<button onclick="window.location.href=\'/loki.php\';" style="white-space: nowrap;">Loki View</button> ';
            echo '<button onclick="window.location.href=\'/sigyn.php\';" style="white-space: nowrap;">Sigyn View</button> ';
            echo '<button onclick="window.location.href=\'/Testmail.php\';" style="white-space: nowrap;">Testmail</button>';
            echo '<button onclick="window.location.href=\'/MACtranslate.php\';" style="white-space: nowrap;">MAC Übersetzung</button>';
            echo '<button onclick="window.location.href=\'/GuesstheMess.php\';" style="white-space: nowrap;">Guess the Mess</button>';
            echo '<button onclick="window.location.href=\'/sekofinale/SekoFinale.php\';" style="white-space: nowrap;">Seko Finale</button>';
            echo '<button onclick="window.location.href=\'/sekofinale/LiveView.php\';" style="white-space: nowrap;">Seko Finale - Live View</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        
        $_SESSION["aktiv"] = false;
        foreach ($ag_complete as $num => $data) {
            $agName = $data['session'];
            if ($_SESSION[$agName]) {
                $_SESSION["aktiv"] = true;
            }
        }

        if ($_SESSION['Kassenpruefer']) {
            echo '<span class="vertical-line"></span>';                
            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Kassenprüfung</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'/Transfers.php\';" style="white-space: nowrap;">Transfers</button> ';
            echo '<button onclick="window.location.href=\'/CountCash.php\';" style="white-space: nowrap;">Bargeld zählen</button> ';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        if ($_SESSION['Hausmeister']) {
            echo '<span class="vertical-line"></span>';    
            echo '<div class="header-menu">';
            echo '<button class="center-btn" onclick="window.location.href=\'/Schluessel.php\';">Schlüsselverwaltung</button>'; 
            echo '</div>';
        }

        if ($_SESSION["aktiv"]) {            
            echo '<span class="vertical-line"></span>';                    
            echo '<div class="header-menu">';
            echo '<div class="header-menu-item">';
            echo '<button class="center-btn">Aktiv</button>';
            echo '<div class="header-submenu">';
            echo '<button onclick="window.location.href=\'/AGedit.php\';" style="white-space: nowrap;">AG-Mitgliedschaft</button> ';
            echo '<button onclick="window.location.href=\'/AG-Essen-Form.php\';" style="white-space: nowrap;">AG-Essen</button> ';

            $infoterminalbuttons = [
                'weh' => ['label' => 'WEH Infoterminal', 'link' => '/LokiManagement.php'],
                'tvk' => ['label' => 'TvK Infoterminal', 'link' => '/SigynManagement.php']
            ];
            $hasOverrideAccess = !empty($_SESSION['Webmaster']) || !empty($_SESSION['NetzAG']);
            foreach ($infoterminalbuttons as $key => $data) {
                $isActive = ($hasOverrideAccess || $_SESSION["turm"] === $key);
                $style = 'white-space: nowrap;' . ($isActive ? '' : ' color: gray; cursor: not-allowed;');
                $disabled = $isActive ? '' : 'disabled';
                $onclick = $isActive ? "onclick=\"window.location.href='{$data['link']}';\"" : '';
                
                echo "<button $onclick style=\"$style\" $disabled>{$data['label']}</button> ";
            }

            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        

        $firstag = true;

        foreach ($ag_complete as $num => $data) {
            $agName = $data['session'];
            if ($_SESSION[$agName] && $data["menu"] == 1) {    
                if ($firstag === true) {
                    echo '<span class="vertical-line"></span>'; 
                    $firstag = false;
                }
                echo '<div class="header-menu">';
                echo '<div class="header-menu-item">';
                echo '<button class="center-btn">' . $agName . '</button>';
                echo '<div class="header-submenu">';
                #echo '<button onclick="window.location.href=\'/'.$agName.'.php\';" style="white-space: nowrap;">'.$agName.'</button>';
                if ($agName === 'NetzAG') {
                    echo '<button onclick="window.location.href=\'/Dashboard.php\';">Dashboard</button> ';
                    echo '<button onclick="window.location.href=\'/House.php\';">Haus</button> ';
                    if ($_SESSION['kasse']) {
                        echo '<button onclick="window.location.href=\'/Transfers.php\';" style="white-space: nowrap;">Transfers</button> ';
                        echo '<button onclick="window.location.href=\'/CountCash.php\';" style="white-space: nowrap;">Bargeld zählen</button> ';
                    }
                    echo '<button onclick="window.location.href=\'/Lastradius.php\';" style="white-space: nowrap;">Last Radius</button> ';
                    echo '<button onclick="window.location.href=\'/Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'/APs.php\';">APs</button> ';
                    echo '<button onclick="window.location.href=\'/Certs.php\';">SSL-Zertifikate</button> ';
                    echo '<button onclick="window.location.href=\'/Switches.php\';">Switches</button> ';
                    echo '<button onclick="window.location.href=\'/mailblacklist.php\';" style="white-space: nowrap;">E-Mail Blacklist</button> ';
                    echo '<button onclick="window.location.href=\'/IPs.php\';" style="white-space: nowrap;">IP Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'/IPhistory.php\';" style="white-space: nowrap;">IP Historie</button> ';
                }
                if ($agName === 'Vorstand') {                                        
                    echo '<button onclick="window.location.href=\'/House.php\';">Haus</button> ';
                    if ($_SESSION['Schrift']) {
                        echo '<button onclick="window.location.href=\'/ProtokollUpload.php\';" style="white-space: nowrap;">Protokoll hochladen</button> ';
                    }
                    echo '<button onclick="window.location.href=\'/Kassenwart.php\';" style="white-space: nowrap;">Konto Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'/Demographie.php\';" style="white-space: nowrap;">Demographie</button>';
                    echo '<button onclick="window.location.href=\'/Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'/Schluessel.php\';" style="white-space: nowrap;">Schlüssel</button>';
                    echo '<button onclick="window.location.href=\'/EditEtagensprecher.php\';" style="white-space: nowrap;">Etagensprecher bearbeiten</button> ';
                    echo '<button onclick="window.location.href=\'/Waschmarken.php\';" style="white-space: nowrap;">Waschmarken verteilen</button> ';
                }
                if ($agName === 'TvK-Sprecher') {                                        
                    echo '<button onclick="window.location.href=\'/House.php\';">Haus</button> ';
                    echo '<button onclick="window.location.href=\'/Kassenwart.php\';" style="white-space: nowrap;">Konto Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'/Demographie.php\';" style="white-space: nowrap;">Demographie</button>';
                    echo '<button onclick="window.location.href=\'/Sperre.php\';">Sperren</button> ';
                    echo '<button onclick="window.location.href=\'/EditEtagensprecher.php\';" style="white-space: nowrap;">Etagensprecher bearbeiten</button> ';
                    echo '<button onclick="window.location.href=\'/NEPmail.php\';" style="white-space: nowrap;">NEP-Mail</button>';
                }
                if ($agName === 'Kassenwart') {
                    echo '<button onclick="window.location.href=\'/Kassenwart.php\';">Konten-Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'/Transfers.php\';" style="white-space: nowrap;">Transfers</button> ';
                    echo '<button onclick="window.location.href=\'/CountCash.php\';" style="white-space: nowrap;">Bargeld zählen</button> ';
                    echo '<button onclick="window.location.href=\'/AG-Essen.php\';" style="white-space: nowrap;">AG-Essen Übersicht</button> ';
                    echo '<button onclick="window.location.href=\'/Kassenprüfer.php\';">Kassenprüfer</button> ';
                }
                if ($agName === 'WEH-BA') {                                        
                    echo '<button onclick="window.location.href=\'/BA-Administration.php\';" style="white-space: nowrap;">Belegung</button> ';
                    echo '<button onclick="window.location.href=\'/BA-Voting.php\';" style="white-space: nowrap;">Neueinzieher Voting</button> ';
                    echo '<button onclick="window.location.href=\'/Demographie.php\';" style="white-space: nowrap;">Demographie</button>';
                }
                if ($agName === 'TvK-BA') {                                        
                    echo '<button onclick="window.location.href=\'/Demographie.php\';" style="white-space: nowrap;">Demographie</button>';
                }
                if ($agName === 'WEH-WaschAG') {
                    echo '<button onclick="window.location.href=\'/https://www.weh.rwth-aachen.de/waschen\';">Waschsystem</button> ';
                    echo '<button onclick="window.location.href=\'/Waschmarken.php\';" style="white-space: nowrap;">Waschmarken erstatten</button> ';
                }
                if ($agName === 'SportAG') {                                        
                    echo '<button onclick="window.location.href=\'/FitnessNew.php\';" style="white-space: nowrap;">Fitness-Introduction</button>';
                    echo '<button onclick="window.location.href=\'/FitnessUsers.php\';" style="white-space: nowrap;">Liste Fitness-User</button> ';
                }
                if ($agName === 'WerkzeugAG') {
                    echo '<button onclick="window.location.href=\'/WerkzeugAdministration.php\';">Buchungen</button> ';
                    echo '<button onclick="window.location.href=\'/WerkzeugBuchen.php\';">Buchen</button> ';
                }
                if ($agName === 'FahrradAG') {
                    echo '<button onclick="window.location.href=\'/FahrradAdministration.php\';">Stellplatz Administration</button> ';
                    echo '<button onclick="window.location.href=\'/Fahrradmail.php\';">Nachricht an Stellplatzuser</button> ';
                    echo '<button onclick="window.location.href=\'/Fahrrad.php\';">Fahrradstellplatz</button> ';
                }
                if ($agName === 'PartyAG') {
                    echo '<button onclick="window.location.href=\'/AlumniListe.php\';" style="white-space: nowrap;">Alumni Liste</button> ';
                    echo '<button onclick="window.location.href=\'/NEPmail.php\';">NEP-Mail</button> ';
                }
                if ($agName === 'SpieleAG') {
                    echo '<button onclick="window.location.href=\'/SpieleAGCode.php\';" style="white-space: nowrap;">Code setzen</button> ';
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
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/waschen\';" style="white-space: nowrap;">WEH-Waschsystem</button> ';
        echo '<button onclick="window.location.href=\'https://www.weh.rwth-aachen.de/waschen-tvk\';" style="white-space: nowrap;">TvK-Waschsystem</button> ';
        echo '<button onclick="window.location.href=\'Printer.php\';">Web-Printer</button> ';
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
        "24" => "hausmeister",
        "25" => "fahrrad",
        "27" => "hausmeister",
        "32" => "musik",
        "33" => "wasch",
        "51" => "ba",
        "52" => "vorstand",
        "53" => "vorstand",
        "54" => "werkzeug",
        "55" => "eimer",
        "56" => "drucker",
        "57" => "wohnzimmer",
        "58" => "kino",
        "59" => "sauna",
        "60" => "fitness",
        "66" => "datenschutz",
        "69" => "party",
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
    echo '<br><br><br><hr><br><br><br>';
    #echo '<h1 style="font-size: 40px; color: white; text-align: center;">Übersicht aktuelle Rundmails</h1><br><br>';

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
        $sql_users .= " OR r.address LIKE '%" . $_SESSION["turm"] . "-ags%' OR r.address = 'ags'";
    }
    $sql_users .= ") ORDER BY r.id DESC";

    // Bereite die Abfrage vor
    $stmt_users = mysqli_prepare($conn, $sql_users);

    // Binde den Parameter $one_month_ago
    mysqli_stmt_bind_param($stmt_users, 'i', $one_month_ago); // 'i' steht für einen Ganzzahl-Parameter

    mysqli_stmt_execute($stmt_users);
    mysqli_stmt_bind_result($stmt_users, $id, $name, $turm, $room, $groups, $subject, $nachricht, $tstamp, $address);


    // Generiere die Tabelle
    echo '<table class="grey-table" style="margin: 0 auto; margin-bottom:60px; text-align: center;">';
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
        }

        $display_subject = (strlen($subject) > 80) ? substr($subject, 0, 80) . ' [...]' : $subject;

        if (strpos($address, 'community') !== false) { # Community
            $row_color = ' #333333';
            $text_size = '16px';
        } elseif (strpos($address, 'important') !== false) { # Important
            $row_color = ' #1f531e';
            $text_size = '22px';
        } elseif (strpos($address, 'ags') !== false) {
            $row_color = ' #000099';
            $text_size = '22px';
        } else { # Essential
            $row_color = ' #0b7309';
            $text_size = '28px';
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
        <input type='hidden' name='address' value='$address'>
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
        $selected_address = htmlspecialchars($_POST['address']);

        echo ('
        <div class="overlay">
        </div>
        <div class="anmeldung-form-container form-container">
            <form method="post">
                <button type="submit" name="close" value="close" class="close-btn">X</button>
                </form>
            <div class="mail-details" style="color: white; text-align: center; padding: 20px;">
                <h2 style="margin-bottom: 10px; font-size: 40px;">' . $selected_subject . '</h2>
                <div style="display: flex; flex-direction: column; justify-content: space-around; margin-bottom: 20px;">
                    <span>' . $selected_name . '</span>
                    <span>' . $formatted_turm . ' - ' . str_pad($selected_room, 4, '0', STR_PAD_LEFT) . '</span>
                    <span>' . $selected_tstamp . '</span>
                    <span>Verteiler: ' . $selected_address . '</span>
                </div>
                <div style="margin-top: 20px; text-align: left; padding: 10px; background-color: #333; border-radius: 10px;">
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

function getCountryAndContinentCounts($conn, $turm) {    
    $countryFile = 'flag-icons/country.json';
    $countries = json_decode(file_get_contents($countryFile), true);


    $sql = "SELECT geburtsort FROM users WHERE pid IN (11) AND turm = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $turm);
    $stmt->execute();
    $result = $stmt->get_result();

    $countryCounts = [];
    $continentCounts = [];
    $totalUsers = 0;

    // Zähle alle Geburtsorte und summiere die Gesamtzahl der Bewohner
    while ($row = $result->fetch_assoc()) {
        $totalUsers++;
        $geburtsort = strtolower($row['geburtsort']);

        foreach ($countries as $country) {
            $countryName = strtolower($country['name']);
            if (strpos($geburtsort, $countryName) !== false) {
                $countryCode = $country['code'];
                $continent = $country['continent'];

                // Länder zählen
                if (!isset($countryCounts[$countryCode])) {
                    $countryCounts[$countryCode] = [
                        'name' => $country['name'],
                        'flag' => "flag-icons/" . $country['flag_4x3'],
                        'count' => 0
                    ];
                }
                $countryCounts[$countryCode]['count']++;

                // Kontinente zählen
                if (!isset($continentCounts[$continent])) {
                    $continentCounts[$continent] = [
                        'name' => ucfirst($continent),
                        'count' => 0
                    ];
                }
                $continentCounts[$continent]['count']++;

                break;
            }
        }
    }

    // Füge den Prozentanteil hinzu
    foreach ($countryCounts as &$country) {
        $country['percent'] = $totalUsers > 0 ? number_format(($country['count'] / $totalUsers) * 100, 1, ',', '') : '0,00';

        // Übersetzungen
        if ($country['name'] === "United States of America") $country['name'] = "USA";
        if ($country['name'] === "United Arab Emirates") $country['name'] = "UAE";
        if ($country['name'] === "Bosnia and Herzegovina") $country['name'] = "Bosnia";
        
    }
    
    foreach ($continentCounts as &$continent) {
        $continent['percent'] = $totalUsers > 0 ? number_format(($continent['count'] / $totalUsers) * 100, 1, ',', '') : '0,00';
        
        // Übersetzungen
        if ($continent['name'] === "Asia") $continent['name'] = "Asien";
        if ($continent['name'] === "Europe") $continent['name'] = "Europa";
        if ($continent['name'] === "Africa") $continent['name'] = "Afrika";
        if ($continent['name'] === "South America") $continent['name'] = "Südamerika";
        if ($continent['name'] === "North America") $continent['name'] = "Nordamerika";
    }

    // Sortiere die Länder nach Anzahl
    usort($countryCounts, function ($a, $b) {
        return $b['count'] - $a['count'];
    });

    // Sortiere die Kontinente nach Anzahl
    usort($continentCounts, function ($a, $b) {
        return $b['count'] - $a['count'];
    });    

    return [
        'countryCounts' => $countryCounts,
        'continentCounts' => $continentCounts
    ];
}

function displayRandomCountryWEH($conn) {
    $dataWeh = getCountryAndContinentCounts($conn, "weh");
    $countryCountsweh = $dataWeh['countryCounts'];

    $randomCountryKey = array_rand($countryCountsweh);
    $randomCountry = $countryCountsweh[$randomCountryKey];

    $name = $randomCountry['name'] ?? "unbekannt";
    $count = $randomCountry['count'] ?? 0;

    if ($count === 1) {
        $stringie = "Im WEH lebt $count Bewohner aus $name!";
    } else {
        $stringie = "Im WEH leben $count Bewohner aus $name!";
    }

    return $stringie;
}

function displayRandomCountryTVK($conn) {
    $dataTvk = getCountryAndContinentCounts($conn, "tvk");
    $countryCountstvk = $dataTvk['countryCounts'];

    $randomCountryKey = array_rand($countryCountstvk);
    $randomCountry = $countryCountstvk[$randomCountryKey];

    $name = $randomCountry['name'] ?? "unbekannt";
    $count = $randomCountry['count'] ?? 0;

    if ($count === 1) {
        $stringie = "Im TvK lebt $count Bewohner aus $name!";
    } else {
        $stringie = "Im TvK leben $count Bewohner aus $name!";
    }

    return $stringie;
}

function displayRandomContinentWEH($conn) {
    $dataWeh = getCountryAndContinentCounts($conn, "weh");
    $continentCountsweh = $dataWeh['continentCounts'];

    $randomContinentKey = array_rand($continentCountsweh);
    $randomContinent = $continentCountsweh[$randomContinentKey];

    $name = $randomContinent['name'] ?? "unbekannt";
    $count = $randomContinent['count'] ?? 0;
    $percent = $randomContinent['percent'] ?? 0;

    if ($count === 1) {
        $stringie = "Im WEH lebt $count Bewohner aus $name!";
    } else {
        $stringie = "Im WEH leben $count Bewohner aus $name!";
    }

    return $stringie;
}

function displayRandomContinentTvK($conn) {
    $dataTvK = getCountryAndContinentCounts($conn, "tvk");
    $continentCountstvk = $dataTvK['continentCounts'];

    $randomContinentKey = array_rand($continentCountstvk);
    $randomContinent = $continentCountstvk[$randomContinentKey];

    $name = $randomContinent['name'] ?? "unbekannt";
    $count = $randomContinent['count'] ?? 0;
    $percent = $randomContinent['percent'] ?? 0;

    if ($count === 1) {
        $stringie = "Im TvK lebt $count Bewohner aus $name!";
    } else {
        $stringie = "Im TvK leben $count Bewohner aus $name!";
    }

    return $stringie;
}

function displayAmountPrintedPages($conn){
    $sql = "SELECT SUM(print_pages) AS gedruckt FROM transfers WHERE tstamp >= UNIX_TIMESTAMP() - 86400";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $gedruckt = $row['gedruckt'];
    $stringie = 'Innerhalb der letzten 24 Stunden wurden ' . $gedruckt . ' Seiten gedruckt!';
    mysqli_stmt_close($stmt);

    return $stringie;
}

function displayAmountUsers($conn) {
    $sql = "SELECT COUNT(uid) as mitgliederanzahl FROM users WHERE pid IN (11, 12, 13)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $mitgliederanzahl = $row['mitgliederanzahl'];
    $stringie = 'Wir haben aktuell ' . $mitgliederanzahl . ' Vereinsmitglieder!';
    mysqli_stmt_close($stmt);

    return $stringie;
}

function displayWashingSlots($waschconn) {
    $sql = "SELECT COUNT(uid) as waschmarken FROM belegung WHERE time >= UNIX_TIMESTAMP() - 86400";
    $stmt = mysqli_prepare($waschconn, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $waschmarken = $row['waschmarken'];
    $stringie = 'Innerhalb der letzten 24 Stunden wurde ' . $waschmarken . ' mal gewaschen!';
    mysqli_stmt_close($stmt);
    return $stringie;
}


function renderUserPostButtons($conn,$uid) {   
   
    $sql = "SELECT firstname, lastname, username, turm FROM users WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $firstname, $lastname, $username, $turm);
    mysqli_stmt_fetch($stmt);

    $name = strtok($firstname, ' ') . ' ' . strtok($lastname, ' ');    
    $email = htmlspecialchars($username . "@" . $turm . ".rwth-aachen.de");
    
    echo "<div style='display: flex; justify-content: center; align-items: center; height: 100%; text-align: center;'>
            <label for='uid' style='color: white; font-size: 60px; margin:10px;'>$name</label>
          </div>";    

    echo "<div style='display: flex; justify-content: center; margin-top:0px; margin-bottom:10px;'>";

    echo "<div style='margin: 0 10px;'>";
    echo "<form method='post' action='User.php'>";
    echo "<input type='hidden' name='id' value='" . htmlspecialchars($uid) . "'>";
    echo "<img src='images/UserPostButtons/user_white.png' alt='Userpage' onmouseover=\"this.src='images/UserPostButtons/user_green.png'\" onmouseout=\"this.src='images/UserPostButtons/user_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
    echo "</form>";
    echo "</div>";
    
    if ($_SESSION["NetzAG"]) {
        echo "<div style='margin: 0 10px;'>";
        echo "<form method='post' action='IPverwaltung.php'>";
        echo "<input type='hidden' name='uid' value='" . htmlspecialchars($uid) . "'>";
        echo "<img src='images/UserPostButtons/ipverwaltung_white.png' alt='IP-Verwaltung' onmouseover=\"this.src='images/UserPostButtons/ipverwaltung_green.png'\" onmouseout=\"this.src='images/UserPostButtons/ipverwaltung_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
        echo "</form>";
        echo "</div>";
    }

    echo "<div style='margin: 0 10px;'>";
    echo "<form method='post' action='UserKonto.php'>";
    echo "<input type='hidden' name='uid' value='" . htmlspecialchars($uid) . "'>";
    echo "<img src='images/UserPostButtons/mitgliedskonto_white.png' alt='Mitgliedskonto' onmouseover=\"this.src='images/UserPostButtons/mitgliedskonto_green.png'\" onmouseout=\"this.src='images/UserPostButtons/mitgliedskonto_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
    echo "</form>";
    echo "</div>";

    if ($_SESSION["NetzAG"]) {
        echo "<div style='margin: 0 10px;'>";
        echo "<form method='post' action='Troubleshoot.php'>";
        echo "<input type='hidden' name='uid' value='" . htmlspecialchars($uid) . "'>";
        echo "<img src='images/UserPostButtons/troubleshoot_white.png' alt='Troubleshoot' onmouseover=\"this.src='images/UserPostButtons/troubleshoot_green.png'\" onmouseout=\"this.src='images/UserPostButtons/troubleshoot_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
        echo "</form>";
        echo "</div>";
    }
    
    echo "<div style='margin: 0 10px;'>";
    echo "<a href='mailto:$email'>";
    echo "<img src='images/UserPostButtons/mail_white.png' alt='E-Mail senden' onmouseover=\"this.src='images/UserPostButtons/mail_green.png'\" onmouseout=\"this.src='images/UserPostButtons/mail_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
    echo "</a>";
    echo "</div>";

    #if ($_SESSION["Webmaster"]) {
    #    echo "<div style='margin: 0 10px;'>";
    #    echo "<form method='post' action='WaschmarkenExchange.php'>";
    #    echo "<input type='hidden' name='uid' value='" . htmlspecialchars($uid) . "'>";
    #    echo "<img src='images/UserPostButtons/waschmarken_white.png' alt='Waschmarken' onmouseover=\"this.src='images/UserPostButtons/waschmarken_green.png'\" onmouseout=\"this.src='images/UserPostButtons/waschmarken_white.png'\" style='cursor: pointer;' onmousedown=\"handleMouseDown(event, this)\">";
    #    echo "</form>";
    #    echo "</div>";
    #}

    echo "</div>";

    echo "<script>
        function handleMouseDown(event, imgElement) {
            var form = imgElement.closest('form');

            // Prüfen auf Mittelklick oder Strg/Cmd + Klick
            if (event.button === 1 || event.ctrlKey || event.metaKey) {
                // Ziel in neuem Tab öffnen
                let newForm = form.cloneNode(true);
                newForm.target = '_blank';
                document.body.appendChild(newForm);
                newForm.submit();
                document.body.removeChild(newForm);
            } else if (event.button === 0) {
                // Normaler Klick, Ziel im gleichen Tab öffnen
                form.submit();
            }
        }
    </script>";

}


## Drucker

function is_pdf_encrypted($file) {
    $fp = fopen($file, 'r');
    $firstBytes = fread($fp, 1024);
    fclose($fp);
    return strpos($firstBytes, '/Encrypt') !== false;
}

function get_pdf_page_count($file) {
    $output = shell_exec("pdfinfo " . escapeshellarg($file) . " | grep Pages");
    return $output ? (int)trim(str_replace("Pages:", "", $output)) : 1;
}

// Datei speichern (mit sicherem Namen)
function sanitizeFileName($filename) {
    // Datei-Endung extrahieren
    $ext = pathinfo($filename, PATHINFO_EXTENSION); // Holt die Erweiterung (pdf, jpg, png)
    $name = pathinfo($filename, PATHINFO_FILENAME); // Holt nur den Dateinamen

    // Ersetzungen für Umlaute & Sonderzeichen
    $replacements = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        'é' => 'e', 'è' => 'e', 'à' => 'a', 'á' => 'a', 'ç' => 'c',
        ' ' => '_', '!' => '', '@' => '', '#' => '', '$' => '', '%' => '', '^' => '', '&' => '', '*' => '',
        '(' => '', ')' => '', '{' => '', '}' => '', '[' => '', ']' => '', ':' => '', ';' => '', '"' => '',
        "'" => '', '<' => '', '>' => '', ',' => '', '?' => '', '/' => '', '\\' => '', '|' => '', '+' => '',
        '=' => '', '~' => '', '`' => ''
    ];

    // Sonderzeichen durch erlaubte Zeichen ersetzen
    $name = strtr($name, $replacements);

    // Alle Zeichen, die nicht a-zA-Z0-9_ sind, durch _ ersetzen
    $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

    // Falls der Name mit einer ungültigen Zahl oder einem Zeichen beginnt, füge "file_" hinzu
    if (preg_match('/^[^a-zA-Z0-9_]/', $name)) {
        $name = 'file_' . $name;
    }

    // **Alle Unterstriche entfernen**
    $name = str_replace('_', '', $name);

    // Sicherstellen, dass die Erweiterung erhalten bleibt
    return $name . '.' . strtolower($ext);
}

function is_valid_pdf($filePath) {
    // Versuche, die Datei mit `fpdf` oder `Ghostscript` zu überprüfen
    if (!file_exists($filePath) || filesize($filePath) == 0) {
        return false; // Datei existiert nicht oder ist leer
    }

    // Öffne die Datei und prüfe, ob sie mit "%PDF-" beginnt (Standard PDF-Header)
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return false;
    }
    
    $header = fread($handle, 5);
    fclose($handle);

    if ($header !== "%PDF-") {
        return false; // Kein gültiges PDF
    }

    // Optional: Prüfe, ob Ghostscript die Datei öffnen kann (wenn auf dem Server verfügbar)
    $output = null;
    $returnVar = null;
    exec("gs -q -o /dev/null -sDEVICE=nullpage " . escapeshellarg($filePath) . " 2>&1", $output, $returnVar);

    return $returnVar === 0; // Wenn Ghostscript keinen Fehler gibt, ist das PDF gültig
}

function shortenFileName($fileName, $maxLength = 20) {
    $fileInfo = pathinfo($fileName);
    $name = $fileInfo['filename']; // Dateiname ohne Endung
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : ''; // Dateiendung

    if (strlen($name) > $maxLength) {
        $name = substr($name, 0, $maxLength) . "[...]"; // Kürzen und "..." hinzufügen
    }

    return $name . $extension; // Zusammenfügen von gekürztem Namen und Endung
}

function berechne_gesamtpreis($gesamtseiten, $druckmodus, $graustufen) {
    // Preisdefinitionen für verschiedene Modi
    $preise = [
        'sw' => [
            'simplex' => 0.02,    // SW Simplex [2 Cent pro Blatt]
            'duplex'  => 0.015,   // SW Duplex [1.5 Cent pro Blatt]
        ],
        'farbe' => [
            'simplex' => 0.08,    // Farbe Simplex [8 Cent pro Blatt]
            'duplex'  => 0.06,    // Farbe Duplex [6 Cent pro Blatt]
        ]
    ];

    // Farbmodus bestimmen
    $modus = $graustufen ? 'sw' : 'farbe';

    // Druckmodus-Preise aus Array holen
    $preis_simplex = $preise[$modus]['simplex'];
    $preis_duplex  = $preise[$modus]['duplex'];

    // Seitenberechnung je nach Druckmodus
    if ($druckmodus === "duplex") {
        $duplex_seiten = floor($gesamtseiten / 2) * 2;
        $simplex_seiten = $gesamtseiten % 2; // Falls ungerade, bleibt 1 Simplex-Seite übrig
    } else {
        $duplex_seiten = 0;
        $simplex_seiten = $gesamtseiten; // Alle Seiten als Simplex
    }

    // Gesamtpreis berechnen
    $gesamtpreis = (($duplex_seiten) * $preis_duplex) + ($simplex_seiten * $preis_simplex);

    return round($gesamtpreis, 2); // Dezimalwert für MySQL
}


function get_snmp_value($ip, $oid) {
    $value = snmpget($ip, "public", $oid);
    return preg_replace('/[^0-9]/', '', $value); // Entfernt alles außer Zahlen
}

function checkifprintjobispossible($conn, $uid, $drucker_id, $drucker, $blätter, $gesamtpreis) {
    // 🔹 **1️⃣ User-Guthaben abrufen**
    $sql = "SELECT SUM(betrag) FROM transfers WHERE uid = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $accountbalance);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_free_result($stmt);
    
    if (is_null($accountbalance)) {
        $accountbalance = 0.00;
    }

    $gesamtpreis = str_replace(',', '.', $gesamtpreis);
    $gesamtpreis = floatval($gesamtpreis);

    // 🔹 **2️⃣ Drucker-IP abrufen**
    $drucker_ip = null;
    foreach ($drucker as $d) {
        if ($d["id"] == $drucker_id) {
            $drucker_ip = $d["ip"];
            break;
        }
    }

    if (!$drucker_ip) {
        return 3; // Drucker nicht gefunden = sicherheitshalber "nicht genug Papier"
    }

    // 🔹 **3️⃣ Papierkapazität abfragen (SNMP)**
    $papier_A4_aktuell = 0;

    if ($drucker_ip === "137.226.141.5") { // SW Drucker (5x A4)
        for ($i = 2; $i <= 6; $i++) {
            $papier_A4_aktuell += get_snmp_value($drucker_ip, "1.3.6.1.2.1.43.8.2.1.10.1.$i"); 
        }
    } elseif ($drucker_ip === "137.226.141.193") { // Farb-Drucker (2x A4, 1x A3)
        $papier_A4_aktuell = get_snmp_value($drucker_ip, "1.3.6.1.2.1.43.8.2.1.10.1.2") + 
                             get_snmp_value($drucker_ip, "1.3.6.1.2.1.43.8.2.1.10.1.3") + 
                             get_snmp_value($drucker_ip, "1.3.6.1.2.1.43.8.2.1.10.1.4");
    }

    // 🔹 **4️⃣ Überprüfen, ob genug Geld auf dem Konto ist**
    if ($accountbalance < $gesamtpreis) {
        return 2; // ❌ Nicht genug Geld
    }

    // 🔹 **5️⃣ Überprüfen, ob genug Papier vorhanden ist**
    if ($papier_A4_aktuell < $blätter) { 
        return 3; // ❌ Nicht genug Papier
    }

    return 1; // ✅ Alles gut, Druck ist möglich!
}

function berechneBlaetterausSeiten($gesamtseiten, $druckmodus) {
    if ($druckmodus === "duplex") {
        return ceil($gesamtseiten / 2); // Duplex: 2 Seiten pro Blatt
    } else {
        return $gesamtseiten; // Simplex: 1 Seite pro Blatt
    }
}

function remove_emojis($text) {
    return preg_replace('/[\x{1F600}-\x{1F64F}' .  // Emoticons
                        '\x{1F300}-\x{1F5FF}' .  // Symbole & Piktogramme
                        '\x{1F680}-\x{1F6FF}' .  // Transport & Orte
                        '\x{1F1E6}-\x{1F1FF}' .  // Flaggen
                        '\x{2600}-\x{26FF}' .    // Verschiedene Symbole
                        '\x{2700}-\x{27BF}' .    // Dingbats
                        '\x{1F900}-\x{1F9FF}' .  // Weitere Emojis (z. B. 🤯🦄)
                        '\x{1FA70}-\x{1FAFF}' .  // Noch neuere Emojis (z. B. 🛝🪄)
                        '\x{200D}' .             // Zero Width Joiner (kombinierte Emojis)
                        '\x{FE0F}]+/u', '', $text); // Variation Selectors (z. B. ☑️)
}

function formatTurm(string $turm): string {
    $turm = strtolower(trim($turm));

    if ($turm === 'tvk') {
        return 'TvK';
    }

    return strtoupper($turm);
}

function cutName($firstname, $lastname) {    
    $name = explode(' ', $firstname)[0] . ' ' . explode(' ', $lastname)[0];
    return $name;
}

function displayBetrag($betrag) {
    return number_format((float)$betrag, 2, ',', '.');
}

function berechneKontostand(mysqli $conn, int $kasse_id): float {
    if ($kasse_id === 69) {
        // PayPal: Sonderberechnung wegen Gebühren
        $sql = "SELECT SUM(
            CASE
                WHEN betrag = 5 THEN 4.92
                WHEN betrag = 10 THEN 9.84
                WHEN betrag = 20 THEN 19.35
                WHEN betrag = 30 THEN 29.20
                WHEN betrag = 40 THEN 39.05
                WHEN betrag = 50 THEN 48.90
                WHEN betrag = 75 THEN 73.53
                WHEN betrag = 100 THEN 98.15
                ELSE betrag
            END
        ) FROM transfers WHERE kasse = ?";
    } else {
        // Standard: normal summieren
        $sql = "SELECT SUM(betrag) FROM transfers WHERE kasse = ?";
    }

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $kasse_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $summe);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return round($summe ?? 0, 2);
}

    

echo '<script>
function copyToClipboard(btn, text) {
    const originalText = btn.innerText;
    const originalBG = btn.style.backgroundColor;

    navigator.clipboard.writeText(text).then(function() {
        // Visual feedback
        btn.style.backgroundColor = "#11a50d";
        btn.innerText = "Kopiert!";

        setTimeout(() => {
            btn.style.backgroundColor = "transparent";
            btn.innerText = originalText;
        }, 700);
    }, function(err) {
        console.error("Copy failed", err);
    });
}
    </script>';

  
?>
