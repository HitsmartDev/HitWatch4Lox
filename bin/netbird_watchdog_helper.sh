#!/bin/bash
# HitWatch4Lox – Root-Helper (Netbird-Watchdog + MQTT-Dienste-Watchdog)
#
# Der Python-Daemon läuft als loxberry-User (Konvention aller HitSmart LoxBerry-Plugins).
# Netbird-Status abfragen, Dienste neu starten und einen Reboot auslösen erfordern jedoch
# Root-Rechte. Statt einzelne sudo-Kommandos mit Argumenten freizugeben (Injection-Risiko,
# unübersichtliche sudoers-Regeln), kapselt dieses Skript alle privilegierten Aktionen hinter
# festen Unterbefehlen. postroot.sh gibt in /etc/sudoers.d/hitwatch4lox NUR die exakten
# Aufrufe frei (siehe dort).
#
# Ausnahmen mit einem vom Nutzer beeinflussten Argument: "restart_service <name>" (Funktion 4
# Mosquitto, Funktion 5 weitere Kerndienste) und "sync_time <ntp-server>" (Funktion 5,
# NTP-Servername aus den Plugin-Einstellungen). Beide werden hier UND vom Python-Daemon gegen
# ein striktes Muster validiert, bevor sie an systemctl/ntpdate übergeben werden. Kein
# Shell-Passthrough, keine Sonderzeichen, kein "ALL" in sudoers.
#
# "restart_networking" (Funktion 5 Auto-Heal, Internet) ist ein fester Unterbefehl OHNE
# Argument – bewusst auf Neustart eines bestehenden Dienstes beschränkt, KEIN Remounten von
# Dateisystemen o.ä. (zu riskant für unbeaufsichtigte Kundenstandorte).
#
# Das LoxBerry MQTT-Gateway (mqttgateway.pl) ist bewusst NICHT Teil dieses Root-Helpers – es
# ist kein systemd-Dienst, sondern ein LoxBerry-Kern-Daemon. Der Python-Daemon prüft/beendet
# ihn stattdessen unprivilegiert per pgrep/pkill (funktioniert weil er als loxberry-User läuft)
# und liest seinen Verbindungsstatus direkt über die vom Gateway selbst veröffentlichten
# MQTT-Topics – beides braucht kein Root.

set -u

ACTION="${1:-}"

_find_netbird() {
    command -v netbird 2>/dev/null || echo "/usr/bin/netbird"
}

_valid_service_name() {
    [[ "$1" =~ ^[A-Za-z0-9_.@-]{1,64}$ ]]
}

_valid_ntp_server() {
    [[ "$1" =~ ^[A-Za-z0-9_.-]{1,253}$ ]]
}

case "$ACTION" in
    check)
        NETBIRD="$(_find_netbird)"
        if [ ! -x "$NETBIRD" ] && ! command -v netbird >/dev/null 2>&1; then
            echo "netbird-Binary nicht gefunden" >&2
            exit 127
        fi
        "$NETBIRD" status --detail 2>&1
        exit $?
        ;;
    restart)
        if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q "^netbird\.service"; then
            systemctl restart netbird
            exit $?
        fi
        NETBIRD="$(_find_netbird)"
        if command -v netbird >/dev/null 2>&1; then
            "$NETBIRD" service restart
            exit $?
        fi
        echo "Weder systemd-Unit 'netbird' noch netbird-Binary gefunden" >&2
        exit 127
        ;;
    reboot)
        # WICHTIG: NICHT als Hintergrundjob "(sleep N && reboot) &" auslösen! Auf einem
        # systemd-System kann pam_systemd beim Beenden der sudo-Session (also sobald dieses
        # Skript zurückkehrt) alle Prozesse der Session inkl. verwaister Hintergrundjobs
        # beenden, BEVOR der Sleep durchgelaufen ist – der Reboot würde dann lautlos nie
        # ausgeführt. "systemctl reboot" ist dagegen von sich aus asynchron: es meldet die
        # Anfrage an systemd (PID 1) und kehrt sofort zurück, der eigentliche Reboot läuft
        # danach komplett unabhängig vom aufrufenden Prozessbaum weiter. Der aufrufende
        # Python-Daemon persistiert den State bereits VOR diesem Aufruf – keine Wartezeit nötig.
        if command -v systemctl >/dev/null 2>&1; then
            systemctl reboot
            exit $?
        fi
        /sbin/reboot
        exit $?
        ;;
    restart_service)
        SVC="${2:-}"
        if ! _valid_service_name "$SVC"; then
            echo "Ungültiger oder fehlender Dienstname: '${SVC}'" >&2
            exit 2
        fi
        if ! command -v systemctl >/dev/null 2>&1; then
            echo "systemctl nicht verfügbar" >&2
            exit 127
        fi
        systemctl restart "$SVC"
        exit $?
        ;;
    restart_networking)
        # Funktion 5 Auto-Heal (Internet-Erreichbarkeit): versucht der Reihe nach die auf
        # LoxBerry-Systemen üblichen Netzwerk-Dienste. Kein Interface-Down/Up-Gebastel –
        # nur ein regulärer Dienst-Neustart.
        if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q "^dhcpcd\.service"; then
            systemctl restart dhcpcd
            exit $?
        fi
        if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q "^networking\.service"; then
            systemctl restart networking
            exit $?
        fi
        echo "Weder dhcpcd- noch networking-Dienst gefunden" >&2
        exit 127
        ;;
    sync_time)
        # Funktion 5 Auto-Heal (Zeit-Synchronisation): setzt die Systemzeit DIREKT per ntpdate
        # gegen den vom Plugin konfigurierten NTP-Server (denselben, gegen den auch gemessen
        # wurde) – funktioniert unabhängig davon ob systemd-timesyncd/chrony überhaupt
        # installiert sind (Live-Fund: auf manchen LoxBerry-Systemen ist keins von beiden
        # vorhanden, LoxBerrys eigene "Systemzeit"-Oberfläche nutzt ebenfalls ntpdate).
        # systemd-timesyncd-Neustart nur als Fallback falls ntpdate fehlt.
        NTPSERVER="${2:-pool.ntp.org}"
        if ! _valid_ntp_server "$NTPSERVER"; then
            echo "Ungültiger NTP-Servername: '${NTPSERVER}'" >&2
            exit 2
        fi
        if command -v ntpdate >/dev/null 2>&1; then
            ntpdate -u "$NTPSERVER"
            exit $?
        fi
        if command -v timedatectl >/dev/null 2>&1; then
            timedatectl set-ntp true 2>/dev/null
        fi
        if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q "^systemd-timesyncd\.service"; then
            systemctl restart systemd-timesyncd
            exit $?
        fi
        echo "Weder ntpdate noch systemd-timesyncd verfügbar" >&2
        exit 127
        ;;
    *)
        echo "Verwendung: $0 {check|restart|reboot|restart_service <name>|restart_networking|sync_time <ntp-server>}" >&2
        exit 1
        ;;
esac
