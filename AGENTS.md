## 📌 Projekt-Status
- **Version:** 0.4 (2026-09-18: vierte Funktion – MQTT-Dienste-Watchdog – auf Nutzerwunsch ergänzt)
- **Aktueller Fokus:** Grundgerüst von HitWatch4Lox (ursprünglich reiner Netbird-Watchdog, jetzt
  auch MQTT-Dienste) vollständig gebaut, als Framework von Unwetter4Lox übernommen (gleiche
  LoxBerry-Plugin-Konventionen: PHP-Webfrontend im iframe-isolierten `sl-`-Komponenten-Stil,
  Python-Daemon mit `RotatingFileHandler`-Logging, Enable-Marker-basierter Autostart/Watchdog-Cron,
  `preupgrade.sh`/`postinstall.sh`/`postroot.sh`-Lifecycle). Der n8n-Teil (zentrale Überwachung
  über die Netbird-API) ist bewusst **nicht** Teil dieses Repos.
- **Während des ersten Live-Tests gefunden und behoben:**
  1. **Reboot-Auslösung lautlos wirkungslos (KRITISCH):** `netbird_watchdog_helper.sh` löste
     Reboots bisher als Hintergrundjob (`(sleep 3 && /sbin/reboot) &`) aus. Auf einem
     systemd-System kann `pam_systemd` beim Beenden der `sudo`-Session verwaiste Hintergrundjobs
     mitbeenden, bevor der Sleep durchgelaufen ist – Reboot fand nie statt, kein Fehler im Log.
     Fix: `systemctl reboot` (asynchron, meldet nur die Anfrage an systemd/PID 1 und kehrt sofort
     zurück – der eigentliche Neustart läuft danach unabhängig vom aufrufenden Prozessbaum).
  2. **Retroaktives Auslösen bei jeder Neuinstallation:** Die "heute schon erledigt"-Sperre für
     den automatischen Reboot lebt in `state.json` (`data/plugins/hitwatch4lox/`). Eine
     Deinstallation räumt dieses Verzeichnis weg (LoxBerry-Standardverhalten) – die Sperre war
     dann weg. Da die alte Prüfung nur `"aktuelle Zeit >= HH:MM an Tag X"` ohne Obergrenze war,
     galt der Reboot dann für den **Rest des Tages** als fällig → jede Neuinstallation nach der
     geplanten Uhrzeit löste erneut einen Reboot aus. Fix: Fangfenster (2× `CHECK_INTERVAL`,
     mind. 10 Minuten) – ein Neustart des Daemons Stunden nach dem geplanten Zeitpunkt löst
     nichts mehr aus, das Fenster gilt dann als verpasst (kein Nachholen).
- **Auf Anfrage umgebaut – Funktion 3 (`[WEEKLY_REBOOT]` → `[SCHEDULED_REBOOT]`):** War
  ursprünglich EIN Wochentag + eine Uhrzeit. Jetzt: **mehrere Wochentage** (Mehrfachauswahl,
  `WEEKDAYS=1,4` etc.) + eine gemeinsame Uhrzeit + eine **Frequenz** (`EVERY_N`, "jedes Mal" oder
  "nur alle 2x/3x/…"), die **pro Wochentag unabhängig gezählt** wird (eigener Zähler je
  Wochentag in `state.json['reboot_weekday_counters']`, Key = ISO-Wochentag als String).
  UI-Bezeichnung geändert von "Geplanter wöchentlicher Reboot" auf schlicht "Automatischer
  Reboot". `last_auto_reboot_reason` heißt jetzt `scheduled_reboot` (vorher `weekly_scheduled`).
- **v0.4 – Funktion 4 (MQTT-Dienste-Watchdog) neu, auf Nutzerwunsch:** Überwacht Mosquitto-Broker
  und/oder LoxBerry MQTT-Gateway unabhängig voneinander, Config-Section `[MQTT_WATCHDOG]`. Nutzt
  `systemctl show <service> --property=ActiveState,SubState` statt nur `is-active`, damit Status-Tab
  den vollen Zustand zeigen kann (Nutzerwunsch: "soll auch den Status anzeigen, Joining usw.") –
  z.B. "activating/start" während ein Dienst noch hochfährt/verbindet, nicht nur ein Boolean.
  Mosquitto zusätzlich mit echtem TCP-Connect-Test (kein reiner Prozess-Check). Dienstnamen sind
  frei konfigurierbar (Text-Feld), da sie je nach LoxBerry-Setup variieren – das ist die **einzige**
  Stelle im ganzen Plugin, an der ein sudoers-Eintrag ein vom Nutzer kommendes Argument akzeptiert
  (`restart_service *`); Name wird in Python UND im Helper-Skript gegen `^[A-Za-z0-9_.@-]{1,64}$`
  validiert, siehe Sicherheits-Abschnitt unten. Status-Checks selbst brauchen kein Root
  (`systemctl show`/`is-active` sind für alle User lesbar) – nur `restart_service` läuft über sudo.
- **Noch offen:**
  - [ ] Nach diesem Umbau erneut auf echtem LoxBerry testen (Mehrfachauswahl-UI F3, Frequenz-Zähler
    pro Wochentag, Fangfenster-Verhalten, Funktion 4 mit echtem Mosquitto/Gateway-Ausfall).
  - [ ] Icons sind programmatisch generiert (einfaches Signal/Punkt-Motiv, navy/amber) – ggf.
    durch ein gestaltetes Icon ersetzen.
  - [ ] Keine automatisierten Tests vorhanden (anders als Unwetter4Lox mit `tests/test_daemon.py`)
    – bei Bedarf `tests/` mit `pytest` ergänzen, v.a. für `check_netbird()`-Parsing,
    Cooldown-Logik, Frequenz-Zähler pro Wochentag und `get_service_state()`-Parsing.
  - [ ] Der offizielle Dienstname des LoxBerry MQTT-Gateways in aktuellen LoxBerry-Versionen ist
    nicht verifiziert (Default-Vermutung `mqttgateway`) – Stefan muss das auf seinem System per
    `systemctl list-units --type=service | grep -i mqtt` prüfen und ggf. in den Einstellungen anpassen.

---

## 🏗️ Architektur-Übersicht

### Daemon: `bin/hitwatch4lox_daemon.py`
- Python-Daemon, ~620 Zeilen. Deutlich einfacher als Unwetter4Lox – keine dauerhafte
  MQTT-Verbindung (keine RC=7-Reconnect-Problematik), da MQTT hier nur optionale,
  kurzlebige Statusveröffentlichung pro Zyklus ist (`paho.mqtt.publish.multiple`,
  connect→publish→disconnect).
- Hauptschleife `run()`: alle `CHECK_INTERVAL` Sekunden (Standard 300s)
  1. Funktion 1: `check_netbird()` → bei nicht verbunden: `restart_netbird()`, warten, erneut prüfen
  2. Bei weiterhin nicht verbunden + Funktion 2 aktiv: `trigger_reboot('netbird_watchdog', state)`
     (respektiert Cooldown via `cooldown_remaining_seconds()`)
  3. Funktion 3: heutiger ISO-Wochentag in `F3_WEEKDAYS`? → innerhalb Fangfenster (2×
     `CHECK_INTERVAL`, min. 10 min) nach `HH:MM`? → Zähler für diesen Wochentag hochzählen,
     bei `counter % EVERY_N == 0` auslösen (Cooldown-pflichtig wie F2)
  4. Funktion 4: `get_service_state()` für Mosquitto (+ `tcp_check()`) und/oder Gateway,
     bei nicht gesund → `restart_service()` (kein Cooldown, kein Reboot – nur Dienst-Neustart)
  5. `save_state()` + `mqtt_publish_status()` (unkritisch bei Fehlschlag)
- Root-Aktionen laufen über `run_helper(action, arg=None)` → `sudo netbird_watchdog_helper.sh {check|restart|reboot|restart_service <name>}`

### Root-Helper: `bin/netbird_watchdog_helper.sh`
- Kapselt privilegierte Aktionen hinter festen Unterbefehlen. `postroot.sh` gibt in
  `/etc/sudoers.d/hitwatch4lox` ausschließlich die exakten Aufrufe frei.
- `check`: `netbird status --detail` (Rohtext, wird von Python geparst: Zeilen `Management:` / `Signal:`)
- `restart`: `systemctl restart netbird` (Fallback `netbird service restart`)
- `reboot`: `systemctl reboot` (Fallback `/sbin/reboot`) – **NIEMALS** wieder als Hintergrundjob
  (`... &`) umbauen, siehe Bugfix oben (Session-Cleanup killt verwaiste Hintergrundjobs).
- `restart_service <name>` (Funktion 4, seit v0.4): **einzige** sudoers-Zeile mit Argument
  (`restart_service *`). Validiert `<name>` gegen `^[A-Za-z0-9_.@-]{1,64}$` (`_valid_service_name()`
  in Bash) BEVOR `systemctl restart "$SVC"` läuft – zusätzlich validiert der Python-Daemon
  denselben Namen schon vor dem Aufruf. Kein Shell-Passthrough (Array-Form in `subprocess.run`).

### state.json Struktur (DATADIR)
- `last_check_epoch`, `last_check`, `netbird_connected`, `netbird_management`, `netbird_signal`
- `last_restart_epoch`, `last_restart`, `restart_count_total`
- `last_auto_reboot_epoch`, `last_auto_reboot`, `last_auto_reboot_reason` (`netbird_watchdog` | `scheduled_reboot`)
- `last_scheduled_reboot_date` (verhindert Mehrfachauslösung von Funktion 3 am selben Tag)
- `reboot_weekday_counters` (Dict, Key = ISO-Wochentag als String "1".."7", Value = Zähler seit
  Aktivierung – Basis für die Frequenz-Auswertung `counter % EVERY_N == 0`)
- `mosquitto_active_state`, `mosquitto_sub_state`, `mosquitto_tcp_ok`, `mosquitto_healthy`,
  `mosquitto_last_restart_epoch`, `mosquitto_last_restart`, `mosquitto_restart_count` (Funktion 4)
- `gateway_active_state`, `gateway_sub_state`, `gateway_healthy`, `gateway_last_restart_epoch`,
  `gateway_last_restart`, `gateway_restart_count` (Funktion 4)
- `status` (Text, "OK" oder Fehlermeldung)

### Cooldown-/Anti-Loop-Logik (KRITISCH)
- Gemeinsamer Cooldown für Funktion 2 UND Funktion 3 (ein `last_auto_reboot_epoch` für beide
  Mechanismen) – `last_auto_reboot_reason` hält fest, welcher Mechanismus zuletzt ausgelöst hat.
- `cooldown_remaining_seconds()`: `COOLDOWN_HOURS * 3600 - (now - last_auto_reboot_epoch)`
- State wird **vor** dem eigentlichen Reboot-Aufruf persistiert (`trigger_reboot()`), da der
  Prozess durch den Reboot selbst beendet wird.
- **Fangfenster (Funktion 3):** `_catch_window_s = max(600, CHECK_INTERVAL * 2)`. Nur innerhalb
  dieses Fensters nach `HH:MM` wird der Zähler erhöht und ausgewertet. Außerhalb (Daemon war
  nicht aktiv) wird der Tag als "erledigt" markiert OHNE den Zähler zu erhöhen – verhindert sowohl
  verspätetes Nachholen als auch eine Verschiebung der Frequenz-Zählung durch Ausfallzeiten.

### Update-Lifecycle (identisch zu Unwetter4Lox – siehe dortiges CLAUDE.md für Details)
1. `preupgrade.sh`: Config-Backup nach `/tmp`, Daemon stoppen (kein `daemon stop` – Enable-Marker bleibt)
2. `postinstall.sh` (loxberry, VOR postroot!): paho-mqtt, default-config, chmod – KEIN Daemon-Start
3. `postroot.sh` (root): sudoers (Daemon + Helper), Config-Restore, cron, Daemon starten

**Wichtig:** `data/plugins/hitwatch4lox/` (inkl. `state.json`) wird bei einem reinen
Update/Upgrade (ZIP erneut über bestehende Installation hochladen) NICHT angefasst. Bei einer
expliziten **Deinstallation** entfernt LoxBerry das komplette Plugin-Verzeichnis inkl. `data/` –
das ist beabsichtigtes LoxBerry-Verhalten, kein Bug, aber relevant beim Testen (siehe Bugfix 2 oben).

### MQTT (optional, unkritisch)
- Präfix Standard: `HitWatch/netbird_watchdog/` (konfigurierbar über `[MQTT] TOPIC_PREFIX`)
- Topics: `status`, `connected`, `management`, `signal`, `last_check_epoch`, `last_restart_epoch`,
  `restart_count_total`, `last_reboot_epoch`, `last_reboot_reason`, `cooldown_active`,
  `cooldown_remaining_min`, plus (nur wenn F4-Teilfunktion aktiv) `mosquitto/*` und `gateway/*`
  (`healthy`, `active_state`, `sub_state`, `restart_count`)
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
| `app_status.php` | Netbird-Status, Watchdog-Aktionen (letzter Neustart/Reboot + Grund), Cooldown-Anzeige, MQTT-Dienste-Status (Funktion 4, nur wenn aktiv), Funktionen-Übersicht, Daemon-Controls |
| `app_settings.php` | F1/F2/F3/F4-Toggles + Parameter, F2-Toggle per JS gesperrt wenn F1 aus, F4-Subtoggles gesperrt wenn F4 aus, F3 Wochentags-Chips (Mehrfachauswahl) + Frequenz-Select, MQTT-Karte |
| `app_log.php` | Log-Session-Liste (identisch zu Unwetter4Lox-Muster) |
| `app_help.php` | Die vier Funktionen, Cooldown-/Fangfenster-Erklärung, Sicherheits-/sudoers-Hinweis (inkl. `restart_service`-Ausnahme), MQTT-Referenz, FAQ |

`.sl-chip` / `.sl-chip-grid` CSS (Wochentags-Mehrfachauswahl) wurde aus dem Unwetter4Lox-Vorbild
zurückgeholt, nachdem es beim ersten Trimmen der Komponentenbibliothek entfernt worden war.

---

## 📋 Versionshistorie

- **v0.4 (2026-09-18):** Funktion 4 (MQTT-Dienste-Watchdog) neu – Mosquitto-Broker (Dienststatus +
  TCP-Check) und/oder LoxBerry MQTT-Gateway (Dienststatus) unabhängig voneinander überwachen und
  bei Bedarf neu starten. Root-Helper um validiertes `restart_service <name>` erweitert (einzige
  sudoers-Zeile mit Nutzer-Argument im ganzen Plugin). Status-Anzeige zeigt vollen systemd-Zustand
  (ActiveState/SubState) statt nur gesund/ungesund.
- **v0.3 (2026-09-17):** Funktion 3 auf Nutzerwunsch umgebaut – mehrere Wochentage + Frequenz
  (pro Wochentag unabhängig gezählt) statt einem einzelnen Wochentag. Config-Section
  `[WEEKLY_REBOOT]` → `[SCHEDULED_REBOOT]`, `last_auto_reboot_reason` Wert `weekly_scheduled` →
  `scheduled_reboot`. UI, Hilfe, README entsprechend angepasst.
- **v0.2 (2026-09-17):** Zwei Bugs aus dem ersten Live-Test behoben (siehe oben): Reboot-Auslösung
  über `systemctl reboot` statt Hintergrundjob; Fangfenster für Funktion 3 gegen retroaktives
  Auslösen bei späten Neustarts/Neuinstallationen.
- **v0.1 (2026-09-17):** Erstversion – vollständiges Plugin-Grundgerüst nach Spezifikation
  gebaut (drei Funktionen, Cooldown-Schutz, Root-Helper-Sicherheitsmodell, optionale
  MQTT-Statusanzeige).
