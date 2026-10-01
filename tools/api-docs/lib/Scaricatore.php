<?php

declare(strict_types=1);

namespace MexalApiDocs;

use Simonelanini\PhpMexalApi\Http\Connector;

/**
 * Interroga il gestionale (help esteso + ?info=true su ogni collezione) e salva istantanee
 * normalizzate in docs/api/sorgenti.
 *
 * Le istantanee contengono soltanto metadati: quando un endpoint ignora ?info=true e
 * risponde con i dati veri (utenti, aziende, parametri aziendali, ...) se ne conservano i
 * nomi dei campi e il tipo dedotto, mai i valori.
 */
final class Scaricatore
{
    public function __construct(
        private readonly Connector $connector,
        private readonly string $sorgenti,
    ) {
    }

    public function esegui(callable $log): void
    {
        $help = $this->connector->get('risorse/help', ['extended' => 'true']);

        if (! $help->successful()) {
            throw new \RuntimeException("L'help in linea ha risposto {$help->status()}: impossibile proseguire.");
        }

        $gruppi = Modello::gruppiHelp($help->json('risorse'));

        // L'help non verifica le credenziali del gestionale: la prima chiamata che le usa è
        // questa, e deve fermare tutto se vengono rifiutate.
        $risposta = $this->connector->get('risorse/dati-generali/installazione');
        self::fermaSeNonAutenticato($risposta->status(), $risposta->json());
        $installazione = $risposta->json();

        $this->salva('help.json', [
            'generato_il' => date('c'),
            'prodotto' => [
                'codice_prodotto' => $installazione['codice_prodotto'] ?? null,
                'versione_prodotto' => $installazione['versione_prodotto'] ?? null,
            ],
            'risorse' => $gruppi,
        ]);

        $dir = $this->sorgenti.'/info';
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        foreach (glob($dir.'/*.json') ?: [] as $vecchia) {
            unlink($vecchia);
        }

        foreach (Modello::percorsiCollezione($gruppi) as $path) {
            $risposta = $this->connector->get('risorse'.$path, ['info' => 'true']);
            self::fermaSeNonAutenticato($risposta->status(), $risposta->json());
            $istantanea = ['path' => $path] + self::normalizza($risposta->status(), $risposta->json());

            $this->salva('info/'.Modello::slug($path).'.json', $istantanea);
            $log(sprintf('%-52s %s', $path, $istantanea['origine']));

            // Le richieste vanno in sequenza: l'utente WebAPI ha un pool di 5 servizi.
            usleep(100_000);
        }
    }

    /**
     * Ogni tentativo con credenziali errate conta per il blocco dell'utente WebAPI (sul cloud
     * bastano pochi tentativi): al primo rifiuto ci si ferma, senza provare le altre risorse.
     */
    private static function fermaSeNonAutenticato(int $status, mixed $json): void
    {
        $dettaglio = is_array($json) ? (string) ($json['error']['response-detail'] ?? '') : '';

        if ($status === 401 || preg_match('/^(2002|3002)\b/', $dettaglio) === 1) {
            throw new \RuntimeException(
                "Autenticazione rifiutata dal gestionale (HTTP $status): interrotto subito per non "
                .'bloccare l\'utente WebAPI. Verifica connessione e credenziali. '.$dettaglio,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizza(int $status, mixed $json): array
    {
        if ($status !== 200) {
            return [
                'origine' => 'errore',
                'errore' => is_array($json) ? ($json['error']['response-detail'] ?? "HTTP $status") : "HTTP $status",
            ];
        }

        if (! is_array($json)) {
            return ['origine' => 'vuoto'];
        }

        if (array_key_exists('dati', $json) && is_array($json['dati']) && array_is_list($json['dati'])) {
            $righe = $json['dati'];

            if ($righe === []) {
                return ['origine' => 'vuoto'];
            }

            if (self::sonoMetadati($righe)) {
                $campi = array_map(static fn (array $c): array => array_intersect_key($c, array_flip([
                    'nome', 'descrizione', 'tipo', 'dimensione_array', 'chiave', 'obbligatorio_put',
                ])), $righe);

                return ['origine' => 'info', 'forma' => 'dati', 'campi' => $campi];
            }

            return ['origine' => 'campione', 'forma' => 'dati', 'campi' => self::campiDaRecord($righe)];
        }

        // Una sola chiave radice con dentro la lista (es. "Utenti", "Aziende").
        if (count($json) === 1) {
            $radice = (string) array_key_first($json);
            $valore = $json[$radice];

            if (is_array($valore) && array_is_list($valore) && isset($valore[0]) && is_array($valore[0]) && ! array_is_list($valore[0])) {
                return ['origine' => 'campione', 'forma' => 'radice:'.$radice, 'campi' => self::campiDaRecord($valore)];
            }

            // Un oggetto sotto una chiave radice (es. "parametri_aziendali").
            if (is_array($valore) && $valore !== [] && ! array_is_list($valore)) {
                return ['origine' => 'campione', 'forma' => 'radice-oggetto:'.$radice, 'campi' => self::campiDaRecord([$valore])];
            }
        }

        // Formato colonnare (progressivi): i campi sono liste di tuple [indice, valore],
        // affiancate da pochi scalari (num_prog_art, next, data_ric_elab).
        $tupla = static fn ($v): bool => is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0]) && array_is_list($v[0]);

        if (array_filter($json, $tupla) !== []) {
            $campi = [];
            foreach ($json as $nome => $valori) {
                if (in_array($nome, ['next', 'data_ric_elab'], true)) {
                    continue;
                }
                $campi[] = $tupla($valori)
                    ? ['nome' => (string) $nome, 'tipo' => self::tipoColonna($valori), 'dimensione_array' => '0']
                    : ['nome' => (string) $nome, 'tipo' => self::tipo($valori)];
            }

            return ['origine' => 'campione', 'forma' => 'colonnare', 'campi' => $campi];
        }

        return ['origine' => 'campione', 'forma' => 'oggetto', 'campi' => self::campiDaRecord([$json])];
    }

    /**
     * @param list<mixed> $righe
     */
    private static function sonoMetadati(array $righe): bool
    {
        foreach ($righe as $riga) {
            if (! is_array($riga) || ! isset($riga['nome']) || ! in_array($riga['tipo'] ?? null, Modello::TIPI_INFO, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nomi e tipi dedotti, mai i valori. Gli oggetti annidati vengono descritti a loro volta.
     *
     * @param list<mixed> $record
     * @return list<array<string, mixed>>
     */
    private static function campiDaRecord(array $record): array
    {
        $campi = [];

        foreach (array_slice($record, 0, 50) as $r) {
            if (! is_array($r)) {
                continue;
            }
            foreach ($r as $nome => $valore) {
                $campi[$nome] ??= ['nome' => (string) $nome, 'tipo' => self::tipo($valore)];

                // Un importo vale 0 (intero) in un record e 12.5 in un altro: basta un decimale
                // fra i record campionati perché il campo sia decimale.
                if ($campi[$nome]['tipo'] === 'Numerico' && self::tipo($valore) === 'Numerico con virgola') {
                    $campi[$nome]['tipo'] = 'Numerico con virgola';
                }
                if (is_array($valore) && $valore !== [] && ! array_is_list($valore)) {
                    $campi[$nome]['campi'] = self::campiDaRecord([$valore]);
                } elseif (is_array($valore)) {
                    $campi[$nome]['dimensione_array'] = '0';
                }
            }
        }

        return array_values($campi);
    }

    /**
     * Tipo di una colonna [[indice, valore], ...]: decimale se lo è anche un solo valore.
     *
     * @param list<mixed> $tuple
     */
    private static function tipoColonna(array $tuple): string
    {
        $tipi = [];
        foreach ($tuple as $t) {
            $tipi[] = self::tipo(is_array($t) ? end($t) : $t);
        }

        return in_array('Numerico con virgola', $tipi, true) ? 'Numerico con virgola' : ($tipi[0] ?? 'Alfanumerico');
    }

    private static function tipo(mixed $valore): string
    {
        return match (true) {
            is_int($valore) => 'Numerico',
            is_float($valore) => 'Numerico con virgola',
            is_bool($valore) => 'Booleano',
            is_array($valore) && ! array_is_list($valore) => 'Oggetto',
            is_array($valore) && isset($valore[0][1]) => self::tipo($valore[0][1]),
            default => 'Alfanumerico',
        };
    }

    /**
     * @param array<string, mixed> $dati
     */
    private function salva(string $file, array $dati): void
    {
        file_put_contents(
            $this->sorgenti.'/'.$file,
            json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }
}
