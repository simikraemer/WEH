# Geschrieben von Fiji
# Juni 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import random
from fcol import send_mail
from fcol import connect_weh
    
def clean_bike_storage(ends):
    sendto = "fahrrad@weh.rwth-aachen.de"
    subject = "Fahrradstellplatz - Änderungen"

    text = ""
    zeit = time.time()
    changes = False

    print("Starte die Bereinigung des Fahrradkellers...")

    text += "Es haben sich folgende Änderungen an der Belegung des Fahrradkellers ergeben:\n\n"

    cursor = conn.cursor()

    # Nachrückerliste definieren
    print("Hole die Nachrückerliste...")
    sql = """
    SELECT users.name, fahrrad.id, users.uid, users.groups
    FROM weh.users
    INNER JOIN weh.fahrrad ON users.uid = fahrrad.uid
    WHERE fahrrad.platz = 0 AND (fahrrad.endtime IS NULL OR fahrrad.endtime > %s)
    ORDER BY 
        CASE
            WHEN users.groups NOT LIKE '1' AND users.groups NOT LIKE '1,19' THEN 0
            ELSE 1
        END,
        fahrrad.starttime ASC
    """
    var = (zeit,)
    cursor.execute(sql, var)
    nachrücker = cursor.fetchall()
    print(f"Nachrückerliste geladen: {len(nachrücker)} Einträge gefunden.")

  # Fall 1.1: Neuer Warteliste-User
    print("Verarbeite neue Warteliste-User...")
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 1"
    cursor.execute(sql)
    neueuser = cursor.fetchall()
    for row in neueuser:
        print(f"Neuer Wartelisten-Eintrag: {row[0]}")
        text += "* " + row[0] + " hat sich auf die Warteliste gesetzt.\n"
        update_sql = "UPDATE fahrrad SET status = 0 WHERE id = %s"
        update_var = (row[1],)
        cursor.execute(update_sql, update_var)
        changes = True

      
  # Fall 1.2: Subletter Rückkehr  
    print("Verarbeite Rückkehr von Sublettern...")
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 5 AND users.pid = 11"
    cursor.execute(sql)
    subletter = cursor.fetchall()
    for row in subletter:
        print(f"Subletter zurück: {row[0]}")
        text += "* " + row[0] + " ist von seiner Untervermietung zurück und wurde wieder auf die Warteliste gesetzt.\n"

        sql = "SELECT starttime FROM fahrrad WHERE platz = 0 AND endtime IS NULL ORDER BY starttime ASC LIMIT 1"
        cursor.execute(sql)
        newtime = cursor.fetchone()[0] - 1
    
        update_sql = "UPDATE fahrrad SET starttime = %s, status = 0, endtime = NULL, platztime = NULL WHERE id = %s"
        update_var = (newtime, row[1],)
        cursor.execute(update_sql, update_var)
        changes = True

  # Fall 4: Warteliste-User zieht aus
    print("Lösche User von der Warteliste, die ausgezogen sind...")
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE (users.pid != 11 AND users.pid != 12) AND fahrrad.platz = 0 AND (fahrrad.endtime IS NULL OR fahrrad.endtime > %s)"    
    cursor.execute(sql, (zeit,))
    rows = cursor.fetchall()

    for row in rows:
        print(f"Wartelisten-User entfernt: {row[0]}")
        text += "* " + row[0] + " wird aus der Warteliste gelöscht, weil diese Person nicht mehr im Haus wohnt.\n"        
        update_sql = "UPDATE fahrrad SET endtime = %s WHERE id = %s"
        update_var = (zeit, row[1])
        cursor.execute(update_sql, update_var)
        changes = True
        
  # Fall 5: Fahrrad-AG entfernt Warteliste-User
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 4"
    cursor.execute(sql)
    rows = cursor.fetchall()
    for row in rows:
        text += "* " + row[0] + " wurde von einem Fahrrad-AG User von der Warteliste entfernt.\n"
        update_sql = "UPDATE fahrrad SET status = 0 WHERE id = %s"
        update_var = (row[1],)
        cursor.execute(update_sql, update_var)
        changes = True
    
  # Fall 2.1: Stellplatz-User zieht aus, Warteliste-User rückt nach
    print("Verarbeite von der Fahrrad-AG entfernte User...")
    sql = "SELECT users.name, fahrrad.platz, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE (users.pid != 11 AND users.pid != 12) AND ((users.ausgezogen < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND users.ausgezogen != 0) OR (users.endtime < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND users.endtime != 0)) AND fahrrad.platz > 0 ORDER BY fahrrad.platz"    
    cursor.execute(sql)
    ausgezogene = cursor.fetchall()

    for row in ausgezogene:
        print(f"User von der AG entfernt: {row[0]}")
        text += "* " + row[0] + " verliert Stellplatz " + str(row[1]) + ", weil diese Person nicht mehr im Haus wohnt.\n"
        update_sql = "UPDATE fahrrad SET platz = 0, endtime = %s WHERE id = %s"
        update_var = (zeit, row[2])
        cursor.execute(update_sql, update_var)

        if nachrücker:
            nachrücker_person = nachrücker.pop(0)
            text += "* " + nachrücker_person[0] + " wird dafür auf den Stellplatz " + str(row[1]) + " nachrücken und wurde per E-Mail informiert.\n"
            update_sql = "UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s"
            update_var = (row[1], zeit, nachrücker_person[1])
            cursor.execute(update_sql, update_var)
            nachrückmail(nachrücker_person[2], row[1])
        else:
            text += "* Es gibt keinen Nachrücker für Stellplatz " + str(row[1]) + ".\n"
        changes = True
    
  # Fall 2.2: Stellplatz-User untervermietet, Warteliste-User rückt nach
    print("Verarbeite ausgezogene Stellplatz-User...")
    sql = "SELECT users.name, fahrrad.platz, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE users.pid = 12 AND fahrrad.platz > 0 ORDER BY fahrrad.platz"
    cursor.execute(sql)
    subletters = cursor.fetchall()

    for row in subletters:
        print(f"Stellplatz freigeworden: {row[1]} von {row[0]}")
        text += "* " + row[0] + " verliert Stellplatz " + str(row[1]) + ", weil diese Person ihr Zimmer untervermietet. Sobald die Person wieder einzieht rückt sie automatisch auf Platz 1 der Warteliste.\n"
        update_sql = "UPDATE fahrrad SET platz = 0, endtime = %s, status = 5 WHERE id = %s"
        update_var = (zeit, row[2])
        cursor.execute(update_sql, update_var)

        if nachrücker:
            nachrücker_person = nachrücker.pop(0)
            text += "* " + nachrücker_person[0] + " wird dafür auf den Stellplatz " + str(row[1]) + " nachrücken und wurde per E-Mail informiert.\n"
            update_sql = "UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s"
            update_var = (row[1], zeit, nachrücker_person[1])
            cursor.execute(update_sql, update_var)
            nachrückmail(nachrücker_person[2], row[1])
        else:
            text += "* Es gibt keinen Nachrücker für Stellplatz " + str(row[1]) + ".\n"
        changes = True

    # Fall 3: Fahrrad-AG entfernt Stellplatz-User, Warteliste-User rückt nach
    print("Verarbeite durch die Fahrrad-AG entfernte Stellplatz-User...")

    # Entfernte Stellplatz-User
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 2"
    cursor.execute(sql)
    rows = cursor.fetchall()
    for row in rows:
        print(f"Stellplatz entfernt: {row[0]} (ID: {row[1]}) wurde von einem Fahrrad-AG Mitglied entfernt.")
        text += f"* {row[0]} wurde von einem Fahrrad-AG Mitglied von seinem Stellplatz entfernt.\n"
        update_sql = "UPDATE fahrrad SET status = 0 WHERE id = %s"
        update_var = (row[1],)
        cursor.execute(update_sql, update_var)
        changes = True

    # Nachrücken nach Entfernung
    print("Verarbeite automatische Nachrücker für entfernte Stellplätze...")
    sql = "SELECT users.name, fahrrad.id, fahrrad.platz, users.uid FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 3"
    cursor.execute(sql)
    rows = cursor.fetchall()
    for row in rows:
        print(f"Nachrücker: {row[0]} (UID: {row[3]}) übernimmt Stellplatz {row[2]} nach Entfernung des alten Users.")
        text += f"* {row[0]} rückte automatisch auf Stellplatz {row[2]} nach, da ein Fahrrad-AG User den alten Stellplatz-User entfernt hat.\n"
        update_sql = "UPDATE fahrrad SET status = 0 WHERE id = %s"
        update_var = (row[1],)
        cursor.execute(update_sql, update_var)
        nachrückmail(row[3], row[2])
        changes = True


  # Check ob alle Stellplätze vergeben sind, freie vergeben
    print("Überprüfe Belegung der Stellplätze...")
    sql = "SELECT COUNT(*) FROM fahrrad WHERE platz > 0"
    cursor.execute(sql)
    count = cursor.fetchone()[0]
    print(f"Belegte Stellplätze: {count}")

    if count == 30:
        text += "\nAlle 30 Stellplätze sind derzeit ordnungsgemäß belegt.\n\n"
    elif count < 30:
        if nachrücker:
            all_plätze = list(range(1, 31))
            sql = "SELECT platz FROM fahrrad WHERE platz > 0";
            cursor.execute(sql)
            belegte_plätze = [row[0] for row in cursor.fetchall()]
            free_plätze = [platz for platz in all_plätze if platz not in belegte_plätze]
            for platz in free_plätze:
                nachrücker_person = nachrücker.pop(0)
                text += "* " + nachrücker_person[0] + " wurde für den freien Stellplatz " + str(platz) + " eingeteilt und wurde per E-Mail informiert.\n"
                update_sql = "UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s"
                update_var = (platz, zeit, nachrücker_person[1])
                cursor.execute(update_sql, update_var)
                nachrückmail(nachrücker_person[2], platz)
            changes = True
        sql = "SELECT COUNT(*) FROM fahrrad WHERE platz > 0"
        cursor.execute(sql)
        count = cursor.fetchone()[0]
        if count == 30:
            text += "\nAlle 30 Stellplätze sind derzeit ordnungsgemäß belegt.\n\n"
        else:
            text += "\nAktuell sind " + str(count) + " Stellplätze belegt. Es existieren keine Bewohner auf der Warteliste.\n\n"
    elif count > 30:        
        text += "\n!Alarmstufe Rot!\nEs sind mehr Stellplätze belegt, als in Wahrheit existieren.\nBitte kontaktiert die Netzwerk-AG!\n\n"
        changes = True
    else:        
        text += "\n!Alarmstufe Rot!\nIrgendetwas läuft grundlegend falsch!\nBitte kontaktiert sofort die Netzwerk-AG!\n\n"
        changes = True
    
    # Beenden
    random_end = random.choice(ends)
    text += random_end
    text += "\nEuer Skript"
    
    if changes:
        print("Änderungen festgestellt. Sende zusammengefassten Bericht:")
        print("-------- Start des Berichts --------")
        print(text)
        print("--------- Ende des Berichts --------")
        send_mail(subject, text, sendto)
        
    conn.commit()

def nachrückmail(uid, stellplatz):
    print(f"Starte das Versenden einer Nachrücker-Mail für UID {uid}, Stellplatz {stellplatz}...")
    cursor = conn.cursor()
    sql = "SELECT firstname, username FROM users WHERE uid = %s"    
    cursor.execute(sql, (uid,))
    
    result = cursor.fetchone()
    if result:
        name, username = result
        sendto = str(username) + "@weh.rwth-aachen.de"
        reply_to = "fahrrad@weh.rwth-aachen.de"
        subject = "Fahrradstellplatz zugewiesen"
        text = "Herzlichen Glückwunsch " + name + "!\n\nDir wurde der Fahrradstellplatz " + str(stellplatz) + " zugewiesen.\n\nDie genause Position kannst du den Übersichtsplänen im Keller entnehmen.\n\nBitte stelle dein Fahrrad zeitnah auf deinem Stellplatz ab. Andernfalls behalten wir uns vor, dir den Stellplatz wieder abzuerkennen.\nWichtig: Solltest du deinen Stellplatz nicht mehr benötigen, antworte bitte auf diese Mail und teile uns dies mit, damit wir deinen Platz einer anderen Person zuweisen können.\nDu erkennst ferner automatisch unsere Stellplatzregeln an, die du auf unserer Webseite einsehen kannst: https://www2.weh.rwth-aachen.de/ags/fahrrad-ag/\n\nViele Grüße,\nFahrrad-AG & Netzwerk-AG"
        print("Nachrücker-Mail Details:")
        print(f"Empfänger: {sendto}")
        print(f"Betreff: {subject}")
        print(f"Inhalt: {text}")
        send_mail(subject, text, sendto, reply_to)
        print(f"Nachrücker-Mail an {sendto} erfolgreich gesendet.\n\n")
        
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


clean_bike_storage(ends)

# Verbindung schließen
conn.close()