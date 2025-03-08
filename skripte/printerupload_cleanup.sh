#!/bin/bash

# Verzeichnis mit den hochgeladenen Dateien
UPLOAD_DIR="/WEH/PHP/printuploads"

# Löscht alle Dateien, die älter als 1 Stunde sind
find "$UPLOAD_DIR" -type f -mmin +60 -exec rm -f {} \;

# Löscht alle leeren Ordner im Verzeichnis (falls welche existieren)
find "$UPLOAD_DIR" -mindepth 1 -type d -empty -delete