# Geschrieben von Fiji
# März 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import imaplib
import email
from email.header import decode_header
import time
import datetime
from datetime import datetime
from fcol import connect_weh, readconfig
import re


def fetch_inbox_mails():
    # --- Zugangsdaten laden ---
    host, port, username, password, use_ssl = get_kontoweckermail_credentials()

    # --- Verbindung aufbauen ---
    if use_ssl:
        mail = imaplib.IMAP4_SSL(host, port)
    else:
        mail = imaplib.IMAP4(host, port)

    mail.login(username, password)
    mail.select("inbox")

    # --- Mails im Posteingang abrufen ---
    status, messages = mail.search(None, 'ALL')  # später ggf. 'UNSEEN'
    mail_ids = messages[0].split()
    print(f"Gefundene Mails: {len(mail_ids)}")

    try:
        mail.create("Archiv")  # wird ignoriert, wenn Ordner schon existiert
    except:
        pass  # keine Aktion notwendig

    # --- Jede Mail einzeln verarbeiten ---
    for num in mail_ids:
        # --- Mail abrufen & parsen ---
        status, msg_data = mail.fetch(num, '(RFC822)')
        raw_email = msg_data[0][1]
        msg = email.message_from_bytes(raw_email)

        subject, encoding = decode_header(msg["Subject"])[0]
        if isinstance(subject, bytes):
            subject = subject.decode(encoding or "utf-8")

        print(f"\n----- Neue Mail -----\nBetreff: {subject}")

        # --- Body extrahieren ---
        mail_body = ""
        if msg.is_multipart():
            for part in msg.walk():
                content_type = part.get_content_type()
                content_disposition = str(part.get("Content-Disposition"))

                if content_type == "text/plain" and "attachment" not in content_disposition:
                    mail_body = part.get_payload(decode=True).decode(part.get_content_charset() or "utf-8", errors="replace")
                    break
        else:
            mail_body = msg.get_payload(decode=True).decode(msg.get_content_charset() or "utf-8", errors="replace")

        # --- Daten extrahieren ---
        betreff_match = re.search(r"Überweisungsbetreff:\s*(\S+)", mail_body)
        betrag_match = re.search(r"Betrag:\s*([\d.,]+)", mail_body)
        name_match = re.search(r"Name:\s*(.+)", mail_body)
        iban_match = re.search(r"IBAN:\s*([A-Z0-9 ]+)", mail_body)
        own_iban_match = re.search(r"Eigene IBAN:\s*([A-Z0-9 ]+)", mail_body)

        ueberweisungsbetreff = betreff_match.group(1) if betreff_match else None
        betrag = betrag_match.group(1).replace(",", ".") if betrag_match else None
        name = name_match.group(1).strip() if name_match else None
        iban_raw = iban_match.group(1).strip() if iban_match else None
        own_iban_raw = own_iban_match.group(1).strip() if iban_match else None

        # --- UID validieren und extrahieren ---
        valid_uid = bool(re.fullmatch(r"W\d+H", ueberweisungsbetreff)) if ueberweisungsbetreff else False
        uid = None
        if valid_uid:
            uid_match = re.search(r"W(\d+)H", ueberweisungsbetreff)
            if uid_match:
                uid = int(uid_match.group(1))

        # --- IBAN prüfen und Konto zuordnen ---
        own_iban_clean = own_iban_raw.replace(" ", "").upper() if own_iban_raw else None

        if own_iban_clean == "DE37390500001070334584":
            netzkonto = False
        else:
            netzkonto = True

        # --- Debug-Ausgabe ---
        print("✅ Mail erfolgreich analysiert:")
        print(f"  Betreff:         {ueberweisungsbetreff}")
        print(f"  Gültiges Format: {valid_uid}")
        print(f"  UID:             {uid}")
        print(f"  Betrag:          {betrag} EUR")
        print(f"  Name:            {name}")
        print(f"  IBAN:            {iban_raw}")
        print(f"  Konto:           {'Netzkonto' if netzkonto else 'Hauskonto'}")
        print(f"  Eigene IBAN:     {own_iban_clean}")

        # --- Datenbankverbindung ---
        wehdb = connect_weh()
        wehcursor = wehdb.cursor()

        # --- Metadaten vorbereiten ---
        zeit = int(time.time())
        zeit_formatiert = datetime.now().strftime("%d.%m.%Y %H:%M")
        beschreibung = "Überweisung"
        konto = 4
        kasse = 72 if netzkonto else 92
        changelog = f"[{zeit_formatiert}] Insert durch Kontowecker\n"

        # --- SQL-Insert vorbereiten ---
        if valid_uid:
            sql = """
                INSERT INTO transfers 
                SET uid = %s, tstamp = %s, beschreibung = %s, konto = %s, 
                    kasse = %s, betrag = %s, changelog = %s
            """
            values = (uid, zeit, beschreibung, konto, kasse, betrag, changelog)
        else:
            sql = """
                INSERT INTO unknowntransfers 
                SET tstamp = %s, betreff = %s, name = %s, betrag = %s, iban = %s, netzkonto = %s
            """
            values = (zeit, ueberweisungsbetreff, name, betrag, iban_raw, int(netzkonto))

        # --- Insert ausführen ---
        wehcursor.execute(sql, values)
        wehdb.commit()
        wehcursor.close()
        wehdb.close()

        # --- Mail in "Archiv" verschieben ---
        mail.copy(num, "Archiv")
        mail.store(num, '+FLAGS', '\\Deleted')  # markiere zum Löschen
        
    # --- Am Ende: alle zum Löschen markierten Mails endgültig löschen ---
    mail.expunge()

    # --- Verbindung schließen ---
    mail.logout()



def get_kontoweckermail_credentials():
    creds = readconfig("kontoweckermail")
    
    host = creds.get("host")
    port = creds.get("port", 993)
    username = creds.get("username")
    password = creds.get("password")
    use_ssl = creds.get("use_ssl", True)

    if not all([host, port, username, password]):
        raise ValueError("Unvollständige Mail-Konfiguration.")

    return host, port, username, password, use_ssl

if __name__ == "__main__":
    print("Starte Kontowecker-Mailauslesung...")
    fetch_inbox_mails()