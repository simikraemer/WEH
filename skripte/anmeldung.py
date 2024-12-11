#!/usr/bin/env python
# -*- coding: utf-8 -*-

# Geschrieben von Fiji
# Dezember 2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail
from fcol import connect_weh
from fcol import connect_anmeldung

## Grundfunktion

def anmeldung_übertragen():  # Überträgt neue Anmeldungen von WordPress in anmeldungen
    print("Starte WordPress-Formular")

    # Verbindung zu den Datenbanken herstellen
    www2db = connect_anmeldung()
    print("Verbindung zu WordPress-Datenbank hergestellt:", www2db)
    wpcursor = www2db.cursor()
    print("WordPress-Datenbankcursor erstellt")

    wehdb = connect_weh()
    print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    try:
        # Alle Einträge aus der WordPress-Tabelle 'anmeldungen' abrufen
        wpcursor.execute("SELECT tstamp, starttime, sublet, subletterend, username, room, turm, firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail FROM anmeldungen")
        anmeldungen = wpcursor.fetchall()
        print(f"{len(anmeldungen)} Anmeldungen aus WordPress-Datenbank abgerufen.")

        for anmeldung in anmeldungen:
            (
                tstamp, starttime, sublet, subletterend, username, room, turm,
                firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail
            ) = anmeldung

            print(f"Übertrage Anmeldung für {firstname} {lastname}, Raum {room} ({turm})")

            # Eintrag in die WEH-Datenbank einfügen
            try:
                wehcursor.execute("""
                    INSERT INTO registration (tstamp, starttime, sublet, subletterend, username, room, turm, firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """, (
                    tstamp, starttime, sublet, subletterend, username, room, turm,
                    firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail
                ))
                wehdb.commit()
                print(f"Anmeldung erfolgreich übertragen: {firstname} {lastname}, Raum {room}")

                # Benachrichtigungsmail senden
                newanmeldungmail(
                    zeit=starttime, room=room, firstname=firstname,
                    lastname=lastname, turm=turm
                )
                print(f"Benachrichtigung gesendet für {firstname} {lastname}.")

                # Eintrag aus WordPress-Tabelle löschen
                wpcursor.execute("DELETE FROM anmeldungen WHERE tstamp = %s", (tstamp,))
                www2db.commit()
                print(f"Eintrag aus WordPress-Datenbank gelöscht: {firstname} {lastname}, Raum {room}")

            except Exception as e:
                print(f"Fehler beim Übertragen oder Löschen der Anmeldung: {e}")
                wehdb.rollback()

            # Wartezeit zwischen den Iterationen
            print("Warte 1 Sekunde, bevor der nächste Eintrag verarbeitet wird...")
            time.sleep(1)

    except Exception as e:
        print(f"Fehler beim Abrufen der Anmeldungen: {e}")

    finally:
        # Verbindungen schließen
        www2db.close()
        print("Verbindung zur WordPress-Datenbank geschlossen")

        wehdb.close()
        print("Verbindung zur WEH-Datenbank geschlossen")

## Funktionen für Mails ##

def newanmeldungmail(zeit, room, firstname, lastname, turm):  # Sendet Mail an uns zur Information über neue Anmeldung
    subject = "Anmeldung " + str(turm) + "-" + str(room) + " - " + str(firstname) + " " + str(lastname)
    message = (
        f"Neue Anmeldung\nEinzugsdatum: {zeit}\n"
        f"Name: {firstname} {lastname}\n"
        f"Turm: {turm}\nRaum: {room}\n\n"
        "Hier bestätigen: https://backend.weh.rwth-aachen.de/Anmeldung.php"
    )
    to_email = "system@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)
    print(f"Benachrichtigungsmail gesendet: {subject} an {to_email}")

anmeldung_übertragen()
