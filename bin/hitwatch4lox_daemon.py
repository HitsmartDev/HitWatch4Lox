"""HitWatch4Lox Daemon – Netbird- und MQTT-Dienste-Watchdog für LoxBerry"""
DAEMON_VERSION = '0.9'
import os, sys, re, json, time, logging, configparser, signal, subprocess, glob, socket, traceback
try: import fcntl  # Exklusiv-Lock – nur auf Linux/LoxBerry verfügbar
except ImportError: fcntl = None

# Prozessname setzen: erscheint in ps/top als "HitWatch4Lox" statt "python3"
try:
    with open('/proc/self/comm', 'w') as _f: _f.write('HitWatch4Lox')
except OSError:
    pass

from logging.handlers import RotatingFileHandler
from datetime import datetime, timedelta

LBHOMEDIR    = os.environ.get('LBHOMEDIR', '')
LBPPLUGINDIR = os.environ.get('LBPPLUGINDIR', 'hitwatch4lox')
CONFIGDIR    = os.path.join(LBHOMEDIR, 'config', 'plugins', LBPPLUGINDIR)
DATADIR      = os.path.join(LBHOMEDIR, 'data',   'plugins', LBPPLUGINDIR)
LOGDIR       = os.path.join(LBHOMEDIR, 'log',    'plugins', LBPPLUGINDIR)

os.makedirs(DATADIR, exist_ok=True)
os.makedirs(LOGDIR,  exist_ok=True)
# Session-Log-Dateien in DATADIR speichern – LoxBerry's log_maint.pl löscht rekursiv
# alle *.log Dateien unter /log/plugins/ bei knappem Plattenplatz. DATADIR ist davon
# nicht betroffen.
SESSIONDIR = os.path.join(DATADIR, 'logs')
os.makedirs(SESSIONDIR, exist_ok=True)
STATE_FILE = os.path.join(DATADIR, 'state.json')
PID_FILE   = os.path.join(LOGDIR,  'daemon.pid')
LOCK_FILE  = os.path.join(LOGDIR,  'daemon.lock')

HELPER_SCRIPT = os.path.join(LBHOMEDIR, 'bin', 'plugins', LBPPLUGINDIR, 'netbird_watchdog_helper.sh')

# ---------------------------------------------------------------------------
# LoxBerry Logging Initialisierung (identisches Muster wie andere HitSmart-Plugins)
# ---------------------------------------------------------------------------
def _lb_level_to_python(lb_level):
    if lb_level <= 0: return 60
    if lb_level <= 2: return logging.CRITICAL
    if lb_level <= 3: return logging.ERROR
    if lb_level <= 4: return logging.WARNING
    if lb_level < 7:  return logging.INFO
    return logging.DEBUG

def get_loxberry_loglevel():
    try:
        bridge = os.path.join(LBHOMEDIR, 'bin', 'plugins', LBPPLUGINDIR, 'loglevel.pl')
        if not os.path.exists(bridge): bridge = os.path.join(os.path.dirname(__file__), 'loglevel.pl')
        res = subprocess.check_output(['perl', bridge], encoding='utf-8').strip()
        return int(res) if res.isdigit() else 6
    except Exception: return 6

CURRENT_LOGLEVEL = get_loxberry_loglevel()

def _make_stable_log_link(target_path):
    """Erstellt daemon.log als Symlink auf die aktuelle Session-Log-Datei –
    ermöglicht LoxBerry logmanager.cgi eine stabile Pfad-Registrierung."""
    try:
        stable = os.path.join(LOGDIR, 'daemon.log')
        if os.path.exists(stable) or os.path.islink(stable):
            os.unlink(stable)
        os.symlink(target_path, stable)
    except Exception: pass

def _cleanup_old_sessions(max_sessions=7):
    pattern = os.path.join(SESSIONDIR, 'HitWatch4Lox_Daemon_????????_??????.log')
    existing = sorted(glob.glob(pattern), reverse=True)
    for old in existing[max_sessions - 1:]:
        try: os.remove(old)
        except OSError: pass

def _init_file_logger():
    class LoxBerryFormatter(logging.Formatter):
        TAG_MAP = {'DEBUG': '<DEBUG>', 'INFO': '<OK>', 'WARNING': '<WARNING>', 'ERROR': '<ERR>', 'CRITICAL': '<CRIT>'}
        def format(self, record):
            tag = self.TAG_MAP.get(record.levelname, '<OK>')
            ts  = datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S')
            return f'{ts} {tag} {record.getMessage()}'
    _ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    logfile = os.path.join(SESSIONDIR, f'HitWatch4Lox_Daemon_{_ts}.log')
    with open(logfile, 'w', encoding='utf-8') as _f:
        _f.write(f'{datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S")} <LOGSTART> HitWatch4Lox Daemon\n')
    try:
        with open(os.path.join(LOGDIR, 'daemon.log.current'), 'w') as _f: _f.write(logfile)
    except Exception: pass
    _make_stable_log_link(logfile)
    root = logging.getLogger()
    for h in root.handlers[:]: root.removeHandler(h)
    _fh = RotatingFileHandler(logfile, mode='a', encoding='utf-8', maxBytes=10*1024*1024, backupCount=3)
    _fh.setFormatter(LoxBerryFormatter())
    logging.basicConfig(level=_lb_level_to_python(CURRENT_LOGLEVEL), handlers=[_fh])
    return logging.getLogger('hitwatch4lox'), logfile

_cleanup_old_sessions()
log, LOGFILE = _init_file_logger()
log.info(f'Logging initialisiert (Level {CURRENT_LOGLEVEL}, Daemon-Version {DAEMON_VERSION})')

# ---------------------------------------------------------------------------
# Konfiguration laden
# ---------------------------------------------------------------------------
cfg = configparser.ConfigParser()
try:
    cfg.read(os.path.join(CONFIGDIR, 'hitwatch4lox.cfg'))
except Exception as e:
    log.critical(f'Fehler beim Laden der Konfiguration: {e}')

def get_cfg(section, key, default=''):
    try: return cfg.get(section, key, fallback=default)
    except Exception: return default

F1_ENABLED       = get_cfg('WATCHDOG', 'ENABLED', '1') == '1'
CHECK_INTERVAL   = max(60, min(3600, int(get_cfg('WATCHDOG', 'CHECK_INTERVAL', '300'))))
RESTART_WAIT     = max(5, min(120, int(get_cfg('WATCHDOG', 'RESTART_WAIT_SECONDS', '20'))))

F2_ENABLED       = F1_ENABLED and get_cfg('REBOOT_ESCALATION', 'ENABLED', '0') == '1'
COOLDOWN_HOURS   = max(1, min(72, int(get_cfg('REBOOT_ESCALATION', 'COOLDOWN_HOURS', '6'))))

F3_ENABLED       = get_cfg('SCHEDULED_REBOOT', 'ENABLED', '0') == '1'
_F3_WEEKDAYS_RAW = get_cfg('SCHEDULED_REBOOT', 'WEEKDAYS', '7')
F3_WEEKDAYS: set = set()
for _w in _F3_WEEKDAYS_RAW.split(','):
    _w = _w.strip()
    if _w.isdigit() and 1 <= int(_w) <= 7:
        F3_WEEKDAYS.add(int(_w))  # 1=Mo .. 7=So (ISO)
if not F3_WEEKDAYS:
    F3_WEEKDAYS = {7}
F3_TIME = get_cfg('SCHEDULED_REBOOT', 'TIME', '04:00')
try:
    _F3_H, _F3_M = [int(x) for x in F3_TIME.split(':', 1)]
except Exception:
    _F3_H, _F3_M = 4, 0
    log.warning(f'SCHEDULED_REBOOT.TIME ungültig ({F3_TIME!r}) – Fallback 04:00')
# Frequenz gilt unabhängig je ausgewähltem Wochentag: 1=jedes Mal, 2=nur jedes 2. Mal an
# diesem Wochentag, usw. – jeder Wochentag hat seinen eigenen Zähler (state.json).
F3_EVERY_N = max(1, min(52, int(get_cfg('SCHEDULED_REBOOT', 'EVERY_N', '1'))))

MQTT_ENABLED       = get_cfg('MQTT', 'ENABLED', '1') == '1'
MQTT_USE_LB        = get_cfg('MQTT', 'USE_LOXBERRY_MQTT', '1') == '1'
MQTT_BROKER        = get_cfg('MQTT', 'BROKER', '127.0.0.1')
MQTT_PORT          = int(get_cfg('MQTT', 'PORT', '1883') or '1883')
MQTT_USER          = get_cfg('MQTT', 'USER', '')
MQTT_PASS          = get_cfg('MQTT', 'PASS', '')
TOPIC_PREFIX        = get_cfg('MQTT', 'TOPIC_PREFIX', 'HitWatch/netbird_watchdog')

# Nur Buchstaben/Ziffern/._@- erlaubt – wird an "sudo helper.sh restart_service <name>"
# übergeben (die einzige Stelle mit einem vom Nutzer konfigurierbaren sudo-Argument).
_SERVICE_NAME_RE = re.compile(r'^[A-Za-z0-9_.@-]{1,64}$')

def _valid_service_name(name: str) -> bool:
    return bool(name) and bool(_SERVICE_NAME_RE.match(name))

# Funktion 4: Überwachung (Status-Anzeige) von Mosquitto + Gateway läuft IMMER sobald F4_ENABLED
# – nicht mehr einzeln abschaltbar (Nutzerwunsch: er will den Status immer sehen). Nur der
# automatische NEUSTART bei ungesundem Zustand ist pro Dienst separat schaltbar (AUTORESTART).
F4_ENABLED              = get_cfg('MQTT_WATCHDOG', 'ENABLED', '0') == '1'
F4_MOSQUITTO_AUTORESTART = F4_ENABLED and get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_AUTORESTART', '1') == '1'
F4_MOSQUITTO_SERVICE    = get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_SERVICE', 'mosquitto').strip()
F4_MOSQUITTO_HOST       = get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_HOST', '127.0.0.1').strip() or '127.0.0.1'
F4_MOSQUITTO_PORT       = int(get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_PORT', '1883') or '1883')
F4_GATEWAY_AUTORESTART  = F4_ENABLED and get_cfg('MQTT_WATCHDOG', 'GATEWAY_AUTORESTART', '0') == '1'
# Das LoxBerry MQTT-Gateway (mqttgateway.pl) ist KEIN systemd-Dienst, sondern ein klassischer
# Perl-Kern-Daemon (bestätigt per 'systemctl list-units' – dort taucht nur mosquitto.service auf,
# 'pgrep -fa mqtt' zeigt aber den laufenden Prozess). Erkennung daher über Prozess-Suchmuster
# (pgrep -f) statt Dienstname.
F4_GATEWAY_PATTERN      = get_cfg('MQTT_WATCHDOG', 'GATEWAY_PROCESS_PATTERN', 'mqttgateway.pl').strip()
# Das Gateway veröffentlicht seinen eigenen Verbindungsstatus + Herzschlag direkt als MQTT-Topics
# (z.B. loxberry/mqttgateway/status = "Connected", .../keepaliveepoch = Unix-TS) – zuverlässiger
# als jede externe Heuristik, da es die autoritative Selbstauskunft des Gateways ist.
F4_GATEWAY_MQTT_PREFIX  = get_cfg('MQTT_WATCHDOG', 'GATEWAY_MQTT_PREFIX', 'loxberry/mqttgateway').strip() or 'loxberry/mqttgateway'
if F4_ENABLED and not _valid_service_name(F4_MOSQUITTO_SERVICE):
    log.warning(f'MQTT_WATCHDOG.MOSQUITTO_SERVICE ungültig ({F4_MOSQUITTO_SERVICE!r}) – Fallback "mosquitto"')
    F4_MOSQUITTO_SERVICE = 'mosquitto'
if F4_ENABLED and (len(F4_GATEWAY_PATTERN) < 4 or len(F4_GATEWAY_PATTERN) > 128):
    log.warning(f'MQTT_WATCHDOG.GATEWAY_PROCESS_PATTERN ungültig/zu kurz ({F4_GATEWAY_PATTERN!r}) – Fallback "mqttgateway.pl"')
    F4_GATEWAY_PATTERN = 'mqttgateway.pl'

_f3_weekdays_str = ','.join(str(w) for w in sorted(F3_WEEKDAYS))
log.info(
    f'Konfiguration: F1(Watchdog)={"an" if F1_ENABLED else "aus"} (Intervall {CHECK_INTERVAL}s) | '
    f'F2(Reboot-Eskalation)={"an" if F2_ENABLED else "aus"} (Cooldown {COOLDOWN_HOURS}h) | '
    f'F3(Automatischer Reboot)={"an" if F3_ENABLED else "aus"} '
    f'(Tage {_f3_weekdays_str} um {F3_TIME}, alle {F3_EVERY_N}x) | '
    f'F4(MQTT-Watchdog)={"an" if F4_ENABLED else "aus"} '
    f'(Mosquitto={F4_MOSQUITTO_SERVICE}, Autorestart={"an" if F4_MOSQUITTO_AUTORESTART else "aus"} | '
    f'Gateway={F4_GATEWAY_PATTERN}, Autorestart={"an" if F4_GATEWAY_AUTORESTART else "aus"}) | '
    f'MQTT={"an" if MQTT_ENABLED else "aus"}'
)

try:
    import paho.mqtt.publish as mqtt_publish_mod
    import paho.mqtt.client as mqtt_client_mod
    MQTT_OK = True
except ImportError:
    MQTT_OK = False
    if MQTT_ENABLED:
        log.warning('paho-mqtt nicht installiert – MQTT-Statusveröffentlichung deaktiviert (Watchdog-Kernfunktion unberührt)')

try:
    import loxberry.mqtt as lb_mqtt
    LB_SDK_MQTT = True
except ImportError:
    LB_SDK_MQTT = False

# ---------------------------------------------------------------------------
# LoxBerry MQTT-Broker-Zugangsdaten auflösen (identisches Muster wie Unwetter4Lox, das
# nachweislich funktioniert): SDK zuerst, sonst direkt aus LoxBerrys System-Configs lesen,
# sonst die manuell in [MQTT] eingetragenen Werte. WICHTIG: die SDK-Funktion liefert die
# Zugangsdaten unter den Keys 'brokeruser'/'brokerpass' (NICHT 'username'/'password') –
# ein früherer Bug hier führte zu stiller anonymer Verbindung statt echter Zugangsdaten.
# Einmalig beim Start aufgelöst (nicht bei jedem MQTT-Zugriff neu), Ergebnis in
# RESOLVED_MQTT_* – von mqtt_publish_status() und mqtt_read_retained() genutzt.
# ---------------------------------------------------------------------------
def _read_mqtt_creds_from_files():
    for p in [os.path.join(LBHOMEDIR, 'config', 'system', 'general.json'),
              os.path.join(LBHOMEDIR, 'config', 'system', 'mqttgateway.json')]:
        if not os.path.exists(p):
            continue
        try:
            with open(p) as f:
                data = json.load(f)
            search = [data]
            for k in ['Mqtt', 'Main']:
                if k in data and isinstance(data[k], dict):
                    search.append(data[k])
            broker, port, user, passwd = None, None, None, None
            for sd in search:
                for bk in ['Brokerhost', 'brokerhost', 'brokeraddress']:
                    if bk in sd: broker = sd[bk]; break
                for pk in ['Brokerport', 'brokerport']:
                    if pk in sd: port = int(sd[pk]); break
            for sd in search:
                for uk in ['Brokeruser', 'brokeruser']:
                    if uk in sd:
                        user = sd[uk]
                        for pk in ['Brokerpass', 'brokerpass']:
                            if pk in sd: passwd = sd[pk]; break
                        return (broker or '127.0.0.1', port or 1883, user, passwd)
        except Exception:
            pass
    return None

def _resolve_mqtt_broker_once():
    if MQTT_USE_LB:
        if LB_SDK_MQTT:
            try:
                m = lb_mqtt.mqtt_connectiondetails()
                broker = m.get('brokeraddress', m.get('brokerhost', '127.0.0.1'))
                port   = int(m.get('brokerport', 1883))
                user   = m.get('brokeruser', '') or None
                passwd = m.get('brokerpass', '') or None
                log.info(f'MQTT: LoxBerry-Zugangsdaten via SDK aufgelöst (Broker {broker}:{port}, User={"gesetzt" if user else "keiner"})')
                return (broker, port, user, passwd)
            except Exception as e:
                log.warning(f'MQTT: LoxBerry-SDK-Auflösung fehlgeschlagen ({e}) – versuche System-Configs direkt')
        result = _read_mqtt_creds_from_files()
        if result:
            broker, port, user, passwd = result
            log.info(f'MQTT: LoxBerry-Zugangsdaten aus System-Config gelesen (Broker {broker}:{port}, User={"gesetzt" if user else "keiner"})')
            return (broker, port, user or None, passwd or None)
        log.warning('MQTT: LoxBerry-Zugangsdaten weder per SDK noch aus System-Configs auflösbar – Fallback auf manuelle [MQTT]-Einstellungen')
    return (MQTT_BROKER, MQTT_PORT, MQTT_USER or None, MQTT_PASS or None)

RESOLVED_MQTT_BROKER, RESOLVED_MQTT_PORT, RESOLVED_MQTT_USER, RESOLVED_MQTT_PASS = _resolve_mqtt_broker_once()

_lock_fd = None

# ---------------------------------------------------------------------------
# Signal-Handling
# ---------------------------------------------------------------------------
def _on_signal(signum, frame):
    log.info(f'Daemon gestoppt (Signal {signum})')
    global _lock_fd
    if os.path.exists(PID_FILE):
        try: os.remove(PID_FILE)
        except Exception: pass
    if _lock_fd is not None and fcntl is not None:
        try: fcntl.flock(_lock_fd, fcntl.LOCK_UN); _lock_fd.close()
        except Exception: pass
    sys.exit(0)

signal.signal(signal.SIGTERM, _on_signal)
signal.signal(signal.SIGINT,  _on_signal)

# ---------------------------------------------------------------------------
# State-Persistenz
# ---------------------------------------------------------------------------
def load_state():
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            log.warning(f'state.json konnte nicht gelesen werden: {e}')
    return {}

def save_state(state):
    try:
        tmp = STATE_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(state, f, ensure_ascii=False, indent=2)
        os.replace(tmp, STATE_FILE)
    except Exception as e:
        log.error(f'state.json konnte nicht geschrieben werden: {e}')

def fmt(epoch):
    if not epoch:
        return '–'
    return datetime.fromtimestamp(epoch).strftime('%d.%m.%Y %H:%M:%S')

# ---------------------------------------------------------------------------
# Root-Helper-Aufrufe (Netbird-Status, Dienst-Neustart, Reboot)
# ---------------------------------------------------------------------------
def run_helper(action, args=None, timeout=30):
    try:
        cmd = ['sudo', HELPER_SCRIPT, action] + list(args or [])
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout, r.stderr
    except subprocess.TimeoutExpired:
        return -1, '', f'Timeout nach {timeout}s'
    except Exception as e:
        return -1, '', str(e)

def _extract(text, label):
    for line in text.splitlines():
        line = line.strip()
        if line.lower().startswith(label.lower() + ':'):
            return line.split(':', 1)[1].strip()
    return '?'

def check_netbird():
    rc, out, err = run_helper('check', timeout=15)
    if rc != 0:
        msg = (err or out or f'RC={rc}').strip().splitlines()[0][:200] if (err or out) else f'RC={rc}'
        return {'connected': False, 'management': '?', 'signal': '?', 'error': msg}
    mgmt = _extract(out, 'Management')
    sig  = _extract(out, 'Signal')
    connected = ('connected' in mgmt.lower()) and ('connected' in sig.lower())
    return {'connected': connected, 'management': mgmt, 'signal': sig, 'error': ''}

def restart_netbird():
    rc, out, err = run_helper('restart', timeout=30)
    if rc != 0:
        log.error(f'Netbird-Dienst-Neustart fehlgeschlagen (RC={rc}): {(err or out).strip()[:300]}')
        return False
    return True

# ---------------------------------------------------------------------------
# Funktion 4: MQTT-Dienste-Watchdog (Mosquitto-Broker, LoxBerry MQTT-Gateway)
# Status-Abfragen brauchen KEIN Root (systemctl show ist für alle User lesbar) – nur
# restart_service läuft über den validierenden Root-Helper.
# ---------------------------------------------------------------------------
def get_service_state(service_name):
    """Liefert ActiveState + SubState eines systemd-Dienstes (z.B. 'active'/'running',
    'activating'/'start' während ein Dienst gerade verbindet/hochfährt, 'failed'/'failed')."""
    try:
        r = subprocess.run(
            ['systemctl', 'show', service_name, '--property=ActiveState,SubState', '--no-pager'],
            capture_output=True, text=True, timeout=10,
        )
        props = {}
        for line in r.stdout.splitlines():
            if '=' in line:
                k, v = line.split('=', 1)
                props[k] = v.strip()
        active_state = props.get('ActiveState') or 'unknown'
        sub_state    = props.get('SubState') or ''
        return {'active_state': active_state, 'sub_state': sub_state, 'healthy': active_state == 'active'}
    except Exception as e:
        return {'active_state': 'unknown', 'sub_state': str(e)[:120], 'healthy': False}

def tcp_check(host, port, timeout=4):
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except Exception:
        return False

def restart_service(service_name):
    if not _valid_service_name(service_name):
        log.error(f'Dienst-Neustart abgelehnt – ungültiger Name: {service_name!r}')
        return False
    rc, out, err = run_helper('restart_service', args=[service_name], timeout=30)
    if rc != 0:
        log.error(f'Neustart von {service_name!r} fehlgeschlagen (RC={rc}): {(err or out).strip()[:300]}')
        return False
    return True

def get_process_state(pattern):
    """Prüft per pgrep ob ein Prozess läuft, dessen Kommandozeile <pattern> enthält – für
    Kern-Daemons wie das LoxBerry MQTT-Gateway (mqttgateway.pl), die KEIN systemd-Dienst sind
    (bestätigt: 'systemctl list-units' zeigt nur mosquitto.service, aber 'pgrep -fa mqtt'
    findet den laufenden Perl-Prozess). Root nicht nötig, pgrep ist für alle User lesbar."""
    if len(pattern) < 4:  # Sicherheitsnetz: verhindert ein zu unspezifisches/leeres Muster
        return {'running': False, 'pid': None}
    try:
        r = subprocess.run(['pgrep', '-f', pattern], capture_output=True, text=True, timeout=10)
        pids = [int(p) for p in r.stdout.split() if p.isdigit()]
        return {'running': bool(pids), 'pid': pids[0] if pids else None}
    except Exception:
        return {'running': False, 'pid': None}

def restart_process(pattern):
    """Beendet einen Prozess per pkill (unprivilegiert – funktioniert nur wenn der Prozess
    demselben User gehört wie dieser Daemon, üblich für LoxBerry-Kern-Skripte). HitWatch4Lox
    startet den Prozess NICHT selbst neu: LoxBerrys eigenes Watchdog-System überwacht seine
    Kern-Daemons (u.a. das MQTT-Gateway) und startet sie normalerweise automatisch neu – ein
    von uns geratener Start-Befehl mit falschen Parametern/Environment wäre riskanter als das
    Beenden dem etablierten LoxBerry-Mechanismus zu überlassen. Der Erfolg wird über eine
    erneute Prüfung nach RESTART_WAIT festgestellt (siehe Aufrufer), nicht hier."""
    if len(pattern) < 4:
        log.error(f'Prozess-Neustart abgelehnt – Muster zu unspezifisch: {pattern!r}')
        return False
    try:
        subprocess.run(['pkill', '-f', pattern], timeout=10)
        return True
    except Exception as e:
        log.error(f'Prozess-Beenden fehlgeschlagen ({pattern}): {e}')
        return False

# CONNACK-Codes (MQTT 3.1.1) für eine verständliche Fehlermeldung statt eines rohen RC-Werts.
_MQTT_CONNACK_RC = {
    1: 'Protokoll-Version nicht unterstützt',
    2: 'Client-ID abgelehnt',
    3: 'Broker nicht verfügbar',
    4: 'Ungültige Zugangsdaten (Benutzername/Passwort)',
    5: 'Nicht autorisiert',
}

def mqtt_read_retained(topics, timeout=4):
    """Liest den aktuellen (retained) Wert mehrerer MQTT-Topics per Kurzverbindung
    (connect → subscribe → warten → disconnect). Gibt (werte_dict, fehlertext) zurück –
    fehlertext ist leer bei Erfolg, sonst der Grund (z.B. Auth-Fehler, Timeout), damit
    Aufrufer eine ehrliche Diagnose statt eines stillen leeren Ergebnisses bekommen.

    Nutzt bewusst paho.mqtt.client direkt statt subscribe.simple(): simple() hat je nach
    installierter paho-Version keinen 'timeout'-Parameter (TypeError) UND würde ohne Timeout
    im schlimmsten Fall UNBEGRENZT blockieren, falls ein Topic nie eintrifft – das würde den
    gesamten Watchdog-Loop einfrieren. Hier gilt ein hart durchgesetztes Zeitlimit."""
    if not MQTT_OK:
        return {}, 'paho-mqtt nicht installiert'
    broker, port = RESOLVED_MQTT_BROKER, RESOLVED_MQTT_PORT
    results = {}
    conn = {'rc': None}  # CONNACK-Code kommt asynchron im Netzwerk-Thread, kein Rückgabewert von connect()
    c = None
    try:
        try:
            c = mqtt_client_mod.Client(mqtt_client_mod.CallbackAPIVersion.VERSION1)
        except (AttributeError, TypeError):
            c = mqtt_client_mod.Client()
        if RESOLVED_MQTT_USER:
            c.username_pw_set(RESOLVED_MQTT_USER, RESOLVED_MQTT_PASS)

        def _on_connect(client, userdata, flags, rc):
            conn['rc'] = rc
            if rc == 0:
                for t in topics:
                    client.subscribe(t)

        def _on_message(client, userdata, msg):
            results[msg.topic] = msg.payload.decode('utf-8', 'replace')

        c.on_connect = _on_connect
        c.on_message = _on_message
        # connect_async() statt connect(): der eigentliche TCP-Connect läuft im Netzwerk-Thread
        # von loop_start() – so bleibt das untenstehende Zeitlimit auch bei einem hängenden
        # DNS-Lookup/TCP-Handshake wirksam (ein blockierendes connect() könnte das umgehen).
        c.connect_async(broker, port, keepalive=max(10, timeout + 5))
        c.loop_start()
        deadline = time.time() + timeout
        while time.time() < deadline and len(results) < len(topics):
            time.sleep(0.1)
        if results:
            return dict(results), ''
        # Kein einziger Wert angekommen – Grund ermitteln statt stumm leer zurückzugeben.
        if conn['rc'] is None:
            return {}, 'Keine Verbindung zum Broker zustande gekommen (Timeout)'
        if conn['rc'] != 0:
            reason = _MQTT_CONNACK_RC.get(conn['rc'], f'RC={conn["rc"]}')
            log.warning(f'MQTT: Verbindung zu {broker}:{port} abgelehnt – {reason}')
            return {}, reason
        return {}, ''
    except Exception as e:
        err = str(e)[:150]
        log.warning(f'MQTT: Retained-Werte von {broker}:{port} konnten nicht gelesen werden: {err}')
        return {}, err
    finally:
        if c is not None:
            try: c.loop_stop()
            except Exception: pass
            try: c.disconnect()
            except Exception: pass

def check_gateway_mqtt_status(mqtt_prefix, max_age_s=900):
    """Liest den vom LoxBerry MQTT-Gateway selbst veröffentlichten Verbindungsstatus
    (<prefix>/status, z.B. 'Connected') sowie einen Herzschlag-Zeitstempel
    (<prefix>/keepaliveepoch) – das Gateway ist damit seine eigene, autoritative Quelle für
    "bin ich mit Mosquitto verbunden", zuverlässiger als jede externe Heuristik. Rein
    informativ – löst selbst keine Aktion aus."""
    status_topic = f'{mqtt_prefix}/status'
    keepalive_topic = f'{mqtt_prefix}/keepaliveepoch'
    vals, err = mqtt_read_retained([status_topic, keepalive_topic])
    if status_topic not in vals and keepalive_topic not in vals:
        detail = err or 'Kein Wert unter diesem Topic-Präfix gefunden (evtl. falscher Präfix)'
        return {'checked': False, 'connected': False, 'stale': False, 'status_text': '', 'detail': detail}
    status_text = vals.get(status_topic, '')
    connected = status_text.strip().lower() == 'connected'
    stale = False
    keepalive_raw = vals.get(keepalive_topic, '')
    if keepalive_raw.strip().isdigit():
        stale = (time.time() - int(keepalive_raw.strip())) > max_age_s
    return {'checked': True, 'connected': connected, 'stale': stale, 'status_text': status_text, 'detail': ''}

# ---------------------------------------------------------------------------
# Aktions-Historie: append-only Liste signifikanter Ereignisse (Neustarts, Reboots) für die
# "Letzte Aktionen"-Anzeige im Status-Tab und die volle Historie im Log-Tab. Auf 200 Einträge
# gedeckelt, damit state.json nicht unbegrenzt wächst.
# ---------------------------------------------------------------------------
def log_action(state, action, success=True, detail=''):
    entry = {
        'epoch': time.time(),
        'time': fmt(time.time()),
        'action': action,
        'success': bool(success),
        'detail': detail,
    }
    action_log = state.setdefault('action_log', [])
    action_log.append(entry)
    if len(action_log) > 200:
        del action_log[:len(action_log) - 200]

def trigger_reboot(reason, state):
    log.critical(f'AUTOMATISCHER REBOOT ausgelöst – Grund: {reason}')
    now = time.time()
    state['last_auto_reboot_epoch']  = now
    state['last_auto_reboot']        = fmt(now)
    state['last_auto_reboot_reason'] = reason
    # State (inkl. optimistischem Log-Eintrag) MUSS vor dem eigentlichen Reboot-Aufruf
    # persistiert werden – der Prozess kann durch den Reboot selbst jederzeit beendet werden.
    log_action(state, reason, success=True, detail='Reboot-Anfrage wird gestellt')
    save_state(state)
    mqtt_publish_status(state)
    rc, out, err = run_helper('reboot', timeout=15)
    if rc != 0:
        # Reboot ist NICHT erfolgt – Prozess läuft weiter, Log-Eintrag kann korrigiert werden.
        log.error(f'Reboot-Helper meldet Fehler (RC={rc}): {(err or out).strip()[:300]}')
        if state.get('action_log'):
            state['action_log'][-1]['success'] = False
            state['action_log'][-1]['detail']  = (err or out).strip()[:200]
        save_state(state)
    else:
        log.critical('Reboot-Anfrage an systemd übermittelt (RC=0) – System sollte in Kürze neu starten')

def cooldown_remaining_seconds(state):
    last = state.get('last_auto_reboot_epoch', 0) or 0
    if last <= 0:
        return 0
    remaining = COOLDOWN_HOURS * 3600 - (time.time() - last)
    return max(0, remaining)

# ---------------------------------------------------------------------------
# MQTT – rein informative Statusveröffentlichung, one-shot pro Zyklus.
# Bewusst KEINE dauerhafte Verbindung: der Watchdog läuft nur alle paar Minuten,
# eine Kurzverbindung pro Zyklus (connect → publish → disconnect) vermeidet die
# gesamte Reconnect-/RC=7-Komplexität dauerhafter MQTT-Clients vollständig.
# Fehlschläge sind nicht kritisch – die Watchdog-Kernfunktion hängt nicht von MQTT ab.
# Zugangsdaten (RESOLVED_MQTT_*) wurden bereits beim Start aufgelöst, siehe oben.
# ---------------------------------------------------------------------------
def mqtt_publish_status(state):
    if not (MQTT_ENABLED and MQTT_OK):
        return
    try:
        broker, port = RESOLVED_MQTT_BROKER, RESOLVED_MQTT_PORT
        auth = {'username': RESOLVED_MQTT_USER, 'password': RESOLVED_MQTT_PASS} if RESOLVED_MQTT_USER else None
        msgs = [
            {'topic': f'{TOPIC_PREFIX}/status',                   'payload': state.get('status', 'OK'),                         'retain': True},
            {'topic': f'{TOPIC_PREFIX}/connected',                'payload': '1' if state.get('netbird_connected') else '0',    'retain': True},
            {'topic': f'{TOPIC_PREFIX}/management',                'payload': state.get('netbird_management', '?'),              'retain': True},
            {'topic': f'{TOPIC_PREFIX}/signal',                    'payload': state.get('netbird_signal', '?'),                   'retain': True},
            {'topic': f'{TOPIC_PREFIX}/last_check_epoch',          'payload': str(int(state.get('last_check_epoch', 0) or 0)),   'retain': True},
            {'topic': f'{TOPIC_PREFIX}/last_restart_epoch',        'payload': str(int(state.get('last_restart_epoch', 0) or 0)), 'retain': True},
            {'topic': f'{TOPIC_PREFIX}/restart_count_total',       'payload': str(int(state.get('restart_count_total', 0) or 0)),'retain': True},
            {'topic': f'{TOPIC_PREFIX}/last_reboot_epoch',         'payload': str(int(state.get('last_auto_reboot_epoch', 0) or 0)), 'retain': True},
            {'topic': f'{TOPIC_PREFIX}/last_reboot_reason',        'payload': state.get('last_auto_reboot_reason', '') or '',    'retain': True},
            {'topic': f'{TOPIC_PREFIX}/cooldown_active',           'payload': '1' if cooldown_remaining_seconds(state) > 0 else '0', 'retain': True},
            {'topic': f'{TOPIC_PREFIX}/cooldown_remaining_min',    'payload': str(int(cooldown_remaining_seconds(state) // 60)), 'retain': True},
        ]
        if F4_ENABLED:
            msgs += [
                {'topic': f'{TOPIC_PREFIX}/mosquitto/healthy',       'payload': '1' if state.get('mosquitto_healthy') else '0',         'retain': True},
                {'topic': f'{TOPIC_PREFIX}/mosquitto/active_state',  'payload': state.get('mosquitto_active_state', '?'),                'retain': True},
                {'topic': f'{TOPIC_PREFIX}/mosquitto/sub_state',     'payload': state.get('mosquitto_sub_state', '?'),                   'retain': True},
                {'topic': f'{TOPIC_PREFIX}/mosquitto/restart_count', 'payload': str(int(state.get('mosquitto_restart_count', 0) or 0)), 'retain': True},
                {'topic': f'{TOPIC_PREFIX}/gateway/healthy',         'payload': '1' if state.get('gateway_healthy') else '0',           'retain': True},
                {'topic': f'{TOPIC_PREFIX}/gateway/active_state',    'payload': state.get('gateway_active_state', '?'),                  'retain': True},
                {'topic': f'{TOPIC_PREFIX}/gateway/sub_state',       'payload': state.get('gateway_sub_state', '?'),                     'retain': True},
                {'topic': f'{TOPIC_PREFIX}/gateway/restart_count',   'payload': str(int(state.get('gateway_restart_count', 0) or 0)),   'retain': True},
                {'topic': f'{TOPIC_PREFIX}/gateway/broker_linked',   'payload': '1' if state.get('gateway_broker_linked') else '0',     'retain': True},
            ]
        mqtt_publish_mod.multiple(
            msgs, hostname=broker, port=port, auth=auth,
            client_id=f'HitWatch4Lox-{socket.gethostname()}', qos=0,
        )
    except Exception as e:
        log.warning(f'MQTT: Statusveröffentlichung fehlgeschlagen (unkritisch): {e}')

# ---------------------------------------------------------------------------
# Instanz-Exklusiv-Lock (verhindert doppelte Daemon-Instanzen, z.B. bei
# überlappendem @reboot-Cron und manuellem Start)
# ---------------------------------------------------------------------------
def _acquire_lock():
    global _lock_fd
    if fcntl is None:
        return
    my_pid = os.getpid()
    try:
        _lock_fd = open(LOCK_FILE, 'w')
        fcntl.flock(_lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return
    except (IOError, OSError):
        log.warning('Lock belegt – warte bis alte Instanz beendet (max. 10s)...')
    deadline = time.time() + 10
    while time.time() < deadline:
        try:
            fcntl.flock(_lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return
        except (IOError, OSError):
            time.sleep(1)
    log.warning('Lock-Timeout – beende alte Instanzen')
    try:
        r = subprocess.run(['pgrep', '-f', 'hitwatch4lox_daemon.py'], capture_output=True, text=True)
        for pid_str in r.stdout.strip().split():
            try:
                other = int(pid_str)
                if other == my_pid: continue
                os.kill(other, signal.SIGKILL)
            except (ValueError, ProcessLookupError, PermissionError):
                pass
    except Exception: pass
    dl2 = time.time() + 10
    while time.time() < dl2:
        try:
            fcntl.flock(_lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return
        except (IOError, OSError):
            time.sleep(1)
    log.warning('Lock nach Kill-Timeout weiter belegt – starte trotzdem weiter')

# ---------------------------------------------------------------------------
# Hauptschleife
# ---------------------------------------------------------------------------
def run():
    _acquire_lock()
    try:
        with open(PID_FILE, 'w') as f: f.write(str(os.getpid()))
    except Exception: pass

    state = load_state()
    _prev_connected = state.get('netbird_connected')
    _last_heartbeat = 0.0
    HEARTBEAT_SECONDS = 1800  # periodischer Log-Eintrag auch ohne Statusänderung

    log.info(
        f'HitWatch4Lox Daemon gestartet (Version {DAEMON_VERSION}, PID {os.getpid()}) – '
        f'F1={F1_ENABLED} F2={F2_ENABLED} F3={F3_ENABLED} MQTT={MQTT_ENABLED and MQTT_OK}'
    )

    while True:
        try:
            now = time.time()
            state['status'] = 'OK'

            # ---------------- Funktion 1: Netbird-Dienst-Watchdog ----------------
            if F1_ENABLED:
                result = check_netbird()
                state['last_check_epoch']   = now
                state['last_check']         = fmt(now)
                state['netbird_connected']  = result['connected']
                state['netbird_management'] = result['management']
                state['netbird_signal']     = result['signal']

                if result['error']:
                    state['status'] = f"Netbird-Statusabfrage fehlgeschlagen: {result['error']}"
                    log.error(state['status'])

                if not result['connected']:
                    if _prev_connected is not False:
                        log.warning(
                            f"Netbird nicht verbunden (Management={result['management']}, "
                            f"Signal={result['signal']}) – starte Dienst neu..."
                        )
                    ok = restart_netbird()
                    state['last_restart_epoch']   = now
                    state['last_restart']         = fmt(now)
                    state['restart_count_total']  = int(state.get('restart_count_total', 0) or 0) + (1 if ok else 0)
                    log_action(state, 'netbird_restart', success=ok)

                    time.sleep(RESTART_WAIT)
                    result2 = check_netbird()
                    state['netbird_connected']  = result2['connected']
                    state['netbird_management'] = result2['management']
                    state['netbird_signal']     = result2['signal']

                    if result2['connected']:
                        log.info('Netbird nach Dienst-Neustart wieder verbunden')
                    else:
                        log.error(
                            f"Netbird nach Dienst-Neustart weiterhin nicht verbunden "
                            f"(Management={result2['management']}, Signal={result2['signal']})"
                        )
                        if F2_ENABLED:
                            remaining = cooldown_remaining_seconds(state)
                            if remaining > 0:
                                log.warning(
                                    f'Reboot unterdrückt – Cooldown aktiv, noch {remaining/3600:.1f}h '
                                    f"(letzter Auto-Reboot: {state.get('last_auto_reboot', '–')}, "
                                    f'Grund: {state.get("last_auto_reboot_reason", "–")})'
                                )
                            else:
                                trigger_reboot('netbird_watchdog', state)
                                save_state(state)
                                return  # System fährt herunter – Prozess muss hier nicht weiterlaufen
                    _prev_connected = result2['connected']
                else:
                    if _prev_connected is False:
                        log.info('Netbird wieder verbunden')
                    _prev_connected = True

            # ---------------- Funktion 3: Automatischer Reboot (mehrere Wochentage + Frequenz) ----------------
            if F3_ENABLED:
                _now_dt = datetime.now()
                _today_iso = _now_dt.isoweekday()  # 1=Mo .. 7=So
                if _today_iso in F3_WEEKDAYS:
                    today_str = _now_dt.strftime('%Y-%m-%d')
                    _already_handled_today = state.get('last_scheduled_reboot_date') == today_str
                    _scheduled_dt = _now_dt.replace(hour=_F3_H, minute=_F3_M, second=0, microsecond=0)
                    _since_scheduled = (_now_dt - _scheduled_dt).total_seconds()
                    # Fangfenster statt offener "irgendwann nach HH:MM"-Prüfung: verhindert dass ein
                    # (Neu-)Start des Daemons Stunden nach dem geplanten Zeitpunkt (z.B. nach einer
                    # Neuinstallation, die state.json zurücksetzt, oder nach einem Absturz) einen
                    # längst überfälligen Reboot nachträglich auslöst. Verpasste Fenster werden
                    # einfach übersprungen – nächster Versuch ist erst wieder am nächsten passenden
                    # Wochentag. Der Frequenz-Zähler wird bei einem verpassten Fenster NICHT erhöht,
                    # damit ein Ausfall die "jedes 2./3./4. Mal"-Zählung nicht verschiebt.
                    _catch_window_s = max(600, CHECK_INTERVAL * 2)
                    if not _already_handled_today and 0 <= _since_scheduled < _catch_window_s:
                        state['last_scheduled_reboot_date'] = today_str
                        counters = state.setdefault('reboot_weekday_counters', {})
                        wd_key = str(_today_iso)
                        count = int(counters.get(wd_key, 0)) + 1
                        counters[wd_key] = count
                        if count % F3_EVERY_N != 0:
                            log.info(
                                f'Automatischer Reboot an diesem Wochentag übersprungen '
                                f'(Zyklus {count}/{F3_EVERY_N}, Frequenz alle {F3_EVERY_N}x)'
                            )
                        else:
                            remaining = cooldown_remaining_seconds(state)
                            if remaining > 0:
                                log.warning(
                                    f'Automatischer Reboot fällig (Zyklus {count}/{F3_EVERY_N}), aber Cooldown '
                                    f"aktiv – noch {remaining/3600:.1f}h (letzter Auto-Reboot: "
                                    f"{state.get('last_auto_reboot', '–')}, Grund: {state.get('last_auto_reboot_reason', '–')})"
                                )
                            else:
                                trigger_reboot('scheduled_reboot', state)
                                save_state(state)
                                return
                    elif not _already_handled_today and _since_scheduled >= _catch_window_s:
                        state['last_scheduled_reboot_date'] = today_str
                        log.warning(
                            f'Automatischer Reboot verpasst (Fenster {_catch_window_s/60:.0f} min nach '
                            f'{F3_TIME} bereits abgelaufen) – wird an diesem Wochentag nicht nachgeholt, '
                            f'Frequenz-Zähler bleibt unverändert.'
                        )

            # ---------------- Funktion 4: MQTT-Dienste-Watchdog ----------------
            # Status beider Dienste wird IMMER erhoben sobald F4 aktiv ist (nicht mehr einzeln
            # abschaltbar) – nur der automatische Neustart ist pro Dienst separat schaltbar.
            if F4_ENABLED:
                m = get_service_state(F4_MOSQUITTO_SERVICE)
                tcp_ok = tcp_check(F4_MOSQUITTO_HOST, F4_MOSQUITTO_PORT)
                mosq_healthy = m['healthy'] and tcp_ok
                state['mosquitto_active_state'] = m['active_state']
                state['mosquitto_sub_state']    = m['sub_state']
                state['mosquitto_tcp_ok']       = tcp_ok
                state['mosquitto_healthy']      = mosq_healthy
                if not mosq_healthy:
                    log.warning(
                        f"Mosquitto ({F4_MOSQUITTO_SERVICE}) nicht gesund – Status: "
                        f"{m['active_state']}/{m['sub_state']}, TCP {F4_MOSQUITTO_HOST}:{F4_MOSQUITTO_PORT} "
                        f"{'erreichbar' if tcp_ok else 'NICHT erreichbar'}"
                        + (' – starte Dienst neu...' if F4_MOSQUITTO_AUTORESTART else ' – Autorestart deaktiviert, kein Neustart.')
                    )
                    if F4_MOSQUITTO_AUTORESTART:
                        ok = restart_service(F4_MOSQUITTO_SERVICE)
                        state['mosquitto_last_restart_epoch'] = now
                        state['mosquitto_last_restart']       = fmt(now)
                        state['mosquitto_restart_count']      = int(state.get('mosquitto_restart_count', 0) or 0) + (1 if ok else 0)
                        log_action(state, 'mosquitto_restart', success=ok)

                g = get_process_state(F4_GATEWAY_PATTERN)
                mqtt_status = check_gateway_mqtt_status(F4_GATEWAY_MQTT_PREFIX)
                gw_linked = mqtt_status['connected'] and not mqtt_status['stale']
                # "Gesund" braucht den Prozess UND (falls prüfbar) eine frische "Connected"-
                # Selbstauskunft des Gateways. Ist der Status-Topic (noch) nicht lesbar (falscher
                # Präfix, MQTT aus, o.ä.), fällt die Bewertung auf die reine Prozessprüfung zurück.
                gw_healthy = g['running'] and (not mqtt_status['checked'] or gw_linked)
                state['gateway_running']        = g['running']
                state['gateway_active_state']   = 'active' if g['running'] else 'inactive'
                state['gateway_sub_state']      = 'running' if g['running'] else 'dead'
                state['gateway_healthy']        = gw_healthy
                state['gateway_broker_checked'] = mqtt_status['checked']
                state['gateway_broker_linked']  = gw_linked
                state['gateway_broker_detail']  = mqtt_status['detail'] or (
                    'Herzschlag veraltet' if mqtt_status['checked'] and mqtt_status['stale'] else
                    (f"Status: {mqtt_status['status_text']}" if mqtt_status['checked'] else '')
                )
                if not gw_healthy:
                    # Wie bei Funktion 1: "läuft" allein reicht nicht – ein Prozess der lebt aber
                    # laut eigener Selbstauskunft nicht mit Mosquitto verbunden ist, gilt ebenso
                    # als ungesund und löst (bei aktivem Autorestart) einen Neustart aus.
                    reason_txt = 'läuft nicht' if not g['running'] else f"läuft, aber nicht mit Mosquitto verbunden ({state['gateway_broker_detail']})"
                    log.warning(
                        f"MQTT-Gateway ({F4_GATEWAY_PATTERN}) {reason_txt}"
                        + (' – beende Prozess, LoxBerry startet Kern-Daemons üblicherweise selbst neu...'
                           if F4_GATEWAY_AUTORESTART else ' – Autorestart deaktiviert, kein Neustart.')
                    )
                    if F4_GATEWAY_AUTORESTART:
                        restart_process(F4_GATEWAY_PATTERN)
                        time.sleep(RESTART_WAIT)
                        g2 = get_process_state(F4_GATEWAY_PATTERN)
                        # Erfolg wird bewusst nur am Prozess festgemacht: der Gateway-eigene
                        # MQTT-Status könnte nach dem Neustart noch für einige Minuten den alten
                        # (retained) Wert zeigen, bevor das Gateway neu publiziert – der nächste
                        # reguläre Prüfzyklus aktualisiert gateway_broker_linked dann von selbst.
                        ok = g2['running']
                        state['gateway_running']      = g2['running']
                        state['gateway_active_state'] = 'active' if ok else 'inactive'
                        state['gateway_sub_state']    = 'running' if ok else 'dead'
                        state['gateway_healthy']      = ok
                        state['gateway_last_restart_epoch'] = now
                        state['gateway_last_restart']       = fmt(now)
                        state['gateway_restart_count']      = int(state.get('gateway_restart_count', 0) or 0) + (1 if ok else 0)
                        log_action(
                            state, 'gateway_restart', success=ok,
                            detail='' if ok else 'LoxBerry hat den Dienst nicht automatisch neu gestartet – bitte manuell prüfen'
                        )
                        if not ok:
                            log.error('MQTT-Gateway nach Beenden nicht automatisch neu gestartet – bitte manuell prüfen (z.B. LoxBerry neu starten)')

            # ---------------- Heartbeat / Persistenz ----------------
            if now - _last_heartbeat > HEARTBEAT_SECONDS:
                log.info(
                    f"Heartbeat – Netbird verbunden: {state.get('netbird_connected')} | "
                    f"Neustarts gesamt: {state.get('restart_count_total', 0)} | "
                    f"Letzter Auto-Reboot: {state.get('last_auto_reboot', '–')}"
                )
                _last_heartbeat = now

            save_state(state)
            mqtt_publish_status(state)

        except Exception:
            log.error(f'Loop-Fehler: {traceback.format_exc()}')

        time.sleep(CHECK_INTERVAL)

if __name__ == '__main__':
    try:
        run()
    except Exception:
        with open(os.path.join(LOGDIR, 'crash.log'), 'a') as f:
            f.write(f'{datetime.now()} CRASH: {traceback.format_exc()}\n')
