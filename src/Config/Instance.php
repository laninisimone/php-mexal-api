<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Config;

use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

/**
 * Le coordinate gestionali inviate nell'header Coordinate-Gestionale:
 *
 *     Azienda=DEM SottoAzienda=A Anno=2025 Magazzino=2
 *
 * Descrivono "dove" leggere e scrivere; la Connection descrive "a chi" parlare. Sono
 * separate perché la stessa installazione ospita più aziende e più anni contabili, e
 * cambiare istanza non deve richiedere di riautenticarsi.
 *
 * I valori sono validati alla costruzione: finiscono dentro un header HTTP, e uno spazio
 * o un a capo di troppo permetterebbe di iniettare header arbitrari nella richiesta.
 */
final class Instance
{
    /**
     * Alfabeto ammesso nei token dell'header. Volutamente ristretto: i codici Mexal sono
     * alfanumerici, e ciò che sta fuori da questo insieme è quasi sempre un errore di
     * configurazione — oppure un tentativo di injection.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    public readonly string $azienda;

    /** Null quando non configurata: in quel caso il token viene omesso dall'header. */
    public readonly ?string $sottoAzienda;

    public readonly int $anno;

    public readonly int $magazzino;

    public function __construct(
        string $azienda = 'IMP',
        ?string $sottoAzienda = null,
        ?int $anno = null,
        int $magazzino = 1,
    ) {
        $this->azienda = self::assertToken('azienda', trim($azienda));

        // Stringa vuota e stringa di soli spazi valgono "non configurata", non "vuota":
        // il manuale prevede che in quel caso il token non venga inviato affatto.
        $sottoAzienda = $sottoAzienda === null ? null : trim($sottoAzienda);
        $this->sottoAzienda = ($sottoAzienda === null || $sottoAzienda === '')
            ? null
            : self::assertToken('sotto_azienda', $sottoAzienda);

        $this->anno = self::assertAnno($anno ?? (int) date('Y'));
        $this->magazzino = self::assertMagazzino($magazzino);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $name = 'default'): self
    {
        try {
            return new self(
                azienda: self::stringOr($config, 'azienda', 'IMP'),
                sottoAzienda: isset($config['sotto_azienda']) && is_scalar($config['sotto_azienda'])
                    ? (string) $config['sotto_azienda']
                    : null,
                anno: self::intOrNull($config, 'anno'),
                magazzino: self::intOrNull($config, 'magazzino') ?? 1,
            );
        } catch (ConfigurationException $e) {
            // Rilancia citando l'istanza: con più istanze configurate, sapere quale è
            // rotta vale più del messaggio di dettaglio da solo.
            throw new ConfigurationException("Mexal instance '$name': ".$e->getMessage(), 0, $e);
        }
    }

    /** Valore dell'header Coordinate-Gestionale. */
    public function header(): string
    {
        $tokens = ["Azienda={$this->azienda}"];

        if ($this->sottoAzienda !== null) {
            $tokens[] = "SottoAzienda={$this->sottoAzienda}";
        }

        $tokens[] = "Anno={$this->anno}";
        $tokens[] = "Magazzino={$this->magazzino}";

        return implode(' ', $tokens);
    }

    /** Copia con uno o più valori sostituiti; l'originale resta immutato. */
    public function with(
        ?string $azienda = null,
        ?string $sottoAzienda = null,
        ?int $anno = null,
        ?int $magazzino = null,
    ): self {
        return new self(
            azienda: $azienda ?? $this->azienda,
            sottoAzienda: $sottoAzienda ?? $this->sottoAzienda,
            anno: $anno ?? $this->anno,
            magazzino: $magazzino ?? $this->magazzino,
        );
    }

    /**
     * @return array{azienda: string, sotto_azienda: string|null, anno: int, magazzino: int}
     */
    public function toArray(): array
    {
        return [
            'azienda' => $this->azienda,
            'sotto_azienda' => $this->sottoAzienda,
            'anno' => $this->anno,
            'magazzino' => $this->magazzino,
        ];
    }

    /** Chiave stabile per la cache dei connector. */
    public function fingerprint(): string
    {
        return $this->header();
    }

    private static function assertToken(string $key, string $value): string
    {
        if (preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new ConfigurationException(
                "$key ammette solo lettere, cifre, punto, trattino e underscore (max 32 caratteri).",
            );
        }

        return $value;
    }

    private static function assertAnno(int $anno): int
    {
        if ($anno < 1900 || $anno > 2999) {
            throw new ConfigurationException("anno fuori intervallo: $anno.");
        }

        return $anno;
    }

    private static function assertMagazzino(int $magazzino): int
    {
        if ($magazzino < 1 || $magazzino > 999) {
            throw new ConfigurationException("magazzino fuori intervallo: $magazzino.");
        }

        return $magazzino;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringOr(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOrNull(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ConfigurationException("$key deve essere un intero, ricevuto: ".get_debug_type($value).'.');
    }
}
