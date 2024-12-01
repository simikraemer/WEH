![](/diverses/logo.png)


# Zusammenfassung

<details>
 <summary>Paths</summary>
 
 ### odin

 - **odin:/WEH/** - Dieses Repository
 - **odin:/etc/credentials/config.json** - ALLE Credentials werden hieraus abgerufen!!! Keine Credentials in den Code schreiben!
 - **odin:/etc/crontab** - Enthält alle geplanten Cron-Jobs für das System (Automatisierte Ausführung von Skripten)

</details>



---

# Datenbank Keys

<details>
<summary>transfers</summary>

 - konto = 0 "Kaution"
 - konto = 1 "Netzbeitrag"
 - konto = 2 "Hausbeitrag"
 - konto = 3 "Druckauftrag"
 - konto = 4 "Einzahlung"
 - konto = 5 "Getränk"
 - konto = 6 "Waschmaschine"
 - konto = 7 "Spülmaschine"
 - konto = 8 "Undefinierte Zahlung"

 - kasse = 0 "Haus"
 - kasse = 1 "NetzAG(bar)-I"
 - kasse = 2 "NetzAG(bar)-II"
 - kasse = 3 "imaginär Schuldbuchung"
 - kasse = 4 "Netz(konto)" (alt)
 - kasse = 5 "imaginär Rückzahlung(positiv)"
 - kasse = 69 "PayPal"
 - kasse = 72 "Netzkonto"
 - kasse = 92 "Hauskonto"

</details>

<details>
<summary>barkasse</summary>

 - kasse = 1 "Netzkasse I"
 - kasse = 2 "Netzkasse II"
 - kasse = 3 "Kassenwartkasse I"
 - kasse = 4 "Kassenwartkasse II"
 - kasse = 5 "Tresor"

</details>