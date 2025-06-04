# Geschrieben von Fiji
# Juli 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

# Dieses Skript soll zum 01. jedes Monats morgens aufgerufen werden.
# Es bucht Mitgliedsbeiträge vom Mitgliedskonto ab, sperrt User, die nicht genug Geld auf dem Konto haben und informiert alle Betroffenen

# Größere Anpassung 06.04.2024:
# Ab sofort wird der Anteil des tatsächlich bezahlten Hausbeitrags ermittelt und der Kassenausgleich in die Datenbank eingetragen.
# Die Infomail an NetzAG und Vorstand wurden zusammengelegt + Info: Überweisung Kassenausgleich muss händisch gemacht werden.

import time
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from fcol import send_mail
from fcol import connect_weh
from fcol import get_constant
from fpdf import FPDF
from fpdf.enums import XPos, YPos
import os
import datetime

DEBUG = True

## Grundprogramm ##

def abrechnung(): 

    zeit = int(time.time()) #UNIX Time
    vornerwoche = zeit - (1 * 7 * 24 * 60 * 60)
    hausbeitrag = get_constant("hausbeitrag")
    netzbeitrag = get_constant("netzbeitrag")
    
    normalcount = 0
    sperrcount = 0
    insolventcount = 0
    warncount = 0
    kassenausgleichbetrag = 0
    fehlenderbetrag = 0
    bezahlter_netzbeitrag = 0

    wehdb = connect_weh()
    cursor = wehdb.cursor()
    cursor.execute("SELECT uid, groups, honory, pid, name, username, turm, mailisactive, email, forwardemail FROM users WHERE pid IN (11,13) AND starttime < %s AND insolvent = 0 AND uid NOT IN (SELECT uid FROM sperre WHERE missedpayment = 1 AND starttime <= %s AND endtime >= %s)", (vornerwoche, zeit, zeit))
    bewohner = cursor.fetchall()
    for row in bewohner:
        uid = row[0]
        groups = row[1]
        honory = row[2]
        pid = row[3]
        name = row[4]
        username = row[5]
        turm = row[6]
        mailisactive = bool(row[7])
        email = row[8]
        forwardemail = bool(row[9])
        
        kontostand = get_kontostand(uid,cursor)
        if kontostand is None:
            kontostand = 0
        
        # Wandele groups in eine Liste von Zahlen um
        if isinstance(groups, str):
            groups = [int(g) if g.isdigit() else g for g in groups.split(",")]
        elif isinstance(groups, int):
            groups = [groups]
        
        nichtaktiv = all(g == 1 or g in [1, 19] for g in groups)
        monatsbeitrag = 0

        # Abrechnen
        if 7 not in groups and pid == 11:
            abrechnung_netzbeitrag(zeit,uid,netzbeitrag,cursor)
            monatsbeitrag += netzbeitrag
            
        if honory == 0 and nichtaktiv:
            abrechnung_hausbeitrag(zeit,uid,hausbeitrag,cursor)
            monatsbeitrag += hausbeitrag

        rest = kontostand - monatsbeitrag

        if rest is None:
            rest = 0
            
        restcheck = rest + 0.01
        
        if honory == 0 and nichtaktiv:
            if restcheck < 0:
                bezahlt = rest + hausbeitrag
                if 7 not in groups and pid == 11:
                    bezahlt += netzbeitrag            
                    bezahlthausanteil = bezahlt * (hausbeitrag / (hausbeitrag + netzbeitrag))                    
                    bezahlternetzanteil = bezahlt * (netzbeitrag / (hausbeitrag + netzbeitrag))
                    bezahlter_netzbeitrag += bezahlternetzanteil
                    fehlenderbetrag += (-1) * rest * (hausbeitrag / (hausbeitrag + netzbeitrag))
                else:
                    bezahlthausanteil = bezahlt
                    fehlenderbetrag += (-1) * rest
                if bezahlthausanteil > 0:
                    kassenausgleichbetrag += bezahlthausanteil
            else: 
                kassenausgleichbetrag += hausbeitrag
                if 7 not in groups and pid == 11:
                    bezahlter_netzbeitrag += netzbeitrag

        if restcheck < monatsbeitrag and restcheck > 0 and (pid == 11 or pid == 12):
            if mailisactive: warnmail(uid, turm, monatsbeitrag)
            warncount += 1
            infostring = "Vorgewarnt"
        elif restcheck < 0 and pid == 13:
            insolvent(uid, zeit); insolventcount += 1; infostring = "Insolvent"
        elif restcheck < 0:
            addsperre(uid, zeit)
            if mailisactive: sperrmail(uid, rest, name, username, turm, email, forwardemail)
            sperrcount += 1
            infostring = "Gesperrt"
        else:
            if mailisactive: confirmmail(uid, turm, monatsbeitrag)
            normalcount += 1
            infostring = "Gezahlt"

        
        ausgabe1 = str(name) + " [" + str(uid) + "] | " + str(infostring)
        ausgabe2 = "Vorher: " + str("{:.2f}".format(kontostand)) + "€ | Nachher: " + str("{:.2f}".format(rest)) + "€"
        ausgabe3 = "Bezahlter Hausbeitrag: " + str("{:.2f}".format(kassenausgleichbetrag)) + "€ | Fehlender Hausbeitrag: " + str("{:.2f}".format(fehlenderbetrag)) + "€"
        ausgabe4 = "Bezahlter Netzbeitrag: " + str("{:.2f}".format(bezahlter_netzbeitrag)) + "€"
        print(ausgabe1)
        print(ausgabe2)
        print(ausgabe3)
        print(ausgabe4)
        print()
        print()
        print()
        
    truenormalcount = normalcount + warncount   
    
    # Waschmarken einrechnen
    waschmarkenbetrag = get_waschmarkensumme_fuer_monat(cursor, zeit)
    if DEBUG:
        print(f"DEBUG: Waschmarkenbetrag für Kassenausgleich: {waschmarkenbetrag:.2f}€")
    kassenausgleichbetrag += waschmarkenbetrag
    kassenausgleichbetrag_2nachkommastellen = round(kassenausgleichbetrag, 2)

    pdfpfad = export_kassenausgleich_pdf(
        zeit,
        kassenausgleichbetrag_2nachkommastellen - waschmarkenbetrag,
        bezahlter_netzbeitrag,
        waschmarkenbetrag
    )

    kassenausgleichinsert(zeit, kassenausgleichbetrag_2nachkommastellen, pdfpfad)


    infomail(
        kassenausgleichbetrag_2nachkommastellen,
        truenormalcount,
        sperrcount,
        insolventcount,
        warncount,
        kassenausgleichbetrag_2nachkommastellen - waschmarkenbetrag,
        bezahlter_netzbeitrag,
        waschmarkenbetrag
    )

    if DEBUG:
        print("DEBUG MODE: Skipping database changes and emails")
    else:
        wehdb.commit()
    wehdb.close()
    
## Allgemeines ##

def addsperre(uid, zeit):
    missedpayment = 1
    internet = 1
    waschen = 1
    mail = 1
    buchen = 1
    drucken = 1
    beschreibung = "Fehlendes Guthaben"
    starttime = zeit
    endtime = 2147483647 # Maximaler UNIX-Time Wert. Viel Spaß an die zukünftigen Generationen mit diesem Problem :-)
    agent = 472

    fedb = connect_weh()
    fecursor = fedb.cursor()

    # SQL-INSERT-Statement mit Spaltennamen und Platzhaltern für Werte
    insert_sql = "INSERT INTO sperre (uid, missedpayment, internet, waschen, mail, buchen, drucken, beschreibung, starttime, endtime, agent, tstamp) " \
                 "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"

    # Tupel mit den Werten, die in die SQL-Abfrage eingesetzt werden sollen
    insert_var = (uid, missedpayment, internet, waschen, mail, buchen, drucken, beschreibung, starttime, endtime, agent, zeit)

    fecursor.execute(insert_sql, insert_var)
    if DEBUG:
        print("DEBUG MODE: Skipping addsperre() database commit")
    else:
        fedb.commit()

def insolvent(uid,zeit):
    cnx = connect_weh()
    cursor = cnx.cursor()
    insert_sql = "UPDATE users SET insolvent = %s WHERE uid = %s"
    insert_var = (zeit,uid)
    cursor.execute(insert_sql, insert_var)
    if DEBUG:
        print("DEBUG MODE: Skipping insolvent() database commit")
    else:
        cnx.commit()

## Funktionen für Mails ##
    
def infomail(kassenausgleichbetrag, truenormalcount, sperrcount, insolventcount, warncount,
             bezahlter_hausbeitrag, bezahlter_netzbeitrag, waschmarkenbetrag):

    gesamtbetrag = bezahlter_hausbeitrag + bezahlter_netzbeitrag + waschmarkenbetrag
    hausgesamt = bezahlter_hausbeitrag + waschmarkenbetrag

    if DEBUG:
        subject = "[DEBUG] WEH Abrechnung"
    else:
        subject = "WEH Abrechnung"

    message = (
        "Hallo Pappnasen (Netzwerk-AG und Haussprecher),\n"
        "die monatliche Abrechnung wurde erfolgreich durchgeführt.\n"
        "\n"
        "────────────────────────────────────────\n\n"
        "💸 Kassenausgleich:\n"
        f"  Betrag:           {kassenausgleichbetrag:.2f} €\n"
        "  Von Netzkonto:    DE90 3905 0000 1070 3346 00\n"
        "  Auf Hauskonto:    DE37 3905 0000 1070 3345 84\n"
        "\n"
        "────────────────────────────────────────\n\n"
        "📊 Finanzübersicht:\n"
        f"  Gesamt:                {gesamtbetrag:.2f} €\n"
        f"    ↳ Netzbeitrag:             {bezahlter_netzbeitrag:.2f} €\n"
        f"    ↳ Hausbeitrag:             {bezahlter_hausbeitrag:.2f} €\n"
        f"    ↳ Waschmarken:             {waschmarkenbetrag:.2f} €\n"
        "\n"
        f"  Hauskonto:             {hausgesamt:.2f} €\n"
        f"    ↳ Hausbeitrag:             {bezahlter_hausbeitrag:.2f} €\n"
        f"    ↳ Waschmarken:             {waschmarkenbetrag:.2f} €\n"
        "\n"
        f"  Netzkonto:             {bezahlter_netzbeitrag:.2f} €\n"
        "\n"
        "────────────────────────────────────────\n\n"
        "👥 Nutzerstatistik:\n"
        f"  Gezahlt:          {truenormalcount} User\n"
        f"  Vorgewarnt:       {warncount} User\n"
        f"  Gesperrt:         {sperrcount} User\n"
        f"  Insolvent:        {insolventcount} User\n"
        "\n"
        "  Gesperrte User wurden kontaktiert und werden durch Aufstocken automatisch entsperrt.\n"
        "  Insolvente User sind ausgezogen, werden aber erst nach 6 Monaten durch streichung.py abgemeldet.\n"
        "\n"
        "────────────────────────────────────────\n\n"
        "Viele Grüße,\n"
        "payment_abrechnung.py und Fiji"
    )


    to_email = "webmaster@weh.rwth-aachen.de" if DEBUG else "vorstand@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)


    
def warnmail(uid,turm,monatsbeitrag):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT name, username FROM users WHERE uid = %s"
    var = (uid,)
    wehcursor.execute(sql, var)
    result = wehcursor.fetchone()
    name = result[0] if result else None
    username = result[1] if result else None
    formatted_beitrag = f"{monatsbeitrag:.2f}€"
    
    subject = "WEH Confirmation of Monthly Payment"
    message = (
        f"Dear {name},\n\n"
        f"Your monthly membership fee of {formatted_beitrag} has been deducted from your WEH account.\n\n"
        "You can view all transactions and check your account balance here:\n"
        "https://backend.weh.rwth-aachen.de/UserKonto.php\n\n"
        "However, your current account balance is very low. While your membership fees for this month were covered, "
        "if you don’t top up your account balance soon, it may not be enough to extend your Internet access "
        "at the next billing cycle in the coming month.\n\n"
        "Please ensure your account balance is sufficient to avoid interruptions.\n\n"
        "Best regards,\n"
        "Netzwerk-AG WEH e.V."
    )
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"

    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)
    
def confirmmail(uid,turm,monatsbeitrag):
    wehdb = connect_weh()
    wehcursor = wehdb.cursor()
    sql = "SELECT name, username FROM users WHERE uid = %s"
    var = (uid,)
    wehcursor.execute(sql, var)
    result = wehcursor.fetchone()
    name = result[0] if result else None
    username = result[1] if result else None
    formatted_beitrag = f"{monatsbeitrag:.2f}€"
    
    subject = "WEH Confirmation of Monthly Payment"
    message = (
        f"Dear {name},\n\n"
        f"your monthly membership fee of {formatted_beitrag} has been deducted from your WEH account.\n\n"
        "You can view all transactions and check your account balance here:\n"
        "https://backend.weh.rwth-aachen.de/UserKonto.php\n\n"
        "Best regards,\n"
        "Netzwerk-AG WEH e.V."
    )
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"

    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)

def sperrmail(uid, rest, name, username, turm, zweite_email, forwardemail):
    posrest = - rest
    
    subject = "WEH Account Ban"
    message = "Dear " + str(name) + ",\n\n"\
    "your membership account balance is too low to extend your access to WEH services, so it was cancelled.\n\n"\
    "To reactivate your internet connection, there are still " + "{:.2f}".format(posrest) + "€ missing.\n\n"\
    "Name: WEH e.V.\n"\
    "IBAN: DE90 3905 0000 1070 3346 00\n"\
    "Transfer Reference: W"+str(uid)+"H\n"\
    "If you do not set this exact Transfer Reference, we will not be able to assign your payment to your account!\n\n"\
    "When your member account has a positive balance, your internet connection will be reactivated automatically.\n\n"\
    "Please note that it may take several days until your payment is processed and assigned.\n"\
    "You will receive an email notification as soon as your account is reactivated.\n\n"\
    "If you have already moved out of WEH without deregistering, please ignore this email.\n\n"\
    "Best Regards,\nNetzwerk-AG WEH e.V."
    to_email = username + "@" + str(turm) + ".rwth-aachen.de"
    
    if DEBUG:
        print("DEBUG MODE: Email not sent. Here are the details:")
        print(f"Subject: {subject}")
        print(f"Message: {message}")
        print(f"To: {to_email}")
    else:
        send_mail(subject, message, to_email)
    
    if not forwardemail:    
        if DEBUG:
            print("DEBUG MODE: Email not sent. Here are the details:")
            print(f"Subject: {subject}")
            print(f"Message: {message}")
            print(f"To: {zweite_email}")
        else:
            send_mail(subject, message, zweite_email)

## Funktionen zur Abrechnung ##        

def abrechnung_netzbeitrag(zeit,uid,netzbeitrag,cursor):
    date = time.strftime('%B %Y', time.gmtime(zeit+10000))    
    beschreibung = "Abrechnung Netzbeitrag " + str(date)
    konto = 1
    kasse = 3
    beitrag = -netzbeitrag
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,beitrag)
    cursor.execute(insert_sql, insert_var)
    
def abrechnung_hausbeitrag(zeit,uid,hausbeitrag,cursor):    
    date = time.strftime('%B %Y', time.gmtime(zeit+10000))
    beschreibung = "Abrechnung Hausbeitrag " + str(date)
    konto = 2
    kasse = 3
    beitrag = -hausbeitrag
    
    insert_sql = "INSERT INTO transfers (tstamp,uid,beschreibung,konto,kasse,betrag) VALUES (%s,%s,%s,%s,%s,%s)"
    insert_var = (zeit,uid,beschreibung,konto,kasse,beitrag)
    cursor.execute(insert_sql, insert_var)

def get_kontostand(uid,cursor):
    # time.sleep(0.5) # Damit alle Zahlungen drin sind, bevor man summiert
    sql = "SELECT SUM(betrag) FROM transfers WHERE uid = %s"
    var = (uid,)
    cursor.execute(sql, var)
    summe = cursor.fetchone()[0]
    return summe

def kassenausgleichinsert(zeit, betrag, pfad):
    db = connect_weh()
    cursor = db.cursor()

    try:
        # Von Netzkonto    
        neg_betrag = (-1) * betrag
        insert_sql = "INSERT INTO transfers (tstamp, uid, beschreibung, konto, kasse, betrag, pfad) VALUES (%s, %s, %s, %s, %s, %s, %s)"
        cursor.execute(insert_sql, (zeit, 472, "Kassenausgleich Netzkonto", 2, 72, neg_betrag, pfad))

        # Auf Hauskonto
        cursor.execute(insert_sql, (zeit, 492, "Kassenausgleich Hauskonto", 2, 92, betrag, pfad))

        if DEBUG:
            print("DEBUG: Hier würde der Kassenausgleich Insert stattfinden")
        else:
            db.commit()

    finally:
        cursor.close()
        db.close()


def get_waschmarkensumme_fuer_monat(cursor, zeit):
    # Monatsanfang berechnen
    lt = time.localtime(zeit)
    anfang_monat = time.mktime((lt.tm_year, lt.tm_mon, 1, 0, 0, 0, 0, 0, -1))
    sql = "SELECT SUM(betrag) FROM transfers WHERE konto = 6 AND tstamp >= %s"
    var = (int(anfang_monat),)
    cursor.execute(sql, var)
    neg_summe = cursor.fetchone()[0]
    summe = (-1) * neg_summe if neg_summe is not None else 0
    return summe

def export_kassenausgleich_pdf(timestamp, hausbeitrag, netzbeitrag, waschmarkenbetrag, pfad="/WEH/PHP/kassenausgleich/"):
    date = datetime.datetime.fromtimestamp(timestamp)
    jahr = date.year
    monat = f"{date.month:02d}"

    filename = f"kassenausgleich_{jahr}_{monat}.pdf"
    full_path = os.path.join(pfad, filename)

    gesamt = hausbeitrag + netzbeitrag + waschmarkenbetrag
    hausgesamt = hausbeitrag + waschmarkenbetrag

    pdf = FPDF()
    pdf.add_page()

    pdf.add_font("DejaVu", "", "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
    pdf.add_font("DejaVu", "B", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf")

    pdf.set_font("DejaVu", "B", 14)
    pdf.cell(0, 10, "Kassenausgleich WEH e.V.", new_x=XPos.LMARGIN, new_y=YPos.NEXT)

    pdf.set_font("DejaVu", "", 12)
    pdf.cell(0, 10, f"Monat: {monat}.{jahr}", new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(5)

    def table_row(label, amount, bold=False, indent=0):
        if bold:
            pdf.set_font("DejaVu", "B", 12)
        else:
            pdf.set_font("DejaVu", "", 12)
        spacing = " " * indent
        pdf.cell(100, 10, spacing + label, border=0)
        pdf.cell(0, 10, f"{amount:.2f} €", border=0, new_x=XPos.LMARGIN, new_y=YPos.NEXT)

    pdf.set_font("DejaVu", "B", 12)
    pdf.cell(0, 10, "Finanzübersicht", new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(2)

    table_row("Netzkonto gesamt", gesamt, bold=True)
    table_row("Netzbeitrag", netzbeitrag, indent=4)
    table_row("Hausbeitrag", hausbeitrag, indent=4)
    table_row("Waschmarken", waschmarkenbetrag, indent=4)
    pdf.ln(5)

    table_row("→ Hauskonto", hausgesamt, bold=True)
    table_row("Hausbeitrag", hausbeitrag, indent=4)
    table_row("Waschmarken", waschmarkenbetrag, indent=4)
    pdf.ln(5)

    table_row("→ Netzkonto verbleibend", netzbeitrag, bold=True)

    # Trenner + Erläuterungstext
    pdf.set_font("DejaVu", "", 12)
    pdf.ln(5)
    pdf.cell(0, 8, "────────────────────────────────────────", new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(2)

    text = (
        "Die Mitglieder des WEH e.V. verfügen über Prepaid-Konten. Das darauf eingezahlte Guthaben befindet sich vollständig auf dem Netzkonto.\n\n"
        "Im Rahmen der monatlichen Abrechnung werden für jedes Mitglied der Hausbeitrag und der Netzbeitrag rechnerisch vom jeweiligen Prepaid-Guthaben abgezogen.\n"
        "Auch die im Vormonat gekauften Waschmarken werden berücksichtigt – ihr Gegenwert wurde den Nutzerkonten bereits beim Kauf abgezogen und soll nun dem Hauskonto gutgeschrieben werden.\n\n"
        "Für den Kassenausgleich wird nun der Anteil, der dem Hauskonto zusteht – also die Summe aller Hausbeiträge und des Waschmarkenumsatzes – vom Netzkonto auf das Hauskonto überwiesen.\n"
        "Der verbleibende Teil auf dem Netzkonto entspricht der Summe der gezahlten Netzbeiträge."
    )

    pdf.multi_cell(0, 8, text)

    pdf.output(full_path)

    rel_path = os.path.join("kassenausgleich", filename)
    if DEBUG:
        print(f"DEBUG: PDF gespeichert unter {full_path}")
    return rel_path





abrechnung()