# Geschrieben von Fiji
# April 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

# Dieses Skript soll regelmäßig (täglich, wöchentlich) aufgerufen werden.
# Es überprüft ob ein User, weniger als die aktuellen Mitgliedsbeiträge auf dem Konto hat und schickt eine Warnmail raus.

import time
from fcol import send_mail
from fcol import connect_weh
from fcol import get_constant

## Grundprogramm ##

def abrechnung(): 
    print("Abrechnung gestartet")
    
    zeit = int(time.time()) #UNIX Time
    print(f"Aktuelle Zeit: {zeit}")
    
    vornerwoche = zeit - (1 * 7 * 24 * 60 * 60)
    print(f"Zeit vor einer Woche: {vornerwoche}")
    
    hausbeitrag = get_constant("hausbeitrag")
    print(f"Hausbeitrag: {hausbeitrag}")
    
    netzbeitrag = get_constant("netzbeitrag")
    print(f"Netzbeitrag: {netzbeitrag}")
    
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    
    wehcursor.execute("SELECT uid, groups, honory, pid, name, username, turm FROM users WHERE pid IN (11,12) AND starttime < %s AND (endtime = 0 OR endtime >= %s) AND uid NOT IN (SELECT uid FROM sperre WHERE missedpayment = 1 AND starttime <= %s AND endtime >= %s)", (vornerwoche, zeit, zeit, zeit))
    bewohner = wehcursor.fetchall()
    
    print(f"Anzahl der gefundenen Bewohner: {len(bewohner)}")
    
    emails_to_send = []
    for row in bewohner:
        print("")
        uid = row[0]
        groups = row[1]
        honory = row[2]
        pid = row[3]
        name = row[4]
        username = row[5]
        turm = row[6]
        
        print(f"Verarbeite Bewohner: UID={uid}, Name={name}, Gruppen={groups}")
        
        # Wandele groups in eine Liste von Zahlen um
        if isinstance(groups, str):
            groups = [int(g) if g.isdigit() else g for g in groups.split(",")]
        elif isinstance(groups, int):
            groups = [groups]

        print(f"Gruppen nach Umwandlung: {groups}")
        
        nichtaktiv = all(g == 1 or g in [1, 19] for g in groups)
        print(f"Nichtaktiv Status: {nichtaktiv}")
        
        monatsbeitrag = 0
        
        if 7 not in groups and pid == 11:
            monatsbeitrag += netzbeitrag
            print(f"Netzbeitrag hinzugefügt: {monatsbeitrag}")
            
        if honory == 0 and nichtaktiv:
            monatsbeitrag += hausbeitrag
            print(f"Hausbeitrag hinzugefügt: {monatsbeitrag}")

        rest = get_rest(uid)
        
        print(f"Aktueller Kontostand für UID {uid}: {rest}")
        
        if rest is None:
            rest = 0
            
        restcheck = rest + 0.01
        print(f"Restcheck: {restcheck}, Monatsbeitrag: {monatsbeitrag}")

        if restcheck < monatsbeitrag:
            print(f"Warnmail an {name} wird gesendet.")
            warnmail(uid, rest, monatsbeitrag, name, username, turm)
            
    wehdb.close()
    print("Abrechnung beendet.")
    
## Funktionen für Mails ##
    
def warnmail(uid, rest, monatsbeitrag, name, username, turm):
    missing = monatsbeitrag - rest
    
    print(f"Erstelle Warnmail für UID {uid}: Fehlender Betrag: {missing}")
    
    subject = "[Important] WEH Account Warning"
    message = (
        "Dear " + str(name) + ",\n\n" +
        "if you have already moved out of WEH, please ignore this email.\n\n" +
        "We hereby inform you that your membership account balance is currently insufficient " +
        "to extend your Internet access for the upcoming accounting period at the end of the month.\n" +
        "In order to ensure uninterrupted access, please ensure that your account balance covers " +
        "the membership fees for the upcoming month.\n\n" +
        "Your monthly membership fees are: " + "{:.2f}".format(monatsbeitrag) + "€\n" +
        "Your current account balance is: " + "{:.2f}".format(rest) + "€\n" +
        "To pay for your internet connection in the coming month, there are still " + "{:.2f}".format(missing) + "€ missing.\n\n" +
        "You can review your account balance and top it up by visiting the following link:\n" +
        "https://backend.weh.rwth-aachen.de/UserKonto.php\n\n" +
        "If you are currently out of Aachen, you can transfer money to our bank account:\n" +
        "Name: WEH e.V.\n" +
        "IBAN: DE90 3905 0000 1070 3346 00\n" +
        "Transfer Reference: W"+str(uid)+"H\n" +
        "Please make sure to use this exact survey, so we can assign the transfer to your account.\n\n" +
        "If you have any questions or concerns, feel free to contact us.\n\n" +
        "Best regards,\nNetzwerk-AG WEH e.V."
    )     
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"
    print(f"Warnmail wird gesendet an: {to_email}")
    send_mail(subject, message, to_email)

## Funktionen zur Abrechnung ##        

def get_rest(uid):
    print(f"Hole Kontostand für UID: {uid}")
    fedb = connect_weh()
    fecursor = fedb.cursor()
    sql = "SELECT SUM(betrag) FROM transfers WHERE uid = %s"
    var = (uid,)
    fecursor.execute(sql, var)
    summe = fecursor.fetchone()[0]
    print(f"Kontostand für UID {uid}: {summe}")
    return summe

abrechnung()
