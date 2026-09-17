<?php
/**
 * index.php – LoxBerry-Wrapper (iframe-Ansatz)
 *
 * Bindet die UI als iframe in den LoxBerry-Rahmen ein, sodass jQuery Mobile
 * komplett isoliert ist. Die innere Seite navigiert eigenständig.
 */

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';

$L = LBSystem::readlanguage('language.ini');

// Initialen inneren Tab aus ?p= lesen (z.B. /index.php?p=app_settings)
$allowed = ['app_status', 'app_settings', 'app_log', 'app_help'];
$inner   = $_GET['p'] ?? 'app_status';
if (!in_array($inner, $allowed, true)) $inner = 'app_status';
$innerSafe = htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

LBWeb::lbheader($L['MAIN.TITLE'] ?? 'HitWatch4Lox', '');
?>
<style>
/* iframe füllt LoxBerry-Content-Area vollständig aus (nur seitlich negativ, oben Abstand) */
#hw4l-wrap { margin: 0.5rem -4% 0; padding: 0; }
#hw4l-iframe {
    border: 0;
    width: 100%;
    height: 600px;   /* Initiale Höhe – wird per postMessage angepasst */
    display: block;
}
</style>

<div id="hw4l-wrap">
    <iframe id="hw4l-iframe"
            src="<?= $innerSafe ?>.php"
            title="<?= htmlspecialchars($L['MAIN.TITLE'] ?? 'HitWatch4Lox', ENT_QUOTES, 'UTF-8') ?>"
            allow="clipboard-write">
    </iframe>
</div>

<script>
function hw4lSetHeight(h) {
    var ifr = document.getElementById('hw4l-iframe');
    if (ifr) ifr.style.height = Math.max(h || 0, 300) + 'px';
}

var _toastTimer = null;
function hw4lShowToast(msg, kind, ms) {
    var el = document.getElementById('hw4l-parent-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'hw4l-parent-toast';
        el.style.cssText = [
            'position:fixed', 'left:50%', 'top:60px',
            'transform:translate(-50%,-0.5rem)',
            'padding:0.6rem 1.3rem', 'border-radius:6px',
            'font-size:0.92rem', 'font-family:system-ui,sans-serif',
            'box-shadow:0 4px 16px rgba(0,0,0,0.25)', 'z-index:2147483647',
            'opacity:0', 'pointer-events:none',
            'transition:opacity 0.18s,transform 0.18s',
            'max-width:80%', 'text-align:center', 'color:#fff',
        ].join(';');
        document.documentElement.appendChild(el);
    }
    var bg = '#143a66';
    if (kind === 'ok')   bg = '#27ae60';
    if (kind === 'warn') bg = '#e67e22';
    if (kind === 'err')  bg = '#e74c3c';
    el.style.background = bg;
    el.textContent = msg;
    el.style.opacity = '1';
    el.style.pointerEvents = 'auto';
    el.style.transform = 'translate(-50%,0)';
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () {
        el.style.opacity = '0';
        el.style.pointerEvents = 'none';
        el.style.transform = 'translate(-50%,-0.5rem)';
    }, Math.max(500, ms || 2500));
}

window.addEventListener('message', function (ev) {
    if (!ev.data || typeof ev.data !== 'object') return;
    if (ev.data.type === 'sl-height') {
        var v = parseInt(ev.data.value, 10);
        if (v > 100 && v < 30000) hw4lSetHeight(v);
    } else if (ev.data.type === 'sl-toast') {
        hw4lShowToast(
            String(ev.data.message || ''),
            String(ev.data.kind || 'ok'),
            Number(ev.data.duration) || 2500
        );
    }
});
</script>

<?php LBWeb::lbfooter(); ?>
