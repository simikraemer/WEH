# Geschrieben von Fiji
# November 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
import datetime
import math
import mysql.connector
import smtplib
import json
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
import ebicspy
from mt940.models import MT940
import re
import subprocess

## Grundprogramm ##

def ebicstransfer(): 

    # Zugangsdaten aus der Konfigurationsdatei laden
    zugangsdaten = readconfig("ebics")
    #Format wie folgt
    #    "ebics": {
    #        "benutzername": "DEIN_BENUTZERNAME",
    #        "pin": "DEIN_PIN",
    #        "blz": "DEINE_BLZ",
    #        "bank_url": "DEINE_BANK_URL"
    #    }

    # Befehl für die Ausführung von hbci-cli, um MT940-Daten abzurufen
    cmd = "hbci-cli -user " + zugangsdaten['benutzername'] + " -pin " + zugangsdaten['pin'] + " -blz " + zugangsdaten['blz'] + " -url " + zugangsdaten['bank_url'] + " -getmt940"
    
    # Ausführung des Befehls und Erfassung der Ausgabe
    process = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate()
    
    # Überprüfe den Ausgabe-Status und verarbeite die Ausgabe
    if process.returncode == 0:
        # Die Ausgabe (stdout) enthält die MT940-Daten
        mt940_data = stdout.decode("utf-8")
        print("MT940-Daten:\n", mt940_data)
        
        # Parsen der MT940-Daten
        mt940 = MT940(mt940_data)

        # Arrays für Transfers mit und ohne UID erstellen
        transfers_mit_uid = []
        transfers_ohne_uid = []

        for transaction in mt940.transactions:
            # Suche nach einer vierstelligen UID im Verwendungszweck
            match = re.search(r'Eilender(\d{4})', transaction.description)
            if match:
                uid = match.group()  # Hier wird die gefundene UID (vierstellige Zahl) gespeichert
                transfers_mit_uid.append({
                    "Valutadatum": transaction.date,
                    "Buchungsdatum": transaction.booking_date,
                    "Betrag": transaction.amount,
                    "Währung": transaction.currency,
                    "Transaktionsart": transaction.transaction_type,
                    "Transaktionsreferenz": transaction.reference,
                    "Gegenpartei": transaction.customer_reference,
                    "Verwendungszweck": transaction.description,
                    "Kontostand": transaction.final_balance,
                    "Kontonummer": transaction.account,
                    "UID": uid
                })
            else:
                transfers_ohne_uid.append({
                    "Valutadatum": transaction.date,
                    "Buchungsdatum": transaction.booking_date,
                    "Betrag": transaction.amount,
                    "Währung": transaction.currency,
                    "Transaktionsart": transaction.transaction_type,
                    "Transaktionsreferenz": transaction.reference,
                    "Gegenpartei": transaction.customer_reference,
                    "Verwendungszweck": transaction.description,
                    "Kontostand": transaction.final_balance,
                    "Kontonummer": transaction.account
                })

        # Eintragen der Transfers mit UID in die Datenbank für Konto 4
        insert_realtransfers(transfers_mit_uid, konto=4, kasse=5)
        insert_alltransfers(transfers_mit_uid, transfers_ohne_uid)

        # Aufrufen der "infomail"-Funktion mit den Arrays
        infomail(transfers_mit_uid, transfers_ohne_uid)

    else:
        # Bei einem Fehler wird stderr die Fehlermeldung enthalten
        error_message = stderr.decode("utf-8")
        print("Fehler beim Ausführen von hbci-cli:", error_message)


def insert_realtransfers(transfers, konto, kasse):
    typodb = connect_weh()
    typocursor = typodb.cursor()

    for transfer in transfers:
        uid = transfer.get("UID", None)
        beschreibung = "EBICS-Transfer"
        date = transfer.get("Buchungsdatum", None)
        tstamp = int(date.timestamp())
        betrag = transfer.get("Betrag", None)

        if uid is not None and beschreibung is not None and tstamp is not None and betrag is not None:
            sql = "INSERT INTO transfers (uid, beschreibung, tstamp, konto, kasse, betrag) VALUES (%s, %s, %s, %s, %s, %s)"
            values = (uid, beschreibung, tstamp, konto, kasse, betrag)
            typocursor.execute(sql, values)

    typodb.commit()
    typodb.close()

def insert_alltransfers(transfers_mit_uid, transfers_ohne_uid):
    typodb = connect_weh()
    typocursor = typodb.cursor()

    for transfer in transfers_mit_uid + transfers_ohne_uid:
        uid = transfer.get("UID", None)
        date = transfer.get("Buchungsdatum", None)
        booking_date = transfer.get("Buchungsdatum", None)
        amount = transfer.get("Betrag", None)
        currency = transfer.get("Währung", None)
        transaction_type = transfer.get("Transaktionsart", None)
        reference = transfer.get("Transaktionsreferenz", None)
        customer_reference = transfer.get("Gegenpartei", None)
        description = transfer.get("Verwendungszweck", None)
        final_balance = transfer.get("Kontostand", None)
        account = transfer.get("Kontonummer", None)

        sql = "INSERT INTO ebics (uid, date, booking_date, amount, currency, transaction_type, reference, customer_reference, description, final_balance, account) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
        values = (uid, date, booking_date, amount, currency, transaction_type, reference, customer_reference, description, final_balance, account)
        typocursor.execute(sql, values)

    # Transaktionen in der Datenbank bestätigen und Verbindung schließen
    typodb.commit()
    typodb.close()
    
## Allgemeines ##

def get_constant(wert):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT wert FROM constants WHERE name = %s"
    var = (wert,)
    wehcursor.execute(sql, var)
    constant = wehcursor.fetchone()[0]
    return constant

def readconfig(wert): # path
    with open('/etc/credentials/config.json', 'r') as f:
        config = json.load(f)
        retconfig = config[wert]
        return retconfig

def connect_weh():
    mysql_config = readconfig("weh")
    typodb = mysql.connector.connect(
        host=mysql_config['host'],
        user=mysql_config['user'],
        password=mysql_config['password'],
        database=mysql_config['database']
    )
    return typodb

## Funktionen für Mails ##

def send_mail(subject, message, to_email):
    # Erstelle eine MIMEText-Nachricht
    msg = MIMEMultipart()
    msg['Subject'] = subject
    msg['From'] = "WEH e.V. <system@weh.rwth-aachen.de>"
    msg['To'] = to_email

    # Füge den Text als MIMEText hinzu
    msg.attach(MIMEText(message))
        
    # Verbinde mit dem SMTP-Server
    mail_config = readconfig("mail")
    with smtplib.SMTP(mail_config['ip'], 25) as smtp_server:
        smtp_server.starttls()
        smtp_server.login(mail_config['user'], mail_config['password'],)
        smtp_server.sendmail(mail_config['address'], to_email, msg.as_string())
     
def infomail(transfers_mit_uid, transfers_ohne_uid):
    subject = "WEH Zahltag"
    
    # Erfolgreiche Zahlungen
    erfolgreiche_zahlungen = "Erfolgreiche Zahlungen:\n\n"
    for transfer in transfers_mit_uid:
        erfolgreiche_zahlungen += "UID: {}\n".format(transfer['UID'])
        erfolgreiche_zahlungen += "Buchungsdatum: {}\n".format(transfer['Buchungsdatum'])
        erfolgreiche_zahlungen += "Betrag: {} {}\n".format(transfer['Betrag'], transfer['Währung'])

    # Nicht erfolgreiche Zahlungen
    nicht_erfolgreiche_zahlungen = "Nicht erfolgreiche Zahlungen:\n\n"
    for transfer in transfers_ohne_uid:
        nicht_erfolgreiche_zahlungen += "Absender: {}\n".format(transfer['Gegenpartei'])
        nicht_erfolgreiche_zahlungen += "Verwendungszweck: {}\n".format(transfer['Verwendungszweck'])
        nicht_erfolgreiche_zahlungen += "Buchungsdatum: {}\n".format(transfer['Buchungsdatum'])
        nicht_erfolgreiche_zahlungen += "Betrag: {} {}\n".format(transfer['Betrag'], transfer['Währung'])

    message = ("Hallo Kassenwarte,\n\n"
               "Hier sind die Zahlungsdetails für den:\n\n"
               "{}\n\n"
               "{}\n\n"
               "Viele Grüße,\nebics.py\ni.A. Netzwerk-AG WEH e.V.".format(erfolgreiche_zahlungen, nicht_erfolgreiche_zahlungen))
    
    to_email = "kasse@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)

ebicstransfer()