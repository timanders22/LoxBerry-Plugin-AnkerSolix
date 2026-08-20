<?php
/**
 * Anker SOLIX - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Datenabruf laeuft im Dienst
 * (bin/ankersolix.py), der Miniserver spricht mit webfrontend/html/index.php.
 * Ein Plugin, das den Abruf hier erledigt, ist falsch gebaut - auch wenn es
 * funktioniert.
 *
 * Praefix 'ak_', weil LBWeb::lbheader() SDK-Globale setzt (unter anderem $cfg
 * aus der general.json als stdClass) und gleichnamige Plugin-Variablen
 * ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil der
 * Miniserver-Endpunkt sie ebenfalls braucht - installiert unter
 * .../html/plugins/<ordner>/, im Archiv unter ../html/. */
$ak_gefunden = false;
foreach (array(
    // installiert: <home>/webfrontend/htmlauth/plugins/<ordner>  ->
    //              <home>/webfrontend/html/plugins/<ordner>
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/ak_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/ak_lib.php',
    // im Archiv: <plugin>/webfrontend/htmlauth -> <plugin>/webfrontend/html
    dirname(__DIR__) . '/html/ak_lib.php',
) as $ak_kandidat) {
    if (is_file($ak_kandidat)) {
        require_once $ak_kandidat;
        $ak_gefunden = true;
        break;
    }
}
if (!$ak_gefunden) {
    echo '<p><b>Fehler:</b> ak_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/ak_test.php';

$ak_p = ak_paths();
if ($ak_p['home'] !== '' && is_file($ak_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $ak_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $ak_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* ---------------- Die Reiterliste steht genau einmal ----------------
 *
 * Aus diesem Feld entsteht der Pruefausdruck fuer 'activetab'. Die Leiste
 * weiter unten und die Bereiche stehen AUSGESCHRIEBEN da - eine Schleife
 * waere hier falsch: hausstandard_pruefen.py sucht data-ziel="tab-…" als
 * Literal, faende bei einer Schleife null Reiter und setzte die Spalte auf
 * "-", also "trifft nicht zu". Ein Strich sammelt sich beim Ueberfliegen wie
 * ein Haken ein. Dass die drei Stellen zusammenpassen, misst der Reiter Test
 * (ak_kongruenz) nach. */
$ak_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$ak_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($ak_reiter))) . ')$/';

$ak_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($ak_muster, (string) $_POST['activetab'])) {
    $ak_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($ak_muster, 'tab-' . (string) $_GET['form'])) {
    $ak_tab = 'tab-' . (string) $_GET['form'];
}

$ak_meldungen = array();   // Erfolgsmeldungen
$ak_fehler = array();      // Beanstandungen - gesammelt, nicht ueberschrieben
$ak_testausgabe = '';
$ak_trockenlauf = array();
$ak_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Merkmal gegen fremde Absender ----------------
 *
 * EINE zentrale Pruefung, bevor irgendein Handler laeuft - einen einzelnen
 * Handler kann man vergessen. Der angemeldete Bereich ist durch die Anmeldung
 * des LoxBerry geschuetzt; gegen eine fremde Seite schuetzt das nicht, denn
 * der Browser schickt die hinterlegten Zugangsdaten bei einer Anfrage von
 * aussen mit. Ein untergeschobenes Formular koennte sonst "Neues Token
 * erzeugen" ausloesen - danach beantwortet der Endpunkt jeden Virtuellen
 * Ausgang mit 403, und ein Virtueller Ausgang wertet die Antwort nicht aus:
 * der Ausfall bliebe still.
 *
 * Faellt die Pruefung durch, wird der POST zurueckgenommen UND gemeldet. Ein
 * Formular, das wortlos nichts tut, schickt den Anwender auf die Suche nach
 * einem Fehler, den es nicht gibt. */
$ak_cfg = ak_config(true);
$ak_token = ak_token();
$ak_cfg['aktionstoken'] = $ak_token;
$ak_formtoken = ak_formtoken($ak_cfg);
if ($ak_post && !ak_formtoken_gueltig($ak_cfg, isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '')) {
    $ak_fehler[] = ak_t('ALLG.FORMTOKEN');
    $ak_post = false;
}

/* ---------------- Vorlage herunterladen ---------------- */
if ($ak_post && isset($_POST['vorlage'])) {
    $ak_art = (string) $_POST['vorlage'];
    $ak_nr = isset($_POST['vorlage_nr']) && preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage_nr'])
        ? (int) $_POST['vorlage_nr'] : 1;
    $ak_vsn = isset($_POST['vorlage_sn']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_POST['vorlage_sn']) : '';
    $ak_datei = null;
    if ($ak_art === 'ausgang') {
        $ak_datei = ak_vo_vorlage($ak_nr);
    } elseif ($ak_art === 'alles') {
        $ak_datei = ak_vorlagen_paket();
        if ($ak_datei === null) {
            $ak_fehler[] = ak_t('LOX.KEIN_ZIP');
            $ak_tab = 'tab-loxone';
        }
    } elseif (in_array($ak_art, array('status', 'energie', 'geraet'), true)) {
        $ak_datei = ak_vorlage($ak_art, $ak_nr, $ak_vsn);
    }
    if ($ak_datei !== null) {
        list($ak_name, $ak_inhalt) = $ak_datei;
        header('Content-Type: ' . ($ak_art === 'alles' ? 'application/zip' : 'application/xml') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $ak_name . '"');
        echo $ak_inhalt;
        exit;
    }
}

/* ---------------- Einstellungen speichern ---------------- */
if ($ak_post && isset($_POST['speichern'])) {
    $ak_cfg = ak_config(true);

    $ak_land = strtoupper(trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['land'])));
    if (!preg_match('/^[A-Z]{2}$/', $ak_land)) {
        $ak_fehler[] = ak_t('EINST.FEHLER_LAND');
    } else {
        $ak_cfg['land'] = $ak_land;
    }

    foreach (array(
        'intervall'      => array(30, 900),
        'takt_details'   => array(1, 240),
        'takt_energie'   => array(1, 240),
        'takt_prognose'  => array(1, 1440),
        'endpunkt_limit' => array(1, 60),
        'anfrage_pause'  => array(0, 100),
        'anfrage_frist'  => array(5, 60),
        'hauslast_min'   => array(0, 5000),
        'hauslast_max'   => array(0, 5000),
        'verlauf_tage'   => array(1, 90),
        'energie_tage'   => array(1, 3650),
        'schreibbremse'  => array(0, 3600),
        'schrittweite'   => array(0, 1000),
        'rueckfall_min'  => array(0, 1440),
        'melden_alter'   => array(60, 86400),
        'wartezeit'      => array(0, 20),
    ) as $ak_feld => $ak_grenzen) {
        $ak_wert = isset($_POST[$ak_feld]) ? trim((string) $_POST[$ak_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $ak_wert)) {
            $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_ZAHL'), ak_t('EINST.L_' . strtoupper($ak_feld)));
            continue;
        }
        $ak_zahl = (int) $ak_wert;
        if ($ak_zahl < $ak_grenzen[0] || $ak_zahl > $ak_grenzen[1]) {
            $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_BEREICH'),
                ak_t('EINST.L_' . strtoupper($ak_feld)), $ak_grenzen[0], $ak_grenzen[1]);
            continue;
        }
        $ak_cfg[$ak_feld] = $ak_zahl;
    }
    if (isset($ak_cfg['hauslast_min'], $ak_cfg['hauslast_max'])
        && $ak_cfg['hauslast_min'] > $ak_cfg['hauslast_max']) {
        $ak_fehler[] = ak_t('EINST.FEHLER_HAUSLAST_TAUSCH');
    }

    foreach (array('steuerung_ein', 'zaehler_ein', 'melden_ein',
                   'ohne_details', 'ohne_energie', 'ohne_prognose') as $ak_haken) {
        $ak_cfg[$ak_haken] = isset($_POST[$ak_haken]) ? 1 : 0;
    }

    $ak_rm = isset($_POST['rueckfall_modus']) ? (string) $_POST['rueckfall_modus'] : '';
    if (!array_key_exists($ak_rm, ak_modi())) {
        $ak_fehler[] = ak_t('EINST.FEHLER_RUECKFALL_MODUS');
    } else {
        $ak_cfg['rueckfall_modus'] = $ak_rm;
    }

    /* Grenzen je Anlage. Ein gemeinsames Maximum fuer eine Solarbank E1600
     * und eine Solarbank 3 ist entweder zu klein oder zu gross. Ein leeres
     * Feld heisst "es gilt der allgemeine Wert" - nicht "0". */
    $ak_gr = array();
    foreach (ak_anlagen() as $ak_nr => $ak_an) {
        $ak_e1 = isset($_POST['gmin_' . $ak_nr]) ? trim((string) $_POST['gmin_' . $ak_nr]) : '';
        $ak_e2 = isset($_POST['gmax_' . $ak_nr]) ? trim((string) $_POST['gmax_' . $ak_nr]) : '';
        foreach (array($ak_e1, $ak_e2) as $ak_v) {
            if ($ak_v !== '' && !preg_match('/^[0-9]{1,4}$/', $ak_v)) {
                $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_GRENZE_ANLAGE'), $ak_nr);
            }
        }
        if ($ak_e1 !== '' && $ak_e2 !== '' && (int) $ak_e1 > (int) $ak_e2) {
            $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_GRENZE_TAUSCH'), $ak_nr);
        }
        if ($ak_e1 !== '' || $ak_e2 !== '') {
            $ak_gr[(string) $ak_nr] = array('min' => $ak_e1, 'max' => $ak_e2);
        }
    }
    $ak_cfg['anlagen_grenzen'] = $ak_gr;

    /* mqtt_ein, mqtt_topic und mqtt_nur_aenderung werden hier bewusst NICHT
     * angefasst: sie wohnen im Reiter MQTT und haben dort ein eigenes
     * Formular. $ak_cfg kommt aus ak_config(), die Werte ueberleben also
     * unveraendert. Stuende hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0",
     * wuerde jedes Speichern der Einstellungen MQTT stillschweigend
     * abschalten - genau diese Falle kostete am 13.08.2026 drei Plugins ihr
     * Aktionstoken. */

    /* Zugangsdaten: eigene Datei mit Rechten 0600. Ein leer zurueckgegebenes
     * Passwortfeld loescht nichts - sonst stuende irgendwann ein leeres
     * Passwort in der Datei, ohne dass es jemand merkt. */
    $ak_email = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['email']));
    $ak_pw = isset($_POST['passwort']) ? (string) $_POST['passwort'] : '';
    if ($ak_email !== '' && !filter_var($ak_email, FILTER_VALIDATE_EMAIL)) {
        $ak_fehler[] = ak_t('EINST.FEHLER_EMAIL');
    } else {
        if (!ak_zugang_speichern($ak_email, $ak_pw)) {
            $ak_fehler[] = ak_t('EINST.FEHLER_ZUGANG_SPEICHERN');
        }
    }
    $ak_zg = ak_zugang();
    if ($ak_zg['laenge'] > 0 && $ak_zg['email'] === '') {
        $ak_fehler[] = ak_t('EINST.WARN_PW_OHNE_KONTO');
    }

    if (!$ak_fehler) {
        if (ak_config_speichern($ak_cfg)) {
            $ak_meldungen[] = ak_t('EINST.GESPEICHERT');
        } else {
            $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_SPEICHERN'), $ak_p['config']);
        }
    }
    $ak_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Wuerden beide
 * Formulare denselben Handler ausloesen, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verliert
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
if ($ak_post && isset($_POST['save_mqtt'])) {
    $ak_cfg = ak_config(true);
    $ak_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $ak_cfg['mqtt_nur_aenderung'] = isset($_POST['mqtt_nur_aenderung']) ? 1 : 0;
    $ak_topic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($ak_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $ak_topic)) {
        $ak_fehler[] = ak_t('EINST.FEHLER_TOPIC');
    } else {
        $ak_cfg['mqtt_topic'] = trim($ak_topic, '/');
    }
    if (!$ak_fehler) {
        if (ak_config_speichern($ak_cfg)) {
            $ak_meldungen[] = ak_t('EINST.GESPEICHERT');
        } else {
            $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_SPEICHERN'), $ak_p['config']);
        }
    }
    $ak_tab = 'tab-mqtt';
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($ak_post && isset($_POST['dienst'])) {
    $ak_befehl = (string) $_POST['dienst'];
    list($ak_ok, $ak_ausgabe) = ak_dienst($ak_befehl);
    if ($ak_ok) {
        $ak_meldungen[] = ak_t('EINST.DIENST_' . strtoupper($ak_befehl)) . ' ' . ak_e($ak_ausgabe);
    } else {
        $ak_fehler[] = nl2br(ak_e($ak_ausgabe));
    }
    $ak_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($ak_post && isset($_POST['token_neu'])) {
    $ak_cfg = ak_config(true);
    $ak_cfg['aktionstoken'] = ak_token_erzeugen();
    if (ak_config_speichern($ak_cfg)) {
        $ak_meldungen[] = ak_t('LOX.TOKEN_NEU');
    } else {
        $ak_fehler[] = sprintf(ak_t('EINST.FEHLER_SPEICHERN'), $ak_p['config']);
    }
    $ak_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($ak_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($ak_p['log']), 0775, true);
    @file_put_contents($ak_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . ak_t('LOG.GELEERT') . "\n");
    $ak_meldungen[] = ak_t('LOG.GELEERT');
    $ak_tab = 'tab-log';
}

/* ---------------- Aktionen des Reiters Test ---------------- */
if ($ak_post && isset($_POST['trockenlauf'])) {
    list($ak_ta, $ak_trockenlauf) = ak_trockenlauf_aktion();
    $ak_tab = 'tab-test';
}
if ($ak_post && isset($_POST['test'])) {
    list($ak_stand, $ak_text) = ak_test_aktion((string) $_POST['test']);
    if ($ak_stand === 1) {
        $ak_meldungen[] = ak_e($ak_text);
    } else {
        // 0 = abgelehnt, 2 = eingereiht ohne Antwort. Beides ist KEIN Erfolg,
        // und ein Erfolg, den niemand geprueft hat, wird nie gemeldet.
        $ak_fehler[] = ak_e($ak_text);
    }
    $ak_tab = 'tab-test';
}
if ($ak_post && isset($_POST['selbsttest'])) {
    $ak_testausgabe = ak_selbsttest();
    $ak_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$ak_cfg = ak_config(true);
$ak_token = ak_token();
$ak_cfg['aktionstoken'] = $ak_token;
$ak_formtoken = ak_formtoken($ak_cfg);
$ak_zg = ak_zugang();
$ak_anlagen = ak_anlagen();
$ak_geraete = ak_geraete();
$ak_zustand = ak_zustand();
$ak_alter = ak_alter();
$ak_pid = ak_dienst_pid();
$ak_mqtt = ak_mqtt_zustand();
$ak_host = ak_host();
$ak_basis = 'http://' . $ak_host . '/plugins/' . $ak_p['plugin'] . '/index.php';
list($ak_wn, $ak_wz) = ak_waechter_stand();

/* Welcher Verlaufstag wird gezeigt? Bis 0.9.6 gab es keine Auswahl - der
 * Dienst hielt bis zu 90 Tage vor, sichtbar war immer nur heute. */
$ak_vtag = isset($_GET['tag']) && preg_match('/^[0-9]{8}$/', (string) $_GET['tag'])
    ? (string) $_GET['tag'] : date('Ymd');

// Protokoll rueckwaerts lesen, nicht die ganze Datei einlesen.
$ak_logzeilen = ak_log_ende($ak_p['log'], 400);
$ak_startlog = ak_log_ende($ak_p['startlog'], 40);

$ak_rahmen = class_exists('LBWeb', false);
if ($ak_rahmen) {
    LBWeb::lbheader('Anker SOLIX', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   einen Rollbehaelter. Ohne ihn steht die letzte Spalte auf einem schmalen
   Bildschirm ausserhalb - und ist UNERREICHBAR, nicht bloss unbequem:
   .sm-tbl hat width:100%, .sm-wrap hat max-width ohne Ueberlauf. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen: ueber die volle Breite
   mit data-role="none" sieht es sonst aus wie ein Textfeld, und der eingebaute
   Pfeil faellt am rechten Rand nicht auf. Die Raute im SVG wird als %23
   geschrieben - eine rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
</style>
<div class="sm-wrap">

<?php foreach ($ak_meldungen as $ak_m) { ?>
<div class="sm-hinweis"><?= $ak_m ?></div>
<?php } ?>
<?php if ($ak_fehler) { ?>
<div class="sm-fehler"><b><?= ak_e(ak_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($ak_fehler as $ak_f) { ?><li><?= $ak_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= ak_e(ak_t('ALLG.DIENST')) ?>
    <b class="<?= $ak_pid ? 'sm-an' : 'sm-aus' ?>"><?= $ak_pid ? ak_e(ak_t('ALLG.LAEUFT')) : ak_e(ak_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $ak_pid ? 'PID ' . (int) $ak_pid : ak_e(ak_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= ak_e(ak_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $ak_alter < 0 ? '&ndash;' : (int) $ak_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $ak_alter < 0 ? ak_e(ak_t('ALLG.NIE')) : ak_e(date('d.m.Y H:i:s', time() - $ak_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= ak_e(ak_t('ALLG.ANLAGEN')) ?>
    <b><?= count($ak_anlagen) ?></b>
    <span class="sm-hilfe"><?= count($ak_geraete) ?> <?= ak_e(ak_t('ALLG.GERAETE')) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $ak_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $ak_mqtt['autostart'] ? ak_e(ak_t('ALLG.EIN')) : ak_e(ak_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= ak_e(ak_t('ALLG.GATEWAY')) ?></span>
  </div>
  <div class="sm-kachel"><?= ak_e(ak_t('ALLG.STEUERUNG')) ?>
    <b class="<?= !empty($ak_cfg['steuerung_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($ak_cfg['steuerung_ein']) ? ak_e(ak_t('ALLG.EIN')) : ak_e(ak_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= ak_e(ak_t('ALLG.SCHREIBEND')) ?></span>
  </div>
</div>

<?php if (!empty($ak_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= ak_e(ak_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= ak_e($ak_zustand['fehler']) ?></div>
<?php } ?>
<?php if ($ak_wn > 0) { ?>
<div class="sm-warnung"><?= sprintf(ak_t('ALLG.WAECHTER_HINWEIS'), (int) $ak_wn, ak_e($ak_wz)) ?></div>
<?php } ?>

<?php foreach ($ak_anlagen as $ak_nr => $ak_an) {
    $ak_tage = ak_verlauf_tage((int) $ak_nr); ?>
<div class="sm-hinweis">
<b><?= ak_e($ak_an['name']) ?></b> (<?= ak_e(ak_t('ALLG.ANLAGE')) ?> <?= ak_e($ak_nr) ?>)
&middot; <?= ak_e(ak_t('ALLG.SOC')) ?> <b><?= $ak_an['soc'] === null ? '&ndash;' : ak_e($ak_an['soc']) . ' %' ?></b>
&middot; <?= ak_e(ak_t('ALLG.PV')) ?> <?= $ak_an['pv'] === null ? '&ndash;' : ak_e($ak_an['pv']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.BATTERIE')) ?> <?= $ak_an['batp'] === null ? '&ndash;' : ak_e($ak_an['batp']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.HAUS')) ?> <?= $ak_an['haus'] === null ? '&ndash;' : ak_e($ak_an['haus']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.MODUS')) ?> <?= ak_e($ak_an['modus_text']) ?>
<?php if (isset($ak_an['prognose_rest']) && $ak_an['prognose_rest'] !== null) { ?>
&middot; <?= ak_e(ak_t('ALLG.PROGNOSE')) ?> <?= ak_e($ak_an['prognose_rest']) ?> kWh
<?php } ?>
<div style="margin-top:8px;"><?= ak_soc_svg(ak_verlauf_lesen((int) $ak_nr, $ak_vtag), $ak_vtag) ?></div>
<?php if (count($ak_tage) > 1) { ?>
<div class="sm-feld" style="margin:6px 0 0;">
  <label for="tagwahl<?= ak_e($ak_nr) ?>"><?= ak_e(ak_t('ALLG.TAG_WAEHLEN')) ?></label>
  <select data-role="none" id="tagwahl<?= ak_e($ak_nr) ?>"
          onchange="location.href='index.php?form=settings&amp;tag='+this.value">
<?php foreach ($ak_tage as $ak_td) { ?>
    <option value="<?= ak_e($ak_td) ?>"<?= $ak_td === $ak_vtag ? ' selected' : '' ?>><?= ak_e(
      substr($ak_td, 6, 2) . '.' . substr($ak_td, 4, 2) . '.' . substr($ak_td, 0, 4)) ?></option>
<?php } ?>
  </select>
</div>
<?php } ?>
<div class="sm-hilfe"><?= ak_e(ak_t('ALLG.VERLAUF_HINWEIS')) ?></div>
<div style="margin-top:10px;"><?= ak_energie_svg(ak_energie_lesen((int) $ak_nr), 30) ?></div>
<div class="sm-hilfe"><?= ak_t('ALLG.ENERGIE_HINWEIS') ?></div>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, und Eingaben in anderen Reitern gehen nicht verloren.

     sm-active setzt der SERVER - an der Leiste und am Bereich. Bis 0.9.6 tat
     das ausschliesslich das Skript am Seitenende, und weil .sm-seite auf
     display:none steht, war die Seite ohne JavaScript VOLLSTAENDIG leer,
     nicht etwa untereinander aufgeklappt. Im Quelltext stand darueber der
     Satz, die Seite sei dann "weiterhin bedienbar" - ein Kommentar, der eine
     Eigenschaft behauptet, ist kein Beleg dafuer. Der Reiter Test zaehlt das
     jetzt nach. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $ak_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= ak_e(ak_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $ak_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $ak_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= ak_e(ak_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $ak_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= ak_e(ak_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $ak_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= ak_e(ak_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $ak_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= ak_e(ak_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= ak_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ak_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= ak_e(ak_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= ak_e(ak_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= ak_e(ak_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= ak_e(ak_t('EINST.H_KONTO')) ?></h2>
<div class="sm-warnung"><?= ak_t('EINST.KONTO_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="email"><?= ak_e(ak_t('EINST.L_EMAIL')) ?></label>
  <input data-role="none" type="text" id="email" name="email" value="<?= ak_e($ak_zg['email']) ?>" placeholder="name@example.com">
  <div class="sm-hilfe"><?= ak_t('EINST.H_EMAIL') ?></div>
</div>
<div class="sm-feld">
  <label for="passwort"><?= ak_e(ak_t('EINST.L_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="passwort" name="passwort" value="" placeholder="<?= $ak_zg['laenge'] > 0 ? ak_e(sprintf(ak_t('EINST.PW_GESETZT'), $ak_zg['laenge'])) : ak_e(ak_t('EINST.PW_LEER')) ?>">
  <div class="sm-hilfe"><?= ak_t('EINST.H_PASSWORT') ?></div>
</div>
<div class="sm-feld">
  <label for="land"><?= ak_e(ak_t('EINST.L_LAND')) ?></label>
  <input data-role="none" type="text" id="land" name="land" value="<?= ak_e($ak_cfg['land']) ?>" maxlength="2" placeholder="DE">
  <div class="sm-hilfe"><?= ak_t('EINST.H_LAND') ?></div>
</div>

<h2><?= ak_e(ak_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="intervall"><?= ak_e(ak_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= (int) $ak_cfg['intervall'] ?>" min="30" max="900">
  <div class="sm-hilfe"><?= ak_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_details"><?= ak_e(ak_t('EINST.L_TAKT_DETAILS')) ?></label>
  <input data-role="none" type="number" id="takt_details" name="takt_details" value="<?= (int) $ak_cfg['takt_details'] ?>" min="1" max="240">
  <div class="sm-hilfe"><?= ak_t('EINST.H_TAKT_DETAILS') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_energie"><?= ak_e(ak_t('EINST.L_TAKT_ENERGIE')) ?></label>
  <input data-role="none" type="number" id="takt_energie" name="takt_energie" value="<?= (int) $ak_cfg['takt_energie'] ?>" min="1" max="240">
  <div class="sm-hilfe"><?= ak_t('EINST.H_TAKT_ENERGIE') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_prognose"><?= ak_e(ak_t('EINST.L_TAKT_PROGNOSE')) ?></label>
  <input data-role="none" type="number" id="takt_prognose" name="takt_prognose" value="<?= (int) $ak_cfg['takt_prognose'] ?>" min="1" max="1440">
  <div class="sm-hilfe"><?= ak_t('EINST.H_TAKT_PROGNOSE') ?></div>
</div>
<div class="sm-feld">
  <label for="endpunkt_limit"><?= ak_e(ak_t('EINST.L_ENDPUNKT_LIMIT')) ?></label>
  <input data-role="none" type="number" id="endpunkt_limit" name="endpunkt_limit" value="<?= (int) $ak_cfg['endpunkt_limit'] ?>" min="1" max="60">
  <div class="sm-hilfe"><?= ak_t('EINST.H_ENDPUNKT_LIMIT') ?></div>
</div>
<div class="sm-feld">
  <label for="anfrage_pause"><?= ak_e(ak_t('EINST.L_ANFRAGE_PAUSE')) ?></label>
  <input data-role="none" type="number" id="anfrage_pause" name="anfrage_pause" value="<?= (int) $ak_cfg['anfrage_pause'] ?>" min="0" max="100">
  <div class="sm-hilfe"><?= ak_t('EINST.H_ANFRAGE_PAUSE') ?></div>
</div>
<div class="sm-feld">
  <label for="anfrage_frist"><?= ak_e(ak_t('EINST.L_ANFRAGE_FRIST')) ?></label>
  <input data-role="none" type="number" id="anfrage_frist" name="anfrage_frist" value="<?= (int) $ak_cfg['anfrage_frist'] ?>" min="5" max="60">
  <div class="sm-hilfe"><?= ak_t('EINST.H_ANFRAGE_FRIST') ?></div>
</div>

<h2><?= ak_e(ak_t('EINST.H_UMFANG')) ?></h2>
<div class="sm-hinweis"><?= ak_t('EINST.UMFANG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ohne_details" value="1" <?= !empty($ak_cfg['ohne_details']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_OHNE_DETAILS')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ohne_energie" value="1" <?= !empty($ak_cfg['ohne_energie']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_OHNE_ENERGIE')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ohne_prognose" value="1" <?= !empty($ak_cfg['ohne_prognose']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_OHNE_PROGNOSE')) ?>
  </label>
  <div class="sm-hilfe"><?= ak_t('EINST.H_OHNE_PROGNOSE') ?></div>
</div>

<h2><?= ak_e(ak_t('EINST.H_ABLAGE')) ?></h2>
<div class="sm-feld">
  <label for="verlauf_tage"><?= ak_e(ak_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $ak_cfg['verlauf_tage'] ?>" min="1" max="90">
  <div class="sm-hilfe"><?= ak_t('EINST.H_VERLAUF_TAGE') ?></div>
</div>
<div class="sm-feld">
  <label for="energie_tage"><?= ak_e(ak_t('EINST.L_ENERGIE_TAGE')) ?></label>
  <input data-role="none" type="number" id="energie_tage" name="energie_tage" value="<?= (int) $ak_cfg['energie_tage'] ?>" min="1" max="3650">
  <div class="sm-hilfe"><?= ak_t('EINST.H_ENERGIE_TAGE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="zaehler_ein" value="1" <?= !empty($ak_cfg['zaehler_ein']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_ZAEHLER_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= ak_t('EINST.H_ZAEHLER_EIN') ?></div>
</div>

<h2><?= ak_e(ak_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= ak_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($ak_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="hauslast_min"><?= ak_e(ak_t('EINST.L_HAUSLAST_MIN')) ?></label>
  <input data-role="none" type="number" id="hauslast_min" name="hauslast_min" value="<?= (int) $ak_cfg['hauslast_min'] ?>" min="0" max="5000">
</div>
<div class="sm-feld">
  <label for="hauslast_max"><?= ak_e(ak_t('EINST.L_HAUSLAST_MAX')) ?></label>
  <input data-role="none" type="number" id="hauslast_max" name="hauslast_max" value="<?= (int) $ak_cfg['hauslast_max'] ?>" min="0" max="5000">
  <div class="sm-hilfe"><?= ak_t('EINST.H_HAUSLAST') ?></div>
</div>

<?php if (count($ak_anlagen) > 0) { ?>
<h3><?= ak_e(ak_t('EINST.H_GRENZEN_ANLAGE')) ?></h3>
<p class="sm-hilfe"><?= ak_t('EINST.H_GRENZEN_ANLAGE_TEXT') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.ANLAGE')) ?></th><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th>
    <th><?= ak_e(ak_t('EINST.L_HAUSLAST_MIN')) ?></th><th><?= ak_e(ak_t('EINST.L_HAUSLAST_MAX')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_GILT')) ?></th></tr>
<?php foreach ($ak_anlagen as $ak_nr => $ak_an) {
    $ak_g = isset($ak_cfg['anlagen_grenzen'][(string) $ak_nr]) ? $ak_cfg['anlagen_grenzen'][(string) $ak_nr] : array();
    list($ak_glo, $ak_ghi) = ak_grenzen($ak_cfg, $ak_nr); ?>
<tr><td><?= ak_e($ak_nr) ?></td><td><?= ak_e($ak_an['name']) ?></td>
    <td><input data-role="none" type="number" name="gmin_<?= ak_e($ak_nr) ?>" min="0" max="5000" style="width:90px;"
        value="<?= ak_e(isset($ak_g['min']) ? $ak_g['min'] : '') ?>" placeholder="<?= (int) $ak_cfg['hauslast_min'] ?>"></td>
    <td><input data-role="none" type="number" name="gmax_<?= ak_e($ak_nr) ?>" min="0" max="5000" style="width:90px;"
        value="<?= ak_e(isset($ak_g['max']) ? $ak_g['max'] : '') ?>" placeholder="<?= (int) $ak_cfg['hauslast_max'] ?>"></td>
    <td><span class="sm-mono"><?= (int) $ak_glo ?>&hellip;<?= (int) $ak_ghi ?> W</span></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<div class="sm-feld">
  <label for="schreibbremse"><?= ak_e(ak_t('EINST.L_SCHREIBBREMSE')) ?></label>
  <input data-role="none" type="number" id="schreibbremse" name="schreibbremse" value="<?= (int) $ak_cfg['schreibbremse'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= ak_t('EINST.H_SCHREIBBREMSE') ?></div>
</div>
<div class="sm-feld">
  <label for="schrittweite"><?= ak_e(ak_t('EINST.L_SCHRITTWEITE')) ?></label>
  <input data-role="none" type="number" id="schrittweite" name="schrittweite" value="<?= (int) $ak_cfg['schrittweite'] ?>" min="0" max="1000">
  <div class="sm-hilfe"><?= ak_t('EINST.H_SCHRITTWEITE') ?></div>
</div>
<div class="sm-feld">
  <label for="rueckfall_min"><?= ak_e(ak_t('EINST.L_RUECKFALL_MIN')) ?></label>
  <input data-role="none" type="number" id="rueckfall_min" name="rueckfall_min" value="<?= (int) $ak_cfg['rueckfall_min'] ?>" min="0" max="1440">
  <div class="sm-hilfe"><?= ak_t('EINST.H_RUECKFALL_MIN') ?></div>
</div>
<div class="sm-feld">
  <label for="rueckfall_modus"><?= ak_e(ak_t('EINST.L_RUECKFALL_MODUS')) ?></label>
  <select data-role="none" id="rueckfall_modus" name="rueckfall_modus">
<?php foreach (array_keys(ak_modi()) as $ak_mo) { ?>
    <option value="<?= ak_e($ak_mo) ?>"<?= $ak_cfg['rueckfall_modus'] === $ak_mo ? ' selected' : '' ?>><?= ak_e(ak_t('MODUS.' . strtoupper($ak_mo))) ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= ak_e(ak_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $ak_cfg['wartezeit'] ?>" min="0" max="20">
  <div class="sm-hilfe"><?= ak_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= ak_e(ak_t('EINST.H_MELDEN')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="melden_ein" value="1" <?= !empty($ak_cfg['melden_ein']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_MELDEN_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= ak_t('EINST.H_MELDEN_EIN') ?></div>
</div>
<div class="sm-feld">
  <label for="melden_alter"><?= ak_e(ak_t('EINST.L_MELDEN_ALTER')) ?></label>
  <input data-role="none" type="number" id="melden_alter" name="melden_alter" value="<?= (int) $ak_cfg['melden_alter'] ?>" min="60" max="86400">
  <div class="sm-hilfe"><?= ak_t('EINST.H_MELDEN_ALTER') ?></div>
</div>

<?php /* MQTT stand bis 0.9.4 hier. Es wohnt jetzt vollstaendig im Reiter
         MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ak_e(ak_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ak_e(ak_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$ak_geraete) { ?>
<div class="sm-warnung"><?= ak_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th><?= ak_e(ak_t('EINST.T_TYP')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_MODELL')) ?></th><th><?= ak_e(ak_t('EINST.T_SN')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_ANLAGE')) ?></th><th><?= ak_e(ak_t('EINST.T_FW')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_ONLINE')) ?></th><th><?= ak_e(ak_t('EINST.T_STUFEN')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_WEG')) ?></th></tr>
<?php foreach ($ak_geraete as $ak_sn => $ak_g) {
    $ak_nr = '';
    foreach ($ak_anlagen as $ak_i => $ak_a) {
        if ($ak_a['site_id'] === $ak_g['site_id']) { $ak_nr = $ak_i; break; }
    }
    $ak_stufen = isset($ak_g['cutoff_stufen']) && is_array($ak_g['cutoff_stufen']) ? $ak_g['cutoff_stufen'] : array(); ?>
<tr><td><?= ak_e($ak_g['name']) ?></td><td><?= ak_e($ak_g['typ']) ?></td>
    <td><?= ak_e($ak_g['pn']) ?></td><td><span class="sm-mono"><?= ak_e($ak_sn) ?></span></td>
    <td><?= ak_e($ak_nr) ?></td><td><?= ak_e($ak_g['fw']) ?></td>
    <td class="<?= $ak_g['online'] ? 'sm-an' : 'sm-aus' ?>"><?= $ak_g['online'] ? ak_e(ak_t('ALLG.JA')) : ak_e(ak_t('ALLG.NEIN')) ?></td>
    <td><?= $ak_stufen ? ak_e(implode(', ', $ak_stufen)) . ' %' : '&mdash;' ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_befehlsweg('hauslast', (int) $ak_g['generation'])) ?></span></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= ak_t('EINST.H_WEG') ?></p>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $ak_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2><?= ak_e(ak_t('EINST.H_MQTT')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($ak_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= ak_e(ak_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= ak_e($ak_cfg['mqtt_topic']) ?>" placeholder="ankersolix">
  <div class="sm-hilfe"><?= ak_t('EINST.H_MQTT_TOPIC') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_nur_aenderung" value="1" <?= !empty($ak_cfg['mqtt_nur_aenderung']) ? 'checked' : '' ?>>
    <?= ak_e(ak_t('EINST.L_MQTT_AENDERUNG')) ?>
  </label>
  <div class="sm-hilfe"><?= ak_t('EINST.H_MQTT_AENDERUNG') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ak_e(ak_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ak_e(ak_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= ak_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>

<?php if (!$ak_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= ak_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$ak_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= ak_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ak_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>

<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $ak_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $ak_mqtt['autostart'] ? ak_e(ak_t('ALLG.EIN')) : ak_e(ak_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= ak_e(ak_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= ak_e($ak_mqtt['broker']) ?>:<?= ak_e($ak_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $ak_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= ak_e(ak_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($ak_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($ak_cfg['mqtt_ein']) ? ak_e(ak_t('ALLG.EIN')) : ak_e(ak_t('ALLG.AUS')) ?></td></tr>
</table>

<h2><?= ak_e(ak_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= ak_t('MQTT.ABO_WARNUNG') ?></div>
<div class="sm-step">
<?= ak_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= ak_e($ak_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= ak_e(ak_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= ak_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('MQTT.T_THEMA')) ?></th><th><?= ak_e(ak_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_mqtt_themen() as $ak_thema => $ak_schluessel) { ?>
<tr><td><span class="sm-mono"><?= ak_e($ak_cfg['mqtt_topic'] . '/' . $ak_thema) ?></span></td>
    <td><?= ak_t($ak_schluessel) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= ak_t('MQTT.PLATZHALTER') ?></p>
<div class="sm-warnung"><?= ak_t('MQTT.TS_HINWEIS') ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ak_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= ak_e(ak_t('LOX.H_TITEL')) ?></h2>
<p><?= ak_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S1_TITEL')) ?></b><br>
<?= ak_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S2_TITEL')) ?></b><br>
<?= ak_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= ak_e($ak_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= ak_t('LOX.S2_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S3_TITEL')) ?></b><br>
<?= ak_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'status', 'anlage' => 1))) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ZYKLUS')) ?></td><td>60 <?= ak_e(ak_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= ak_t('LOX.S3_BEFEHLE') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_TITEL')) ?></th><th><?= ak_e(ak_t('LOX.T_BEFEHL')) ?></th>
    <th><?= ak_e(ak_t('LOX.T_EINHEIT')) ?></th><th><?= ak_e(ak_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_felder_zeile('status') as $ak_feld => $ak_info) { ?>
<tr><td><span class="sm-mono">ANKER_1_<?= ak_e($ak_feld) ?></span></td>
    <td><span class="sm-mono"><?= ak_e(ak_check($ak_feld)) ?></span></td>
    <td><?= ak_e($ak_info[0]) ?></td><td><?= ak_t($ak_info[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= ak_t('LOX.S3_SEMIKOLON') ?></div>
<div class="sm-warnung"><?= ak_t('LOX.S3_STRICH') ?></div>
<?php if (count($ak_anlagen) > 1) { ?>
<p><b><?= ak_e(ak_t('LOX.MEHRERE_ANLAGEN')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.ANLAGE')) ?></th><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($ak_anlagen as $ak_nr => $ak_an) { ?>
<tr><td><?= ak_e($ak_nr) ?></td><td><?= ak_e($ak_an['name']) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'status', 'anlage' => (int) $ak_nr))) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S4_TITEL')) ?></b><br>
<?= ak_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></td><td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'energie', 'anlage' => 1))) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= ak_e(ak_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_BEFEHL')) ?></th><th><?= ak_e(ak_t('LOX.T_EINHEIT')) ?></th><th><?= ak_e(ak_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_felder_zeile('energie') as $ak_feld => $ak_info) { ?>
<tr><td><span class="sm-mono"><?= ak_e(ak_check($ak_feld)) ?></span></td>
    <td><?= ak_e($ak_info[0]) ?></td><td><?= ak_t($ak_info[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?= ak_t('LOX.S4_ZEITRAUM') ?></div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S5_TITEL')) ?></b><br>
<?= ak_t('LOX.S5_TEXT') ?>
<?php if (!$ak_geraete) { ?>
<div class="sm-warnung"><?= ak_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th><?= ak_e(ak_t('EINST.T_SN')) ?></th>
    <th><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($ak_geraete as $ak_sn => $ak_g) { ?>
<tr><td><?= ak_e($ak_g['name']) ?></td><td><span class="sm-mono"><?= ak_e($ak_sn) ?></span></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'geraet', 'sn' => $ak_sn))) ?></span></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_BEFEHL')) ?></th><th><?= ak_e(ak_t('LOX.T_EINHEIT')) ?></th><th><?= ak_e(ak_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_felder_zeile('geraet') as $ak_feld => $ak_info) { ?>
<tr><td><span class="sm-mono"><?= ak_e(ak_check($ak_feld)) ?></span></td>
    <td><?= ak_e($ak_info[0]) ?></td><td><?= ak_t($ak_info[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S6_TITEL')) ?></b><br>
<?= ak_t('LOX.S6_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= ak_e($ak_host) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_HAUSLAST')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'hauslast', 'anlage' => 1), false)) ?>&amp;watt=&lt;v&gt;</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_MODUS')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'modus', 'anlage' => 1, 'wert' => 'eigenverbrauch'), false)) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_RESERVE')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'reserve', 'anlage' => 1), false)) ?>&amp;prozent=&lt;v&gt;</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_EINSPEISUNG')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'einspeisung', 'anlage' => 1, 'wert' => 'aus'), false)) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_GRENZE')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'einspeisegrenze', 'anlage' => 1), false)) ?>&amp;watt=&lt;v&gt;</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_NOTSTROM')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'notstromreserve', 'anlage' => 1), false)) ?>&amp;prozent=&lt;v&gt;</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_PVLIMIT')) ?></td>
    <td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'pvlimit'), false)) ?>&amp;sn=SN&amp;watt=&lt;v&gt;</span></td></tr>
</table>
</div>
<?= ak_t('LOX.S6_MODI') ?>
<div class="sm-warnung"><?= ak_t('LOX.S6_WARNUNG') ?></div>
<div class="sm-warnung"><?= ak_t('LOX.S6_UNGEPRUEFT') ?></div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S7_TITEL')) ?></b><br>
<?= ak_t('LOX.S7_TEXT') ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S8_TITEL')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= ak_e($ak_token) ?></span></td></tr>
</table>
<?= ak_t('LOX.S8_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= ak_e(ak_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S9_TITEL')) ?></b><br>
<?= ak_t('LOX.S9_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ak_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="status">
    <input data-role="none" type="hidden" name="vorlage_nr" value="1">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ak_e(ak_t('LOX.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="energie">
    <input data-role="none" type="hidden" name="vorlage_nr" value="1">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ak_e(ak_t('LOX.K_VORLAGE_ENERGIE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="ausgang">
    <input data-role="none" type="hidden" name="vorlage_nr" value="1">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ak_e(ak_t('LOX.K_VORLAGE_AUSGANG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="alles">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ak_e(ak_t('LOX.K_VORLAGE_ALLES')) ?></button>
  </form>
</div>
<div class="sm-warnung"><?= ak_t('LOX.VORLAGE_TOKEN_WARNUNG') ?></div>
<?php if (count($ak_anlagen) > 1 || count($ak_geraete) > 0) { ?>
<p class="sm-hilfe"><?= ak_t('LOX.VORLAGE_EINZELN') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_SATZ')) ?></th><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th>&nbsp;</th></tr>
<?php foreach ($ak_anlagen as $ak_nr => $ak_an) {
    foreach (array('status', 'energie', 'ausgang') as $ak_art) { ?>
<tr><td><?= ak_e(ak_t('LOX.SATZ_' . strtoupper($ak_art))) ?> <?= ak_e($ak_nr) ?></td>
    <td><?= ak_e($ak_an['name']) ?></td>
    <td><form action="index.php" method="post">
      <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
      <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
      <input data-role="none" type="hidden" name="vorlage" value="<?= ak_e($ak_art) ?>">
      <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ak_e($ak_nr) ?>">
      <button data-role="none" class="sm-btn sm-b-technik" style="min-width:150px;" type="submit"><?= ak_e(ak_t('LOX.K_ERZEUGEN')) ?></button>
    </form></td></tr>
<?php } } ?>
<?php foreach ($ak_geraete as $ak_sn => $ak_g) { ?>
<tr><td><?= ak_e(ak_t('LOX.SATZ_GERAET')) ?></td>
    <td><?= ak_e($ak_g['name']) ?> (<span class="sm-mono"><?= ak_e($ak_sn) ?></span>)</td>
    <td><form action="index.php" method="post">
      <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
      <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
      <input data-role="none" type="hidden" name="vorlage" value="geraet">
      <input data-role="none" type="hidden" name="vorlage_sn" value="<?= ak_e($ak_sn) ?>">
      <button data-role="none" class="sm-btn sm-b-technik" style="min-width:150px;" type="submit"><?= ak_e(ak_t('LOX.K_ERZEUGEN')) ?></button>
    </form></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * Je Zeile: Nummer, Typ, Name, Parameter, woran die Eingaenge kommen.
 * Typ, Name und Parameter stehen als Sprachschluessel drin, die Eingangsspalte
 * ist symbolisch und damit sprachfrei.
 */
function ak_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',      'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',      'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',      'BAUSTEIN.N06', 'BAUSTEIN.P06', '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',      'BAUSTEIN.N07', 'BAUSTEIN.P07', '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',      'BAUSTEIN.N08', 'BAUSTEIN.P08', '&mdash;'),
        array(9,  'BAUSTEIN.T_SWS',     'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #7'),
        array(10, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N10', '',             'I &larr; #8'),
        array(11, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N11', '',             'I1 &larr; #9, I2 &larr; #10'),
        array(12, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N12', 'BAUSTEIN.P12', 'I &larr; #11'),
        array(13, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; #1'),
        array(15, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I1 &larr; #1, I2 &larr; #3'),
        array(16, 'BAUSTEIN.T_VEZ',     'BAUSTEIN.N16', 'BAUSTEIN.P16', '&mdash;'),
        array(17, 'BAUSTEIN.T_VERGL',   'BAUSTEIN.N17', 'BAUSTEIN.P17', 'I1 &larr; #1, I2 &larr; #16'),
        array(18, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N18', 'BAUSTEIN.P18', 'I1 &larr; #5'),
        array(19, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N19', 'BAUSTEIN.P19', 'I1 &larr; #6'),
        array(20, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N20', 'BAUSTEIN.P20', '&mdash;'),
        array(21, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N21', 'BAUSTEIN.P21', 'I1 &larr; #18, I2 &larr; #17, I3 &larr; #20'),
        array(22, 'BAUSTEIN.T_IMPULS',  'BAUSTEIN.N22', 'BAUSTEIN.P22', '&mdash;'),
        array(23, 'BAUSTEIN.T_ANALOGSP','BAUSTEIN.N23', 'BAUSTEIN.P23', 'I &larr; #21, ' . ak_t('BAUSTEIN.TRIGGER') . ' &larr; #22'),
        array(24, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N24', 'BAUSTEIN.P24', 'I1 &larr; #23, I2 &larr; #22'),
        array(25, 'BAUSTEIN.T_VA',      'BAUSTEIN.N25', 'BAUSTEIN.P25', 'I &larr; #24'),
        array(26, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N26', 'BAUSTEIN.P26', 'I1..I6 &larr; ' . ak_t('BAUSTEIN.ENERGIE_VE')),
        array(27, 'BAUSTEIN.T_VE',      'BAUSTEIN.N27', 'BAUSTEIN.P27', '&mdash;'),
        array(28, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N28', 'BAUSTEIN.P28', 'I &larr; #27'),
        array(29, 'BAUSTEIN.T_VA',      'BAUSTEIN.N29', 'BAUSTEIN.P29', 'I &larr; #28'),
        array(30, 'BAUSTEIN.T_VEZ',     'BAUSTEIN.N30', 'BAUSTEIN.P30', '&mdash;'),
    );
}
?>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S10_TITEL')) ?></b><br>
<?= ak_t('LOX.S10_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= ak_e(ak_t('LOX.T_BAUSTEIN')) ?></th><th><?= ak_e(ak_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= ak_e(ak_t('LOX.T_PARAMETER')) ?></th><th><?= ak_e(ak_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (ak_bausteine() as $ak_b) { ?>
<tr><td><?= (int) $ak_b[0] ?></td><td><?= ak_t($ak_b[1]) ?></td><td><?= ak_t($ak_b[2]) ?></td>
    <td><?= $ak_b[3] !== '' ? ak_t($ak_b[3]) : '&mdash;' ?></td><td><?= $ak_b[4] ?></td></tr>
<?php } ?>
</table>
</div>
<?= ak_t('LOX.S10_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S11_TITEL')) ?></b><br>
<?= ak_t('LOX.S11_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_PRUEFUNG')) ?></th><th><?= ak_e(ak_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'selftest'))) ?></span></td>
    <td><span class="sm-mono">SELFTEST;OK=1;TOKEN=OK</span></td></tr>
<tr><td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'status', 'anlage' => 1))) ?></span></td>
    <td><span class="sm-mono">ANKER;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= ak_e($ak_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= ak_e(ak_adresse(array('aktion' => 'quatsch'))) ?></span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $ak_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= ak_e(ak_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= ak_t('TEST.EINLEITUNG') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= ak_e(ak_t('TEST.T_FRAGE')) ?></th><th><?= ak_e(ak_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (ak_pruefungen() as $ak_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($ak_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($ak_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $ak_z['frage'] ?></td><td><?= $ak_z['antwort'] ?></td></tr>
<?php } ?>
</table>
</div>

<h3><?= ak_e(ak_t('TEST.H_LESEN')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ak_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= ak_e(ak_adresse(array('aktion' => 'status', 'anlage' => 1))) ?>" target="_blank"><?= ak_e(ak_t('TEST.K_STATUS')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= ak_e(ak_adresse(array('aktion' => 'energie', 'anlage' => 1))) ?>" target="_blank"><?= ak_e(ak_t('TEST.K_ENERGIE')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= ak_e(ak_adresse(array('aktion' => 'anlagen'))) ?>" target="_blank"><?= ak_e(ak_t('TEST.K_ANLAGEN')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= ak_e(ak_adresse(array('aktion' => 'selftest'))) ?>" target="_blank"><?= ak_e(ak_t('TEST.K_SELFTEST')) ?></a>
</div>

<h3><?= ak_e(ak_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ak_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= ak_e(ak_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= ak_e(ak_adresse(array('aktion' => 'roh'))) ?>" target="_blank"><?= ak_e(ak_t('TEST.K_ABBILD')) ?></a>
  <a data-role="none" class="sm-btn sm-b-technik" href="index.php?form=test&amp;rohdaten=1"><?= ak_e(ak_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($ak_testausgabe !== '') { ?>
<div class="sm-pre"><?= ak_e($ak_testausgabe) ?></div>
<?php } ?>

<?php
/* Die Rohdaten der Cloud - mit den ECHTEN Feldnamen.
 *
 * Bis 0.9.6 versprach die Hilfe genau das vom Knopf "Rohdaten als JSON
 * ansehen", und der zeigte das bereits umgesetzte Abbild mit den Namen dieses
 * Plugins. Fuer eine Fassung, deren erklaerter Zweck das Nachmessen der
 * Feldnamen ist, war das die teuerste Luecke.
 *
 * Sie werden NUR hier gezeigt, im angemeldeten Bereich: der Block 'account'
 * traegt die Kontokennung, und der tokengeschuetzte Endpunkt ist im
 * unangemeldeten Bereich erreichbar. */
if (isset($_GET['rohdaten'])) {
    $ak_roh = ak_cache();
    echo '<h3>' . ak_e(ak_t('TEST.H_ROHDATEN')) . '</h3>';
    echo '<div class="sm-warnung">' . ak_t('TEST.ROHDATEN_WARNUNG') . '</div>';
    if (!$ak_roh) {
        echo '<div class="sm-hinweis">' . ak_t('TEST.ROHDATEN_LEER') . '</div>';
    } else {
        echo '<p class="sm-hilfe">' . sprintf(ak_t('TEST.ROHDATEN_STAND'),
            ak_e(date('d.m.Y H:i:s', (int) (isset($ak_roh['ts']) ? $ak_roh['ts'] : 0)))) . '</p>';
        echo '<div class="sm-pre" style="max-height:520px;">'
           . ak_e(json_encode($ak_roh, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</div>';
    }
}
?>

<h3><?= ak_e(ak_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= ak_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($ak_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= ak_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_anlage"><?= ak_e(ak_t('TEST.L_ANLAGE')) ?></label>
  <input data-role="none" type="number" id="test_anlage" name="test_anlage" value="1" min="1" max="99">
</div>
<div class="sm-feld">
  <label for="test_watt"><?= ak_e(ak_t('TEST.L_WATT')) ?></label>
  <input data-role="none" type="number" id="test_watt" name="test_watt" value="<?= (int) $ak_cfg['hauslast_min'] ?>" min="-5000" max="5000">
  <div class="sm-hilfe"><?= ak_t('TEST.H_WATT') ?></div>
</div>
<div class="sm-feld">
  <label for="test_prozent"><?= ak_e(ak_t('TEST.L_PROZENT')) ?></label>
  <input data-role="none" type="number" id="test_prozent" name="test_prozent" value="10" min="0" max="100">
  <div class="sm-hilfe"><?= ak_t('TEST.H_PROZENT') ?></div>
</div>
<div class="sm-feld">
  <label for="test_modus"><?= ak_e(ak_t('TEST.L_MODUS')) ?></label>
  <select data-role="none" id="test_modus" name="test_modus">
<?php foreach (array_keys(ak_modi()) as $ak_mo) { ?>
    <option value="<?= ak_e($ak_mo) ?>"><?= ak_e(ak_t('MODUS.' . strtoupper($ak_mo))) ?></option>
<?php } ?>
  </select>
</div>

<h3><?= ak_e(ak_t('TEST.H_TROCKEN')) ?></h3>
<div class="sm-hinweis"><?= ak_t('TEST.TROCKEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="test_trocken"><?= ak_e(ak_t('TEST.L_TROCKEN')) ?></label>
  <select data-role="none" id="test_trocken" name="test_trocken">
    <option value="hauslast"><?= ak_e(ak_t('TEST.K_HAUSLAST')) ?></option>
    <option value="modus"><?= ak_e(ak_t('TEST.K_MODUS')) ?></option>
    <option value="reserve"><?= ak_e(ak_t('TEST.K_RESERVE')) ?></option>
  </select>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ak_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trockenlauf" value="1"><?= ak_e(ak_t('TEST.K_TROCKEN')) ?></button>
</div>

<h3><?= ak_e(ak_t('TEST.H_SCHARF')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= ak_e(ak_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="hauslast_test"><?= ak_e(ak_t('TEST.K_HAUSLAST')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="modus_test"><?= ak_e(ak_t('TEST.K_MODUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="reserve_test"><?= ak_e(ak_t('TEST.K_RESERVE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="notstrom_test"><?= ak_e(ak_t('TEST.K_NOTSTROM')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="grenze_test"><?= ak_e(ak_t('TEST.K_GRENZE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="einspeisung_aus"><?= ak_e(ak_t('TEST.K_EINSP_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="einspeisung_ein"><?= ak_e(ak_t('TEST.K_EINSP_EIN')) ?></button>
</div>
</form>

<?php if ($ak_trockenlauf) { ?>
<h3><?= ak_e(ak_t('TEST.H_TROCKEN_ERGEBNIS')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= ak_e(ak_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach ($ak_trockenlauf as $ak_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($ak_z[0] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($ak_z[0] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= ak_e($ak_z[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<div class="sm-warnung"><b><?= ak_e(ak_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= ak_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $ak_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= ak_e(ak_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= ak_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= ak_e($ak_p['log']) ?></span></p>
<?php if ($ak_logzeilen) { ?>
<div class="sm-log"><?= ak_e(implode("\n", $ak_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ak_t('LOG.LEER') ?></div>
<?php } ?>

<h2><?= ak_e(ak_t('LOG.H_START')) ?></h2>
<p class="sm-hilfe"><?= ak_t('LOG.START_ERKLAERUNG') ?><br>
<span class="sm-mono"><?= ak_e($ak_p['startlog']) ?></span></p>
<?php if ($ak_startlog) { ?>
<div class="sm-log" style="max-height:220px;"><?= ak_e(implode("\n", $ak_startlog)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ak_t('LOG.LEER') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ak_e($ak_formtoken) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= ak_e(ak_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($ak_tab) ?>);
})();
</script>
<?php
if ($ak_rahmen) {
    LBWeb::lbfooter();
}
