# Geschrieben von Fiji
# Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from fcol import send_mail
from fcol import connect_weh
import datetime

## Grundprogramm ##

def streichung(): 

    # Wie spät haben wir's?
    zeit = int(time.time()) #UNIX Time
    zeit_vor6monaten = zeit - (6 * 31 * 24 * 60 * 60)
    
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    
    alle_namen = []

    wehcursor.execute("SELECT uid, insolvent, name, oldroom, turm FROM users WHERE pid = 13 AND insolvent > 0 ORDER BY FIELD(turm, 'weh', 'tvk'), oldroom")
    insolvente_bewohner = wehcursor.fetchall()
    
    print(f"Anzahl insolventer Bewohner: {len(insolvente_bewohner)}")

    for row in insolvente_bewohner:
        uid = row[0]
        insolvent = row[1] # UNIX Time, wann User kein Geld mehr auf dem Konto hatte
        name = row[2]
        oldroom = row[3]
        turm = row[4]
        
        print(f"Bearbeite User: UID={uid}, Insolvent={insolvent}, Name={name}, Oldroom={oldroom}, Turm={turm}")

        if insolvent < zeit_vor6monaten and insolvent != 0:
            turm_formatted = 'TvK' if turm == 'tvk' else turm.upper()
            nicename = f"{name} ({turm_formatted} {oldroom})"
            alle_namen.append(nicename)
            print(f"User {uid} wurde zur Streichung hinzugefügt: {nicename}")
            users_rauswurf(zeit, uid)
        else:
            print(f"User {uid} erfüllt nicht die Streichungskriterien.")

    if alle_namen:  # Liste nicht leer
        print("Sende Infomail mit folgenden Namen:")
        for name in alle_namen:
            print(f"  - {name}")
        infomail(alle_namen)
    else:
        print("Keine Benutzer zur Streichung gefunden.")
    
    wehdb.close()

## Funktionen für Mails ##
     
def infomail(alle_namen):
    subject = "WEH Streichung"
    message = ("Hallo Vorstand,\n\n"
               "jedes Quartal wird eine automatisierte Streichung von ausgezogenen Mitgliedern durchgeführt.\n"
               "Betroffen davon sind User, die zum Zeitpunkt dieser E-Mail vor 6 Monaten insolvent geworden sind.\n"
               "Grundlage dazu ist §9.3 unserer Satzung:\n"
               "'Die Streichung eines außerordentlichen Mitgliedes ist ohne Mahnung zulässig, wenn es mit der Zahlung des Mitgliedsbeitrages mehr als 6 Monaten [sic!] in Verzug ist.'\n"
               "Bedeutet alle diese Mitglieder haben seit mindestens 6 Monaten ein leeres Mitgliedskonto, wohnen nicht mehr hier und können gestrichen werden.\n\n"
               "Auf der nächsten Vollversammlung/Haussenat müsst ihr nach §9 der Satzung kurz darüber abstimmen lassen, dass all diese Leute gestrichen werden.\n"
               "Die Namensliste könnt ihr im Protokoll ergänzen.\n\n"
               "Format: Name (Ehemaliger Raum)\n\n"
                + '\n'.join(alle_namen) +  # Fügt die Namen aus dem Array als einzelne Zeilen hinzu
               "\n\n"
               "Viele Grüße,\nstreichung.py\ni.A. Netzwerk-AG WEH e.V.")
    to_email = "vorstand@weh.rwth-aachen.de"
    print(f"Sende E-Mail an: {to_email}\nBetreff: {subject}\nNachricht:\n{message}\n")
    send_mail(subject, message, to_email)

## Funktionen zur Abmeldung ##

def users_rauswurf(endtime, uid): # users abmeldung
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
    
    update_sql = "UPDATE users SET pid = 14, endtime = %s, historie = %s WHERE uid = %s"
    update_var = (endtime, historie, uid)
    fecursor.execute(update_sql, update_var)
    fedb.commit()
    print(f"User {uid} wurde erfolgreich abgemeldet. Historie aktualisiert.")

streichung()
