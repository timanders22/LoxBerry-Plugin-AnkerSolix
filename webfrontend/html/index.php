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
 *   status   [&anlage=N]        Leistungswerte der Anlage
 *   energie  [&anlage=N]        Tagesenergien der Anlage
 *   geraet   &sn=<Seriennummer> Werte eines einzelnen Geraets
 *   anlagen                     Liste der erkannten Anlagen
 *   roh                         vollstaendiges Abbild als JSON (Fehlersuche)
 *
 * Schaltende Aktionen (nur wenn im Reiter Einstellungen zugelassen):
 *   hauslast &watt=<W>   [&anlage=N][&sn=..]
 *   modus    &wert=<Modus>[&anlage=N][&sn=..]
 *   reserve  &prozent=<%> [&anlage=N][&sn=..]
 *   abruf                       sofortiger Abruf statt Warten auf den Takt
 *
 * Der Endpunkt spricht NIE selbst mit der Anker-Cloud. Lesende Aktionen
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: die Cloud hat dieses Feld nicht geliefert.
 * Es wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/ak_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$ak_cfg = ak_config();
$ak_p = ak_paths();

/* ---------------- Token ---------------- */
$ak_soll = (string) $ak_cfg['aktionstoken'];
$ak_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($ak_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($ak_soll, $ak_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$ak_lesend = array('status', 'energie', 'geraet', 'anlagen', 'roh');
$ak_schaltend = array('hauslast', 'modus', 'reserve', 'abruf');
$ak_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($ak_aktion, array_merge($ak_lesend, $ak_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($ak_lesend, $ak_schaltend)) . "\n";
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

$ak_anlage  = ak_param('anlage', '/^[0-9]{1,2}$/', '1');
$ak_sn      = ak_param('sn', '/^[A-Za-z0-9]{1,32}$/', '');
$ak_watt    = ak_param('watt', '/^-?[0-9]{1,5}$/', '');
$ak_prozent = ak_param('prozent', '/^[0-9]{1,3}$/', '');
$ak_wert    = ak_param('wert', '/^[a-z]{1,20}$/', '');

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
        echo $nr . ';' . (isset($an['name']) ? $an['name'] : '') . ';'
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
    printf("GERAET;OK=%d;SOC=%s;PV=%s;AUSGANG=%s;LADEN=%s;SOLL=%s;ONLINE=%s;WLAN=%s;LEISTUNG=%s;ALTER=%d\n",
        $ak_ok, ak_w($d['soc']), ak_w($d['pv']), ak_w($d['ausgang']), ak_w($d['laden']),
        ak_w($d['sollwert']), ak_w($d['online']), ak_w($d['wlan']), ak_w($d['leistung']), $ak_alter);
    exit;
}

$ak_alle = ak_anlagen();
$ak_an = isset($ak_alle[$ak_anlage]) ? $ak_alle[$ak_anlage] : null;

if ($ak_aktion === 'status') {
    if ($ak_an === null) {
        printf("ANKER;OK=0;GRUND=ANLAGE_UNBEKANNT;N=%d;ALTER=%d\n", count($ak_alle), $ak_alter);
        exit;
    }
    printf("ANKER;OK=%d;SOC=%s;PV=%s;LADEN=%s;ENTLADEN=%s;BATP=%s;AUSGANG=%s;HAUS=%s;"
         . "NETZBEZUG=%s;NETZEINSP=%s;SOLL=%s;MODUS=%s;GERAETE=%d;ALTER=%d\n",
        $ak_ok, ak_w($ak_an['soc']), ak_w($ak_an['pv']), ak_w($ak_an['laden']),
        ak_w($ak_an['entladen']), ak_w($ak_an['batp']), ak_w($ak_an['ausgang']),
        ak_w($ak_an['haus']), ak_w($ak_an['netzbezug']), ak_w($ak_an['netzeinspeisung']),
        ak_w($ak_an['sollwert']), ak_w($ak_an['modus']),
        (int) $ak_an['anzahl_geraete'], $ak_alter);
    exit;
}

if ($ak_aktion === 'energie') {
    if ($ak_an === null) {
        printf("ENERGIE;OK=0;GRUND=ANLAGE_UNBEKANNT;ALTER=%d\n", $ak_alter);
        exit;
    }
    $en = isset($ak_an['energie']) && is_array($ak_an['energie']) ? $ak_an['energie'] : array();
    printf("ENERGIE;OK=%d;PV=%s;BATLD=%s;BATENTL=%s;HAUS=%s;NETZBEZUG=%s;NETZEINSP=%s;DATUM=%s;ALTER=%d\n",
        $ak_ok, ak_w(isset($en['pv']) ? $en['pv'] : null),
        ak_w(isset($en['batterie_geladen']) ? $en['batterie_geladen'] : null),
        ak_w(isset($en['batterie_abgegeben']) ? $en['batterie_abgegeben'] : null),
        ak_w(isset($en['haus']) ? $en['haus'] : null),
        ak_w(isset($en['netzbezug']) ? $en['netzbezug'] : null),
        ak_w(isset($en['netzeinspeisung']) ? $en['netzeinspeisung'] : null),
        isset($en['datum']) && $en['datum'] !== '' ? $en['datum'] : '-', $ak_alter);
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
if ($ak_aktion === 'hauslast') {
    if ($ak_watt === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WATT_FEHLT\n";
        exit;
    }
    $ak_befehl['watt'] = (int) $ak_watt;
} elseif ($ak_aktion === 'modus') {
    if ($ak_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $ak_befehl['wert'] = $ak_wert;
} elseif ($ak_aktion === 'reserve') {
    if ($ak_prozent === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PROZENT_FEHLT\n";
        exit;
    }
    $ak_befehl['prozent'] = (int) $ak_prozent;
}

list($ak_erg, $ak_meldung) = ak_befehl_absetzen($ak_befehl);
if ($ak_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $ak_erg, $ak_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $ak_meldung));
