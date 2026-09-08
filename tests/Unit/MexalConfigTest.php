<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Config\MexalConfig;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

final class MexalConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function arrayConfig(): array
    {
        return [
            'default' => ['connection' => 'live', 'instance' => 'principale'],
            'connections' => [
                'local' => [
                    'type' => 'local',
                    'url' => 'https://mexal.test',
                    'port' => 9004,
                    'api_user' => 'api',
                    'api_password' => 'pwd',
                ],
                'live' => [
                    'type' => 'live',
                    'domain' => 'dominio_xyz',
                    'api_user' => 'api',
                    'api_password' => 'pwd',
                ],
            ],
            'instances' => [
                'principale' => ['azienda' => 'DEM', 'anno' => 2025, 'magazzino' => 2],
            ],
            'http' => ['timeout' => 90, 'retries' => 2],
        ];
    }

    public function testFromArrayMirrorsTheConfigurationFileShape(): void
    {
        $config = MexalConfig::fromArray($this->arrayConfig());

        self::assertSame('live', $config->defaultConnectionName());
        self::assertSame('principale', $config->defaultInstanceName());
        self::assertSame(['local', 'live'], $config->connectionNames());
        self::assertSame('dominio_xyz', $config->connection()->domain);
        self::assertSame('https://mexal.test:9004/webapi/', $config->connection('local')->baseUri);
        self::assertSame(2, $config->instance()->magazzino);
        self::assertSame(90, $config->http()->timeout);
        self::assertSame(2, $config->http()->retries);
    }

    public function testAnUnknownConnectionListsTheAvailableOnes(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Disponibili: local, live/');

        MexalConfig::fromArray($this->arrayConfig())->connection('staging');
    }

    public function testADefaultPointingToAMissingConnectionFailsAtConstruction(): void
    {
        $config = $this->arrayConfig();
        $config['default']['connection'] = 'inesistente';

        $this->expectException(ConfigurationException::class);

        MexalConfig::fromArray($config);
    }

    public function testADefaultInstanceIsProvidedWhenNoneIsConfigured(): void
    {
        $config = new MexalConfig(['local' => Connection::local('https://mexal.test', 'api', 'pwd')]);

        self::assertSame('IMP', $config->instance()->azienda);
        self::assertSame((int) date('Y'), $config->instance()->anno);
    }

    public function testForConnectionCoversTheSingleConnectionCase(): void
    {
        $config = MexalConfig::forConnection(Connection::live('dominio', 'api', 'pwd'));

        self::assertSame('live', $config->defaultConnectionName());
        self::assertSame('dominio', $config->connection()->domain);
    }

    public function testFromEnvBuildsTheLocalConnection(): void
    {
        $config = MexalConfig::fromEnv([
            'MEXAL_URL' => 'https://192.168.1.10',
            'MEXAL_PORT' => '9004',
            'MEXAL_API_USER' => 'api',
            'MEXAL_API_PASSWORD' => 'pwd',
            'MEXAL_AZIENDA' => 'DEM',
            'MEXAL_ANNO' => '2024',
            'MEXAL_HTTP_TIMEOUT' => '120',
        ]);

        self::assertSame('local', $config->defaultConnectionName());
        self::assertSame('https://192.168.1.10:9004/webapi/', $config->connection()->baseUri);
        self::assertSame('DEM', $config->instance()->azienda);
        self::assertSame(2024, $config->instance()->anno);
        self::assertSame(120, $config->http()->timeout);
    }

    public function testFromEnvRegistersOnlyTheConnectionsTheEnvironmentDescribes(): void
    {
        $config = MexalConfig::fromEnv([
            'MEXAL_LIVE_DOMAIN' => 'dominio_xyz',
            'MEXAL_API_USER' => 'api',
            'MEXAL_API_PASSWORD' => 'pwd',
        ]);

        self::assertSame(['live'], $config->connectionNames());
        self::assertSame('live', $config->defaultConnectionName());
    }

    public function testFromEnvFailsLoudlyWhenNothingIsConfigured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/MEXAL_URL/');

        MexalConfig::fromEnv([]);
    }

    public function testFromEnvKeepsTlsVerificationOnByDefault(): void
    {
        $config = MexalConfig::fromEnv([
            'MEXAL_URL' => 'https://mexal.test',
            'MEXAL_API_USER' => 'api',
            'MEXAL_API_PASSWORD' => 'pwd',
        ]);

        self::assertTrue($config->connection()->verify);
    }

    public function testHttpOptionsRejectNegativeValues(): void
    {
        $this->expectException(ConfigurationException::class);

        new HttpOptions(timeout: -1);
    }

    public function testHttpOptionsRejectNonNumericStrings(): void
    {
        $this->expectException(ConfigurationException::class);

        HttpOptions::fromArray(['timeout' => 'sessanta']);
    }

    public function testTheUserAgentCannotCarryAHeaderInjection(): void
    {
        $options = new HttpOptions(userAgent: "cattivo\r\nX-Injected: 1");

        self::assertStringNotContainsString("\r", $options->userAgent);
        self::assertStringNotContainsString("\n", $options->userAgent);
    }
}
