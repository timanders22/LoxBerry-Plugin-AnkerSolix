<?php
/**
 * Anker SOLIX - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Anker-Konto, ob die
 * Einrichtung traegt. Was sich nur mit Geraet pruefen liesse, wird als solches
 * benannt statt geraten.
 *
 * Die ERSTE Zeile ist ein echter HTTP-Aufruf gegen den eigenen Endpunkt. Alle
 * uebrigen sehen sich Dateien an; nur diese eine spricht die Stelle an, die
 * spaeter der Miniserver anspricht.
 *
 * Dazu kommen die Zeilen, die pruefen, ob die Oberflaeche selbst noch
 * zusammenpasst: Reiterliste gegen Bereiche, serverseitiges sm-active,
 * Vorgabeliste gegen den Dienst, Themenliste gegen den Sendecode,
 * Suchmuster auf Eindeutigkeit. Wer eine Pruefung blind macht - etwa durch
 * eine zusammengesetzte CSS-Klasse, die ein Werkzeug nicht mehr statisch
 * lesen kann -, ersetzt sie hier.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 = ja, 0 = nein, -1 = Hinweis. */
function ak_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/** Der Quelltext der Oberflaeche - Grundlage der Kongruenzproben. */
function ak_oberflaeche_quelle()
{
    $f = __DIR__ . '/index.php';
    return is_file($f) ? (string) @file_get_contents($f) : '';
}

/**
 * Positivliste, Reiterleiste und Bereiche muessen dieselben Namen tragen.
 *
 * Alle drei stehen ausgeschrieben und koennen deshalb auseinanderlaufen -
 * genau dafuer gibt es diese Probe. Ausgeschrieben stehen sie, weil
 * hausstandard_pruefen.py data-ziel="tab-…" als LITERAL sucht: bei einer
 * PHP-Schleife faende es null Reiter und setzte die Spalte auf "-", also
 * "trifft nicht zu" - und ein Strich sammelt sich beim Ueberfliegen wie ein
 * Haken ein.
 */
function ak_kongruenz()
{
    $s = ak_oberflaeche_quelle();
    if ($s === '') {
        return array(-1, ak_t('TEST.A_KONGRUENZ_UNBEKANNT'));
    }
    $liste = array();
    if (preg_match('/\$ak_reiter\s*=\s*array\((.*?)\);/s', $s, $m)) {
        preg_match_all("/'([a-z]+)'\s*=>/", $m[1], $x);
        $liste = $x[1];
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $leiste = $y[1];
    preg_match_all('/id="tab-([a-z]+)"/', $s, $z);
    $bereiche = $z[1];

    if (!$liste || !$leiste || !$bereiche) {
        return array(0, sprintf(ak_t('TEST.A_KONGRUENZ_LEER'),
            count($liste), count($leiste), count($bereiche)));
    }
    if ($liste === $leiste && $liste === $bereiche) {
        return array(1, sprintf(ak_t('TEST.A_KONGRUENZ_OK'), count($liste), implode(', ', $liste)));
    }
    return array(0, sprintf(ak_t('TEST.A_KONGRUENZ_ABWEICHUNG'),
        implode(', ', $liste), implode(', ', $leiste), implode(', ', $bereiche)));
}

/**
 * Setzt der SERVER das sm-active, oder erst das Skript?
 *
 * .sm-seite steht auf display:none. Setzte sm-active ausschliesslich das
 * JavaScript, waere die Seite ohne Skript VOLLSTAENDIG leer - nicht etwa
 * untereinander aufgeklappt. Genau das war bis 0.9.6 der Fall, und im
 * Quelltext stand darueber der Satz, die Seite sei dann "weiterhin bedienbar".
 *
 * Ein Kommentar, der eine Eigenschaft behauptet, ist kein Beleg dafuer.
 * Deshalb zaehlt diese Zeile nach.
 */
function ak_smactive_probe()
{
    $s = ak_oberflaeche_quelle();
    if ($s === '') {
        return array(-1, ak_t('TEST.A_SMACTIVE_UNBEKANNT'));
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $anzahl = count($y[1]);
    // Serverseitig gesetzt heisst: im Klassenattribut steht ein PHP-Ausdruck,
    // der ' sm-active' anhaengt.
    $leiste = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl) {
        return array(1, sprintf(ak_t('TEST.A_SMACTIVE_OK'), $anzahl));
    }
    return array(0, sprintf(ak_t('TEST.A_SMACTIVE_FEHLT'), $leiste, $bereiche, $anzahl));
}

/**
 * Steht in der erzeugten Vorlage jedes Feld - auch ohne Zwischenspeicher?
 *
 * Die Vorlage entsteht aus der Feldliste, nicht aus den zuletzt gelesenen
 * Werten. Waere es anders, bekaeme jemand, der die Vorlage vor dem ersten
 * gelungenen Abruf erzeugt, eine halbe Importdatei - und merkte es erst in
 * Loxone.
 */
function ak_vorlage_probe()
{
    $fehlt = array();
    foreach (array('status', 'energie') as $satz) {
        list(, $inhalt) = ak_vorlage($satz, 1);
        foreach (array_keys(ak_felder_zeile($satz)) as $feld) {
            if (strpos($inhalt, ak_x(ak_check($feld))) === false) {
                $fehlt[] = $satz . '/' . $feld;
            }
        }
    }
    return $fehlt
        ? array(0, sprintf(ak_t('TEST.A_VORLAGE_FEHLT'), implode(', ', $fehlt)))
        : array(1, sprintf(ak_t('TEST.A_VORLAGE_OK'),
            count(ak_felder_zeile('status')), count(ak_felder_zeile('energie'))));
}

function ak_pruefungen()
{
    $p = ak_paths();
    $cfg = ak_config(true);
    $z = ak_zugang();
    $zeilen = array();

    /* --- 1. Der eigene Endpunkt, wirklich aufgerufen --- */
    list($stand, $text) = ak_endpunkt_probe(3);
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_ENDPUNKT'), $text);

    /* --- 2. Laufzeitumgebung --- */
    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = ak_pruefzeile(is_file($venv) ? 1 : 0, ak_t('TEST.F_VENV'),
        is_file($venv) ? ak_e($venv) : ak_t('TEST.A_VENV_FEHLT'));

    $fassung = ak_bibliothek_fassung();
    $zeilen[] = ak_pruefzeile($fassung !== '' ? 1 : 0, ak_t('TEST.F_LIB'),
        $fassung !== '' ? 'anker-solix-api ' . ak_e($fassung) : ak_t('TEST.A_LIB_FEHLT'));

    $notify = $p['bindir'] . '/ak_notify.php';
    $zeilen[] = ak_pruefzeile(is_file($notify) ? 1 : -1, ak_t('TEST.F_MELDEWEG'),
        is_file($notify) ? ak_e($notify) : ak_t('TEST.A_MELDEWEG_FEHLT'));

    /* --- 3. Dienst --- */
    $pid = ak_dienst_pid();
    $zeilen[] = ak_pruefzeile($pid > 0 ? 1 : 0, ak_t('TEST.F_DIENST'),
        $pid > 0 ? ak_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (ak_dienst_soll() ? ak_t('TEST.A_DIENST_SOLL_TOT') : ak_t('TEST.A_DIENST_GESTOPPT')));

    // Ein Dienst, den der Waechter stuendlich aufsammelt, sieht in der Kachel
    // genauso gesund aus wie einer, der seit Wochen durchlaeuft.
    list($wn, $wz) = ak_waechter_stand();
    if ($wn > 0) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_WAECHTER'),
            sprintf(ak_t('TEST.A_WAECHTER'), $wn, ak_e($wz)));
    } else {
        $zeilen[] = ak_pruefzeile(1, ak_t('TEST.F_WAECHTER'), ak_t('TEST.A_WAECHTER_NIE'));
    }

    /* --- 4. Konto --- */
    $zeilen[] = ak_pruefzeile($z['email'] !== '' && strpos($z['email'], '@') !== false ? 1 : 0,
        ak_t('TEST.F_KONTO'),
        $z['email'] !== '' ? ak_e($z['email']) : ak_t('TEST.A_KONTO_FEHLT'));

    // Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie seinen
    // Wert anzeigen.
    $zeilen[] = ak_pruefzeile($z['laenge'] > 0 ? 1 : 0, ak_t('TEST.F_PASSWORT'),
        $z['laenge'] > 0 ? sprintf(ak_t('TEST.A_PASSWORT_DA'), $z['laenge']) : ak_t('TEST.A_PASSWORT_FEHLT'));

    $rechte = is_file($p['zugang']) ? (fileperms($p['zugang']) & 0777) : -1;
    $zeilen[] = ak_pruefzeile(($rechte >= 0 && ($rechte & 0077) === 0) ? 1 : 0,
        ak_t('TEST.F_RECHTE'),
        $rechte >= 0 ? '0' . decoct($rechte) : ak_t('TEST.A_ZUGANGSDATEI_FEHLT'));

    // Eine Zweitschrift, die es nicht gibt, heilt nichts.
    $zeilen[] = ak_pruefzeile(is_file($p['sicherung']) ? 1 : 0, ak_t('TEST.F_ZWEITSCHRIFT'),
        is_file($p['sicherung'])
            ? ak_e(basename($p['sicherung'])) . ' (' . date('d.m.Y H:i', filemtime($p['sicherung'])) . ')'
            : ak_t('TEST.A_ZWEITSCHRIFT_FEHLT'));

    // Eine beschaedigte Konfiguration bleibt als .kaputt liegen - liegt eine
    // da, hat es sie gegeben, und das gehoert gesagt.
    if (is_file($p['config'] . '.kaputt')) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_KAPUTT'),
            sprintf(ak_t('TEST.A_KAPUTT'), date('d.m.Y H:i', filemtime($p['config'] . '.kaputt'))));
    }

    /* --- 5. Daten --- */
    $anlagen = ak_anlagen();
    $zeilen[] = ak_pruefzeile(count($anlagen) > 0 ? 1 : 0, ak_t('TEST.F_ANLAGEN'),
        count($anlagen) > 0 ? sprintf(ak_t('TEST.A_ANLAGEN'), count($anlagen), count(ak_geraete()))
                            : ak_t('TEST.A_KEINE_ANLAGEN'));

    $alter = ak_alter();
    if ($alter < 0) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_ABRUF'), ak_t('TEST.A_NIE_ABGERUFEN'));
    } else {
        $frisch = $alter <= max(120, 3 * (int) $cfg['intervall']);
        $zeilen[] = ak_pruefzeile($frisch ? 1 : 0, ak_t('TEST.F_ABRUF'),
            sprintf(ak_t('TEST.A_ABRUF_ALTER'), $alter));
    }

    $zu = ak_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_LETZTER_FEHLER'), ak_e($zu['fehler']));
    }

    // Das Zaehlwerk sagt, ob der Takt zur Anfragegrenze der Cloud passt.
    // Ohne diese Zahl stellt man Takt und Abrufumfang blind ein.
    $zw = isset($zu['zaehlwerk']) && is_array($zu['zaehlwerk']) ? $zu['zaehlwerk'] : array();
    if ($zw) {
        $n429 = (int) (isset($zw['http429']) ? $zw['http429'] : 0);
        $zeilen[] = ak_pruefzeile($n429 > 0 ? 0 : 1, ak_t('TEST.F_ZAEHLWERK'),
            sprintf(ak_t('TEST.A_ZAEHLWERK'),
                (int) (isset($zw['anfragen']) ? $zw['anfragen'] : 0),
                (int) (isset($zw['fehler']) ? $zw['fehler'] : 0), $n429));
    }

    /* --- 6. MQTT --- */
    $m = ak_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_MQTT'), ak_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = ak_pruefzeile(1, ak_t('TEST.F_MQTT'),
            ak_e($m['broker']) . ':' . ak_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_MQTT'), ak_t('TEST.A_MQTT_AUS'));
    }

    /* --- 7. Steuerung --- */
    $zeilen[] = ak_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, ak_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? ak_t('TEST.A_STEUERUNG_EIN') : ak_t('TEST.A_STEUERUNG_AUS'));

    if (!empty($cfg['steuerung_ein'])) {
        $zeilen[] = ak_pruefzeile((int) $cfg['rueckfall_min'] > 0 ? 1 : -1, ak_t('TEST.F_RUECKFALL'),
            (int) $cfg['rueckfall_min'] > 0
                ? sprintf(ak_t('TEST.A_RUECKFALL_EIN'), (int) $cfg['rueckfall_min'], ak_e($cfg['rueckfall_modus']))
                : ak_t('TEST.A_RUECKFALL_AUS'));
    }

    /* --- 8. Bleibt die Oberflaeche mit sich selbst im Reinen? --- */
    list($stand, $text) = ak_kongruenz();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_KONGRUENZ'), $text);

    list($stand, $text) = ak_smactive_probe();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_SMACTIVE'), $text);

    list($stand, $text) = ak_vorgaben_abgleich();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_VORGABEN'), $text);

    list($stand, $text) = ak_themen_abgleich();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_THEMEN'), $text);

    list($stand, $text) = ak_muster_eindeutig();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_MUSTER'), $text);

    list($stand, $text) = ak_vorlage_probe();
    $zeilen[] = ak_pruefzeile($stand, ak_t('TEST.F_VORLAGE'), $text);

    return $zeilen;
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei ak_befehl_absetzen.
 */
function ak_test_aktion($aktion)
{
    $anlage = isset($_POST['test_anlage']) ? (string) $_POST['test_anlage'] : '1';
    if (!preg_match('/^[0-9]{1,2}$/', $anlage)) {
        return array(0, ak_t('TEST.M_ANLAGE_UNGUELTIG'));
    }

    switch ($aktion) {
        case 'abruf':
            return ak_befehl_absetzen(array('aktion' => 'abruf'), 8);

        case 'hauslast_test':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^-?[0-9]{1,5}$/', $watt)) {
                return array(0, ak_t('TEST.M_WATT_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'hauslast', 'anlage' => $anlage, 'watt' => (int) $watt));

        case 'modus_test':
            $wert = isset($_POST['test_modus']) ? (string) $_POST['test_modus'] : '';
            if (!preg_match('/^[a-z]{1,20}$/', $wert)) {
                return array(0, ak_t('TEST.M_MODUS_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'modus', 'anlage' => $anlage, 'wert' => $wert));

        case 'reserve_test':
            $prozent = isset($_POST['test_prozent']) ? (string) $_POST['test_prozent'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $prozent)) {
                return array(0, ak_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'reserve', 'anlage' => $anlage, 'prozent' => (int) $prozent));

        case 'notstrom_test':
            $prozent = isset($_POST['test_prozent']) ? (string) $_POST['test_prozent'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $prozent)) {
                return array(0, ak_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'notstromreserve', 'anlage' => $anlage,
                                            'prozent' => (int) $prozent));

        case 'einspeisung_aus':
        case 'einspeisung_ein':
            return ak_befehl_absetzen(array('aktion' => 'einspeisung', 'anlage' => $anlage,
                                            'wert' => $aktion === 'einspeisung_ein' ? 'ein' : 'aus'));

        case 'grenze_test':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^[0-9]{1,5}$/', $watt)) {
                return array(0, ak_t('TEST.M_WATT_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'einspeisegrenze', 'anlage' => $anlage,
                                            'watt' => (int) $watt));

        default:
            return array(0, ak_t('TEST.M_UNBEKANNT'));
    }
}

/**
 * Welche Aktion der Trockenlauf durchspielt - dieselbe Auswahl wie oben,
 * damit nichts scharf geschaltet werden kann, was vorher nicht trocken
 * durchgespielt werden konnte.
 */
function ak_trockenlauf_aktion()
{
    $aktion = isset($_POST['test_trocken']) ? (string) $_POST['test_trocken'] : 'hauslast';
    if (!in_array($aktion, array('hauslast', 'modus', 'reserve'), true)) {
        $aktion = 'hauslast';
    }
    $anlage = isset($_POST['test_anlage']) ? (int) $_POST['test_anlage'] : 1;
    if ($aktion === 'modus') {
        $wert = isset($_POST['test_modus']) ? (string) $_POST['test_modus'] : 'eigenverbrauch';
    } elseif ($aktion === 'reserve') {
        $wert = isset($_POST['test_prozent']) ? (int) $_POST['test_prozent'] : 10;
    } else {
        $wert = isset($_POST['test_watt']) ? (int) $_POST['test_watt'] : 0;
    }
    return array($aktion, ak_trockenlauf($aktion, $anlage, $wert));
}

/** Mini-SVG: Ladezustand ueber einen Tag (0 bis 24 h, 0 bis 100 %). */
function ak_soc_svg($punkte, $tag = '')
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    // Der Bezugspunkt ist der ANGEZEIGTE Tag, nicht immer heute: sonst faellt
    // jeder Punkt eines aelteren Tages aus dem Bild, und die Grafik bliebe
    // leer, ohne dass man den Grund saehe.
    $tag0 = ($tag !== '' && preg_match('/^[0-9]{8}$/', $tag))
        ? strtotime(substr($tag, 0, 4) . '-' . substr($tag, 4, 2) . '-' . substr($tag, 6, 2) . ' 00:00')
        : strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3)
              . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph)
              . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6)
              . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        $poly[] = round($x0 + $pw * $anteil, 1) . ','
                . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
    }
    if (count($poly) >= 2) {
        $erst = explode(',', $poly[0]);
        $letzt = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $erst[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' '
              . $letzt[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $letzt[0] . '" cy="' . $letzt[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . ak_e(ak_t('TEST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}

/**
 * Kleines Balkenbild der Tagesenergien - PV gegen Hausverbrauch.
 *
 * Bis 0.9.6 gab es die Tagessummen nur fuer heute und nur als Zahl. Ein
 * Verlauf ueber die letzten Wochen beantwortet die Frage, die man wirklich
 * hat: traegt die Anlage den Haushalt, und seit wann nicht mehr.
 */
function ak_energie_svg($tage, $anzahl = 30)
{
    $tage = array_slice($tage, -$anzahl, $anzahl, true);
    $w = 720; $h = 140; $x0 = 40; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 22;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    if (!$tage) {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . ak_e(ak_t('TEST.KEINE_MESSPUNKTE')) . '</text></svg>';
        return $svg;
    }
    $max = 0.0;
    foreach ($tage as $t) {
        $max = max($max, (float) ($t['pv'] ?? 0), (float) ($t['haus'] ?? 0));
    }
    if ($max <= 0) {
        $max = 1.0;
    }
    foreach (array(0, 0.5, 1) as $anteil) {
        $y = $y0 + $ph - $ph * $anteil;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3) . '" font-size="9" fill="#999"'
              . ' text-anchor="end">' . round($max * $anteil, 1) . '</text>';
    }
    $n = count($tage);
    $bw = $pw / max(1, $n);
    $i = 0;
    foreach ($tage as $datum => $t) {
        $x = $x0 + $i * $bw;
        foreach (array(array('pv', '#6dac20', 0.0), array('haus', '#546e7a', 0.45)) as $b) {
            $v = $t[$b[0]];
            if ($v === null) {
                continue;
            }
            $hh = $ph * min(1, (float) $v / $max);
            $svg .= '<rect x="' . round($x + $bw * $b[2] + 1, 1) . '" y="' . round($y0 + $ph - $hh, 1)
                  . '" width="' . round(max(1, $bw * 0.42), 1) . '" height="' . round($hh, 1)
                  . '" fill="' . $b[1] . '"><title>' . ak_e($datum . ' ' . $b[0] . ' ' . $v . ' kWh')
                  . '</title></rect>';
        }
        if ($i === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 6)
                  . '" font-size="9" fill="#999" text-anchor="middle">'
                  . ak_e(substr((string) $datum, 8, 2) . '.' . substr((string) $datum, 5, 2) . '.') . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}
