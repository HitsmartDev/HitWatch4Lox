## 📌 Projekt-Status
- **Version:** 0.8 (2026-09-18: Root Cause für die MQTT-Auth-Fehler gefunden und behoben – simpler
  Key-Tippfehler in `_resolve_mqtt_broker()`, `username`/`password` statt der tatsächlichen
  LoxBerry-SDK-Keys `brokeruser`/`brokerpass`. Zugangsdaten-Auflösung 1:1 nach dem bewährten
  Unwetter4Lox-Muster nachgebaut, siehe unten. Stefan hatte den entscheidenden Hinweis: "er sollte
  sich mit den in LoxBerry hinterlegten Credentials einloggen, so wie Unwetter4Lox das macht".)
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
  Mosquitto zusätzlich mit echtem TCP-Connect-Test (kein reiner Prozess-Check). Status-Checks
  selbst brauchen kein Root (`systemctl show`/`is-active` sind für alle User lesbar) – nur
  `restart_service` läuft über sudo.
- **v0.5 – Funktion 4 auf erneuten Nutzerwunsch überarbeitet (drei Punkte in einer Anfrage):**
  1. **Überwachung nicht mehr einzeln abschaltbar:** `MOSQUITTO_ENABLED`/`GATEWAY_ENABLED`
     (steuerten bisher SOWOHL Anzeige ALS AUCH Neustart) ersetzt durch `MOSQUITTO_AUTORESTART`/
     `GATEWAY_AUTORESTART` (steuern NUR noch den automatischen Neustart). Status beider Dienste
     wird jetzt immer erhoben und angezeigt sobald `MQTT_WATCHDOG.ENABLED=1` – Nutzer wollte
     Sichtbarkeit nicht abschalten können müssen, nur die automatische Aktion.
  2. **Gateway↔Mosquitto-Verbindungscheck neu:** `check_gateway_broker_link()` – ermittelt die
     MainPID des Gateway-Dienstes (`systemctl show --property=MainPID`, unprivilegiert), prüft
     dann über den Root-Helper (`link_check <pid> <port>`, `ss -tnp state established`) ob eine
     TCP-Verbindung zu Mosquitto besteht. Best-Effort/informativ – KEINE Aktion daran gekoppelt.
     Dies ist die zweite (und letzte) sudoers-Ausnahme mit Nutzerargument im Plugin, PID/Port
     sind aber rein numerisch und werden streng validiert (`_valid_number()` im Helper).
  3. **Aktions-Historie neu:** `log_action(state, action, success, detail)` schreibt jeden
     signifikanten Vorfall (Dienst-Neustart, ausgelöster Reboot) in `state['action_log']`
     (Liste, gedeckelt auf 200 Einträge). Status-Tab zeigt die letzten 8 in einer neuen "Letzte
     Aktionen"-Karte, Log-Tab zeigt die volle Historie in einer Tabelle. Icon+Label-Mapping über
     `hw4l_action_label()` in `common.php` (von `app_status.php` UND `app_log.php` genutzt).
- **v0.6 – Live-Diagnose auf Stefans LoxBerry (KRITISCHER Fund):** Nach Installation von v0.5 zeigte
  der Status-Tab Mosquitto korrekt ("active/running", TCP erreichbar), aber das MQTT-Gateway
  dauerhaft "inactive/dead" – Grund: `systemctl list-units --type=service --all | grep -i mqtt`
  zeigt AUSSCHLIESSLICH `mosquitto.service`. `pgrep -fa mqtt` bestätigte, dass der Gateway-Prozess
  (`/usr/bin/perl /opt/loxberry/sbin/mqttgateway.pl`) sehr wohl läuft – es ist einfach KEIN
  systemd-Dienst, sondern ein LoxBerry-Kern-Daemon. `systemctl show` auf eine nicht-existente
  Unit liefert klaglos `ActiveState=inactive`/`SubState=dead` zurück statt eines Fehlers – der
  bisherige Code lief also nie auf einen Fehler, zeigte aber durchgehend falsche Daten.
  **Fix (grundlegender Umbau der Gateway-Erkennung):**
  1. **Prozess-Erkennung:** `get_process_state()` (`pgrep -f <GATEWAY_PROCESS_PATTERN>`, Default
     `mqttgateway.pl`) ersetzt `get_service_state()`/`systemctl show` für das Gateway. Kein Root
     nötig (pgrep für alle User lesbar).
  2. **Verbindungsstatus – Nutzerhinweis führte zu einer BESSEREN Lösung als der ursprüngliche
     `ss -tnp`-Ansatz:** Stefan entdeckte in der Loxone-Miniserver-Oberfläche (MQTT Virtual
     Inputs), dass das Gateway seinen eigenen Verbindungsstatus DIREKT als MQTT-Topic
     veröffentlicht (`loxberry_mqttgateway_status = "Connected"`, dazu ein
     `loxberry_mqttgateway_keepaliveepoch`-Herzschlag). `check_gateway_mqtt_status()` liest jetzt
     `<GATEWAY_MQTT_PREFIX>/status` + `/keepaliveepoch` (Default-Präfix `loxberry/mqttgateway` –
     **noch nicht mit `mosquitto_sub -h localhost -t 'loxberry/mqttgateway/#' -v -C 8` verifiziert,
     nur aus der Loxone-Anzeige abgeleitet!**) statt einer TCP-Heuristik zu vertrauen. Das ist die
     autoritative Selbstauskunft des Gateways – zuverlässiger als jede externe Prüfung.
  3. **`link_check`-Root-Helper-Subcommand komplett entfernt** (samt `_valid_number()`,
     sudoers-Zeile) – nicht mehr gebraucht, da die MQTT-Statusabfrage kein Root braucht.
     Reduziert die sudoers-Ausnahmen mit Nutzerargument von zwei auf eine (`restart_service`).
  4. **Gateway-Neustart per `pkill`, unprivilegiert** (kein Root, da Gateway als `loxberry`-User
     läuft wie der Daemon selbst) – `restart_process()`. HitWatch4Lox startet den Prozess bewusst
     NICHT selbst neu (Risiko: falsche Start-Parameter für einen produktiven LoxBerry-Kerndienst),
     sondern verlässt sich auf LoxBerrys eigenes Watchdog-System, das seine Kern-Daemons normalerweise
     selbst respawnt. Erfolg wird nach `RESTART_WAIT` am wieder laufenden Prozess geprüft.
  5. **Trigger-Bedingung erweitert:** Neustart-Versuch jetzt bei "läuft nicht" ODER "läuft, aber
     laut MQTT-Status nicht mit Mosquitto verbunden" (vorher nur bei totem Prozess) – konsistent
     mit Funktion 1s Philosophie (Prozess lebt ≠ tatsächlich verbunden).
- **Noch offen:**
  - [ ] **Exakten `loxberry/mqttgateway`-Topic-Pfad noch nicht verifiziert** – Stefan soll
    `mosquitto_sub -h localhost -t 'loxberry/mqttgateway/#' -v -C 8` ausführen und das Ergebnis
    teilen, damit `GATEWAY_MQTT_PREFIX` (Default aktuell nur eine plausible Annahme aus der
    Loxone-UI-Anzeige) bestätigt oder korrigiert werden kann.
  - [ ] Nach diesem Umbau erneut auf echtem LoxBerry testen (Mehrfachauswahl-UI F3, Frequenz-Zähler
    pro Wochentag, Fangfenster-Verhalten, Funktion 4 Gateway-Erkennung + Autorestart-Logik mit
    echtem Topic-Präfix, Aktions-Historie über mehrere Tage).
  - [ ] Icons sind programmatisch generiert (einfaches Signal/Punkt-Motiv, navy/amber) – ggf.
    durch ein gestaltetes Icon ersetzen.
  - [ ] Keine automatisierten Tests vorhanden (anders als Unwetter4Lox mit `tests/test_daemon.py`)
    – bei Bedarf `tests/` mit `pytest` ergänzen, v.a. für `check_netbird()`-Parsing,
    Cooldown-Logik, Frequenz-Zähler pro Wochentag, `get_service_state()`/`get_process_state()`-
    Parsing und `check_gateway_mqtt_status()`.
  - [ ] pkill-basierter Gateway-Neustart setzt voraus, dass LoxBerrys eigenes Watchdog-System den
    Prozess tatsächlich respawnt – diese Annahme ist plausibel (Standard-LoxBerry-Muster für
    Kern-Daemons) aber NICHT verifiziert. Falls sich das beim echten Test als falsch herausstellt,
    bliebe das Gateway nach einem Autorestart-Versuch dauerhaft down – ggf. muss HitWatch4Lox den
    Prozess dann doch selbst neu starten (Start-Kommando müsste dafür sicher ermittelt werden).

---

## 🏗️ Architektur-Übersicht

### Daemon: `bin/hitwatch4lox_daemon.py`
- Python-Daemon, ~820 Zeilen. Deutlich einfacher als Unwetter4Lox – keine dauerhafte
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
  4. Funktion 4: Status IMMER erheben – Mosquitto via `get_service_state()` (systemd) +
     `tcp_check()`; Gateway via `get_process_state()` (`pgrep`, KEIN systemd) +
     `check_gateway_mqtt_status()` (liest die vom Gateway selbst veröffentlichten MQTT-Topics).
     Neustart nur wenn die jeweilige `..._AUTORESTART`-Config an ist (kein Cooldown, kein Reboot,
     nur Dienst-/Prozess-Neustart; Gateway-Neustart via `restart_process()`/`pkill`, unprivilegiert)
  5. `log_action()` bei jedem Neustart/Reboot → `save_state()` + `mqtt_publish_status()` (unkritisch bei Fehlschlag)
- Root-Aktionen laufen über `run_helper(action, args=None)` → `sudo netbird_watchdog_helper.sh {check|restart|reboot|restart_service <name>}`
  – NUR für Netbird + Mosquitto. Das MQTT-Gateway braucht kein Root (pgrep/pkill/MQTT-Read
  funktionieren unprivilegiert, da Gateway und Daemon beide als `loxberry`-User laufen).

### Root-Helper: `bin/netbird_watchdog_helper.sh`
- Kapselt privilegierte Aktionen hinter festen Unterbefehlen. `postroot.sh` gibt in
  `/etc/sudoers.d/hitwatch4lox` ausschließlich die exakten Aufrufe frei.
- `check`: `netbird status --detail` (Rohtext, wird von Python geparst: Zeilen `Management:` / `Signal:`)
- `restart`: `systemctl restart netbird` (Fallback `netbird service restart`)
- `reboot`: `systemctl reboot` (Fallback `/sbin/reboot`) – **NIEMALS** wieder als Hintergrundjob
  (`... &`) umbauen, siehe Bugfix oben (Session-Cleanup killt verwaiste Hintergrundjobs).
- `restart_service <name>` (Funktion 4, Mosquitto, seit v0.4): **einzige** sudoers-Zeile mit
  Argument (`restart_service *`). Validiert `<name>` gegen `^[A-Za-z0-9_.@-]{1,64}$`
  (`_valid_service_name()` in Bash) BEVOR `systemctl restart "$SVC"` läuft – zusätzlich validiert
  der Python-Daemon denselben Namen schon vor dem Aufruf. Kein Shell-Passthrough (Array-Form in
  `subprocess.run`).
- `link_check` (v0.5) wieder entfernt in v0.6 – ersetzt durch die MQTT-Status-Topics des Gateways
  selbst, kein Root mehr nötig für den Verbindungscheck.

### state.json Struktur (DATADIR)
- `last_check_epoch`, `last_check`, `netbird_connected`, `netbird_management`, `netbird_signal`
- `last_restart_epoch`, `last_restart`, `restart_count_total`
- `last_auto_reboot_epoch`, `last_auto_reboot`, `last_auto_reboot_reason` (`netbird_watchdog` | `scheduled_reboot`)
- `last_scheduled_reboot_date` (verhindert Mehrfachauslösung von Funktion 3 am selben Tag)
- `reboot_weekday_counters` (Dict, Key = ISO-Wochentag als String "1".."7", Value = Zähler seit
  Aktivierung – Basis für die Frequenz-Auswertung `counter % EVERY_N == 0`)
- `mosquitto_active_state`, `mosquitto_sub_state`, `mosquitto_tcp_ok`, `mosquitto_healthy`,
  `mosquitto_last_restart_epoch`, `mosquitto_last_restart`, `mosquitto_restart_count` (Funktion 4)
- `gateway_running` (bool, aus `pgrep`), `gateway_active_state`/`gateway_sub_state` (synthetisch
  "active"/"inactive" bzw. "running"/"dead" – KEIN echter systemd-Wert, nur zur UI-Konsistenz mit
  Mosquitto), `gateway_healthy`, `gateway_broker_checked`, `gateway_broker_linked`,
  `gateway_broker_detail`, `gateway_last_restart_epoch`, `gateway_last_restart`,
  `gateway_restart_count` (Funktion 4)
- `action_log` (Liste, max. 200 Einträge, neueste am Ende – `{epoch, time, action, success,
  detail}`; `action` ∈ `netbird_restart`/`mosquitto_restart`/`gateway_restart`/`netbird_watchdog`/
  `scheduled_reboot`; PHP zeigt sie umgekehrt/neueste zuerst via `array_reverse()`)
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
  `cooldown_remaining_min`, plus (wenn F4 aktiv) `mosquitto/*` und `gateway/*` (`healthy`,
  `active_state`, `sub_state`, `restart_count`, zusätzlich `gateway/broker_linked`)
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
| `app_status.php` | Netbird-Status, Watchdog-Aktionen, **Letzte Aktionen** (neu, letzte 8 aus `action_log`), Cooldown-Anzeige, MQTT-Dienste-Status (Funktion 4, mit Gateway↔Broker-Link), Funktionen-Übersicht, Daemon-Controls |
| `app_settings.php` | F1/F2/F3/F4-Toggles + Parameter, F2-Toggle per JS gesperrt wenn F1 aus, F4-Autorestart-Toggles (nicht mehr "Überwachung") gesperrt wenn F4 aus, F3 Wochentags-Chips (Mehrfachauswahl) + Frequenz-Select, MQTT-Karte |
| `app_log.php` | **Aktions-Historie** (neu, volle `action_log`-Tabelle bis 200 Einträge) oberhalb der bisherigen Log-Session-Liste |
| `app_help.php` | Die vier Funktionen, Aktions-Historie-Erklärung, Cooldown-/Fangfenster-Erklärung, Sicherheits-/sudoers-Hinweis (inkl. `restart_service`-Ausnahme), MQTT-Referenz, FAQ |

`.sl-chip` / `.sl-chip-grid` CSS (Wochentags-Mehrfachauswahl) wurde aus dem Unwetter4Lox-Vorbild
zurückgeholt, nachdem es beim ersten Trimmen der Komponentenbibliothek entfernt worden war.
`hw4l_action_label(string $action): array` in `common.php` liefert `[icon, label]` für einen
Aktionstyp – gemeinsam genutzt von `app_status.php` (Kurzliste) und `app_log.php` (Volltabelle).

---

## 📋 Versionshistorie

- **v0.8 (2026-09-18):** Root-Cause-Fix: `_resolve_mqtt_broker()` las die LoxBerry-SDK-Antwort
  unter den falschen Keys (`username`/`password` statt `brokeruser`/`brokerpass`) – Zugangsdaten
  wurden nie gefunden, Verbindung lief immer anonym. Komplett nach dem bewährten
  Unwetter4Lox-Muster ersetzt: SDK zuerst (korrekte Keys), dann `config/system/general.json` /
  `mqttgateway.json` direkt, zuletzt manuelle `[MQTT]`-Werte. Einmalig beim Start aufgelöst
  (`RESOLVED_MQTT_*`-Konstanten), Ergebnis ins Log geschrieben.
- **v0.7 (2026-09-18):** Mosquitto lehnt anonyme MQTT-Verbindungen ab (`not authorised`,
  bestätigt per `mosquitto_sub` auf dem LoxBerry) – unser Gateway-Statuscheck scheiterte am
  selben Fehler, war aber nur auf DEBUG geloggt. `mqtt_read_retained()` gibt jetzt den echten
  Fehlertext zurück (WARNING statt DEBUG, sichtbar als Detail im Status-Tab). Löst das
  Grundproblem NICHT (gültige Zugangsdaten fehlen weiterhin) – macht es nur diagnostizierbar.
- **v0.6 (2026-09-18):** Live-Diagnose ergab: MQTT-Gateway (`mqttgateway.pl`) ist kein
  systemd-Dienst (nur `mosquitto.service` existiert), sondern ein LoxBerry-Kern-Daemon –
  `systemctl show` lieferte für die nie existierende "mqttgateway"-Unit klaglos
  `inactive`/`dead` zurück statt eines Fehlers, UI zeigte daher dauerhaft falsche Daten.
  Fix: Prozess-Erkennung via `pgrep -f` (`GATEWAY_PROCESS_PATTERN`, Default `mqttgateway.pl`),
  Verbindungsstatus über die vom Gateway selbst veröffentlichten MQTT-Topics
  (`GATEWAY_MQTT_PREFIX/status` + `/keepaliveepoch`, Default `loxberry/mqttgateway` – **noch
  nicht verifiziert**) statt TCP-Heuristik. `link_check`-Root-Helper-Subcommand entfernt (nicht
  mehr gebraucht), Gateway-Neustart jetzt unprivilegiert per `pkill` (kein sudo).
- **v0.5 (2026-09-18):** Funktion 4 überarbeitet – `MOSQUITTO_ENABLED`/`GATEWAY_ENABLED` (steuerten
  Anzeige+Neustart zusammen) ersetzt durch `MOSQUITTO_AUTORESTART`/`GATEWAY_AUTORESTART` (nur noch
  Neustart; Status wird immer angezeigt sobald F4 an ist). Neuer Gateway↔Mosquitto-Verbindungscheck
  (`link_check` via `ss -tnp`, informativ). Neue Aktions-Historie (`action_log` in `state.json`,
  max. 200 Einträge) – "Letzte Aktionen"-Karte im Status-Tab, volle Tabelle im Log-Tab.
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
