#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
pskabgleich.py

Abgleich bestätigter pskonly-MACs aus der WEH-DB mit einem Cisco AireOS WLC 5500.

Wichtig:
  - Standard ist DRY-RUN. Ohne --apply wird NICHTS am WLC und NICHTS in der DB geändert.
  - Der Cisco-WLC-Spezialfall wird behandelt: Nach erfolgreicher SSH-Authentifizierung
    fragt AireOS im interaktiven Shell-Channel oft nochmal nach "User:" und "Password:".
  - Dafür können optional separate CLI-Login-Daten in config.json gesetzt werden:
      "cli_username" / "cli_password"
    oder:
      "login_username" / "login_password"
  - Mit --terminal-debug wird die WLC-Sitzung quasi wie im Terminal geloggt.
    Das gesendete Passwort wird maskiert.

Voraussetzungen:
  pip3 install paramiko mysql-connector-python

Erwartet:
  - fcol.py liegt im gleichen Verzeichnis.
  - /etc/credentials/config.json enthält wlcweh mit username/password/host.
  - fcol.connect_weh() verbindet zur WEH-Datenbank.
"""

from __future__ import annotations

import argparse
import fcntl
import logging
import os
import re
import socket
import sys
import time
from dataclasses import dataclass
from typing import Dict, Iterable, Optional, Pattern, Set, Tuple

import paramiko

import fcol


# -----------------------------------------------------------------------------
# Feste Zielparameter
# -----------------------------------------------------------------------------

PROFILE_NAME = "weh-pskonly"
WLAN_ID = "2"
INTERFACE_NAME = "vlan919"
WLC_CONFIG_KEY = "wlcweh"
LOCK_FILE = "/tmp/pskabgleich.lock"

# Cisco WLC 5500 / AireOS ist beim initialen Shell-Login oft langsam.
SSH_CONNECT_TIMEOUT_SECONDS = 90
WLC_LOGIN_TIMEOUT_SECONDS = 120
WLC_COMMAND_TIMEOUT_SECONDS = 90
WLC_SAVE_TIMEOUT_SECONDS = 600

# Standard ist bewusst Dry-Run. Live nur mit --apply.
DEFAULT_DRY_RUN = True

# WLC-Beschreibung begrenzen. Lange Texte und Sonderzeichen sind in AireOS-CLI nervig.
DESCRIPTION_MAX_LENGTH = 64

# Nach echten WLC-Änderungen speichern, sofern nicht --no-save gesetzt ist.
SAVE_CONFIG_AFTER_CHANGES = True

# Zusätzlicher roher Output pro Kommando. Normalerweise reicht --terminal-debug.
LOG_RAW_WLC_OUTPUT = False

# Globale Ausgabestufe:
# LOW: kompakte Lauf- und Ergebnisinfos, Warnungen, Fehler.
# HIGH: zusaetzlich Setup-, Verbindungs- und Detailausgaben.
DEBUG_LEVEL_LOW = 1
DEBUG_LEVEL_HIGH = 2
DEBUG_LEVEL = DEBUG_LEVEL_LOW
_ACTIVE_DEBUG_LEVEL = DEBUG_LEVEL


# -----------------------------------------------------------------------------
# Datenmodelle
# -----------------------------------------------------------------------------

@dataclass(frozen=True)
class PskOnlyEntry:
    id: int
    uid: int
    mac_db: str
    mac_wlc: str
    description: str


@dataclass
class RunStats:
    db_pending: int = 0
    invalid_mac: int = 0
    already_on_wlc: int = 0
    added: int = 0
    failed: int = 0
    dry_run_skipped: int = 0
    db_updated: int = 0


# -----------------------------------------------------------------------------
# Logging
# -----------------------------------------------------------------------------

def setup_logging(verbose: bool, terminal_debug: bool, paramiko_debug: bool) -> None:
    global _ACTIVE_DEBUG_LEVEL

    _ACTIVE_DEBUG_LEVEL = DEBUG_LEVEL_HIGH if verbose or terminal_debug else DEBUG_LEVEL
    level = logging.DEBUG if verbose or terminal_debug or paramiko_debug else logging.INFO
    logging.basicConfig(
        level=level,
        format="%(asctime)s [%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    # Paramiko ist sehr laut. Nur mit --paramiko-debug aktivieren.
    logging.getLogger("paramiko").setLevel(logging.DEBUG if paramiko_debug else logging.WARNING)


def debug_high_enabled() -> bool:
    return _ACTIVE_DEBUG_LEVEL >= DEBUG_LEVEL_HIGH


def log_high(message: str, *args) -> None:
    # LOW-Ausgaben bleiben direkte logging.info/warning/error-Aufrufe.
    # HIGH-Ausgaben laufen zentral hierdurch.
    if debug_high_enabled():
        logging.info(message, *args)


def log_section(title: str) -> None:
    if not debug_high_enabled():
        return
    logging.info("=" * 80)
    logging.info(title)
    logging.info("=" * 80)


# -----------------------------------------------------------------------------
# Locking gegen parallele Starts durch Cronjob + Agent-Trigger
# -----------------------------------------------------------------------------

def acquire_lock(lock_file: str):
    fh = open(lock_file, "w", encoding="utf-8")
    try:
        fcntl.flock(fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        logging.warning("Ein anderer pskabgleich.py-Prozess läuft bereits. Beende diesen Lauf sauber.")
        sys.exit(0)

    fh.write(str(os.getpid()))
    fh.truncate()
    fh.flush()
    return fh


# -----------------------------------------------------------------------------
# MAC- und Description-Handling
# -----------------------------------------------------------------------------

MAC_COLON_OR_DASH_RE = re.compile(
    r"\b(?P<mac>[0-9A-Fa-f]{2}([:-])[0-9A-Fa-f]{2}(?:\2[0-9A-Fa-f]{2}){4})\b"
)
MAC_DOT_RE = re.compile(r"\b(?P<mac>[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4})\b")
MAC_PLAIN_RE = re.compile(r"\b(?P<mac>[0-9A-Fa-f]{12})\b")


def normalize_mac_for_compare(mac: str) -> Optional[str]:
    """Gibt aa:bb:cc:dd:ee:ff zurück oder None, wenn ungültig."""
    if mac is None:
        return None
    hex_only = re.sub(r"[^0-9A-Fa-f]", "", mac).lower()
    if len(hex_only) != 12 or not re.fullmatch(r"[0-9a-f]{12}", hex_only):
        return None
    return ":".join(hex_only[i:i + 2] for i in range(0, 12, 2))


def normalize_mac_for_wlc(mac: str) -> str:
    normalized = normalize_mac_for_compare(mac)
    if not normalized:
        raise ValueError(f"Ungültige MAC-Adresse: {mac!r}")
    return normalized.upper()


def extract_first_mac(line: str) -> Optional[Tuple[str, str]]:
    """Extrahiert die erste MAC aus einer Zeile. Rückgabe: (original, normalized)."""
    for regex in (MAC_COLON_OR_DASH_RE, MAC_DOT_RE, MAC_PLAIN_RE):
        match = regex.search(line)
        if match:
            original = match.group("mac")
            normalized = normalize_mac_for_compare(original)
            if normalized:
                return original, normalized
    return None


def sanitize_description(description: Optional[str], entry_id: int, uid: int) -> str:
    """Erstellt eine WLC-taugliche Beschreibung."""
    if description is None:
        description = ""

    desc = str(description).replace("\r", " ").replace("\n", " ").replace("\t", " ")
    desc = re.sub(r"\s+", " ", desc).strip()
    desc = desc.replace('"', "'")

    if not desc:
        desc = f"pskonly-id-{entry_id}-uid-{uid}"

    if len(desc) > DESCRIPTION_MAX_LENGTH:
        desc = desc[:DESCRIPTION_MAX_LENGTH].rstrip()

    return desc


def shell_quote_wlc_description(description: str) -> str:
    """Quoted Description für WLC-CLI."""
    return f'"{description}"'


def tokenized_description(description: str) -> str:
    """
    Fallback, falls die konkrete AireOS-Version quoted descriptions nicht akzeptiert.
    Keine Leerzeichen, keine Quotes.
    """
    token = re.sub(r"[^0-9A-Za-z_.@:+-]+", "_", description).strip("_")
    return token[:DESCRIPTION_MAX_LENGTH] or "pskonly"


# -----------------------------------------------------------------------------
# DB-Funktionen
# -----------------------------------------------------------------------------

def fetch_pending_entries() -> Tuple[list[PskOnlyEntry], int]:
    log_section("DB: Lade bestätigte, noch nicht als WLC-eingetragen markierte pskonly-Einträge für aktive WEH-User")

    db = fcol.connect_weh()
    cursor = db.cursor(dictionary=True)
    try:
        sql = """
            SELECT
                p.id,
                p.uid,
                p.mac,
                p.beschreibung
            FROM pskonly p
            INNER JOIN users u
                ON u.uid = p.uid
            WHERE p.status = 1
              AND p.wlceingetragen = 0
              AND u.turm = 'weh'
              AND u.pid IN (11, 12)
            ORDER BY p.id ASC
        """
        cursor.execute(sql)
        rows = cursor.fetchall()

        entries: list[PskOnlyEntry] = []
        invalid_count = 0

        for row in rows:
            entry_id = int(row["id"])
            uid = int(row["uid"])
            mac_db = str(row["mac"] or "").strip()
            mac_normalized = normalize_mac_for_compare(mac_db)

            if not mac_normalized:
                invalid_count += 1
                logging.error(
                    "DB id=%s uid=%s hat ungültige MAC %r. Wird nicht verarbeitet.",
                    entry_id,
                    uid,
                    mac_db,
                )
                continue

            mac_wlc = normalize_mac_for_wlc(mac_db)
            description = sanitize_description(row.get("beschreibung"), entry_id, uid)
            entries.append(PskOnlyEntry(entry_id, uid, mac_db, mac_wlc, description))

        logging.info(
            "DB: %s offene Einträge gefunden, davon %s valide und %s ungültig.",
            len(rows),
            len(entries),
            invalid_count,
        )
        return entries, invalid_count
    finally:
        cursor.close()
        db.close()


def mark_entry_as_wlc_inserted(entry: PskOnlyEntry) -> bool:
    db = fcol.connect_weh()
    cursor = db.cursor()
    try:
        sql = """
            UPDATE pskonly p
            INNER JOIN users u
                ON u.uid = p.uid
            SET p.wlceingetragen = 1
            WHERE p.id = %s
              AND p.status = 1
              AND u.turm = 'weh'
              AND u.pid IN (11, 12)
        """
        cursor.execute(sql, (entry.id,))
        db.commit()

        if cursor.rowcount == 1:
            log_high("DB: id=%s uid=%s mac=%s -> wlceingetragen=1 gesetzt.", entry.id, entry.uid, entry.mac_wlc)
            return True

        logging.warning(
            "DB: id=%s uid=%s mac=%s konnte nicht aktualisiert werden. rowcount=%s. Status evtl. geändert?",
            entry.id,
            entry.uid,
            entry.mac_wlc,
            cursor.rowcount,
        )
        return False
    except Exception:
        db.rollback()
        logging.exception("DB: Fehler beim Update von id=%s mac=%s.", entry.id, entry.mac_wlc)
        return False
    finally:
        cursor.close()
        db.close()


# -----------------------------------------------------------------------------
# WLC SSH Client
# -----------------------------------------------------------------------------

class WlcClient:
    def __init__(
        self,
        host: str,
        username: str,
        password: str,
        cli_username: Optional[str] = None,
        cli_password: Optional[str] = None,
        port: int = 22,
        timeout: int = SSH_CONNECT_TIMEOUT_SECONDS,
        terminal_debug: bool = False,
    ):
        self.host = host
        self.username = username
        self.password = password
        self.cli_username = cli_username or username
        self.cli_password = cli_password or password
        self.port = port
        self.timeout = timeout
        self.terminal_debug = terminal_debug
        self.client: Optional[paramiko.SSHClient] = None
        self.channel: Optional[paramiko.Channel] = None

        # AireOS-Prompt sieht typischerweise so aus:
        #   (Cisco Controller) >
        #   (wlc-name) >
        self.prompt_re = re.compile(r"(?:^|[\r\n])\s*\([^\r\n]+\)\s*>\s*$")
        self.user_prompt_re = re.compile(r"User\s*:\s*$", re.IGNORECASE)
        self.password_prompt_re = re.compile(r"Password\s*:\s*$", re.IGNORECASE)

    def connect(self) -> None:
        log_section(f"WLC: Verbinde per SSH mit {self.host}:{self.port}")
        logging.info("SSH-Verbindung mit WLC wird hergestellt...")

        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(
            hostname=self.host,
            port=self.port,
            username=self.username,
            password=self.password,
            timeout=self.timeout,
            banner_timeout=self.timeout,
            auth_timeout=self.timeout,
            look_for_keys=False,
            allow_agent=False,
        )

        log_high("WLC: SSH-Transport authentifiziert. Öffne interaktive Shell.")
        channel = client.invoke_shell(term="vt100", width=200, height=2000)
        channel.settimeout(0.0)

        self.client = client
        self.channel = channel

        self._complete_aireos_login(timeout=WLC_LOGIN_TIMEOUT_SECONDS)

        self.send_command("config paging disable", timeout=30)
        log_high("WLC: Paging deaktiviert.")

    def close(self) -> None:
        if self.channel is not None:
            try:
                self._send_raw("logout" + chr(10))
            except Exception:
                pass
            try:
                self.channel.close()
            except Exception:
                pass
        if self.client is not None:
            self.client.close()
        log_high("WLC: SSH-Verbindung geschlossen.")

    def _complete_aireos_login(self, timeout: int) -> None:
        """
        Behandelt den zweiten interaktiven AireOS-Login im Shell-Channel.
        Nach SSH-Auth kann der Controller nochmal so fragen:

            (Cisco Controller)
            User:
            Password:
            (Cisco Controller) >
        """
        log_high("WLC: Warte auf AireOS-Prompt oder interaktive User/Password-Abfrage.")

        event, output = self._read_until_event(timeout=timeout, context="initialer AireOS-Login")

        if event == "prompt":
            log_high("WLC: Prompt direkt erkannt, kein zweiter Login nötig.")
            return

        if event == "password":
            # Selten, aber möglich, wenn der Controller aus irgendeinem Grund direkt Passwort will.
            log_high("WLC: Interaktive Password-Abfrage ohne User-Prompt erkannt, sende Passwort.")
            self._send_raw(self.cli_password + chr(10), sensitive=True)
            event, output = self._read_until_event(timeout=timeout, context="AireOS Prompt nach Password")
        elif event == "user":
            log_high("WLC: Interaktive User-Abfrage erkannt, sende Username.")
            self._send_raw(self.cli_username + chr(10))
            event, output = self._read_until_event(timeout=timeout, context="AireOS Password-Prompt nach User")

            if event == "password":
                log_high("WLC: Interaktive Password-Abfrage erkannt, sende Passwort.")
                self._send_raw(self.cli_password + chr(10), sensitive=True)
                event, output = self._read_until_event(timeout=timeout, context="AireOS Prompt nach Password")

        if event == "prompt":
            log_high("WLC: AireOS-Login abgeschlossen, Prompt erkannt.")
            return

        if event in {"user", "password"}:
            raise RuntimeError(
                "WLC: Login scheint fehlgeschlagen zu sein; Controller fragt erneut nach User/Password. "
                "Mit --terminal-debug prüfen, ob User/Passwort abgelehnt werden oder ob ein anderer Prompt erscheint. "
                f"Letzter Output:\n{output[-2000:]}"
            )

        raise RuntimeError(f"WLC: Nach Login kein Controller-Prompt erkannt. Letzter Output:\n{output[-2000:]}")

    def _send_raw(self, data: str, sensitive: bool = False) -> None:
        if self.channel is None:
            raise RuntimeError("WLC channel not connected")
        self._terminal_log_tx(data, sensitive=sensitive)
        self.channel.send(data)

    def _terminal_log_rx(self, data: str) -> None:
        if not self.terminal_debug:
            return
        cleaned = data.replace("\r", "\n")
        cleaned = re.sub(r"\n{3,}", "\n\n", cleaned)
        if cleaned.strip():
            logging.debug("WLC RX <<<\n%s", cleaned.rstrip())

    def _terminal_log_tx(self, data: str, sensitive: bool = False) -> None:
        if not self.terminal_debug:
            return
        if sensitive:
            shown = "********"
        else:
            shown = data.replace(chr(13), "<CR>").replace(chr(10), "<LF>")
        logging.debug("WLC TX >>> %s", shown)

    def _clean_tail(self, output: str, length: int = 500) -> str:
        return output.replace("\r", "\n")[-length:]

    def _detect_event(self, output: str) -> Optional[str]:
        tail = self._clean_tail(output)
        stripped_tail = tail.rstrip()

        if self.prompt_re.search(stripped_tail):
            return "prompt"

        last_line = stripped_tail.split("\n")[-1].strip() if stripped_tail else ""
        if self.user_prompt_re.search(last_line):
            return "user"
        if self.password_prompt_re.search(last_line):
            return "password"

        lower_tail = tail.lower()
        if "login incorrect" in lower_tail or "authentication failed" in lower_tail or "invalid username" in lower_tail:
            return "login_failed"

        return None

    def _read_until_event(self, timeout: int, context: str) -> Tuple[str, str]:
        """
        Liest aus dem interaktiven Channel, bis ein relevanter Zustand erkannt wird:
        prompt, user, password oder login_failed.
        """
        if self.channel is None:
            raise RuntimeError("WLC channel not connected")

        end_time = time.time() + timeout
        output = ""
        last_data_time = time.time()

        while time.time() < end_time:
            try:
                if self.channel.recv_ready():
                    data = self.channel.recv(65535).decode("utf-8", errors="replace")
                    output += data
                    last_data_time = time.time()
                    self._terminal_log_rx(data)
                    self._handle_paging_if_needed(output)
                else:
                    if output and time.time() - last_data_time >= 0.20:
                        event = self._detect_event(output)
                        if event:
                            if LOG_RAW_WLC_OUTPUT:
                                logging.debug("WLC OUT until %s:\n%s", context, output)
                            return event, output
                    time.sleep(0.05)
            except socket.timeout:
                time.sleep(0.05)

        raise TimeoutError(f"Timeout beim Warten auf {context} nach {timeout}s. Bisheriger Output:\n{output[-2000:]}")

    def _read_until_prompt(self, timeout: int = WLC_COMMAND_TIMEOUT_SECONDS) -> str:
        event, output = self._read_until_event(timeout=timeout, context="WLC-Prompt")
        if event != "prompt":
            raise RuntimeError(f"WLC: Statt Command-Prompt wurde {event!r} erkannt. Output:\n{output[-2000:]}")
        return output

    def _handle_paging_if_needed(self, output: str) -> None:
        lower_tail = output.lower()[-160:]
        if "press enter to continue" in lower_tail or "--more--" in lower_tail:
            self._send_raw(chr(10))

    def send_command(self, command: str, timeout: int = WLC_COMMAND_TIMEOUT_SECONDS) -> str:
        if self.channel is None:
            raise RuntimeError("WLC channel not connected")

        logging.debug("WLC CMD > %s", command)
        self._send_raw(command + chr(10))
        output = self._read_until_prompt(timeout=timeout)

        if LOG_RAW_WLC_OUTPUT:
            logging.debug("WLC OUT for %r:\n%s", command, output)

        return output

    def save_config(self) -> str:
        if self.channel is None:
            raise RuntimeError("WLC channel not connected")

        logging.info("WLC: Speichere Konfiguration mit 'save config'.")
        self._send_raw("save config" + chr(10))

        end_time = time.time() + WLC_SAVE_TIMEOUT_SECONDS
        output = ""
        sent_yes = False
        success_seen = False
        last_data_time = time.time()
        last_wait_log = time.time()

        success_markers = (
            "configuration saved",
            "config saved",
            "save complete",
            "successfully saved",
            "configuration file saved",
            "configuration has been saved",
        )

        while time.time() < end_time:
            if self.channel.recv_ready():
                data = self.channel.recv(65535).decode("utf-8", errors="replace")
                output += data
                last_data_time = time.time()
                self._terminal_log_rx(data)
                low = output.lower()

                if not sent_yes and (
                    "are you sure" in low
                    or "save configuration" in low
                    or "configuration will be saved" in low
                    or "y/n" in low
                    or "yes/no" in low
                ):
                    logging.debug("WLC: save config fragt nach Bestätigung, sende 'y'.")
                    self._send_raw("y" + chr(10))
                    sent_yes = True

                if any(marker in low for marker in success_markers):
                    success_seen = True

                event = self._detect_event(output)
                if event == "prompt":
                    log_high("WLC: save config abgeschlossen, Prompt erkannt.")
                    if LOG_RAW_WLC_OUTPUT:
                        logging.debug("WLC OUT for save config:%s%s", chr(10), output)
                    return output
            else:
                now = time.time()

                if success_seen and now - last_data_time >= 3:
                    log_high("WLC: save config meldete Erfolg; kein weiterer Output seit 3s. Fahre fort.")
                    return output

                if now - last_wait_log >= 15:
                    remaining = int(max(0, end_time - now))
                    log_high("WLC: Warte noch auf Abschluss von save config. Resttimeout ca. %ss", remaining)
                    last_wait_log = now

                time.sleep(0.2)

        raise TimeoutError(f"Timeout bei save config nach {WLC_SAVE_TIMEOUT_SECONDS}s. Bisheriger Output:{chr(10)}{output[-2000:]}")


# -----------------------------------------------------------------------------
# WLC MAC-Filter-Funktionen
# -----------------------------------------------------------------------------

def parse_macfilter_summary(output: str) -> Dict[str, Set[str]]:
    """
    Parst 'show macfilter summary'.

    Rückgabe:
      normalized_mac -> set(WLAN IDs), z.B. {'aa:bb:cc:dd:ee:ff': {'2'}}
    """
    result: Dict[str, Set[str]] = {}

    for raw_line in output.splitlines():
        line = raw_line.strip()
        extracted = extract_first_mac(line)
        if not extracted:
            continue

        mac_original, mac_normalized = extracted
        after_mac = line[line.find(mac_original) + len(mac_original):].strip()
        wlan_id = "UNKNOWN"
        if after_mac:
            wlan_id = after_mac.split()[0]

        result.setdefault(mac_normalized, set()).add(wlan_id)

    return result


def get_wlc_macfilters(wlc: WlcClient) -> Dict[str, Set[str]]:
    log_high("WLC: Lese vorhandene MAC-Filter per 'show macfilter summary'.")
    output = wlc.send_command("show macfilter summary", timeout=WLC_COMMAND_TIMEOUT_SECONDS)
    macfilters = parse_macfilter_summary(output)
    log_high("WLC: %s MAC-Filter-Einträge aus Summary geparst.", len(macfilters))
    return macfilters


def is_registered_for_target_wlan(mac_wlc: str, macfilters: Dict[str, Set[str]]) -> bool:
    mac_norm = normalize_mac_for_compare(mac_wlc)
    if not mac_norm:
        return False

    wlan_ids = {str(x).strip().lower() for x in macfilters.get(mac_norm, set())}
    return WLAN_ID.lower() in wlan_ids or "any" in wlan_ids


def build_add_command(entry: PskOnlyEntry, quoted: bool) -> str:
    desc = shell_quote_wlc_description(entry.description) if quoted else tokenized_description(entry.description)

    # IP Address bleibt absichtlich leer: Der optionale IP-Parameter wird nicht angehängt.
    return f"config macfilter add {entry.mac_wlc} {WLAN_ID} {INTERFACE_NAME} {desc}"


def build_add_command_uid_description(entry: PskOnlyEntry) -> str:
    """
    Letzter Fallback für AireOS-Fälle, in denen der Controller die normale
    Description ablehnt. Dann wird nur die UID als Description gesetzt.
    """
    return f"config macfilter add {entry.mac_wlc} {WLAN_ID} {INTERFACE_NAME} {entry.uid}"


def output_looks_like_error(output: str) -> bool:
    low = output.lower()
    error_markers = [
        "invalid",
        "error",
        "incorrect",
        "failed",
        "not found",
        "usage:",
        "unable",
    ]
    return any(marker in low for marker in error_markers)


def add_macfilter(wlc: WlcClient, entry: PskOnlyEntry, dry_run: bool) -> bool:
    log_high(
        "WLC: id=%s uid=%s mac=%s wird hinzugefügt: WLAN_ID=%s Profile=%s Interface=%s Description=%r",
        entry.id,
        entry.uid,
        entry.mac_wlc,
        WLAN_ID,
        PROFILE_NAME,
        INTERFACE_NAME,
        entry.description,
    )

    primary_command = build_add_command(entry, quoted=True)
    fallback_command = build_add_command(entry, quoted=False)
    uid_description_command = build_add_command_uid_description(entry)

    if dry_run:
        log_high("DRY-RUN: Würde ausführen: %s", primary_command)
        if fallback_command != primary_command:
            logging.debug("DRY-RUN: Fallback bei Quote-Problemen wäre: %s", fallback_command)
        return False

    primary_output = wlc.send_command(primary_command, timeout=WLC_COMMAND_TIMEOUT_SECONDS)
    if output_looks_like_error(primary_output):
        logging.warning("WLC: Primärer Add-Befehl meldet evtl. Fehler für id=%s mac=%s.", entry.id, entry.mac_wlc)
        logging.warning("WLC: Output des primären Add-Befehls:%s%s", chr(10), primary_output[-2000:])

    # Direkt danach Summary prüfen. Wenn erfolgreich, ist die MAC dort vorhanden.
    macfilters = get_wlc_macfilters(wlc)
    if is_registered_for_target_wlan(entry.mac_wlc, macfilters):
        log_high("WLC: Add bestätigt für id=%s mac=%s.", entry.id, entry.mac_wlc)
        return True

    # Fallback für AireOS-Versionen, die Quotes bei Description nicht mögen.
    if fallback_command != primary_command:
        logging.warning(
            "WLC: MAC nach quoted Add nicht gefunden. Fallback mit tokenisierter Description für id=%s mac=%s.",
            entry.id,
            entry.mac_wlc,
        )
        log_high("WLC: Fallback-Befehl: %s", fallback_command)
        fallback_output = wlc.send_command(fallback_command, timeout=WLC_COMMAND_TIMEOUT_SECONDS)
        if output_looks_like_error(fallback_output):
            logging.warning("WLC: Fallback-Add-Befehl meldet evtl. Fehler für id=%s mac=%s.", entry.id, entry.mac_wlc)
            logging.warning("WLC: Output des Fallback-Add-Befehls:%s%s", chr(10), fallback_output[-2000:])

        macfilters = get_wlc_macfilters(wlc)
        if is_registered_for_target_wlan(entry.mac_wlc, macfilters):
            log_high("WLC: Fallback-Add bestätigt für id=%s mac=%s.", entry.id, entry.mac_wlc)
            return True

    logging.warning(
        "WLC: Description wurde für id=%s mac=%s offenbar abgelehnt. Letzter Fallback: Add mit UID als Description.",
        entry.id,
        entry.mac_wlc,
    )
    log_high("WLC: UID-Description-Befehl: %s", uid_description_command)
    uid_description_output = wlc.send_command(uid_description_command, timeout=WLC_COMMAND_TIMEOUT_SECONDS)
    if output_looks_like_error(uid_description_output):
        logging.warning("WLC: Add mit UID-Description meldet evtl. Fehler für id=%s mac=%s.", entry.id, entry.mac_wlc)
        logging.warning("WLC: Output des UID-Description-Add-Befehls:%s%s", chr(10), uid_description_output[-2000:])

    macfilters = get_wlc_macfilters(wlc)
    if is_registered_for_target_wlan(entry.mac_wlc, macfilters):
        log_high("WLC: Add mit UID-Description bestätigt für id=%s mac=%s.", entry.id, entry.mac_wlc)
        return True

    logging.error("WLC: Add konnte nicht bestätigt werden für id=%s uid=%s mac=%s.", entry.id, entry.uid, entry.mac_wlc)
    logging.error("WLC: Primärer Add-Befehl war: %s", primary_command)
    logging.error("WLC: Letzter Output primärer Add-Befehl:%s%s", chr(10), primary_output[-2000:])
    if fallback_command != primary_command:
        logging.error("WLC: Fallback-Add-Befehl war: %s", fallback_command)
        if 'fallback_output' in locals():
            logging.error("WLC: Letzter Output Fallback-Add-Befehl:%s%s", chr(10), fallback_output[-2000:])
    logging.error("WLC: UID-Description-Add-Befehl war: %s", uid_description_command)
    if 'uid_description_output' in locals():
        logging.error("WLC: Letzter Output UID-Description-Add-Befehl:%s%s", chr(10), uid_description_output[-2000:])
    return False


def log_run_summary(stats: RunStats, dry_run: bool) -> None:
    log_section("Fertig pskabgleich.py")

    if stats.failed:
        logging.error(
            "PSK-Abgleich fertig: %s Fehler bei %s offenen validen Einträgen.",
            stats.failed,
            stats.db_pending,
        )
    elif dry_run:
        logging.info(
            "PSK-Abgleich fertig: Dry-run, %s offene valide Einträge geprüft, keine Änderungen geschrieben.",
            stats.db_pending,
        )
    elif stats.added > 0:
        logging.info(
            "PSK-Abgleich fertig: %s MAC(s) neu auf dem WLC eingetragen, %s DB-Update(s) gesetzt.",
            stats.added,
            stats.db_updated,
        )
    elif stats.already_on_wlc > 0:
        logging.info(
            "PSK-Abgleich fertig: %s MAC(s) waren bereits auf dem WLC vorhanden, %s DB-Update(s) gesetzt.",
            stats.already_on_wlc,
            stats.db_updated,
        )
    else:
        logging.info("PSK-Abgleich fertig: Keine Änderungen nötig.")

    if stats.invalid_mac > 0:
        logging.warning("Ungültige MACs übersprungen: %s", stats.invalid_mac)

    log_high("DB offene valide Einträge: %s", stats.db_pending)
    log_high("Ungültige MACs: %s", stats.invalid_mac)
    log_high("Bereits auf WLC vorhanden: %s", stats.already_on_wlc)
    log_high("Neu auf WLC hinzugefügt: %s", stats.added)
    log_high("DB Updates wlceingetragen=1: %s", stats.db_updated)
    log_high("Dry-run übersprungen: %s", stats.dry_run_skipped)
    log_high("Fehler: %s", stats.failed)


# -----------------------------------------------------------------------------
# Hauptlogik
# -----------------------------------------------------------------------------

def run(dry_run: bool, verbose: bool, terminal_debug: bool, no_save: bool) -> int:
    stats = RunStats()

    log_section("Start pskabgleich.py")
    """ logging.info("Ziel: Profile=%s WLAN_ID=%s Interface=%s", PROFILE_NAME, WLAN_ID, INTERFACE_NAME)
    logging.info(
        "Modus: dry_run=%s verbose=%s terminal_debug=%s save_config=%s",
        dry_run,
        verbose,
        terminal_debug,
        not no_save and SAVE_CONFIG_AFTER_CHANGES,
    ) """

    if dry_run:
        logging.info("DRY-RUN ist aktiv: Es werden keine WLC-Änderungen und keine DB-Updates geschrieben.")
    else:
        logging.info("LIVE-MODUS ist aktiv: WLC-Änderungen und DB-Updates werden geschrieben.")

    lock_handle = acquire_lock(LOCK_FILE)
    try:
        entries, invalid_count = fetch_pending_entries()
        stats.db_pending = len(entries)
        stats.invalid_mac = invalid_count

        if not entries:
            logging.info("Keine validen offenen DB-Einträge. Kein WLC-Abgleich nötig.")
            return 0

        wlc_config = fcol.readconfig(WLC_CONFIG_KEY)
        host = wlc_config["host"]
        username = wlc_config["username"]
        password = wlc_config["password"]

        # SSH-Transport-Login und AireOS-CLI-Login können auf manchen WLCs
        # unterschiedlich behandelt werden. Standard: gleiche Daten verwenden.
        # Optional in /etc/credentials/config.json unter wlcweh setzen:
        #   "cli_username": "...",
        #   "cli_password": "..."
        # oder:
        #   "login_username": "...",
        #   "login_password": "..."
        cli_username = wlc_config.get("cli_username") or wlc_config.get("login_username") or username
        cli_password = wlc_config.get("cli_password") or wlc_config.get("login_password") or password
        port = int(wlc_config.get("port", 22))

        if cli_username != username:
            log_high("WLC: SSH-Username und AireOS-CLI-Username unterscheiden sich. CLI-Username=%s", cli_username)

        wlc = WlcClient(
            host=host,
            username=username,
            password=password,
            cli_username=cli_username,
            cli_password=cli_password,
            port=port,
            terminal_debug=terminal_debug,
        )
        changed_wlc = False

        try:
            wlc.connect()
            macfilters = get_wlc_macfilters(wlc)

            for entry in entries:
                log_high("-" * 80)
                log_high(
                    "Prüfe DB id=%s uid=%s mac_db=%r mac_wlc=%s desc=%r",
                    entry.id,
                    entry.uid,
                    entry.mac_db,
                    entry.mac_wlc,
                    entry.description,
                )

                mac_norm = normalize_mac_for_compare(entry.mac_wlc)
                existing_wlan_ids = macfilters.get(mac_norm or "", set())

                if is_registered_for_target_wlan(entry.mac_wlc, macfilters):
                    stats.already_on_wlc += 1
                    log_high(
                        "WLC: MAC %s ist bereits für WLAN_ID=%s vorhanden. Gefundene WLAN-IDs: %s",
                        entry.mac_wlc,
                        WLAN_ID,
                        sorted(existing_wlan_ids),
                    )
                    if not dry_run and mark_entry_as_wlc_inserted(entry):
                        stats.db_updated += 1
                    elif dry_run:
                        log_high("DRY-RUN: Würde DB id=%s auf wlceingetragen=1 setzen.", entry.id)
                        stats.dry_run_skipped += 1
                    continue

                if existing_wlan_ids:
                    logging.warning(
                        "WLC: MAC %s existiert bereits, aber nicht für Ziel-WLAN %s. Gefundene WLAN-IDs: %s. Würde Add für Ziel-WLAN versuchen.",
                        entry.mac_wlc,
                        WLAN_ID,
                        sorted(existing_wlan_ids),
                    )

                added_ok = add_macfilter(wlc, entry, dry_run=dry_run)
                if dry_run:
                    stats.dry_run_skipped += 1
                    continue

                if added_ok:
                    changed_wlc = True
                    stats.added += 1
                    if mark_entry_as_wlc_inserted(entry):
                        stats.db_updated += 1
                    # Cache aktualisieren, damit spätere Duplikate im gleichen Lauf sauber erkannt werden.
                    macfilters = get_wlc_macfilters(wlc)
                else:
                    stats.failed += 1

            if changed_wlc and SAVE_CONFIG_AFTER_CHANGES and not no_save and not dry_run:
                wlc.save_config()
                logging.info("WLC: Konfiguration gespeichert.")
            elif changed_wlc and no_save:
                logging.warning("WLC: Änderungen wurden vorgenommen, aber wegen --no-save nicht gespeichert.")

        finally:
            wlc.close()

    finally:
        try:
            fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)
            lock_handle.close()
        except Exception:
            pass

    log_run_summary(stats, dry_run=dry_run)

    return 1 if stats.failed else 0


def parse_args(argv: Iterable[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Abgleich weh.pskonly -> Cisco WLC MAC Filter")
    parser.add_argument(
        "--apply",
        action="store_true",
        help="LIVE ausführen: WLC ändern und wlceingetragen=1 in der DB setzen. Ohne --apply läuft das Skript als Dry-Run.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Explizit Dry-Run erzwingen. Ist aktuell ohnehin der Standard.",
    )
    parser.add_argument("--verbose", "-v", action="store_true", help="Debug-Ausgaben aktivieren.")
    parser.add_argument(
        "--terminal-debug",
        action="store_true",
        help="WLC-Terminal-Trace ausgeben. Passwort wird maskiert. --verbose aktiviert das ebenfalls.",
    )
    parser.add_argument(
        "--paramiko-debug",
        action="store_true",
        help="Zusätzlich sehr laute Paramiko-SSH-Debugausgaben aktivieren.",
    )
    parser.add_argument("--no-save", action="store_true", help="Nach WLC-Änderungen kein 'save config' ausführen.")
    return parser.parse_args(list(argv))


def main() -> int:
    args = parse_args(sys.argv[1:])

    # Standard: Dry-Run. Nur --apply schaltet live.
    # Falls aus Versehen --apply --dry-run gesetzt wird, gewinnt Dry-Run.
    dry_run = DEFAULT_DRY_RUN
    if args.apply:
        dry_run = False
    if args.dry_run:
        dry_run = True

    terminal_debug = args.terminal_debug or args.verbose
    setup_logging(verbose=args.verbose, terminal_debug=terminal_debug, paramiko_debug=args.paramiko_debug)

    try:
        return run(
            dry_run=dry_run,
            verbose=args.verbose,
            terminal_debug=terminal_debug,
            no_save=args.no_save,
        )
    except KeyboardInterrupt:
        logging.warning("Abbruch per KeyboardInterrupt.")
        return 130
    except Exception:
        logging.exception("Fataler Fehler in pskabgleich.py")
        return 2


if __name__ == "__main__":
    sys.exit(main())
