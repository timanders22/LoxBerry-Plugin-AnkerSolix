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
        if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
            kill "$P" 2>/dev/null || true
            i=0
            while [ $i -lt 10 ] && kill -0 "$P" 2>/dev/null; do
                sleep 1
                i=$((i + 1))
            done
            # Nur hart beenden, wenn er noch lebt und es wirklich unser Dienst ist.
            if kill -0 "$P" 2>/dev/null && grep -qa "ankersolix.py" "/proc/$P/cmdline" 2>/dev/null; then
                kill -9 "$P" 2>/dev/null || true
            fi
            # Nur HIER gemeldet: eine liegengebliebene PID-Datei allein
            # ist kein laufender Dienst.
            echo "<INFO> Laufender Dienst angehalten (Rueckfallweg)."
        fi
        rm -f "$PID"
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
