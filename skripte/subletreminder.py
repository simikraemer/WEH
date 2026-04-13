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

    # Abgelaufene Sublet-Paare direkt mit gleichem Raum UND gleichem Turm abfragen
    sql_pairs = """
        SELECT
            subletter.oldroom,
            subletter.turm,
            subletter.firstname,
            subletter.email,
            subletter.subletterend,
            sublet.firstname,
            sublet.email,
            sublet.subtenanttill
        FROM users AS subletter
        INNER JOIN users AS sublet
            ON subletter.oldroom = sublet.room
           AND subletter.turm = sublet.turm
        WHERE subletter.subletterend != 0
          AND subletter.subletterend < %s
          AND subletter.pid = 12
          AND subletter.oldroom != 0
          AND subletter.turm IN ('weh', 'tvk')
          AND sublet.subtenanttill != 0
          AND sublet.subtenanttill < %s
          AND sublet.pid = 11
          AND sublet.room != 0
          AND sublet.turm IN ('weh', 'tvk')
        ORDER BY subletter.turm, subletter.oldroom, GREATEST(subletter.subletterend, sublet.subtenanttill)
    """
    wehcursor.execute(sql_pairs, (current_time, current_time))
    rows = wehcursor.fetchall()

    # Verbindung schließen
    wehcursor.close()
    wehdb.close()
    print("Verbindung zur WEH-Datenbank geschlossen")

    pairs = []
    seen = set()

    for row in rows:
        room = row[0]
        turm = row[1]
        subletter_firstname = (row[2] or "").split()[0] if row[2] else ""
        subletter_email = (row[3] or "").strip()
        subletter_end = row[4]
        sublet_firstname = (row[5] or "").split()[0] if row[5] else ""
        sublet_email = (row[6] or "").strip()
        sublet_till = row[7]

        # Ende des längeren Zeitraums
        end_time = max(subletter_end, sublet_till)

        # Leere Mailadressen überspringen
        if not subletter_email or not sublet_email:
            print(
                f"Überspringe Paar wegen fehlender Mailadresse: "
                f"turm={turm}, room={room}, subletter_email='{subletter_email}', sublet_email='{sublet_email}'"
            )
            continue

        # Doppelte Paare vermeiden
        pair_key = (turm, room, subletter_email.lower(), sublet_email.lower(), end_time)
        if pair_key in seen:
            print(f"Überspringe doppeltes Paar: {pair_key}")
            continue
        seen.add(pair_key)

        pair = {
            "subletter_email": subletter_email,
            "sublet_email": sublet_email,
            "subletter_firstname": subletter_firstname,
            "sublet_firstname": sublet_firstname,
            "room": room,
            "turm": turm,
            "end_time": end_time
        }
        pairs.append(pair)
        print(f"Paar erstellt: {pair}")

    # E-Mails verschicken
    for pair in pairs:
        print(
            f"Vorbereitet zum Senden einer E-Mail an: "
            f"{pair['subletter_email']} und {pair['sublet_email']}"
        )
        print(
            f"Vornamen: {pair['subletter_firstname']} und {pair['sublet_firstname']}"
        )
        print(
            f"Turm: {pair['turm']}, Zimmer: {pair['room']}, "
            f"Endzeit: {pair['end_time']} (Unix-Timestamp)"
        )
        mail(
            to_emails=[pair["subletter_email"], pair["sublet_email"]],
            firstnames=[pair["subletter_firstname"], pair["sublet_firstname"]],
            room=pair["room"],
            turm=pair["turm"],
            end_time=pair["end_time"]
        )


def mail(to_emails, firstnames, room, turm, end_time):
    subject = "Current State of Your Sublet"
    firstname1, firstname2 = firstnames

    # Endzeitpunkt in deutsches Datumsformat umwandeln
    end_date = datetime.fromtimestamp(end_time).strftime("%d.%m.%Y")

    message = (
        f"Hello {firstname1} and {firstname2},\n\n"
        f"We have noticed that the subletting period for room {room} in {turm.upper()} ended on {end_date}.\n\n"
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