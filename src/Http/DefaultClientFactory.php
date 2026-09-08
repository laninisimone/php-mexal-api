<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Http;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

/**
 * Sceglie il client HTTP fra quelli installati, configurandolo per la connessione.
 *
 * Ordine di preferenza: Guzzle, poi Symfony HttpClient, infine la discovery PSR-18
 * generica. I primi due sono privilegiati perché espongono timeout e verifica TLS come
 * opzioni: sono le due impostazioni che il pacchetto deve poter garantire.
 *
 * Se non c'è nessuno dei due e la connessione chiede una postura TLS diversa da quella di
 * default, la costruzione FALLISCE invece di proseguire. È voluto: proseguire
 * significherebbe verificare il certificato quando l'utente ha chiesto di non farlo (errore
 * confuso a runtime) o — molto peggio — non verificarlo quando l'utente ha chiesto di sì.
 * Un fallimento rumoroso al bootstrap è preferibile a una garanzia di sicurezza disattesa
 * in silenzio.
 */
final class DefaultClientFactory implements ClientFactory
{
    /** @var array<string, ClientInterface> */
    private array $clients = [];

    public function createFor(Connection $connection, HttpOptions $options): ClientInterface
    {
        // Un client per connessione: le opzioni TLS e di timeout ci sono cucite dentro,
        // quindi non è riutilizzabile fra connessioni diverse, ma lo è fra chiamate.
        $key = $connection->fingerprint().'|'.$options->timeout.'|'.$options->connectTimeout;

        return $this->clients[$key] ??= $this->build($connection, $options);
    }

    private function build(Connection $connection, HttpOptions $options): ClientInterface
    {
        if (class_exists(GuzzleClient::class)) {
            return $this->guzzle($connection, $options);
        }

        if (class_exists(SymfonyPsr18Client::class) && class_exists(SymfonyHttpClient::class)) {
            return $this->symfony($connection, $options);
        }

        return $this->discovered($connection);
    }

    private function guzzle(Connection $connection, HttpOptions $options): ClientInterface
    {
        return new GuzzleClient([
            'verify' => $connection->verify,
            // In Guzzle 0 significa "nessun limite", che è anche la nostra convenzione.
            'timeout' => $options->timeout,
            'connect_timeout' => $options->connectTimeout,
            // Lo status lo interpreta il Connector: le eccezioni di Guzzle sui 4xx/5xx
            // impedirebbero di leggere l'oggetto "error" del gestionale.
            'http_errors' => false,
            // Un redirect porterebbe l'header Authorization su un host diverso da quello
            // configurato. Il protocollo WebAPI non ne usa: seguirli è solo un rischio.
            'allow_redirects' => false,
        ]);
    }

    private function symfony(Connection $connection, HttpOptions $options): ClientInterface
    {
        $config = [
            'verify_peer' => $connection->verifiesTls(),
            'verify_host' => $connection->verifiesTls(),
            'max_redirects' => 0,
            // In Symfony "timeout" è l'inattività fra due frammenti di risposta e
            // "max_duration" il tetto complessivo: il timeout di connessione mappa sul primo.
            'timeout' => $options->connectTimeout > 0 ? $options->connectTimeout : null,
            'max_duration' => $options->timeout,
        ];

        if (($caBundle = $connection->caBundle()) !== null) {
            $config['cafile'] = $caBundle;
        }

        return new SymfonyPsr18Client(SymfonyHttpClient::create(array_filter(
            $config,
            static fn (mixed $value): bool => $value !== null,
        )));
    }

    private function discovered(Connection $connection): ClientInterface
    {
        // Il client trovato dalla discovery verifica il certificato con le CA di sistema e
        // usa i propri timeout: va bene solo se è esattamente ciò che la connessione chiede.
        if ($connection->verify !== true) {
            throw new ConfigurationException(sprintf(
                "Mexal connection '%s' richiede una configurazione TLS specifica (verify: %s), ma non è "
                .'installato un client HTTP configurabile. Installa guzzlehttp/guzzle oppure '
                .'symfony/http-client, o inietta un client PSR-18 già configurato passando '
                .'$httpClient al costruttore di Mexal.',
                $connection->name,
                is_string($connection->verify) ? "'{$connection->verify}'" : 'false',
            ));
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException $e) {
            throw new ConfigurationException(
                'Nessun client HTTP PSR-18 disponibile. Installa guzzlehttp/guzzle (consigliato) '
                .'oppure symfony/http-client, o inietta il client della tua applicazione.',
                0,
                $e,
            );
        }
    }
}
