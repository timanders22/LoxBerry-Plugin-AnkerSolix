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
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

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
# Zweite Schreibweise desselben Skripts fuer den Vergleich weiter unten: wurde
# der Dienst ueber einen anderen Weg auf dieselbe Datei gestartet (Symlink im
# Pfad, LBHOMEDIR gegen den aufgeloesten Ablageort), steht in seiner
# Befehlszeile eine andere Zeichenkette fuer dieselbe Datei. Ein Vergleich, der
# das uebersieht, meldet "laeuft nicht" und laesst den Dienst stehen.
SKRIPT_R=$(readlink -f "$SKRIPT" 2>/dev/null)
[ -n "$SKRIPT_R" ] || SKRIPT_R="$SKRIPT"
# Der Dienst laeuft als loxberry; wo es den Benutzer nicht gibt, als der
# eigene. Die Suche ueber /proc sieht nur dessen Prozesse an.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

# ---------- Die eigenen Prozesse erkennen ----------
#
# Argumentweise, nicht ueber eine Teilzeichenkette (Regeln/03, "Prozesse
# argumentweise erkennen"). Bis 0.9.17 stand hier
#     grep -qa "ankersolix.py" "/proc/$P/cmdline"
# und das trifft JEDE Befehlszeile, in der die Zeichenkette irgendwo vorkommt:
# einen Editor mit der Datei offen, ein Sicherungsskript, das den Ordner
# durchsucht, und den Einmallauf der eigenen Oberflaeche. In WSL gemessen
# (Pruefung-AnkerSolix-0.9.17, Fall 1): ein fremder Prozess
# "python3 -c … <dienstpfad>", dessen Nummer in der PID-Datei stand, galt als
# Dienst - "status" meldete "laeuft 2133", und nach "stop" war er tot.
#
# Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
# genau der eigene Dienstpfad. Das dritte Argument schliesst die Einmallaeufe
# aus (--einmal, --selbsttest, --vorgaben, --freigeben) - sie laufen als
# eigener Prozess, sind aber nicht der Dauerlaeufer und duerfen von "stop"
# nicht getroffen werden (Fall 4). Der Dauerlaeufer wird an genau einer Stelle
# gestartet, in starten(), als  "$PY" "$SKRIPT".
#
# Gelesen wird ohne Hilfsprogramm: "read -d ''" zerlegt die Befehlszeile am
# Nullbyte. Das spart je Prozess einen Aufruf von tr - der Waechter laeuft
# minuetlich.
ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' ak_a0 || return 1
        IFS= read -r -d '' ak_a1 || return 1
        case "${ak_a0##*/}" in python|python3|python3.*) ;; *) return 1 ;; esac
        if [ "$ak_a1" != "$SKRIPT" ]; then
            [ "$(readlink -f "$ak_a1" 2>/dev/null)" = "$SKRIPT_R" ] || return 1
        fi
        IFS= read -r -d '' ak_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Dienste, aufsteigend und ohne Dubletten.
#
# Zwei Quellen, weil keine allein reicht:
#   - die Suche ueber /proc findet auch einen Dienst OHNE PID-Datei.
#     purge_installation raeumt data/plugins/<ordner>/ bei jedem Upgrade ab
#     (Regeln/06); der Minutentakt kann in der Luecke einen zweiten starten.
#     In WSL gemessen (Fall 2): "stop" meldete "angehalten", und danach lief
#     noch ein eigener Dienst.
#   - die PID-Datei findet auch einen Dienst, der einem anderen Benutzer
#     gehoert (von Hand als root gestartet) und deshalb durch den
#     Benutzerfilter faellt.
dienste() {
    {
        for ak_d in /proc/[0-9]*; do
            ist_dienst "${ak_d#/proc/}" || continue
            [ "$(stat -c %u "$ak_d" 2>/dev/null)" = "$DIENST_UID" ] || continue
            echo "${ak_d#/proc/}"
        done
        ak_p=""
        [ -f "$PID" ] && IFS= read -r ak_p < "$PID" 2>/dev/null
        case "$ak_p" in
            ''|*[!0-9]*) ;;
            *) ist_dienst "$ak_p" && echo "$ak_p" ;;
        esac
    } | sort -un
}

laeuft() {
    [ -n "$(dienste)" ]
}

starten() {
    LAUFEND=$(dienste)
    if [ -n "$LAUFEND" ]; then
        ERSTE=$(printf '%s\n' "$LAUFEND" | head -n 1)
        # Die PID-Datei nachziehen, wenn sie fehlt oder veraltet ist. Die
        # Nummer ist argumentweise geprueft - eine ungepruefte Nummer aus einer
        # Mustersuche darf hier nie hinein (der Fehler der Bewaesserungslinie,
        # Bestand-2026-09-18/klasse-F, Zeile 11).
        echo "$ERSTE" > "$PID" 2>/dev/null
        echo "laeuft bereits (PID $ERSTE)"
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
    # ALLE eigenen Dienste, nicht nur den aus der PID-Datei.
    ZIEL=$(dienste)
    if [ -z "$ZIEL" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    kill $ZIEL 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste)" ] || break
        sleep 1
    done
    # Vor dem harten Signal wird NEU gesucht, nicht die Liste von vorhin
    # wiederverwendet: zwischen den beiden Signalen kann ein Prozess enden und
    # seine Nummer neu vergeben werden.
    REST=$(dienste)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    # "angehalten" ist eine Zusicherung, kein Rueckgabewert: es wird nachgesehen
    # (Regeln/01, "Wirkung pruefen, nicht Rueckgabewert").
    UEBRIG=$(dienste)
    if [ -n "$UEBRIG" ]; then
        echo "FEHLER: Dienst laeuft weiter (PID $(printf '%s' "$UEBRIG" | tr '\n' ' '))"
        return 1
    fi
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        # Gemeldet werden die gefundenen Nummern, nicht der Inhalt der
        # PID-Datei: liegt dort eine fremde oder veraltete Nummer, waere sie
        # eine Falschaussage. Laufen zwei, stehen beide da.
        LAUFEND=$(dienste)
        if [ -n "$LAUFEND" ]; then
            echo "laeuft $(printf '%s' "$LAUFEND" | tr '\n' ' ')"
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
