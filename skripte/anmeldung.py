# Geschrieben von Fiji
# 2023-2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail
from fcol import connect_weh
from fcol import connect_wp

## Grundfunktion

def wordpressformular(): # Überträgt neue Anmeldungen von Wordpress in anmeldungen
    print("Starte wordpressformular")

    wpdb = connect_wp()
    print("Verbindung zu WordPress-Datenbank hergestellt:", wpdb)
    wpcursor = wpdb.cursor()
    print("WordPress-Datenbankcursor erstellt")

    wehdb = connect_weh()
    print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    # Check ob neue Anmeldung vorliegt
    wpcursor.execute("SELECT id FROM wp_frm_items WHERE form_id = 8")
    neu = wpcursor.fetchall()
    print("Neue Anmeldungen gefunden:", neu)
    
    for row in neu:
        id = int(row[0])
        print(f"Verarbeite Anmeldung mit ID: {id}")
        
        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 55 AND item_id = {}".format(id))
        room = wpcursor.fetchall()[0][0]
        print(f"Raum: {room}")

        # STW Übersetzung
        if room == 0:
            room = 2
        elif room == 12:
            room = 3
        elif room == 1:
            room = 1
        elif room == 11:
            room = 4
        print(f"Übersetzter Raum: {room}")
               
        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 57 AND item_id = {}".format(id))
        firstname = wpcursor.fetchall()[0][0]
        print(f"Vorname: {firstname}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 58 AND item_id = {}".format(id))
        lastname = wpcursor.fetchall()[0][0]
        print(f"Nachname: {lastname}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 56 AND item_id = {}".format(id))
        startdate = wpcursor.fetchall()[0][0]
        print(f"Einzugsdatum: {startdate}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 60 AND item_id = {}".format(id))
        geburtsort = wpcursor.fetchall()[0][0]
        print(f"Geburtsort: {geburtsort}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 63 AND item_id = {}".format(id))
        username = wpcursor.fetchall()[0][0]
        print(f"Benutzername: {username}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 61 AND item_id = {}".format(id))
        telephone_result = wpcursor.fetchall()
        if telephone_result:
            telefon = telephone_result[0][0]
        else:
            telefon = '' 
        print(f"Telefon: {telefon}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 62 AND item_id = {}".format(id))
        email = wpcursor.fetchall()[0][0]
        print(f"E-Mail: {email}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 59 AND item_id = {}".format(id))
        geburtstag = wpcursor.fetchall()[0][0]
        print(f"Geburtstag: {geburtstag}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 64 AND item_id = {}".format(id))
        if (wpcursor.fetchone() != None):
            forwardemail = '1'
        else:
            forwardemail = '0'
        print(f"Forward E-Mail: {forwardemail}")

        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 71 AND item_id = {}".format(id))
        result_sub = wpcursor.fetchone()[0]
        print(f"Sublet-Status: {result_sub}")

        if result_sub == "Untermieter":
            sublet = 1
            wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 72 AND item_id = {}".format(id))
            if wpcursor.fetchone() is not None:
                wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 72 AND item_id = {}".format(id))
                endofsublet = wpcursor.fetchall()[0][0]
            else:
                endofsublet = "!!!FEHLT!!!"
            print(f"Ende des Untermietverhältnisses: {endofsublet}")

        elif result_sub == "Hauptmieter":
            sublet = 0
            endofsublet = ''
            print("Hauptmieter")
        else:
            print("Fehler bei Übertragung Subletvalue")
            
        wpcursor.execute("SELECT meta_value FROM wp_frm_item_metas WHERE field_id = 75 AND item_id = {}".format(id))
        turm = wpcursor.fetchall()[0][0].lower()
        print(f"Turm: {turm}")

        status = 0
        agent = None
        kommentar = ''
        zeit = int(time.time())
        print(f"Zeitstempel: {zeit}")

        sql_check = "SELECT COUNT(*) FROM anmeldungen WHERE room = %s AND username = %s AND (status = 0 OR status = 2)"
        var_check = (room,username)
        wehcursor.execute(sql_check, var_check)
        record_count = wehcursor.fetchone()[0]
        print(f"Anzahl der bestehenden Anmeldungen für Raum {room}: {record_count}")

        if record_count < 1:  # No matching records found, so insert the data
            sql_insert = "INSERT INTO anmeldungen (room, firstname, lastname, startdate, geburtsort, username, email, geburtstag, telefon, status, kommentar, forwardemail, agent, sublet, subletterend, tstamp, turm) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
            var_insert = (room, firstname, lastname, startdate, geburtsort, username, email, geburtstag, telefon, status, kommentar, forwardemail, agent, sublet, endofsublet, zeit, turm)
            wehcursor.execute(sql_insert, var_insert)
            wehdb.commit()
            print("Neue Anmeldung in die Datenbank eingefügt")

            if room == 1504:
                fijimail()
                print("Fijimail gesendet")

            newanmeldungmail(startdate, room, firstname, lastname, turm)

        else:
            duplicateanmeldungmail(zeit, room, firstname, lastname, email)

        sql1 = "DELETE FROM wp_frm_item_metas WHERE item_id = {}".format(id)
        wpcursor.execute(sql1)
        sql2 = "DELETE FROM wp_frm_items WHERE id = {}".format(id)
        wpcursor.execute(sql2)
        wpdb.commit()
        print(f"Eintrag mit ID {id} aus WordPress-Datenbank gelöscht")

    wpdb.close()
    print("Verbindung zur WordPress-Datenbank geschlossen")

## Funktionen für Mails ##
        
def newanmeldungmail(zeit, room, firstname, lastname, turm): #sendet mail an uns, zur information über neue anmeldung
    subject = "Anmeldung " + str(turm) + "-" + str(room) + " - " + str(firstname) + " " + str(lastname)
    message = "Neue Anmeldung\nEinzugsdatum: " + str(zeit) + "\nName: " + str(firstname) + " " + str(lastname) + "\nTurm: " + str(turm) + "\nRaum: " + str(room) + "\n\nHier bestätigen: https://backend.weh.rwth-aachen.de/Anmeldung.php"
    to_email = "system@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)
    print(f"Benachrichtigungsmail gesendet: {subject} an {to_email}")
        
def duplicateanmeldungmail(zeit, room, firstname, lastname, email): #sendet mail an user, wenn doppelt registriert
    subject = "Anmeldung z" + str(room) + " - " + str(firstname) + " " + str(lastname)
    message = (
        f"Dear {firstname} {lastname},\n\n"
        f"We regret to inform you that your recent registration for room {room} has been declined, "
        f"as we have already received a prior registration from you that is currently under review.\n\n"
        f"If you need further assistance, it might be helpful to visit our network consultation hours. "
        f"For more information, please see the following link: https://www2.weh.rwth-aachen.de/ags/netzag/\n\n"
        f"We kindly ask for your patience as we process your registration.\n\n"
        f"Thank you for your understanding.\n\n"
        f"Best regards,\n"
        f"Netzwerk-AG WEH e.V."
    )
    to_email = email
    send_mail(subject, message, to_email)
    print(f"Benachrichtigungsmail gesendet: {subject} an {to_email}")

def fijimail(): # Top Secret
    sendto = "fiji@weh.rwth-aachen.de"
    subject = "Stellplatz frei!!!"
    text = "Stellplatz 30 ist frei geworden (neuer User hat sich für R1504 angemeldet), du hast bis zum CleanUsersCron Zeit dich in weh.fahrrad auf Stellplatz 30 zu setzen.\nUnd stell dein Fahrrad um!\n\nYippie!"
    send_mail(subject, text, sendto)
    print(f"Fijimail gesendet: {subject} an {sendto}")

wordpressformular()
