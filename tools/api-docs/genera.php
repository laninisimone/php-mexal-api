<?php

declare(strict_types=1);

/*
 * Rigenera docs/api a partire dall'help in linea del gestionale.
 *
 *   php tools/api-docs/genera.php scarica      interroga il gestionale e aggiorna docs/api/sorgenti
 *   php tools/api-docs/genera.php costruisci   rigenera docs/api/openapi e docs/api/ai dalle sorgenti
 *   php tools/api-docs/genera.php              entrambe
 *
 * "scarica" legge la connessione dalle stesse variabili d'ambiente di Mexal::fromEnv()
 * (MEXAL_URL, MEXAL_API_USER, MEXAL_API_PASSWORD, MEXAL_AZIENDA, MEXAL_ANNO, ...). Fa solo
 * GET, in sequenza: help?extended=true e ?info=true su ogni collezione.
 *
 * Le coordinate contano: le risorse dei moduli di produzione rispondono a ?info=true solo
 * su aziende di livello produzione. Per quelle il generatore ripiega sul manuale.
 */

use MexalApiDocs\Ai;
use MexalApiDocs\Modello;
use MexalApiDocs\OpenApi;
use MexalApiDocs\Scaricatore;
use Simonelanini\PhpMexalApi\Mexal;

$radice = dirname(__DIR__, 2);

require $radice.'/vendor/autoload.php';
require __DIR__.'/lib/Yaml.php';
require __DIR__.'/lib/Modello.php';
require __DIR__.'/lib/Scaricatore.php';
require __DIR__.'/lib/OpenApi.php';
require __DIR__.'/lib/Ai.php';

$docs = $radice.'/docs/api';
$comando = $argv[1] ?? 'tutto';

if (! in_array($comando, ['scarica', 'costruisci', 'tutto'], true)) {
    fwrite(STDERR, "Uso: php tools/api-docs/genera.php [scarica|costruisci]\n");
    exit(2);
}

$log = static function (string $riga): void {
    fwrite(STDOUT, $riga."\n");
};

if ($comando !== 'costruisci') {
    $log('Interrogo il gestionale...');
    (new Scaricatore(Mexal::fromEnv()->connector(), $docs.'/sorgenti'))->esegui($log);
}

if ($comando !== 'scarica') {
    $modello = Modello::carica($docs.'/sorgenti');

    foreach ((new OpenApi($modello))->file() as $nome => $contenuto) {
        file_put_contents($docs.'/openapi/'.$nome, $contenuto);
        $log("openapi/$nome");
    }

    foreach ((new Ai($modello))->file() as $nome => $contenuto) {
        file_put_contents($docs.'/ai/'.$nome, $contenuto);
        $log("ai/$nome");
    }
}
