# Geschrieben von Fiji
# April 2023
# Für den WEH e.V.
# fiji@weh.rwth-aachen.de

from fcol import send_mail

subject = "Reminder: Klimaanlage säubern"
message = """Liebe Netzwerk-AG,

einmal pro Jahr muss die Klimaanlage des Serverraums von Pollen, Staub und weiterem Dreck gesäubert werden.
Der Monat August eignet sich perfekt dafür!

Befolgt dazu einfach diese Schritte:

1. Falls Autos davorstehen: Rundmail an das Haus, dass die Autos zu Reinigungsdatum umparken sollen, sonst Abschleppen.
2. Schlüssel für Schloss Klimagerät + Schlauch aus dem Netzkeller holen.
3. Schlauch an Hahn gegenüber des Kellereingangs anschließen.
4. Schloss hinter der Klimaanlage mit dem Schlüssel aufschließen und die Klimaanlage ausschalten. (Roten Schalter drehen)
5. Klimaanlage von vorne, seitwärts und hinten abspritzen.
6. Klimaanlage wieder einschalten und Schloss einhängen. (Dauert ~10 Minuten bis sie wieder läuft)
7. Bei Kellereingang Anhöhe das Wasser aus dem Schlauch laufen lassen.

Viel Erfolg,
www:/opt/local/WEH/skripte/cleaningcheck.py
"""

to_email = "netag@weh.rwth-aachen.de"
send_mail(subject, message, to_email)