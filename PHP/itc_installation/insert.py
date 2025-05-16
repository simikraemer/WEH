import csv
import json
import mysql.connector
from datetime import datetime

# Konfiguration lesen
def get_config(name):
    with open('/etc/credentials/config.json', 'r', encoding='utf-8') as f:
        config = json.load(f)
        return config[name]

creds = get_config('itcphp')

conn = mysql.connector.connect(
    host=creds['host'].replace('p:', '') or 'localhost',
    user=creds['user'],
    password=creds['password'],
    database=creds['database']
)
cursor = conn.cursor()

# Status-Map
status_map = {
    'Storniert': -1,
    'Vorgemerkt': 0,
    'Bestellt': 1,
    'In Installation': 2,
    'Pausiert': 3,
    'Updates ausstehend': 4,
    'Bereit zur Ausgabe': 5,
    'Rückgabe ausstehend': 6,
    'Altgerät ausstehend': 7,
    'Abgeschlossen': 8
}

# MA-Status-Map
mastatus_map = {
    'Neuer Mitarbeiter': 1,
    'Neuer HiWi': 2,
    'Neuer Azubi': 3,
    'Bestehender Mitarbeiter': 4,
    'Bestehender HiWi': 5,
    'Bestehender Azubi': 6,
    'Praktikant': 7,
    'IT-Koordinator': 8,
    'Undefiniert': 9
}

# CSV einlesen
with open('insert.csv', newline='', encoding='utf-8-sig') as csvfile:
    reader = csv.DictReader(csvfile, delimiter=';')
    for i, row in enumerate(reader, 1):
        raw_datum = row['Datum'].strip()
        datum = None
        if raw_datum:
            try:
                datum = datetime.strptime(raw_datum, '%d.%m.%Y').strftime('%Y-%m-%d')
            except ValueError:
                print(f"⚠️ Ungültiges Datumsformat bei Zeile {i}: {raw_datum}")

        data = {
            'status': status_map.get(row['Fortschritt'].strip(), None),
            'ticket': row['Ticketnummer'].strip(),
            'datum': datum,
            'zeit': row['Zeit'].strip() or None,
            'neugerät': row['Gerät'].strip(),
            'name': row['Name des Mitarbeiters'].strip(),
            'abteilung': row['Abteilung'].strip(),
            'mastatus': mastatus_map.get(row['MA-Status'].strip(), None),
            'altgerät': row['Altgerät'].strip(),
            'dock': row['Dock'].strip(),
            'monitor': row['Monitor'].strip(),
            'software': row['Software/Lizenz'].strip(),
            'notiz': row['Notizen'].strip()
        }

        print(f"\n#{i} Vorschau für Insert:")
        for k, v in data.items():
            print(f"  {k:10s} → {v!r}")

        # INSERT deaktiviert
        # fields = ', '.join(data.keys())
        # placeholders = ', '.join(['%s'] * len(data))
        # values = [v if v not in ['', 'NULL'] else None for v in data.values()]
        # cursor.execute(f"INSERT INTO installation ({fields}) VALUES ({placeholders})", values)

# conn.commit()  # ← deaktiviert
print("\n🔍 Vorschau abgeschlossen. Kein Eintrag wurde geschrieben.")
