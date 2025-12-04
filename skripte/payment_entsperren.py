# Geschrieben von Fiji
# Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

# Aus Effizienz und Swag Gründen neu strukturiert von Fiji
# April 2025

# Dieses Skript soll regelmäßig (mindestens stündlich) aufgerufen werden.
# Es überprüft ob ein User, der aufgrund von fehlendem Guthaben gesperrt wurde, inzwischen genug Geld auf dem Mitgliedskonto hat und entsperrt diesen.

import time
from fcol import send_mail
from fcol import connect_weh

DEBUG = False

def checkpayment(DEBUG=True):
    zeit = int(time.time())

    db = connect_weh()
    cursor = db.cursor()

    cursor.execute("""
        SELECT DISTINCT u.uid, u.name, u.username, u.turm
        FROM users u
        JOIN sperre s ON u.uid = s.uid
        WHERE s.missedpayment = 1 AND s.endtime >= %s
    """, (zeit,))
    bewohner = cursor.fetchall()
    if DEBUG:
        print(f"[DEBUG] Gefundene Bewohner: {len(bewohner)}")
    else:
        print(f"[INFO] Bewohner mit verpassten Zahlungen: {len(bewohner)}")

    if not bewohner:
        db.close()
        return

    uid_list = tuple(row[0] for row in bewohner)
    cursor.execute(f"""
        SELECT uid, SUM(betrag) as summe
        FROM transfers
        WHERE uid IN {uid_list}
        GROUP BY uid
    """)
    guthaben_map = {uid: summe or 0.0 for uid, summe in cursor.fetchall()}

    entsperrt = 0

    for uid, name, username, turm in bewohner:
        rest = guthaben_map.get(uid, 0.0)
        if DEBUG:
            print(f"[DEBUG] Prüfe UID {uid} - Name: {name}, Guthaben: {rest:.2f}€")
        if rest + 0.01 > 0:
            if DEBUG:
                print(f"[DEBUG] → Entsperren vorbereitet: UID {uid}")
            else:
                print(f"[✓] Entsperrt: {name} (UID {uid}) - Guthaben: {rest:.2f}€")

            if not DEBUG:
                cursor.execute("""
                    UPDATE sperre SET endtime = %s
                    WHERE uid = %s AND missedpayment = 1 AND endtime >= %s
                """, (zeit, uid, zeit))
                db.commit()

                to_email = f"{username}@{turm}.rwth-aachen.de"
                subject = "WEH Account Reactivation"
                message = f"Dear {name},\n\nyour access to WEH services was reactivated.\n\nBest Regards,\nNetzwerk-AG WEH e.V."
                send_mail(subject, message, to_email)

            entsperrt += 1
        else:
            print(f"[✗] Noch gesperrt: {name} (UID {uid}) - Guthaben: {rest:.2f}€")

    db.close()
    print(f"[INFO] Vorgang abgeschlossen - {entsperrt} Nutzer entsperrt.")

checkpayment(DEBUG)
