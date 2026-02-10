#!/usr/bin/env python
# -*- coding: utf-8 -*-

# Geschrieben von Fiji
# Oktober 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

"""
Cron-Skript: Prüft täglich per SNMP den Status der Drucker und sendet bei Problemen
eine Mail an net@weh.rwth-aachen.de.

Probleme werden NUR gemeldet, wenn:
- Papier A4 KOMPLETT leer ist (0 Blätter),
- Toner KOMPLETT leer ist (0%),
- oder der hrPrinterStatus NICHT in {idle, printing, warmup} ist (also nicht "waiting/sleeping/printing").

Installationshinweis Cron (Beispiel, täglich 07:30):
  30 7 * * * /usr/bin/env python3 /path/to/monitor_printers.py >/dev/null 2>&1
"""

import re
import subprocess
import shutil
from datetime import datetime
from typing import Optional, Tuple, List, Dict

from fcol import send_mail  # def send_mail(subject, message, to_email, reply_to=None):

# ---------------------------- Konfiguration -------------------------------- #

SNMP_COMMUNITY = "public"
SNMP_TIMEOUT = "2"   # Sekunden
SNMP_RETRIES = "1"

# Druckerliste analog zur PHP-Seite
PRINTERS = [
    {"id": 1, "turm": "WEH", "farbe": "SW",    "modell": "WEH Schwarz-Weiß", "ip": "137.226.141.5",   "name": "WEHsw",    "type": "mono"},
    {"id": 2, "turm": "WEH", "farbe": "Farbe", "modell": "WEH Farbe",        "ip": "137.226.141.193", "name": "WEHfarbe", "type": "color"},
    # {"id": 3, "turm": "TvK", "farbe": "SW",    "modell": "TvK Schwarz-Weiß", "ip": "todo",            "name": "TvKsw",    "type": "mono"},
]

# OIDs
OID_HR_PRINTER_STATUS = "1.3.6.1.2.1.25.3.5.1.1.1"      # hrPrinterStatus
OID_PRT_ALERT_DESC    = "1.3.6.1.2.1.43.16.5.1.2.1.1"    # prtAlertDescription (erste Zeile)

# Papierstände (CurrentLevel)
MONO_A4_TRAYS = [2, 3, 4, 5, 6]
MONO_A4_MAX   = 2500

COLOR_A4_TRAYS = [2, 3, 4]
COLOR_A4_MAX   = 1500

OID_PRT_INPUT_CUR_LVL_TMPL = "1.3.6.1.2.1.43.8.2.1.10.1.{tray}"  # prtInputCurrentLevel

# Tonerstände – Indizes & Skalierung wie im PHP
OID_SUPPLIES_LVL_TMPL      = "1.3.6.1.2.1.43.11.1.1.9.1.{index}"  # prtMarkerSuppliesLevel
MONO_TONER_INDEX_MAX       = {1: 40000}   # Schwarz
COLOR_TONER_INDEX_MAX      = {1: 6000, 2: 6000, 3: 6000, 4: 12000}  # C,M,Y,K
COLOR_NAMES                = {1: "Cyan", 2: "Magenta", 3: "Gelb", 4: "Schwarz"}

# Erlaubte (OK) hrPrinterStatus-Codes laut RFC2790:
# 3 idle (waiting/sleeping), 4 printing, 5 warmup
ALLOWED_PRINTER_STATUS = {3, 4, 5}

# Mail-Empfänger
ALERT_MAIL_TO = "drucker@weh.rwth-aachen.de"
MAIL_SUBJECT_PREFIX = "[Drucker-Monitor]"

# ------------------------------ SNMP Helper -------------------------------- #

def ensure_snmp_tools():
    if shutil.which("snmpget") is None:
        raise RuntimeError("snmpget nicht gefunden. Bitte Net-SNMP Tools installieren.")

def _run_snmp(cmd: List[str]) -> Tuple[bool, str]:
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
        out = (res.stdout or "").strip()
        err = (res.stderr or "").strip()
        if res.returncode == 0 and out:
            return True, out
        return False, (out or err)
    except Exception as e:
        return False, str(e)

def snmpget(ip: str, oid: str) -> Tuple[bool, str]:
    cmd = [
        "snmpget", "-v2c", "-c", SNMP_COMMUNITY, "-t", SNMP_TIMEOUT, "-r", SNMP_RETRIES,
        "-Oqv", ip, oid
    ]
    return _run_snmp(cmd)

def snmpget_int(ip: str, oid: str) -> Optional[int]:
    ok, out = snmpget(ip, oid)
    if not ok:
        return None
    m = re.search(r"-?\d+", out)
    if not m:
        return None
    try:
        return int(m.group(0))
    except ValueError:
        return None

# ---------------------------- Auswertung Helper ----------------------------- #

def pct(value: Optional[int], max_value: int) -> Optional[int]:
    if value is None or value < 0 or max_value <= 0:
        return None
    return int(round((value / float(max_value)) * 100))

def map_hr_printer_status(code: Optional[int]) -> Tuple[str, bool]:
    mapping = {
        1: "other",
        2: "unknown",
        3: "idle",
        4: "printing",
        5: "warmup",
    }
    if code is None:
        return ("unbekannt (SNMP-Fehler)", False)
    txt = mapping.get(code, f"unbekannt ({code})")
    return (txt, code in ALLOWED_PRINTER_STATUS)

def get_alert_desc(ip: str) -> Optional[str]:
    ok, out = snmpget(ip, OID_PRT_ALERT_DESC)
    if not ok:
        return None
    out = out.strip().strip('"').strip()
    if not out or "No Such" in out:
        return None
    return out

def sum_trays(ip: str, trays: List[int]) -> int:
    total = 0
    for t in trays:
        oid = OID_PRT_INPUT_CUR_LVL_TMPL.format(tray=t)
        val = snmpget_int(ip, oid)
        if val and val > 0:
            total += val
    return total

# ------------------------------ Checks je Drucker --------------------------- #

def check_printer(prn: Dict) -> Dict:
    """
    Liefert dict:
      {
        "printer": "...",
        "ip": "...",
        "ok": bool,
        "issues": [str, ...],
        "details": [str, ...]
      }
    """
    result = {
        "printer": f'{prn["modell"]} ({prn["name"]}, {prn["turm"]})',
        "ip": prn.get("ip") or "n/a",
        "ok": True,
        "issues": [],
        "details": []
    }

    ip = prn.get("ip")
    if not ip or ip == "todo":
        result["ok"] = False
        result["issues"].append("Keine IP konfiguriert.")
        return result

    # hrPrinterStatus (nur Problem, wenn NICHT allowed)
    status_code = snmpget_int(ip, OID_HR_PRINTER_STATUS)
    status_txt, status_ok = map_hr_printer_status(status_code)
    result["details"].append(f"Status: {status_txt}")
    if not status_ok:
        result["ok"] = False
        result["issues"].append(f"Statusproblem: {status_txt}")

    # Alert-Description (nur als Detail, KEIN Problem bei 'Sleeping/Waiting/Printing')
    alert = get_alert_desc(ip)
    if alert:
        result["details"].append(f"Alert: {alert}")

    # Papierstände A4 – Problem NUR wenn komplett leer (=0)
    if prn["type"] == "mono":
        a4_current = sum_trays(ip, MONO_A4_TRAYS)
        a4_max = MONO_A4_MAX
    else:
        a4_current = sum_trays(ip, COLOR_A4_TRAYS)
        a4_max = COLOR_A4_MAX
    if a4_current <= 0:
        result["ok"] = False
        result["issues"].append(f"Kein Papier A4: {a4_current}/{a4_max}")
    result["details"].append(f"Papier A4: {a4_current}/{a4_max}")

    # Toner – Problem NUR wenn 0%
    if prn["type"] == "mono":
        for idx, vmax in MONO_TONER_INDEX_MAX.items():
            val = snmpget_int(ip, OID_SUPPLIES_LVL_TMPL.format(index=idx))
            percent = pct(val, vmax)
            if percent is not None:
                result["details"].append(f"Toner Schwarz: {percent}%")
                if percent <= 0:
                    result["ok"] = False
                    result["issues"].append("Toner Schwarz leer (0%)")
            else:
                result["details"].append("Toner Schwarz: n/a")
    else:
        empties = []
        for idx, vmax in COLOR_TONER_INDEX_MAX.items():
            val = snmpget_int(ip, OID_SUPPLIES_LVL_TMPL.format(index=idx))
            percent = pct(val, vmax)
            name = COLOR_NAMES[idx]
            if percent is not None:
                result["details"].append(f"Toner {name}: {percent}%")
                if percent <= 0:
                    empties.append(name)
            else:
                result["details"].append(f"Toner {name}: n/a")
        if empties:
            result["ok"] = False
            result["issues"].append("Toner leer: " + ", ".join(empties))

    return result

# ------------------------------ Mail / Main -------------------------------- #

def build_mail_body(results: List[Dict]) -> Optional[Tuple[str, str]]:
    problems = [r for r in results if not r["ok"]]
    if not problems:
        return None

    date_str = datetime.now().strftime("%Y-%m-%d")
    subject = f"{MAIL_SUBJECT_PREFIX} Probleme erkannt – {date_str}"

    lines = []
    lines.append("Hallo Netzwerk-AG,\n")
    lines.append("Automatischer täglicher Druckercheck hat Probleme erkannt:\n")

    for r in problems:
        lines.append(f"— {r['printer']} @ {r['ip']}")
        for issue in r["issues"]:
            lines.append(f"   • {issue}")
        lines.append("   Details:")
        for d in r["details"]:
            lines.append(f"     - {d}")
        lines.append("")

    lines.append("Viele Grüße\nDrucker-Monitor (Cron)")
    body = "\n".join(lines)
    return subject, body

def main():
    ensure_snmp_tools()

    results = []
    for prn in PRINTERS:
        try:
            r = check_printer(prn)
        except Exception as e:
            r = {
                "printer": f'{prn["modell"]} ({prn["name"]}, {prn["turm"]})',
                "ip": prn.get("ip") or "n/a",
                "ok": False,
                "issues": [f"Ausnahme/Fehler beim Check: {e}"],
                "details": []
            }
        results.append(r)

    mail_data = build_mail_body(results)
    if mail_data:
        subject, body = mail_data
        send_mail(subject, body, ALERT_MAIL_TO)
    # Keine Ausgabe bei "alles ok"

if __name__ == "__main__":
    main()
