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
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
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
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
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
            if ($erzeugen && ak_json_schreiben($p['config'], $cfg)) {
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
    if (!ak_json_schreiben($p['config'], $cfg)) {
        return false;
    }
    // Die Zweitschrift wird erst NACH dem gelungenen Schreiben erneuert.
    ak_json_schreiben($p['sicherung'], $cfg);
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
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Dienst ---------------- */

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
    // Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'ankersolix.py') !== false ? $pid : 0;
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
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = ak_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
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

    if ($aktion === 'hauslast') {
        $w = (int) $wert;
        list($lo, $hi) = ak_grenzen($cfg, $nr);
        $zeilen[] = ($w < $lo || $w > $hi)
            ? array(0, sprintf(ak_t('TROCKEN.AUSSER_GRENZEN'), $w, $lo, $hi))
            : array(1, sprintf(ak_t('TROCKEN.IN_GRENZEN'), $w, $lo, $hi));
        $soll = isset($an['sollwert']) ? $an['sollwert'] : null;
        if ($soll !== null && (int) $cfg['schrittweite'] > 0
            && abs($w - (int) $soll) < (int) $cfg['schrittweite']) {
            $zeilen[] = array(0, sprintf(ak_t('TROCKEN.SCHRITTWEITE'),
                abs($w - (int) $soll), (int) $cfg['schrittweite']));
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
    if ($bremse > 0) {
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

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - also Ergebnis unbekannt.
 * Es wird bewusst kein Erfolg gemeldet, den niemand geprueft hat.
 */
function ak_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = ak_paths();
    $cfg = ak_config(true);
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(20, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = ak_json_lesen($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
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
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
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
 * Die Themenliste gegen den Sendecode halten.
 *
 * Der teuerste Befund der Renault-Sitzung: Oberflaeche, Baustein-Liste und
 * Importdatei nannten fuenf Themen, die der Sendecode nie veroeffentlicht hat.
 * Wer die Importdatei einlas, bekam virtuelle Eingaenge, die dauerhaft auf 0
 * standen - ohne Fehlermeldung. Angeglichen wird die Anleitung an den
 * Sendecode, nicht umgekehrt; diese Zeile findet den Unterschied.
 *
 * Rueckgabe: array(stand, text)
 */
function ak_themen_abgleich()
{
    $p = ak_paths();
    $py = $p['bindir'] . '/ankersolix.py';
    if (!is_file($py)) {
        $py = dirname(dirname(__DIR__)) . '/bin/ankersolix.py';
    }
    if (!is_file($py)) {
        return array(-1, ak_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    $quelle = (string) @file_get_contents($py);
    if (!preg_match('/MQTT_THEMEN\s*=\s*\((.*?)\)\s*\n/s', $quelle, $m)) {
        return array(-1, ak_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    preg_match_all('/"([^"]+)"/', $m[1], $t);
    $dienst = $t[1];
    $hier = array_keys(ak_mqtt_themen());
    $nur_hier = array_values(array_diff($hier, $dienst));
    $nur_dort = array_values(array_diff($dienst, $hier));
    if (!$nur_hier && !$nur_dort) {
        return array(1, sprintf(ak_t('TEST.A_THEMEN_OK'), count($hier)));
    }
    $s = array();
    if ($nur_hier) { $s[] = ak_t('TEST.A_THEMEN_NUR_LISTE') . ': ' . implode(', ', $nur_hier); }
    if ($nur_dort) { $s[] = ak_t('TEST.A_THEMEN_NUR_CODE') . ': ' . implode(', ', $nur_dort); }
    return array(0, implode(' | ', $s));
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
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . ak_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ak_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ak_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ak_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
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
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
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
 * Rueckgabe: array(stand, text)
 */
function ak_muster_eindeutig()
{
    $doppel = array();
    foreach (array('status', 'energie', 'geraet') as $satz) {
        $namen = array_keys(ak_felder_zeile($satz));
        foreach ($namen as $a) {
            foreach ($namen as $b) {
                if ($a === $b) {
                    continue;
                }
                // Traefe das Muster von $a auch irgendwo in ';'.$b.'='?
                if (strpos(';' . $b . '=', ';' . $a . '=') !== false) {
                    $doppel[] = $satz . ': ' . $a . ' in ' . $b;
                }
            }
        }
    }
    return $doppel
        ? array(0, sprintf(ak_t('TEST.A_MUSTER_DOPPELT'), implode(', ', $doppel)))
        : array(1, ak_t('TEST.A_MUSTER_OK'));
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
        $bedeutung = trim(strip_tags(html_entity_decode(ak_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => $praefix . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => ak_check($feld),
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
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
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
        . 'Comment="Steuerbefehle ueber das Plugin ' . ak_x($p['plugin'])
        . ' - ENTHAELT DAS AKTIONSTOKEN, nicht weitergeben." '
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
    foreach ($befehle as $c) {
        // Der Platzhalter <v> darf NICHT durch rawurlencode laufen - Loxone
        // ersetzt ihn woertlich. Deshalb wird er hinter der Adresse angehaengt.
        $adr = ak_adresse($c[1], false) . ($c[2] !== '' ? '&' . $c[2] : '');
        $o .= "\t" . '<VirtualOutCmd Title="' . ak_x($c[0]) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
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
        curl_close($ch);
    } else {
        // Ohne die curl-Erweiterung ueber Streams. php-curl steht NICHT in
        // dpkg/apt, ist also nicht zugesichert - deshalb function_exists().
        $ctx = stream_context_create(array('http' => array(
            'timeout' => (int) $frist, 'ignore_errors' => true,
            'header' => 'Host: ' . ak_host() . "\r\n",
        )));
        $rumpf = (string) @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])
            && preg_match('#HTTP/[0-9.]+\s+([0-9]{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
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
