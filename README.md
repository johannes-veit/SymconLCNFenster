# LCN Window Control für IP-Symcon 9

Version **0.1.0** – eigenständige Fenstersteuerung für eine bereits in LCN-Pro programmierte GT8-Taste.

## Funktionsprinzip

- **KURZ** auf der konfigurierten LCN-Taste = Fenster **ZU**
- **LANG / MAKE** auf derselben LCN-Taste = Fenster **AUF**
- Symcon sendet ausschließlich diese virtuellen Tastenereignisse.
- Symcon schaltet **keine SR6-Relais direkt** und beendet keine Fahrt.
- LCN bleibt verantwortlich für Verriegelung, Fahrzeit und das automatische Abschalten der Relais.

## Rückmeldung und Zustand

Zwei vorhandene boolesche LCN-Relaisvariablen werden explizit ausgewählt:

- Relais AUF EIN → `FÄHRT AUF`
- Relais ZU EIN → `FÄHRT ZU`
- danach beide AUS → zuletzt eindeutig erkannter Endzustand bleibt `OFFEN` bzw. `GESCHLOSSEN`
- beide Relais gleichzeitig EIN → Sicherheitsfehler, neue Fahrbefehle werden blockiert

Der stabile Endzustand wird als Modul-Attribut gespeichert und bei einem normalen Update/Neustart nicht verworfen, wenn beide Relais AUS sind. Damit werden auch Fahrten über den physischen GT8 erfasst, sobald die echten Relais-Rückmeldungen in Symcon eintreffen.

**Grenze des Verfahrens:** Ohne Endlagensensor ist der Endzustand abgeleitet. Eine mechanisch nicht ausgeführte Fahrt kann Symcon nicht erkennen.

## Visualisierung

Die HTML-SDK-Kachel zeigt:

- runden türkisgrünen Button **AUF**
- runden türkisgrünen Button **ZU**
- Status `UNBEKANNT`, `GESCHLOSSEN`, `OFFEN`, `FÄHRT ZU`, `FÄHRT AUF` oder `FEHLER`

Ist das Fenster offen oder fährt auf, ist **AUF** ausgegraut. Ist es geschlossen oder fährt zu, ist **ZU** ausgegraut. Eine laufende API-Anfrage graut die Bedienung nicht zusätzlich aus.

## Stabilitätsregeln

- keine automatische/heuristische Suche nach Relaisvariablen
- unveränderte VM_UPDATE-Wiederholungen werden ignoriert
- keine Fahrzeit-Timer in Symcon
- keine direkte Relaissteuerung
- kein Polling und keine Hardwarebefehle bei `ApplyChanges()`
- keine automatische Wiederholung bei unklarem API-/Netzwerkfehler
- schnelle unterschiedliche Folgebefehle werden in der Kachel auf den jeweils letzten Befehl zusammengefasst
- gleicher Doppelklick wird entprellt
- nur der tatsächliche LCN-Telegrammversand wird global serialisiert; zwischen Telegrammen liegen mindestens 100 ms, es wird nicht auf Relaisfeedback gewartet

## Installation

Die ZIP-Datei entpacken bzw. den Repository-Inhalt als IP-Symcon-Modulbibliothek bereitstellen. Danach **LCN Fenster** anlegen und konfigurieren:

1. LCN-Sendemodul auswählen.
2. Tastentabelle A–D und Taste 1–8 wählen.
3. Boolesche Rückmeldevariable für Relais AUF auswählen.
4. Boolesche Rückmeldevariable für Relais ZU auswählen.
5. Übernehmen.
6. Zuerst mit `Rückmeldungen jetzt übernehmen` prüfen, dass beide Relais korrekt gelesen werden.
7. Danach eine Fahrt über den physischen GT8 testen. Erst anschließend die Symcon-Buttons testen.

## LCN-PCK Tastencodierung

Das Modul erzeugt das 12-stellige TS-Datenfeld wie die bestehende Lichtsteuerung:

- KURZ/HIT: `K`
- LANG/MAKE: `L`
- nicht verwendete Tabellen: `-`
- acht Bit für Taste 1–8

Beispiel A3:

- ZU/KURZ: `K---00100000`
- AUF/LANG: `L---00100000`

Ein zusätzlicher BREAK-Befehl wird bewusst nicht automatisch gesendet, damit keine eventuell in LCN-Pro belegte Loslass-Aktion ausgelöst wird.
