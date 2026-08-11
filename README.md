# Energie & Kosten – IP-Symcon 9.0

Version 1.0 BUILD2 – 11.08.2026

## Ziel
Eine einzelne HTML-SDK-Kachel für Netzbezug, Einspeisung, Heizstab, Kosten, 30-Tage-Diagramm und saisonale Jahreshochrechnung.

## Standard-IDs
- Netzbezug Gesamtzähler: 46689
- Einspeisung Gesamtzähler: 49541
- Heizstab Leistung: 47596

Die IDs können in der Instanzkonfiguration geändert werden.

## Standard-Einstellungen
- Strompreis Netzbezug: 0,2788 €/kWh
- Einspeisevergütung: 0,0688 €/kWh
- Heizöl Energiegehalt: 10,0 kWh/L
- Kesselwirkungsgrad: 90 %
- Kachelwechsel: 8 s, einstellbar 3–60 s
- Archivierung automatisch: EIN

## Installation auf Raspberry Pi (lokales Modul)
1. Vorher IP-Symcon-Datensicherung erstellen.
2. ZIP entpacken. Der Ordner `EnergieKosten` muss anschließend unter `/var/lib/symcon/modules/` liegen.
   Erwartete Struktur: `/var/lib/symcon/modules/EnergieKosten/library.json` und `/var/lib/symcon/modules/EnergieKosten/EnergieKosten/module.php`.
3. IP-Symcon-Dienst kurz neu starten.
4. In der Konsole eine neue Instanz `Energie Kosten` hinzufügen (Hersteller `Eigene Module`).
5. IDs und Preise prüfen, `Änderungen übernehmen`.
6. Die Instanz als Kachel in der Kachelvisualisierung hinzufügen.

## Verhalten
- Kleine Kachel: Heute → Monat → Jahr, automatisch in der eingestellten Zeit.
- Antippen: dieselbe Instanz wird maximiert geöffnet.
- Große Ansicht: Diagramm + Tag/Woche/Monat/Jahr; `Monat` entspricht im Diagramm den letzten 30 Tagen.
- Werte darunter: Tag/Woche/Monat/Jahr für Netzbezug, Einspeisung und Heizstab.
- Heizstab: kWh und rechnerisch gesparte Liter Heizöl.
- Jahresprognose: nur saisonal. Fehlen Monatsdaten, wird keine irreführende lineare Sommer×12-Prognose ausgegeben.

## Wichtig zum ersten Start
Die Archivierung kann keine Vergangenheit erzeugen. Wenn die Fronius-Zähler vorher noch nicht archiviert wurden, sind Tag/Monat/Jahr zunächst Teilwerte ab Installation. Das 30-Tage-Diagramm ist nach 30 Tagen vollständig.

Der neue Heizstab-Gesamtzähler beginnt mit Installation dieses Moduls bei 0 kWh. Die bisherigen Heizstab- und Solar-Skripte werden durch dieses Modul NICHT geändert oder gelöscht und dürfen während der Testphase parallel weiterlaufen.

## Rückbau
Einfach die neue Instanz löschen/deaktivieren. Die bestehenden alten Skripte und Variablen bleiben unverändert. Vor dem späteren Aufräumen der alten IDs erst Werte mehrere Tage vergleichen.
