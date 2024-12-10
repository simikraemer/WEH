import time
import mysql.connector
import smtplib
import json
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

def send_mail(subject, message, to_email, reply_to=None):
    # Erstelle eine MIMEText-Nachricht
    msg = MIMEMultipart()
    msg['Subject'] = subject
    msg['From'] = "WEH e.V. <system@weh.rwth-aachen.de>"
    msg['To'] = to_email

    # Optionale Reply-To-Adresse hinzufügen
    if reply_to:
        msg['Reply-To'] = reply_to

    # Füge den Text als MIMEText hinzu
    msg.attach(MIMEText(message))
        
    # Verbinde mit dem SMTP-Server
    mail_config = readconfig("mail")
    with smtplib.SMTP(mail_config['ip'], 25) as smtp_server:
        smtp_server.starttls()
        smtp_server.login(mail_config['user'], mail_config['password'])
        smtp_server.sendmail(mail_config['address'], to_email, msg.as_string())

    # Ausgabe der gesendeten Mail-Details
    echo_message = f"Betreff: {subject}, From: {msg['From']}, To: {msg['To']}"
    if reply_to:
        echo_message += f", Reply-To: {msg['Reply-To']}"
    print(echo_message)


def get_constant(wert):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT wert FROM constants WHERE name = %s"
    var = (wert,)
    wehcursor.execute(sql, var)
    constant = wehcursor.fetchone()[0]
    return constant

def readconfig(wert): # path
    with open('/etc/credentials/config.json', 'r', encoding='utf-8') as f:
        config = json.load(f)
        retconfig = config[wert]
        return retconfig

###### Konnektoren Connectors Conn connect ######

def connect_weh():
    mysql_config = readconfig("weh")
    typodb = mysql.connector.connect(
        host=mysql_config['host'],
        user=mysql_config['user'],
        password=mysql_config['password'],
        database=mysql_config['database']
    )
    return typodb

def connect_wp():
    mysql_config = readconfig("mysqlwp")
    wehdb = mysql.connector.connect(
        host=mysql_config['host'],
        user=mysql_config['user'],
        password=mysql_config['password'],
        database=mysql_config['database']
    )
    return wehdb

def connect_anmeldung():
    mysql_config = readconfig("anmeldung")
    wehdb = mysql.connector.connect(
        host=mysql_config['host'],
        user=mysql_config['user'],
        password=mysql_config['password'],
        database=mysql_config['database']
    )
    return wehdb

def connect_wasch():
    mysql_config = readconfig("wasch")
    wehdb = mysql.connector.connect(
        host=mysql_config['host'],
        user=mysql_config['user'],
        password=mysql_config['password'],
        database=mysql_config['database']
    )
    return wehdb

###### Time ######

def unix_to_date(unixtime):
    # Unix-Zeitstempel in lokales Datum und Uhrzeit umwandeln
    datum_und_uhrzeit = time.localtime(unixtime)
    
    # Datumsteile extrahieren
    tag = str(datum_und_uhrzeit.tm_mday).zfill(2)
    monat = str(datum_und_uhrzeit.tm_mon).zfill(2)
    jahr = str(datum_und_uhrzeit.tm_year)
    
    # Datumsteile zu einem String zusammensetzen
    datum_string = str(tag)+"."+str(monat)+"."+str(jahr)
    
    return datum_string