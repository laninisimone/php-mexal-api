<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Nyholm\Psr7\Request;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;
use Simonelanini\PhpMexalApi\Exceptions\TransportException;
use Simonelanini\PhpMexalApi\Tests\Support\NetworkFailure;
use Simonelanini\PhpMexalApi\Tests\Support\TestCase;

final class ConnectorTest extends TestCase
{
    public function testSendsAuthorizationAndCoordinateHeaders(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->connector()->get('risorse/clienti');

        $request = $this->http->lastRequest();

        self::assertSame(
            'Passepartout '.base64_encode('api:secret'),
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('Azienda=DEM Anno=2025 Magazzino=1', $request->getHeaderLine('Coordinate-Gestionale'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringContainsString('php-mexal-api', $request->getHeaderLine('User-Agent'));
        self::assertSame('', $request->getHeaderLine('Accept-Encoding'));
    }

    public function testCompressionAddsTheAcceptEncodingHeader(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->connector(compress: true)->get('risorse/clienti');

        self::assertSame('gzip', $this->http->lastRequest()->getHeaderLine('Accept-Encoding'));
    }

    public function testBuildsTheUriFromSegmentsAndQueryString(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->connector()->get(['risorse', 'dati-generali', 'installazione'], ['fields' => 'codice,descrizione']);

        self::assertSame(
            'https://mexal.test:9004/webapi/risorse/dati-generali/installazione?fields=codice%2Cdescrizione',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testQueryParametersOfADeleteTravelInTheQueryStringNotInTheBody(): void
    {
        $this->http->push(204);

        $this->connector()->delete('risorse/articoli/415254492f53', ['encoding' => 'hex']);

        $request = $this->http->lastRequest();

        self::assertSame('DELETE', $request->getMethod());
        self::assertStringContainsString('?encoding=hex', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
    }

    public function testAnEmptyBodyIsEncodedAsAJsonObjectNotAsAnArray(): void
    {
        $this->http->pushJson(200, []);

        $this->connector()->post('servizi', []);

        self::assertSame('{}', (string) $this->http->lastRequest()->getBody());
    }

    public function testPathTraversalInTheResourceIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->connector()->get('risorse/../../admin');
    }

    public function testControlCharactersInTheResourceAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->connector()->get("risorse/clienti\r\nX-Injected: 1");
    }

    public function testIdempotentRequestsAreRetriedOnNetworkFailure(): void
    {
        $request = new Request('GET', 'https://mexal.test');

        $this->http
            ->pushFailure(new NetworkFailure($request))
            ->pushFailure(new NetworkFailure($request))
            ->pushJson(200, ['dati' => [['codice' => '1']]]);

        $response = $this->connector(new HttpOptions(retries: 2, retryDelay: 0))->get('risorse/clienti');

        self::assertSame(200, $response->status());
        self::assertSame(3, $this->http->requestCount());
    }

    public function testRetriesAreExhaustedAndTurnIntoATransportException(): void
    {
        $request = new Request('GET', 'https://mexal.test');

        $this->http
            ->pushFailure(new NetworkFailure($request))
            ->pushFailure(new NetworkFailure($request));

        $this->expectException(TransportException::class);

        try {
            $this->connector(new HttpOptions(retries: 1, retryDelay: 0))->get('risorse/clienti');
        } finally {
            self::assertSame(2, $this->http->requestCount());
        }
    }

    public function testTransientStatusesAreRetried(): void
    {
        $this->http
            ->push(503)
            ->pushJson(200, ['dati' => []]);

        $response = $this->connector(new HttpOptions(retries: 1, retryDelay: 0))->get('risorse/clienti');

        self::assertSame(200, $response->status());
        self::assertSame(2, $this->http->requestCount());
    }

    public function testClientErrorsAreNotRetriedBecauseRetryingChangesNothing(): void
    {
        $this->http->pushJson(400, ['error' => ['response-detail' => '6001 - errore']]);

        $response = $this->connector(new HttpOptions(retries: 3, retryDelay: 0))->get('risorse/clienti');

        self::assertSame(400, $response->status());
        self::assertSame(1, $this->http->requestCount());
    }

    /**
     * Il punto centrale della politica di retry: una POST che crea entità non va MAI
     * ripetuta, o un errore dopo la scrittura genererebbe documenti duplicati.
     */
    public function testNonIdempotentPostsAreNeverRetried(): void
    {
        $this->http->pushFailure(new NetworkFailure(new Request('POST', 'https://mexal.test')));

        $this->expectException(TransportException::class);

        try {
            $this->connector(new HttpOptions(retries: 5, retryDelay: 0))->post('risorse/clienti', ['codice' => '1']);
        } finally {
            self::assertSame(1, $this->http->requestCount());
        }
    }

    public function testAPostMarkedIdempotentIsRetried(): void
    {
        $this->http
            ->pushFailure(new NetworkFailure(new Request('POST', 'https://mexal.test')))
            ->pushJson(200, ['dati' => []]);

        $this->connector(new HttpOptions(retries: 1, retryDelay: 0))
            ->post('risorse/clienti/ricerca', ['filtri' => []], idempotent: true);

        self::assertSame(2, $this->http->requestCount());
    }

    public function testIsConnectedSwallowsTransportErrors(): void
    {
        $this->http->pushFailure(new NetworkFailure(new Request('GET', 'https://mexal.test')));

        self::assertFalse($this->connector()->isConnected());
    }

    public function testWithInstanceLeavesTheOriginalConnectorUntouched(): void
    {
        $connector = $this->connector();
        $other = $connector->withInstance($connector->instance()->with(anno: 2024));

        self::assertSame(2025, $connector->instance()->anno);
        self::assertSame(2024, $other->instance()->anno);
    }
}
