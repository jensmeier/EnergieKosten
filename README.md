# Energie & Kosten – IP-Symcon 9.0

Version 1.0, BUILD3 · Autor JZCG

## Zweck
Kompakte Energie-Kachel mit automatisch wechselnder Anzeige Heute / Monat / Jahr sowie maximierter Detailansicht mit Tag / Woche / letzte 30 Tage / Jahr.

## Vorhandene Daten werden weiterverwendet
BUILD3 ist für die bestehende Installation vorbereitet und liest die bereits vorhandenen Summenvariablen der bisherigen Skripte automatisch. Dadurch stimmen Heute, Woche, Monat und Jahr sofort mit den vorhandenen Zählern überein und beginnen nicht neu bei null.

Für Diagramm und saisonale Prognose werden bevorzugt die bereits geloggten Tages-kWh-Variablen ausgewertet. Das Modul legt dafür keine zusätzlichen Tag-/Woche-/Monat-/Jahr-Variablen an.

Die bisherigen Solar- und Heizstab-Skripte werden weder verändert noch gelöscht und sollen während des Paralleltests weiterlaufen.

## Kachel
- automatischer Wechsel Heute → Monat → Jahr
- Wechselzeit 3–60 s einstellbar, Standard 8 s
- Netzbezug: kWh + €
- Einspeisung: kWh + €
- Heizstab: kWh + gesparte Liter Heizöl

## Detailansicht
- Tag: stündliche Verteilung
- Woche: tägliche Werte
- Monat: letzte 30 Tage
- Jahr: Monatswerte
- saisonale Jahreshochrechnung für Netzbezug und Einspeisung
- energetische und finanzielle Deckung
- Heizstab-/Heizöl-Prognose, soweit historische Heizstab-Tagesdaten vorhanden sind

## Standardquellen
- Netzbezug Gesamt: 46689
- Einspeisung Gesamt: 49541
- Heizstab Leistung: 47596

Zusätzlich werden die bekannten Bestandsvariablen automatisch gelesen, ohne dass sie in der Modulkonfiguration einzeln eingetragen werden müssen.
