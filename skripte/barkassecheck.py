# Geschrieben von Fiji
# April 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

from fcol import send_mail
from fcol import connect_weh

## Grundprogramm ##

def check():
    select_db = connect_weh()
    select_cursor = select_db.cursor()
    sql = "SELECT SUM(betrag) FROM barkasse WHERE kasse = 1"
    select_cursor.execute(sql)
    summe = round(select_cursor.fetchone()[0], 2)
    select_cursor.close()
    
    select_db = connect_weh()
    select_cursor = select_db.cursor()
    sql = "SELECT DISTINCT users.username, users.turm FROM users JOIN constants ON constants.wert = users.uid WHERE constants.name IN ('kasse_netz1')"
    select_cursor.execute(sql)
    rows = select_cursor.fetchall()
    select_cursor.close()
    
    for row in rows:
        mail(summe, row[0], row[1])
    

## Funktionen für Mails ##
        
def mail(summe, username, turm):
    subject = "Monatlicher Barkassencheck"
    message = "Es ist Zeit den Kassenstand mit der Datenbank abzugleichen!\n\nBetrag: " + str(summe) + "€\n\nhttps://getnet.weh.rwth-aachen.de/verwaltung/Kasse.php \n\nGrüße von Fiji"
    to_email = str(username) + "@" + str(turm) + ".rwth-aachen.de"
    send_mail(subject, message, to_email)


check()