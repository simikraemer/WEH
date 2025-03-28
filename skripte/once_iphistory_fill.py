#!/usr/bin/env python
# -*- coding: utf-8 -*-

# Geschrieben von Fiji
# März 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from fcol import connect_weh

wehdb = connect_weh()
print("Verbindung zur WEH-Datenbank hergestellt:", wehdb)
wehcursor = wehdb.cursor()
print("WEH-Datenbankcursor erstellt")

# Agent-ID
AGENT_ID = 2136

# Hilfsfunktion für gültige Timestamps
def valid_tstamp(ts):
    return int(ts) if ts and str(ts).isdigit() and int(ts) > 0 else int(time.time())

# === Erste Abfrage: MACAUTH ===
wehcursor.execute("SELECT uid, ip, tstamp FROM macauth")
macauth_entries = wehcursor.fetchall()

print("\n=== MACAUTH-Einträge ===")
for row in macauth_entries:
    uid, ip, tstamp_raw = row
    tstamp = valid_tstamp(tstamp_raw)

    print(f"UID: {uid:<5} | IP: {ip:<15} | Timestamp: {tstamp}")

    wehcursor.execute("""
        INSERT INTO iphistory (uid, ip, tstamp, starttime, agent)
        VALUES (%s, %s, %s, %s, %s)
    """, (uid, ip, tstamp, tstamp, AGENT_ID))

# === Zweite Abfrage: NATMAPPING ===
wehcursor.execute("""
    SELECT u.uid, n.ip, u.starttime 
    FROM natmapping n 
    JOIN users u ON n.room = u.room AND n.turm = u.turm AND u.subnet = n.subnet
""")
natmapping_entries = wehcursor.fetchall()

print("\n=== NATMAPPING-Einträge ===")
for row in natmapping_entries:
    uid, ip, tstamp_raw = row
    tstamp = valid_tstamp(tstamp_raw)

    print(f"UID: {uid:<5} | IP: {ip:<15} | Startzeit: {tstamp}")

    wehcursor.execute("""
        INSERT INTO iphistory (uid, ip, tstamp, starttime, agent)
        VALUES (%s, %s, %s, %s, %s)
    """, (uid, ip, tstamp, tstamp, AGENT_ID))

# Speichern
wehdb.commit()
print("\nAlle Einträge wurden erfolgreich in iphistory gespeichert.")
