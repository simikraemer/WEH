#!/usr/bin/env python
# -*- coding: utf-8 -*-

# Geschrieben von Fiji
# November 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

import time
from datetime import datetime
from fcol import send_mail_buchungssystem
from fcol import connect_weh

# DB-Verbindung
wehdb = connect_weh()
wehcursor = wehdb.cursor()

def get_active_code():
    wehcursor.execute(
        """
        SELECT code
        FROM weh.codes
        WHERE title=%s AND active=1
        ORDER BY tstamp DESC
        LIMIT 1
        """,
        ("SpieleAGCode",),
    )
    row = wehcursor.fetchone()
    return row[0] if row else None

def format_room(room_int):
    try:
        return str(int(room_int)).zfill(4)
    except Exception:
        return None

def build_email(room_int, turm_str):
    r = format_room(room_int)
    if not r or not turm_str:
        return None
    return f"z{r}@{turm_str}.rwth-aachen.de"

def find_upcoming_bookings(area_id, window_start, window_end):
    wehcursor.execute(
        """
        SELECT
            e.id,
            e.start_time,
            e.name,
            e.create_by,
            r.room_name,
            u.room    AS user_room,
            u.turm    AS user_turm
        FROM buchungssystem.mrbs_entry AS e
        JOIN buchungssystem.mrbs_room  AS r ON r.id = e.room_id
        JOIN weh.users                 AS u ON u.username = e.create_by
        WHERE
            r.area_id = %s
            AND e.start_time >= %s
            AND e.start_time <  %s
            AND (e.reminded IS NULL OR e.reminded = 0)
        """,
        (area_id, window_start, window_end),
    )
    return wehcursor.fetchall()

def main():
    now_ts = int(time.time())
    next_hour_ts = now_ts + 3600
    area_id = 9 # TvK-Lernraum

    code = get_active_code()
    if not code:
        # Kein aktiver Code -> nichts tun
        return

    bookings = find_upcoming_bookings(area_id, now_ts, next_hour_ts)

    for (entry_id, start_time, title, create_by, room_name, user_room, user_turm) in bookings:
        to_email = build_email(user_room, user_turm)
        if not to_email:
            # Kann keine Zieladresse bauen -> als erinnert markieren mit Hinweis, damit es nicht hängt
            print("Keine Mail vorhanden wtf")
            continue

        start_dt = datetime.fromtimestamp(start_time)
        start_str = start_dt.strftime("%d.%m.%Y %H:%M")

        subject = f"Zugangscode für deine Buchung um {start_dt.strftime('%H:%M')} Uhr"
        message = (
            "Hallo,\n\n"
            "du hast in Kürze eine Buchung im TvK Lernraum.\n\n"
            f"• Titel: {title or '–'}\n"
            f"• Raum:  {room_name or '–'} (Area 9)\n"
            f"• Start: {start_str}\n\n"
            f"Zugangscode: {code}\n\n"
            "Bitte teile den Code nicht öffentlich und schließe die Tür nach Benutzung wieder ab.\n\n"
            "Viele Grüße\n"
            "WEH e.V."
        )

        try:
            send_mail_buchungssystem(subject, message, to_email)
        except Exception as e:
            # Beim Senden nicht erinnern, damit nächster Cronlauf es erneut versucht
            # Aber Info vermerken
            print("WTF")
            continue

    wehdb.commit()

if __name__ == "__main__":
    main()
