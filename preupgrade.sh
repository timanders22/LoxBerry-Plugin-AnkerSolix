#!/bin/bash
# Anker SOLIX - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Vor dem Upgrade: laufenden Dienst anhalten und die Konfiguration ausserhalb
# des Plugin-Ordners sichern. Die Zugangsdaten liegen in einer eigenen Datei
# und werden getrennt gesichert (Rechte 0600 bleiben erhalten).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ankersolix}"
BASE="${ARGV5:-$LBHOMEDIR}"

# ---------- Zuerst die Marke "Aktualisierung laeuft" ----------
#
# Der Installer legt die Cron-Datei rund eine Minute VOR postinstall.sh neu an
# (Regeln/06, am Geraet gemessen: preupgrade 03:31:30, Cron 03:31:32,
# postinstall 03:32:24). In dieser Luecke ist config/plugins/<ordner>/ bereits
# abgeraeumt, die Oberflaeche aber erreichbar.
#
# Was das hier kostet, ist am 18.09.2026 in WSL gemessen
# (Pruefung-AnkerSolix-0.9.18): die Oberflaeche zeigt in der Luecke zwei leere
# Kontofelder; ein Speichern schreibt eine zugang.json ohne Passwort, und
# postinstall.sh spielt die Sicherung dann nicht mehr ein - das
# Anker-Kontopasswort war nach dem Upgrade fort (Fall L4d). Danach liess sich
# der Dienst ueber den Knopf in der Luecke mit dieser leeren Datei starten
# (Fall L5b). Die Gegenprobe ausserhalb der Luecke ist gruen (Fall G8c): das
# Loch ist die Luecke, nicht das Speichern.
#
# Die Marke liegt NEBEN dem Datenordner - purge_installation loescht den
# Ordner selbst. Sie faellt zuerst, noch vor dem Anhalten: zwischen dem ersten
# Befehl dieses Skripts und dem Anhalten kann der Minutentakt laufen.
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" 2>/dev/null
if [ -s "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ]; then
    echo "<OK> Dienststart und Oberflaeche bis zum Ende der Installation gesperrt."
else
    echo "<INFO> Die Marke fuer die laufende Aktualisierung liess sich nicht"
    echo "<INFO> anlegen ($BASE/data/plugins/$PFOLDER.upgrade_laeuft)."
fi

# Anhalten ueber das Dienstskript, nicht von Hand.
#
# Frueher stand hier: kill, zwei Sekunden warten, dann BEDINGUNGSLOS kill -9.
# Gleich zwei Fehler darin. Erstens sind zwei Sekunden zu knapp - der Dienst
# haelt eine offene HTTP-Sitzung zur Anker-Cloud und schreibt seine Dateien
# ueber os.replace(); trifft ein SIGKILL genau dazwischen, bleibt Unfertiges
# liegen. Zweitens wurde kill -9 auch dann geschickt, wenn der Prozess laengst
# weg war - und Prozessnummern werden wiederverwendet. Im unguenstigen Fall
# haette das Update also einen voellig fremden Prozess erschlagen.
#
# dienst.sh stop macht es richtig: freundlich beenden, bis zu zehn Sekunden
# warten, dabei jede Sekunde nachsehen, und nur wenn der Prozess dann noch
# lebt UND nachweislich unser Skript ist, hart beenden.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"

# ---------- Die eigenen Prozesse erkennen ----------
#
# Argumentweise (Regeln/03, "Prozesse argumentweise erkennen"): argv[0] ist ein
# Python, argv[1] ist genau der eigene Dienstpfad, ein drittes Argument gibt es
# nicht. Bis 0.9.17 stand im Rueckfallweg unten das erste Signal ganz ohne
# Pruefung und vor dem harten eine Teilzeichenkettensuche
# (grep -qa "ankersolix.py"). In WSL gemessen
# (Pruefung-AnkerSolix-0.9.17, Fall 6): ein fremder Prozess
# "python3 -c … <dienstpfad>", dessen Nummer in der PID-Datei stand, war nach
# preupgrade.sh tot.
#
# Die zweite Schreibweise deckt den Fall ab, dass der Dienst ueber einen
# anderen Pfad auf dieselbe Datei gestartet wurde (bin/dienst.sh loest seinen
# Ablageort mit readlink -f auf, hier kommt er aus $5).
AK_SKRIPT="$BASE/bin/plugins/$PFOLDER/ankersolix.py"
AK_SKRIPT_R=$(readlink -f "$AK_SKRIPT" 2>/dev/null)
[ -n "$AK_SKRIPT_R" ] || AK_SKRIPT_R="$AK_SKRIPT"
AK_UID=$(id -u loxberry 2>/dev/null || id -u)

ak_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' ak_a0 || return 1
        IFS= read -r -d '' ak_a1 || return 1
        case "${ak_a0##*/}" in python|python3|python3.*) ;; *) return 1 ;; esac
        if [ "$ak_a1" != "$AK_SKRIPT" ]; then
            [ "$(readlink -f "$ak_a1" 2>/dev/null)" = "$AK_SKRIPT_R" ] || return 1
        fi
        IFS= read -r -d '' ak_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Dienste des eigenen Benutzers - auch die ohne PID-Datei.
ak_dienste() {
    for ak_d in /proc/[0-9]*; do
        ak_ist_dienst "${ak_d#/proc/}" || continue
        [ "$(stat -c %u "$ak_d" 2>/dev/null)" = "$AK_UID" ] || continue
        echo "${ak_d#/proc/}"
    done
    return 0
}

# Beendet sie: freundlich, bis zu zehn Sekunden Zeit, dann hart - und vor JEDEM
# Signal wird neu gesucht, auch vor dem kill -9. Gibt die Nummern aus, die beim
# ersten Signal gemeint waren.
ak_dienste_beenden() {
    ak_ziel=$(ak_dienste)
    [ -n "$ak_ziel" ] || return 0
    kill $ak_ziel 2>/dev/null
    ak_i=0
    while [ $ak_i -lt 10 ] && [ -n "$(ak_dienste)" ]; do
        sleep 1
        ak_i=$((ak_i + 1))
    done
    ak_rest=$(ak_dienste)
    [ -n "$ak_rest" ] && kill -9 $ak_rest 2>/dev/null
    echo $ak_ziel
}

# ---------- Lief der Dienst? ZUERST merken ----------
# Das ist die Berichtigung eines Fehlers, der bis 0.9.13 in jedem Update
# steckte: `dienst.sh stop` entfernt den Sollmerker `soll_laufen`, der
# Installer raeumt gleich darauf data/plugins/<ordner>/ vollstaendig ab,
# und postinstall.sh rief an keiner Stelle `start`. Nach JEDEM Update
# stand das Plugin still - der minuetliche Waechter findet ohne
# Sollmerker nichts zu tun, die Installation meldet Erfolg, und die
# Oberflaeche zeigt "gestoppt", als haette der Betreiber es selbst
# angehalten. Dieselbe Stelle haben Bewaesserung und Weissware bereits
# berichtigt; postinstall.sh holt den Dienst jetzt zurueck.
#
# Der Merker liegt NEBEN dem Konfigordner: alles darin und im Datenordner
# ist nach purge_installation weg.
LIEF="$BASE/config/plugins/$PFOLDER.backup.lief"
rm -f "$LIEF"
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    : > "$LIEF" || true
    echo "<INFO> Der Dienst lief - er wird nach dem Upgrade wieder gestartet."
fi

# Die Meldung haengt am Merker, nicht am blossen Aufruf: `anhalten()` gibt
# auch ohne laufenden Dienst 0 zurueck („laeuft nicht"), und mit `|| true`
# stand die Zeile ohnehin unbedingt da. Gemessen 11.09.2026 ueber den
# Bestand; derselbe Fehler steckte in vier Linien.
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1 || true
    if [ -f "$LIEF" ]; then
        echo "<INFO> Laufender Dienst angehalten."
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
else
    # Rueckfall, falls das Dienstskript fehlt: dieselbe Sorgfalt von Hand.
    PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
    if [ -f "$PID" ]; then
        P=$(cat "$PID" 2>/dev/null)
        # Geprueft wird VOR dem ersten Signal, nicht erst vor dem harten.
        # Prozessnummern werden wiederverwendet: liegt eine alte PID-Datei
        # herum und traegt ihre Zahl inzwischen einen fremden Vorgang, traf
        # das erste Signal genau den.
        if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && ak_ist_dienst "$P"; then
            kill "$P" 2>/dev/null || true
            i=0
            while [ $i -lt 10 ] && kill -0 "$P" 2>/dev/null && ak_ist_dienst "$P"; do
                sleep 1
                i=$((i + 1))
            done
            # Vor dem harten Signal erneut pruefen - er kann inzwischen weg
            # und die Nummer neu vergeben sein.
            if kill -0 "$P" 2>/dev/null && ak_ist_dienst "$P"; then
                kill -9 "$P" 2>/dev/null || true
            fi
            # Nur HIER gemeldet: eine liegengebliebene PID-Datei allein
            # ist kein laufender Dienst.
            echo "<INFO> Laufender Dienst angehalten (Rueckfallweg)."
        elif [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
            echo "<INFO> Die Nummer $P aus der PID-Datei gehoert einem fremden"
            echo "<INFO> Vorgang - es wurde nichts beendet, die Datei wird entfernt."
        fi
        rm -f "$PID"
    fi
    # Dazu jeder eigene Dienst OHNE PID-Datei. purge_installation raeumt
    # data/plugins/<ordner>/ bei jedem Upgrade ab (Regeln/06), der Minutentakt
    # kann in der Luecke einen zweiten starten. In WSL gemessen
    # (Pruefung-AnkerSolix-0.9.17, Fall 6): ohne diesen Schritt lief er durch
    # das ganze Upgrade weiter.
    WAISEN=$(ak_dienste_beenden)
    if [ -n "$WAISEN" ]; then
        echo "<INFO> Ein Dienst ohne PID-Datei lief und wurde beendet (PID $WAISEN)."
    fi
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
for f in ankersolix.json zugang.json; do
    if [ -f "$CFGDIR/$f" ]; then
        cp -p "$CFGDIR/$f" "$BASE/config/plugins/$PFOLDER.backup.$f" || true
    fi
done
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.zugang.json" 2>/dev/null || true
echo "<OK> preupgrade abgeschlossen."
exit 0
