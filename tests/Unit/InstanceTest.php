<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

final class InstanceTest extends TestCase
{
    public function testHeaderContainsAllTheCoordinates(): void
    {
        $instance = new Instance(azienda: 'DEM', sottoAzienda: 'A', anno: 2025, magazzino: 2);

        self::assertSame('Azienda=DEM SottoAzienda=A Anno=2025 Magazzino=2', $instance->header());
    }

    public function testSottoAziendaIsOmittedWhenNotConfigured(): void
    {
        self::assertSame(
            'Azienda=DEM Anno=2025 Magazzino=1',
            (new Instance(azienda: 'DEM', sottoAzienda: '   ', anno: 2025))->header(),
        );
    }

    public function testYearDefaultsToTheCurrentOne(): void
    {
        self::assertSame((int) date('Y'), (new Instance())->anno);
    }

    public function testValuesThatWouldInjectAHeaderAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Instance(azienda: "DEM\r\nX-Injected: 1");
    }

    public function testSpacesInTheCompanyCodeAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Instance(azienda: 'DEM ALTRO');
    }

    public function testYearOutOfRangeIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Instance(anno: 12025);
    }

    public function testWithReturnsACopyAndLeavesTheOriginalUntouched(): void
    {
        $original = new Instance(azienda: 'DEM', anno: 2025);
        $copy = $original->with(anno: 2024);

        self::assertSame(2025, $original->anno);
        self::assertSame(2024, $copy->anno);
        self::assertSame('DEM', $copy->azienda);
    }

    public function testFromArrayAcceptsStringsAsTheyArriveFromTheEnvironment(): void
    {
        $instance = Instance::fromArray(['azienda' => 'DEM', 'anno' => '2024', 'magazzino' => '3']);

        self::assertSame(2024, $instance->anno);
        self::assertSame(3, $instance->magazzino);
    }

    public function testFromArrayReportsTheInstanceNameOnFailure(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/'secondaria'/");

        Instance::fromArray(['azienda' => 'non valida!'], 'secondaria');
    }
}
