<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Config;

use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

/**
 * Registro delle connessioni e delle istanze disponibili, con i relativi default.
 *
 * È il sostituto framework-agnostic del file config/mexal-api.php: la forma dell'array
 * accettata da fromArray() è identica, così un wrapper Laravel può limitarsi a
 * `MexalConfig::fromArray(config('mexal-api'))` e un progetto Symfony a passare il
 * parametro corrispondente.
 */
final class MexalConfig
{
    /** @var array<string, Connection> */
    private array $connections;

    /** @var array<string, Instance> */
    private array $instances;

    /**
     * @param array<string, Connection> $connections
     * @param array<string, Instance> $instances
     */
    public function __construct(
        array $connections,
        array $instances = [],
        private readonly ?string $defaultConnection = null,
        private readonly ?string $defaultInstance = null,
        private readonly HttpOptions $http = new HttpOptions(),
    ) {
        if ($connections === []) {
            throw new ConfigurationException('Serve almeno una connessione Mexal.');
        }

        $this->connections = $connections;

        // Un'istanza di default esiste sempre: senza coordinate gestionali gli endpoint
        // aziendali rispondono 1001, e chiedere all'utente di dichiararle anche quando
        // gli va bene IMP/anno corrente/magazzino 1 è attrito inutile.
        $this->instances = $instances === [] ? ['default' => new Instance()] : $instances;

        $this->assertExists($this->connections, $this->defaultConnection, 'connection');
        $this->assertExists($this->instances, $this->defaultInstance, 'instance');
    }

    /**
     * Accetta la forma classica del file di configurazione:
     *
     *     [
     *         'default'     => ['connection' => 'local', 'instance' => 'default'],
     *         'connections' => ['local' => [...], 'live' => [...]],
     *         'instances'   => ['default' => [...]],
     *         'http'        => ['timeout' => 60, ...],
     *     ]
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $connections = [];
        foreach (self::section($config, 'connections') as $name => $definition) {
            $connections[(string) $name] = $definition instanceof Connection
                ? $definition
                : Connection::fromArray(self::asArray($definition, "connections.$name"), (string) $name);
        }

        $instances = [];
        foreach (self::section($config, 'instances') as $name => $definition) {
            $instances[(string) $name] = $definition instanceof Instance
                ? $definition
                : Instance::fromArray(self::asArray($definition, "instances.$name"), (string) $name);
        }

        $defaults = self::section($config, 'default');

        return new self(
            connections: $connections,
            instances: $instances,
            defaultConnection: self::nullableString($defaults, 'connection'),
            defaultInstance: self::nullableString($defaults, 'instance'),
            http: HttpOptions::fromArray(self::section($config, 'http')),
        );
    }

    /**
     * Scorciatoia per il caso più comune: una sola connessione, una sola istanza.
     */
    public static function forConnection(
        Connection $connection,
        ?Instance $instance = null,
        ?HttpOptions $http = null,
    ): self {
        return new self(
            connections: [$connection->name => $connection],
            instances: ['default' => $instance ?? new Instance()],
            defaultConnection: $connection->name,
            defaultInstance: 'default',
            http: $http ?? new HttpOptions(),
        );
    }

    /**
     * Costruisce la configurazione dalle variabili d'ambiente, con gli stessi nomi usati
     * dalla versione Laravel del pacchetto (MEXAL_URL, MEXAL_API_USER, ...).
     *
     * Vengono registrate solo le connessioni per cui l'ambiente contiene abbastanza dati:
     * un progetto che usa solo il cloud non deve essere costretto a definire anche MEXAL_URL.
     *
     * @param array<string, string|null>|null $env Sorgente alternativa, utile nei test.
     */
    public static function fromEnv(?array $env = null): self
    {
        $read = static function (string $key) use ($env): ?string {
            if ($env !== null) {
                $value = $env[$key] ?? null;
            } else {
                // $_ENV e $_SERVER coprono i loader tipo vlucas/phpdotenv e symfony/dotenv;
                // getenv() copre le variabili impostate dal processo o dal web server.
                $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

                if ($value === null) {
                    $fromGetenv = getenv($key);
                    $value = $fromGetenv === false ? null : $fromGetenv;
                }
            }

            if (! is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };

        $connections = [];

        // Il discriminante è MEXAL_URL e non le credenziali: MEXAL_API_USER è condivisa
        // fra locale e cloud, quindi la sua presenza non dice nulla su quale delle due
        // installazioni esista davvero.
        if ($read('MEXAL_URL') !== null) {
            $connections['local'] = [
                'type' => 'local',
                'url' => $read('MEXAL_URL'),
                'port' => $read('MEXAL_PORT') ?? Connection::LOCAL_PORT,
                'api_user' => $read('MEXAL_API_USER'),
                'api_password' => $read('MEXAL_API_PASSWORD'),
                'so_user' => $read('MEXAL_SO_USER'),
                'so_password' => $read('MEXAL_SO_PASSWORD'),
                'verify' => $read('MEXAL_VERIFY_SSL') ?? true,
                'allow_plain_http' => $read('MEXAL_ALLOW_PLAIN_HTTP') ?? false,
            ];
        }

        if ($read('MEXAL_LIVE_DOMAIN') !== null) {
            $connections['live'] = [
                'type' => 'live',
                'url' => $read('MEXAL_LIVE_URL') ?? Connection::LIVE_URL,
                'domain' => $read('MEXAL_LIVE_DOMAIN'),
                'api_user' => $read('MEXAL_API_USER'),
                'api_password' => $read('MEXAL_API_PASSWORD'),
                'verify' => $read('MEXAL_LIVE_VERIFY_SSL') ?? true,
            ];
        }

        if ($connections === []) {
            throw new ConfigurationException(
                'Nessuna connessione Mexal ricavabile dall\'ambiente: imposta almeno MEXAL_URL con '
                .'MEXAL_API_USER / MEXAL_API_PASSWORD, oppure MEXAL_LIVE_DOMAIN per il cloud.',
            );
        }

        return self::fromArray([
            'default' => [
                // Se l'ambiente non lo dice, la connessione di default è l'unica presente.
                'connection' => $read('MEXAL_CONN') ?? array_key_first($connections),
                'instance' => 'default',
            ],
            'connections' => $connections,
            'instances' => [
                'default' => [
                    'anno' => $read('MEXAL_ANNO'),
                    'azienda' => $read('MEXAL_AZIENDA') ?? 'IMP',
                    'sotto_azienda' => $read('MEXAL_SUB_AZIENDA'),
                    'magazzino' => $read('MEXAL_MAGAZZINO'),
                ],
            ],
            'http' => [
                'timeout' => $read('MEXAL_HTTP_TIMEOUT'),
                'connect_timeout' => $read('MEXAL_HTTP_CONNECT_TIMEOUT'),
                'retries' => $read('MEXAL_HTTP_RETRIES'),
                'retry_delay' => $read('MEXAL_HTTP_RETRY_DELAY'),
                'max_pages' => $read('MEXAL_HTTP_MAX_PAGES'),
            ],
        ]);
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->defaultConnectionName();

        return $this->connections[$name]
            ?? throw new ConfigurationException(
                "Mexal connection '$name' non configurata. Disponibili: "
                .(implode(', ', array_keys($this->connections)) ?: 'nessuna').'.',
            );
    }

    public function instance(?string $name = null): Instance
    {
        $name ??= $this->defaultInstanceName();

        return $this->instances[$name]
            ?? throw new ConfigurationException(
                "Mexal instance '$name' non configurata. Disponibili: "
                .(implode(', ', array_keys($this->instances)) ?: 'nessuna').'.',
            );
    }

    public function http(): HttpOptions
    {
        return $this->http;
    }

    public function defaultConnectionName(): string
    {
        return $this->defaultConnection ?? (string) array_key_first($this->connections);
    }

    public function defaultInstanceName(): string
    {
        return $this->defaultInstance ?? (string) array_key_first($this->instances);
    }

    /** @return list<string> */
    public function connectionNames(): array
    {
        return array_map('strval', array_keys($this->connections));
    }

    /** @return list<string> */
    public function instanceNames(): array
    {
        return array_map('strval', array_keys($this->instances));
    }

    /**
     * @param array<string, mixed> $pool
     */
    private function assertExists(array $pool, ?string $name, string $kind): void
    {
        if ($name !== null && ! isset($pool[$name])) {
            throw new ConfigurationException(
                "La $kind di default '$name' non è fra quelle configurate: ".implode(', ', array_keys($pool)).'.',
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw new ConfigurationException("La chiave di configurazione '$path' deve essere un array.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
