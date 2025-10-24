#!/usr/bin/env python3
# -*- coding: utf-8 -*-

# iphistory_cleanup.py
# Aktualisiert/erzeugt IP-History-Einträge aus macauth und natmapping.
# Läuft täglich via Cron. DEBUG=True => verbose + kein Commit (Dry-Run).

import sys
import time
from collections import defaultdict

from fcol import connect_weh

# =======================
# Konfiguration
# =======================
DEBUG = False  # Bei False werden Änderungen committet
AGENT_ID = 472
NOW = int(time.time())

# =======================
# Helpers
# =======================
def log(*args, **kwargs):
    if DEBUG:
        print(*args, **kwargs, file=sys.stderr)

def valid_ts(ts, fallback=NOW):
    try:
        t = int(ts)
        return t if t > 0 else fallback
    except Exception:
        return fallback

def fetchall_dict(cur):
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]

# =======================
# Daten laden
# =======================
def load_current_macauth(conn):
    """
    Liefert aktuelle Zuordnungen aus macauth:
      returns dict[ip] = { 'source':'macauth', 'uid':uid, 'start':tstamp }
    Falls gleiche IP mehrfach vorkommt (sollte nicht), wird die jüngste tstamp bevorzugt.
    """
    cur = conn.cursor()
    cur.execute("SELECT uid, ip, tstamp FROM macauth")
    rows = fetchall_dict(cur)

    ipmap = {}
    for r in rows:
        uid = int(r['uid'])
        ip = str(r['ip']).strip()
        ts = valid_ts(r.get('tstamp'))
        if not ip:
            continue
        if ip not in ipmap or ts > ipmap[ip]['start']:
            ipmap[ip] = {'source': 'macauth', 'uid': uid, 'start': ts}
    log(f"[macauth] geladene IPs: {len(ipmap)}")
    return ipmap

def load_current_natmapping(conn):
    """
    Liefert aktuelle Zuordnungen aus natmapping (genattete Subnetze):
      join users: uid + users.starttime als Start
      returns dict[ip] = { 'source':'natmapping', 'uid':uid, 'start':starttime }
    """
    cur = conn.cursor()
    cur.execute("""
        SELECT u.uid AS uid, n.ip AS ip, u.starttime AS starttime
        FROM natmapping n
        JOIN users u ON n.room = u.room AND n.turm = u.turm AND u.subnet = n.subnet
    """)
    rows = fetchall_dict(cur)

    ipmap = {}
    for r in rows:
        uid = int(r['uid'])
        ip = str(r['ip']).strip()
        ts = valid_ts(r.get('starttime'))
        if not ip:
            continue
        # falls mehrere Zuordnungen, jüngste starttime bevorzugen
        if ip not in ipmap or ts > ipmap[ip]['start']:
            ipmap[ip] = {'source': 'natmapping', 'uid': uid, 'start': ts}
    log(f"[natmapping] geladene IPs: {len(ipmap)}")
    return ipmap

def merge_current_assignments(mac_map, nat_map):
    """
    Merged aktuelle Zuordnungen mit Priorität:
      macauth > natmapping
    Ergebnis: dict[ip] = {'uid':..., 'start':..., 'source':...}
    """
    merged = dict(nat_map)  # baseline
    for ip, v in mac_map.items():
        merged[ip] = v  # override/insert
    log(f"[merge] kombinierte aktuelle IPs: {len(merged)}")
    return merged

def load_active_history(conn):
    """
    Lädt offene (endtime IS NULL/0) History-Einträge je IP.
    Es können mehrere aktive Records pro IP existieren (inkonsistent) -> wir behandeln alle.
    returns:
      by_ip: dict[ip] = [ {id, uid, starttime, endtime} ]
      by_uid_ip: set of (uid, ip) die aktuell aktiv sind
    """
    cur = conn.cursor()
    cur.execute("""
        SELECT id, uid, ip, starttime, endtime
        FROM iphistory
        WHERE (endtime IS NULL OR endtime = 0)
    """)
    rows = fetchall_dict(cur)

    by_ip = defaultdict(list)
    by_uid_ip = set()
    for r in rows:
        ip = str(r['ip']).strip()
        by_ip[ip].append(r)
        by_uid_ip.add((int(r['uid']), ip))
    log(f"[iphistory] aktive Einträge: {len(rows)} für {len(by_ip)} IPs")
    return by_ip, by_uid_ip

# =======================
# Diff planen
# =======================
def plan_changes(current_by_ip, active_by_ip, active_uid_ip):
    """
    Ermittelt:
      - welche aktiven History-Einträge pro IP zu schließen sind (endtime setzen),
      - welche (uid,ip) neu zu eröffnen sind (INSERT).
    Regeln:
      - Wenn es für eine IP aktuell keinen Besitzer gibt -> alle aktiven Records dieser IP schließen.
      - Gibt es einen aktuellen Besitzer:
          - Wenn aktiver Record mit diesem (uid, ip) existiert -> nichts tun.
          - Alle anderen aktiven Records mit gleicher IP -> schließen.
          - Falls kein aktiver Record für (uid,ip) existiert -> INSERT.
    """
    closes = []   # list of {id, ip, uid}
    inserts = []  # list of {uid, ip, start}

    # 1) Alle aktuell aktiven Einträge pro IP gegen "current" spiegeln
    for ip, actives in active_by_ip.items():
        cur = current_by_ip.get(ip)  # None, wenn niemand aktuell diese IP hat
        if not cur:
            # niemand hat diese IP mehr -> alle offenen Records schließen
            for rec in actives:
                closes.append({'id': rec['id'], 'ip': ip, 'uid': rec['uid']})
            continue

        current_uid = int(cur['uid'])
        # Von den aktiven Records alle schließen, die NICHT current_uid entsprechen
        has_active_for_current = False
        for rec in actives:
            if int(rec['uid']) == current_uid:
                has_active_for_current = True
            else:
                closes.append({'id': rec['id'], 'ip': ip, 'uid': rec['uid']})

        # Falls kein aktiver Eintrag für current vorhanden -> einfügen
        if not has_active_for_current:
            inserts.append({'uid': current_uid, 'ip': ip, 'start': cur['start']})

    # 2) IPs, die aktuell vergeben sind, aber KEINEN aktiven Record haben -> INSERT
    for ip, cur in current_by_ip.items():
        if (int(cur['uid']), ip) not in active_uid_ip:
            # Kann bereits in Schritt 1 als INSERT geplant worden sein (wenn IP existierte, aber anderer User aktiv war)
            # Um Duplikate zu vermeiden, prüfen wir:
            if not any(it['uid'] == int(cur['uid']) and it['ip'] == ip for it in inserts):
                inserts.append({'uid': int(cur['uid']), 'ip': ip, 'start': cur['start']})

    return closes, inserts

# =======================
# Änderungen anwenden
# =======================
def apply_changes(conn, closes, inserts):
    cur = conn.cursor()

    # Schließen
    for c in closes:
        log(f"[CLOSE] id={c['id']} ip={c['ip']} uid={c['uid']} endtime={NOW}")
        if not DEBUG:
            cur.execute("UPDATE iphistory SET endtime=%s WHERE id=%s", (NOW, c['id']))

    # Einfügen
    for ins in inserts:
        uid = ins['uid']
        ip = ins['ip']
        start = valid_ts(ins.get('start'), NOW)
        log(f"[INSERT] uid={uid} ip={ip} start={start} tstamp={start} agent={AGENT_ID}")
        if not DEBUG:
            cur.execute("""
                INSERT INTO iphistory (uid, ip, tstamp, starttime, agent)
                VALUES (%s, %s, %s, %s, %s)
            """, (uid, ip, start, start, AGENT_ID))

# =======================
# Main
# =======================
def main():
    db = connect_weh()
    # Explizit AutoCommit aus, wir kontrollieren Transaktion
    try:
        db.autocommit = False  # je nach Connector ggf. ignoriert
    except Exception:
        pass

    log("Verbunden. Lade aktuelle Zuordnungen…")

    mac_map = load_current_macauth(db)
    nat_map = load_current_natmapping(db)
    current = merge_current_assignments(mac_map, nat_map)

    active_by_ip, active_uid_ip = load_active_history(db)

    closes, inserts = plan_changes(current, active_by_ip, active_uid_ip)

    log(f"Geplante CLOSEs: {len(closes)}")
    log(f"Geplante INSERTs: {len(inserts)}")

    try:
        apply_changes(db, closes, inserts)
        if DEBUG:
            db.rollback()
            log("DEBUG aktiv: Änderungen NICHT committet (Rollback).")
        else:
            db.commit()
            log("Änderungen committet.")
    except Exception as e:
        try:
            db.rollback()
        except Exception:
            pass
        print(f"[ERROR] {e}", file=sys.stderr)
        sys.exit(1)
    finally:
        try:
            db.close()
        except Exception:
            pass

if __name__ == "__main__":
    main()
