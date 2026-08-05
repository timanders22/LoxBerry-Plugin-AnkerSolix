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

/* Aktiver Reiter. Wer einen Reiter hinzufuegt, muss diese Positivliste
 * mitziehen - sonst springt die Seite nach jedem Absenden zurueck auf
 * Einstellungen, obwohl der Reiter sichtbar und anklickbar ist. */
$ak_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$ak_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($ak_muster, (string) $_POST['activetab'])) {
    $ak_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($ak_muster, 'tab-' . (string) $_GET['form'])) {
    $ak_tab = 'tab-' . (string) $_GET['form'];
}

$ak_meldungen = array();   // Erfolgsmeldungen
$ak_fehler = array();      // Beanstandungen - gesammelt, nicht ueberschrieben
$ak_testausgabe = '';
$ak_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Vorlage herunterladen ---------------- */
if ($ak_post && isset($_POST['vorlage'])) {
    $ak_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage']) ? (int) $_POST['vorlage'] : 1;
    list($ak_name, $ak_inhalt) = ak_vorlage($ak_nr);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $ak_name . '"');
    echo $ak_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($ak_post && isset($_POST['speichern'])) {
    $ak_cfg = ak_config();

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
        'endpunkt_limit' => array(1, 60),
        'hauslast_min'   => array(0, 5000),
        'hauslast_max'   => array(0, 5000),
        'verlauf_tage'   => array(1, 90),
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

    $ak_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $ak_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;

    $ak_topic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['mqtt_topic']));
    if ($ak_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $ak_topic)) {
        $ak_fehler[] = ak_t('EINST.FEHLER_TOPIC');
    } else {
        $ak_cfg['mqtt_topic'] = trim($ak_topic, '/');
    }

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

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($ak_post && isset($_POST['dienst'])) {
    $ak_befehl = (string) $_POST['dienst'];
    list($ak_ok, $ak_ausgabe) = ak_dienst($ak_befehl);
    if ($ak_ok) {
        $ak_meldungen[] = ak_t('EINST.DIENST_' . strtoupper($ak_befehl)) . ' ' . ak_e($ak_ausgabe);
    } else {
        $ak_fehler[] = ak_e($ak_ausgabe);
    }
    $ak_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($ak_post && isset($_POST['token_neu'])) {
    $ak_cfg = ak_config();
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
if ($ak_post && isset($_POST['test'])) {
    list($ak_stand, $ak_text) = ak_test_aktion((string) $_POST['test']);
    if ($ak_stand === 1) {
        $ak_meldungen[] = ak_e($ak_text);
    } elseif ($ak_stand === 2) {
        $ak_fehler[] = ak_e($ak_text);
    } else {
        $ak_fehler[] = ak_e($ak_text);
    }
    $ak_tab = 'tab-test';
}
if ($ak_post && isset($_POST['selbsttest'])) {
    $ak_testausgabe = ak_selbsttest();
    $ak_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$ak_cfg = ak_config();
$ak_token = ak_token();
$ak_zg = ak_zugang();
$ak_anlagen = ak_anlagen();
$ak_geraete = ak_geraete();
$ak_zustand = ak_zustand();
$ak_alter = ak_alter();
$ak_pid = ak_dienst_pid();
$ak_mqtt = ak_mqtt_zustand();
$ak_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$ak_basis = 'http://' . $ak_host . '/plugins/' . $ak_p['plugin'] . '/index.php';
$ak_logzeilen = array();
if (is_file($ak_p['log'])) {
    $ak_logzeilen = array_slice(
        array_reverse(file($ak_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

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
</div>

<?php if (!empty($ak_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= ak_e(ak_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= ak_e($ak_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($ak_anlagen as $ak_nr => $ak_an) { ?>
<div class="sm-hinweis">
<b><?= ak_e($ak_an['name']) ?></b> (<?= ak_e(ak_t('ALLG.ANLAGE')) ?> <?= ak_e($ak_nr) ?>)
&middot; <?= ak_e(ak_t('ALLG.SOC')) ?> <b><?= $ak_an['soc'] === null ? '&ndash;' : ak_e($ak_an['soc']) . ' %' ?></b>
&middot; <?= ak_e(ak_t('ALLG.PV')) ?> <?= $ak_an['pv'] === null ? '&ndash;' : ak_e($ak_an['pv']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.BATTERIE')) ?> <?= $ak_an['batp'] === null ? '&ndash;' : ak_e($ak_an['batp']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.HAUS')) ?> <?= $ak_an['haus'] === null ? '&ndash;' : ak_e($ak_an['haus']) . ' W' ?>
&middot; <?= ak_e(ak_t('ALLG.MODUS')) ?> <?= ak_e($ak_an['modus_text']) ?>
<div style="margin-top:8px;"><?= ak_soc_svg(ak_verlauf_lesen((int) $ak_nr)) ?></div>
<div class="sm-hilfe"><?= ak_e(ak_t('ALLG.VERLAUF_HINWEIS')) ?></div>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     faellt das Skript aus, ist die Seite weiterhin bedienbar. -->
<div class="sm-tabs">
	<a class="sm-tab" data-ziel="tab-settings" href="index.php?form=settings"><?= ak_e(ak_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab" data-ziel="tab-mqtt"     href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= ak_e(ak_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab" data-ziel="tab-test"     href="index.php?form=test"><?= ak_e(ak_t('REITER.TEST')) ?></a>
	<a class="sm-tab" data-ziel="tab-log"      href="index.php?form=log"><?= ak_e(ak_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite" id="tab-settings">

<h2><?= ak_e(ak_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= ak_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ak_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= ak_e(ak_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= ak_e(ak_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= ak_e(ak_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
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
  <label for="endpunkt_limit"><?= ak_e(ak_t('EINST.L_ENDPUNKT_LIMIT')) ?></label>
  <input data-role="none" type="number" id="endpunkt_limit" name="endpunkt_limit" value="<?= (int) $ak_cfg['endpunkt_limit'] ?>" min="1" max="60">
  <div class="sm-hilfe"><?= ak_t('EINST.H_ENDPUNKT_LIMIT') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_tage"><?= ak_e(ak_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $ak_cfg['verlauf_tage'] ?>" min="1" max="90">
  <div class="sm-hilfe"><?= ak_t('EINST.H_VERLAUF_TAGE') ?></div>
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
<div class="sm-feld">
  <label for="wartezeit"><?= ak_e(ak_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $ak_cfg['wartezeit'] ?>" min="0" max="20">
  <div class="sm-hilfe"><?= ak_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2>MQTT</h2>
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

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= ak_e(ak_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ak_e(ak_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$ak_geraete) { ?>
<div class="sm-warnung"><?= ak_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th><?= ak_e(ak_t('EINST.T_TYP')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_MODELL')) ?></th><th><?= ak_e(ak_t('EINST.T_SN')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_ANLAGE')) ?></th><th><?= ak_e(ak_t('EINST.T_FW')) ?></th>
    <th><?= ak_e(ak_t('EINST.T_ONLINE')) ?></th></tr>
<?php foreach ($ak_geraete as $ak_sn => $ak_g) {
    $ak_nr = '';
    foreach ($ak_anlagen as $ak_i => $ak_a) {
        if ($ak_a['site_id'] === $ak_g['site_id']) { $ak_nr = $ak_i; break; }
    } ?>
<tr><td><?= ak_e($ak_g['name']) ?></td><td><?= ak_e($ak_g['typ']) ?></td>
    <td><?= ak_e($ak_g['pn']) ?></td><td><span class="sm-mono"><?= ak_e($ak_sn) ?></span></td>
    <td><?= ak_e($ak_nr) ?></td><td><?= ak_e($ak_g['fw']) ?></td>
    <td class="<?= $ak_g['online'] ? 'sm-an' : 'sm-aus' ?>"><?= $ak_g['online'] ? ak_e(ak_t('ALLG.JA')) : ak_e(ak_t('ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite" id="tab-mqtt">
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
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('MQTT.T_THEMA')) ?></th><th><?= ak_e(ak_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_mqtt_themen() as $ak_thema => $ak_schluessel) { ?>
<tr><td><span class="sm-mono"><?= ak_e($ak_cfg['mqtt_topic'] . '/' . $ak_thema) ?></span></td>
    <td><?= ak_t($ak_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= ak_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite" id="tab-loxone">
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
    <td><span class="sm-mono"><?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=status&amp;anlage=1</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ZYKLUS')) ?></td><td>60 <?= ak_e(ak_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= ak_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_TITEL')) ?></th><th><?= ak_e(ak_t('LOX.T_BEFEHL')) ?></th>
    <th><?= ak_e(ak_t('LOX.T_EINHEIT')) ?></th><th><?= ak_e(ak_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_status_felder() as $ak_feld => $ak_info) { ?>
<tr><td><span class="sm-mono">ANKER_1_<?= ak_e($ak_feld) ?></span></td>
    <td><span class="sm-mono">\i<?= ak_e($ak_feld) ?>=\i\v</span></td>
    <td><?= ak_e($ak_info[0]) ?></td><td><?= ak_t($ak_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= ak_t('LOX.S3_STRICH') ?></div>
<?php if (count($ak_anlagen) > 1) { ?>
<p><b><?= ak_e(ak_t('LOX.MEHRERE_ANLAGEN')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.ANLAGE')) ?></th><th><?= ak_e(ak_t('EINST.T_NAME')) ?></th><th><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($ak_anlagen as $ak_nr => $ak_an) { ?>
<tr><td><?= ak_e($ak_nr) ?></td><td><?= ak_e($ak_an['name']) ?></td>
    <td><span class="sm-mono"><?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=status&amp;anlage=<?= ak_e($ak_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="1">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= ak_e(ak_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ak_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S4_TITEL')) ?></b><br>
<?= ak_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ADRESSE')) ?></td><td><span class="sm-mono"><?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=energie&amp;anlage=1</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= ak_e(ak_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_BEFEHL')) ?></th><th><?= ak_e(ak_t('LOX.T_EINHEIT')) ?></th><th><?= ak_e(ak_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ak_energie_felder() as $ak_feld => $ak_info) { ?>
<tr><td><span class="sm-mono">\i<?= ak_e($ak_feld) ?>=\i\v</span></td>
    <td><?= ak_e($ak_info[0]) ?></td><td><?= ak_t($ak_info[1]) ?></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S5_TITEL')) ?></b><br>
<?= ak_t('LOX.S5_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= ak_e($ak_host) ?></span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_HAUSLAST')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ak_e($ak_p['plugin']) ?>/index.php?token=<?= ak_e($ak_token) ?>&amp;aktion=hauslast&amp;anlage=1&amp;watt=&lt;v&gt;</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_MODUS')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ak_e($ak_p['plugin']) ?>/index.php?token=<?= ak_e($ak_token) ?>&amp;aktion=modus&amp;anlage=1&amp;wert=eigenverbrauch</span></td></tr>
<tr><td><?= ak_e(ak_t('LOX.T_VA_RESERVE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ak_e($ak_p['plugin']) ?>/index.php?token=<?= ak_e($ak_token) ?>&amp;aktion=reserve&amp;anlage=1&amp;prozent=10</span></td></tr>
</table>
<?= ak_t('LOX.S5_MODI') ?>
<div class="sm-warnung"><?= ak_t('LOX.S5_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S6_TITEL')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ak_e(ak_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ak_e(ak_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= ak_e($ak_token) ?></span></td></tr>
</table>
<?= ak_t('LOX.S6_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= ak_e(ak_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S7_TITEL')) ?></b><br>
<?= ak_t('LOX.S7_TEXT') ?>
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
    );
}
?>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S8_TITEL')) ?></b><br>
<?= ak_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= ak_e(ak_t('LOX.T_BAUSTEIN')) ?></th><th><?= ak_e(ak_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= ak_e(ak_t('LOX.T_PARAMETER')) ?></th><th><?= ak_e(ak_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (ak_bausteine() as $ak_b) { ?>
<tr><td><?= (int) $ak_b[0] ?></td><td><?= ak_t($ak_b[1]) ?></td><td><?= ak_t($ak_b[2]) ?></td>
    <td><?= $ak_b[3] !== '' ? ak_t($ak_b[3]) : '&mdash;' ?></td><td><?= $ak_b[4] ?></td></tr>
<?php } ?>
</table>
<?= ak_t('LOX.S8_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= ak_e(ak_t('LOX.S9_TITEL')) ?></b><br>
<?= ak_t('LOX.S9_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ak_e(ak_t('LOX.T_PRUEFUNG')) ?></th><th><?= ak_e(ak_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">ANKER;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= ak_e($ak_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite" id="tab-test">
<h2><?= ak_e(ak_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= ak_t('TEST.EINLEITUNG') ?></p>
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

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ak_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ak_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= ak_e(ak_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=status&amp;anlage=1" target="_blank"><?= ak_e(ak_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=energie&amp;anlage=1" target="_blank"><?= ak_e(ak_t('TEST.K_ENERGIE')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=anlagen" target="_blank"><?= ak_e(ak_t('TEST.K_ANLAGEN')) ?></a>
</div>

<h3><?= ak_e(ak_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= ak_e(ak_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= ak_e($ak_basis) ?>?token=<?= ak_e($ak_token) ?>&amp;aktion=roh" target="_blank"><?= ak_e(ak_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($ak_testausgabe !== '') { ?>
<div class="sm-pre"><?= ak_e($ak_testausgabe) ?></div>
<?php } ?>

<h3><?= ak_e(ak_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= ak_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($ak_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= ak_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
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
  <label for="test_modus"><?= ak_e(ak_t('TEST.L_MODUS')) ?></label>
  <select data-role="none" id="test_modus" name="test_modus">
    <option value="eigenverbrauch"><?= ak_e(ak_t('MODUS.EIGENVERBRAUCH')) ?></option>
    <option value="steckdosen"><?= ak_e(ak_t('MODUS.STECKDOSEN')) ?></option>
    <option value="manuell"><?= ak_e(ak_t('MODUS.MANUELL')) ?></option>
    <option value="zeitplan"><?= ak_e(ak_t('MODUS.ZEITPLAN')) ?></option>
    <option value="smart"><?= ak_e(ak_t('MODUS.SMART')) ?></option>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= ak_e(ak_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="hauslast_test"><?= ak_e(ak_t('TEST.K_HAUSLAST')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="modus_test"><?= ak_e(ak_t('TEST.K_MODUS')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= ak_e(ak_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= ak_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite" id="tab-log">
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ak_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
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
	zeige(<?= json_encode($ak_tab) ?>);
})();
</script>
<?php
if ($ak_rahmen) {
    LBWeb::lbfooter();
}
