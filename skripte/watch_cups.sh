#!/bin/bash

# CUPS page_log Speicherort
LOGFILE="/var/log/cups/page_log"
PRINTCONFIRM_SCRIPT="/WEH/PHP/PrintConfirm.php"  # Direkt auf dem Server

echo "🖨️ Überwache CUPS page_log für Druckauftrags-Updates..."

# Starte die Überwachung von page_log
inotifywait -m -e modify "$LOGFILE" | while read file event; do
    echo "📄 page_log wurde aktualisiert!"
    
    # Alle abgeschlossenen Druckaufträge aus page_log auslesen
    JOB_IDS=$(awk '{print $7}' "$LOGFILE" | sort | uniq)
    
    for JOB_ID in $JOB_IDS; do
        echo "✅ Job abgeschlossen: $JOB_ID - Starte PrintConfirm.php lokal"
        
        # PHP-Skript direkt ausführen
        php "$PRINTCONFIRM_SCRIPT" cups_id="$JOB_ID" &
    done
done
