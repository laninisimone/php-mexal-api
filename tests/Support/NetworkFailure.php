<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Errore di rete PSR-18: è la condizione che la politica di retry deve riconoscere come
 * transitoria e ritentare.
 */
final class NetworkFailure extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message = 'Connection refused')
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
