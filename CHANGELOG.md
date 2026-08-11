# Changelog

## V1 BUILD4
- Autor JZCG.
- Kleine Kachel: Netzbezug und Einspeisung zeigen Euro groß/fett, kWh kleiner darunter.
- Heizstab zeigt kWh groß/fett und gesparte Liter Öl kleiner.
- Automatische Größenanpassung per ResizeObserver; Hinweis verschwindet bei kleinen Kacheln.
- Neue String-Variable „Energie Detail“ mit Webinhalt-Darstellung.
- Antippen der kleinen Kachel öffnet jetzt die Detailvariable statt der Modulinstanz.
- Detailansicht enthält Diagramm Tag/Woche/letzte 30 Tage/Jahr, Summen und saisonale Prognose.
- Bestehende Alt-Skripte und Bestands-IDs bleiben unverändert.


## V1 BUILD3
- Fehler behoben: Heute/Monat/Jahr zeigten den Fronius-Lebenszeitstand statt den Zeitraumverbrauch.
- Vorhandene kWh-/Euro-Summen werden automatisch genutzt: Netzbezug, Einspeisung und Heizstab für Tag/Woche/Monat/Jahr.
- Bereits geloggte Tagesvariablen werden für 7-/30-Tage-Diagramm und saisonale Monats-/Jahreshistorie verwendet.
- Vorhandenes Archiv wird nicht mehr unnötig umkonfiguriert; bestehender Aggregationstyp bleibt erhalten.
- Erste Zählerperiode nach neu aktiviertem Logging wird bei Fallback-Auswertung übersprungen, damit kein kompletter Lebenszeitstand als Tagesverbrauch erscheint.
- Doppelten Titel in der kleinen Kachel entfernt.
- Autor: JZCG.

## V1 BUILD2
- HTML-SDK Visualisierungsdaten werden als JSON-String übertragen.
- Autor auf JZCG geändert.

## V1 BUILD1
- Erste Version.
