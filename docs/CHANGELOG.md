# Changelog

## 0.2.1

- Zentral-ZU-Sequenz stabilisiert: der 1-s-Modultimer bleibt während der gesamten Queue aktiv und wird nicht mehr nach jedem Callback deaktiviert und neu aktiviert.
- Regression für drei und mehr tatsächlich notwendige Folgekommandos ergänzt.
- Alle ausgewählten Fenster werden in die Queue aufgenommen; der Istzustand wird erst unmittelbar vor dem jeweiligen Slot geprüft.
- Dadurch können kurz veraltete Zustände beim Tastendruck kein späteres Fenster mehr aus der Sequenz entfernen.
- Visualisierung: kurze Rückmeldung **„Befehl gesendet“** für 3 Sekunden.
- Visualisierung: Statusliste aller ausgewählten LCN-Fenster und KLF200-Nodes, ohne internes Scrollfenster.
- LCN-Statusvariable bzw. KLF200-`MAIN` werden beobachtet; Änderungen aktualisieren die Gruppen-Kachel ohne künstliches Setzen von Status-/Positionswerten.
- Bestehendes `LCNWindow` bleibt gegenüber 0.2.0 unverändert.

## 0.2.0

- Neue Instanz `LCN Fenster Zentral ZU` / `Alle Fenster ZU`.
- Gemischte Auswahl aus LCN-Fenster- und VELUX-KLF200-Node-Instanzen.
- 1-s-Abstand zwischen erforderlichen Schließbefehlen.
- LCN-Fenster schließen über `LCW_Close()`.
- KLF200 Node schließt über `KLF200_ShutterMoveDown()`.
