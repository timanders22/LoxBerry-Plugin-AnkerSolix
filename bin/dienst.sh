#!/bin/bash
# Anker SOLIX - Start, Stopp und Waechter des Abrufdienstes.
#
# Der Ordnername kommt aus dem EIGENEN Ablageort, die Wurzel aus $LBHOMEDIR
# (weiter unten, mit Abgleich gegen den Ablageort) - nicht ueber
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

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd -P)   # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")

# Die Wurzel wird GELESEN, nicht geraten (Regeln/03, Stufe 1 ist $LBHOMEDIR;
# Vorlage lb_wurzel_suchen() in Regeln/06; Bestand-2026-09-18/klasse-H,
# Bauart H1).
#
# Bis 0.9.18 stand hier  LBHOMEDIR=$(cd "$SELF/../../.." && pwd)  - das
# UEBERSCHRIEB ein gesetztes $LBHOMEDIR mit einer Rechnung aus dem Ablageort,
# und weiter unten legte ein "mkdir -p" bei JEDEM Aufruf die Ordner an. In WSL
# gemessen (Bestand-2026-09-18/klasse-H, Fall M7; Pruefung-AnkerSolix-0.9.19,
# Faelle H1-H4): ein "dienst.sh status" aus einem Pruefarchiv unter
# <Wurzel>/pruefung/ankersolix/bin legte in der laufenden Anlage
# data/plugins/bin und log/plugins/bin an. Und aus einer Kopie des ganzen
# Baums lief ein ZWEITER Dienst an, der dasselbe Anker-Konto abfragt (H8).
#
# Drei Stufen, in dieser Reihenfolge:
#   1. $LBHOMEDIR aus der Umgebung, wenn es eine Wurzel bezeichnet (am Geraet
#      steht es in /etc/environment, der Cron laedt es ueber pam_env),
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins UND
#      config/system/general.json traegt,
#   3. drei Ebenen ueber dem Ablageort - das bisherige Verhalten.
ak_wurzel_suchen() {
    ak_v="$SELF"
    ak_i=0
    while [ -n "$ak_v" ] && [ "$ak_v" != "/" ] && [ "$ak_i" -lt 8 ]; do
        if [ -d "$ak_v/config/plugins" ] && [ -d "$ak_v/data/plugins" ] \
           && [ -f "$ak_v/config/system/general.json" ]; then
            echo "$ak_v"
            return 0
        fi
        ak_v=$(dirname "$ak_v")
        ak_i=$((ak_i + 1))
    done
    return 1
}
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
else
    LBHOMEDIR=$(ak_wurzel_suchen) || LBHOMEDIR=$(cd "$SELF/../../.." && pwd -P)
fi

# Laeuft dieses Skript wirklich AUS der Installation unter dieser Wurzel?
# Nur dann darf es anlegen, starten und anhalten. Aus einem Pruefarchiv, einem
# ausgepackten Archiv oder einer Baumkopie heraus waere der Ordnername "bin"
# oder die Wurzel eine fremde - geschrieben wuerde in die laufende Anlage oder
# ein zweiter Dienst gestartet. Ein Schutz faellt geschlossen aus (CLAUDE.md 4);
# "status" liest nur und bleibt erlaubt.
INSTALLIERT=0
[ "$SELF" = "$LBHOMEDIR/bin/plugins/$PNAME" ] && INSTALLIERT=1

PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". Sie liegt NEBEN dem Datenordner, weil
# purge_installation den Ordner selbst loescht (Regeln/06). preupgrade.sh legt
# sie als Erstes an, postinstall.sh entfernt sie per trap - auch nach einem
# Abbruch.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
# Nur postinstall.sh setzt das: dort ist die Marke die eigene, und der Start
# ist der letzte Schritt der Installation. Als ARGUMENT, nicht als
# Umgebungsvariable - der Abstieg auf loxberry oben laeuft ueber su, und was
# su von der Umgebung durchreicht, ist hier nicht gemessen; die Argumente
# reicht die Zeile nachweislich durch ("$0" "$@").
AK_TROTZ_MARKE=0
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

# Angelegt wird erst beim START (starten()), nicht bei jedem Aufruf. Bis 0.9.18
# stand hier ein "mkdir -p" auf oberster Ebene: auch "status" und "stop" legten
# damit an - aus dem Pruefarchiv in der laufenden Anlage (Fall H1), und in der
# Upgrade-Luecke einen Datenordner, den purge_installation gerade abgeraeumt
# hatte (Bestand-2026-09-18/klasse-H, Fall M5; hier Fall H7a).

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

# ---------- Laeuft gerade eine Aktualisierung? ----------
#
# Gemessen am 18.09.2026 in WSL (Pruefung-AnkerSolix-0.9.18, Faelle L4/L5):
# in der Luecke zwischen preupgrade.sh und postinstall.sh ist
# config/plugins/<ordner>/ weg. Die Oberflaeche zeigt dann zwei leere
# Kontofelder an; wer dort speichert, schreibt eine zugang.json OHNE Passwort,
# und postinstall.sh spielt die Sicherung nicht mehr ein, weil die Datei
# "Inhalt" hat. Der Knopf "Dienst starten" liess den Dienst danach MIT dieser
# leeren Zugangsdatei anlaufen (Fall L5b, 1 Prozess in der Luecke).
#
# Nur eine Marke, die hoechstens eine Stunde alt ist, zaehlt. Aelter, aus der
# Zukunft oder unlesbar: eine abgebrochene Installation hat sie liegen lassen,
# und der Dienst darf nicht fuer immer stillstehen.
#
# Die Uhr wird gemessen, nicht angenommen: liefert "date" nichts - unter Last
# kann ein fork scheitern -, dann rechnete die Schale mit einer leeren
# Zeichenkette, das Alter fiele negativ aus und der Dienst startete MITTEN in
# der Aktualisierung. Ein Schutz faellt geschlossen aus (Regeln/01): ohne Uhr
# gilt die Marke.
marke_gilt() {
    [ -f "$MARKE" ] || return 1
    ak_seit=$(cat "$MARKE" 2>/dev/null)
    case "$ak_seit" in ''|*[!0-9]*) ak_seit=0 ;; esac
    ak_jetzt=$(date +%s 2>/dev/null)
    case "$ak_jetzt" in ''|*[!0-9]*) ak_jetzt="" ;; esac
    [ -z "$ak_jetzt" ] && return 0
    ak_alter=$(( ak_jetzt - ak_seit ))
    # Ein paar Minuten "Zukunft" sind eine nachgestellte Uhr, keine Luege.
    [ "$ak_alter" -ge -300 ] && [ "$ak_alter" -lt 3600 ]
}

starten() {
    if [ "$AK_TROTZ_MARKE" != "1" ] && marke_gilt; then
        # Ende mit 0: eine laufende Aktualisierung ist kein Fehler. Der
        # Waechter soll deshalb auch keine Fehlerzeile schreiben.
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet."
        return 0
    fi
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
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
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

# Was schreibt oder Signale schickt, laeuft nur aus der Installation (siehe
# INSTALLIERT oben). Gemessen: Pruefung-AnkerSolix-0.9.19, Faelle H3, H4, H8.
case "$1" in
    start|stop|restart|waechter)
        if [ "$INSTALLIERT" != "1" ]; then
            echo "FEHLER: dieses Skript liegt nicht unter $LBHOMEDIR/bin/plugins/$PNAME."
            echo "FEHLER: Aus einem Pruefarchiv, einem ausgepackten Archiv oder einer Kopie"
            echo "FEHLER: wird nichts angelegt, gestartet oder angehalten."
            exit 1
        fi
        ;;
esac

case "$1" in
    start)
        # "--trotz-marke" kommt ausschliesslich aus postinstall.sh.
        if [ "${2:-}" = "--trotz-marke" ]; then
            AK_TROTZ_MARKE=1
        fi
        starten
        ;;
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
        echo "Aufruf: $0 {start [--trotz-marke]|stop|restart|status|waechter}"
        exit 2
        ;;
esac
