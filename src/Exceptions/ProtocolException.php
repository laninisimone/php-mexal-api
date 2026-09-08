<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Exceptions;

use RuntimeException;

/**
 * Errore di protocollo rilevato dal client prima o al posto di una risposta valida del
 * gestionale: header Location assente dopo una create, limite di pagine superato in
 * paginazione, corpo JSON non decodificabile dove il manuale ne prevede uno.
 */
final class ProtocolException extends RuntimeException implements MexalException
{
}
