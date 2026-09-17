## 📌 Projekt-Status
- **Version:** 0.1.0 (2026-09-17: Erstversion des Plugins gebaut – noch nicht auf LoxBerry getestet)
- **Aktueller Fokus:** Grundgerüst von HitWatch4Lox (Netbird-Watchdog) vollständig gebaut, als
  Framework von Unwetter4Lox übernommen (gleiche LoxBerry-Plugin-Konventionen: PHP-Webfrontend
  im iframe-isolierten `sl-`-Komponenten-Stil, Python-Daemon mit `RotatingFileHandler`-Logging,
  Enable-Marker-basierter Autostart/Watchdog-Cron, `preupgrade.sh`/`postinstall.sh`/`postroot.sh`-
  Lifecycle). Enthält **nur** die drei in der Spezifikation beschriebenen Funktionen
  (Dienst-Watchdog, Reboot-Eskalation, wöchentlicher Reboot) – der n8n-Teil (zentrale
  Überwachung über die Netbird-API) ist bewusst **nicht** Teil dieses Repos.
- **Noch offen:**
  - [ ] GitHub-Repository `HitWatch4Lox` unter dem HitSmart-Account anlegen und pushen – in
    dieser Session war `gh` nicht authentifiziert (`gh auth login` erforderlich), daher lokal
    nur committet, noch nicht auf GitHub veröffentlicht.
  - [ ] Auf echtem LoxBerry installieren und testen: Netbird-Status-Parsing
    (`netbird status --detail`), Dienst-Neustart via `netbird_watchdog_helper.sh`, sudoers-Regeln,
    Reboot-Auslösung, Cooldown-Verhalten.
  - [ ] Icons sind programmatisch generiert (einfaches Signal/Punkt-Motiv, navy/amber) – ggf.
    durch ein gestaltetes Icon ersetzen.
  - [ ] Keine automatisierten Tests vorhanden (anders als Unwetter4Lox mit `tests/test_daemon.py`)
    – bei Bedarf `tests/` mit Vitest-Äquivalent für Python (`pytest`) ergänzen, v.a. für
    `check_netbird()`-Parsing und Cooldown-Logik.

---

## 🏗️ Architektur-Übersicht

### Daemon: `bin/hitwatch4lox_daemon.py`
- Python-Daemon, ~380 Zeilen. Deutlich einfacher als Unwetter4Lox – keine dauerhafte
  MQTT-Verbindung (keine RC=7-Reconnect-Problematik), da MQTT hier nur optionale,
  kurzlebige Statusveröffentlichung pro Zyklus ist (`paho.mqtt.publish.multiple`,
  connect→publish→disconnect).
- Hauptschleife `run()`: alle `CHECK_INTERVAL` Sekunden (Standard 300s)
  1. Funktion 1: `check_netbird()` → bei nicht verbunden: `restart_netbird()`, warten, erneut prüfen
  2. Bei weiterhin nicht verbunden + Funktion 2 aktiv: `trigger_reboot('netbird_watchdog', state)`
     (respektiert Cooldown via `cooldown_remaining_seconds()`)
  3. Funktion 3: Wochentag/Uhrzeit-Abgleich gegen `WEEKLY_REBOOT`-Config, ebenfalls Cooldown-pflichtig
  4. `save_state()` + `mqtt_publish_status()` (unkritisch bei Fehlschlag)
- Root-Aktionen laufen über `run_helper(action)` → `sudo netbird_watchdog_helper.sh {check|restart|reboot}`

### Root-Helper: `bin/netbird_watchdog_helper.sh`
- Kapselt ALLE privilegierten Aktionen hinter drei festen Unterbefehlen (kein Passthrough
  beliebiger Argumente). `postroot.sh` gibt in `/etc/sudoers.d/hitwatch4lox` ausschließlich
  diese drei exakten Aufrufe frei.
- `check`: `netbird status --detail` (Rohtext, wird von Python geparst: Zeilen `Management:` / `Signal:`)
- `restart`: `systemctl restart netbird` (Fallback `netbird service restart`)
- `reboot`: `(sleep 3 && /sbin/reboot) &` – asynchron, damit der aufrufende State-Save vorher abschließt

### state.json Struktur (DATADIR)
- `last_check_epoch`, `last_check`, `netbird_connected`, `netbird_management`, `netbird_signal`
- `last_restart_epoch`, `last_restart`, `restart_count_total`
- `last_auto_reboot_epoch`, `last_auto_reboot`, `last_auto_reboot_reason` (`netbird_watchdog` | `weekly_scheduled`)
- `last_weekly_reboot_date` (verhindert Mehrfachauslösung von Funktion 3 am selben Tag)
- `status` (Text, "OK" oder Fehlermeldung)

### Cooldown-/Anti-Loop-Logik (KRITISCH)
- Gemeinsamer Cooldown für Funktion 2 UND Funktion 3 (ein `last_auto_reboot_epoch` für beide
  Mechanismen) – `last_auto_reboot_reason` hält fest, welcher Mechanismus zuletzt ausgelöst hat.
- `cooldown_remaining_seconds()`: `COOLDOWN_HOURS * 3600 - (now - last_auto_reboot_epoch)`
- State wird **vor** dem eigentlichen Reboot-Aufruf persistiert (`trigger_reboot()`), da der
  Prozess durch den Reboot selbst beendet wird.

### Update-Lifecycle (identisch zu Unwetter4Lox – siehe dortiges CLAUDE.md für Details)
1. `preupgrade.sh`: Config-Backup nach `/tmp`, Daemon stoppen (kein `daemon stop` – Enable-Marker bleibt)
2. `postinstall.sh` (loxberry, VOR postroot!): paho-mqtt, default-config, chmod – KEIN Daemon-Start
3. `postroot.sh` (root): sudoers (Daemon + Helper), Config-Restore, cron, Daemon starten

### MQTT (optional, unkritisch)
- Präfix Standard: `HitWatch/netbird_watchdog/` (konfigurierbar über `[MQTT] TOPIC_PREFIX`)
- Topics: `status`, `connected`, `management`, `signal`, `last_check_epoch`, `last_restart_epoch`,
  `restart_count_total`, `last_reboot_epoch`, `last_reboot_reason`, `cooldown_active`,
  `cooldown_remaining_min`
- Vollständige Referenz: `webfrontend/htmlauth/app_help.php`

---

## 🖥️ Web-UI

Gleiches iframe-isoliertes Muster wie Unwetter4Lox (`index.php` → iframe → `app_*.php`,
`common.php` für Header/Footer/CSRF, `assets/style.css` + `assets/app.js` als generische
`sl-`-Komponentenbibliothek, nahezu unverändert übernommen). Kein Geocoding/Miniserver-Bezug
nötig (kein Standort erforderlich) – `ajax.php` daher deutlich schlanker als bei Unwetter4Lox
(nur Daemon-Steuerung + `check_update`).

| Datei | Zweck |
|---|---|
| `app_status.php` | Netbird-Status, Watchdog-Aktionen (letzter Neustart/Reboot + Grund), Cooldown-Anzeige, Funktionen-Übersicht, Daemon-Controls |
| `app_settings.php` | F1/F2/F3-Toggles + Parameter, F2-Toggle per JS gesperrt wenn F1 aus, MQTT-Karte |
| `app_log.php` | Log-Session-Liste (identisch zu Unwetter4Lox-Muster) |
| `app_help.php` | Die drei Funktionen, Cooldown-Erklärung, Sicherheits-/sudoers-Hinweis, MQTT-Referenz, FAQ |

---

## 📋 Versionshistorie

- **v0.1.0 (2026-09-17):** Erstversion – vollständiges Plugin-Grundgerüst nach Spezifikation
  gebaut (drei Funktionen, Cooldown-Schutz, Root-Helper-Sicherheitsmodell, optionale
  MQTT-Statusanzeige). Noch nicht auf echtem LoxBerry getestet, GitHub-Repo noch nicht angelegt.
