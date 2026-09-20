# Changelog – HitWatch4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

## [1.4] – 2026-09-20

### Behoben – Zeit-Synchronisation weiterhin "nicht ermittelbar" (echter Root Cause gefunden)
- v1.3 lieferte den entscheidenden Log-Hinweis: `RC=0, keine Ausgabe`. Ursache: der Aufruf
  fragte zusätzlich zur echten Property `NTPSynchronized` eine nicht-existente
  `SystemClockSynchronized`-Property ab (Verwechslung mit dem gleichnamigen Textlabel aus
  `timedatectl status`, das aber tatsächlich `NTPSynchronized` abbildet) – im
  `org.freedesktop.timedate1`-D-Bus-Interface gibt es nur `NTPSynchronized`. Eine ungültige
  Property in der Liste ließ `timedatectl show` komplett leer zurückkehren. Fix: nur noch die
  echte Property abfragen, zusätzlich ein Fallback auf `timedatectl status` (Textausgabe,
  funktioniert auch mit eingeschränktem D-Bus-Zugriff) falls `show` dennoch nichts liefert.
- CPU-Temperatur bleibt auf dem getesteten Gerät "nicht ermittelbar" – das Log bestätigt
  explizit weder `thermal_zone*` noch `vcgencmd` vorhanden, was für eine virtualisierte
  LoxBerry-Instanz (kein durchgereichter Sensor) ein legitimer, softwareseitig nicht
  behebbarer Zustand ist, kein Bug.

### Geändert – Hilfe-Seite: vollständige MQTT-Topic-Referenz
- Die MQTT-Topics-Sektion dokumentiert jetzt für jedes Topic die exakten möglichen Werte
  (nicht nur den Typ), inkl. aller systemd-ActiveState/SubState-Werte für Mosquitto, aller
  `action`-Werte des neuen Event-Topics mit Klartext-Label und auslösender Funktion, und der
  drei Ampel-Zustände mit Bedeutung.

---

## [1.3] – 2026-09-20

### Behoben – Zeit-Synchronisation & CPU-Temperatur dauerhaft "nicht ermittelbar" (Live-Test)
- **Zeit-Synchronisation (root cause gefunden):** `check_time_sync()` rief `timedatectl show
  --property=... --value` auf – das `--value`-Flag existiert erst ab systemd 230 (2016). Auf
  älteren systemd-Versionen liefert der Aufruf keine verwertbare Ausgabe. Fix: Umstellung auf
  dasselbe bewährte "Key=Value"-Parsing wie `get_service_state()` (dort bereits nachweislich
  funktionsfähig, siehe Mosquitto-Erkennung in Funktion 4) statt auf `--value` zu bauen.
- **CPU-Temperatur (robuster + diagnostizierbar):** `check_cpu_temperature()` probiert jetzt
  ALLE vorhandenen `/sys/class/thermal/thermal_zone*/temp`-Zonen durch (vorher nur `zone0`, das
  auf manchen Systemen einen anderen Sensor als die CPU belegt). Bleibt die Ermittlung trotzdem
  erfolglos (z.B. weil die Hardware/VM keinen Sensor durchreicht), landet der genaue Grund jetzt
  einmalig im Log statt still zu bleiben.
- **Log-Spam vermieden:** "Sensor/Tool nicht vorhanden" ist ein dauerhafter Hardware-/
  Systemzustand (anders als ein zwischenzeitlich abgestürzter Dienst) – wird daher nur EINMAL
  pro Daemon-Lauf geloggt, nicht bei jedem Prüfzyklus neu.

---

## [1.2] – 2026-09-20

### Behoben – MQTT-Statusveröffentlichung schlug bei jedem Zyklus fehl (v1.1-Regression)
- Erster Live-Test von v1.1 zeigte im Log: `MQTT: Statusveröffentlichung fehlgeschlagen
  (unkritisch): multiple() got an unexpected keyword argument 'qos'`. Ursache: anders als
  `publish.single()` (für das neue Event-Topic) kennt `publish.multiple()` gar kein globales
  `qos`-Argument – QoS wird dort ausschließlich pro Nachricht über einen `qos`-Key im jeweiligen
  Dict gesetzt. Das fälschlich übergebene `qos=0` führte zu einem `TypeError` bei **jeder**
  einzelnen Statusveröffentlichung (Ampel, alle Funktions-Topics), nicht nur den neuen
  Diagnose-Topics. Fix: `qos=0` aus dem `multiple()`-Aufruf entfernt (Signatur lokal mit
  `paho-mqtt` verifiziert).
- Nebenbei behoben: "Zeit-Synchronisation nicht ermittelbar" loggte bisher keinen Grund. Ein
  fehlgeschlagener `timedatectl`-Aufruf (leere Ausgabe, Fehler, Binary fehlt) wird jetzt mit dem
  genauen Grund im Log vermerkt statt still zu bleiben – Speicher/RAM/Temperatur-Checks bleiben
  bewusst ohne Extra-Logging, da "Sensor nicht vorhanden" dort ein normaler, erwarteter Fall ist.

---

## [1.1] – 2026-09-20

### Hinzugefügt – Funktion 5: System-Diagnose (Auto-Heal)
- Neue Funktion überwacht typische Vor-Ort-Einsatz-Ursachen: Speicherplatz (Root-Partition),
  RAM-Auslastung, CPU-Temperatur, Internet-Erreichbarkeit (getrennt von der Netbird-Prüfung
  in Funktion 1), Zeit-Synchronisation (NTP) sowie eine frei konfigurierbare Liste weiterer
  LoxBerry-Kerndienste (z.B. `lighttpd`, `cron`, `ssh`).
- Anders als Funktion 4 ist hier **jedes Thema einzeln sowohl im Monitoring als auch im
  Auto-Heal** schaltbar (Nutzerwunsch) – manche Standorte sollen ggf. nur beobachtet werden.
  Auto-Heal beschränkt sich bewusst auf Dienst-/Netzwerk-Neustarts (Internet: Netzwerk-Dienst
  neu starten; Zeit: `systemd-timesyncd` neu starten; weitere Dienste: `systemctl restart`
  über denselben validierten Root-Helper wie Funktion 4). Speicherplatz/RAM/Temperatur haben
  bewusst KEIN Auto-Heal – ein Neustart löst diese Probleme nicht.
- Root-Helper um zwei neue, argumentlose Unterbefehle erweitert: `restart_networking` und
  `sync_time`.

### Hinzugefügt – MQTT-Ampel + Sofort-Event
- Neues Ampel-Topic `health` (green/yellow/red) + `health_detail` (Klartext-Problemliste) fasst
  Funktion 1/4/5 zu einem Gesamtstatus zusammen – für eine Ein-Blick-Übersicht über viele
  Standorte in Loxone, ohne jedes Einzel-Topic selbst auswerten zu müssen. Auf der Statusseite
  jetzt auch als Banner ganz oben sichtbar.
- Neues Event-Topic `event` (NICHT retained, JSON-Payload) wird ab sofort bei jedem
  Dienst-/Prozess-Neustart oder Reboot **sofort** veröffentlicht statt erst beim nächsten
  regulären Prüfzyklus – ermöglicht eine Loxone-Benachrichtigung in Echtzeit statt mit
  Verzögerung von bis zu mehreren Minuten.

---

## [1.0] – 2026-09-18

### Geändert – UI-Übersicht (Daemon-Steuerung an oberste Stelle)
- Die Karte "Daemon Status & Steuerung" (Restart/Stop-Buttons, Log-Link) steht jetzt ganz oben
  auf der Statusseite statt ganz unten – direkt sichtbar ohne Scrollen.

### Hinzugefügt – Letzte Prüfung für Funktion 4 (MQTT-Dienste-Watchdog)
- Die MQTT-Dienste-Status-Karte zeigt jetzt an, wann Mosquitto/Gateway zuletzt geprüft wurden
  (Zeitstempel + eigenes Prüfintervall), analog zur bestehenden Anzeige bei Funktion 1.

### Geändert – Funktion 4 läuft mit eigenem, unabhängigem Prüfintervall
- Bisher lief die komplette Hauptschleife im festen Takt von Funktion 1 (`CHECK_INTERVAL`,
  Standard 300s) – Funktion 4 (MQTT-Watchdog) hing daran und konnte MQTT-Ausfälle dadurch bis zu
  5 Minuten spät erkennen.
- Neue Architektur: Ein gemeinsamer `LOOP_TICK` (das Minimum aus allen aktiven Funktionsintervallen,
  mind. 15s) treibt die Hauptschleife, jede Funktion prüft intern selbst ob ihr eigenes Intervall
  bereits abgelaufen ist. Funktion 4 bekommt ein eigenes `CHECK_INTERVAL` (Standard 60s, in den
  Settings einstellbar 15–300s) und läuft damit unabhängig und deutlich häufiger als Funktion 1.
- Selbst gefundene Regression beim Umbau vorab behoben: `state['status']` darf nur noch
  zurückgesetzt werden wenn Funktion 1 tatsächlich lief – sonst wäre ein erkannter Fehlerstatus
  bei jedem Loop-Tick sofort wieder überschrieben worden, bevor die nächste echte Prüfung erfolgt.

---

## [0.9] – 2026-09-18

### Behoben – MQTT-Statuscheck konnte den Watchdog dauerhaft einfrieren (KRITISCH)
- Nach dem Zugangsdaten-Fix (v0.8) zeigte der Live-Test einen neuen Fehler:
  `simple() got an unexpected keyword argument 'timeout'` – die installierte paho-mqtt-Version
  kennt diesen Parameter bei `subscribe.simple()` nicht. Schwerwiegender als der reine
  Kompatibilitätsfehler: **ohne Timeout kann `subscribe.simple()` unbegrenzt blockieren**, falls
  ein Topic nie eintrifft (falscher Präfix, Gateway offline, …) – das hätte den kompletten
  Watchdog-Loop (inkl. Netbird-Check und Reboot-Logik!) dauerhaft einfrieren können.
- Fix: `mqtt_read_retained()` komplett auf `paho.mqtt.client` direkt umgestellt (statt
  `subscribe.simple()`), mit `connect_async()` + eigener Zeitlimit-Schleife – das Zeitlimit
  wird dadurch auch bei einem hängenden DNS-Lookup/TCP-Handshake sicher durchgesetzt, nicht nur
  beim Warten auf Nachrichten.
- Nebeneffekt behoben: Ein abgelehntes CONNACK (falsche Zugangsdaten, RC 1–5) wird jetzt korrekt
  als Fehlermeldung zurückgegeben statt als leeres, unerklärtes Ergebnis – vorher wäre das bei
  der neuen Client-Implementierung sonst eine stille Regression gewesen.

---

## [0.8] – 2026-09-18

### Behoben – MQTT-Zugangsdaten wurden nie tatsächlich verwendet (KRITISCH)
- Die Ursache für die "not authorised"-Fehler war ein einfacher Tippfehler bei den
  Dictionary-Keys: `_resolve_mqtt_broker()` las `cred.get('username')`/`cred.get('password')`
  aus der LoxBerry-SDK-Antwort – die tatsächlichen Keys heißen aber `brokeruser`/`brokerpass`
  (bestätigt durch Vergleich mit dem nachweislich funktionierenden Unwetter4Lox-Code). Die
  Zugangsdaten wurden dadurch nie gefunden, die Verbindung lief immer anonym – gegen einen
  Broker der anonyme Verbindungen ablehnt, führte das zu stillen Fehlschlägen.
- Fix: Zugangsdaten-Auflösung 1:1 nach dem bewährten Unwetter4Lox-Muster nachgebaut – LoxBerry-SDK
  zuerst (korrekte Keys), bei Fehlschlag direktes Lesen aus `config/system/general.json` /
  `config/system/mqttgateway.json`, erst zuletzt Fallback auf die manuell in `[MQTT]` eingetragenen
  Werte. Wird jetzt einmalig beim Daemon-Start aufgelöst (nicht mehr bei jedem MQTT-Zugriff neu)
  und das Ergebnis (Broker, ob ein User gesetzt wurde) ins Log geschrieben – sofort sichtbar ohne
  weiteres Debugging. Betrifft sowohl die eigene optionale Statusveröffentlichung als auch den
  neuen Gateway-Verbindungscheck aus v0.6/v0.7.

---

## [0.7] – 2026-09-18

### Behoben – MQTT-Verbindungsfehler beim Gateway-Statuscheck war unsichtbar
- Live-Test zeigte "Verbindung zu Mosquitto: nicht prüfbar" ohne erkennbaren Grund. Ursache:
  Mosquitto lehnt anonyme Verbindungen ab (bestätigt durch `mosquitto_sub ... Connection
  Refused: not authorised` direkt auf dem LoxBerry), unser eigener Lesezugriff auf die
  Gateway-MQTT-Topics scheiterte am selben Auth-Fehler – wurde aber nur auf DEBUG-Level
  geloggt und damit faktisch nie sichtbar.
- Fix: `mqtt_read_retained()` gibt jetzt den echten Fehlertext zurück (z.B. den Auth-Fehler)
  statt ihn stumm zu verschlucken; wird als Grund im Status-Tab ("Verbindung zu Mosquitto")
  und im Log (jetzt WARNING statt DEBUG) angezeigt. Hilfe-Tab um einen Troubleshooting-Hinweis
  ergänzt. Betrifft nur die Verbindungsanzeige – die Prozesserkennung (`pgrep`) ist davon
  unabhängig.

---

## [0.6] – 2026-09-18

### Behoben – MQTT-Gateway-Erkennung war grundlegend falsch
- Live-Test auf echtem LoxBerry zeigte: Mosquitto korrekt erkannt, das MQTT-Gateway aber
  dauerhaft "inactive/dead", obwohl es lief. Ursache: `systemctl list-units --type=service --all`
  zeigt ausschließlich `mosquitto.service` – das LoxBerry MQTT-Gateway (`mqttgateway.pl`) ist
  gar kein systemd-Dienst, sondern ein klassischer LoxBerry-Kern-Perl-Daemon.
  `systemctl show <nicht-existente-Unit>` liefert dabei klaglos `ActiveState=inactive`/
  `SubState=dead` zurück statt eines Fehlers, weshalb der Bug nicht als Fehler auffiel, sondern
  einfach dauerhaft falsche Daten zeigte.
- Fix: Gateway-Erkennung läuft jetzt über `pgrep -f` (konfigurierbares Prozess-Suchmuster,
  Standard `mqttgateway.pl`) statt `systemctl`.

### Geändert – Gateway↔Mosquitto-Verbindungsprüfung auf autoritative Quelle umgestellt
- Der bisherige `ss -tnp`-basierte TCP-Verbindungscheck (v0.5) wurde ersetzt: das MQTT-Gateway
  veröffentlicht seinen eigenen Verbindungsstatus direkt als MQTT-Topic (`<Präfix>/status`, z.B.
  "Connected", plus Herzschlag `<Präfix>/keepaliveepoch`) – sichtbar in Loxone Config als MQTT
  Virtual Input `loxberry_mqttgateway_status`. HitWatch4Lox liest diese Werte jetzt direkt statt
  über eine externe Heuristik zu raten.
- Der Root-Helper-Unterbefehl `link_check` (und die zugehörige sudoers-Ausnahme) wurde wieder
  entfernt – nicht mehr gebraucht, da die MQTT-Statusabfrage kein Root benötigt. Reduziert die
  Anzahl sudoers-Ausnahmen mit Nutzerargument von zwei auf eine (`restart_service`).
- Automatischer Gateway-Neustart läuft jetzt unprivilegiert per `pkill` (kein sudo, da Gateway
  unter demselben User wie der Daemon läuft) und wird ausgelöst wenn der Prozess entweder nicht
  läuft ODER läuft aber laut eigener Selbstauskunft nicht mit Mosquitto verbunden ist – analog zu
  Funktion 1. HitWatch4Lox startet den Prozess bewusst nicht selbst neu, sondern verlässt sich auf
  LoxBerrys eigenes Watchdog-System für seine Kern-Daemons.

---

## [0.5] – 2026-09-18

### Geändert – Funktion 4: Überwachung nicht mehr einzeln abschaltbar
- `[MQTT_WATCHDOG] MOSQUITTO_ENABLED`/`GATEWAY_ENABLED` (steuerten bisher Anzeige **und**
  Neustart gemeinsam) ersetzt durch `MOSQUITTO_AUTORESTART`/`GATEWAY_AUTORESTART` (steuern nur
  noch den automatischen Neustart). Status von Mosquitto **und** MQTT-Gateway wird jetzt immer
  angezeigt, sobald `[MQTT_WATCHDOG] ENABLED=1` – unabhängig davon, ob automatisch neu gestartet
  werden soll.

### Hinzugefügt – Gateway↔Mosquitto-Verbindungscheck
- Neue Best-Effort-Prüfung, ob das MQTT-Gateway tatsächlich mit Mosquitto verbunden ist (nicht
  nur ob der Gateway-Dienst läuft): ermittelt die Haupt-PID des Gateway-Dienstes und prüft über
  den Root-Helper (`ss -tnp`), ob eine established TCP-Verbindung zum Broker-Port besteht.
  Rein informativ im Status-Tab ("Verbindung zu Mosquitto") – löst selbst keine Aktion aus.
- Root-Helper um `link_check <pid> <port>` erweitert, PID/Port streng numerisch validiert
  (zweite und letzte sudoers-Ausnahme mit Nutzerargument im Plugin, neben `restart_service`).

### Hinzugefügt – Aktions-Historie ("Letzte Aktionen")
- Jeder signifikante Vorfall (Netbird-Dienst-Neustart, Mosquitto-Neustart, Gateway-Neustart,
  ausgelöster Reboot) wird jetzt mit Zeitstempel, Erfolg/Fehlschlag und Detailtext in
  `state.json` protokolliert (max. 200 Einträge).
- Neue Karte "Letzte Aktionen" im Status-Tab zeigt die letzten 8 Ereignisse auf einen Blick.
- Der Log-Tab zeigt zusätzlich zu den bisherigen rohen Log-Sessions jetzt eine vollständige,
  durchsuchbare Aktions-Historie-Tabelle (bis zu 200 Einträge).

---

## [0.4] – 2026-09-18

### Hinzugefügt – Funktion 4: MQTT-Dienste-Watchdog
- Neue, unabhängig ein-/ausschaltbare Funktion überwacht Mosquitto-Broker und/oder LoxBerry
  MQTT-Gateway und startet den jeweiligen Dienst bei Bedarf einmalig neu (gleiches
  "kein Loop, ein Versuch pro Zyklus"-Prinzip wie Funktion 1).
- **Mosquitto:** Prüft sowohl den systemd-Dienststatus (`systemctl show`, ActiveState/SubState)
  als auch eine echte TCP-Verbindung zum konfigurierten Broker-Host/Port – ein hängender,
  aber laut systemd noch "aktiver" Prozess wird so trotzdem als ungesund erkannt.
- **MQTT-Gateway:** Prüft den systemd-Dienststatus. Standardmäßig deaktiviert, da der genaue
  Dienstname je nach LoxBerry-Version variieren kann; in den Einstellungen frei konfigurierbar.
- Status-Anzeige zeigt den vollen systemd-Zustand (ActiveState/SubState, z.B. "active/running"
  oder "activating/start" während ein Dienst noch verbindet), nicht nur ein Ampel-Symbol.
- Neue MQTT-Topics `mosquitto/*` und `gateway/*` (healthy, active_state, sub_state, restart_count).
- Root-Helper um `restart_service <name>` erweitert – die einzige sudoers-Regel mit einem vom
  Nutzer konfigurierbaren Argument im gesamten Plugin. Name wird sowohl vom Python-Daemon als
  auch vom Helper-Skript selbst gegen ein striktes Identifier-Muster validiert
  (`^[A-Za-z0-9_.@-]{1,64}$`) bevor er an `systemctl restart` übergeben wird.

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
