<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Anker SOLIX (Gruppe 26, 0.9.32).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.1)
 * abonniert <praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - ak_befehl_annehmen() (Freigabe, Dienst,
 * Abrufbremse, Schreiber-Wache, Gleichwert-Unterdrueckung X-7, Einreihen, Warten auf den Dienst, Schrittweite)
 * - mit der Kennung von=mqtt und der Quelle 'mqtt', und gibt array(ok, grund) fuer die Quittung zurueck
 * (ak_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht: das Plugin ist selbst der Empfaenger.
 *
 * Sperrminute 0 fuer alle Sollwerte (Begruendung am Eintrag, Entwurf N2/E-E3): die Bruecke wuerde einen gleichen
 * Wert binnen 60 s mit "abgelehnt: wiederholt" beantworten, ohne tun() zu rufen - dann setzte niemand den Merker
 * "Sollwert empfangen" (Entscheidung Nr. 22), und der Rueckfall griffe, obwohl Loxone lebt. Gleiche Werte faengt
 * die Gleichwert-Unterdrueckung der Linie ab (SET;OK=1;UNVERAENDERT=1 -> "angenommen"), zu viele Anfragen die
 * Schreibbremse des Dienstes. abruf: 30 s wie die Abrufbremse des Endpunkts.
 *
 * Anlage 1 ohne Nummer, Anlage 2 bis 9 als <praefix>/befehl/<nr>/<befehl> (Form Marstek {nr}).
 * pvlimit nicht ueber MQTT (N7): es braucht die Seriennummer, und die Ebenen der Bruecke kennen nur a-z, 0-9, _
 * (Seriennummern der Anker-Geraete tragen Grossbuchstaben, und sie stuende dann in Thema und Quittung); es
 * bleibt beim HTTP-Weg mit Token.
 * Zweiter Haken ist der vorhandene "Schreibende Befehle zulassen" (steuerung_ein, Entscheidung V1); der erste
 * ist "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus, wirkt nur mit mqtt_ein).
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('ak_config')) {
    $ak_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ak_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ak_lib.php';
    }
    $ak_kand[] = dirname(__DIR__) . '/webfrontend/html/ak_lib.php';
    foreach ($ak_kand as $ak_k) {
        if (is_file($ak_k)) {
            require_once $ak_k;
            break;
        }
    }
}
$ak_sperre_grund = 'Loxone sendet die Hauslast im Regeltakt (Musterprojekt 60 s) oft mit gleichem Wert; die '
    . 'Gleichwert-Unterdrueckung der Linie (60 s, SET;OK=1;UNVERAENDERT=1) und die Schreibbremse des Dienstes sind die '
    . 'eine Pruefung, und nur ein angenommener Wert haelt den Rueckfall auf (Entscheidung Nr. 22).';
$ak_tun = function ($aktion, $feld) {
    return function ($w, $t) use ($aktion, $feld) {
        $b = array('aktion' => $aktion, 'anlage' => isset($t['nr']) ? (string) (int) $t['nr'] : '1');
        if ($feld === 'wert_schalter') {
            $b['wert'] = ((string) $w === '1') ? 'ein' : 'aus';
        } elseif ($feld !== '') {
            $b[$feld] = ($feld === 'wert') ? (string) $w : (int) $w;
        }
        return ak_befehl_quittung(ak_befehl_annehmen($b, 'mqtt', 'mqtt'));
    };
};
$ak_befehle = array();
foreach (array('' => '', '{nr}/' => ' Anlage N') as $ak_vor => $ak_tn) {
    $ak_befehle[$ak_vor . 'hauslast'] = array('art' => 'ganz', 'min' => 0, 'max' => 5000, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Hauslast setzen' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_HAUSLAST',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('hauslast', 'watt'));
    $ak_befehle[$ak_vor . 'modus'] = array('art' => 'auswahl',
        'werte' => array('eigenverbrauch', 'steckdosen', 'manuell', 'zeitplan', 'smart', 'zeitfenster'),
        'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Betriebsart' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_MODUS',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('modus', 'wert'));
    $ak_befehle[$ak_vor . 'reserve'] = array('art' => 'ganz', 'min' => 0, 'max' => 100, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Entladegrenze setzen' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_RESERVE',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('reserve', 'prozent'));
    $ak_befehle[$ak_vor . 'einspeisung'] = array('art' => 'schalter', 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Netzeinspeisung' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_EINSPEISUNG',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('einspeisung', 'wert_schalter'));
    $ak_befehle[$ak_vor . 'einspeisegrenze'] = array('art' => 'ganz', 'min' => 0, 'max' => 20000, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Einspeisegrenze setzen' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_GRENZE',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('einspeisegrenze', 'watt'));
    $ak_befehle[$ak_vor . 'notstromreserve'] = array('art' => 'ganz', 'min' => 0, 'max' => 100, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $ak_sperre_grund,
        'titel' => 'Anker SOLIX Notstromreserve setzen' . $ak_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_NOTSTROM',
        'quittung' => 'ausgefuehrt', 'tun' => $ak_tun('notstromreserve', 'prozent'));
}
$ak_befehle['abruf'] = array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 30,
    'begruendung' => 'wie die Abrufbremse des Endpunkts: hoechstens ein Sofortabruf je 30 s',
    'titel' => 'Anker SOLIX Sofort abrufen (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF',
    'quittung' => 'uebergeben', 'tun' => $ak_tun('abruf', ''));
return array(
    'fassung' => 1,
    'linie'   => 'ankersolix',
    'plugin'  => 'Anker SOLIX',
    'geladen' => function_exists('ak_befehl_annehmen') && function_exists('ak_befehl_quittung'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        // ak_config(false): wie der unangemeldete Endpunkt legt die Bruecke nichts an und heilt nichts.
        $c = ak_config(false);
        return array('praefix'     => (string) $c['mqtt_topic'],
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                     'eingreifend' => !empty($c['steuerung_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        // Ungebremst ins Protokoll des Plugins (wie marstek_log) - jeder Befehl steht dort. Der Text traegt
        // schon "Befehlsbruecke: " (bb_log()); WARN heisst im Protokoll WARNING wie beim Dienst.
        $ak_st = (string) $stufe === 'WARN' ? 'WARNING' : ((string) $stufe === '' ? 'INFO' : (string) $stufe);
        ak_wache_log($text, $ak_st);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $ak_befehle,
);
