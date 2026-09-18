<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

// ── State laden ──
$state = [];
$sf    = $lbpdatadir . '/state.json';
if (file_exists($sf)) $state = json_decode(file_get_contents($sf), true) ?? [];

// ── Config laden ──
$cfg = parse_ini_file($lbpconfigdir . '/hitwatch4lox.cfg', true) ?: [];
$f1_enabled = ($cfg['WATCHDOG']['ENABLED'] ?? '1') == '1';
$f2_enabled = $f1_enabled && ($cfg['REBOOT_ESCALATION']['ENABLED'] ?? '0') == '1';
$f3_enabled = ($cfg['SCHEDULED_REBOOT']['ENABLED'] ?? '0') == '1';
$check_interval = (int)($cfg['WATCHDOG']['CHECK_INTERVAL'] ?? 300);
$cooldown_hours  = (int)($cfg['REBOOT_ESCALATION']['COOLDOWN_HOURS'] ?? 6);
$weekday_names = [1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag'];
$f3_weekdays = array_filter(array_map('trim', explode(',', $cfg['SCHEDULED_REBOOT']['WEEKDAYS'] ?? '7')));
$f3_time    = $cfg['SCHEDULED_REBOOT']['TIME'] ?? '04:00';
$f3_every_n = (int)($cfg['SCHEDULED_REBOOT']['EVERY_N'] ?? 1);
$f4_enabled = ($cfg['MQTT_WATCHDOG']['ENABLED'] ?? '0') == '1';
$f4_mosq_autorestart = $f4_enabled && ($cfg['MQTT_WATCHDOG']['MOSQUITTO_AUTORESTART'] ?? '1') == '1';
$f4_gw_autorestart   = $f4_enabled && ($cfg['MQTT_WATCHDOG']['GATEWAY_AUTORESTART'] ?? '0') == '1';
$f4_mosq_service = $cfg['MQTT_WATCHDOG']['MOSQUITTO_SERVICE'] ?? 'mosquitto';
$f4_gw_pattern   = $cfg['MQTT_WATCHDOG']['GATEWAY_PROCESS_PATTERN'] ?? 'mqttgateway.pl';

// ── Daemon-Status (PID-Check + Prozessname) ──
$pidfile        = $lbplogdir . '/daemon.pid';
$pid            = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
$daemon_running = false;
if ($pid && is_numeric($pid)) {
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline !== false) {
        $daemon_running = strpos($cmdline, 'hitwatch4lox_daemon') !== false
                       || strpos($cmdline, 'hitwatch4lox') !== false;
    }
}
// Enable-Marker: gesetzt = Autostart/Watchdog aktiv.
$daemon_enabled = file_exists($lbpdatadir . '/daemon.enabled');

// Liest Tail der Log-Datei und gibt 'green'/'orange'/'red'/'none' zurück.
function log_health_check(?string $logfile): string {
    if (!$logfile || !file_exists($logfile) || !is_readable($logfile)) return 'none';
    $cutoff    = time() - 1800;
    $has_error = false;
    $has_warn  = false;
    $fp = @fopen($logfile, 'rb');
    if (!$fp) return 'none';
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    fseek($fp, -min($size, 61440), SEEK_END);
    $chunk = fread($fp, 61440);
    fclose($fp);
    foreach (explode("\n", $chunk) as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) continue;
        if (strtotime($m[1]) < $cutoff) continue;
        if (strpos($line, ' ERROR') !== false || strpos($line, '<ERR>') !== false) {
            $has_error = true; break;
        }
        if (strpos($line, ' WARNING') !== false || strpos($line, '<WARNING>') !== false) {
            $has_warn = true;
        }
    }
    if ($has_error) return 'red';
    if ($has_warn)  return 'orange';
    return 'green';
}

$_ptr  = $lbplogdir . '/daemon.log.current';
$_clog = file_exists($_ptr) ? trim(file_get_contents($_ptr)) : null;
if ($_clog && !file_exists($_clog)) $_clog = null;
if (!$_clog) {
    $_logs = glob($lbpdatadir . '/logs/*.log') ?: [];
    if (!$_logs) $_logs = glob($lbplogdir . '/*.log') ?: [];
    if ($_logs) { usort($_logs, fn($a,$b) => filemtime($b) - filemtime($a)); $_clog = $_logs[0]; }
}
$log_health = $daemon_running ? log_health_check($_clog) : 'none';

$connected   = (bool)($state['netbird_connected'] ?? false);
$management  = $state['netbird_management'] ?? '?';
$signal_    = $state['netbird_signal'] ?? '?';
$status      = $state['status'] ?? 'OK';
$last_check  = $state['last_check'] ?? '–';
$last_check_epoch = (int)($state['last_check_epoch'] ?? 0);
$last_restart = $state['last_restart'] ?? '–';
$restart_count = (int)($state['restart_count_total'] ?? 0);
$last_reboot = $state['last_auto_reboot'] ?? '–';
$last_reboot_reason = $state['last_auto_reboot_reason'] ?? '';
$last_reboot_epoch = (int)($state['last_auto_reboot_epoch'] ?? 0);

$cooldown_remaining_s = 0;
if ($last_reboot_epoch > 0) {
    $cooldown_remaining_s = max(0, $cooldown_hours * 3600 - (time() - $last_reboot_epoch));
}

// Staleness-Check
$age      = time() - $last_check_epoch;
$is_stale = ($f1_enabled && $daemon_running && $last_check_epoch > 0 && $age > ($check_interval * 2 + 60));

$action_log = array_reverse($state['action_log'] ?? []); // neueste zuerst

render_header('app_status');
?>

<!-- ================================================================
     NETBIRD-STATUS
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🐦 <?= h($L['MAIN.NETBIRD_STATUS'] ?? 'Netbird-Status') ?></span>
        <span class="sl-badge <?= $connected ? 'ok' : 'err' ?>">
            <?= $connected ? ($L['MAIN.CONNECTED'] ?? 'Verbunden') : ($L['MAIN.DISCONNECTED'] ?? 'Nicht verbunden') ?>
        </span>
    </div>
    <div class="sl-card-body">
        <?php if (!$f1_enabled): ?>
        <div class="sl-flash warn" style="margin-bottom:0.7rem">
            ⏸️ Funktion 1 (Dienst-Watchdog) ist deaktiviert – es wird kein Status geprüft.
            <a href="app_settings.php" style="color:inherit;font-weight:700;margin-left:0.4rem">→ Aktivieren</a>
        </div>
        <?php endif; ?>
        <ul class="sl-info-list">
            <li><span class="sl-info-key">Management-Verbindung</span>
                <span class="sl-info-val <?= stripos($management,'connected')!==false ? 'ok' : 'alert' ?>"><?= h($management) ?></span></li>
            <li><span class="sl-info-key">Signal-Verbindung</span>
                <span class="sl-info-val <?= stripos($signal_,'connected')!==false ? 'ok' : 'alert' ?>"><?= h($signal_) ?></span></li>
            <li><span class="sl-info-key">Letzte Prüfung</span>
                <span class="sl-info-val <?= $is_stale ? 'alert' : '' ?>"><?= h($last_check) ?></span></li>
            <li><span class="sl-info-key">Prüfintervall</span> <span class="sl-info-val"><?= (int)$check_interval ?> s</span></li>
        </ul>
        <?php if ($is_stale): ?>
        <div class="sl-flash err" style="margin-top:0.6rem">
            ⚠️ Seit <?= round($age/60) ?> min keine Prüfung – Watchdog startet nach 20 min automatisch neu
        </div>
        <?php endif; ?>
        <?php if ($status !== 'OK'): ?>
        <div class="sl-flash err" style="margin-top:0.6rem"><?= h($status) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ================================================================
     WATCHDOG-AKTIONEN
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🔁 <?= h($L['MAIN.WATCHDOG_ACTIONS'] ?? 'Watchdog-Aktionen') ?></span>
    </div>
    <div class="sl-card-body">
        <ul class="sl-info-list">
            <li><span class="sl-info-key">Letzter Dienst-Neustart</span> <span class="sl-info-val"><?= h($last_restart) ?></span></li>
            <li><span class="sl-info-key">Dienst-Neustarts gesamt</span> <span class="sl-info-val"><?= $restart_count ?></span></li>
            <li><span class="sl-info-key">Letzter automatischer Reboot</span> <span class="sl-info-val <?= $last_reboot_epoch ? 'warn' : '' ?>"><?= h($last_reboot) ?></span></li>
            <?php if ($last_reboot_reason): [$_ric, $_rlb] = hw4l_action_label($last_reboot_reason); ?>
            <li><span class="sl-info-key">Grund</span> <span class="sl-info-val"><?= $_ric ?> <?= h($_rlb) ?></span></li>
            <?php endif; ?>
        </ul>
        <?php if ($cooldown_remaining_s > 0): ?>
        <div class="sl-flash warn" style="margin-top:0.6rem">
            🧊 Reboot-Cooldown aktiv – noch <?= round($cooldown_remaining_s/3600, 1) ?> h
            (verhindert Boot-Loops falls das Problem nicht am LoxBerry liegt)
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================================================================
     LETZTE AKTIONEN
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">📋 Letzte Aktionen</span>
    </div>
    <div class="sl-card-body">
<?php if (empty($action_log)): ?>
        <p class="sl-hint">Noch keine Aktionen protokolliert.</p>
<?php else: ?>
        <ul class="sl-info-list">
<?php foreach (array_slice($action_log, 0, 8) as $entry):
    [$_aic, $_alb] = hw4l_action_label($entry['action'] ?? '');
    $_asuccess = (bool)($entry['success'] ?? true);
    $_atime = $entry['time'] ?? '–';
    $_adetail = $entry['detail'] ?? '';
?>
            <li>
                <span class="sl-info-key"><?= $_aic ?> <?= h($_alb) ?><?php if ($_adetail): ?><br><span style="font-size:0.72rem;color:var(--muted)"><?= h($_adetail) ?></span><?php endif; ?></span>
                <span class="sl-info-val <?= $_asuccess ? '' : 'alert' ?>"><?= h($_atime) ?><?= $_asuccess ? '' : ' ⚠' ?></span>
            </li>
<?php endforeach; ?>
        </ul>
        <p class="sl-hint" style="margin-top:0.6rem;text-align:right">
            <a href="app_log.php" style="color:inherit;font-weight:700">→ Vollständige Aktions-Historie ansehen</a>
        </p>
<?php endif; ?>
    </div>
</div>

<?php if ($f4_enabled): ?>
<!-- ================================================================
     MQTT-DIENSTE-STATUS
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">📡 MQTT-Dienste-Status</span>
    </div>
    <div class="sl-card-body">
<?php
    $m_active   = $state['mosquitto_active_state'] ?? '?';
    $m_sub      = $state['mosquitto_sub_state'] ?? '';
    $m_tcp      = (bool)($state['mosquitto_tcp_ok'] ?? false);
    $m_healthy  = (bool)($state['mosquitto_healthy'] ?? false);
    $m_restarts = (int)($state['mosquitto_restart_count'] ?? 0);
    $g_active   = $state['gateway_active_state'] ?? '?';
    $g_sub      = $state['gateway_sub_state'] ?? '';
    $g_healthy  = (bool)($state['gateway_healthy'] ?? false);
    $g_restarts = (int)($state['gateway_restart_count'] ?? 0);
    $g_checked  = (bool)($state['gateway_broker_checked'] ?? false);
    $g_linked   = (bool)($state['gateway_broker_linked'] ?? false);
?>
        <div class="sl-section-title">🦟 Mosquitto (<?= h($f4_mosq_service) ?>)</div>
        <ul class="sl-info-list">
            <li><span class="sl-info-key">Dienststatus</span>
                <span class="sl-info-val <?= $m_healthy ? 'ok' : 'alert' ?>"><?= h($m_active) ?><?= $m_sub ? ' / ' . h($m_sub) : '' ?></span></li>
            <li><span class="sl-info-key">TCP-Erreichbarkeit</span>
                <span class="sl-info-val <?= $m_tcp ? 'ok' : 'alert' ?>"><?= $m_tcp ? 'erreichbar' : 'nicht erreichbar' ?></span></li>
            <li><span class="sl-info-key">Automatischer Neustart</span>
                <span class="sl-info-val" style="color:<?= $f4_mosq_autorestart ? 'var(--green)' : 'var(--muted)' ?>"><?= $f4_mosq_autorestart ? 'An' : 'Aus' ?></span></li>
            <li><span class="sl-info-key">Neustarts gesamt</span> <span class="sl-info-val"><?= $m_restarts ?></span></li>
        </ul>
        <div class="sl-section-title">📡 MQTT-Gateway (<?= h($f4_gw_pattern) ?>)</div>
        <ul class="sl-info-list">
            <li><span class="sl-info-key">Prozess</span>
                <span class="sl-info-val <?= $g_healthy ? 'ok' : 'alert' ?>"><?= h($g_active) ?><?= $g_sub ? ' / ' . h($g_sub) : '' ?></span></li>
            <li><span class="sl-info-key">Verbindung zu Mosquitto</span>
                <span class="sl-info-val <?= $g_checked ? ($g_linked ? 'ok' : 'alert') : '' ?>">
                    <?= $g_checked ? ($g_linked ? 'verbunden' : 'nicht verbunden') : 'nicht prüfbar' ?>
                </span></li>
            <li><span class="sl-info-key">Automatischer Neustart</span>
                <span class="sl-info-val" style="color:<?= $f4_gw_autorestart ? 'var(--green)' : 'var(--muted)' ?>"><?= $f4_gw_autorestart ? 'An' : 'Aus' ?></span></li>
            <li><span class="sl-info-key">Neustarts gesamt</span> <span class="sl-info-val"><?= $g_restarts ?></span></li>
        </ul>
        <p class="sl-hint" style="margin-top:0.5rem">Mosquitto-Dienststatus direkt von <code>systemctl show</code> (ActiveState / SubState) –
            "activating" bedeutet der Dienst startet gerade. Das MQTT-Gateway ist kein systemd-Dienst
            (LoxBerry-Kern-Daemon) – Prozess-Erkennung über <code>pgrep</code>, die Verbindung zu
            Mosquitto liest den vom Gateway selbst veröffentlichten MQTT-Status
            (<code>&lt;Präfix&gt;/status</code>). Rein informativ – löst selbst keinen Neustart aus.</p>
    </div>
</div>
<?php endif; ?>

<!-- ================================================================
     FUNKTIONEN-ÜBERSICHT
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">⚙️ <?= h($L['MAIN.FUNCTIONS_OVERVIEW'] ?? 'Funktionen-Übersicht') ?></span>
    </div>
    <div class="sl-card-body">
        <div class="sl-stat-grid">
            <div class="sl-stat">
                <div class="sl-stat-val" style="font-size:0.95rem;color:<?= $f1_enabled ? 'var(--green)' : 'var(--muted)' ?>"><?= $f1_enabled ? 'An' : 'Aus' ?></div>
                <div class="sl-stat-lbl">F1 – Dienst-Watchdog</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" style="font-size:0.95rem;color:<?= $f2_enabled ? 'var(--green)' : 'var(--muted)' ?>"><?= $f2_enabled ? 'An' : 'Aus' ?></div>
                <div class="sl-stat-lbl">F2 – Reboot-Eskalation</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" style="font-size:0.95rem;color:<?= $f3_enabled ? 'var(--green)' : 'var(--muted)' ?>"><?= $f3_enabled ? 'An' : 'Aus' ?></div>
                <div class="sl-stat-lbl">F3 – Automatischer Reboot</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" style="font-size:0.95rem;color:<?= $f4_enabled ? 'var(--green)' : 'var(--muted)' ?>"><?= $f4_enabled ? 'An' : 'Aus' ?></div>
                <div class="sl-stat-lbl">F4 – MQTT-Watchdog</div>
            </div>
        </div>
        <?php if ($f3_enabled):
            $f3_day_labels = array_map(fn($w) => $weekday_names[(int)$w] ?? '?', $f3_weekdays);
        ?>
        <p class="sl-hint">Automatischer Reboot: <b><?= h(implode(', ', $f3_day_labels)) ?></b> um <b><?= h($f3_time) ?></b> Uhr
            (<?= $f3_every_n <= 1 ? 'jedes Mal' : 'nur alle ' . $f3_every_n . 'x' ?>).</p>
        <?php endif; ?>
        <a href="app_settings.php" class="sl-btn secondary sm">⚙️ Zu den Einstellungen</a>
    </div>
</div>

<!-- ================================================================
     DAEMON STATUS & STEUERUNG
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🔧 <?= h($L['MAIN.TITLE'] ?? 'HitWatch4Lox') ?> <?= h($L['MAIN.STATUS'] ?? 'Status') ?></span>
        <?php
        $log_titles = [
            'green'  => 'Log OK – keine Fehler/Warnungen in den letzten 30 min',
            'orange' => 'Warnungen in den letzten 30 min – Log prüfen',
            'red'    => 'Fehler in den letzten 30 min – Log prüfen',
            'none'   => '',
        ];
        if ($log_health !== 'none'):
        ?>
        <span class="sl-log-light <?= $log_health ?>" title="<?= $log_titles[$log_health] ?>"></span>
        <?php endif; ?>
        <span class="sl-badge <?= $daemon_running ? 'ok' : 'err' ?>">
            <?= $daemon_running ? ($L['MAIN.DAEMON_RUNNING'] ?? 'Läuft') : ($L['MAIN.DAEMON_STOPPED'] ?? 'Gestoppt') ?>
        </span>
    </div>
    <div class="sl-card-body">
        <div class="sl-daemon-row">
            <div>
                <div class="sl-daemon-name">
                    <span class="sl-status-dot <?= $daemon_running ? 'on' : 'off' ?>"></span>
                    Daemon
                    <?= $daemon_running ? ($L['MAIN.DAEMON_RUNNING'] ?? 'läuft') : ($L['MAIN.DAEMON_STOPPED'] ?? 'gestoppt') ?>
                </div>
                <div class="sl-daemon-meta">
                    <?php if (!$daemon_running && !$daemon_enabled): ?>
                    <span class="stale">⏸️ Deaktiviert – manuell gestoppt. Kein Autostart, kein Watchdog. „Start" reaktiviert.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sl-daemon-btns">
<?php if ($daemon_running): ?>
                <button id="btn-restart" class="sl-btn primary sm">↺ Restart</button>
                <button class="sl-btn danger sm" onclick="if(confirm('Daemon wirklich stoppen? Autostart nach Reboot und Watchdog werden deaktiviert – der Daemon startet erst wieder wenn du hier „Start" drückst.')) fetch('ajax.php?action=stop_json').then(function(){setTimeout(function(){location.reload()},1500)})">■ Stop</button>
<?php else: ?>
                <button id="btn-start" class="sl-btn success sm">▶ Start</button>
<?php endif; ?>
<?php if ($_clog): ?>
                <a href="/admin/system/tools/logfile.cgi?logfile=<?= urlencode($_clog) ?>&package=<?= urlencode($lbpplugindir) ?>&name=Daemon&header=html&format=template"
                   target="_blank" class="sl-btn secondary sm">📋 Log</a>
<?php endif; ?>
                <span id="daemon-action-status" style="font-size:0.75rem;color:#aaa;display:none"></span>
            </div>
        </div>
    </div>
</div>

<!-- Auto-Refresh Statuszeile -->
<p class="sl-hint" style="text-align:center;margin-top:0.5rem">
    <span id="refresh-status">⟳ Auto-Aktualisierung aktiv</span>
</p>

<script>
(function() {
    var knownEpoch   = <?= (int)$last_check_epoch ?>;
    var knownRunning = <?= $daemon_running ? 'true' : 'false' ?>;
    var statusEl     = document.getElementById('refresh-status');
    var actionEl     = document.getElementById('daemon-action-status');
    var failCount    = 0;
    var waitForStart = false;

    function checkForUpdate() {
        fetch('ajax.php?action=check_update', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                failCount = 0;
                var newData    = d.epoch && d.epoch > knownEpoch;
                var statusFlip = typeof d.running !== 'undefined' && d.running !== knownRunning;
                if (waitForStart && d.running) {
                    if (actionEl) actionEl.textContent = '✓ Daemon läuft – wird geladen…';
                    location.reload(); return;
                }
                if (newData || statusFlip) {
                    if (statusEl) statusEl.textContent = '⟳ ' + (statusFlip ? 'Status geändert' : 'Neue Daten') + ' – wird geladen…';
                    location.reload();
                } else {
                    var now = new Date().toLocaleTimeString('de-AT', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
                    if (statusEl) statusEl.textContent = '⟳ Zuletzt geprüft: ' + now;
                }
            })
            .catch(function() {
                failCount++;
                if (statusEl) statusEl.textContent = '⚠ Verbindungsunterbrechung (' + failCount + ')';
            });
    }

    function daemonAction(action, label) {
        if (actionEl) { actionEl.textContent = label; actionEl.style.display = 'inline'; }
        document.querySelectorAll('#btn-restart, #btn-start').forEach(function(b) { b.disabled = true; });
        fetch('ajax.php?action=' + action, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function() {
                waitForStart = true;
                var tries = 0;
                var poll = setInterval(function() {
                    tries++;
                    checkForUpdate();
                    if (tries >= 20) { clearInterval(poll); location.reload(); }
                }, 3000);
            })
            .catch(function() {
                if (actionEl) actionEl.textContent = '⚠ Fehler';
                document.querySelectorAll('#btn-restart, #btn-start').forEach(function(b) { b.disabled = false; });
            });
    }

    var btnRestart = document.getElementById('btn-restart');
    if (btnRestart) btnRestart.addEventListener('click', function(e) {
        e.preventDefault();
        daemonAction('restart_json', '↺ Neustart läuft…');
    });
    var btnStart = document.getElementById('btn-start');
    if (btnStart) btnStart.addEventListener('click', function(e) {
        e.preventDefault();
        if (!btnStart.disabled) daemonAction('start_json', '▶ Startet…');
    });

    setTimeout(checkForUpdate, 5000);
    setInterval(checkForUpdate, 30000);
})();
</script>

<?php render_footer(); ?>
