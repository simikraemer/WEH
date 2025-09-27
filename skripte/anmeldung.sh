#!/bin/bash

echo "[INFO] Starte anmeldung.sh"

# Erst das Python-Skript ausführen
echo "[INFO] Starte Python-Skript..."
/usr/bin/python3 /WEH/skripte/anmeldung.py && \
    echo "[✓] Python-Skript erfolgreich" || \
    echo "[✗] Python-Skript fehlgeschlagen"

# Konfigurationsdatei
CONFIG_FILE="/etc/credentials/config.json"

# Konfigurationswerte auslesen
HOST=$(jq -r '.www2ssh.host' "$CONFIG_FILE")
PORT=$(jq -r '.www2ssh.port' "$CONFIG_FILE")
USERNAME=$(jq -r '.www2ssh.username' "$CONFIG_FILE")
KEYFILE_ORIGINAL=$(jq -r '.www2ssh.keyfile' "$CONFIG_FILE")

# Validierung
if [ -z "$KEYFILE_ORIGINAL" ]; then
    echo "[✗] Fehler: KEYFILE aus config.json ist leer!"
    exit 1
fi

# Angepasster Pfad basierend auf Benutzer
if [ "$(whoami)" = "www-data" ]; then
    KEYFILE="$KEYFILE_ORIGINAL"
else
    KEYFILE="${KEYFILE_ORIGINAL}.root"
fi

# Debug-Ausgabe
echo "[INFO] Verwende SSH-Key: $KEYFILE"

# Lokaler und Remote-Pfad
REMOTE_DIR="/SERVER/htdocs/www/reg/uploads/"
LOCAL_DIR="/WEH/PHP/anmeldung/"

# Überprüfung, ob das lokale Verzeichnis existiert
if [ ! -d "$LOCAL_DIR" ]; then
    echo "[INFO] Lokales Verzeichnis $LOCAL_DIR existiert nicht - wird erstellt..."
    mkdir -p "$LOCAL_DIR"
fi

# Prüfen ob Dateien vorhanden sind
echo "[INFO] Überprüfe, ob Dateien vorhanden sind auf $HOST..."
SSH_CHECK=$(ssh -i "$KEYFILE" -p "$PORT" "$USERNAME@$HOST" "ls -1 $REMOTE_DIR 2>/dev/null | wc -l")

if [ "$SSH_CHECK" -eq 0 ]; then
    echo "[✓] Keine neuen Dateien zum Übertragen - Skript beendet."
    exit 0
fi

# Dateien vom Remote-Server herunterladen
echo "[INFO] Übertrage Anmeldungs-Dateien von $HOST:$REMOTE_DIR nach $LOCAL_DIR ..."
scp -i "$KEYFILE" -P "$PORT" "$USERNAME@$HOST:$REMOTE_DIR*" "$LOCAL_DIR"

if [ $? -eq 0 ]; then
    echo "[✓] Dateiübertragung erfolgreich"

    # Dateien auf dem Remote-Server löschen
    echo "[INFO] Lösche Dateien auf dem Remote-Server..."
    ssh -i "$KEYFILE" -p "$PORT" "$USERNAME@$HOST" "rm -f $REMOTE_DIR*"

    if [ $? -eq 0 ]; then
        echo "[✓] Quell-Dateien erfolgreich gelöscht"
    else
        echo "[✗] Fehler beim Löschen der Quell-Dateien"
        exit 1
    fi

    # Besitzrechte im lokalen Verzeichnis setzen
    echo "[INFO] Setze Besitzrechte im Zielverzeichnis..."
    chown -R www-data:www-data "$LOCAL_DIR"

    if [ $? -eq 0 ]; then
        echo "[✓] Besitzrechte erfolgreich gesetzt"
    else
        echo "[✗] Fehler beim Setzen der Besitzrechte"
        exit 1
    fi
else
    echo "[✗] Keine neuen Anmeldungen oder Fehler beim Kopieren"
    exit 1
fi
