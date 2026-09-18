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
# EINZIGE Ausnahmen mit einem vom Nutzer beeinflussten Argument:
# - "restart_service <name>" (Funktion 4): Dienstname aus den Plugin-Einstellungen (je nach
#   LoxBerry-Setup unterschiedlich). Wird hier UND vom Python-Daemon gegen ein striktes Muster
#   (nur Buchstaben/Ziffern/._@-) validiert, bevor er an systemctl übergeben wird.
# - "link_check <pid> <port>" (Funktion 4): rein lesende Prüfung ob eine established TCP-Verbindung
#   vom gegebenen Prozess zum gegebenen Port existiert (ss -tnp). PID/Port sind rein numerisch und
#   werden ebenfalls streng validiert. Kein Shell-Passthrough, keine Sonderzeichen, kein "ALL".

set -u

ACTION="${1:-}"

_find_netbird() {
    command -v netbird 2>/dev/null || echo "/usr/bin/netbird"
}

_valid_service_name() {
    [[ "$1" =~ ^[A-Za-z0-9_.@-]{1,64}$ ]]
}

_valid_number() {
    [[ "$1" =~ ^[0-9]{1,10}$ ]]
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
    link_check)
        PID="${2:-}"
        PORT="${3:-}"
        if ! _valid_number "$PID" || ! _valid_number "$PORT"; then
            echo "Ungültige PID/Port: '${PID}' / '${PORT}'" >&2
            exit 2
        fi
        if ! command -v ss >/dev/null 2>&1; then
            echo "ss nicht verfügbar" >&2
            exit 127
        fi
        # Established TCP-Verbindung vom gegebenen Prozess zum gegebenen Zielport? -p braucht
        # Root um PIDs anderer User (z.B. mosquitto läuft oft als eigener System-User) zu sehen.
        if ss -H -tnp state established "( dport = :${PORT} )" 2>/dev/null | grep -q "pid=${PID}[,)]"; then
            exit 0
        fi
        exit 1
        ;;
    *)
        echo "Verwendung: $0 {check|restart|reboot|restart_service <name>|link_check <pid> <port>}" >&2
        exit 1
        ;;
esac
