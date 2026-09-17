# Changelog – HitWatch4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

## [0.1.0] – 2026-09-17

### Hinzugefügt – Erstversion
- LoxBerry-Plugin mit eigener Web-UI (Status / Einstellungen / Log / Hilfe), aufgebaut auf
  demselben iframe-isolierten UI-Framework wie Unwetter4Lox.
- **Funktion 1 – Netbird-Dienst-Watchdog:** periodische Prüfung von `netbird status --detail`
  (Management- und Signal-Verbindung), einmaliger Dienst-Neustart bei Verbindungsverlust,
  konfigurierbares Prüfintervall.
- **Funktion 2 – Automatischer Reboot-Eskalation:** optionaler, einmaliger Reboot wenn Netbird
  auch nach dem Dienst-Neustart nicht verbunden ist. Nur wirksam bei aktiver Funktion 1.
- **Funktion 3 – Geplanter wöchentlicher Reboot:** unabhängiger Wartungsneustart zu frei
  wählbarem Wochentag/Uhrzeit.
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
