# Energie & Kosten – V1 BUILD9

IP-Symcon 9.0 Modul von **JZCG**.

## BUILD9
- Rasteradaptive Kachel statt reinem Verkleinern der Schrift.
- Eigene Layouts für typische Symcon-Rastergrößen: 1x1, 1x2/1x3, 2x1, 2x2, 3x1, 3x2, 2x3 und 3x3.
- 1x1 zeigt bewusst nur eine Kennzahl gleichzeitig und wechselt innerhalb des eingestellten Zeitraums zwischen Netzbezug, Einspeisung und Heizstab.
- 2x1/3x1 und 3x2 nutzen die Breite mit drei Spalten.
- 2x2/2x3/3x3 zeigen die vollständige Übersicht mit Haupt- und Nebenwerten.
- Energiebilanz wird nur eingeblendet, wenn genügend Platz vorhanden ist.
- Symcon-Theme über `--content-color` / `--accent-color`, transparenter Hintergrund.
- Detailansicht, Archivdaten, historische Diagramme und Jahreshochrechnung bleiben gegenüber BUILD8 unverändert.
- Bestehende Solar-/Heizstab-Skripte und IDs werden nicht gelöscht oder verändert.

## Bedienung
- Die Kachel wechselt nach der eingestellten Zeit zwischen **Heute → Monat → Jahr**.
- In 1x1 werden innerhalb dieses Zeitraums zusätzlich die drei Kennzahlen nacheinander gezeigt, sodass nichts unlesbar klein wird.
- Antippen öffnet die separate Detailansicht `Energie Detail`.
- Dort bleiben Tag / Woche / letzte 30 Tage / Jahr sowie das horizontale Zurückscrollen erhalten.
