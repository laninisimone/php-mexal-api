<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Http\Response;

final class ResponseTest extends TestCase
{
    public function testJsonDecodesTheBodyAndReadsRootKeys(): void
    {
        $response = new Response(new PsrResponse(
            200,
            ['Content-Type' => 'application/json'],
            '{"dati":[{"codice":"1"}],"next":"tok"}',
        ));

        self::assertSame('tok', $response->json('next'));
        self::assertSame([['codice' => '1']], $response->records());
        self::assertTrue($response->isJson());
        self::assertTrue($response->successful());
    }

    public function testAMissingDatiKeyMeansAnEmptyList(): void
    {
        $response = new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], '{}'));

        self::assertSame([], $response->records());
    }

    public function testInvalidJsonReturnsNullInsteadOfThrowing(): void
    {
        $response = new Response(new PsrResponse(502, ['Content-Type' => 'text/html'], '<html>oops</html>'));

        self::assertNull($response->json());
        self::assertNull($response->json('error'));
        self::assertFalse($response->isJson());
        self::assertTrue($response->serverError());
    }

    public function testJsonObjectFailsLoudlyWhereTheManualGuaranteesAnObject(): void
    {
        $response = new Response(new PsrResponse(200, ['Content-Type' => 'text/html'], 'non json'));

        $this->expectException(ProtocolException::class);

        $response->jsonObject('risorse/clienti/1');
    }

    public function testTheBodyCanBeReadMoreThanOnce(): void
    {
        $response = new Response(new PsrResponse(200, [], 'contenuto'));

        self::assertSame('contenuto', $response->body());
        self::assertSame('contenuto', $response->body());
    }

    /**
     * Alcuni client PSR-18 consegnano il body ancora compresso quando l'header
     * Accept-Encoding è stato impostato a mano: la rete di sicurezza va verificata.
     */
    public function testAGzippedBodyIsDecompressedWhenTheClientDidNotDoIt(): void
    {
        $payload = (string) gzencode('{"dati":[]}');

        $response = new Response(new PsrResponse(
            200,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
            $payload,
        ));

        self::assertSame('{"dati":[]}', $response->body());
        self::assertSame([], $response->records());
    }

    public function testAnAlreadyDecompressedBodyIsLeftUntouched(): void
    {
        $response = new Response(new PsrResponse(
            200,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
            '{"dati":[]}',
        ));

        self::assertSame('{"dati":[]}', $response->body());
    }

    public function testHeadersAndStatusHelpers(): void
    {
        $response = new Response(new PsrResponse(404, ['X-Custom' => 'valore']));

        self::assertSame('valore', $response->header('X-Custom'));
        self::assertNull($response->header('X-Assente'));
        self::assertTrue($response->clientError());
        self::assertFalse($response->successful());
        self::assertSame('', $response->contentType());
    }
}
