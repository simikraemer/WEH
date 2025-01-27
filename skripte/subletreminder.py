# Geschrieben von Fiji
# Mai 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail
from fcol import connect_weh

## Grundprogramm ##

def subletreminder():
    print("Starte Subletreminder")
    today = datetime.now()
    current_time = int(today.timestamp())  # Aktuelle Zeit in Unix-Timestamp
    print(f"Heutige Zeit: {current_time} (Unix-Timestamp)")

    wehdb = connect_weh()  # Verbindung zur Datenbank herstellen
    print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    # Subletters abfragen, deren Zeitraum abgelaufen ist
    sql_subletters = """
        SELECT oldroom, firstname, email, subletterend 
        FROM users 
        WHERE subletterend != 0 AND subletterend < %s AND pid = 12 
        ORDER BY subletterend
    """
    wehcursor.execute(sql_subletters, (current_time,))
    subletters = wehcursor.fetchall()

    # Sublets abfragen, deren Zeitraum abgelaufen ist
    sql_sublets = """
        SELECT room, firstname, email, subtenanttill 
        FROM users 
        WHERE subtenanttill != 0 AND subtenanttill < %s AND pid = 11 AND room != 0 
        ORDER BY subtenanttill
    """
    wehcursor.execute(sql_sublets, (current_time,))
    sublets = wehcursor.fetchall()

    # Verbindung schließen
    wehcursor.close()
    wehdb.close()
    print("Verbindung zur WEH-Datenbank geschlossen")

    # Paare bilden (oldroom = room)
    pairs = []
    for subletter in subletters:
        for sublet in sublets:
            if subletter[0] == sublet[0]:  # Vergleich: oldroom == room
                pair = {
                    "subletter_email": subletter[2],
                    "sublet_email": sublet[2],
                    "subletter_firstname": subletter[1].split()[0],  # Nur der erste Vorname
                    "sublet_firstname": sublet[1].split()[0],  # Nur der erste Vorname
                    "room": subletter[0],
                    "end_time": max(subletter[3], sublet[3])  # Ende des längeren Zeitraums
                }
                pairs.append(pair)
                print(f"Paar erstellt: {pair}")

    # E-Mails verschicken
    for pair in pairs:
        print(f"Vorbereitet zum Senden einer E-Mail an: {pair['subletter_email']} und {pair['sublet_email']}")
        print(f"Vornamen: {pair['subletter_firstname']} und {pair['sublet_firstname']}")
        print(f"Zimmer: {pair['room']}, Endzeit: {pair['end_time']} (Unix-Timestamp)")
        mail(
            to_emails=[pair["subletter_email"], pair["sublet_email"]],
            firstnames=[pair["subletter_firstname"], pair["sublet_firstname"]],
            room=pair["room"],
            end_time=pair["end_time"]
        )


def mail(to_emails, firstnames, room, end_time):
    subject = "Current State of Your Sublet"
    firstname1, firstname2 = firstnames

    # Endzeitpunkt in deutsches Datumsformat umwandeln
    end_date = datetime.fromtimestamp(end_time).strftime("%d.%m.%Y")

    message = (
        f"Hello {firstname1} and {firstname2},\n\n"
        f"We have noticed that the subletting period for room {room} ended on {end_date}.\n\n"
        "If the subletter has already returned, we kindly ask them to contact the NetzAG to have their account reactivated. "
        "This ensures uninterrupted access to the network for the room.\n\n"
        "If the subtenant has moved to a different room, it is important that this information is reported to the NetzAG as soon as possible. "
        "Failure to do so could result in the loss of network access.\n\n"
        "Should you have any questions or require assistance regarding this process, please do not hesitate to reach out to us. "
        "We are here to help both of you ensure a smooth transition and address any concerns you may have.\n\n"
        "Best Regards,\n"
        "Netzwerk-AG"
    )


    # E-Mail an jede Adresse einzeln senden
    for email in to_emails:
        print(f"Sending reminder email to: {email}")
        send_mail(subject, message, email)  # Einzelne E-Mail-Adresse direkt übergeben
        print(f"Reminder email successfully sent to: {email}")


subletreminder()