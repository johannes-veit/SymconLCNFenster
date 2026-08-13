# Changelog

## 0.2.2

- Zentral-ZU-Hardwarebefehle vollständig vom 1-s-Sequenztimer entkoppelt.
- Jeder notwendige Fensterbefehl wird über `IPS_RunScriptText()` in einem eigenen asynchronen Script-Kontext gestartet.
- Ein blockierender KLF200-Node (`WaitForFinishSession`) kann dadurch nachfolgende LCN-Fenster nicht mehr anhalten.
- KLF200 `RunStatus` wird automatisch erkannt und beobachtet.
- KLF200-Statusliste zeigt während Bewegung `LÄUFT`; bei sicher bekannter Richtung `FÄHRT ZU` bzw. `FÄHRT AUF`.
- Zentral-ZU setzt für KLF200 sicher den Richtungshinweis `FÄHRT ZU`, ohne dessen Positions-/Statusvariablen künstlich zu verändern.
- Bestehendes Einzelmodul `LCNWindow` bleibt bytegleich zur stabilen 0.1.1.

## 0.2.1

- Fensterstatusliste und 3-s-Rückmeldung `Befehl gesendet` ergänzt.
- Queue prüft den Fensterzustand erst im jeweiligen Slot.

## 0.2.0

- Neue Instanz `LCN Fenster Zentral ZU`.
