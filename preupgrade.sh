#!/bin/bash
# HitWatch4Lox preupgrade.sh
# Wird von LoxBerry VOR dem Überschreiben der Dateien aufgerufen (Plugin-Update).
# WICHTIG: Backup muss nach /tmp/ gehen, weil LoxBerry den config/-Ordner beim Update
# komplett löscht und neu anlegt – ein Backup im config/-Ordner würde mitgelöscht!

PLUGIN="hitwatch4lox"
LBHOMEDIR="${LBHOMEDIR:-/opt/loxberry}"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGIN}/${PLUGIN}.cfg"
CFGBAK_TMP="/tmp/${PLUGIN}_cfg_upgrade.bak"
PIDFILE="${LBHOMEDIR}/log/plugins/${PLUGIN}/daemon.pid"

# Config sichern (vor Update, da LoxBerry config/-Ordner beim Update löscht)
if [ -f "${CFGFILE}" ]; then
    cp "${CFGFILE}" "${CFGBAK_TMP}"
    echo "<OK> Konfiguration nach /tmp gesichert: ${CFGBAK_TMP}"
else
    echo "<INFO> Keine bestehende Konfiguration vorhanden – kein Backup nötig"
fi

# Alten Daemon stoppen BEVOR neue Dateien installiert werden.
# WICHTIG: NICHT 'daemon stop' verwenden – das würde den Enable-Marker löschen und den
# Autostart nach dem Update unterdrücken. Direktes Beenden über PID + pkill lässt ihn stehen.
echo "<INFO> Stoppe Daemon vor Plugin-Update..."
if [ -f "${PIDFILE}" ]; then
    PID=$(cat "${PIDFILE}" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        kill "$PID" 2>/dev/null
        sleep 1
        kill -0 "$PID" 2>/dev/null && kill -9 "$PID" 2>/dev/null || true
    fi
    rm -f "${PIDFILE}"
fi
pkill -f "hitwatch4lox_daemon.py" 2>/dev/null || true
sleep 1
echo "<OK> Daemon-Stop abgeschlossen"

exit 0
