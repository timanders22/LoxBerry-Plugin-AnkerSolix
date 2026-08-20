#!REPLACELBPBINDIR/venv/bin/python3
"""Anker SOLIX - Abrufdienst fuer LoxBerry.

Holt die Werte der Anker-Cloud, legt sie als JSON-Zwischenspeicher ab, gibt sie
optional ueber das LoxBerry-MQTT-Gateway weiter und arbeitet Schreibbefehle aus
einer Warteschlange ab, die der Loxone-Endpunkt fuellt.

Warum Cloud und nicht lokal: Anker SOLIX hat KEINE lokale Schnittstelle. Weder
die Solarbank noch der Smart Meter beantworten Anfragen aus dem Heimnetz. Alle
Werte laufen ueber die Anker-Cloud beziehungsweise deren MQTT-Server. Ein
Vergleich mit dem Marstek-Plugin, das rein lokal arbeitet, geht deshalb an
dieser Stelle nicht auf.

Drei Aufgaben, drei Dateien - dieses Skript ist der Dienst. Die Oberflaeche
(webfrontend/htmlauth/index.php) und der Miniserver-Endpunkt
(webfrontend/html/index.php) rufen es nie direkt auf, sondern lesen den
Zwischenspeicher beziehungsweise legen Befehle ab.

Aufrufe:
    ankersolix.py                 Dienst (Dauerbetrieb)
    ankersolix.py --einmal        ein einzelner Abruf, dann Ende
    ankersolix.py --selbsttest    Pruefungen ohne Netz, Ausgabe als Klartext
    ankersolix.py --vorgaben      die Vorgabeliste als JSON (fuer den Abgleich
                                  mit ak_vorgaben() in der Oberflaeche)
    ankersolix.py --freigeben     einmalig die Betriebsart 'eigenverbrauch'
                                  setzen und beenden; die Deinstallation ruft
                                  das auf, damit kein gestellter Sollwert
                                  zurueckbleibt
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import socket
import subprocess
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# ---------------------------------------------------------------------------
# Pfade aus dem EIGENEN Ablageort ableiten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring. Sichtbare Folge waere ein Dienst, der gegen /-Pfade werkelt und
# trotzdem Erfolg meldet.
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>
PNAME = SELF.name
# Drei Ebenen darueber liegt das LoxBerry-Wurzelverzeichnis. Liegt das Skript
# ausnahmsweise flacher (Entwicklung, Pruefaufbau), wird nicht mit einem
# nackten IndexError abgebrochen, sondern die Umgebungsvariable genommen.
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln())
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "ankersolix.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
DATEI_LETZTER_SCHREIB = PDATA / "letzter_schreibbefehl"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
ORDNER_ENERGIE = PDATA / "energie"
DATEI_LOG = PLOG / "ankersolix.log"
NOTIFY = SELF / "ak_notify.php"

# Hoechstalter einer Befehlsdatei in Sekunden. Aelteres wird verworfen statt
# ausgefuehrt - siehe warteschlange().
BEFEHL_HOECHSTALTER = 60


def zeitzone_setzen() -> str:
    """Zeitzone aus der LoxBerry-Einstellung uebernehmen.

    Die Verlaufsdateien tragen das Datum im Namen (anlageN_JJJJMMTT.csv) und
    entstehen ueber time.strftime(). Ohne gesetzte Zeitzone nimmt Python die
    des Systems - und laeuft das Grundsystem auf UTC, wechselt die Datei im
    Sommer schon um 22 Uhr Ortszeit. Der Tagesverlauf waere dann quer zum
    Sonnenverlauf geteilt, und niemand faende den Grund.

    Genommen wird, was der Nutzer in LoxBerry eingestellt hat - nicht fest
    Europe/Berlin: das Plugin laeuft auch anderswo.
    """
    tz = os.environ.get("TZ") or ""
    if not tz:
        try:
            with open(LBHOME / "config" / "system" / "general.json", encoding="utf-8") as fh:
                gen = json.load(fh)
            for ab in ("Timeserver", "TIMESERVER", "Base", "BASE"):
                wert = (gen.get(ab) or {}).get("Timezone") or (gen.get(ab) or {}).get("TIMEZONE")
                if wert:
                    tz = str(wert)
                    break
        except (OSError, ValueError, AttributeError):
            tz = ""
    if not tz:
        # Letzter Rueckfall: was das Grundsystem in /etc/timezone fuehrt.
        try:
            with open("/etc/timezone", encoding="utf-8") as fh:
                tz = fh.read().strip()
        except OSError:
            tz = ""
    if tz:
        os.environ["TZ"] = tz
        try:
            time.tzset()
        except AttributeError:
            pass    # Windows kennt tzset nicht - dort laeuft das Plugin ohnehin nicht
    return tz


ZEITZONE = zeitzone_setzen()

# ---------------------------------------------------------------------------
# Vorgabewerte.
#
# Diese Liste muss zu ak_vorgaben() in webfrontend/html/ak_lib.php passen.
# Zwei Listen an zwei Orten laufen auseinander, und der Unterschied faellt
# erst auf, wenn die Oberflaeche etwas anderes anzeigt als der Dienst tut.
# Zusammenlegen laesst sich das nicht - PHP und Python lesen einander nicht.
# Deshalb gibt "--vorgaben" die Liste als JSON aus, und der Reiter Test haelt
# beide gegeneinander.
# ---------------------------------------------------------------------------
VORGABEN = {
    # --- Konto und Takt ---
    "land": "DE",
    "intervall": 60,
    "takt_details": 10,
    "takt_energie": 15,
    "takt_prognose": 60,
    "endpunkt_limit": 10,
    "anfrage_pause": 3,       # Zehntelsekunden zwischen zwei Anfragen
    "anfrage_frist": 10,      # Sekunden Zeitschranke je Anfrage
    # --- Abrufumfang: was NICHT geholt wird (spart Anfragen) ---
    "ohne_details": 0,
    "ohne_energie": 0,
    "ohne_prognose": 1,
    # --- Ablage ---
    "verlauf_tage": 8,
    "energie_tage": 400,
    "zaehler_ein": 1,
    # --- MQTT ---
    "mqtt_ein": 0,
    "mqtt_topic": "ankersolix",
    "mqtt_nur_aenderung": 0,
    # --- Steuerung ---
    "steuerung_ein": 0,
    "hauslast_min": 0,
    "hauslast_max": 1600,
    "anlagen_grenzen": {},
    "schreibbremse": 10,
    "schrittweite": 10,
    "rueckfall_min": 0,
    "rueckfall_modus": "eigenverbrauch",
    # --- Meldewege ---
    "melden_ein": 1,
    "melden_alter": 900,
}

# Betriebsmodi der Solarbank 2/3. Die Zahlen stammen aus SolarbankUsageMode der
# Bibliothek (apitypes.py) und sind dort so festgelegt.
#
# 4 (Notstrom) fehlt absichtlich: der Enum-Kommentar sagt ausdruecklich, dass
# dieser Modus nur den Zustand abbildet und sich nicht ueber den Zeitplan
# setzen laesst. Ein Eintrag hier waere ein Befehl, der still nichts tut.
MODI = {
    "eigenverbrauch": 1,   # smartmeter  - Ausgabe nach gemessenem Hausverbrauch
    "steckdosen": 2,       # smartplugs  - Ausgabe nach gemessenen Steckdosen
    "manuell": 3,          # manual      - fester Zeitplan
    "zeitplan": 5,         # use_time    - Nutzungszeitplan (SB2 AC)
    "smart": 7,            # smart       - KI-gestuetzt
    "zeitfenster": 8,      # time_slot   - fuer dynamische Tarife (SB3)
}
MODI_TEXT = {
    0: "unbekannt", 1: "Eigenverbrauch", 2: "Steckdosen", 3: "Manuell",
    4: "Notstrom", 5: "Nutzungszeit", 7: "Smart", 8: "Zeitfenster",
}
# Rueckweg: Zahl -> Name. Fuer die Liste der erlaubten Modi eines Geraets.
MODI_NAME = {v: k for k, v in MODI.items()}

# ---------------------------------------------------------------------------
# Die Themen, die dieser Dienst veroeffentlicht.
#
# DIESE Liste ist die Wahrheit, und ak_mqtt_themen() in der Oberflaeche muss
# ihr gleichen - nicht umgekehrt. Der Reiter Test liest diesen Block und haelt
# beide gegeneinander. Der teuerste Befund der Renault-Sitzung war eine
# Anleitung, die fuenf Themen nannte, die der Sendecode nie veroeffentlicht
# hat: wer die Importdatei einlas, bekam virtuelle Eingaenge, die dauerhaft
# auf 0 standen, ohne Fehlermeldung.
#
# Aus derselben Liste entstehen auch die tatsaechlich gesendeten Themen
# (mqtt_paare) - eine Stelle, nicht zwei.
# ---------------------------------------------------------------------------
MQTT_THEMEN = (
    "ok",
    "ts",
    "fehler",
    "anlagen",
    "anlageN/soc",
    "anlageN/pv",
    "anlageN/laden",
    "anlageN/entladen",
    "anlageN/batp",
    "anlageN/ausgang",
    "anlageN/haus",
    "anlageN/netzbezug",
    "anlageN/netzeinspeisung",
    "anlageN/sollwert",
    "anlageN/modus",
    "anlageN/reserve",
    "anlageN/einspeisung",
    "anlageN/einspeisegrenze",
    "anlageN/prognose",
    "anlageN/name",
    "anlageN/energie/pv",
    "anlageN/energie/batterie_geladen",
    "anlageN/energie/batterie_abgegeben",
    "anlageN/energie/haus",
    "anlageN/energie/netzbezug",
    "anlageN/energie/netzeinspeisung",
    "anlageN/zaehler/pv",
    "anlageN/zaehler/haus",
    "anlageN/zaehler/netzbezug",
    "anlageN/zaehler/netzeinspeisung",
    "geraet/<SN>/soc",
    "geraet/<SN>/pv",
    "geraet/<SN>/ausgang",
    "geraet/<SN>/laden",
    "geraet/<SN>/sollwert",
    "geraet/<SN>/online",
    "geraet/<SN>/wlan",
    "geraet/<SN>/leistung",
    "geraet/<SN>/fw",
)

_LAUF = True
_LOG = logging.getLogger("ankersolix")
_LETZTE_MELDUNG: dict[str, float] = {}
_LETZTE_MQTT: dict[str, str] = {}
_ZAEHLWERK = {"anfragen": 0, "fehler": 0, "http429": 0, "anmeldungen": 0}


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet seine eigene Ausgabe in
# eine ANDERE Datei (ankersolix_start.log) - beide in dieselbe zu schreiben
# ginge schief, sobald der RotatingFileHandler umbenennt: der Anhaenge-
# Deskriptor der Shell zeigte danach auf die weggeschobene Datei.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h = RotatingFileHandler(DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8")
    except OSError as err:
        # Scheitert die Datei, nach stderr - nicht nach stdout.
        h = logging.StreamHandler(sys.stderr)
        print(f"Logdatei nicht beschreibbar ({err}) - schreibe nach stderr.", file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False


def melde_gebremst(schluessel: str, text: str, sekunden: int = 3600) -> None:
    """Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
    Logdatei durch eine Dauerstoerung unlesbar."""
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


def benachrichtigen(schwere: int, text: str, schluessel: str = "", sekunden: int = 21600) -> None:
    """Eine Meldung in den LoxBerry-Benachrichtigungsbereich legen.

    Ohne diesen Weg erfaehrt niemand von einer Stoerung: schreibt der Dienst
    nur ins eigene Protokoll, laeuft er tagelang mit 429-Abweisungen weiter,
    und man merkt es erst, wenn man die Oberflaeche oeffnet.

    Fuer Benachrichtigungen gibt es in Python keine LoxBerry-Schnittstelle -
    notify_ext() ist PHP. Deshalb das Zwischenstueck bin/ak_notify.php.
    Der Pluginordner wird ausdruecklich mitgegeben, weil die
    LoxBerry-Umgebungsvariablen bei einem Start aus dem Cron fehlen koennen.
    """
    if not config().get("melden_ein"):
        return
    schluessel = schluessel or text[:40]
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get("notify_" + schluessel, 0) < sekunden:
        return
    _LETZTE_MELDUNG["notify_" + schluessel] = jetzt
    if not NOTIFY.is_file():
        return
    try:
        subprocess.run(["php", str(NOTIFY), str(int(schwere)), text, PNAME],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       timeout=15, check=False)
    except (OSError, subprocess.SubprocessError) as err:
        melde_gebremst("notify", f"Benachrichtigung nicht moeglich: {err}")


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
def json_lesen(pfad: Path) -> dict:
    try:
        with pfad.open("r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten, rechte: int | None = None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen. So liest die Oberflaeche nie
    eine halb geschriebene Datei."""
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        tmp = pfad.with_suffix(pfad.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1, default=str)
        if rechte is not None:
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def ganzzahl(quelle: dict, name: str, lo: int, hi: int) -> int:
    try:
        w = int(quelle.get(name) if quelle.get(name) not in (None, "") else VORGABEN[name])
    except (TypeError, ValueError):
        w = int(VORGABEN[name])
    return max(lo, min(hi, w))


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    c["intervall"] = ganzzahl(c, "intervall", 30, 900)
    c["takt_details"] = ganzzahl(c, "takt_details", 1, 240)
    c["takt_energie"] = ganzzahl(c, "takt_energie", 1, 240)
    c["takt_prognose"] = ganzzahl(c, "takt_prognose", 1, 1440)
    c["endpunkt_limit"] = ganzzahl(c, "endpunkt_limit", 1, 60)
    c["anfrage_pause"] = ganzzahl(c, "anfrage_pause", 0, 100)
    c["anfrage_frist"] = ganzzahl(c, "anfrage_frist", 5, 60)
    c["hauslast_min"] = ganzzahl(c, "hauslast_min", 0, 5000)
    c["hauslast_max"] = ganzzahl(c, "hauslast_max", 0, 5000)
    c["verlauf_tage"] = ganzzahl(c, "verlauf_tage", 1, 90)
    c["energie_tage"] = ganzzahl(c, "energie_tage", 1, 3650)
    c["schreibbremse"] = ganzzahl(c, "schreibbremse", 0, 3600)
    c["schrittweite"] = ganzzahl(c, "schrittweite", 0, 1000)
    c["rueckfall_min"] = ganzzahl(c, "rueckfall_min", 0, 1440)
    c["melden_alter"] = ganzzahl(c, "melden_alter", 60, 86400)
    land = str(c.get("land") or "DE").strip().upper()
    c["land"] = land if len(land) == 2 and land.isalpha() else "DE"
    if str(c.get("rueckfall_modus") or "") not in MODI:
        c["rueckfall_modus"] = "eigenverbrauch"
    if not isinstance(c.get("anlagen_grenzen"), dict):
        c["anlagen_grenzen"] = {}
    return c


def grenzen(cfg: dict, nummer) -> tuple[int, int]:
    """Grenzen der Hauslast: je Anlage, sonst die allgemeinen.

    Ein gemeinsames Maximum fuer eine Solarbank E1600 und eine Solarbank 3 ist
    entweder zu klein oder zu gross - beides ist falsch.
    """
    lo, hi = int(cfg["hauslast_min"]), int(cfg["hauslast_max"])
    g = (cfg.get("anlagen_grenzen") or {}).get(str(nummer))
    if isinstance(g, dict):
        try:
            if str(g.get("min", "")) != "":
                lo = int(g["min"])
            if str(g.get("max", "")) != "":
                hi = int(g["max"])
        except (TypeError, ValueError):
            pass
    return lo, hi


def zugang() -> dict:
    z = json_lesen(DATEI_ZUGANG)
    return {
        "email": str(z.get("email") or "").strip(),
        "passwort": str(z.get("passwort") or ""),
    }


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
# eingeschaltet.
#
# Achtung: Mqtt.Brokerhost ist ab Werk gesetzt ("localhost"). Eine Pruefung
# darauf beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
# Massgeblich ist Gatewayautostart.
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    udp = m.get("Udpinport", m.get("udpinport"))
    try:
        udp = int(udp)
    except (TypeError, ValueError):
        udp = 0
    return {
        "gefunden": bool(m),
        "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
        "udpport": udp,
        "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
        "brokerport": str(m.get("Brokerport", m.get("brokerport", ""))),
    }


def mqtt_senden(paare: dict, praefix: str) -> None:
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port", "MQTT: kein UDP-Eingangsport in general.json gefunden - nichts gesendet.")
        return
    if not z["autostart"]:
        melde_gebremst(
            "mqtt_aus",
            "MQTT: das Gateway ist nicht auf Autostart gestellt (System -> MQTT Gateway). "
            "Es wird gesendet, aber vermutlich hoert niemand zu.",
        )
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return
    try:
        for k, v in paare.items():
            if v is None:
                continue
            # Zeilenumbrueche zerreissen die Syntax des UDP-Gateways: es
            # liest Zeile fuer Zeile, ein \n im Wert waere also der Anfang
            # eines neuen Befehls. Fehlertexte aus der Anker-Cloud koennen
            # mehrzeilig sein - genau die landen hier.
            sauber = str(v).replace("\r", " ").replace("\n", " ").strip()
            nachricht = f"publish {praefix}/{k} {mqtt_wert_saeubern(sauber)}".encode("utf-8")
            s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


def _tief(quelle, pfad: str):
    """Einen Wert ueber einen Pfad wie 'energie/pv' aus verschachtelten
    Woerterbuechern holen."""
    for teil in pfad.split("/"):
        if not isinstance(quelle, dict):
            return None
        quelle = quelle.get(teil)
    return quelle


def mqtt_paare(lox: dict) -> dict:
    """Die zu sendenden Themen - aus MQTT_THEMEN, nicht aus einer zweiten
    Liste. So kann die Anleitung dem Sendecode nicht davonlaufen."""
    anlagen = lox.get("anlagen") or {}
    geraete = lox.get("geraete") or {}
    paare: dict = {}
    for thema in MQTT_THEMEN:
        if thema == "ok":
            paare["ok"] = lox.get("ok")
        elif thema == "ts":
            paare["ts"] = lox.get("ts")
        elif thema == "fehler":
            # Immer senden, auch leer: ein Thema, das bei Ruhe verschwindet,
            # laesst den letzten Fehlertext im Broker stehen.
            paare["fehler"] = lox.get("fehler") or "-"
        elif thema == "anlagen":
            paare["anlagen"] = lox.get("anzahl_anlagen")
        elif thema.startswith("anlageN/"):
            rest = thema[len("anlageN/"):]
            for nummer, a in anlagen.items():
                paare[f"anlage{nummer}/{rest}"] = _tief(a, rest)
        elif thema.startswith("geraet/<SN>/"):
            rest = thema[len("geraet/<SN>/"):]
            for sn, g in geraete.items():
                paare[f"geraet/{sn}/{rest}"] = _tief(g, rest)
    return paare


def mqtt_ausgeben(lox: dict, cfg: dict, ok: int) -> None:
    """Werte veroeffentlichen - mit Zeitstempel, und bei einem Fehlschlag nur
    die Stoermeldung.

    Ueber MQTT gibt es kein "Alter": beim Senden ist es immer null. Wer den
    HTTP- und den MQTT-Weg gleich behandeln will, veroeffentlicht deshalb den
    ZEITSTEMPEL und laesst die Gegenseite rechnen.

    Bei ok = 0 gehen ausschliesslich 'ok', 'ts' und 'fehler' hinaus. Die
    Messwerte behalten ihren zurueckbehaltenen Stand - sonst ueberschriebe man
    gute Daten mit alten und verkaufte es als frisch. 'ts' traegt dabei den
    Zeitpunkt des letzten ERFOLGREICHEN Abrufs; genau daran erkennt die
    Gegenseite den Ausfall.
    """
    if not cfg.get("mqtt_ein"):
        return
    praefix = str(cfg.get("mqtt_topic") or "ankersolix").strip("/") or "ankersolix"
    if not ok:
        mqtt_senden({"ok": 0, "ts": lox.get("ts"), "fehler": lox.get("fehler") or "-"}, praefix)
        return

    paare = mqtt_paare(lox)
    if cfg.get("mqtt_nur_aenderung"):
        # Nur senden, was sich geaendert hat - aber ok/ts/fehler IMMER. Wer nur
        # bei Aenderungen sendet, hoert bei einer Stoerung einfach auf, und in
        # Loxone sieht ein toter Dienst dann aus wie ein ruhiges Haus.
        gefiltert = {}
        for k, v in paare.items():
            if k in ("ok", "ts", "fehler"):
                gefiltert[k] = v
                continue
            neu = "" if v is None else str(v)
            if _LETZTE_MQTT.get(k) != neu:
                _LETZTE_MQTT[k] = neu
                gefiltert[k] = v
        paare = gefiltert
    mqtt_senden(paare, praefix)


# ---------------------------------------------------------------------------
# Hilfsfunktionen zum Auslesen der Cloud-Antworten
#
# Die Antwortstruktur der Anker-Cloud waechst mit jedem neuen Geraet. Deshalb
# wird jedes Feld ueber .get() geholt und ein fehlender Wert bleibt None statt
# 0 zu werden: eine 0 waere eine stille Falschaussage - in Loxone sieht ein
# ausgefallener Wert dann aus wie ein echter Nullwert.
# ---------------------------------------------------------------------------
def zahl(wert, nachkomma: int = 0):
    if wert is None or wert == "":
        return None
    try:
        f = float(str(wert).replace(",", "."))
    except (TypeError, ValueError):
        return None
    return round(f, nachkomma) if nachkomma else int(round(f))


def erstes(quelle: dict, *schluessel):
    for k in schluessel:
        if k in quelle and quelle[k] not in (None, ""):
            return quelle[k]
    return None


def schalter(wert):
    """Einen Ja/Nein-Wert der Cloud auf 1/0 bringen - oder None lassen.

    Ein fehlender Schalter darf NICHT zu 0 werden: 0 hiesse hier
    "Einspeisung gesperrt", und das ist eine Aussage, die niemand gemessen hat.
    """
    if wert is None or wert == "":
        return None
    if isinstance(wert, bool):
        return 1 if wert else 0
    s = str(wert).strip().lower()
    if s in ("1", "true", "yes", "on", "enable", "enabled"):
        return 1
    if s in ("0", "false", "no", "off", "disable", "disabled"):
        return 0
    return None


def geraete_der_anlage(devices: dict, site_id: str) -> dict:
    return {sn: d for sn, d in devices.items() if str(d.get("site_id") or "") == str(site_id)}


def anlagen_soc(geraete: dict):
    """Ladezustand der Anlage aus den Geraetewerten.

    Bewusst NICHT aus solarbank_info.total_battery_power: dieses Feld ist je
    nach Fassung der Cloud ein Anteil (0,75) oder ein Prozentwert (75). Welches
    von beidem, laesst sich ohne Geraet nicht entscheiden - deshalb wird der
    eindeutige Geraetewert battery_soc genommen (Prozent) und, wenn vorhanden,
    mit der Kapazitaet gewichtet. Der Rohwert der Anlage wird zusaetzlich
    unveraendert weitergereicht, damit man beides vergleichen kann.
    """
    summe = 0.0
    gewicht = 0.0
    for d in geraete.values():
        soc = zahl(d.get("battery_soc"), 1)
        if soc is None:
            continue
        kap = zahl(erstes(d, "battery_capacity", "battery_size")) or 1
        summe += soc * kap
        gewicht += kap
    return round(summe / gewicht, 1) if gewicht else None


def anlage_abbilden(site_id: str, site: dict, devices: dict, zusatz: dict) -> dict:
    sb = site.get("solarbank_info") or {}
    grid = site.get("grid_info") or {}
    geraete = geraete_der_anlage(devices, site_id)
    speicher = {sn: d for sn, d in geraete.items()
                if str(d.get("type") or "") in ("solarbank", "solarbank_pps")}

    laden = zahl(erstes(sb, "total_charging_power", "charging_power"))
    entladen = zahl(erstes(sb, "battery_discharge_power"))
    batp = None
    if laden is not None or entladen is not None:
        batp = (laden or 0) - (entladen or 0)

    modus = zahl(erstes(site, "user_scene_mode", "usage_mode") or (sb.get("usage_mode")))
    if modus is None:
        modus = zahl(erstes(site.get("scene_info") or {}, "usage_mode"))

    energie = ((site.get("energy_details") or {}).get("today") or {})

    # Stationsparameter: Notstromreserve, Netzeinspeisung, Einspeisegrenze.
    # Die Cloud haengt sie je nach Fassung an verschiedene Stellen, deshalb
    # mehrere Kandidaten - und None, wenn keiner passt.
    stat = site.get("station_parm") or site.get("site_detail") or {}
    reserve = zahl(erstes(stat, "soc_reserve", "socReserve")
                   or erstes(sb, "power_cutoff", "output_cutoff_data"))
    einspeisung = schalter(erstes(stat, "grid_export", "gridExport"))
    grenze = zahl(erstes(stat, "grid_export_limit", "gridExportLimit"))

    return {
        "site_id": site_id,
        "name": str(erstes(site.get("site_info") or {}, "site_name") or site.get("site_name") or site_id),
        "soc": anlagen_soc(speicher),
        "soc_roh": erstes(sb, "total_battery_power"),
        "pv": zahl(erstes(sb, "total_photovoltaic_power", "photovoltaic_power")),
        "laden": laden,
        "entladen": entladen,
        "batp": batp,
        "ausgang": zahl(erstes(sb, "total_output_power")),
        "haus": zahl(erstes(site, "home_load_power") or sb.get("to_home_load")),
        "netzbezug": zahl(erstes(grid, "grid_to_home_power")),
        "netzeinspeisung": zahl(erstes(grid, "photovoltaic_to_grid_power")),
        "sollwert": zahl(erstes(site, "retain_load", "set_load_power")),
        "modus": modus,
        "modus_text": MODI_TEXT.get(modus or 0, "unbekannt"),
        "modi_erlaubt": zusatz.get("modi_erlaubt") or [],
        "reserve": reserve,
        "einspeisung": einspeisung,
        "einspeisegrenze": grenze,
        "prognose_rest": zusatz.get("prognose_rest"),
        "prognose_tag": zusatz.get("prognose_tag"),
        "anzahl_geraete": len(geraete),
        "anzahl_speicher": len(speicher),
        "energie": {
            "datum": energie.get("date"),
            "pv": zahl(energie.get("solar_production"), 2),
            "batterie_geladen": zahl(energie.get("battery_charge"), 2),
            "batterie_abgegeben": zahl(energie.get("battery_discharge"), 2),
            "haus": zahl(energie.get("home_usage"), 2),
            "netzbezug": zahl(erstes(energie, "grid_import", "grid_to_home"), 2),
            "netzeinspeisung": zahl(energie.get("solar_to_grid"), 2),
        },
        "zaehler": zusatz.get("zaehler") or {},
    }


def geraet_abbilden(sn: str, d: dict, zusatz: dict) -> dict:
    return {
        "sn": sn,
        "pn": str(d.get("device_pn") or ""),
        "name": str(erstes(d, "alias", "name") or sn),
        "typ": str(d.get("type") or ""),
        "generation": zahl(d.get("generation")),
        "site_id": str(d.get("site_id") or ""),
        "soc": zahl(d.get("battery_soc"), 1),
        "pv": zahl(erstes(d, "input_power", "photovoltaic_power")),
        "ausgang": zahl(erstes(d, "output_power")),
        "laden": zahl(erstes(d, "charging_power")),
        "sollwert": zahl(erstes(d, "set_output_power", "preset_system_output_power")),
        "status": str(erstes(d, "charging_status", "status") or ""),
        "status_text": str(erstes(d, "charging_status_desc", "status_desc") or ""),
        "online": 1 if str(erstes(d, "wifi_online") or "").lower() in ("1", "true") else 0,
        "wlan": zahl(d.get("wifi_signal")),
        "fw": str(d.get("sw_version") or ""),
        "fw_neu": str(zusatz.get("fw_neu") or ""),
        "auto_upgrade": schalter(d.get("auto_upgrade")),
        "kapazitaet": zahl(erstes(d, "battery_capacity", "battery_size")),
        "netzbezug": zahl(d.get("grid_to_home_power")),
        "netzeinspeisung": zahl(d.get("photovoltaic_to_grid_power")),
        "leistung": zahl(d.get("current_power")),
        # Die zulaessigen Entladegrenzen dieses Geraets. Bis 0.9.6 wurden sie
        # erst im Fehlerfall abgefragt - der Anwender musste also erst
        # danebenliegen, um zu erfahren, was ueberhaupt geht.
        "cutoff_stufen": zusatz.get("cutoff_stufen") or [],
    }


# ---------------------------------------------------------------------------
# Verlauf (Ladezustand ueber den Tag) fuer die Mini-Grafik der Oberflaeche
# ---------------------------------------------------------------------------
def verlauf_anhaengen(nummer: int, soc, batp, tage: int) -> None:
    if soc is None:
        return
    ordner = PDATA / "verlauf"
    ordner.mkdir(parents=True, exist_ok=True)
    datei = ordner / f"anlage{nummer}_{time.strftime('%Y%m%d')}.csv"
    marke = PDATA / f".verlauf_ts_{nummer}"
    letzte = 0
    try:
        letzte = int(marke.read_text())
    except (OSError, ValueError):
        pass
    if time.time() - letzte < 240:
        return
    try:
        with datei.open("a", encoding="utf-8") as f:
            f.write(f"{int(time.time())};{soc};{batp if batp is not None else ''}\n")
        marke.write_text(str(int(time.time())))
    except OSError:
        return
    grenze = time.time() - tage * 86400
    for alt in ordner.glob("anlage*_*.csv"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Tagesenergien fortschreiben und daraus Zaehlerstaende bilden
#
# Die Cloud liefert nur "heute". Um Mitternacht faellt der Wert auf 0 zurueck -
# wer in Loxone eine Statistik fuehrt, verliert damit den Tagesabschluss, und
# eine Monats- oder Jahressumme gibt es ueberhaupt nicht.
#
# Zaehlerstaende: Loxone rechnet mit fortlaufenden Werten am liebsten. Sie
# entstehen hier aus den Tagessummen und liegen in derselben Datei, damit sie
# einen Neustart ueberleben. Sie koennen nur STEIGEN; ein zurueckspringender
# Tageswert (die Cloud korrigiert nachtraeglich) wird nicht abgezogen.
# ---------------------------------------------------------------------------
FELDER_ENERGIE = ("pv", "batterie_geladen", "batterie_abgegeben",
                  "haus", "netzbezug", "netzeinspeisung")


def energie_fortschreiben(nummer: int, energie: dict, tage: int) -> dict:
    """Den heutigen Stand in anlageN.csv festhalten. Rueckgabe: Zaehlerstaende."""
    datum = str(energie.get("datum") or time.strftime("%Y-%m-%d"))
    if not datum or len(datum) != 10:
        datum = time.strftime("%Y-%m-%d")
    ORDNER_ENERGIE.mkdir(parents=True, exist_ok=True)
    datei = ORDNER_ENERGIE / f"anlage{nummer}.csv"

    zeilen: dict[str, list] = {}
    try:
        with datei.open("r", encoding="utf-8") as f:
            for z in f:
                t = z.rstrip("\n").split(";")
                if len(t) >= 7 and len(t[0]) == 10:
                    zeilen[t[0]] = t
    except OSError:
        pass

    neu = [datum]
    for feld in FELDER_ENERGIE:
        w = energie.get(feld)
        neu.append("" if w is None else str(w))
    # Ein Tag, fuer den die Cloud gar nichts geliefert hat, wird NICHT als
    # Nullzeile festgeschrieben - sonst stuende dort dauerhaft eine 0.
    if any(x != "" for x in neu[1:]):
        zeilen[datum] = neu

    grenze = time.strftime("%Y-%m-%d", time.localtime(time.time() - tage * 86400))
    zeilen = {k: v for k, v in zeilen.items() if k >= grenze}

    try:
        tmp = datei.with_suffix(".csv.tmp")
        with tmp.open("w", encoding="utf-8") as f:
            for k in sorted(zeilen):
                f.write(";".join(zeilen[k]) + "\n")
        os.replace(tmp, datei)
    except OSError as err:
        melde_gebremst("energie_csv", f"Tagesenergien nicht schreibbar: {err}")

    zaehler = {}
    for i, feld in enumerate(FELDER_ENERGIE, start=1):
        summe = 0.0
        gefunden = False
        for k in sorted(zeilen):
            try:
                if zeilen[k][i] != "":
                    summe += float(zeilen[k][i])
                    gefunden = True
            except (IndexError, ValueError):
                continue
        if gefunden:
            zaehler[feld] = round(summe, 2)
    return zaehler


# ---------------------------------------------------------------------------
# Fehlermeldungen, die sagen, wer geantwortet hat
#
# Der nackte Fehlertext einer Bibliothek hilft niemandem. ECONNREFUSED
# (erreichbar, aber nichts lauscht) bedeutet etwas voellig anderes als ein
# Zeitueberlauf (nichts antwortet) oder EHOSTUNREACH (kein Weg dorthin).
# ---------------------------------------------------------------------------
def fehlertext(err: Exception) -> str:
    name = type(err).__name__
    text = str(err) or name
    kleine = text.lower()
    if isinstance(err, asyncio.TimeoutError):
        return ("Zeitueberlauf: die Anker-Cloud hat nicht geantwortet. "
                "Meist eine gestoerte Internetverbindung oder eine Stoerung beim Anbieter.")
    grund = getattr(err, "os_error", None)
    errno = getattr(grund, "errno", None) if grund is not None else getattr(err, "errno", None)
    if errno == 111:
        return "Verbindung abgewiesen (ECONNREFUSED): der Gegenstelle ist der Port bekannt, aber es lauscht nichts."
    if errno == 113:
        return "Kein Weg zum Ziel (EHOSTUNREACH): pruefen Sie Netzwerk und Standardroute des LoxBerry."
    if errno in (-2, -3):
        return "Namensaufloesung fehlgeschlagen: der DNS-Server des LoxBerry antwortet nicht."
    if "429" in text or "too many requests" in kleine:
        return ("Die Anker-Cloud hat wegen zu vieler Anfragen abgewiesen (429). "
                "Abstand oder Takt in den Einstellungen vergroessern, oder im Reiter "
                "Einstellungen Abfragen abschalten, die Sie nicht brauchen.")
    if ("401" in text or "40200" in text
            or "password" in kleine and "wrong" in kleine
            or "invalid" in kleine and ("credential" in kleine or "password" in kleine)):
        return ("Anmeldung abgewiesen: Anker-Benutzername, Passwort oder Land stimmen nicht. "
                "Land ist das Zweibuchstabenkuerzel des Kontos, nicht die Sprache.")
    if "<html" in kleine or "<!doctype" in kleine:
        return ("Es kam HTML statt JSON zurueck - geantwortet hat also ein vorgelagerter Dienst "
                "(Proxy, Portal, Fehlerseite), nicht die Anker-Schnittstelle. "
                "Die Anmeldung selbst ist damit nicht der Fehler.")
    return f"{name}: {text}"


def ist_429(err: Exception) -> bool:
    t = str(err).lower()
    return "429" in t or "too many requests" in t


# ---------------------------------------------------------------------------
# Schreibbefehle aus der Warteschlange
#
# Der Loxone-Endpunkt legt hier eine JSON-Datei ab, der Dienst arbeitet sie ab
# und legt die Antwort daneben. Der Endpunkt selbst spricht NIE mit der Cloud -
# ein Plugin, das den Datenabruf im Endpunkt erledigt, ist falsch gebaut.
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str, zusatz: dict | None = None) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    d = {"ok": ok, "meldung": meldung, "ts": int(time.time())}
    if zusatz:
        d.update(zusatz)
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json", d)
    # Alte Antworten aufraeumen
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


def anlage_waehlen(api, nummer_oder_id) -> str | None:
    ids = sorted(api.sites.keys())
    s = str(nummer_oder_id or "").strip()
    if s in ids:
        return s
    try:
        n = int(s)
    except (TypeError, ValueError):
        n = 1
    return ids[n - 1] if 1 <= n <= len(ids) else None


def anlage_nummer(api, site_id: str) -> int:
    ids = sorted(api.sites.keys())
    return ids.index(site_id) + 1 if site_id in ids else 1


def speicher_waehlen(api, site_id: str, sn: str | None):
    kandidaten = {k: d for k, d in api.devices.items()
                  if str(d.get("site_id") or "") == site_id
                  and str(d.get("type") or "") == "solarbank"}
    if sn:
        return (sn, kandidaten[sn]) if sn in kandidaten else (None, None)
    if not kandidaten:
        return (None, None)
    erster = sorted(kandidaten.keys())[0]
    return (erster, kandidaten[erster])


def schreibbremse_frei(cfg: dict) -> tuple[bool, int]:
    """Darf jetzt geschrieben werden?

    Die Anker-Cloud weist ab etwa zehn bis zwoelf Abfragen je Minute mit 429
    ab. Ein Loxone-Regelkreis, der im Sekundentakt einen neuen Sollwert
    schickt, laeuft ohne Bremse geradewegs in die Sperre - und die trifft dann
    auch die LESENDEN Abfragen.

    Verworfen wird gemeldet, nicht stillschweigend geschluckt: ein Befehl, der
    wortlos nichts tut, schickt den Anwender auf die Suche nach einem Fehler,
    den es nicht gibt.
    """
    bremse = int(cfg.get("schreibbremse") or 0)
    if bremse <= 0:
        return True, 0
    try:
        letzte = int(DATEI_LETZTER_SCHREIB.read_text())
    except (OSError, ValueError):
        return True, 0
    rest = bremse - int(time.time() - letzte)
    return (rest <= 0), max(0, rest)


def schreibbefehl_vermerken() -> None:
    try:
        PDATA.mkdir(parents=True, exist_ok=True)
        DATEI_LETZTER_SCHREIB.write_text(str(int(time.time())))
    except OSError:
        pass


async def befehl_ausfuehren(api, cfg: dict, b: dict) -> tuple[int, str, dict]:
    """Rueckgabe: (ok, Meldung, Zusatzfelder)."""
    aktion = str(b.get("aktion") or "")

    if aktion == "abruf":
        return (1, "Sofortabruf eingeplant.", {})

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.", {})

    frei, rest = schreibbremse_frei(cfg)
    if not frei:
        return (0, f"Schreibbremse: der letzte Befehl liegt weniger als {cfg['schreibbremse']} s zurueck, "
                   f"noch {rest} s. Die Anker-Cloud sperrt bei zu vielen Anfragen (429), "
                   f"und das traefe dann auch die Messwerte.", {})

    site_id = anlage_waehlen(api, b.get("anlage"))
    if site_id is None:
        return (0, f"Anlage '{b.get('anlage')}' gibt es nicht. Bekannt sind {len(api.sites)} Anlagen.", {})
    nummer = anlage_nummer(api, site_id)

    # Die Wechselrichter-Begrenzung gilt EINEM Geraet, nicht der Anlage.
    if aktion == "pvlimit":
        sn = str(b.get("sn") or "")
        if sn not in api.devices:
            return (0, f"Geraet '{sn}' ist nicht bekannt.", {})
        watt = zahl(b.get("watt"))
        if watt is None:
            return (0, "Der Wert fuer die Begrenzung fehlt oder ist keine Zahl.", {})
        # Die Bibliothek nennt 0 bis 800 W als Grenzen des MI80. Ausserhalb
        # wird abgewiesen, nicht gekappt.
        if watt < 0 or watt > 800:
            return (0, f"{watt} W liegt ausserhalb von 0 bis 800 W - das ist der Bereich, "
                       f"den die Bibliothek fuer den Mikrowechselrichter nennt.", {})
        erg = await api.set_device_pv_power(deviceSn=sn, limit=int(watt))
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        return (ok, f"Begrenzung {watt} W an {sn} gesendet." if ok
                else f"Die Cloud hat die Begrenzung {watt} W nicht angenommen.", {"watt": watt, "sn": sn})

    sn, geraet = speicher_waehlen(api, site_id, str(b.get("sn") or "") or None)
    if sn is None:
        return (0, "In dieser Anlage ist kein Speicher (Solarbank) bekannt.", {})
    generation = zahl(geraet.get("generation")) or 0

    if aktion == "hauslast":
        watt = zahl(b.get("watt"))
        if watt is None:
            return (0, "Der Wert fuer die Hauslast fehlt oder ist keine Zahl.", {})
        lo, hi = grenzen(cfg, nummer)
        if watt < lo or watt > hi:
            # Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
            # fuehrt zu einer Anlage, die etwas anderes tut als angezeigt.
            return (0, f"Hauslast {watt} W liegt ausserhalb der eingestellten Grenzen ({lo} bis {hi} W) "
                       f"fuer Anlage {nummer}. Grenzen im Reiter Einstellungen anpassen, "
                       f"wenn Ihr Geraet mehr kann.", {})
        # Schrittweite: eine Aenderung um wenige Watt kostet eine Cloud-Anfrage
        # und bewirkt nichts. Auch das wird GEMELDET, nicht verschluckt.
        schritt = int(cfg.get("schrittweite") or 0)
        alt = zahl(erstes(api.sites.get(site_id) or {}, "retain_load", "set_load_power"))
        if schritt > 0 and alt is not None and abs(watt - alt) < schritt:
            return (0, f"Der Sollwert weicht nur um {abs(watt - alt)} W vom bisherigen ab "
                       f"(Schrittweite {schritt} W). Nicht gesendet.", {"watt": watt, "sn": sn})
        if generation >= 2:
            erg = await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, preset=float(watt))
        else:
            erg = await api.set_home_load(siteId=site_id, deviceSn=sn, preset=int(watt))
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        return (ok, f"Hauslast {watt} W an {sn} gesendet." if ok
                else f"Die Cloud hat den Sollwert {watt} W nicht angenommen.", {"watt": watt, "sn": sn})

    if aktion == "modus":
        wert = str(b.get("wert") or "").strip().lower()
        if wert not in MODI:
            return (0, f"Unbekannter Modus '{wert}'. Erlaubt sind: {', '.join(sorted(MODI))}.", {})
        if generation < 2:
            return (0, "Betriebsmodi gibt es erst ab Solarbank 2. Die Solarbank 1 kennt nur Zeitplaene.", {})
        # Was dieses Geraet wirklich annimmt, weiss das Geraet - nicht wir.
        erlaubt = await modi_des_geraets(api, sn, site_id)
        if erlaubt and wert not in erlaubt:
            return (0, f"Der Modus '{wert}' wird von {sn} nicht angeboten. "
                       f"Gemeldet werden: {', '.join(sorted(erlaubt))}.", {})
        erg = await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, usage_mode=MODI[wert])
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        return (ok, f"Modus '{wert}' an {sn} gesendet." if ok
                else f"Die Cloud hat den Modus '{wert}' nicht angenommen.", {"modus": wert, "sn": sn})

    if aktion == "reserve":
        prozent = zahl(b.get("prozent"))
        if prozent is None:
            return (0, "Der Prozentwert fuer die Reserve fehlt oder ist keine Zahl.", {})
        stufen = await api.get_power_cutoff(deviceSn=sn)
        liste = (stufen or {}).get("power_cutoff_data") or []
        treffer = None
        erlaubt = []
        for e in liste:
            p = zahl(e.get("output_cutoff_data"))
            if p is not None:
                erlaubt.append(p)
                if p == prozent:
                    treffer = e
        if treffer is None:
            # Auch hier: nicht auf die naechste Stufe runden. Das Geraet gibt
            # feste Stufen vor, und welche gemeint war, weiss nur der Mensch.
            return (0, f"{prozent} % ist keine zulaessige Entladegrenze fuer {sn}. "
                       f"Zulaessig sind: {', '.join(str(x) for x in sorted(set(erlaubt))) or 'keine gemeldet'}.", {})
        erg = await api.set_power_cutoff(deviceSn=sn, setId=int(treffer.get("id")))
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        return (ok, f"Entladegrenze {prozent} % an {sn} gesendet." if ok
                else f"Die Cloud hat die Entladegrenze {prozent} % nicht angenommen.",
                {"prozent": prozent, "sn": sn})

    if aktion in ("einspeisung", "einspeisegrenze", "notstromreserve"):
        # Alle drei gehen ueber set_station_parm. Es wird immer nur EIN
        # Parameter mitgegeben - die uebrigen bleiben None und damit
        # unveraendert. Ein Aufruf, der nebenbei zwei andere Werte
        # zuruecksetzt, waere ein Eingriff, den niemand angefordert hat.
        felder: dict = {}
        if aktion == "einspeisung":
            wert = str(b.get("wert") or "").strip().lower()
            if wert not in ("ein", "aus"):
                return (0, f"Unbekannter Wert '{wert}'. Erlaubt sind: ein, aus.", {})
            felder["gridExport"] = (wert == "ein")
            text = f"Netzeinspeisung '{wert}'"
        elif aktion == "einspeisegrenze":
            watt = zahl(b.get("watt"))
            if watt is None:
                return (0, "Der Wert fuer die Einspeisegrenze fehlt oder ist keine Zahl.", {})
            if watt < 0 or watt > 20000:
                return (0, f"{watt} W ist als Einspeisegrenze nicht plausibel (0 bis 20000 W).", {})
            felder["gridExportLimit"] = int(watt)
            text = f"Einspeisegrenze {watt} W"
        else:
            prozent = zahl(b.get("prozent"))
            if prozent is None:
                return (0, "Der Prozentwert fuer die Notstromreserve fehlt oder ist keine Zahl.", {})
            if prozent < 0 or prozent > 100:
                return (0, f"{prozent} % liegt ausserhalb von 0 bis 100 %.", {})
            felder["socReserve"] = int(prozent)
            text = f"Notstromreserve {prozent} %"

        if not hasattr(api, "set_station_parm"):
            return (0, "Diese Fassung von anker-solix-api kennt set_station_parm nicht. "
                       "Das Plugin ist auf v3.6.3 abgestimmt.", {})
        erg = await api.set_station_parm(siteId=site_id, deviceSn=sn, **felder)
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        return (ok, f"{text} an {sn} gesendet." if ok
                else f"Die Cloud hat '{text}' nicht angenommen.", {"sn": sn})

    return (0, f"Unbekannte Aktion '{aktion}'.", {})


async def modi_des_geraets(api, sn: str, site_id: str) -> list:
    """Welche Betriebsarten dieses Geraet anbietet - gefragt, nicht geraten.

    Eine fest eingetragene Liste waere eine Behauptung ueber fremde Hardware.
    Antwortet die Bibliothek nicht (aeltere Fassung, Einzelgeraet), bleibt die
    Liste leer, und dann wird NICHT gesperrt - eine leere Antwort ist kein
    Beleg dafuer, dass ein Modus fehlt.
    """
    if not hasattr(api, "solarbank_usage_mode_options"):
        return []
    try:
        erg = api.solarbank_usage_mode_options(deviceSn=sn, siteId=site_id)
        if asyncio.iscoroutine(erg):
            erg = await erg
    except Exception:  # noqa: BLE001 - eine Auskunft, die scheitert, sperrt nichts
        return []
    namen = []
    for e in (erg or []):
        # Die Bibliothek gibt je nach Fassung Namen oder Zahlen zurueck.
        n = zahl(e)
        if n is not None and n in MODI_NAME:
            namen.append(MODI_NAME[n])
        else:
            s = str(getattr(e, "name", e)).strip().lower()
            for k, v in {"smartmeter": "eigenverbrauch", "smartplugs": "steckdosen",
                         "manual": "manuell", "use_time": "zeitplan",
                         "smart": "smart", "time_slot": "zeitfenster"}.items():
                if s == k:
                    namen.append(v)
    return sorted(set(namen))


async def warteschlange(api, cfg: dict) -> bool:
    """Arbeitet alle vorliegenden Befehle ab. Rueckgabe: True, wenn ein
    Sofortabruf angefordert wurde."""
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    sofort = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        kennung = datei.stem
        # Alter VOR dem Loeschen merken.
        try:
            alter = time.time() - datei.stat().st_mtime
        except OSError:
            alter = 0.0
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue

        # Veraltete Befehle werden verworfen, nicht ausgefuehrt.
        #
        # Die Befehle legt die Oberflaeche als Dateien ab; abgearbeitet werden
        # sie vom Dienst. Stand der Dienst zwischendurch still - Cloud gesperrt,
        # Absturz, Update -, dann liegen beim Neustart Befehle von vor Stunden
        # da. Eine Hauslast von heute Vormittag jetzt an die Solarbank zu
        # schicken, ist schlimmer als sie gar nicht zu schicken: es greift
        # unerwartet in die Speicherlogik ein, und niemand versteht, warum.
        if alter > BEFEHL_HOECHSTALTER:
            antwort_schreiben(kennung, 0,
                              f"Befehl war {int(alter)} s alt und wurde verworfen "
                              f"(Grenze {BEFEHL_HOECHSTALTER} s). Bitte erneut ausloesen.")
            _LOG.warning("Befehl %s (%s) verworfen: %d s alt.",
                         kennung, b.get("aktion"), int(alter))
            continue
        try:
            ok, meldung, zusatz = await befehl_ausfuehren(api, cfg, b)
        except Exception as err:  # noqa: BLE001 - jeder Fehler gehoert gemeldet, nicht verschluckt
            ok, meldung, zusatz = 0, fehlertext(err), {}
            if ist_429(err):
                _ZAEHLWERK["http429"] += 1
        antwort_schreiben(kennung, ok, meldung, zusatz)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        if b.get("aktion") == "abruf" and ok:
            sofort = True
    return sofort


# ---------------------------------------------------------------------------
# Rueckfall in eine sichere Betriebsart
#
# Anker kennt keinen Watchdog: ein gesetzter Sollwert bleibt stehen, auch wenn
# Loxone ausfaellt. Bis 0.9.6 schob die README diese Aufgabe an Loxone - was
# genau dann nicht traegt, wenn man sie braucht, denn Loxone ist ja gerade das,
# was ausgefallen ist.
#
# Ab Werk aus (rueckfall_min = 0). Wer ihn einschaltet, bekommt einen Eingriff,
# der ohne sein Zutun geschieht - das gehoert entschieden, nicht vorgegeben.
# ---------------------------------------------------------------------------
async def rueckfall_pruefen(api, cfg: dict) -> None:
    minuten = int(cfg.get("rueckfall_min") or 0)
    if minuten <= 0 or not cfg.get("steuerung_ein"):
        return
    try:
        letzte = int(DATEI_LETZTER_SCHREIB.read_text())
    except (OSError, ValueError):
        return          # es gab nie einen Sollwert - also nichts zurueckzunehmen
    if letzte <= 0 or (time.time() - letzte) < minuten * 60:
        return
    modus = str(cfg.get("rueckfall_modus") or "eigenverbrauch")
    getan = []
    for site_id in sorted(api.sites.keys()):
        sn, geraet = speicher_waehlen(api, site_id, None)
        if sn is None or (zahl(geraet.get("generation")) or 0) < 2:
            continue
        try:
            await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, usage_mode=MODI[modus])
            getan.append(sn)
        except Exception as err:  # noqa: BLE001
            _LOG.error("Rueckfall an %s fehlgeschlagen: %s", sn, fehlertext(err))
    # Den Merker in JEDEM Fall zuruecksetzen, auch wenn nichts gelang: sonst
    # rennt der Rueckfall bei jedem Durchlauf erneut gegen dieselbe Wand.
    schreibbefehl_vermerken()
    if getan:
        text = (f"Seit {minuten} min kam kein Sollwert mehr. Die Betriebsart wurde auf "
                f"'{modus}' zurueckgestellt ({', '.join(getan)}).")
        _LOG.warning(text)
        benachrichtigen(4, text, "rueckfall", 3600)


# ---------------------------------------------------------------------------
# Zusatzangaben, die eigene Abfragen kosten
# ---------------------------------------------------------------------------
async def cutoff_stufen(api, sn: str) -> list:
    """Die zulaessigen Entladegrenzen eines Geraets."""
    try:
        stufen = await api.get_power_cutoff(deviceSn=sn)
    except Exception:  # noqa: BLE001 - eine Auskunft, die scheitert, ist kein Abbruch
        return []
    out = []
    for e in ((stufen or {}).get("power_cutoff_data") or []):
        p = zahl(e.get("output_cutoff_data"))
        if p is not None:
            out.append(p)
    return sorted(set(out))


async def prognose_holen(api, site_id: str) -> dict:
    """Solarprognose der Anlage.

    Die Bibliothek laesst hoechstens eine Auffrischung je Stunde zu; der Takt
    dafuer steht eigens in den Einstellungen. Was nicht kommt, bleibt None -
    eine erfundene 0 saehe in Loxone aus wie "heute kommt nichts mehr".
    """
    out: dict = {}
    try:
        if hasattr(api, "refresh_pv_forecast"):
            await api.refresh_pv_forecast(siteId=site_id)
        if hasattr(api, "extractSolarForecast"):
            erg = api.extractSolarForecast(siteId=site_id)
            if asyncio.iscoroutine(erg):
                erg = await erg
            if isinstance(erg, dict):
                out["prognose_tag"] = zahl(erstes(erg, "trend_today", "today", "produced_today"), 2)
                out["prognose_rest"] = zahl(erstes(erg, "remain_today", "rest_today", "remaining"), 2)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("prognose", f"Solarprognose nicht abrufbar: {fehlertext(err)}", 3600)
    return out


# ---------------------------------------------------------------------------
# Abbild schreiben
# ---------------------------------------------------------------------------
def abbild_schreiben(api, cfg: dict, ok: int, fehler: str = "", zusatz: dict | None = None) -> dict:
    zusatz = zusatz or {}
    ids = sorted(api.sites.keys())
    anlagen = {}
    for i, sid in enumerate(ids, start=1):
        a = anlage_abbilden(sid, api.sites.get(sid) or {}, api.devices,
                            (zusatz.get("anlagen") or {}).get(sid) or {})
        if cfg.get("zaehler_ein"):
            a["zaehler"] = energie_fortschreiben(i, a["energie"], int(cfg["energie_tage"]))
        anlagen[str(i)] = a
    geraete = {sn: geraet_abbilden(sn, d, (zusatz.get("geraete") or {}).get(sn) or {})
               for sn, d in api.devices.items()}

    # Der Zeitstempel wird NUR nach einem erfolgreichen Abruf fortgeschrieben.
    #
    # Bis 0.9.6 stand hier bedingungslos time.time(). Damit mass ALTER den
    # Abstand zum letzten VERSUCH, nicht zum letzten ERFOLG - und die
    # Ausfallerkennung, die der Reiter "Einbindung in Loxone" in Schritt 7
    # beschreibt ("Sekunden seit dem letzten erfolgreichen Abruf", Schwelle
    # 300 s), konnte niemals ansprechen: bei einer drei Tage langen Stoerung
    # blieb ALTER unter 60.
    alt = json_lesen(DATEI_LOXONE)
    ts = int(time.time()) if ok else int(alt.get("ts") or 0)

    lox = {
        "ok": ok,
        "ts": ts,
        "ts_versuch": int(time.time()),
        "fehler": fehler,
        "anzahl_anlagen": len(ids),
        "zaehlwerk": dict(_ZAEHLWERK),
        "anlagen": anlagen,
        "geraete": geraete,
    }
    json_schreiben(DATEI_LOXONE, lox)

    # Der vollstaendige Zwischenspeicher fuer die Fehlersuche. Zugangsdaten
    # stehen hier nicht drin - die Bibliothek fuehrt sie getrennt.
    json_schreiben(DATEI_CACHE, {
        "ts": int(time.time()),
        "sites": api.sites,
        "devices": api.devices,
        "account": {k: v for k, v in (api.account or {}).items()
                    if k not in ("password", "token", "auth_token")},
    })

    for nummer, a in anlagen.items():
        verlauf_anhaengen(int(nummer), a.get("soc"), a.get("batp"), cfg["verlauf_tage"])

    mqtt_ausgeben(lox, cfg, ok)
    return lox


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    z["zaehlwerk"] = dict(_ZAEHLWERK)
    json_schreiben(DATEI_ZUSTAND, z)


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


def drosselung_setzen(api, cfg: dict) -> None:
    """Die Anfragedrosselung der Bibliothek einstellen - und sagen, ob es ging.

    Bis 0.9.6 stand hier:

        if hasattr(ziel, "endpoint_limit"):
            ziel.endpoint_limit = cfg["endpunkt_limit"]

    In v3.6.3 gibt es kein Attribut 'endpoint_limit'. Das Feld heisst
    '_endpoint_limit' (privat), gesetzt wird ueber die METHODE
    endpointLimit(n) - vorhanden an AnkerSolixApi und an apisession. hasattr()
    lieferte also False, der Block lief leer, und das Eingabefeld in der
    Oberflaeche war ohne jede Wirkung. Ein 'except AttributeError: pass'
    versteckte den Fehler zusaetzlich.

    Deshalb: der Weg wird benannt, und wenn keiner passt, steht das im
    Protokoll. Eine Einstellung, die nicht ankommt, gehoert gemeldet.
    """
    gesetzt = []
    for name, wert, kandidaten in (
        ("Anfragen je Minute", int(cfg["endpunkt_limit"]), ("endpointLimit",)),
        ("Pause zwischen Anfragen", int(cfg["anfrage_pause"]) / 10.0, ("requestDelay",)),
        ("Zeitschranke je Anfrage", int(cfg["anfrage_frist"]), ("requestTimeout",)),
    ):
        erledigt = False
        for ziel in (api, getattr(api, "apisession", None)):
            if ziel is None:
                continue
            for k in kandidaten:
                fn = getattr(ziel, k, None)
                if callable(fn):
                    try:
                        fn(wert)
                        erledigt = True
                        break
                    except (TypeError, ValueError) as err:
                        _LOG.warning("%s liess sich nicht setzen (%s): %s", name, k, err)
            if erledigt:
                break
        if erledigt:
            gesetzt.append(f"{name}={wert}")
        else:
            _LOG.warning("%s konnte nicht gesetzt werden - diese Fassung von anker-solix-api "
                         "kennt keinen der Wege %s. Die Einstellung bleibt ohne Wirkung.",
                         name, ", ".join(kandidaten))
    if gesetzt:
        _LOG.info("Drosselung gesetzt: %s", "; ".join(gesetzt))


async def dienst(einmal: bool = False, freigeben: bool = False) -> int:
    from aiohttp import ClientSession, ClientTimeout
    from anker_solix_api.api import AnkerSolixApi

    cfg = config()
    z = zugang()
    if not z["email"] or not z["passwort"]:
        _LOG.error("Zugangsdaten fehlen. Reiter Einstellungen der Plugin-Oberflaeche oeffnen.")
        zustand_schreiben(ok=0, fehler="Zugangsdaten fehlen.")
        return 1

    if not freigeben:
        _LOG.info("Dienst startet (Takt %s s, Land %s, Steuerung %s).",
                  cfg["intervall"], cfg["land"], "ein" if cfg.get("steuerung_ein") else "aus")

    # Zeitschranke an der Sitzung selbst: ohne sie kann ein Abruf, den die
    # Gegenstelle offen laesst, den Dienst dauerhaft anhalten - und der
    # Minutenwaechter startet ihn nicht neu, weil der Prozess ja lebt.
    frist = ClientTimeout(total=max(15, int(cfg["anfrage_frist"]) * 3))
    async with ClientSession(timeout=frist) as sitzung:
        api = AnkerSolixApi(z["email"], z["passwort"], cfg["land"], sitzung, _LOG)
        drosselung_setzen(api, cfg)

        if freigeben:
            # Einmalig die Betriebsart zuruecksetzen. Die Deinstallation ruft
            # das auf, damit kein gestellter Sollwert zurueckbleibt.
            try:
                await api.update_sites()
            except Exception as err:  # noqa: BLE001
                _LOG.error("Freigabe: Anlagen nicht abrufbar: %s", fehlertext(err))
                return 1
            erledigt = 0
            for site_id in sorted(api.sites.keys()):
                sn, geraet = speicher_waehlen(api, site_id, None)
                if sn is None or (zahl(geraet.get("generation")) or 0) < 2:
                    continue
                try:
                    await api.set_sb2_home_load(siteId=site_id, deviceSn=sn,
                                                usage_mode=MODI["eigenverbrauch"])
                    erledigt += 1
                    _LOG.info("Freigabe: %s auf Eigenverbrauch gestellt.", sn)
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Freigabe an %s fehlgeschlagen: %s", sn, fehlertext(err))
            return 0 if erledigt else 1

        zyklus = 0
        fehler_folge = 0
        # Zeitpunkt des letzten ERFOLGREICHEN Abrufs je Gruppe.
        #
        # Frueher hing das an einem Modulo-Zaehler: if zyklus % takt == 0.
        # Schlug die Cloud genau in diesem Zyklus fehl - ein HTTP 429 genuegt -,
        # wurde der Zaehler am Schleifenende trotzdem hochgezaehlt, und die
        # Energiedaten kamen erst einen ganzen Block spaeter wieder dran. Bei
        # takt_energie 15 sind das 15 verlorene Durchlaeufe wegen eines
        # einzigen Aussetzers. Mit Zeitstempeln wird beim naechsten
        # gelungenen Durchlauf sofort nachgeholt.
        letzte_details = 0.0
        letzte_energie = 0.0
        letzte_prognose = 0.0
        letzte_stufen = 0.0
        zusatz: dict = {"anlagen": {}, "geraete": {}}
        while _LAUF:
            cfg = config()  # Aenderungen aus der Oberflaeche ohne Neustart uebernehmen
            ok = 0
            fehler = ""
            teilfehler = []
            jetzt = time.time()

            # JEDER Abruf mit eigenem try. Frueher lagen alle drei in einem
            # gemeinsamen Block: schlug die Detailabfrage fehl, obwohl
            # update_sites() gerade frische Werte geliefert hatte, sprang das
            # Skript in den except-Zweig, ok blieb 0 - und Loxone verwarf die
            # gueltigen Werte gleich mit. Ein Teilausfall darf nicht alles
            # entwerten.
            try:
                _ZAEHLWERK["anfragen"] += 1
                await api.update_sites()
                ok = 1
                fehler_folge = 0
            except Exception as err:  # noqa: BLE001
                teilfehler.append(f"Anlagen: {fehlertext(err)}")
                fehler_folge += 1
                _ZAEHLWERK["fehler"] += 1
                if ist_429(err):
                    _ZAEHLWERK["http429"] += 1

            if ok and not cfg.get("ohne_details") \
                    and (jetzt - letzte_details) >= cfg["takt_details"] * cfg["intervall"]:
                try:
                    # Reihenfolge ist vorgegeben: Geraetedetails zuerst, sie
                    # legen bei Einzelgeraeten erst die virtuellen Anlagen an.
                    await api.update_device_details()
                    await api.update_site_details()
                    letzte_details = jetzt
                except Exception as err:  # noqa: BLE001
                    teilfehler.append(f"Details: {fehlertext(err)}")
                    _ZAEHLWERK["fehler"] += 1

            if ok and not cfg.get("ohne_energie") \
                    and (jetzt - letzte_energie) >= cfg["takt_energie"] * cfg["intervall"]:
                try:
                    await api.update_device_energy()
                    letzte_energie = jetzt
                except Exception as err:  # noqa: BLE001
                    teilfehler.append(f"Energie: {fehlertext(err)}")
                    _ZAEHLWERK["fehler"] += 1

            if ok and not cfg.get("ohne_prognose") \
                    and (jetzt - letzte_prognose) >= cfg["takt_prognose"] * 60:
                for site_id in sorted(api.sites.keys()):
                    zusatz["anlagen"].setdefault(site_id, {}).update(
                        await prognose_holen(api, site_id))
                letzte_prognose = jetzt

            # Die zulaessigen Entladegrenzen aendern sich nie - einmal je Tag
            # genuegt. Sie stehen danach in der Oberflaeche, statt dass der
            # Anwender sie durch Danebenliegen herausfindet.
            if ok and (jetzt - letzte_stufen) >= 86400:
                for sn, d in api.devices.items():
                    if str(d.get("type") or "") == "solarbank":
                        zusatz["geraete"].setdefault(sn, {})["cutoff_stufen"] = \
                            await cutoff_stufen(api, sn)
                for site_id in sorted(api.sites.keys()):
                    sn, _g = speicher_waehlen(api, site_id, None)
                    if sn:
                        zusatz["anlagen"].setdefault(site_id, {})["modi_erlaubt"] = \
                            await modi_des_geraets(api, sn, site_id)
                letzte_stufen = jetzt

            if teilfehler:
                fehler = "; ".join(teilfehler)
                melde_gebremst("abruf", f"Abruf unvollstaendig: {fehler}", 900)

            abbild_schreiben(api, cfg, ok, fehler, zusatz)
            zustand_schreiben(ok=ok, fehler=fehler, zyklus=zyklus, fehler_folge=fehler_folge,
                              pid=os.getpid(), intervall=cfg["intervall"])

            # Meldeweg: erst wenn das Abbild wirklich zu alt geworden ist.
            # Ein einzelner Aussetzer ist kein Anlass - drei Stunden Stille
            # sind einer, und niemand merkt sie von selbst.
            lox_alter = int(time.time()) - int((json_lesen(DATEI_LOXONE).get("ts") or 0))
            if not ok and lox_alter > int(cfg["melden_alter"]):
                benachrichtigen(3, f"Anker SOLIX: seit {lox_alter // 60} min kein erfolgreicher "
                                   f"Abruf. Letzte Meldung: {fehler or 'unbekannt'}", "abruf_alt")

            if ok:
                await rueckfall_pruefen(api, cfg)
                _LOG.debug("Abruf %s: %s Anlagen, %s Geraete", zyklus, len(api.sites), len(api.devices))
            zyklus += 1
            if einmal:
                return 0 if ok else 1

            # Wartezeit in Sekundenschritten, damit Befehle aus der
            # Warteschlange nicht bis zum naechsten Takt liegen bleiben.
            rest = cfg["intervall"]
            # Nach mehreren Fehlschlaegen den Abstand vergroessern, statt gegen
            # eine gestoerte Cloud anzurennen.
            if fehler_folge >= 3:
                rest = min(900, cfg["intervall"] * min(8, fehler_folge))
                melde_gebremst("bremse", f"{fehler_folge} Fehlversuche - naechster Abruf erst in {rest} s.", 1800)
            while rest > 0 and _LAUF:
                try:
                    if await warteschlange(api, cfg):
                        break  # Sofortabruf angefordert
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                await asyncio.sleep(1)
                rest -= 1
    _LOG.info("Dienst beendet.")
    return 0


# ---------------------------------------------------------------------------
# Selbsttest - beantwortet ohne Netz und ohne Loxone, ob die Einrichtung traegt
# ---------------------------------------------------------------------------
def selbsttest() -> int:
    zeilen = []
    fehler = 0

    v = sys.version_info
    if v >= (3, 12):
        zeilen.append(f"[OK]   Python {v.major}.{v.minor}.{v.micro} (die Bibliothek verlangt 3.12 oder neuer)")
    else:
        fehler += 1
        zeilen.append(f"[FEHL] Python {v.major}.{v.minor}.{v.micro} ist zu alt - anker-solix-api verlangt 3.12 oder neuer")

    venv = SELF / "venv" / "bin" / "python3"
    zeilen.append(f"[{'OK]  ' if venv.exists() else 'FEHL]'} Virtuelle Umgebung: {venv}")
    if not venv.exists():
        fehler += 1

    try:
        import importlib.metadata as md
        from anker_solix_api.api import AnkerSolixApi
        try:
            fassung = md.version("anker-solix-api")
        except Exception:  # noqa: BLE001
            fassung = "unbekannt"
        zeilen.append(f"[OK]   Bibliothek anker-solix-api geladen, Fassung {fassung}")

        # Die Wege, ueber die das Plugin die Drosselung setzt und schreibt -
        # nachgesehen, nicht angenommen. Genau hier lag der Fehler bis 0.9.6:
        # das Plugin setzte ein Attribut, das es nie gab, und schwieg dazu.
        for name, kandidaten in (
            ("Anfragedrosselung", ("endpointLimit",)),
            ("Pause zwischen Anfragen", ("requestDelay",)),
            ("Zeitschranke", ("requestTimeout",)),
            ("Stationsparameter (Einspeisung, Reserve)", ("set_station_parm",)),
            ("Wechselrichter-Begrenzung", ("set_device_pv_power",)),
            ("Betriebsarten des Geraets", ("solarbank_usage_mode_options",)),
            ("Solarprognose", ("refresh_pv_forecast", "extractSolarForecast")),
        ):
            gefunden = [k for k in kandidaten
                        if hasattr(AnkerSolixApi, k)
                        or hasattr(getattr(AnkerSolixApi, "apisession", object), k)]
            if gefunden:
                zeilen.append(f"[OK]   {name}: {', '.join(gefunden)}")
            else:
                fehler += 1
                zeilen.append(f"[FEHL] {name}: keiner der Wege {', '.join(kandidaten)} vorhanden - "
                              f"die zugehoerige Einstellung bliebe ohne Wirkung")
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append(f"[FEHL] Bibliothek anker-solix-api laesst sich nicht laden: {err}")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = os.access(pfad, os.W_OK) if pfad.exists() else False
        zeilen.append(f"[{'OK]  ' if schreibbar else 'FEHL]'} Ordner {name} beschreibbar: {pfad}")
        if not schreibbar:
            fehler += 1

    zeilen.append(f"[{'OK]  ' if NOTIFY.is_file() else 'INFO]'} Meldeweg: {NOTIFY}"
                  + ("" if NOTIFY.is_file() else " fehlt - es gibt dann keine Benachrichtigungen"))

    z = zugang()
    # Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie seinen Wert zeigen.
    if z["email"] and "@" in z["email"]:
        zeilen.append(f"[OK]   Anker-Benutzername hinterlegt ({z['email'][:2]}...@..., {len(z['email'])} Zeichen)")
    elif z["email"]:
        fehler += 1
        zeilen.append("[FEHL] Der Anker-Benutzername sieht nicht wie eine E-Mail-Adresse aus")
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Anker-Benutzername hinterlegt")
    if z["passwort"]:
        zeilen.append(f"[OK]   Passwort hinterlegt ({len(z['passwort'])} Zeichen, Inhalt wird nicht angezeigt)")
    else:
        fehler += 1
        zeilen.append("[FEHL] Kein Passwort hinterlegt")
    try:
        rechte = oct(DATEI_ZUGANG.stat().st_mode & 0o777)
        passt = (DATEI_ZUGANG.stat().st_mode & 0o077) == 0
        zeilen.append(f"[{'OK]  ' if passt else 'FEHL]'} Rechte der Zugangsdatei: {rechte} (erwartet 0o600)")
        if not passt:
            fehler += 1
    except OSError:
        fehler += 1
        zeilen.append(f"[FEHL] Zugangsdatei fehlt: {DATEI_ZUGANG}")

    c = config()
    # Zeitzone sichtbar machen: die Verlaufsdateien tragen das Datum im Namen,
    # und ein Grundsystem auf UTC teilt den Tag im Sommer schon um 22 Uhr.
    if ZEITZONE:
        zeilen.append(f"[OK]   Zeitzone {ZEITZONE}, jetzt ist {time.strftime('%d.%m.%Y %H:%M')}")
    else:
        zeilen.append(f"[INFO] Keine Zeitzone ermittelt - es gilt die des Grundsystems. "
                      f"Jetzt ist {time.strftime('%d.%m.%Y %H:%M')}; stimmt das nicht, "
                      f"teilt der Verlauf den Tag an der falschen Stelle.")
    zeilen.append(f"[INFO] Takt {c['intervall']} s, Details alle {c['takt_details']} Takte, "
                  f"Energie alle {c['takt_energie']} Takte, Land {c['land']}")
    weg = [n for n, k in (("Details", "ohne_details"), ("Energie", "ohne_energie"),
                          ("Prognose", "ohne_prognose")) if c.get(k)]
    zeilen.append(f"[INFO] Abgeschaltete Abfragen: {', '.join(weg) if weg else 'keine'}")
    zeilen.append(f"[INFO] Schreibende Befehle: {'zugelassen' if c.get('steuerung_ein') else 'gesperrt'}, "
                  f"Hauslast erlaubt von {c['hauslast_min']} bis {c['hauslast_max']} W, "
                  f"Schreibbremse {c['schreibbremse']} s, Schrittweite {c['schrittweite']} W")
    if c.get("rueckfall_min"):
        zeilen.append(f"[INFO] Rueckfall nach {c['rueckfall_min']} min ohne Sollwert "
                      f"auf '{c['rueckfall_modus']}'")
    else:
        zeilen.append("[INFO] Rueckfall in eine sichere Betriebsart: aus. "
                      "Anker kennt keinen Watchdog - ein gesetzter Sollwert bleibt stehen.")

    m = mqtt_zustand()
    if not m["gefunden"]:
        zeilen.append("[FEHL] Im general.json des LoxBerry ist kein MQTT-Abschnitt zu finden")
        fehler += 1
    elif m["autostart"]:
        zeilen.append(f"[OK]   MQTT-Gateway auf Autostart, Broker {m['broker']}:{m['brokerport']}, "
                      f"UDP-Eingang {m['udpport']}")
    else:
        zeilen.append("[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt "
                      "(System -> MQTT Gateway). Ohne das kommt am Miniserver nichts an.")
        fehler += 1

    z2 = json_lesen(DATEI_ZUSTAND)
    if z2:
        lox = json_lesen(DATEI_LOXONE)
        alter = int(time.time()) - int(lox.get("ts") or 0)
        zeilen.append(f"[INFO] Letzter ERFOLGREICHER Abruf vor {alter} s, ok={z2.get('ok')}, "
                      f"Fehler: {z2.get('fehler') or 'keiner'}")
        zw = z2.get("zaehlwerk") or {}
        if zw:
            zeilen.append(f"[INFO] Zaehlwerk: {zw.get('anfragen', 0)} Abrufe, "
                          f"{zw.get('fehler', 0)} Fehlschlaege, {zw.get('http429', 0)} Abweisungen (429)")
    else:
        zeilen.append("[INFO] Es hat noch kein Abruf stattgefunden")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer ein Anker-Konto und ein Geraet noetig sind:")
    zeilen.append("  - ob die Anmeldung an der Anker-Cloud gelingt")
    zeilen.append("  - ob die Feldnamen dieser Cloud-Fassung zu der Zuordnung hier passen")
    zeilen.append("  - ob die schreibenden Befehle am Geraet die erwartete Wirkung haben")
    zeilen.append("  - insbesondere: Einspeisegrenze, Notstromreserve und die")
    zeilen.append("    Wechselrichter-Begrenzung sind neu in 0.9.7 und an keinem Geraet erprobt")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    # --vorgaben braucht kein Protokoll und keine Pfade: es gibt nur die Liste
    # aus, damit die Oberflaeche sie gegen ihre eigene halten kann.
    if "--vorgaben" in sys.argv:
        print(json.dumps(VORGABEN, ensure_ascii=False, sort_keys=True))
        return 0
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst(einmal="--einmal" in sys.argv,
                                  freigeben="--freigeben" in sys.argv))
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
