# Changelog

## 0.2.0 – Alle Fenster ZU

- neue eigenständige Instanz `LCN Fenster Zentral ZU` / `Alle Fenster ZU`
- vorhandenes `LCN Fenster` aus 0.1.1 bytegleich übernommen
- gemischte Auswahl aus LCN-Fenster- und VELUX-KLF200-Node-Instanzen
- ZU-Befehl wird ausschließlich aus dem exakten Modul-GUID automatisch bestimmt
- LCN-Fenster schließen über die vorhandene `LCW_Close()`-Funktion
- KLF200 Node schließt über `KLF200_ShutterMoveDown()`; kein direktes Setzen/Fälschen der Positionsvariable
- bereits geschlossene Fenster werden übersprungen
- erster Hardwarebefehl ebenfalls asynchron über einen kurzen 50-ms-Modultimer; der Visu-Klick führt selbst keine Geräteaktion aus
- danach 1000 ms Startabstand zwischen tatsächlich erforderlichen Befehlen
- asynchrone Sequenz über Modultimer statt blockierender Mehrsekunden-Schleife bzw. eines potenziell wartenden KLF200-Aufrufs im Visu-Klick
- doppelter Tastendruck während einer laufenden Sequenz erzeugt keine zweite Queue
- Queue wird bei `ApplyChanges()`/Neustart bewusst verworfen; keine automatischen Hardwarebefehle nach Update/Restart
- Visualisierung des Zentralbuttons entspricht dem vorhandenen `LCN Befehl / Zentral AUS`

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
