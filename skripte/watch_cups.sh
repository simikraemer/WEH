#!/bin/bash

# CUPS page_log Speicherort
LOGFILE="/var/log/cups/page_log"
PRINTCONFIRM_SCRIPT="/WEH/PHP/PrintConfirm.php"  # Direkt auf dem Server
SYSLOG_TAG="watch_cups"

logger -t "$SYSLOG_TAG" "🖨️ Starte Überwachung von CUPS page_log..."

# Starte die Überwachung von page_log
tail -n 0 -F "$LOGFILE" | while read line; do
    logger -t "$SYSLOG_TAG" "📄 Neue Zeile erkannt: $line"

    # Prüfen, ob die Zeile eine CUPS_ID enthält
    if [[ "$line" =~ CUPS_ID:([0-9]+) ]]; then
        JOB_ID="${BASH_REMATCH[1]}"
        logger -t "$SYSLOG_TAG" "✅ Neuer Job erkannt: $JOB_ID - Starte PrintConfirm.php"

        # PHP-Skript direkt mit Job-ID aufrufen
        php "$PRINTCONFIRM_SCRIPT" "$JOB_ID" 2>&1 | logger -t "$SYSLOG_TAG"
    else
        logger -t "$SYSLOG_TAG" "⚠️ Keine gültige CUPS_ID in dieser Zeile gefunden."
    fi
done
