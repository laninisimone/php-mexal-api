<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi;

use Simonelanini\PhpMexalApi\Concerns\InteractsWithResponses;
use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Http\Connector;

/**
 * Operazioni di alto livello su una singola connessione + istanza.
 *
 * Tiene il Connector "puro" (solo HTTP e configurazione) separato dalla semantica del
 * gestionale: endpoint di sistema, canale servizi, archivi MyDB e costruzione dei client
 * di risorsa.
 */
final class MexalClient
{
    use InteractsWithResponses;

    public function __construct(private readonly Connector $connector)
    {
    }

    public function connector(): Connector
    {
        return $this->connector;
    }

    /**
     * Client su una risorsa REST.
     *
     * @param bool $raw Salta il prefisso "risorse/": serve solo per i path già completi.
     */
    public function resource(string|MexalResource $name, bool $raw = false): ResourceClient
    {
        return new ResourceClient(
            $name instanceof MexalResource ? $name->value : $name,
            $this->connector,
            $raw,
        );
    }

    /**
     * Archivio MyDB, il cui path è nella forma risorse/mydb/<APP>@<archivio>.
     *
     * @param string $app App Passbuilder e archivio, nella forma APP@archivio. Un valore
     *                    senza "@" viene completato con "@mydb", che è il nome usato
     *                    dagli esempi del manuale.
     * @param string|null $extension Sigla documento (sigla_doc) per gli archivi MyDB collegati a
     *                               documenti o parcelle, es. 'OC'.
     */
    public function mydb(string|MexalResource $app, ?string $extension = null): ResourceClient
    {
        $appName = $app instanceof MexalResource ? $app->value : $app;
        $archivio = str_contains($appName, '@') ? $appName : $appName.'@mydb';
        $path = 'risorse/mydb/'.$archivio;

        if ($extension !== null && $extension !== '') {
            $path .= '/'.$extension;
        }

        return new ResourceClient($path, $this->connector, rawPath: true);
    }

    /**
     * Dati dell'installazione: versione, moduli attivi, configurazione del servizio.
     *
     * @return array<array-key, mixed>
     */
    public function installazione(): array
    {
        return $this->throwUnlessStatus(
            $this->connector->get(['risorse', 'dati-generali', 'installazione']),
            200,
        )->jsonObject('risorse/dati-generali/installazione');
    }

    /**
     * @return list<mixed>
     */
    public function utenti(): array
    {
        // Questo endpoint è l'eccezione: la chiave root è "Utenti", non "dati".
        return $this->throwUnlessStatus(
            $this->connector->get('risorse/dati-generali/utenti'),
            200,
        )->records('Utenti');
    }

    /**
     * Elenco degli endpoint esposti dall'installazione. Con $extended la risposta riporta
     * anche supporto alla paginazione, encoding e chiavi di ciascun endpoint.
     *
     * Insieme a ResourceClient::info() è il riferimento sempre allineato alla versione del
     * gestionale in uso, più affidabile di qualsiasi elenco statico (enum compresa).
     *
     * @return list<mixed>
     */
    public function help(bool $extended = false): array
    {
        return $this->throwUnlessStatus(
            $this->connector->get('risorse/help', $extended ? ['extended' => 'true'] : []),
            200,
        )->records('risorse');
    }

    /**
     * Invoca un servizio del canale /servizi, seguendo la paginazione quando presente.
     *
     * Il canale non è RESTful: l'operazione si sceglie con il campo "cmd" e i parametri
     * vanno in "dati". Se la risposta non è JSON (stampe PDF, allegati multipart) viene
     * restituito il body grezzo; se è JSON viene aggregato il contenuto di "dati" di ogni
     * pagina, e i servizi che rispondono con un oggetto singolo lo restituiscono come unico
     * elemento della lista.
     *
     * @param string $cmd Nome del comando (campo "cmd")
     * @param array<string, mixed> $dati Parametri del servizio (campo "dati")
     * @param string|null $contentType Riceve il Content-Type dell'ultima risposta
     * @param-out string           $contentType
     * @param int|null $maxPages Limite di sicurezza sulla paginazione
     * @param array<string, mixed> $root Parametri che il servizio vuole al livello di "cmd"
     *                                   invece che dentro "dati": è il caso di
     *                                   esec_collage_server_remoto (codice_app,
     *                                   nome_collage, etichetta_collage),
     *                                   get_prog_ubicazioni (cod_art_progr, dettlotti) e
     *                                   dei "campi"/"filtri" di lista_docdv.
     * @return list<mixed>|string
     */
    public function servizi(
        string $cmd,
        array $dati = [],
        ?string &$contentType = null,
        ?int $maxPages = null,
        array $root = [],
    ): array|string {
        $limit = $maxPages ?? $this->connector->options()->maxPages;
        $aggregate = [];
        $next = null;
        $page = 0;

        do {
            if (++$page > $limit) {
                throw new ProtocolException("Limite di {$limit} pagine superato sul servizio '{$cmd}'.");
            }

            $response = $this->throwUnlessStatus(
                $this->connector->post(
                    'servizi',
                    $this->serviziBody($cmd, $dati, $root, $next),
                    // Accept permissivo: lo stesso canale restituisce JSON, PDF e multipart.
                    accept: '*/*',
                ),
                200,
            );

            $contentType = $response->contentType();

            if (! $response->isJson()) {
                return $response->body();
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];

            $records = $payload['dati'] ?? null;

            // Un servizio che risponde con un oggetto singolo (senza "dati") va comunque
            // consegnato: diventa l'unico elemento della lista, così il chiamante ha
            // sempre lo stesso tipo di ritorno.
            $aggregate = array_merge($aggregate, is_array($records) ? array_values($records) : [$payload]);

            $next = $this->nextToken($payload['next'] ?? null);
        } while ($next !== null);

        return $aggregate;
    }

    /**
     * Il canale servizi vuole il token di paginazione nel body, allo stesso livello di "cmd",
     * e non in query string come fanno invece gli endpoint REST.
     *
     * "dati" viene omesso quando è vuoto: diversi servizi non lo prevedono affatto, e un
     * array PHP vuoto finirebbe serializzato come [] invece che come oggetto JSON.
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private function serviziBody(string $cmd, array $dati, array $root, ?string $next): array
    {
        // cmd, dati e next sono il protocollo del canale: i parametri del singolo servizio
        // non devono poterli sovrascrivere.
        $reserved = ['cmd' => true, 'dati' => true, 'next' => true];
        $body = array_merge(['cmd' => $cmd], array_diff_key($root, $reserved));

        if ($next !== null) {
            $body['next'] = $next;
        }

        if ($dati !== []) {
            $body['dati'] = $dati;
        }

        return $body;
    }
}
