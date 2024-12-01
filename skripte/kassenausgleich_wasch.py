# Geschrieben von Fiji
# Juli 2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import datetime
from fcol import send_mail
from fcol import connect_weh

def kassenausgleich():
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    zeit = int(time.time())
    lastyear_unix = zeit - (365*24*60*60)

    summe = get_waschmarkensumfromoneyear(wehcursor, lastyear_unix)
    print("Die Summe beträgt: " + "{:.2f}".format(summe) + "€")

    transferinserts(wehcursor, summe, zeit, lastyear_unix)
    print("Transfers wurden inserted in weh.transfers")

    mail(summe)
    print("Mail an Kassenwarte versendet")

    wehdb.commit()
    wehcursor.close()
    wehdb.close()

def get_waschmarkensumfromoneyear(wehcursor, lastyear_unix):
    sql = "SELECT SUM(betrag) FROM transfers WHERE konto = 6 AND tstamp > %s"
    var = (lastyear_unix,)
    wehcursor.execute(sql, var)
    neg_summe = wehcursor.fetchone()[0]
    summe = (-1) * neg_summe if neg_summe is not None else 0
    return summe

def transferinserts(wehcursor, summe, zeit, lastyear_unix):
    transfers = [
        {"betrag": -summe, "uid": 472, "konto": 8, "kasse": 72}, # Netz
        {"betrag": summe, "uid": 492, "konto": 4, "kasse": 92} # Haus
    ]

    datum_lastyear = datetime.datetime.fromtimestamp(lastyear_unix).strftime('%d.%m.%Y')
    datum_thisyear = datetime.datetime.fromtimestamp(zeit).strftime('%d.%m.%Y')
    beschreibung = f"Waschmarken Kassenausgleich {datum_lastyear} bis {datum_thisyear}"

    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"

    for transfer in transfers:
        insert_var = (zeit, transfer["uid"], beschreibung, transfer["konto"], transfer["kasse"], transfer["betrag"])
        wehcursor.execute(insert_sql, insert_var)

def mail(summe):
    subject = "WEH Kassenausgleich Waschmarken"
    message = "Hallo Kassenwarte,"\
    "\n\njedes Jahr muss ein manueller Kassenausgleich durchgeführt werden - indem der Geldbetrag für gekaufte Waschmarken von Netzkasse auf Hauskasse transferiert werden."\
    "\n\n----------------------------------------"\
    "\n\nDie Änderung wurde bereits in die Datenbank eingetragen und muss nur noch überwiesen werden."\
    "\n\nBetrag: " + "{:.2f}".format(summe) + "€"\
    "\nVon Netzkonto: DE90 3905 0000 1070 3346 00"\
    "\nAuf Hauskonto: DE37 3905 0000 1070 3345 84"\
    "\n\n----------------------------------------"\
    "\n\nViele Grüße,\nkassenausgleich_wasch.py und Fiji"
    to_email = "kasse@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)

kassenausgleich()