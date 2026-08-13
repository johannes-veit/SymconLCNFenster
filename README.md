# LCN Window Control für IP-Symcon 9

Version **0.2.2**.

## LCN Fenster

Die bestehende Fensterinstanz ist bytegleich zur stabilen **V0.1.1**.

- KURZ = ZU
- LANG/MAKE = AUF
- LCN übernimmt Relaislogik, Verriegelung und zeitgesteuertes Abschalten.
- Symcon speichert den erkannten Endzustand persistent.

## LCN Fenster Zentral ZU

In der Gruppeninstanz werden nur die gewünschten Fensterinstanzen ausgewählt. Unterstützt werden automatisch:

- `LCN Fenster` `{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}` → `LCW_Close()`
- `KLF200 Node` `{4EBD07B1-2962-4531-AC5F-7944789A9CE5}` → `KLF200_ShutterMoveDown()`

### Zuverlässige 1-s-Folge

Alle ausgewählten Fenster werden in die Queue aufgenommen. Der 1-s-Modultimer führt selbst **keinen Hardwarebefehl** aus. Pro Slot startet er lediglich einen eigenen asynchronen `IPS_RunScriptText()`-Worker und ist sofort wieder frei.

Damit kann ein KLF200-Node, der intern auf die Fertigmeldung des Gerätes wartet, die nachfolgenden LCN-Fenster nicht mehr blockieren. Bereits geschlossene Fenster werden unmittelbar vor ihrem Slot übersprungen.

### Statusliste

Es werden keine Status- oder Positionswerte künstlich gesetzt.

- LCN-Fenster: `AUF`, `ZU`, `FÄHRT AUF`, `FÄHRT ZU`
- KLF200: `AUF`, `ZU`, `LÄUFT`; bei sicher bekannter Richtung `FÄHRT AUF` oder `FÄHRT ZU`

Bei KLF200 werden sowohl `MAIN` als auch der echte boolesche `RunStatus` automatisch erkannt und beobachtet. Bei einem Zentral-ZU-Befehl ist die KLF200-Zielrichtung sicher bekannt und wird während `RunStatus=true` als `FÄHRT ZU` angezeigt. Bei einer extern gestarteten KLF200-Fahrt ohne veröffentlichte Richtungsinformation wird bewusst nur `LÄUFT` angezeigt.

Die Visualisierung zeigt zusätzlich für ca. 3 Sekunden **„Befehl gesendet“** nach dem Zentral-ZU-Tastendruck.
