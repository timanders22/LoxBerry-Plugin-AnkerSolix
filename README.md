# LoxBerry-Plugin: Anker SOLIX

Bindet **Anker SOLIX** an Loxone an: Solarbank E1600 (Gen 1), Solarbank 2
(Plus/Pro/AC), Solarbank 3 E2700, Anker Smart Meter, MI80-Wechselrichter,
Smart Plugs, Powerstations (C300 bis F3800), Power Panel und Home Energy
System X1.

> **Fassung 0.9.x — ungeprüft.** Das Plugin wurde ohne Anker-Konto und ohne
> Gerät gebaut. Aufbau, Sprachdateien, Endpunkt und Oberfläche sind geprüft;
> ob die Feldnamen der Cloud-Antwort passen und ob die schreibenden Befehle am
> Gerät die erwartete Wirkung haben, ist es **nicht**. Deshalb 0.9.x und nicht
> 1.0.0. Wer es erprobt, findet im Reiter *Test* den Knopf *Rohdaten der Cloud
> ansehen* — dort stehen die tatsächlichen Feldnamen der eigenen Anlage.
>
> **Neu in 0.9.7 und damit besonders unerprobt:** Netzeinspeisung sperren,
> Einspeisegrenze, Notstromreserve und die Begrenzung des Wechselrichters. Sie
> greifen über `set_station_parm` beziehungsweise `set_device_pv_power` ein.

## Version 0.9.29

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen.
* Die Statuskacheln über den Reitern (Dienst, letzter Abruf, Anlagen, MQTT, Steuerung) bleiben, wie sie sind.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Version 0.9.28

Eingaben mit angehängtem Zeilenumbruch werden abgewiesen (Verbesserungsliste Anker-k1).
Gemessen am ganzen Endpunkt, an der Oberfläche über
`php -S` und an den Bibliotheksfunktionen, je unter PHP 7.4 und 8.5 (Windows, eigene Attrappen); nicht am Gerät,
nicht an einer Anlage.

* **Ein Wert mit angehängtem Zeilenumbruch wird abgewiesen.** Bisher ging z. B. `watt=100%0A` als 100 durch, weil
  die Muster des Endpunkts auf `$` endeten; das passt auch vor einem abschließenden Zeilenumbruch. Jetzt antwortet der
  Endpunkt mit HTTP 400 `FEHLER;OK=0;GRUND=PARAMETER`, wie bei jedem anderen Wert außerhalb des Musters. Das gilt für
  alle sechs Parameter: `anlage`, `sn`, `watt`, `prozent`, `wert`, `zeitraum`.
* Gültige Werte antworten unverändert, Byte für Byte gleich wie vorher (gemessen an sechs Adressen).
* **Auch im Reiter Test** wird ein Wert mit angehängtem Zeilenumbruch abgewiesen und das Feld markiert, statt den
  Befehl einzureihen. Bisher ging z. B. `eigenverbrauch` mit Zeilenumbruch als Betriebsart in die Warteschlange.
  Das gilt für Anlage, Watt, Prozent und Modus.
* Dieselbe Strenge gilt für die übrigen Prüfungen der Oberfläche und der Bibliothek: Verlaufstag, Vorlagennummer,
  Einstellungen, gespeicherte Merker und Dateinamen. Dort ändert sich für den Nutzer nichts, weil die Werte schon
  vorher getrimmt wurden oder keinen Umbruch tragen können.

**In Loxone:** Nichts zu ändern; eine Adresse, die Loxone selbst zusammensetzt, trägt keinen Zeilenumbruch.

## Version 0.9.27

Schreiber-Wache (Energie-1, Entscheidung 25).
Gemessen mit Attrappen unter PHP 7.4 und 8.5 sowie mit echtem Dienst in WSL; nicht am Gerät, nicht an einer echten Anlage.

* **Kennung des Schreibers:** Sollwert-Befehle nehmen ein optionales `&von=<kennung>` an, die Vorlage sendet `von=loxone`. Eine ungültige Kennung wird mit 400 `GRUND=VON` abgewiesen, dabei wird nichts eingereiht.
* **Schreiber-Wache, ab Werk an:** Schreiben innerhalb von 15 Minuten mehrere Schreiber, meldet das Plugin es im Protokoll, im Reiter Test (24 h) und in der Antwort mit `;SCHREIBER=n`. Eine LoxBerry-Meldung dazu ist wählbar und ab Werk aus.
* **„Fremde Schreiber abweisen“, ab Werk aus:** Eingeschaltet antwortet ein nicht erlaubter Schreiber mit 409 `GRUND=FREMDSCHREIBER`, nichts wird eingereiht. Die Rücknahme `modus=eigenverbrauch` wird nie abgewiesen.
* **Merker nicht nutzbar:** Der Befehl geht trotzdem, die Antwort trägt `;WACHE=MERKER`.
* Gleichwert-Bremse, Schrittweite und Rückfall sind unverändert, es gibt kein neues MQTT-Thema.

**In Loxone:** nichts zu tun. Wer die Vorlage neu einliest, bekommt `von=loxone` an den Befehlen.

## Version 0.9.26

Entscheidung 22 (Verbesserungsliste `Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`).
Gemessen an einer Cloud-Attrappe unter PHP 8.3; nicht am Speicher.

* **Rückfall berichtigt:** Der Rückfall (ab Werk aus) misst jetzt die Zeit seit
  dem letzten gültigen Sollwert von Loxone. Auch ein Aufruf mit `UNVERAENDERT=1`
  (gleicher Wert innerhalb 60 s oder innerhalb der Schrittweite) zählt. Bisher
  griff der Rückfall bei gleichbleibendem Sollwert, obwohl Loxone lebte – etwa
  mit den Takt-Bausteinen der Loxone-Vorlage.
* Abgewiesene Aufrufe, Reiter Test und Trockenlauf ohne Senden zählen nicht;
  gesendet wird nichts zusätzlich.
* Ist der Merker unbrauchbar, gilt das bisherige Verhalten: der Rückfall greift
  eher zu früh als nie. Der Reiter Test zeigt das Alter des Merkers.

## Version 0.9.25

Entscheidung 21 (Verbesserungsliste `Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`).
Gemessen an einer Cloud-Attrappe unter PHP 8.3; nicht am Speicher.

* **Hauslast innerhalb der Schrittweite:** Liegt ein neuer Hauslast-Sollwert nur
  innerhalb der Schrittweite neben dem gesetzten, antwortet der Endpunkt
  `SET;OK=1;AKTION=hauslast;UNVERAENDERT=1` mit HTTP 200 statt bisher `OK=0` mit
  HTTP 500. Gesendet wird weiterhin nichts. Das gilt auch, während die
  Schreibbremse greift.
* Ein Wert außerhalb der Schrittweite geht wie bisher hinaus; echte Fehler (Cloud
  lehnt ab, Grenzen, Schreibbremse bei anderem Wert) bleiben Fehler.
* Reiter Test und Trockenlauf zeigen diesen Fall als „nichts gesendet“ statt als
  Ablehnung.

## Version 0.9.24

Verbesserungen aus dem Durchgang (Verbesserungsliste
`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`, Entscheidungen 16 und 19).
Gemessen an einer Anker-Cloud-Attrappe unter PHP 7.4, 8.3 und 8.5; nicht am Speicher.

* **Neuinstallation:** Alte Zweitschriften werden schon vor dem Kopieren der
  Oberfläche beiseitegelegt (`preinstall.sh`, `.alt`). Bisher galt das alte
  Aktionstoken nach einer Neuinstallation weiter.
* Der Endpunkt schreibt die Konfiguration nie mehr aus der Zweitschrift zurück.
* **Befehlsbremse:** Derselbe Sollwert (modus, reserve, einspeisung,
  einspeisegrenze, notstromreserve, pvlimit, hauslast) je Anlage innerhalb von
  60 s wird nicht erneut gesendet; die Antwort ist `UNVERAENDERT=1` mit HTTP 200
  statt bisher einer Wiederholung oder HTTP 500. Die Schreibbremse bleibt.
* **Nach einer Beanstandung wird nichts gespeichert:** Benutzername und Land werden
  nicht mehr still gekürzt, ein Passwort als Liste wird beanstandet.
* Vorlagenkopf mit Umlaut („überschreibt“).

## Version 0.9.23

Verbesserungen aus dem Durchgang vom 30.09.2026 (Verbesserungsliste
`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`). Gemessen an Attrappen
für Anker-Cloud und Broker unter PHP 7.4, 8.3 und 8.5; nicht am Gerät.

* **Ein Neustart während einer Cloud-Störung behält Anlagen und Geräte.** Bis
  0.9.22 war die Liste danach leer, und der Endpunkt antwortete
  `ANLAGE_UNBEKANNT`. Jetzt gibt es die bekannten Werte mit `OK=0` und ihrem Alter.
  Die Deinstallation räumt alle retained Themen ab (vorher nur eines).
* **Abrufbremse:** `aktion=abruf` höchstens alle 30 s, sonst HTTP 429 mit
  `WARTEN_S`. Der Knopf im Reiter Test geht durch dieselbe Bremse. Ist der
  Merker nicht nutzbar, antwortet der Endpunkt mit 503, statt ungebremst
  weiterzumachen.
* Der Endpunkt protokolliert Abweisungen und schaltende Befehle mit Absender,
  gebremst und mit Zähler, nie mit dem Token.
* Parameter als Liste (`?token[]=…`, `aktion[]=…`) werden sauber mit 403 bzw.
  400 abgewiesen. Unter PHP 8.5 mit eingeschalteter Fehleranzeige kam bis 0.9.22
  HTTP 200 mit PHP-Warnungen; geschaltet wurde auch damals nichts.
* Die Prüfzeilen „Themenliste gegen Sendecode“ und „Suchmuster eindeutig“
  messen jetzt wirklich am Code, statt nur Dateien zu vergleichen.
* Die Kommentare in den Loxone-Vorlagen sind höchstens 40 Zeichen lang, damit
  die Kachelnamen nicht abgeschnitten werden.
* Nach einer Beanstandung stehen die eingetippten Werte wieder im Formular, das
  Feld ist rot umrandet; das Passwort kommt nie zurück. „Einstellungen sichern“
  warnt gelb, wenn die Sicherung beim Zurückspielen abgewiesen würde.

## Version 0.9.22 — Zustände bleiben im Broker, OK merkt einen stehenden Dienst

- **Zustände gehen retained hinaus.** Anlagenzahl, Anlagenname, Betriebsart, Sollwerte, Reserve, Einspeiseschalter
  und -grenze, Firmware und die Erreichbarkeit eines Geräts (`online`) bleiben im Broker stehen; nach einem Neustart von
  Miniserver oder Gateway sind sie sofort wieder da. Messwerte, Tages- und Zählerwerte, Prognose, `ok`, `ts` und `fehler`
  gehen nie retained hinaus. Welche Themen retained sind, zeigt der Reiter MQTT in einer eigenen Spalte; der Reiter Test
  prüft die Tabelle. Bis 0.9.21 ging kein Thema retained hinaus, obwohl der Hinweis das behauptete.
- **Die Deinstallation räumt die zurückbehaltenen Themen ab** (nur unter dem eingestellten Präfix, nur wenn MQTT
  eingeschaltet war; drei Durchgänge, weil der UDP-Eingang des Gateways unter Last Pakete verwirft — bestätigt wird das
  Abräumen nicht).
- **`OK` am Endpunkt ist 0, sobald `ALTER` das Dreifache des Abruftakts übersteigt.** Bisher blieb `OK=1` stehen, auch
  wenn der Dienst seit Stunden nicht mehr lief. `ALTER` ist unverändert. Der Reiter Test benutzt dieselbe Grenze.
- `online` wird nicht mehr als 0 gemeldet, wenn die Cloud den Wert gar nicht liefert; er wird dann nicht gesendet.

**Für bestehende Anlagen:** In Loxone muss nichts geändert werden. Wer den Anzeigenamen der OK-Eingänge in einer neuen
Vorlage sehen will, importiert neu (Loxone Config legt dann neue Bausteine an und überschreibt nichts).

## Version 0.9.21 — volle Karte, Sicherung, Zähler und ein Neustart jede Minute

Durchsicht vom 29.09.2026 mit vier Prüfern (Code, Oberfläche, Installer, MQTT); jeder Punkt ist gemessen
und hat eine Gegenprobe, die an 0.9.20 rot und an 0.9.21 grün ist.

- **Bei voller Speicherkarte gingen alle Einstellungen verloren.** Ein abgeschnitten geschriebener Stand
  galt als Erfolg; danach waren Konfiguration und Zweitschrift unlesbar, das Aktionstoken neu gewürfelt
  (jede Adresse im Miniserver antwortete 403) und das Anker-Passwort leer. Jetzt gilt nur, was ganz
  geschrieben ist und sich so zurücklesen lässt; die Zweitschrift wird erst danach erneuert.
- **Konfiguration und Zweitschrift sind nur noch für das Plugin lesbar (0600).** Beide trugen das
  Aktionstoken und standen mit 0644 da; das Update berichtigt bestehende Anlagen.
- **Das Zurückspielen einer Sicherung prüft jeden Wert** wie das Formular: Typ, Grenzen, Themen-Präfix,
  Aktionstoken. Bisher wurde ein Token als Liste zu `Array` und ein leeres Token still neu gewürfelt. Die
  Sicherung trägt jetzt auch die Anker-Zugangsdaten (wie der Hinweis am Knopf immer schon sagte) und einen
  lesbaren Kopf.
- **Zähler fallen nicht mehr.** Die Energiezähler waren die Summe des Aufbewahrungsfensters und fielen,
  wenn ein Tag herausfiel, ein Tageswert berichtigt oder das Fenster verkleinert wurde — ein Zähler-Baustein
  in Loxone rechnete daraus negative Energie. Jetzt wird nur das Plus fortgeschrieben; bestehende Zähler
  springen nicht.
- **`ALTER` vor dem ersten gelungenen Abruf ist -1**, nicht die Uhrzeit in Sekunden seit 1970, und `OK` ist
  dann 0.
- **Kein Neustart jede Minute ohne Zugangsdaten.** „Dienst starten" vor dem Eintragen der Zugangsdaten
  setzte den Sollmerker, und der Wächter versuchte es jede Minute. Außerdem verhindert eine Startsperre, dass
  Knopf und Wächter gleichzeitig zwei Dienste starten.
- **MQTT:** `anlageN/prognose` wurde nie gesendet (falscher Schlüssel), jetzt schon. Mit „nur Änderungen
  senden" geht alle 30 Minuten der volle Satz hinaus. Ein leerer Text geht als `-` hinaus, nicht leer. Ein
  ungültiges Themen-Präfix wird abgewiesen, statt still gesäubert oder durch `ankersolix` ersetzt zu werden.
- **Die Oberfläche leitet nach jedem Absenden um.** F5 schickte bisher den letzten Knopf noch einmal, etwa
  einen zweiten Schaltbefehl an die Solarbank. Die Meldung reist als Einmalmeldung und erscheint genau
  einmal. Die Zugangsdaten werden nur noch gespeichert, wenn auch alle übrigen Felder gültig sind.
- **Installation und Deinstallation:** eine Neuinstallation spielt keine Zweitschriften einer früheren
  Installation mehr ein (sie werden beiseitegelegt und genannt); das Upgrade meldet den Dienst genau einmal;
  die Deinstallation hält den Dienst an, bevor sie auf Eigenverbrauch zurückstellt, bricht eine hängende
  Rückstellung nach 120 s mit Meldung ab und erkennt `steuerung_ein` auch als `true`.
- **Die Loxone-Vorlage** trägt Einheit, Grenzen und den Kopf, den Loxone Config selbst schreibt; die Titel
  der Eingänge sind unverändert.
- PHP 8.5 meldet keine Verfallswarnungen mehr.

**Für bestehende Anlagen:** Wer die Eingangsvorlage neu importiert, bekommt neue Bausteine neben den alten
(Loxone Config überschreibt nichts); die Titel sind gleich geblieben, nur Einheit und Anzeigename sind neu.

## Version 0.9.20 — die Kachel „MQTT" zeigt das Plugin

- **Die Kachel „MQTT" zeigt jetzt, ob dieses Plugin veröffentlicht.** Bis 0.9.19
  stand dort als großer Wert der Autostart des MQTT-Gateways von LoxBerry, und
  „MQTT ein" las sich, als sende das Plugin — auch wenn es im Reiter MQTT
  ausgeschaltet war. Der Autostart des Gateways steht jetzt klein darunter;
  fehlt der MQTT-Abschnitt in der LoxBerry-Konfiguration, heißt er dort
  „nicht feststellbar" statt „aus".
- **Nach einem Upgrade verlangt das Installationsprotokoll die Zugangsdaten
  nicht mehr neu.** Bis 0.9.19 endete es jedes Mal mit „Anker-Zugangsdaten
  eintragen", auch wenn sie gerade zurückgespielt worden waren; jetzt steht die
  Anleitung nur noch, wenn `zugang.json` danach kein Passwort trägt
  (Erstinstallation oder gescheiterte Rückholung), sonst „Aktualisierung
  abgeschlossen, Einstellungen übernommen".

## Version 0.9.19 — die Sicherung wird nach Inhalt beurteilt, und das Plugin schreibt nur aus der Installation

Gemessen am 18.09.2026 in einem Wegwerfbaum unter Linux (81 Fälle, Prüfstand
`Pruefung-AnkerSolix-0.9.19`; am veröffentlichten Stand 0.9.18 waren 34 davon
rot, jetzt keiner).

**Sichern und Zurückspielen.** Ob eine Datei „Inhalt" trägt, entschieden die
Hakenskripte bisher danach, ob irgendwo ein Anführungszeichen steht. Eine
abgeschnittene Datei hat eines, ein `{"email":"","passwort":""}` ebenso.

- `postinstall.sh` spielte die heile Sicherung deshalb nicht zurück, wenn
  `ankersolix.json` oder `zugang.json` abgeschnitten war oder kein Passwort
  trug. Jetzt heißt Inhalt: ein lesbares JSON-Objekt **mit** Aktionstoken
  bzw. **mit** Passwort. Zurückgespielt wird nur eine Sicherung, die selbst
  Inhalt trägt; ein verdrängter Stand bleibt als `<datei>.kaputt` (0600)
  liegen. Eine Sicherung, die nur `{}` enthält, wird nicht mehr als
  „wiederhergestellt" gemeldet.
- `preupgrade.sh` kopierte ungeprüft **direkt auf** die Sicherung. Eine
  abgeschnittene oder passwortlose Datei verdrängte so die heile Sicherung,
  und ein beim Schreiben abgebrochenes `cp` (volle Karte) hinterließ weder die
  alte noch eine vollständige neue. Jetzt wird nur ein Stand mit Inhalt
  gesichert, über eine Nebendatei, die nachgelesen und dann umbenannt wird.
- `preupgrade.sh` meldet, was geschah: „angehalten" nur, wenn `dienst.sh stop`
  das sagt; die Zusage „wird nach dem Upgrade wieder gestartet" nur, wenn der
  Merker dafür wirklich liegt; die Schlusszeile ist eine Warnung, sobald eine
  Sicherung nicht geschrieben werden konnte.

**Wurzel und Ordnername.** `bin/dienst.sh` rechnete die LoxBerry-Wurzel aus
dem eigenen Ablageort und legte bei **jedem** Aufruf, auch bei `status`, den
Daten- und den Logordner an. Aus einem Prüfarchiv unter
`<Wurzel>/pruefung/ankersolix/bin` entstanden so in der laufenden Anlage die
Ordner `data/plugins/bin` und `log/plugins/bin`; aus einer Kopie des ganzen
Baums lief ein zweiter Dienst am selben Anker-Konto an. Jetzt wird die Wurzel
aus `$LBHOMEDIR` gelesen (sonst aufwärts gesucht, erst zuletzt gerechnet),
angelegt wird nur beim Start, und `start`, `stop`, `restart` und `waechter`
arbeiten nur, wenn das Skript unter `<Wurzel>/bin/plugins/<ordner>` liegt.
`bin/ankersolix.py` hatte dieselbe Rechnung (`SELF.parents[2]`) und ist
ebenso umgestellt; `--selbsttest` läuft weiterhin überall, legt außerhalb der
Installation aber nichts an.

**Zweitinstallation.** Liegt eine zweite Installation unter einem anderen
Ordnernamen (etwa `ankersolix01`) und fehlt ihr Konfigurationsordner — genau
das ist in der Lücke einer Aktualisierung der Fall —, fiel die Oberfläche auf
den Ordner `ankersolix` zurück. Die Sperre während der Aktualisierung sah
dann auf die fremde Marke, und ein Speichern schrieb in die Zugangsdaten des
**anderen** Plugins (die E-Mail des Anker-Kontos war danach leer). In der
Installationslage gilt jetzt allein der eigene Ordnername.

## Version 0.9.18 — während einer Aktualisierung kostete ein Klick das Anker-Passwort

Zwischen `preupgrade.sh` und `postinstall.sh` liegt eine Lücke von rund einer
Minute. In dieser Zeit hat der Installer `config/plugins/ankersolix/` bereits
abgeräumt, die Oberfläche ist aber erreichbar, und der Minutentakt läuft weiter.
Was das für dieses Plugin bedeutet, ist am 18.09.2026 in einem Wegwerfbaum unter
Linux gemessen worden (66 Fälle, Prüfstand `Pruefung-AnkerSolix-0.9.18`):

- Die Oberfläche zeigte in dieser Zeit **zwei leere Kontofelder** an, weil die
  Zugangsdatei gerade gelöscht war. Wer dort auf *Einstellungen speichern*
  drückte, schrieb eine `zugang.json` **ohne Passwort** — und `postinstall.sh`
  spielte die Sicherung danach nicht mehr ein, weil die Datei ja „Inhalt" hatte.
  Das Anker-Kontopasswort war nach dem Update fort (Fall L4d).
- Direkt danach ließ sich der Dienst über den Knopf *Dienst starten* mitten in
  der Aktualisierung anwerfen — mit ebendieser leeren Zugangsdatei (Fall L5b).
- Dasselbe Speichern **außerhalb** der Lücke ist unauffällig (Fall G8c). Der
  Schaden hängt an der Lücke, nicht am Knopf.

`preupgrade.sh` legt deshalb jetzt als **Erstes** die Marke
`data/plugins/ankersolix.upgrade_laeuft` mit der Unixzeit an — neben dem
Datenordner, weil der Installer den Ordner selbst löscht. Solange sie gilt,

- startet `bin/dienst.sh` den Dienst nicht und meldet den Grund,
- zeigt die Oberfläche nur einen Hinweis und nimmt nichts entgegen.

Eine Marke, die älter als eine Stunde ist, aus der Zukunft stammt oder keine
lesbare Zeit enthält, gilt **nicht** — eine abgebrochene Installation darf das
Plugin nicht dauerhaft stilllegen. Lässt sich die Uhr nicht lesen, gilt sie
dagegen sehr wohl: ein Schutz fällt geschlossen aus. `postinstall.sh` entfernt
sie über einen `trap`, also auch nach einem Abbruch, und startet den Dienst als
letzten Schritt ausdrücklich mit `--trotz-marke`; dadurch kann der Minutentakt
in genau dieser Sekunde keinen zweiten Dienst danebenstellen (Fall G7a: 200
Wächterläufe während der Installation, danach genau ein Dienst).

`uninstall/uninstall` räumt die Marke weg und dazu den Merker
`config/plugins/ankersolix.backup.lief`, der bisher liegenblieb und eine spätere
**Neu**installation den Dienst hätte anwerfen lassen.

Der unangemeldete Endpunkt für den Miniserver sperrt **nicht**: er weist
schreibende Befehle ohnehin ab, solange kein Dienst läuft, und ein zusätzlich
abgewiesener Befehl ginge still verloren — ein Virtueller Ausgang wertet die
Antwort nicht aus (Fall L6b).

Im Reiter *Test* steht eine neue Zeile: liegt eine Marke, und wie alt ist sie?
Sie ist nur zu sehen, wenn die Marke nicht mehr gilt — sonst hält die Seite
schon am Eingang an.

Geändert: `preupgrade.sh`, `postinstall.sh`, `uninstall/uninstall`,
`bin/dienst.sh`, `webfrontend/html/ak_lib.php`,
`webfrontend/htmlauth/index.php`, `webfrontend/htmlauth/ak_test.php` und beide
Sprachdateien. Die argumentweise Diensterkennung (0.9.17) und die Rückstellung
der Anlage auf Eigenverbrauch beim Deinstallieren (0.9.16) sind unverändert und
mitgemessen.

## Version 0.9.17 — „angehalten" traf den Falschen und ließ den Richtigen stehen

Das Plugin erkannte seinen eigenen Dienst an einer **Teilzeichenkette** der
Befehlszeile (`grep -qa "ankersolix.py" /proc/<nummer>/cmdline`). Das trifft
jeden Prozess, in dem der Name irgendwo vorkommt — einen Editor mit der Datei
offen, ein Sicherungsskript, das den Ordner durchsucht, den Einmallauf der
eigenen Oberfläche. Und es sah immer nur **eine** Nummer an, nämlich die aus
`data/plugins/ankersolix/dienst.pid`.

Beides ist am 18.09.2026 in einem Wegwerfbaum unter Linux gemessen worden:

- Stand die Nummer eines **fremden** Prozesses in der PID-Datei, meldete
  `dienst.sh status` „laeuft", und `dienst.sh stop` beendete ihn. Dieselbe
  Stelle steckte im Rückfallweg von `preupgrade.sh`, in `uninstall/uninstall`
  und in der Kachel „Dienst läuft" der Oberfläche.
- Liefen **zwei** eigene Dienste — einer ohne PID-Datei, wie ihn der
  Minutenwächter starten kann, während der Installer den Datenordner gerade
  abgeräumt hat —, dann meldete `stop` „angehalten", und einer lief weiter.

Ein Prozess gilt jetzt nur dann als eigener Dienst, wenn seine Befehlszeile aus
**genau zwei** Argumenten besteht: einem Python-Interpreter und dem vollen Pfad
der eigenen `ankersolix.py`. Die Einmalläufe (`--einmal`, `--selbsttest`,
`--vorgaben`, `--freigeben`) haben ein drittes Argument und werden dadurch nicht
mehr getroffen. Gesucht wird zusätzlich über `/proc`, begrenzt auf den Benutzer
des Dienstes, damit auch ein Dienst ohne PID-Datei mitgeht; vor **jedem** Signal
— auch vor dem harten — wird neu nachgesehen, weil Prozessnummern
wiederverwendet werden. `stop` sagt am Ende nicht mehr „angehalten", weil es
etwas geschickt hat, sondern weil danach keiner mehr läuft; sonst nennt es die
Nummern, die stehen geblieben sind.

Geändert: `bin/dienst.sh`, `preupgrade.sh`, `uninstall/uninstall` und
`webfrontend/html/ak_lib.php`. Die Rückstellung der Anlage auf Eigenverbrauch
beim Deinstallieren (0.9.16) ist unverändert.

## Version 0.9.16 — die Deinstallation stellte die Anlage nicht zurück

Der LoxBerry-Installer legt das Deinstallationsskript unter
`data/system/uninstall/ankersolix` ab und übergibt ihm Ordnernamen und
LoxBerry-Wurzel als Argumente. Das Skript leitete den Ordnernamen aber aus
seinem eigenen Ablageort ab und kam dort auf `system` statt `ankersolix`.
Damit lief bei einer Deinstallation **nichts** von dem, wofür es da ist:

- die Rückstellung der Solarbank auf Eigenverbrauch entfiel still, weil die
  Konfiguration nicht gefunden wurde — ein gesetzter Hauslast-Sollwert wäre
  stehen geblieben;
- der Dienst wurde nicht angehalten;
- die Sicherungen neben dem Konfigurationsordner blieben liegen, darunter
  `ankersolix.backup.zugang.json` mit dem Kontopasswort im Klartext.

Ordnername und Wurzel kommen jetzt aus den Argumenten des Installers. Gefunden
am Govee-Plugin, dort am Gerät nachgerechnet; hier im Prüfstand so aufgerufen
wie vom Installer nachgemessen.

Die fünf Symbole tragen wieder die Fassung aus 0.9.15: in 0.9.16 war ein
Herkunftsvermerk (C2PA) eingebettet, die Bildpunkte waren unverändert.

## Version 0.9.15 — die Bibliothek war nie ladbar

**Das Plugin konnte in keiner Fassung vor dieser einen einzigen Wert holen.**
Die Installation vom 11.09.2026 hat es gezeigt: `<FAIL> anker-solix-api ist
installiert, laesst sich aber nicht laden.` Zwei Fehler stecken darin, und
jeder allein hätte genügt.

**Erstens der Name.** Die Verteilung heißt `anker-solix-api`, der Paketordner
darin heißt schlicht `api`. Das Plugin schrieb an drei Stellen
`from anker_solix_api.api import AnkerSolixApi` — ein Name, den es in keiner
Fassung der Bibliothek gab. Nachgemessen in der `top_level.txt` der
`dist-info` am Gerät.

**Zweitens die Abhängigkeiten.** `anker-solix-api` bringt sie nicht mit. Die
`pyproject.toml` führt sie unter `[tool.poetry.dependencies]` mit
`package-mode = false` und setzt `dynamic = ["dependencies"]`; gebaut wird das
Paket aber von setuptools, das den Poetry-Abschnitt nicht kennt. Das fertige
Wheel trägt **keine einzige** `Requires-Dist`-Zeile. `pip install git+…` meldet
darum Erfolg und spielt nur den nackten Paketordner ein. Die erste Zeile von
`api/api.py` lautet `from aiohttp import ClientSession` — und aiohttp war nie
da. `postinstall.sh` installiert jetzt die fünf von der Bibliothek erklärten
Pakete (aiohttp, aiofiles, cryptography, paho-mqtt, python-dotenv) mit den
Untergrenzen aus ihrer eigenen `pyproject.toml`.

**Die Ursache stand hinter `2>/dev/null`.** Die Ladeprüfung verwarf die
Meldung von Python und sagte nur, es gehe nicht. Der eigentliche Satz —
`ModuleNotFoundError: No module named 'aiohttp'` — hätte den Fehler sofort
benannt. Er steht jetzt im Installationsprotokoll, eingerückt unter `<FAIL>`.
Ebenso steht dort, welche Fassungen der Abhängigkeiten tatsächlich eingespielt
wurden: festgenagelt sind sie nicht, sonst gäbe es auf einer Architektur ohne
fertiges Wheel keinen Weg mehr.

`api` ist ein sehr gewöhnlicher Paketname, und der Ordner des Dienstskripts
steht als erster im Suchweg. Legt jemand eine `api.py` daneben, verdeckt sie
die Bibliothek lautlos. Der Selbsttest meldet deshalb nicht mehr nur, **dass**
geladen wurde, sondern **woher** — und nennt aiohttp mit seiner Fassung.

**Und dahinter lag noch ein Fehlalarm.** Kaum lud die Bibliothek zum ersten
Mal, meldete der Selbsttest zwei Einstellungen als wirkungslos: *Pause
zwischen Anfragen* und *Zeitschranke*. Beide werden zur Laufzeit sehr wohl
gesetzt — `drosselung_setzen()` fragt das fertige Objekt, und dort gibt es
`apisession`. Der Selbsttest fragte die **Klasse**, die den Namen nicht kennt,
fiel deshalb immer auf `object` zurück und fand nichts. Er sieht jetzt dort
nach, wo die beiden Wege wohnen: an `AnkerSolixClientSession`. Zwölf von zwölf
Wegen grün, am Gerät gemessen.

Gegengeprüft in einer eigenen venv am Gerät: mit den fünf Paketen und dem
richtigen Namen lädt `AnkerSolixApi`, alle zwölf geprüften Wege sind
vorhanden. Ohne sie nicht. **Am Verhalten gegenüber einer echten Anlage ändert das nichts — hier
steht weiterhin kein Anker-Gerät zum Messen.**

## Version 0.9.14 — der Dienst kommt nach dem Update von selbst zurück

**Das Plugin stand nach jedem Update still.** `dienst.sh stop` entfernt den
Sollmerker `soll_laufen`, der Installer räumt gleich darauf den ganzen
Datenordner ab, und `postinstall.sh` rief an keiner Stelle `start`. Der
minütliche Wächter findet ohne Sollmerker nichts zu tun; die Installation
meldete Erfolg, und die Oberfläche zeigte „gestoppt", als hätte der Betreiber
selbst angehalten. Wer das Plugin nach einem Auto-Update für tot hielt, lag
nicht falsch — es war still abgeschaltet.

`preupgrade.sh` merkt sich jetzt **vor** dem Anhalten, ob der Dienst laufen
sollte, und legt den Merker **neben** den Konfigurationsordner (alles darin und
im Datenordner ist nach `purge_installation` weg). `postinstall.sh` startet ihn
danach wieder und sagt es. Denselben Weg gehen Bewässerung seit 0.9.19 und
Weißware seit 0.9.18.

**Und das Protokoll behauptete etwas, das nicht stimmte.** „Laufender Dienst
angehalten." stand bedingungslos da — auch wenn gar keiner lief. `anhalten()`
gibt in diesem Fall „laeuft nicht" und 0 zurück, und die Antwort ging nach
`/dev/null`. Dasselbe im Rückfallweg, wo eine liegengebliebene PID-Datei
genügte. Beides hängt jetzt an der Tatsache statt am Aufruf.

Geprüft mit `Werkzeuge/preupgrade_meldung_pruefen.py`, das `preupgrade.sh` mit
einer Dienst-Attrappe wirklich ausführt: gegen 0.9.14 grün, gegen 0.9.13 rot.
**Am Verhalten des Dienstes selbst ändert sich nichts.**

## Version 0.9.12 — die zehn Steuerbefehle tragen einen Namen

- **Der Reiter Test sagt jetzt, ob die MQTT-Veröffentlichung dieses Plugins
  eingeschaltet ist.** Bis 0.9.11 stand dort nur der Zustand des MQTT-Gateways
  von LoxBerry — das ist eine Aussage über den LoxBerry, nicht über dieses
  Plugin. Wer die Veröffentlichung ausgeschaltet hatte, sah trotzdem einen
  grünen Haken und konnte am Reiter nicht erkennen, dass nichts an den Broker
  geht. Die neue Zeile steht vor der Gateway-Zeile und ist **grau**, wenn
  ausgeschaltet — das ist eine Entscheidung, kein Fehler. Anlass: derselbe
  Befund an BatterieBMS 0.9.17, dort am Gerät gemessen (`Regeln/04`).

Bis 0.9.11 lieferte die Vorlage der Steuerbefehle **zehn Ausgänge ohne
Beschriftung**.

Loxone Config nimmt den `Comment` einer Vorlage als **Anzeigenamen**. Stand
dort nichts, zeigte Config den Titel. Jetzt steht dort ein Name mit
Gerätevorsatz — die Bausteinsuche des Miniservers kennt den Geräteknoten
nicht, und „Automatik" gibt es auch im Batterie-Plugin.

**Die Titel sind unverändert geblieben** — ein geänderter Titel legt beim
erneuten Import neue Ausgänge **neben** die alten. Wer die Vorlage neu
einliest, bekommt dieselben Ausgänge, nur mit Namen.

Der Vorsatz nennt die **Anlagennummer**, weil bei zwei Speichern sonst
zweimal dieselben zehn Namen in der Bausteinsuche stünden:
`Hauslast setzen (W)` → **SOLIX 1: Hauslast setzen (W)**.

Gemessen: 10 von 10 Titeln und Befehlen byteweise wie in 0.9.11, 0 Ausgänge
ohne Beschriftung (vorher 10), längster Anzeigename 35 Zeichen. Übersetzt
wird nichts Neues — die Beschriftung ist derselbe Text wie im Titel. Im Kopf
der Vorlage steht jetzt außerdem, dass Loxone Config beim Import neu anlegt
und nichts überschreibt.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/ankersolix.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.


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
| `…&von=<kennung>` | an jedem Sollwert-Befehl oben optional: Herkunft für die Schreiber-Wache (siehe unten) |

**Ein Strich als Wert** heißt: die Cloud hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber innerhalb der Wartezeit ohne Antwort — also
Ergebnis unbekannt. Ein Erfolg, den niemand geprüft hat, wird nie gemeldet.

Derselbe Sollwert (`hauslast`, `modus`, `reserve`, `einspeisung`,
`einspeisegrenze`, `notstromreserve`, `pvlimit`, je Anlage und Seriennummer)
wird innerhalb von 60 s **nicht erneut gesendet**; die Antwort lautet dann
`SET;OK=1;AKTION=…;UNVERAENDERT=1;SEIT_S=…`. Gemerkt wird nur ein Befehl, den
der Dienst bestätigt hat (`OK=1`). Ein anderer Wert geht sofort hinaus,
Schreibbremse und Schrittweite des Dienstes gelten weiter. Befehle aus dem
Reiter *Test* werden nie unterdrückt.

Liegt ein `hauslast`-Wert nur innerhalb der eingestellten **Schrittweite**
neben dem gesetzten Sollwert, sendet der Dienst ebenfalls nichts. Das ist kein
Fehler: die Antwort lautet `SET;OK=1;AKTION=hauslast;UNVERAENDERT=1;MELDUNG=…`
(HTTP 200, ohne `SEIT_S`), auch während die Schreibbremse greift.

Der **Rückfall** (Reiter *Einstellungen*, ab Werk aus) misst die Zeit seit dem
letzten gültigen Sollwert von Loxone, nicht nur seit dem letzten gesendeten:
auch eine Antwort mit `UNVERAENDERT=1` (gleicher Wert innerhalb 60 s oder
innerhalb der Schrittweite) zählt. Abgewiesene Aufrufe (Token, Parameter,
Grenzen, Schreibbremse, Steuerung aus) zählen nicht, ebenso wenig Reiter *Test*
und Trockenlauf, solange sie nichts senden; ein gesendeter Befehl setzt den
Rückfall wie bisher zurück. Gesendet wird dadurch nichts zusätzlich. Lässt sich
der Merker `sollwert_empfangen` nicht lesen oder schreiben, misst der Rückfall
wie bisher nur am letzten gesendeten Befehl – er greift dann eher zu früh als
nie. Die Zeile *Rückfall* im Reiter *Test* nennt das Alter des Merkers.

### Schreiber-Wache

Jeder Sollwert-Befehl (`hauslast`, `modus`, `reserve`, `einspeisung`,
`einspeisegrenze`, `notstromreserve`, `pvlimit`) darf `&von=<kennung>` tragen
(1 bis 32 Zeichen aus Buchstaben, Ziffern, `_` und `-`); die Loxone-Vorlage
setzt `von=loxone`. Bekannte Kennungen der übrigen Hausplugins sind
`einspeisebremse`, `awattar` und `evcc`. Der Endpunkt merkt sich je Anlage
Kennung und Absenderadresse. Eine ungültige Kennung (auch leer oder als Liste)
wird mit HTTP 400 `SET;OK=0;AKTION=…;GRUND=VON` abgewiesen, und nichts wird
eingereiht; eine Adresse ohne `von` geht immer und erscheint als „ohne
Kennung“. `aktion=abruf` und die lesenden Aufrufe beachten `von` nicht.

* **Melden (ab Werk an):** Schicken innerhalb des Zeitfensters (ab Werk 15 min,
  einstellbar 1–120) mehrere Schreiber Befehle an dieselbe Anlage, steht das
  gebremst im Protokoll, im Reiter *Test* (Tabelle „Schreiber der letzten 24
  Stunden“) und in jeder weiteren Antwort als `;SCHREIBER=n`. Abgewiesen wird
  nichts. Auf Wunsch kommt eine LoxBerry-Meldung dazu, sobald neue Schreiber
  hinzukommen (ab Werk aus).
* **Fremde Schreiber abweisen (ab Werk aus):** Befehle eines Schreibers, der
  nicht in der Liste der erlaubten Schreiber steht, bekommen HTTP 409
  `SET;OK=0;AKTION=…;GRUND=FREMDSCHREIBER`; nichts wird eingereiht, und der
  Rückfall zählt sie nicht als Sollwert von Loxone. Die Liste nennt je Eintrag
  eine Kennung, eine Adresse oder Kennung@Adresse. Die Rücknahme
  `modus=eigenverbrauch` (die Solarbank regelt wieder selbst) wird nie
  abgewiesen. Erst einschalten, wenn der Reiter *Test* eine Woche lang nur die
  erwarteten Schreiber zeigt.
* Lässt sich der Merker (`data/plugins/<ordner>/schreiber_anlage<N>.json`)
  nicht schreiben, geht der Befehl trotzdem weiter; die Antwort trägt dann
  `;WACHE=MERKER`. Gleichwert-Unterdrückung, Schrittweite und Rückfall bleiben,
  wie sie waren. Ein neues MQTT-Thema gibt es nicht. Nach einer Aktualisierung
  beginnt die Wache leer.
* Eine ältere Vorlage ohne `von=loxone` arbeitet weiter. Es genügt, an die
  bestehenden Befehle des virtuellen Ausgangs `&von=loxone` anzuhängen; ein
  zweiter Import legte die Bausteine doppelt an.

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
