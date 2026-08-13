# LCN Window Control für IP-Symcon 9

Version **0.2.0** – stabile LCN-Fensterinstanz **0.1.1 unverändert** plus neuer zentraler Gruppenbefehl **Alle Fenster ZU**.

## 1. LCN Fenster

Die vorhandene Instanz `LCN Fenster` entspricht bytegleich der funktionierenden Version 0.1.1:

- KURZ = ZU
- LANG / MAKE = AUF
- echte AUF-/ZU-Relaisrückmeldungen bestimmen den Zustand
- kein direktes Relais-Schalten durch Symcon
- kein Symcon-Fahrzeittimer
- persistenter abgeleiteter Endzustand
- zwei runde türkisfarbene Buttons AUF/ZU

## 2. LCN Fenster Zentral ZU / Alle Fenster ZU

Neue zustandslose Zentralinstanz mit genau einem runden türkisfarbenen Button im Layout des `LCN Befehl / Zentral AUS` aus der Lichtsteuerung.

### Konfiguration

Es werden ausschließlich die gewünschten **Fenster-Instanzen** in einer Liste ausgewählt. Weitere Tasten-, Relais- oder Variablenangaben sind nicht nötig.

Automatische Erkennung:

- `LCN Fenster` `{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}` → vorhandene Methode `LCW_Close()`
- `KLF200 Node` `{4EBD07B1-2962-4531-AC5F-7944789A9CE5}` → nativer Befehl `KLF200_ShutterMoveDown()`

Für KLF200 wird **nicht** per `SetValue()` in die Positionsvariable geschrieben. Der native KLF200-Schließbefehl steuert das Gerät; die vorhandene KLF200-Instanz aktualisiert danach ihre eigenen Statusvariablen und damit auch die bestehende Dachfenster-Visualisierung.

### Sequenz

- bereits geschlossene Fenster werden übersprungen
- der Visu-Klick legt nur die Queue an; **kein Hardwarebefehl läuft im HTML-Aufruf selbst**
- der erste erforderliche Schließbefehl startet über einen kurzen 50-ms-Modultimer
- weitere tatsächlich erforderliche Befehle folgen mit **1000 ms Abstand**
- kein mehrsekündiges `sleep()` und kein blockierender KLF200-Aufruf im Visualisierungs-Klick
- erneuter Tastendruck während einer laufenden Sequenz erzeugt keine zweite Queue
- bei Update/Neustart wird eine eventuell alte Queue verworfen; es werden niemals automatisch Hardwarebefehle fortgesetzt

### KLF200-Richtung

Beim verwendeten KLF200-Modul ist für Window Opener `MAIN` die Positionsvariable. Der native `ShutterMoveDown()`-Befehl setzt den Hauptparameter auf `0xC800` (Maximum / 100 %), während `ShutterMoveUp()` auf `0x0000` geht. Deshalb wird für **Schließen** bewusst `ShutterMoveDown()` verwendet und nicht ein Positionswert 0 erzwungen.

## Installation / Update

Die Library wie bisher aktualisieren. Bestehende `LCN Fenster`-Instanzen bleiben unverändert. Danach eine neue Instanz **LCN Fenster Zentral ZU** bzw. **Alle Fenster ZU** anlegen und in der Liste alle gewünschten `LCN Fenster`- und `KLF200 Node`-Instanzen auswählen.
