<?php
/**
 * common.php – gemeinsamer Seitenkopf/Fuß für app_*.php (HitWatch4Lox)
 *
 * Setzt voraus, dass loxberry_system.php bereits vom aufrufenden Skript
 * eingebunden wurde und $L, $lbpconfigdir, $lbpplugindir verfügbar sind.
 *
 * Kein loxberry_web.php hier – jQuery Mobile darf nicht ins iframe gelangen.
 */

// Plugin-Version ermitteln – primär über die LoxBerry-API.
$PLUGIN_VERSION = '';
if (class_exists('LBSystem') && method_exists('LBSystem', 'pluginversion')) {
    $PLUGIN_VERSION = (string) LBSystem::pluginversion();
}
if ($PLUGIN_VERSION === '') {
    foreach ([
        ($lbpdatadir   ?? '') . '/plugin.cfg',
        ($lbpconfigdir ?? '') . '/plugin.cfg',
        dirname($lbpconfigdir ?? '') . '/plugin.cfg',
        ($lbpbindir    ?? '') . '/plugin.cfg',
    ] as $_p) {
        if ($_p !== '/plugin.cfg' && is_file($_p)) {
            $_pcfg = parse_ini_file($_p, true) ?: [];
            $PLUGIN_VERSION = (string) ($_pcfg['PLUGIN']['VERSION'] ?? '');
            if ($PLUGIN_VERSION !== '') break;
        }
    }
}
if ($PLUGIN_VERSION === '') $PLUGIN_VERSION = '?';

// CSRF-Session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['hw4l_csrf'])) {
    $_SESSION['hw4l_csrf'] = bin2hex(random_bytes(16));
}
function hw4l_csrf(): string { return $_SESSION['hw4l_csrf']; }

// XSS-sicheres Escaping
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Aktions-Historie: Icon + Label je Aktionstyp (state.json 'action_log' + last_auto_reboot_reason)
function hw4l_action_label(string $action): array {
    static $map = [
        'netbird_restart'       => ['🐦', 'Netbird-Dienst neugestartet'],
        'mosquitto_restart'     => ['🦟', 'Mosquitto neugestartet'],
        'gateway_restart'       => ['📡', 'MQTT-Gateway neugestartet'],
        'netbird_watchdog'      => ['🔄', 'Reboot ausgelöst – Netbird-Watchdog (Funktion 2)'],
        'scheduled_reboot'      => ['🗓️', 'Reboot ausgelöst – Automatischer Reboot (Funktion 3)'],
        'diag_internet_restart' => ['🌐', 'Netzwerk neugestartet (Internet-Ausfall, Funktion 5)'],
        'diag_time_restart'     => ['🕒', 'Zeit-Synchronisation neugestartet (Funktion 5)'],
        'diag_service_restart'  => ['🛠️', 'Dienst neugestartet (Funktion 5)'],
    ];
    return $map[$action] ?? ['❔', $action];
}

// Auto-Heal-Statuslabel: "Aus" / "An (sofort)" / "An (ab X min)" – konsistente Anzeige für
// alle Auto-Heal-Schalter mit Mindest-Ausfalldauer (Funktion 1/4/5).
function hw4l_autoheal_label(bool $enabled, int $unhealthy_min): string {
    if (!$enabled) return 'Aus';
    return $unhealthy_min > 0 ? "An (ab {$unhealthy_min} min)" : 'An (sofort)';
}

// Health-Ampel: Farbe/Icon/Label je Zustand (state.json 'health' – 'green'/'yellow'/'red')
function hw4l_health_badge(string $level): array {
    static $map = [
        'green'  => ['ok',   '🟢', 'Alles OK'],
        'yellow' => ['warn', '🟡', 'Warnung'],
        'red'    => ['err',  '🔴', 'Fehler'],
    ];
    return $map[$level] ?? ['', '⚪', 'Unbekannt'];
}

function render_header(string $active): void
{
    global $L, $PLUGIN_VERSION, $lbpplugindir;

    $tabs = [
        'app_status'   => '🚦 ' . ($L['MAIN.STATUS']   ?? 'Status'),
        'app_settings' => '⚙️ '  . ($L['MAIN.SETTINGS'] ?? 'Einstellungen'),
        'app_log'      => '📋 ' . ($L['MAIN.LOG']      ?? 'Log'),
        'app_help'     => '❓ '  . ($L['MAIN.HELP']     ?? 'Hilfe'),
    ];

    $_cssVer = is_file(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : '0';
    $_jsVer  = is_file(__DIR__ . '/assets/app.js')   ? filemtime(__DIR__ . '/assets/app.js')   : '0';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="sl-csrf" content="<?= h(hw4l_csrf()) ?>">
<title><?= h($L['MAIN.TITLE'] ?? 'HitWatch4Lox') ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= h((string)$_cssVer) ?>">
<script src="assets/app.js?v=<?= h((string)$_jsVer) ?>"></script>
</head>
<body class="sl-embed">
<header class="sl-header">
    <div class="sl-header-banner">
        <div class="sl-brand">
            <span class="sl-title">🐦 <?= h($L['MAIN.TITLE'] ?? 'HitWatch4Lox') ?></span>
            <span class="sl-subtitle">Netbird-Verbindungs-Watchdog für LoxBerry</span>
        </div>
        <span class="sl-version">v<?= h($PLUGIN_VERSION) ?></span>
    </div>
    <div class="sl-header-nav">
        <nav class="sl-nav">
<?php foreach ($tabs as $page => $label): ?>
            <a href="<?= h($page) ?>.php" class="sl-tab <?= $active === $page ? 'active' : '' ?>"><?= h($label) ?></a>
<?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="sl-main">
<?php
}

function render_footer(): void
{
?>
</main>
<footer class="sl-footer">
    &copy; <?= date('Y') ?> HitSmart / Stefan &nbsp;·&nbsp;
    <a href="https://github.com/HitsmartDev/HitWatch4Lox" target="_blank" style="color:inherit">GitHub</a>
</footer>
<div id="sl-toast" class="sl-toast"></div>
<script>
// Iframe-Höhe regelmäßig berichten (zusätzlich zu app.js – für Inhalt der nach DOMContentLoaded kommt)
(function () {
    if (window.parent === window) return;
    var lastH = -1, pending = null;
    function report() {
        var h = Math.ceil(document.documentElement.scrollHeight / 10) * 10;
        if (h === lastH) return;
        lastH = h;
        try { window.parent.postMessage({ type: 'sl-height', value: h }, '*'); } catch (e) {}
    }
    function schedule() { if (!pending) pending = setTimeout(function(){ pending = null; report(); }, 50); }
    if (document.readyState === 'complete') report(); else window.addEventListener('load', report);
    window.addEventListener('resize', schedule);
    if ('MutationObserver' in window) {
        var mo = new MutationObserver(schedule);
        mo.observe(document.body, { childList:true, subtree:true, attributes:true });
    }
    setInterval(report, 1500);
})();
</script>
</body>
</html>
<?php
}

function flash_err(string $m):  void { echo '<div class="sl-flash err">',  h($m), '</div>'; }
function flash_ok(string $m):   void { echo '<div class="sl-flash ok">',   h($m), '</div>'; }
function flash_warn(string $m): void { echo '<div class="sl-flash warn">', h($m), '</div>'; }
