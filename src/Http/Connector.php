<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Http;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Exceptions\TransportException;

/**
 * Strato HTTP puro: compone URL e header, invia la richiesta, applica la politica di
 * retry e restituisce una Response. Non conosce risorse, paginazione né semantica Mexal —
 * quella vive in ResourceClient e MexalClient.
 *
 * È immutabile: withInstance() e withCompression() restituiscono una nuova istanza, così
 * cambiare azienda o anno in un punto del codice non altera il connector condiviso altrove.
 */
final class Connector
{
    /** @var non-empty-string Nessun encoding: solo caratteri di controllo e traversal sono vietati. */
    private const UNSAFE_PATH = '/[\x00-\x1F\x7F]|(?:^|\/)\.\.(?:\/|$)/';

    public function __construct(
        private readonly Connection $connection,
        private readonly Instance $instance,
        private readonly HttpOptions $options,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly bool $compress = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function instance(): Instance
    {
        return $this->instance;
    }

    public function options(): HttpOptions
    {
        return $this->options;
    }

    /** Nuovo connector sulle stesse credenziali ma su altre coordinate gestionali. */
    public function withInstance(Instance $instance): self
    {
        return new self(
            $this->connection,
            $instance,
            $this->options,
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->compress,
            $this->logger,
        );
    }

    /** Nuovo connector con Accept-Encoding: gzip attivo o disattivo. */
    public function withCompression(bool $compress = true): self
    {
        return new self(
            $this->connection,
            $this->instance,
            $this->options,
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $compress,
            $this->logger,
        );
    }

    /**
     * @param string|list<string> $resource Path relativo a /webapi/, come stringa o come segmenti.
     * @param array<string, mixed> $params Query string.
     */
    public function get(string|array $resource, array $params = [], string $accept = 'application/json'): Response
    {
        return $this->send('GET', $this->buildUri($resource, $params), null, $accept, idempotent: true);
    }

    /**
     * La POST crea entità sul gestionale e non è quindi ritentabile per default. Va marcata
     * idempotente solo per gli endpoint di sola lettura, come /ricerca.
     *
     * L'Accept è configurabile perché il canale servizi risponde anche con PDF (le stampe) o
     * con un multipart (gli allegati Docuvision): chiedere solo JSON escluderebbe quei servizi.
     *
     * @param string|list<string> $resource
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     */
    public function post(
        string|array $resource,
        array $body = [],
        array $params = [],
        bool $idempotent = false,
        string $accept = 'application/json',
    ): Response {
        return $this->send('POST', $this->buildUri($resource, $params), $body, $accept, $idempotent);
    }

    /**
     * @param string|list<string> $resource
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     */
    public function put(string|array $resource, array $body = [], array $params = []): Response
    {
        // PUT è idempotente per definizione HTTP: ripeterla lascia la risorsa nello stesso stato.
        return $this->send('PUT', $this->buildUri($resource, $params), $body, 'application/json', idempotent: true);
    }

    /**
     * @param string|list<string> $resource
     * @param array<string, mixed> $params
     */
    public function delete(string|array $resource, array $params = []): Response
    {
        // I parametri vanno in query string: una DELETE con body non è supportata in modo
        // uniforme dai client HTTP e il gestionale non la prevede.
        return $this->send('DELETE', $this->buildUri($resource, $params), null, 'application/json', idempotent: true);
    }

    /**
     * Verifica non bloccante della raggiungibilità: nessuna eccezione, solo true/false.
     * Utile in health check e comandi diagnostici.
     */
    public function isConnected(): bool
    {
        try {
            return $this->get(['risorse', 'dati-generali', 'installazione'])->successful();
        } catch (TransportException|ProtocolException) {
            return false;
        }
    }

    /**
     * URL completo di una risorsa, utile per comporre chiamate fuori dal CRUD standard.
     *
     * @param string|list<string> $resource
     * @param array<string, mixed> $params
     */
    public function buildUri(string|array $resource, array $params = []): string
    {
        $path = is_array($resource) ? implode('/', $resource) : $resource;
        $path = ltrim($path, '/');

        // I codici risorsa arrivano già codificati da ResourceClient; qui si intercetta il
        // path costruito a mano. Un ".." risalirebbe fuori da /webapi/ verso altri endpoint
        // del server, un CR/LF spezzerebbe la request line.
        if (preg_match(self::UNSAFE_PATH, $path) === 1) {
            throw new ConfigurationException("Path risorsa non valido: '$path'.");
        }

        $uri = $this->connection->baseUri.$path;

        if ($params !== []) {
            // RFC3986: lo spazio diventa %20 e non '+', che in un path-like query value
            // alcuni server interpretano letteralmente.
            $uri .= '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $uri, ?array $body, string $accept, bool $idempotent): Response
    {
        // I retry hanno senso solo dove ripetere non ha effetti collaterali. Una POST che
        // crea un documento non viene mai ripetuta: un errore *dopo* la scrittura
        // genererebbe un duplicato in contabilità, che è peggio dell'errore stesso.
        $attempts = $idempotent ? $this->options->retries + 1 : 1;
        $payload = $body === null ? null : $this->encodeBody($body);

        for ($attempt = 1; ; $attempt++) {
            $startedAt = microtime(true);

            try {
                // La richiesta si ricostruisce a ogni tentativo: lo stream del body è già
                // stato consumato dal tentativo precedente e non sempre è riavvolgibile.
                $psr = $this->client->sendRequest($this->buildRequest($method, $uri, $payload, $accept));
                $response = new Response($psr, $method, $uri);
            } catch (NetworkExceptionInterface $e) {
                // Nessuna risposta: DNS, TCP, TLS, timeout. Transitorio per definizione.
                if ($attempt < $attempts) {
                    $this->waitBeforeRetry($attempt, $method, $uri, $e->getMessage());

                    continue;
                }

                throw TransportException::from($e, $method, $uri);
            } catch (ClientExceptionInterface $e) {
                // Richiesta malformata o errore interno del client: ritentare non cambia esito.
                throw TransportException::from($e, $method, $uri);
            }

            $this->logCall($method, $uri, $response->status(), $startedAt, $attempt);

            if ($attempt < $attempts && $this->isTransientStatus($response->status())) {
                $this->waitBeforeRetry($attempt, $method, $uri, 'HTTP '.$response->status());

                continue;
            }

            return $response;
        }
    }

    private function buildRequest(string $method, string $uri, ?string $payload, string $accept): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri);

        foreach ($this->headers($accept) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($payload !== null) {
            $request = $request->withBody($this->streamFactory->createStream($payload));
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $accept): array
    {
        $headers = [
            'Authorization' => $this->connection->authorizationHeader(),
            'Coordinate-Gestionale' => $this->instance->header(),
            'Accept' => $accept,
            // Inviato anche senza body, come fa il client di riferimento del manuale:
            // alcune installazioni lo usano per selezionare il parser della richiesta.
            'Content-Type' => 'application/json',
            'User-Agent' => $this->options->userAgent,
        ];

        if ($this->compress) {
            $headers['Accept-Encoding'] = 'gzip';
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeBody(array $body): string
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new ProtocolException('Corpo della richiesta non serializzabile in JSON: '.$e->getMessage(), 0, $e);
        }

        // Un array PHP vuoto diventa "[]", ma il gestionale attende sempre un oggetto:
        // "{}" è la forma corretta di un corpo senza campi.
        return $json === '[]' ? '{}' : $json;
    }

    /** 5xx, 408 e 429 sono le uniche condizioni in cui ritentare ha senso. */
    private function isTransientStatus(int $status): bool
    {
        return $status >= 500 || $status === 408 || $status === 429;
    }

    private function waitBeforeRetry(int $attempt, string $method, string $uri, string $reason): void
    {
        // Attesa crescente: il pool di servizi WebAPI è piccolo (5 per utente), quindi
        // insistere a raffica peggiora la congestione invece di risolverla.
        $delayMs = $this->options->retryDelay * $attempt;

        $this->logger?->warning('Mexal: tentativo {attempt} fallito, ritento fra {delay}ms', [
            'attempt' => $attempt,
            'delay' => $delayMs,
            'method' => $method,
            'uri' => $uri,
            'reason' => $reason,
        ]);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function logCall(string $method, string $uri, int $status, float $startedAt, int $attempt): void
    {
        // Volutamente senza header né body: l'Authorization contiene le credenziali e il
        // body contiene dati dell'anagrafica. Metodo, URI, status e durata bastano a
        // diagnosticare, e sono sicuri da conservare in un log centralizzato.
        $this->logger?->debug('Mexal {method} {uri} -> {status}', [
            'method' => $method,
            'uri' => $uri,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'attempt' => $attempt,
        ]);
    }
}
