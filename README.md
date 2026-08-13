# LCN Window Control für IP-Symcon 9

Version **0.2.1**.

## LCN Fenster

Die bestehende Fensterinstanz bleibt gegenüber der funktionierenden V0.1.1/V0.2.0 unverändert.

- KURZ = ZU
- LANG/MAKE = AUF
- LCN übernimmt Relaislogik, Verriegelung und zeitgesteuertes Abschalten.
- Symcon speichert den erkannten Endzustand persistent.

## LCN Fenster Zentral ZU

In der Gruppeninstanz werden nur die gewünschten Fensterinstanzen ausgewählt. Unterstützt werden automatisch:

- `LCN Fenster` `{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}` → `LCW_Close()`
- `KLF200 Node` `{4EBD07B1-2962-4531-AC5F-7944789A9CE5}` → `KLF200_ShutterMoveDown()`

Die Gruppenfolge benutzt einen dauerhaft aktiven 1-s-Modultimer. Alle ausgewählten Fenster werden zunächst in die Queue aufgenommen; unmittelbar vor dem jeweiligen Slot wird geprüft, ob das Fenster bereits geschlossen ist. Bereits geschlossene Fenster werden übersprungen, ohne einen Hardwarebefehl zu erzeugen.

Es werden **keine** Status- oder Positionswerte künstlich gesetzt. LCN-Fenster aktualisieren sich über ihre vorhandene Relaisrückmeldung; KLF200 aktualisiert seine eigene `MAIN`-Position. Die Gruppen-Kachel beobachtet diese echten Variablen und zeigt den aktuellen Zustand der eingebundenen Fenster an.

Die Visualisierung zeigt zusätzlich für ca. 3 Sekunden **„Befehl gesendet“** nach einem gültigen Zentral-ZU-Tastendruck.
