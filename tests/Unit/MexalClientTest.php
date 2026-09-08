<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Tests\Support\TestCase;

final class MexalClientTest extends TestCase
{
    public function testInstallazioneReturnsTheWholeJsonObject(): void
    {
        $this->http->pushJson(200, ['versione' => '2025A', 'moduli' => ['magazzino']]);

        self::assertSame('2025A', $this->mexal()->installazione()['versione']);
        self::assertStringEndsWith('/risorse/dati-generali/installazione', (string) $this->http->lastRequest()->getUri());
    }

    /** Questo endpoint è l'eccezione: la chiave root è "Utenti", non "dati". */
    public function testUtentiReadsTheUtentiRootKey(): void
    {
        $this->http->pushJson(200, ['Utenti' => [['codice' => 1], ['codice' => 2]]]);

        self::assertCount(2, $this->mexal()->utenti());
    }

    public function testHelpReadsTheRisorseRootKeyAndSupportsExtended(): void
    {
        $this->http->pushJson(200, ['risorse' => [['nome' => 'clienti']]]);

        self::assertCount(1, $this->mexal()->help(extended: true));
        self::assertStringContainsString('extended=true', (string) $this->http->lastRequest()->getUri());
    }

    public function testMydbCompletesTheArchiveNameWhenTheAtSignIsMissing(): void
    {
        $mexal = $this->mexal();

        self::assertSame('risorse/mydb/MIA_APP@mydb', $mexal->mydb('MIA_APP')->path());
        self::assertSame('risorse/mydb/MIA_APP@ANAG', $mexal->mydb('MIA_APP@ANAG')->path());
        self::assertSame('risorse/mydb/MIA_APP@ANAG/OC', $mexal->mydb('MIA_APP@ANAG', 'OC')->path());
    }

    public function testServiziSendsCmdAndDatiAndAggregatesThePages(): void
    {
        $this->http
            ->pushJson(200, ['dati' => [['riga' => 1]], 'next' => 'tok'])
            ->pushJson(200, ['dati' => [['riga' => 2]]]);

        $output = $this->mexal()->servizi('calcolo_esposizione', ['in_codice_conto' => '501.00022']);

        self::assertSame([['riga' => 1], ['riga' => 2]], $output);

        $first = $this->decodeBody((string) $this->http->requestAt(0)->getBody());
        self::assertSame('calcolo_esposizione', $first['cmd']);
        self::assertSame(['in_codice_conto' => '501.00022'], $first['dati']);
        self::assertArrayNotHasKey('next', $first);

        // Sul canale servizi il token di paginazione viaggia nel BODY, accanto a "cmd",
        // non in query string come sugli endpoint REST.
        $second = $this->decodeBody((string) $this->http->requestAt(1)->getBody());
        self::assertSame('tok', $second['next']);
        self::assertStringNotContainsString('next=', (string) $this->http->requestAt(1)->getUri());
    }

    public function testServiziOmitsDatiWhenEmptyBecauseSomeServicesRejectIt(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->mexal()->servizi('elenco_aziende');

        self::assertArrayNotHasKey('dati', $this->decodeBody((string) $this->http->lastRequest()->getBody()));
    }

    public function testServiziPutsRootParametersNextToCmd(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->mexal()->servizi('get_prog_ubicazioni', root: ['cod_art_progr' => 'FUELEX97', 'dettlotti' => true]);

        $body = $this->decodeBody((string) $this->http->lastRequest()->getBody());

        self::assertSame('FUELEX97', $body['cod_art_progr']);
        self::assertTrue($body['dettlotti']);
    }

    public function testRootParametersCannotOverrideTheProtocolFields(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->mexal()->servizi('vero_comando', root: ['cmd' => 'comando_iniettato', 'next' => 'falso']);

        $body = $this->decodeBody((string) $this->http->lastRequest()->getBody());

        self::assertSame('vero_comando', $body['cmd']);
        self::assertArrayNotHasKey('next', $body);
    }

    public function testServiziReturnsTheRawBodyWhenTheResponseIsNotJson(): void
    {
        $this->http->push(200, '%PDF-1.4 fake', ['Content-Type' => 'application/pdf']);

        $contentType = null;
        $output = $this->mexal()->servizi('stampa_documento', ['sigla' => 'OC'], $contentType);

        self::assertSame('%PDF-1.4 fake', $output);
        self::assertSame('application/pdf', $contentType);
    }

    public function testAServiceRespondingWithASingleObjectStillReturnsAList(): void
    {
        $this->http->pushJson(200, ['esposizione' => 1234.5]);

        self::assertSame([['esposizione' => 1234.5]], $this->mexal()->servizi('calcolo_esposizione'));
    }

    public function testServiziRequestsArePostedWithAPermissiveAccept(): void
    {
        $this->http->pushJson(200, ['dati' => []]);

        $this->mexal()->servizi('qualsiasi');

        self::assertSame('*/*', $this->http->lastRequest()->getHeaderLine('Accept'));
    }

    public function testOnBuildsAClientOnAdHocCoordinates(): void
    {
        $this->http->pushJson(200, ['versione' => '2025A']);

        $this->mexal()->on(new Instance(azienda: 'ALT', anno: 2024, magazzino: 3))->installazione();

        self::assertSame(
            'Azienda=ALT Anno=2024 Magazzino=3',
            $this->http->lastRequest()->getHeaderLine('Coordinate-Gestionale'),
        );
    }

    public function testConnectorsAreReusedAcrossCalls(): void
    {
        $mexal = $this->mexal();

        self::assertSame($mexal->connector(), $mexal->connector());
        self::assertNotSame($mexal->connector(), $mexal->connector(compress: true));
    }

    public function testAnUnexpectedStatusOnASystemEndpointRaisesARequestException(): void
    {
        $this->http->pushJson(503, ['error' => ['response-detail' => '3002 - pool esaurito']]);

        $this->expectException(RequestException::class);

        $this->mexal()->utenti();
    }
}
