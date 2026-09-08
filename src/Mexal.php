<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Config\MexalConfig;
use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;
use Simonelanini\PhpMexalApi\Http\ClientFactory;
use Simonelanini\PhpMexalApi\Http\Connector;
use Simonelanini\PhpMexalApi\Http\DefaultClientFactory;

/**
 * Punto di ingresso del pacchetto: sceglie su quale connessione e istanza far girare le
 * operazioni, e tiene in cache i connector già costruiti.
 *
 * È l'unica classe che un'applicazione deve istanziare. Registrala una volta nel container
 * del tuo framework e riusala: costruire un connector significa costruire un client HTTP,
 * e rifarlo a ogni chiamata butta via il pool di connessioni keep-alive.
 *
 *     $mexal = Mexal::make(Connection::live('dominio', 'utente', 'password'));
 *     $clienti = $mexal->resource(MexalResource::CLIENTI)->list();
 */
final class Mexal
{
    /** @var array<string, Connector> Connector già costruiti, per connessione+istanza+compressione. */
    private array $connectors = [];

    private readonly ClientFactory $clientFactory;

    private ?RequestFactoryInterface $requestFactory;

    private ?StreamFactoryInterface $streamFactory;

    /**
     * @param MexalConfig $config Connessioni, istanze e opzioni HTTP.
     * @param ClientInterface|null $httpClient Client PSR-18 già configurato. Passandolo, timeout e
     *                                         verifica TLS diventano responsabilità tua: la
     *                                         ClientFactory non viene interpellata.
     * @param LoggerInterface|null $logger PSR-3 opzionale. Riceve metodo, URI, status e durata
     *                                     di ogni chiamata; mai header né body.
     * @param ClientFactory|null $clientFactory Strategia di costruzione del client HTTP.
     * @param RequestFactoryInterface|null $requestFactory PSR-17; se null viene cercata con la discovery.
     * @param StreamFactoryInterface|null $streamFactory PSR-17; se null viene cercata con la discovery.
     */
    public function __construct(
        private readonly MexalConfig $config,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
        ?ClientFactory $clientFactory = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? new DefaultClientFactory();
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Scorciatoia per il caso più comune: una connessione, una istanza.
     */
    public static function make(
        Connection $connection,
        ?Instance $instance = null,
        ?HttpOptions $http = null,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            MexalConfig::forConnection($connection, $instance, $http),
            $httpClient,
            $logger,
        );
    }

    /**
     * Costruisce tutto a partire dall'array di configurazione dell'applicazione: è la via
     * che un wrapper di framework dovrebbe usare.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(
        array $config,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(MexalConfig::fromArray($config), $httpClient, $logger);
    }

    /**
     * Costruisce tutto dalle variabili d'ambiente (MEXAL_URL, MEXAL_API_USER, ...).
     *
     * @param array<string, string|null>|null $env Sorgente alternativa, utile nei test.
     */
    public static function fromEnv(
        ?array $env = null,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(MexalConfig::fromEnv($env), $httpClient, $logger);
    }

    public function config(): MexalConfig
    {
        return $this->config;
    }

    /**
     * Connector HTTP di basso livello. Serve solo per gli endpoint non coperti dai client
     * di alto livello: per tutto il resto usa resource() o client().
     */
    public function connector(?string $connection = null, ?string $instance = null, bool $compress = false): Connector
    {
        $connectionName = $connection ?? $this->config->defaultConnectionName();
        $instanceName = $instance ?? $this->config->defaultInstanceName();
        $key = $connectionName.'|'.$instanceName.'|'.($compress ? 'gz' : 'raw');

        return $this->connectors[$key] ??= $this->buildConnector($connectionName, $instanceName, $compress);
    }

    /**
     * Client di alto livello sulla connessione richiesta.
     */
    public function client(?string $connection = null, ?string $instance = null, bool $compress = false): MexalClient
    {
        return new MexalClient($this->connector($connection, $instance, $compress));
    }

    /**
     * Entry point principale: $mexal->resource('clienti')->list()
     */
    public function resource(
        string|MexalResource $name,
        ?string $connection = null,
        ?string $instance = null,
        bool $compress = false,
    ): ResourceClient {
        return $this->client($connection, $instance, $compress)->resource($name);
    }

    /**
     * Archivio MyDB di una app Passbuilder.
     */
    public function mydb(
        string|MexalResource $app,
        ?string $extension = null,
        ?string $connection = null,
        ?string $instance = null,
        bool $compress = false,
    ): ResourceClient {
        return $this->client($connection, $instance, $compress)->mydb($app, $extension);
    }

    /**
     * Client su coordinate gestionali costruite al volo, senza doverle registrare in
     * configurazione: utile per i job che ciclano su più aziende o più anni.
     */
    public function on(Instance $instance, ?string $connection = null, bool $compress = false): MexalClient
    {
        return new MexalClient(
            $this->connector($connection, null, $compress)->withInstance($instance),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public function installazione(?string $connection = null, ?string $instance = null): array
    {
        return $this->client($connection, $instance)->installazione();
    }

    /**
     * @return list<mixed>
     */
    public function utenti(?string $connection = null, ?string $instance = null): array
    {
        return $this->client($connection, $instance)->utenti();
    }

    /**
     * Elenco degli endpoint disponibili; con $extended anche paginazione, encoding e chiavi.
     *
     * @return list<mixed>
     */
    public function help(bool $extended = false, ?string $connection = null, ?string $instance = null): array
    {
        return $this->client($connection, $instance)->help($extended);
    }

    /**
     * Invoca un servizio del canale /servizi.
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed> $root
     * @param-out string               $contentType
     * @return list<mixed>|string
     */
    public function servizi(
        string $cmd,
        array $dati = [],
        ?string &$contentType = null,
        ?string $connection = null,
        ?string $instance = null,
        ?int $maxPages = null,
        array $root = [],
    ): array|string {
        // $contentType è per riferimento e va inoltrato come tale: uno spread lo spezzerebbe
        // e lascerebbe la variabile del chiamante sempre a null.
        return $this->client($connection, $instance)->servizi($cmd, $dati, $contentType, $maxPages, $root);
    }

    /** Verifica non bloccante della raggiungibilità del server. */
    public function isConnected(?string $connection = null, ?string $instance = null): bool
    {
        return $this->connector($connection, $instance)->isConnected();
    }

    private function buildConnector(string $connectionName, string $instanceName, bool $compress): Connector
    {
        $connection = $this->config->connection($connectionName);
        $options = $this->config->http();

        return new Connector(
            connection: $connection,
            instance: $this->config->instance($instanceName),
            options: $options,
            // Un client iniettato vince sempre: chi lo passa ha già deciso proxy, TLS e
            // timeout, e la factory non deve sovrascrivere quelle scelte.
            client: $this->httpClient ?? $this->clientFactory->createFor($connection, $options),
            requestFactory: $this->requestFactory ??= self::discoverRequestFactory(),
            streamFactory: $this->streamFactory ??= self::discoverStreamFactory(),
            compress: $compress,
            logger: $this->logger,
        );
    }

    private static function discoverRequestFactory(): RequestFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException $e) {
            throw self::missingPsr17($e);
        }
    }

    private static function discoverStreamFactory(): StreamFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $e) {
            throw self::missingPsr17($e);
        }
    }

    private static function missingPsr17(NotFoundException $previous): ConfigurationException
    {
        return new ConfigurationException(
            'Nessuna implementazione PSR-17 trovata. Installa nyholm/psr7 (consigliato) oppure '
            .'guzzlehttp/psr7, o passa le factory al costruttore di Mexal.',
            0,
            $previous,
        );
    }
}
