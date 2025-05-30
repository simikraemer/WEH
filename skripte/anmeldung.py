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
    print("[INFO] Starte Übertragung von Anmeldungen...")

    # Verbindung zu den Datenbanken herstellen
    www2db = connect_anmeldung()
    wpcursor = www2db.cursor()
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()

    try:
        # Alle Einträge aus der WordPress-Tabelle 'anmeldungen' abrufen
        wpcursor.execute("SELECT tstamp, starttime, sublet, subletterend, username, room, turm, firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail FROM anmeldungen")
        anmeldungen = wpcursor.fetchall()        
        print(f"[INFO] {len(anmeldungen)} neue Anmeldungen gefunden.")

        for anmeldung in anmeldungen:
            (
                tstamp, starttime, sublet, subletterend, username, room, turm,
                firstname, lastname, geburtstag, geburtsort, telefon, email, forwardemail
            ) = anmeldung

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
                print(f"[✓] Anmeldung übertragen: {firstname} {lastname}, Raum {room} ({turm})")

                # Benachrichtigungsmail senden
                newanmeldungmail(
                    zeit=starttime, room=room, firstname=firstname,
                    lastname=lastname, turm=turm
                )

                # Eintrag aus WordPress-Tabelle löschen
                wpcursor.execute("DELETE FROM anmeldungen WHERE tstamp = %s", (tstamp,))
                www2db.commit()

            except Exception as e:
                print(f"[FEHLER] Übertragung fehlgeschlagen für {firstname} {lastname}: {e}")
                wehdb.rollback()

            # Wartezeit zwischen den Iterationen
            print("Warte 1 Sekunde, bevor der nächste Eintrag verarbeitet wird...")
            time.sleep(1)

    except Exception as e:
        print(f"[FEHLER] Fehler beim Abrufen der Anmeldungen: {e}")

    finally:
        # Verbindungen schließen
        www2db.close()
        wehdb.close()
        print("[INFO] Übertragung abgeschlossen, Datenbankverbindungen geschlossen.")

## Funktionen für Mails ##

def newanmeldungmail(zeit, room, firstname, lastname, turm):  # Sendet Mail an uns zur Information über neue Anmeldung
    subject = "Anmeldung " + str(turm) + "-" + str(room) + " - " + str(firstname) + " " + str(lastname)
    formatted_date = datetime.fromtimestamp(zeit).strftime('%d.%m.%Y')
    message = (
        f"Neue Anmeldung\nEinzugsdatum: {formatted_date}\n"
        f"Name: {firstname} {lastname}\n"
        f"Turm: {turm}\nRaum: {room}\n\n"
        "Hier bestätigen: https://backend.weh.rwth-aachen.de/Dashboard.php"
    )
    to_email = "system@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)

anmeldung_übertragen()
