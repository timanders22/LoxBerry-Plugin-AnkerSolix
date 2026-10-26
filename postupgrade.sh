#!/bin/bash
# Anker SOLIX - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Seit 0.9.32 (K9): LoxBerry ruft postinstall.sh bei jedem Einbau selbst
# (plugininstall.pl :1305-1311) und bei einem Upgrade danach dieses Skript
# (:1331-1337). Bis 0.9.31 rief es postinstall.sh ein zweites Mal auf; ein
# Einbaumerker hielt den zweiten Lauf an. Jetzt tut es nur, was ein Upgrade
# zusaetzlich braucht - postinstall.sh hat die Marke "Aktualisierung laeuft"
# beim Beenden schon entfernt:
#
#   1. endpunkt_ein = 1 in die Konfiguration UND ihre Zweitschrift schreiben,
#      wenn eine eingerichtete Datei den Schluessel noch nicht traegt
#      (Entscheidung 2, E-E21): bestehende Anlagen behalten den HTTP-Lesezweig
#      fuer Loxone. Scheitert es, gilt die Lese-Regel ak_uebergang() trotzdem.
#      Geschrieben wird eine Nebendatei (umask 077), die VOR dem Umbenennen
#      geprueft wird (K2: alle Schluessel wie vorher, endpunkt_ein 1), danach
#      0600.
#   2. Die Befehlsbruecke starten, wenn ihre Haken an sind (E-E3) - nach der
#      Marke; solange sie lag, haette sie nicht gestartet. Der Waechter im
#      Minutentakt holt einen ausgelassenen Start binnen 60 s nach.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ankersolix}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzel und Ordnername wie preinstall.sh (Regeln/06): ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
case "$PFOLDER" in
    ''|*/*|.|..) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts ergaenzt."; exit 0 ;;
esac
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts ergaenzt."
    exit 0
fi

# ---------- 1. endpunkt_ein festschreiben (E-E21) ----------
if command -v php >/dev/null 2>&1; then
    for CF in "$BASE/config/plugins/$PFOLDER/ankersolix.json" "$BASE/config/plugins/$PFOLDER.backup.ankersolix.json"; do
        [ -f "$CF" ] || continue
        NEU="$CF.neu.$$"
        # Rueckgabe: 0 = Nebendatei geschrieben und geprueft, 1 = nichts zu tun, 2 = Fehler.
        ( umask 077; php -r '
            $roh = @file_get_contents($argv[1]);
            if ($roh === false) { exit(2); }
            $d = json_decode($roh, true);
            if (!is_array($d)) { exit(1); }
            $ein = false;
            foreach (array_keys($d) as $k) { if ((string) $k !== "aktionstoken") { $ein = true; break; } }
            if (!$ein || array_key_exists("endpunkt_ein", $d)) { exit(1); }
            $d["endpunkt_ein"] = 1;
            $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($j === false || @file_put_contents($argv[2], $j) !== strlen($j)) { exit(2); }
            $zurueck = json_decode((string) @file_get_contents($argv[2]), true);
            if (!is_array($zurueck) || (int) $zurueck["endpunkt_ein"] !== 1) { exit(2); }
            unset($zurueck["endpunkt_ein"]);
            $alt = $d;
            unset($alt["endpunkt_ein"]);
            exit($zurueck === $alt ? 0 : 2);
        ' -- "$CF" "$NEU" ) 2>/dev/null
        RC=$?
        if [ "$RC" = 0 ] && mv -f "$NEU" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null; then
            echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 in $(basename "$CF") (bestehende Anlage - der"
            echo "<INFO> HTTP-Lesezweig fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung)."
        elif [ "$RC" = 1 ]; then
            rm -f "$NEU" 2>/dev/null
        else
            rm -f "$NEU" 2>/dev/null
            echo "<WARNING> endpunkt_ein liess sich in $(basename "$CF") nicht ergaenzen - es gilt die"
            echo "<WARNING> Lese-Regel (bestehende Anlage = Lesezweig an)."
        fi
    done
else
    echo "<INFO> php fehlt - endpunkt_ein wird nicht festgeschrieben (die Lese-Regel gilt)."
fi

# ---------- 2. Die Befehlsbruecke starten (E-E3) ----------
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
    case "$BB_AUS" in
        *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
        "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
        *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
    esac
fi
exit 0
