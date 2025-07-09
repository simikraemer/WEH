# Geschrieben von Fiji
# Juni 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import random
from fcol import send_mail
from fcol import connect_weh

DEBUG = True
    
def clean_bike_storage(ends, turm):
    if DEBUG:
        sendto = "webmaster@weh.rwth-aachen.de"
    else:
        if turm == 'tvk':
            sendto = "sprecher@tvk.rwth-aachen.de"
        else:
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
    WHERE fahrrad.platz = 0 AND (fahrrad.endtime IS NULL OR fahrrad.endtime > %s) AND fahrrad.turm = %s
    ORDER BY 
        CASE
            WHEN users.groups NOT LIKE '1' AND users.groups NOT LIKE '1,19' THEN 0
            ELSE 1
        END,
        fahrrad.starttime ASC
    """
    cursor.execute(sql, (zeit, turm))
    nachrücker = cursor.fetchall()

    # CASE 1: Neue Wartelisten-User
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 1 AND fahrrad.turm = %s"
    cursor.execute(sql, (turm,))
    neueuser = cursor.fetchall()
    for row in neueuser:
        text += f"* {row[0]} hat sich auf die Warteliste gesetzt.\n"
        cursor.execute("UPDATE fahrrad SET status = 0 WHERE id = %s", (row[1],))
        changes = True

      
    # CASE 2: Subletter Rückkehr
    sql = "SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 5 AND users.pid = 11 AND fahrrad.turm = %s"
    cursor.execute(sql, (turm,))
    subletter = cursor.fetchall()
    for row in subletter:
        sql = "SELECT starttime FROM fahrrad WHERE platz = 0 AND endtime IS NULL AND turm = %s ORDER BY starttime ASC LIMIT 1"
        cursor.execute(sql, (turm,))
        newtime = cursor.fetchone()[0] - 1
        cursor.execute("UPDATE fahrrad SET starttime = %s, status = 0, endtime = NULL, platztime = NULL WHERE id = %s", (newtime, row[1]))
        text += f"* {row[0]} ist von seiner Untervermietung zurück und wurde wieder auf die Warteliste gesetzt.\n"
        changes = True

    # CASE 3: Warteliste-User ausgezogen
    sql = """
    SELECT users.name, fahrrad.id 
    FROM users 
    INNER JOIN fahrrad ON users.uid = fahrrad.uid 
    WHERE (users.pid != 11 AND users.pid != 12) AND fahrrad.platz = 0 
      AND (fahrrad.endtime IS NULL OR fahrrad.endtime > %s) AND fahrrad.turm = %s
    """
    cursor.execute(sql, (zeit, turm))
    for row in cursor.fetchall():
        text += f"* {row[0]} wird aus der Warteliste gelöscht, weil diese Person nicht mehr im Haus wohnt.\n"
        cursor.execute("UPDATE fahrrad SET endtime = %s WHERE id = %s", (zeit, row[1]))
        changes = True
        
    # CASE 4: Fahrrad-AG entfernt Wartelisten-User
    cursor.execute("SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 4 AND fahrrad.turm = %s", (turm,))
    for row in cursor.fetchall():
        text += f"* {row[0]} wurde von einem Fahrrad-AG User von der Warteliste entfernt.\n"
        cursor.execute("UPDATE fahrrad SET status = 0 WHERE id = %s", (row[1],))
        changes = True
    
    # CASE 5: Ausgezogene Stellplatz-User
    sql = """
    SELECT users.name, fahrrad.platz, fahrrad.id 
    FROM users 
    INNER JOIN fahrrad ON users.uid = fahrrad.uid 
    WHERE (users.pid NOT IN (11,12)) 
      AND ((users.ausgezogen < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND users.ausgezogen != 0) 
      OR (users.endtime < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND users.endtime != 0)) 
      AND fahrrad.platz > 0 AND fahrrad.turm = %s
    ORDER BY fahrrad.platz
    """
    cursor.execute(sql, (turm,))
    for row in cursor.fetchall():
        cursor.execute("UPDATE fahrrad SET platz = 0, endtime = %s WHERE id = %s", (zeit, row[2]))
        text += f"* {row[0]} verliert Stellplatz {row[1]}, weil diese Person nicht mehr im Haus wohnt.\n"
        if nachrücker:
            person = nachrücker.pop(0)
            cursor.execute("UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s", (row[1], zeit, person[1]))
            text += f"* {person[0]} wird dafür auf den Stellplatz {row[1]} nachrücken und wurde per E-Mail informiert.\n"
            nachrückmail(person[2], row[1], turm)
        else:
            text += f"* Es gibt keinen Nachrücker für Stellplatz {row[1]}.\n"
        changes = True
    
    # CASE 6: Subletters mit Stellplatz temporär entfernen
    sql = "SELECT users.name, fahrrad.platz, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE users.pid = 12 AND fahrrad.platz > 0 AND fahrrad.turm = %s ORDER BY fahrrad.platz"
    cursor.execute(sql, (turm,))
    for row in cursor.fetchall():
        cursor.execute("UPDATE fahrrad SET platz = 0, endtime = %s, status = 5 WHERE id = %s", (zeit, row[2]))
        text += f"* {row[0]} verliert Stellplatz {row[1]}, weil untervermietet wurde. Sobald zurück, rückt Person auf Wartelistenplatz 1.\n"
        if nachrücker:
            person = nachrücker.pop(0)
            cursor.execute("UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s", (row[1], zeit, person[1]))
            text += f"* {person[0]} wird dafür auf den Stellplatz {row[1]} nachrücken und wurde per E-Mail informiert.\n"
            nachrückmail(person[2], row[1], turm)
        else:
            text += f"* Es gibt keinen Nachrücker für Stellplatz {row[1]}.\n"
        changes = True

    # CASE 7: Fahrrad-AG entfernt Stellplatz-User, Warteliste-User rückt nach
    cursor.execute("SELECT users.name, fahrrad.id FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 2 AND fahrrad.turm = %s", (turm,))
    for row in cursor.fetchall():
        cursor.execute("UPDATE fahrrad SET status = 0 WHERE id = %s", (row[1],))
        text += f"* {row[0]} wurde von einem Fahrrad-AG Mitglied von seinem Stellplatz entfernt.\n"
        changes = True

    # CASE 8: Nachrücken nach Entfernung
    cursor.execute("SELECT users.name, fahrrad.id, fahrrad.platz, users.uid FROM users INNER JOIN fahrrad ON users.uid = fahrrad.uid WHERE fahrrad.status = 3 AND fahrrad.turm = %s", (turm,))
    for row in cursor.fetchall():
        cursor.execute("UPDATE fahrrad SET status = 0 WHERE id = %s", (row[1],))
        nachrückmail(row[3], row[2], turm)
        text += f"* {row[0]} rückte automatisch auf Stellplatz {row[2]} nach, da ein Fahrrad-AG User den alten Stellplatz-User entfernt hat.\n"
        changes = True

    # END: Stellplätze prüfen
    cursor.execute("SELECT COUNT(*) FROM fahrrad WHERE platz > 0 AND turm = %s", (turm,))
    count = cursor.fetchone()[0]

    stellplätze = 31 if turm == 'tvk' else 30

    if count == stellplätze:
        text += "\nAlle Stellplätze sind derzeit ordnungsgemäß belegt.\n\n"
    elif count < stellplätze:
        cursor.execute("SELECT platz FROM fahrrad WHERE platz > 0 AND turm = %s", (turm,))
        belegte = [row[0] for row in cursor.fetchall()]
        freie = [p for p in range(1, stellplätze+1) if p not in belegte]
        text += "\n"
        for p in freie:
            if not nachrücker:
                break
            person = nachrücker.pop(0)
            cursor.execute("UPDATE fahrrad SET platz = %s, platztime = %s WHERE id = %s", (p, zeit, person[1]))
            nachrückmail(person[2], p, turm)
            text += f"* {person[0]} wurde für freien Stellplatz {p} eingeteilt und wurde per E-Mail informiert.\n"
        text += "\n"
        changes = True
    elif count > stellplätze:
        text += "\n!Alarmstufe Rot!\nEs sind mehr Stellplätze belegt, als existieren. Bitte Netzwerk-AG informieren!\n\n"
        changes = True

    random_end = random.choice(ends)
    text += random_end + "\nEuer Skript"
    
    if changes:
        print("Änderungen festgestellt. Sende zusammengefassten Bericht:")
        print("-------- Start des Berichts --------")
        print(text)
        print("--------- Ende des Berichts --------")
        send_mail(subject, text, sendto)

    if not DEBUG:
        conn.commit()

def nachrückmail(uid, stellplatz, turm):
    print(f"Starte das Versenden einer Nachrücker-Mail für UID {uid}, Stellplatz {stellplatz}...")
    cursor = conn.cursor()
    sql = "SELECT firstname, username FROM users WHERE uid = %s"    
    cursor.execute(sql, (uid,))
    
    result = cursor.fetchone()
    if result:
        name, username = result
        sendto = f"{username}@{turm}.rwth-aachen.de"
        reply_to = "sprecher@tvk.rwth-aachen.de" if turm == 'tvk' else "fahrrad@weh.rwth-aachen.de"
        subject = "Fahrradstellplatz zugewiesen"
        if turm == 'tvk':
            text = (
                f"Herzlichen Glückwunsch {name}!\n\n"
                f"Dir wurde der Fahrradstellplatz {stellplatz} zugewiesen.\n\n"
                f"Die genause Position kannst du den Übersichtsplänen im Keller entnehmen.\n\n"
                f"Bitte stelle dein Fahrrad zeitnah auf deinem Stellplatz ab. Andernfalls behalten wir uns vor, "
                f"dir den Stellplatz wieder abzuerkennen.\n"
                f"Solltest du deinen Stellplatz nicht mehr benötigen, antworte bitte auf diese Mail und teile "
                f"uns dies mit, damit wir deinen Platz einer anderen Person zuweisen können.\n\n"
                f"Viele Grüße,\nTvK-Haussprecher & Netzwerk-AG"
            )
        else:
            text = (
                f"Herzlichen Glückwunsch {name}!\n\n"
                f"Dir wurde der Fahrradstellplatz {stellplatz} zugewiesen.\n\n"
                f"Die genause Position kannst du den Übersichtsplänen im Keller entnehmen.\n\n"
                f"Bitte stelle dein Fahrrad zeitnah auf deinem Stellplatz ab. Andernfalls behalten wir uns vor, "
                f"dir den Stellplatz wieder abzuerkennen.\n"
                f"Solltest du deinen Stellplatz nicht mehr benötigen, antworte bitte auf diese Mail und teile "
                f"uns dies mit, damit wir deinen Platz einer anderen Person zuweisen können.\n"
                f"Du erkennst ferner automatisch unsere Stellplatzregeln an, die du auf unserer Webseite einsehen kannst: "
                f"https://www2.weh.rwth-aachen.de/ags/fahrrad-ag/\n\n"
                f"Viele Grüße,\nFahrrad-AG & Netzwerk-AG"
            )

        if DEBUG:
            print(f"[DEBUG] Mail an {sendto} wurde NICHT gesendet.")
            print("------ MAIL-INHALT ------")
            print(f"Betreff: {subject}")
            print(f"Reply-To: {reply_to}")
            print(f"Inhalt:\n{text}")
            print("-------------------------\n")
        else:
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

clean_bike_storage(ends, 'weh')
clean_bike_storage(ends, 'tvk')

# Verbindung schließen
conn.close()