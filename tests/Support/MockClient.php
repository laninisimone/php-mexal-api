<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client PSR-18 pilotato dai test: consegna risposte preparate in coda e conserva le
 * richieste ricevute, così si può verificare URL, header e body senza toccare la rete.
 */
final class MockClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param array<string, string> $headers
     */
    public function push(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function pushJson(int $status, array $body = [], array $headers = []): self
    {
        return $this->push(
            $status,
            (string) json_encode($body, JSON_THROW_ON_ERROR),
            $headers + ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Coda una pagina di risultati con il relativo token "next".
     *
     * @param list<array<string, mixed>> $records
     */
    public function pushPage(array $records, ?string $next = null): self
    {
        $body = ['dati' => $records];

        if ($next !== null) {
            $body['next'] = $next;
        }

        return $this->pushJson(200, $body);
    }

    public function pushFailure(ClientExceptionInterface $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        Assert::assertNotNull($next, sprintf(
            'Il MockClient ha ricevuto una richiesta non prevista: %s %s',
            $request->getMethod(),
            (string) $request->getUri(),
        ));

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        Assert::assertNotEmpty($this->requests, 'Nessuna richiesta inviata.');

        return $this->requests[count($this->requests) - 1];
    }

    public function requestAt(int $index): RequestInterface
    {
        Assert::assertArrayHasKey($index, $this->requests, "Nessuna richiesta all'indice $index.");

        return $this->requests[$index];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /** @return list<string> */
    public function uris(): array
    {
        return array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }

    public function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}
