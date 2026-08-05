<?php
/**
 * Anker SOLIX - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Anker-Konto, ob die
 * Einrichtung traegt. Was sich nur mit Geraet pruefen liesse, wird als solches
 * benannt statt geraten.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 = ja, 0 = nein, -1 = Hinweis. */
function ak_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function ak_pruefungen()
{
    $p = ak_paths();
    $cfg = ak_config();
    $z = ak_zugang();
    $zeilen = array();

    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = ak_pruefzeile(is_file($venv) ? 1 : 0, ak_t('TEST.F_VENV'),
        is_file($venv) ? $venv : ak_t('TEST.A_VENV_FEHLT'));

    $fassung = ak_bibliothek_fassung();
    $zeilen[] = ak_pruefzeile($fassung !== '' ? 1 : 0, ak_t('TEST.F_LIB'),
        $fassung !== '' ? 'anker-solix-api ' . $fassung : ak_t('TEST.A_LIB_FEHLT'));

    $pid = ak_dienst_pid();
    $zeilen[] = ak_pruefzeile($pid > 0 ? 1 : 0, ak_t('TEST.F_DIENST'),
        $pid > 0 ? ak_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (ak_dienst_soll() ? ak_t('TEST.A_DIENST_SOLL_TOT') : ak_t('TEST.A_DIENST_GESTOPPT')));

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

    $m = ak_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_MQTT'), ak_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = ak_pruefzeile(1, ak_t('TEST.F_MQTT'),
            ak_e($m['broker']) . ':' . ak_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = ak_pruefzeile(0, ak_t('TEST.F_MQTT'), ak_t('TEST.A_MQTT_AUS'));
    }

    $zeilen[] = ak_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, ak_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? ak_t('TEST.A_STEUERUNG_EIN') : ak_t('TEST.A_STEUERUNG_AUS'));

    return $zeilen;
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei ak_befehl_absetzen.
 */
function ak_test_aktion($aktion)
{
    switch ($aktion) {
        case 'abruf':
            return ak_befehl_absetzen(array('aktion' => 'abruf'), 8);

        case 'hauslast_test':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^-?[0-9]{1,5}$/', $watt)) {
                return array(0, ak_t('TEST.M_WATT_UNGUELTIG'));
            }
            $anlage = isset($_POST['test_anlage']) ? (string) $_POST['test_anlage'] : '1';
            if (!preg_match('/^[0-9]{1,2}$/', $anlage)) {
                return array(0, ak_t('TEST.M_ANLAGE_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'hauslast', 'anlage' => $anlage, 'watt' => (int) $watt));

        case 'modus_test':
            $wert = isset($_POST['test_modus']) ? (string) $_POST['test_modus'] : '';
            if (!preg_match('/^[a-z]{1,20}$/', $wert)) {
                return array(0, ak_t('TEST.M_MODUS_UNGUELTIG'));
            }
            $anlage = isset($_POST['test_anlage']) ? (string) $_POST['test_anlage'] : '1';
            if (!preg_match('/^[0-9]{1,2}$/', $anlage)) {
                return array(0, ak_t('TEST.M_ANLAGE_UNGUELTIG'));
            }
            return ak_befehl_absetzen(array('aktion' => 'modus', 'anlage' => $anlage, 'wert' => $wert));

        default:
            return array(0, ak_t('TEST.M_UNBEKANNT'));
    }
}

/** Mini-SVG: Ladezustand ueber den heutigen Tag (0 bis 24 h, 0 bis 100 %). */
function ak_soc_svg($punkte)
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    $tag0 = strtotime('today 00:00');
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
