# Changelog

## V1 BUILD2 – 11.08.2026
- Fix: `UpdateVisualizationValue()` erhält komplexe Visualisierungsdaten jetzt als JSON-String. Dadurch tritt beim Erstellen der Instanz unter IP-Symcon 9.0 nicht mehr `Cannot auto-convert value for parameter Value (Type is not supported)` auf.
- HTML-SDK `handleMessage()` verarbeitet sowohl JSON-Strings als auch direkte Werte.
- Autor in `library.json` auf `JZCG` geändert.

## 1.0 BUILD1 – 11.08.2026
- Erste Testversion für IP-Symcon 9.0.
- HTML-SDK-Kachel, kompakt + maximierte Detailansicht.
- Automatischer Wechsel Heute/Monat/Jahr, 3–60 s einstellbar.
- Diagramm Tag/Woche/letzte 30 Tage/Jahr.
- Tag/Woche/Monat/Jahr für Netzbezug und Einspeisung in kWh + €.
- Heizstab in kWh + rechnerisch gesparte Liter Heizöl.
- Ein neuer fortlaufender Heizstab-Gesamtzähler.
- Automatische Archivaktivierung und Aggregationstyp Zähler.
- Saisonale Jahresprognose; keine lineare Jahreshochrechnung bei fehlender Saisonhistorie.
- Alte Skripte/IDs bleiben unangetastet (Paralleltest).
