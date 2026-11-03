<?php
/**
 * Anker SOLIX - Bruecke vom Python-Dienst zur gemeinsamen Sprachausgabe (seit 0.9.35).
 * Aufruf: php ak_ansage.php <anlass> <pluginordner>. Anlaesse: ausfall, wieder, rueckfall. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$ak_a = isset($argv[1]) ? (string) $argv[1] : '';
$ak_o = isset($argv[2]) ? (string) $argv[2] : '';
if (!in_array($ak_a, array('ausfall', 'wieder', 'rueckfall'), true) || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ak_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$ak_h = getenv('LBHOMEDIR');
if (!$ak_h || !is_dir($ak_h . '/config/plugins')) {
    $ak_h = dirname(dirname(dirname(__DIR__)));
}
$ak_lib = $ak_h . '/webfrontend/html/plugins/' . $ak_o . '/ak_lib.php';
if (!is_file($ak_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $ak_h);
putenv('LBPPLUGINDIR=' . $ak_o);
require_once $ak_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) ak_ansage_anlass($ak_a)) . "\n";
exit(0);
