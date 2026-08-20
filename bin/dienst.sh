#!/bin/bash
# Anker SOLIX - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf. Derzeit legt dieses Plugin keinen an - es
# bringt keinen daemon/-Ordner mit, den LoxBerry verlinken wuerde. Aber der
# Pfad ist die Identitaet dieses Skripts: aus ihm kommen Plugin-Name, Daten-,
# Log- und Konfigurationsverzeichnis. Wird es irgendwann doch ueber einen
# Symlink aufgerufen, waere PNAME der Name des VERLINKENDEN Ordners, und der
# Dienst schriebe seine Daten woanders hin - ohne Fehlermeldung. Zwei Woerter
# Vorsorge gegen einen Fehler, den man erst bemerkt, wenn Werte fehlen.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)   # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/ankersolix.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das Python-Skript nach stderr schreibt, bevor sein
# Protokoll steht (Syntaxfehler, fehlende Bibliothek, Abbruch im Importpfad).
#
# Bis 0.9.6 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, die
# ankersolix.py mit einem RotatingFileHandler fuehrt. Beim Ueberlauf benennt
# der Handler die Datei um und legt eine neue an - der Anhaenge-Deskriptor
# dieser Shell zeigt danach weiter auf die WEGGESCHOBENE Datei. Die
# Startmeldungen landen ab da in einer Datei, die niemand mehr ansieht, und
# die Groessenkappung greift fuer sie gar nicht mehr.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/ankersolix_start.log"
PY="$SELF/venv/bin/python3"
SKRIPT="$SELF/ankersolix.py"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein
    grep -qa "ankersolix.py" "/proc/$P/cmdline" 2>/dev/null || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zugang.json" ]; then
        echo "FEHLER: Zugangsdaten fehlen ($PCONFIG/zugang.json). Erst in der Oberflaeche eintragen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Startdatei, NICHT in das Protokoll: dort schreibt
    # ausschliesslich der RotatingFileHandler des Python-Skripts. Das Skript
    # protokolliert deshalb auch nicht zusaetzlich nach stdout.
    # Beim Start gekappt: diese Datei sammelt nur die Ausgabe EINES Laufes.
    # Ohne Kappung waere sie der einzige Weg im Plugin, der unbegrenzt waechst.
    : > "$STARTLOG"
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    # Die ersten Zeilen gleich mitgeben: wer den Knopf in der Oberflaeche
    # drueckt, sieht sonst nur "Start fehlgeschlagen" und muss suchen.
    if [ -s "$STARTLOG" ]; then
        echo "--- $STARTLOG ---"
        head -n 12 "$STARTLOG"
    fi
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            # Zaehler VOR dem Start hochsetzen: gelingt der Start nicht,
            # ist der Versuch trotzdem geschehen und gehoert gezaehlt.
            # Die Oberflaeche zeigt den Stand im Reiter Test - ein Dienst,
            # den der Waechter stuendlich aufsammelt, sieht sonst gesund aus.
            ZAEHLER="$PDATA/waechter.txt"
            N=$(cat "$ZAEHLER" 2>/dev/null | head -n 1)
            case "$N" in ''|*[!0-9]*) N=0 ;; esac
            printf '%s
%s
' "$((N + 1))" "$(date '+%Y-%m-%d %H:%M:%S')" > "$ZAEHLER"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
