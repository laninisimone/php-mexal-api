<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Http\DefaultClientFactory;

final class DefaultClientFactoryTest extends TestCase
{
    public function testGuzzleIsConfiguredWithTheConnectionAndTimeoutSettings(): void
    {
        $client = (new DefaultClientFactory())->createFor(
            Connection::local('https://mexal.test', 'api', 'pwd', verify: false),
            new HttpOptions(timeout: 90, connectTimeout: 5),
        );

        self::assertInstanceOf(GuzzleClient::class, $client);

        // getConfig() è deprecato e sparirà in Guzzle 8: quando si allargherà il vincolo a ^8.0
        // questa asserzione andrà riscritta (probabilmente ispezionando l'handler stack).
        $config = $client->getConfig();

        self::assertFalse($config['verify']);
        self::assertSame(90, $config['timeout']);
        self::assertSame(5, $config['connect_timeout']);

        // Lo status lo interpreta il Connector: le eccezioni di Guzzle sui 4xx/5xx
        // impedirebbero di leggere l'oggetto "error" del gestionale.
        self::assertFalse($config['http_errors']);

        // Un redirect porterebbe l'header Authorization su un host diverso.
        self::assertFalse($config['allow_redirects']);
    }

    public function testACaBundlePathIsHandedToTheClient(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'ca').'.pem';
        file_put_contents($bundle, '-----BEGIN CERTIFICATE-----');

        try {
            $client = (new DefaultClientFactory())->createFor(
                Connection::local('https://mexal.test', 'api', 'pwd', verify: $bundle),
                new HttpOptions(),
            );

            self::assertInstanceOf(GuzzleClient::class, $client);
            self::assertSame($bundle, $client->getConfig()['verify']);
        } finally {
            @unlink($bundle);
        }
    }

    public function testClientsAreReusedForTheSameConnection(): void
    {
        $factory = new DefaultClientFactory();
        $connection = Connection::local('https://mexal.test', 'api', 'pwd');
        $options = new HttpOptions();

        self::assertSame(
            $factory->createFor($connection, $options),
            $factory->createFor($connection, $options),
        );
    }

    public function testDifferentConnectionsGetDifferentClients(): void
    {
        $factory = new DefaultClientFactory();
        $options = new HttpOptions();

        self::assertNotSame(
            $factory->createFor(Connection::local('https://uno.test', 'api', 'pwd'), $options),
            $factory->createFor(Connection::local('https://due.test', 'api', 'pwd'), $options),
        );
    }
}
