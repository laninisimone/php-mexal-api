<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Config\MexalConfig;
use Simonelanini\PhpMexalApi\Http\Connector;
use Simonelanini\PhpMexalApi\Mexal;

abstract class TestCase extends BaseTestCase
{
    protected MockClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockClient();
    }

    protected function connection(): Connection
    {
        return Connection::local(
            url: 'https://mexal.test',
            apiUser: 'api',
            apiPassword: 'secret',
            port: 9004,
            name: 'local',
        );
    }

    protected function connector(?HttpOptions $options = null, bool $compress = false): Connector
    {
        $factory = $this->http->factory();

        return new Connector(
            connection: $this->connection(),
            instance: new Instance(azienda: 'DEM', anno: 2025),
            options: $options ?? new HttpOptions(),
            client: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            compress: $compress,
        );
    }

    protected function mexal(?HttpOptions $options = null): Mexal
    {
        $factory = $this->http->factory();

        return new Mexal(
            config: new MexalConfig(
                connections: ['local' => $this->connection()],
                instances: ['default' => new Instance(azienda: 'DEM', anno: 2025)],
                http: $options ?? new HttpOptions(),
            ),
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /** @return array<string, mixed> */
    protected function decodeBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
