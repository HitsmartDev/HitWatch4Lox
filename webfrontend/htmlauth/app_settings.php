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
        $mqtt_en = isset($_POST['mqtt_enabled']) ? '1' : '0';
        $use_lb_new = isset($_POST['use_lb_mqtt']) ? '1' : '0';

        // Dienstnamen: nur Buchstaben/Ziffern/._@- (wird an sudo systemctl restart übergeben) –
        // ungültige Eingaben fallen auf den Standardwert zurück statt einen Fehler zu zeigen.
        $svc_re = '/^[A-Za-z0-9_.@-]{1,64}$/';
        $mosq_service = trim($_POST['f4_mosquitto_service'] ?? 'mosquitto');
        if (!preg_match($svc_re, $mosq_service)) $mosq_service = 'mosquitto';
        $gw_service = trim($_POST['f4_gateway_service'] ?? 'mqttgateway');
        if (!preg_match($svc_re, $gw_service)) $gw_service = 'mqttgateway';
        $mosq_host = strip_tags(trim($_POST['f4_mosquitto_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $mosq_port = max(1, min(65535, intval($_POST['f4_mosquitto_port'] ?? 1883)));

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
        $c .= "MOSQUITTO_AUTORESTART={$f4_mosq_auto}\n";
        $c .= "MOSQUITTO_SERVICE={$mosq_service}\n";
        $c .= "MOSQUITTO_HOST={$mosq_host}\n";
        $c .= "MOSQUITTO_PORT={$mosq_port}\n";
        $c .= "GATEWAY_AUTORESTART={$f4_gw_auto}\n";
        $c .= "GATEWAY_SERVICE={$gw_service}\n\n";

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
                angezeigt sobald diese Funktion aktiv ist (Prüfintervall = Funktion 1) – die
                Überwachung selbst lässt sich nicht einzeln abschalten. Was du separat steuern
                kannst, ist ob ein ungesunder Dienst automatisch neu gestartet wird (unten).
                Nutze <code>systemctl list-units --type=service | grep -i mqtt</code> per SSH,
                falls du die genauen Dienstnamen deines Systems prüfen willst.</p>
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
            <label for="f4_gateway_service">MQTT-Gateway – Dienstname (systemd)</label>
            <input type="text" id="f4_gateway_service" name="f4_gateway_service" value="<?= cv('MQTT_WATCHDOG','GATEWAY_SERVICE','mqttgateway') ?>">
            <p class="sl-hint">Der Standardwert ist eine Annahme – bitte vor dem Aktivieren des
                automatischen Neustarts den korrekten Namen für dein System prüfen.</p>
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="f4_gateway_autorestart" name="f4_gateway_autorestart" <?= $f4_gw_autorestart ? 'checked' : '' ?> <?= !$f4_enabled ? 'disabled' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">MQTT-Gateway automatisch neu starten</span>
            </div>
            <p class="sl-hint">Standardmäßig deaktiviert, da ein falscher Dienstname sonst wiederholt
                sinnlose Neustart-Versuche auslösen würde. Status wird trotzdem immer angezeigt.</p>
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
