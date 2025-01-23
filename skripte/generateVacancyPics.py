# Geschrieben von Fiji
# Januar 2025
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

from fcol import connect_weh
import matplotlib.pyplot as plt
import pandas as pd
import matplotlib.image as mpimg

# Datenbankverbindung und Datenabruf
conn = connect_weh()
cursor = conn.cursor()
sql = "SELECT name, vacancy FROM groups WHERE vacancy > 0 and turm = 'weh' LIMIT 10"  # Beschränkung auf 10 Einträge
cursor.execute(sql)
neueuser = cursor.fetchall()

# Daten in DataFrame umwandeln
df = pd.DataFrame(neueuser, columns=["Name", "Open Positions"])

# Einstellungen
max_rows_per_table = 5  # Maximale Zeilenanzahl pro Tabelle
table_width = 0.4  # Breite pro Tabelle (als Anteil am Gesamtbild)
table_height = 0.4  # Höhe der Tabelle (als Anteil am Gesamtbild)
table_padding = 0.05  # Horizontaler Abstand zwischen den Tabellen

# Funktion zur gleichmäßigen Verteilung der Daten
def split_balanced(data, max_rows):
    """
    Teilt die Daten so auf, dass die Tabellen möglichst gleichmäßig gefüllt sind.
    """
    n = len(data)
    if n <= max_rows:
        return [data]  # Alle Daten passen in eine Tabelle

    # Ziel: Zwei möglichst gleich große Teile erstellen
    mid_point = n // 2
    if abs(mid_point - (n - mid_point)) > 1:
        mid_point += 1 if mid_point < (n - mid_point) else -1

    return [data.iloc[:mid_point], data.iloc[mid_point:]]


# Daten gleichmäßig aufteilen
tables = split_balanced(df, max_rows_per_table)

# Bilddimensionen und Design
fig, ax = plt.subplots(figsize=(16, 9))

# Hintergrundbild laden und setzen
background_path = "/WEH/PHP/infopics/template.png"
background = mpimg.imread(background_path)
ax.imshow(background, aspect="auto", extent=[0, 1, 0, 1], zorder=0)

# Titel hinzufügen
title_text = "Current Open Positions in AGs:"
plt.text(
    0.5, 0.8,
    title_text,
    color="white", fontsize=28, ha="center", va="center", transform=ax.transAxes,
    weight="bold"
)

# Dynamisches Layout für die Tabellen
start_x = (1 - (len(tables) * table_width + (len(tables) - 1) * table_padding)) / 2  # Zentrieren
y_position = 0.25

for i, table_data in enumerate(tables):
    x_position = start_x + i * (table_width + table_padding)  # Position der Tabelle
    
    # Daten mit Header zusammenführen
    data_with_header = [table_data.columns.tolist()] + table_data.values.tolist()
    
    # Tabelle erstellen
    table = ax.table(
        cellText=data_with_header,
        colLabels=None,
        loc="center",
        cellLoc="center",
        bbox=[x_position, y_position, table_width, table_height]  # Dynamische Positionierung
    )
    
    # Tabellenformatierung
    table.auto_set_font_size(False)
    table.set_fontsize(14)
    table.scale(1, 2)  # Zellenhöhe auf 2 reduziert für kompakteres Layout
    for (row, col), cell in table.get_celld().items():
        cell.set_edgecolor("white")
        cell.set_linewidth(0.5)
        if row == 0:  # Header hervorheben
            cell.set_facecolor("#111")
            cell.set_text_props(color="white", weight="bold")
            cell.set_height(0.1)  # Kopfzeile etwas flacher
        else:
            cell.set_facecolor("black")
            cell.set_text_props(color="white")
            cell.set_height(0.08)  # Normale Zellen kompakt

# Achsen deaktivieren
ax.axis("off")

# Bild speichern
plt.savefig("/WEH/PHP/infopics/vacancy.png", dpi=300, bbox_inches="tight", pad_inches=0)
plt.show()
