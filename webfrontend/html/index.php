<?php
/**
 * Anker SOLIX - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesende Aktionen:
 *   selftest                    Token pruefen, OHNE dass etwas geschieht
 *   status   [&anlage=N]        Leistungswerte der Anlage
 *   energie  [&anlage=N][&zeitraum=tag|monat|jahr]
 *   geraet   &sn=<Seriennummer> Werte eines einzelnen Geraets
 *   anlagen                     Liste der erkannten Anlagen
 *   roh                         umgesetztes Abbild als JSON (Fehlersuche)
 *
 * Schaltende Aktionen (nur wenn im Reiter Einstellungen zugelassen):
 *   hauslast        &watt=<W>      [&anlage=N][&sn=..]
 *   modus           &wert=<Modus>  [&anlage=N][&sn=..]
 *   reserve         &prozent=<%>   [&anlage=N][&sn=..]
 *   einspeisung     &wert=ein|aus  [&anlage=N][&sn=..]
 *   einspeisegrenze &watt=<W>      [&anlage=N][&sn=..]
 *   notstromreserve &prozent=<%>   [&anlage=N][&sn=..]
 *   pvlimit         &watt=<W>      &sn=<Seriennummer>
 *   abruf                          sofortiger Abruf statt Warten auf den Takt,
 *                                  hoechstens alle 30 s (sonst 429 mit WARTEN_S)
 *
 * Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026): hauslast, modus,
 * reserve, einspeisung, einspeisegrenze, notstromreserve und pvlimit mit
 * DEMSELBEN Wert (je Anlage und sn) innerhalb von 60 s werden nicht erneut
 * eingereiht: HTTP 200, SET;OK=1;AKTION=..;UNVERAENDERT=1;SEIT_S=n. Ein
 * anderer Wert geht sofort hinaus (kein 429). Ohne nutzbaren Merker: 503
 * GRUND=GLEICHWERT_MERKER, nichts eingereiht.
 *
 * Schrittweite (Entscheidung Nr. 21, 01.10.2026): eine hauslast, die nur
 * innerhalb der Schrittweite neben dem gesetzten Sollwert liegt, sendet der
 * Dienst nicht; die Antwort ist HTTP 200, SET;OK=1;AKTION=hauslast;
 * UNVERAENDERT=1;MELDUNG=.. (ohne SEIT_S). Bis 0.9.24 OK=0 mit HTTP 500.
 *
 * Rueckfall (Entscheidung Nr. 22, 01.10.2026): jeder angenommene Sollwert -
 * gesendet oder UNVERAENDERT=1 - setzt den Merker data/sollwert_empfangen,
 * an dem der Dienst den Rueckfall misst (ak_sollwert_empfangen_vermerken()).
 * Abgewiesene Aufrufe (Token, Parameter, Steuerung aus, OK=0) setzen ihn nicht.
 *
 * Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25, 02.10.2026):
 *   von=<kennung>  an einem Sollwert-Befehl (hauslast, modus, reserve,
 *               einspeisung, einspeisegrenze, notstromreserve, pvlimit), optional
 *               (die Vorlage setzt von=loxone); gemerkt wird Kennung@Absender je
 *               Anlage. Eine ungueltige Kennung: HTTP 400 GRUND=VON, nichts
 *               eingereiht. Mehr als ein Schreiber im Fenster (ab Werk 15 min):
 *               Protokoll, Reiter Test, Antwort ;SCHREIBER=n - abgewiesen wird
 *               nichts. Nur mit "Fremde Schreiber abweisen" (ab Werk aus) bekommt
 *               ein nicht erlaubter Schreiber HTTP 409 GRUND=FREMDSCHREIBER, und
 *               nichts wird eingereiht; modus=eigenverbrauch (Ruecknahme) nie.
 *               Merker nicht nutzbar: der Befehl geht trotzdem, ;WACHE=MERKER.
 *               Lesende Aktionen und abruf beachten von nicht.
 *
 * Der Endpunkt spricht NIE selbst mit der Anker-Cloud. Lesende Aktionen
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: die Cloud hat dieses Feld nicht geliefert.
 * Es wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 *
 * Den VOLLSTAENDIGEN Zwischenspeicher (cache.json, mit den echten Feldnamen
 * der Cloud) gibt dieser Endpunkt NICHT heraus: dort steht die Kontokennung.
 * Er ist nur im angemeldeten Bereich zu sehen, Reiter Test.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/ak_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/* Lesen, nicht anlegen.
 *
 * Wer sich nicht ausweisen kann, legt nichts an - auch nichts Harmloses. Bis
 * 0.9.6 stand hier ak_config(), und die legte bei jedem Aufruf Verzeichnisse
 * an und spielte die Sicherung zurueck, noch BEVOR das Token geprueft war. */
$ak_cfg = ak_config(false);
$ak_p = ak_paths();

/* ---------------- Token ---------------- */
$ak_soll = (string) $ak_cfg['aktionstoken'];
// Nur Zeichenketten zaehlen (Nachtrag 30.09.2026): aus ?token[]=x machte
// (string) bis 0.9.23 "Array" samt PHP-Warnung; bei display_errors=1 kam die
// Abweisung dann mit HTTP 200. Eine Liste gilt als fehlendes Token (403)
// bzw. als unbekannte Aktion (400).
$ak_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
$ak_aktion = isset($_GET['aktion']) ? (is_string($_GET['aktion']) ? $_GET['aktion'] : '') : 'status';
// Fuer die Protokollzeile (a4): nur Kleinbuchstaben, hoechstens 20 Zeichen.
$ak_aktion_log = substr(preg_replace('/[^a-z]/', '', strtolower($ak_aktion)), 0, 20);

/* selftest steht unmittelbar hinter der Token-Pruefung: die Pruefung greift,
 * die Wirkung nicht. Ein Token muss sich pruefen lassen, ohne dass etwas
 * geschieht - sonst bleiben nur zwei schlechte Wege: entweder man schaltet
 * wirklich, dann faehrt der Speicher um, oder man erfaehrt nie, ob die
 * Adresse im Miniserver noch stimmt. */
if ($ak_soll === '') {
    ak_endpunkt_log('abweisung', 'kein Token eingerichtet, Aktion ' . $ak_aktion_log);
    http_response_code(403);
    if ($ak_aktion === 'selftest') {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($ak_soll, $ak_ist)) {
    ak_endpunkt_log('abweisung', ($ak_ist === '' ? 'ohne Token' : 'falsches Token')
        . ', Aktion ' . $ak_aktion_log);
    http_response_code(403);
    if ($ak_aktion === 'selftest') {
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}
if ($ak_aktion === 'selftest') {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$ak_lesend = array('status', 'energie', 'geraet', 'anlagen', 'roh');
$ak_schaltend = array('hauslast', 'modus', 'reserve', 'abruf',
                      'einspeisung', 'einspeisegrenze', 'notstromreserve', 'pvlimit');
if (!in_array($ak_aktion, array_merge($ak_lesend, $ak_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: selftest, ' . implode(', ', array_merge($ak_lesend, $ak_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter pruefen ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen - ein still veraenderter Wert fuehrt zu einer
 * Anlage, die etwas anderes tut, als die Adresse sagt.
 */
function ak_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    // Eine Liste (?name[]=...) ist kein Wert - abweisen, nicht umwandeln.
    if (!is_string($_GET[$name])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " ist keine einzelne Angabe.\n";
        exit;
    }
    $w = $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

$ak_anlage   = ak_param('anlage', '/^[0-9]{1,2}$/', '1');
$ak_sn       = ak_param('sn', '/^[A-Za-z0-9]{1,32}$/', '');
$ak_watt     = ak_param('watt', '/^-?[0-9]{1,5}$/', '');
$ak_prozent  = ak_param('prozent', '/^[0-9]{1,3}$/', '');
$ak_wert     = ak_param('wert', '/^[a-z]{1,20}$/', '');
$ak_zeitraum = ak_param('zeitraum', '/^(tag|monat|jahr)$/', 'tag');

/* Schreiber-Wache (Energie-1 C1): &von= lesen, nur an den Sollwert-Befehlen, die die
 * Wache sieht. Fehlt es: '' (ohne Kennung). Eine Kennung, die nicht ins Muster
 * passt (1..32 aus A-Z a-z 0-9 _ -, auch leer oder als Liste), wird abgewiesen wie
 * ein falscher Wert - abweisen statt zurechtbiegen (Nr. 19); ein Tippfehler faellt
 * beim Einrichten auf. Eine Adresse OHNE von geht immer. Lesende Aktionen und
 * abruf bleiben, wie sie waren. */
$ak_von = '';
if (in_array($ak_aktion, ak_wache_aktionen(), true) && isset($_GET['von'])) {
    $ak_von = is_string($_GET['von']) ? (string) $_GET['von'] : '';
    if (!ak_wache_kennung_gueltig($ak_von)) {
        http_response_code(400);
        echo 'SET;OK=0;AKTION=' . $ak_aktion . ";GRUND=VON;ERLAUBT=A-Z,a-z,0-9,_,-;LAENGE=1..32\n";
        echo "Der Wert von von passt nicht ins erlaubte Muster (1 bis 32 Zeichen aus Buchstaben, Ziffern, _ und -).\n";
        exit;
    }
}

/* ---------------- Hilfsausgabe ---------------- */
function ak_w($v)
{
    // Ein Strich statt einer erfundenen 0. Loxone behaelt dann den letzten
    // Wert - genau das ist bei einem fehlenden Messwert richtig.
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

/**
 * Eine Antwortzeile aus der Feldliste bauen.
 *
 * Die Zeile und die Loxone-Vorlage entstehen aus DERSELBEN Liste
 * (ak_felder_zeile). Zwei Stellen, die dasselbe zusammensetzen, laufen
 * auseinander - und dann steht in der Importdatei ein Suchmuster fuer ein
 * Feld, das die Zeile gar nicht traegt.
 *
 * Felder mit 'zeile' => 0 (Namen, Datumsangaben) kommen hier nie vor: ein
 * Text mit ';' oder '=' zerlegt die Zeile, die Loxone mit einer
 * Befehlserkennung liest, und der Miniserver sieht nur noch den Anfang.
 */
function ak_zeile($kennung, $satz, $werte)
{
    $out = $kennung;
    foreach (ak_felder_zeile($satz) as $feld => $info) {
        $out .= ';' . $feld . '=' . ak_w(isset($werte[$feld]) ? $werte[$feld] : null);
    }
    return $out . "\n";
}

$ak_lox = ak_loxone();
$ak_alter = ak_alter();
// OK=0 auch dann, wenn der letzte Erfolg laenger zurueckliegt als drei Takte
// (Entscheidung des Hausherrn 29.09.2026): ein Dienst, der nicht mehr
// abruft, liess bis 0.9.21 OK=1 stehen. ALTER bleibt unveraendert daneben.
$ak_ok = (!empty($ak_lox['ok']) && $ak_alter >= 0 && $ak_alter <= ak_ok_grenze($ak_cfg)) ? 1 : 0;

/* ================= Lesende Aktionen ================= */

if ($ak_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($ak_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($ak_aktion === 'anlagen') {
    $a = ak_anlagen();
    echo 'ANLAGEN;OK=' . $ak_ok . ';N=' . count($a) . ';ALTER=' . $ak_alter . "\n";
    foreach ($a as $nr => $an) {
        // Der Anlagenname ist ein Textfeld aus einer fremden App. Ein ';' oder
        // '=' darin zerlegte bis 0.9.6 die Zeile, die Loxone auswertet.
        $name = str_replace(array(';', '=', "\r", "\n"), ' ', (string) (isset($an['name']) ? $an['name'] : ''));
        echo $nr . ';' . trim($name) . ';'
           . (isset($an['site_id']) ? $an['site_id'] : '') . ';'
           . 'Geraete=' . (isset($an['anzahl_geraete']) ? (int) $an['anzahl_geraete'] : 0) . "\n";
    }
    exit;
}

if ($ak_aktion === 'geraet') {
    if ($ak_sn === '') {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=SN_FEHLT\n";
        exit;
    }
    $g = ak_geraete();
    if (!isset($g[$ak_sn])) {
        echo 'GERAET;OK=0;GRUND=UNBEKANNT;ALTER=' . $ak_alter . "\n";
        exit;
    }
    $d = $g[$ak_sn];
    echo ak_zeile('GERAET', 'geraet', array(
        'OK'       => $ak_ok,
        'SOC'      => $d['soc'],
        'PV'       => $d['pv'],
        'AUSGANG'  => $d['ausgang'],
        'LADEN'    => $d['laden'],
        'SOLL'     => $d['sollwert'],
        'ONLINE'   => $d['online'],
        'WLAN'     => $d['wlan'],
        'LEISTUNG' => $d['leistung'],
        'ALTER'    => $ak_alter,
    ));
    exit;
}

$ak_alle = ak_anlagen();
$ak_an = isset($ak_alle[$ak_anlage]) ? $ak_alle[$ak_anlage] : null;

if ($ak_aktion === 'status') {
    if ($ak_an === null) {
        printf("ANKER;OK=0;GRUND=ANLAGE_UNBEKANNT;N=%d;ALTER=%d\n", count($ak_alle), $ak_alter);
        exit;
    }
    echo ak_zeile('ANKER', 'status', array(
        'OK'          => $ak_ok,
        'SOC'         => $ak_an['soc'],
        'PV'          => $ak_an['pv'],
        'LADEN'       => $ak_an['laden'],
        'ENTLADEN'    => $ak_an['entladen'],
        'BATP'        => $ak_an['batp'],
        'AUSGANG'     => $ak_an['ausgang'],
        'HAUS'        => $ak_an['haus'],
        'NETZBEZUG'   => $ak_an['netzbezug'],
        'NETZEINSP'   => $ak_an['netzeinspeisung'],
        'SOLL'        => $ak_an['sollwert'],
        'MODUS'       => $ak_an['modus'],
        'RESERVE'     => isset($ak_an['reserve']) ? $ak_an['reserve'] : null,
        'EINSPEISUNG' => isset($ak_an['einspeisung']) ? $ak_an['einspeisung'] : null,
        'GRENZE'      => isset($ak_an['einspeisegrenze']) ? $ak_an['einspeisegrenze'] : null,
        'PROGNOSE'    => isset($ak_an['prognose_rest']) ? $ak_an['prognose_rest'] : null,
        'GERAETE'     => (int) $ak_an['anzahl_geraete'],
        'ALTER'       => $ak_alter,
    ));
    exit;
}

if ($ak_aktion === 'energie') {
    if ($ak_an === null) {
        printf("ENERGIE;OK=0;GRUND=ANLAGE_UNBEKANNT;ALTER=%d\n", $ak_alter);
        exit;
    }
    $en = isset($ak_an['energie']) && is_array($ak_an['energie']) ? $ak_an['energie'] : array();
    $za = isset($ak_an['zaehler']) && is_array($ak_an['zaehler']) ? $ak_an['zaehler'] : array();

    // Monat und Jahr kommen aus der eigenen Tagesaufzeichnung, nicht aus der
    // Cloud: die liefert nur "heute", und um Mitternacht faellt der Wert auf 0
    // zurueck. Wer in Loxone eine Statistik fuehrt, verliert sonst den
    // Tagesabschluss.
    $monat = ak_energie_summe((int) $ak_anlage, 'monat');
    $jahr  = ak_energie_summe((int) $ak_anlage, 'jahr');

    // Bei zeitraum=monat|jahr treten die Summen an die Stelle der Tageswerte -
    // dieselben Feldnamen, damit dieselbe Importvorlage passt.
    $quelle = $en;
    if ($ak_zeitraum === 'monat') {
        $quelle = $monat;
    } elseif ($ak_zeitraum === 'jahr') {
        $quelle = $jahr;
    }

    echo ak_zeile('ENERGIE', 'energie', array(
        'OK'        => $ak_ok,
        'PV'        => isset($quelle['pv']) ? $quelle['pv'] : null,
        'BATLD'     => isset($quelle['batterie_geladen']) ? $quelle['batterie_geladen'] : null,
        'BATENTL'   => isset($quelle['batterie_abgegeben']) ? $quelle['batterie_abgegeben'] : null,
        'HAUS'      => isset($quelle['haus']) ? $quelle['haus'] : null,
        'NETZBEZUG' => isset($quelle['netzbezug']) ? $quelle['netzbezug'] : null,
        'NETZEINSP' => isset($quelle['netzeinspeisung']) ? $quelle['netzeinspeisung'] : null,
        'ZPV'       => isset($za['pv']) ? $za['pv'] : null,
        'ZHAUS'     => isset($za['haus']) ? $za['haus'] : null,
        'ZBEZUG'    => isset($za['netzbezug']) ? $za['netzbezug'] : null,
        'ZEINSP'    => isset($za['netzeinspeisung']) ? $za['netzeinspeisung'] : null,
        'MPV'       => $monat['pv'],
        'MHAUS'     => $monat['haus'],
        'JPV'       => $jahr['pv'],
        'JHAUS'     => $jahr['haus'],
        'ALTER'     => $ak_alter,
    ));
    exit;
}

/* ================= Schaltende Aktionen ================= */

// Gebremste Protokollzeile (a4): wer schaltet, steht im Protokoll. Die
// Seriennummer bleibt draussen - Protokolle landen in Foren.
ak_endpunkt_log('befehl', 'Aktion ' . $ak_aktion_log . ', Anlage ' . $ak_anlage);

if ($ak_aktion !== 'abruf' && empty($ak_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (ak_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts,
    // und der Befehl laege bis zum naechsten Start in der Warteschlange.
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

/* Sofortabruf hoechstens alle 30 s (Verbesserung a3 / X-7, 30.09.2026).
 * Bis 0.9.23 holte jeder Aufruf die ganze Cloud neu - ein flatternder
 * Ausgang trieb das Konto in die 429-Sperre. Faellt der Merker aus, wird
 * abgewiesen (503), nicht durchgelassen. */
if ($ak_aktion === 'abruf') {
    $ak_rest = ak_abruf_bremse();
    if ($ak_rest < 0) {
        http_response_code(503);
        echo "SET;OK=0;AKTION=abruf;GRUND=BREMSE_MERKER\n";
        exit;
    }
    if ($ak_rest > 0) {
        http_response_code(429);
        header('Retry-After: ' . $ak_rest);
        echo 'SET;OK=0;AKTION=abruf;GRUND=BREMSE;WARTEN_S=' . $ak_rest . "\n";
        exit;
    }
}

$ak_befehl = array('aktion' => $ak_aktion, 'anlage' => $ak_anlage);
if ($ak_sn !== '') {
    $ak_befehl['sn'] = $ak_sn;
}

/* Welcher Parameter zu welcher Aktion gehoert - eine Stelle, kein
 * if-Treppenhaus. Ein Feld, das nur zu einer Aktion gehoert, wird bei den
 * anderen gar nicht erst uebernommen. */
$ak_pflicht = array(
    'hauslast'        => array('watt', $ak_watt, 'WATT_FEHLT'),
    'einspeisegrenze' => array('watt', $ak_watt, 'WATT_FEHLT'),
    'pvlimit'         => array('watt', $ak_watt, 'WATT_FEHLT'),
    'reserve'         => array('prozent', $ak_prozent, 'PROZENT_FEHLT'),
    'notstromreserve' => array('prozent', $ak_prozent, 'PROZENT_FEHLT'),
    'modus'           => array('wert', $ak_wert, 'WERT_FEHLT'),
    'einspeisung'     => array('wert', $ak_wert, 'WERT_FEHLT'),
);
if (isset($ak_pflicht[$ak_aktion])) {
    list($ak_feld, $ak_v, $ak_grund) = $ak_pflicht[$ak_aktion];
    if ($ak_v === '') {
        http_response_code(400);
        echo 'SET;OK=0;GRUND=' . $ak_grund . "\n";
        exit;
    }
    $ak_befehl[$ak_feld] = ($ak_feld === 'wert') ? $ak_v : (int) $ak_v;
}
if ($ak_aktion === 'einspeisung' && !in_array($ak_wert, array('ein', 'aus'), true)) {
    http_response_code(400);
    echo "SET;OK=0;GRUND=WERT_UNZULAESSIG\n";
    echo "Erlaubt sind: ein, aus\n";
    exit;
}
if ($ak_aktion === 'pvlimit' && $ak_sn === '') {
    // Eine Wechselrichter-Begrenzung gilt EINEM Geraet. Ohne Seriennummer
    // waere nicht bestimmt, welches gemeint ist - und geraten wird nicht.
    http_response_code(400);
    echo "SET;OK=0;GRUND=SN_FEHLT\n";
    exit;
}

/* Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25; Kopf der Funktionen in
 * ak_lib.php). VOR der Gleichwert-Unterdrueckung: auch ein Befehl, den sie als
 * unveraendert beantwortet, kommt von einem Schreiber. Ein abgewiesener Befehl
 * (409) wird nicht eingereiht und setzt den Merker "Sollwert empfangen" (Nr. 22)
 * nicht. Gleichwert, Schrittweite und Rueckfall bleiben, wie sie waren; der
 * Zusatz ;SCHREIBER=n / ;WACHE=MERKER haengt an jeder Antwort danach. */
$ak_wache = ak_wache_anwenden($ak_aktion, isset($ak_befehl['wert']) ? $ak_befehl['wert'] : '', $ak_anlage, $ak_von);
$ak_wz = $ak_wache['zusatz'];
if ($ak_wache['abweisen']) {
    http_response_code(409);
    echo 'SET;OK=0;AKTION=' . $ak_aktion . ";GRUND=FREMDSCHREIBER\n";
    echo 'Der Schreiber ' . $ak_wache['schreiber'] . ' steht nicht in der Liste der erlaubten Schreiber '
       . "(Reiter Einstellungen, Schreiber-Wache). Nichts wurde eingereiht.\n";
    exit;
}

/* Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026, Entscheidungen
 * Nr. 16 und 19; Vorbild EVCC 0.9.37). Erst sind Aktion und Wert geprueft
 * (oben), dann kommt die Unterdrueckung, dann das Einreihen. Der Merker bleibt
 * bis nach der Antwort des Dienstes gesperrt; laesst er sich nicht oeffnen,
 * faellt es geschlossen aus (503) - eingereiht wird dann nichts. */
$ak_gw_schl = ak_gleichwert_schluessel($ak_befehl);
$ak_gw = null;
$ak_gw_merker = array();
$ak_gw_wert = ak_gleichwert_wert($ak_befehl);
if ($ak_gw_schl !== '') {
    $ak_gw = ak_gleichwert_oeffnen();
    if ($ak_gw === false) {
        http_response_code(503);
        echo 'SET;OK=0;AKTION=' . $ak_aktion . ';GRUND=GLEICHWERT_MERKER' . $ak_wz . "\n";
        exit;
    }
    $ak_gw_merker = ak_gleichwert_lesen($ak_gw);
    $ak_seit = ak_gleichwert_seit($ak_gw_merker, $ak_gw_schl, $ak_gw_wert);
    if ($ak_seit >= 0) {
        ak_gleichwert_schliessen($ak_gw, null);
        // Derselbe Wert wie eben gesendet: Loxone lebt (Nr. 22).
        ak_sollwert_empfangen_vermerken();
        printf("SET;OK=1;AKTION=%s;UNVERAENDERT=1;SEIT_S=%d;MELDUNG=Derselbe Wert ging vor %d s hinaus - nichts gesendet.%s\n",
            $ak_aktion, $ak_seit, $ak_seit, $ak_wz);
        exit;
    }
}

// Die ungeheilte Konfiguration von oben (X-1): der Endpunkt schreibt nie
// die Konfiguration aus der Zweitschrift zurueck, auch nicht beim Einreihen.
// Kennzeichen fuer den Dienst (Nr. 22): nimmt er diesen Sollwert an
// (ok=1, gesendet oder unveraendert), setzt ER den Merker "Sollwert
// empfangen" - auch wenn hier niemand mehr auf die Antwort wartet (OK=2).
// Die Gleichwert-Werte oben sind schon gebildet; quelle gehoert nicht dazu.
if ($ak_gw_schl !== '') {
    $ak_befehl['quelle'] = 'endpunkt';
}
$ak_abgesetzt = ak_befehl_absetzen($ak_befehl, null, $ak_cfg);
list($ak_erg, $ak_meldung) = $ak_abgesetzt;
// Innerhalb der Schrittweite (Nr. 21) ging nichts hinaus: der Merker behaelt
// den zuletzt GESENDETEN Wert (leerer Schluessel - nur Abgelaufenes raeumen),
// sonst hiesse es spaeter "Derselbe Wert ging vor n s hinaus" fuer einen Wert,
// der nie hinausging.
$ak_unveraendert = ($ak_erg === 1 && !empty($ak_abgesetzt[2]));
if ($ak_gw !== null) {
    ak_gleichwert_schliessen($ak_gw, ak_gleichwert_nachher($ak_gw_merker,
        $ak_unveraendert ? '' : $ak_gw_schl, $ak_gw_wert, $ak_erg === 1));
}
if ($ak_erg === 0) {
    http_response_code(500);
}
if ($ak_unveraendert) {
    printf("SET;OK=1;AKTION=%s;UNVERAENDERT=1;MELDUNG=%s%s\n", $ak_aktion,
        str_replace(array("\r", "\n", ';'), ' ', $ak_meldung), $ak_wz);
    exit;
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s%s\n", $ak_erg, $ak_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $ak_meldung), $ak_wz);
