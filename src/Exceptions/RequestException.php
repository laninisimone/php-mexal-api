<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Exceptions;

use RuntimeException;
use Simonelanini\PhpMexalApi\Enums\MexalErrorCode;
use Simonelanini\PhpMexalApi\Http\Response;

/**
 * Il gestionale ha risposto, ma con uno status diverso da quello atteso.
 *
 * Il manuale WebAPI descrive un oggetto "error" con la diagnostica dell'errore; questa
 * classe lo espone campo per campo invece di lasciare al chiamante il parsing di una
 * stringa nella forma "<codice> - <messaggio> [<dettaglio>] [<dettaglio>]".
 *
 * Il messaggio dell'eccezione riporta il response-detail per intero: è lì che il
 * gestionale scrive la causa reale del rifiuto, e troncarlo rende l'errore inutile in log.
 *
 * Riferimento: manuale WebAPI 3.1, "Gestione Errori" e appendice "Codici di Errore".
 */
final class RequestException extends RuntimeException implements MexalException
{
    /** @var array<string, mixed> */
    private array $error;

    /**
     * @param array<string, mixed> $error
     */
    private function __construct(
        string $message,
        private readonly Response $response,
        array $error,
    ) {
        // Lo status HTTP diventa il code dell'eccezione: è la convenzione più diffusa fra i
        // client HTTP e permette un match veloce (404, 401, 503...) senza ispezionare la risposta.
        parent::__construct($message, $response->status());

        $this->error = $error;
    }

    public static function from(Response $response): self
    {
        $error = self::extractError($response);

        return new self(
            self::buildMessage($response, $error, $response->method(), $response->uri()),
            $response,
            $error,
        );
    }

    /** La risposta completa, per i casi in cui serva il body o un header specifico. */
    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * Oggetto "error" completo. Vuoto quando il gestionale non ne restituisce uno: è il caso
     * dei 404, che il manuale documenta come risposte prive di corpo JSON strutturato.
     *
     * @return array<string, mixed>
     */
    public function error(): array
    {
        return $this->error;
    }

    /** Campo "response-detail" grezzo: "<codice> - <messaggio> [<dettaglio>]". */
    public function detail(): ?string
    {
        return $this->stringField('response-detail');
    }

    /** Codice numerico applicativo in testa al detail (1001, 3002, 5001, 6001, ...). */
    public function errorCode(): ?int
    {
        return self::splitDetail($this->detail())['code'];
    }

    /** Famiglia dell'errore, quando il codice è fra quelli documentati dal manuale. */
    public function errorFamily(): ?MexalErrorCode
    {
        $code = $this->errorCode();

        return $code === null ? null : MexalErrorCode::tryFrom($code);
    }

    /** Descrizione dell'errore, senza il codice e senza i dettagli fra parentesi quadre. */
    public function reason(): ?string
    {
        return self::splitDetail($this->detail())['reason'];
    }

    /**
     * Dettagli fra parentesi quadre, dal più generico al più specifico. È qui che il
     * gestionale scrive la causa vera ("Aliquota iva obbligatoria", "pool esaurito", ...).
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return self::splitDetail($this->detail())['hints'];
    }

    /** UUID della richiesta, da citare al supporto tecnico per rintracciarla nei log. */
    public function requestId(): ?string
    {
        return $this->stringField('request-id');
    }

    public function requestUri(): ?string
    {
        return $this->stringField('request-uri');
    }

    public function requestMethod(): ?string
    {
        return $this->stringField('request-method');
    }

    /** Data e ora del server gestionale, nel formato dd/MM/yyyy HH:mm:ss. */
    public function serverTimestamp(): ?string
    {
        return $this->stringField('timestamp');
    }

    /**
     * Vero quando ritentare la stessa richiesta ha una possibilità concreta di riuscire:
     * indisponibilità temporanea (5xx), timeout intermedio (408) o rate limit (429).
     * Un 4xx applicativo non cambia esito e non va ritentato.
     */
    public function isRetryable(): bool
    {
        return $this->response->serverError()
            || in_array($this->status(), [408, 429], true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractError(Response $response): array
    {
        // Una risposta di errore può benissimo non essere JSON: 404 senza body, gateway
        // intermedi che rispondono HTML, servizi che rispondono binario. json() torna null
        // in quei casi e non deve far esplodere la costruzione dell'eccezione.
        $error = $response->json('error');

        return is_array($error) ? $error : [];
    }

    /**
     * @param array<string, mixed> $error
     */
    private static function buildMessage(Response $response, array $error, string $method, string $uri): string
    {
        $detail = $error['response-detail'] ?? null;
        $status = $response->status();

        if (! is_string($detail) || $detail === '') {
            // Nessun oggetto "error": si ripiega sul contesto della richiesta, che il
            // chiamante ha comunque bisogno di vedere in log. Il body NON viene incluso:
            // potrebbe essere enorme, binario, o contenere dati dell'anagrafica.
            $context = trim($method.' '.$uri);

            return $context === ''
                ? sprintf('Richiesta al gestionale fallita con status HTTP %d.', $status)
                : sprintf('Richiesta %s fallita con status HTTP %d.', $context, $status);
        }

        $message = sprintf('[%d] %s', $status, $detail);

        // Preferisci il contesto restituito dal gestionale a quello locale: coincidono, ma
        // quello del gestionale è ciò che il supporto tecnico ritrova nei propri log.
        $errorMethod = is_string($error['request-method'] ?? null) ? $error['request-method'] : $method;
        $errorUri = is_string($error['request-uri'] ?? null) ? $error['request-uri'] : $uri;

        if ($errorMethod !== '' && $errorUri !== '') {
            $message .= sprintf(' · %s %s', $errorMethod, $errorUri);
        }

        $requestId = $error['request-id'] ?? null;
        if (is_string($requestId) && $requestId !== '') {
            $message .= sprintf(' · request-id %s', $requestId);
        }

        return $message;
    }

    /**
     * Scompone "6001 - errore gestionale [Aliquota iva obbligatoria]" nelle sue tre parti.
     *
     * @return array{code: int|null, reason: string|null, hints: list<string>}
     */
    private static function splitDetail(?string $detail): array
    {
        $empty = ['code' => null, 'reason' => null, 'hints' => []];

        if ($detail === null || preg_match('/^\s*(\d+)\s*-\s*(.*)$/s', $detail, $matches) !== 1) {
            return $empty;
        }

        $rest = $matches[2];

        // I dettagli sono annidati al più di un livello nel manuale: una regex sui gruppi
        // non annidati è sufficiente e non rischia backtracking catastrofico su input lunghi.
        preg_match_all('/\[([^\[\]]*)\]/', $rest, $brackets);
        $reason = trim((string) preg_replace('/\[[^\[\]]*\]/', '', $rest));

        return [
            'code' => (int) $matches[1],
            'reason' => $reason === '' ? null : $reason,
            'hints' => array_values(array_filter(
                array_map('trim', $brackets[1]),
                static fn (string $hint): bool => $hint !== '',
            )),
        ];
    }

    private function stringField(string $key): ?string
    {
        $value = $this->error[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
