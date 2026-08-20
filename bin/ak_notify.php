<?php
/**
 * Anker SOLIX - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php ak_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Warum es dieses Zwischenstueck gibt: der Abrufdienst ist in Python
 * geschrieben, und fuer Benachrichtigungen gibt es dort keine
 * LoxBerry-Schnittstelle. notify_ext() ist PHP. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.2.0/bin/apc_notify.php - nicht neu geschrieben,
 * weil die Fassung dort geprueft ist.
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil ein aus dem
 * Cron oder aus postinstall.sh gestarteter Dienst die LoxBerry-Umgebungs-
 * variablen nicht sicher mitbringt. Ohne ihn fiele dieses Skript auf den
 * fest eingetragenen Namen zurueck - wer das Plugin in einen anderen Ordner
 * installiert hat, faende seine Warnung dann unter einem Paketnamen, den es
 * nicht gibt, und damit gar nicht.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Dieser Block steht VOR seinem Aufruf. PHP zieht Funktionen, die in einem
 * if-Block stehen, nicht vor: sie entstehen erst, wenn die Zeile ausgefuehrt
 * wird. Stuende er am Dateiende, endete der Aufruf weiter unten mit
 * "Call to undefined function" und Rueckgabewert 255, sobald LBHOMEDIR leer
 * ist - und genau davon geht ein Dienst aus, der ueber su gestartet wird.
 * Der Fehler steckte bis 1.1.6 im Vorbild.
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

$home = getenv('LBHOMEDIR');
if (!$home) {
    $home = lb_wurzel_ermitteln();
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!$home || !file_exists($sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $sdk . "\n");
    exit(1);
}
require_once $home . '/libs/phplib/loxberry_system.php';
require_once $sdk;

$schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1]) ? (int) $argv[1] : 4;
$text    = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = (string) getenv('LBPPLUGINDIR');
}
if (!$paket) {
    $paket = 'ankersolix';
}

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $paket,
    'NAME'     => 'Anker SOLIX',
    'MESSAGE'  => $text,
    'SEVERITY' => $schwere,
));

exit(0);
