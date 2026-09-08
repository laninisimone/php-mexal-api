<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Exceptions;

use InvalidArgumentException;

/**
 * Configurazione assente, incoerente o non applicabile: connessione inesistente, URL
 * mancante, credenziali che viaggerebbero in chiaro, client HTTP non configurabile.
 *
 * È sempre un errore di programmazione o di deploy, mai una condizione transitoria:
 * viene sollevata il prima possibile, alla costruzione degli oggetti di configurazione,
 * invece che alla prima chiamata di rete.
 */
final class ConfigurationException extends InvalidArgumentException implements MexalException
{
}
