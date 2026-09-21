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
<summary>⚙️ Die fünf Funktionen im Detail</summary>
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
<p><b>Mindest-Ausfalldauer vor Neustart:</b> Standard 0 min = sofort beim ersten fehlgeschlagenen
Check. Netbird hat kein automatisches Reconnect (siehe oben) – ein Warten würde hier grundsätzlich
nicht helfen, daher bleibt der Default bewusst bei "sofort". Die Schwelle ist trotzdem wie überall
im Plugin konfigurierbar, falls du bewusst kurze Aussetzer tolerieren möchtest.</p>
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
<p>Überwacht Mosquitto-Broker <b>und</b> das LoxBerry MQTT-Gateway mit einem eigenen,
unabhängigen Prüfintervall (Standard 60s, einstellbar in den Einstellungen – bewusst kürzer als
Funktion 1, da ein MQTT-Ausfall schneller auffallen soll). Die Status-Anzeige zeigt zusätzlich
an, wann zuletzt geprüft wurde. Sie ist <b>nicht einzeln abschaltbar</b> – sobald Funktion 4 aktiv ist, siehst du
immer den Zustand beider Dienste. Separat schaltbar ist nur, ob ein ungesunder Dienst
<b>automatisch neu gestartet</b> wird (gleiches Prinzip wie Funktion 1, nur für MQTT statt
Netbird – ein Versuch pro Erkennungszyklus, kein Cooldown/Reboot-Eskalation nötig). Für Mosquitto
und Gateway jeweils getrennt einstellbar: <b>Mindest-Ausfalldauer vor Neustart</b> (Standard 0 min
= sofort, wie bei Funktion 1 konfigurierbar falls du kurze Aussetzer tolerieren willst).</p>
<p><b>Mosquitto-Broker:</b> läuft als regulärer systemd-Dienst. Prüft sowohl den
Dienststatus (<code>systemctl show</code>, ActiveState/SubState) als auch eine echte
TCP-Verbindung zum konfigurierten Broker-Port. Ein Prozess, der laut systemd noch "aktiv" ist
aber keine Verbindungen mehr annimmt (hängender Broker), wird so trotzdem als ungesund erkannt.
Automatischer Neustart läuft über <code>systemctl restart</code>.</p>
<p><b>MQTT-Gateway:</b> Das LoxBerry MQTT-Gateway (<code>mqttgateway.pl</code>) ist <b>kein</b>
systemd-Dienst, sondern ein LoxBerry-Kern-Daemon – <code>systemctl</code> kennt es schlicht
nicht. Die Erkennung läuft daher über zwei unabhängige Wege:</p>
<ul>
<li><b>Prozess-Erkennung:</b> <code>pgrep -f</code> mit einem konfigurierbaren Suchmuster
(Standard <code>mqttgateway.pl</code>) – läuft der Prozess überhaupt?</li>
<li><b>Verbindungsstatus:</b> Das Gateway veröffentlicht seinen eigenen Verbindungszustand direkt
als MQTT-Topic (<code>&lt;Präfix&gt;/status</code>, z.B. "Connected", plus einen Herzschlag
<code>&lt;Präfix&gt;/keepaliveepoch</code>) – sichtbar in Loxone Config als MQTT Virtual Input
z.B. <code>&lt;hostname&gt;_mqttgateway_status</code>. HitWatch4Lox liest diese Werte direkt statt
sie zu erraten – die zuverlässigste verfügbare Quelle, da sie vom Gateway selbst kommt. Ein
veralteter Herzschlag (Standard &gt; 15 Min.) gilt ebenfalls als "nicht verbunden". Der Präfix
wird beim Daemon-Start automatisch aus dem <b>tatsächlichen System-Hostnamen</b> dieses Geräts
ermittelt (<code>&lt;hostname&gt;/mqttgateway</code>) – das Gateway veröffentlicht NICHT unter
einem fixen "loxberry"-Präfix, sondern unter dem individuellen Hostnamen jedes Geräts.</li>
</ul>
<p>Ein Neustart-Versuch wird ausgelöst, wenn der Prozess entweder <b>gar nicht läuft</b> ODER
<b>läuft, aber laut eigener Selbstauskunft nicht mit Mosquitto verbunden ist</b> – genau wie bei
Funktion 1 reicht "der Prozess lebt" allein nicht. Automatischer Neustart des Gateways
funktioniert dann anders als bei Mosquitto: HitWatch4Lox beendet den Prozess (<code>pkill</code>),
startet ihn aber bewusst <b>nicht selbst neu</b> – das erledigt LoxBerrys eigenes Watchdog-System
für seine Kern-Daemons zuverlässiger als ein von uns geratener Start-Befehl mit möglicherweise
falschen Parametern. Der Erfolg wird nach kurzer Wartezeit am wieder laufenden Prozess erneut
geprüft (der MQTT-Verbindungsstatus kann noch etwas länger den alten Wert zeigen, bis das Gateway
neu publiziert) und im Log/der Aktions-Historie vermerkt. Standardmäßig deaktiviert – vor dem
Aktivieren empfiehlt sich ein Blick in die Aktions-Historie nach einem
manuellen Test.</p>
<p class="sl-hint"><b>Zeigt "Verbindung zu Mosquitto" dauerhaft "nicht prüfbar"?</b> Meist liegt
das an MQTT-Zugangsdaten – manche Mosquitto-Installationen lehnen anonyme Verbindungen ab
(erkennbar an <code>mosquitto_sub ... Connection Refused: not authorised</code>). Der Daemon-Log
zeigt seit dieser Version den genauen Grund. Das beeinträchtigt nur die Verbindungsanzeige – ob
der Gateway-<b>Prozess</b> läuft, wird davon unabhängig zuverlässig per <code>pgrep</code> erkannt.</p>
<p class="sl-hint"><b>Zeigt "Verbindung zu Mosquitto" dauerhaft "nicht verbunden" (nicht
"nicht prüfbar"), obwohl der Prozess läuft?</b> Live-Fund: Ein zu diesem Zeitpunkt bereits
falscher Präfix kann trotzdem einen Wert liefern – nämlich eine <b>alte, retained MQTT-Nachricht</b>
von einer früheren Konfiguration oder einem früher anderen Hostnamen, die auf dem Broker
"hängen geblieben" ist (retained Nachrichten bleiben bestehen bis sie explizit überschrieben
werden). Das äußert sich dann als "Herzschlag veraltet" statt als ehrliches "kein Wert
gefunden" – die eigentliche Ursache (falscher Präfix) wird so als reines Timing-Problem
getarnt. Prüfe im Status-Tab das Feld "MQTT-Status-Topic-Präfix" gegen die tatsächlichen
Loxone MQTT Virtual Input-Namen (z.B. <code>meinhostname_mqttgateway_status</code> →
Präfix müsste <code>meinhostname/mqttgateway</code> sein) bzw. direkt per SSH:
<code>mosquitto_sub -h localhost -t '&lt;dein-hostname&gt;/mqttgateway/#' -v -C 4</code>.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Funktion 5 – System-Diagnose (Auto-Heal)</summary>
<div class="sl-details-body">
<p>Erkennt typische Ursachen für einen Vor-Ort-Einsatz bei Kunden, bevor sie zum Totalausfall
werden. Anders als Funktion 4 ist hier <b>jedes Thema einzeln sowohl im Monitoring als auch im
Auto-Heal</b> schaltbar – manche Standorte sollen vielleicht nur beobachtet, nicht automatisch
verändert werden. Eigenes Prüfintervall (Standard 120 s).</p>
<ul>
<li><b>💾 Speicherplatz:</b> Füllstand der Root-Partition in %. Warnung/kritisch-Schwellen einzeln
einstellbar. <b>Kein Auto-Heal</b> – ein Neustart macht keinen Speicherplatz frei, das ist reine
Frühwarnung bevor die SD-Karte vollläuft.</li>
<li><b>🧠 RAM-Auslastung:</b> % belegter Arbeitsspeicher (inkl. Cache-Bereinigung über
<code>MemAvailable</code>). Kein Auto-Heal.</li>
<li><b>🌡️ CPU-Temperatur:</b> liest die generische <code>thermal_zone*</code>-Schnittstelle (probiert
alle vorhandenen Zonen durch, Fallback <code>vcgencmd</code> auf Raspberry Pi). Auf Geräten ohne
lesbaren Sensor (z.B. manche x86-VMs) erscheint "nicht ermittelbar" – der genaue Grund landet
einmalig im Log. Kein Auto-Heal.</li>
<li><b>🌐 Internet-Erreichbarkeit:</b> TCP-Verbindungstest gegen ein konfigurierbares Ziel (Standard
1.1.1.1:53) – bewusst <b>getrennt</b> von der Netbird-Prüfung (Funktion 1), damit man
unterscheiden kann ob beim Kunden das Internet weg ist oder nur Netbird selbst ein Problem hat.
<b>Auto-Heal (optional):</b> startet den Netzwerk-Dienst (<code>dhcpcd</code> bzw.
<code>networking</code>) neu – kein Interface-Down/Up-Gebastel. Erst nach einer einstellbaren
Mindest-Ausfalldauer (Standard 3 min), da kurze Aussetzer (z.B. ein DHCP-Renew) sich oft von
selbst lösen, bevor der Netzwerk-Dienst neu gestartet werden müsste.</li>
<li><b>🕒 Zeit-Synchronisation:</b> fragt <code>timedatectl</code> ob die Systemzeit aktuell
NTP-synchronisiert ist (keine eigene NTP-Abfrage nötig, nutzt systemds eigene Bewertung).
Relevant u.a. für Funktion 3 (zeitgesteuerter Reboot) und TLS-Zertifikate. <b>Auto-Heal
(optional):</b> startet <code>systemd-timesyncd</code> neu (Fallback <code>ntpdate</code>) –
ABER erst wenn der Zustand durchgehend länger als eine einstellbare Schwelle anhält (Standard
10 min). Grund: <code>systemd-timesyncd</code> ist ein dauerhaft laufender Dienst, der von sich
aus periodisch neu synchronisiert – ein kurzer Ausschlag direkt nach einem Neustart oder nach
einem kurzen Netzwerk-Hänger löst sich normalerweise von selbst, ein sofortiger Neustart bei
jedem einzelnen Prüfzyklus wäre unnötig. Bleibt die Zeit dagegen wirklich dauerhaft
unsynchronisiert (in der Praxis beobachtet: auch nach 30+ Minuten noch nicht), greift Auto-Heal
nach Ablauf der Schwelle ein. Schlägt die Ermittlung grundsätzlich fehl (z.B.
<code>timedatectl</code> nicht installiert), landet der genaue Grund einmalig im Log.</li>
<li><b>🛠️ Weitere Kern-Dienste:</b> eine frei konfigurierbare, kommagetrennte Liste zusätzlicher
systemd-Dienste (z.B. <code>lighttpd</code>, <code>cron</code>, <code>ssh</code>) – Status wie bei
Mosquitto in Funktion 4. <b>Auto-Heal (optional):</b> <code>systemctl restart</code> über denselben
validierten Root-Helper, mit eigener Mindest-Ausfalldauer (Standard 0 min = sofort) – jeder Dienst
in der Liste zählt dabei unabhängig, mit eigenem Zähler seit wann er ungesund ist.</li>
</ul>
<p class="sl-hint"><b>Mindest-Ausfalldauer vor Neustart – konsistent für alle Auto-Heal-Aktionen im
Plugin</b> (Funktion 1 Netbird, Funktion 4 Mosquitto/Gateway, Funktion 5 Internet/Zeit-Sync/weitere
Dienste): jeder Auto-Heal-Schalter hat einen eigenen, einstellbaren "erst nach X Minuten
eingreifen"-Wert. Default ist überall 0 = sofort (unverändertes Verhalten), <b>außer</b> bei
Internet (3 min) und Zeit-Sync (10 min) – dort ist ein kurzer, selbstheilender Aussetzer die
Regel, kein Ausnahmefall. Wird die Schwelle unterschritten, wird das Problem trotzdem angezeigt
und geloggt ("wartet noch"), nur der Neustart selbst wird zurückgehalten.</p>
<p class="sl-hint">Bewusste Grenze: Auto-Heal beschränkt sich überall auf Dienst-/Netzwerk-Neustarts.
Ein automatisches Remounten eines schreibgeschützten Root-Dateisystems (klassisches
SD-Karten-Sterbesymptom) ist <b>nicht</b> automatisiert – das kaschiert oft nur eine sterbende
Karte und würde das eigentliche Problem eher verschleiern als lösen.</p>
</div>
</details>

</div>
</details>

<!-- ================================================================
     AKTIONS-HISTORIE
     ================================================================ -->
<details class="sl-details">
<summary>📋 Letzte Aktionen &amp; Aktions-Historie</summary>
<div class="sl-details-body">
<p>Der Status-Tab zeigt eine Karte "Letzte Aktionen" mit den letzten 8 signifikanten Ereignissen
(Dienst-Neustarts, ausgelöste Reboots) inkl. Zeitpunkt und Erfolg/Fehlschlag. Über den Link
"Vollständige Aktions-Historie ansehen" gelangst du zum Log-Tab, der die komplette Historie
(bis zu 200 Einträge) in einer durchsuchbaren Tabelle zeigt.</p>
<p>Diese strukturierte Historie ist unabhängig von den rohen Text-Logdateien (Log-Sessions,
weiter unten auf derselben Seite) – sie wird in <code>state.json</code> gespeichert und übersteht
damit auch einen Neustart des Daemons.</p>
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
<p>Für die Unterbefehle <code>check</code>, <code>restart</code>, <code>reboot</code> (Netbird,
Funktion 1/2/3) sowie <code>restart_networking</code> und <code>sync_time</code> (Funktion 5
Auto-Heal) gibt die <code>sudoers</code>-Regel (unter <code>/etc/sudoers.d/hitwatch4lox</code>,
von <code>postroot.sh</code> angelegt) <b>ausschließlich</b> diese exakten, argumentlosen Aufrufe
frei – kein <code>ALL</code>, keine Wildcards.</p>
<p><b>Einzige Ausnahme:</b> <code>restart_service &lt;name&gt;</code> (Funktion 4 Mosquitto,
Funktion 5 weitere Kerndienste) nimmt einen vom Nutzer in den Einstellungen konfigurierten
Dienstnamen entgegen, da dieser je nach LoxBerry-Setup unterschiedlich ist. Sowohl der
Python-Daemon als auch das Helper-Skript selbst validieren den Namen streng (nur Buchstaben,
Ziffern sowie <code>._@-</code>, max. 64 Zeichen) bevor er an <code>systemctl restart</code>
übergeben wird – kein Shell-Passthrough, keine Sonderzeichen. Die <code>sudoers</code>-Zeile
dafür lautet <code>restart_service *</code>, ist damit aber weiterhin auf genau diesen einen
validierten Befehl beschränkt, nicht auf beliebige sudo-Kommandos.</p>
<p>Das MQTT-Gateway läuft <b>nicht</b> über diesen Root-Helper: Da es unter demselben User wie
der Daemon (<code>loxberry</code>) läuft, genügen unprivilegierte <code>pgrep</code>/<code>pkill</code>
– kein sudo, kein zusätzlicher sudoers-Eintrag nötig.</p>
</div>
</details>

<!-- ================================================================
     MQTT TOPICS
     ================================================================ -->
<details class="sl-details">
<summary>📡 MQTT Topics (optionale Statusanzeige)</summary>
<div class="sl-details-body">
<p>Standard-Präfix: <code>HitWatch/netbird_watchdog/</code> (konfigurierbar in den Einstellungen).
Alle Topic-Namen unten sind relativ zu diesem Präfix zu lesen, z.B. wird aus <code>health</code>
tatsächlich <code>HitWatch/netbird_watchdog/health</code> veröffentlicht.</p>
<p>Rein informativ – bei MQTT-Ausfall läuft die Watchdog-Logik unverändert weiter. Alle Topics
<b>außer <code>event</code></b> sind <code>retain=true</code> (ein neu verbundener Client sieht
sofort den letzten Stand) und werden bei jedem Hauptschleifen-Durchlauf neu veröffentlicht (kurze
Kurzverbindung: connect → publish → disconnect, keine Dauerverbindung, QoS 0). Topics einer
Funktion erscheinen nur, wenn diese Funktion aktiv ist (z.B. <code>mosquitto/*</code> nur wenn
Funktion 4 an ist).</p>

<h4 style="margin:1rem 0 0.3rem">🚦 Ampel – <code>health</code> / <code>health_detail</code></h4>
<p>Fasst Funktion 1 (Netbird), Funktion 4 (Mosquitto/Gateway) und Funktion 5 (System-Diagnose) zu
einem einzigen Gesamtstatus zusammen – für eine Ein-Blick-Übersicht über viele Kundenstandorte in
Loxone, ohne dass man jedes Einzel-Topic selbst auswerten muss. Wird bei <b>jedem</b> Loop-Tick neu
berechnet und veröffentlicht, ist also nie älter als die letzte Statusveröffentlichung.</p>
<table class="sl-mqtt-tbl"><thead><tr><th><code>health</code>-Wert</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>green</code></td><td>Alles OK – kein aktives Problem. <code>health_detail</code> = "Alles OK"</td></tr>
<tr><td><code>yellow</code></td><td>Nur Warnungen (z.B. Speicherplatz/RAM/Temperatur im Warnbereich), nichts Kritisches</td></tr>
<tr><td><code>red</code></td><td>Mindestens ein kritisches Problem (z.B. Netbird/Mosquitto/Gateway getrennt, kein Internet, Speicher/Temperatur kritisch, ein Kern-Dienst aus Funktion 5 down)</td></tr>
</tbody></table>
<p class="sl-hint"><code>health_detail</code> ist bei <code>yellow</code>/<code>red</code> eine
mit "; " getrennte Klartext-Liste aller aktuell zutreffenden Probleme, z.B. <code>"Speicher
kritisch (97%); Kein Internet"</code>.</p>

<h4 style="margin:1rem 0 0.3rem">⚡ Sofort-Event – <code>event</code> (NICHT retained)</h4>
<p>Wird <b>sofort</b> bei jedem Dienst-/Prozess-Neustart oder Reboot veröffentlicht – unabhängig
vom nächsten regulären Prüfzyklus, der je nach Prüfintervall erst Minuten später käme. Bewusst
<b>nicht retained</b>: ein neu verbundener Client soll nicht die letzte (evtl. alte) Aktion sofort
präsentiert bekommen, sondern nur künftige Aktionen live mitbekommen – ideal für eine
Loxone-Benachrichtigung "es wurde gerade etwas neugestartet" in Echtzeit (z.B. MQTT Virtual Input
+ ein Baustein, der bei <b>jeder</b> Nachricht auslöst, nicht nur bei Wertänderung).</p>
<p>JSON-Payload: <code>{"action": "…", "label": "…", "success": true|false, "detail": "…",
"time": "TT.MM.JJJJ HH:MM:SS", "epoch": 1234567890}</code></p>
<table class="sl-mqtt-tbl"><thead><tr><th><code>action</code>-Wert</th><th><code>label</code> (Klartext im Payload)</th><th>Ausgelöst von</th></tr></thead><tbody>
<tr><td><code>netbird_restart</code></td><td>Netbird-Dienst neu gestartet</td><td>Funktion 1</td></tr>
<tr><td><code>mosquitto_restart</code></td><td>Mosquitto neu gestartet</td><td>Funktion 4</td></tr>
<tr><td><code>gateway_restart</code></td><td>MQTT-Gateway neu gestartet</td><td>Funktion 4</td></tr>
<tr><td><code>netbird_watchdog</code></td><td>Automatischer Reboot (Netbird-Eskalation)</td><td>Funktion 2</td></tr>
<tr><td><code>scheduled_reboot</code></td><td>Automatischer Reboot (Zeitplan)</td><td>Funktion 3</td></tr>
<tr><td><code>diag_internet_restart</code></td><td>Netzwerk neu gestartet (Internet-Ausfall)</td><td>Funktion 5</td></tr>
<tr><td><code>diag_time_restart</code></td><td>Zeit-Synchronisation neu gestartet</td><td>Funktion 5</td></tr>
<tr><td><code>diag_service_restart</code></td><td>Dienst neu gestartet</td><td>Funktion 5 (Dienstname steht im <code>detail</code>-Feld)</td></tr>
</tbody></table>
<p class="sl-hint"><code>success</code> ist bei einem Reboot (<code>netbird_watchdog</code>/
<code>scheduled_reboot</code>) zunächst optimistisch <code>true</code> (die Reboot-<b>Anfrage</b>
wurde gestellt, bevor das System tatsächlich herunterfährt) – nur wenn der Root-Helper-Aufruf
selbst fehlschlägt, wird <code>false</code> korrigiert und im nächsten Prüfzyklus erneut versucht.</p>

<h4 style="margin:1rem 0 0.3rem">📋 Alle Topics im Überblick</h4>
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>health</code></td><td><code>green</code> / <code>yellow</code> / <code>red</code></td><td>Ampel – siehe oben</td></tr>
<tr><td><code>health_detail</code></td><td>Text</td><td>Klartext-Problemliste, "Alles OK" wenn keine</td></tr>
<tr><td><code>event</code></td><td>JSON, <b>nicht</b> retained</td><td>Sofort bei jeder Aktion – siehe oben</td></tr>
<tr><td><code>status</code></td><td>"OK" oder Fehlertext</td><td>Funktion 1 – letzter Prüf-Fehlertext (z.B. wenn <code>netbird status</code> selbst fehlschlägt)</td></tr>
<tr><td><code>connected</code></td><td><code>0</code> / <code>1</code></td><td>Netbird aktuell verbunden (Management UND Signal)</td></tr>
<tr><td><code>management</code></td><td>Text, z.B. "Connected"/"Disconnected"</td><td>Management-Verbindungsstatus aus <code>netbird status --detail</code></td></tr>
<tr><td><code>signal</code></td><td>Text, z.B. "Connected"/"Disconnected"</td><td>Signal-Verbindungsstatus aus <code>netbird status --detail</code></td></tr>
<tr><td><code>last_check_epoch</code></td><td>Unix-Zeitstempel</td><td>Zeitpunkt der letzten Netbird-Prüfung</td></tr>
<tr><td><code>last_restart_epoch</code></td><td>Unix-Zeitstempel</td><td>Zeitpunkt des letzten Netbird-Dienst-Neustarts</td></tr>
<tr><td><code>restart_count_total</code></td><td>Zahl ≥ 0</td><td>Netbird-Dienst-Neustarts seit Daemon-Start</td></tr>
<tr><td><code>last_reboot_epoch</code></td><td>Unix-Zeitstempel</td><td>Zeitpunkt des letzten automatischen Reboots (Funktion 2 oder 3)</td></tr>
<tr><td><code>last_reboot_reason</code></td><td><code>netbird_watchdog</code> / <code>scheduled_reboot</code> / leer</td><td>Welcher Mechanismus zuletzt gebootet hat</td></tr>
<tr><td><code>cooldown_active</code></td><td><code>0</code> / <code>1</code></td><td>Reboot-Cooldown (Funktion 2+3 gemeinsam) aktuell aktiv</td></tr>
<tr><td><code>cooldown_remaining_min</code></td><td>Minuten ≥ 0</td><td>Verbleibende Cooldown-Zeit</td></tr>
<tr><td><code>mosquitto/healthy</code></td><td><code>0</code> / <code>1</code></td><td>Nur wenn Funktion 4 aktiv – Dienststatus UND TCP-Check kombiniert</td></tr>
<tr><td><code>mosquitto/active_state</code></td><td>systemd ActiveState: <code>active</code> / <code>activating</code> / <code>deactivating</code> / <code>inactive</code> / <code>failed</code> / <code>reloading</code></td><td>Roher systemd-Zustand des Mosquitto-Dienstes</td></tr>
<tr><td><code>mosquitto/sub_state</code></td><td>systemd SubState, z.B. <code>running</code> / <code>dead</code> / <code>start</code> / <code>failed</code></td><td>Feinerer systemd-Unterzustand (z.B. "start" während der Dienst gerade hochfährt)</td></tr>
<tr><td><code>mosquitto/restart_count</code></td><td>Zahl ≥ 0</td><td>Mosquitto-Neustarts seit Daemon-Start</td></tr>
<tr><td><code>gateway/healthy</code></td><td><code>0</code> / <code>1</code></td><td>Nur wenn Funktion 4 aktiv – Prozess läuft UND (falls prüfbar) mit Mosquitto verbunden</td></tr>
<tr><td><code>gateway/active_state</code></td><td><code>active</code> / <code>inactive</code></td><td>Synthetisch aus <code>pgrep</code> abgeleitet (Gateway ist kein systemd-Dienst), kein echter systemd-Wert</td></tr>
<tr><td><code>gateway/sub_state</code></td><td><code>running</code> / <code>dead</code></td><td>Ebenfalls synthetisch aus <code>pgrep</code></td></tr>
<tr><td><code>gateway/restart_count</code></td><td>Zahl ≥ 0</td><td>Gateway-Prozess beendet (LoxBerry respawnt normalerweise selbst) seit Daemon-Start</td></tr>
<tr><td><code>gateway/broker_linked</code></td><td><code>0</code> / <code>1</code></td><td>Gateway meldet selbst "Connected" zu Mosquitto (aus dessen eigenem MQTT-Status-Topic, siehe Funktion 4)</td></tr>
<tr><td><code>diag/disk_percent</code></td><td>0–100 (%)</td><td>Nur bei Funktion 5 + Speicher-Monitoring aktiv – Füllstand der Root-Partition</td></tr>
<tr><td><code>diag/memory_percent</code></td><td>0–100 (%)</td><td>Nur bei Funktion 5 + RAM-Monitoring aktiv</td></tr>
<tr><td><code>diag/temp_c</code></td><td>Zahl (°C)</td><td>Nur bei Funktion 5 + Temperatur-Monitoring aktiv UND Sensor lesbar – fehlt das Topic komplett, war kein Sensor auffindbar (z.B. auf mancher virtualisierter Hardware)</td></tr>
<tr><td><code>diag/internet_ok</code></td><td><code>0</code> / <code>1</code></td><td>Nur bei Funktion 5 + Internet-Monitoring aktiv – TCP-Erreichbarkeit des konfigurierten Prüfziels</td></tr>
<tr><td><code>diag/time_synced</code></td><td><code>0</code> / <code>1</code></td><td>Nur bei Funktion 5 + Zeit-Monitoring aktiv – laut <code>timedatectl</code> NTP-synchronisiert</td></tr>
</tbody></table>
<p class="sl-hint">Die Kern-Dienste aus Funktion 5 (<code>SERVICES_LIST</code>) sowie einzelne
Detailtexte (z.B. <code>gateway_broker_detail</code>, Fehlergründe bei nicht ermittelbaren
Diagnose-Werten) werden aktuell NICHT als eigene MQTT-Topics veröffentlicht, nur im Status-Tab
und im Daemon-Log – bei Bedarf gerne als Erweiterung ergänzbar.</p>
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
