<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Mexal;
use Simonelanini\PhpMexalApi\Tests\Support\TestCase;

/**
 * Ripercorre gli esempi pubblicati nel README e nella guida rapida.
 *
 * Serve a impedire che documentazione e codice divergano: una firma che cambia senza che la
 * documentazione la segua fa fallire questi test, non gli utenti.
 */
final class DocumentedUsageTest extends TestCase
{
    public function testTheReadmeConstructorsCompileAndProduceTheDocumentedUris(): void
    {
        $locale = Mexal::make(
            Connection::local(
                url: 'https://192.168.1.10',
                apiUser: 'utente_api',
                apiPassword: 'password_api',
                port: 9004,
            ),
            new Instance(azienda: 'DEM', anno: 2025),
        );

        $cloud = Mexal::make(
            Connection::live(domain: 'dominio_xyz', apiUser: 'utente_api', apiPassword: 'password_api'),
            new Instance(azienda: 'DEM'),
        );

        self::assertSame('https://192.168.1.10:9004/webapi/', $locale->config()->connection()->baseUri);
        self::assertSame('https://services.passepartout.cloud/webapi/', $cloud->config()->connection()->baseUri);
    }

    public function testTheQuickstartReadFlow(): void
    {
        $this->http
            ->pushJson(200, ['versione' => '2025A'])
            ->pushPage([['codice' => '501.00001', 'ragione_sociale' => 'ACME SPA']])
            ->pushJson(200, ['codice' => '501.00001']);

        $mexal = $this->mexal();

        self::assertSame('2025A', $mexal->installazione()['versione']);

        $clienti = $mexal->resource(MexalResource::CLIENTI)->list(['fields' => 'codice,ragione_sociale']);
        self::assertSame('ACME SPA', $clienti[0]['ragione_sociale']);

        $cliente = $mexal->resource(MexalResource::CLIENTI)->get('501.00001');
        self::assertSame('501.00001', $cliente['codice']);
    }

    public function testTheQuickstartWriteFlow(): void
    {
        $this->http
            ->push(201, '', ['Location' => '/webapi/risorse/articoli/ARTICOLO1'])
            ->push(204)
            ->push(204);

        $articoli = $this->mexal()->resource(MexalResource::ARTICOLI);

        $codice = $articoli->createAndGetId([
            'codice' => 'ARTICOLO1',
            'descrizione' => 'Articolo di prova',
            'aliquota_iva' => 22,
        ]);

        self::assertSame('ARTICOLO1', $codice);
        self::assertTrue($articoli->update($codice, ['descrizione' => 'Nuova descrizione']));
        self::assertTrue($articoli->delete($codice));
    }

    public function testTheDocumentedErrorHandling(): void
    {
        $this->http->pushJson(400, [
            'error' => [
                'response-detail' => '6001 - errore gestionale [Aliquota iva obbligatoria]',
                'request-id' => '955f5722-0000',
            ],
        ]);

        try {
            $this->mexal()->resource(MexalResource::ARTICOLI)->create(['codice' => 'ART001']);
            self::fail('Attesa una RequestException.');
        } catch (RequestException $e) {
            self::assertSame(6001, $e->errorCode());
            self::assertSame(['Aliquota iva obbligatoria'], $e->hints());
            self::assertSame('955f5722-0000', $e->requestId());
            self::assertNotSame('', $e->errorFamily()?->rimedio() ?? '');
        }
    }

    public function testTheDocumentedSearchFilters(): void
    {
        $this->http->pushPage([['codice' => 'ART001']]);

        $risultati = $this->mexal()->resource(MexalResource::ARTICOLI)->search([
            'filtri' => [
                ['campo' => 'tipologia', 'condizione' => '=', 'valore' => 'A'],
                [
                    'campo' => 'descrizione',
                    'condizione' => 'contiene',
                    'case_insensitive' => true,
                    'valore' => ['BICI', 'MTB'],
                ],
            ],
        ]);

        self::assertCount(1, $risultati);
    }

    public function testTheDocumentedConnectorEscapeHatch(): void
    {
        $this->http->pushPage([['campo' => 'valore']]);

        $response = $this->mexal()->connector()->get('risorse/un/endpoint/particolare', ['param' => 'valore']);

        self::assertTrue($response->successful());
        self::assertSame([['campo' => 'valore']], $response->records());
    }

    public function testTheDocumentedCsvExportKeepsMemoryFlat(): void
    {
        $this->http
            ->pushPage([['codice' => 'A', 'descrizione' => 'Uno']], next: 'tok')
            ->pushPage([['codice' => 'B', 'descrizione' => 'Due']]);

        $righe = [];
        foreach ($this->mexal()->resource(MexalResource::ARTICOLI)->cursor(['fields' => 'codice,descrizione']) as $articolo) {
            $righe[] = $articolo['codice'];
        }

        self::assertSame(['A', 'B'], $righe);
    }
}
