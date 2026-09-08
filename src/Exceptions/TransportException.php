<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Il server non ha risposto affatto: DNS, TCP, handshake TLS, timeout di connessione o di
 * lettura. Incapsula l'eccezione PSR-18 del client sottostante, così il codice chiamante
 * non deve conoscere l'implementazione HTTP in uso (Guzzle, Symfony, cURL...).
 *
 * Va distinta da RequestException: qui non esiste una risposta, quindi non c'è nessuno
 * status né oggetto "error" del gestionale da interrogare.
 */
final class TransportException extends RuntimeException implements MexalException
{
    public static function from(ClientExceptionInterface $previous, string $method, string $uri): self
    {
        return new self(
            sprintf('Chiamata %s %s fallita a livello di trasporto: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
