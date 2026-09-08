<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Tests\Support\TestCase;

final class ResourceClientTest extends TestCase
{
    public function testResourcePathIsPrefixedWithRisorse(): void
    {
        self::assertSame('risorse/clienti', $this->mexal()->resource('clienti')->path());
        self::assertSame('risorse/clienti', $this->mexal()->resource(MexalResource::CLIENTI)->path());
    }

    public function testListFollowsTheChainOfNextTokens(): void
    {
        $this->http
            ->pushPage([['codice' => '1'], ['codice' => '2']], next: 'tok-1')
            ->pushPage([['codice' => '3']], next: 'tok-2')
            ->pushPage([['codice' => '4']]);

        $clienti = $this->mexal()->resource(MexalResource::CLIENTI)->list();

        self::assertCount(4, $clienti);
        self::assertSame(['1', '2', '3', '4'], array_column($clienti, 'codice'));

        // Il token della pagina precedente viaggia in query string sugli endpoint REST.
        self::assertStringContainsString('next=tok-1', $this->http->uris()[1]);
        self::assertStringContainsString('next=tok-2', $this->http->uris()[2]);
    }

    /**
     * Le pagine non devono conservare le chiavi originali: gli indici della seconda
     * pagina sovrascriverebbero quelli della prima al momento della materializzazione.
     */
    public function testRecordsOfDifferentPagesDoNotOverwriteEachOther(): void
    {
        $this->http
            ->pushPage([['codice' => 'A'], ['codice' => 'B']], next: 'tok')
            ->pushPage([['codice' => 'C'], ['codice' => 'D']]);

        self::assertSame(['A', 'B', 'C', 'D'], array_column(
            $this->mexal()->resource('clienti')->list(),
            'codice',
        ));
    }

    public function testCursorDoesNotRequestPagesThatAreNeverReached(): void
    {
        $this->http
            ->pushPage([['codice' => '1'], ['codice' => '2']], next: 'tok-1')
            ->pushPage([['codice' => '3']]);

        $first = null;
        foreach ($this->mexal()->resource('articoli')->cursor() as $record) {
            $first = $record;
            break;
        }

        self::assertSame(['codice' => '1'], $first);
        // Solo la prima pagina è stata scaricata: la seconda non è mai stata richiesta.
        self::assertSame(1, $this->http->requestCount());
    }

    public function testPaginationStopsAtTheConfiguredPageLimit(): void
    {
        $this->http
            ->pushPage([['codice' => '1']], next: 'tok-1')
            ->pushPage([['codice' => '2']], next: 'tok-2')
            ->pushPage([['codice' => '3']], next: 'tok-3');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Limite di 2 pagine/');

        $this->mexal()->resource('clienti')->list(maxPages: 2);
    }

    public function testThePageLimitCanComeFromTheHttpOptions(): void
    {
        $this->http
            ->pushPage([['codice' => '1']], next: 'tok-1')
            ->pushPage([['codice' => '2']], next: 'tok-2');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Limite di 1 pagine/');

        $this->mexal(new HttpOptions(maxPages: 1))->resource('clienti')->list();
    }

    public function testAnEmptyNextTokenEndsThePagination(): void
    {
        // "next": "" significa fine, mentre "next": "0" è un token legittimo.
        $this->http->pushJson(200, ['dati' => [['codice' => '1']], 'next' => '']);

        self::assertCount(1, $this->mexal()->resource('clienti')->list());
        self::assertSame(1, $this->http->requestCount());
    }

    public function testANextTokenOfZeroIsNotMistakenForTheEnd(): void
    {
        $this->http
            ->pushJson(200, ['dati' => [['codice' => '1']], 'next' => '0'])
            ->pushPage([['codice' => '2']]);

        self::assertCount(2, $this->mexal()->resource('clienti')->list());
    }

    public function testGetUrlEncodesTheCode(): void
    {
        $this->http->pushJson(200, ['codice' => 'ART 001']);

        $this->mexal()->resource(MexalResource::ARTICOLI)->get('ART 001');

        self::assertSame(
            'https://mexal.test:9004/webapi/risorse/articoli/ART%20001',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    /**
     * I codici con slash non possono essere url-encodati: il gestionale li vuole in
     * esadecimale, con la codifica dichiarata in query string.
     */
    public function testCodesContainingASlashAreSentAsHex(): void
    {
        $this->http->pushJson(200, ['codice' => 'ARTI/S']);

        $this->mexal()->resource(MexalResource::ARTICOLI)->get('ARTI/S');

        self::assertSame(
            'https://mexal.test:9004/webapi/risorse/articoli/'.bin2hex('ARTI/S').'?encoding=hex',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testSearchSendsFiltersInTheBodyAndPaginatesInTheQueryString(): void
    {
        $this->http
            ->pushPage([['codice' => '1']], next: 'tok')
            ->pushPage([['codice' => '2']]);

        $filtri = ['filtri' => [['campo' => 'tipologia', 'condizione' => '=', 'valore' => 'A']]];
        $risultati = $this->mexal()->resource(MexalResource::ARTICOLI)->search($filtri, ['fields' => 'codice']);

        self::assertCount(2, $risultati);

        $first = $this->http->requestAt(0);
        self::assertSame('POST', $first->getMethod());
        self::assertStringEndsWith('/risorse/articoli/ricerca?fields=codice', (string) $first->getUri());
        self::assertSame($filtri, $this->decodeBody((string) $first->getBody()));
        self::assertStringContainsString('next=tok', (string) $this->http->requestAt(1)->getUri());
    }

    public function testCreateReadsBackTheResourceFromTheLocationHeader(): void
    {
        $this->http
            ->push(201, '', ['Location' => '/webapi/risorse/clienti/501.00001'])
            ->pushJson(200, ['codice' => '501.00001', 'ragione_sociale' => 'ACME SPA']);

        $cliente = $this->mexal()->resource(MexalResource::CLIENTI)->create(['ragione_sociale' => 'ACME SPA']);

        self::assertSame('ACME SPA', $cliente['ragione_sociale']);
        self::assertStringEndsWith('/risorse/clienti/501.00001', (string) $this->http->requestAt(1)->getUri());
    }

    public function testCreateAndGetIdAvoidsTheSecondCall(): void
    {
        $this->http->push(201, '', ['Location' => '/webapi/risorse/clienti/501.00001']);

        $codice = $this->mexal()->resource(MexalResource::CLIENTI)->createAndGetId(['ragione_sociale' => 'ACME']);

        self::assertSame('501.00001', $codice);
        self::assertSame(1, $this->http->requestCount());
    }

    public function testCreateAndGetIdDecodesAHexLocation(): void
    {
        $this->http->push(201, '', [
            'Location' => '/webapi/risorse/articoli/'.bin2hex('ARTI/S').'?encoding=hex',
        ]);

        self::assertSame('ARTI/S', $this->mexal()->resource(MexalResource::ARTICOLI)->createAndGetId([]));
    }

    public function testAMissingLocationHeaderIsAProtocolError(): void
    {
        $this->http->push(201);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Location/');

        $this->mexal()->resource(MexalResource::CLIENTI)->create(['ragione_sociale' => 'ACME']);
    }

    public function testUpdateExpectsA204(): void
    {
        $this->http->push(204);

        self::assertTrue($this->mexal()->resource(MexalResource::CLIENTI)->update('501.00001', [
            'ragione_sociale' => 'ACME SRL',
            'data_ult_mod' => '20250903 101500',
        ]));

        $request = $this->http->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('20250903 101500', $this->decodeBody((string) $request->getBody())['data_ult_mod']);
    }

    public function testAnUnexpectedStatusOnUpdateRaisesARequestException(): void
    {
        $this->http->pushJson(200, []);

        $this->expectException(RequestException::class);

        $this->mexal()->resource(MexalResource::CLIENTI)->update('501.00001', []);
    }

    public function testDeleteExpectsA204(): void
    {
        $this->http->push(204);

        self::assertTrue($this->mexal()->resource(MexalResource::CLIENTI)->delete('501.00001'));
        self::assertSame('DELETE', $this->http->lastRequest()->getMethod());
    }

    public function testSubBuildsTheNestedPathAndPropagatesTheEncoding(): void
    {
        $this->http->pushPage([['progressivo' => 1]]);

        $articoli = $this->mexal()->resource(MexalResource::ARTICOLI);
        $progressivi = $articoli->sub('ARTI/S', 'progressivi');

        self::assertSame('risorse/articoli/'.bin2hex('ARTI/S').'/progressivi', $progressivi->path());

        $progressivi->list();

        // L'encoding riguarda il codice in mezzo al path, quindi va propagato anche qui.
        self::assertStringContainsString('encoding=hex', (string) $this->http->lastRequest()->getUri());
    }

    public function testAllegatoReturnsTheRawBodyAndItsContentType(): void
    {
        $this->http->push(200, 'binario', ['Content-Type' => 'image/jpeg']);

        $contentType = null;
        $body = $this->mexal()->resource(MexalResource::ARTICOLI)->allegato('FUELEX97', 'immagine-catalogo', $contentType);

        self::assertSame('binario', $body);
        self::assertSame('image/jpeg', $contentType);
        self::assertSame('*/*', $this->http->lastRequest()->getHeaderLine('Accept'));
    }

    public function testInfoAsksForTheEndpointMetadata(): void
    {
        $this->http->pushPage([['campo' => 'codice']]);

        $this->mexal()->resource(MexalResource::CLIENTI)->info();

        self::assertStringContainsString('info=true', (string) $this->http->lastRequest()->getUri());
    }
}
