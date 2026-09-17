# CLAUDE.md – HitWatch4Lox

LoxBerry-Plugin: Hitwatch4Lox

---

## Sync-Instruktionen

1. **Beim Start:** `AGENTS.md` lesen – dort steht der aktuelle Projektzustand.
2. **Beim Abschluss / Wechsel zu Gemini:** `AGENTS.md` UND `gemini.md` aktualisieren.

---

## Architektur & Standards (v0.9.x)

### Mehrsprachigkeit (i18n)
- **PHP:** Nutzt `LBSystem::readlanguage("language.ini")`. Sprachdateien in `templates/lang/`.
- **Python:** Alle MQTT-Klartexte und Notifications über das `L`-Dictionary (inline in daemon.py).

### MQTT Topic Struktur
- Präfix: `HitWatch/` (konfigurierbar)


### LoxBerry Logging
- **Daemon:** Eigener `RotatingFileHandler` (max 10 MB, 3 Backups) – loxberry.log.Logger NIEMALS verwenden!
- **Session-Files:** in `DATADIR/logs/` (NICHT LOGDIR – log_maint.pl löscht rekursiv!)
- **Rotation:** `max_log_files=7` Session-Dateien (pro Daemon-Start), dazu FileHandler-Rotation auf 10 MB
- **Viewer:** `LBWeb::logfile_button_html(...)` in PHP

---

## LoxBerry Plugin Framework – Pflicht-Regeln

### Pfade (NIEMALS hardcoden)
- Immer `REPLACELBHOMEDIR` und `REPLACELBPPLUGINDIR` verwenden.

### Log-Format
Standard LoxBerry Tags (`<OK>`, `<ERR>`, `<LOGSTART>`, etc.) sind zwingend für den Viewer.

### Persistence
`postinstall.sh` darf die `unwetter4lox.cfg` niemals überschreiben, wenn sie bereits existiert.

---

## Kritische Implementierungs-Details (v0.9.x)


### MQTT-Robustheit (v0.9.9 – KRITISCH!)
- `mqtt_connect()` wird NUR EINMAL beim Daemon-Start aufgerufen
- `loop_start()` + `reconnect_delay_set(30, 300)` – paho übernimmt alle Reconnects automatisch
- KEIN `mqtt_connect()` im Main-Loop außer Hard-Reset nach 600s Disconnect (Cooldown 900s)
- **AUSNAHME (v0.9.70): Erst-Connect-Retry.** Wenn `mqtt_connect()` beim Start synchron scheitert (Broker beim Boot noch nicht da → `ConnectionRefusedError`), läuft `loop_start()` NIE → kein Auto-Reconnect. Solange `_mqtt_connect_time == 0.0` wird `mqtt_connect()` im Main-Loop aktiv wiederholt (Cooldown 60s via `_last_connect_retry`).
- **`publish()` gibt bei getrenntem MQTT `False` zurück (qos=0, keine Queue).** Aufrufer die Gate-State fortschreiben (`alarm/zusammenfassung`, `notification/*`) MÜSSEN den Rückgabewert prüfen und `_prev_*` nur bei `True` setzen (v0.9.70 – sonst hängt die retained Message für immer).
- **`_on_connect()` leert `_last_mqtt_error_text`** bei jedem erfolgreichen (Re-)Connect (sonst hängt `status/mqtt_fehler` nach dem ersten Blip).
- **RC=7-Serie** wird erst als beendet markiert wenn 120s stabil UND seit dem Disconnect wieder ein Publish erfolgreich war (`_last_successful_publish > _first_disco_time`).
- **RC=7 = `MQTT_ERR_CONN_LOST`** (paho-Code, NICHT "Not authorized"!) = anderer Client übernimmt Session
- **Client-ID: `_MQTT_CLIENT_ID = f"Unwetter4Lox-{hostname}"`** – lesbarer Name im Broker, stabil über Neustarts
  - RC=7-Schutz übernimmt fcntl.flock (nie zwei Instanzen gleichzeitig) – kein PID-Suffix mehr nötig
  - NIEMALS auf fixen String ohne Hostname zurückändern – Konflikte wenn mehrere LoxBerry im Netz!
- Self-Healing: Nach 10 Min Dauertrennung → `sys.exit(2)`, Watchdog startet neu
- **Hard-Reset-Cooldown:** `_last_hard_reset` (global) – `mqtt_connect()` höchstens alle 15 Min (Cooldown 900s)
- **Startup:** pgrep nach anderen Instanzen + SIGTERM – verhindert Duplikat-Instanz


### Update-Lifecycle (KRITISCH!)
LoxBerry führt Installationsskripte in dieser Reihenfolge aus:
1. **preupgrade.sh**: Config-Backup nach /tmp + Daemon stoppen
2. **[LoxBerry: Dateien löschen/installieren]**
3. **postinstall.sh** (loxberry, VOR postroot!): `pip3 install paho-mqtt` als Fallback (`python3-paho-mqtt` kommt via `apt/apt.txt`), default-config (Erstinstall), chmod, TAWES-cache löschen – **KEIN Daemon-Start** (kein sudo, kein LAT/LON da Config noch nicht restored!)
4. **postroot.sh** (root): sudoers, Config-Restore aus /tmp, Enable-Marker-Restore, chmod, cron, **Daemon starten wenn vorher aktiviert / Erstinstallation** (runuser, NIEMALS als Root!)

⚠️ NIEMALS Daemon-Start in postinstall.sh!
⚠️ NIEMALS Daemon-Fallback als Root in postroot.sh!

### Autostart & Watchdog (postroot.sh) – v0.9.70
- **Enable-Marker `data/plugins/unwetter4lox/daemon.enabled`**: `daemon start` legt an, `daemon stop` entfernt (expliziter User-Stop), `daemon restart` / `_stop_all` fassen ihn NICHT an. ALLE Cron-Jobs (`@reboot`, `03:00`, beide Watchdogs) prüfen ihn zuerst → ein per UI gestopptes Plugin wird von Reboot/Watchdog NICHT wieder gestartet.
- `postroot.sh` startet den Daemon nach Installation/Update wie bisher (sofern LAT/LON gesetzt) – `daemon start` legt dabei den Marker (neu) an. Ein Plugin-**Update** reaktiviert ein gestopptes Plugin also (bewusste Aktion); nur Reboot/Watchdog respektieren den Stop.
- `preupgrade.sh` verwendet NICHT `daemon stop` (das würde den Marker löschen) – direkter PID/pkill.
- `/etc/cron.d/unwetter4lox` (root-owned, Update-sicher) enthält:
  - `@reboot`: Autostart 120s nach Systemstart (nur wenn Enable-Marker da)
  - `0 3 * * *`: Täglicher `restart` um **03:00 Uhr** (NICHT `stop; start` – ein fehlschlagender Start darf den Daemon nicht dauerhaft ausschalten)
  - `1-59/5 * * * *`: **Liveness-Watchdog** – `! pgrep -f unwetter4lox_daemon.py` (NICHT mehr `[ -f PIDFILE ] && kill -0`!), 15s Wartezeit + pgrep-Doppelcheck, `rm daemon.pid` + `start`
  - `3-59/5 * * * *`: **Hänge-Watchdog** – Prozess lebt aber `state.json` > 20 Min alt (Deadlock) → `restart`
- **`sys.exit(2)` (RC=7-Self-Healing) löscht die PID-Datei NICHT** (sonst greift der PID-basierte Teil des Watchdogs nicht; stale PID → `kill -0` schlägt fehl, `pgrep` findet nichts → Neustart)
- `uninstall/uninstall` räumt `/etc/cron.d/unwetter4lox` + `/tmp`-Backups + laufende Prozesse auf


### common.php – Plugin-Version
- Liest `plugin.cfg` aus `$lbpplugindir . '/plugin.cfg'` (NICHT `$lbpconfigdir`!)
- Fallback: `dirname($lbpconfigdir) . '/plugin.cfg'`

### ZIP-Erstellung (Windows)
Immer `build_zip.ps1 -Version X.X.X` verwenden. NICHT `Compress-Archive` (erzeugt Backslashes → LoxBerry bricht ab).
**Namenskonvention: `hitwatch4lox-V.X.X.X.zip`** (z.B. `hitwatch4lox-V.0.9.48.zip`)

### Release-ZIP via GitHub Actions (v0.9.73 – KRITISCH!)
`.github/workflows/release.yml` baut bei jedem `v*`-Tag-Push eine eigene, in sich geschlossene Staging-/ZIP-Logik – **referenziert `create-plugin-zip.sh` NICHT mehr**. Diese Datei steht bewusst in `.gitignore` (lokale Entwickler-Hilfsdatei) und existiert im CI-Checkout nie; ein `chmod +x` darauf brach den gesamten Release-Job vor dem eigentlichen ZIP-Build ab (kein Release/ZIP wurde je erzeugt). NIEMALS wieder ein Skript referenzieren, das nicht im Repo committet ist.

### Commit Messages (ÖFFENTLICHES REPO!)
NIEMALS "Claude", "Gemini", "AGENTS", "AI" oder ähnliches in Commit-Messages erwähnen!
