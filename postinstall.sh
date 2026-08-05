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

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" "$PDATA/befehle" || {
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
        INHALT=$(cat "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
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
        echo "<FAIL> Fehlt das Paket python3-venv? (apt install python3-venv)"
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

echo "<INFO> Installiere anker-solix-api $LIBTAG (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir "$LIBURL"; then
    echo "<INFO> Feste Fassung $LIBTAG nicht installierbar - versuche den Hauptzweig."
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir \
        "git+https://github.com/thomluther/anker-solix-api.git@main"; then
        echo "<FAIL> anker-solix-api konnte nicht installiert werden."
        echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, kein git installiert"
        echo "<FAIL> (apt install git), oder GitHub war nicht erreichbar."
        exit 1
    fi
    # Ersatzweg gegangen - und angezeigt, sonst wird aus dem Ersatz unbemerkt
    # der Normalfall.
    echo "<INFO> ERSATZWEG: Es wurde der Hauptzweig statt $LIBTAG installiert."
fi

# Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob sich die
# Bibliothek auch laden laesst.
if ! "$VENV/bin/python3" -c 'from anker_solix_api.api import AnkerSolixApi' 2>/dev/null; then
    echo "<FAIL> anker-solix-api ist installiert, laesst sich aber nicht laden."
    exit 1
fi
LIBVER=$("$VENV/bin/python3" -c 'import importlib.metadata as m; print(m.version("anker-solix-api"))' 2>/dev/null || echo "unbekannt")
echo "<OK> anker-solix-api geladen, Fassung $LIBVER"

# ---------- Rechte ----------
chmod 755 "$PBIN/ankersolix.py" 2>/dev/null
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/zugang.json"

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, Anker-Zugangsdaten eintragen"
echo "<INFO> und den Dienst im Reiter Einstellungen starten."
exit 0
