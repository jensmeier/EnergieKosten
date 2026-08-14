# BUILD8
- Kleine Kachel übernimmt jetzt das Symcon-Theme über `--content-color` und `--accent-color`; eigener dunkler Verlauf, Außenrahmen und Schatten aus BUILD7 wurden entfernt.
- Transparenter Kachelhintergrund wie bei der bewährten HeizölKachel; dezente Innenflächen werden nur aus der Symcon-Inhaltsfarbe gemischt.
- Auto-Größe neu aufgebaut: Breite, Höhe und Fläche entscheiden zwischen micro / nano / mini / compact / normal / large; ResizeObserver und Orientation-Update bleiben aktiv.
- Wertehierarchie unverändert: Netzbezug/Einspeisung Euro groß und fett, kWh kleiner; Heizstab kWh groß, Liter kleiner.
- Detailansicht übernimmt ebenfalls Symcon-Farben statt eigener Light-/Dark-Palette.
- Webinhalt `Energie Detail` wird ab BUILD8 ohne zusätzliches Symcon-Padding dargestellt; bestehende Instanzen werden in `ApplyChanges()` migriert.
- Detailansicht nochmals verdichtet: kompakter Kopf, flachere Statistik-/Jahreskarten, adaptive short/very-short-Modi und kein vertikales Scrollen.
- Bei schmalen Ansichten bleibt die Höhe stabil; die vier unteren Karten werden nötigenfalls horizontal statt vertikal umgebrochen.
- Diagramm, geloggte Historie, Tag/Woche/30 Tage/Jahr, Balken-Details, Energiebilanz und Hochrechnung bleiben funktional unverändert.

# BUILD7
- Kleine Kachel komplett neu gestaltet: dunkle Karte passend zu den übrigen Tablet-Kacheln, kompakter Kopf „Energie“, Zeitraum-Chip und klarere Wertehierarchie.
- Echte Auto-Größe innerhalb der von Symcon vorgegebenen Kachelfläche: Schrift, Abstände und Radien skalieren anhand von Breite und Höhe per ResizeObserver.
- Detailansicht für 10-Zoll-Tablet im Querformat neu strukturiert und auf eine Bildschirmhöhe begrenzt; kein vertikales Scrollen mehr.
- Diagramm nutzt flexibel den verfügbaren oberen Bereich; horizontales Wischen zu älteren Tag/Woche/Monat/Jahr-Werten bleibt erhalten.
- Netzbezug, Einspeisung, Heizstab und Jahresrechnung stehen als vier kompakte Karten nebeneinander unter dem Diagramm.
- Jahresrechnung zeigt weiterhin Einspeisung/Netzbezug bisher + Hochrechnung sowie Energiebilanz, jetzt platzsparend.
- Balken-Detailinformation wird schwebend eingeblendet und vergrößert die Seite nicht.

# BUILD6
- Jahresrechnung vereinfacht: Einspeisung und Netzbezug jeweils „Bis jetzt“ in kWh + Euro sowie Hochrechnung bis 31.12.
- Deckungs-Prozentanzeigen aus der Jahresrechnung entfernt.
- Neue klare Anzeige „Energiebilanz“ = Einspeisevergütung minus Netzbezugskosten; bisher und als Jahreshochrechnung.
- Heizstab ist nicht Bestandteil der Jahresrechnung; Heizstab-/Ölwerte bleiben nur in der separaten Statistik.
- Wenn saisonale Vergleichsdaten vollständig sind, nutzt die Hochrechnung die saisonale Prognose; ansonsten gibt es eine als vorläufig gekennzeichnete lineare Hochrechnung.
- Diagramm Tag/Woche/Monat horizontal scroll-/wischbar: Tag bis 7 Tage zurück, Woche/30 Tage bis ca. 400 Tage zurück. Jahresansicht unterstützt ebenfalls ältere Jahre, sofern Archivdaten vorhanden sind.
- Diagramm startet immer beim aktuellsten Zeitraum; Button „Aktuell“ springt zurück nach rechts.
- Antippen eines Balkens zeigt Datum/Zeit, Netzbezug kWh+€, Einspeisung kWh+€ und Energiebilanz.
- Unter dem Diagramm werden Netzbezug, Einspeisung und Energiebilanz des aktuell sichtbaren Zeitfensters zusammengefasst.

# BUILD5
- Tablet-Kachel passt den Inhalt automatisch an die von Symcon vorgegebene feste Kachelfläche an; extra XS-Modus gegen abgeschnittene Werte.
- Vorhandene geloggte Tages-IDs 15785 (Netzbezug) und 56673 (Einspeisung) werden explizit als bevorzugte Historie ausgewiesen und genutzt.
- Detailseite trennt jetzt bisherige Deckung und echte Jahresprognose klar.
- Deckung über 100 % wird als „100 % gedeckt“ dargestellt; der tatsächliche Faktor (z. B. 31,3×) und der Überschuss bleiben sichtbar.
- Fehlende Winter-/Vergleichsdaten werden mit Archivbeginn und verwendeten IDs erklärt.

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
