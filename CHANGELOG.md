# Changelog – HitWatch4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

## [0.3] – 2026-09-17

### Geändert – Funktion 3: mehrere Wochentage + Frequenz statt einem Wochentag
- Auf Nutzerwunsch umgebaut: Funktion 3 heißt jetzt schlicht **"Automatischer Reboot"** und
  unterstützt eine **Mehrfachauswahl** an Wochentagen (statt genau einem) sowie eine
  **Frequenz** ("Jedes Mal" oder "nur alle 2x/3x/…/8x"). Die Frequenz wird **pro Wochentag
  unabhängig gezählt** – bei Auswahl Montag + Donnerstag und Frequenz "alle 2x" löst z.B. jeder
  2. Montag *und* jeder 2. Donnerstag aus, mit jeweils eigenem Zähler.
- Config-Section `[WEEKLY_REBOOT]` (`WEEKDAY`) → `[SCHEDULED_REBOOT]` (`WEEKDAYS`,
  kommagetrennt, + `EVERY_N`). `last_auto_reboot_reason`-Wert `weekly_scheduled` →
  `scheduled_reboot`. Neues `state.json`-Feld `reboot_weekday_counters` (Zähler je ISO-Wochentag).
- Das Fangfenster aus v0.2 gilt weiterhin pro Wochentag: wird es verpasst, bleibt der
  Frequenz-Zähler für diesen Tag unverändert (kein Verschieben der Zählung durch Ausfallzeiten).
- UI: Wochentage jetzt als Chip-Mehrfachauswahl (Mo–So) statt Dropdown; neues Frequenz-Auswahlfeld.

---

## [0.2] – 2026-09-17

### Behoben – Automatischer Reboot löste lautlos keinen Neustart aus
- Im ersten Testlauf (Funktion 3 aktiviert, Zeitpunkt erreicht) fand kein Reboot statt, ohne
  Fehler im Log. Ursache: `netbird_watchdog_helper.sh` löste den Reboot bisher als
  Hintergrundjob (`(sleep 3 && /sbin/reboot) &`) aus, damit der aufrufende Python-Daemon nicht
  blockiert. Auf einem systemd-System kann `pam_systemd` beim Beenden der `sudo`-Session
  (die endet, sobald das Helper-Skript zurückkehrt) verwaiste Hintergrundjobs der Session
  mitbeenden – der Sleep lief dann nie zu Ende, der eigentliche `reboot`-Aufruf fand nie statt.
- Fix: `systemctl reboot` statt manuellem Hintergrundjob. `systemctl reboot` ist von sich aus
  asynchron – es meldet die Anfrage an systemd (PID 1) und kehrt sofort zurück; der eigentliche
  Neustart läuft danach unabhängig vom aufrufenden Prozessbaum. Fallback auf `/sbin/reboot`
  bleibt für Non-systemd-Systeme erhalten. Zusätzlich loggt der Daemon jetzt auch den
  Erfolgsfall der Reboot-Anfrage (vorher nur Fehlerfall).

### Behoben – Reboot löste bei jeder Neuinstallation erneut aus
- Die "heute schon erledigt"-Sperre für den automatischen Reboot lebt in `state.json`
  (`data/plugins/hitwatch4lox/`). Eine Deinstallation räumt dieses Verzeichnis weg
  (LoxBerry-Standardverhalten) – die Sperre war danach weg. Da die alte Prüfung nur
  `"aktuelle Zeit >= HH:MM an Tag X"` **ohne Obergrenze** war, galt der Reboot dann für den
  Rest des Tages als fällig – jede Neuinstallation nach der geplanten Uhrzeit löste erneut
  einen Reboot aus.
- Fix: Fangfenster (2× `CHECK_INTERVAL`, mind. 10 Minuten) nach der geplanten Uhrzeit. Ein
  (Neu-)Start des Daemons außerhalb dieses Fensters (z.B. nach einer Neuinstallation Stunden
  später) löst nichts mehr aus – das Fenster gilt dann als verpasst, kein nachträgliches
  Auslösen bis zum nächsten passenden Wochentag.

### Geändert
- Versionsnummer auf 0.2 (vorher 0.1) angehoben, damit ein erneuter ZIP-Upload von LoxBerry
  eindeutig als Update (State bleibt erhalten) statt als Neuinstallation erkannt wird.

---

## [0.1] – 2026-09-17

### Hinzugefügt – Erstversion
- LoxBerry-Plugin mit eigener Web-UI (Status / Einstellungen / Log / Hilfe), aufgebaut auf
  demselben iframe-isolierten UI-Framework wie Unwetter4Lox.
- **Funktion 1 – Netbird-Dienst-Watchdog:** periodische Prüfung von `netbird status --detail`
  (Management- und Signal-Verbindung), einmaliger Dienst-Neustart bei Verbindungsverlust,
  konfigurierbares Prüfintervall.
- **Funktion 2 – Automatischer Reboot-Eskalation:** optionaler, einmaliger Reboot wenn Netbird
  auch nach dem Dienst-Neustart nicht verbunden ist. Nur wirksam bei aktiver Funktion 1.
- **Funktion 3 – Geplanter wöchentlicher Reboot:** unabhängiger Wartungsneustart zu frei
  wählbarem Wochentag/Uhrzeit (in v0.3 zu Mehrfachauswahl + Frequenz erweitert).
- **Cooldown-Schutz** gegen Boot-Loops: gemeinsamer, konfigurierbarer Zeitraum (Standard 6 h),
  den Funktion 2 und Funktion 3 vor jedem automatischen Reboot respektieren; Zustand persistent
  in `state.json`.
- Root-pflichtige Aktionen (Netbird-Status, Dienst-Neustart, Reboot) ausschließlich über
  `netbird_watchdog_helper.sh` mit drei festen Unterbefehlen, `sudoers`-Freigabe exakt darauf
  beschränkt.
- Optionale, rein informative MQTT-Statusveröffentlichung (Standard-Präfix
  `HitWatch/netbird_watchdog/`) als kurzlebige Verbindung pro Prüfzyklus – die
  Watchdog-Kernfunktion ist von MQTT vollständig unabhängig.
- LoxBerry-Konventionen: eigener `RotatingFileHandler` (10 MB, 3 Backups), Session-Logs in
  `DATADIR/logs/` (nicht `LOGDIR` – `log_maint.pl` löscht dort rekursiv), max. 7 Sessions,
  Enable-Marker-basierter Autostart/Watchdog-Cron, `preupgrade.sh`/`postinstall.sh`/`postroot.sh`
  Lifecycle identisch zum bewährten Unwetter4Lox-Muster.
