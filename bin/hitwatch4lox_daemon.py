"""HitWatch4Lox Daemon – Netbird- und MQTT-Dienste-Watchdog für LoxBerry"""
DAEMON_VERSION = '1.9'
import os, sys, re, json, time, logging, configparser, signal, subprocess, glob, socket, shutil, traceback
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
# Mindest-Ausfalldauer bevor der Dienst-Neustart ausgelöst wird (0 = sofort, bisheriges
# Verhalten). Netbird hat kein automatisches Reconnect – ein Warten hilft hier grundsätzlich
# nicht (siehe app_help.php), Default bleibt daher bei 0, ist aber wie überall konfigurierbar.
F1_UNHEALTHY_MIN = max(0, min(60, int(get_cfg('WATCHDOG', 'UNHEALTHY_MIN', '0'))))

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
# Eigenes, von Funktion 1 unabhängiges Prüfintervall – standardmäßig deutlich kürzer (60s statt
# 300s), damit ein MQTT-Ausfall schneller auffällt. Siehe Hauptschleife: LOOP_TICK richtet sich
# nach dem kürzesten aktiven Intervall, jede Funktion prüft selbst ob SIE fällig ist.
F4_CHECK_INTERVAL       = max(15, min(3600, int(get_cfg('MQTT_WATCHDOG', 'CHECK_INTERVAL', '60'))))
F4_MOSQUITTO_AUTORESTART = F4_ENABLED and get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_AUTORESTART', '1') == '1'
F4_MOSQUITTO_UNHEALTHY_MIN = max(0, min(60, int(get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_UNHEALTHY_MIN', '0'))))
F4_MOSQUITTO_SERVICE    = get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_SERVICE', 'mosquitto').strip()
F4_MOSQUITTO_HOST       = get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_HOST', '127.0.0.1').strip() or '127.0.0.1'
F4_MOSQUITTO_PORT       = int(get_cfg('MQTT_WATCHDOG', 'MOSQUITTO_PORT', '1883') or '1883')
F4_GATEWAY_AUTORESTART  = F4_ENABLED and get_cfg('MQTT_WATCHDOG', 'GATEWAY_AUTORESTART', '0') == '1'
F4_GATEWAY_UNHEALTHY_MIN = max(0, min(60, int(get_cfg('MQTT_WATCHDOG', 'GATEWAY_UNHEALTHY_MIN', '0'))))
# Das LoxBerry MQTT-Gateway (mqttgateway.pl) ist KEIN systemd-Dienst, sondern ein klassischer
# Perl-Kern-Daemon (bestätigt per 'systemctl list-units' – dort taucht nur mosquitto.service auf,
# 'pgrep -fa mqtt' zeigt aber den laufenden Prozess). Erkennung daher über Prozess-Suchmuster
# (pgrep -f) statt Dienstname.
F4_GATEWAY_PATTERN      = get_cfg('MQTT_WATCHDOG', 'GATEWAY_PROCESS_PATTERN', 'mqttgateway.pl').strip()
# Das Gateway veröffentlicht seinen eigenen Verbindungsstatus + Herzschlag direkt als MQTT-Topics
# unter <SYSTEM-HOSTNAME>/mqttgateway/status + .../keepaliveepoch – zuverlässiger als jede
# externe Heuristik, da es die autoritative Selbstauskunft des Gateways ist. WICHTIG (Live-Fund):
# der Präfix ist NICHT der literale String "loxberry", sondern hängt vom tatsächlichen
# System-Hostnamen des jeweiligen Geräts ab (z.B. "loxberrybs/mqttgateway/..." auf einem LoxBerry
# mit Hostname "loxberrybs") – ein früherer Default mit dem fixen Literal "loxberry/mqttgateway"
# funktionierte nur zufällig auf Geräten deren Hostname noch "loxberry" (LoxBerry-Werkseinstellung)
# war. Der Default wird daher jetzt dynamisch aus dem aktuellen Hostnamen abgeleitet – bleibt aber
# wie gehabt in den Einstellungen überschreibbar, falls ein Gerät wider Erwarten abweicht.
F4_GATEWAY_MQTT_PREFIX_DEFAULT = f'{socket.gethostname()}/mqttgateway'
F4_GATEWAY_MQTT_PREFIX  = get_cfg('MQTT_WATCHDOG', 'GATEWAY_MQTT_PREFIX', F4_GATEWAY_MQTT_PREFIX_DEFAULT).strip() or F4_GATEWAY_MQTT_PREFIX_DEFAULT
if F4_ENABLED and not _valid_service_name(F4_MOSQUITTO_SERVICE):
    log.warning(f'MQTT_WATCHDOG.MOSQUITTO_SERVICE ungültig ({F4_MOSQUITTO_SERVICE!r}) – Fallback "mosquitto"')
    F4_MOSQUITTO_SERVICE = 'mosquitto'
if F4_ENABLED and (len(F4_GATEWAY_PATTERN) < 4 or len(F4_GATEWAY_PATTERN) > 128):
    log.warning(f'MQTT_WATCHDOG.GATEWAY_PROCESS_PATTERN ungültig/zu kurz ({F4_GATEWAY_PATTERN!r}) – Fallback "mqttgateway.pl"')
    F4_GATEWAY_PATTERN = 'mqttgateway.pl'

# Funktion 5: System-Diagnose. Anders als Funktion 4 ist hier JEDES Thema einzeln sowohl im
# Monitoring ALS AUCH im Auto-Heal schaltbar (Nutzerwunsch: manche Standorte sollen nur
# beobachtet, nicht automatisch verändert werden). Auto-Heal beschränkt sich bewusst auf
# Dienst-/Netzwerk-Neustarts – kein Dateisystem-Remount o.ä. (zu riskant unbeaufsichtigt).
F5_ENABLED         = get_cfg('SYSTEM_DIAGNOSTICS', 'ENABLED', '0') == '1'
F5_CHECK_INTERVAL  = max(30, min(3600, int(get_cfg('SYSTEM_DIAGNOSTICS', 'CHECK_INTERVAL', '120'))))

F5_DISK_MONITOR    = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'DISK_MONITOR', '1') == '1'
F5_DISK_WARN_PCT   = max(1, min(99, int(get_cfg('SYSTEM_DIAGNOSTICS', 'DISK_WARN_PERCENT', '85'))))
F5_DISK_CRIT_PCT   = max(F5_DISK_WARN_PCT, min(100, int(get_cfg('SYSTEM_DIAGNOSTICS', 'DISK_CRIT_PERCENT', '95'))))

F5_MEMORY_MONITOR  = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'MEMORY_MONITOR', '1') == '1'
F5_MEMORY_WARN_PCT = max(1, min(100, int(get_cfg('SYSTEM_DIAGNOSTICS', 'MEMORY_WARN_PERCENT', '90'))))

F5_TEMP_MONITOR    = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'TEMP_MONITOR', '1') == '1'
F5_TEMP_WARN_C     = max(1, min(120, int(get_cfg('SYSTEM_DIAGNOSTICS', 'TEMP_WARN_C', '70'))))
F5_TEMP_CRIT_C     = max(F5_TEMP_WARN_C, min(120, int(get_cfg('SYSTEM_DIAGNOSTICS', 'TEMP_CRIT_C', '80'))))

F5_INTERNET_MONITOR  = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'INTERNET_MONITOR', '1') == '1'
F5_INTERNET_AUTOHEAL = F5_INTERNET_MONITOR and get_cfg('SYSTEM_DIAGNOSTICS', 'INTERNET_AUTOHEAL', '0') == '1'
F5_INTERNET_HOST     = get_cfg('SYSTEM_DIAGNOSTICS', 'INTERNET_HOST', '1.1.1.1').strip() or '1.1.1.1'
F5_INTERNET_PORT     = int(get_cfg('SYSTEM_DIAGNOSTICS', 'INTERNET_PORT', '53') or '53')
# Netzwerk-Blips (kurzer DHCP-Hänger, kurzer ISP-Aussetzer) lösen sich oft von selbst –
# Standard 3 min Mindest-Ausfalldauer vor einem Netzwerk-Neustart, konfigurierbar.
F5_INTERNET_UNHEALTHY_MIN = max(0, min(60, int(get_cfg('SYSTEM_DIAGNOSTICS', 'INTERNET_UNHEALTHY_MIN', '3'))))

F5_TIME_MONITOR  = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'TIME_MONITOR', '1') == '1'
F5_TIME_AUTOHEAL = F5_TIME_MONITOR and get_cfg('SYSTEM_DIAGNOSTICS', 'TIME_AUTOHEAL', '0') == '1'
# systemd-timesyncd synchronisiert von sich aus periodisch neu – ein kurzer Ausschlag (z.B. kurz
# nach einem Neustart) braucht keinen Eingriff. Auto-Heal greift daher erst wenn der Zustand
# durchgehend länger als diese Schwelle anhält (Standard 10 min).
F5_TIME_UNSYNCED_MIN = max(1, min(180, int(get_cfg('SYSTEM_DIAGNOSTICS', 'TIME_UNSYNCED_MIN', '10'))))

F5_SERVICES_MONITOR  = F5_ENABLED and get_cfg('SYSTEM_DIAGNOSTICS', 'SERVICES_MONITOR', '0') == '1'
F5_SERVICES_AUTOHEAL = F5_SERVICES_MONITOR and get_cfg('SYSTEM_DIAGNOSTICS', 'SERVICES_AUTOHEAL', '0') == '1'
F5_SERVICES_UNHEALTHY_MIN = max(0, min(60, int(get_cfg('SYSTEM_DIAGNOSTICS', 'SERVICES_UNHEALTHY_MIN', '0'))))
_F5_SERVICES_RAW = get_cfg('SYSTEM_DIAGNOSTICS', 'SERVICES_LIST', '')
F5_SERVICES_LIST = [s.strip() for s in _F5_SERVICES_RAW.split(',') if s.strip() and _valid_service_name(s.strip())]

# Die Hauptschleife tickt im kürzesten aktiven Intervall (üblicherweise F4, 60s statt F1s 300s),
# damit ein Dienst mit kurzem Prüfintervall nicht auf den nächsten langen F1-Zyklus warten muss.
# Jede Funktion prüft selbst anhand ihres eigenen "zuletzt gelaufen"-Zeitstempels ob sie an der
# Reihe ist (siehe run()) – der Tick ist nur die Granularität, nicht das Intervall selbst.
LOOP_TICK = CHECK_INTERVAL
if F4_ENABLED:
    LOOP_TICK = min(LOOP_TICK, F4_CHECK_INTERVAL)
if F5_ENABLED:
    LOOP_TICK = min(LOOP_TICK, F5_CHECK_INTERVAL)
LOOP_TICK = max(15, LOOP_TICK)

_f3_weekdays_str = ','.join(str(w) for w in sorted(F3_WEEKDAYS))
log.info(
    f'Konfiguration: F1(Watchdog)={"an" if F1_ENABLED else "aus"} (Intervall {CHECK_INTERVAL}s) | '
    f'F2(Reboot-Eskalation)={"an" if F2_ENABLED else "aus"} (Cooldown {COOLDOWN_HOURS}h) | '
    f'F3(Automatischer Reboot)={"an" if F3_ENABLED else "aus"} '
    f'(Tage {_f3_weekdays_str} um {F3_TIME}, alle {F3_EVERY_N}x) | '
    f'F4(MQTT-Watchdog)={"an" if F4_ENABLED else "aus"} (Intervall {F4_CHECK_INTERVAL}s) '
    f'(Mosquitto={F4_MOSQUITTO_SERVICE}, Autorestart={"an" if F4_MOSQUITTO_AUTORESTART else "aus"} | '
    f'Gateway={F4_GATEWAY_PATTERN} (MQTT-Präfix {F4_GATEWAY_MQTT_PREFIX}), '
    f'Autorestart={"an" if F4_GATEWAY_AUTORESTART else "aus"}) | '
    f'F5(System-Diagnose)={"an" if F5_ENABLED else "aus"} (Intervall {F5_CHECK_INTERVAL}s, '
    f'Disk={"an" if F5_DISK_MONITOR else "aus"} RAM={"an" if F5_MEMORY_MONITOR else "aus"} '
    f'Temp={"an" if F5_TEMP_MONITOR else "aus"} '
    f'Internet={"an" if F5_INTERNET_MONITOR else "aus"}/Heal={"an" if F5_INTERNET_AUTOHEAL else "aus"} '
    f'Zeit={"an" if F5_TIME_MONITOR else "aus"}/Heal={"an" if F5_TIME_AUTOHEAL else "aus"} '
    f'Dienste={"an" if F5_SERVICES_MONITOR else "aus"}/Heal={"an" if F5_SERVICES_AUTOHEAL else "aus"}) | '
    f'MQTT={"an" if MQTT_ENABLED else "aus"} | Loop-Takt={LOOP_TICK}s'
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
# Funktion 5: System-Diagnose (Speicher, RAM, CPU-Temperatur, Internet, Zeit-Synchronisation,
# weitere LoxBerry-Kerndienste) – typische Ursachen für einen Vor-Ort-Einsatz bei Kunden.
# Reine Status-Checks brauchen kein Root; die drei Auto-Heal-Aktionen (Netzwerk neu starten,
# Zeit-Sync neu starten, restart_service für weitere Dienste) laufen über denselben
# validierenden Root-Helper wie Funktion 1/4.
# ---------------------------------------------------------------------------
def check_disk_usage(path='/'):
    try:
        usage = shutil.disk_usage(path)
        percent = round((usage.total - usage.free) / usage.total * 100, 1)
        return {'ok': True, 'percent': percent}
    except Exception as e:
        return {'ok': False, 'percent': None, 'error': str(e)[:120]}

def check_memory_usage():
    try:
        info = {}
        with open('/proc/meminfo') as f:
            for line in f:
                k, _, v = line.partition(':')
                info[k.strip()] = int(v.strip().split()[0])  # kB
        total = info.get('MemTotal', 0)
        avail = info.get('MemAvailable', info.get('MemFree', 0))
        if total <= 0:
            return {'ok': False, 'percent': None}
        percent = round((total - avail) / total * 100, 1)
        return {'ok': True, 'percent': percent}
    except Exception as e:
        return {'ok': False, 'percent': None, 'error': str(e)[:120]}

def check_cpu_temperature():
    """Bevorzugt die generische thermal_zone-Schnittstelle (kein Root, auf praktisch jedem
    Linux-System vorhanden) – probiert ALLE vorhandenen Zonen durch (nicht nur zone0, das auf
    manchen Systemen ein anderer Sensor als die CPU belegt), vcgencmd (Raspberry-spezifisch) nur
    als letzter Fallback. Liefert bei Fehlschlag eine Fehlerliste statt stumm 'nicht ermittelbar'
    zu sein – nicht jede Hardware (z.B. manche x86-VMs ohne durchgereichten Sensor) hat
    überhaupt einen lesbaren Temperatursensor, das ist ein legitimer Fall, aber diagnostizierbar."""
    errors = []
    for zone in sorted(glob.glob('/sys/class/thermal/thermal_zone*/temp')):
        try:
            with open(zone) as f:
                millideg = int(f.read().strip())
            return {'ok': True, 'temp_c': round(millideg / 1000, 1)}
        except Exception as e:
            errors.append(f'{zone}: {e}')
    if not errors:
        errors.append('kein /sys/class/thermal/thermal_zone*/temp vorhanden')
    try:
        r = subprocess.run(['vcgencmd', 'measure_temp'], capture_output=True, text=True, timeout=5)
        m = re.search(r'temp=([\d.]+)', r.stdout)
        if m:
            return {'ok': True, 'temp_c': float(m.group(1))}
        errors.append(f"vcgencmd: {(r.stderr or r.stdout or 'keine Ausgabe').strip()[:100]}")
    except FileNotFoundError:
        errors.append('vcgencmd nicht installiert (kein Raspberry Pi?)')
    except Exception as e:
        errors.append(f'vcgencmd: {e}')
    return {'ok': False, 'temp_c': None, 'error': '; '.join(errors)[:250]}

def _any_ntp_service_installed():
    """Prüft ob wenigstens einer der gängigen NTP-Client-Dienste auf diesem System überhaupt
    als systemd-Unit EXISTIERT (LoadState=loaded – unabhängig davon ob er gerade läuft).
    Unterscheidet 'kein NTP-Client installiert' (z.B. typisch für LXC-Container, die die
    Uhrzeit direkt vom Host-Kernel übernehmen und daher gar keinen eigenen NTP-Client
    brauchen – die Zeit ist trotzdem korrekt) von 'NTP-Client installiert, aber nicht aktiv'
    (ein echtes, meldenswertes Problem)."""
    for svc in ('systemd-timesyncd.service', 'chrony.service', 'chronyd.service', 'ntp.service', 'ntpd.service'):
        try:
            r = subprocess.run(
                ['systemctl', 'show', svc, '--property=LoadState', '--no-pager'],
                capture_output=True, text=True, timeout=5,
            )
            if 'LoadState=loaded' in r.stdout:
                return True
        except Exception:
            pass
    return False

def check_time_sync():
    """Nutzt systemds eigene Sync-Bewertung (timedatectl) statt selbst eine NTP-Abfrage zu
    bauen. Parst 'Key=Value'-Zeilen aus 'timedatectl show' OHNE das --value-Flag (das gibt es
    erst ab systemd 230, 2016) – dasselbe robuste Muster wie get_service_state() weiter oben.
    WICHTIG: Die einzige echte Property im 'org.freedesktop.timedate1'-D-Bus-Interface heißt
    'NTPSynchronized' – ein früherer Versuch fragte zusätzlich ein nicht-existentes
    'SystemClockSynchronized' ab; eine einzelne ungültige Property in der Liste ließ
    'timedatectl show' auf manchen Systemen komplett LEER zurückkehren (RC=0, keine Ausgabe)
    statt nur die gültige Property zu liefern.

    Liefert zusätzlich 'ntp_present' – Live-Fund: Auf manchen LoxBerry-Installationen (vermutlich
    LXC-Container auf Proxmox, die die Uhr vom Host-Kernel übernehmen) läuft GAR KEIN NTP-Client
    (`timedatectl status` zeigt dort 'NTP service: n/a', RTC 'n/a', alle gängigen NTP-Dienste
    inaktiv) – die Zeit ist trotzdem korrekt, 'NTPSynchronized=no' ist hier kein echtes Problem.
    Der Aufrufer kann so zwischen "nicht synchron" (echte Warnung) und "kein NTP-Client
    vorhanden" (informativ, kein Fehlalarm) unterscheiden."""
    try:
        r = subprocess.run(
            ['timedatectl', 'show', '--property=NTPSynchronized'],
            capture_output=True, text=True, timeout=10,
        )
        for line in r.stdout.splitlines():
            if line.startswith('NTPSynchronized='):
                synced = line.split('=', 1)[1].strip().lower() == 'yes'
                return {'ok': True, 'synced': synced, 'ntp_present': synced or _any_ntp_service_installed()}
        # Fallback: 'timedatectl status' ist die klassische, textbasierte Ausgabe und liefert
        # auf praktisch jedem System (auch mit eingeschränktem D-Bus-Zugriff, z.B. in manchen
        # LXC-Containern) zumindest die Zeile "System clock synchronized: yes/no".
        r2 = subprocess.run(['timedatectl', 'status'], capture_output=True, text=True, timeout=10)
        m = re.search(r'System clock synchronized:\s*(\w+)', r2.stdout, re.IGNORECASE)
        if m:
            synced = m.group(1).strip().lower() == 'yes'
            return {'ok': True, 'synced': synced, 'ntp_present': synced or _any_ntp_service_installed()}
        err = (r.stderr or r2.stderr or r2.stdout or f'RC={r.returncode}, keine Ausgabe').strip()[:150]
        return {'ok': False, 'synced': None, 'ntp_present': True, 'error': err}
    except FileNotFoundError:
        return {'ok': False, 'synced': None, 'ntp_present': True, 'error': 'timedatectl nicht installiert'}
    except Exception as e:
        return {'ok': False, 'synced': None, 'ntp_present': True, 'error': str(e)[:120]}

def restart_networking():
    rc, out, err = run_helper('restart_networking', timeout=30)
    if rc != 0:
        log.error(f'Netzwerk-Neustart fehlgeschlagen (RC={rc}): {(err or out).strip()[:300]}')
        return False
    return True

def sync_time_now():
    rc, out, err = run_helper('sync_time', timeout=20)
    if rc != 0:
        log.error(f'Zeit-Synchronisation fehlgeschlagen (RC={rc}): {(err or out).strip()[:300]}')
        return False
    return True

# ---------------------------------------------------------------------------
# Health-Ampel: fasst F1/F4/F5 zu einem einzigen Status (green/yellow/red) + Klartext-Liste
# zusammen – für eine Ein-Blick-Übersicht über viele Standorte (Status-Tab + MQTT-Ampel-Topic).
# ---------------------------------------------------------------------------
def compute_health(state):
    crit, warn = [], []

    if F1_ENABLED and state.get('netbird_connected') is False:
        crit.append('Netbird nicht verbunden')

    if F4_ENABLED:
        if state.get('mosquitto_healthy') is False:
            crit.append('Mosquitto nicht erreichbar')
        if state.get('gateway_healthy') is False:
            crit.append('MQTT-Gateway nicht verbunden')

    if F5_DISK_MONITOR:
        lvl = state.get('diag_disk_level')
        if lvl == 'crit':
            crit.append(f"Speicher kritisch ({state.get('diag_disk_percent', '?')}%)")
        elif lvl == 'warn':
            warn.append(f"Speicher knapp ({state.get('diag_disk_percent', '?')}%)")

    if F5_MEMORY_MONITOR and state.get('diag_memory_level') == 'warn':
        warn.append(f"RAM-Auslastung hoch ({state.get('diag_memory_percent', '?')}%)")

    if F5_TEMP_MONITOR and state.get('diag_temp_available'):
        lvl = state.get('diag_temp_level')
        if lvl == 'crit':
            crit.append(f"CPU-Temperatur kritisch ({state.get('diag_temp_c', '?')}°C)")
        elif lvl == 'warn':
            warn.append(f"CPU-Temperatur hoch ({state.get('diag_temp_c', '?')}°C)")

    if F5_INTERNET_MONITOR and state.get('diag_internet_ok') is False:
        crit.append('Kein Internet')

    if F5_TIME_MONITOR and state.get('diag_time_synced') is False:
        if state.get('diag_time_ntp_present', True):
            warn.append('Systemzeit nicht synchronisiert')
        else:
            # Kann nicht zuverlässig zwischen "Container mit geteilter Host-Uhr" (unproblematisch)
            # und "echte Hardware ohne eingerichtetes NTP" (Zeit kann über Wochen/Monate driften)
            # unterschieden werden – bewusst weiterhin als Warnung sichtbar, damit der Nutzer
            # selbst entscheidet statt dass das Plugin das fälschlich als unproblematisch annimmt.
            warn.append('Kein NTP-Client installiert')

    if F5_SERVICES_MONITOR:
        for name, info in (state.get('diag_services') or {}).items():
            if not info.get('healthy', True):
                crit.append(f'Dienst {name} down')

    if crit:
        return 'red', crit + warn
    if warn:
        return 'yellow', warn
    return 'green', []

# ---------------------------------------------------------------------------
# Menschenlesbare Labels für Aktionen – für das MQTT-Event-Topic (JSON-Payload). Deckt sich
# inhaltlich mit hw4l_action_label() in common.php (dort für die PHP-UI).
# ---------------------------------------------------------------------------
ACTION_LABELS = {
    'netbird_restart':      'Netbird-Dienst neu gestartet',
    'mosquitto_restart':    'Mosquitto neu gestartet',
    'gateway_restart':      'MQTT-Gateway neu gestartet',
    'netbird_watchdog':     'Automatischer Reboot (Netbird-Eskalation)',
    'scheduled_reboot':     'Automatischer Reboot (Zeitplan)',
    'diag_internet_restart':'Netzwerk neu gestartet (Internet-Ausfall)',
    'diag_time_restart':    'Zeit-Synchronisation neu gestartet',
    'diag_service_restart': 'Dienst neu gestartet',
}

# ---------------------------------------------------------------------------
# Aktions-Historie: append-only Liste signifikanter Ereignisse (Neustarts, Reboots) für die
# "Letzte Aktionen"-Anzeige im Status-Tab und die volle Historie im Log-Tab. Auf 200 Einträge
# gedeckelt, damit state.json nicht unbegrenzt wächst. Jede Aktion wird zusätzlich SOFORT als
# eigenes MQTT-Event veröffentlicht (nicht erst beim nächsten Zyklus) – für Loxone-Benach-
# richtigungen in Echtzeit statt erst nach dem nächsten Heartbeat.
# ---------------------------------------------------------------------------
def log_action(state, action, success=True, detail=''):
    now = time.time()
    entry = {
        'epoch': now,
        'time': fmt(now),
        'action': action,
        'success': bool(success),
        'detail': detail,
    }
    action_log = state.setdefault('action_log', [])
    action_log.append(entry)
    if len(action_log) > 200:
        del action_log[:len(action_log) - 200]
    mqtt_publish_event(entry)

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
# Mindest-Ausfalldauer vor Auto-Heal: gemeinsame Basis für Funktion 1/4/5. Verwaltet einen
# 'seit wann anhaltend ungesund'-Zeitstempel in state[key]. Bei unhealthy=False wird der
# Zeitstempel zurückgesetzt (Zustand wieder gesund) und 0.0 zurückgegeben. Standardwert für
# alle neuen Schwellen ist 0 Minuten (= sofort) – identisch zum bisherigen, ungebremsten
# Verhalten, damit sich für bestehende Installationen nichts ändert, sofern der Nutzer nicht
# selbst eine Wartezeit einstellt.
# ---------------------------------------------------------------------------
def _unhealthy_elapsed_min(state, key, unhealthy, now):
    if not unhealthy:
        state[key] = 0
        return 0.0
    since = state.get(key) or 0
    if since <= 0:
        since = now
        state[key] = since
    return (now - since) / 60

# ---------------------------------------------------------------------------
# MQTT – rein informative Statusveröffentlichung, one-shot pro Zyklus.
# Bewusst KEINE dauerhafte Verbindung: der Watchdog läuft nur alle paar Minuten,
# eine Kurzverbindung pro Zyklus (connect → publish → disconnect) vermeidet die
# gesamte Reconnect-/RC=7-Komplexität dauerhafter MQTT-Clients vollständig.
# Fehlschläge sind nicht kritisch – die Watchdog-Kernfunktion hängt nicht von MQTT ab.
# Zugangsdaten (RESOLVED_MQTT_*) wurden bereits beim Start aufgelöst, siehe oben.
# ---------------------------------------------------------------------------
def mqtt_publish_event(entry):
    """Veröffentlicht eine einzelne Aktion SOFORT (nicht retained, eigene Kurzverbindung) statt
    erst beim nächsten Hauptschleifen-Zyklus über mqtt_publish_status() – damit eine Loxone-
    Benachrichtigung bei jedem Neustart/Reboot in Echtzeit ausgelöst werden kann, nicht erst
    Minuten später. Fehlschläge sind unkritisch, wie bei mqtt_publish_status()."""
    if not (MQTT_ENABLED and MQTT_OK):
        return
    try:
        broker, port = RESOLVED_MQTT_BROKER, RESOLVED_MQTT_PORT
        auth = {'username': RESOLVED_MQTT_USER, 'password': RESOLVED_MQTT_PASS} if RESOLVED_MQTT_USER else None
        payload = json.dumps({
            'action':  entry['action'],
            'label':   ACTION_LABELS.get(entry['action'], entry['action']),
            'success': entry['success'],
            'detail':  entry['detail'],
            'time':    entry['time'],
            'epoch':   int(entry['epoch']),
        }, ensure_ascii=False)
        mqtt_publish_mod.single(
            f'{TOPIC_PREFIX}/event', payload=payload, hostname=broker, port=port, auth=auth,
            client_id=f'HitWatch4Lox-{socket.gethostname()}-evt', qos=0, retain=False,
        )
    except Exception as e:
        log.warning(f'MQTT: Event-Veröffentlichung fehlgeschlagen (unkritisch): {e}')

def mqtt_publish_status(state):
    if not (MQTT_ENABLED and MQTT_OK):
        return
    try:
        broker, port = RESOLVED_MQTT_BROKER, RESOLVED_MQTT_PORT
        auth = {'username': RESOLVED_MQTT_USER, 'password': RESOLVED_MQTT_PASS} if RESOLVED_MQTT_USER else None
        msgs = [
            {'topic': f'{TOPIC_PREFIX}/health',                   'payload': state.get('health', 'green'),                      'retain': True},
            {'topic': f'{TOPIC_PREFIX}/health_detail',            'payload': state.get('health_detail', 'Alles OK'),            'retain': True},
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
        if F5_ENABLED:
            if F5_DISK_MONITOR:
                msgs.append({'topic': f'{TOPIC_PREFIX}/diag/disk_percent',   'payload': str(state.get('diag_disk_percent', '') or ''),   'retain': True})
            if F5_MEMORY_MONITOR:
                msgs.append({'topic': f'{TOPIC_PREFIX}/diag/memory_percent', 'payload': str(state.get('diag_memory_percent', '') or ''), 'retain': True})
            if F5_TEMP_MONITOR and state.get('diag_temp_available'):
                msgs.append({'topic': f'{TOPIC_PREFIX}/diag/temp_c',         'payload': str(state.get('diag_temp_c', '') or ''),         'retain': True})
            if F5_INTERNET_MONITOR:
                msgs.append({'topic': f'{TOPIC_PREFIX}/diag/internet_ok',    'payload': '1' if state.get('diag_internet_ok') else '0',   'retain': True})
            if F5_TIME_MONITOR:
                msgs.append({'topic': f'{TOPIC_PREFIX}/diag/time_synced',    'payload': '1' if state.get('diag_time_synced') else '0',   'retain': True})
        # publish.multiple() kennt anders als publish.single() KEIN globales 'qos'-Argument –
        # QoS wird stattdessen pro Nachricht über einen 'qos'-Key im jeweiligen Dict gesetzt
        # (hier nicht gesetzt, Default ist dann 0 je Nachricht). Ein fälschlich übergebenes
        # 'qos=0' als Funktionsargument führte zu einem TypeError bei JEDER Veröffentlichung.
        mqtt_publish_mod.multiple(
            msgs, hostname=broker, port=port, auth=auth,
            client_id=f'HitWatch4Lox-{socket.gethostname()}',
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
    # "0.0" statt time.time(): erzwingt dass F1/F4 beim allerersten Tick sofort laufen,
    # unabhängig von ihrem jeweiligen Intervall (kein Warten auf den ersten vollen Zyklus).
    _last_f1_run = 0.0
    _last_f4_run = 0.0
    _last_f5_run = 0.0
    # "Sensor/Tool nicht vorhanden" (z.B. kein thermal_zone, kein timedatectl) ist ein
    # dauerhafter Hardware-/Systemzustand, kein wiederkehrendes Problem wie ein toter Dienst –
    # daher nur EINMAL pro Daemon-Lauf geloggt (Diagnose-Zweck), nicht bei jedem F5-Zyklus neu.
    _temp_unavailable_logged = False
    _time_unavailable_logged = False
    _time_no_ntp_logged = False

    log.info(
        f'HitWatch4Lox Daemon gestartet (Version {DAEMON_VERSION}, PID {os.getpid()}) – '
        f'F1={F1_ENABLED} F2={F2_ENABLED} F3={F3_ENABLED} F4={F4_ENABLED} F5={F5_ENABLED} '
        f'MQTT={MQTT_ENABLED and MQTT_OK}'
    )

    while True:
        try:
            now = time.time()

            # ---------------- Funktion 1: Netbird-Dienst-Watchdog ----------------
            # Eigenes Intervall (CHECK_INTERVAL) statt bei jedem Loop-Tick zu laufen – der Tick
            # kann durch Funktion 4 kürzer sein als F1s eigenes Prüfintervall. 'status' wird
            # bewusst NUR hier gesetzt (nicht mehr pauschal jeden Tick auf 'OK' zurückgesetzt) –
            # sonst würde ein erkannter Fehler schon vor dem nächsten F1-Lauf wieder verschwinden.
            if F1_ENABLED and (now - _last_f1_run >= CHECK_INTERVAL):
                _last_f1_run = now
                state['status'] = 'OK'
                result = check_netbird()
                state['last_check_epoch']   = now
                state['last_check']         = fmt(now)
                state['netbird_connected']  = result['connected']
                state['netbird_management'] = result['management']
                state['netbird_signal']     = result['signal']

                if result['error']:
                    state['status'] = f"Netbird-Statusabfrage fehlgeschlagen: {result['error']}"
                    log.error(state['status'])

                netbird_unhealthy_min = _unhealthy_elapsed_min(
                    state, 'netbird_unhealthy_since_epoch', not result['connected'], now
                )
                if not result['connected']:
                    if netbird_unhealthy_min < F1_UNHEALTHY_MIN:
                        log.warning(
                            f"Netbird nicht verbunden (Management={result['management']}, "
                            f"Signal={result['signal']}) – seit {netbird_unhealthy_min:.0f} min, "
                            f'warte auf Mindest-Ausfalldauer ({F1_UNHEALTHY_MIN} min) vor Neustart'
                        )
                        _prev_connected = False
                    else:
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
                        # Fenster neu starten statt bei jedem weiteren Zyklus sofort wieder
                        # einzugreifen – gibt dem Neustart Zeit zu wirken (nur relevant bei
                        # F1_UNHEALTHY_MIN > 0, Standard 0 restartet weiterhin sofort).
                        state['netbird_unhealthy_since_epoch'] = now

                        time.sleep(RESTART_WAIT)
                        result2 = check_netbird()
                        state['netbird_connected']  = result2['connected']
                        state['netbird_management'] = result2['management']
                        state['netbird_signal']     = result2['signal']

                        if result2['connected']:
                            log.info('Netbird nach Dienst-Neustart wieder verbunden')
                            state['netbird_unhealthy_since_epoch'] = 0
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
                    _catch_window_s = max(600, LOOP_TICK * 2)
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
            # Eigenes Intervall (F4_CHECK_INTERVAL, Standard 60s) statt F1s CHECK_INTERVAL.
            if F4_ENABLED and (now - _last_f4_run >= F4_CHECK_INTERVAL):
                _last_f4_run = now
                state['mqtt_watchdog_last_check_epoch'] = now
                state['mqtt_watchdog_last_check']       = fmt(now)
                m = get_service_state(F4_MOSQUITTO_SERVICE)
                tcp_ok = tcp_check(F4_MOSQUITTO_HOST, F4_MOSQUITTO_PORT)
                mosq_healthy = m['healthy'] and tcp_ok
                state['mosquitto_active_state'] = m['active_state']
                state['mosquitto_sub_state']    = m['sub_state']
                state['mosquitto_tcp_ok']       = tcp_ok
                state['mosquitto_healthy']      = mosq_healthy
                mosq_unhealthy_min = _unhealthy_elapsed_min(
                    state, 'mosquitto_unhealthy_since_epoch', not mosq_healthy, now
                )
                if not mosq_healthy:
                    waiting = F4_MOSQUITTO_AUTORESTART and mosq_unhealthy_min < F4_MOSQUITTO_UNHEALTHY_MIN
                    log.warning(
                        f"Mosquitto ({F4_MOSQUITTO_SERVICE}) nicht gesund – Status: "
                        f"{m['active_state']}/{m['sub_state']}, TCP {F4_MOSQUITTO_HOST}:{F4_MOSQUITTO_PORT} "
                        f"{'erreichbar' if tcp_ok else 'NICHT erreichbar'}"
                        + (f' – seit {mosq_unhealthy_min:.0f} min, warte auf Mindest-Ausfalldauer ({F4_MOSQUITTO_UNHEALTHY_MIN} min)'
                           if waiting else
                           (' – starte Dienst neu...' if F4_MOSQUITTO_AUTORESTART else ' – Autorestart deaktiviert, kein Neustart.'))
                    )
                    if F4_MOSQUITTO_AUTORESTART and not waiting:
                        ok = restart_service(F4_MOSQUITTO_SERVICE)
                        state['mosquitto_last_restart_epoch'] = now
                        state['mosquitto_last_restart']       = fmt(now)
                        state['mosquitto_restart_count']      = int(state.get('mosquitto_restart_count', 0) or 0) + (1 if ok else 0)
                        log_action(state, 'mosquitto_restart', success=ok)
                        state['mosquitto_unhealthy_since_epoch'] = now

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
                gw_unhealthy_min = _unhealthy_elapsed_min(
                    state, 'gateway_unhealthy_since_epoch', not gw_healthy, now
                )
                if not gw_healthy:
                    # Wie bei Funktion 1: "läuft" allein reicht nicht – ein Prozess der lebt aber
                    # laut eigener Selbstauskunft nicht mit Mosquitto verbunden ist, gilt ebenso
                    # als ungesund und löst (bei aktivem Autorestart) einen Neustart aus.
                    reason_txt = 'läuft nicht' if not g['running'] else f"läuft, aber nicht mit Mosquitto verbunden ({state['gateway_broker_detail']})"
                    waiting = F4_GATEWAY_AUTORESTART and gw_unhealthy_min < F4_GATEWAY_UNHEALTHY_MIN
                    log.warning(
                        f"MQTT-Gateway ({F4_GATEWAY_PATTERN}) {reason_txt}"
                        + (f' – seit {gw_unhealthy_min:.0f} min, warte auf Mindest-Ausfalldauer ({F4_GATEWAY_UNHEALTHY_MIN} min)'
                           if waiting else
                           (' – beende Prozess, LoxBerry startet Kern-Daemons üblicherweise selbst neu...'
                            if F4_GATEWAY_AUTORESTART else ' – Autorestart deaktiviert, kein Neustart.'))
                    )
                    if F4_GATEWAY_AUTORESTART and not waiting:
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
                        state['gateway_unhealthy_since_epoch'] = now
                        if not ok:
                            log.error('MQTT-Gateway nach Beenden nicht automatisch neu gestartet – bitte manuell prüfen (z.B. LoxBerry neu starten)')

            # ---------------- Funktion 5: System-Diagnose ----------------
            # Eigenes Intervall (F5_CHECK_INTERVAL, Standard 120s). Jedes Thema ist einzeln per
            # Monitor-Flag steuerbar; die drei Auto-Heal-Aktionen (Netzwerk, Zeit-Sync, weitere
            # Kerndienste) sind bewusst restart-basiert und daher nur eigenständig schaltbar.
            if F5_ENABLED and (now - _last_f5_run >= F5_CHECK_INTERVAL):
                _last_f5_run = now
                state['diag_last_check_epoch'] = now
                state['diag_last_check']       = fmt(now)

                if F5_DISK_MONITOR:
                    d = check_disk_usage()
                    state['diag_disk_percent'] = d.get('percent')
                    lvl = 'unknown'
                    if d['ok']:
                        lvl = 'crit' if d['percent'] >= F5_DISK_CRIT_PCT else ('warn' if d['percent'] >= F5_DISK_WARN_PCT else 'ok')
                        if lvl != 'ok':
                            log.warning(f"Speicherplatz {'kritisch' if lvl == 'crit' else 'knapp'}: {d['percent']}% belegt auf /")
                    state['diag_disk_level'] = lvl

                if F5_MEMORY_MONITOR:
                    mem = check_memory_usage()
                    state['diag_memory_percent'] = mem.get('percent')
                    lvl = 'unknown'
                    if mem['ok']:
                        lvl = 'warn' if mem['percent'] >= F5_MEMORY_WARN_PCT else 'ok'
                        if lvl == 'warn':
                            log.warning(f"RAM-Auslastung hoch: {mem['percent']}%")
                    state['diag_memory_level'] = lvl

                if F5_TEMP_MONITOR:
                    t = check_cpu_temperature()
                    state['diag_temp_available'] = t['ok']
                    state['diag_temp_c'] = t.get('temp_c')
                    lvl = 'unknown'
                    if t['ok']:
                        lvl = 'crit' if t['temp_c'] >= F5_TEMP_CRIT_C else ('warn' if t['temp_c'] >= F5_TEMP_WARN_C else 'ok')
                        if lvl != 'ok':
                            log.warning(f"CPU-Temperatur {'kritisch' if lvl == 'crit' else 'hoch'}: {t['temp_c']}°C")
                        _temp_unavailable_logged = False
                    elif not _temp_unavailable_logged:
                        log.warning(f"CPU-Temperatur nicht ermittelbar: {t.get('error', 'unbekannter Grund')}")
                        _temp_unavailable_logged = True
                    state['diag_temp_level'] = lvl

                if F5_INTERNET_MONITOR:
                    inet_ok = tcp_check(F5_INTERNET_HOST, F5_INTERNET_PORT, timeout=5)
                    state['diag_internet_ok'] = inet_ok
                    inet_unhealthy_min = _unhealthy_elapsed_min(
                        state, 'diag_internet_unhealthy_since_epoch', not inet_ok, now
                    )
                    if not inet_ok:
                        waiting = F5_INTERNET_AUTOHEAL and inet_unhealthy_min < F5_INTERNET_UNHEALTHY_MIN
                        log.warning(
                            f'Internet nicht erreichbar (TCP {F5_INTERNET_HOST}:{F5_INTERNET_PORT})'
                            + (f' – seit {inet_unhealthy_min:.0f} min, warte auf Mindest-Ausfalldauer ({F5_INTERNET_UNHEALTHY_MIN} min)'
                               if waiting else
                               (' – starte Netzwerk neu...' if F5_INTERNET_AUTOHEAL else ' – Auto-Heal deaktiviert, kein Eingriff.'))
                        )
                        if F5_INTERNET_AUTOHEAL and not waiting:
                            ok = restart_networking()
                            state['diag_internet_last_restart_epoch'] = now
                            state['diag_internet_last_restart']       = fmt(now)
                            state['diag_internet_restart_count']      = int(state.get('diag_internet_restart_count', 0) or 0) + (1 if ok else 0)
                            log_action(state, 'diag_internet_restart', success=ok)
                            state['diag_internet_unhealthy_since_epoch'] = now

                if F5_TIME_MONITOR:
                    ts = check_time_sync()
                    state['diag_time_synced'] = ts.get('synced')
                    state['diag_time_ntp_present'] = ts.get('ntp_present', True)
                    if not ts['ok']:
                        if not _time_unavailable_logged:
                            log.warning(
                                'Zeit-Synchronisationsstatus nicht ermittelbar (timedatectl '
                                f"lieferte keinen Wert): {ts.get('error', 'unbekannter Grund')}"
                            )
                            _time_unavailable_logged = True
                        state['diag_time_unsynced_since_epoch'] = 0  # nicht beurteilbar, kein Timer
                    else:
                        _time_unavailable_logged = False
                        if ts['synced'] is False and not ts.get('ntp_present', True):
                            # Live-Fund: manche LoxBerry-Installationen haben GAR KEINEN NTP-Client
                            # (systemd-timesyncd/chrony/ntp allesamt inaktiv/nicht installiert,
                            # 'timedatectl status' zeigt 'NTP service: n/a'). Kann sowohl ein
                            # Container mit geteilter Host-Uhr sein (unproblematisch) ALS AUCH
                            # echte Hardware ohne eingerichtetes NTP (Zeit kann über Wochen/Monate
                            # driften, z.B. Raspberry Pi ohne RTC) – von innerhalb des Gastsystems
                            # NICHT zuverlässig unterscheidbar. Bewusst weiterhin als Warnung
                            # sichtbar (nur einmalig geloggt, kein Dauerspam) statt das fälschlich
                            # als unproblematisch anzunehmen. Kein Auto-Heal-Versuch (ein Neustart
                            # eines nicht vorhandenen Dienstes wäre wirkungslos).
                            state['diag_time_unsynced_since_epoch'] = 0
                            if not _time_no_ntp_logged:
                                log.warning(
                                    'Kein NTP-Client installiert (systemd-timesyncd/chrony/ntp '
                                    'nicht vorhanden) – auf einem Container mit geteilter Host-Uhr '
                                    'unproblematisch, auf echter Hardware sollte ein NTP-Client '
                                    'eingerichtet werden, sonst kann die Zeit langfristig driften.'
                                )
                                _time_no_ntp_logged = True
                        elif ts['synced'] is False:
                            _time_no_ntp_logged = False
                            # systemd-timesyncd ist ein dauerhaft laufender Dienst, der von sich aus
                            # periodisch erneut versucht zu synchronisieren – ein kurzer Ausschlag
                            # (z.B. direkt nach einem Neustart, oder ein kurzer Netz-Hänger) löst
                            # sich normalerweise von SELBST, ohne dass ein Neustart nötig wäre. Ein
                            # sofortiger Auto-Heal bei jedem einzelnen Prüfzyklus wäre daher unnötig
                            # aggressiv. Erst wenn der Zustand über F5_TIME_UNSYNCED_MIN Minuten
                            # anhält (Standard 10 min), greift Auto-Heal tatsächlich ein.
                            since = state.get('diag_time_unsynced_since_epoch') or 0
                            if since <= 0:
                                since = now
                                state['diag_time_unsynced_since_epoch'] = since
                            unsynced_min = (now - since) / 60
                            if F5_TIME_AUTOHEAL and unsynced_min >= F5_TIME_UNSYNCED_MIN:
                                log.warning(
                                    f'Systemzeit seit {unsynced_min:.0f} min nicht synchronisiert '
                                    '(NTP) – starte Zeit-Synchronisation neu...'
                                )
                                ok = sync_time_now()
                                state['diag_time_last_restart_epoch'] = now
                                state['diag_time_last_restart']       = fmt(now)
                                state['diag_time_restart_count']      = int(state.get('diag_time_restart_count', 0) or 0) + (1 if ok else 0)
                                log_action(state, 'diag_time_restart', success=ok)
                                # Fenster neu starten statt bei jedem weiteren Zyklus sofort wieder
                                # einzugreifen – gibt dem Neustart Zeit zu wirken.
                                state['diag_time_unsynced_since_epoch'] = now
                            else:
                                log.warning(
                                    f'Systemzeit seit {unsynced_min:.0f} min nicht synchronisiert (NTP)'
                                    + (f' – noch unter der {F5_TIME_UNSYNCED_MIN}-min-Schwelle, warte ab'
                                       if F5_TIME_AUTOHEAL else ' – Auto-Heal deaktiviert, kein Eingriff.')
                                )
                        else:
                            _time_no_ntp_logged = False
                            state['diag_time_unsynced_since_epoch'] = 0

                if F5_SERVICES_MONITOR and F5_SERVICES_LIST:
                    diag_services = state.setdefault('diag_services', {})
                    for svc in F5_SERVICES_LIST:
                        s = get_service_state(svc)
                        prev = diag_services.get(svc, {})
                        # Eigener Ausfall-Zeitstempel PRO Dienst (nicht ein globaler Key wie bei
                        # den übrigen Checks) – jeder überwachte Dienst hat seine eigene
                        # Mindest-Ausfalldauer-Uhr, unabhängig von den anderen.
                        unhealthy_since = 0 if s['healthy'] else (int(prev.get('unhealthy_since_epoch', 0) or 0) or now)
                        unhealthy_min = (now - unhealthy_since) / 60 if unhealthy_since else 0.0
                        entry = {
                            'active_state': s['active_state'], 'sub_state': s['sub_state'], 'healthy': s['healthy'],
                            'restart_count':      int(prev.get('restart_count', 0) or 0),
                            'last_restart':       prev.get('last_restart', '–'),
                            'last_restart_epoch': int(prev.get('last_restart_epoch', 0) or 0),
                            'unhealthy_since_epoch': unhealthy_since,
                        }
                        if not s['healthy']:
                            waiting = F5_SERVICES_AUTOHEAL and unhealthy_min < F5_SERVICES_UNHEALTHY_MIN
                            log.warning(
                                f"Dienst {svc} nicht aktiv ({s['active_state']}/{s['sub_state']})"
                                + (f' – seit {unhealthy_min:.0f} min, warte auf Mindest-Ausfalldauer ({F5_SERVICES_UNHEALTHY_MIN} min)'
                                   if waiting else
                                   (' – starte neu...' if F5_SERVICES_AUTOHEAL else ' – Auto-Heal deaktiviert, kein Neustart.'))
                            )
                            if F5_SERVICES_AUTOHEAL and not waiting:
                                ok = restart_service(svc)
                                entry['restart_count']      = entry['restart_count'] + (1 if ok else 0)
                                entry['last_restart']       = fmt(now)
                                entry['last_restart_epoch'] = now
                                log_action(state, 'diag_service_restart', success=ok, detail=svc)
                                entry['unhealthy_since_epoch'] = now
                        diag_services[svc] = entry

            # ---------------- Health-Ampel ----------------
            # Fasst F1/F4/F5 zu einem Gesamtstatus zusammen – läuft jeden Tick (billig, liest nur
            # bereits berechneten State), damit die Ampel nie älter ist als die letzte Persistenz.
            _health, _problems = compute_health(state)
            state['health']        = _health
            state['health_detail'] = '; '.join(_problems) if _problems else 'Alles OK'

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

        time.sleep(LOOP_TICK)

if __name__ == '__main__':
    try:
        run()
    except Exception:
        with open(os.path.join(LOGDIR, 'crash.log'), 'a') as f:
            f.write(f'{datetime.now()} CRASH: {traceback.format_exc()}\n')
