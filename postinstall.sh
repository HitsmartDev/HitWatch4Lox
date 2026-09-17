#!/bin/bash
# HitWatch4Lox postinstall.sh – läuft als loxberry User nach der Installation
# WICHTIG: postinstall.sh läuft VOR postroot.sh! Config-Restore und sudoers-Setup
# erfolgen erst in postroot.sh. Deshalb KEIN Daemon-Start hier – postroot.sh übernimmt das.

LBHOMEDIR="REPLACELBHOMEDIR"
PLUGINDIR="REPLACELBPPLUGINDIR"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGINDIR}/hitwatch4lox.cfg"
CFGDEF="${LBHOMEDIR}/config/plugins/${PLUGINDIR}/hitwatch4lox.cfg.default"

echo "<INFO> HitWatch4Lox postinstall startet..."
echo "<INFO> LBHOMEDIR=${LBHOMEDIR}"
echo "<INFO> PLUGINDIR=${PLUGINDIR}"

# paho-mqtt installieren: pip (kein root nötig) ist der korrekte Weg in postinstall.sh.
# apt-get würde sudo erfordern, das als loxberry-User hier nicht verfügbar ist.
# MQTT ist bei HitWatch4Lox rein optional (Statusanzeige) – ein Fehlschlag hier ist unkritisch.
echo "<INFO> Installiere paho-mqtt..."
pip3 install paho-mqtt --break-system-packages 2>/dev/null || \
pip3 install paho-mqtt 2>/dev/null || \
python3 -m pip install paho-mqtt --break-system-packages 2>/dev/null || true
if python3 -c "import paho.mqtt" 2>/dev/null; then
    echo "<OK> paho-mqtt verfügbar (neu installiert oder bereits vorhanden)"
else
    echo "<WARNING> paho-mqtt Installation fehlgeschlagen – MQTT-Statusanzeige bleibt deaktiviert, Watchdog läuft trotzdem"
fi

# Standard-Config anlegen wenn noch nicht vorhanden (nur bei Erstinstallation)
if [ ! -f "$CFGFILE" ]; then
    if [ -f "$CFGDEF" ]; then
        cp "$CFGDEF" "$CFGFILE"
        echo "<OK> Standard-Config angelegt: ${CFGFILE}"
    else
        echo "<WARNING> cfg.default nicht gefunden: ${CFGDEF}"
    fi
else
    echo "<INFO> Config bereits vorhanden: ${CFGFILE}"
fi

# Python-Daemon und Helper-Skript ausführbar machen
DAEMON_PY="${LBHOMEDIR}/bin/plugins/${PLUGINDIR}/hitwatch4lox_daemon.py"
if [ -f "${DAEMON_PY}" ]; then
    chmod +x "${DAEMON_PY}"
    echo "<OK> Daemon ausführbar: ${DAEMON_PY}"
fi
HELPER="${LBHOMEDIR}/bin/plugins/${PLUGINDIR}/netbird_watchdog_helper.sh"
if [ -f "${HELPER}" ]; then
    chmod +x "${HELPER}"
    echo "<OK> Root-Helper ausführbar: ${HELPER}"
fi

# Daemon-Start erfolgt in postroot.sh – dort ist Config bereits wiederhergestellt
# und sudoers ist eingerichtet. postinstall.sh läuft VOR postroot.sh.
echo "<INFO> Daemon-Start erfolgt in postroot.sh nach Config-Restore."

echo "<OK> HitWatch4Lox postinstall abgeschlossen."
exit 0
