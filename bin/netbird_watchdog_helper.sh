#!/bin/bash
# HitWatch4Lox – Netbird-Watchdog Root-Helper
#
# Der Python-Daemon läuft als loxberry-User (Konvention aller HitSmart LoxBerry-Plugins).
# Netbird-Status abfragen, den Netbird-Dienst neu starten und einen Reboot auslösen
# erfordern jedoch Root-Rechte. Statt einzelne sudo-Kommandos mit Argumenten freizugeben
# (Injection-Risiko, unübersichtliche sudoers-Regeln), kapselt dieses Skript alle
# privilegierten Aktionen hinter festen Unterbefehlen ohne Nutzereingaben.
# postroot.sh gibt in /etc/sudoers.d/hitwatch4lox NUR die exakten Aufrufe frei
# (siehe dort) – niemals "ALL" oder Wildcards.

set -u

ACTION="${1:-}"

_find_netbird() {
    command -v netbird 2>/dev/null || echo "/usr/bin/netbird"
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
        # Asynchron auslösen – der aufrufende Python-Daemon soll den State vor dem
        # eigentlichen Neustart bereits persistiert haben. Kurze Verzögerung gibt
        # dem Daemon Zeit, sauber zu beenden bzw. den letzten Log-Eintrag zu schreiben.
        (sleep 3 && /sbin/reboot) &
        exit 0
        ;;
    *)
        echo "Verwendung: $0 {check|restart|reboot}" >&2
        exit 1
        ;;
esac
