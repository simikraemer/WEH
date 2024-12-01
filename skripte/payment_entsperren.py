# Geschrieben von Fiji
# Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

# Dieses Skript soll regelmäßig (mindestens stündlich) aufgerufen werden.
# Es überprüft ob ein User, der aufgrund von fehlendem Guthaben gesperrt wurde, inszwischen genug Geld auf dem Mitgliedskonto hat und entsperrt diesen.

import time
from fcol import send_mail
from fcol import connect_weh

## Grundprogramm ##

def checkpayment(): 
    
    # Konstanten ziehen
    zeit = int(time.time()) #UNIX Time

    wehdb = connect_weh()
    wehcursor = wehdb.cursor()

    # Bewohner
    sql = ("SELECT DISTINCT users.uid FROM users JOIN sperre ON users.uid = sperre.uid WHERE sperre.missedpayment = 1 AND sperre.starttime <= %s AND sperre.endtime >= %s")
    var = (zeit, zeit)
    wehcursor.execute(sql, var)
    bewohner = wehcursor.fetchall()
    for row in bewohner:
        uid = row[0]
        rest = get_rest(uid)
        restcheck = rest + 0.01
        if restcheck > 0:
            entsperren(uid,zeit)
            entsperrmail(uid)

    wehdb.close()

## Funktionen für Mails ##
    
def entsperrmail(uid):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT name, username, turm FROM users WHERE uid = %s"
    var = (uid,)
    wehcursor.execute(sql, var)
    result = wehcursor.fetchone()
    name = result[0] if result else None
    username = result[1] if result else None
    turm = result[2] if result else None
    
    subject = "WEH Account Reactivation"
    message = "Dear " + str(name) + ","\
    "\n\nyour access to WEH services was reactivated."\
    "\n\nBest Regards,\nNetzwerk-AG WEH e.V."
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"
    send_mail(subject, message, to_email)

## Funktionen zur Abrechnung ##        
    
def get_rest(uid):
    time.sleep(2) # Damit alle Zahlungen drin sind, bevor man summiert
    fedb = connect_weh()
    fecursor = fedb.cursor()
    sql = "SELECT SUM(betrag) FROM transfers WHERE uid = %s"
    var = (uid,)
    fecursor.execute(sql, var)
    summe = fecursor.fetchone()[0]
    return summe

def entsperren(uid,zeit):
    fedb = connect_weh()
    fecursor = fedb.cursor()
    update_sql = "UPDATE sperre SET endtime = %s WHERE uid = %s AND missedpayment = 1 AND starttime <= %s AND endtime >= %s"
    update_var = (zeit, uid, zeit, zeit)
    fecursor.execute(update_sql, update_var)
    fedb.commit()
    

checkpayment()