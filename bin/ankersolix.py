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
    ankersolix.py --themen        die Themen, die der Sendecode bildet, als
                                  JSON (fuer den Reiter Test; schreibt nichts)
    ankersolix.py --freigeben     einmalig die Betriebsart 'eigenverbrauch'
                                  setzen und beenden; die Deinstallation ruft
                                  das auf, damit kein gestellter Sollwert
                                  zurueckbleibt
    ankersolix.py --abraeumen [p] die zurueckbehaltenen Themen unter dem
                                  Praefix p (ohne p: alle gemerkten) am
                                  Broker leeren und ruecklesen (seit 0.9.32,
                                  K22); Oberflaeche und Deinstallation
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
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


def lb_wurzel_lesen() -> Path:
    """Die LoxBerry-Wurzel GELESEN, nicht geraten - drei Stufen wie bin/dienst.sh.

    Bis 0.9.18 stand hier "LBHOME = SELF.parents[2]": drei Ebenen ueber dem
    Ablageort, ein gesetztes LBHOMEDIR zaehlte nur, wenn das Skript flacher
    lag. In WSL gemessen (Pruefung-AnkerSolix-0.9.19, Faelle Y1/Y2): ein
    "--selbsttest" oder "--einmal" aus einem Pruefarchiv unter
    <Wurzel>/pruefung/ankersolix/bin legte in der laufenden Anlage
    log/plugins/bin und data/plugins/bin an - dieselbe Bauart wie H1 in
    Bestand-2026-09-18/klasse-H.

      1. LBHOMEDIR, wenn es eine Wurzel bezeichnet,
      2. aufwaerts suchen bis config/plugins, data/plugins UND
         config/system/general.json,
      3. drei Ebenen ueber dem Ablageort (bisheriges Verhalten), bei flacher
         Lage lb_wurzel_ermitteln().
    """
    umg = os.environ.get("LBHOMEDIR") or ""
    if umg and os.path.isdir(os.path.join(umg, "config", "plugins")) \
            and os.path.isdir(os.path.join(umg, "data", "plugins")):
        return Path(umg).resolve()
    d = SELF
    for _ in range(8):
        if (d / "config" / "plugins").is_dir() and (d / "data" / "plugins").is_dir() \
                and (d / "config" / "system" / "general.json").is_file():
            return d
        if d.parent == d:
            break
        d = d.parent
    if len(SELF.parents) >= 3:
        return SELF.parents[2]
    return Path(lb_wurzel_ermitteln())


LBHOME = lb_wurzel_lesen()
# Liegt dieses Skript wirklich in der Installation unter dieser Wurzel? Nur
# dann legt es Ordner an und laeuft als Dienst (main(), log_einrichten()).
# Aus einem Pruefarchiv waere PNAME "bin", aus einer Baumkopie die Wurzel eine
# fremde (Faelle Y2, Y5).
INSTALLIERT = SELF == LBHOME / "bin" / "plugins" / PNAME
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "ankersolix.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
DATEI_LETZTER_SCHREIB = PDATA / "letzter_schreibbefehl"
# Wann kam zuletzt ein gueltiger Sollwert von Loxone (Entscheidung Nr. 22)?
# Gemeinsam mit dem Endpunkt (ak_sollwert_empfangen_vermerken() in ak_lib.php),
# beide schreiben unter flock auf die Sperrdatei.
DATEI_SOLLWERT_EMPFANGEN = PDATA / "sollwert_empfangen"
DATEI_SOLLWERT_SPERRE = PDATA / "sollwert_empfangen.sperre"
# Ein Merker weiter als so viele Sekunden in der Zukunft gilt als ungueltig.
SOLLWERT_ZUKUNFT_S = 60
# Die Warteschlange der Auftraege (Endpunkt, Reiter Test, Befehlsbruecke).
# Bis 0.9.31 hiess der Ordner befehle/ - genau dort legt die Befehlsbruecke
# 1.0.1 seit 0.9.32 ihren Zustand ab (zustand.json, waechter.json,
# mosquitto/). warteschlange() frass jede *.json darin (Entwurf N1, Probe
# probe_n1_g26). Deshalb ein eigener Ordner UND nur Namen der eigenen Form -
# ein Ordner mit zwei Eigentuemern ist die Falle (E-E1).
ORDNER_BEFEHLE = PDATA / "auftraege"
AUFTRAG_MUSTER = re.compile(r"^[0-9a-f]{16}\.json\Z")
# Merker der Praefixe, unter denen retained gesendet wurde (K22, E-E9), und
# seine Zweitschrift neben dem Konfigordner: die ueberlebt das Abraeumen des
# Datenordners bei jedem Update.
DATEI_PRAEFIXE = PDATA / "mqtt_praefixe.json"
DATEI_PRAEFIXE_ZWEIT = LBHOME / "config" / "plugins" / (PNAME + ".mqtt_praefixe.json")
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
    # Seit 0.9.32 ab Werk 1 (Entscheidung 2, E-E12): eine Neuinstallation sendet
    # ueber das Gateway. Eine eingerichtete Bestandsdatei ohne den Schluessel
    # liest ihn ueber uebergang() weiter als 0.
    "mqtt_ein": 1,
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
    # --- Strompreis (Anzeige, V2b, seit 0.9.32) ---
    # Abnehmer von haus/strompreis ueber eine Dauerverbindung des Dienstes;
    # ab Werk aus. Das Plugin steuert damit nichts (E-E10).
    "strompreis_ein": 0,
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
# Einzeilige Tabellen als dict(...): Werkzeuge/connack_klartext_pruefen.py schneidet
# jede Zeile "NAME = {" bis zur naechsten Zeile "}" heraus (seit 0.9.32).
MODI_NAME = dict((v, k) for k, v in MODI.items())

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
    # ok, ts und fehler an der Wurzel gehen seit 0.9.32 noch eine Fassung weiter
    # (fremde Abos <praefix>/# koennen daran haengen) und entfallen danach
    # (E-E6). Das Lebenszeichen ist status/* (lebenszeichen_senden()).
    "ok",
    "ts",
    "fehler",
    "status/zaehler",
    "status/ok",
    "status/alter",
    "status/fehler",
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
    # Summen des laufenden Monats und Jahres aus der eigenen Tagesaufzeichnung
    # (seit 0.9.32, N3) - bis dahin nur ueber HTTP (zeitraum=monat|jahr).
    "anlageN/monat/pv",
    "anlageN/monat/batterie_geladen",
    "anlageN/monat/batterie_abgegeben",
    "anlageN/monat/haus",
    "anlageN/monat/netzbezug",
    "anlageN/monat/netzeinspeisung",
    "anlageN/jahr/pv",
    "anlageN/jahr/batterie_geladen",
    "anlageN/jahr/batterie_abgegeben",
    "anlageN/jahr/haus",
    "anlageN/jahr/netzbezug",
    "anlageN/jahr/netzeinspeisung",
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

# ---------------------------------------------------------------------------
# Welche Themen gehen retained hinaus - DIE eine Stelle (Entscheidung des
# Hausherrn 29.09.2026, Regeln/07 "Hausstandard seit 03.09.2026").
#
# Retained sind Zustaende und Einstellungen: Anlagenzahl, Name, Betriebsart,
# Sollwerte, Reserve, Einspeiseschalter und -grenze, Firmware und ob die Cloud
# das Geraet erreicht. Nie retained sind Leistungen, Ladezustand, Tages- und
# Zaehlerwerte, Prognose und WLAN-Signal (Messwerte mit Zeitbezug) sowie
# ok, ts und fehler (Lebenszeichen bzw. Aussage des Dienstes ueber sich).
#
# Die Oberflaeche liest diesen Block fuer die Spalte "retained" im Reiter
# MQTT (ak_retain_themen in ak_lib.php); der Reiter Test haelt ihn gegen
# MQTT_THEMEN. Ein Thema, das hier fehlt, geht fluechtig hinaus. Eine leere
# Nutzlast geht nie hinaus (mqtt_senden schickt dann "-"), denn ein leeres
# retain loescht das Thema im Broker.
# ---------------------------------------------------------------------------
MQTT_RETAINED = (
    "anlagen",
    "anlageN/sollwert",
    "anlageN/modus",
    "anlageN/reserve",
    "anlageN/einspeisung",
    "anlageN/einspeisegrenze",
    "anlageN/name",
    "geraet/<SN>/sollwert",
    "geraet/<SN>/online",
    "geraet/<SN>/fw",
)

# Welche Themen Text sind - DIE eine Stelle (E-E4, seit 0.9.32). Alles andere
# ist Zahl. Ohne Aussage geht ein Text als "-", eine Zahl als -1 hinaus
# (fehlwert()); bis 0.9.31 ging ein fehlender Wert gar nicht hinaus, und ein
# retained Zustand behielt im Broker den alten Wert. Der Reiter MQTT liest
# diesen Block fuer die Spalte "ohne Aussage" (ak_text_themen()).
MQTT_TEXT = (
    "fehler",
    "status/fehler",
    "anlageN/name",
    "geraet/<SN>/fw",
)

# Nur noch der Rueckfall ohne Broker-Eintrag (seit 0.9.32, K22): so oft
# gehen die Abraeumzeilen an den UDP-Eingang des Gateways - der verwirft unter
# Last, und sendto() meldet auch dann Erfolg (Regeln/07, Nachtrag 19.09.2026).
# Der Regelweg raeumt ueber den Broker ab und liest zurueck.
ABRAEUMEN_RUNDEN = 3

# Lebenszeichen status/* fest alle 60 s (E-E5), unabhaengig vom Abruftakt.
LEBENSZEICHEN_TAKT = 60

# Abnehmer haus/strompreis (V2b): nur die sechs Themen der Nr. 15, Frist 120 s
# am live gesehenen Zaehler, Vorschau im Muster der Nr. 15.
PREIS_PRAEFIX = "haus/strompreis/"
PREIS_THEMEN = ("vorschau", "ab", "raster", "aktuell", "anbieter", "zaehler")
PREIS_FRIST_S = 120
PREIS_MUSTER = re.compile(r"^-?\d{1,4}(\.\d{1,3})?(;-?\d{1,4}(\.\d{1,3})?)*\Z")


def themenstamm(thema: str) -> str:
    """'anlage2/modus' -> 'anlageN/modus', 'geraet/<sn>/fw' -> 'geraet/<SN>/fw'."""
    teile = thema.split("/")
    if len(teile) >= 2 and teile[0].startswith("anlage") and teile[0][6:].isdigit():
        return "anlageN/" + "/".join(teile[1:])
    if len(teile) >= 3 and teile[0] == "geraet":
        return "geraet/<SN>/" + "/".join(teile[2:])
    return thema


def mqtt_befehl(thema: str) -> str:
    """Befehlswort fuer den UDP-Eingang des Gateways V1: 'retain' nur fuer
    Themen der Tabelle, sonst 'publish' - auch fuer jedes unbekannte."""
    return "retain" if themenstamm(thema) in MQTT_RETAINED else "publish"


_LAUF = True
_LOG = logging.getLogger("ankersolix")
_LETZTE_MELDUNG: dict[str, float] = {}
_LETZTE_MQTT: dict[str, str] = {}
# Zeitpunkt (monotone Uhr) des letzten Vollversands bei "nur Aenderungen".
_LETZTE_VOLL: float | None = None
VOLLSATZ_ABSTAND = 1800
_ZAEHLWERK = dict(anfragen=0, fehler=0, http429=0, anmeldungen=0)
# K24 (seit 0.9.32): der zuletzt ANGENOMMENE Modusbefehl je Anlage mit Zeit -
# das Abbild laeuft bis zum naechsten Abruf hinterher, und das Musterprojekt
# schickt "manuell" 15 s vor der Hauslast. _ABRUF_ZEIT: letzter erfolgreicher
# Abruf (update_sites).
_MODUS_GESETZT: dict = {}
_ABRUF_ZEIT = 0.0


class WachsameRotation(RotatingFileHandler):
    """Umlaufender Protokollhandler, der eine geloeschte Datei neu oeffnet.

    `log/plugins` liegt auf einer Ramdisk (zram). Wird sie geleert, raeumt
    LoxBerrys `log_maint` auf, oder loescht jemand die Datei von Hand, dann
    schreibt ein einmal geoeffneter Handler bis zum Prozessende in einen
    Inode, den es nicht mehr gibt - ohne Fehlermeldung, ohne Datei, ohne
    Hinweis. Am Geraet gemessen (06.09.2026, Python 3.13.5): FileHandler und
    RotatingFileHandler verlieren die Zeile, WatchedFileHandler nicht.

    Die Standardbibliothek hat den WatchedFileHandler, aber nicht zusammen
    mit dem Umlauf. Deshalb hier beides: vor jeder Zeile Geraetenummer und
    Inode vergleichen, bei Abweichung neu oeffnen, nach jedem Umlauf die
    Kennung nachfuehren.
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._kennung = self._kennung_lesen()

    def _kennung_lesen(self):
        """(Geraetenummer, Inode) der Datei - None, wenn es sie nicht gibt."""
        try:
            s = os.stat(self.baseFilename)
        except OSError:
            return None
        return (s.st_dev, s.st_ino)

    def _nachfassen(self):
        """Neu oeffnen, wenn unter dem offenen Deskriptor eine andere (oder
        gar keine) Datei mehr liegt."""
        if self._kennung_lesen() == self._kennung:
            return
        if self.stream is not None:
            try:
                self.stream.flush()
            finally:
                self.stream.close()
                self.stream = None
        self.stream = self._open()
        self._kennung = self._kennung_lesen()

    def emit(self, record):
        try:
            self._nachfassen()
        except Exception:
            # Ein Fehlschlag beim Nachfassen darf die Zeile nicht kosten:
            # lieber in den alten Deskriptor schreiben als gar nicht.
            pass
        super().emit(record)

    def doRollover(self):
        super().doRollover()
        self._kennung = self._kennung_lesen()


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet seine eigene Ausgabe in
# eine ANDERE Datei (ankersolix_start.log) - beide in dieselbe zu schreiben
# ginge schief, sobald der RotatingFileHandler umbenennt: der Anhaenge-
# Deskriptor der Shell zeigte danach auf die weggeschobene Datei.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    _LOG.setLevel(logging.INFO)
    try:
        # Ausserhalb der Installation (Pruefarchiv) wird nichts angelegt: der
        # Ordner laege in der laufenden Anlage, unter dem Namen "bin" (Y1).
        if not INSTALLIERT:
            raise OSError(f"{SELF} liegt nicht unter {LBHOME}/bin/plugins/{PNAME}")
        PLOG.mkdir(parents=True, exist_ok=True)
        h = WachsameRotation(DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8")
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
    # Die Sperre gilt erst nach einer GELUNGENEN Meldung (Befund Code 8): bis
    # 0.9.21 wurde sie vor dem Versuch gesetzt und der Rueckgabewert
    # verworfen - ein kaputter Meldeweg blieb stumm und unterdrueckte die
    # naechste Meldung sechs Stunden lang.
    if not NOTIFY.is_file():
        melde_gebremst("notify", f"Benachrichtigung nicht moeglich: {NOTIFY.name} fehlt.")
        return
    try:
        erg = subprocess.run(["php", str(NOTIFY), str(int(schwere)), text, PNAME],
                             stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
                             timeout=15, check=False)
    except (OSError, subprocess.SubprocessError) as err:
        melde_gebremst("notify", f"Benachrichtigung nicht moeglich: {err}")
        return
    if erg.returncode != 0:
        zeilen = (erg.stderr or b"").decode("utf-8", "replace").strip().splitlines()
        grund = zeilen[0].strip()[:200] if zeilen else "keine Ausgabe"
        melde_gebremst("notify", f"Benachrichtigung gescheitert (Rueckgabe {erg.returncode}): {grund}")
        return
    _LETZTE_MELDUNG["notify_" + schluessel] = jetzt


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


def uebergang(roh: dict) -> dict:
    """Lese-Regel fuer Schluessel, deren Vorgabe sich mit 0.9.32 geaendert hat
    (E-E12, N11) - dieselbe Regel wie ak_uebergang() der Oberflaeche, der
    Reiter Test haelt beide gegeneinander.

    Eine EINGERICHTETE Datei (mindestens ein Schluessel ausser aktionstoken)
    ohne mqtt_ein ist eine Datei von vor 0.9.32: dort galt 0. Eine leere Datei
    (postinstall.sh legt "{}" an) und eine fehlende sind neu - es gilt die
    Vorgabe 1. Das Kriterium ist NICHT "Token vorhanden": das erste Oeffnen
    der Oberflaeche speichert ueber ak_token() die vollstaendige
    Vorgabeliste samt Token - mit den neuen Schluesseln."""
    if isinstance(roh, dict) and any(k != "aktionstoken" for k in roh) and "mqtt_ein" not in roh:
        roh = dict(roh)
        roh["mqtt_ein"] = 0
    return roh


def config() -> dict:
    c = dict(VORGABEN)
    c.update(uebergang(json_lesen(DATEI_CONFIG)))
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
    c["strompreis_ein"] = 1 if str(c.get("strompreis_ein")) in ("1", "True", "true") else 0
    return c


def grenzen(cfg: dict, nummer) -> tuple[int, int]:
    """Grenzen der Hauslast: je Anlage, sonst die allgemeinen.

    Ein gemeinsames Maximum fuer eine Solarbank E1600 und eine Solarbank 3 ist
    entweder zu klein oder zu gross - beides ist falsch.
    """
    lo, hi = int(cfg["hauslast_min"]), int(cfg["hauslast_max"])
    g = (cfg.get("anlagen_grenzen") or {}).get(str(nummer))
    if not isinstance(g, dict):
        return lo, hi
    # K3/K14 (seit 0.9.32): je Anlage nur 0..5000 (wie ak_zahlgrenzen()), und
    # min nicht ueber max - gegen den GELTENDEN Wert (leeres Feld = allgemeiner
    # Wert). Sonst gelten die allgemeinen Grenzen, mit einer gebremsten
    # Protokollzeile - nie eine Sperre aller Sollwerte der Anlage.
    try:
        nlo = lo if str(g.get("min", "")) == "" else int(g["min"])
        nhi = hi if str(g.get("max", "")) == "" else int(g["max"])
    except (TypeError, ValueError):
        nlo, nhi = -1, -1
    if not (0 <= nlo <= 5000 and 0 <= nhi <= 5000) or nlo > nhi:
        melde_gebremst(f"grenzen_{nummer}", f"Grenzen der Hauslast fuer Anlage {nummer} unbrauchbar "
                                            f"(min {g.get('min')!r}, max {g.get('max')!r}) - es gelten die "
                                            f"allgemeinen ({lo} bis {hi} W). Reiter Einstellungen.", 3600)
        return lo, hi
    return nlo, nhi


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
                # E-E4 (seit 0.9.32): ohne Aussage -1 bzw. "-", nie nichts.
                v = fehlwert(k)
            # Zeilenumbrueche zerreissen die Syntax des UDP-Gateways: es
            # liest Zeile fuer Zeile, ein \n im Wert waere also der Anfang
            # eines neuen Befehls. Fehlertexte aus der Anker-Cloud koennen
            # mehrzeilig sein - genau die landen hier.
            sauber = str(v).replace("\r", " ").replace("\n", " ").strip()
            # Nie eine leere Nutzlast (Befund MQTT M5): das Gateway reicht sie
            # als Leerwert weiter, retained loeschte sie den Stand im Broker.
            wert = mqtt_wert_saeubern(sauber) or "-"
            # Befehlswort je Thema aus MQTT_RETAINED, nicht je Aufruf: derselbe
            # Aufruf traegt Zustaende und das Lebenszeichen (Regeln/07).
            nachricht = f"{mqtt_befehl(k)} {praefix}/{k} {wert}".encode("utf-8")
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
            # Das Abbild fuehrt die Prognose als prognose_rest; das Thema
            # heisst weiter prognose (Befund MQTT M1: bis 0.9.21 nie gesendet).
            quelle = "prognose_rest" if rest == "prognose" else rest
            for nummer, a in anlagen.items():
                paare[f"anlage{nummer}/{rest}"] = _tief(a, quelle)
        elif thema.startswith("geraet/<SN>/"):
            rest = thema[len("geraet/<SN>/"):]
            for sn, g in geraete.items():
                paare[f"geraet/{sn}/{rest}"] = _tief(g, rest)
    return paare


def themen_gebildet() -> list[str]:
    """--themen (Verbesserung b2, 30.09.2026): die Themenstaemme, die
    mqtt_paare() WIRKLICH bildet.

    Bis 0.9.23 hielt der Reiter Test die Liste der Oberflaeche nur gegen
    MQTT_THEMEN - Tabelle gegen Tabelle. 'anlageN/prognose' stand in beiden
    und wurde nie gesendet (Befund MQTT M1), weil mqtt_paare() einen
    Schluessel las, den das Abbild nicht fuehrt.

    Das Musterabbild entsteht aus denselben Funktionen wie im Betrieb
    (anlage_abbilden, geraet_abbilden) mit einer leeren Cloud-Antwort; jede
    Luecke (None) wird mit einem Platzhalter gefuellt. So fehlt nur ein
    Thema, dessen Quelle es im Abbild gar nicht gibt. Die Zaehler bildet
    zaehler_fortschreiben() aus FELDER_ENERGIE - hier ebenso. Schreibt nichts.
    """
    def fuellen(x):
        if isinstance(x, dict):
            return {k: fuellen(v) for k, v in x.items()}
        return "x" if x is None else x

    a = anlage_abbilden("S", {}, {"G": {"site_id": "S", "type": "solarbank"}}, {})
    a["zaehler"] = {f: 0 for f in FELDER_ENERGIE}
    g = geraet_abbilden("G", {"site_id": "S", "type": "solarbank"}, {})
    lox = fuellen({"ok": 1, "ts": 1, "fehler": "", "anzahl_anlagen": 1,
                   "anlagen": {"1": a}, "geraete": {"G": g}})
    # Seit 0.9.32 kommt das Lebenszeichen dazu (lebenszeichen_senden(), E5) -
    # es entsteht nicht aus dem Abbild, gehoert aber zu dem, was gesendet wird.
    return sorted({themenstamm(k) for k, v in mqtt_paare(lox).items() if v is not None}
                  | set(LEBENSZEICHEN_THEMEN))


def mqtt_abraeumen_themen(lox: dict) -> list[str]:
    """Die konkreten retained Themen zum letzten Abbild: Anlagennummern und
    Seriennummern aus loxone.json, Themenstaemme aus MQTT_RETAINED."""
    anlagen = lox.get("anlagen") if isinstance(lox.get("anlagen"), dict) else {}
    geraete = lox.get("geraete") if isinstance(lox.get("geraete"), dict) else {}
    out: list[str] = []
    for thema in MQTT_THEMEN:
        if thema not in MQTT_RETAINED:
            continue
        if thema.startswith("anlageN/"):
            out += [f"anlage{n}/{thema[len('anlageN/'):]}" for n in anlagen]
        elif thema.startswith("geraet/<SN>/"):
            out += [f"geraet/{sn}/{thema[len('geraet/<SN>/'):]}" for sn in geraete]
        else:
            out.append(thema)
    return out


# mqtt_abraeumen() (UDP, drei Runden, ohne Ruecklesen, nur bei mqtt_ein 1)
# ist seit 0.9.32 abraeumen_aufruf() -> abraeumen_mit_ruecklesen() (K22).


def praefix_gueltig(s) -> bool:
    """Themen-Praefix pruefen - dieselbe Regel wie ak_topic_gueltig() in
    webfrontend/html/ak_lib.php (Befund MQTT M6): nicht leer, nur A-Z a-z 0-9
    _ - /, kein Schraegstrich am Anfang oder Ende, keine zwei hintereinander,
    hoechstens 64 Zeichen."""
    if not isinstance(s, str) or not s.strip() or len(s) > 64:
        return False
    if not all(c.isascii() and (c.isalnum() or c in "_-/") for c in s):
        return False
    return not s.startswith("/") and not s.endswith("/") and "//" not in s


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
    global _LETZTE_VOLL
    if not cfg.get("mqtt_ein"):
        return
    # Ein ungueltiges Praefix faellt nicht still auf "ankersolix" zurueck
    # (Befund MQTT M6): gemeldet wird, gesendet nicht.
    praefix = cfg.get("mqtt_topic")
    if not praefix_gueltig(praefix):
        melde_gebremst("mqtt_praefix", f"MQTT: das Themen-Praefix {praefix!r} ist ungueltig - "
                                       "nichts gesendet. Bitte im Reiter MQTT berichtigen.")
        return
    if not ok:
        mqtt_senden({"ok": 0, "ts": lox.get("ts"), "fehler": lox.get("fehler") or "-"}, praefix)
        return

    paare = mqtt_paare(lox)
    if cfg.get("mqtt_nur_aenderung"):
        # Nur senden, was sich geaendert hat - aber ok/ts/fehler IMMER. Wer nur
        # bei Aenderungen sendet, hoert bei einer Stoerung einfach auf, und in
        # Loxone sieht ein toter Dienst dann aus wie ein ruhiges Haus.
        # Alle 30 Minuten der volle Satz (Regeln/07; Befund MQTT M3): ein
        # verlorenes Datagramm fehlt sonst bis zur naechsten Aenderung.
        jetzt = time.monotonic()
        if _LETZTE_VOLL is None or jetzt - _LETZTE_VOLL >= VOLLSATZ_ABSTAND:
            _LETZTE_MQTT.clear()
            _LETZTE_VOLL = jetzt
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
    # K22: unter diesem Praefix steht jetzt Retained - fuer das Abraeumen merken.
    if any(mqtt_befehl(k) == "retain" for k in paare):
        praefix_merken(praefix)


# ---------------------------------------------------------------------------
# Gruppe 26 (0.9.32): Lebenszeichen, -1/"-"-Regel, Abraeumen mit Ruecklesen
# und der Abnehmer haus/strompreis.
# ---------------------------------------------------------------------------

def ist_textthema(thema: str) -> bool:
    """Ist das Thema ein Text (Fehlwert "-") und keine Zahl (Fehlwert -1)?
    Die Tabelle MQTT_TEXT ist die eine Stelle (E-E4)."""
    return themenstamm(thema) in MQTT_TEXT


def fehlwert(thema: str):
    """Der Wert fuer "keine Aussage": -1 fuer Zahlen, "-" fuer Texte (E-E4,
    Hausform G16-G25). Bis 0.9.31 ging ein fehlender Wert gar nicht hinaus -
    ein retained Zustand behielt im Broker den alten Wert."""
    return "-" if ist_textthema(thema) else -1


def fluechtige_zahlenthemen(lox: dict) -> list[str]:
    """Die konkreten Messwert-Themen zum Abbild: nicht retained, keine Texte,
    nicht das Lebenszeichen und nicht ok/ts/fehler. Sie gehen einmal je Ausfall
    als -1 hinaus (E-E4); die retained Zustaende bleiben stehen - sie sind der
    zuletzt bekannte Stand einer Einstellung, und status/ok sagt, dass er alt ist."""
    aus = []
    for k in mqtt_paare(lox):
        stamm = themenstamm(k)
        if stamm in MQTT_RETAINED or stamm in MQTT_TEXT or stamm in ("ok", "ts", "fehler", "anlagen"):
            continue
        if stamm.startswith("status/"):
            continue
        aus.append(k)
    return aus


LEBENSZEICHEN_THEMEN = ("status/zaehler", "status/ok", "status/alter", "status/fehler")
_LEBEN: dict = {"zaehler": -1, "letzt": 0.0, "ausfall_gemeldet": False}


def lebenszeichen_werte(cfg: dict, jetzt: float | None = None) -> dict:
    """ok, alter und fehler des Lebenszeichens - dieselbe Regel wie der
    Endpunkt (ak_ok_grenze(): 3 x wirksamer Takt, N5). Liest nur das Abbild."""
    jetzt = time.time() if jetzt is None else jetzt
    lox = json_lesen(DATEI_LOXONE)
    try:
        ts = int(lox.get("ts") or 0)
    except (TypeError, ValueError):
        ts = 0
    alter = max(0, int(jetzt) - ts) if ts > 0 else -1
    grenze = 3 * int(cfg.get("intervall") or VORGABEN["intervall"])
    ok = 1 if (lox.get("ok") and 0 <= alter <= grenze) else 0
    fehler = str(lox.get("fehler") or "").strip() or "-"
    return {"ok": ok, "alter": alter, "fehler": fehler, "grenze": grenze, "lox": lox}


def lebenszeichen_senden(cfg: dict, erzwingen: bool = False, leser=None) -> bool:
    """Das Lebenszeichen status/zaehler, ok, alter, fehler - fest alle 60 s aus
    der Sekundenschleife, unabhaengig vom Abruftakt und von der Fehlerbremse
    (E5, N4, E-E5), und einmal direkt nach jedem Abbild. Nie retained.

    Faellt status/ok auf 0, gehen EINMAL je Ausfall alle fluechtigen
    Zahlenthemen als -1 hinaus (E-E4). Daneben wird die Lage der Preisquelle
    (Abnehmer haus/strompreis) fortgeschrieben - fuer Reiter Test und
    healthcheck, ohne eigene Themen (E-E10).
    Rueckgabe: True, wenn ein Lebenszeichen faellig war."""
    jetzt = time.time()
    if not erzwingen and jetzt - _LEBEN["letzt"] < LEBENSZEICHEN_TAKT:
        return False
    _LEBEN["letzt"] = jetzt
    _LEBEN["zaehler"] = (_LEBEN["zaehler"] + 1) % 1000
    w = lebenszeichen_werte(cfg, jetzt)
    preis = leser.lage(jetzt) if leser is not None else {"lage": "aus"}
    gesendet = 0
    if cfg.get("mqtt_ein") and praefix_gueltig(cfg.get("mqtt_topic")):
        praefix = cfg.get("mqtt_topic")
        paare = {"status/zaehler": _LEBEN["zaehler"], "status/ok": w["ok"],
                 "status/alter": w["alter"], "status/fehler": w["fehler"]}
        if not w["ok"] and not _LEBEN["ausfall_gemeldet"] and isinstance(w["lox"].get("anlagen"), dict):
            for k in fluechtige_zahlenthemen(w["lox"]):
                paare[k] = -1
            _LEBEN["ausfall_gemeldet"] = True
        elif w["ok"]:
            _LEBEN["ausfall_gemeldet"] = False
        mqtt_senden(paare, praefix)
        gesendet = 1
    zustand_schreiben(lebenszeichen={"zaehler": _LEBEN["zaehler"], "ok": w["ok"], "alter": w["alter"],
                                     "grenze": w["grenze"], "gesendet": gesendet, "ts": int(jetzt)},
                      preis=preis)
    return True


def energie_summen(nummer: int, stichtag: str | None = None) -> tuple[dict, dict]:
    """Summen des laufenden Monats und Jahres aus der eigenen Tagesaufzeichnung
    (anlageN.csv) - DIESELBE Regel wie ak_energie_summe() der Oberflaeche:
    nur Tage mit Wert, je Feld nur vorhandene Werte, round 2, keine Tage oder
    kein Wert -> None (geht als -1 hinaus). Der Reiter Test haelt beide
    Rechnungen gegeneinander (N3, E-E7)."""
    stichtag = stichtag or time.strftime("%Y-%m-%d")
    j, m = stichtag[:4], stichtag[:7]
    leer = lambda: {f: None for f in FELDER_ENERGIE}    # noqa: E731
    monat, jahr = leer(), leer()
    datei = ORDNER_ENERGIE / f"anlage{int(nummer)}.csv"
    try:
        with datei.open("r", encoding="utf-8") as f:
            zeilen = f.read().split("\n")
    except OSError:
        return monat, jahr
    for z in zeilen:
        t = z.split(";")
        if len(t) < 7 or len(t[0]) != 10 or t[0][4] != "-" or t[0][7] != "-" \
                or not (t[0][:4] + t[0][5:7] + t[0][8:]).isdigit():
            continue
        for summe, passt in ((monat, t[0][:7] == m), (jahr, t[0][:4] == j)):
            if not passt:
                continue
            for i, feld in enumerate(FELDER_ENERGIE, start=1):
                if t[i] == "":
                    continue
                try:
                    v = float(t[i])
                except ValueError:
                    continue
                summe[feld] = (summe[feld] or 0.0) + v
    for summe in (monat, jahr):
        for feld in FELDER_ENERGIE:
            if summe[feld] is not None:
                summe[feld] = round(summe[feld], 2)
    return monat, jahr


# ---------------------------------------------------------------------------
# Broker-Zugang und die Klartexte einer abgewiesenen Anmeldung.
#
# Der Zugang kommt aus general.json (Mqtt.Brokerhost/-port/-user/-pass, wie
# bb_broker() der Befehlsbruecke). Das Kennwort steht nur im Prozess
# (username_pw_set), nie in einer Befehlszeile und nie im Protokoll
# (Regeln/05). paho 1.x meldet eine Abweisung mit den CONNACK-Codes 1-5,
# paho 2.x mit den Ursachencodes 132-136 - beide Zaehlweisen stehen hier
# (Gedaechtnis paho-fassung-je-plugin; Werkzeuge/connack_klartext_pruefen.py).
# ---------------------------------------------------------------------------
CONNACK_TEXT = {
    1: "Der Broker lehnt die Protokollfassung ab.",
    2: "Der Broker lehnt die Kennung des Clients ab.",
    3: "Der Broker ist nicht verfuegbar.",
    4: "Benutzername oder Passwort des Brokers sind falsch. Sie stehen unter System -> MQTT Gateway "
       "(Brokeruser, Brokerpass).",
    5: "Der Broker hat die Anmeldung abgewiesen (nicht autorisiert). Traegt System -> MQTT Gateway einen "
       "Benutzer und ein Passwort?",
    132: "Der Broker lehnt die Protokollfassung ab.",
    133: "Der Broker lehnt die Kennung des Clients ab.",
    136: "Der Broker ist nicht verfuegbar.",
    134: "Benutzername oder Passwort des Brokers sind falsch. Sie stehen unter System -> MQTT Gateway "
         "(Brokeruser, Brokerpass).",
    135: "Der Broker hat die Anmeldung abgewiesen (nicht autorisiert). Traegt System -> MQTT Gateway einen "
         "Benutzer und ein Passwort?",
}

_LOGGER = _LOG


def broker_zugang() -> dict:
    """Host, Port, Benutzer und Kennwort aus general.json - oder {} ohne
    Broker-Eintrag. Das Kennwort verlaesst dieses Woerterbuch nur in
    username_pw_set()."""
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    if not isinstance(m, dict):
        return {}
    host = str(m.get("Brokerhost", m.get("brokerhost", "")) or "").strip()
    if not host:
        return {}
    if host == "localhost":
        host = "127.0.0.1"
    try:
        port = int(m.get("Brokerport", m.get("brokerport", 1883)) or 1883)
    except (TypeError, ValueError):
        port = 1883
    if not 0 < port < 65536:
        port = 1883
    return {"host": host, "port": port,
            "user": str(m.get("Brokeruser", m.get("brokeruser", "")) or ""),
            "pass": str(m.get("Brokerpass", m.get("brokerpass", "")) or "")}


def paho_klient(kennung: str):
    """Einen paho-Client bauen - die Fassung wird abgetastet, nicht angenommen:
    paho 2.x mit CallbackAPIVersion.VERSION2, paho 1.x ohne (Hausform
    VolkswagenID). Rueckgabe (modul, client) oder (None, Grund)."""
    try:
        import paho.mqtt.client as mqtt
    except Exception as err:  # noqa: BLE001 - fehlt paho, laeuft der Rest weiter
        return None, f"paho-mqtt fehlt in der virtuellen Umgebung ({type(err).__name__})."
    try:
        k = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=kennung, clean_session=True)
    except (AttributeError, TypeError):
        k = mqtt.Client(client_id=kennung, clean_session=True)
    return mqtt, k


def paho_fassung() -> str:
    try:
        import importlib.metadata as md
        return md.version("paho-mqtt")
    except Exception:  # noqa: BLE001
        return ""


def preis_angemeldet(client, userdata, flags, rc, properties=None):
    """on_connect des Abnehmers haus/strompreis (beide Zaehlweisen, siehe
    CONNACK_TEXT). Bei jedem Wiederverbinden neu abonnieren; eine Abweisung
    steht im Klartext im Protokoll - gebremst ueber den Leser, ohne ihn
    (Probe) jedes Mal."""
    try:
        code = int(getattr(rc, "value", rc) or 0)
    except (TypeError, ValueError):
        code = -1
    leser = userdata
    if code != 0:
        text = CONNACK_TEXT.get(code, f"Der Broker hat die Anmeldung mit Code {code} abgewiesen.")
        if leser is not None:
            leser.angemeldet = False
            leser.grund = text
            if not leser.melden("connack", 3600):
                return
        _LOGGER.warning("Strompreis: Anmeldung am Broker abgewiesen (Code %s): %s", code, text)
        return
    if leser is not None:
        leser.angemeldet = True
        leser.grund = ""
    client.subscribe(PREIS_PRAEFIX + "#", 0)


class StrompreisLeser:
    """Abnehmer von haus/strompreis (V2b, E-E11): eine paho-DAUERVERBINDUNG im
    Dienst - keine kurze Minutensitzung, die den Zaehler des Anbieters je nach
    Sendesekunde verpasst (Phasenfalle). Eigener Thread (loop_start), nur
    Lesen; die Rueckrufe legen unter einer Sperre in ein Woerterbuch ab, die
    asyncio-Schleife liest nur unter derselben Sperre - kein await im Thread,
    keine Cloud-Anfrage im Thread, keine Ein-/Ausgabe im Rueckruf.

    Gestartet und gestoppt wird nach strompreis_ein (ab Werk aus). Nichts
    wird unter haus/ geschrieben; kein Letzter Wille."""

    def __init__(self):
        import threading
        self.sperre = threading.Lock()
        self.klient = None
        self.client = None
        self.werte: dict[str, str] = {}
        self.wechsel: list[float] = []
        self.angemeldet = False
        self.grund = ""
        self._gemeldet: dict[str, float] = {}

    def melden(self, schluessel: str, sekunden: int) -> bool:
        jetzt = time.time()
        if jetzt - self._gemeldet.get(schluessel, 0) < sekunden:
            return False
        self._gemeldet[schluessel] = jetzt
        return True

    def bei_nachricht(self, _klient, _daten, nachricht) -> None:
        thema = str(getattr(nachricht, "topic", "") or "")
        if not thema.startswith(PREIS_PRAEFIX):
            return
        name = thema[len(PREIS_PRAEFIX):]
        if name not in PREIS_THEMEN:
            return
        roh = bytes(getattr(nachricht, "payload", b"") or b"")
        if len(roh) > 4096:
            return
        text = roh.decode("utf-8", "replace")
        with self.sperre:
            if name == "zaehler" and not getattr(nachricht, "retain", False) \
                    and self.werte.get("zaehler") != text:
                self.wechsel.append(time.time())
                self.wechsel = self.wechsel[-5:]
            self.werte[name] = text

    def laeuft(self) -> bool:
        return self.klient is not None

    def starten(self) -> None:
        z = broker_zugang()
        if not z:
            if self.melden("kein_broker", 3600):
                _LOG.warning("Strompreis: kein Broker in general.json - die Preisanzeige bleibt leer.")
            self.grund = "kein Broker in general.json"
            return
        mqtt, k = paho_klient(f"ankersolix-{PNAME}-preis-{os.getpid()}")
        if mqtt is None:
            self.grund = k
            if self.melden("paho", 86400):
                _LOG.warning("Strompreis: %s", k)
            return
        k.user_data_set(self)
        self.client = k
        self.client.on_connect = preis_angemeldet
        self.client.on_message = self.bei_nachricht
        if z.get("user"):
            k.username_pw_set(z["user"], z.get("pass") or None)
        try:
            k.reconnect_delay_set(1, 60)
            k.connect_async(z["host"], z["port"], 60)
            k.loop_start()
        except Exception as err:  # noqa: BLE001
            self.grund = fehlertext(err)
            if self.melden("start", 3600):
                _LOG.warning("Strompreis: Verbindung nicht moeglich: %s", self.grund)
            return
        self.klient = k
        _LOG.info("Strompreis: Abnehmer gestartet (%s:%s, %s#).", z["host"], z["port"], PREIS_PRAEFIX)

    def stoppen(self) -> None:
        k = self.klient
        self.klient = None
        self.client = None
        self.angemeldet = False
        if k is None:
            return
        try:
            k.disconnect()
            k.loop_stop()
        except Exception:  # noqa: BLE001
            pass
        with self.sperre:
            self.werte = {}
            self.wechsel = []
        _LOG.info("Strompreis: Abnehmer angehalten.")

    def angleichen(self, cfg: dict) -> None:
        if cfg.get("strompreis_ein") and not self.laeuft():
            self.starten()
        elif not cfg.get("strompreis_ein") and self.laeuft():
            self.stoppen()

    def gesehen(self) -> dict:
        with self.sperre:
            return {"werte": dict(self.werte), "zaehler_wechsel": list(self.wechsel)}

    def lage(self, jetzt: float) -> dict:
        if not self.laeuft():
            return {"lage": "aus", "grund": self.grund}
        erg = strompreis_lage(self.gesehen(), jetzt)
        erg["angemeldet"] = 1 if self.angemeldet else 0
        if self.grund:
            erg["grund_broker"] = self.grund
        return erg


def strompreis_lage(gesehen: dict, jetzt: float) -> dict:
    """Aus dem, was die Dauerverbindung gesehen hat (retained Werte und die
    Zeitpunkte der LIVE gesehenen Zaehlerwechsel), die Lage "haus" - oder den
    Grund fuer "keine Preise" (Entwurf 2.8, Probe S1-S9).

    haus nur, wenn der Zaehler binnen PREIS_FRIST_S live gewechselt hat,
    raster 900|3600, ab im Raster, vorschau im Muster (<= 4096 Byte) und die
    laufende Stunde gedeckt ist (Raster 900 -> Stundenmittel; angebrochene
    Randstunden fallen weg, Form Marstek N1). Ergebnis: Preis jetzt, Rang der
    laufenden Stunde in den naechsten 24 h, die drei guenstigsten Stunden -
    keine Preisliste."""
    w = gesehen.get("werte") or {}
    alle = gesehen.get("zaehler_wechsel") or []
    if not [t for t in alle if jetzt - t <= PREIS_FRIST_S]:
        letzte = max(alle) if alle else 0
        return {"lage": "schweigt", "seit_s": int(jetzt - letzte) if letzte else -1}
    try:
        raster = int(str(w.get("raster", "-1")))
        ab = int(str(w.get("ab", "-1")))
    except ValueError:
        return {"lage": "unbrauchbar", "grund": "raster/ab keine Zahl"}
    vorschau = str(w.get("vorschau", "-"))
    if raster not in (900, 3600) or ab <= 0 or ab % raster:
        return {"lage": "unbrauchbar", "grund": f"raster {raster}, ab {ab}"}
    if vorschau == "-" or len(vorschau) > 4096 or not PREIS_MUSTER.match(vorschau):
        return {"lage": "unbrauchbar", "grund": "vorschau"}
    preise = [float(x) for x in vorschau.split(";")]
    stunden: dict[int, list] = {}
    for i, p in enumerate(preise):
        t = ab + i * raster
        stunden.setdefault(t - t % 3600, []).append(p)
    je = 3600 // raster
    mittel = {h: round(sum(v) / len(v), 3) for h, v in stunden.items() if len(v) == je}
    h_jetzt = int(jetzt) - int(jetzt) % 3600
    if h_jetzt not in mittel:
        return {"lage": "deckt_stunde_nicht", "stunden": len(mittel)}
    kuenftig = {h: p for h, p in mittel.items() if h_jetzt <= h < h_jetzt + 24 * 3600}
    rang = 1 + sum(1 for p in kuenftig.values() if p < mittel[h_jetzt])
    return {"lage": "haus", "anbieter": str(w.get("anbieter", "-"))[:16], "jetzt_ct": mittel[h_jetzt],
            "stunden": len(kuenftig), "rang": rang,
            "guenstigste": sorted(kuenftig, key=lambda h: (kuenftig[h], h))[:3]}


# ---------------------------------------------------------------------------
# Abraeumen mit Ruecklesen (K22, E-E9; Bauart Docker D18 / Ultraschall).
#
# Ausloeser: Speichern im Reiter MQTT mit Praefixwechsel oder "MQTT aus",
# Zurueckspielen einer Sicherung mit derselben Wirkung, Deinstallation - auch
# bei mqtt_ein = 0, wenn je gesendet wurde. Jedes Praefix, unter dem der
# Dienst retained gesendet hat, steht im Merker data/<o>/mqtt_praefixe.json
# und in der Zweitschrift config/plugins/<o>.mqtt_praefixe.json (die ueberlebt
# das Abraeumen des Datenordners beim Update).
#
# Weg: kurze paho-Sitzung (retained ist zeitunabhaengig - keine Phasenfalle):
# Abo <praefix>/# 3 s -> die retained Themen (nie unter <praefix>/befehl/) ->
# je Thema leere Nutzlast retained -> zweites Abo 3 s -> "n abgeraeumt, m
# stehen noch". Nur das eigene Praefix: nie haus/*, nie ein Praefix, das ein
# anderes Plugin als Thema eingetragen hat. Ohne Broker-Eintrag bleibt der
# alte UDP-Weg als Rueckfall (ohne Ruecklesen, und das wird gesagt).
# ---------------------------------------------------------------------------
_PRAEFIX_GEMERKT: set = set()


def praefixe_lesen() -> dict:
    """Merker und Zweitschrift zusammen: {praefix: zeit}."""
    aus: dict = {}
    for datei in (DATEI_PRAEFIXE_ZWEIT, DATEI_PRAEFIXE):
        d = json_lesen(datei)
        for p, t in (d.get("praefixe") or {}).items() if isinstance(d.get("praefixe"), dict) else []:
            if praefix_gueltig(p):
                try:
                    aus[p] = max(int(t), int(aus.get(p, 0)))
                except (TypeError, ValueError):
                    aus[p] = int(aus.get(p, 0))
    return aus


def praefixe_schreiben(praefixe: dict) -> None:
    """Beide Merker schreiben - nie als root (die Datei gehoerte sonst root)."""
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        return
    for datei in (DATEI_PRAEFIXE, DATEI_PRAEFIXE_ZWEIT):
        if not datei.parent.is_dir():
            continue
        if not praefixe and not datei.exists():
            continue
        json_schreiben(datei, {"praefixe": praefixe}, 0o600)


def praefix_merken(praefix: str) -> None:
    """Nach retained Senden: das Praefix merken (nur einmal je Prozess schreiben)."""
    if praefix in _PRAEFIX_GEMERKT:
        return
    p = praefixe_lesen()
    if praefix not in p:
        p[praefix] = int(time.time())
        praefixe_schreiben(p)
    _PRAEFIX_GEMERKT.add(praefix)


def praefix_vergessen(praefix: str) -> None:
    p = praefixe_lesen()
    if praefix in p:
        del p[praefix]
        praefixe_schreiben(p)
    _PRAEFIX_GEMERKT.discard(praefix)


def praefix_fremd(praefix: str) -> str:
    """Steht das Praefix in einem ANDEREN Plugin als Thema? Rueckgabe: dessen
    Ordner, sonst ''. Gelesen werden nur die JSON-Dateien direkt in
    config/plugins/<andere>/ (Bauart Abfuhr: fremde Themen nie anfassen)."""
    wurzel = LBHOME / "config" / "plugins"
    try:
        ordner = sorted(d for d in wurzel.iterdir() if d.is_dir() and d.name != PNAME)
    except OSError:
        return ""
    for d in ordner:
        try:
            dateien = sorted(d.glob("*.json"))
        except OSError:
            continue
        for f in dateien:
            daten = json_lesen(f)
            for k, v in daten.items():
                if isinstance(v, str) and v.strip().strip("/") == praefix \
                        and ("topic" in k.lower() or "praefix" in k.lower() or "prefix" in k.lower()):
                    return d.name
    return ""


def abraeumen_erlaubt(praefix) -> str:
    """'' = erlaubt, sonst der Grund (fuer die Ausgabe)."""
    if not praefix_gueltig(praefix):
        return f"Das Praefix {praefix!r} ist ungueltig."
    if praefix == "haus" or praefix.startswith("haus/"):
        return "Themen unter haus/ gehoeren nie diesem Plugin."
    fremd = praefix_fremd(praefix)
    if fremd:
        return f"Das Praefix {praefix} traegt das Plugin {fremd} als Thema - nichts angefasst."
    return ""


def _sn_maskieren(thema: str) -> str:
    teile = thema.split("/")
    if len(teile) >= 3 and teile[-3] == "geraet":
        teile[-2] = "<SN>"
    return "/".join(teile)


def retained_lesen(z: dict, praefix: str, sekunden: float = 3.0):
    """Eine kurze Sitzung: die retained Themen unter <praefix>/#.
    Rueckgabe (liste, grund) - grund '' bei Erfolg."""
    import threading
    mqtt, sitzung = paho_klient(f"ankersolix-{PNAME}-abraeumen-{os.getpid()}")
    if mqtt is None:
        return None, sitzung
    gefunden: dict = {}
    zustand = {"code": None}
    fertig = threading.Event()

    def angemeldet(_k, _d, _f, rc, *_rest):
        try:
            zustand["code"] = int(getattr(rc, "value", rc) or 0)
        except (TypeError, ValueError):
            zustand["code"] = -1
        if zustand["code"] == 0:
            _k.subscribe(praefix + "/#", 0)
        fertig.set()

    def nachricht(_k, _d, m):
        if getattr(m, "retain", False) and getattr(m, "payload", b""):
            gefunden[str(m.topic)] = True

    sitzung.on_connect = angemeldet
    sitzung.on_message = nachricht
    if z.get("user"):
        sitzung.username_pw_set(z["user"], z.get("pass") or None)
    try:
        sitzung.connect(z["host"], z["port"], 30)
    except Exception as err:  # noqa: BLE001
        return None, f"Broker {z['host']}:{z['port']} nicht erreichbar ({type(err).__name__})."
    sitzung.loop_start()
    try:
        fertig.wait(5)
        if zustand["code"] is None:
            return None, "keine Antwort des Brokers auf die Anmeldung"
        if zustand["code"] != 0:
            return None, CONNACK_TEXT.get(zustand["code"], f"Anmeldung mit Code {zustand['code']} abgewiesen.")
        time.sleep(sekunden)
        return sorted(t for t in gefunden if not t.startswith(praefix + "/befehl/")), ""
    finally:
        try:
            sitzung.disconnect()
        except Exception:  # noqa: BLE001
            pass
        sitzung.loop_stop()


def retained_leeren(z: dict, themen: list) -> str:
    """Je Thema eine leere Nutzlast retained direkt an den Broker. '' = gesendet."""
    mqtt, sitzung = paho_klient(f"ankersolix-{PNAME}-leeren-{os.getpid()}")
    if mqtt is None:
        return sitzung
    if z.get("user"):
        sitzung.username_pw_set(z["user"], z.get("pass") or None)
    try:
        sitzung.connect(z["host"], z["port"], 30)
    except Exception as err:  # noqa: BLE001
        return f"Broker nicht erreichbar ({type(err).__name__})."
    sitzung.loop_start()
    try:
        for t in themen:
            info = sitzung.publish(t, b"", qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:           # paho 1.x vor 1.6 kennt kein timeout
                info.wait_for_publish()
            except Exception:  # noqa: BLE001
                pass
        return ""
    finally:
        try:
            sitzung.disconnect()
        except Exception:  # noqa: BLE001
            pass
        sitzung.loop_stop()


def abraeumen_udp(praefix: str) -> tuple[int, str]:
    """Rueckfall ohne Broker-Eintrag: die bekannten retained Themen ueber den
    UDP-Eingang des Gateways leeren - OHNE Ruecklesen, und das wird gesagt."""
    themen = mqtt_abraeumen_themen(json_lesen(DATEI_LOXONE))
    z = mqtt_zustand()
    if not z["udpport"]:
        return 1, (f"<WARNING> Weder Broker noch UDP-Eingang des MQTT-Gateways in general.json - die "
                   f"zurueckbehaltenen Themen unter {praefix}/ wurden nicht abgeraeumt.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        return 1, f"<WARNING> MQTT: Socket nicht moeglich ({err}) - nichts abgeraeumt."
    try:
        for runde in range(ABRAEUMEN_RUNDEN):
            if runde:
                time.sleep(1)
            for t in themen:
                s.sendto(f"retain {praefix}/{t} ".encode("utf-8"), ("127.0.0.1", z["udpport"]))
                time.sleep(0.02)
    except OSError as err:
        return 1, f"<WARNING> MQTT: Abraeumen ueber UDP fehlgeschlagen ({err})."
    finally:
        s.close()
    return 1, (f"<WARNING> Kein Broker in general.json: {len(themen)} zurueckbehaltene Themen unter {praefix}/ "
               f"nur ueber den UDP-Eingang zum Loeschen geschickt ({ABRAEUMEN_RUNDEN} Durchgaenge) - OHNE "
               "Ruecklesen; ob sie weg sind, sagt erst mosquitto_sub --retained-only.")


def abraeumen_mit_ruecklesen(praefix: str) -> tuple[int, str]:
    """Ein Praefix abraeumen. Rueckgabe (rc, Zeile mit <INFO>/<WARNING>).
    rc 0 = nichts steht mehr; das Praefix faellt dann aus dem Merker."""
    grund = abraeumen_erlaubt(praefix)
    if grund:
        return 1, f"<WARNING> {grund}"
    z = broker_zugang()
    if not z:
        return abraeumen_udp(praefix)
    themen, fehler = retained_lesen(z, praefix)
    if themen is None:
        return 1, f"<WARNING> Abraeumen unter {praefix}/ nicht moeglich: {fehler}"
    if themen:
        fehler = retained_leeren(z, themen)
        if fehler:
            return 1, f"<WARNING> Abraeumen unter {praefix}/ nicht moeglich: {fehler}"
    rest, fehler = retained_lesen(z, praefix)
    if rest is None:
        return 1, (f"<WARNING> {len(themen)} Themen unter {praefix}/ geleert, Ruecklesen nicht moeglich: "
                   f"{fehler}")
    if rest:
        namen = ", ".join(_sn_maskieren(t) for t in rest[:10])
        return 1, (f"<WARNING> {len(themen)} Themen unter {praefix}/ abgeraeumt, {len(rest)} stehen noch "
                   f"({namen}) - naechster Versuch beim naechsten Speichern oder Start.")
    praefix_vergessen(praefix)
    return 0, f"<INFO> {len(themen)} zurueckbehaltene Themen unter {praefix}/ abgeraeumt, 0 stehen noch (rueckgelesen)."


def abraeumen_aufruf(argumente: list) -> int:
    """--abraeumen [<praefix>]: ohne Praefix alle aus dem Merker und das
    eingestellte, wenn MQTT an ist. Schreibt nichts ausser dem Merker und kein
    Protokoll - die Deinstallation ruft das als loxberry auf (su)."""
    if argumente:
        liste = [argumente[0]]
    else:
        liste = sorted(praefixe_lesen())
        cfg = config()
        if cfg.get("mqtt_ein") and praefix_gueltig(cfg.get("mqtt_topic")) and cfg["mqtt_topic"] not in liste:
            liste.append(cfg["mqtt_topic"])
    if not liste:
        print("<INFO> Es wurde nie retained ueber MQTT gesendet - nichts abzuraeumen.")
        return 0
    rc = 0
    for p in liste:
        r, zeile = abraeumen_mit_ruecklesen(p)
        print(zeile)
        rc = rc or r
    return rc


def praefixe_nachfassen(cfg: dict) -> None:
    """Beim Start des Dienstes: was im Merker steht und nicht (mehr) das
    eigene Sendepraefix ist, noch einmal abraeumen (Ultraschall-Form)."""
    aktiv = cfg.get("mqtt_topic") if cfg.get("mqtt_ein") else None
    for p in sorted(praefixe_lesen()):
        if p == aktiv:
            continue
        rc, zeile = abraeumen_mit_ruecklesen(p)
        (_LOG.info if rc == 0 else _LOG.warning)("Abraeumen beim Start: %s", zeile.split("> ", 1)[-1])


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
        # Seit 0.9.32 (N3): Summen des laufenden Monats und Jahres, gesetzt in
        # abbild_schreiben() aus energie_summen().
        "monat": {f: None for f in FELDER_ENERGIE},
        "jahr": {f: None for f in FELDER_ENERGIE},
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
        # Fehlt wifi_online in der Antwort, ist die Erreichbarkeit nicht gemessen:
        # None (wird nicht gesendet) statt einer erfundenen 0, die retained bliebe.
        "online": schalter(erstes(d, "wifi_online")),
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
    # Die Zaehler aus den Zeilen VOR dem Kappen (Befund Code 2).
    zaehler = zaehler_fortschreiben(datei, zeilen, grenze)
    zeilen = {k: v for k, v in zeilen.items() if k >= grenze}

    try:
        tmp = datei.with_suffix(".csv.tmp")
        with tmp.open("w", encoding="utf-8") as f:
            for k in sorted(zeilen):
                f.write(";".join(zeilen[k]) + "\n")
        os.replace(tmp, datei)
    except OSError as err:
        melde_gebremst("energie_csv", f"Tagesenergien nicht schreibbar: {err}")
    return zaehler


def zaehler_fortschreiben(datei: Path, zeilen: dict, grenze: str) -> dict:
    """Zaehlerstaende, die nur steigen (Befund Code 2, 29.09.2026).

    Bis 0.9.21 war der Zaehler die Summe ueber die aufbewahrten Tage. Er fiel,
    sobald ein Tag aus dem Fenster fiel, die Aufbewahrung verkleinert wurde
    oder die Cloud einen Tageswert nach unten berichtigte - gegen die Zusage
    oben und in der Hilfe.

    Jetzt fuehrt ein Merker neben der CSV (<csv>.zaehler.json) je Feld die
    Summe und den hoechsten schon gezaehlten Wert je Tag. Hinzu kommt nur das
    Plus, ein Rueckgang wird nie abgezogen. Tage ausserhalb des Fensters
    fallen aus dem Merker, die Summe bleibt. Ohne Merker (erster Lauf) oder
    ohne Eintrag fuer ein Feld beginnt die Summe bei der Fenstersumme der
    vorliegenden Zeilen - so springt kein bestehender Zaehler.
    Rueckgabe nur fuer Felder, die je einen Wert hatten.
    """
    merkdatei = datei.with_name(datei.name + ".zaehler.json")
    merker = json_lesen(merkdatei)
    vorher = json.dumps(merker, sort_keys=True)
    zaehler = {}
    for i, feld in enumerate(FELDER_ENERGIE, start=1):
        m = merker.get(feld)
        gefunden = isinstance(m, dict)
        if not gefunden:
            m = {}
        try:
            summe = float(m.get("summe") or 0)
        except (TypeError, ValueError):
            summe = 0.0
        tage_m = m.get("tage") if isinstance(m.get("tage"), dict) else {}
        for k in sorted(zeilen):
            try:
                roh = zeilen[k][i]
                if roh == "":
                    continue
                wert = float(roh)
            except (IndexError, ValueError):
                continue
            gefunden = True
            try:
                bisher = float(tage_m.get(k, 0))
            except (TypeError, ValueError):
                bisher = 0.0
            summe += max(0.0, wert - bisher)
            tage_m[k] = max(wert, bisher)
        if gefunden:
            tage_m = {k: v for k, v in tage_m.items() if k >= grenze}
            merker[feld] = {"summe": round(summe, 6), "tage": tage_m}
            zaehler[feld] = round(summe, 2)
    if json.dumps(merker, sort_keys=True) != vorher:
        json_schreiben(merkdatei, merker)
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


def sollwert_empfangen_lesen() -> int:
    """Wann kam zuletzt ein gueltiger Sollwert von Loxone (Entscheidung Nr. 22)?

    0, wenn der Merker fehlt, unlesbar oder unplausibel ist - dann misst der
    Rueckfall wie bis 0.9.26 nur am letzten gesendeten Befehl. Ein Zeitpunkt
    mehr als SOLLWERT_ZUKUNFT_S in der Zukunft (Uhr zurueckgestellt, Merker
    von Hand beschrieben) zaehlt nicht: er hielte den Rueckfall sonst still
    bis dahin auf.
    """
    try:
        roh = DATEI_SOLLWERT_EMPFANGEN.read_text(encoding="ascii").strip()
    except (OSError, ValueError):
        return 0
    if not roh.isdigit() or len(roh) > 12:
        return 0
    zeit = int(roh)
    if zeit <= 0 or zeit > time.time() + SOLLWERT_ZUKUNFT_S:
        return 0
    return zeit


def sollwert_empfangen_vermerken() -> bool:
    """Den Merker "Sollwert empfangen" auf jetzt setzen (Entscheidung Nr. 22).

    Dieselbe Sperrdatei wie der Endpunkt, unter flock; geschrieben wird eine
    Nebendatei, die dann umbenannt wird - rueckfall_pruefen() liest ohne
    Sperre und sieht so nie eine halbe Zahl. Die Sperrdatei ist
    close-on-exec (O_CLOEXEC, im Endpunkt Modus "c+e") und vererbt sich an
    keinen Kindprozess. Scheitert es, bleibt eine gebremste Protokollzeile:
    der Rueckfall misst dann wie bisher am letzten gesendeten Befehl und
    greift eher zu frueh als nie - kein stiller Dauerschutz.
    """
    tmp = DATEI_SOLLWERT_EMPFANGEN.with_name(DATEI_SOLLWERT_EMPFANGEN.name + ".tmp")
    fd = -1
    gesperrt = False
    try:
        import fcntl
        fd = os.open(str(DATEI_SOLLWERT_SPERRE), os.O_RDWR | os.O_CREAT | getattr(os, "O_CLOEXEC", 0), 0o664)
        fcntl.flock(fd, fcntl.LOCK_EX)
        gesperrt = True
        jetzt = int(time.time())
        if sollwert_empfangen_lesen() >= jetzt:
            return True
        roh = str(jetzt).encode("ascii")
        with open(tmp, "wb") as f:
            if f.write(roh) != len(roh):
                raise OSError("unvollstaendig geschrieben")
        os.replace(tmp, DATEI_SOLLWERT_EMPFANGEN)
        return True
    except (OSError, ImportError) as err:
        if gesperrt:
            try:
                tmp.unlink()
            except OSError:
                pass
        melde_gebremst("sollwert_empfangen",
                       f"Der Merker {DATEI_SOLLWERT_EMPFANGEN} (Sollwert empfangen) laesst sich nicht "
                       f"schreiben ({err}). Der Rueckfall misst bis dahin nur am letzten gesendeten Befehl "
                       f"und kann greifen, obwohl Loxone Sollwerte schickt.", 3600)
        return False
    finally:
        if fd >= 0:
            os.close(fd)


async def befehl_ausfuehren(api, cfg: dict, b: dict) -> tuple[int, str, dict]:
    """Rueckgabe: (ok, Meldung, Zusatzfelder)."""
    aktion = str(b.get("aktion") or "")

    if aktion == "abruf":
        return (1, "Sofortabruf eingeplant.", {})

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.", {})

    # Die Hauslast prueft die Schreibbremse erst NACH der Schrittweite
    # (Entscheidung Nr. 21): ein Sollwert, der ohnehin nicht gesendet wird,
    # kostet keine Cloud-Anfrage und ist kein Fehler.
    frei, rest = schreibbremse_frei(cfg)
    bremse_text = (f"Schreibbremse: der letzte Befehl liegt weniger als {cfg['schreibbremse']} s zurueck, "
                   f"noch {rest} s. Die Anker-Cloud sperrt bei zu vielen Anfragen (429), "
                   f"und das traefe dann auch die Messwerte.")
    if not frei and aktion != "hauslast":
        return (0, bremse_text, {})

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
        # und bewirkt nichts. Gesendet wird nichts, gemeldet wird es trotzdem -
        # als "unveraendert", nicht als Fehler (Entscheidung Nr. 21, wie
        # Marstek): bis 0.9.24 war das ok=0, der Endpunkt gab HTTP 500.
        schritt = int(cfg.get("schrittweite") or 0)
        alt = zahl(erstes(api.sites.get(site_id) or {}, "retain_load", "set_load_power"))
        if schritt > 0 and alt is not None and abs(watt - alt) < schritt:
            return (1, f"Der Sollwert {watt} W weicht nur um {abs(watt - alt)} W vom gesetzten ({alt} W) ab "
                       f"(Schrittweite {schritt} W). Nichts gesendet.",
                    {"watt": watt, "sn": sn, "unveraendert": 1, "gesetzt": alt})
        # K24 (seit 0.9.32, E-E19): bei Solarbank 2/3 aendert set_sb2_home_load()
        # die Hauslast nur in den Plaenen manual und smartplugs (anker-solix-api
        # v3.6.3, schedule.py :1565/:1634). In jeder anderen Betriebsart ginge
        # der Befehl ohne Wirkung durch - abweisen und sagen warum, NICHT still
        # usage_mode=3 mitsenden (das stellte ungefragt die Betriebsart um und
        # machte den Rueckfall wirkungslos).
        modus_hinweis = ""
        if generation >= 2:
            senden, modus_hinweis = hauslast_modus(site_id, api.sites.get(site_id) or {})
            if not senden:
                return (0, modus_hinweis, {"watt": watt, "sn": sn})
        if not frei:
            return (0, bremse_text, {})
        if generation >= 2:
            erg = await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, preset=float(watt))
        else:
            erg = await api.set_home_load(siteId=site_id, deviceSn=sn, preset=int(watt))
        schreibbefehl_vermerken()
        ok = 1 if erg else 0
        # N9: die Bibliothek klemmt preset still auf min_load/max_load des Plans
        # (schedule.py :1530-1552) - "0 W gesendet" kann am Geraet eine
        # Mindestlast sein. Gesagt, nicht behauptet; der zurueckgelesene
        # Sollwert steht nach dem naechsten Abruf im Abbild.
        zusatz_text = (" Das Geraet kann eine Mindestlast vorgeben; der wirksame Sollwert steht nach dem "
                       "naechsten Abruf unter sollwert." if generation >= 2 else "")
        return (ok, f"Hauslast {watt} W an {sn} gesendet.{zusatz_text}{modus_hinweis}" if ok
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
        if ok:
            # K24: der angenommene Modus gilt fuer die Hauslast, bis das Abbild ihn einholt.
            _MODUS_GESETZT[site_id] = (MODI[wert], time.time())
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


def hauslast_modus(site_id: str, site: dict, jetzt: float | None = None) -> tuple[bool, str]:
    """K24: darf die Hauslast bei Solarbank 2/3 gesendet werden?

    Betriebsart aus dem letzten Abruf - ausser ein ANGENOMMENER Modusbefehl
    ist juenger als dieser Abruf und hoechstens 15 min alt (der Nachlauf des
    Abbilds; das Musterprojekt schickt "manuell" 15 s vor der Hauslast).
    Rueckgabe (senden, Text): Text ist bei Ablehnung die Meldung (beginnt mit
    MODUS_<n>, die Bruecke quittiert "abgelehnt: MODUS_<n>: ..."), sonst ein
    Hinweis fuer die Meldung oder ''."""
    jetzt = time.time() if jetzt is None else jetzt
    sb = site.get("solarbank_info") or {}
    m = zahl(erstes(site, "user_scene_mode", "usage_mode") or (sb.get("usage_mode")))
    if m is None:
        m = zahl(erstes(site.get("scene_info") or {}, "usage_mode"))
    gesetzt = _MODUS_GESETZT.get(site_id)
    if gesetzt and gesetzt[1] >= _ABRUF_ZEIT and jetzt - gesetzt[1] < 900:
        m = gesetzt[0]
    if m in (2, 3):
        return True, ""
    if m in (None, 0):
        return True, " Betriebsart unbekannt - die Hauslast wirkt nur in Manuell oder Steckdosen."
    return False, (f"MODUS_{m}: Hauslast wirkt nur in Betriebsart manuell (oder steckdosen); "
                   f"erst modus manuell senden")


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
        # N1: nur Auftraege der eigenen Form (16 Hex-Zeichen aus
        # ak_befehl_absetzen()); jede andere Datei bleibt unberuehrt.
        if not AUFTRAG_MUSTER.match(datei.name):
            continue
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
        # Entscheidung Nr. 22: ein Sollwert vom Endpunkt (Loxone), den der
        # Dienst angenommen hat - gesendet oder unveraendert (Schrittweite) -,
        # haelt den Rueckfall auf. Vor der Antwort, damit der Merker steht,
        # wenn der Endpunkt antwortet. Befehle aus dem Reiter Test tragen
        # keine Quelle und zaehlen nur ueber das Senden (letzter_schreibbefehl).
        # Seit 0.9.32 auch Quelle 'mqtt' (Befehlsbruecke, E-E3) - sonst hielte ein
        # ueber MQTT angenommener Sollwert den Rueckfall nicht auf.
        if ok == 1 and b.get("quelle") in ("endpunkt", "mqtt") and b.get("aktion") != "abruf":
            sollwert_empfangen_vermerken()
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
#
# Gemessen wird ab dem JUENGEREN von "zuletzt gesendet" (letzter_schreibbefehl,
# auch nach dem Rueckfall selbst) und "zuletzt von Loxone empfangen"
# (sollwert_empfangen, Entscheidung Nr. 22). Bis 0.9.26 nur ab dem gesendeten:
# einen gleichbleibenden Sollwert senden Gleichwert-Unterdrueckung (60 s) und
# Schrittweite nicht erneut, und der Rueckfall griff, obwohl Loxone lebte.
# ---------------------------------------------------------------------------
async def rueckfall_pruefen(api, cfg: dict) -> None:
    minuten = int(cfg.get("rueckfall_min") or 0)
    if minuten <= 0 or not cfg.get("steuerung_ein"):
        return
    try:
        letzte = int(DATEI_LETZTER_SCHREIB.read_text())
    except (OSError, ValueError):
        letzte = 0
    # Ein fehlender oder unbrauchbarer Merker "empfangen" zaehlt 0: dann gilt
    # das bisherige Verhalten (nur der gesendete Befehl).
    bezug = max(letzte, sollwert_empfangen_lesen())
    if bezug <= 0:
        return          # es gab nie einen Sollwert - also nichts zurueckzunehmen
    if (time.time() - bezug) < minuten * 60:
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
        # N3 (seit 0.9.32): Monat und Jahr aus derselben Datei wie der Endpunkt.
        a["monat"], a["jahr"] = energie_summen(i)
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

    # Neustart waehrend einer Stoerung (Verbesserung a2, 30.09.2026): nach
    # dem Start sind api.sites und api.devices leer, bis update_sites()
    # einmal gelingt. Bis 0.9.23 stand dann "anlagen": {} im Abbild - der
    # Endpunkt meldete ANLAGE_UNBEKANNT statt OK=0, und die Deinstallation
    # fand die retained Themen der Anlagen und Geraete nicht mehr. Jetzt
    # bleiben die Bloecke des vorigen Abbilds stehen; ok bleibt 0 und ts der
    # Zeitpunkt des letzten Erfolgs. Gilt nur, solange der Dienst noch keine
    # Anlage kennt - danach traegt api.sites den Stand wie bisher.
    uebernommen = False
    if not ok and not ids:
        va = alt.get("anlagen")
        vg = alt.get("geraete")
        if isinstance(va, dict) and va:
            anlagen = va
            uebernommen = True
        if isinstance(vg, dict) and vg:
            geraete = vg
            uebernommen = True
        if uebernommen:
            melde_gebremst("vorabbild", f"Abruf gescheitert, bevor der Dienst eine Anlage kannte: "
                                        f"{len(anlagen)} Anlagen und {len(geraete)} Geraete aus dem "
                                        f"vorigen Abbild uebernommen (ok=0).", 3600)

    lox = {
        "ok": ok,
        "ts": ts,
        "ts_versuch": int(time.time()),
        "fehler": fehler,
        "anzahl_anlagen": len(anlagen),
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

    # Uebernommene Werte sind kein neuer Messpunkt (a2).
    for nummer, a in ({} if uebernommen else anlagen).items():
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
    # Der Paketordner der Verteilung "anker-solix-api" heisst schlicht "api"
    # (top_level.txt der dist-info). Bis 0.9.14 stand hier
    # "anker_solix_api.api" - ein Name, den es in keiner Fassung der
    # Bibliothek gab. Naeheres in postinstall.sh.
    from api.api import AnkerSolixApi

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
    if not freigeben:
        # K22: gemerkte Praefixe, unter denen nicht mehr gesendet wird, nachfassen.
        praefixe_nachfassen(cfg)
    leser = StrompreisLeser()
    try:
        return await _dienst_lauf(einmal, freigeben, frist, z, leser)
    finally:
        leser.stoppen()


async def _dienst_lauf(einmal: bool, freigeben: bool, frist, z: dict, leser) -> int:
    """Der eigentliche Lauf von dienst() - ausgelagert, damit der Strompreis-Leser
    in jedem Fall angehalten wird (finally oben)."""
    global _ABRUF_ZEIT
    from aiohttp import ClientSession
    from api.api import AnkerSolixApi
    cfg = config()
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
            leser.angleichen(cfg)
            try:
                _ZAEHLWERK["anfragen"] += 1
                await api.update_sites()
                ok = 1
                fehler_folge = 0
                _ABRUF_ZEIT = time.time()
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
                    # K26: jede Anfrage zaehlt, und 429 in jedem Zweig.
                    _ZAEHLWERK["anfragen"] += 1
                    await api.update_device_details()
                    _ZAEHLWERK["anfragen"] += 1
                    await api.update_site_details()
                    letzte_details = jetzt
                except Exception as err:  # noqa: BLE001
                    teilfehler.append(f"Details: {fehlertext(err)}")
                    _ZAEHLWERK["fehler"] += 1
                    if ist_429(err):
                        _ZAEHLWERK["http429"] += 1

            if ok and not cfg.get("ohne_energie") \
                    and (jetzt - letzte_energie) >= cfg["takt_energie"] * cfg["intervall"]:
                try:
                    _ZAEHLWERK["anfragen"] += 1
                    await api.update_device_energy()
                    letzte_energie = jetzt
                except Exception as err:  # noqa: BLE001
                    teilfehler.append(f"Energie: {fehlertext(err)}")
                    _ZAEHLWERK["fehler"] += 1
                    if ist_429(err):
                        _ZAEHLWERK["http429"] += 1

            if ok and not cfg.get("ohne_prognose") \
                    and (jetzt - letzte_prognose) >= cfg["takt_prognose"] * 60:
                for site_id in sorted(api.sites.keys()):
                    _ZAEHLWERK["anfragen"] += 1
                    zusatz["anlagen"].setdefault(site_id, {}).update(
                        await prognose_holen(api, site_id))
                letzte_prognose = jetzt

            # Die zulaessigen Entladegrenzen aendern sich nie - einmal je Tag
            # genuegt. Sie stehen danach in der Oberflaeche, statt dass der
            # Anwender sie durch Danebenliegen herausfindet.
            if ok and (jetzt - letzte_stufen) >= 86400:
                for sn, d in api.devices.items():
                    if str(d.get("type") or "") == "solarbank":
                        _ZAEHLWERK["anfragen"] += 1
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
            # E-E5: das Lebenszeichen einmal direkt nach jedem Abbild ...
            lebenszeichen_senden(cfg, erzwingen=True, leser=leser)

            # Meldeweg: erst wenn das Abbild wirklich zu alt geworden ist.
            # Ein einzelner Aussetzer ist kein Anlass - drei Stunden Stille
            # sind einer, und niemand merkt sie von selbst.
            # ts 0 heisst "noch nie" (Befund Code 3) - keine Rechnung ab 1970.
            lox_ts = int(json_lesen(DATEI_LOXONE).get("ts") or 0)
            lox_alter = int(time.time()) - lox_ts if lox_ts > 0 else -1
            if not ok and (lox_alter < 0 or lox_alter > int(cfg["melden_alter"])):
                seit = (f"seit {lox_alter // 60} min kein erfolgreicher Abruf" if lox_alter >= 0
                        else "bisher kein erfolgreicher Abruf")
                benachrichtigen(3, f"Anker SOLIX: {seit}. Letzte Meldung: {fehler or 'unbekannt'}",
                                "abruf_alt")

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
                # ... und fest alle 60 s, auch in der Fehlerbremse (bis 900 s, N4).
                try:
                    lebenszeichen_senden(config(), leser=leser)
                except Exception as err:  # noqa: BLE001 - ein Lebenszeichen haelt den Dienst nie an
                    melde_gebremst("lebenszeichen", f"Lebenszeichen nicht gesendet: {fehlertext(err)}", 3600)
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
        # aiohttp zuerst: die Bibliothek zieht es in ihrer ersten Zeile und
        # bringt es NICHT selbst mit (ihr Wheel traegt keine Requires-Dist).
        # Fehlt es, soll hier "aiohttp" stehen und nicht eine Meldung ueber
        # anker-solix-api, die in die Irre fuehrt.
        import aiohttp
        from api.api import AnkerSolixApi
        try:
            fassung = md.version("anker-solix-api")
        except Exception:  # noqa: BLE001
            fassung = "unbekannt"
        zeilen.append(f"[OK]   Bibliothek anker-solix-api geladen, Fassung {fassung}")
        # "api" ist ein sehr gewoehnlicher Paketname, und der Ordner dieses
        # Skripts steht als erster im Suchweg. Legt jemand eine api.py daneben,
        # verdeckt sie die Bibliothek lautlos. Deshalb wird nicht nur gemeldet,
        # DASS geladen wurde, sondern WOHER.
        herkunft = getattr(sys.modules.get("api.api"), "__file__", "unbekannt")
        zeilen.append(f"[OK]   geladen aus {herkunft}")
        zeilen.append(f"[OK]   aiohttp {getattr(aiohttp, '__version__', 'unbekannt')}")

        # Die Wege, ueber die das Plugin die Drosselung setzt und schreibt -
        # nachgesehen, nicht angenommen. Genau hier lag der Fehler bis 0.9.6:
        # das Plugin setzte ein Attribut, das es nie gab, und schwieg dazu.
        #
        # Die Sitzungsklasse wird MITGEPRUEFT, und zwar als Klasse. Bis 0.9.14
        # stand hier getattr(AnkerSolixApi, "apisession", object): ein Zugriff
        # auf die KLASSE, die "apisession" gar nicht kennt - der Name entsteht
        # erst am fertigen Objekt. Der Ausdruck fiel deshalb immer auf object
        # zurueck, und der Selbsttest meldete "Pause zwischen Anfragen" und
        # "Zeitschranke" als wirkungslos. Beide werden zur Laufzeit sehr wohl
        # gesetzt: drosselung_setzen() fragt das Objekt. requestDelay und
        # requestTimeout wohnen an AnkerSolixClientSession, endpointLimit an
        # beiden - gemessen am 12.09.2026 gegen v3.6.3. Aufgefallen ist es
        # erst, als die Bibliothek zum ersten Mal ueberhaupt lud.
        from api.session import AnkerSolixClientSession as SITZUNGSKLASSE
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
                        or hasattr(SITZUNGSKLASSE, k)]
            if gefunden:
                zeilen.append(f"[OK]   {name}: {', '.join(gefunden)}")
            else:
                fehler += 1
                zeilen.append(f"[FEHL] {name}: keiner der Wege {', '.join(kandidaten)} vorhanden - "
                              f"die zugehoerige Einstellung bliebe ohne Wirkung")
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append(f"[FEHL] Bibliothek anker-solix-api laesst sich nicht laden: {err}")

    # paho-mqtt: seit 0.9.32 braucht der Dienst es selbst (Abraeumen mit
    # Ruecklesen, Abnehmer haus/strompreis). postinstall.sh begrenzt auf
    # >=2.1.0,<3 (N10); 1.x geht ebenfalls (Fassung wird abgetastet).
    pf = paho_fassung()
    if pf:
        zeilen.append(f"[OK]   paho-mqtt {pf}")
    else:
        zeilen.append("[INFO] paho-mqtt fehlt - Abraeumen mit Ruecklesen und Strompreis-Anzeige gehen nicht")

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
        lox_ts = int(lox.get("ts") or 0)
        if lox_ts > 0:
            zeilen.append(f"[INFO] Letzter ERFOLGREICHER Abruf vor {int(time.time()) - lox_ts} s, "
                          f"ok={z2.get('ok')}, Fehler: {z2.get('fehler') or 'keiner'}")
        else:
            # ts 0 heisst "noch nie" (Befund Code 3).
            zeilen.append(f"[INFO] Noch kein ERFOLGREICHER Abruf, ok={z2.get('ok')}, "
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
    # --themen ebenso (b2): die gebildeten Themen fuer den Reiter Test.
    if "--themen" in sys.argv:
        print(json.dumps(themen_gebildet(), ensure_ascii=False))
        return 0
    # Dienst, Einmallauf und Freigabe schreiben in Daten- und Logordner. Aus
    # einem Pruefarchiv oder einer Baumkopie heraus waere das die laufende
    # Anlage bzw. ein zweiter Dienst am selben Anker-Konto (Faelle Y2, Y5).
    # Der Selbsttest liest nur und bleibt erlaubt.
    if not INSTALLIERT and "--selbsttest" not in sys.argv:
        print(f"FEHLER: {SELF} liegt nicht unter {LBHOME}/bin/plugins/{PNAME} - "
              "aus einem ausgepackten Archiv oder einer Kopie wird nichts gestartet.",
              file=sys.stderr)
        return 1
    # Vor log_einrichten(): das Abraeumen schreibt kein Protokoll, nur den
    # Merker der Praefixe (nie als root).
    if "--abraeumen" in sys.argv:
        i = sys.argv.index("--abraeumen")
        return abraeumen_aufruf(sys.argv[i + 1:i + 2])
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
