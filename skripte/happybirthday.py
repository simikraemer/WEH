# Geschrieben von Fiji
# Mai 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail
from fcol import connect_weh
from fcol import connect_wasch

## Grundprogramm ##

def happybirthday():
    print("Starte happybirthday")
    today = datetime.now()
    current_month = today.month
    current_day = today.day
    print(f"Heutiges Datum: {today}, Monat: {current_month}, Tag: {current_day}")
    
    # Verbindung zur Wasch-Datenbank
    waschdb = connect_wasch()
    print("Verbindung zur Wasch-Datenbank hergestellt.")
    waschcursor = waschdb.cursor()
    print("Wasch-Datenbankcursor erstellt")

    print("Starte Abruf der Wasch-User mit status = 1...")
    waschcursor.execute("SELECT uid FROM waschusers WHERE status = 1")
    valid_uids = [row[0] for row in waschcursor.fetchall()]  # Direkt als int speichern

    waschcursor.close()
    waschdb.close()

    # Verbindung zur WEH-Datenbank
    wehdb = connect_weh()
    print("Verbindung zur WEH-Datenbank hergestellt.")
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    if valid_uids:  # Falls es gültige UIDs gibt
        placeholders = ', '.join(['%s'] * len(valid_uids))  # Platzhalter für Parameter
        sql = f"""
            SELECT username, geburtstag, firstname, uid, turm 
            FROM users 
            WHERE pid = 11 AND uid IN ({placeholders})
        """
        print("Führe SQL-Abfrage in der WEH-Datenbank aus.")
        #print("Führe SQL-Abfrage in der WEH-Datenbank aus mit UIDs:", valid_uids)

        wehcursor.execute(sql, valid_uids)  # Parameterisierte Query für Sicherheit
        results = wehcursor.fetchall()

        print(f"Anzahl der gefundenen Benutzer in WEH-DB: {len(results)}")
    else:
        print("Keine gültigen UIDs gefunden. Es werden keine Benutzer aus der WEH-DB abgerufen.")
        results = []

    # Endgültige Benutzerliste ausgeben
    #print("Benutzer gefunden:", results)

    for row in results:
        username, geburtstag, firstname, uid, turm = row

        if geburtstag:
            birthday = datetime.fromtimestamp(geburtstag)
            #print(f"Überprüfe Geburtstag für Benutzer: {username}, Geburtstag: {birthday}")

            if birthday.month == current_month and birthday.day == current_day:
                print(f"🎉 Benutzer {username} hat heute Geburtstag!")

                try:
#                    mail(firstname, username, turm)
                    print(f"✅ Geburtstagsmail an {username} gesendet.")
                except Exception as e:
                    print(f"⚠️ Fehler beim Senden der Mail an {username}: {e}")

                try:
#                    waschmarke(uid)
                    print(f"✅ Waschmarke für {username} vergeben.")
                except Exception as e:
                    print(f"⚠️ Fehler beim Setzen der Waschmarke für {username}: {e}")
        else:
            print(f"⚠️ Kein gültiges Geburtsdatum für {username} gefunden.")

    wehcursor.close()
    wehdb.close()
    print("Verbindung zur WEH-Datenbank geschlossen")

def mail(firstname, username, turm):
    subject = "Happy Birthday!"
    message = ("Hello " + str(firstname) + ",\n\n" +
               "It's our pleasure to let you know that a washing token has been gifted to you on your special day!\n" +
               "We hope this small gift adds a touch of convenience to your celebrations.\n\n" +
               "Everyone here at WEH sends their best wishes for a birthday that's as wonderful as you are!\n\n" +
               "Warmest regards,\n" +
               "Walter Eilender Haus")
    to_email = str(username) + "@" + str(turm) + ".rwth-aachen.de"
    print(f"Sende Geburtstagsmail an: {to_email}")
    send_mail(subject, message, to_email)
    print(f"Geburtstagsmail gesendet an: {to_email}")

def waschmarke(uid):
    zeit = int(time.time())
    print(f"Füge Waschmarke hinzu für Benutzer-ID: {uid} zur Zeit: {zeit}")

    wehdb = connect_wasch()
    print("Verbindung zur Wasch-Datenbank hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("Wasch-Datenbankcursor erstellt")

    try:
        sql1 = "INSERT INTO transfers (von_uid, nach_uid, anzahl, time) VALUES (-6, %s, 1, %s)"
        params1 = (uid, zeit)
        wehcursor.execute(sql1, params1)
        print(f"Transfer in transfers Tabelle eingefügt für Benutzer-ID: {uid}")

        sql2 = "UPDATE waschusers SET waschmarken = waschmarken + 1 WHERE uid = %s"
        params2 = (uid,)
        wehcursor.execute(sql2, params2)
        print(f"Waschmarken für Benutzer-ID: {uid} aktualisiert")

        wehdb.commit()
        print("Änderungen in der Wasch-Datenbank bestätigt")

    except Exception as e:
        wehdb.rollback()
        print("Ein Fehler ist aufgetreten: " + str(e))

    finally:
        wehcursor.close()
        wehdb.close()
        print("Verbindung zur Wasch-Datenbank geschlossen")

happybirthday()
