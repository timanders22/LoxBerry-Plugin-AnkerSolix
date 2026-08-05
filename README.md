# LoxBerry-Plugin: Anker SOLIX

Bindet **Anker SOLIX** an Loxone an: Solarbank E1600 (Gen 1), Solarbank 2
(Plus/Pro/AC), Solarbank 3 E2700, Anker Smart Meter, MI80-Wechselrichter,
Smart Plugs, Powerstations (C300 bis F3800), Power Panel und Home Energy
System X1.

> **Fassung 0.9.0 — ungeprüft.** Das Plugin wurde ohne Anker-Konto und ohne
> Gerät gebaut. Aufbau, Sprachdateien, Endpunkt und Oberfläche sind geprüft;
> ob die Feldnamen der Cloud-Antwort passen und ob die schreibenden Befehle am
> Gerät die erwartete Wirkung haben, ist es **nicht**. Deshalb 0.9.0 und nicht
> 1.0.0. Wer es erprobt, findet im Reiter *Test* den Knopf *Rohdaten als JSON
> ansehen* — dort stehen die tatsächlichen Feldnamen der eigenen Anlage.

## Anker hat keine lokale API

Anders als beim Marstek-Speicher, dem dieses Plugin nachgebaut ist, gibt es
bei Anker SOLIX **keine lokale Schnittstelle**. Weder die Solarbank noch der
Smart Meter beantworten Anfragen aus dem Heimnetz; sämtliche Werte laufen über
die Anker-Cloud beziehungsweise deren MQTT-Server. Das Plugin meldet sich
deshalb mit den Anker-Zugangsdaten an.

Daraus folgen drei Unterschiede, die man kennen sollte:

* **Zugangsdaten nötig.** Sie liegen in `config/plugins/ankersolix/zugang.json`
  mit den Rechten 0600 — nicht in der Konfiguration, die die Oberfläche
  anzeigt, und nie in der Loxone-Projektdatei.
* **Anfragegrenzen.** Die Energiestatistik ist auf etwa 10–12 Abfragen je
  Minute gedrosselt; darüber antwortet die Cloud mit HTTP 429. Ein Takt unter
  60 s bringt nichts, weil das Gerät die Cloud selbst nur ein- bis alle fünf
  Minuten auffrischt.
* **Kein Watchdog.** Der Marstek-Passivmodus stoppt den Speicher, wenn Loxone
  schweigt. Anker kennt nichts Vergleichbares: ein gesetzter Sollwert bleibt
  stehen. Wer das nicht will, lässt Loxone vor dem Herunterfahren einmal die
  Betriebsart `eigenverbrauch` senden.

## Aufbau

    bin/ankersolix.py         Abrufdienst (Python, eigene venv)
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (fünf Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + gemeinsame Bibliothek

Drei Aufgaben, drei Dateien: Die Oberfläche bedient, der Dienst ruft ab, der
Endpunkt bedient den Miniserver. Weder Oberfläche noch Endpunkt sprechen je
selbst mit der Anker-Cloud — sie lesen den Zwischenspeicher und legen Befehle
in einer Warteschlange ab.

## Voraussetzungen

* **Python 3.12 oder neuer.** Die Bibliothek `anker-solix-api` verlangt das
  (`requires-python = ">=3.12"`). Auf Debian 12 (Bookworm) ist das System-Python
  3.11 — dort schlägt die Installation mit einer klaren Meldung fehl, statt
  stillschweigend ein totes Plugin zu hinterlassen. Debian 13 liefert 3.13.
* **Internetverbindung bei der Installation.** `anker-solix-api` steht nicht auf
  PyPI und wird von GitHub geholt (Tag `v3.6.3`), also wird `git` gebraucht.
* **`python3-venv`.** Systemweites `pip3 install` scheitert auf Debian 12/13 an
  PEP 668 (`externally-managed-environment`); deshalb eine eigene venv unter
  `bin/plugins/ankersolix/venv`.
* MQTT-Gateway eingeschaltet, wenn die Werte per MQTT kommen sollen. Es ist seit
  LoxBerry 3 Bestandteil des Systems und wird unter *System → MQTT Gateway*
  aktiviert, nicht nachinstalliert.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&anlage=N` | `ANKER;OK=..;SOC=..;PV=..;LADEN=..;ENTLADEN=..;BATP=..;AUSGANG=..;HAUS=..;NETZBEZUG=..;NETZEINSP=..;SOLL=..;MODUS=..;GERAETE=..;ALTER=..` |
| `?token=T&aktion=energie&anlage=N` | `ENERGIE;OK=..;PV=..;BATLD=..;BATENTL=..;HAUS=..;NETZBEZUG=..;NETZEINSP=..;DATUM=..;ALTER=..` |
| `?token=T&aktion=geraet&sn=SN` | Werte eines einzelnen Geräts |
| `?token=T&aktion=anlagen` | Liste der erkannten Anlagen |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=hauslast&watt=W` | Ausgangsleistung setzen |
| `?token=T&aktion=modus&wert=…` | `eigenverbrauch`, `steckdosen`, `manuell`, `zeitplan`, `smart` |
| `?token=T&aktion=reserve&prozent=P` | Entladegrenze setzen |
| `?token=T&aktion=abruf` | sofort abrufen statt auf den Takt zu warten |

**Ein Strich als Wert** heißt: die Cloud hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber innerhalb der Wartezeit ohne Antwort — also
Ergebnis unbekannt. Ein Erfolg, den niemand geprüft hat, wird nie gemeldet.

## Datenschutz

Es sind keine persönlichen Daten im Plugin enthalten. Zugangsdaten und alle
Einstellungen liegen ausschließlich in der lokalen Konfiguration. Verbindungen
gibt es nur zur Anker-Cloud.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Die Cloud-Anbindung nutzt
[anker-solix-api](https://github.com/thomluther/anker-solix-api) von thomluther
(ebenfalls MIT). Das ist keine amtliche Anker-Schnittstelle und kann sich
jederzeit ändern.
