# Geschrieben von Fiji
# März 2023
# Update Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import datetime
from fcol import get_constant
from fcol import send_mail
from fcol import connect_weh
from fcol import unix_to_date

## Grundprogramm ##

def abmeldung(): 
    print("Starte Abmeldung")

    wehdb = connect_weh()
    print("Datenbankverbindung hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("Datenbankcursor erstellt")

    # Check ob neue Abmeldung vorliegt
    wehcursor.execute("SELECT * FROM abmeldungen WHERE status = 0")
    abgemeldete_bewohner = wehcursor.fetchall()
    print("Abgemeldete Bewohner gefunden:", abgemeldete_bewohner)

    for row in abgemeldete_bewohner:
        uid = row[1]  # aus IP des users gezogen
        endtime = row[4]
        iban = row[5]
        keepemail = row[6]  # 0=mail nicht behalten, 1=mail behalten
        alumni = row[7]  # 0=nicht auf liste, 1=auf alumniliste
        alumnimail = row[8]
        bezahlart = row[9]  # 0=bar, 1=iban
        print(f"Verarbeite Abmeldung für UID: {uid}, Endzeit: {endtime}, IBAN: {iban}, KeepEmail: {keepemail}, Alumni: {alumni}, AlumniMail: {alumnimail}, Bezahlart: {bezahlart}")

        wehdb = connect_weh()
        wehcursor = wehdb.cursor()
        sql = "SELECT name, username, turm FROM users WHERE uid = %s"
        var = (uid,)
        wehcursor.execute(sql, var)
        result = wehcursor.fetchone()
        name = result[0] if result else None
        username = result[1] if result else None
        turm = result[2] if result else None

        # Konstanten ziehen
        abmeldekosten = get_constant("abmeldekosten")
        print("Abmeldekosten:", abmeldekosten)
        zeit = int(time.time())  # UNIX Time
        print("Aktuelle UNIX-Zeit:", zeit)
        
        summe = get_betrag(uid)
        print("Summe auf dem Konto des Benutzers:", summe)
        betrag = summe
        
        # Abrechnen der Abmeldekosten
        if bezahlart == 1:
            betrag -= abmeldekosten
            print("Betrag nach Abzug der Abmeldekosten:", betrag)
        
        # Alumnimail eintragen
        if alumni == 1:
            add_alumni(zeit, uid, alumnimail)
            print("Alumni-Mail eingetragen für UID:", uid)
            
        # Wenn durch Abmeldekosten o.Ä. der Betrag negativ ist, erwarten wir nicht, dass die User uns etwas zurückzahlen. Die kriegt man eh nicht mehr.
        if betrag < 0:
            betrag = 0
            print("Betrag war negativ, wurde auf 0 gesetzt")
        
        # Rauswurf aus users
        auszugsart = users_rauswurf(endtime, uid)  # 0=User lebt noch hier, 1=User lebt nicht mehr hier
        print("Auszugsart für UID:", uid, "ist:", auszugsart)
        
        # Druckersperre ist nicht notwendig, da User kein Geld mehr auf Mitgliedskonto hat
        
        # Statuswechsel
        if bezahlart == 1:  # "Abmeldung abgeschlossen"
            if betrag > 0:
                # Netzsprecher erhält Mail mit Überweisungsaufforderung des Betrags
                # 31.03.2025 - Keine Kontomail mehr, sondern Verwaltung über Anmeldung.php
                #kontomail(betrag, iban, name)
                #print("Kontomail gesendet für Betrag:", betrag)
                print("Fiji hat den übelsten Swag!")
            status = 1
            print("Status gesetzt auf 2 (Abmeldung abgeschlossen) für UID:", uid)
        elif bezahlart == 0:  # "Barzahlung ausstehend"
            status = 1
            print("Status gesetzt auf 1 (Barzahlung ausstehend) für UID:", uid)
        else:
            print("Fehler bei bezahlart\nAbmeldung von uid: " + uid + "\nGrüße von Fiji")
        
        # Konto auf 0 setzen, wenn Auszahlung erfolgen soll
        if betrag > 0:
            abrechnung_abmeldung(zeit, uid, summe, bezahlart, abmeldekosten)
            print("Abrechnung durchgeführt für UID:", uid)
        
        # Bestätigungsmail an User ballern
        bestätigungsmail(uid, endtime, betrag, bezahlart, name, username, turm)
        print("Bestätigungsmail gesendet an UID:", uid)
            
        # Status und Betrag in abmeldungen eintragen
        statuswechsel(status, betrag, uid)
        print("Statuswechsel durchgeführt für UID:", uid)
    
    wehdb.close()
    print("Datenbankverbindung geschlossen")


## Funktionen für Mails ##

def kontomail(betrag,iban,name): #sendet infos an netzag mit kontozugriff
    subject = "WEH Abmeldung IBAN"
    formatted_betrag = ("%0.2f" % betrag).replace('.', ',') + " €"
    message = "Betrag: " + str(formatted_betrag) + "\nKonto: " + str(iban) + "\nName: " + str(name)
    to_email = "ticket@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)

def bestätigungsmail(uid,endtime,betrag,bezahlart,name,username,turm):
    formatted_betrag = "{:.2f}".format(betrag)
    
    subject = "WEH Deregistration"
    if betrag == 0: #in abmeldung() wird alles kleiner als 0 ohnehin auf 0 gesetzt
        if bezahlart == 1:
            message = "Dear " + str(name) + ",\n\nyour deregistration from WEH e.V. for " + str(unix_to_date(endtime)) + " was successful.\n\nHowever, the remaining amount of your member account (with the transfer fee of your chosen IBAN-method) is less than or equal to 0€.\nTherefore you will not get any money back, but you don't have also don't have to pay us anything. .\nIf you think this is a mistake, please visit our consultation hour and we'll check it out.\n\nIf you didn't deregister and received this mail, please contact us immediately.\n\nBest Regards,\nNetzwerk-AG WEH e.V."
        elif bezahlart == 0:
            message = "Dear " + str(name) + ",\n\nyour deregistration from WEH e.V. for " + str(unix_to_date(endtime)) + " was successful.\n\nHowever, the remaining amount of your member account is less than or equal to 0€.\nTherefore you will not get any money back, but you don't have also don't have to pay us anything. .\nIf you think this is a mistake, please visit our consultation hour and we'll check it out.\n\nIf you didn't deregister and received this mail, please contact us immediately.\n\nBest Regards,\nNetzwerk-AG WEH e.V."
        else:
            print("Problem bei Bezahlart für Bestätigungsmail\nGrüße von Fiji")    
    else:
        if bezahlart == 1:
            message = "Dear " + str(name) + ",\n\nyour deregistration from WEH e.V. for " + str(unix_to_date(endtime)) + " was successful.\n\nYou will receive " + str(formatted_betrag) + "€.\nPlease be patient while we transfer the amount.\n\nIf you didn't deregister and received this mail, please contact us immediately.\n\nBest Regards,\nNetzwerk-AG WEH e.V."
        elif bezahlart == 0:
            message = "Dear " + str(name) + ",\n\nyour deregistration from WEH e.V. for " + str(unix_to_date(endtime)) + " was successful.\n\nYou will receive " + str(formatted_betrag) + "€.\nPlease come to our consultation hour to pick it up.\n\nIf you didn't deregister and received this mail, please contact us immediately.\n\nBest Regards,\nNetzwerk-AG WEH e.V."
        else:
            print("Problem bei Bezahlart für Bestätigungsmail\nGrüße von Fiji")
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"
    send_mail(subject, message, to_email)

## Funktionen zur Abmeldung ##

def add_alumni(zeit, uid, alumnimail): #Fügt User zu alumnimail hinzu
    tstamp = zeit
    mailaddress = alumnimail
    
    cnx = connect_weh()
    cursor = cnx.cursor()

    # Überprüfen, ob die UID bereits vorhanden ist
    select_sql = "SELECT uid FROM alumnimail WHERE uid = %s"
    select_var = (uid,)
    cursor.execute(select_sql, select_var)
    existing_uid = cursor.fetchone()

    if existing_uid:
        # Die UID ist bereits vorhanden, daher die E-Mail aktualisieren
        update_sql = "UPDATE alumnimail SET email = %s WHERE uid = %s"
        update_var = (mailaddress, uid)
        cursor.execute(update_sql, update_var)
    else:
        # Die UID ist nicht vorhanden, daher eine neue Zeile einfügen
        insert_sql = "INSERT INTO alumnimail (uid, tstamp, email) VALUES (%s, %s, %s)"
        insert_var = (uid, tstamp, mailaddress)
        cursor.execute(insert_sql, insert_var)

    cnx.commit()

def users_rauswurf(endtime,uid): #users abmeldung
    fedb = connect_weh()
    fecursor = fedb.cursor()

    # Room und Historie abrufen
    sql = "SELECT room, historie FROM users WHERE uid = %s"
    var = (uid,)
    fecursor.execute(sql, var)
    result = fecursor.fetchone()
    room = result[0]
    historie = result[1]
    
    datum_unform = datetime.datetime.now()
    datum = datum_unform.strftime("%d.%m.%Y")
        
    historie += "\n" + datum + " Abgemeldet (System)"
    
    # Das löschen von Subnetz und den eingetragenen IPs übernimmt das Cleaning Skript
    
    if room != 0: # Für User, die noch eine Weile bei uns wohnen bleiben. Die werden dann vom Cleaning Skript rausgeworfen, sobald endtime eine Woche überschritten wurde.
        # historie wird vom cleaning skript ergänzt
                
        update_sql = "UPDATE users SET endtime = %s WHERE uid = %s"
        update_var = (endtime, uid)
        fecursor.execute(update_sql, update_var)
        fedb.commit()
        return 0
        
    else: #falls user keinen raum mehr hat, also bereits als ausgezogen (nicht abgemeldet) eingetragen wurde
        
        update_sql = "UPDATE users SET pid = 14, endtime = %s, historie = %s WHERE uid = %s"
        update_var = (endtime, historie, uid)
        fecursor.execute(update_sql, update_var)
        fedb.commit()
        return 1
            
def statuswechsel(status,betrag,uid): #hakt den bewohner in abmeldungen ab, wenn abgemeldet
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    update_sql = "UPDATE abmeldungen SET status = %s, betrag = %s WHERE uid = %s"
    update_var = (status,betrag,uid,)
    wehcursor.execute(update_sql, update_var)
    wehdb.commit()


## Funktionen zur Abrechnung ##        

def abrechnung_abmeldung(zeit,uid,summe,bezahlart,abmeldekosten):
    tstamp = zeit
    beschreibung = "Abmeldung"
    konto = 0
    neg_summe = -summe
    if bezahlart == 1: #IBAN
        kasse = 4 #Netzkonto

        summe_konto = neg_summe + abmeldekosten
        beschreibung_konto = "Abmeldung " + str(uid) + " - Änderung Netzkonto"
        cnx = connect_weh()
        cursor = cnx.cursor()
        insert_sql = "INSERT INTO transfers (uid,tstamp,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
        insert_var = (472,tstamp,beschreibung_konto,8,72,summe_konto)
        cursor.execute(insert_sql, insert_var)
        cnx.commit()

    elif bezahlart == 0: #Abholung
        kasse = 1 #Barkasse
    
    cnx = connect_weh()
    cursor = cnx.cursor()
    insert_sql = "INSERT INTO transfers (uid,tstamp,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (uid,tstamp,beschreibung,konto,kasse,neg_summe)
    cursor.execute(insert_sql, insert_var)
    cnx.commit()


def get_betrag(uid):
    try:
        fedb = connect_weh()
        fecursor = fedb.cursor()
        sql = "SELECT sum(betrag) FROM transfers WHERE uid = %s"
        var = (uid,)
        fecursor.execute(sql, var)
        summe = fecursor.fetchone()

        if summe is not None:
            return summe[0]
        else:
            return 0

    except Exception as e:
        print("Fehler beim Abrufen des Betrags:", e)
        return 0

abmeldung()




