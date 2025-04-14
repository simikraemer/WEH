# Geschrieben von Fiji
# Juni 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import datetime
import random
from fcol import send_mail
from fcol import connect_weh

def clean_user(ends):
    print("[INFO] Cleanup gestartet...")
    
    sendto = "netag@weh.rwth-aachen.de"
    subject = "User Cleanup"
    
    text = ""
    zeit = time.time()
    datum_unform = datetime.datetime.now()
    datum = datum_unform.strftime("%d.%m.%Y")
    changes = False

    text += "Es haben sich folgende Änderungen ergeben:\n\n"

    cursor = conn.cursor()   
     
    # Fall 1: Abgemeldet (room=0, endtime gesetzt)
    sql = "SELECT name, uid FROM users WHERE pid=11 AND room=0 AND endtime<UNIX_TIMESTAMP() + 86400 AND endtime>0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurde abgemeldet, da der User keinem Raum mehr zugewiesen war und seine Endtime erreicht wurde.\n"
        
        update_sql = "UPDATE users SET pid=14, groups=1, subnet='', historie=CONCAT(historie, CONCAT(0x0A, %s, ' Abgemeldet (System)')) WHERE uid = %s"
        update_var = (datum, uid)
        cursor.execute(update_sql, update_var)
        
        delete_sql = "DELETE FROM macauth WHERE uid = %s"
        delete_var = (uid,)
        cursor.execute(delete_sql, delete_var)

        changes = True   
        print(f"[✓] {name} abgemeldet – kein Raum & Endzeit erreicht")
          
    # Fall 2: Abgemeldet
    sql = "SELECT name, uid, room FROM users WHERE pid=11 AND room>0 AND endtime<UNIX_TIMESTAMP() - 86400 AND endtime>0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()

    for row in neueuser:
        name = row[0]
        uid = row[1]
        room = row[2]

        text += "* " + name + " wurde abgemeldet, da die Endtime des Users erreicht wurde.\n"

        update_sql = "UPDATE users SET pid=14, groups=1, subnet='', oldroom=%s, room=0, historie=CONCAT(historie, CONCAT(0x0A, %s, ' Abgemeldet (System)')) WHERE uid=%s"
        update_var = (room, datum, uid)
        cursor.execute(update_sql, update_var)
        
        delete_sql = "DELETE FROM macauth WHERE uid = %s"
        delete_var = (uid,)
        cursor.execute(delete_sql, delete_var)
        
        changes = True
        print(f"[✓] {name} abgemeldet – Endzeit erreicht, Raum war: {room}")
     
    # Fall 3: Ausgezogen (room=0, endtime=0)
    sql = "SELECT name, uid FROM users WHERE pid=11 AND room=0 AND (endtime=0 OR endtime IS NULL)"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " ist ausgezogen.\n"
        
        update_sql = "UPDATE users SET pid=13, groups=1, ausgezogen=%s, subnet='', historie=CONCAT(historie, CONCAT(0x0A, %s, ' Ausgezogen (System)')) WHERE uid = %s"
        update_var = (zeit, datum, uid)
        cursor.execute(update_sql, update_var)
        
        delete_sql = "DELETE FROM macauth WHERE uid = %s"
        delete_var = (uid,)
        cursor.execute(delete_sql, delete_var)
        
        changes = True
        print(f"[✓] {name} ist ausgezogen")
        
    # Fall 4: Remove IPs
    sql = "SELECT users.name, users.uid FROM users WHERE users.pid in (13,14) AND (users.subnet > 0 OR users.uid IN (SELECT macauth.uid FROM macauth))"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurden Subnetz und IPs entfernt.\n"
        
        update_sql = "UPDATE users SET groups=1, subnet='' WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        delete_sql = "DELETE FROM macauth WHERE uid = %s"
        delete_var = (uid,)
        cursor.execute(delete_sql, delete_var)
        
        changes = True
        print(f"[✓] {name} – Subnetz & IPs entfernt")
        
    # Fall 5: Subletter IPs
    sql = "SELECT users.name, users.uid FROM users WHERE users.pid = 12 AND EXISTS (SELECT 1 FROM macauth WHERE macauth.uid = users.uid AND macauth.sublet = 0)"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " ist ein Subletter und seine IPs wurden eingefroren.\n"
        
        update_sql = "UPDATE macauth SET sublet=1 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True    
        print(f"[✓] {name} – IPs eingefroren (Subletter)")
        
    # Fall 6: Sublet IPs
    sql = "SELECT users.name, users.uid FROM users WHERE users.pid = 11 AND EXISTS (SELECT 1 FROM macauth WHERE macauth.uid = users.uid AND macauth.sublet = 1)"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " ist kein Subletter mehr und seine IPs wurden aufgetaut.\n"
        
        update_sql = "UPDATE macauth SET sublet=0 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – IPs wieder freigegeben (kein Subletter mehr)")
        
    # Fall 7: User mindestens 5 IPs
    select_sql = "SELECT wert FROM constants WHERE name = 'standard_ips'"
    cursor.execute(select_sql)
    result = cursor.fetchone()
    if result is not None:
        standard_ips = int(result[0])
    else:
        # Standardwert, falls der Eintrag nicht gefunden wird
        standard_ips = 5    
        
    sql = """
    SELECT users.name, users.uid, users.subnet, (%s - COUNT(*)) AS num_ips_to_add
    FROM weh.users
    LEFT JOIN weh.macauth ON users.uid = macauth.uid
    WHERE users.pid = 11 AND users.subnet != ""
    GROUP BY users.uid
    HAVING COUNT(*) < %s
    """
    var = (standard_ips, standard_ips)
    cursor.execute(sql, var)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        subnet_cut = row[2][:-1]
        num_ips_to_add = row[3]
        text += "* " + str(name) + " hatte weniger als " + str(standard_ips) + " IPs. Hinzugefügt wurden die IPs:\n"

        select_sql = "SELECT ip FROM macauth WHERE uid = %s"
        select_var = (uid,)
        cursor.execute(select_sql, select_var)
        occupied_ips = [ip[0] for ip in cursor.fetchall()] 
        
        for _ in range(num_ips_to_add):
            available_ips = []
            for i in range(1, 256):
                available_ips.append(subnet_cut + str(i))

            for ip in available_ips:
                if ip not in occupied_ips:
                    free_ip = ip
                    break
                    
            zeit = int(time.time())
            insert_sql = "INSERT INTO macauth (uid, tstamp, ip) VALUES (%s, %s, %s)"
            insert_var = (uid, zeit, free_ip)
            cursor.execute(insert_sql, insert_var)
            
            text += "  " + free_ip + "\n"
            occupied_ips.append(free_ip)

        
        #text += "\nOccupied IPs: {}\n".format(occupied_ips)
        #text += "\nAvailable IPs: {}\n".format(available_ips)
        
        text += "\n"
        changes = True
        print(f"[✓] {name} – zusätzliche IPs generiert")
        
    # Fall 8: Abgemeldeter hat noch aktiven Mailaccount
    sql = "SELECT users.name, abmeldungen.uid FROM weh.abmeldungen JOIN weh.users ON users.uid = abmeldungen.uid WHERE users.pid = 14 AND abmeldungen.keepemail = 0 AND users.mailisactive = 1"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurde nach Auszug und Abmeldung das Postfach entfernt.\n"
        
        update_sql = "UPDATE users SET mailisactive=0 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Postfach deaktiviert nach Abmeldung")
        
    # Fall 9: Bewohner hat Mail deaktiviert
    sql = "SELECT users.name, users.uid FROM users WHERE users.pid IN (11,12) AND users.mailisactive = 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + "'s Mailaccount wurde reaktiviert.\n"
        
        update_sql = "UPDATE users SET mailisactive = 1 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Mailaccount reaktiviert")
        
    # Fall 10: Bewohner oder Subletter haben einen Wert in ausgezogen
    sql = "SELECT name, uid FROM weh.users WHERE (ausgezogen > 0) AND pid IN (11,12)"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " hatte fälschlicherweise einen Wert bei 'ausgezogen', der entfernt wurde.\n"
        
        update_sql = "UPDATE users SET ausgezogen=0 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Feld 'ausgezogen' wurde entfernt")
        
    # Fall 11: Raum falsch
    sql = "SELECT name, uid, room FROM users WHERE pid IN (13,14) AND room>0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        room = row[2]
        text += "* " + name + " war noch für Raum " + str(room) + " eingetragen und wurde entfernt.\n"
        
        update_sql = "UPDATE users SET oldroom = %s, room = 0 WHERE uid = %s"
        update_var = (room,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Raum {room} wurde entfernt")
        
    # Fall 12: Abgemeldet, aber nicht ausgezogen
    sql = "SELECT name, uid, endtime FROM users WHERE pid IN (14) AND endtime > 0 AND ausgezogen = 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        endtime = row[2]
        text += "* " + name + " wurde das Auszugsdatum nachgetragen.\n"
        
        update_sql = "UPDATE users SET ausgezogen = %s WHERE uid = %s"
        update_var = (endtime,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Auszugsdatum nachgetragen")
        
    # Fall 13: Nicht zugeordnete IPs löschen
    sql = """
        SELECT m.id, m.ip
        FROM weh.macauth m
        LEFT JOIN weh.users u ON m.uid = u.uid
        WHERE u.uid IS NULL
    """
    cursor.execute(sql)
    falscheips = cursor.fetchall()
    for row in falscheips:
        id = row[0]
        ip = row[1]   
                
        text += "* Nicht zugewiesene IP " + str(ip) + " wurde gelöscht.\n"
            
        update_sql = "DELETE FROM macauth WHERE id = %s"
        update_var = (id,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] IP {ip} gelöscht – keine gültige UID")
    
    # Fall 14: Sperren von Ausgezogenen
    sql = "SELECT u.name, u.uid FROM users u JOIN sperre s ON u.uid = s.uid WHERE u.pid IN (13,14,64) AND s.starttime <= %s AND s.endtime >= %s"
    cursor.execute(sql, (zeit, zeit))
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* Alle Sperren auf " + name + " wurden beendet, da die Person ausgezogen ist.\n"
        
        update_sql = "UPDATE sperre SET endtime = %s WHERE uid = %s"
        update_var = (zeit,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] Sperren auf {name} beendet (ausgezogen)")
        
    # Fall 15: Ausgezogen - Auszugsdatum nicht gesetzt
    sql = "SELECT name, uid, endtime FROM users WHERE pid IN (13) AND ausgezogen = 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurde das Auszugsdatum nachgetragen.\n"
        
        update_sql = "UPDATE users SET ausgezogen = %s WHERE uid = %s"
        update_var = (zeit,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Auszugsdatum nachgetragen")
                
    # Fall 16: Abgemeldet - Enddatum nicht gesetzt
    sql = "SELECT name, uid, endtime FROM users WHERE pid IN (14) AND endtime = 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurde das Enddatum nachgetragen.\n"
        
        update_sql = "UPDATE users SET endtime = %s WHERE uid = %s"
        update_var = (zeit,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Enddatum nachgetragen")
                
    # Fall 17: Raus, aber noch Etagensprecher
    sql = "SELECT name, uid FROM users WHERE pid IN (13,14) AND etagensprecher <> 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        text += "* " + name + " wurde als Etagensprecher entfernt, da ausgezogen.\n"
        
        update_sql = "UPDATE users SET etagensprecher = 0 WHERE uid = %s"
        update_var = (uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Rolle als Etagensprecher entfernt")
        
    # Fall 18: Falsches Subnetz (IP in natmapping über das Subnetz auf den Raum gemappt)
    sql = "SELECT u.name, u.uid, n.subnet, u.subnet FROM weh.users u LEFT JOIN weh.natmapping n ON (u.room = n.room and u.turm = n.turm) WHERE u.pid = 11 AND (u.subnet != n.subnet OR u.subnet IS NULL)"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        subnet = row[2]
        falschessubnet = row[3]
        text += "* " + name + " hatte das falsche Subnetz " + str(falschessubnet) + " eingetragen, was nun mit " + str(subnet) + " korrigiert wurde.\n"
        
        update_sql = "UPDATE users SET subnet = %s WHERE uid = %s"
        update_var = (subnet,uid,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – Subnetz korrigiert: {falschessubnet} → {subnet}")
        
    # Fall 19: Falsche private IP
    sql = """
        SELECT u.name, u.uid, n.subnet, m.ip, m.id
        FROM weh.users u 
        LEFT JOIN weh.natmapping n ON (u.room = n.room and u.turm = n.turm)
        LEFT JOIN weh.macauth m ON u.uid = m.uid
        WHERE u.pid = 11 AND m.ip LIKE "10.%" AND SUBSTRING_INDEX(m.ip, '.', 3) != SUBSTRING_INDEX(n.subnet, '.', 3)
    """
    cursor.execute(sql)
    falscheips = cursor.fetchall()
    for row in falscheips:
        name = row[0]
        uid = row[1]
        subnet = row[2]
        ip = row[3]
        id = row[4]
        
        newipisfree = False
        counter = 1
        while not newipisfree:
            newip = '.'.join(subnet.split('.')[:3]) + "." + str(counter)
            
            check_sql = "SELECT ip FROM weh.macauth WHERE ip = %s"
            cursor.execute(check_sql, (newip,))
            result = cursor.fetchone()
            if not result:
                newipisfree = True
            else:
                counter += 1
                
        text += "* " + name + "'s falsch zugewiesene private IP " + str(ip) + " wurde durch die IP " + str(newip) + " ersetzt.\n"
            
        update_sql = "UPDATE macauth SET ip = %s WHERE id = %s"
        update_var = (newip,id,)
        cursor.execute(update_sql, update_var)
        
        changes = True
        print(f"[✓] {name} – private IP ersetzt: {ip} → {newip}")
        
        
    # Fall 20: User ist ausgezogen, hat aber noch Keyzuordnung
    schlüsselarray = {
        1: "Dach",
        2: "Schacht",
        3: "Wohnzimmer",
        4: "Trennwand",
        5: "Wohnzimmerschrank",
        6: "Tischtennisraum",
        7: "Druckerschrank",
        8: "Putzraum",
        9: "Sprecherbriefkasten",
        10: "Getränkekeller",
        11: "Waschkellerschrank",
        12: "Rechter TK-Flügel",
        13: "Serverraum",
        14: "Sprecherkeller",
        15: "Musikkeller",
        16: "Werkzeugkeller",
        17: "Aufzug"
    }

    sql = "SELECT u.name, u.uid, k.key, k.id FROM weh.users u LEFT JOIN weh.keys k ON u.uid = k.uid WHERE u.pid IN (13,14) AND k.back = 0"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        name = row[0]
        uid = row[1]
        key = row[2]
        id = row[3]
        text += "* " + name + " ist ausgezogen und der " + schlüsselarray.get(key, "Unbekannt") + "-Schlüssel wurde ausgetragen.\n"
        
        update_sql = "UPDATE `keys` SET `back` = %s WHERE `id` = %s"
        update_var = (zeit, id,)
        cursor.execute(update_sql, update_var)
        
        changes = True       
        print(f"[✓] {name} – Schlüssel '{schlüsselarray.get(key, 'Unbekannt')}' ausgetragen")
             
    # Beenden
    text += "\n"
    random_end = random.choice(ends)
    text += random_end
    text += "\nEuer Skript"

    if changes:
        send_mail(subject, text, sendto)
        print("[INFO] Cleanup abgeschlossen, Zusammenfassungsmail gesendet.")
    else:
        print("[INFO] Keine Änderung notwendig, Abgeschlossen.")
                
    conn.commit()

# Verbindung zur Datenbank herstellen
conn = connect_weh()

ends = [
    "Grüße von Fiji!",
    "Es gibt nur einen Rudi Völler!",
    "Halt durch!",
    "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "Kuss!",
    "Gib mir Freiheit oder gib mir den Tod!",
    "Yippie ka yeah, Schweinebacke!",
    "I'll be back!",
    "Ich bin dein Vater!",
    "Bis zur Unendlichkeit und noch viel weiter!",
    "Möge die Macht mit dir sein!",
    "Ich sehe tote Menschen!",
    "Houston, we have a problem!",
    "Ich bin der König der Welt!",
    "Sein oder nicht sein, das ist hier die Frage!",
    "Hasta la Vista, baby!",
    "Mein Name ist Bond, Skript Bond!",
    "Es gibt kein Entkommen!",
    "Ich sehe was, was du nicht siehst!",
    "Die erste Regel des WEH-Skripts ist: Ihr verliert kein Wort über das WEH-Skript.\nDie zweite Regel des WEH-Skripts ist: Ihr verliert kein Wort über das WEH-Skript!",
    "Ich bin zu alt für diesen Scheiß!",
    "Ich hab eine Wassermelone getragen!",
    "Ich gebe ihm eine Datenbankänderungszusammenfassung, die er nicht ablehnen kann!",
    "Ich schau dir in die Augen, Kleines!",
    "Ich kann nicht zu gestern zurückkehren, weil ich damals eine andere Person war!",
    "Nicht alle, die ziellos umherwandern, sind verloren!",
    "Gott weiß ich will kein Engel sein!",
    "Das Wasser soll dein Spiegel sein, erst wenn es glatt ist wirst du sehen wie viel Märchen dir noch bleibt und um Erlösung wirst du flehen!",
    "Und der Haifisch, der hat Zähne und die trägt er im Gesicht. Und Mackie, der hat ein Messer, doch das Messer sieht man nicht!",
    "Und der Haifisch, der hat Tränen und die laufen vom Gesicht, doch der Haifisch lebt im Wasser, so die Tränen sieht man nicht!",
    "Ich glaub' mein Schwein pfeift!",
    "Vorsicht, hinter dir, ein dreiköpfiger Affe!",
    "Munter ans Werk, AG-Mitglied. Munter ans Werk. Womit ich nicht sagen will, dass Sie sonst faulenzen. Niemand hat mehr geleistet als Sie. Und der gesamte Einsatz wäre umsonst bis - nun, sagen wir mal es ist wieder soweit für Sie. Der rechte Mann am falschen Ort kann in der Welt viel bewegen. Also wachen Sie auf, AG-Mitglied. Schnuppern Sie die Asche in der Luft.",
    "Schrecklicher und mächtiger Talos! Wir, Eure unwürdigen Diener, lobpreisen Euch! Denn nur durch Eure Gunst und Güte können wir zu wahrer Erleuchtung finden!\nUnd Ihr habt unseren Lobpreis verdient, denn wir sind eins! Ehe Ihr emporstiegt und aus den Acht die Neun wurden, seid Ihr unter uns gewandelt, großer Talos - nicht als Gott, sondern als Mensch!\nDoch Ihr wart einst ein Mensch! Ja! Und als Mensch, da sagtet Ihr: Lasst mich euch die Macht von Talos Sturmkrone zeigen, der aus dem Norden kommt, wo mein Atem der lange Winter ist.\nIch atme nun, in königlicher Pracht, und gestalte dieses Land, das mir gehört. Dies tue ich für euch, Rote Legionen, denn ich liebe euch.\nJa, Liebe. Liebe! Schon als Mensch hat der große Talos uns hoch geschätzt. Denn in uns, in jedem von uns, sah er die Zukunft von Himmelsrand! Die Zukunft von Tamriel!\nUnd da ist sie, meine Freunde! Die nackte Wahrheit! Wir sind die Kinder der Menschheit! Talos ist der wahre Gott der Menschheit! Vom Fleische emporgestiegen, um das Reich des Geistes zu regieren.\nDer bloße Gedanke ist für unsere Elfenherren unvorstellbar! Den Himmel mit uns zu teilen? Mit einem Menschen? Ha! Sie bringen es ja kaum fertig, unsere Anwesenheit auf Erden zu dulden!\nHeute nehmen sie Euch den Glauben. Aber was wird morgen sein? Was dann? Werden die Elfen Euch die Häuser wegnehmen, die Geschäfte? Eure Kinder? Ja, sogar Euer Leben?\nUnd was macht das Kaiserreich? Nichts! Nein, weniger als nichts! Die kaiserliche Maschinerie vollzieht den Willen der Thalmor! Gegen das eigene Volk.\nAlso steht auf! Steht auf, Kinder des Kaiserreichs. Steht auf, Sturmmäntel! Nehmt das Wort des mächtigen Talos an, der sowohl Mensch als auch Gott ist.\nDenn wir sind die Kinder der Menschheit! Und wir werden Himmel wie auch Erde besitzen! Und wir, nicht die Elfen oder ihre Speichellecker, werden über Himmelsrand herrschen! Auf ewig!",
    "Hier könnte Ihre Werbung stehen!",
    "Bass, Bass, wir brauchen Bass!",
    "Auf dem Marktplatz glaubt niemand an höhere Menschen!",
    "ʕ ● ᴥ ●ʔ",
    "ᕦʕ •`ᴥ•´ʔᕤ",
    "(=◉ᆽ◉=)",
    "૮ ⚆ﻌ⚆ა",
    "Ѱζ༼ᴼل͜ᴼ༽ᶘѰ",
    "( ͡° ͜ʖ├┬┴┬┴",
    "┌∩┐(◣_◢)┌∩┐",
    "(◕︿◕✿)",
    "(⌐▀͡ ̯ʖ▀)",
]

clean_user(ends)

# Verbindung schließen
conn.close()