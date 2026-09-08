<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Config;

use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

/**
 * Parametri di trasporto validi per tutte le connessioni.
 *
 * I default non sono arbitrari: il servizio WebAPI elabora una GET fino a 30 secondi prima
 * di interrompersi e restituire un "next" di paginazione, quindi un timeout di lettura
 * sotto quella soglia taglierebbe letture legittime. Il timeout di connessione resta breve
 * perché un server spento non deve tenere appesa l'applicazione.
 */
final class HttpOptions
{
    public const DEFAULT_USER_AGENT = 'php-mexal-api/1.0 (+https://github.com/simonelanini/php-mexal-api)';

    public readonly int $timeout;

    public readonly int $connectTimeout;

    public readonly int $retries;

    public readonly int $retryDelay;

    public readonly int $maxPages;

    public readonly string $userAgent;

    /**
     * @param int $timeout Timeout di lettura in secondi (0 = nessun limite).
     * @param int $connectTimeout Timeout di connessione in secondi (0 = nessun limite).
     * @param int $retries Tentativi AGGIUNTIVI oltre al primo. 0 disattiva i retry.
     * @param int $retryDelay Attesa base fra i tentativi in millisecondi; cresce linearmente.
     * @param int $maxPages Limite di sicurezza sulla paginazione automatica.
     * @param string $userAgent Identifica il client nei log del gestionale.
     */
    public function __construct(
        int $timeout = 60,
        int $connectTimeout = 10,
        int $retries = 0,
        int $retryDelay = 250,
        int $maxPages = 1000,
        string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
        $this->timeout = self::assertNotNegative('timeout', $timeout);
        $this->connectTimeout = self::assertNotNegative('connect_timeout', $connectTimeout);
        $this->retries = self::assertNotNegative('retries', $retries);
        $this->retryDelay = self::assertNotNegative('retry_delay', $retryDelay);

        if ($maxPages < 1) {
            throw new ConfigurationException('http.max_pages deve essere almeno 1.');
        }

        $this->maxPages = $maxPages;

        // Lo User-Agent finisce in un header: niente CR/LF, o si aprirebbe una response splitting.
        $this->userAgent = trim(str_replace(["\r", "\n"], '', $userAgent)) ?: self::DEFAULT_USER_AGENT;
    }

    /**
     * Accetta la stessa forma dell'array di configurazione, con chiavi snake_case.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self();

        return new self(
            timeout: self::intOr($config, 'timeout', $defaults->timeout),
            connectTimeout: self::intOr($config, 'connect_timeout', $defaults->connectTimeout),
            retries: self::intOr($config, 'retries', $defaults->retries),
            retryDelay: self::intOr($config, 'retry_delay', $defaults->retryDelay),
            maxPages: self::intOr($config, 'max_pages', $defaults->maxPages),
            userAgent: isset($config['user_agent']) && is_scalar($config['user_agent'])
                ? (string) $config['user_agent']
                : $defaults->userAgent,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        // Le variabili d'ambiente arrivano sempre come stringhe: "60" deve valere 60, ma
        // "sessanta" è un errore di configurazione e non deve diventare silenziosamente 0.
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ConfigurationException("http.$key deve essere un intero, ricevuto: ".get_debug_type($value).'.');
    }

    private static function assertNotNegative(string $key, int $value): int
    {
        if ($value < 0) {
            throw new ConfigurationException("http.$key non può essere negativo.");
        }

        return $value;
    }
}
