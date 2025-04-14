#!/bin/bash

# Erst das Python-Skript ausführen
/usr/bin/python3 /WEH/skripte/anmeldung.py

# Konfigurationsdatei
CONFIG_FILE="/etc/credentials/config.json"

# Konfigurationswerte auslesen
HOST=$(jq -r '.www2ssh.host' $CONFIG_FILE)
PORT=$(jq -r '.www2ssh.port' $CONFIG_FILE)
USERNAME=$(jq -r '.www2ssh.username' $CONFIG_FILE)
KEYFILE=$(jq -r '.www2ssh.keyfile' $CONFIG_FILE)

# Lokaler und Remote-Pfad
REMOTE_DIR="/SERVER/htdocs/www/reg/uploads/"
LOCAL_DIR="/WEH/PHP/anmeldung/"

# Überprüfung, ob das lokale Verzeichnis existiert
if [ ! -d "$LOCAL_DIR" ]; then
    echo "Lokales Verzeichnis $LOCAL_DIR existiert nicht. Erstelle es..."
    mkdir -p "$LOCAL_DIR"
fi

# Dateien vom Remote-Server herunterladen
echo "Übertrage Dateien von $REMOTE_DIR nach $LOCAL_DIR ..."
scp -i "$KEYFILE" -P "$PORT" "$USERNAME@$HOST:$REMOTE_DIR*" "$LOCAL_DIR"

if [ $? -eq 0 ]; then
    echo "Dateiübertragung erfolgreich abgeschlossen."

    # Dateien auf dem Remote-Server löschen
    echo "Lösche Dateien auf dem Remote-Server..."
    ssh -i "$KEYFILE" -p "$PORT" "$USERNAME@$HOST" "rm -f $REMOTE_DIR*"

    if [ $? -eq 0 ]; then
        echo "Quell-Dateien erfolgreich gelöscht."
    else
        echo "Fehler beim Löschen der Quell-Dateien!"
        exit 1
    fi

    # Besitzrechte im lokalen Verzeichnis setzen
    echo "Setze Besitzrechte im Zielverzeichnis..."
    chown -R www-data:www-data "$LOCAL_DIR"

    if [ $? -eq 0 ]; then
        echo "Besitzrechte erfolgreich gesetzt."
    else
        echo "Fehler beim Setzen der Besitzrechte!"
        exit 1
    fi
else
    echo "Fehler bei der Dateiübertragung!"
    exit 1
fi
