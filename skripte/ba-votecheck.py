# Geschrieben von Fiji
# September 2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from fcol import send_mail, connect_weh

def votecheck():
    zeit = int(time.time())
    print("Starte votecheck")
    wehdb = connect_weh()

    print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    # SQL-Abfrage, um alle abgelaufenen Votes mit Ergebnissen zu finden und die Ergebnisse zu summieren
    sql_votes = """
    SELECT p.id, p.uid, p.room, v.kandidat, SUM(v.count) as total_points 
    FROM bapolls p 
    JOIN bavotes v ON p.id = v.pollid 
    WHERE p.beendet = 0 AND p.endtime < %s 
    GROUP BY p.id, v.kandidat
    """
    wehcursor.execute(sql_votes, (zeit,))
    results = wehcursor.fetchall()
    print("Gefundene Votes mit Ergebnissen:", results)

    # Liste der Polls, die bereits verarbeitet wurden
    processed_polls = set()
    
    current_poll_id = None
    poll_results = []

    for row in results:
        poll_id = row[0]
        uid = row[1]
        room = row[2]
        kandidat = row[3]
        total_points = row[4]

        if current_poll_id is None or current_poll_id != poll_id:
            # Wenn wir zu einem neuen Poll wechseln, die Ergebnisse für den vorherigen Poll verarbeiten
            if current_poll_id is not None:
                # Vote beendet und mail senden
                mail(room)
                # Setze beendet auf 1 für den aktuellen Poll
                update_sql = "UPDATE bapolls SET beendet = 1 WHERE id = %s"
                wehcursor.execute(update_sql, (current_poll_id,))
                wehdb.commit()

                processed_polls.add(current_poll_id)

            # Aktuelle Poll-ID aktualisieren
            current_poll_id = poll_id
            poll_results = []  # Liste zurücksetzen

        poll_results.append((kandidat, total_points))
        print(f"Poll ID: {poll_id}, Room: {room}, Kandidat: {kandidat}, Total Points: {total_points}")

    # Verarbeite den letzten Poll in der Schleife
    if current_poll_id is not None:
        mail(room)
        update_sql = "UPDATE bapolls SET beendet = 1 WHERE id = %s"
        wehcursor.execute(update_sql, (current_poll_id,))
        wehdb.commit()
        processed_polls.add(current_poll_id)

    # SQL-Abfrage, um alle abgelaufenen Votes ohne Stimmen zu finden
    sql_no_votes = """
    SELECT p.id, p.room
    FROM bapolls p 
    WHERE p.beendet = 0 AND p.endtime < %s AND p.id NOT IN (
        SELECT pollid FROM bavotes
    )
    """
    wehcursor.execute(sql_no_votes, (zeit,))
    no_vote_results = wehcursor.fetchall()
    print("Gefundene Votes ohne Ergebnisse:", no_vote_results)

    for row in no_vote_results:
        poll_id = row[0]
        room = row[1]
        print(f"Poll ID: {poll_id}, Room: {room} hat keine Stimmen erhalten.")
        
        # Benachrichtigung bei fehlenden Votes
        mail_no_votes(room)
        
        # Setze beendet auf 1 für den Poll ohne Votes
        update_sql = "UPDATE bapolls SET beendet = 1 WHERE id = %s"
        wehcursor.execute(update_sql, (poll_id,))
        wehdb.commit()

    wehcursor.close()
    wehdb.close()
    print("Verbindung zur WEH-Datenbank geschlossen")

def mail(raum):
    subject = "[BA] Raum " + str(raum) + " - Ergebnisse"
    message = (
        "Hallo BA,\n\n" +
        "der Vote für die Belegung von Raum " + str(raum) + " wurde beendet.\n\n" +
        "Die Ergebnisse können hier eingesehen werden:\nhttps://backend.weh.rwth-aachen.de/BA-Administration.php\n\n" +
        "Viele Grüße,\nba-votecheck.py"
    )
    to_email = "ba@weh.rwth-aachen.de"
    #to_email = "fiji@weh.rwth-aachen.de"
    print(f"Sende Mail an: {to_email}")
    send_mail(subject, message, to_email)
    print(f"Mail gesendet an: {to_email}")

def mail_no_votes(raum):
    subject = "[BA] " + str(raum) + " - Keine Stimmen eingegangen"
    message = (
        "Hallo BA,\n\n" +
        "der Vote für die Belegung von Raum " + str(raum) + " ist abgelaufen,\n" +
        "aber es sind keine Stimmen eingegangen.\n\n" +
        "Hier die Übersicht:\nhttps://backend.weh.rwth-aachen.de/BA-Administration.php\n\n" +
        "Viele Grüße,\nba-votecheck.py"
    )
    to_email = "ba@weh.rwth-aachen.de"
    #to_email = "fiji@weh.rwth-aachen.de"
    print(f"Sende Mail an: {to_email}")
    send_mail(subject, message, to_email)
    print(f"Mail gesendet an: {to_email}")

if __name__ == "__main__":
    votecheck()
