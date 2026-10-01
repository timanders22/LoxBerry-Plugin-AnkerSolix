#!/bin/bash
# Anker SOLIX - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# X-1 (B-Nachzug 01.10.2026, Entscheidung 1 vom 29.09.2026), Muster
# LoxBerry-Plugin-Abfahrtsassistent-1.6.19/preinstall.sh. Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschriften sind zugleich
# die Upgrade-Sicherung, und postinstall.sh spielt aus ihnen zurueck.
#
# Ohne Marke ist es eine NEUINSTALLATION. Die Zweitschriften einer frueheren
# Installation - config/plugins/<ordner>.backup.ankersolix.json (mit dem
# alten Aktionstoken und der Freigabe schreibender Befehle) und
# config/plugins/<ordner>.backup.zugang.json (mit dem Anker-Kontopasswort im
# Klartext) - gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bis 0.9.24 legte erst postinstall.sh sie beiseite, rund eine Minute nach
# dem Kopieren der Oberflaeche. In dieser Luecke las die Oberflaeche
# (ak_config) die alte Zweitschrift und schrieb sie als Konfiguration zurueck;
# postinstall.sh fand dann eine Konfiguration mit Inhalt vor und liess die
# Zweitschrift liegen - das alte Aktionstoken galt weiter.
# Die Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt
# es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-ankersolix}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie im Muster: ohne config/plugins, data/plugins UND
# config/system/general.json wird nichts angefasst (Regeln/06,
# Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BEISEITE=""
FEST=""
for f in ankersolix.json zugang.json; do
    ZIEL="$BASE/config/plugins/$PFOLDER.backup.$f"
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            # Beide tragen ein Geheimnis (Aktionstoken bzw. Kontopasswort).
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    AK_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && AK_TEXT="$AK_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && AK_TEXT="$AK_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$AK_TEXT"
fi
exit 0
