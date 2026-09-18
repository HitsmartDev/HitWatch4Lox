# HitWatch4Lox

**LoxBerry-Plugin: lokaler Netbird- und MQTT-Dienste-Watchdog mit Anti-Loop-Reboot-Eskalation.**

> Die installierte Version steht in der Plugin-Oberfläche (Kopfzeile) und im LoxBerry Plugin-Manager. Änderungen pro Release: siehe `CHANGELOG.md`.

---

## Warum dieses Plugin?

LoxBerrys im Kundeneinsatz senden Daten via MQTT (Loxone) und sind über einen installierten
[Netbird](https://netbird.io)-Client remote erreichbar. Der Netbird-Client auf Linux hat
jedoch kein zuverlässiges automatisches Reconnect-Verhalten nach längerem
Verbindungsverlust – ein bestätigtes, bekanntes Verhalten, dokumentiert in mehreren offenen
Issues im [`netbirdio/netbird`](https://github.com/netbirdio/netbird)-Repository. Manchmal
hilft nur ein manuelles `netbird down && netbird up`, in manchen Fällen erst ein voller
Systemneustart. Ohne Gegenmaßnahme sind betroffene Geräte remote weder erreichbar noch neu
startbar.

HitWatch4Lox löst dieses Problem **lokal auf jedem Gerät**, ohne sich selbst in einer
Neustart-Schleife zu verfangen.

---

## Die vier Funktionen

### 1. Netbird-Dienst-Watchdog

- Periodische Prüfung (Intervall einstellbar, Standard 300 s)
- Prüft nicht nur ob der Dienst läuft, sondern ob Netbird **tatsächlich verbunden** ist
  (`netbird status --detail` → Management- und Signal-Verbindung)
- Bei „nicht verbunden": einmalig `systemctl restart netbird`, kurze Wartezeit, erneute Prüfung
- Kein Loop – nur ein Versuch pro Erkennungszyklus

### 2. Automatischer Reboot-Eskalation (optional)

- Nur wirksam wenn Funktion 1 aktiv ist
- Ist die Verbindung nach dem Dienst-Neustart weiterhin nicht da: einmaliger Reboot des LoxBerry
- Getrennt von Funktion 1 ein-/ausschaltbar – nicht jeder Standort soll automatisch neu gebootet werden

### 3. Automatischer Reboot

- Frei wählbare Wochentage (Mehrfachauswahl) + gemeinsame Uhrzeit
- Frequenz einstellbar: jedes Mal oder nur jedes 2./3./4.… Mal – **pro Wochentag unabhängig
  gezählt** (z.B. Mo+Do bei "alle 2x" → jeder 2. Montag *und* jeder 2. Donnerstag, unabhängig)
- Komplett unabhängige Logik – läuft auch wenn Funktion 1/2 deaktiviert sind
- Reiner Wartungsneustart, unabhängig vom Netbird-Status
- Fangfenster (2× Prüfintervall, mind. 10 min): war der Daemon zum geplanten Zeitpunkt nicht
  aktiv, wird der Reboot an diesem Tag nicht nachträglich nachgeholt

### 4. MQTT-Dienste-Watchdog (optional)

- Überwacht Mosquitto-Broker und/oder LoxBerry MQTT-Gateway unabhängig voneinander
- Mosquitto: systemd-Dienststatus **und** echter TCP-Erreichbarkeitstest zum Broker-Port
- MQTT-Gateway: systemd-Dienststatus (Dienstname konfigurierbar, da versionsabhängig)
- Startet den jeweiligen Dienst bei Bedarf einmalig neu – kein Reboot, kein Cooldown nötig
- Nutzt dasselbe Prüfintervall wie Funktion 1

### Schutz vor Boot-Loops (Cooldown)

Vor jedem automatischen Reboot (Funktion 2 **und** Funktion 3) prüft das Plugin, ob in den
letzten X Stunden (Standard 6 h, einstellbar) bereits ein automatischer Reboot durch dieses
Plugin ausgelöst wurde. Falls ja, wird der Reboot unterdrückt und nur geloggt – das
verhindert einen Boot-Loop, wenn die eigentliche Ursache nicht am LoxBerry liegt (z.B.
Netzwerk/Router/ISP beim Kunden).

---

## Sicherheit

Der Daemon läuft als unprivilegierter `loxberry`-User. Alle root-pflichtigen Aktionen
(Netbird-Status abfragen, Dienst neu starten, rebooten) laufen ausschließlich über ein
separates Root-Helper-Skript (`netbird_watchdog_helper.sh`). Für Netbird (Funktion 1/2/3) gibt
die `sudoers`-Regel **ausschließlich** feste, argumentlose Aufrufe frei – keine Wildcards.
Einzige Ausnahme: `restart_service <name>` (Funktion 4) nimmt einen konfigurierbaren
Dienstnamen entgegen, der sowohl vom Daemon als auch vom Helper-Skript streng gegen ein
Identifier-Muster validiert wird, bevor er an `systemctl restart` übergeben wird.

---

## Optionale MQTT-Statusanzeige

HitWatch4Lox kann seinen Status (Verbindungsstatus, letzte Aktionen, Cooldown) optional als
MQTT-Nachrichten an Loxone veröffentlichen (Standard-Präfix `HitWatch/netbird_watchdog/`).
Das ist rein informativ – die Watchdog-Kernfunktion ist von MQTT vollständig unabhängig.
Vollständige Topic-Referenz: siehe Hilfe-Tab im Plugin.

---

## Zentrale Überwachung über alle Geräte

HitWatch4Lox deckt die **lokale Selbstheilung** ab. Für die proaktive Benachrichtigung, wenn
ein LoxBerry im Netbird-Netzwerk komplett unerreichbar wird, kommt separat ein n8n-Workflow
zum Einsatz, der die Netbird-API zentral abfragt. Dieser Teil ist bewusst nicht Bestandteil
dieses Repositories.

---

## Installation

1. ZIP-Datei aus den [Releases](https://github.com/HitsmartDev/HitWatch4Lox/releases) herunterladen
2. In LoxBerry: **Plugin Manager → ZIP-Datei installieren**
3. Funktion 1 (Dienst-Watchdog) ist nach der Installation standardmäßig aktiv; Funktion 2 und 3
   sind standardmäßig deaktiviert und können in den Einstellungen aktiviert werden.

## Voraussetzungen

- LoxBerry ≥ 2.0
- Python ≥ 3.8
- Installierter und konfigurierter Netbird-Client

## Lizenz / Autor

HitSmart / Stefan – siehe `plugin.cfg`.
