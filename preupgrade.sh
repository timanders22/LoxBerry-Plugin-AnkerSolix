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
# Warnungen werden gezaehlt: die Schlusszeile darf nicht besser aussehen als
# ihr schlechtester Schritt (CLAUDE.md 6).
AK_WARN=0
rm -f "$LIEF"
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    # Zugesagt wird der Neustart nur, wenn der Merker wirklich liegt. Bis 0.9.18
    # stand hier  : > "$LIEF" || true  und die Zusage danach unbedingt; in
    # einem nicht beschreibbaren config/plugins/ entstand kein Merker, und die
    # Zeile versprach trotzdem den Neustart (Pruefung-AnkerSolix-0.9.19, U3b).
    if : 2>/dev/null > "$LIEF" && [ -f "$LIEF" ]; then
        echo "<INFO> Der Dienst lief - er wird nach dem Upgrade wieder gestartet."
    else
        AK_WARN=1
        echo "<WARNING> Der Dienst lief, aber der Merker $LIEF liess sich nicht"
        echo "<WARNING> anlegen. Nach dem Upgrade bitte im Reiter Einstellungen von Hand starten."
    fi
fi

# Die Meldung haengt an der AUSGABE von "dienst.sh stop", nicht am Merker.
# Bis 0.9.18 entschied der Merker: ein Dienst, der ohne Sollmerker lief, wurde
# angehalten und als "lief nicht" gemeldet (Pruefung-AnkerSolix-0.9.19, U1),
# und ein gescheitertes Anhalten ("FEHLER: Dienst laeuft weiter", Rueckgabewert
# 1 aus anhalten()) als "angehalten" (U2). anhalten() sagt "angehalten" erst,
# nachdem es nachgesehen hat, und "laeuft nicht", wenn es nichts fand.
if [ -x "$DIENST" ]; then
    STOP_AUS=$("$DIENST" stop 2>&1)
    STOP_RC=$?
    STOP_LETZTE=$(printf '%s\n' "$STOP_AUS" | tail -n 1)
    if [ "$STOP_RC" = 0 ] && [ "$STOP_LETZTE" = "angehalten" ]; then
        echo "<INFO> Laufender Dienst angehalten."
    elif [ "$STOP_RC" = 0 ] && [ "$STOP_LETZTE" = "laeuft nicht" ]; then
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    else
        AK_WARN=1
        echo "<WARNING> Der Dienst liess sich nicht anhalten (Rueckgabewert $STOP_RC):"
        printf '%s\n' "$STOP_AUS" | head -n 3 | sed 's/^/<WARNING>   /'
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

# ---------- INHALT statt Anfuehrungszeichen ----------
#
# Wortgleich in postinstall.sh - ein Hakenskript kann sich nichts aus dem
# Plugin-Ordner holen. "Inhalt" heisst: ein lesbares JSON-Objekt MIT dem
# Geheimnis der Datei - in ankersolix.json das Aktionstoken (ohne es erreicht
# der Miniserver den Endpunkt nicht mehr), in zugang.json das Passwort des
# Anker-Kontos (die Oberflaeche kann es nicht loeschen, nur ersetzen: ein
# leeres Passwortfeld laesst das gespeicherte stehen, ak_zugang_speichern()).
#
# Rueckgabe: 0 = traegt Inhalt, 1 = fehlt, leer oder "{}",
#            3 = da, aber ohne Inhalt (kein gueltiges JSON-Objekt oder ohne
#                das Geheimnis), 2 = NICHT PRUEFBAR (kein php).
ak_inhalt() {   # $1 Datei, $2 Art: konf | zugang
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $roh = @file_get_contents($argv[1]);
        if ($roh === false) { exit(3); }
        $t = trim($roh);
        if ($t === "" || $t === "{}") { exit(1); }
        $d = json_decode($t, true);
        if (!is_array($d) || count($d) === 0) { exit(3); }
        if ($argv[2] === "zugang") {
            $ok = isset($d["passwort"]) && is_string($d["passwort"]) && $d["passwort"] !== "";
        } else {
            $ok = isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                  && trim($d["aktionstoken"]) !== "";
        }
        exit($ok ? 0 : 3);
    ' -- "$1" "$2" 2>/dev/null
    ak_ir=$?
    case "$ak_ir" in 0|1|3) return "$ak_ir" ;; esac
    return 2
}

# ---------- Sichern: nur Inhalt, und nie ueber die Sicherung hinweg ----------
#
# Bis 0.9.18 stand hier  cp -p "$CFGDIR/$f" "<ordner>.backup.$f"  ohne jede
# Pruefung, direkt auf die Sicherung. Zwei Folgen, beide in WSL gemessen
# (Pruefung-AnkerSolix-0.9.19):
#   - Faelle S1-S3: eine abgeschnittene Datei oder ein {"email":"",
#     "passwort":""} verdraengte die heile Sicherung - das Kontopasswort bzw.
#     das Aktionstoken stand danach nirgends mehr.
#   - Fall S6: cp kappt das Ziel, BEVOR es schreibt. Scheiterte das Schreiben
#     (volle Karte, nachgestellt mit ulimit -f), war die alte Sicherung weg
#     und die neue unvollstaendig.
# Jetzt: pruefen, in eine Nebendatei kopieren, nachlesen, umbenennen. Ein
# Stand ohne Inhalt ueberschreibt die Sicherung nie.
CFGDIR="$BASE/config/plugins/$PFOLDER"
for f in ankersolix.json zugang.json; do
    CF="$CFGDIR/$f"
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    [ -e "$CF" ] || continue
    case "$f" in zugang.json) ART=zugang ;; *) ART=konf ;; esac
    ak_inhalt "$CF" "$ART"; CF_RC=$?
    if [ "$CF_RC" = 1 ]; then
        # Nie eingerichtet (postinstall.sh legt "{}" an) - nichts zu sichern.
        [ -e "$BK" ] && echo "<INFO> $f ist leer - die vorhandene Sicherung bleibt unveraendert."
        continue
    fi
    if [ "$CF_RC" = 3 ]; then
        AK_WARN=1
        echo "<WARNING> $f ist unlesbar oder traegt kein $( [ "$ART" = zugang ] && echo Passwort || echo Aktionstoken )."
        if [ -e "$BK" ]; then
            echo "<WARNING> Die vorhandene Sicherung bleibt unveraendert: $BK"
        else
            echo "<WARNING> Es gibt keine Sicherung; $f wird NICHT gesichert."
        fi
        continue
    fi
    if [ "$CF_RC" = 2 ] && [ -e "$BK" ]; then
        AK_WARN=1
        echo "<WARNING> $f liess sich nicht pruefen (fehlt php?). Die vorhandene"
        echo "<WARNING> Sicherung bleibt unveraendert: $BK"
        continue
    fi
    # Die Nebendatei liegt neben der Sicherung (dasselbe Dateisystem, also ist
    # mv ein Umbenennen). zugang.json traegt das Passwort im Klartext: 0600,
    # bevor die Datei ihren Namen bekommt.
    NEU="$BK.neu.$$"
    if cp -p "$CF" "$NEU" 2>/dev/null \
       && { [ "$ART" != zugang ] || chmod 600 "$NEU" 2>/dev/null; } \
       && cmp -s "$CF" "$NEU" && mv -f "$NEU" "$BK" 2>/dev/null; then
        echo "<INFO> $f gesichert."
    else
        rm -f "$NEU" 2>/dev/null
        AK_WARN=1
        echo "<WARNING> $f liess sich NICHT sichern; eine vorhandene Sicherung bleibt unveraendert."
    fi
done
if [ "$AK_WARN" = 0 ]; then
    echo "<OK> preupgrade abgeschlossen."
else
    echo "<WARNING> preupgrade mit Warnungen abgeschlossen - siehe die Zeilen oben."
fi
exit 0
