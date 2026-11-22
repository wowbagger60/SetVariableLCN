# SetVariableLCN

Deutsch | [English](README.md)

Modul für IP-Symcon von Ueli Schweizer / Cassanis Automation. Präfix: `SVLCN`

Schreibt Symcon-Werte wie Azimut, Höhenwinkel der Sonne oder Aussentemperatur
zuverlässig in LCN-Variablen, damit LCN-Module mit Schwellwerten darauf
reagieren können.

> LCN kann Variablen nur relativ ändern (oder auf 0 setzen), und Telegramme
> gehen gelegentlich verloren. Das Modul arbeitet darum als Regelkreis: Es
> sendet die Differenz Soll − Ist nur, wenn es den Ist-Wert sicher kennt, und
> fragt sonst nach.

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Einrichtung](#2-einrichtung)
3. [Umrechnung und der Wert 0](#3-umrechnung-und-der-wert-0)
4. [Ablauf](#4-ablauf)
5. [Störung](#5-störung)
6. [Einstellungen](#6-einstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Unterstützung](#8-unterstützung)
9. [Änderungen](#9-änderungen)

## 1. Voraussetzungen

- IP-Symcon ab 8.0 mit dem LCN-Modul von Symcon.
- Pro LCN-Modul eine Instanz «LCN Value», in der die genutzten Variablen mit
  dem Werttyp **Zähler** aktiviert sind (A1–A12 bei Firmware ab Feb. 2013,
  T/R1/R2 = Variable 1/2/3).
- **LCN selbst darf diese Variablen nie verändern** (keine Tastenbefehle,
  Sensoren oder Regler darauf). Das Modul würde jede Änderung zurücksetzen.

## 2. Einrichtung

Instanz «SetVariableLCN» anlegen und in der Liste «Werte» pro Wert eine Zeile
erfassen: Quelle (Symcon-Variable), LCN-Variable (Variable unter LCN Value),
Faktor, Offset, Totband, maximales Alter der Quelle und Firmware.

## 3. Umrechnung und der Wert 0

Rohwert = Quelle × Faktor + Offset, gerundet und auf 0 bis 30000 begrenzt.

Nach einem Neustart steht eine LCN-Variable auf 0. Faktor und Offset sollten
so gewählt sein, dass gültige Werte nie 0 ergeben; 0 heisst dann «ungültig»,
und die Schwellwerte im LCN-Modul können das abfangen. Beispiele:

| Wert | Bereich | Faktor | Offset | Rohwerte |
|---|---|---|---|---|
| Aussentemperatur | −40 … 60 °C | 10 | 1000 | 600 … 1600 (LCN-Temperaturformat) |
| Azimut | 0 … 360° | 10 | 1 | 1 … 3601 |
| Höhenwinkel | −90 … 90° | 10 | 1000 | 100 … 1900 |

Mit «Quelle max. Alter» schreibt das Modul 0, wenn die Quelle länger nicht
aktualisiert wurde (z. B. Wetterstation ausgefallen).

## 4. Ablauf

Pro Wert:

1. Liegt der Ist-Wert im Totband um den Sollwert, ist nichts zu tun.
2. Der Ist-Wert gilt als **frisch**, wenn er nach dem letzten Schreiben
   aktualisiert wurde (Zeitstempel «Aktualisiert», nicht «Geändert») und nicht
   älter als «LCN-Wert abfragen, wenn älter als» ist.
3. Ist er frisch und weicht ab, sendet das Modul die Differenz (`Z+`/`Z-`,
   höchstens 4000 pro Telegramm; bei grossen Sprüngen nach unten über 0).
   Danach wartet es die Wartezeit ab.
4. Ist er nicht frisch, fragt das Modul den Wert ab (`MWTn`; bei T/R1/R2 mit
   `LCN_RequestRead` über die LCN-Value-Instanz) und wartet auf die Antwort.

5. Bleiben die Abfragen unbeantwortet, setzt das Modul die LCN-Variable einmal
   auf 0 und fragt dann erneut. Ein LCN-Modul antwortet nach dem Einschalten
   nicht auf Abfragen einer Variable, die seither nie gesetzt wurde; das Nullen
   macht sie abfragbar.

Ein verlorenes Telegramm führt so höchstens zu einer zusätzlichen Abfrage, nie
zu einer doppelten Addition. Die periodische Abfrage findet auch eine
LCN-Variable, die nach einem Neustart des LCN-Moduls auf 0 steht.

## 5. Störung

Nach «Runden ohne Wirkung bis zur Störung» Schreibversuchen ohne Erfolg (oder
ebenso vielen unbeantworteten Abfragen) sendet das Modul nicht weiter, schreibt
eine Meldung ins Log und setzt die Variable «Störung». Ein neuer Sollwert, die
Schaltfläche «Störungen zurücksetzen» oder ein wieder passender Ist-Wert heben
die Störung auf.

## 6. Einstellungen

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Wartezeit nach dem Schreiben | 3 s | So lange gilt nach dem Senden noch kein neuer Versuch |
| Runden ohne Wirkung bis zur Störung | 3 | Siehe [Störung](#5-störung) |
| LCN-Wert abfragen, wenn älter als | 900 s | Periodische Kontrolle; 0 = nur nach dem Schreiben |

Firmware «bis Nov. 2010» nutzt `ZA`/`ZS` und kann nur T (Tvar) schreiben.

## 7. PHP-Befehle

```php
SVLCN_Check(int $InstanceID);        // Regelkreis sofort ausführen
SVLCN_ResetFaults(int $InstanceID);  // Störungen und Rundenzähler zurücksetzen
SVLCN_GetStatus(int $InstanceID);    // Array mit Soll, Ist, Aktualisiert, Runden, Störung je Wert
```

## 8. Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## 9. Änderungen

**1.0 Build 4:**

- Antwortet ein LCN-Modul nicht auf die Abfrage einer Variable, setzt das
  Modul sie einmal auf 0 und fragt erneut. Nach dem Einschalten eines
  LCN-Moduls antwortet es für nie gesetzte Variablen nicht, bisher wurden
  diese darum nie geschrieben. Bestehende Störungen «keine Antwort» heilen
  sich nach dem Update selbst.

**1.0 Build 3:** Erstveröffentlichung.
