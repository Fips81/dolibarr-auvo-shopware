# AuVo Shopware

[![Neueste Version](https://img.shields.io/github/v/release/Fips81/dolibarr-auvo-shopware?label=version&sort=semver)](https://github.com/Fips81/dolibarr-auvo-shopware/releases/latest)

**Dolibarr mit einem Shopware-6-Shop verbinden.** Das Modul überträgt Artikel
aus Dolibarr in den Shop, holt Bestellungen als Aufträge nach Dolibarr und
meldet Status und Rechnung an Shopware zurück. Dolibarr bleibt dabei das
führende System für Artikel, Preise und Lager.

- **Artikel-Sync:** Bezeichnung, Beschreibung, Verkaufspreis, Einkaufspreis,
  Lagerbestand, Bilder und Aktiv-Status, gesteuert über eine
  Dolibarr-Kategorie. Der Kategoriebaum wird in Shopware gespiegelt.
- **Varianten:** Dolibarr-Produktvarianten (Modul „Varianten“) werden zu
  Shopware-Varianten mit Eigenschaftsgruppen, eigenem Preis und Bestand.
- **Bestellabruf:** Shopware-Bestellungen werden als freigegebene Aufträge
  angelegt. Der Kunde wird über die E-Mail-Adresse gefunden oder neu angelegt.
- **Status zurückmelden:** Auftragsstatus aus Dolibarr (in Bearbeitung,
  erledigt, storniert) wird über die Shopware-Statusmaschine übertragen.
- **Echte Rechnung im Shop:** Die Dolibarr-Rechnung zum Auftrag wird als PDF
  hochgeladen und erscheint in Shopware als Rechnungsdokument der Bestellung,
  für den Kunden sichtbar.
- **Schnell auch bei großen Katalogen:** Übertragung gebündelt über die
  Shopware Sync API, unveränderte Artikel werden übersprungen.

Passt zu [AuVo E-Rechnung](https://github.com/Fips81/dolibarr-auvo-erechnung):
Die Rechnung, die im Shop landet, ist dann eine ZUGFeRD-Rechnung.

> AuVo Shopware ist ein unabhängiges Modul für Dolibarr und kein offizielles
> Produkt der Dolibarr Foundation oder der shopware AG. Shopware und Dolibarr
> sind Namen bzw. Marken ihrer jeweiligen Inhaber.

## Voraussetzungen

- **Dolibarr** ab 19.0, entwickelt und im Einsatz mit 24.0
- **PHP** mit der Erweiterung `curl`
- **Shopware 6** mit Zugriff auf die Admin API
- Dolibarr-Module **Produkte**, **Kategorien** und **Kundenaufträge**; für
  Varianten zusätzlich **Varianten**, für die Rechnung **Rechnungen**

## Installation

1. `module_auvoshopware-X.Y.Z.zip` aus den
   [Releases](https://github.com/Fips81/dolibarr-auvo-shopware/releases)
   herunterladen.
2. Entpacken und den Ordner `auvoshopware/` nach `htdocs/custom/` kopieren
   (Besitzer: Webserver-Benutzer, z. B. `www-data`). Alternativ in Dolibarr
   unter *Einstellungen → Module → Externes Modul hochladen*.
3. Unter *Einstellungen → Module* **AuVo Shopware** aktivieren und den
   Benutzern die Rechte „View the Shopware sync dashboard“ (Übersicht ansehen)
   bzw. „Trigger Shopware synchronization“ (Sync auslösen) geben.
4. In Shopware unter *Einstellungen → System → Integrationen* eine neue
   Integration mit Administratorrechten anlegen und Zugangs-ID und
   Sicherheitsschlüssel notieren.
5. In den Moduleinstellungen eintragen:
   - Shop-URL, Client-ID und Client-Secret, dann **Verbindung testen**
   - Quellkategorie in Dolibarr: Nur Artikel in dieser Kategorie und ihren
     Unterkategorien werden übertragen.
   - Elternkategorie, Steuersatz und Verkaufskanal in Shopware. Die nötigen
     IDs zeigt die Einstellungsseite unter „Shopware-Referenzwerte“ an.
   - Optional: welche Bestellstatus importiert werden. Leer heißt: alle außer
     stornierten.

Danach gibt es im Hauptmenü den Eintrag **AuVo Shopware** mit Artikelliste,
Sync-Buttons und den importierten Bestellungen.

**Update:** Ordner `custom/auvoshopware` durch den neuen ersetzen (alten
Ordner vorher **außerhalb** von `custom/` sichern, sonst lädt Dolibarr beide),
dann das Modul einmal deaktivieren und wieder aktivieren. Einstellungen und
Zuordnungen bleiben dabei erhalten.

## Wie es funktioniert

### Artikel

- Artikel und Varianten bekommen in Shopware eine feste ID, die aus der
  Dolibarr-Referenz abgeleitet wird. Ein erneuter Sync aktualisiert also immer
  denselben Shop-Artikel, auch nach dem Zurücksetzen der Zuordnungstabelle.
- Pro Artikel wird ein Prüfwert der übertragenen Daten gespeichert. Ist er
  unverändert, wird der Artikel beim nächsten Sync übersprungen.
- Bilder kommen aus dem Dokumentenordner des Produkts (jpg, png, gif, webp,
  bmp). Das erste Bild wird Titelbild. In Dolibarr gelöschte Bilder werden auch
  in Shopware entfernt.
- Optionale Zusatzfelder am Produkt werden mit übertragen, wenn es sie gibt:

  | Zusatzfeld (Code)       | in Shopware                         |
  |-------------------------|-------------------------------------|
  | `hersteller`            | Hersteller                          |
  | `hersteller_uvp`        | Streichpreis (UVP, brutto)          |
  | `materialeigenschaften` | Zusatzfeld „Materialeigenschaften“  |
  | `druckdetails`          | Zusatzfeld „Druckdetails“           |

### Bestellungen

- Abgerufen werden die Bestellungen in den eingestellten Status, unabhängig
  vom Zahlungsstatus. So kommen auch Kauf auf Rechnung und Nachnahme sofort an.
- Jede Bestellung wird nur einmal importiert.
- Kunden werden über die E-Mail-Adresse gesucht. Neue Kunden bekommen
  Kundennummern ab 100001, getrennt von manuell vergebenen Nummern.
- Übernommen werden die Artikelzeilen, zugeordnet über die Artikelnummer
  (Shopware-Artikelnummer = Dolibarr-Referenz). Versandkosten und Rabatte
  werden derzeit nicht als Zeilen übernommen.

### Zurück an Shopware

- **Status melden:** Auftrag in Bearbeitung → `in_progress`, erledigt →
  `completed`, storniert → `cancelled`. Das Modul fragt die erlaubten
  Übergänge bei Shopware ab und geht bei Bedarf mehrere Schritte.
- **Rechnung senden:** Alle mit dem Auftrag verknüpften Dolibarr-Rechnungen
  werden als PDF erzeugt, hochgeladen und als Rechnungsdokument an die
  Bestellung gehängt. Die Rechnungsnummer steht zusätzlich in einem
  Zusatzfeld der Bestellung.

### Automatisch per Cron

Das Modul bringt den geplanten Job „Mit Shopware synchronisieren“ mit
(stündlich, Artikel-Sync und Bestellabruf). Er ist nach der Installation
**deaktiviert** und lässt sich unter *Start → Einstellungen → Geplante Jobs*
einschalten, sobald Verbindung und Sync-Umfang geprüft sind. Dafür muss der
Dolibarr-Cron eingerichtet sein.

## Entwicklung und Releases

Bei jedem Push prüft die GitHub-CI die Syntax aller PHP-Dateien und ob die
deutsche und englische Sprachdatei dieselben Schlüssel haben. Das Release-ZIP
`module_auvoshopware-X.Y.Z.zip` entsteht automatisch, sobald ein Tag `vX.Y.Z`
gepusht wird (`.github/workflows/release-zip.yml`: Der Tag muss zur
Modulversion passen, das ZIP ohne `.github/` und `build/` hängt am Release).

## Changelog

### 0.16.0 (November 2026)

- Modulname „AuVo Shopware“ und eigene Modulnummer 194205 aus dem Block von
  AuVo Design (vorher Platzhalter 500000). Nach dem Update das Modul einmal
  deaktivieren und wieder aktivieren und die Rechte neu vergeben.
- README auf Deutsch, Aufräumen der Vorlagen-Dateien.
- GitHub-CI (PHP-Syntax, Sprachdateien) und automatisches Release-ZIP.

### 0.15.0 (August 2026)

- Zusatzfelder Materialeigenschaften und Druckdetails als Shopware-Zusatzfelder.
- Dolibarr-Rechnung als Shopware-Dokument hochladen, Bestellstatus
  zurückmelden.
- Bestellabruf mit Kundenzuordnung, Cron-Job, Bilder löschen.

## Lizenz

GPL v3 oder später, siehe [COPYING](COPYING).

## Über dieses Projekt

AuVo Shopware wird von auvo-design gepflegt und steht allen Dolibarr-Nutzern
frei zur Verfügung. Fehler und Wünsche bitte als
[GitHub-Issue](https://github.com/Fips81/dolibarr-auvo-shopware/issues)
melden. Sicherheitslücken bitte nicht als Issue, sondern an
r.vogel@auvo-design.de.
