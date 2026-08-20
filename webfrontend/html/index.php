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
 *   abruf                          sofortiger Abruf statt Warten auf den Takt
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
$ak_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
$ak_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';

/* selftest steht unmittelbar hinter der Token-Pruefung: die Pruefung greift,
 * die Wirkung nicht. Ein Token muss sich pruefen lassen, ohne dass etwas
 * geschieht - sonst bleiben nur zwei schlechte Wege: entweder man schaltet
 * wirklich, dann faehrt der Speicher um, oder man erfaehrt nie, ob die
 * Adresse im Miniserver noch stimmt. */
if ($ak_soll === '') {
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
    $w = (string) $_GET[$name];
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
$ak_ok = (!empty($ak_lox['ok']) && $ak_alter >= 0) ? 1 : 0;

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

list($ak_erg, $ak_meldung) = ak_befehl_absetzen($ak_befehl);
if ($ak_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $ak_erg, $ak_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $ak_meldung));
