<?php
/**
 * Anker SOLIX - gemeinsame Bibliothek
 *
 * Liegt bewusst unter webfrontend/html/, weil der Miniserver-Endpunkt sie
 * ebenso braucht wie die Oberflaeche. Nur so gibt es EINE Datei statt zweier
 * Kopien, die auseinanderlaufen. Die Oberflaeche unter htmlauth/ laedt sie von
 * hier (zwei Kandidatenpfade: installiert und im Archiv).
 *
 * Die Bibliothek spricht NIE mit der Anker-Cloud. Sie liest den
 * Zwischenspeicher, den bin/ankersolix.py schreibt, und legt Schreibbefehle in
 * einer Warteschlange ab. Ein Plugin, das den Datenabruf in der Oberflaeche
 * oder im Endpunkt erledigt, ist falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'ak_', weil LBWeb::lbheader() SDK-Globale setzt und gleichnamige
 * Plugin-Variablen ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('ak_e')) {
    function ak_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function ak_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    // Der Rueckfall unten gilt nur AUSSERHALB der Installationslage (ausgepacktes
    // Archiv, Sandkasten: dort heisst der Ordner "html"). Liegt diese Datei
    // unter <home>/webfrontend/html/plugins/<ordner>/, IST <ordner> der Name -
    // auch wenn config/plugins/<ordner>/ gerade fehlt. Bis 0.9.18 fiel eine
    // Zweitinstallation "ankersolix01" in ihrer Upgrade-Luecke (Konfigordner
    // abgeraeumt) auf "ankersolix" zurueck: die Sperre sah auf die fremde
    // Marke, und ein Speichern schrieb in die Zugangsdaten des ANDEREN Plugins
    // (WSL, Pruefung-AnkerSolix-0.9.19, Faelle P3/P4; Regeln/06, "Ein Rueckfall
    // auf den vorgesehenen Ordnernamen ...").
    $ak_hier = @realpath(dirname(__FILE__));
    $ak_soll = $home ? @realpath($home . '/webfrontend/html/plugins/' . $dir) : false;
    $installiert = ($ak_hier !== false && $ak_soll !== false && $ak_hier === $ak_soll);
    if ($home && !$installiert && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'ankersolix') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) {
                $dir = $kand;
                break;
            }
        }
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/ankersolix.json',
            'zugang'    => $home . '/config/plugins/' . $dir . '/zugang.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.ankersolix.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/ankersolix.log',
            'startlog'  => $home . '/log/plugins/' . $dir . '/ankersolix_start.log',
        );
    } else {
        // Nicht installiert (Entwicklung, Attrappe): neben dem Plugin arbeiten.
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/ankersolix.json',
            'zugang'    => $basis . '/config/zugang.json',
            'sicherung' => $basis . '/config/ankersolix.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/ankersolix.log',
            'startlog'  => $basis . '/log/ankersolix_start.log',
        );
    }
    return $p;
}

/**
 * Voreinstellungen.
 *
 * Muessen zu VORGABEN in bin/ankersolix.py passen - zwei Listen an zwei Orten
 * laufen sonst auseinander, und der Unterschied faellt erst auf, wenn die
 * Oberflaeche etwas anderes anzeigt als der Dienst tut. Der Reiter Test misst
 * die Uebereinstimmung deshalb selbst nach.
 *
 * 'aktionstoken' und 'wartezeit' gehoeren nicht zum Dienst: sie betreffen nur
 * Oberflaeche und Endpunkt. ak_vorgaben_dienst() nimmt sie heraus.
 */
function ak_vorgaben()
{
    return array(
        /* --- Konto und Takt --- */
        'land'               => 'DE',
        'intervall'          => 60,
        'takt_details'       => 10,
        'takt_energie'       => 15,
        'takt_prognose'      => 60,
        'endpunkt_limit'     => 10,
        'anfrage_pause'      => 3,     // Zehntelsekunden zwischen zwei Anfragen
        'anfrage_frist'      => 10,    // Sekunden Zeitschranke je Anfrage
        /* --- Abrufumfang: was NICHT geholt wird (spart Anfragen) --- */
        'ohne_details'       => 0,
        'ohne_energie'       => 0,
        'ohne_prognose'      => 1,
        /* --- Ablage --- */
        'verlauf_tage'       => 8,
        'energie_tage'       => 400,
        'zaehler_ein'        => 1,
        /* --- MQTT --- */
        'mqtt_ein'           => 0,
        'mqtt_topic'         => 'ankersolix',
        'mqtt_nur_aenderung' => 0,
        /* --- Steuerung --- */
        'steuerung_ein'      => 0,
        'hauslast_min'       => 0,
        'hauslast_max'       => 1600,
        'anlagen_grenzen'    => array(),   // "1" => array('min'=>0,'max'=>1600)
        'schreibbremse'      => 10,
        'schrittweite'       => 10,
        'rueckfall_min'      => 0,
        'rueckfall_modus'    => 'eigenverbrauch',
        /* --- Meldewege --- */
        'melden_ein'         => 1,
        'melden_alter'       => 900,
        /* --- Oberflaeche und Endpunkt --- */
        'aktionstoken'       => '',
        'wartezeit'          => 6,
    );
}

/**
 * Grenzen der Zahlenfelder - EINE Stelle fuer das Formular und das
 * Zurueckspielen (Befund Oberflaeche 4; Regeln/04 "Eine Grenze steht genau
 * einmal"). Der naheliegende Name ak_grenzen() ist vergeben (Hauslast je
 * Anlage, weiter unten).
 */
function ak_zahlgrenzen()
{
    return array(
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
    );
}

/** Die Haken: gespeichert als 0 oder 1 (int). */
function ak_haken_felder()
{
    return array('ohne_details', 'ohne_energie', 'ohne_prognose', 'zaehler_ein',
                 'mqtt_ein', 'mqtt_nur_aenderung', 'steuerung_ein', 'melden_ein');
}

/** Die Schluessel, die auch der Dienst kennen muss. */
function ak_vorgaben_dienst()
{
    $v = ak_vorgaben();
    unset($v['aktionstoken'], $v['wartezeit']);
    return $v;
}

/**
 * JSON lesen und dabei UNTERSCHEIDEN, ob die Datei fehlt oder kaputt ist.
 *
 * Bis 0.9.6 wurde beides zu array(). Eine abgeschnittene Datei - Stromausfall
 * mitten im Schreiben - ergab damit stillschweigend die Werkseinstellung; weil
 * dann das Token fehlte, wurde sofort zurueckgeschrieben und die intakte
 * Zweitschrift gleich mit ueberschrieben.
 *
 * $stand: 'ok' | 'fehlt' | 'kaputt'
 */
function ak_json_lesen($pfad, &$stand = null)
{
    $stand = 'fehlt';
    if (!is_file($pfad)) {
        return array();
    }
    $roh = @file_get_contents($pfad);
    if ($roh === false) {
        $stand = 'kaputt';
        return array();
    }
    if (trim($roh) === '') {
        // Eine leere Datei ist kein Schaden: genau so legt postinstall.sh an.
        return array();
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        $stand = 'kaputt';
        return array();
    }
    $stand = 'ok';
    return $d;
}

/**
 * Atomar schreiben - und die Rechte gehoeren an das ANLEGEN, nicht hinterher.
 *
 * "Schreiben, dann chmod" laesst die Datei fuer die Dauer des Schreibens mit
 * den Vorgaben der umask stehen. Bei einer Datei mit einem Passwort im
 * Klartext ist das der Unterschied zwischen "kurz lesbar" und "nie lesbar".
 *
 * Die Nebendatei traegt die Prozessnummer im Namen, sonst zerlegen zwei
 * gleichzeitige Schreiber einander die Nebendatei.
 */
function ak_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false. Ohne diese Pruefung
    // schriebe der Aufrufer eine leere Datei - und meldete Erfolg.
    if ($json === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    /* Geschrieben ist erst, was GANZ geschrieben ist und sich so zuruecklesen
     * laesst (Befund Code 1, 29.09.2026): bei voller Karte liefert fwrite()
     * eine kleinere Zahl, nicht false - rename() ersetzte die heile Datei
     * durch die abgeschnittene, und der Aufrufer meldete Erfolg. */
    $n = ftruncate($fh, 0) ? @fwrite($fh, $json) : false;
    $ok = ($n === strlen($json));
    $ok = @fflush($fh) && $ok;
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = (@file_get_contents($tmp) === $json);
    }
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Eine Meldung hoechstens einmal je Zeitfenster ins Protokoll.
 *
 * Der Merker liegt in einer Datei, nicht im Prozess: Oberflaeche und Endpunkt
 * sind kurzlebig, ein Merker im Arbeitsspeicher haelt dort nichts still. Ohne
 * ihn schriebe eine Selbstheilung, die nicht greift, bei JEDEM Aufruf eine
 * Zeile - bei einem Endpunkt, den Loxone im Minutentakt anspricht.
 */
function ak_log_wenn_neu($schluessel, $text, $sekunden = 3600)
{
    $p = ak_paths();
    $merker = $p['datadir'] . '/.meldung_' . preg_replace('/[^a-z0-9_]/', '', $schluessel);
    $letzte = is_file($merker) ? (int) @file_get_contents($merker) : 0;
    if (time() - $letzte < $sekunden) {
        return false;
    }
    if (!is_dir($p['datadir']) && !@mkdir($p['datadir'], 0775, true) && !is_dir($p['datadir'])) {
        return false;
    }
    @file_put_contents($merker, (string) time());
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] WARNING ' . $text . "\n", FILE_APPEND);
    return true;
}

/**
 * Gebremste Protokollzeile des Endpunkts (Verbesserung a4, 30.09.2026).
 *
 * Bis 0.9.23 schrieb der Endpunkt keine Zeile: ob der Miniserver gar nicht
 * anruft, mit einem alten Token anruft oder schaltet, war nicht zu
 * unterscheiden. Jetzt je Weg ('abweisung' = Token fehlt oder falsch,
 * 'befehl' = schaltende Aktion) hoechstens eine Zeile je $fenster Sekunden,
 * mit dem Absender aus REMOTE_ADDR. Dazwischen zaehlt der Merker
 * data/.endpunkt_<weg>.json (unter flock); die naechste Zeile nennt, wie
 * viele Aufrufe seither nicht einzeln protokolliert wurden, 'gesamt' zaehlt
 * alle.
 * Das Token steht nie in der Zeile. Es wird nichts angelegt ausser dem
 * Merker selbst: fehlt der Daten- oder der Logordner, bleibt es bei der
 * Antwort - der unangemeldete Endpunkt legt keine Ordner an.
 * Rueckgabe: true, wenn eine Zeile geschrieben wurde.
 */
function ak_endpunkt_log($weg, $text, $fenster = 600)
{
    $p = ak_paths();
    if (!in_array($weg, array('abweisung', 'befehl'), true)
        || !is_dir($p['datadir']) || !is_dir(dirname($p['log']))) {
        return false;
    }
    $von = isset($_SERVER['REMOTE_ADDR'])
        ? substr(preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']), 0, 45) : '';
    if ($von === '') {
        $von = '?';
    }
    $fh = @fopen($p['datadir'] . '/.endpunkt_' . $weg . '.json', 'c+');
    if ($fh === false) {
        return false;
    }
    if (!@flock($fh, LOCK_EX)) {
        @fclose($fh);
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) {
        $m = array();
    }
    $letzte = isset($m['letzte_zeile']) ? (int) $m['letzte_zeile'] : 0;
    $still = isset($m['still']) ? (int) $m['still'] : 0;
    $gesamt = (isset($m['gesamt']) ? (int) $m['gesamt'] : 0) + 1;
    $jetzt = time();
    $geschrieben = false;
    // Eine zurueckgestellte Uhr ($letzte in der Zukunft) haelt nichts still.
    if ($jetzt - $letzte >= $fenster || $letzte > $jetzt) {
        $zeile = '[' . date('Y-m-d H:i:s') . '] ' . ($weg === 'abweisung' ? 'WARNING' : 'INFO')
               . ' Endpunkt (' . $weg . ') von ' . $von . ': '
               . str_replace(array("\r", "\n"), ' ', (string) $text);
        if ($still > 0 && $letzte > 0) {
            $zeile .= sprintf(' - dazu %d weitere Aufrufe seit %s, nicht einzeln protokolliert',
                $still, date('Y-m-d H:i:s', $letzte));
        }
        $geschrieben = @file_put_contents($p['log'], $zeile . "\n", FILE_APPEND | LOCK_EX) !== false;
    }
    if ($geschrieben) {
        $letzte = $jetzt;
        $still = 0;
    } else {
        $still++;
    }
    $neu = json_encode(array('letzte_zeile' => $letzte, 'still' => $still, 'gesamt' => $gesamt,
                             'letzter_aufruf' => $jetzt, 'letzter_absender' => $von));
    if (@ftruncate($fh, 0) && @rewind($fh)) {
        @fwrite($fh, (string) $neu);
        @fflush($fh);
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return $geschrieben;
}

/**
 * Konfiguration lesen.
 *
 * $erzeugen = false: es wird NICHTS angelegt und NICHTS zurueckgeschrieben.
 * Genau so ruft der unangemeldete Endpunkt auf. Wer sich nicht ausweisen kann,
 * legt nichts an - auch nichts Harmloses. Bis 0.9.6 fuehrte jeder Aufruf des
 * Endpunkts, auch einer ohne Token, ein mkdir() und ein copy() aus, noch bevor
 * das Token geprueft war.
 */
function ak_config($erzeugen = true)
{
    $p = ak_paths();
    $stand = 'fehlt';
    $cfg = ak_json_lesen($p['config'], $stand);

    if ($stand === 'kaputt') {
        // Ungueltiges JSON ist ein Fehler, kein leerer Wert. Die beschaedigte
        // Datei bleibt als .kaputt liegen - sonst liesse sich hinterher nicht
        // mehr feststellen, was verlorenging.
        if ($erzeugen) {
            $ziel = $p['config'] . '.kaputt';
            if (!is_file($ziel)) {
                @copy($p['config'], $ziel);
            }
            ak_log_wenn_neu('config_kaputt',
                'Die Konfiguration ist unlesbar (kein gueltiges JSON). Eine Abschrift liegt als '
                . basename($ziel) . ' daneben; weitergearbeitet wird mit der Zweitschrift.');
        }
        $cfg = array();
        $stand = 'fehlt';
    }

    if ($stand !== 'ok' || !$cfg) {
        // Die Zweitschrift wird GELESEN, nicht kopiert - und nur dort
        // zurueckgeschrieben, wo Schreiben ueberhaupt erlaubt ist.
        $sstand = 'fehlt';
        $sich = ak_json_lesen($p['sicherung'], $sstand);
        if ($sstand === 'ok' && $sich) {
            $cfg = $sich;
            if ($erzeugen && ak_json_schreiben($p['config'], $cfg, 0600)) {
                ak_log_wenn_neu('config_geheilt',
                    'Die Konfiguration fehlte oder war unlesbar und wurde aus der Zweitschrift wiederhergestellt.');
            }
        }
    }
    return array_merge(ak_vorgaben(), is_array($cfg) ? $cfg : array());
}

function ak_config_speichern($cfg)
{
    $p = ak_paths();
    // 0600: die Konfiguration traegt das Aktionstoken (Befund Code 11).
    if (!ak_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    // Die Zweitschrift wird erst erneuert, wenn die Konfiguration ganz
    // geschrieben und zurueckgelesen gleich ist - das prueft der Helfer.
    ak_json_schreiben($p['sicherung'], $cfg, 0600);
    return true;
}

/**
 * Zugangsdaten.
 *
 * Eigene Datei mit Rechten 0600, nicht in der Konfiguration, die die
 * Oberflaeche anzeigt. Das Passwort wird nie zurueckgegeben - nur seine Laenge.
 */
function ak_zugang()
{
    $z = ak_json_lesen(ak_paths()['zugang']);
    return array(
        'email'  => isset($z['email']) ? (string) $z['email'] : '',
        'laenge' => isset($z['passwort']) ? strlen((string) $z['passwort']) : 0,
    );
}

function ak_zugang_speichern($email, $passwort)
{
    $p = ak_paths();
    $alt = ak_json_lesen($p['zugang']);
    $neu = array(
        'email'    => $email !== null ? $email : (isset($alt['email']) ? $alt['email'] : ''),
        // Leeres Feld loescht nichts: kommt das Passwortfeld leer zurueck,
        // bleibt das gespeicherte Passwort stehen.
        'passwort' => ($passwort !== null && $passwort !== '')
                      ? $passwort
                      : (isset($alt['passwort']) ? $alt['passwort'] : ''),
    );
    // Rechte beim ANLEGEN - hier steht ein Passwort im Klartext.
    return ak_json_schreiben($p['zugang'], $neu, 0600);
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function ak_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/** Sorgt dafuer, dass ein Token vorhanden ist, und gibt es zurueck. */
function ak_token()
{
    $cfg = ak_config(true);
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = ak_token_erzeugen();
        ak_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/**
 * Merkmal gegen fremde Absender.
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit. Ein
 * untergeschobenes Formular koennte sonst "Neues Token erzeugen" ausloesen;
 * danach beantwortet der Endpunkt jeden Virtuellen Ausgang mit 403 - und ein
 * Virtueller Ausgang wertet die Antwort nicht aus, der Ausfall bliebe still.
 *
 * Fail closed: ohne hinterlegtes Token gibt es nichts zu vergleichen, und
 * hash_equals('', '') waere wahr.
 */
function ak_formtoken($cfg)
{
    $basis = (string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '');
    if (trim($basis) === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $basis);
}

function ak_formtoken_gueltig($cfg, $eingang)
{
    $soll = ak_formtoken($cfg);
    if ($soll === '' || !is_string($eingang) || $eingang === '') {
        return false;
    }
    return hash_equals($soll, $eingang);
}

/**
 * Themen-Praefix pruefen - EINE Stelle fuer Formular und Sicherung (Befund
 * MQTT M6). Nicht leer, nur A-Z a-z 0-9 _ - /, kein Schraegstrich am Anfang
 * oder Ende, keine zwei hintereinander, hoechstens 64 Zeichen; # und +
 * fallen damit ebenso heraus wie Leerzeichen. Der Dienst prueft dasselbe
 * (praefix_gueltig() in bin/ankersolix.py).
 */
function ak_topic_gueltig($s)
{
    if (!is_string($s) || trim($s) === '') {
        return false;
    }
    if (!preg_match('#^[A-Za-z0-9_/\-]{1,64}\z#', $s)) {
        return false;
    }
    return $s[0] !== '/' && substr($s, -1) !== '/' && strpos($s, '//') === false;
}

/* ---------------- Zwischenspeicher lesen ---------------- */

function ak_loxone()
{
    return ak_json_lesen(ak_paths()['datadir'] . '/loxone.json');
}

function ak_zustand()
{
    return ak_json_lesen(ak_paths()['datadir'] . '/zustand.json');
}

/**
 * Der VOLLSTAENDIGE Zwischenspeicher, so wie ihn die Anker-Cloud geliefert
 * hat - mit den echten Feldnamen.
 *
 * Bis 0.9.6 gab es diese Funktion zwar, aber sie wurde von keiner Zeile
 * aufgerufen. Der Knopf "Rohdaten als JSON ansehen" zeigte statt dessen das
 * bereits umgesetzte Abbild mit den Namen DIESES Plugins - also genau nicht
 * das, wofuer die Hilfe ihn ankuendigt ("dort steht, wie die Felder bei Ihnen
 * wirklich heissen").
 *
 * Diese Daten gehen NICHT ueber den tokengeschuetzten Endpunkt hinaus: der
 * Block 'account' traegt die Kontokennung. Sie sind nur im angemeldeten
 * Bereich zu sehen.
 */
function ak_cache()
{
    return ak_json_lesen(ak_paths()['datadir'] . '/cache.json');
}

/** Anlagen aus dem Abbild, 1-basiert. */
function ak_anlagen()
{
    $l = ak_loxone();
    return isset($l['anlagen']) && is_array($l['anlagen']) ? $l['anlagen'] : array();
}

function ak_geraete()
{
    $l = ak_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/**
 * Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt.
 *
 * Der Zeitstempel wird vom Dienst NUR nach einem erfolgreichen Abruf
 * fortgeschrieben. Bis 0.9.6 geschah das bei jedem Lauf, auch nach einer
 * Fehlerantwort der Cloud - die im Reiter "Einbindung in Loxone" beschriebene
 * Ausfallerkennung ueber ALTER konnte damit nie ansprechen.
 */
function ak_alter()
{
    $l = ak_loxone();
    // ts 0 heisst "noch nie" (Befund Code 3): der Dienst schreibt 0, solange
    // kein Abruf gelungen ist.
    $ts = isset($l['ts']) ? (int) $l['ts'] : 0;
    return $ts > 0 ? max(0, time() - $ts) : -1;
}

/**
 * Der Abruftakt, wie ihn der Dienst faehrt: config() in bin/ankersolix.py
 * nimmt die Vorgabe, wenn der Wert fehlt oder keine Zahl ist, und kappt auf
 * die Grenzen aus ak_zahlgrenzen(). Dieselbe Rechnung hier - sonst misst OK
 * gegen einen Takt, den der Dienst gar nicht faehrt.
 */
function ak_takt_wirksam($cfg)
{
    $v = ak_vorgaben();
    $g = ak_zahlgrenzen();
    $w = (is_array($cfg) && isset($cfg['intervall'])) ? $cfg['intervall'] : null;
    if ($w === null || $w === '' || is_bool($w) || !is_numeric($w)) {
        $w = $v['intervall'];
    }
    return max($g['intervall'][0], min($g['intervall'][1], (int) $w));
}

/**
 * Ab diesem ALTER meldet der Endpunkt OK=0: dem Dreifachen des Abruftakts
 * (Entscheidung des Hausherrn 29.09.2026). ALTER selbst bleibt unveraendert.
 */
function ak_ok_grenze($cfg)
{
    return 3 * ak_takt_wirksam($cfg);
}

/* ---------------- Laeuft gerade eine Aktualisierung? ---------------- */

/**
 * Der Ablageort der Marke: NEBEN dem Datenordner, nicht darin.
 *
 * purge_installation loescht data/plugins/<ordner>/ bei jedem Upgrade
 * (Regeln/06); eine Marke darin waere genau dann weg, wenn sie gebraucht wird.
 */
function ak_upgrade_marke()
{
    $d = ak_paths()['datadir'];
    return dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
}

/**
 * preupgrade.sh legt die Marke als Erstes an, postinstall.sh entfernt sie per
 * trap. Dazwischen ist config/plugins/<ordner>/ abgeraeumt - und was die
 * Oberflaeche in dieser Zeit anrichtet, ist gemessen (WSL, 18.09.2026,
 * Pruefung-AnkerSolix-0.9.18):
 *
 *   Fall L4  Ein Speichern schreibt zugang.json mit LEEREM Passwort. Die
 *            Kontofelder sind in der Luecke leer, weil die Datei fehlt, und
 *            ein leeres Passwortfeld loescht sonst absichtlich nichts - hier
 *            gibt es aber nichts mehr, was es bewahren koennte.
 *            postinstall.sh spielt die Sicherung danach NICHT ein: die Datei
 *            hat "Inhalt". Das Anker-Kontopasswort war nach dem Upgrade fort.
 *   Fall L5  Danach startete der Knopf "Dienst starten" den Dienst mitten in
 *            der Aktualisierung, mit ebendieser leeren Zugangsdatei.
 *   Fall G8  Dasselbe Speichern AUSSERHALB der Luecke haelt Passwort und
 *            Aktionstoken. Der Schaden haengt an der Luecke, nicht am Knopf.
 *
 * Deshalb sperrt diese Linie die Oberflaeche - wie Intercom 2.2.11, anders
 * als Sprachsteuerung 0.11.7, wo nichts verlorenging (Regeln/06: das ist eine
 * Messung, keine Regel).
 *
 * Aelter als eine Stunde oder unlesbar gilt die Marke nicht: eine
 * abgebrochene Installation darf die Seite nicht fuer immer stilllegen.
 */
function ak_upgrade_laeuft()
{
    $f = ak_upgrade_marke();
    if (!@is_file($f)) {
        return false;
    }
    $roh = @file_get_contents($f);
    if ($roh === false) {
        return false;
    }
    $roh = trim((string) $roh);
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return false;
    }
    $alter = time() - (int) $roh;
    // Ein paar Minuten "Zukunft" sind eine nachgestellte Uhr, keine Luege.
    return $alter > -300 && $alter < 3600;
}

/* ---------------- Dienst ---------------- */

/**
 * Ist die Prozessnummer $pid der Dienst DIESES Plugins?
 *
 * Argumentweise (Regeln/03, "Prozesse argumentweise erkennen"), nicht ueber
 * eine Teilzeichenkette. Bis 0.9.17 stand hier
 *     strpos($cmd, 'ankersolix.py') !== false
 * und das hielt jeden Prozess fuer den Dienst, in dessen Befehlszeile der Name
 * irgendwo vorkommt: einen Editor mit der Datei offen, ein Sicherungsskript,
 * das den Ordner durchsucht, den Einmallauf der eigenen Oberflaeche. In WSL
 * gemessen (Pruefung-AnkerSolix-0.9.17, Fall 8): fuer einen fremden Prozess
 * "python3 -c … <dienstpfad>", dessen Nummer in der PID-Datei stand, gab
 * ak_dienst_pid() dessen Nummer zurueck - die Kachel meldete "Dienst laeuft".
 *
 * Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
 * genau der eigene Dienstpfad. bin/dienst.sh startet den Dauerlaeufer an genau
 * einer Stelle als  venv/bin/python3 <bindir>/ankersolix.py; die Einmallaeufe
 * (--einmal, --selbsttest, --vorgaben, --freigeben) haben ein drittes Argument
 * und sind kein laufender Dienst.
 *
 * Der zweite Vergleich ueber realpath() deckt den Fall ab, dass der Dienst
 * ueber einen anderen Pfad auf dieselbe Datei gestartet wurde: bin/dienst.sh
 * loest seinen Ablageort mit readlink -f auf, ak_paths() baut ihn aus
 * LBHOMEDIR. Ohne ihn meldete die Oberflaeche "gestoppt", waehrend der Dienst
 * laeuft.
 */
function ak_ist_dienst($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return false;
    }
    $roh = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($roh) || $roh === '') {
        return false;
    }
    // Die Befehlszeile ist eine Folge von Argumenten, jedes mit einem Nullbyte
    // abgeschlossen; das letzte Stueck nach dem Trennen ist deshalb leer.
    $teile = explode("\0", $roh);
    if ($teile[count($teile) - 1] === '') {
        array_pop($teile);
    }
    if (count($teile) !== 2) {
        return false;
    }
    $a0 = basename($teile[0]);
    if ($a0 !== 'python' && strpos($a0, 'python3') !== 0) {
        return false;
    }
    $soll = ak_paths()['bindir'] . '/ankersolix.py';
    if ($teile[1] === $soll) {
        return true;
    }
    $r1 = @realpath($teile[1]);
    $r2 = @realpath($soll);
    return $r1 !== false && $r2 !== false && $r1 === $r2;
}

function ak_dienst_pid()
{
    $f = ak_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    // Nummernrecycling und fremde Prozesse ausschliessen.
    return ak_ist_dienst($pid) ? $pid : 0;
}

function ak_dienst_soll()
{
    return is_file(ak_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/**
 * Wie oft der Minutenwaechter den Dienst schon aufgesammelt hat.
 *
 * Ein Dienst, den der Waechter stuendlich neu startet, sieht in der Kachel
 * "Dienst laeuft" genauso gesund aus wie einer, der seit Wochen durchlaeuft.
 * Rueckgabe: array(anzahl, zeitpunkt).
 */
function ak_waechter_stand()
{
    $f = ak_paths()['datadir'] . '/waechter.txt';
    if (!is_file($f)) {
        return array(0, '');
    }
    $z = explode("\n", (string) @file_get_contents($f));
    return array((int) trim(isset($z[0]) ? $z[0] : '0'), trim(isset($z[1]) ? $z[1] : ''));
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function ak_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, ak_t('EINST.DIENST_UNBEKANNT'));
    }
    $skript = ak_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, sprintf(ak_t('EINST.DIENST_SKRIPT_FEHLT'), $skript));
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** Fassung der Python-Bibliothek in der virtuellen Umgebung, oder ''. */
function ak_bibliothek_fassung()
{
    $py = ak_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py)) {
        return '';
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' -c ' . escapeshellarg(
        'import importlib.metadata as m; print(m.version("anker-solix-api"))'
    ) . ' 2>/dev/null', $ausgabe);
    return trim(implode('', $ausgabe));
}

/** Ausgabe von ankersolix.py --selbsttest. */
function ak_selbsttest()
{
    $p = ak_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/ankersolix.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder ankersolix.py fehlt.\n"
             . "       Erwartet: " . $py . "\n"
             . "                 " . $skript . "\n"
             . "       Abhilfe: Plugin neu installieren; die Installation legt beides an.";
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/** Die Vorgabeliste des Dienstes - fuer den Abgleich mit ak_vorgaben(). */
function ak_dienst_vorgaben()
{
    $p = ak_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/ankersolix.py';
    if (!is_file($py) || !is_file($skript)) {
        return null;
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --vorgaben 2>/dev/null', $ausgabe);
    $d = json_decode(implode('', $ausgabe), true);
    return is_array($d) ? $d : null;
}

/**
 * Vorgaben hier und im Dienst gegeneinander halten.
 *
 * Fast jedes Plugin dieser Reihe fuehrt die Liste zweimal - einmal in PHP fuer
 * die Oberflaeche, einmal in der Sprache des Dienstes. Pflicht ist nicht, das
 * zusammenzulegen, sondern diese Pruefzeile: sie faellt auf, sobald eine der
 * beiden Listen fortgeschrieben wird und die andere nicht.
 *
 * Rueckgabe: array(stand, text)
 */
function ak_vorgaben_abgleich()
{
    $dienst = ak_dienst_vorgaben();
    if ($dienst === null) {
        return array(-1, ak_t('TEST.A_VORGABEN_UNBEKANNT'));
    }
    $hier = ak_vorgaben_dienst();
    $nur_hier = array_diff(array_keys($hier), array_keys($dienst));
    $nur_dort = array_diff(array_keys($dienst), array_keys($hier));
    $andere = array();
    foreach ($hier as $k => $v) {
        if (!array_key_exists($k, $dienst) || is_array($v)) {
            continue;
        }
        // Lose vergleichen: JSON kennt 0 und "0" nicht auseinander.
        if ((string) $v !== (string) $dienst[$k]) {
            $andere[] = $k . ' (' . var_export($v, true) . ' / ' . var_export($dienst[$k], true) . ')';
        }
    }
    if (!$nur_hier && !$nur_dort && !$andere) {
        return array(1, sprintf(ak_t('TEST.A_VORGABEN_OK'), count($hier)));
    }
    $t = array();
    if ($nur_hier) { $t[] = ak_t('TEST.A_VORGABEN_NUR_PHP') . ': ' . implode(', ', $nur_hier); }
    if ($nur_dort) { $t[] = ak_t('TEST.A_VORGABEN_NUR_PY') . ': ' . implode(', ', $nur_dort); }
    if ($andere)   { $t[] = ak_t('TEST.A_VORGABEN_ANDERS') . ': ' . implode(', ', $andere); }
    return array(0, implode(' | ', $t));
}

/**
 * Trockenlauf: was ein Schreibbefehl TAETE, ohne ihn abzusetzen.
 *
 * Die Stufe vor dem ersten echten Befehl. Sie oeffnet keine Verbindung und
 * braucht keinen laufenden Dienst - gerade dann will man es wissen. Geprueft
 * werden dieselben Sperren in derselben Reihenfolge wie in
 * befehl_ausfuehren() in bin/ankersolix.py.
 *
 * Rueckgabe: array von array(stand, text). stand: 1 = ginge durch,
 * 0 = wuerde abgewiesen, -1 = Hinweis.
 */
function ak_trockenlauf($aktion, $anlage, $wert)
{
    $cfg = ak_config(true);
    $zeilen = array();
    $anlagen = ak_anlagen();
    $nr = (string) (int) $anlage;

    $zeilen[] = empty($cfg['steuerung_ein'])
        ? array(0, ak_t('TROCKEN.STEUERUNG_AUS'))
        : array(1, ak_t('TROCKEN.STEUERUNG_EIN'));

    $pid = ak_dienst_pid();
    $zeilen[] = $pid === 0
        ? array(0, ak_t('TROCKEN.DIENST_TOT'))
        : array(1, sprintf(ak_t('TROCKEN.DIENST_LAEUFT'), $pid));

    if (!isset($anlagen[$nr])) {
        $zeilen[] = array(0, sprintf(ak_t('TROCKEN.ANLAGE_UNBEKANNT'), $nr, count($anlagen)));
        return $zeilen;
    }
    $an = $anlagen[$nr];
    $zeilen[] = array(1, sprintf(ak_t('TROCKEN.ANLAGE'), $nr, $an['name']));

    // Welches Geraet bekaeme den Befehl, und ueber welchen Weg?
    $speicher = array();
    foreach (ak_geraete() as $sn => $g) {
        if ((string) $g['site_id'] === (string) $an['site_id']
            && in_array($g['typ'], array('solarbank', 'solarbank_pps'), true)) {
            $speicher[$sn] = $g;
        }
    }
    if (!$speicher) {
        $zeilen[] = array(0, ak_t('TROCKEN.KEIN_SPEICHER'));
        return $zeilen;
    }
    ksort($speicher);
    $sn = (string) key($speicher);
    $g = $speicher[$sn];
    $gen = (int) $g['generation'];
    $zeilen[] = array(1, sprintf(ak_t('TROCKEN.GERAET'), $g['name'], $sn, $gen > 0 ? $gen : '?'));
    $zeilen[] = array(-1, sprintf(ak_t('TROCKEN.WEG'), ak_befehlsweg($aktion, $gen)));

    // Innerhalb der Schrittweite ginge nichts hinaus (Nr. 21): dann ist die
    // Schreibbremse nicht im Weg, und die Bremszeile entfaellt.
    $schritt_unveraendert = false;
    if ($aktion === 'hauslast') {
        $w = (int) $wert;
        list($lo, $hi) = ak_grenzen($cfg, $nr);
        $zeilen[] = ($w < $lo || $w > $hi)
            ? array(0, sprintf(ak_t('TROCKEN.AUSSER_GRENZEN'), $w, $lo, $hi))
            : array(1, sprintf(ak_t('TROCKEN.IN_GRENZEN'), $w, $lo, $hi));
        $soll = isset($an['sollwert']) ? $an['sollwert'] : null;
        if ($soll !== null && (int) $cfg['schrittweite'] > 0
            && abs($w - (int) $soll) < (int) $cfg['schrittweite']) {
            $zeilen[] = array(-1, sprintf(ak_t('TROCKEN.SCHRITTWEITE'),
                abs($w - (int) $soll), (int) $cfg['schrittweite']));
            $schritt_unveraendert = true;
        }
    } elseif ($aktion === 'modus') {
        $modi = ak_modi_erlaubt($an);
        $zeilen[] = in_array((string) $wert, $modi, true)
            ? array(1, sprintf(ak_t('TROCKEN.MODUS_OK'), (string) $wert))
            : array(0, sprintf(ak_t('TROCKEN.MODUS_UNZULAESSIG'), (string) $wert, implode(', ', $modi)));
        if ($gen > 0 && $gen < 2) {
            $zeilen[] = array(0, ak_t('TROCKEN.MODUS_GEN1'));
        }
    } elseif ($aktion === 'reserve') {
        $stufen = isset($g['cutoff_stufen']) && is_array($g['cutoff_stufen']) ? $g['cutoff_stufen'] : array();
        if (!$stufen) {
            $zeilen[] = array(-1, ak_t('TROCKEN.STUFEN_UNBEKANNT'));
        } else {
            $zeilen[] = in_array((int) $wert, array_map('intval', $stufen), true)
                ? array(1, sprintf(ak_t('TROCKEN.STUFE_OK'), (int) $wert))
                : array(0, sprintf(ak_t('TROCKEN.STUFE_UNZULAESSIG'), (int) $wert, implode(', ', $stufen)));
        }
    }

    $bremse = (int) $cfg['schreibbremse'];
    if ($bremse > 0 && !$schritt_unveraendert) {
        $letzte = ak_letzter_schreibbefehl();
        $rest = $bremse - (time() - $letzte);
        $zeilen[] = ($letzte > 0 && $rest > 0)
            ? array(0, sprintf(ak_t('TROCKEN.BREMSE_AKTIV'), $rest, $bremse))
            : array(1, sprintf(ak_t('TROCKEN.BREMSE_FREI'), $bremse));
    }
    return $zeilen;
}

/** Welcher Bibliotheksaufruf traefe dieses Geraet? */
function ak_befehlsweg($aktion, $generation)
{
    switch ($aktion) {
        case 'hauslast':
            return $generation >= 2 ? 'set_sb2_home_load(preset=...)' : 'set_home_load(preset=...)';
        case 'modus':
            return $generation >= 2 ? 'set_sb2_home_load(usage_mode=...)' : '-';
        case 'reserve':
            return 'get_power_cutoff() + set_power_cutoff(setId=...)';
        case 'einspeisung':
        case 'einspeisegrenze':
            return 'set_station_parm(gridExport=..., gridExportLimit=...)';
        case 'notstromreserve':
            return 'set_station_parm(socReserve=...)';
        case 'pvlimit':
            return 'set_device_pv_power(limit=...)';
    }
    return '-';
}

/** Grenzen fuer die Hauslast: je Anlage, sonst die allgemeinen. */
function ak_grenzen($cfg, $nummer)
{
    $lo = (int) $cfg['hauslast_min'];
    $hi = (int) $cfg['hauslast_max'];
    $g = isset($cfg['anlagen_grenzen']) && is_array($cfg['anlagen_grenzen']) ? $cfg['anlagen_grenzen'] : array();
    $k = (string) (int) $nummer;
    if (isset($g[$k]) && is_array($g[$k])) {
        if (isset($g[$k]['min']) && $g[$k]['min'] !== '') { $lo = (int) $g[$k]['min']; }
        if (isset($g[$k]['max']) && $g[$k]['max'] !== '') { $hi = (int) $g[$k]['max']; }
    }
    return array($lo, $hi);
}

/**
 * Welche Betriebsarten diese Anlage annimmt.
 *
 * Der Dienst fragt sie beim Geraet ab (solarbank_usage_mode_options) und legt
 * sie ins Abbild. Eine fest eingetragene Liste waere eine Behauptung ueber
 * fremde Hardware: welchen Modus ein Geraet kann, weiss nur das Geraet.
 * Solange nichts gemeldet wurde, gilt die Liste der Bibliotheksfassung.
 */
function ak_modi_erlaubt($anlage)
{
    if (isset($anlage['modi_erlaubt']) && is_array($anlage['modi_erlaubt']) && $anlage['modi_erlaubt']) {
        return array_values($anlage['modi_erlaubt']);
    }
    return array_keys(ak_modi());
}

/**
 * Betriebsarten: Name -> Zahl der Bibliothek.
 *
 * Die Zahlen stammen aus SolarbankUsageMode (apitypes.py) und sind dort so
 * festgelegt. 4 (Notstrom) fehlt absichtlich: der Enum-Kommentar sagt
 * ausdruecklich, dass dieser Modus nur den Zustand abbildet und sich nicht
 * ueber den Zeitplan setzen laesst.
 */
function ak_modi()
{
    return array(
        'eigenverbrauch' => 1,   // smartmeter
        'steckdosen'     => 2,   // smartplugs
        'manuell'        => 3,   // manual
        'zeitplan'       => 5,   // use_time
        'smart'          => 7,   // smart
        'zeitfenster'    => 8,   // time_slot - fuer dynamische Tarife
    );
}

function ak_letzter_schreibbefehl()
{
    $f = ak_paths()['datadir'] . '/letzter_schreibbefehl';
    return is_file($f) ? (int) @file_get_contents($f) : 0;
}

/* ---------------- Sofortabruf-Bremse (Verbesserung a3 / X-7, 30.09.2026) ----
 *
 * Ein Sofortabruf holt ALLES neu aus der Cloud. Bis 0.9.23 reihte jeder
 * Aufruf von aktion=abruf einen ein - ein flatternder Ausgang in Loxone trieb
 * das Konto so in die 429-Sperre, und die trifft auch die Messwerte.
 * Jetzt hoechstens einer je ak_abruf_abstand() Sekunden. Der Merker liegt im
 * Datenordner und wird unter flock gelesen und geschrieben; laesst er sich
 * nicht oeffnen oder schreiben, faellt die Bremse GESCHLOSSEN aus (Rahmen
 * X-7, Bauform EVCC 0.9.34).
 */
function ak_abruf_abstand()
{
    return 30;
}

/**
 * Rueckgabe: 0 = frei (und jetzt vermerkt), > 0 = noch so viele Sekunden
 * warten, -1 = Merker nicht nutzbar (der Aufrufer weist ab).
 * Eine zurueckgestellte Uhr sperrt hoechstens ak_abruf_abstand() Sekunden.
 */
function ak_abruf_bremse()
{
    $p = ak_paths();
    $abstand = ak_abruf_abstand();
    $f = $p['datadir'] . '/abruf_bremse';
    $fh = is_dir($p['datadir']) ? @fopen($f, 'c+') : false;
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if ($fh !== false) {
            @fclose($fh);
        }
        ak_log_wenn_neu('abruf_bremse', 'Der Merker der Sofortabruf-Bremse (' . $f . ') laesst sich '
            . 'nicht oeffnen - aktion=abruf wird mit 503 abgewiesen, bis das behoben ist.');
        return -1;
    }
    $roh = trim((string) stream_get_contents($fh));
    $letzt = preg_match('/^[0-9]{1,12}$/', $roh) ? (int) $roh : 0;
    $jetzt = time();
    $rest = $letzt > 0 ? min($abstand, $letzt + $abstand - $jetzt) : 0;
    if ($rest > 0) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return $rest;
    }
    $neu = (string) $jetzt;
    $ok = @ftruncate($fh, 0) && @rewind($fh) && @fwrite($fh, $neu) === strlen($neu) && @fflush($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
    if (!$ok) {
        ak_log_wenn_neu('abruf_bremse', 'Der Merker der Sofortabruf-Bremse (' . $f . ') laesst sich '
            . 'nicht schreiben - aktion=abruf wird mit 503 abgewiesen, bis das behoben ist.');
        return -1;
    }
    return 0;
}

/* ---------------- Gleichwert-Unterdrueckung (X-7) ----------------
 *
 * B-Nachzug 01.10.2026, Entscheidungen Nr. 16 (AnkerSolix) und Nr. 19;
 * Vorbild EVCC 0.9.37 (webfrontend/html/index.php, Befehlsbremse), Marstek
 * 1.1.19 und Heimkino (vb_hk2_bau). Ein Sollwert-Befehl des Endpunkts mit
 * DEMSELBEN Wert geht innerhalb von 60 s nicht erneut in die Warteschlange
 * (HTTP 200, UNVERAENDERT=1). Bis 0.9.24 reihte ein Loxone-Ausgang, der
 * denselben Wert wiederholt, jeden Aufruf ein; der Dienst schickte ihn erneut
 * an die Cloud, oder die Schreibbremse wies ihn mit OK=0 (HTTP 500) ab.
 * Ein anderer Wert geht sofort hinaus - ein zusaetzliches 429 gibt es nicht
 * (Nr. 16); Schreibbremse und Schrittweite des Dienstes bleiben. */

/** Fenster der Gleichwert-Unterdrueckung in Sekunden. */
function ak_gleichwert_fenster()
{
    return 60;
}

/**
 * Merkerschluessel eines Befehls, '' fuer alle nicht betroffenen.
 *
 * Betroffen sind die Sollwert-Befehle (Nr. 19: Modus, Grenzen, Ein/Aus):
 * hauslast, modus, reserve, einspeisung, einspeisegrenze, notstromreserve,
 * pvlimit. Nicht abruf (Ereignis mit eigener 30-s-Bremse). Der Schluessel
 * traegt Anlage (als Zahl, "01" = "1" wie im Dienst) und Seriennummer: ein
 * Befehl an ein anderes Geraet ist ein anderer Sollwert.
 */
function ak_gleichwert_schluessel($befehl)
{
    $aktion = (is_array($befehl) && isset($befehl['aktion'])) ? (string) $befehl['aktion'] : '';
    if (!in_array($aktion, array('hauslast', 'modus', 'reserve', 'einspeisung',
                                 'einspeisegrenze', 'notstromreserve', 'pvlimit'), true)) {
        return '';
    }
    return $aktion . '|' . (isset($befehl['anlage']) ? (int) $befehl['anlage'] : 1)
         . '|' . (isset($befehl['sn']) ? (string) $befehl['sn'] : '');
}

/** Der verglichene Wert eines Befehls: watt, prozent oder wert. */
function ak_gleichwert_wert($befehl)
{
    foreach (array('watt', 'prozent', 'wert') as $k) {
        if (is_array($befehl) && isset($befehl[$k])) {
            return $k . '=' . (string) $befehl[$k];
        }
    }
    return '';
}

/**
 * Den Merker oeffnen und sperren. Rueckgabe: Dateizeiger oder false.
 *
 * Die Sperre bleibt waehrend des Einreihens und Wartens gehalten (wie EVCC):
 * zwei gleichzeitige gleiche Aufrufe reihen so nur einmal ein. "e"
 * (close-on-exec) wie im Heimkino-Bau, falls je ein Kindprozess entsteht.
 * Angelegt wird nur der Merker selbst, kein Ordner: ohne Datenordner -
 * false, und der Endpunkt faellt geschlossen aus (503).
 */
function ak_gleichwert_oeffnen()
{
    $p = ak_paths();
    $f = $p['datadir'] . '/befehl_gleichwert.json';
    $fh = is_dir($p['datadir']) ? @fopen($f, 'c+e') : false;
    if ($fh !== false && !@flock($fh, LOCK_EX)) {
        @fclose($fh);
        $fh = false;
    }
    if ($fh === false) {
        ak_log_wenn_neu('gleichwert', 'Der Merker der Gleichwert-Unterdrueckung (' . $f . ') laesst sich '
            . 'nicht oeffnen - schaltende Befehle werden mit 503 abgewiesen, bis das behoben ist. '
            . 'Pruefen: Datenordner, Platz und Eigentuemer (loxberry).');
    }
    return $fh;
}

/** Den gesperrten Merker lesen; Unlesbares gilt als leer (dann geht der
 * Befehl hinaus - im Zweifel senden, nie still verschlucken). */
function ak_gleichwert_lesen($fh)
{
    @rewind($fh);
    $d = json_decode((string) stream_get_contents($fh), true);
    return is_array($d) ? $d : array();
}

/** Sekunden seit DEMSELBEN Wert, -1 wenn ein anderer Wert gemerkt ist oder
 * der gemerkte nicht im Fenster liegt (eine zurueckgestellte Uhr haelt
 * nichts zurueck). */
function ak_gleichwert_seit($merker, $schluessel, $wert)
{
    if ($schluessel === '' || !isset($merker[$schluessel]) || !is_array($merker[$schluessel])) {
        return -1;
    }
    $e = $merker[$schluessel];
    if (!isset($e['w'], $e['t']) || (string) $e['w'] !== (string) $wert) {
        return -1;
    }
    $seit = time() - (int) $e['t'];
    return ($seit >= 0 && $seit < ak_gleichwert_fenster()) ? $seit : -1;
}

/**
 * Der Merker nach einem Befehl: bestaetigt (ok=1) - der eigene Wert mit
 * Zeit; abgelehnt oder ohne Antwort (0/2) - der eigene Eintrag faellt weg,
 * ein Wiederholen geht dann hinaus. Eintraege ausserhalb des Fensters
 * werden nicht mitgeschleppt.
 */
function ak_gleichwert_nachher($merker, $schluessel, $wert, $gelungen)
{
    $jetzt = time();
    foreach ($merker as $k => $e) {
        $alter = (is_array($e) && isset($e['t'])) ? $jetzt - (int) $e['t'] : -1;
        if ($alter < 0 || $alter >= ak_gleichwert_fenster()) {
            unset($merker[$k]);
        }
    }
    if ($schluessel !== '') {
        if ($gelungen) {
            $merker[$schluessel] = array('w' => (string) $wert, 't' => $jetzt);
        } else {
            unset($merker[$schluessel]);
        }
    }
    return $merker;
}

/** Den Merker schreiben (ausser bei null), entsperren und schliessen.
 * Erfolg nur bei vollstaendig geschriebenem Inhalt (Fehlerklasse 1). */
function ak_gleichwert_schliessen($fh, $merker)
{
    $ok = true;
    if ($merker !== null) {
        $roh = (string) json_encode($merker);
        $ok = @ftruncate($fh, 0) && @rewind($fh) && @fwrite($fh, $roh) === strlen($roh) && @fflush($fh);
        if (!$ok) {
            ak_log_wenn_neu('gleichwert_schreiben', 'Der Merker der Gleichwert-Unterdrueckung liess sich '
                . 'nicht schreiben - ein gleicher Befehl geht dann erneut hinaus. '
                . 'Pruefen: Platz und Eigentuemer (loxberry).');
        }
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return $ok;
}

/**
 * Den Merker nach einem Befehl aus dem Reiter Test nachfuehren.
 *
 * Der Reiter Test unterdrueckt nichts (ein Mensch drueckt den Knopf
 * bewusst). Er fuehrt den Merker aber nach: sonst wuerde ein Loxone-Befehl,
 * der kurz zuvor denselben Wert setzte, nach einem anderen Wert aus dem
 * Reiter Test noch 60 s lang unterdrueckt. Laesst sich der Merker nicht
 * oeffnen, bleibt es still - der Endpunkt faellt dann ohnehin geschlossen aus.
 */
function ak_gleichwert_nachfuehren($befehl, $erg)
{
    $schl = ak_gleichwert_schluessel($befehl);
    if ($schl === '') {
        return false;
    }
    $fh = ak_gleichwert_oeffnen();
    if ($fh === false) {
        return false;
    }
    return ak_gleichwert_schliessen($fh, ak_gleichwert_nachher(ak_gleichwert_lesen($fh), $schl,
        ak_gleichwert_wert($befehl), (int) $erg === 1));
}

/* ---------------- Sollwert empfangen (Entscheidung Nr. 22, 01.10.2026) ----
 *
 * Der Rueckfall des Dienstes (rueckfall_min, ab Werk aus) misst die Zeit
 * seit dem letzten gueltigen Sollwert von Loxone. Bis 0.9.26 mass er nur ab
 * dem letzten GESENDETEN Befehl: einen gleichbleibenden Sollwert senden die
 * Gleichwert-Unterdrueckung und die Schrittweite nicht erneut, und der
 * Rueckfall griff, obwohl Loxone lebte.
 *
 * Merker data/sollwert_empfangen (Unix-Zeit). Der Endpunkt setzt ihn, wenn
 * die Gleichwert-Unterdrueckung antwortet; den eingereihten Befehl kennzeichnet
 * er mit 'quelle' => 'endpunkt', und der Dienst setzt ihn nach ok=1
 * (sollwert_empfangen_vermerken() in bin/ankersolix.py). Beide schreiben unter
 * flock auf data/sollwert_empfangen.sperre ("c+e": close-on-exec) eine
 * Nebendatei und benennen sie um. Fehlt der Merker oder laesst er sich nicht
 * schreiben, misst der Rueckfall wie bisher am gesendeten Befehl - er greift
 * dann eher zu frueh als nie. Der Reiter Test setzt ihn nie.
 */

/** Zeitpunkt des letzten empfangenen Sollwerts, 0 = keiner oder unbrauchbar
 * (mehr als 60 s in der Zukunft zaehlt nicht, wie im Dienst). */
function ak_sollwert_empfangen()
{
    $f = ak_paths()['datadir'] . '/sollwert_empfangen';
    if (!is_file($f)) {
        return 0;
    }
    $roh = trim((string) @file_get_contents($f));
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return 0;
    }
    $t = (int) $roh;
    return ($t > 0 && $t <= time() + 60) ? $t : 0;
}

/**
 * Den Merker auf jetzt setzen. Rueckgabe true bei Erfolg. Scheitert es,
 * bleibt die Antwort an Loxone unberuehrt (der Sollwert ist ja behandelt);
 * eine gebremste Protokollzeile nennt die Folge.
 */
function ak_sollwert_empfangen_vermerken()
{
    $p = ak_paths();
    $f = $p['datadir'] . '/sollwert_empfangen';
    $fh = is_dir($p['datadir']) ? @fopen($f . '.sperre', 'c+e') : false;
    if ($fh !== false && !@flock($fh, LOCK_EX)) {
        @fclose($fh);
        $fh = false;
    }
    $ok = false;
    if ($fh !== false) {
        $jetzt = time();
        if (ak_sollwert_empfangen() >= $jetzt) {
            $ok = true;
        } else {
            $tmp = $f . '.tmp';
            $roh = (string) $jetzt;
            $ok = @file_put_contents($tmp, $roh) === strlen($roh) && @rename($tmp, $f);
            if (!$ok) {
                @unlink($tmp);
            }
        }
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
    if (!$ok) {
        ak_log_wenn_neu('sollwert_empfangen', 'Der Merker ' . $f . ' (Sollwert empfangen) laesst sich nicht '
            . 'schreiben - der Rueckfall misst bis dahin nur am letzten gesendeten Befehl und kann greifen, '
            . 'obwohl Loxone Sollwerte schickt. Pruefen: Datenordner, Platz und Eigentuemer (loxberry).');
    }
    return $ok;
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, meldung, unveraendert). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - also Ergebnis unbekannt.
 * Es wird bewusst kein Erfolg gemeldet, den niemand geprueft hat.
 * unveraendert = 1 nur bei ok = 1, wenn der Dienst nichts gesendet hat, weil
 * der Wert innerhalb der Schrittweite neben dem gesetzten liegt
 * (Entscheidung Nr. 21); sonst 0.
 *
 * $cfg: die Konfiguration des Aufrufers. Bis 0.9.24 stand hier
 * ak_config(true): fehlte die Konfiguration oder war sie unlesbar, schrieb
 * schon ein schaltender Aufruf des UNANGEMELDETEN Endpunkts sie aus der
 * Zweitschrift zurueck (samt .kaputt-Abschrift und Protokollzeile) - obwohl
 * der Endpunkt ausdruecklich nichts anlegt (ak_config(false), X-1 im
 * B-Nachzug 01.10.2026). Jetzt wird nur gelesen; der Endpunkt reicht seine
 * ungeheilte Konfiguration durch, die Oberflaeche hat beim Seitenaufbau
 * ohnehin schon geheilt. Gebraucht wird hier nur die Wartezeit.
 */
function ak_befehl_absetzen($befehl, $wartezeit = null, $cfg = null)
{
    $p = ak_paths();
    if (!is_array($cfg)) {
        $cfg = ak_config(false);
    }
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(20, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, sprintf(ak_t('TEST.M_ABLAGE_ORDNER'), $ordner), 0);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, sprintf(ak_t('TEST.M_ABLAGE_DATEI'), $datei), 0);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = ak_json_lesen($antwort);
            $ok = (int) (isset($a['ok']) ? $a['ok'] : 0);
            return array($ok,
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''),
                         ($ok === 1 && isset($a['unveraendert']) && (int) $a['unveraendert'] === 1) ? 1 : 0);
        }
        usleep(100000);
    }
    return array(2, sprintf(ak_t('TEST.M_EINGEREIHT'), $wartezeit), 0);
}

/* ---------------- Verlauf ---------------- */

/** Messpunkte eines Tages: Array von array(ts, soc, batp). */
function ak_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    if (!preg_match('/^[0-9]{8}$/', (string) $tag)) {
        return array();
    }
    $f = ak_paths()['datadir'] . '/verlauf/anlage' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2) {
                $out[] = array((int) $c[0], (float) $c[1], isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/**
 * Welche Tage liegen ueberhaupt vor - neuester zuerst.
 *
 * Bis 0.9.6 hielt der Dienst bis zu 90 Tage vor, die Oberflaeche zeigte aber
 * immer nur den heutigen: die Einstellung "Verlauf aufbewahren" hatte keine
 * sichtbare Wirkung. Entweder es gibt eine Tagesauswahl, oder die Einstellung
 * ist irrefuehrend.
 */
function ak_verlauf_tage($nummer)
{
    $ordner = ak_paths()['datadir'] . '/verlauf';
    $tage = array();
    foreach (glob($ordner . '/anlage' . (int) $nummer . '_*.csv') ?: array() as $f) {
        if (preg_match('/_([0-9]{8})\.csv$/', $f, $m)) {
            $tage[] = $m[1];
        }
    }
    rsort($tage);
    return $tage;
}

/**
 * Tagesenergien aus der eigenen Aufzeichnung.
 *
 * Die Cloud liefert nur "heute"; um Mitternacht faellt der Wert auf 0 zurueck.
 * Wer in Loxone eine Statistik fuehrt, verliert damit den Tagesabschluss. Der
 * Dienst schreibt die Tagessummen deshalb selbst fort.
 *
 * Rueckgabe: Datum (JJJJ-MM-TT) => array der Energiewerte.
 */
function ak_energie_lesen($nummer, $von = '', $bis = '')
{
    $f = ak_paths()['datadir'] . '/energie/anlage' . (int) $nummer . '.csv';
    $out = array();
    if (!is_file($f)) {
        return $out;
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
        $c = explode(';', $zeile);
        if (count($c) < 7 || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $c[0])) {
            continue;
        }
        if ($von !== '' && $c[0] < $von) { continue; }
        if ($bis !== '' && $c[0] > $bis) { continue; }
        $out[$c[0]] = array(
            'pv'                 => $c[1] === '' ? null : (float) $c[1],
            'batterie_geladen'   => $c[2] === '' ? null : (float) $c[2],
            'batterie_abgegeben' => $c[3] === '' ? null : (float) $c[3],
            'haus'               => $c[4] === '' ? null : (float) $c[4],
            'netzbezug'          => $c[5] === '' ? null : (float) $c[5],
            'netzeinspeisung'    => $c[6] === '' ? null : (float) $c[6],
        );
    }
    return $out;
}

/** Summe ueber einen Zeitraum: 'monat', 'jahr' oder 'gesamt'. */
function ak_energie_summe($nummer, $zeitraum = 'monat', $stichtag = '')
{
    $stichtag = $stichtag !== '' ? $stichtag : date('Y-m-d');
    if ($zeitraum === 'monat') {
        $von = substr($stichtag, 0, 7) . '-01';
        $bis = date('Y-m-t', strtotime($von));
    } elseif ($zeitraum === 'jahr') {
        $von = substr($stichtag, 0, 4) . '-01-01';
        $bis = substr($stichtag, 0, 4) . '-12-31';
    } else {
        $von = '';
        $bis = '';
    }
    $summe = array('pv' => null, 'batterie_geladen' => null, 'batterie_abgegeben' => null,
                   'haus' => null, 'netzbezug' => null, 'netzeinspeisung' => null, 'tage' => 0);
    foreach (ak_energie_lesen($nummer, $von, $bis) as $tag) {
        $summe['tage']++;
        foreach ($tag as $k => $v) {
            if ($v === null) {
                continue;
            }
            $summe[$k] = ($summe[$k] === null ? 0.0 : $summe[$k]) + $v;
        }
    }
    foreach ($summe as $k => $v) {
        if ($k !== 'tage' && $v !== null) {
            $summe[$k] = round($v, 2);
        }
    }
    return $summe;
}

/* ---------------- Protokoll ----------------
 *
 * NICHT die ganze Datei einlesen und NICHT exec("tail"). An 12.000 Zeilen
 * (610 kB) gemessen, je 20 Durchlaeufe:
 *     file() + array_reverse   0,37 ms   zusaetzlich 2048 kB
 *     exec("tail -n 400")      2,17 ms   zusaetzlich    0 kB
 *     rueckwaerts mit fseek    0,05 ms   zusaetzlich    0 kB
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat.
 */
function ak_log_ende($datei, $anzahl = 400, $block = 8192)
{
    // Erst fragen, dann oeffnen. Ein @fopen() auf eine fehlende Datei ist
    // stumm, aber nicht folgenlos: ein gesetzter Fehlerbehandler sieht die
    // Warnung trotzdem, und im Pruefstand steht sie dann als Befund da.
    // Die Protokolldatei fehlt regelmaessig - vor dem ersten Start gibt es
    // sie noch gar nicht.
    if (!is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ---------------- MQTT-Gateway ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart.
 */
function ak_mqtt_zustand()
{
    $p = ak_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '', 'websocket' => '');
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = ak_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    $auto = $hol('Gatewayautostart', 'gatewayautostart');
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $auto, array('1', 'true'), true) ? 1 : 0,
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function ak_abo_text()
{
    $m = ak_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return ak_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(ak_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return ak_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/**
 * Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung.
 *
 * Diese Liste IST die Anleitung. Sie wird an den Sendecode angeglichen, nicht
 * umgekehrt - der Reiter Test misst beide gegeneinander.
 *
 * 'ts' und 'fehler' sind seit 0.9.7 dabei. Ueber MQTT gibt es kein "Alter":
 * beim Senden ist es immer null. Wer die beiden Wege gleich behandeln will,
 * veroeffentlicht den ZEITSTEMPEL und laesst die Gegenseite rechnen. Ohne ihn
 * ist ein toter Dienst von einem gesunden nicht zu unterscheiden - es wird
 * schlicht nichts mehr gesendet, und die letzten Werte bleiben im Broker
 * stehen.
 *
 * Die Spalte "Bedeutung" ist als BESCHRIFTUNG geschrieben, nicht als Satz:
 * Werte, die ueber MQTT gehen, bekommen keine Importvorlage, aus der Loxone
 * einen Anzeigenamen bilden koennte. Diese Spalte ist die einzige brauchbare
 * Vorlage fuer den Namen, den man in Loxone von Hand setzt.
 */
function ak_mqtt_themen()
{
    return array(
        'ok'                          => 'AK_MQTT.OK',
        'ts'                          => 'AK_MQTT.TS',
        'fehler'                      => 'AK_MQTT.FEHLER',
        'anlagen'                     => 'AK_MQTT.ANLAGEN',
        'anlageN/soc'                 => 'AK_MQTT.SOC',
        'anlageN/pv'                  => 'AK_MQTT.PV',
        'anlageN/laden'               => 'AK_MQTT.LADEN',
        'anlageN/entladen'            => 'AK_MQTT.ENTLADEN',
        'anlageN/batp'                => 'AK_MQTT.BATP',
        'anlageN/ausgang'             => 'AK_MQTT.AUSGANG',
        'anlageN/haus'                => 'AK_MQTT.HAUS',
        'anlageN/netzbezug'           => 'AK_MQTT.NETZBEZUG',
        'anlageN/netzeinspeisung'     => 'AK_MQTT.NETZEINSP',
        'anlageN/sollwert'            => 'AK_MQTT.SOLLWERT',
        'anlageN/modus'               => 'AK_MQTT.MODUS',
        'anlageN/reserve'             => 'AK_MQTT.RESERVE',
        'anlageN/einspeisung'         => 'AK_MQTT.EINSPEISUNG',
        'anlageN/einspeisegrenze'     => 'AK_MQTT.GRENZE',
        'anlageN/prognose'            => 'AK_MQTT.PROGNOSE',
        'anlageN/name'                => 'AK_MQTT.NAME',
        'anlageN/energie/pv'          => 'AK_MQTT.E_PV',
        'anlageN/energie/batterie_geladen'   => 'AK_MQTT.E_LADEN',
        'anlageN/energie/batterie_abgegeben' => 'AK_MQTT.E_ENTLADEN',
        'anlageN/energie/haus'        => 'AK_MQTT.E_HAUS',
        'anlageN/energie/netzbezug'   => 'AK_MQTT.E_NETZBEZUG',
        'anlageN/energie/netzeinspeisung' => 'AK_MQTT.E_NETZEINSP',
        'anlageN/zaehler/pv'          => 'AK_MQTT.Z_PV',
        'anlageN/zaehler/haus'        => 'AK_MQTT.Z_HAUS',
        'anlageN/zaehler/netzbezug'   => 'AK_MQTT.Z_NETZBEZUG',
        'anlageN/zaehler/netzeinspeisung' => 'AK_MQTT.Z_NETZEINSP',
        'geraet/<SN>/soc'             => 'AK_MQTT.G_SOC',
        'geraet/<SN>/pv'              => 'AK_MQTT.G_PV',
        'geraet/<SN>/ausgang'         => 'AK_MQTT.G_AUSGANG',
        'geraet/<SN>/laden'           => 'AK_MQTT.G_LADEN',
        'geraet/<SN>/sollwert'        => 'AK_MQTT.G_SOLLWERT',
        'geraet/<SN>/online'          => 'AK_MQTT.G_ONLINE',
        'geraet/<SN>/wlan'            => 'AK_MQTT.G_WLAN',
        'geraet/<SN>/leistung'        => 'AK_MQTT.G_LEISTUNG',
        'geraet/<SN>/fw'              => 'AK_MQTT.G_FW',
    );
}

/**
 * Die Themen, die der Dienst WIRKLICH bildet: ankersolix.py --themen
 * (Verbesserung b2, 30.09.2026). null, wenn der Dienst nicht zu fragen ist.
 */
function ak_dienst_themen()
{
    $p = ak_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/ankersolix.py';
    if (!is_file($py) || !is_file($skript)) {
        return null;
    }
    $ausgabe = array();
    $rc = 1;
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --themen 2>/dev/null', $ausgabe, $rc);
    $d = json_decode(implode('', $ausgabe), true);
    if ($rc !== 0 || !is_array($d)) {
        return null;
    }
    return array_values(array_filter($d, 'is_string'));
}

/**
 * Die Themenliste gegen den Sendecode halten.
 *
 * Der teuerste Befund der Renault-Sitzung: Oberflaeche, Baustein-Liste und
 * Importdatei nannten fuenf Themen, die der Sendecode nie veroeffentlicht hat.
 * Wer die Importdatei einlas, bekam virtuelle Eingaenge, die dauerhaft auf 0
 * standen - ohne Fehlermeldung. Angeglichen wird die Anleitung an den
 * Sendecode, nicht umgekehrt; diese Zeile findet den Unterschied.
 *
 * Bis 0.9.23 las diese Zeile die Tabelle MQTT_THEMEN aus dem Quelltext des
 * Dienstes - Tabelle gegen Tabelle. 'anlageN/prognose' stand in beiden und
 * wurde nie gesendet (Befund MQTT M1), die Zeile zeigte einen Haken. Jetzt
 * fragt sie den Dienst, welche Themen er aus einem Musterabbild bildet
 * (--themen), und vergleicht in beiden Richtungen. Laesst er sich nicht
 * fragen, ist die Zeile grau - "nicht geprueft", nicht "in Ordnung".
 *
 * Rueckgabe: array(stand, text)
 */
function ak_themen_abgleich()
{
    $dienst = ak_dienst_themen();
    if ($dienst === null) {
        return array(-1, ak_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    // Eine leere Menge ist kein Einklang (CLAUDE.md, 6).
    if (!$dienst) {
        return array(0, ak_t('TEST.A_THEMEN_LEER'));
    }
    $hier = array_keys(ak_mqtt_themen());
    $nur_hier = array_values(array_diff($hier, $dienst));
    $nur_dort = array_values(array_diff($dienst, $hier));
    if (!$nur_hier && !$nur_dort) {
        return array(1, sprintf(ak_t('TEST.A_THEMEN_OK'), count($hier)));
    }
    $s = array();
    // Themennamen wie geraet/<SN>/fw maskieren - sonst verschluckt der Browser sie als Tag.
    if ($nur_hier) { $s[] = ak_t('TEST.A_THEMEN_NUR_LISTE') . ': ' . htmlspecialchars(implode(', ', $nur_hier), ENT_QUOTES, 'UTF-8'); }
    if ($nur_dort) { $s[] = ak_t('TEST.A_THEMEN_NUR_CODE') . ': ' . htmlspecialchars(implode(', ', $nur_dort), ENT_QUOTES, 'UTF-8'); }
    return array(0, implode(' | ', $s));
}

/**
 * Die Retain-Tabelle des Dienstes (MQTT_RETAINED in bin/ankersolix.py).
 *
 * Sie steht an EINER Stelle, im Dienst, der danach sendet; die Oberflaeche
 * liest sie nur (Regeln/07: "Die Retain-Tabelle gehoert in die gemeinsame
 * Datei, aus der auch die Oberflaeche sie liest"). Rueckgabe: Liste der
 * Themenstaemme, oder null, wenn der Block nicht lesbar ist - dann zeigt die
 * Spalte "unbekannt", nie ein geratenes "nein".
 */
function ak_retain_themen()
{
    $p = ak_paths();
    $py = $p['bindir'] . '/ankersolix.py';
    if (!is_file($py)) {
        $py = dirname(dirname(__DIR__)) . '/bin/ankersolix.py';
    }
    if (!is_file($py)) {
        return null;
    }
    $quelle = (string) @file_get_contents($py);
    if (!preg_match('/^MQTT_RETAINED\s*=\s*\((.*?)\)\s*\n/ms', $quelle, $m)) {
        return null;
    }
    preg_match_all('/"([^"]+)"/', $m[1], $t);
    return $t[1];
}

/**
 * Die Retain-Tabelle pruefen: lesbar, nicht leer, nur Themen der Liste, und
 * weder Lebenszeichen noch Dienstaussage darin (ok, ts, fehler - Regeln/07,
 * Entscheidungen 03.09., 18.09. und 19.09.2026).
 *
 * Rueckgabe: array(stand, text)
 */
function ak_retain_abgleich()
{
    $r = ak_retain_themen();
    if ($r === null) {
        return array(-1, ak_t('TEST.A_RETAIN_UNBEKANNT'));
    }
    if (!$r) {
        return array(0, ak_t('TEST.A_RETAIN_LEER'));
    }
    $liste = array_keys(ak_mqtt_themen());
    $fremd = array_values(array_diff($r, $liste));
    $leben = array_values(array_intersect($r, array('ok', 'ts', 'fehler')));
    $s = array();
    if ($fremd) { $s[] = ak_t('TEST.A_RETAIN_FREMD') . ': ' . ak_e(implode(', ', $fremd)); }
    if ($leben) { $s[] = ak_t('TEST.A_RETAIN_LEBEN') . ': ' . ak_e(implode(', ', $leben)); }
    if ($s) {
        return array(0, implode(' | ', $s));
    }
    return array(1, sprintf(ak_t('TEST.A_RETAIN_OK'), count($r), count($liste)));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function ak_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ak_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    /* Nachgezogen auf die Fassung aus APC-UPS 1.2.7 (29.09.2026): HintText
     * vorn, <Info templateType="2">, Unit und eigene Grenzen je Befehl. Die
     * Fassung aus APC-UPS 1.0.0 kannte das nicht - in Loxone Config standen
     * nackte Zahlen ohne Einheit (Regeln/07, "Unit ist Pflicht"). */
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . ak_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . ak_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ak_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ak_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ak_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $einheit = isset($c['einheit']) ? trim((string) $c['einheit']) : '';
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ak_x($c['title']) . '" ';
        $o .= 'Comment="' . ak_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . ak_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . ak_x(isset($c['min']) ? $c['min'] : -2147483647) . '" ';
        $o .= 'MaxVal="' . ak_x(isset($c['max']) ? $c['max'] : 2147483647) . '" ';
        $o .= 'Unit="' . ak_x($einheit === '' ? '<v.1>' : '<v.1> ' . $einheit) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Befehlserkennung eines Feldes - EINE Stelle fuer Vorlage und Tabelle.
 *
 * Das Semikolon gehoert ins Muster, und zwar zwingend.
 *
 * Loxone sucht die Zeichenkette WOERTLICH und nimmt den ersten Treffer. Ohne
 * fuehrendes Semikolon findet "LADEN=" auch die Stelle in "ENTLADEN=" - dass
 * es heute stimmt, laege nur daran, dass LADEN in der Zeile zufaellig vor
 * ENTLADEN steht. Faellt LADEN einmal weg oder wechselt die Reihenfolge,
 * stuende die Entladeleistung im Ladeeingang. Ein falscher Wert ist schlimmer
 * als ein fehlender.
 *
 * In jeder Statuszeile geht jedem Feld ein Semikolon voran
 * (ANKER;OK=1;SOC=...), das Muster passt also unveraendert.
 *
 * Bis 0.9.6 gab es diese Funktion nicht: die Vorlage setzte das Semikolon, die
 * Tabelle zum Abschreiben nicht - zwei Stellen, die dasselbe zusammensetzen,
 * laufen auseinander. Genau das war passiert.
 */
function ak_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Die Felder der Statusantwort.
 *
 * Je Feld: Einheit, Sprachschluessel, 'zeile'.
 *
 * 'zeile' => 0 heisst: nur ueber MQTT und aktion=roh, NICHT in der
 * ;-getrennten Statuszeile. Ein Anlagenname mit ';' oder '=' zerlegt sonst die
 * Zeile, die Loxone mit einer Befehlserkennung liest, und der Miniserver sieht
 * nur noch den Anfang.
 */
function ak_status_felder()
{
    return array(
        'OK'          => array('',    'AK_FELD.OK',          1),
        'SOC'         => array('%',   'AK_FELD.SOC',         1),
        'PV'          => array('W',   'AK_FELD.PV',          1),
        'LADEN'       => array('W',   'AK_FELD.LADEN',       1),
        'ENTLADEN'    => array('W',   'AK_FELD.ENTLADEN',    1),
        'BATP'        => array('W',   'AK_FELD.BATP',        1),
        'AUSGANG'     => array('W',   'AK_FELD.AUSGANG',     1),
        'HAUS'        => array('W',   'AK_FELD.HAUS',        1),
        'NETZBEZUG'   => array('W',   'AK_FELD.NETZBEZUG',   1),
        'NETZEINSP'   => array('W',   'AK_FELD.NETZEINSP',   1),
        'SOLL'        => array('W',   'AK_FELD.SOLL',        1),
        'MODUS'       => array('',    'AK_FELD.MODUS',       1),
        'RESERVE'     => array('%',   'AK_FELD.RESERVE',     1),
        'EINSPEISUNG' => array('',    'AK_FELD.EINSPEISUNG', 1),
        'GRENZE'      => array('W',   'AK_FELD.GRENZE',      1),
        'PROGNOSE'    => array('kWh', 'AK_FELD.PROGNOSE',    1),
        'GERAETE'     => array('',    'AK_FELD.GERAETE',     1),
        'ALTER'       => array('s',   'AK_FELD.ALTER',       1),
        'NAME'        => array('',    'AK_FELD.NAME',        0),
    );
}

function ak_energie_felder()
{
    return array(
        'OK'        => array('',    'AK_EFELD.OK',        1),
        'PV'        => array('kWh', 'AK_EFELD.PV',        1),
        'BATLD'     => array('kWh', 'AK_EFELD.BATLD',     1),
        'BATENTL'   => array('kWh', 'AK_EFELD.BATENTL',   1),
        'HAUS'      => array('kWh', 'AK_EFELD.HAUS',      1),
        'NETZBEZUG' => array('kWh', 'AK_EFELD.NETZBEZUG', 1),
        'NETZEINSP' => array('kWh', 'AK_EFELD.NETZEINSP', 1),
        'ZPV'       => array('kWh', 'AK_EFELD.ZPV',       1),
        'ZHAUS'     => array('kWh', 'AK_EFELD.ZHAUS',     1),
        'ZBEZUG'    => array('kWh', 'AK_EFELD.ZBEZUG',    1),
        'ZEINSP'    => array('kWh', 'AK_EFELD.ZEINSP',    1),
        'MPV'       => array('kWh', 'AK_EFELD.MPV',       1),
        'MHAUS'     => array('kWh', 'AK_EFELD.MHAUS',     1),
        'JPV'       => array('kWh', 'AK_EFELD.JPV',       1),
        'JHAUS'     => array('kWh', 'AK_EFELD.JHAUS',     1),
        'ALTER'     => array('s',   'AK_EFELD.ALTER',     1),
        'DATUM'     => array('',    'AK_EFELD.DATUM',     0),
    );
}

function ak_geraet_felder()
{
    return array(
        'OK'        => array('',  'AK_GFELD.OK',        1),
        'SOC'       => array('%', 'AK_GFELD.SOC',       1),
        'PV'        => array('W', 'AK_GFELD.PV',        1),
        'AUSGANG'   => array('W', 'AK_GFELD.AUSGANG',   1),
        'LADEN'     => array('W', 'AK_GFELD.LADEN',     1),
        'SOLL'      => array('W', 'AK_GFELD.SOLL',      1),
        'ONLINE'    => array('',  'AK_GFELD.ONLINE',    1),
        'WLAN'      => array('',  'AK_GFELD.WLAN',      1),
        'LEISTUNG'  => array('W', 'AK_GFELD.LEISTUNG',  1),
        'ALTER'     => array('s', 'AK_GFELD.ALTER',     1),
        'NAME'      => array('',  'AK_GFELD.NAME',      0),
        'FW'        => array('',  'AK_GFELD.FW',        0),
    );
}

/** Die Feldliste eines Satzes. */
function ak_felder($satz)
{
    if ($satz === 'energie') {
        return ak_energie_felder();
    }
    if ($satz === 'geraet') {
        return ak_geraet_felder();
    }
    return ak_status_felder();
}

/** Nur die Felder, die wirklich in der ;-getrennten Zeile stehen. */
function ak_felder_zeile($satz)
{
    $out = array();
    foreach (ak_felder($satz) as $name => $f) {
        if (!empty($f[2])) {
            $out[$name] = $f;
        }
    }
    return $out;
}

/**
 * Ist jedes Suchmuster in der Zeile eindeutig?
 *
 * Loxone nimmt den ERSTEN woertlichen Treffer. Zwei Felder, von denen das eine
 * im anderen steckt, waeren eine Falle - ';LADEN=' gegen ';ENTLADEN=' geht nur
 * deshalb gut, weil das fuehrende Semikolon dabei ist. Diese Zeile misst das
 * nach, statt es zu behaupten.
 *
 * Bis 0.9.23 verglich sie ';A=' mit ';B=' fuer zwei VERSCHIEDENE Namen - das
 * trifft nie, die Zeile konnte nicht anschlagen (Befund b3), und sie fragte
 * ak_check() gar nicht. Jetzt (Verbesserung b3, 30.09.2026): die Antwortzeile
 * wird gebaut wie im Endpunkt (Kennung, dann ';FELD=Wert' in der Reihenfolge
 * von ak_felder_zeile()), der Suchtext kommt aus ak_check() (zwischen den
 * beiden \i), und sein ERSTER Treffer muss das eigene Feld sein.
 *
 * $saetze: fuer die Gegenprobe eine eigene Liste (Satz => Feldnamen); ohne
 * Angabe die Felder aus ak_felder_zeile().
 * Rueckgabe: array(stand, text)
 */
function ak_muster_eindeutig($saetze = null)
{
    if (!is_array($saetze)) {
        $saetze = array();
        foreach (array('status', 'energie', 'geraet') as $satz) {
            $saetze[$satz] = array_keys(ak_felder_zeile($satz));
        }
    }
    $doppel = array();
    $anzahl = 0;
    foreach ($saetze as $satz => $namen) {
        // Eine leere Feldliste ist kein "eindeutig" (CLAUDE.md, 6).
        if (!is_array($namen) || !$namen) {
            $doppel[] = $satz . ': ' . ak_t('TEST.A_MUSTER_LEER');
            continue;
        }
        $zeile = 'X';
        $stellen = array();
        foreach ($namen as $i => $n) {
            $stellen[$i] = strlen($zeile);
            $zeile .= ';' . $n . '=0';
        }
        foreach ($namen as $i => $n) {
            $anzahl++;
            if (!preg_match('/\\\\i(.+?)\\\\i/', ak_check($n), $m)) {
                $doppel[] = $satz . ': ' . $n . ' (' . ak_t('TEST.A_MUSTER_KEIN_SUCHTEXT') . ')';
                continue;
            }
            // Wo der Suchtext im EIGENEN Abschnitt ';NAME=Wert' steht - dort
            // muss sein erster Treffer in der ganzen Zeile liegen.
            $innen = strpos(';' . $n . '=0', $m[1]);
            if ($innen === false) {
                $doppel[] = $satz . ': ' . $n . ' (' . ak_t('TEST.A_MUSTER_FREMD') . ')';
                continue;
            }
            $pos = strpos($zeile, $m[1]);
            if ($pos === $stellen[$i] + $innen) {
                continue;
            }
            // Wessen Abschnitt trifft der Suchtext zuerst?
            $bei = '?';
            if ($pos !== false) {
                foreach ($stellen as $j => $s) {
                    if ($s <= $pos) {
                        $bei = $namen[$j];
                    }
                }
            }
            $doppel[] = $satz . ': ' . $n . ' -> ' . $bei;
        }
    }
    return $doppel
        ? array(0, sprintf(ak_t('TEST.A_MUSTER_DOPPELT'), ak_e(implode(', ', $doppel))))
        : array(1, sprintf(ak_t('TEST.A_MUSTER_OK'), $anzahl));
}

/** Der Rechnername, aus dem alle angezeigten Adressen gebildet werden. */
function ak_host()
{
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/**
 * Die Adresse eines Endpunktaufrufs - EINE Stelle.
 *
 * Eine Adresse, die angezeigt wird, wird aus demselben Bauteil gebildet wie
 * die Adressen, die das Plugin selbst benutzt. Zwei Stellen, die dasselbe
 * zusammensetzen, laufen auseinander - und dann weist das Plugin die eigene
 * Anleitung ab. Jede angezeigte Adresse traegt deshalb jeden Parameter, den
 * der Endpunkt verlangt, das Token eingeschlossen.
 */
function ak_adresse($parameter = array(), $mit_host = true)
{
    $p = ak_paths();
    $q = array_merge(array('token' => ak_token()), $parameter);
    $teile = array();
    foreach ($q as $k => $v) {
        $teile[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return ($mit_host ? 'http://' . ak_host() : '')
         . '/plugins/' . $p['plugin'] . '/index.php?' . implode('&', $teile);
}

/**
 * Vorlage fuer den Import in Loxone Config.
 *
 * $satz: 'status' | 'energie' | 'geraet'.
 * Rueckgabe: array(name, inhalt)
 */
function ak_vorlage($satz = 'status', $nummer = 1, $sn = '')
{
    $satz = in_array($satz, array('status', 'energie', 'geraet'), true) ? $satz : 'status';
    $nummer = max(1, min(99, (int) $nummer));
    $sn = preg_replace('/[^A-Za-z0-9]/', '', (string) $sn);
    $cmds = array();
    $praefix = $satz === 'geraet'
        ? 'ANKER_G_' . $sn
        : 'ANKER_' . ($satz === 'energie' ? 'E_' : '') . $nummer;
    foreach (ak_felder_zeile($satz) as $feld => $info) {
        // Der Text laeuft gleich durch ak_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich
        // 'l&auml;dt' statt 'laedt'.
        // Der Comment wird in Loxone Config zum Kachelnamen - hoechstens 40
        // Zeichen (Verbesserung b4, 30.09.2026). Gibt es eine Kurzfassung
        // (AK_KURZ.FELD_OK zu AK_FELD.OK), gilt sie; die Feldtabelle im Reiter
        // "Einbindung in Loxone" behaelt die ausfuehrliche Beschreibung.
        $ak_kurz = 'AK_KURZ.' . str_replace('.', '_', substr($info[1], 3));
        $ak_text = ak_t($ak_kurz);
        if ($ak_text === $ak_kurz) {
            $ak_text = ak_t($info[1]);
        }
        $bedeutung = trim(strip_tags(html_entity_decode($ak_text, ENT_QUOTES, 'UTF-8')));
        /* Grenzen sind in Loxone eine VALIDIERUNG: ein Wert darueber wird 0
         * (Regeln/07). Deshalb weit genug fuer jeden Wert, den das Plugin
         * senden kann; ALTER kann -1 sein ("noch nie abgerufen"), Leistungen
         * sind vorzeichenbehaftet. Fehlende Werte gehen als "-" hinaus und
         * beruehren die Grenzen nicht. */
        $grenzen = array('%' => array(0, 100), 'W' => array(-1000000, 1000000),
                         'kWh' => array(0, 1000000000), 's' => array(-1, 2147483647));
        $g = isset($grenzen[$info[0]]) ? $grenzen[$info[0]] : array(-2147483647, 2147483647);
        $cmds[] = array(
            'title'   => $praefix . '_' . $feld,
            'comment' => $bedeutung,
            'check'   => ak_check($feld),
            'einheit' => $info[0],
            'min'     => $g[0],
            'max'     => $g[1],
        );
    }
    if ($satz === 'geraet') {
        $adresse = ak_adresse(array('aktion' => 'geraet', 'sn' => $sn));
        $titel = 'Anker SOLIX Geraet ' . $sn;
        $takt = '60';
        $name = 'ankersolix_geraet_' . $sn . '.xml';
    } elseif ($satz === 'energie') {
        $adresse = ak_adresse(array('aktion' => 'energie', 'anlage' => $nummer));
        $titel = 'Anker SOLIX Energie ' . $nummer;
        $takt = '300';
        $name = 'ankersolix_energie' . $nummer . '.xml';
    } else {
        $adresse = ak_adresse(array('aktion' => 'status', 'anlage' => $nummer));
        $titel = 'Anker SOLIX ' . $nummer;
        $takt = '60';
        $name = 'ankersolix_anlage' . $nummer . '.xml';
    }
    return array($name, ak_xml_virtual_in_http(array(
        'title'   => $titel,
        'address' => $adresse,
        'polling' => $takt,
        'comment' => 'Erzeugt vom LoxBerry-Plugin Anker SOLIX (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und überschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/**
 * Vorlage der Steuerbefehle (Virtueller Ausgang).
 *
 * Format wie ein Original-Export aus Loxone Config 17.1. Uebernommen aus
 * LoxBerry-Plugin-MarstekVenus-1.0.15 (marstek_vo_vorlage) - dort geprueft.
 *
 * ACHTUNG: die Datei enthaelt das Aktionstoken im Klartext. Das steht auch im
 * Kommentar der Datei selbst, damit es niemand weitergibt.
 */
function ak_vo_vorlage($nummer = 1)
{
    $p = ak_paths();
    $nummer = max(1, min(99, (int) $nummer));
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Anker SOLIX ' . $nummer . ' steuern (LoxBerry-Plugin)" '
        . 'Comment="Steuerbefehle über das Plugin ' . ak_x($p['plugin'])
        . ' - ENTHÄLT DAS AKTIONSTOKEN, nicht weitergeben. Loxone Config legt beim '
        . 'Import neu an und überschreibt nichts." '
        . 'Address="http://' . ak_x(ak_host()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    $befehle = array(
        array(ak_t('VO.HAUSLAST'),  array('aktion' => 'hauslast', 'anlage' => $nummer), 'watt=<v>', true),
        array(ak_t('VO.EIGEN'),     array('aktion' => 'modus', 'anlage' => $nummer, 'wert' => 'eigenverbrauch'), '', false),
        array(ak_t('VO.MANUELL'),   array('aktion' => 'modus', 'anlage' => $nummer, 'wert' => 'manuell'), '', false),
        array(ak_t('VO.SMART'),     array('aktion' => 'modus', 'anlage' => $nummer, 'wert' => 'smart'), '', false),
        array(ak_t('VO.RESERVE'),   array('aktion' => 'reserve', 'anlage' => $nummer), 'prozent=<v>', true),
        array(ak_t('VO.EINSP_AUS'), array('aktion' => 'einspeisung', 'anlage' => $nummer, 'wert' => 'aus'), '', false),
        array(ak_t('VO.EINSP_EIN'), array('aktion' => 'einspeisung', 'anlage' => $nummer, 'wert' => 'ein'), '', false),
        array(ak_t('VO.GRENZE'),    array('aktion' => 'einspeisegrenze', 'anlage' => $nummer), 'watt=<v>', true),
        array(ak_t('VO.NOTSTROM'),  array('aktion' => 'notstromreserve', 'anlage' => $nummer), 'prozent=<v>', true),
        array(ak_t('VO.ABRUF'),     array('aktion' => 'abruf', 'anlage' => $nummer), '', false),
    );
    /* Der Comment ist der ANZEIGENAME in Loxone Config (Regeln/07); bis
     * 0.9.11 stand dort "", und Config zeigte den Titel. Der Vorsatz nennt
     * die Anlage - bei zwei Speichern stehen sonst zweimal dieselben zehn
     * Namen in der Bausteinsuche. Uebersetzt wird nichts Neues: die
     * Beschriftung ist derselbe Text wie im Titel. */
    $vorsatz = 'SOLIX ' . $nummer . ': ';
    foreach ($befehle as $c) {
        // Der Platzhalter <v> darf NICHT durch rawurlencode laufen - Loxone
        // ersetzt ihn woertlich. Deshalb wird er hinter der Adresse angehaengt.
        $adr = ak_adresse($c[1], false) . ($c[2] !== '' ? '&' . $c[2] : '');
        $o .= "\t" . '<VirtualOutCmd Title="' . ak_x($c[0]) . '" Comment="'
            . ak_x($vorsatz . $c[0]) . '" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . ak_x($adr) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="' . (!empty($c[3]) ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('ankersolix_steuern' . $nummer . '.xml', $o);
}

/**
 * Alles auf einmal: jede Vorlage, die zu dieser Anlage gehoert, in EINEM
 * Archiv. Wer mehrere Anlagen und mehrere Geraete hat, klickt sonst ein
 * Dutzend Mal.
 *
 * Rueckgabe: array(name, inhalt) - ein ZIP, wenn die Erweiterung da ist,
 * sonst null. ZipArchive steht NICHT in dpkg/apt, ist also nicht zugesichert
 * und wird mit class_exists() geprueft.
 */
function ak_vorlagen_paket()
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $dateien = array();
    foreach (ak_anlagen() as $nr => $an) {
        $dateien[] = ak_vorlage('status', (int) $nr);
        $dateien[] = ak_vorlage('energie', (int) $nr);
        $dateien[] = ak_vo_vorlage((int) $nr);
    }
    foreach (ak_geraete() as $sn => $g) {
        $dateien[] = ak_vorlage('geraet', 1, $sn);
    }
    if (!$dateien) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ak_');
    if ($tmp === false) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        return null;
    }
    foreach ($dateien as $d) {
        $zip->addFromString($d[0], $d[1]);
    }
    $zip->addFromString('LIESMICH.txt',
        "Anker SOLIX - Loxone-Vorlagen\r\n"
        . "Erzeugt am " . date('d.m.Y H:i') . "\r\n\r\n"
        . "ankersolix_anlageN.xml    Virtuelle Eingaenge, Leistungswerte (Takt 60 s)\r\n"
        . "ankersolix_energieN.xml   Virtuelle Eingaenge, Energiewerte (Takt 300 s)\r\n"
        . "ankersolix_geraet_SN.xml  Virtuelle Eingaenge je Geraet\r\n"
        . "ankersolix_steuernN.xml   Virtueller Ausgang mit den Steuerbefehlen\r\n\r\n"
        . "ACHTUNG: die Dateien enthalten das Aktionstoken im Klartext.\r\n"
        . "Wer sie weitergibt, gibt den Zugriff auf die Anlage mit weiter.\r\n\r\n"
        . "Loxone Config legt beim Import NEU an und ueberschreibt nichts -\r\n"
        . "zweimal eingelesen ergibt doppelte Bausteine.\r\n");
    $zip->close();
    $inhalt = (string) @file_get_contents($tmp);
    @unlink($tmp);
    return array('ankersolix_vorlagen.zip', $inhalt);
}

/* ==================================================================
 * Selbstpruefung: Teile, die BEIDE Seiten brauchen
 * ================================================================== */

/**
 * Ruft den eigenen Endpunkt wirklich auf.
 *
 * Alle uebrigen Pruefzeilen sehen sich Dateien an. Nur diese eine spricht die
 * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet die
 * Klasse, bei der html/ und htmlauth/ installiert in getrennten Baeumen liegen
 * und der Endpunkt mit HTTP 500 antwortet, ohne dass es jemand merkt.
 *
 * Drei Ausgaenge, nicht zwei. Der dritte ist wichtig: ein Webserver, der nur
 * eine Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus nicht
 * selbst aufrufen. Ein Kreuz waere dort ein Kreuz, das nichts bedeutet.
 *
 * 127.0.0.1 ist hier die RICHTIGE Adresse - das gilt fuer einen Aufruf vom
 * Server aus, nicht fuer einen Knopf, den ein Mensch im Browser anklickt.
 *
 * Rueckgabe: array(stand, text). stand 1 = Haken, 0 = Kreuz, -1 = Hinweis.
 */
function ak_endpunkt_probe($frist = 3)
{
    $p = ak_paths();
    $pfad = '/plugins/' . $p['plugin'] . '/index.php?token=' . rawurlencode(ak_token()) . '&aktion=selftest';
    $url = 'http://127.0.0.1' . $pfad;
    $rumpf = '';
    $code = 0;
    $fehler = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $frist);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $frist);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: ' . ak_host()));
        $rumpf = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = (string) curl_error($ch);
        // Seit PHP 8.0 wirkungslos, unter 8.5 als ueberholt gemeldet.
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    } else {
        // Ohne die curl-Erweiterung ueber Streams. php-curl steht NICHT in
        // dpkg/apt, ist also nicht zugesichert - deshalb function_exists().
        $ctx = stream_context_create(array('http' => array(
            'timeout' => (int) $frist, 'ignore_errors' => true,
            'header' => 'Host: ' . ak_host() . "\r\n",
        )));
        list($ak_roh, $code) = ak_http_abruf($url, $ctx);
        $rumpf = $ak_roh === false ? '' : (string) $ak_roh;
    }

    if ($code === 0 && trim($rumpf) === '') {
        return array(-1, sprintf(ak_t('TEST.A_ENDPUNKT_STUMM'), $fehler !== '' ? $fehler : '-'));
    }
    if ($code === 200 && strpos($rumpf, 'SELFTEST;OK=1') !== false) {
        return array(1, sprintf(ak_t('TEST.A_ENDPUNKT_OK'), $pfad));
    }
    return array(0, sprintf(ak_t('TEST.A_ENDPUNKT_FEHL'),
        $code, substr(trim(preg_replace('/\s+/', ' ', $rumpf)), 0, 160)));
}

/**
 * Eine Adresse abrufen und den HTTP-Code aus den Kopfzeilen lesen.
 * Rueckgabe: array(Inhalt oder false, Code; 0 = kein Code erkennbar).
 *
 * Ueber fopen() und stream_get_meta_data() statt ueber die alte
 * Kopfzeilen-Variable von PHP: 8.5 meldet sie als ueberholt (Kette
 * 29.09.2026). Bauform eb_http_abruf() aus Einspeisebremse 0.9.28; die
 * Kopfzeilen im wrapper_data gibt es unter 7.4 wie unter 8.x.
 */
function ak_http_abruf($url, $ctx)
{
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(false, 0);
    }
    $meta = @stream_get_meta_data($fp);
    $t = @stream_get_contents($fp);
    @fclose($fp);
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    return array($t, $code);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein ak_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function ak_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'.
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt beim
 * Durchsehen sofort auf, was fehlt, statt dass die Seite leer bleibt.
 */
function ak_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ak_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    $a = $teile[0];
    $s = $teile[1];
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 * Zugangsdaten|null).
 */
function ak_sicherung_lesen($roh, &$namen = null)
{
    $mangel = array();
    // X-3 (Verbesserungsbau 30.09.2026): die Namen der beanstandeten
    // Schluessel, nie ihre Werte.
    $namen = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ak_t('EINST.SICH_KEIN_JSON')), 0, null);
    }
    $neu = ak_vorgaben();
    $bekannt = array_keys($neu);
    $zugang = array();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        // Der lesbare Kopf (_hinweis ...) wird ueberlesen.
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        // Zugangsdaten gehoeren in zugang.json, nicht in die Konfiguration.
        if ($k === 'email' || $k === 'passwort') {
            $f = !is_string($w) ? ak_t('EINST.SICH_TEXT')
                : (($k === 'email' && $w !== '' && filter_var($w, FILTER_VALIDATE_EMAIL) === false)
                    ? ak_t('EINST.FEHLER_EMAIL') : '');
            if ($f !== '') {
                $mangel[] = ak_e($k) . ': ' . $f;
                $namen[] = $k;
            } else {
                $zugang[$k] = $w;
                $anzahl++;
            }
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ak_t('EINST.SICH_FREMD'), ak_e($k));
            $namen[] = $k;
            continue;
        }
        // Jeder Wert wird geprueft (Befund Oberflaeche 4) - gesammelt, nicht
        // der erste; eine halb gueltige Datei aendert nichts.
        $f = ak_sicherung_wert($k, $w);
        if ($f !== '') {
            $mangel[] = ak_e($k) . ': ' . $f;
            $namen[] = $k;
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ak_t('EINST.SICH_LEER');
    }
    // Wie das Formular: kleinste nicht ueber groesster - nur wenn beide
    // Werte aus der Datei angenommen wurden.
    if (isset($daten['hauslast_min'], $daten['hauslast_max'])
        && $neu['hauslast_min'] === $daten['hauslast_min']
        && $neu['hauslast_max'] === $daten['hauslast_max']
        && $neu['hauslast_min'] > $neu['hauslast_max']) {
        $mangel[] = ak_t('EINST.FEHLER_HAUSLAST_TAUSCH');
        $namen[] = 'hauslast_min';
        $namen[] = 'hauslast_max';
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(ak_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $namen = array_merge($namen, $fehlend);
        $mangel[] = sprintf(ak_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    // Rueckgabe: array(Konfiguration|null, Beanstandungen, Anzahl,
    // Zugangsdaten|null). email/passwort duerfen fehlen (aeltere Sicherung).
    return array($mangel ? null : $neu, $mangel, $anzahl, ($mangel || !$zugang) ? null : $zugang);
}

/**
 * Einen Wert der Sicherung pruefen: Typ wie in ak_vorgaben(), Grenzen wie
 * im Formular (ak_zahlgrenzen()), Auswahl nur aus der erlaubten Liste.
 * Rueckgabe: '' = gueltig, sonst die Beanstandung.
 */
function ak_sicherung_wert($k, $w)
{
    $zahlen = ak_zahlgrenzen();
    if (isset($zahlen[$k])) {
        // is_int: true/false und "5" werden abgewiesen, nicht umgedeutet.
        return (is_int($w) && $w >= $zahlen[$k][0] && $w <= $zahlen[$k][1])
            ? '' : sprintf(ak_t('EINST.SICH_ZAHL'), $zahlen[$k][0], $zahlen[$k][1]);
    }
    if (in_array($k, ak_haken_felder(), true)) {
        return ($w === 0 || $w === 1) ? '' : ak_t('EINST.SICH_HAKEN');
    }
    switch ($k) {
        case 'land':
            // Wie das Formular: zwei Grossbuchstaben (eine feste Liste gibt es nicht).
            return (is_string($w) && preg_match('/^[A-Z]{2}\z/', $w)) ? '' : ak_t('EINST.SICH_LAND');
        case 'rueckfall_modus':
            return (is_string($w) && array_key_exists($w, ak_modi()))
                ? '' : sprintf(ak_t('EINST.SICH_AUSWAHL'), implode(', ', array_keys(ak_modi())));
        case 'mqtt_topic':
            return ak_topic_gueltig($w) ? '' : ak_t('EINST.FEHLER_TOPIC');
        case 'aktionstoken':
            return (is_string($w) && preg_match('/^[A-Za-z0-9]{16,64}\z/', $w)) ? '' : ak_t('EINST.SICH_TOKEN');
        case 'anlagen_grenzen':
            return ak_sicherung_grenzen_gueltig($w) ? '' : ak_t('EINST.SICH_GRENZEN');
    }
    // Ein kuenftiger Schluessel ohne eigene Regel: wenigstens der Typ der Vorgabe.
    $v = ak_vorgaben();
    return (array_key_exists($k, $v) && gettype($w) === gettype($v[$k])) ? '' : ak_t('EINST.SICH_TEXT');
}

/**
 * anlagen_grenzen so, wie das Formular sie baut: je Anlagennummer (1-99)
 * genau min und max, jedes '' oder hoechstens vier Ziffern (als Zeichenkette
 * wie aus dem Formular, oder als Zahl), min nicht groesser als max.
 */
function ak_sicherung_grenzen_gueltig($w)
{
    if (!is_array($w)) {
        return false;
    }
    foreach ($w as $nr => $g) {
        if (!preg_match('/^[1-9][0-9]?\z/', (string) $nr) || !is_array($g)) {
            return false;
        }
        $s = array_keys($g);
        sort($s);
        if ($s !== array('max', 'min')) {
            return false;
        }
        foreach (array('min', 'max') as $f) {
            $v = $g[$f];
            if (is_int($v)) {
                if ($v < 0 || $v > 9999) {
                    return false;
                }
            } elseif (!is_string($v) || ($v !== '' && !preg_match('/^[0-9]{1,4}\z/', $v))) {
                return false;
            }
        }
        if ((string) $g['min'] !== '' && (string) $g['max'] !== '' && (int) $g['min'] > (int) $g['max']) {
            return false;
        }
    }
    return true;
}

/**
 * Die Sicherungsdatei bauen: lesbarer Kopf, alle Einstellungen, dazu E-Mail
 * und Passwort aus zugang.json (Hausstandard: die Datei traegt alle
 * Zugangsdaten; Befund Oberflaeche 3). Die Fassung fragt LoxBerry nach dem
 * Ordnernamen (pluginversion(<ordner>)); ohne LoxBerry steht '?'.
 */
function ak_sicherung_bauen($warnung = '')
{
    $p = ak_paths();
    $fassung = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $fassung = (string) LBSystem::pluginversion($p['plugin']);
    }
    $z = ak_json_lesen($p['zugang']);
    $kopf = array('_hinweis' => sprintf(ak_t('EINST.SICH_KOPF'), $p['plugin'],
        $fassung !== '' ? $fassung : '?', date('Y-m-d H:i:s')));
    // X-3: bestuende die Datei das eigene Zurueckspielen nicht, sagt es der
    // Kopf - mit den Namen, nie den Werten (ak_rueckspiel_altwerte()).
    if ((string) $warnung !== '') {
        $kopf['_warnung'] = (string) $warnung;
    }
    $zugang = array(
        'email'    => isset($z['email']) ? (string) $z['email'] : '',
        'passwort' => isset($z['passwort']) ? (string) $z['passwort'] : '',
    );
    return json_encode($kopf + ak_config() + $zugang,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * X-3 (Verbesserungsbau 30.09.2026): Welche gespeicherten Werte bestuenden
 * das eigene Zurueckspielen nicht? Die Sicherung wird gebaut und durch
 * ak_sicherung_lesen() geschickt - dieselbe Pruefung wie beim Zurueckspielen.
 * Rueckgabe: Liste der NAMEN, nie der Werte; leer = die Sicherung liesse sich
 * zurueckspielen (oder liess sich gar nicht bauen - das meldet der Knopf).
 * Der Name traegt bewusst kein "sicherung": Werkzeuge/sicherung_pruefen.py
 * nimmt die erste Funktion *_sicherung* mit json_encode fuer die Ausfuhr.
 */
function ak_rueckspiel_altwerte()
{
    $roh = ak_sicherung_bauen();
    if (!is_string($roh)) {
        return array();
    }
    $namen = array();
    list($neu) = ak_sicherung_lesen($roh, $namen);
    if ($neu !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
}

/* ---------------- Einmalmeldung nach dem POST (B11) ----------------
 *
 * Jeder POST endet mit 303 (Regeln/04); was die Seite danach zeigen soll,
 * reist in dieser Datei: im Datenordner, 0600, beim naechsten GET gelesen
 * und geloescht, aelter als 120 s verworfen. Bauform eb_einmal_*() aus
 * Einspeisebremse 0.9.28. Passwort und Aktionstoken werden vor dem
 * Schreiben unkenntlich gemacht (Regeln/04, Nachtrag Raumklima 17.09.).
 */
/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2;
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular")
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Nie Geheimnisse: das Passwort des Anker-Kontos steht in keiner Liste und
 * reist deshalb nie mit; sein Feld wird hoechstens markiert.
 * ================================================================== */

/** Die Felder je Formular: array(text => [...], haken => [...], muster => regex|''). */
function ak_eingabe_felder($form)
{
    $felder = array(
        'settings' => array(
            'text'   => array('email', 'land', 'intervall', 'takt_details', 'takt_energie',
                              'takt_prognose', 'endpunkt_limit', 'anfrage_pause', 'anfrage_frist',
                              'verlauf_tage', 'energie_tage', 'hauslast_min', 'hauslast_max',
                              'schreibbremse', 'schrittweite', 'rueckfall_min', 'rueckfall_modus',
                              'wartezeit', 'melden_alter'),
            'haken'  => array('ohne_details', 'ohne_energie', 'ohne_prognose', 'zaehler_ein',
                              'steuerung_ein', 'melden_ein'),
            // Grenzen je Anlage: gmin_<nr>, gmax_<nr>
            'muster' => '/^g(min|max)_[1-9][0-9]?\z/',
        ),
        'mqtt' => array(
            'text'   => array('mqtt_topic'),
            'haken'  => array('mqtt_ein', 'mqtt_nur_aenderung'),
            'muster' => '',
        ),
        'test' => array(
            'text'   => array('test_anlage', 'test_watt', 'test_prozent', 'test_modus', 'test_trocken'),
            'haken'  => array(),
            'muster' => '',
        ),
    );
    return isset($felder[$form]) ? $felder[$form] : null;
}

/** Gehoert $feld zum Formular $form (Text oder Muster)? */
function ak_eingabe_textfeld($f, $feld)
{
    return in_array($feld, $f['text'], true)
        || ($f['muster'] !== '' && preg_match($f['muster'], $feld) === 1);
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * Ein Wert, der kein gueltiges UTF-8 ist oder laenger als 256 Byte, reist
 * nicht mit (sonst scheiterte json_encode und mit ihm die Umleitung) - das
 * Feld zeigt dann den gespeicherten Stand.
 */
function ak_eingaben_sammeln($form, $beanstandet)
{
    $f = ak_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $werte = array();
    foreach ($_POST as $feld => $w) {
        $feld = (string) $feld;
        if (ak_eingabe_textfeld($f, $feld) && is_string($w) && strlen($w) <= 256
            && preg_match('//u', $w) === 1) {
            $werte[$feld] = $w;
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = isset($_POST[$feld]) ? '1' : '';
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text). */
function ak_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || ak_eingabe_felder($roh['form']) === null) {
        return $ein;
    }
    $f = ak_eingabe_felder($roh['form']);
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            $k = (string) $k;
            if ((ak_eingabe_textfeld($f, $k) || in_array($k, $f['haken'], true)) && is_string($v)) {
                $werte[$k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && ($b === 'passwort' || ak_eingabe_textfeld($f, $b)
                                  || in_array($b, $f['haken'], true))) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Welches Formular zeigt gerade Eingaben ('' = keines)? */
function ak_eingaben_aktiv()
{
    $ein = ak_eingaben_setzen();
    return $ein['form'];
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function ak_eingabe($form, $feld, $gespeichert)
{
    $ein = ak_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function ak_eingabe_an($form, $feld, $gespeichert)
{
    $ein = ak_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return (bool) $gespeichert;
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function ak_markierung($feld)
{
    $ein = ak_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function ak_einmal_schreiben($meldungen, $fehler, $test, $trocken, $eingaben = null)
{
    $p = ak_paths();
    $geheim = array();
    $cfg = ak_config(false);
    $z = ak_json_lesen($p['zugang']);
    foreach (array(isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '',
                   isset($z['passwort']) ? $z['passwort'] : '') as $g) {
        if (is_string($g) && strlen($g) >= 4) {
            $geheim[] = $g;
            $geheim[] = ak_e($g);
        }
    }
    $weg = function ($t) use ($geheim) {
        return $geheim ? str_replace($geheim, '***', (string) $t) : (string) $t;
    };
    $zeilen = array();
    foreach ((array) $trocken as $r) {
        if (is_array($r) && count($r) >= 2) {
            $zeilen[] = array((int) $r[0], $weg($r[1]));
        }
    }
    // X-2: die Eingaben eines beanstandeten Formulars, ebenso gesaeubert.
    if (is_array($eingaben) && isset($eingaben['werte']) && is_array($eingaben['werte'])) {
        $eingaben['werte'] = array_map($weg, $eingaben['werte']);
    } else {
        $eingaben = null;
    }
    return ak_json_schreiben($p['datadir'] . '/einmalmeldung.json', array(
        'zeit'      => time(),
        'meldungen' => array_map($weg, array_values((array) $meldungen)),
        'fehler'    => array_map($weg, array_values((array) $fehler)),
        'test'      => $weg($test),
        'trocken'   => $zeilen,
        'eingaben'  => $eingaben,
    ), 0600);
}

function ak_einmal_lesen()
{
    $f = ak_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $liste = function ($x) {
        return is_array($x) ? array_values(array_map('strval', array_filter($x, 'is_scalar'))) : array();
    };
    $zeilen = array();
    foreach ((isset($d['trocken']) && is_array($d['trocken'])) ? $d['trocken'] : array() as $r) {
        if (is_array($r) && isset($r[0], $r[1])) {
            $zeilen[] = array((int) $r[0], (string) $r[1]);
        }
    }
    return array(
        'meldungen' => $liste(isset($d['meldungen']) ? $d['meldungen'] : null),
        'fehler'    => $liste(isset($d['fehler']) ? $d['fehler'] : null),
        'test'      => isset($d['test']) ? (string) $d['test'] : '',
        'trocken'   => $zeilen,
        'eingaben'  => (isset($d['eingaben']) && is_array($d['eingaben'])) ? $d['eingaben'] : null,
    );
}
