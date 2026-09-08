<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Http;

use Psr\Http\Client\ClientInterface;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;

/**
 * Costruisce il client HTTP di una specifica connessione.
 *
 * Serve un'astrazione perché PSR-18 descrive solo "manda una richiesta, ricevi una
 * risposta": timeout e verifica del certificato non fanno parte dell'interfaccia e
 * dipendono dall'implementazione. Sono però proprietà della connessione — il cloud e
 * un server on-premise hanno posture TLS diverse — quindi il client va costruito
 * conoscendo la connessione, non una volta per l'intera applicazione.
 *
 * Implementa questa interfaccia per usare un client tuo (proxy aziendale, mTLS,
 * strumentazione, cache di sviluppo).
 */
interface ClientFactory
{
    public function createFor(Connection $connection, HttpOptions $options): ClientInterface;
}
