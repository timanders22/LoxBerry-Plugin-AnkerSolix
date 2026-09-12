#!/bin/bash
# Anker SOLIX - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Legt an: Konfigurations-, Daten- und Logordner, die Zugangsdatei mit Rechten
# 0600 und die virtuelle Python-Umgebung samt der Bibliothek anker-solix-api.
#
# WICHTIG (PEP 668): Debian 12/13 kennzeichnen die System-Python-Umgebung als
# extern verwaltet. Ein systemweites "pip3 install" wird mit
# "error: externally-managed-environment" abgewiesen - auch mit --user, auch
# als root. Deshalb eine eigene venv, und der Shebang der Skripte zeigt direkt
# darauf. JEDER Rueckgabewert wird geprueft: eine Installation, die "ALLES
# ERLEDIGT" meldet, obwohl die venv fehlschlug, ist schlimmer als ein Abbruch.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ankersolix}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

SELFDIR=$(cd "$(dirname "$0")" && pwd)
PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

# Fassung der Bibliothek. Auf einen Tag festgenagelt, damit eine Installation
# von heute morgen und eine von heute abend dasselbe ergeben. Der Tag v3.6.3
# ist auf der Release-Seite des Projekts nachgesehen, nicht geraten.
LIBTAG="v3.6.3"
LIBURL="git+https://github.com/thomluther/anker-solix-api.git@${LIBTAG}"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" "$PDATA/befehle" "$PDATA/antworten"          "$PDATA/verlauf" "$PDATA/energie" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Konfiguration ----------
[ -f "$PCONFIG/ankersolix.json" ] || echo '{}' > "$PCONFIG/ankersolix.json"
if [ ! -f "$PCONFIG/zugang.json" ]; then
    echo '{}' > "$PCONFIG/zugang.json"
fi
chmod 600 "$PCONFIG/zugang.json"

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
for f in ankersolix.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ]; then
        # Nicht auf "{}" vergleichen, sondern fragen, ob ueberhaupt ein
        # Schluessel drinsteht. Ein Textvergleich haengt daran, ob der
        # Schreiber Einrueckung oder Leerzeichen setzt - und das entscheidet
        # dann darueber, ob die Sicherung zurueckkommt oder der Nutzer seine
        # Einstellungen verliert.
        if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
        fi
    fi
done
chmod 600 "$PCONFIG/zugang.json"

# ---------- Python suchen ----------
# Die Bibliothek verlangt Python 3.12 oder neuer (pyproject: requires-python
# >= 3.12). Auf einem LoxBerry mit Debian 12 (Bookworm) ist das System-Python
# 3.11 - dann gibt es hier KEINE stille Notloesung, sondern eine klare Ansage.
PY=""
for k in python3.14 python3.13 python3.12; do
    if command -v "$k" >/dev/null 2>&1; then PY="$k"; break; fi
done
if [ -z "$PY" ] && command -v python3 >/dev/null 2>&1; then
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,12) else 1)'; then
        PY="python3"
    fi
fi
if [ -z "$PY" ]; then
    HAVE=$(python3 -V 2>&1 || echo "kein python3")
    echo "<FAIL> Es wurde kein Python 3.12 oder neuer gefunden (gefunden: $HAVE)."
    echo "<FAIL> Die Bibliothek anker-solix-api setzt Python >= 3.12 voraus."
    echo "<FAIL> Abhilfe: LoxBerry auf eine Debian-Fassung mit Python 3.12+ heben"
    echo "<FAIL> (Debian 13 liefert 3.13) oder Python 3.12 zusaetzlich installieren"
    echo "<FAIL> (z. B. Paket python3.12 aus den Backports)."
    echo "<FAIL> Das Plugin bleibt installiert, der Dienst kann aber nicht starten."
    exit 1
fi
echo "<INFO> Verwendetes Python: $PY ($($PY -V 2>&1))"

# ---------- virtuelle Umgebung ----------
BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ]; then
    if "$VENV/bin/python3" -c 'import sys; sys.exit(0 if sys.version_info >= (3,12) else 1)' 2>/dev/null; then
        BRAUCHBAR=1
    fi
fi
if [ "$BRAUCHBAR" -eq 0 ]; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<FAIL> Dafuer wird das Paket python3-venv gebraucht. Es steht in"
        echo "<FAIL> dpkg/apt und wird von LoxBerry als root eingespielt - wenn"
        echo "<FAIL> das nicht geschehen ist, war waehrend der Installation die"
        echo "<FAIL> Paketquelle nicht erreichbar. Abhilfe von Hand:"
        echo "<FAIL>     sudo apt-get update && sudo apt-get install python3-venv"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
if [ ! -x "$VENV/bin/python3" ]; then
    echo "<FAIL> $VENV/bin/python3 fehlt - Abbruch."
    exit 1
fi

"$VENV/bin/python3" -m pip install --upgrade pip setuptools wheel >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - wird mit der vorhandenen Fassung versucht."

# Auf 32-Bit-ARM (aeltere Raspberry Pi) gibt es fuer manche Abhaengigkeiten
# kein fertiges Wheel; pip uebersetzt dann selbst und braucht dafuer einen
# C-Uebersetzer. Fehlt er, bricht die Installation mit einer Fehlerwand ab, in
# der die eigentliche Ursache untergeht. Deshalb vorher nachsehen und es
# benennen - abgebrochen wird nicht, denn mit --prefer-binary geht es auf den
# meisten Geraeten trotzdem.
BOGEN=$(dpkg --print-architecture 2>/dev/null || uname -m)
if ! command -v cc >/dev/null 2>&1 && ! command -v gcc >/dev/null 2>&1; then
    echo "<INFO> Architektur $BOGEN, aber kein C-Uebersetzer vorhanden."
    echo "<INFO> Sollte die naechste Zeile mit einem Uebersetzungsfehler abbrechen,"
    echo "<INFO> hilft:  sudo apt install build-essential python3-dev"
fi

if ! command -v git >/dev/null 2>&1; then
    echo "<INFO> git ist nicht vorhanden. anker-solix-api steht NICHT auf PyPI und"
    echo "<INFO> wird mit pip install git+https://... geholt - ohne git bricht der"
    echo "<INFO> naechste Schritt ab. Das Paket steht in dpkg/apt; wurde es nicht"
    echo "<INFO> eingespielt, war die Paketquelle nicht erreichbar."
fi

# ---------- Abhaengigkeiten der Bibliothek ----------
# anker-solix-api bringt seine Abhaengigkeiten NICHT im Paket mit. Die
# pyproject.toml setzt dynamic = ["dependencies"] und fuehrt die Liste unter
# [tool.poetry.dependencies] mit package-mode = false. Gebaut wird das Paket
# aber von setuptools, und das kennt den Poetry-Abschnitt nicht: das fertige
# Wheel traegt KEINE einzige Requires-Dist-Zeile. Nachgemessen am 12.09.2026
# in der dist-info des Geraets - dort steht nur Name, Version und
# Requires-Python.
#
# Folge: "pip install git+..." meldet Erfolg und installiert nur den nackten
# Paketordner. Beim ersten Laden bricht es mit "No module named 'aiohttp'" ab.
# Genau das ist bei der Installation vom 11.09.2026 16:40 geschehen.
#
# Die fuenf Namen und ihre Untergrenzen sind aus der pyproject.toml des Tags
# $LIBTAG abgeschrieben, nicht geraten. python-dotenv wird vom Paket heute
# nicht eingelesen (nur von den Beispielskripten des Projekts) - es steht
# trotzdem hier, weil es zur erklaerten Liste der Bibliothek gehoert.
LIBDEPS="aiohttp>=3.10.11 aiofiles>=25.1.0 cryptography>=3.4.8 paho-mqtt>=2.1.0 python-dotenv>=1.2.1"
echo "<INFO> Installiere die Abhaengigkeiten der Bibliothek ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir --prefer-binary $LIBDEPS; then
    echo "<FAIL> Die Abhaengigkeiten der Bibliothek liessen sich nicht installieren."
    echo "<FAIL> Betroffen: $LIBDEPS"
    echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, oder fuer diese"
    echo "<FAIL> Architektur gibt es kein fertiges Wheel und es fehlt der"
    echo "<FAIL> Uebersetzer. Abhilfe von Hand:"
    echo "<FAIL>     sudo apt install build-essential python3-dev"
    exit 1
fi

echo "<INFO> Installiere anker-solix-api $LIBTAG (benoetigt eine Internetverbindung) ..."
# --prefer-binary: lieber ein fertiges Wheel als selbst uebersetzen. Auf
# 32-Bit-ARM (aeltere Raspberry Pi) fehlen fuer manche Abhaengigkeiten die
# vorgebauten Pakete, und ohne Uebersetzer bricht die Installation ab.
if ! "$VENV/bin/python3" -m pip install --no-cache-dir --prefer-binary "$LIBURL"; then
    echo "<INFO> Feste Fassung $LIBTAG nicht installierbar - versuche den Hauptzweig."
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir --prefer-binary \
        "git+https://github.com/thomluther/anker-solix-api.git@main"; then
        echo "<FAIL> anker-solix-api konnte nicht installiert werden."
        echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, GitHub nicht"
        echo "<FAIL> erreichbar, oder git fehlt. git steht in dpkg/apt und wird von"
        echo "<FAIL> LoxBerry als root eingespielt. Abhilfe von Hand:"
        echo "<FAIL>     sudo apt-get update && sudo apt-get install git"
        exit 1
    fi
    # Ersatzweg gegangen - und angezeigt, sonst wird aus dem Ersatz unbemerkt
    # der Normalfall.
    echo "<INFO> ERSATZWEG: Es wurde der Hauptzweig statt $LIBTAG installiert."
fi

# Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob sich die
# Bibliothek auch laden laesst.
#
# ACHTUNG beim Namen: die Verteilung heisst "anker-solix-api", der Paketordner
# darin heisst schlicht "api" (top_level.txt der dist-info, nachgemessen am
# 12.09.2026). Bis 0.9.14 stand hier "from anker_solix_api.api import ..." -
# ein Name, den es nie gab. Die Pruefung schlug damit bei JEDER Installation
# fehl, auch bei einer vollstaendig geglueckten.
#
# Die Fehlermeldung wird angezeigt statt verworfen: "laesst sich nicht laden"
# ohne Grund war 0.9.14s zweiter Fehler - die eigentliche Ursache
# (ModuleNotFoundError: aiohttp) stand hinter 2>/dev/null.
if ! LADEFEHLER=$("$VENV/bin/python3" -c 'import aiohttp
from api.api import AnkerSolixApi' 2>&1); then
    echo "<FAIL> anker-solix-api ist installiert, laesst sich aber nicht laden."
    echo "<FAIL> Meldung von Python:"
    echo "$LADEFEHLER" | sed 's/^/<FAIL>     /'
    exit 1
fi
LIBVER=$("$VENV/bin/python3" -c 'import importlib.metadata as m; print(m.version("anker-solix-api"))' 2>/dev/null || echo "unbekannt")
echo "<OK> anker-solix-api geladen, Fassung $LIBVER"
# Die Fassungen der Abhaengigkeiten werden nicht festgenagelt, sondern nur
# nach unten begrenzt - sonst gibt es auf einer Architektur ohne fertiges
# Wheel keinen Weg mehr. Damit eine Installation trotzdem nachvollziehbar
# bleibt, steht hier im Protokoll, was tatsaechlich eingespielt wurde.
echo "<INFO> Eingespielte Abhaengigkeiten:"
"$VENV/bin/python3" -m pip list --format=freeze 2>/dev/null     | grep -iE '^(aiohttp|aiofiles|cryptography|paho-mqtt|python-dotenv)=='     | sed 's/^/<INFO>     /'


# ---------- Rechte ----------
chmod 755 "$PBIN/ankersolix.py" 2>/dev/null
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
# ak_notify.php wird vom Dienst ueber "php <pfad>" aufgerufen, braucht also
# kein Ausfuehrungsrecht - lesbar muss es sein.
chmod 644 "$PBIN/ak_notify.php" 2>/dev/null
# Das Deinstallationsskript uebernimmt LoxBerry aus dem Installationsordner.
# Ohne Ausfuehrungsrecht bliebe die Sicherung mit dem Anker-Passwort nach dem
# Entfernen liegen - deshalb hier, im entpackten Stand, setzen.
chmod 755 "$SELFDIR/uninstall/uninstall" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/zugang.json"

# ---------- Dienst wieder anwerfen ----------
# Der Merker stammt aus preupgrade.sh und sagt, dass der Dienst vor dem
# Upgrade laufen sollte. Bis 0.9.13 gab es ihn nicht: nach jedem Update lag
# das Plugin still, bis jemand von Hand auf "Dienst starten" drueckte - und
# weil `soll_laufen` im abgeraeumten Datenordner lag, griff auch der
# minuetliche Waechter nicht. Uebernommen von Weissware 0.9.18.
LIEF="$BASE/config/plugins/$PFOLDER.backup.lief"
if [ -f "$LIEF" ]; then
    if [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" start >/dev/null 2>&1; then
        echo "<OK> Der Dienst wurde wieder gestartet."
    else
        echo "<INFO> Der Dienst lief vor dem Upgrade, liess sich aber nicht"
        echo "<INFO> starten. Bitte im Reiter Einstellungen nachsehen."
    fi
    rm -f "$LIEF"
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, Anker-Zugangsdaten eintragen"
echo "<INFO> und den Dienst im Reiter Einstellungen starten."
exit 0
