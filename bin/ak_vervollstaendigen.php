<?php
/**
 * Anker SOLIX - Konfiguration vervollstaendigen, Kommandozeile (seit 0.9.41).
 *
 * Aufgerufen vom Dienst beim Start (bin/ankersolix.py main(), vor dem ersten
 * Lesen der Konfiguration) und von postupgrade.sh. Geschrieben wird
 * ausschliesslich ueber ak_config_vervollstaendigen() in ak_lib.php - der
 * Dienst schreibt die Konfiguration nicht selbst (ein Schreiber fuer ein
 * Format; Bauart BLE-Scanner 1.3.40). Wurzel und Bibliothek wie in
 * bin/ak_ansage.php.
 *
 * Aufruf: php ak_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|nicht_eingerichtet|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 ausser bei fehler und den Kennungen (1). Die Zeile nennt nur
 * Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$ak_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ak_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$ak_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$ak_h = getenv('LBHOMEDIR');
if (!$ak_wurzel($ak_h)) {
    $ak_h = dirname(dirname(dirname(__DIR__)));
    if (!$ak_wurzel($ak_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$ak_lib = $ak_h . '/webfrontend/html/plugins/' . $ak_o . '/ak_lib.php';
if (!is_file($ak_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $ak_h);
putenv('LBPPLUGINDIR=' . $ak_o);
require_once $ak_lib;
$ak_e = ak_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $ak_e['stand'] . ';SCHLUESSEL=' . implode(',', $ak_e['schluessel']) . "\n";
exit($ak_e['stand'] === 'fehler' ? 1 : 0);
