<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

// ── Log-Dateien suchen ──
$pluginDataDir = (isset($lbpdatadir) && $lbpdatadir)
    ? $lbpdatadir
    : str_replace('/config/plugins/', '/data/plugins/', rtrim($lbpconfigdir, '/'));
$sessdir = $pluginDataDir . '/logs';

$allsessions_raw = glob($sessdir . '/HitWatch4Lox_Daemon_*.log') ?: [];
$legacydir        = $lbplogdir . '/sessions';
$allsessions_raw  = array_merge(
    $allsessions_raw,
    glob($legacydir . '/HitWatch4Lox_Daemon_*.log') ?: []
);
usort($allsessions_raw, fn($a, $b) => filemtime($b) - filemtime($a));
$allsessions = $allsessions_raw;

// ── Aktuelle Session ermitteln ──
$ptr_file    = $lbplogdir . '/daemon.log.current';
$current_log = null;
if (file_exists($ptr_file)) {
    $ptr_val = trim(file_get_contents($ptr_file));
    if ($ptr_val && file_exists($ptr_val)) $current_log = $ptr_val;
}
if (!$current_log) {
    $stable = $lbplogdir . '/daemon.log';
    if (file_exists($stable)) $current_log = $stable;
}
if (!$current_log && !empty($allsessions)) $current_log = $allsessions[0];

// ── URL-Parameter: direkte Session ──
$raw_sess = $_GET['session'] ?? null;
if ($raw_sess) {
    $safe_sess = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($raw_sess));
    $sess_path = $sessdir . '/' . $safe_sess;
    if (file_exists($sess_path)) {
        $viewer_url = '/admin/system/tools/logfile.cgi'
            . '?logfile='  . urlencode($sess_path)
            . '&package='  . urlencode($lbpplugindir)
            . '&name=Daemon&header=html&format=template';
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
        echo '<script>window.open(' . json_encode($viewer_url) . ","
           . ' "_blank"); history.back();</script>';
        echo '</body></html>';
        exit;
    }
}

render_header('app_log');
?>

<?php if (empty($allsessions)): ?>
<div class="sl-card">
    <div class="sl-card-body" style="text-align:center;padding:2rem">
        <p style="color:var(--muted);font-size:1rem">📋 <?= h($L['MAIN.LOG_EMPTY'] ?? 'Keine Log-Dateien gefunden') ?></p>
        <a href="app_status.php" class="sl-btn secondary sm" style="margin-top:0.5rem">🚦 Zum Status</a>
        <p class="sl-hint" style="margin-top:1rem">Log-Verzeichnis: <code><?= h($sessdir) ?></code></p>
    </div>
</div>

<?php else: ?>

<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">📋 <?= h($L['MAIN.LOG'] ?? 'Log-Sessions') ?></span></div>
    <div class="sl-card-body">
        <p class="sl-hint" style="margin:0 0 0.6rem">
            <b><?= count($allsessions) ?></b> Log-Sessions gefunden.
            Klicke auf <b>Öffnen</b> um die Session im LoxBerry Log-Viewer anzuzeigen (öffnet in neuem Tab).
        </p>
        <div style="overflow-x:auto">
        <table class="sl-log-list">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Geändert</th>
                    <th>Größe</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
<?php
$real_cur = $current_log ? (realpath($current_log) ?: $current_log) : null;
$idx = 0;
foreach ($allsessions as $logfile):
    $basename  = basename($logfile);
    $mtime     = filemtime($logfile);
    $size      = filesize($logfile);
    $size_str  = $size > 1048576 ? round($size/1048576, 1) . ' MB'
               : ($size > 1024 ? round($size/1024, 1) . ' KB' : $size . ' B');
    $real_file = realpath($logfile) ?: $logfile;
    $is_current= ($real_file === $real_cur);
    $date_str  = date('d.m.Y H:i:s', $mtime);
    $viewer_url = '/admin/system/tools/logfile.cgi'
        . '?logfile='  . urlencode($logfile)
        . '&package='  . urlencode($lbpplugindir)
        . '&name=Daemon&header=html&format=template';
    $idx++;
?>
                <tr>
                    <td style="font-weight:<?= $is_current ? '700' : 'normal' ?>">
                        <?= h($basename) ?>
                        <?php if ($is_current): ?>
                            <span class="sl-badge ok" style="margin-left:4px">Aktuell</span>
                        <?php elseif ($idx === 2): ?>
                            <span class="sl-badge muted" style="margin-left:4px">Vorherige</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;color:var(--muted)"><?= h($date_str) ?></td>
                    <td style="white-space:nowrap;color:var(--muted)"><?= h($size_str) ?></td>
                    <td>
                        <a href="<?= h($viewer_url) ?>" target="_blank" class="sl-btn secondary sm">📂 Öffnen</a>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="sl-hint" style="margin-top:0.6rem">
            Der Daemon erstellt bei jedem Start eine neue Log-Datei (Timestamp im Dateinamen).
            Es werden maximal 7 Sessions aufbewahrt.
        </p>
    </div>
</div>

<?php endif; ?>

<?php render_footer(); ?>
