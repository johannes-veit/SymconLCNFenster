# Changelog

## 0.1.0

- erste eigenständige Instanz `LCN Fenster`
- KURZ = ZU, LANG/MAKE = AUF über dieselbe konfigurierte LCN-Taste
- explizite boolesche AUF-/ZU-Relaisrückmeldung, keine Heuristik
- persistenter abgeleiteter Endzustand über Update/Neustart
- keine Symcon-Fahrzeit und keine Relaisabschaltung
- Konfliktsperre bei gleichzeitig aktiven Richtungsrelais
- HTML-SDK-Kachel mit zwei runden Symcon-türkisfarbenen Richtungstasten
- serverseitig eingesetzter Initialzustand
- Folgebefehlswarteschlange ohne automatische Wiederholung bei Transportfehlern
- 100-ms-Abstand zwischen LCN-TS-Telegrammen, ohne synchrones Warten auf Relaisfeedback
