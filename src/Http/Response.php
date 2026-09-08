<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Http;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;

/**
 * Involucro sottile sulla risposta PSR-7, con i pochi accessori che servono a leggere le
 * risposte del gestionale.
 *
 * Esiste per due motivi: non far entrare la PSR-7 nella superficie pubblica del pacchetto
 * (chi lo usa non deve conoscere gli stream), e leggere il body una sola volta — molti
 * stream PSR-7 non sono riavvolgibili e una seconda lettura tornerebbe vuota.
 */
final class Response
{
    private ?string $body = null;

    private mixed $decoded = null;

    private bool $decodedResolved = false;

    /**
     * @param string $method Metodo della richiesta che ha prodotto questa risposta.
     * @param string $uri URI della richiesta. Non contiene mai credenziali: l'autenticazione
     *                    viaggia negli header, quindi è sicuro riportarlo in log ed eccezioni.
     */
    public function __construct(
        private readonly ResponseInterface $psr,
        private readonly string $method = '',
        private readonly string $uri = '',
    ) {
    }

    /** Metodo HTTP della richiesta originale, stringa vuota se non tracciato. */
    public function method(): string
    {
        return $this->method;
    }

    /** URI della richiesta originale, stringa vuota se non tracciato. */
    public function uri(): string
    {
        return $this->uri;
    }

    public function status(): int
    {
        return $this->psr->getStatusCode();
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /** Primo valore dell'header, null se assente. */
    public function header(string $name): ?string
    {
        $values = $this->psr->getHeader($name);

        return $values === [] ? null : $values[0];
    }

    /** Content-Type normalizzato a stringa (vuota se l'header manca). */
    public function contentType(): string
    {
        return $this->header('Content-Type') ?? '';
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->contentType()), 'application/json');
    }

    public function body(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $stream = $this->psr->getBody();

        // Uno stream già consumato altrove torna vuoto: se è riavvolgibile lo si riporta
        // all'inizio, così la lettura funziona anche se qualcuno lo ha ispezionato prima.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $this->body = $this->decompress($stream->getContents());
    }

    /**
     * Body decodificato. Con $key restituisce la sola chiave di primo livello richiesta
     * ("dati", "next", "error"), che è tutto ciò che il protocollo Mexal prevede.
     *
     * Torna null quando il body non è JSON valido: le risposte di errore possono essere
     * HTML di un proxy o vuote, e un client che le incontra deve poterlo scoprire senza
     * gestire un'eccezione.
     */
    public function json(?string $key = null): mixed
    {
        if (! $this->decodedResolved) {
            $this->decodedResolved = true;

            try {
                $this->decoded = $this->body() === ''
                    ? null
                    : json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->decoded = null;
            }
        }

        if ($key === null) {
            return $this->decoded;
        }

        return is_array($this->decoded) ? ($this->decoded[$key] ?? null) : null;
    }

    /**
     * Come json(), ma pretende un oggetto JSON: si usa dove il manuale ne garantisce uno e
     * riceverne altro è un errore di protocollo, non un dato mancante.
     *
     * @return array<array-key, mixed>
     */
    public function jsonObject(string $context = ''): array
    {
        $decoded = $this->json();

        if (! is_array($decoded)) {
            throw new ProtocolException(sprintf(
                'Risposta non interpretabile come JSON%s: Content-Type "%s", status %d.',
                $context === '' ? '' : " da $context",
                $this->contentType(),
                $this->status(),
            ));
        }

        return $decoded;
    }

    /**
     * Lista di record sotto una chiave ("dati" per gli endpoint REST). Un valore assente
     * vale lista vuota: il gestionale omette "dati" quando non ci sono record.
     *
     * @return list<mixed>
     */
    public function records(string $key = 'dati'): array
    {
        $value = $this->json($key);

        return is_array($value) ? array_values($value) : [];
    }

    /** La risposta PSR-7 originale, per i casi non coperti da questi accessori. */
    public function psr(): ResponseInterface
    {
        return $this->psr;
    }

    /**
     * Rete di sicurezza sul gzip.
     *
     * Con Accept-Encoding: gzip impostato a mano, alcuni client PSR-18 consegnano il body
     * ancora compresso invece di decomprimerlo (curl decodifica solo l'encoding che ha
     * negoziato lui). Si decomprime solo se l'header lo dichiara E i magic bytes lo
     * confermano: così un body già decompresso dal client non viene toccato.
     */
    private function decompress(string $body): string
    {
        if ($body === '' || ! str_contains(strtolower($this->header('Content-Encoding') ?? ''), 'gzip')) {
            return $body;
        }

        if (substr($body, 0, 2) !== "\x1f\x8b" || ! function_exists('gzdecode')) {
            return $body;
        }

        $decoded = @gzdecode($body);

        return $decoded === false ? $body : $decoded;
    }
}
