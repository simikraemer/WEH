# Geschrieben von Fiji
# Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

# Dieses Skript soll zum 01. jedes Monats morgens aufgerufen werden.
# Es bucht Mitgliedsbeiträge vom Mitgliedskonto ab, sperrt User, die nicht genug Geld auf dem Konto haben und informiert alle Betroffenen

# Größere Anpassung 06.04.2024:
# Ab sofort wird der Anteil des tatsächlich bezahlten Hausbeitrags ermittelt und der Kassenausgleich in die Datenbank eingetragen.
# Die Infomail an NetzAG und Vorstand wurden zusammengelegt + Info: Überweisung Kassenausgleich muss händisch gemacht werden.

import time
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from fcol import send_mail
from fcol import connect_weh
from fcol import get_constant

DEBUG = False

## Grundprogramm ##

def abrechnung(): 

    zeit = int(time.time()) #UNIX Time
    vornerwoche = zeit - (1 * 7 * 24 * 60 * 60)
    hausbeitrag = get_constant("hausbeitrag")
    netzbeitrag = get_constant("netzbeitrag")
    
    normalcount = 0
    sperrcount = 0
    insolventcount = 0
    warncount = 0
    kassenausgleichbetrag = 0
    fehlenderbetrag = 0

    wehdb = connect_weh()
    cursor = wehdb.cursor()
    cursor.execute("SELECT uid, groups, honory, pid, name, username, turm, mailisactive, email, forwardemail FROM users WHERE pid IN (11,13) AND starttime < %s AND insolvent = 0 AND uid NOT IN (SELECT uid FROM sperre WHERE missedpayment = 1 AND starttime <= %s AND endtime >= %s)", (vornerwoche, zeit, zeit))
    bewohner = cursor.fetchall()
    for row in bewohner:
        uid = row[0]
        groups = row[1]
        honory = row[2]
        pid = row[3]
        name = row[4]
        username = row[5]
        turm = row[6]
        mailisactive = bool(row[7])
        email = row[8]
        forwardemail = bool(row[9])
        
        kontostand = get_kontostand(uid,cursor)
        if kontostand is None:
            kontostand = 0
        
        # Wandele groups in eine Liste von Zahlen um
        if isinstance(groups, str):
            groups = [int(g) if g.isdigit() else g for g in groups.split(",")]
        elif isinstance(groups, int):
            groups = [groups]
        
        nichtaktiv = all(g == 1 or g in [1, 19] for g in groups)
        monatsbeitrag = 0

        # Abrechnen
        if 7 not in groups and pid == 11:
            abrechnung_netzbeitrag(zeit,uid,netzbeitrag,cursor)
            monatsbeitrag += netzbeitrag
            
        if honory == 0 and nichtaktiv:
            abrechnung_hausbeitrag(zeit,uid,hausbeitrag,cursor)
            monatsbeitrag += hausbeitrag

        rest = kontostand - monatsbeitrag

        if rest is None:
            rest = 0
            
        restcheck = rest + 0.01
        
        if honory == 0 and nichtaktiv:
            if restcheck < 0:
                bezahlt = rest + hausbeitrag
                if 7 not in groups and pid == 11:
                    bezahlt += netzbeitrag            
                    bezahlthausanteil = bezahlt * (hausbeitrag / (hausbeitrag + netzbeitrag))
                    fehlenderbetrag += (-1) * rest * (hausbeitrag / (hausbeitrag + netzbeitrag))
                else:
                    bezahlthausanteil = bezahlt
                    fehlenderbetrag += (-1) * rest
                if bezahlthausanteil > 0:
                    kassenausgleichbetrag += bezahlthausanteil
            else: 
                kassenausgleichbetrag += hausbeitrag

        if restcheck < monatsbeitrag and restcheck > 0 and (pid == 11 or pid == 12):
            if mailisactive: warnmail(uid, turm, monatsbeitrag)
            warncount += 1
            infostring = "Vorgewarnt"
        elif restcheck < 0 and pid == 13:
            insolvent(uid, zeit); insolventcount += 1; infostring = "Insolvent"
        elif restcheck < 0:
            addsperre(uid, zeit)
            if mailisactive: sperrmail(uid, rest, name, username, turm, email, forwardemail)
            sperrcount += 1
            infostring = "Gesperrt"
        else:
            if mailisactive: confirmmail(uid, turm, monatsbeitrag)
            normalcount += 1
            infostring = "Gezahlt"

        
        ausgabe1 = str(name) + " [" + str(uid) + "] | " + str(infostring)
        ausgabe2 = "Vorher: " + str("{:.2f}".format(kontostand)) + "€ | Nachher: " + str("{:.2f}".format(rest)) + "€"
        ausgabe3 = "Bezahlter Hausbeitrag: " + str("{:.2f}".format(kassenausgleichbetrag)) + "€ | Fehlender Hausbeitrag: " + str("{:.2f}".format(fehlenderbetrag)) + "€"
        print(ausgabe1)
        print(ausgabe2)
        print(ausgabe3)
        print()
        print()
        print()
        
    truenormalcount = normalcount + warncount
    
    ## Ungefähr 80% der gesperrten User sind ohne Abmeldung ausgezogen und werden hierüber gesperrt. 
    ## Die anderen 20% sind einfach Banane und haben es vergessen. Die Stocken ihr Konto später noch auf und "überweisen" damit noch den Rest ihres Beitrags.
    #kassenausgleichbetrag += fehlenderbetrag * 0.2 
    # Ausgehasht von Fiji 16.09.2024 -> Wir spekulieren nicht mehr. Kassenausgleichbetrag geht so an Hauskasse, Rest an Netzkasse
    
    kassenausgleichbetrag_2nachkommastellen = round(kassenausgleichbetrag, 2)
    
    kassenausgleichinsert(zeit,kassenausgleichbetrag_2nachkommastellen,cursor)
    infomail(kassenausgleichbetrag_2nachkommastellen, truenormalcount, sperrcount, insolventcount, warncount)
    if DEBUG:
        print("DEBUG MODE: Skipping database changes and emails")
    else:
        wehdb.commit()
    wehdb.close()
    
## Allgemeines ##

def addsperre(uid, zeit):
    missedpayment = 1
    internet = 1
    waschen = 1
    mail = 1
    buchen = 1
    drucken = 1
    beschreibung = "Fehlendes Guthaben"
    starttime = zeit
    endtime = 2147483647 # Maximaler UNIX-Time Wert. Viel Spaß an die zukünftigen Generationen mit diesem Problem :-)
    agent = 472

    fedb = connect_weh()
    fecursor = fedb.cursor()

    # SQL-INSERT-Statement mit Spaltennamen und Platzhaltern für Werte
    insert_sql = "INSERT INTO sperre (uid, missedpayment, internet, waschen, mail, buchen, drucken, beschreibung, starttime, endtime, agent, tstamp) " \
                 "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"

    # Tupel mit den Werten, die in die SQL-Abfrage eingesetzt werden sollen
    insert_var = (uid, missedpayment, internet, waschen, mail, buchen, drucken, beschreibung, starttime, endtime, agent, zeit)

    fecursor.execute(insert_sql, insert_var)
    if DEBUG:
        print("DEBUG MODE: Skipping addsperre() database commit")
    else:
        fedb.commit()

def insolvent(uid,zeit):
    cnx = connect_weh()
    cursor = cnx.cursor()
    insert_sql = "UPDATE users SET insolvent = %s WHERE uid = %s"
    insert_var = (zeit,uid)
    cursor.execute(insert_sql, insert_var)
    if DEBUG:
        print("DEBUG MODE: Skipping insolvent() database commit")
    else:
        cnx.commit()

## Funktionen für Mails ##
    
def infomail(kassenausgleichbetrag, truenormalcount, sperrcount, insolventcount, warncount):
    if DEBUG:
        subject = "[DEBUG] WEH Abrechnung"
    else:
        subject = "WEH Abrechnung"
    message = "Hallo Pappnasen (Netzwerk-AG und Haussprecher),"\
    "\ndie monatliche Abrechnung wurde erfolgreich durchgeführt."\
    "\n\n----------------------------------------"\
    "\n\nDer monatliche Kassenausgleich wurde bereits in die Datenbank eingetragen und muss nur noch überwiesen werden."\
    "\n\nBetrag: " + "{:.2f}".format(kassenausgleichbetrag) + "€"\
    "\nVon Netzkonto: DE90 3905 0000 1070 3346 00"\
    "\nAuf Hauskonto: DE37 3905 0000 1070 3345 84"\
    "\n\n----------------------------------------"\
    "\n\nGezahlt: " + str(truenormalcount) + " User"\
    "\nDavon wurden " + str(warncount) + " User vorgewarnt, da ihr Kontorestbetrag nicht für eine weitere Monatsabrechnung ausreichen würde."\
    "\n\nGesperrt: " + str(sperrcount) + " User"\
    "\nDiese User wurden kontaktiert und werden durch Aufstocken ihres Kontos automatisch entsperrt."\
    "\n\nInsolvent: " + str(insolventcount) + " User"\
    "\nDie insolventen User sind alle bereits ausgezogen, jedoch können sie erst aus dem Verein abgemeldet werden, wenn sie die Beiträge nicht mehr zahlen."\
    "\nNach 6 Monaten (Satzung §9.3) wird streichung.py diese User abmelden und dem Vorstand eine Namensliste schicken."\
    "\n\n----------------------------------------"\
    "\n\nViele Grüße,\npayment_abrechnung.py und Fiji"
    if DEBUG:
        to_email = "webmaster@weh.rwth-aachen.de"
    else:
        to_email = "vorstand@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)
    
def warnmail(uid,turm,monatsbeitrag):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT name, username FROM users WHERE uid = %s"
    var = (uid,)
    wehcursor.execute(sql, var)
    result = wehcursor.fetchone()
    name = result[0] if result else None
    username = result[1] if result else None
    formatted_beitrag = f"{monatsbeitrag:.2f}€"
    
    subject = "WEH Confirmation of Monthly Payment"
    message = (
        f"Dear {name},\n\n"
        f"Your monthly membership fee of {formatted_beitrag} has been deducted from your WEH account.\n\n"
        "You can view all transactions and check your account balance here:\n"
        "https://backend.weh.rwth-aachen.de/UserKonto.php\n\n"
        "However, your current account balance is very low. While your membership fees for this month were covered, "
        "if you don’t top up your account balance soon, it may not be enough to extend your Internet access "
        "at the next billing cycle in the coming month.\n\n"
        "Please ensure your account balance is sufficient to avoid interruptions.\n\n"
        "Best regards,\n"
        "Netzwerk-AG WEH e.V."
    )
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"

    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)
    
def confirmmail(uid,turm,monatsbeitrag):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT name, username FROM users WHERE uid = %s"
    var = (uid,)
    wehcursor.execute(sql, var)
    result = wehcursor.fetchone()
    name = result[0] if result else None
    username = result[1] if result else None
    formatted_beitrag = f"{monatsbeitrag:.2f}€"
    
    subject = "WEH Confirmation of Monthly Payment"
    message = (
        f"Dear {name},\n\n"
        f"your monthly membership fee of {formatted_beitrag} has been deducted from your WEH account.\n\n"
        "You can view all transactions and check your account balance here:\n"
        "https://backend.weh.rwth-aachen.de/UserKonto.php\n\n"
        "Best regards,\n"
        "Netzwerk-AG WEH e.V."
    )
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"

    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)

def sperrmail(uid, rest, name, username, turm, zweite_email, forwardemail):
    posrest = - rest
    
    subject = "WEH Account Ban"
    message = "Dear " + str(name) + ",\n\nyour membership account balance is too low to extend your access to WEH services, so it was cancelled.\n\n"\
    "To reactivate your internet connection, there are still " + "{:.2f}".format(posrest) + "€ missing.\n\n"\
    "Name: WEH e.V.\n"\
    "IBAN: DE90 3905 0000 1070 3346 00\n"\
    "Transfer Reference: W"+str(uid)+"H\n"\
    "If you do not set this exact Transfer Reference, we will not be able to assign your payment to your account!\n\n"\
    "When your member account has a positive balance, your internet connection will be reactivated automatically.\n\n"\
    "If you have already moved out of WEH without deregistering, please ignore this email.\n\n"\
    "Best Regards,\nNetzwerk-AG WEH e.V."
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"
    
    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)
    
    if not forwardemail:    
        if DEBUG:
            print("DEBUG MODE: Email not sent. Here are the details:")
            print(f"Subject: {subject}")
            print(f"Message: {message}")
            print(f"To: {zweite_email}")
        else:
            send_mail(subject, message, zweite_email)

## Funktionen zur Abrechnung ##        

def abrechnung_netzbeitrag(zeit,uid,netzbeitrag,cursor):
    date = time.strftime('%B %Y', time.gmtime(zeit+10000))    
    beschreibung = "Abrechnung Netzbeitrag " + str(date)
    konto = 1
    kasse = 3
    beitrag = -netzbeitrag
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,beitrag)
    cursor.execute(insert_sql, insert_var)
    
def abrechnung_hausbeitrag(zeit,uid,hausbeitrag,cursor):    
    date = time.strftime('%B %Y', time.gmtime(zeit+10000))
    beschreibung = "Abrechnung Hausbeitrag " + str(date)
    konto = 2
    kasse = 3
    beitrag = -hausbeitrag
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,beitrag)
    cursor.execute(insert_sql, insert_var)

def get_kontostand(uid,cursor):
    # time.sleep(0.5) # Damit alle Zahlungen drin sind, bevor man summiert
    sql = "SELECT SUM(betrag) FROM transfers WHERE uid = %s"
    var = (uid,)
    cursor.execute(sql, var)
    summe = cursor.fetchone()[0]
    return summe

def kassenausgleichinsert(zeit,betrag,cursor):   
    
    # Von Netzkonto    
    neg_betrag = (-1) * betrag
    uid = 472
    beschreibung = "Kassenausgleich Netzkonto"
    konto = 2
    kasse = 72
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,neg_betrag)
    cursor.execute(insert_sql, insert_var)
    
    # Auf Hauskonto
    uid = 492
    beschreibung = "Kassenausgleich Hauskonto"
    konto = 2
    kasse = 92
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,betrag)
    cursor.execute(insert_sql, insert_var)

abrechnung()