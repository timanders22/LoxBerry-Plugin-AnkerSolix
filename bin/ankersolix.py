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
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import socket
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path

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
    LBHOME = Path(os.environ.get("LBHOMEDIR") or "/opt/loxberry")
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "ankersolix.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
DATEI_LOG = PLOG / "ankersolix.log"

VORGABEN = {
    "land": "DE",
    "intervall": 60,
    "takt_details": 10,
    "takt_energie": 15,
    "endpunkt_limit": 10,
    "mqtt_ein": 0,
    "mqtt_topic": "ankersolix",
    "steuerung_ein": 0,
    "hauslast_min": 0,
    "hauslast_max": 1600,
    "verlauf_tage": 8,
}

# Betriebsmodi der Solarbank 2/3. Die Zahlen stammen aus SolarbankUsageMode der
# Bibliothek (apitypes.py) und sind dort so festgelegt.
MODI = {
    "eigenverbrauch": 1,   # smartmeter  - Ausgabe nach gemessenem Hausverbrauch
    "steckdosen": 2,       # smartplugs  - Ausgabe nach gemessenen Steckdosen
    "manuell": 3,          # manual      - fester Zeitplan
    "zeitplan": 5,         # use_time    - Nutzungszeitplan (SB2 AC)
    "smart": 7,            # smart       - KI-gestuetzt
}
MODI_TEXT = {
    0: "unbekannt", 1: "Eigenverbrauch", 2: "Steckdosen", 3: "Manuell",
    4: "Notstrom", 5: "Nutzungszeit", 7: "Smart", 8: "Zeitfenster",
}

_LAUF = True
_LOG = logging.getLogger("ankersolix")
_LETZTE_MELDUNG: dict[str, float] = {}


# ---------------------------------------------------------------------------
# Protokollierung
#
# Ausschliesslich in die Datei. Das Startskript leitet die Ausgabe des Dienstes
# ohnehin in dieselbe Datei um - ein zweiter Kanal nach stdout schriebe jede
# Zeile doppelt hinein.
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


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    c["intervall"] = max(30, min(900, int(c.get("intervall") or 60)))
    c["takt_details"] = max(1, min(240, int(c.get("takt_details") or 10)))
    c["takt_energie"] = max(1, min(240, int(c.get("takt_energie") or 15)))
    c["endpunkt_limit"] = max(1, min(60, int(c.get("endpunkt_limit") or 10)))
    c["hauslast_min"] = max(0, min(5000, int(c.get("hauslast_min") or 0)))
    c["hauslast_max"] = max(0, min(5000, int(c.get("hauslast_max") or 1600)))
    c["verlauf_tage"] = max(1, min(90, int(c.get("verlauf_tage") or 8)))
    land = str(c.get("land") or "DE").strip().upper()
    c["land"] = land if len(land) == 2 and land.isalpha() else "DE"
    return c


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
            nachricht = f"publish {praefix}/{k} {v}".encode("utf-8")
            s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


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


def anlage_abbilden(site_id: str, site: dict, devices: dict) -> dict:
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
    }


def geraet_abbilden(sn: str, d: dict) -> dict:
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
        "kapazitaet": zahl(erstes(d, "battery_capacity", "battery_size")),
        "netzbezug": zahl(d.get("grid_to_home_power")),
        "netzeinspeisung": zahl(d.get("photovoltaic_to_grid_power")),
        "leistung": zahl(d.get("current_power")),
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
                "Abstand oder Takt in den Einstellungen vergroessern.")
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


async def befehl_ausfuehren(api, cfg: dict, b: dict) -> tuple[int, str, dict]:
    """Rueckgabe: (ok, Meldung, Zusatzfelder)."""
    aktion = str(b.get("aktion") or "")

    if aktion == "abruf":
        return (1, "Sofortabruf eingeplant.", {})

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.", {})

    site_id = anlage_waehlen(api, b.get("anlage"))
    if site_id is None:
        return (0, f"Anlage '{b.get('anlage')}' gibt es nicht. Bekannt sind {len(api.sites)} Anlagen.", {})
    sn, geraet = speicher_waehlen(api, site_id, str(b.get("sn") or "") or None)
    if sn is None:
        return (0, "In dieser Anlage ist kein Speicher (Solarbank) bekannt.", {})
    generation = zahl(geraet.get("generation")) or 0

    if aktion == "hauslast":
        watt = zahl(b.get("watt"))
        if watt is None:
            return (0, "Der Wert fuer die Hauslast fehlt oder ist keine Zahl.", {})
        lo, hi = cfg["hauslast_min"], cfg["hauslast_max"]
        if watt < lo or watt > hi:
            # Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
            # fuehrt zu einer Anlage, die etwas anderes tut als angezeigt.
            return (0, f"Hauslast {watt} W liegt ausserhalb der eingestellten Grenzen ({lo} bis {hi} W). "
                       f"Grenzen im Reiter Einstellungen anpassen, wenn Ihr Geraet mehr kann.", {})
        if generation >= 2:
            erg = await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, preset=float(watt))
        else:
            erg = await api.set_home_load(siteId=site_id, deviceSn=sn, preset=int(watt))
        ok = 1 if erg else 0
        return (ok, f"Hauslast {watt} W an {sn} gesendet." if ok
                else f"Die Cloud hat den Sollwert {watt} W nicht angenommen.", {"watt": watt, "sn": sn})

    if aktion == "modus":
        wert = str(b.get("wert") or "").strip().lower()
        if wert not in MODI:
            return (0, f"Unbekannter Modus '{wert}'. Erlaubt sind: {', '.join(sorted(MODI))}.", {})
        if generation < 2:
            return (0, "Betriebsmodi gibt es erst ab Solarbank 2. Die Solarbank 1 kennt nur Zeitplaene.", {})
        erg = await api.set_sb2_home_load(siteId=site_id, deviceSn=sn, usage_mode=MODI[wert])
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
        ok = 1 if erg else 0
        return (ok, f"Entladegrenze {prozent} % an {sn} gesendet." if ok
                else f"Die Cloud hat die Entladegrenze {prozent} % nicht angenommen.",
                {"prozent": prozent, "sn": sn})

    return (0, f"Unbekannte Aktion '{aktion}'.", {})


async def warteschlange(api, cfg: dict) -> bool:
    """Arbeitet alle vorliegenden Befehle ab. Rueckgabe: True, wenn ein
    Sofortabruf angefordert wurde."""
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    sofort = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        kennung = datei.stem
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        try:
            ok, meldung, zusatz = await befehl_ausfuehren(api, cfg, b)
        except Exception as err:  # noqa: BLE001 - jeder Fehler gehoert gemeldet, nicht verschluckt
            ok, meldung, zusatz = 0, fehlertext(err), {}
        antwort_schreiben(kennung, ok, meldung, zusatz)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        if b.get("aktion") == "abruf" and ok:
            sofort = True
    return sofort


# ---------------------------------------------------------------------------
# Abbild schreiben
# ---------------------------------------------------------------------------
def abbild_schreiben(api, cfg: dict, ok: int, fehler: str = "") -> dict:
    ids = sorted(api.sites.keys())
    anlagen = {}
    for i, sid in enumerate(ids, start=1):
        anlagen[str(i)] = anlage_abbilden(sid, api.sites.get(sid) or {}, api.devices)
    geraete = {sn: geraet_abbilden(sn, d) for sn, d in api.devices.items()}

    lox = {
        "ok": ok,
        "ts": int(time.time()),
        "fehler": fehler,
        "anzahl_anlagen": len(ids),
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

    if cfg.get("mqtt_ein"):
        praefix = str(cfg.get("mqtt_topic") or "ankersolix").strip("/") or "ankersolix"
        paare = {"ok": ok, "anlagen": len(ids)}
        for nummer, a in anlagen.items():
            for feld in ("soc", "pv", "laden", "entladen", "batp", "ausgang", "haus",
                         "netzbezug", "netzeinspeisung", "sollwert", "modus"):
                paare[f"anlage{nummer}/{feld}"] = a.get(feld)
            for feld, wert in (a.get("energie") or {}).items():
                paare[f"anlage{nummer}/energie/{feld}"] = wert
        for sn, g in geraete.items():
            for feld in ("soc", "pv", "ausgang", "laden", "sollwert", "online", "wlan", "leistung"):
                paare[f"geraet/{sn}/{feld}"] = g.get(feld)
        mqtt_senden(paare, praefix)

    return lox


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    json_schreiben(DATEI_ZUSTAND, z)


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


async def dienst(einmal: bool = False) -> int:
    from aiohttp import ClientSession
    from anker_solix_api.api import AnkerSolixApi

    cfg = config()
    z = zugang()
    if not z["email"] or not z["passwort"]:
        _LOG.error("Zugangsdaten fehlen. Reiter Einstellungen der Plugin-Oberflaeche oeffnen.")
        zustand_schreiben(ok=0, fehler="Zugangsdaten fehlen.")
        return 1

    _LOG.info("Dienst startet (Takt %s s, Land %s, Steuerung %s).",
              cfg["intervall"], cfg["land"], "ein" if cfg.get("steuerung_ein") else "aus")

    async with ClientSession() as sitzung:
        api = AnkerSolixApi(z["email"], z["passwort"], cfg["land"], sitzung, _LOG)
        # Anfragedrosselung, falls die Bibliothek sie anbietet. Die Anker-Cloud
        # weist ab etwa 10 bis 12 Abfragen je Minute und Endpunkt mit 429 ab.
        for ziel in (api, getattr(api, "apisession", None)):
            if ziel is not None and hasattr(ziel, "endpoint_limit"):
                try:
                    ziel.endpoint_limit = cfg["endpunkt_limit"]
                except (AttributeError, TypeError):
                    pass

        zyklus = 0
        fehler_folge = 0
        while _LAUF:
            cfg = config()  # Aenderungen aus der Oberflaeche ohne Neustart uebernehmen
            ok = 0
            fehler = ""
            try:
                await api.update_sites()
                if zyklus % cfg["takt_details"] == 0:
                    # Reihenfolge ist vorgegeben: Geraetedetails zuerst, sie
                    # legen bei Einzelgeraeten erst die virtuellen Anlagen an.
                    await api.update_device_details()
                    await api.update_site_details()
                if zyklus % cfg["takt_energie"] == 0:
                    await api.update_device_energy()
                ok = 1
                fehler_folge = 0
            except Exception as err:  # noqa: BLE001
                fehler = fehlertext(err)
                fehler_folge += 1
                melde_gebremst("abruf", f"Abruf fehlgeschlagen: {fehler}", 900)

            abbild_schreiben(api, cfg, ok, fehler)
            zustand_schreiben(ok=ok, fehler=fehler, zyklus=zyklus, fehler_folge=fehler_folge,
                              pid=os.getpid(), intervall=cfg["intervall"])
            if ok:
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
        from anker_solix_api.api import AnkerSolixApi  # noqa: F401
        try:
            fassung = md.version("anker-solix-api")
        except Exception:  # noqa: BLE001
            fassung = "unbekannt"
        zeilen.append(f"[OK]   Bibliothek anker-solix-api geladen, Fassung {fassung}")
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append(f"[FEHL] Bibliothek anker-solix-api laesst sich nicht laden: {err}")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = os.access(pfad, os.W_OK) if pfad.exists() else False
        zeilen.append(f"[{'OK]  ' if schreibbar else 'FEHL]'} Ordner {name} beschreibbar: {pfad}")
        if not schreibbar:
            fehler += 1

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
    zeilen.append(f"[INFO] Takt {c['intervall']} s, Details alle {c['takt_details']} Takte, "
                  f"Energie alle {c['takt_energie']} Takte, Land {c['land']}")
    zeilen.append(f"[INFO] Schreibende Befehle: {'zugelassen' if c.get('steuerung_ein') else 'gesperrt'}, "
                  f"Hauslast erlaubt von {c['hauslast_min']} bis {c['hauslast_max']} W")

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
        alter = int(time.time()) - int(z2.get("ts") or 0)
        zeilen.append(f"[INFO] Letzter Abruf vor {alter} s, ok={z2.get('ok')}, "
                      f"Fehler: {z2.get('fehler') or 'keiner'}")
    else:
        zeilen.append("[INFO] Es hat noch kein Abruf stattgefunden")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer ein Anker-Konto und ein Geraet noetig sind:")
    zeilen.append("  - ob die Anmeldung an der Anker-Cloud gelingt")
    zeilen.append("  - ob die Feldnamen dieser Cloud-Fassung zu der Zuordnung hier passen")
    zeilen.append("  - ob die schreibenden Befehle am Geraet die erwartete Wirkung haben")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst(einmal="--einmal" in sys.argv))
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
