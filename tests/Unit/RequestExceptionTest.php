<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Enums\MexalErrorCode;
use Simonelanini\PhpMexalApi\Exceptions\MexalException;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Http\Response;

final class RequestExceptionTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     */
    private function exception(int $status, array $body, string $method = 'POST', string $uri = '/webapi/risorse/articoli'): RequestException
    {
        return RequestException::from(new Response(
            new PsrResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($body)),
            $method,
            $uri,
        ));
    }

    public function testTheDetailIsSplitIntoCodeReasonAndHints(): void
    {
        $e = $this->exception(400, [
            'error' => [
                'response-detail' => '6001 - errore gestionale [Anagrafica articolo] [Aliquota iva obbligatoria]',
                'request-id' => '955f5722-0000',
                'request-method' => 'POST',
                'request-uri' => '/webapi/risorse/articoli',
                'timestamp' => '03/09/2025 10:15:00',
            ],
        ]);

        self::assertSame(6001, $e->errorCode());
        self::assertSame(MexalErrorCode::ERRORE_GESTIONALE, $e->errorFamily());
        self::assertSame('errore gestionale', $e->reason());
        self::assertSame(['Anagrafica articolo', 'Aliquota iva obbligatoria'], $e->hints());
        self::assertSame('955f5722-0000', $e->requestId());
        self::assertSame('03/09/2025 10:15:00', $e->serverTimestamp());
        self::assertSame(400, $e->status());
        self::assertSame(400, $e->getCode());
    }

    /**
     * Il messaggio deve riportare il detail per intero: è l'unico posto in cui il
     * gestionale scrive la causa vera del rifiuto.
     */
    public function testTheMessageCarriesTheFullDetailAndTheRequestContext(): void
    {
        $message = $this->exception(400, [
            'error' => [
                'response-detail' => '6001 - errore gestionale [Aliquota iva obbligatoria]',
                'request-id' => 'abc-123',
                'request-method' => 'POST',
                'request-uri' => '/webapi/risorse/articoli',
            ],
        ])->getMessage();

        self::assertStringContainsString('Aliquota iva obbligatoria', $message);
        self::assertStringContainsString('POST /webapi/risorse/articoli', $message);
        self::assertStringContainsString('abc-123', $message);
    }

    public function testAResponseWithoutAnErrorObjectFallsBackToTheRequestContext(): void
    {
        $e = RequestException::from(new Response(new PsrResponse(404), 'GET', '/webapi/risorse/clienti/999'));

        self::assertSame([], $e->error());
        self::assertNull($e->errorCode());
        self::assertNull($e->detail());
        self::assertStringContainsString('404', $e->getMessage());
        self::assertStringContainsString('/webapi/risorse/clienti/999', $e->getMessage());
    }

    public function testAnHtmlErrorPageFromAProxyDoesNotBreakTheParsing(): void
    {
        $e = RequestException::from(new Response(
            new PsrResponse(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>'),
            'GET',
            '/webapi/risorse/clienti',
        ));

        self::assertSame([], $e->error());
        self::assertTrue($e->isRetryable());
    }

    public function testRetryabilityFollowsTheStatus(): void
    {
        self::assertTrue($this->exception(503, [])->isRetryable());
        self::assertTrue($this->exception(429, [])->isRetryable());
        self::assertTrue($this->exception(408, [])->isRetryable());
        self::assertFalse($this->exception(400, [])->isRetryable());
        self::assertFalse($this->exception(404, [])->isRetryable());
    }

    public function testUnknownErrorCodesHaveNoFamilyButKeepTheirNumber(): void
    {
        $e = $this->exception(400, ['error' => ['response-detail' => '9999 - codice sconosciuto']]);

        self::assertSame(9999, $e->errorCode());
        self::assertNull($e->errorFamily());
        self::assertSame('codice sconosciuto', $e->reason());
    }

    public function testEveryFamilyOffersARemedy(): void
    {
        foreach (MexalErrorCode::cases() as $case) {
            self::assertNotSame('', $case->rimedio());
        }
    }

    public function testItIsCatchableAsAMexalException(): void
    {
        self::assertInstanceOf(MexalException::class, $this->exception(400, []));
    }
}
