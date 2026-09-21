<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

// ── Config laden ──
$cfgfile = $lbpconfigdir . '/hitwatch4lox.cfg';
$cfg     = parse_ini_file($cfgfile, true) ?: [];
$use_lb  = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$saved   = false;
$err     = '';

// ── POST speichern ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== hw4l_csrf()) {
        $err = 'Ungültige Anfrage (CSRF-Fehler). Bitte Seite neu laden und erneut versuchen.';
    } else {
        $f1_en   = isset($_POST['f1_enabled']) ? '1' : '0';
        $f2_en   = isset($_POST['f2_enabled']) ? '1' : '0';
        $f3_en   = isset($_POST['f3_enabled']) ? '1' : '0';
        $f4_en   = isset($_POST['f4_enabled']) ? '1' : '0';
        $f4_mosq_auto = isset($_POST['f4_mosquitto_autorestart']) ? '1' : '0';
        $f4_gw_auto   = isset($_POST['f4_gateway_autorestart']) ? '1' : '0';
        $f5_en          = isset($_POST['f5_enabled']) ? '1' : '0';
        $f5_disk_mon    = isset($_POST['f5_disk_monitor']) ? '1' : '0';
        $f5_mem_mon     = isset($_POST['f5_memory_monitor']) ? '1' : '0';
        $f5_temp_mon    = isset($_POST['f5_temp_monitor']) ? '1' : '0';
        $f5_inet_mon    = isset($_POST['f5_internet_monitor']) ? '1' : '0';
        $f5_inet_heal   = isset($_POST['f5_internet_autoheal']) ? '1' : '0';
        $f5_time_mon    = isset($_POST['f5_time_monitor']) ? '1' : '0';
        $f5_time_heal   = isset($_POST['f5_time_autoheal']) ? '1' : '0';
        $f5_svc_mon     = isset($_POST['f5_services_monitor']) ? '1' : '0';
        $f5_svc_heal    = isset($_POST['f5_services_autoheal']) ? '1' : '0';
        $mqtt_en = isset($_POST['mqtt_enabled']) ? '1' : '0';
        $use_lb_new = isset($_POST['use_lb_mqtt']) ? '1' : '0';

        // Mosquitto-Dienstname: nur Buchstaben/Ziffern/._@- (wird an sudo systemctl restart
        // übergeben) – ungültige Eingaben fallen auf den Standardwert zurück statt einen Fehler
        // zu zeigen. Gateway ist kein systemd-Dienst (siehe unten) und läuft nie über sudo.
        $svc_re = '/^[A-Za-z0-9_.@-]{1,64}$/';
        $mosq_service = trim($_POST['f4_mosquitto_service'] ?? 'mosquitto');
        if (!preg_match($svc_re, $mosq_service)) $mosq_service = 'mosquitto';
        $mosq_host = strip_tags(trim($_POST['f4_mosquitto_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $mosq_port = max(1, min(65535, intval($_POST['f4_mosquitto_port'] ?? 1883)));

        // Gateway-Prozessmuster (pgrep -f) und MQTT-Status-Topic-Präfix: großzügigeres Muster,
        // da Skript-Pfade/-Namen erlaubt sein müssen; Mindestlänge 4 verhindert ein zu
        // unspezifisches pkill-Muster.
        $pattern_re = '/^[A-Za-z0-9_.\/-]{4,128}$/';
        $gw_pattern = trim($_POST['f4_gateway_pattern'] ?? 'mqttgateway.pl');
        if (!preg_match($pattern_re, $gw_pattern)) $gw_pattern = 'mqttgateway.pl';
        $gw_mqtt_prefix = trim($_POST['f4_gateway_mqtt_prefix'] ?? 'loxberry/mqttgateway', " \t\n\r\0\x0B/");
        if ($gw_mqtt_prefix === '') $gw_mqtt_prefix = 'loxberry/mqttgateway';

        // Funktion 5: Schwellwerte, Internet-Check-Ziel, Dienstliste (jeder Eintrag validiert
        // wie ein systemd-Dienstname – dieselbe Regel wie f4_mosquitto_service oben, da die
        // Namen an denselben Root-Helper "restart_service <name>" übergeben werden).
        $f5_disk_warn  = max(1, min(99,  intval($_POST['f5_disk_warn']   ?? 85)));
        $f5_disk_crit  = max($f5_disk_warn, min(100, intval($_POST['f5_disk_crit'] ?? 95)));
        $f5_mem_warn   = max(1, min(100, intval($_POST['f5_memory_warn'] ?? 90)));
        $f5_temp_warn  = max(1, min(120, intval($_POST['f5_temp_warn']   ?? 70)));
        $f5_temp_crit  = max($f5_temp_warn, min(120, intval($_POST['f5_temp_crit'] ?? 80)));
        $f5_inet_host  = strip_tags(trim($_POST['f5_internet_host'] ?? '1.1.1.1')) ?: '1.1.1.1';
        $f5_inet_port  = max(1, min(65535, intval($_POST['f5_internet_port'] ?? 53)));
        $f5_svc_list = [];
        foreach (explode(',', $_POST['f5_services_list'] ?? '') as $s) {
            $s = trim($s);
            if ($s !== '' && preg_match($svc_re, $s)) $f5_svc_list[] = $s;
        }
        $f5_svc_list_str = implode(',', $f5_svc_list);

        // Mehrere Wochentage: Checkboxen f3_weekday_1..f3_weekday_7 (ISO: 1=Mo .. 7=So)
        $weekdays = [];
        for ($i = 1; $i <= 7; $i++) {
            if (isset($_POST["f3_weekday_{$i}"])) $weekdays[] = $i;
        }
        if (empty($weekdays)) $weekdays = [7];
        $weekdays_str = implode(',', $weekdays);

        $time_raw = trim($_POST['f3_time'] ?? '04:00');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time_raw)) {
            $time_raw = '04:00';
        }
        $every_n = max(1, min(52, intval($_POST['f3_every_n'] ?? 1)));

        $c  = "[WATCHDOG]\n";
        $c .= "ENABLED={$f1_en}\n";
        $c .= 'CHECK_INTERVAL='       . max(60, min(3600, intval($_POST['check_interval']       ?? 300))) . "\n";
        $c .= 'RESTART_WAIT_SECONDS=' . max(5,  min(120,  intval($_POST['restart_wait_seconds'] ?? 20)))  . "\n\n";

        $c .= "[REBOOT_ESCALATION]\n";
        $c .= "ENABLED={$f2_en}\n";
        $c .= 'COOLDOWN_HOURS=' . max(1, min(72, intval($_POST['cooldown_hours'] ?? 6))) . "\n\n";

        $c .= "[SCHEDULED_REBOOT]\n";
        $c .= "ENABLED={$f3_en}\n";
        $c .= "WEEKDAYS={$weekdays_str}\n";
        $c .= "TIME={$time_raw}\n";
        $c .= "EVERY_N={$every_n}\n\n";

        $c .= "[MQTT_WATCHDOG]\n";
        $c .= "ENABLED={$f4_en}\n";
        $c .= 'CHECK_INTERVAL=' . max(15, min(900, intval($_POST['f4_check_interval'] ?? 60))) . "\n";
        $c .= "MOSQUITTO_AUTORESTART={$f4_mosq_auto}\n";
        $c .= "MOSQUITTO_SERVICE={$mosq_service}\n";
        $c .= "MOSQUITTO_HOST={$mosq_host}\n";
        $c .= "MOSQUITTO_PORT={$mosq_port}\n";
        $c .= "GATEWAY_AUTORESTART={$f4_gw_auto}\n";
        $c .= "GATEWAY_PROCESS_PATTERN={$gw_pattern}\n";
        $c .= "GATEWAY_MQTT_PREFIX={$gw_mqtt_prefix}\n\n";

        $c .= "[SYSTEM_DIAGNOSTICS]\n";
        $c .= "ENABLED={$f5_en}\n";
        $c .= 'CHECK_INTERVAL=' . max(30, min(3600, intval($_POST['f5_check_interval'] ?? 120))) . "\n";
        $c .= "DISK_MONITOR={$f5_disk_mon}\n";
        $c .= "DISK_WARN_PERCENT={$f5_disk_warn}\n";
        $c .= "DISK_CRIT_PERCENT={$f5_disk_crit}\n";
        $c .= "MEMORY_MONITOR={$f5_mem_mon}\n";
        $c .= "MEMORY_WARN_PERCENT={$f5_mem_warn}\n";
        $c .= "TEMP_MONITOR={$f5_temp_mon}\n";
        $c .= "TEMP_WARN_C={$f5_temp_warn}\n";
        $c .= "TEMP_CRIT_C={$f5_temp_crit}\n";
        $c .= "INTERNET_MONITOR={$f5_inet_mon}\n";
        $c .= "INTERNET_AUTOHEAL={$f5_inet_heal}\n";
        $c .= "INTERNET_HOST={$f5_inet_host}\n";
        $c .= "INTERNET_PORT={$f5_inet_port}\n";
        $c .= "TIME_MONITOR={$f5_time_mon}\n";
        $c .= "TIME_AUTOHEAL={$f5_time_heal}\n";
        $c .= 'TIME_UNSYNCED_MIN=' . max(1, min(180, intval($_POST['f5_time_unsynced_min'] ?? 10))) . "\n";
        $c .= "SERVICES_MONITOR={$f5_svc_mon}\n";
        $c .= "SERVICES_AUTOHEAL={$f5_svc_heal}\n";
        $c .= "SERVICES_LIST={$f5_svc_list_str}\n\n";

        $c .= "[MQTT]\n";
        $c .= "ENABLED={$mqtt_en}\n";
        $c .= "USE_LOXBERRY_MQTT={$use_lb_new}\n";
        $c .= 'BROKER='       . strip_tags(trim($_POST['broker']       ?? '127.0.0.1'))          . "\n";
        $c .= 'PORT='         . intval($_POST['port']                  ?? 1883)                 . "\n";
        $c .= 'USER='         . strip_tags(trim($_POST['mqtt_user']    ?? ''))                   . "\n";
        $c .= 'PASS='         . strip_tags(trim($_POST['mqtt_pass']    ?? ''))                   . "\n";
        $c .= 'TOPIC_PREFIX=' . strip_tags(trim($_POST['topic_prefix'] ?? 'HitWatch/netbird_watchdog')) . "\n";

        if (file_put_contents($cfgfile, $c) !== false) {
            $saved = true;
            $cfg   = parse_ini_file($cfgfile, true) ?: [];
            $use_lb = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
        } else {
            $err = $L['MAIN.SAVE_ERR'] ?? 'Speichern fehlgeschlagen';
        }
    }
}

function cv(string $s, string $k, string $d = ''): string {
    global $cfg;
    return h((string)($cfg[$s][$k] ?? $d));
}

$f1_enabled = ($cfg['WATCHDOG']['ENABLED'] ?? '1') == '1';
$f2_enabled = ($cfg['REBOOT_ESCALATION']['ENABLED'] ?? '0') == '1';
$f3_enabled = ($cfg['SCHEDULED_REBOOT']['ENABLED'] ?? '0') == '1';
$f4_enabled = ($cfg['MQTT_WATCHDOG']['ENABLED'] ?? '0') == '1';
$f4_mosq_autorestart = ($cfg['MQTT_WATCHDOG']['MOSQUITTO_AUTORESTART'] ?? '1') == '1';
$f4_gw_autorestart   = ($cfg['MQTT_WATCHDOG']['GATEWAY_AUTORESTART'] ?? '0') == '1';
$f5_enabled           = ($cfg['SYSTEM_DIAGNOSTICS']['ENABLED'] ?? '0') == '1';
$f5_disk_monitor      = ($cfg['SYSTEM_DIAGNOSTICS']['DISK_MONITOR'] ?? '1') == '1';
$f5_memory_monitor    = ($cfg['SYSTEM_DIAGNOSTICS']['MEMORY_MONITOR'] ?? '1') == '1';
$f5_temp_monitor      = ($cfg['SYSTEM_DIAGNOSTICS']['TEMP_MONITOR'] ?? '1') == '1';
$f5_internet_monitor  = ($cfg['SYSTEM_DIAGNOSTICS']['INTERNET_MONITOR'] ?? '1') == '1';
$f5_internet_autoheal = ($cfg['SYSTEM_DIAGNOSTICS']['INTERNET_AUTOHEAL'] ?? '0') == '1';
$f5_time_monitor      = ($cfg['SYSTEM_DIAGNOSTICS']['TIME_MONITOR'] ?? '1') == '1';
$f5_time_autoheal     = ($cfg['SYSTEM_DIAGNOSTICS']['TIME_AUTOHEAL'] ?? '0') == '1';
$f5_services_monitor  = ($cfg['SYSTEM_DIAGNOSTICS']['SERVICES_MONITOR'] ?? '0') == '1';
$f5_services_autoheal = ($cfg['SYSTEM_DIAGNOSTICS']['SERVICES_AUTOHEAL'] ?? '0') == '1';
$weekday_names = [1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'];
$f3_weekdays_cfg = array_map('trim', explode(',', $cfg['SCHEDULED_REBOOT']['WEEKDAYS'] ?? '7'));

render_header('app_settings');
?>

<?php if ($saved): flash_ok($L['MAIN.SAVED'] ?? '✅ Einstellungen gespeichert'); ?>
<div class="sl-flash warn" style="margin-top:0.3rem">
    ↺ Einstellungen geändert – bitte den Daemon neu starten damit alle Änderungen aktiv werden.
    <a href="app_status.php" style="color:inherit;font-weight:700;margin-left:0.4rem">→ Zum Status / Restart</a>
</div>
<?php endif; ?>
<?php if ($err):   flash_err($err); endif; ?>

<form method="POST" class="sl-form">
<input type="hidden" name="csrf" value="<?= h(hw4l_csrf()) ?>">

<!-- ================================================================
     FUNKTION 1 – DIENST-WATCHDOG
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🔁 Funktion 1 – Netbird-Dienst-Watchdog</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f1_enabled" name="f1_enabled" <?= $f1_enabled ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Watchdog aktivieren</span>
            </div>
            <p class="sl-hint">Prüft periodisch ob Netbird tatsächlich verbunden ist (nicht nur ob der Dienst
                läuft) und startet den Dienst bei Bedarf einmalig neu. Kein Loop – nur ein Versuch pro Zyklus.</p>
        </div>
        <div class="sl-slider-row">
            <label>Prüfintervall <span class="sl-slider-val" id="sci"><?= cv('WATCHDOG','CHECK_INTERVAL','300') ?></span> s</label>
            <input type="range" name="check_interval" min="60" max="1800" step="30"
                   value="<?= cv('WATCHDOG','CHECK_INTERVAL','300') ?>"
                   oninput="document.getElementById('sci').textContent=this.value">
        </div>
        <div class="sl-slider-row">
            <label>Wartezeit nach Dienst-Neustart <span class="sl-slider-val" id="srw"><?= cv('WATCHDOG','RESTART_WAIT_SECONDS','20') ?></span> s</label>
            <input type="range" name="restart_wait_seconds" min="5" max="60" step="5"
                   value="<?= cv('WATCHDOG','RESTART_WAIT_SECONDS','20') ?>"
                   oninput="document.getElementById('srw').textContent=this.value">
            <p class="sl-hint">Wie lange nach <code>systemctl restart netbird</code> gewartet wird, bevor der Status erneut geprüft wird.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     FUNKTION 2 – REBOOT-ESKALATION
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🔄 Funktion 2 – Automatischer Reboot bei Verbindungsausfall</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f2_enabled" name="f2_enabled" <?= $f2_enabled ? 'checked' : '' ?> <?= !$f1_enabled ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Reboot-Eskalation aktivieren</span>
            </div>
            <p class="sl-hint" id="f2_hint">Nur wirksam wenn Funktion 1 aktiv ist. Wenn Netbird auch nach dem
                Dienst-Neustart nicht verbunden ist, wird <b>einmalig</b> ein Reboot des LoxBerry ausgelöst.
                Manche Standorte sollen ggf. nicht automatisch neu gestartet werden – daher separat abschaltbar.</p>
        </div>
        <div class="sl-slider-row">
            <label>Reboot-Cooldown <span class="sl-slider-val" id="sch"><?= cv('REBOOT_ESCALATION','COOLDOWN_HOURS','6') ?></span> h</label>
            <input type="range" name="cooldown_hours" min="1" max="72" step="1"
                   value="<?= cv('REBOOT_ESCALATION','COOLDOWN_HOURS','6') ?>"
                   oninput="document.getElementById('sch').textContent=this.value">
            <p class="sl-hint">Innerhalb dieses Zeitraums löst das Plugin nach einem automatischen Reboot
                <b>keinen weiteren</b> aus – verhindert einen Boot-Loop wenn die eigentliche Ursache extern liegt
                (z.B. Router/ISP beim Kunden). Gilt für Funktion 2 <b>und</b> Funktion 3 gemeinsam.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     FUNKTION 3 – AUTOMATISCHER REBOOT
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🗓️ Funktion 3 – Automatischer Reboot</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="f3_enabled" <?= $f3_enabled ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Automatischen Reboot aktivieren</span>
            </div>
            <p class="sl-hint">Reiner Wartungsneustart, komplett unabhängig vom Netbird-Status. Läuft auch wenn
                Funktion 1/2 deaktiviert sind. Respektiert denselben Cooldown wie Funktion 2.</p>
        </div>
        <div class="sl-field">
            <label>Wochentage</label>
            <div class="sl-chip-grid">
<?php foreach ($weekday_names as $num => $name):
    $checked = in_array((string)$num, $f3_weekdays_cfg, true);
?>
                <label class="sl-chip">
                    <input type="checkbox" name="f3_weekday_<?= $num ?>" value="1" <?= $checked ? 'checked' : '' ?>>
                    <span><?= h($name) ?></span>
                </label>
<?php endforeach; ?>
            </div>
            <p class="sl-hint">Alle ausgewählten Tage nutzen dieselbe Uhrzeit und Frequenz (unten) – jeder Tag
                zählt seine eigenen Vorkommen aber unabhängig.</p>
        </div>
        <div class="sl-field">
            <label for="f3_time">Uhrzeit</label>
            <input type="time" id="f3_time" name="f3_time" value="<?= cv('SCHEDULED_REBOOT','TIME','04:00') ?>">
        </div>
        <div class="sl-field">
            <label for="f3_every_n">Frequenz</label>
            <?php $cur_every_n = (int)($cfg['SCHEDULED_REBOOT']['EVERY_N'] ?? 1); ?>
            <select id="f3_every_n" name="f3_every_n">
                <option value="1" <?= $cur_every_n === 1 ? 'selected' : '' ?>>Jedes Mal</option>
<?php for ($n = 2; $n <= 8; $n++): ?>
                <option value="<?= $n ?>" <?= $cur_every_n === $n ? 'selected' : '' ?>>Nur alle <?= $n ?>x</option>
<?php endfor; ?>
            </select>
            <p class="sl-hint">Gilt gemeinsam für alle ausgewählten Wochentage, aber jeder Wochentag zählt für
                sich: bei "alle 2x" und Auswahl Mo+Do wird z.B. jeder 2. Montag <b>und</b> jeder 2. Donnerstag
                übersprungen – unabhängig voneinander.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     FUNKTION 4 – MQTT-DIENSTE-WATCHDOG
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">📡 Funktion 4 – MQTT-Dienste-Watchdog</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f4_enabled" name="f4_enabled" <?= $f4_enabled ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">MQTT-Dienste-Watchdog aktivieren</span>
            </div>
            <p class="sl-hint">Status von Mosquitto-Broker und LoxBerry MQTT-Gateway wird immer
                angezeigt sobald diese Funktion aktiv ist (eigenes Prüfintervall, siehe unten) – die
                Überwachung selbst lässt sich nicht einzeln abschalten. Was du separat steuern
                kannst, ist ob ein ungesunder Dienst automatisch neu gestartet wird (unten).
                Nutze <code>systemctl list-units --type=service | grep -i mqtt</code> per SSH,
                falls du die genauen Dienstnamen deines Systems prüfen willst.</p>
        </div>
        <div class="sl-slider-row">
            <label>Prüfintervall <span class="sl-slider-val" id="sf4ci"><?= cv('MQTT_WATCHDOG','CHECK_INTERVAL','60') ?></span> s</label>
            <input type="range" name="f4_check_interval" min="15" max="300" step="15"
                   value="<?= cv('MQTT_WATCHDOG','CHECK_INTERVAL','60') ?>"
                   oninput="document.getElementById('sf4ci').textContent=this.value">
            <p class="sl-hint">Eigenes, unabhängiges Prüfintervall – läuft getrennt vom Prüfintervall der
                Funktion 1, da ein MQTT-Ausfall schneller auffallen soll.</p>
        </div>
        <hr>
        <div class="sl-field">
            <label for="f4_mosquitto_service">Mosquitto – Dienstname (systemd)</label>
            <input type="text" id="f4_mosquitto_service" name="f4_mosquitto_service" value="<?= cv('MQTT_WATCHDOG','MOSQUITTO_SERVICE','mosquitto') ?>">
        </div>
        <div class="sl-field">
            <label for="f4_mosquitto_host">Broker-Adresse für TCP-Check</label>
            <input type="text" id="f4_mosquitto_host" name="f4_mosquitto_host" value="<?= cv('MQTT_WATCHDOG','MOSQUITTO_HOST','127.0.0.1') ?>">
        </div>
        <div class="sl-field">
            <label for="f4_mosquitto_port">Broker-Port für TCP-Check</label>
            <input type="number" id="f4_mosquitto_port" name="f4_mosquitto_port" value="<?= cv('MQTT_WATCHDOG','MOSQUITTO_PORT','1883') ?>">
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f4_mosquitto_autorestart" name="f4_mosquitto_autorestart" <?= $f4_mosq_autorestart ? 'checked' : '' ?> <?= !$f4_enabled ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Mosquitto automatisch neu starten</span>
            </div>
            <p class="sl-hint">Prüft sowohl den systemd-Dienststatus als auch eine echte TCP-Verbindung
                zum Broker-Port – ein hängender Prozess, der zwar noch "aktiv" gemeldet wird aber keine
                Verbindungen mehr annimmt, wird so trotzdem erkannt. Ist dieser Schalter aus, wird
                nur der Status angezeigt, aber nichts automatisch neu gestartet.</p>
        </div>
        <hr>
        <div class="sl-field">
            <label for="f4_gateway_pattern">MQTT-Gateway – Prozess-Suchmuster (pgrep -f)</label>
            <input type="text" id="f4_gateway_pattern" name="f4_gateway_pattern" value="<?= cv('MQTT_WATCHDOG','GATEWAY_PROCESS_PATTERN','mqttgateway.pl') ?>">
            <p class="sl-hint">Das LoxBerry MQTT-Gateway (<code>mqttgateway.pl</code>) ist kein
                systemd-Dienst, sondern ein LoxBerry-Kern-Daemon – die Erkennung läuft daher über
                einen Prozess-Suchmuster-Abgleich statt über <code>systemctl</code>.</p>
        </div>
        <div class="sl-field">
            <label for="f4_gateway_mqtt_prefix">MQTT-Gateway – Status-Topic-Präfix</label>
            <input type="text" id="f4_gateway_mqtt_prefix" name="f4_gateway_mqtt_prefix" value="<?= cv('MQTT_WATCHDOG','GATEWAY_MQTT_PREFIX','loxberry/mqttgateway') ?>">
            <p class="sl-hint">Das Gateway veröffentlicht seinen eigenen Verbindungsstatus unter
                <code>&lt;Präfix&gt;/status</code> (z.B. "Connected") und einen Herzschlag unter
                <code>&lt;Präfix&gt;/keepaliveepoch</code> – sichtbar in Loxone Config als MQTT
                Virtual Input <code>loxberry_mqttgateway_status</code>. Wird für die Anzeige
                "Verbindung zu Mosquitto" im Status-Tab genutzt (rein informativ).</p>
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f4_gateway_autorestart" name="f4_gateway_autorestart" <?= $f4_gw_autorestart ? 'checked' : '' ?> <?= !$f4_enabled ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">MQTT-Gateway automatisch neu starten</span>
            </div>
            <p class="sl-hint">Standardmäßig deaktiviert. HitWatch4Lox beendet den Prozess bei
                Bedarf, startet ihn aber bewusst NICHT selbst neu – das übernimmt LoxBerrys
                eigenes Watchdog-System für seine Kern-Daemons. Der Erfolg wird nach kurzer
                Wartezeit erneut geprüft.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     FUNKTION 5 – SYSTEM-DIAGNOSE
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🩺 Funktion 5 – System-Diagnose</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f5_enabled" name="f5_enabled" <?= $f5_enabled ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">System-Diagnose aktivieren</span>
            </div>
            <p class="sl-hint">Erkennt typische Vor-Ort-Einsatz-Ursachen (volle SD-Karte, RAM-Druck,
                CPU-Temperatur, kein Internet, Zeitabweichung, weitere LoxBerry-Kerndienste). Anders als
                Funktion 4 ist hier jedes Thema einzeln <b>sowohl im Monitoring als auch im Auto-Heal</b>
                schaltbar – manche Standorte sollen ggf. nur beobachtet werden. Auto-Heal beschränkt sich
                bewusst auf Dienst-/Netzwerk-Neustarts, kein Dateisystem-Eingriff.</p>
        </div>
        <div class="sl-slider-row">
            <label>Prüfintervall <span class="sl-slider-val" id="sf5ci"><?= cv('SYSTEM_DIAGNOSTICS','CHECK_INTERVAL','120') ?></span> s</label>
            <input type="range" name="f5_check_interval" min="30" max="600" step="30"
                   value="<?= cv('SYSTEM_DIAGNOSTICS','CHECK_INTERVAL','120') ?>"
                   oninput="document.getElementById('sf5ci').textContent=this.value">
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" name="f5_disk_monitor" <?= $f5_disk_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">💾 Speicherplatz überwachen</span>
            </div>
            <div class="sl-slider-row">
                <label>Warnung ab <span class="sl-slider-val" id="sf5dw"><?= cv('SYSTEM_DIAGNOSTICS','DISK_WARN_PERCENT','85') ?></span> %</label>
                <input type="range" name="f5_disk_warn" min="50" max="99" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','DISK_WARN_PERCENT','85') ?>"
                       oninput="document.getElementById('sf5dw').textContent=this.value">
            </div>
            <div class="sl-slider-row">
                <label>Kritisch ab <span class="sl-slider-val" id="sf5dc"><?= cv('SYSTEM_DIAGNOSTICS','DISK_CRIT_PERCENT','95') ?></span> %</label>
                <input type="range" name="f5_disk_crit" min="50" max="100" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','DISK_CRIT_PERCENT','95') ?>"
                       oninput="document.getElementById('sf5dc').textContent=this.value">
            </div>
            <p class="sl-hint">Kein Auto-Heal (ein Neustart macht Speicherplatz nicht frei) – reine Frühwarnung
                bevor die SD-Karte vollläuft.</p>
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" name="f5_memory_monitor" <?= $f5_memory_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">🧠 RAM-Auslastung überwachen</span>
            </div>
            <div class="sl-slider-row">
                <label>Warnung ab <span class="sl-slider-val" id="sf5mw"><?= cv('SYSTEM_DIAGNOSTICS','MEMORY_WARN_PERCENT','90') ?></span> %</label>
                <input type="range" name="f5_memory_warn" min="50" max="100" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','MEMORY_WARN_PERCENT','90') ?>"
                       oninput="document.getElementById('sf5mw').textContent=this.value">
            </div>
            <p class="sl-hint">Kein Auto-Heal – reine Anzeige.</p>
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" name="f5_temp_monitor" <?= $f5_temp_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">🌡️ CPU-Temperatur überwachen</span>
            </div>
            <div class="sl-slider-row">
                <label>Warnung ab <span class="sl-slider-val" id="sf5tw"><?= cv('SYSTEM_DIAGNOSTICS','TEMP_WARN_C','70') ?></span> °C</label>
                <input type="range" name="f5_temp_warn" min="40" max="100" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','TEMP_WARN_C','70') ?>"
                       oninput="document.getElementById('sf5tw').textContent=this.value">
            </div>
            <div class="sl-slider-row">
                <label>Kritisch ab <span class="sl-slider-val" id="sf5tc"><?= cv('SYSTEM_DIAGNOSTICS','TEMP_CRIT_C','80') ?></span> °C</label>
                <input type="range" name="f5_temp_crit" min="40" max="120" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','TEMP_CRIT_C','80') ?>"
                       oninput="document.getElementById('sf5tc').textContent=this.value">
            </div>
            <p class="sl-hint">Kein Auto-Heal – nur auf Geräten mit lesbarer Temperatursensorik (z.B. Raspberry
                Pi) verfügbar, sonst "nicht ermittelbar".</p>
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" id="f5_internet_monitor" name="f5_internet_monitor" <?= $f5_internet_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">🌐 Internet-Erreichbarkeit überwachen</span>
            </div>
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f5_internet_autoheal" name="f5_internet_autoheal" <?= $f5_internet_autoheal ? 'checked' : '' ?> <?= !$f5_internet_monitor ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Netzwerk bei Ausfall automatisch neu starten</span>
            </div>
            <div class="sl-field">
                <label for="f5_internet_host">Prüfziel Host</label>
                <input type="text" id="f5_internet_host" name="f5_internet_host" value="<?= cv('SYSTEM_DIAGNOSTICS','INTERNET_HOST','1.1.1.1') ?>">
            </div>
            <div class="sl-field">
                <label for="f5_internet_port">Prüfziel Port</label>
                <input type="number" id="f5_internet_port" name="f5_internet_port" value="<?= cv('SYSTEM_DIAGNOSTICS','INTERNET_PORT','53') ?>">
            </div>
            <p class="sl-hint">Getrennt von der Netbird-Prüfung (Funktion 1) – unterscheidet "Kunde hat kein
                Internet" von "Netbird selbst hat ein Problem". Auto-Heal startet den Netzwerk-Dienst
                (dhcpcd/networking) neu, kein Interface-Down/Up.</p>
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" id="f5_time_monitor" name="f5_time_monitor" <?= $f5_time_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">🕒 Zeit-Synchronisation überwachen</span>
            </div>
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f5_time_autoheal" name="f5_time_autoheal" <?= $f5_time_autoheal ? 'checked' : '' ?> <?= !$f5_time_monitor ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Zeit-Sync bei Abweichung automatisch neu starten</span>
            </div>
            <div class="sl-slider-row">
                <label>Erst eingreifen wenn durchgehend nicht synchron seit <span class="sl-slider-val" id="sf5tu"><?= cv('SYSTEM_DIAGNOSTICS','TIME_UNSYNCED_MIN','10') ?></span> min</label>
                <input type="range" name="f5_time_unsynced_min" min="1" max="60" step="1"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','TIME_UNSYNCED_MIN','10') ?>"
                       oninput="document.getElementById('sf5tu').textContent=this.value">
            </div>
            <p class="sl-hint">Prüft ob systemd die Uhrzeit als NTP-synchronisiert meldet. Relevant u.a. für
                Funktion 3 (zeitgesteuerter Reboot) und Zertifikate. systemd-timesyncd synchronisiert von
                sich aus periodisch neu – ein kurzer Ausschlag direkt nach einem Neustart oder Netzwerk-
                Hänger braucht keinen Eingriff und löst sich meist von selbst. Auto-Heal greift daher erst
                ein, wenn der Zustand durchgehend länger als die obige Schwelle anhält.</p>
        </div>
        <hr>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" class="f5-sub" id="f5_services_monitor" name="f5_services_monitor" <?= $f5_services_monitor ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">🛠️ Weitere Kern-Dienste überwachen</span>
            </div>
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f5_services_autoheal" name="f5_services_autoheal" <?= $f5_services_autoheal ? 'checked' : '' ?> <?= !$f5_services_monitor ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Diese Dienste bei Bedarf automatisch neu starten</span>
            </div>
            <div class="sl-field">
                <label for="f5_services_list">Dienstnamen (systemd, kommagetrennt)</label>
                <input type="text" id="f5_services_list" name="f5_services_list"
                       value="<?= cv('SYSTEM_DIAGNOSTICS','SERVICES_LIST','') ?>" placeholder="z.B. lighttpd,cron,ssh">
            </div>
            <p class="sl-hint">Zusätzlich zu Netbird (Funktion 1) und Mosquitto (Funktion 4) – z.B. weitere
                LoxBerry-Kerndienste. Nutze <code>systemctl list-units --type=service</code> per SSH um die
                genauen Namen zu ermitteln. Läuft über denselben validierten Root-Helper wie Funktion 4.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     MQTT BROKER (optionale Statusanzeige)
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">📡 <?= h($L['MAIN.MQTT_BROKER'] ?? 'MQTT Broker') ?> (optional)</span></div>
    <div class="sl-card-body">
        <p class="sl-hint">Rein informative Statusveröffentlichung an Loxone (Verbindungsstatus, letzte Aktionen).
            Die Watchdog-Logik selbst ist davon vollständig unabhängig – bei MQTT-Ausfall läuft der Watchdog normal weiter.</p>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="mqtt_enabled" <?= ($cfg['MQTT']['ENABLED'] ?? '1') == '1' ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">MQTT-Statusveröffentlichung aktivieren</span>
            </div>
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="use_lb_mqtt" name="use_lb_mqtt" <?= $use_lb ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label"><?= h($L['MAIN.MQTT_AUTO'] ?? 'LoxBerry MQTT Auto-Erkennung') ?></span>
            </div>
        </div>
        <div id="mqtt_manual" <?= $use_lb ? 'style="display:none"' : '' ?>>
            <div class="sl-field">
                <label for="broker">Broker IP / Hostname</label>
                <input type="text" id="broker" name="broker" value="<?= cv('MQTT','BROKER','127.0.0.1') ?>">
            </div>
            <div class="sl-field">
                <label for="port">Port</label>
                <input type="number" id="port" name="port" value="<?= cv('MQTT','PORT','1883') ?>">
            </div>
            <div class="sl-field">
                <label for="mqtt_user">Benutzername (optional)</label>
                <input type="text" id="mqtt_user" name="mqtt_user" value="<?= cv('MQTT','USER','') ?>">
            </div>
            <div class="sl-field">
                <label for="mqtt_pass">Passwort (optional)</label>
                <input type="password" id="mqtt_pass" name="mqtt_pass" value="<?= cv('MQTT','PASS','') ?>">
            </div>
        </div>
        <div class="sl-field">
            <label for="topic_prefix">MQTT Topic Prefix</label>
            <input type="text" id="topic_prefix" name="topic_prefix" value="<?= cv('MQTT','TOPIC_PREFIX','HitWatch/netbird_watchdog') ?>">
            <p class="sl-hint">Alle Topics beginnen mit diesem Präfix: <code><?= cv('MQTT','TOPIC_PREFIX','HitWatch/netbird_watchdog') ?>/connected</code> etc.</p>
        </div>
    </div>
</div>

<div style="padding:0.5rem 0 1.5rem">
    <button type="submit" class="sl-btn primary">💾 <?= h($L['MAIN.SAVE'] ?? 'Speichern') ?></button>
</div>

</form>

<script>
// F2 Toggle: nur bedienbar wenn F1 aktiv
(function() {
    var f1 = document.getElementById('f1_enabled');
    var f2 = document.getElementById('f2_enabled');
    var hint = document.getElementById('f2_hint');
    if (!f1 || !f2) return;
    function update() {
        f2.disabled = !f1.checked;
        if (!f1.checked) f2.checked = false;
    }
    f1.addEventListener('change', update);
})();

// F4 Autorestart-Toggles: nur bedienbar wenn F4 aktiv
(function() {
    var f4 = document.getElementById('f4_enabled');
    var sub = [document.getElementById('f4_mosquitto_autorestart'), document.getElementById('f4_gateway_autorestart')];
    if (!f4) return;
    function update() {
        sub.forEach(function(el) {
            if (!el) return;
            el.disabled = !f4.checked;
            if (!f4.checked) el.checked = false;
        });
    }
    f4.addEventListener('change', update);
})();

// F5 Toggles: Master schaltet alle Sub-Themen, jedes Sub-Thema schaltet nur sein eigenes
// Auto-Heal (Internet/Zeit/Dienste – Disk/RAM/Temp haben kein Auto-Heal).
(function() {
    var f5 = document.getElementById('f5_enabled');
    var subs = document.querySelectorAll('.f5-sub');
    var pairs = [
        ['f5_internet_monitor', 'f5_internet_autoheal'],
        ['f5_time_monitor',     'f5_time_autoheal'],
        ['f5_services_monitor', 'f5_services_autoheal'],
    ];
    function updateHeal() {
        pairs.forEach(function(p) {
            var mon = document.getElementById(p[0]);
            var heal = document.getElementById(p[1]);
            if (!mon || !heal) return;
            heal.disabled = !mon.checked || (f5 && !f5.checked);
            if (heal.disabled) heal.checked = false;
        });
    }
    function updateMaster() {
        subs.forEach(function(el) {
            el.disabled = f5 && !f5.checked;
            if (el.disabled) el.checked = false;
        });
        updateHeal();
    }
    if (f5) f5.addEventListener('change', updateMaster);
    pairs.forEach(function(p) {
        var mon = document.getElementById(p[0]);
        if (mon) mon.addEventListener('change', updateHeal);
    });
})();

// MQTT Toggle: manuelle Felder ein-/ausblenden
(function() {
    var toggle = document.getElementById('use_lb_mqtt');
    var manual = document.getElementById('mqtt_manual');
    if (!toggle || !manual) return;
    function update() { manual.style.display = toggle.checked ? 'none' : ''; }
    update();
    toggle.addEventListener('change', update);
})();
</script>

<?php render_footer(); ?>
