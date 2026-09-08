<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Exceptions;

use Throwable;

/**
 * Contratto comune a tutte le eccezioni del pacchetto.
 *
 * Le implementazioni hanno gerarchie native diverse (InvalidArgumentException per la
 * configurazione, RuntimeException per gli errori a runtime), quindi un'interfaccia è
 * l'unico modo per catturarle tutte insieme:
 *
 *     catch (MexalException $e) { ... }
 *
 * Nessuna eccezione del pacchetto espone credenziali: i messaggi riportano solo metodo,
 * URI e diagnostica del gestionale.
 */
interface MexalException extends Throwable
{
}
