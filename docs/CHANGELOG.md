# Changelog

## 0.1.1 – Instanzerstellung / Referenzverwaltung

- fehlende modulinterne Methode `ResetReferences()` ergänzt
- Referenzen werden über `GetReferenceList()` und `UnregisterReference()` sauber neu aufgebaut
- Laufzeittest korrigiert: der Mock stellt keine nicht existente Framework-Methode `ResetReferences()` mehr bereit
- zusätzlicher Regressionstest für die Framework-Oberfläche und Instanzerstellung
- keine Änderung an LCN-Fahr-, Zustands- oder Visualisierungslogik

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
