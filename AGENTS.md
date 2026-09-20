## 📌 Projekt-Status
- **Version:** 1.3 (2026-09-20: Zweiter Live-Test-Fund – Zeit-Synchronisation UND
  CPU-Temperatur zeigten dauerhaft "nicht ermittelbar". Root Cause Zeit-Sync: `timedatectl show
  --value` nutzt ein Flag das erst ab systemd 230 existiert – umgestellt auf dasselbe
  "Key=Value"-Parsing wie `get_service_state()`. CPU-Temperatur: probiert jetzt alle
  `thermal_zone*` statt nur `zone0`, UND loggt bei echtem Fehlschlag jetzt einmalig den genauen
  Grund statt still zu bleiben.)
  Basiert auf v1.2 (2026-09-20): Live-Test von v1.1 zeigte einen Regressions-Bug –
  `mqtt_publish_status()` gab bei JEDER Veröffentlichung einen TypeError, weil
  `publish.multiple()` anders als `publish.single()` kein globales `qos`-Argument kennt.
  Fix verifiziert durch lokale Installation von `paho-mqtt` und Signaturprüfung.
  Basiert auf v1.1 (2026-09-20): neue Funktion 5 "System-Diagnose" – Speicher/RAM/CPU-Temperatur/
  Internet/Zeit-Sync/weitere Kerndienste, jedes Thema einzeln im Monitoring UND Auto-Heal
  schaltbar. Dazu ein neues MQTT-Ampel-Topic (`health`/`health_detail`, fasst F1/F4/F5 zu einem
  Gesamtstatus zusammen) und ein sofortiges Event-Topic (`event`, nicht retained) bei jedem
  Neustart/Reboot statt erst beim nächsten Zyklus – auf Nutzerwunsch, um HitWatch4Lox als
  zentrale "Ist alles grün?"-Quelle für Loxone bei vielen Kundenstandorten nutzbar zu machen.
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
- **v1.0 – UI-Umbau + eigenes F4-Prüfintervall (auf Nutzerwunsch, drei Punkte in einer Anfrage):**
  1. **Daemon-Steuerung nach oben:** Die Karte "Daemon Status & Steuerung" (Restart/Stop, Log-Link)
     stand bisher ganz unten auf `app_status.php` – jetzt die erste Karte direkt nach dem Header.
  2. **"Letzte Prüfung" für Funktion 4:** Neue State-Felder `mqtt_watchdog_last_check`/
     `mqtt_watchdog_last_check_epoch` (geschrieben zu Beginn jedes F4-Durchlaufs), in der
     MQTT-Dienste-Status-Karte angezeigt (analog zur bestehenden Anzeige bei Funktion 1), inkl.
     Staleness-Markierung wenn seit > 3× Prüfintervall + 60s keine Prüfung mehr lief.
  3. **F4 entkoppelt von F1s Intervall:** Bisher trieb ein einziges `CHECK_INTERVAL` (F1, Standard
     300s) die komplette Hauptschleife – F4 hing daran und konnte MQTT-Ausfälle dadurch bis zu 5
     Minuten spät erkennen. Neue `LOOP_TICK`-Architektur: das Minimum aus allen aktiven
     Funktionsintervallen (mind. 15s) treibt `time.sleep()`, jede Funktion prüft selbst per
     `now - _last_X_run >= X_INTERVAL` ob sie an der Reihe ist. Neues `MQTT_WATCHDOG.CHECK_INTERVAL`
     (Default 60s, Settings-Slider 15–300s, Daemon-seitig geclamped 15–3600s). Fangfenster von
     Funktion 3 nutzt jetzt `LOOP_TICK` statt `CHECK_INTERVAL` als Basis.
  4. **Selbst gefundene Regression vorab behoben:** Beim Umbau wäre `state['status'] = 'OK'`
     (bisher am Anfang jedes Loop-Ticks) sonst bei jedem Tick zurückgesetzt worden, auch wenn
     Funktion 1 in diesem Tick gar nicht lief – ein erkannter Fehlerstatus wäre so sofort wieder
     verschwunden, bevor die nächste echte Prüfung stattfindet. Reset jetzt nur noch innerhalb
     des F1-gegateten Blocks.
- **v1.1 – Funktion 5 "System-Diagnose" + MQTT-Ampel/Event (auf Nutzerwunsch):** Stefan fragte
  nach weiteren Themen, die HitWatch4Lox als "Backup-Akteur" an vielen Kunden-LoxBerries prüfen/
  reparieren könnte, plus einer zentralen MQTT-Ampel für Loxone und einer Sofort-Benachrichtigung
  bei jedem Neustart. Umsetzung:
  1. **Funktion 5 (`[SYSTEM_DIAGNOSTICS]`):** sechs Themen – Speicherplatz (Root-Partition-%,
     `shutil.disk_usage`), RAM-Auslastung (`/proc/meminfo`, `MemAvailable`-basiert), CPU-Temperatur
     (`/sys/class/thermal/thermal_zone0/temp`, Fallback `vcgencmd`), Internet-Erreichbarkeit
     (TCP-Check, Standard 1.1.1.1:53, bewusst GETRENNT von Funktion 1s Netbird-Check), Zeit-
     Synchronisation (`timedatectl show --property=NTPSynchronized,SystemClockSynchronized` –
     nutzt systemds eigene Bewertung statt einer selbstgebauten NTP-Abfrage) und eine frei
     konfigurierbare Liste weiterer Kerndienste (wiederverwendet den bestehenden generischen
     `restart_service`-Mechanismus aus Funktion 4/Mosquitto 1:1).
  2. **Bewusst andere Toggle-Philosophie als Funktion 4:** Nutzerwunsch war hier explizit dass
     sowohl Monitoring ALS AUCH Auto-Heal pro Thema einzeln schaltbar sind (nicht wie bei F4, wo
     Monitoring immer läuft). Internet/Zeit/Dienste haben je ein eigenes `_MONITOR`- und
     `_AUTOHEAL`-Flag; Speicher/RAM/Temperatur haben bewusst KEIN Auto-Heal-Flag – ein Neustart
     löst diese drei Probleme nicht, würde nur unnötiges Risiko ohne Nutzen bedeuten.
  3. **Root-Helper um zwei feste (argumentlose) Unterbefehle erweitert:** `restart_networking`
     (versucht `dhcpcd`, dann `networking`) und `sync_time` (`timedatectl set-ntp true` +
     `systemd-timesyncd` restart, Fallback `ntpdate`) – beide OHNE Nutzerargument, daher einfache
     feste sudoers-Zeilen wie `check`/`restart`/`reboot`, keine neue Validierungs-Angriffsfläche.
  4. **Health-Ampel (`compute_health()`):** aggregiert F1 (Netbird verbunden?), F4 (Mosquitto/
     Gateway gesund?) und F5 (alle aktiven Sub-Checks) zu `state['health']`
     (`green`/`yellow`/`red`) + `state['health_detail']` (Klartext-Problemliste). Läuft JEDEN
     Loop-Tick (billig, liest nur bereits berechneten State) direkt vor `save_state()`/
     `mqtt_publish_status()` – die Ampel ist damit nie älter als die letzte Persistenz. Auf der
     Statusseite als Banner ganz oben sichtbar (`hw4l_health_badge()` in `common.php`).
  5. **MQTT-Event-Topic (`event`, NICHT retained):** `log_action()` ruft jetzt zusätzlich
     `mqtt_publish_event(entry)` auf – eine eigene Kurzverbindung SOFORT bei jeder signifikanten
     Aktion (Dienst-Neustart, Reboot), unabhängig vom nächsten regulären
     `mqtt_publish_status()`-Zyklus (der bis zu `LOOP_TICK` Sekunden später käme). JSON-Payload
     `{action, label, success, detail, time, epoch}`, Label-Mapping über das neue
     `ACTION_LABELS`-Dict in Python (inhaltlich deckungsgleich mit `hw4l_action_label()` in
     `common.php`, aber separat gepflegt – Python kann PHP-Funktionen nicht aufrufen).
- **v1.2 – Live-Test-Regression sofort behoben:** Erster Live-Test von v1.1 zeigte im Log
  `multiple() got an unexpected keyword argument 'qos'` bei JEDER Statusveröffentlichung
  (Ampel + alle Funktions-Topics, nicht nur die neuen Diagnose-Topics). Root Cause:
  `paho.mqtt.publish.multiple()` hat – anders als `publish.single()`, das für das neue
  Event-Topic genutzt wird – KEIN globales `qos`-Kwarg; QoS wird dort nur pro Nachricht per
  `qos`-Key im jeweiligen Dict gesetzt. Fix per lokaler `paho-mqtt`-Installation und
  `inspect.signature()` verifiziert, dann `qos=0` aus dem Aufruf entfernt. Zusätzlich:
  `check_time_sync()` loggt jetzt bei einer leeren/fehlgeschlagenen `timedatectl`-Ausgabe den
  genauen Grund (vorher stilles "nicht ermittelbar" ohne Diagnose-Möglichkeit).
- **v1.3 – Zweiter Live-Test-Fund, root-cause behoben:** Nach v1.2 lief die Ampel/MQTT sauber,
  aber Zeit-Synchronisation UND CPU-Temperatur zeigten weiterhin dauerhaft "nicht ermittelbar"
  (Screenshot vom Nutzer). Statt zu raten, Code-Vergleich mit dem bereits bewährten
  `get_service_state()`-Muster (nutzt `systemctl show --property=...` OHNE `--value`, bekanntlich
  funktionsfähig da Mosquitto-Erkennung in Funktion 4 beim Nutzer bereits lief):
  1. **Zeit-Sync root cause:** `check_time_sync()` nutzte `timedatectl show --value` – das
     `--value`-Flag gibt es erst ab systemd 230 (2016). Umgestellt auf dasselbe "Key=Value"-
     Zeilen-Parsing wie bei `get_service_state()`, kein `--value` mehr nötig.
  2. **CPU-Temperatur:** könnte echtes Hardware-/VM-Limit sein (kein Sensor durchgereicht) statt
     Bug – daher robuster gemacht (alle `thermal_zone*` statt nur `zone0` durchprobiert) UND vor
     allem diagnostizierbar: loggt bei echtem Fehlschlag jetzt einmalig den genauen Grund
     (welche Zonen/vcgencmd mit welchem Fehler scheiterten) statt still zu bleiben.
  3. **Log-Spam-Vermeidung:** "Sensor/Tool nicht vorhanden" ist ein dauerhafter Zustand (anders
     als ein zwischenzeitlich abgestürzter Dienst) – beide neuen Diagnose-Logs feuern daher nur
     EINMAL pro Daemon-Lauf (`_temp_unavailable_logged`/`_time_unavailable_logged`-Flags in
     `run()`, zurückgesetzt sobald die Ermittlung wieder erfolgreich ist).
- **Noch offen:**
  - [ ] Funktion 5 (alle sechs Sub-Checks + Auto-Heal-Aktionen), die Health-Ampel und das
    Event-Topic sind mangels Linux-Testumgebung nur isoliert (Funktionsebene, simulierter State)
    getestet worden, nicht vollständig auf einem echten LoxBerry. Live-Test bestätigte bereits
    Speicher/RAM/Internet-Anzeige und die MQTT-Ampel als funktionierend (nach v1.2-Fix). Ob
    Zeit-Sync/CPU-Temperatur nach v1.3 beim Nutzer tatsächlich einen Wert liefern (oder ob die
    Hardware schlicht keinen Sensor hat) steht noch aus.
  - [ ] **Mit v0.9 erstmals wirklich testbar:** v0.6 (falscher Erkennungsweg) → v0.7 (Fehler
    unsichtbar) → v0.8 (falsche Auth-Keys) → v0.9 (Timeout-Bug) verhinderten jeweils einen echten
    Funktionstest von "Verbindung zu Mosquitto". Exakten `loxberry/mqttgateway`-Topic-Pfad daher
    weiterhin nicht abschließend verifiziert – Stefan soll nach Installation von v0.9 prüfen ob
    "Verbindung zu Mosquitto" jetzt "verbunden" zeigt; falls nicht, zeigt das Log jetzt den
    genauen Grund (CONNACK-Fehlertext oder "kein Wert unter Präfix").
  - [ ] Mehrfachauswahl-UI F3, Frequenz-Zähler pro Wochentag, Fangfenster-Verhalten, Autorestart-
    Logik (Mosquitto + Gateway) und Aktions-Historie über mehrere Tage noch nicht auf echtem
    LoxBerry verifiziert.
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
- Python-Daemon, ~875 Zeilen. Deutlich einfacher als Unwetter4Lox – keine dauerhafte
  MQTT-Verbindung (keine RC=7-Reconnect-Problematik), da MQTT hier nur optionale,
  kurzlebige Statusveröffentlichung pro Zyklus ist (`paho.mqtt.publish.multiple`,
  connect→publish→disconnect).
- Hauptschleife `run()` (seit v1.0): treibt sich im Takt von `LOOP_TICK` (= Minimum aus allen
  aktiven Funktionsintervallen, mind. 15s) statt einem einzigen festen Intervall. Jede Funktion
  gated ihre eigene Ausführung selbst per `now - _last_X_run >= X_INTERVAL`:
  1. Funktion 1 (eigenes `CHECK_INTERVAL`, Standard 300s): `check_netbird()` → bei nicht
     verbunden: `restart_netbird()`, warten, erneut prüfen
  2. Bei weiterhin nicht verbunden + Funktion 2 aktiv: `trigger_reboot('netbird_watchdog', state)`
     (respektiert Cooldown via `cooldown_remaining_seconds()`)
  3. Funktion 3: läuft jeden Tick, wall-clock-gegated. Heutiger ISO-Wochentag in `F3_WEEKDAYS`? →
     innerhalb Fangfenster (2× `LOOP_TICK`, min. 10 min) nach `HH:MM`? → Zähler für diesen
     Wochentag hochzählen, bei `counter % EVERY_N == 0` auslösen (Cooldown-pflichtig wie F2)
  4. Funktion 4 (eigenes `CHECK_INTERVAL`, Standard 60s, seit v1.0 entkoppelt von F1): Status
     IMMER erheben – Mosquitto via `get_service_state()` (systemd) + `tcp_check()`; Gateway via
     `get_process_state()` (`pgrep`, KEIN systemd) + `check_gateway_mqtt_status()` (liest die vom
     Gateway selbst veröffentlichten MQTT-Topics). Schreibt `mqtt_watchdog_last_check(_epoch)` zu
     Beginn jedes Durchlaufs. Neustart nur wenn die jeweilige `..._AUTORESTART`-Config an ist
     (kein Cooldown, kein Reboot, nur Dienst-/Prozess-Neustart; Gateway-Neustart via
     `restart_process()`/`pkill`, unprivilegiert)
  5. Funktion 5 (eigenes `CHECK_INTERVAL`, Standard 120s, seit v1.1): sechs einzeln schaltbare
     Themen (Speicher, RAM, CPU-Temp, Internet, Zeit-Sync, weitere Kerndienste) – siehe
     Abschnitt "Funktion 5" unten. Anders als F1-F4 ist hier auch das Monitoring pro Thema
     einzeln abschaltbar, nicht nur das Auto-Heal.
  6. Health-Ampel (`compute_health()`, seit v1.1): läuft JEDEN Tick, fasst F1/F4/F5 zu
     `state['health']`/`state['health_detail']` zusammen – vor der Persistenz, siehe unten.
  7. `log_action()` bei jedem Neustart/Reboot → schreibt `action_log` UND veröffentlicht seit
     v1.1 sofort ein MQTT-Event (`mqtt_publish_event()`, siehe unten) → danach
     `save_state()` + `mqtt_publish_status()` (Heartbeat-Zyklus, unkritisch bei Fehlschlag)

### Funktion 5: System-Diagnose (`bin/hitwatch4lox_daemon.py`, seit v1.1)
- Sechs Themen, JEDES mit eigenem Monitoring-Flag (`F5_*_MONITOR`) und – wo ein Neustart
  überhaupt sinnvoll helfen kann – einem eigenen Auto-Heal-Flag (`F5_*_AUTOHEAL`):
  - **Speicher** (`check_disk_usage()`, `shutil.disk_usage('/')`) – kein Auto-Heal
  - **RAM** (`check_memory_usage()`, `/proc/meminfo` `MemAvailable`) – kein Auto-Heal
  - **CPU-Temperatur** (`check_cpu_temperature()`, `/sys/class/thermal/thermal_zone0/temp`,
    Fallback `vcgencmd measure_temp`) – kein Auto-Heal
  - **Internet** (`tcp_check()` gegen konfigurierbares Ziel, Standard 1.1.1.1:53 – bewusst
    getrennt von Funktion 1s Netbird-Check) – Auto-Heal via `restart_networking()`
    (Root-Helper `restart_networking`: `dhcpcd` oder `networking` neu starten)
  - **Zeit-Sync** (`check_time_sync()`, `timedatectl show --property=NTPSynchronized,
    SystemClockSynchronized` – nutzt systemds eigene Bewertung statt eigener NTP-Logik) –
    Auto-Heal via `sync_time_now()` (Root-Helper `sync_time`: `timedatectl set-ntp true` +
    `systemd-timesyncd` restart, Fallback `ntpdate`)
  - **Weitere Kerndienste** (`SERVICES_LIST`, kommagetrennt, z.B. `lighttpd,cron,ssh`) –
    wiederverwendet 1:1 `get_service_state()`/`restart_service()` aus Funktion 4
- Speicher/RAM/Temperatur haben bewusst KEIN Auto-Heal: ein Neustart behebt diese Probleme
  nicht, würde nur Risiko ohne Nutzen bedeuten. Kein Auto-Remount eines schreibgeschützten
  Root-Dateisystems (klassisches SD-Karten-Sterbesymptom) – das kaschiert oft nur eine
  sterbende Karte, bewusst NICHT automatisiert.
- State pro Dienst in `diag_services` (Dict, Key = Dienstname) – persistiert `restart_count`
  über Zyklen hinweg, analog zu Mosquitto/Gateway in Funktion 4.

### MQTT-Ampel + Sofort-Event (seit v1.1)
- `compute_health(state)`: aggregiert F1 (`netbird_connected`), F4 (`mosquitto_healthy`,
  `gateway_healthy`) und F5 (alle aktiven Sub-Checks) zu `('red'|'yellow'|'green', [Probleme])`.
  Rot bei mindestens einem kritischen Problem, gelb bei nur Warnungen, sonst grün. Läuft JEDEN
  Loop-Tick (billig) direkt vor `save_state()`/`mqtt_publish_status()`.
- `mqtt_publish_event(entry)`: eigene Kurzverbindung, published SOFORT bei jedem `log_action()`-
  Aufruf (nicht retained, JSON `{action, label, success, detail, time, epoch}`) – unabhängig vom
  nächsten regulären `mqtt_publish_status()`-Zyklus, der bis zu `LOOP_TICK` Sekunden später käme.
  `ACTION_LABELS`-Dict in Python für die Klartext-Labels (inhaltlich deckungsgleich mit, aber
  separat von `hw4l_action_label()` in `common.php` – Python kann PHP-Funktionen nicht aufrufen).
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
- `restart_networking` / `sync_time` (Funktion 5 Auto-Heal, seit v1.1): zwei feste, ARGUMENTLOSE
  Unterbefehle – keine neue Validierungs-Angriffsfläche wie bei `restart_service`, da kein
  Nutzer-Input entgegengenommen wird. Bewusst auf Dienst-Neustarts beschränkt (kein Interface-
  Down/Up, kein Dateisystem-Remount).

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
- `mqtt_watchdog_last_check_epoch`, `mqtt_watchdog_last_check` (seit v1.0, zu Beginn jedes
  F4-Durchlaufs geschrieben, unabhängig von F1s `last_check`)
- `health`, `health_detail` (seit v1.1, `compute_health()`-Ergebnis, jeden Tick aktualisiert)
- `diag_last_check_epoch`, `diag_last_check` (Funktion 5, seit v1.1)
- `diag_disk_percent`, `diag_disk_level` (`ok`/`warn`/`crit`/`unknown`)
- `diag_memory_percent`, `diag_memory_level`
- `diag_temp_available` (bool), `diag_temp_c`, `diag_temp_level`
- `diag_internet_ok`, `diag_internet_restart_count`, `diag_internet_last_restart(_epoch)`
- `diag_time_synced`, `diag_time_restart_count`, `diag_time_last_restart(_epoch)`
- `diag_services` (Dict, Key = Dienstname, Value = `{active_state, sub_state, healthy,
  restart_count, last_restart, last_restart_epoch}` – persistiert Zähler über Zyklen hinweg)
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
- **Fangfenster (Funktion 3):** `_catch_window_s = max(600, LOOP_TICK * 2)` (seit v1.0, vorher
  `CHECK_INTERVAL * 2`). Nur innerhalb
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
- **Seit v1.1:** `health`/`health_detail` (Ampel, immer veröffentlicht) und `event` (NICHT
  retained, sofort bei jeder Aktion statt erst beim nächsten Zyklus, JSON-Payload) – siehe
  Abschnitt "MQTT-Ampel + Sofort-Event" oben.
- Topics: `status`, `connected`, `management`, `signal`, `last_check_epoch`, `last_restart_epoch`,
  `restart_count_total`, `last_reboot_epoch`, `last_reboot_reason`, `cooldown_active`,
  `cooldown_remaining_min`, plus (wenn F4 aktiv) `mosquitto/*` und `gateway/*` (`healthy`,
  `active_state`, `sub_state`, `restart_count`, zusätzlich `gateway/broker_linked`), plus
  (wenn F5 aktiv, seit v1.1) `diag/disk_percent`, `diag/memory_percent`, `diag/temp_c`,
  `diag/internet_ok`, `diag/time_synced` (jeweils nur wenn das zugehörige Monitoring an ist)
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
| `app_status.php` | Health-Ampel-Banner (seit v1.1, ganz oben), Daemon-Controls, Netbird-Status, Watchdog-Aktionen, **Letzte Aktionen** (letzte 8 aus `action_log`), Cooldown-Anzeige, MQTT-Dienste-Status (Funktion 4, mit Gateway↔Broker-Link), **System-Diagnose** (Funktion 5, seit v1.1), Funktionen-Übersicht |
| `app_settings.php` | F1/F2/F3/F4/F5-Toggles + Parameter, F2-Toggle per JS gesperrt wenn F1 aus, F4-Autorestart-Toggles gesperrt wenn F4 aus, F5: jedes Thema mit eigenem Monitoring-Toggle + (wo sinnvoll) eigenem Auto-Heal-Toggle, F3 Wochentags-Chips (Mehrfachauswahl) + Frequenz-Select, MQTT-Karte |
| `app_log.php` | **Aktions-Historie** (neu, volle `action_log`-Tabelle bis 200 Einträge) oberhalb der bisherigen Log-Session-Liste |
| `app_help.php` | Die vier Funktionen, Aktions-Historie-Erklärung, Cooldown-/Fangfenster-Erklärung, Sicherheits-/sudoers-Hinweis (inkl. `restart_service`-Ausnahme), MQTT-Referenz, FAQ |

`.sl-chip` / `.sl-chip-grid` CSS (Wochentags-Mehrfachauswahl) wurde aus dem Unwetter4Lox-Vorbild
zurückgeholt, nachdem es beim ersten Trimmen der Komponentenbibliothek entfernt worden war.
`hw4l_action_label(string $action): array` in `common.php` liefert `[icon, label]` für einen
Aktionstyp – gemeinsam genutzt von `app_status.php` (Kurzliste) und `app_log.php` (Volltabelle).

---

## 📋 Versionshistorie

- **v1.3 (2026-09-20):** Zeit-Synchronisation UND CPU-Temperatur zeigten weiterhin "nicht
  ermittelbar" nach v1.2. Root Cause Zeit-Sync: `timedatectl show --value` (Flag erst ab systemd
  230) – umgestellt auf dasselbe "Key=Value"-Parsing wie `get_service_state()`. CPU-Temperatur:
  probiert jetzt alle `thermal_zone*`-Zonen statt nur `zone0`, loggt bei echtem Fehlschlag den
  genauen Grund (einmalig pro Daemon-Lauf, kein Dauerspam).
- **v1.2 (2026-09-20):** Live-Test-Regression aus v1.1 behoben: `mqtt_publish_status()` gab bei
  jeder Veröffentlichung einen `TypeError` (`multiple() got an unexpected keyword argument
  'qos'`) – `publish.multiple()` kennt kein globales `qos`-Argument, anders als `publish.single()`.
  Zeit-Synchronisations-Check loggt jetzt den genauen Grund bei fehlgeschlagener Ermittlung.
- **v1.1 (2026-09-20):** Neue Funktion 5 "System-Diagnose" (Speicher/RAM/CPU-Temperatur/Internet/
  Zeit-Sync/weitere Kerndienste, Monitoring UND Auto-Heal je einzeln schaltbar). Neues
  MQTT-Ampel-Topic (`health`/`health_detail`, fasst F1/F4/F5 zusammen) + Statusseiten-Banner.
  Neues Sofort-Event-Topic (`event`, nicht retained) bei jedem Neustart/Reboot statt erst beim
  nächsten Zyklus. Root-Helper um `restart_networking`/`sync_time` erweitert.
- **v1.0 (2026-09-18):** Daemon-Steuerung-Karte auf `app_status.php` nach oben verschoben;
  "Letzte Prüfung"-Anzeige für Funktion 4 (Zeitstempel + Staleness); Funktion 4 läuft jetzt mit
  eigenem, unabhängigem Prüfintervall (`MQTT_WATCHDOG.CHECK_INTERVAL`, Default 60s) statt am
  `CHECK_INTERVAL` von Funktion 1 zu hängen – neue `LOOP_TICK`-Hauptschleifen-Architektur.
- **v0.9 (2026-09-18):** `mqtt_read_retained()` (Gateway-Statuscheck) konnte den gesamten
  Watchdog-Loop einfrieren – `subscribe.simple()` kannte den `timeout`-Parameter in der
  installierten paho-Version nicht UND hätte ohne Timeout im Zweifel unbegrenzt blockiert.
  Komplett auf `paho.mqtt.client` direkt umgestellt: `connect_async()` + `loop_start()` +
  eigene Zeitlimit-Schleife (`while time.time() < deadline`), CONNACK-Fehler (falsche
  Zugangsdaten etc.) werden jetzt als Klartext-Fehlermeldung zurückgegeben statt zu leeren
  Ergebnissen zu führen.
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
