<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\ConnectionType;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

final class ConnectionTest extends TestCase
{
    public function testLocalBuildsBaseUriWithPortAndWebapiPath(): void
    {
        $connection = Connection::local('https://192.168.1.10', 'api', 'pwd', port: 9004);

        self::assertSame('https://192.168.1.10:9004/webapi/', $connection->baseUri);
        self::assertSame(ConnectionType::LOCAL, $connection->type);
    }

    public function testBaseUriPreservesAPathPrefixForReverseProxies(): void
    {
        $connection = Connection::local('https://erp.example.com/mexal/', 'api', 'pwd', port: null);

        self::assertSame('https://erp.example.com/mexal/webapi/', $connection->baseUri);
    }

    public function testExplicitPortOverridesTheOneInTheUrl(): void
    {
        $connection = Connection::local('https://erp.example.com:8443', 'api', 'pwd', port: 9004);

        self::assertSame('https://erp.example.com:9004/webapi/', $connection->baseUri);
    }

    public function testAuthorizationHeaderFollowsTheManualFormat(): void
    {
        $connection = Connection::local('https://mexal.test', 'api', 'pwd', soUser: 'so', soPassword: 'sopwd');

        self::assertSame(
            'Passepartout '.base64_encode('api:pwd').' '.base64_encode('so:sopwd'),
            $connection->authorizationHeader(),
        );
    }

    public function testLiveAuthorizationHeaderCarriesTheDomain(): void
    {
        $connection = Connection::live('dominio_xyz', 'api', 'pwd');

        self::assertSame(
            'Passepartout '.base64_encode('api:pwd').' Dominio=dominio_xyz',
            $connection->authorizationHeader(),
        );
        self::assertSame('https://services.passepartout.cloud/webapi/', $connection->baseUri);
        self::assertTrue($connection->isLive());
    }

    public function testSoCredentialsAreOmittedWhenNotConfigured(): void
    {
        $connection = Connection::local('https://mexal.test', 'api', 'pwd');

        self::assertSame('Passepartout '.base64_encode('api:pwd'), $connection->authorizationHeader());
    }

    public function testPlainHttpIsRejectedBecauseBasicAuthWouldTravelInClear(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/chiaro/');

        Connection::local('http://192.168.1.10', 'api', 'pwd');
    }

    public function testPlainHttpIsAllowedWithAnExplicitOptIn(): void
    {
        $connection = Connection::local('http://192.168.1.10', 'api', 'pwd', allowPlainHttp: true);

        self::assertSame('http://192.168.1.10:9004/webapi/', $connection->baseUri);
    }

    public function testCredentialsInTheUrlAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        Connection::local('https://api:pwd@mexal.test', 'api', 'pwd');
    }

    public function testTlsVerificationIsOnByDefaultEvenOnLocalConnections(): void
    {
        self::assertTrue(Connection::local('https://mexal.test', 'api', 'pwd')->verify);
        self::assertTrue(Connection::local('https://mexal.test', 'api', 'pwd')->verifiesTls());
    }

    /**
     * Le variabili d'ambiente arrivano sempre come stringhe: "false" deve disattivare la
     * verifica, non attivarla perché ogni stringa non vuota è truthy in PHP.
     */
    public function testStringBooleansFromTheEnvironmentAreNormalised(): void
    {
        self::assertFalse(Connection::local('https://mexal.test', 'api', 'pwd', verify: 'false')->verify);
        self::assertFalse(Connection::local('https://mexal.test', 'api', 'pwd', verify: '0')->verify);
        self::assertTrue(Connection::local('https://mexal.test', 'api', 'pwd', verify: 'true')->verify);
    }

    public function testAMissingCaBundleFailsAtConfigurationTime(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/CA bundle/');

        Connection::local('https://mexal.test', 'api', 'pwd', verify: '/percorso/inesistente.pem');
    }

    public function testAnExistingCaBundleIsKeptAsThePathToUse(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'ca').'.pem';
        file_put_contents($bundle, '-----BEGIN CERTIFICATE-----');

        try {
            $connection = Connection::local('https://mexal.test', 'api', 'pwd', verify: $bundle);

            self::assertSame($bundle, $connection->caBundle());
            self::assertTrue($connection->verifiesTls());
        } finally {
            @unlink($bundle);
        }
    }

    public function testDomainWithWhitespaceIsRejectedToPreventHeaderInjection(): void
    {
        $this->expectException(ConfigurationException::class);

        Connection::live("dominio\r\nX-Injected: 1", 'api', 'pwd');
    }

    public function testCredentialsNeverAppearInTheArrayOrDebugRepresentation(): void
    {
        $connection = Connection::local('https://mexal.test', 'utente', 'password-segretissima', soUser: 'so', soPassword: 'so-pwd');

        $dumped = print_r($connection->toArray(), true).print_r($connection->__debugInfo(), true);

        self::assertStringNotContainsString('password-segretissima', $dumped);
        self::assertStringNotContainsString('so-pwd', $dumped);
        self::assertStringNotContainsString(base64_encode('utente:password-segretissima'), $dumped);
        self::assertSame('set', $connection->toArray()['api_credentials']);
    }

    public function testUnknownTypeFailsImmediately(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/type 'ftp' non supportato/");

        Connection::fromArray(['type' => 'ftp', 'url' => 'https://mexal.test'], 'strana');
    }

    public function testFromArrayDefaultsToThePortOfTheWebapiServiceWhenTheKeyIsAbsent(): void
    {
        $connection = Connection::fromArray([
            'type' => 'local',
            'url' => 'https://mexal.test',
            'api_user' => 'api',
            'api_password' => 'pwd',
        ], 'local');

        self::assertSame('https://mexal.test:9004/webapi/', $connection->baseUri);
    }

    public function testFromArrayWithAnExplicitNullPortLeavesTheUrlUntouched(): void
    {
        $connection = Connection::fromArray([
            'type' => 'local',
            'url' => 'https://erp.example.com',
            'port' => null,
            'api_user' => 'api',
            'api_password' => 'pwd',
        ], 'local');

        self::assertSame('https://erp.example.com/webapi/', $connection->baseUri);
    }

    public function testMissingApiCredentialsAreReportedWithTheConnectionName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/'produzione'/");

        Connection::fromArray(['type' => 'local', 'url' => 'https://mexal.test'], 'produzione');
    }
}
