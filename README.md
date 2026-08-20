# LoxBerry-Plugin: Anker SOLIX

Bindet **Anker SOLIX** an Loxone an: Solarbank E1600 (Gen 1), Solarbank 2
(Plus/Pro/AC), Solarbank 3 E2700, Anker Smart Meter, MI80-Wechselrichter,
Smart Plugs, Powerstations (C300 bis F3800), Power Panel und Home Energy
System X1.

> **Fassung 0.9.7 — ungeprüft.** Das Plugin wurde ohne Anker-Konto und ohne
> Gerät gebaut. Aufbau, Sprachdateien, Endpunkt und Oberfläche sind geprüft;
> ob die Feldnamen der Cloud-Antwort passen und ob die schreibenden Befehle am
> Gerät die erwartete Wirkung haben, ist es **nicht**. Deshalb 0.9.7 und nicht
> 1.0.0. Wer es erprobt, findet im Reiter *Test* den Knopf *Rohdaten der Cloud
> ansehen* — dort stehen die tatsächlichen Feldnamen der eigenen Anlage.
>
> **Neu in 0.9.7 und damit besonders unerprobt:** Netzeinspeisung sperren,
> Einspeisegrenze, Notstromreserve und die Begrenzung des Wechselrichters. Sie
> greifen über `set_station_parm` beziehungsweise `set_device_pv_power` ein.

## Was sich gegenüber 0.9.6 geändert hat

Reparaturen zuerst — sie betreffen Zusagen, die 0.9.6 gemacht und nicht
gehalten hat:

* **`ALTER` misst jetzt den Abstand zum letzten *erfolgreichen* Abruf.** Bis
  0.9.6 wurde der Zeitstempel in jedem Durchlauf neu gesetzt, auch nach einer
  Fehlerantwort der Cloud. Die Ausfallerkennung, die der Reiter *Einbindung in
  Loxone* beschreibt (Schwelle 300 s), konnte damit **nie** ansprechen: bei
  einer drei Tage langen Störung blieb `ALTER` unter 60.
* **Die Oberfläche braucht kein JavaScript mehr.** `sm-active` setzt jetzt der
  Server. Vorher tat das ausschließlich das Skript am Seitenende — und weil
  `.sm-seite` auf `display: none` steht, war die Seite ohne JavaScript
  vollständig leer.
* **Die Anfragegrenze wirkt.** Das Plugin setzte ein Attribut `endpoint_limit`,
  das es in `anker-solix-api` nicht gibt (dort heißt es `_endpoint_limit`,
  gesetzt wird über die Methode `endpointLimit()`). Das Eingabefeld war ohne
  Wirkung, und ein `except AttributeError: pass` versteckte es. Jetzt wird der
  Weg benannt — und wenn keiner passt, steht das im Protokoll.
* **Die Befehlserkennung zum Abschreiben trägt das führende Semikolon.** Die
  erzeugte Importdatei hatte es, die Tabelle daneben nicht. Ohne Semikolon
  findet `LADEN=` auch die Stelle in `ENTLADEN=`.
* **Der Knopf für die Rohdaten zeigt die Rohdaten.** Bis 0.9.6 zeigte er das
  bereits umgesetzte Abbild mit den Namen dieses Plugins — also genau nicht
  das, was Hilfe und README versprachen.
* **`dpkg/apt` und `uninstall/uninstall`** gibt es jetzt. Ersteres lässt
  LoxBerry `python3-venv` und `git` als root einspielen (`postinstall.sh` läuft
  als Benutzer `loxberry` und kann das nicht); letzteres räumt die Sicherung
  mit dem Anker-Passwort weg, die eine Ebene über dem Konfigurationsordner
  liegt und eine Deinstallation sonst überlebt.
* **Formulare tragen ein Merkmal gegen fremde Absender**, geprüft an einer
  zentralen Stelle vor allen Handlern.
* Beschädigte Konfiguration wird als Fehler behandelt und bleibt als `.kaputt`
  liegen; geschrieben wird über eine Nebendatei mit `rename()`; die Rechte der
  Zugangsdatei entstehen beim Anlegen, nicht hinterher.

Dazu neue Funktionen:

* **Rückfall in eine sichere Betriebsart.** Anker kennt keinen Watchdog. Kommt
  längere Zeit kein Sollwert mehr, stellt das Plugin die Betriebsart selbst
  zurück. Ab Werk **aus**.
* **Schreibbremse und Schrittweite** gegen einen Regelkreis, der die Cloud in
  die 429-Sperre treibt. Verworfene Befehle werden gemeldet, nicht geschluckt.
* **Grenzen je Anlage** statt einer gemeinsamen Ober- und Untergrenze.
* **Abrufumfang einstellbar**: Details, Energiestatistik und Prognose lassen
  sich einzeln abschalten — die wirksamste Bremse gegen HTTP 429.
* **Solarprognose** (Rest-Ertrag des Tages) als eigener Wert.
* **Tagesenergien werden fortgeschrieben**: daraus Monats- und Jahressummen und
  fortlaufende Zählerstände für den Loxone-Energiemonitor. Die Cloud liefert
  nur „heute“, und um Mitternacht fällt der Wert auf 0 zurück.
* **Einspeisung sperren, Einspeisegrenze, Notstromreserve, Wechselrichter
  begrenzen** — die vier neuen, unerprobten Befehle.
* **Trockenlauf** im Reiter *Test*: was der Befehl täte, ohne ihn abzusetzen.
* **Benachrichtigungen** in den LoxBerry-Meldebereich bei anhaltender Störung.
* **Verlauf mit Tagesauswahl** und ein Balkenbild der Tagesenergien. Bis 0.9.6
  hielt der Dienst bis zu 90 Tage vor und zeigte immer nur heute.
* **Vorlagen für alles**: Leistungswerte, Energiewerte, je Gerät, Virtueller
  Ausgang mit den Steuerbefehlen — einzeln oder als ZIP.
* Der Reiter *Test* **ruft den eigenen Endpunkt wirklich auf** und prüft
  zusätzlich, ob Oberfläche, Dienst und Anleitung noch zusammenpassen.

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
  Minuten auffrischt. Wie oft abgewiesen wurde, steht im Reiter *Test*.
* **Kein Watchdog.** Der Marstek-Passivmodus stoppt den Speicher, wenn Loxone
  schweigt. Anker kennt nichts Vergleichbares: ein gesetzter Sollwert bleibt
  stehen. Seit 0.9.7 kann das Plugin die Betriebsart nach einer einstellbaren
  Zeit selbst zurückstellen — das muss man aber ausdrücklich einschalten.

## Aufbau

    bin/ankersolix.py         Abrufdienst (Python, eigene venv)
    bin/ak_notify.php         Meldeweg in den LoxBerry-Meldebereich
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    dpkg/apt                  python3-venv und git, von LoxBerry als root
    uninstall/uninstall       gibt die Anlage frei und räumt die Sicherungen weg
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
  Es steht in `dpkg/apt` und wird von LoxBerry als root eingespielt.
* **`python3-venv`.** Systemweites `pip3 install` scheitert auf Debian 12/13 an
  PEP 668 (`externally-managed-environment`); deshalb eine eigene venv unter
  `bin/plugins/ankersolix/venv`. Steht ebenfalls in `dpkg/apt`.
* MQTT-Gateway eingeschaltet, wenn die Werte per MQTT kommen sollen. Es ist seit
  LoxBerry 3 Bestandteil des Systems und wird unter *System → MQTT Gateway*
  aktiviert, nicht nachinstalliert.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=selftest` | Token prüfen, **ohne dass etwas geschieht** |
| `?token=T&aktion=status&anlage=N` | `ANKER;OK=..;SOC=..;PV=..;LADEN=..;ENTLADEN=..;BATP=..;AUSGANG=..;HAUS=..;NETZBEZUG=..;NETZEINSP=..;SOLL=..;MODUS=..;RESERVE=..;EINSPEISUNG=..;GRENZE=..;PROGNOSE=..;GERAETE=..;ALTER=..` |
| `?token=T&aktion=energie&anlage=N[&zeitraum=tag\|monat\|jahr]` | Tages-, Monats- oder Jahreswerte, dazu die fortlaufenden Zählerstände |
| `?token=T&aktion=geraet&sn=SN` | Werte eines einzelnen Geräts |
| `?token=T&aktion=anlagen` | Liste der erkannten Anlagen |
| `?token=T&aktion=roh` | umgesetztes Abbild als JSON |
| `?token=T&aktion=hauslast&watt=W` | Ausgangsleistung setzen |
| `?token=T&aktion=modus&wert=…` | `eigenverbrauch`, `steckdosen`, `manuell`, `zeitplan`, `smart`, `zeitfenster` |
| `?token=T&aktion=reserve&prozent=P` | Entladegrenze setzen |
| `?token=T&aktion=einspeisung&wert=ein\|aus` | Netzeinspeisung freigeben oder sperren |
| `?token=T&aktion=einspeisegrenze&watt=W` | Einspeisegrenze setzen |
| `?token=T&aktion=notstromreserve&prozent=P` | Notstromreserve setzen |
| `?token=T&aktion=pvlimit&sn=SN&watt=W` | Wechselrichter begrenzen (MI80, 0–800 W) |
| `?token=T&aktion=abruf` | sofort abrufen statt auf den Takt zu warten |

**Ein Strich als Wert** heißt: die Cloud hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber innerhalb der Wartezeit ohne Antwort — also
Ergebnis unbekannt. Ein Erfolg, den niemand geprüft hat, wird nie gemeldet.

Die **Rohdaten der Cloud** mit den echten Feldnamen gibt der Endpunkt bewusst
**nicht** heraus: sie tragen die Kontokennung, und der Endpunkt liegt im
unangemeldeten Bereich. Sie stehen im Reiter *Test*.

## Was das Plugin über MQTT sendet

Dieselben Werte wie über HTTP, dazu `ok`, **`ts`** und `fehler`. Über MQTT gibt
es kein „Alter“ — beim Senden ist es immer null. Deshalb wird der *Zeitstempel
des letzten erfolgreichen Abrufs* veröffentlicht, und die Gegenseite rechnet.
Ohne ihn ist ein toter Dienst von einem gesunden nicht zu unterscheiden.

Bei einem Fehlschlag gehen ausschließlich `ok=0`, `ts` und `fehler` hinaus. Die
Messwerte behalten ihren Stand — sonst überschriebe man gute Daten mit alten
und verkaufte es als frisch.

## Datenschutz

Es sind keine persönlichen Daten im Plugin enthalten. Zugangsdaten und alle
Einstellungen liegen ausschließlich in der lokalen Konfiguration. Verbindungen
gibt es nur zur Anker-Cloud. Die Deinstallation entfernt auch die Sicherung
außerhalb des Plugin-Ordners, in der die Zugangsdaten liegen.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Die Cloud-Anbindung nutzt
[anker-solix-api](https://github.com/thomluther/anker-solix-api) von thomluther
(ebenfalls MIT). Das ist keine amtliche Anker-Schnittstelle und kann sich
jederzeit ändern.
