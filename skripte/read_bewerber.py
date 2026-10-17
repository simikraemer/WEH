#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Liest Bewerber-Nationalitaeten aus /WEH/PHP/bapolls/*.pdf.

Die JSON enthaelt AUSSCHLIESSLICH:
  {"weh_123_1234567890.pdf": {"1": "de", "2": "tr", ...}, ...}

Ein PDF wird nur gespeichert, wenn jede Bewerbernummer eine eindeutig
zugeordnete Nationalitaet hat UND die Anzahl zur Tabelle weh.bapolls passt.
Bewerbernamen, Kontaktdaten und Portal-IDs werden nie gespeichert oder geloggt.

Aufruf: python3 /WEH/skripte/read_bewerber.py [--verbose] [--dry-run] [--marshall]
--marshall: Marshallinseln-Faelle diagnostizieren, nie JSON ueberschreiben.
Voraussetzungen: PyMuPDF (fitz), mysql-connector-python (bereits fuer fcol.py).
"""

import argparse
from collections import Counter, defaultdict
from dataclasses import dataclass, field
import difflib
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import unicodedata


HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
DEFAULT_PDF_DIR = ROOT / "PHP" / "bapolls"
DEFAULT_COUNTRY_JSON = ROOT / "PHP" / "flag-icons" / "country.json"
DEFAULT_OUTPUT = HERE / "bewerber_nationalitaeten.json"

RE_APPLICANT = re.compile(r"(?im)^\s*Bewerber\s*(?:Nr\.?\s*)?(\d+)\s*$")
RE_PORTAL = re.compile(r"https?://[^\s]*bewerberportal[^\s]*/(?:de|en)/search/(\d+)", re.I)
RE_PAGE_MARK = re.compile(r"(?m)(\d+)\s*/\s*(\d+)\s*$")
RE_NATIONALITY = re.compile(
    r"(?i)^\s*(?:nationalit(?:ät|aet|at)|nationality)\s*(?:[:\-]\s*)?(.*)\s*$"
)

# Deutschnamen und haeufige Varianten. Englische offizielle Namen stammen
# zusaetzlich AUTOMATISCH aus PHP/flag-icons/country.json.
# WICHTIG: keine unpraezisen Zuordnungen wie "Kongo" oder "Korea".
GERMAN_ALIASES = {
    "af": "Afghanistan",
    "al": "Albanien",
    "dz": "Algerien",
    "ad": "Andorra",
    "ao": "Angola",
    "ag": "Antigua und Barbuda",
    "ar": "Argentinien",
    "am": "Armenien",
    "au": "Australien",
    "at": "Österreich",
    "az": "Aserbaidschan",
    "bs": "Bahamas",
    "bh": "Bahrain",
    "bd": "Bangladesch",
    "bb": "Barbados",
    "by": "Belarus|Weißrussland|Weissrussland",
    "be": "Belgien",
    "bz": "Belize",
    "bj": "Benin",
    "bt": "Bhutan",
    "bo": "Bolivien",
    "ba": "Bosnien und Herzegowina|Bosnien-Herzegowina",
    "bw": "Botswana",
    "br": "Brasilien",
    "bn": "Brunei|Brunei Darussalam",
    "bg": "Bulgarien",
    "bf": "Burkina Faso",
    "bi": "Burundi",
    "cv": "Kap Verde|Cabo Verde|Kapverden",
    "kh": "Kambodscha",
    "cm": "Kamerun",
    "ca": "Kanada",
    "cf": "Zentralafrikanische Republik",
    "td": "Tschad",
    "cl": "Chile",
    "cn": "China|Volksrepublik China",
    "co": "Kolumbien",
    "km": "Komoren",
    "cr": "Costa Rica",
    "hr": "Kroatien",
    "cu": "Kuba",
    "cy": "Zypern",
    "cz": "Tschechien|Tschechische Republik",
    "ci": "Elfenbeinküste|Côte d’Ivoire|Cote d'Ivoire",
    "cd": "Demokratische Republik Kongo|Kongo-Kinshasa|DR Kongo|Kongo (Demokratische Republik)",
    "cg": "Republik Kongo|Kongo-Brazzaville|Kongo (Republik)",
    "dk": "Dänemark",
    "dj": "Dschibuti",
    "dm": "Dominica",
    "do": "Dominikanische Republik",
    "ec": "Ecuador|Ekuador",
    "eg": "Ägypten",
    "sv": "El Salvador",
    "gq": "Äquatorialguinea",
    "er": "Eritrea",
    "ee": "Estland",
    "sz": "Eswatini|Swasiland",
    "et": "Äthiopien",
    "fj": "Fidschi",
    "fi": "Finnland",
    "fr": "Frankreich",
    "ga": "Gabun",
    "gm": "Gambia",
    "ge": "Georgien",
    "de": "Deutschland|Bundesrepublik Deutschland|BRD|Germany",
    "gh": "Ghana",
    "gr": "Griechenland",
    "gt": "Guatemala",
    "gn": "Guinea",
    "gw": "Guinea-Bissau",
    "gy": "Guyana",
    "ht": "Haiti",
    "hn": "Honduras",
    "hu": "Ungarn",
    "is": "Island",
    "in": "Indien",
    "id": "Indonesien",
    "ir": "Iran|Islamische Republik Iran",
    "iq": "Irak",
    "ie": "Irland",
    "il": "Israel",
    "it": "Italien",
    "jm": "Jamaika",
    "jp": "Japan",
    "jo": "Jordanien",
    "kz": "Kasachstan",
    "ke": "Kenia",
    "ki": "Kiribati",
    "xk": "Kosovo|Republik Kosovo",
    "kw": "Kuwait",
    "kg": "Kirgisistan|Kirgisien|Kirgisstan",
    "la": "Laos",
    "lv": "Lettland",
    "lb": "Libanon",
    "ls": "Lesotho",
    "lr": "Liberia",
    "ly": "Libyen",
    "li": "Liechtenstein",
    "lt": "Litauen",
    "lu": "Luxemburg",
    "mg": "Madagaskar",
    "mw": "Malawi",
    "my": "Malaysia",
    "mv": "Malediven",
    "ml": "Mali",
    "mt": "Malta",
    "mh": "Marshallinseln",
    "mr": "Mauretanien",
    "mu": "Mauritius",
    "mx": "Mexiko",
    "md": "Moldau|Republik Moldau|Moldawien|Moldova",
    "mc": "Monaco",
    "mn": "Mongolei",
    "me": "Montenegro",
    "ma": "Marokko",
    "mz": "Mosambik",
    "mm": "Myanmar|Birma|Burma",
    "na": "Namibia",
    "nr": "Nauru",
    "np": "Nepal",
    "nl": "Niederlande|Holland",
    "nz": "Neuseeland",
    "ni": "Nicaragua",
    "ne": "Niger",
    "ng": "Nigeria",
    "kp": "Nordkorea|Demokratische Volksrepublik Korea",
    "mk": "Nordmazedonien|Mazedonien|Republik Nordmazedonien",
    "no": "Norwegen",
    "om": "Oman",
    "pk": "Pakistan",
    "pw": "Palau",
    "pa": "Panama",
    "pg": "Papua-Neuguinea",
    "py": "Paraguay",
    "pe": "Peru",
    "ph": "Philippinen",
    "pl": "Polen",
    "pt": "Portugal",
    "qa": "Katar",
    "ro": "Rumänien",
    "ru": "Russland|Russische Föderation",
    "rw": "Ruanda",
    "kn": "St. Kitts und Nevis|Sankt Kitts und Nevis",
    "lc": "St. Lucia|Sankt Lucia",
    "vc": "St. Vincent und die Grenadinen|Sankt Vincent und die Grenadinen",
    "ws": "Samoa",
    "sm": "San Marino",
    "st": "São Tomé und Príncipe|Sao Tome und Principe",
    "sa": "Saudi-Arabien|Saudi Arabien",
    "sn": "Senegal",
    "rs": "Serbien",
    "sc": "Seychellen",
    "sl": "Sierra Leone",
    "sg": "Singapur",
    "sk": "Slowakei",
    "si": "Slowenien",
    "sb": "Salomonen|Salomoninseln",
    "so": "Somalia",
    "za": "Südafrika",
    "kr": "Südkorea|Republik Korea",
    "ss": "Südsudan",
    "es": "Spanien",
    "lk": "Sri Lanka",
    "ps": "Palästina|Staat Palästina|Palästinensische Gebiete",
    "sd": "Sudan",
    "sr": "Suriname",
    "se": "Schweden",
    "ch": "Schweiz",
    "sy": "Syrien|Arabische Republik Syrien",
    "tw": "Taiwan",
    "tj": "Tadschikistan",
    "tz": "Tansania",
    "th": "Thailand",
    "tl": "Osttimor|Timor-Leste",
    "tg": "Togo",
    "to": "Tonga",
    "tt": "Trinidad und Tobago",
    "tn": "Tunesien",
    "tm": "Turkmenistan",
    "tr": "Türkei|Türkiye|Tuerkei|Turkey",
    "tv": "Tuvalu",
    "ug": "Uganda",
    "ua": "Ukraine",
    "ae": "Vereinigte Arabische Emirate|VAE",
    "gb": "Vereinigtes Königreich|Großbritannien|Grossbritannien|England|Schottland|Wales|Nordirland|UK|United Kingdom",
    "us": "USA|Vereinigte Staaten|Vereinigte Staaten von Amerika|Amerika|United States|United States of America",
    "uy": "Uruguay",
    "uz": "Usbekistan",
    "vu": "Vanuatu",
    "va": "Vatikan|Vatikanstadt|Heiliger Stuhl",
    "ve": "Venezuela",
    "vn": "Vietnam",
    "ye": "Jemen",
    "zm": "Sambia",
    "zw": "Simbabwe",
    # Territorien, die in country.json eigene ISO-Eintraege besitzen:
    "hk": "Hongkong|Hong Kong",
    "mo": "Macau|Macao",
    "pr": "Puerto Rico",
    "fo": "Färöer|Färöer-Inseln",
    "gl": "Grönland",
    "re": "Réunion|Reunion",
    "nc": "Neukaledonien",
    "pf": "Französisch-Polynesien",
    "gf": "Französisch-Guayana",
    "gp": "Guadeloupe",
    "mq": "Martinique",
    "yt": "Mayotte",
}


def norm(value):
    """Vereinheitlicht nur Schreibweise, nicht Bedeutung."""
    value = value.strip().casefold().replace("ß", "ss")
    value = unicodedata.normalize("NFKD", value)
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = value.replace("&", " und ")
    value = re.sub(r"[^a-z0-9]+", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def load_country_index(path):
    with path.open(encoding="utf-8") as fh:
        entries = json.load(fh)
    if not isinstance(entries, list):
        raise ValueError("country.json muss ein JSON-Array sein")

    codes = {}
    aliases = {}
    origin = {}

    def register(label, code, where):
        key = norm(label)
        if not key:
            return
        if key in aliases and aliases[key] != code:
            raise ValueError(
                f"Mehrdeutige Laenderbezeichnung {label!r}: "
                f"{aliases[key]} ({origin[key]}) versus {code} ({where})"
            )
        aliases[key] = code
        origin[key] = where

    for entry in entries:
        if not isinstance(entry, dict):
            continue
        code = str(entry.get("code", "")).lower()
        # Pseudolaender wie 'eu' und 'xx' ausschliessen; Kosovo (xk) erlauben.
        if not (entry.get("iso") is True or code == "xk"):
            continue
        if not re.fullmatch(r"[a-z]{2}", code):
            continue
        name = entry.get("name")
        if not isinstance(name, str) or not name:
            continue
        codes[code] = name
        register(name, code, "country.json")
        register(code, code, "ISO-Code")

    for code, options in GERMAN_ALIASES.items():
        if code not in codes:
            raise ValueError(f"Alias fuer {code}: Laendercode fehlt in country.json")
        for label in options.split("|"):
            register(label, code, "deutscher Alias")

    return aliases, codes


def country_code(raw, aliases):
    return aliases.get(norm(raw))


def suggestions(raw, aliases, codes):
    keys = difflib.get_close_matches(norm(raw), aliases.keys(), n=3, cutoff=0.65)
    return ", ".join(f"{codes[aliases[key]]} [{aliases[key]}]" for key in keys)


@dataclass
class Applicant:
    number: int
    pages: list = field(default_factory=list)
    nationalities: list = field(default_factory=list)  # (raw_field_value, page)


@dataclass
class Extraction:
    fmt: str = "unbekannt"
    page_count: int = 0
    applicants: dict = field(default_factory=dict)
    errors: list = field(default_factory=list)
    warnings: list = field(default_factory=list)
    textless_pages: list = field(default_factory=list)
    layout_diagnostics: list = field(default_factory=list)
    codes: dict = field(default_factory=dict)


def extract_nationalities(text):
    """Liest ausschliesslich das explizite PDF-Feld 'Nationalitaet'."""
    lines = [line.strip() for line in text.splitlines()]
    results = []
    for pos, line in enumerate(lines):
        match = RE_NATIONALITY.fullmatch(line)
        if not match:
            continue
        raw = match.group(1).strip(" \t:\u00a0")
        if not raw:
            # Manche PDF-Generatoren trennen Feldbezeichnung und Wert.
            for nxt in lines[pos + 1 : pos + 4]:
                if nxt:
                    raw = nxt
                    break
        results.append(raw)
    return results


def pdf_needs_password(pdf):
    """PyMuPDF ist je nach Debian-Version mit snake_case oder camelCase verfuegbar."""
    for attribute in ("needs_pass", "needsPass", "is_encrypted", "isEncrypted"):
        value = getattr(pdf, attribute, None)
        if value is not None:
            return bool(value() if callable(value) else value)
    # Kein Attribut vorhanden: Textauslesung versuchen; etwaige Fehler
    # werden dann am betroffenen PDF gemeldet statt die ganze Serie abzubrechen.
    return False


def pdf_page_text(page):
    """Textausgabe kompatibel zu aelteren und neueren PyMuPDF-Versionen."""
    getter = getattr(page, "get_text", None)
    if getter is None:
        getter = getattr(page, "getText", None)
    if getter is None:
        raise RuntimeError("PyMuPDF-Seite hat weder get_text noch getText")
    try:
        return getter("text", sort=True) or ""
    except TypeError as exc:
        # Aeltere Versionen besitzen das optionale sort-Argument noch nicht.
        if "sort" not in str(exc):
            raise
        return getter("text") or ""


def parse_paginated_groups(result, pages, require_portal):
    """Gruppiert mehrseitige Portal-Bewerbungen anhand von x/N-Seitenzaehlern.

    require_portal=True nutzt zusaetzlich die pro Bewerbung identische Portal-ID.
    Ohne Portal-ID ist die Gruppierung nur erlaubt, wenn alle Seitenzaehler
    durchgaengige, eindeutig abgrenzbare Sequenzen ergeben.
    """
    groups = []
    seen = set()
    for pageno, text in pages:
        if not text.strip():
            result.errors.append(f"Seite {pageno}: leere Seite mitten in der Seitenfolge")
            continue
        matches = RE_PAGE_MARK.findall(text)
        if len(matches) != 1:
            result.errors.append(
                f"Seite {pageno}: genau ein Seitenzaehler x/N erwartet, gefunden {len(matches)}"
            )
            continue
        part, total = map(int, matches[0])
        if not (1 <= part <= total):
            result.errors.append(f"Seite {pageno}: ungueltiger Seitenzaehler {part}/{total}")
            continue

        portal_id = None
        if require_portal:
            ids = set(RE_PORTAL.findall(text))
            if len(ids) != 1:
                result.errors.append(
                    f"Seite {pageno}: genau eine Bewerberportal-ID erwartet, gefunden {len(ids)}"
                )
                continue
            portal_id = next(iter(ids))  # Nur im RAM, nicht in der JSON / den Logs.

        # Eine neue Bewerbung beginnt ausschliesslich bei 1/N.
        if part == 1:
            if groups:
                previous = groups[-1]["pages"]
                if [x[1] for x in previous] != list(range(1, previous[0][2] + 1)):
                    result.errors.append(
                        f"Vor PDF-Seite {pageno}: vorige Bewerbung unvollstaendig "
                        f"(PDF-Seiten {[x[0] for x in previous]})"
                    )
            if require_portal:
                if portal_id in seen:
                    result.errors.append(
                        f"Seite {pageno}: Bewerberportal-ID erneut als neuer Datensatz gefunden"
                    )
                seen.add(portal_id)
            groups.append({"id": portal_id, "pages": []})
        elif not groups:
            result.errors.append(f"Seite {pageno}: {part}/{total} ohne vorherige 1/N-Seite")
            continue

        if require_portal and groups[-1]["id"] != portal_id:
            result.errors.append(f"Seite {pageno}: Bewerberportal-ID wechselt mitten in Bewerbung")
            continue
        if groups[-1]["pages"]:
            previous_part = groups[-1]["pages"][-1][1]
            previous_total = groups[-1]["pages"][0][2]
            if part != previous_part + 1 or total != previous_total:
                result.errors.append(
                    f"Seite {pageno}: unpassende Seitenfolge {part}/{total} "
                    f"nach {previous_part}/{previous_total}"
                )
        groups[-1]["pages"].append((pageno, part, total, text))

    for number, group in enumerate(groups, 1):
        items = group["pages"]
        if not items:
            result.errors.append(f"Bewerbung {number}: keine gueltigen Seiten")
            continue
        seq = [item[1] for item in items]
        expected_seq = list(range(1, items[0][2] + 1))
        totals = {item[2] for item in items}
        if len(totals) != 1 or seq != expected_seq:
            result.errors.append(
                f"Kandidat {number}: unvollstaendige Seitenfolge {seq}, "
                f"erwartet {expected_seq} (PDF-Seiten {[x[0] for x in items]})"
            )
        applicant = Applicant(number=number, pages=[p for p, _, _, _ in items])
        for pageno, _, _, text in items:
            applicant.nationalities.extend((raw, pageno) for raw in extract_nationalities(text))
        result.applicants[number] = applicant

    result.warnings.append(
        "Kandidatennummern aus Reihenfolge der PDF-Bewerbungen abgeleitet; "
        "nicht explizit im PDF ausgewiesen. Vor der Voting-Auswertung "
        "Reihenfolge mit dem Abstimmungsformular abgleichen."
    )



def parse_nationality_anchor_groups(result, pages, expected):
    """Fallback fuer zusammengefuegte PDF-Ausdrucke ohne Kandidatennummern.

    Eine Bewerbung beginnt am *einzigen* Nationalitaetsfeld einer Seite.
    Laengere Bewerbungen koennen unmittelbar folgende Seiten ohne dieses Feld
    enthalten. Die Reihenfolge im PDF wird als Wahlposition 1..N interpretiert.

    Der Modus wird nur aufgerufen, wenn exakt N Felder auf N verschiedenen
    Seiten vorkommen. Eine solche Validierung prueft die Zuordenbarkeit,
    beweist aber NICHT unabhaengig die Uebereinstimmung mit dem Wahlformular.
    """
    fields_by_page = [(page, extract_nationalities(text)) for page, text in pages]
    counts = [len(values) for _, values in fields_by_page]
    n_fields = sum(counts)
    if expected is None or n_fields != expected or any(n > 1 for n in counts):
        result.errors.append(
            f"Feldbasierte Gruppierung nicht moeglich: {n_fields} Nationalitaetsfelder "
            f"auf {sum(bool(n) for n in counts)} Seiten, DB-Bewerberzahl={expected}; "
            "erforderlich ist genau ein Feld pro Bewerbung und hoechstens eines pro Seite"
        )
        result.layout_diagnostics = describe_unknown_layout(pages)
        return

    result.fmt = "Feldfolge (ohne Kandidaten-IDs)"
    current = None
    preceding = []
    last_content = max(pageno for pageno, text in pages if text.strip())
    for (pageno, text), (_, fields) in zip(pages, fields_by_page):
        if not text.strip():
            if pageno > last_content:
                result.warnings.append(f"Seite {pageno}: leere PDF-Endseite ignoriert")
            else:
                result.errors.append(
                    f"Seite {pageno}: leere Seite zwischen Bewerbungen; "
                    "Gruppierung unklar"
                )
            continue
        if fields:
            number = len(result.applicants) + 1
            result.applicants[number] = Applicant(
                number=number,
                pages=[pageno],
                nationalities=[(fields[0], pageno)],
            )
            current = number
        elif current is None:
            preceding.append(pageno)
        else:
            result.applicants[current].pages.append(pageno)
            result.warnings.append(
                f"Seite {pageno}: Folgeseite dem Bewerbungsabschnitt {current} "
                "ohne Nationalitaetsfeld zugeordnet"
            )

    if preceding:
        result.applicants[1].pages[0:0] = preceding
        result.warnings.append(
            f"Vor dem ersten Nationalitaetsfeld liegen {len(preceding)} "
            f"Textseite(n) {preceding}; dem ersten Abschnitt zugeschlagen. "
            "Anfang des PDFs manuell pruefen."
        )
    result.warnings.append(
        "Kandidatennummern 1..N aus Reihenfolge der Nationalitaetsfelder "
        "abgeleitet (keine expliziten Wahlpositionen im PDF). "
        "Vor nvc.py die Reihenfolge stichprobenartig mit dem Abstimmungsformular abgleichen."
    )


def describe_unknown_layout(pages):
    """Anonyme Strukturdiagnose; niemals ganze PDF-Texte/Namen ausgeben."""
    info = []
    for pageno, text in pages:
        if not text.strip():
            info.append((pageno, "kein extrahierbarer Text"))
            continue
        markers = RE_PAGE_MARK.findall(text)
        marker = (f"x/N={markers[-1][0]}/{markers[-1][1]}" if len(markers) == 1
                  else f"x/N-Felder={len(markers)}")
        info.append((pageno,
            f"Textzeichen={len(text)}, Nationalitaetsfelder={len(extract_nationalities(text))}, "
            f"Bewerber-Ueberschriften={len(RE_APPLICANT.findall(text))}, "
            f"Portal-IDs={len(set(RE_PORTAL.findall(text)))}, {marker}"
        ))
    return info


def read_pdf(path, expected=None):
    result = Extraction()
    pages = []
    try:
        import fitz
    except ImportError as exc:
        raise RuntimeError("PyMuPDF fehlt (Modul fitz). Paket installieren.") from exc

    try:
        with fitz.open(str(path)) as pdf:
            if pdf_needs_password(pdf):
                result.errors.append("PDF ist passwortgeschuetzt")
                return result
            result.page_count = len(pdf)
            for i, page in enumerate(pdf, 1):
                text = pdf_page_text(page)
                if not text.strip():
                    result.textless_pages.append(i)
                pages.append((i, text))
    except Exception as exc:
        result.errors.append(f"PDF konnte nicht gelesen werden: {type(exc).__name__}: {exc}")
        return result

    if not any(text.strip() for _, text in pages):
        result.errors.append("PDF ohne auslesbaren Text (Scan? OCR erforderlich)")
        return result

    has_explicit = any(RE_APPLICANT.search(text) for _, text in pages)
    has_portal = any(RE_PORTAL.search(text) for _, text in pages)
    if has_explicit and has_portal:
        result.errors.append("Gemischte Bewerber-Nummerierung und Portal-IDs; Format unklar")
        return result

    if has_explicit:
        result.fmt = "alt (explizite Nummern)"
        current = None
        last_content_page = max(pageno for pageno, text in pages if text.strip())
        for pageno, text in pages:
            if not text.strip():
                if pageno > last_content_page:
                    result.warnings.append(f"Seite {pageno}: leere PDF-Endseite ignoriert")
                else:
                    result.errors.append(
                        f"Seite {pageno}: leere Seite mitten zwischen Bewerbungen; "
                        "Zuordnung muss geprueft werden"
                    )
                continue
            matches = [int(n) for n in RE_APPLICANT.findall(text)]
            distinct = list(dict.fromkeys(matches))
            # Manche importierten Formulare enthalten zwei 'Bewerber N'-
            # Textstellen, davon nur eine im gueltigen Wahlbereich 1..N.
            # Unpassende IDs nie ohne Meldung als Wahlposition verwenden.
            if len(distinct) > 1 and expected is not None:
                eligible = [n for n in distinct if 1 <= n <= expected]
                rejected = [n for n in distinct if n not in eligible]
                if len(eligible) == 1 and rejected:
                    result.warnings.append(
                        f"Seite {pageno}: zusaetzliche Bewerber-IDs {rejected} "
                        f"ausserhalb 1..{expected} ignoriert; Wahlposition {eligible[0]}"
                    )
                    distinct = eligible
                    matches = eligible
            if len(distinct) == 1:
                number = distinct[0]
                if len(matches) > 1:
                    result.warnings.append(
                        f"Seite {pageno}: identische Bewerber-Ueberschrift {number} "
                        f"{len(matches)}x gefunden, als eine Nummer interpretiert"
                    )
                if number in result.applicants:
                    result.errors.append(
                        f"Seite {pageno}: Bewerbernummer {number} nach bereits "
                        "abgeschlossener Bewerbung erneut gefunden"
                    )
                    current = None
                    continue
                applicant = Applicant(number=number, pages=[pageno])
                applicant.nationalities = [
                    (value, pageno) for value in extract_nationalities(text)
                ]
                result.applicants[number] = applicant
                current = number
            elif not distinct:
                if current is None:
                    result.errors.append(
                        f"Seite {pageno}: weder Bewerber-Ueberschrift noch "
                        "vorherige Bewerbung fuer Fortsetzungsseite vorhanden"
                    )
                    continue
                # Altformat: Seite ohne neue Ueberschrift ist eine Folgeseite
                # der unmittelbar zuvor begonnenen Bewerbung. Ein darauf
                # vorkommendes Nationalitaetsfeld wird mitvalidiert (mehr als
                # ein Feld fuer dieselbe Bewerbung -> Fehler).
                applicant = result.applicants[current]
                applicant.pages.append(pageno)
                applicant.nationalities.extend(
                    (raw, pageno) for raw in extract_nationalities(text)
                )
                result.warnings.append(
                    f"Seite {pageno}: Fortsetzungsseite von Bewerber {current} "
                    "ohne neue Ueberschrift zugeordnet"
                )
            else:
                result.errors.append(
                    f"Seite {pageno}: mehrere verschiedene Bewerber-Ueberschriften "
                    f"{distinct}; nicht automatisch zuordenbar"
                )
                current = None
    elif has_portal:
        result.fmt = "neu (Portal-Seitenfolge)"
        parse_paginated_groups(result, pages, require_portal=True)
    else:
        # Zusaetzlicher sicherer Fallback fuer Formate mit wiederholtem
        # Seitenzaehler 1/N, 2/N, ... OHNE Portal-URL. Nur fortlaufende,
        # vollstaendige Seitensequenzen werden akzeptiert; reine Seite 1/1
        # kann auch eine eigene Bewerbung sein.
        all_have_counter = all(
            bool(text.strip()) and len(RE_PAGE_MARK.findall(text)) == 1
            for _, text in pages
        )
        if all_have_counter:
            result.fmt = "Seitenzaehler (ohne Portal-ID)"
            parse_paginated_groups(result, pages, require_portal=False)
        else:
            # Zusaetzliche, in Odin beobachtete Formate besitzen fuer jeden
            # Bewerber genau ein Nationalitaetsfeld, aber weder explizite
            # Wahlpositionen noch eine komplette x/N-Seitenfolge. Der
            # feldbasierte Parser prueft die Zahl UND die Einzigartigkeit.
            # Bei Fehlschlag bleiben Strukturdiagnosen verfuegbar.
            parse_nationality_anchor_groups(result, pages, expected)
    return result


def validate_extraction(result, expected, aliases, codes):
    numbers = sorted(result.applicants)
    if len(numbers) != expected:
        result.errors.append(
            f"DB-Abgleich: {len(numbers)} Bewerber extrahiert, "
            f"bapolls.anzahl_kandidaten={expected}"
        )
    if numbers != list(range(1, expected + 1)):
        missing = sorted(set(range(1, expected + 1)) - set(numbers))
        extra = sorted(set(numbers) - set(range(1, expected + 1)))
        result.errors.append(f"Kandidatennummern nicht 1..{expected}: fehlen={missing}, zusaetzlich={extra}")

    for number, applicant in sorted(result.applicants.items()):
        fields = applicant.nationalities
        if len(fields) != 1:
            result.errors.append(
                f"Kandidat {number} (Seiten {applicant.pages}): "
                f"Nationalitaetsfeld {len(fields)}x gefunden (erwartet genau 1x)"
            )
            continue
        raw, pageno = fields[0]
        code = country_code(raw, aliases)
        # Manuelle Korrektur: Inder aus der Region "Maharashtra" geben regelmäßig "Marshallinseln" als ihre Nationalität an.
        # Da Marshallinseln nur 40k Einwohner, werden fix alle Marshallislander als Inder gewertet
        if code == "mh":
            code = "in"
            result.warnings.append(
                f"Kandidat {number}, Seite {pageno}: Nationalitaet {raw!r} "
                "manuell von Marshallinseln (mh) auf Indien (in) korrigiert"
            )
        if code is None:
            possible = suggestions(raw, aliases, codes)
            result.errors.append(
                f"Kandidat {number}, Seite {pageno}: Nationalitaet {raw!r} "
                f"nicht eindeutig erkannt" + (f"; Vorschlaege: {possible}" if possible else "")
            )
            continue
        result.codes[str(number)] = code

    if len(result.codes) != expected:
        result.errors.append(f"Laender-Codes: {len(result.codes)}/{expected} erfolgreich zugeordnet")
    return not result.errors


def read_database():
    # fcol.py laeuft aus /WEH/skripte und verwendet die bestehenden Credentials.
    from fcol import connect_weh
    conn = connect_weh()
    cursor = None
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT id, pfad, anzahl_kandidaten FROM bapolls ORDER BY id")
        return list(cursor.fetchall())
    finally:
        if cursor is not None:
            cursor.close()
        conn.close()


def atomic_save_json(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        fd, temporary = tempfile.mkstemp(prefix=".read_bewerber_", suffix=".tmp", dir=str(path.parent))
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(content, fh, ensure_ascii=False, indent=2)
            fh.write("\n")
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(temporary, path)
        os.chmod(path, 0o600)
    finally:
        if temporary and os.path.exists(temporary):
            os.unlink(temporary)


def diagnose_marshall(path, extraction, aliases, poll_id):
    """Gibt ausschliesslich die Marshallinseln-Treffer mit originalem PDF-Feld aus.

    Ein Treffer wird auch in einem insgesamt verworfenen PDF angezeigt. Die
    Diagnose veraendert weder Mapping noch die produktive JSON.
    """
    matches = []
    for number, applicant in sorted(extraction.applicants.items()):
        for raw, pageno in applicant.nationalities:
            mapped = country_code(raw, aliases)
            if mapped == "mh" or "marshall" in norm(raw):
                matches.append((number, pageno, raw, mapped))
    if not matches:
        return []

    print("\n  >>> MARSHALL-DIAGNOSE: {} Treffer in {} (Poll-ID {})".format(
        len(matches), path.name, poll_id))
    print("      PDF-Format: {} | Vollstaendig validiert: {}".format(
        extraction.fmt, "JA" if not extraction.errors else "NEIN"))

    try:
        import fitz
        with fitz.open(str(path)) as pdf:
            page_texts = {}
            for _, pageno, _, _ in matches:
                if pageno not in page_texts:
                    page_texts[pageno] = pdf_page_text(pdf[pageno - 1])
    except Exception as exc:
        page_texts = {}
        print("      PDF-Quellzeile konnte nicht gelesen werden: {}: {}".format(
            type(exc).__name__, exc))

    for number, pageno, raw, mapped in matches:
        print("      Kandidat: {} | PDF-Seite: {} | Bewerberseiten: {}".format(
            number, pageno, extraction.applicants[number].pages))
        print("      EXAKTER extrahierter Nationalitaetswert: {!r}".format(raw))
        print("      Normalisiert: {!r} | ISO-Zuordnung im PDF: {}".format(
            norm(raw), mapped or "NICHT ERKANNT"))
        if mapped == "mh":
            print("      In der Ausgabe-JSON manuell korrigiert auf: Indien (in)")
        text = page_texts.get(pageno, "")
        lines = text.splitlines()
        candidates = []
        for pos, line in enumerate(lines):
            field_match = RE_NATIONALITY.fullmatch(line.strip())
            if not field_match:
                continue
            original = field_match.group(1).strip(" \t:\u00a0")
            if original == raw or (not original and raw in [x.strip() for x in lines[pos+1:pos+4]]):
                candidates.append(line.strip())
        for line in candidates:
            print("      Originale Nationalitaetszeile im PDF: {!r}".format(line))
        if not candidates:
            print("      Originale Nationalitaetszeile: nicht eindeutig isolierbar")
        print("      ---")
    return matches


def main():
    parser = argparse.ArgumentParser(description="Nationalitaeten aus BA-Bewerber-PDFs erfassen und mit DB abgleichen")
    parser.add_argument("--pdf-dir", type=Path, default=DEFAULT_PDF_DIR, help="PDF-Quellverzeichnis")
    parser.add_argument("--country-json", type=Path, default=DEFAULT_COUNTRY_JSON, help="Country-Mapping aus PHP")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="JSON-Zieldatei")
    parser.add_argument("--verbose", "-v", action="store_true", help="Jeden erfolgreich erkannten Bewerber ausgeben")
    parser.add_argument("--dry-run", action="store_true", help="Pruefen, ohne JSON zu schreiben")
    parser.add_argument("--marshall", action="store_true",
                        help="Marshallinseln-Treffer mit PDF-Feldtext untersuchen, ohne JSON zu schreiben")
    args = parser.parse_args()
    if args.marshall:
        args.dry_run = True  # Diagnose darf niemals die produktive JSON ueberschreiben.


    print("=" * 88)
    print("READ_BEWERBER | PDF -> Kandidatennummer -> ISO-Nationalitaet | DB-Pruefung")
    print("=" * 88)
    print(f"PDF-Verzeichnis : {args.pdf_dir}")
    print(f"Country-JSON     : {args.country_json}")
    print(f"Ausgabe-JSON     : {args.output}")
    print(f"Trockenlauf      : {'JA' if args.dry_run else 'NEIN'}")

    try:
        aliases, codes = load_country_index(args.country_json)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FATAL: Laenderliste unbrauchbar: {exc}", file=sys.stderr)
        return 1
    print(f"Laendermapping   : {len(codes)} ISO/unterstuetzte Codes, {len(aliases)} Namensvarianten")

    if not args.pdf_dir.is_dir():
        print("FATAL: PDF-Verzeichnis existiert nicht", file=sys.stderr)
        return 1
    files = sorted(f for f in args.pdf_dir.iterdir() if f.is_file() and f.suffix.lower() == ".pdf")
    if not files:
        print("FATAL: Keine PDF-Dateien im angegebenen Verzeichnis", file=sys.stderr)
        return 1

    try:
        rows = read_database()
    except Exception as exc:
        print(f"FATAL: Datenbankabfrage fehlgeschlagen: {type(exc).__name__}: {exc}", file=sys.stderr)
        print("Hinweis: fcol.py/connect_weh() und mysql-connector-python pruefen.", file=sys.stderr)
        return 1

    db = {}
    duplicates = defaultdict(list)
    for row in rows:
        filename = Path(str(row["pfad"]).replace("\\", "/")).name
        if not filename.lower().endswith(".pdf"):
            print(f"WARNUNG: Poll-ID {row['id']}: pfad verweist nicht auf ein PDF ({filename})")
            continue
        if filename in db:
            duplicates[filename].extend([db[filename]["id"], row["id"]])
        db[filename] = row
    if duplicates:
        for filename, ids in sorted(duplicates.items()):
            print(f"FATAL: PDF-Dateiname mehrfach in DB: {filename}, Poll-IDs {sorted(set(ids))}")
        print("Aus Sicherheitsgruenden keine JSON erzeugt.")
        return 1
    if not db:
        print("FATAL: Keine gueltigen bapolls-Datensaetze", file=sys.stderr)
        return 1

    print(f"PDF-Dateien      : {len(files)}")
    print(f"Bapolls-DB       : {len(db)} Datensaetze mit PDF")
    print("-" * 88)
    accepted = {}
    error_files = []
    warning_files = 0
    total_expected = 0
    total_extracted = 0
    total_recognized = 0
    total_saved = 0
    formats = Counter()
    distribution = Counter()
    found_on_disk = set()
    orphan_files = []
    derived_order_files = []
    marshall_matches = []

    for index, path in enumerate(files, 1):
        print(f"\n[{index:3}/{len(files)}] {path.name}")
        found_on_disk.add(path.name)
        row = db.get(path.name)
        if row is None:
            print("  INFO: Nicht von weh.bapolls referenziert; fuer Voting-Auswertung uebersprungen")
            orphan_files.append(path.name)
            continue

        expected = int(row["anzahl_kandidaten"])
        total_expected += expected
        if expected <= 0:
            print(f"  FEHLER: ungueltige Bewerberzahl in DB: {expected}")
            error_files.append((path.name, [f"ungueltige DB-Bewerberzahl: {expected}"]))
            continue

        extraction = read_pdf(path, expected)
        formats[extraction.fmt] += 1
        validate_extraction(extraction, expected, aliases, codes)
        if args.marshall:
            for match in diagnose_marshall(path, extraction, aliases, row['id']):
                marshall_matches.append((path.name,) + match)
        total_extracted += len(extraction.applicants)
        total_recognized += len(extraction.codes)
        print(f"  Poll-ID: {row['id']} | Format: {extraction.fmt} | PDF-Seiten: {extraction.page_count}")
        print(
            f"  Bewerber: DB={expected}, erkannt={len(extraction.applicants)}, "
            f"Nationalitaeten={len(extraction.codes)}"
        )

        if args.verbose:
            for number, applicant in sorted(extraction.applicants.items()):
                code = extraction.codes.get(str(number), "NICHT ERKANNT")
                print(f"    Kandidat {number:>2}: {code:<16} PDF-Seite(n) {applicant.pages}")

        # Fortsetzungsseiten bei vielen Bewerbungen sind normal und werden
        # im normalen Lauf nur zusammengefasst. Bei --verbose pro Seite sichtbar.
        continuation_warnings = [w for w in extraction.warnings if "Fortsetzungsseite von Bewerber" in w]
        normal_warnings = [w for w in extraction.warnings if "Fortsetzungsseite von Bewerber" not in w]
        if continuation_warnings and not args.verbose:
            print(f"  INFO: {len(continuation_warnings)} Fortsetzungsseite(n) zugeordnet")
        for warning in (extraction.warnings if args.verbose else normal_warnings):
            print(f"  WARNUNG: {warning}")
        if extraction.warnings:
            warning_files += 1
        if extraction.layout_diagnostics:
            print("  ANONYME PDF-STRUKTURDIAGNOSE (keine Textinhalte):")
            for pageno, description in extraction.layout_diagnostics:
                print(f"    Seite {pageno}: {description}")

        if extraction.errors:
            print(f"  STATUS: FEHLER ({len(extraction.errors)} Problem(e)); wird NICHT gespeichert")
            for error in extraction.errors:
                print(f"    - {error}")
            error_files.append((path.name, extraction.errors))
        else:
            accepted[path.name] = dict(sorted(extraction.codes.items(), key=lambda item: int(item[0])))
            distribution.update(extraction.codes.values())
            total_saved += len(extraction.codes)
            if extraction.fmt in (
                "neu (Portal-Seitenfolge)",
                "Seitenzaehler (ohne Portal-ID)",
                "Feldfolge (ohne Kandidaten-IDs)",
            ):
                derived_order_files.append(path.name)
                print("  STATUS: OK - technisch validiert; Voting-Reihenfolge noch pruefen")
            else:
                print("  STATUS: OK - vollstaendig validiert")

    missing_files = sorted(set(db) - found_on_disk)
    if missing_files:
        print("\n" + "=" * 88)
        print("FEHLENDE PDFs: In bapolls referenziert, aber nicht im PDF-Verzeichnis vorhanden")
        for filename in missing_files:
            row = db[filename]
            total_expected += int(row["anzahl_kandidaten"])
            error_files.append((filename, ["DB-Eintrag ohne PDF-Datei"]))
            print(f"  - {filename} (Poll-ID {row['id']}, erwartete Bewerber {row['anzahl_kandidaten']})")

    if args.marshall:
        print("\n" + "=" * 88)
        print("MARSHALL-DIAGNOSE | ZUSAMMENFASSUNG")
        print("=" * 88)
        print("Gefundene Kandidatenpositionen mit Zuordnung mh: {}".format(len(marshall_matches)))
        for filename, number, pageno, raw, mapped in marshall_matches:
            print("  {} | Kandidat {} | Seite {} | Feld {!r} | ISO {}".format(
                filename, number, pageno, raw, mapped or "?"))
        print("Es wurde ausschliesslich gelesen: --marshall aktiviert automatisch --dry-run.")

    print("\n" + "=" * 88)
    print("ZUSAMMENFASSUNG")
    print("=" * 88)
    print(f"PDFs auf Dateisystem             : {len(files):>6}")
    print(f"PDFs in DB                      : {len(db):>6}")
    print(f"Nicht referenzierte PDFs (SKIP) : {len(orphan_files):>6}")
    print(f"PDFs vollstaendig OK            : {len(accepted):>6} ({100 * len(accepted) / len(db):.1f} % der DB-Abstimmungen)")
    print(f"DB-PDFs mit Fehler / fehlend    : {len(error_files):>6}")
    print(f"PDFs mit Warnung                : {warning_files:>6}")
    print(f"PDFs mit abgeleiteter Reihenf. : {len(derived_order_files):>6} (Voting-Reihenfolge pruefen)")
    print(f"Kandidaten laut DB              : {total_expected:>6}")
    print(f"Kandidaten aus PDF erkannt      : {total_extracted:>6}")
    print(f"Nationalitaeten erkannt         : {total_recognized:>6}")
    print(f"Kandidaten in JSON              : {total_saved:>6} ({100 * total_saved / total_expected if total_expected else 0:.1f} % aller DB-Kandidaten)")
    print("PDF-Formate:")
    for label, count in formats.most_common():
        print(f"  {label:<32} {count:>5}")
    print("Laenderverteilung (nur validierte PDFs):")
    for code, count in distribution.most_common():
        print(f"  {code:>4} | {codes.get(code, '?'):<34} {count:>5}")
    if orphan_files:
        print("\nNICHT REFERENZIERTE PDFS (keine Fehler fuer den DB-Abgleich):")
        for filename in orphan_files:
            print(f"  - {filename}")
    if derived_order_files:
        print("\nREIHENFOLGE ZU PRUEFEN (keine expliziten Voting-Nummern im PDF):")
        for filename in derived_order_files:
            print(f"  - {filename}")
    if error_files:
        print("\nFEHLERLISTE:")
        for filename, errors in error_files:
            print(f"  {filename}:")
            for error in errors:
                print(f"    - {error}")

    if not args.dry_run:
        if not accepted:
            print("\nKEINE JSON ERZEUGT: Kein einziges PDF erfolgreich validiert.")
            return 2
        try:
            atomic_save_json(args.output, accepted)
        except OSError as exc:
            print(f"FATAL: JSON konnte nicht geschrieben werden: {exc}", file=sys.stderr)
            return 1
        print(f"\nJSON geschrieben: {args.output} ({len(accepted)} PDFs, {total_saved} Kandidaten)")
        print("JSON enthaelt ausschliesslich Dateinamen, Kandidatennummern und ISO-Laendercodes.")
    else:
        print("\nDRY-RUN: Ausgabe-JSON wurde nicht geschrieben.")

    if error_files:
        print("ERGEBNIS: UNVOLLSTAENDIG. Betroffene PDFs fehlen vollstaendig in der JSON.")
        return 2
    if derived_order_files:
        print("ERGEBNIS: ALLE PDFs FORMAL VALIDIERT; EINIGE VOTING-REIHENFOLGEN MUESSEN GEPRUEFT WERDEN.")
    else:
        print("ERGEBNIS: ALLE PDFs / DB-EINTRAEGE VALIDIERT.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
