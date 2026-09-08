<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi;

use Generator;
use Simonelanini\PhpMexalApi\Concerns\InteractsWithResponses;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Http\Connector;
use Simonelanini\PhpMexalApi\Http\Response;

/**
 * Client su una singola risorsa Mexal: CRUD, ricerca, sotto-risorse, allegati e
 * paginazione automatica.
 *
 * Restituisce array e Generator invece di collection di un framework: chi usa Laravel può
 * sempre avvolgerli in collect(), chi non lo usa non si porta dietro una dipendenza.
 */
final class ResourceClient
{
    use InteractsWithResponses;

    private readonly string $resourcePath;

    /**
     * @param bool $rawPath Se false il path viene prefissato con "risorse/".
     * @param array<string, string> $defaultParams Query string sempre inviata dalla risorsa: la usa
     *                                             sub() per propagare l'encoding di un codice che
     *                                             sta in mezzo al path e non solo in fondo.
     */
    public function __construct(
        string $resourcePath,
        private readonly Connector $connector,
        bool $rawPath = false,
        private readonly array $defaultParams = [],
    ) {
        $this->resourcePath = $rawPath
            ? trim($resourcePath, '/')
            : 'risorse/'.trim($resourcePath, '/');
    }

    /** Percorso completo della risorsa, utile per comporre chiamate diverse dal CRUD standard. */
    public function path(): string
    {
        return $this->resourcePath;
    }

    public function connector(): Connector
    {
        return $this->connector;
    }

    /**
     * Metadati dell'endpoint (GET ?info=true): campi disponibili, tipi, obbligatorietà.
     * Insieme a MexalClient::help() è la documentazione sempre allineata alla versione del
     * gestionale in uso — le WebAPI non espongono uno schema OpenAPI.
     *
     * @return list<mixed>
     */
    public function info(): array
    {
        return $this->request(['info' => 'true'])->records();
    }

    /**
     * Legge l'intera collezione seguendo la catena dei "next".
     *
     * @param array<string, mixed> $params Query string (fields, max, ...)
     * @return list<mixed>
     */
    public function list(array $params = [], ?int $maxPages = null): array
    {
        return iterator_to_array($this->cursor($params, $maxPages), false);
    }

    /**
     * Come list(), ma senza tenere in memoria tutte le pagine insieme: i record vengono
     * consegnati man mano che arrivano, e le pagine successive non vengono nemmeno
     * richieste finché il ciclo non le raggiunge.
     *
     * È la via da preferire sugli archivi grossi, dove il gestionale pagina ogni 30 secondi
     * o 50 MB e l'intera collezione non sta comoda in RAM.
     *
     * @param array<string, mixed> $params
     * @return Generator<int, mixed>
     */
    public function cursor(array $params = [], ?int $maxPages = null): Generator
    {
        return $this->paginate(
            // paginate() ha già composto la query completa: qui non va rifatto il merge.
            fn (array $query): Response => $this->connector->get($this->resourcePath, $query),
            $params,
            $maxPages,
        );
    }

    /**
     * Ricerca con filtri nel body (POST /ricerca). I filtri sono in AND fra loro; più valori
     * nello stesso filtro sono in OR. La paginazione resta in query string come sulla GET.
     *
     * @param array<string, mixed> $body Es. ['filtri' => [['campo' => ..., 'condizione' => ..., 'valore' => ...]]]
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    public function search(array $body, array $params = [], ?int $maxPages = null): array
    {
        return iterator_to_array($this->searchCursor($body, $params, $maxPages), false);
    }

    /**
     * Variante lazy di search(), con gli stessi vantaggi di cursor() sui risultati corposi.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     * @return Generator<int, mixed>
     */
    public function searchCursor(array $body, array $params = [], ?int $maxPages = null): Generator
    {
        return $this->paginate(
            fn (array $query): Response => $this->connector->post(
                $this->resourcePath.'/ricerca',
                $body,
                $query,
                // /ricerca legge soltanto: ripeterla dopo un errore di rete è sicuro.
                idempotent: true,
            ),
            $params,
            $maxPages,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    public function get(string $id, array $params = []): array
    {
        [$segment, $encoding] = self::encodeId($id);

        return $this->fetch($segment, array_merge($params, $encoding));
    }

    /**
     * Crea la risorsa e ne restituisce il contenuto, rileggendola all'indirizzo indicato
     * dal gestionale nell'header Location.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    public function create(array $body, array $params = []): array
    {
        [$segment, $locationParams] = $this->createAndLocate($body, $params);

        return $this->fetch($segment, $locationParams);
    }

    /**
     * Crea la risorsa e ne restituisce il solo codice, senza la rilettura che create()
     * comporta: una chiamata HTTP invece di due quando il contenuto non serve.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     */
    public function createAndGetId(array $body, array $params = []): string
    {
        [$segment, $locationParams] = $this->createAndLocate($body, $params);

        return self::decodeId($segment, $locationParams);
    }

    /**
     * In revisione il gestionale può controllare la data di ultima modifica: rimanda nel body
     * il campo data_ult_mod letto dalla GET, altrimenti una modifica concorrente andrebbe persa.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params Query string aggiuntiva (es. solo_testata=true)
     */
    public function update(string $id, array $body, array $params = []): bool
    {
        [$segment, $encoding] = self::encodeId($id);

        $this->throwUnlessStatus(
            $this->connector->put(
                $this->resourcePath.'/'.$segment,
                $body,
                $this->query(array_merge($params, $encoding)),
            ),
            204,
        );

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function delete(string $id, array $params = []): bool
    {
        [$segment, $encoding] = self::encodeId($id);

        $this->throwUnlessStatus(
            $this->connector->delete(
                $this->resourcePath.'/'.$segment,
                $this->query(array_merge($params, $encoding)),
            ),
            204,
        );

        return true;
    }

    /**
     * Sotto-risorsa di un singolo record, es. articoli/{codice}/progressivi oppure
     * articoli/{codice}/abbinati. L'eventuale encoding del codice viene propagato, perché
     * riguarda l'intero path e non solo il segmento finale.
     */
    public function sub(string $id, string $path): self
    {
        [$segment, $encoding] = self::encodeId($id);

        return new self(
            $this->resourcePath.'/'.$segment.'/'.trim($path, '/'),
            $this->connector,
            rawPath: true,
            defaultParams: array_merge($this->defaultParams, $encoding),
        );
    }

    /**
     * Allegato binario di un record (icona, immagine-catalogo, immagine per gli articoli).
     *
     * @param string|null $contentType Riceve il Content-Type della risposta (image/jpeg, application/pdf, ...)
     * @param-out string      $contentType
     */
    public function allegato(string $id, string $tipo, ?string &$contentType = null): string
    {
        [$segment, $encoding] = self::encodeId($id);

        // Un allegato torna binario: chiedere Accept: application/json lo escluderebbe.
        $response = $this->throwUnlessStatus(
            $this->connector->get(
                $this->resourcePath.'/'.$segment.'/allegati/'.rawurlencode($tipo),
                $this->query($encoding),
                '*/*',
            ),
            200,
        );

        $contentType = $response->contentType();

        return $response->body();
    }

    /**
     * Segue la catena dei "next" restituendo un record alla volta.
     *
     * I record vengono emessi senza chiave (`yield $record` e non `yield $k => $record`):
     * altrimenti gli indici della seconda pagina sovrascriverebbero quelli della prima nel
     * momento in cui il generatore viene materializzato in array.
     *
     * @param callable(array<string, mixed>): Response $request
     * @param array<string, mixed> $params
     * @return Generator<int, mixed>
     */
    private function paginate(callable $request, array $params, ?int $maxPages): Generator
    {
        $limit = $maxPages ?? $this->connector->options()->maxPages;
        $query = $this->query($params);
        $page = 0;

        do {
            if (++$page > $limit) {
                // Un tetto esiste perché un "next" che non si esaurisce mai — bug del
                // gestionale o filtro che rigenera record — farebbe girare il ciclo
                // all'infinito consumando il pool di servizi.
                throw new ProtocolException(
                    "Limite di {$limit} pagine superato leggendo '{$this->resourcePath}'.",
                );
            }

            $response = $this->throwUnlessStatus($request($query), 200);

            foreach ($response->records() as $record) {
                yield $record;
            }

            $next = $this->nextToken($response->json('next'));

            if ($next !== null) {
                $query['next'] = $next;
            }
        } while ($next !== null);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params = []): Response
    {
        return $this->throwUnlessStatus(
            $this->connector->get($this->resourcePath, $this->query($params)),
            200,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function fetch(string $segment, array $params = []): array
    {
        return $this->throwUnlessStatus(
            $this->connector->get($this->resourcePath.'/'.$segment, $this->query($params)),
            200,
        )->jsonObject($this->resourcePath.'/'.$segment);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function query(array $params = []): array
    {
        return array_merge($this->defaultParams, $params);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, string>}
     */
    private function createAndLocate(array $body, array $params): array
    {
        $response = $this->throwUnlessStatus(
            $this->connector->post($this->resourcePath, $body, $this->query($params)),
            201,
        );

        return self::parseLocation($response->header('Location'))
            ?? throw new ProtocolException(
                "Impossibile determinare la risorsa creata su '{$this->resourcePath}': "
                .'header Location assente o non interpretabile.',
            );
    }

    /**
     * L'header Location può contenere una query string (tipicamente encoding=hex quando il
     * codice contiene slash o backslash): va quindi letto con parse_url e non con basename,
     * che si porterebbe dietro anche la query string dentro il codice risorsa.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    private static function parseLocation(?string $location): ?array
    {
        if ($location === null || trim($location) === '') {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $separator = strrpos($path, '/');
        $segment = $separator === false ? $path : substr($path, $separator + 1);

        if ($segment === '') {
            return null;
        }

        $query = parse_url($location, PHP_URL_QUERY);
        $parsed = [];

        if (is_string($query) && $query !== '') {
            parse_str($query, $parsed);
        }

        // parse_str produce chiavi int per parametri tipo "0=x": si normalizzano a stringa
        // perché finiscono in una query string, dove le chiavi sono sempre testuali. I
        // valori non scalari (array annidati) non hanno senso qui e vengono scartati.
        $params = [];
        foreach ($parsed as $key => $value) {
            if (is_scalar($value)) {
                $params[(string) $key] = (string) $value;
            }
        }

        return [$segment, $params];
    }

    /**
     * Prepara il codice per il path. I codici che contengono slash o backslash non possono
     * essere url-encodati (il gestionale li rifiuta comunque) e vanno inviati codificati in
     * esadecimale, dichiarando la codifica in query string.
     *
     * Il rawurlencode() sugli altri codici non è solo cosmetico: impedisce che un codice
     * contenente "?", "#" o "%" alteri la struttura dell'URL.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function encodeId(string $id): array
    {
        if (str_contains($id, '/') || str_contains($id, '\\')) {
            return [bin2hex($id), ['encoding' => 'hex']];
        }

        return [rawurlencode($id), []];
    }

    /**
     * Riporta al codice originale il segmento restituito dal gestionale, così da poterlo
     * riutilizzare con get(), update() e delete(). Nell'header Location la sola codifica
     * impiegata è hex.
     *
     * @param array<string, string> $params
     */
    private static function decodeId(string $segment, array $params): string
    {
        $isHex = ($params['encoding'] ?? null) === 'hex'
            && $segment !== ''
            && strlen($segment) % 2 === 0
            && ctype_xdigit($segment);

        if ($isHex && ($decoded = hex2bin($segment)) !== false) {
            return $decoded;
        }

        return rawurldecode($segment);
    }
}
