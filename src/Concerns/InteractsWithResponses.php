<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Concerns;

use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Http\Response;

/**
 * Comportamenti condivisi da chi legge le risposte del gestionale: controllo dello status e
 * lettura del token di paginazione. Stanno qui invece che duplicati in ResourceClient e
 * MexalClient, dove il rischio è che una correzione ne raggiunga solo uno.
 */
trait InteractsWithResponses
{
    /**
     * Il gestionale usa status diversi a seconda dell'operazione (200 in lettura, 201 in
     * creazione, 204 su modifica ed eliminazione): il controllo è quindi su una lista di
     * status attesi, non sul generico 2xx. Un 200 al posto di un 201 significa che è
     * successo qualcosa di diverso da quel che si era chiesto.
     */
    protected function throwUnlessStatus(Response $response, int ...$expected): Response
    {
        if (! in_array($response->status(), $expected, true)) {
            throw RequestException::from($response);
        }

        return $response;
    }

    /**
     * Il manuale documenta "next" come stringa opaca, presente solo finché restano record da
     * leggere. Alcune risposte lo annidano dentro un array: entrambe le forme vengono
     * ricondotte al token, e una stringa vuota vale come fine della paginazione.
     *
     * Il token va usato subito: è legato a un semaforo di sessione e scade quando l'archivio
     * è stato iterato per intero.
     */
    protected function nextToken(mixed $next): ?string
    {
        if (is_array($next)) {
            $next = $next[0] ?? null;
        }

        if (! is_string($next) && ! is_int($next)) {
            return null;
        }

        // Un token può benissimo valere "0" o "9;3": si esclude solo la stringa vuota,
        // non tutto ciò che PHP considera falsy.
        return (string) $next === '' ? null : (string) $next;
    }
}
