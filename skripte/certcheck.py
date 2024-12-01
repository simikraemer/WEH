# Geschrieben von Fiji
# 2024
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail
from fcol import connect_weh

def certcheck():
    # Aktuellen Unix-Timestamp abrufen
    zeit = time.time()
    inaweek = zeit + (7 * 24 * 60 * 60)
    inamonth = zeit + (24 * 24 * 60 * 60)

    # Verbindung zur WEH-Datenbank herstellen
    wehdb = connect_weh()
    print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
    
    wehcursor = wehdb.cursor()
    print("WEH-Datenbankcursor erstellt")

    # Zertifikate abrufen, die in weniger als einem Monat ablaufen und deren alert = 0 ist
    wehcursor.execute("SELECT id, alert, cn, endtime FROM certs WHERE alert = 0 AND endtime <= %s", (inamonth,))
    for row in wehcursor.fetchall():
        cert_id = row[0]
        alert = row[1]
        cn = row[2]
        endtime = row[3] 
        
        print(f"Zertifikat {cn} mit ID {cert_id} läuft am {datetime.fromtimestamp(endtime).strftime('%d.%m.%Y')} ab. Sende Warnung...")       
        alertmail(endtime, alert, cn)
        wehcursor.execute("UPDATE certs SET alert = 1 WHERE id = %s", (cert_id,))
        print(f"Zertifikat {cn} mit ID {cert_id} wurde auf alert = 1 gesetzt.")
    
    # Zertifikate abrufen, die in weniger als einer Woche ablaufen und deren alert = 1 oder 2 ist
    wehcursor.execute("SELECT id, alert, cn, endtime FROM certs WHERE alert IN (1,2) AND endtime <= %s", (inaweek,))
    for row in wehcursor.fetchall():
        cert_id = row[0]
        alert = row[1]
        cn = row[2]
        endtime = row[3]
        
        print(f"Zertifikat {cn} mit ID {cert_id} läuft in weniger als einer Woche ab (am {datetime.fromtimestamp(endtime).strftime('%d.%m.%Y')}). Sende erneute Warnung...")        
        alertmail(endtime, alert, cn)
        wehcursor.execute("UPDATE certs SET alert = 2 WHERE id = %s", (cert_id,))
        print(f"Zertifikat {cn} mit ID {cert_id} wurde auf alert = 2 gesetzt.")
    
    # Zertifikate abrufen, deren alert = 1 oder 2 ist und deren endtime sicher ist (mehr als einen Monat entfernt)
    wehcursor.execute("SELECT id, alert, cn, endtime FROM certs WHERE alert IN (1,2) AND endtime > %s", (inamonth,))
    for row in wehcursor.fetchall():
        cert_id = row[0]
        alert = row[1]
        cn = row[2]
        endtime = row[3]
        
        print(f"Zertifikat {cn} mit ID {cert_id} läuft am {datetime.fromtimestamp(endtime).strftime('%d.%m.%Y')} ab und ist sicher. Setze Alarmstufe zurück...")        
        wehcursor.execute("UPDATE certs SET alert = 0 WHERE id = %s", (cert_id,))
        print(f"Zertifikat {cn} mit ID {cert_id} wurde auf alert = 0 gesetzt.")
    
    # Änderungen in der Datenbank speichern
    wehdb.commit()
    
    # Cursor und Verbindung schließen
    wehcursor.close()
    wehdb.close()
    print("Verbindung zur WEH-Datenbank geschlossen")


def alertmail(endtime, alert, cn):
    subject = "[ACHTUNG!] " + str(cn) + " läuft am " + datetime.fromtimestamp(endtime).strftime("%d.%m.%Y") + " ab!"
    message = f"""
Liebe Netzansprechpartner,

dies ist eine automatische Benachrichtigung, dass das Zertifikat für {cn} am {datetime.fromtimestamp(endtime).strftime("%d.%m.%Y")} abläuft. 

Bitte kümmert euch rechtzeitig um die Erneuerung des Zertifikats, um Ausfälle zu vermeiden.
https://backend.weh.rwth-aachen.de/Certs.php
https://wiki.weh.rwth-aachen.de/index.php/RWTH_Server_Zertifikate
https://wiki.weh.rwth-aachen.de/index.php/Stunnel


Im Folgenden findet ihr eine Anleitung aus dem Wiki, wie ihr ein neues Zertifikat beantragen könnt (RA-Portal) oder ein neues stunnel-Zertifikat erstellt/signiert:



== Zertifikat beantragen ==
    Infos für Serverzertifikat hier:
    https://help.itc.rwth-aachen.de/service/81a55cea5f2b416892901cf1736bcfc7/article/14ead960dbe34039beac01f568c75afd/

    Serverzertifikat bei GEANT beantragen:
    1. Auf den Server wechseln, auf dem das Zertifikat installiert werden soll.
    2. openssl installieren (falls nicht schon vorhanden).
    3. In das entsprechende Verzeichnis wechseln, in dem die Zertifikate gespeichert sind.
        * Private Key:
            - Wenn bereits ein Key vorhanden ist, muss keiner erstellt werden. Nur Zertifikate laufen ab, Keys nicht.
            - Private Key erstellen (4096 Bit oder höher):
            openssl genrsa -out servername.weh-private-YYYYMMDD.pem 4096
            - Zertifikatsrequest erstellen:
            openssl req -new -sha256 -key servername.weh-private-YYYYMMDD.pem -out servername.weh-req.pem -config cert-req.conf
    4. RA Portal öffnen (Zugriff für eingetragene Netzansprechpartner im ITC): https://ra-portal.itc.rwth-aachen.de/
    5. Zu SSL-Zertifikate navigieren.
    6. Hochladen des Zertifikats:
        * SSL-Zertifikat beantragen
        * Das Zertifikatsrequestfile hochladen (Seite fordert eventuell ein .csr File an, aber ein .pem File wird auch akzeptiert!)
        * Email: Eure Netzansprechpartner-Mail auswählen
        * SSL-Zertifikatsantrag hochladen.
    7. Der Antrag muss von den ITC-NOC-Kollegen freigegeben werden. Sobald dies geschehen ist, erhaltet ihr eine Mail. Daraufhin könnt ihr das CSR an GEANT absenden, indem ihr im RA-Portal den entsprechenden Button drückt.
    8. Nach der Genehmigung durch GEANT erhaltet ihr eine weitere Mail. Im RA-Portal könnt ihr dann das Zertifikat herunterladen und im Server einbinden. Welche Dateien benötigt werden (ohne Kette, mit Kette, nur Intermediate), hängt vom Server ab. Bitte prüft dies in den entsprechenden Konfigurationsdateien.


    

== stunnel Zertifikate ==

    === 1. Private Keys sammeln ===
    Keys aus db:/etc/stunnel/CA/privkeys nehmen oder auf jeden Server via SSH einloggen und alle Private Keys aus /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-key einsammeln.
    Dieser Schritt ist entscheidend, um sicherzustellen, dass wir die notwendigen Schlüssel für die Signierung der neuen Zertifikate haben.

    === 2. Zertifikate signieren ===
    Mit den gesammelten Private Keys, cacert.pem und cacert.key die neuen Zertifikate erstellen. 
    Hierbei wird ein Skript verwendet, das für jeden Schlüssel eine Zertifikatsignieranforderung (CSR) erstellt, diese signiert und das Zertifikat erzeugt.
    Während des Skripts muss man für jedes signierte Zert die CA-Passphrase eingeben.

    Das Skript, die CA-Files und die CA-Passphrase befinden sich in db:/etc/stunnel/CA

    <pre>
    #!/bin/bash
    # Stellen Sie sicher, dass Sie sich im Verzeichnis mit den Key-Dateien befinden

    # Pfad zum CA-Zertifikat und CA-Key
    CA_CERT="cacert.pem"
    CA_KEY="cacert.key"

    # Durchläuft alle Key-Dateien und erstellt entsprechende Zertifikate
    for key in stunnel-*-key.pem; do
    # Der Basisname ohne die Erweiterung "-key.pem" wird extrahiert
    base_name=$(echo "$key" | sed 's/-key.pem//')

    # Erstellen einer Zertifikatsignieranforderung (CSR) für den Key
    openssl req -new -key "$key" -out "{{base_name}}.csr" \
        -subj "/C=DE/ST=NRW/L=Aachen/O=RWTH Aachen University/OU=IT Services/CN={{base_name}}.weh.rwth-aachen.de"

    # Erstellen einer Konfigurationsdatei für die Erweiterungen
    cat > "{{base_name}}_x509ext.conf" <<EOF
    basicConstraints = CA:FALSE
    nsCertType = client, email, objsign
    nsComment = "OpenSSL Generated Certificate"
    subjectKeyIdentifier = hash
    authorityKeyIdentifier = keyid,issuer
    keyUsage = digitalSignature, keyEncipherment
    extendedKeyUsage = serverAuth, clientAuth
    EOF

    # Signieren der CSR mit dem CA-Key und CA-Zertifikat, Erstellen des Zertifikats mit Erweiterungen
    openssl x509 -req -days 3285 -in "{{base_name}}.csr" -CA "$CA_CERT" -CAkey "$CA_KEY" -CAcreateserial \
        -extfile "{{base_name}}_x509ext.conf" \
        -out "{{base_name}}-cert.pem"

    # Aufräumen der CSR-Datei und der Konfigurationsdatei
    rm "{{base_name}}.csr" "{{base_name}}_x509ext.conf"
    done

    echo "Zertifikatserstellung abgeschlossen."
    </pre>

    === 3. Zertifikate einbinden ===
    Die signierten Zertifikate wurden auf allen Servern entweder mit SCP oder FileZilla in den Homefolder übertragen. Dies stellt sicher, dass die Zertifikate an einem sicheren Ort sind und leicht zugänglich für die nächsten Schritte.
    Die Schritte 3.2 und 3.3 sollten direkt nacheinander ausgeführt werden, um eine reibungslose Verbindung zur Datenbank zu garantieren.

    ==== 3.1 Backupfolder (einmalig) ====
    Zunächst sollte ein Backupordner für die derzeit vertrauten Zertifikate erstellt werden. Dies ermöglicht eine sichere Wiederherstellung, falls bei der Aktualisierung Probleme auftreten sollten.
    <pre>
    mkdir /etc/ssl/stunnel/backup_trusted
    cp /etc/ssl/stunnel/trusted/* /etc/ssl/stunnel/backup_trusted/
    </pre>

    ==== 3.2 Zertifikat auf dem Datenbankserver (für jedes Zert) ====
    Anschließend wird jedes Zertifikat in das entsprechende Verzeichnis verschoben und die notwendigen Berechtigungen werden gesetzt. Diese Schritte sorgen dafür, dass die Zertifikate sicher sind und nur von autorisierten Prozessen gelesen werden können.
    <pre>
    mv /home/simon/stunnelzerts/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/stunnel/trusted/
    sudo chown stunnel4:stunnel4 /etc/ssl/stunnel/trusted/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    sudo chmod 600 /etc/ssl/stunnel/trusted/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    sudo c_rehash /etc/ssl/stunnel/trusted/
    sudo systemctl restart stunnel4.service
    </pre>

    ==== 3.3 Zertifikat auf dem jeweiligen Server (für jedes Zert) ====
    Um eine kontinuierliche und sichere Dienstleistung zu gewährleisten, ist es wichtig, die alten Zertifikate zunächst umzubenennen, bevor die neuen Zertifikate implementiert werden. Dies minimiert die Wahrscheinlichkeit von Dienstunterbrechungen, indem eine nahtlose Übergabe von alten zu neuen Zertifikaten ermöglicht wird und stellt sicher, dass die Server jederzeit sicher auf die Datenbank zugreifen können.

    ===== 3.3.1 Normales Debian =====
    <pre>
    cp /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/OLD_stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    cp /home/simon/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/
    chown stunnel4:stunnel4 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    chmod 600 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    c_rehash /etc/ssl/private/
    sudo systemctl restart stunnel4.service
    </pre>

    ===== 3.3.2 cip1 =====
    <pre>
    cp /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/OLD_stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    cp /home/simon/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/
    chown stunnel4:stunnel4 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    chmod 600 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    c_rehash /etc/ssl/private/
    sudo service stunnel4 restart
    </pre>

    ===== 3.3.3 majestix =====
    <pre>
    cp /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/OLD_stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    cp /home/simon/stunnel-<servername>.weh.rwth-aachen.de-cert.pem /etc/ssl/private/
    chown stunnel4:stunnel4 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    chmod 600 /etc/ssl/private/stunnel-<servername>.weh.rwth-aachen.de-cert.pem
    c_rehash /etc/ssl/private/
    sudo systemctl restart stunnel4.service
    sudo service stunnel start
    </pre>

    ==== 4. Besonderheiten ====

    ===== 4.1. Cipher Sicherheitslevel =====
    Bei neueren OS muss man in /etc/ssl/openssl.cnf
    <pre>
    [system_default_sect]
    CipherString = DEFAULT@SECLEVEL=1
    </pre>
    setzen!

    ===== 4.2. Neue Server =====
    Folgende Files dorthin kopieren:
    <pre>
    /var/lib/stunnel4/certs/stunnel-db.weh.rwth-aachen.de-cert.pem
    /var/lib/stunnel4/certs/cacert.pem
    </pre>

    und dann nochmal
    <pre>
    sudo c_rehash /var/lib/stunnel4/certs
    </pre>

    

Viele Grüße,
certcheck.py
"""
    to_email = "netag@weh.rwth-aachen.de"
    send_mail(subject, message, to_email)
    print(f"Benachrichtigungsmail gesendet: {subject} an {to_email}")


certcheck()