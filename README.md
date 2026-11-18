# Gewächshaus Kachel (IP-Symcon)

HTML-SDK-Kachel für die Kachel-Visualisierung: Übersicht und Bewässerung von Gewächshäusern und Beeten mit dem
GARDENA smart system (Symcon-Modul `de.symcon.gardena`).

## Funktionen
- Je Bereich (Gewächshaus, Hochbeet, Blumenbeet …):
  - Raumklima von einem Zigbee2MQTT-Gerät (Temperatur, Luftfeuchte, Batterie, „seit … keine Daten“)
  - Boden von einem GARDENA Sensor (Feuchte mit Grenzstrich, Bodentemperatur, Batterie)
  - Ventil (GARDENA Valve): Zustand, Restlaufzeit, Hand-Start 5/10/15/30 min, Stopp
- Automatik je Bereich: Startzeiten mit eigener Dauer (`05:50=5, 06:00=15`), Wochentage.
  Vor jedem Start wird geprüft und im Protokoll begründet:
  - Ventil erreichbar?
  - Frost (Gewächshaus: Raumtemperatur, sonst Außentemperatur) unter der Grenze → nicht gießen
  - Bodenfeuchte ≥ Grenze → nicht gießen (Bodenwert älter als 26 h → nach Zeitplan)
  - nur Bereiche „im Freien“: es regnet gerade, hat in 24 h länger als N min geregnet (Regensensor + Archiv)
    oder die Regenprognose der nächsten 24 h erreicht die Grenze → nicht gießen
- Regenprognose aus der JSON-Variable des Skripts „Wettervorhersage_DWD“ (DWD MOSMIX, Stundenwerte `prec` in mm):
  Summen 24/48/72 h, Stundenbalken, Tageswerte.
- Pumpe Zisterne: schalten in der Kachel. „Wasser kommt aus der Zisterne“ (Standard): Automatik gießt nur bei laufender Pumpe,
  sonst Hinweis in der Kachel. Optional schaltet das Modul die Pumpe vor jeder Bewässerung ein (mit Vorlauf) und danach wieder aus.
- Automatik-Läufe nacheinander (Warteschlange), Hand-Starts sofort (GARDENA: höchstens 2 Ventile gleichzeitig).
- Einstellungen (Automatik, Startzeiten, Wochentage, Grenzen) lassen sich in der Kachel ändern; sie landen in der Instanz-Eigenschaft.
- Kleine Kachel (unter 300 px Höhe): kompakte Liste, Tippen öffnet die eingestellte Seite.
- Gestaltung über das Modul „Visu Design“ (ab 1.7): Farben (`W5D_GetCSS`), Kachel-Bausteine (`W5D_GetTileCSS`, Wurzel `.w5t.fill`:
  Karten, Zeilen, Chips, Buttons, Schalter, Rundknöpfe, Eingaben, Badges) und Hell/Dunkel (`W5D_GetTileJS` → `w5tTheme()`).
  Eigenes CSS nur für Regenbalken, Tageswerte und Messwertfelder, ausschließlich mit `--w5t-*`/`--w5-*` (Wasser = `--w5t-cold`).
  Theme-Wechsel kommt ohne Neuladen an (CSS im Zustand, Nachricht IM_CHANGESETTINGS der Design-Instanz).

## Einrichtung
Modulverwaltung → `https://github.com/cippo-69/IPSymconGewaechshausKachel` → Instanz „Gewächshaus Kachel“ anlegen,
Bereiche eintragen, Instanz bzw. Link in die Kachel-Visu legen. `GWK_Check($id)` zeigt, was jetzt gegossen würde.

## Hinweise
- Die Öffnungsdauer der GARDENA-Ventil-Instanz wird für den Start kurz auf die gewünschte Dauer gesetzt und danach zurückgestellt.
- Die eigenen Wochenpläne an den GARDENA-Ventilen sollten aus sein, sonst gießt es doppelt.
