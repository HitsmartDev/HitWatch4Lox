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
        $mqtt_en = isset($_POST['mqtt_enabled']) ? '1' : '0';
        $use_lb_new = isset($_POST['use_lb_mqtt']) ? '1' : '0';

        $weekday = max(1, min(7, intval($_POST['f3_weekday'] ?? 7)));
        $time_raw = trim($_POST['f3_time'] ?? '04:00');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time_raw)) {
            $time_raw = '04:00';
        }

        $c  = "[WATCHDOG]\n";
        $c .= "ENABLED={$f1_en}\n";
        $c .= 'CHECK_INTERVAL='       . max(60, min(3600, intval($_POST['check_interval']       ?? 300))) . "\n";
        $c .= 'RESTART_WAIT_SECONDS=' . max(5,  min(120,  intval($_POST['restart_wait_seconds'] ?? 20)))  . "\n\n";

        $c .= "[REBOOT_ESCALATION]\n";
        $c .= "ENABLED={$f2_en}\n";
        $c .= 'COOLDOWN_HOURS=' . max(1, min(72, intval($_POST['cooldown_hours'] ?? 6))) . "\n\n";

        $c .= "[WEEKLY_REBOOT]\n";
        $c .= "ENABLED={$f3_en}\n";
        $c .= "WEEKDAY={$weekday}\n";
        $c .= "TIME={$time_raw}\n\n";

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
$f3_enabled = ($cfg['WEEKLY_REBOOT']['ENABLED'] ?? '0') == '1';
$weekday_names = [1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag'];

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
     FUNKTION 3 – GEPLANTER WÖCHENTLICHER REBOOT
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🗓️ Funktion 3 – Geplanter wöchentlicher Reboot</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="f3_enabled" <?= $f3_enabled ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">Wöchentlichen Wartungsneustart aktivieren</span>
            </div>
            <p class="sl-hint">Reiner Wartungsneustart, komplett unabhängig vom Netbird-Status. Läuft auch wenn
                Funktion 1/2 deaktiviert sind. Respektiert denselben Cooldown wie Funktion 2.</p>
        </div>
        <div class="sl-field">
            <label for="f3_weekday">Wochentag</label>
            <select id="f3_weekday" name="f3_weekday">
<?php $cur_wd = (int)($cfg['WEEKLY_REBOOT']['WEEKDAY'] ?? 7); foreach ($weekday_names as $num => $name): ?>
                <option value="<?= $num ?>" <?= $cur_wd === $num ? 'selected' : '' ?>><?= h($name) ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div class="sl-field">
            <label for="f3_time">Uhrzeit</label>
            <input type="time" id="f3_time" name="f3_time" value="<?= cv('WEEKLY_REBOOT','TIME','04:00') ?>">
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
