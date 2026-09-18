<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

render_header('app_help');
?>

<!-- ================================================================
     WAS IST HITWATCH4LOX
     ================================================================ -->
<details class="sl-details" open>
<summary>📖 Was ist HitWatch4Lox?</summary>
<div class="sl-details-body">
<p>Der Netbird-Client auf Linux hat kein zuverlässiges automatisches Reconnect-Verhalten
nach längerem Verbindungsverlust – bestätigt durch mehrere offene GitHub-Issues im
<code>netbirdio/netbird</code>-Repository. Manchmal hilft nur ein manuelles
<code>netbird down &amp;&amp; netbird up</code>, in manchen Fällen erst ein voller Systemneustart.</p>
<p>HitWatch4Lox ist ein LoxBerry-Plugin, das genau dieses Problem lokal auf jedem Gerät
löst – ohne sich selbst in einer Neustart-Schleife zu verfangen. Es überwacht die
tatsächliche Netbird-Verbindung (nicht nur ob der Dienst läuft) und reagiert bei Bedarf
automatisiert.</p>
<p><a href="https://github.com/HitsmartDev/HitWatch4Lox" target="_blank">GitHub Repository</a></p>
</div>
</details>

<!-- ================================================================
     DIE VIER FUNKTIONEN
     ================================================================ -->
<details class="sl-details" open>
<summary>⚙️ Die vier Funktionen im Detail</summary>
<div class="sl-details-body">

<details class="sl-details-nested" open>
<summary>Funktion 1 – Netbird-Dienst-Watchdog</summary>
<div class="sl-details-body">
<p>Periodische Prüfung (Standard alle 300 s, einstellbar): <code>netbird status --detail</code>
wird ausgewertet – geprüft wird ob <b>Management</b> und <b>Signal</b> beide "Connected" melden,
nicht nur ob <code>systemctl is-active netbird</code> ok zurückgibt (ein hängender/getrennter
Client kann als Dienst trotzdem "aktiv" erscheinen).</p>
<p>Meldet die Prüfung "nicht verbunden", wird <b>einmalig</b> <code>systemctl restart netbird</code>
ausgeführt, eine kurze Wartezeit abgewartet (Standard 20 s) und danach erneut geprüft. Kein
Loop – pro Erkennungszyklus nur ein Versuch. Ist die Verbindung danach wieder da, ist die
Sache erledigt; ist sie es nicht, kann optional Funktion 2 eskalieren.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Funktion 2 – Automatischer Reboot-Eskalation</summary>
<div class="sl-details-body">
<p>Nur wirksam wenn Funktion 1 aktiv ist. Bleibt Netbird auch nach dem Dienst-Neustart
getrennt, löst HitWatch4Lox <b>einmalig</b> einen Reboot des LoxBerry aus – vorbehaltlich
des Cooldowns (siehe unten).</p>
<p>Getrennt von Funktion 1 ein-/ausschaltbar: an manchen Standorten möchte man vielleicht
nur den Dienst neu starten lassen, aber nicht automatisch das ganze Gerät neu booten.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Funktion 3 – Automatischer Reboot</summary>
<div class="sl-details-body">
<p>Ein reiner Wartungsneustart zu frei wählbaren Wochentagen (Mehrfachauswahl) und einer
gemeinsamen Uhrzeit – komplett unabhängig vom Netbird-Status. Läuft auch wenn Funktion 1/2
deaktiviert sind. Respektiert denselben Cooldown wie Funktion 2, damit nachvollziehbar bleibt,
welcher Mechanismus einen Reboot ausgelöst hat (Status-Tab zeigt den Grund).</p>
<p><b>Frequenz:</b> "Jedes Mal" löst an jedem ausgewählten Wochentag einen Reboot aus. "Nur
alle 2x"/"3x"/… überspringt entsprechend viele Vorkommen – und zwar <b>pro Wochentag
unabhängig gezählt</b>. Beispiel: Montag + Donnerstag ausgewählt, Frequenz "alle 2x" →
jeder 2. Montag <b>und</b> jeder 2. Donnerstag lösen aus, mit eigenem Zähler je Wochentag
(gespeichert in <code>state.json</code>).</p>
<p><b>Fangfenster statt "irgendwann heute noch":</b> Der Reboot feuert nur, wenn die Prüfung
innerhalb weniger Minuten nach dem eingestellten Zeitpunkt läuft (Fenstergröße = 2× Prüfintervall,
mind. 10 Minuten). War der Daemon zum geplanten Zeitpunkt nicht aktiv (z.B. weil das Plugin
gerade neu installiert wurde oder abgestürzt war), wird der Reboot an diesem Tag <b>nicht
nachträglich nachgeholt</b> und der Frequenz-Zähler bleibt unverändert – sonst würde jeder
spätere Neustart des Daemons am selben Tag erneut einen überfälligen Reboot auslösen.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Funktion 4 – MQTT-Dienste-Watchdog</summary>
<div class="sl-details-body">
<p>Überwacht Mosquitto-Broker und/oder das LoxBerry MQTT-Gateway unabhängig voneinander
(Prüfintervall = Funktion 1) und startet den jeweiligen Dienst bei Bedarf <b>einmalig</b> neu –
gleiches Prinzip wie Funktion 1, nur für MQTT statt Netbird.</p>
<p><b>Mosquitto-Broker:</b> Prüft sowohl den systemd-Dienststatus (<code>systemctl show</code>,
ActiveState/SubState) als auch eine echte TCP-Verbindung zum konfigurierten Broker-Port.
Ein Prozess, der laut systemd noch "aktiv" ist aber keine Verbindungen mehr annimmt (hängender
Broker), wird so trotzdem als ungesund erkannt.</p>
<p><b>MQTT-Gateway:</b> Prüft nur den systemd-Dienststatus. Standardmäßig deaktiviert, da der
genaue Dienstname je nach LoxBerry-Version variieren kann – vor dem Aktivieren empfiehlt sich
ein Check per SSH: <code>systemctl list-units --type=service | grep -i mqtt</code>.</p>
<p>Beide Dienstnamen sind in den Einstellungen frei konfigurierbar. <b>Kein Cooldown/Reboot-
Eskalation für Funktion 4</b> – ein Dienst-Neustart gilt hier als ausreichend; anders als bei
Funktion 1/2 gibt es keine Kopplung an einen Systemneustart.</p>
</div>
</details>

</div>
</details>

<!-- ================================================================
     ANTI-LOOP / COOLDOWN
     ================================================================ -->
<details class="sl-details" open>
<summary>🧊 Schutz vor Boot-Loops (Cooldown)</summary>
<div class="sl-details-body">
<p>Vor jedem automatischen Reboot (egal ob durch Funktion 2 oder Funktion 3 ausgelöst) prüft
das Plugin: <b>Wurde in den letzten X Stunden (Standard 6 h, einstellbar) bereits ein
automatischer Reboot durch dieses Plugin ausgelöst?</b> Falls ja, wird der Reboot
unterdrückt und nur geloggt ("Reboot unterdrückt, letzter Reboot war um HH:MM,
Cooldown aktiv").</p>
<p>Das verhindert eine Boot-Loop-Spirale, falls die eigentliche Ursache gar nicht am
LoxBerry liegt – z.B. ein Netzwerk-, Router- oder ISP-Problem beim Kunden, das auch ein
Reboot nicht beheben kann. Der Zeitpunkt des letzten automatischen Reboots wird persistent
in <code>data/plugins/hitwatch4lox/state.json</code> gespeichert und übersteht damit auch
einen Neustart des Daemons selbst.</p>
</div>
</details>

<!-- ================================================================
     SICHERHEIT / ROOT-RECHTE
     ================================================================ -->
<details class="sl-details">
<summary>🔒 Root-Rechte &amp; Sicherheit</summary>
<div class="sl-details-body">
<p>Der Daemon läuft – wie alle HitSmart LoxBerry-Plugins – als unprivilegierter
<code>loxberry</code>-User. Root-pflichtige Aktionen (Netbird-Status, Dienst-Neustarts, Reboot)
laufen ausschließlich über ein separates Root-Helper-Skript (<code>netbird_watchdog_helper.sh</code>).</p>
<p>Für die Unterbefehle <code>check</code>, <code>restart</code> und <code>reboot</code> (Netbird,
Funktion 1/2/3) gibt die <code>sudoers</code>-Regel (unter <code>/etc/sudoers.d/hitwatch4lox</code>,
von <code>postroot.sh</code> angelegt) <b>ausschließlich</b> diese exakten, argumentlosen Aufrufe
frei – kein <code>ALL</code>, keine Wildcards.</p>
<p><b>Einzige Ausnahme:</b> <code>restart_service &lt;name&gt;</code> (Funktion 4, Mosquitto/
MQTT-Gateway) nimmt einen vom Nutzer in den Einstellungen konfigurierten Dienstnamen entgegen,
da dieser je nach LoxBerry-Setup unterschiedlich ist. Sowohl der Python-Daemon als auch das
Helper-Skript selbst validieren den Namen streng (nur Buchstaben, Ziffern sowie
<code>._@-</code>, max. 64 Zeichen) bevor er an <code>systemctl restart</code> übergeben wird –
kein Shell-Passthrough, keine Sonderzeichen. Die <code>sudoers</code>-Zeile dafür lautet
<code>restart_service *</code>, ist damit aber weiterhin auf genau diesen einen validierten
Befehl beschränkt, nicht auf beliebige sudo-Kommandos.</p>
</div>
</details>

<!-- ================================================================
     MQTT TOPICS
     ================================================================ -->
<details class="sl-details">
<summary>📡 MQTT Topics (optionale Statusanzeige)</summary>
<div class="sl-details-body">
<p>Standard-Präfix: <code>HitWatch/netbird_watchdog/</code> (konfigurierbar). Alle Topics mit
<b>retain=true</b>. Rein informativ – bei MQTT-Ausfall läuft die Watchdog-Logik unverändert
weiter, da jede Veröffentlichung als kurzlebige Verbindung (connect → publish → disconnect)
pro Prüfzyklus erfolgt, nicht als Dauerverbindung.</p>
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>status</code></td><td>Text</td><td>"OK" oder Fehlertext</td></tr>
<tr><td><code>connected</code></td><td>0 / 1</td><td>Netbird aktuell verbunden</td></tr>
<tr><td><code>management</code></td><td>Text</td><td>Management-Verbindungsstatus aus <code>netbird status --detail</code></td></tr>
<tr><td><code>signal</code></td><td>Text</td><td>Signal-Verbindungsstatus aus <code>netbird status --detail</code></td></tr>
<tr><td><code>last_check_epoch</code></td><td>Unix-TS</td><td>Zeitpunkt der letzten Prüfung</td></tr>
<tr><td><code>last_restart_epoch</code></td><td>Unix-TS</td><td>Zeitpunkt des letzten Dienst-Neustarts</td></tr>
<tr><td><code>restart_count_total</code></td><td>Zahl</td><td>Dienst-Neustarts seit Daemon-Start</td></tr>
<tr><td><code>last_reboot_epoch</code></td><td>Unix-TS</td><td>Zeitpunkt des letzten automatischen Reboots</td></tr>
<tr><td><code>last_reboot_reason</code></td><td>Text</td><td><code>netbird_watchdog</code> oder <code>scheduled_reboot</code></td></tr>
<tr><td><code>cooldown_active</code></td><td>0 / 1</td><td>Reboot-Cooldown aktuell aktiv</td></tr>
<tr><td><code>cooldown_remaining_min</code></td><td>Minuten</td><td>Verbleibende Cooldown-Zeit</td></tr>
<tr><td><code>mosquitto/healthy</code></td><td>0 / 1</td><td>Nur wenn Funktion 4 + Mosquitto-Überwachung aktiv</td></tr>
<tr><td><code>mosquitto/active_state</code></td><td>Text</td><td>systemd ActiveState (active/activating/failed/…)</td></tr>
<tr><td><code>mosquitto/sub_state</code></td><td>Text</td><td>systemd SubState (running/start/…)</td></tr>
<tr><td><code>mosquitto/restart_count</code></td><td>Zahl</td><td>Mosquitto-Neustarts seit Daemon-Start</td></tr>
<tr><td><code>gateway/healthy</code></td><td>0 / 1</td><td>Nur wenn Funktion 4 + Gateway-Überwachung aktiv</td></tr>
<tr><td><code>gateway/active_state</code></td><td>Text</td><td>systemd ActiveState des MQTT-Gateways</td></tr>
<tr><td><code>gateway/sub_state</code></td><td>Text</td><td>systemd SubState des MQTT-Gateways</td></tr>
<tr><td><code>gateway/restart_count</code></td><td>Zahl</td><td>Gateway-Neustarts seit Daemon-Start</td></tr>
</tbody></table>
</div>
</details>

<!-- ================================================================
     ZENTRALE ÜBERWACHUNG (N8N)
     ================================================================ -->
<details class="sl-details">
<summary>🌐 Zentrale Überwachung über alle Geräte</summary>
<div class="sl-details-body">
<p>HitWatch4Lox löst die <b>lokale Selbstheilung</b> auf jedem einzelnen Gerät. Für die
proaktive Benachrichtigung, wenn ein LoxBerry im Netbird-Netzwerk komplett unerreichbar
wird (bevor der Kunde anruft), kommt separat ein n8n-Workflow zum Einsatz, der die
Netbird-API zentral abfragt. Dieser Teil ist bewusst nicht Bestandteil dieses
Plugins/Repositories.</p>
</div>
</details>

<!-- ================================================================
     FAQ
     ================================================================ -->
<details class="sl-details">
<summary>❓ Häufige Fragen</summary>
<div class="sl-details-body">

<details class="sl-details-nested">
<summary>Was passiert wenn ich den Daemon stoppe?</summary>
<div class="sl-details-body">
<p>Der „Stop"-Button beendet den Daemon <b>dauerhaft</b>: Autostart nach einem Reboot, der
tägliche Neustart um 03:00 Uhr und der Prozess-Watchdog werden alle deaktiviert. Der Daemon
läuft erst wieder wenn du hier „Start" drückst. Intern wird dafür der Merker
<code>data/plugins/hitwatch4lox/daemon.enabled</code> entfernt bzw. angelegt.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Warum meldet die Statusabfrage einen Fehler, obwohl Netbird installiert ist?</summary>
<div class="sl-details-body">
<p>Der Root-Helper führt <code>netbird status --detail</code> aus. Schlägt das fehl (z.B.
weil das Binary nicht im PATH liegt oder der Netbird-Dienst nicht existiert), erscheint der
Fehlertext im Status-Tab und im Log. Prüfe zunächst manuell auf dem LoxBerry per SSH:
<code>sudo netbird status --detail</code>.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Wieso wird bei Verbindungsverlust nicht sofort ein Reboot ausgelöst?</summary>
<div class="sl-details-body">
<p>Ein Dienst-Neustart (Funktion 1) behebt die meisten Netbird-Hänger bereits und ist
deutlich weniger invasiv als ein voller Systemneustart. Erst wenn das nicht reicht,
eskaliert optional Funktion 2 – und auch dann nur einmalig pro Cooldown-Zeitraum.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Wo finde ich ältere Logs?</summary>
<div class="sl-details-body">
<p>Der Log-Tab zeigt alle verfügbaren Sessions (max. 7) der letzten Daemon-Starts. Jeder
Start erstellt eine eigene Log-Datei mit Zeitstempel im Namen.</p>
</div>
</details>

</div>
</details>

<div style="text-align:center;margin-top:1rem">
    <a href="https://github.com/HitsmartDev/HitWatch4Lox" target="_blank" class="sl-btn secondary sm">⭐ GitHub Repository</a>
</div>

<?php render_footer(); ?>
