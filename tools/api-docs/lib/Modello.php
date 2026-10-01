<?php

declare(strict_types=1);

namespace MexalApiDocs;

use RuntimeException;

/**
 * Modello unico delle risorse, da cui escono sia gli OpenAPI sia il catalogo per l'AI.
 *
 * Fonti, in ordine di affidabilità:
 *   1. help?extended=true      endpoint, metodi, chiavi, paginazione, filtri
 *   2. ?info=true              campi, tipi, chiavi, obbligatorietà in revisione
 *   3. manuale WebAPI v3.1     campi obbligatori in inserimento, parametri speciali, note,
 *                              strutture delle risorse che sull'azienda interrogata non
 *                              rispondono a ?info=true (moduli di produzione non attivi)
 *   4. relazioni.json          a quale archivio appartengono i codici (curato a mano)
 */
final class Modello
{
    /** Tipi con cui ?info=true descrive i campi. */
    public const TIPI_INFO = [
        'Alfanumerico',
        'Numerico',
        'Numerico con virgola',
        'Data e ora (formato AAAAMMGG HHMMSS)',
    ];

    public const OPERAZIONI = ['lista', 'cerca', 'leggi', 'crea', 'modifica', 'elimina'];

    /**
     * Sotto-risorse che espongono gli stessi campi di un'altra collezione, per path
     * normalizzato: l'help chiama il codice articolo ora "codice", ora "cod_articolo".
     */
    private const ALIAS_CAMPI = [
        '/articoli/{}/abbinati' => '/articoli-abbinati',
        '/articoli/{}/progressivi' => '/progressivi-articoli',
    ];

    /** Operazioni che il manuale documenta una volta sola per più path. */
    private const ALIAS_MANUALE = [
        '/mydb/{}/{}' => '/mydb/{}',
    ];

    /** @var array<string, array<string, mixed>> per id risorsa (path della collezione senza "/") */
    public array $risorse = [];

    /** @var array<string, list<string>> gruppo dell'help => id risorse */
    public array $gruppi = [];

    /** @var array<string, mixed> */
    public array $prodotto = [];

    public string $generatoIl = '';

    /** @var list<array<string, mixed>> */
    public array $servizi = [];

    /** @var array<string, array<string, mixed>> operazioni del manuale per "METODO path_norm" */
    private array $manuale = [];

    /** @var array<string, array<string, mixed>> */
    private array $strutture = [];

    public static function carica(string $sorgenti): self
    {
        $leggi = static function (string $file): array {
            if (! is_file($file)) {
                throw new RuntimeException("File mancante: $file");
            }

            return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        };

        $help = $leggi($sorgenti.'/help.json');
        $manuale = $leggi($sorgenti.'/manuale.json');
        $relazioni = $leggi($sorgenti.'/relazioni.json');
        $descrizioni = $leggi($sorgenti.'/descrizioni.json');

        $istantanee = [];
        foreach (glob($sorgenti.'/info/*.json') ?: [] as $file) {
            $i = $leggi($file);
            $istantanee[$i['path']] = $i;
        }

        $m = new self();
        $m->prodotto = $help['prodotto'] ?? [];
        $m->generatoIl = (string) ($help['generato_il'] ?? '');

        foreach ($manuale['operazioni'] as $op) {
            $m->manuale[$op['metodo'].' '.$op['path_norm']] ??= $op;
        }
        $m->strutture = $manuale['strutture'];

        $m->costruisciRisorse(self::gruppiHelp($help['risorse'] ?? null));
        $m->assegnaCampi($istantanee);
        $m->collegaTestateRighe();
        $m->campiSoloInserimento();
        $m->applicaRelazioni($relazioni);
        $m->applicaDescrizioni($descrizioni['risorse'] ?? []);
        $m->servizi = self::servizi($manuale['servizi']);

        return $m;
    }

    /**
     * Collezioni senza parametri nel path: sono quelle su cui ha senso chiedere ?info=true.
     *
     * @param list<array<string, list<array<string, mixed>>>> $gruppi
     * @return list<string>
     */
    public static function percorsiCollezione(array $gruppi): array
    {
        $percorsi = [];

        foreach (self::endpoint($gruppi) as $op) {
            if (! str_contains($op['collezione'], '{')) {
                $percorsi[$op['collezione']] = true;
            }
        }

        return array_keys($percorsi);
    }

    /**
     * Verifica la forma della risposta dell'help ("risorse": [{gruppo: [endpoint, ...]}, ...])
     * prima di usarla: è JSON esterno, e un cambio di formato deve fermare la generazione
     * invece di produrre documenti sbagliati.
     *
     * @return list<array<string, list<array<string, mixed>>>>
     */
    public static function gruppiHelp(mixed $risorse): array
    {
        if (! is_array($risorse) || ! array_is_list($risorse)) {
            throw new RuntimeException("L'help non contiene la lista 'risorse' attesa.");
        }

        $gruppi = [];

        foreach ($risorse as $gruppo) {
            if (! is_array($gruppo)) {
                throw new RuntimeException("Gruppo dell'help non valido.");
            }

            $tipizzato = [];
            foreach ($gruppo as $nome => $endpoints) {
                if (! is_array($endpoints) || ! array_is_list($endpoints)) {
                    throw new RuntimeException("Endpoint del gruppo '$nome' non validi.");
                }

                $lista = [];
                foreach ($endpoints as $e) {
                    if (! is_array($e) || ! isset($e['regexp'], $e['method'], $e['descrizione'])) {
                        throw new RuntimeException("Endpoint senza regexp, method o descrizione nel gruppo '$nome'.");
                    }

                    $endpoint = [];
                    foreach ($e as $chiave => $valore) {
                        $endpoint[(string) $chiave] = $valore;
                    }
                    $lista[] = $endpoint;
                }

                $tipizzato[(string) $nome] = $lista;
            }

            $gruppi[] = $tipizzato;
        }

        return $gruppi;
    }

    public static function slug(string $path): string
    {
        return str_replace(['/', '{', '}'], ['__', '', ''], trim($path, '/'));
    }

    /** "documenti/ordini-clienti" => "DocumentiOrdiniClienti" */
    public static function nomeSchema(string $id): string
    {
        $parti = preg_split('/[^A-Za-z0-9]+/', str_replace(['{', '}'], '', $id)) ?: [];

        return implode('', array_map(static fn (string $p): string => ucfirst($p), array_filter($parti)));
    }

    /** "documenti/ordini-clienti" => "documenti_ordini_clienti" */
    public static function nomeOperazione(string $id): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(str_replace(['{', '}'], '', $id))), '_');
    }

    // ------------------------------------------------------------------ endpoint

    /**
     * Ogni endpoint dell'help, espanso nelle sue varianti (segmenti opzionali) e classificato.
     *
     * @param list<array<string, list<array<string, mixed>>>> $gruppi
     * @return list<array<string, mixed>>
     */
    private static function endpoint(array $gruppi): array
    {
        $out = [];

        foreach ($gruppi as $gruppo) {
            foreach ($gruppo as $nomeGruppo => $endpoints) {
                foreach ($endpoints as $e) {
                    foreach (self::varianti($e) as [$template, $parametri]) {
                        $out[] = self::classifica((string) $nomeGruppo, $e, $template, $parametri);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $e
     * @param list<array<string, mixed>> $parametri
     * @return array<string, mixed>
     */
    private static function classifica(string $gruppo, array $e, string $template, array $parametri): array
    {
        $segmenti = explode('/', $template);
        $ultimo = end($segmenti);
        $segmentoChiave = preg_match('/^\{[^}]+\}(\+\{[^}]+\})*$/', $ultimo) === 1;
        $senzaChiave = substr($template, 0, (int) strrpos($template, '/'));

        [$tipo, $collezione] = match (true) {
            str_ends_with($template, '/ricerca') => ['cerca', substr($template, 0, -strlen('/ricerca'))],
            $e['method'] === 'POST' => ['crea', $template],
            $e['method'] === 'GET' && ($e['next'] || $e['max'] || ! $segmentoChiave) => ['lista', $template],
            $e['method'] === 'GET' => ['leggi', $senzaChiave],
            $e['method'] === 'PUT' => ['modifica', $senzaChiave],
            default => ['elimina', $senzaChiave],
        };

        $nomiCollezione = [];
        preg_match_all('/\{([^}]+)\}/', $collezione, $match);
        foreach ($match[1] as $n) {
            $nomiCollezione[$n] = true;
        }

        return [
            'gruppo' => $gruppo,
            'metodo' => $e['method'],
            'tipo' => $tipo,
            'path' => $template,
            'collezione' => $collezione,
            'parametri_path' => array_values(array_filter($parametri, static fn ($p) => isset($nomiCollezione[$p['nome']]))),
            'chiave' => array_values(array_filter($parametri, static fn ($p) => ! isset($nomiCollezione[$p['nome']]))),
            'descrizione' => trim((string) preg_replace('/\s*\((resource|collection|collezione)\)\s*/i', ' ', (string) $e['descrizione'])),
            'versione' => (string) ($e['versione'] ?? ''),
            'flag' => [
                'next' => (bool) $e['next'],
                'max' => (bool) $e['max'],
                'fields' => (bool) $e['fields'],
                'filter' => (bool) $e['filter'],
                'location' => (bool) $e['location'],
                'coordinate' => (bool) $e['coordinate'],
            ],
        ];
    }

    /**
     * Converte la regexp dell'help in uno o più template di path con i parametri nominati
     * come le chiavi dichiarate dall'help.
     *
     * @param array<string, mixed> $e
     * @return list<array{0: string, 1: list<array<string, mixed>>}>
     */
    private static function varianti(array $e): array
    {
        $i = 0;
        $nodi = self::nodi((string) $e['regexp'], $i);
        $mappa = self::mappaChiavi(self::gruppiNominati($nodi), $e['chiavi'] ?? []);

        $out = [];
        foreach (self::espandi($nodi) as $variante) {
            $template = '';
            $parametri = [];

            foreach ($variante as $nodo) {
                if ($nodo['t'] === 'lit') {
                    $template .= $nodo['v'];

                    continue;
                }

                // Gruppo vuoto (es. "(?<sigladoc>)" nei MyDB): è un segnaposto, non un parametro.
                if ($nodo['pattern'] === '') {
                    continue;
                }

                $chiave = $mappa[$nodo['nome']];
                $template .= '{'.$chiave['nome'].'}';
                $parametri[] = ['nome' => $chiave['nome'], 'tipo' => $chiave['tipo'], 'encodable' => $chiave['encodable'], 'pattern' => $nodo['pattern']];
            }

            $out[] = [rtrim($template, '/') ?: '/', $parametri];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nodi(string $re, int &$i): array
    {
        $nodi = [];
        $lit = '';
        $n = strlen($re);

        while ($i < $n) {
            $c = $re[$i];

            if ($c === ')') {
                break;
            }

            if ($c === '^' || $c === '$') {
                $i++;

                continue;
            }

            if ($c === '\\') {
                $lit .= $re[$i + 1];
                $i += 2;

                continue;
            }

            // "{0,1}" su un carattere letterale (lo "/" finale delle collezioni): lo si omette.
            if ($c === '{') {
                $lit = substr($lit, 0, -1);
                $i = (int) strpos($re, '}', $i) + 1;

                continue;
            }

            if ($c === '(') {
                self::chiudiLetterale($nodi, $lit);

                if (substr($re, $i, 3) === '(?<') {
                    $fine = (int) strpos($re, '>', $i);
                    $nome = substr($re, $i + 3, $fine - $i - 3);
                    $i = $fine + 1;
                    $nodi[] = ['t' => 'par', 'nome' => $nome, 'pattern' => self::corpoGruppo($re, $i)];

                    continue;
                }

                $i++;
                $interni = self::nodi($re, $i);
                $i++;

                if (substr($re, $i, 5) === '{0,1}') {
                    $i += 5;
                    $nodi[] = ['t' => 'opt', 'nodi' => $interni];
                } elseif (($re[$i] ?? '') === '?') {
                    $i++;
                    $nodi[] = ['t' => 'opt', 'nodi' => $interni];
                } else {
                    array_push($nodi, ...$interni);
                }

                continue;
            }

            $lit .= $c;
            $i++;
        }

        self::chiudiLetterale($nodi, $lit);

        return $nodi;
    }

    /**
     * @param list<array<string, mixed>> $nodi
     */
    private static function chiudiLetterale(array &$nodi, string &$lit): void
    {
        if ($lit !== '') {
            $nodi[] = ['t' => 'lit', 'v' => $lit];
            $lit = '';
        }
    }

    private static function corpoGruppo(string $re, int &$i): string
    {
        $inizio = $i;
        $profondita = 1;
        $n = strlen($re);

        while ($i < $n) {
            $c = $re[$i];

            if ($c === '\\') {
                $i += 2;

                continue;
            }

            if ($c === '[') {
                $i++;
                while ($i < $n && $re[$i] !== ']') {
                    $i += $re[$i] === '\\' ? 2 : 1;
                }
                $i++;

                continue;
            }

            if ($c === '(') {
                $profondita++;
            } elseif ($c === ')' && --$profondita === 0) {
                $corpo = substr($re, $inizio, $i - $inizio);
                $i++;

                return $corpo;
            }

            $i++;
        }

        throw new RuntimeException("Gruppo non chiuso nella regexp: $re");
    }

    /**
     * @param list<array<string, mixed>> $nodi
     * @return list<list<array<string, mixed>>>
     */
    private static function espandi(array $nodi): array
    {
        $varianti = [[]];

        foreach ($nodi as $nodo) {
            if ($nodo['t'] !== 'opt') {
                foreach ($varianti as $k => $v) {
                    $varianti[$k][] = $nodo;
                }

                continue;
            }

            $nuove = [];
            foreach ($varianti as $v) {
                $nuove[] = $v;
                foreach (self::espandi($nodo['nodi']) as $interna) {
                    $nuove[] = array_merge($v, $interna);
                }
            }
            $varianti = $nuove;
        }

        return $varianti;
    }

    /**
     * @param list<array<string, mixed>> $nodi
     * @return list<string>
     */
    private static function gruppiNominati(array $nodi): array
    {
        $nomi = [];

        foreach ($nodi as $nodo) {
            if ($nodo['t'] === 'par') {
                $nomi[] = $nodo['nome'];
            } elseif ($nodo['t'] === 'opt') {
                array_push($nomi, ...self::gruppiNominati($nodo['nodi']));
            }
        }

        return $nomi;
    }

    /**
     * I gruppi della regexp hanno nomi abbreviati ("codsottobolla"), le chiavi dell'help i
     * nomi dei campi ("cod_sottobolla"). Se tutti i gruppi trovano la loro chiave per nome
     * si usa quella corrispondenza, altrimenti l'ordine: i MyDB elencano le chiavi in un
     * ordine diverso da quello del path, le altre risorse le elencano in ordine ma con nomi
     * diversi.
     *
     * @param list<string> $gruppi
     * @param list<array<string, mixed>> $chiavi
     * @return array<string, array{nome: string, tipo: string, encodable: bool}>
     */
    private static function mappaChiavi(array $gruppi, array $chiavi): array
    {
        $norm = static fn (string $s): string => strtolower(str_replace('_', '', $s));
        $perNome = [];

        foreach ($gruppi as $g) {
            foreach ($chiavi as $c) {
                if ($norm($g) === $norm($c['nome'])) {
                    $perNome[$g] = $c;
                }
            }
        }

        $mappa = [];
        foreach ($gruppi as $k => $g) {
            $c = count($perNome) === count($gruppi) ? $perNome[$g] : ($chiavi[$k] ?? ['nome' => $g, 'tipo' => 'string', 'encodable' => false]);
            $mappa[$g] = ['nome' => (string) $c['nome'], 'tipo' => (string) $c['tipo'], 'encodable' => (bool) $c['encodable']];
        }

        return $mappa;
    }

    /**
     * @param list<array<string, list<array<string, mixed>>>> $gruppi
     */
    private function costruisciRisorse(array $gruppi): void
    {
        $visti = [];

        foreach (self::endpoint($gruppi) as $op) {
            // La stessa variante può comparire due volte (es. MyDB con e senza sigla vuota).
            if (isset($visti[$op['metodo'].' '.$op['path']])) {
                continue;
            }
            $visti[$op['metodo'].' '.$op['path']] = true;

            $id = ltrim($op['collezione'], '/');

            $this->risorse[$id] ??= [
                'id' => $id,
                'path' => $op['collezione'],
                'gruppo' => $op['gruppo'],
                'titolo' => self::titolo($id),
                'descrizione' => '',
                'coordinate' => false,
                'parametri_path' => $op['parametri_path'],
                'operazioni' => [],
                'campi' => [],
                'origine_campi' => 'nessuna',
                'forma' => 'dati',
                'relazioni' => [],
                'note' => [],
            ];

            $manuale = $this->manuale[$op['metodo'].' '.self::norm($op['path'])] ?? null;
            if ($manuale !== null) {
                $op['manuale'] = $manuale;
            }

            $this->risorse[$id]['operazioni'][$op['tipo']][] = $op;
            $this->risorse[$id]['coordinate'] = $this->risorse[$id]['coordinate'] || $op['flag']['coordinate'];

            if (! in_array($id, $this->gruppi[$op['gruppo']] ?? [], true)) {
                $this->gruppi[$op['gruppo']][] = $id;
            }
        }

        foreach ($this->risorse as $id => $r) {
            $prima = $r['operazioni']['lista'][0] ?? $r['operazioni']['leggi'][0] ?? reset($r['operazioni'])[0];
            $descrizione = (string) preg_replace('/^(lista|lettura|elenco)\s+(di\s+)?/i', '', (string) $prima['descrizione']);
            $this->risorse[$id]['descrizione'] = ucfirst($descrizione);
            $this->risorse[$id]['chiave'] = self::chiaveRisorsa($r['operazioni']);
        }
    }

    /**
     * Le parti della chiave dalla variante più lunga; quelle assenti in qualche variante
     * sono opzionali (es. il conto nei movimenti di magazzino).
     *
     * @param array<string, list<array<string, mixed>>> $operazioni
     * @return list<array<string, mixed>>
     */
    private static function chiaveRisorsa(array $operazioni): array
    {
        $varianti = array_merge($operazioni['leggi'] ?? [], $operazioni['modifica'] ?? [], $operazioni['elimina'] ?? []);

        if ($varianti === []) {
            return [];
        }

        usort($varianti, static fn ($a, $b) => count($b['chiave']) <=> count($a['chiave']));
        $chiave = [];

        foreach ($varianti[0]['chiave'] as $parte) {
            $sempre = array_reduce(
                $varianti,
                static fn (bool $ok, $v) => $ok && in_array($parte['nome'], array_column($v['chiave'], 'nome'), true),
                true,
            );
            $chiave[] = $parte + ['opzionale' => ! $sempre];
        }

        return $chiave;
    }

    private static function titolo(string $id): string
    {
        $parti = array_values(array_filter(explode('/', $id), static fn ($p) => ! str_starts_with($p, '{')));
        if (count($parti) > 1 && $parti[0] === 'dati-generali') {
            array_shift($parti);
        }

        return ucfirst(str_replace('-', ' ', implode(' - ', $parti)));
    }

    public static function norm(string $path): string
    {
        return rtrim((string) preg_replace('/\{[^}]*\}/', '{}', (string) preg_replace('/\{[^}]*\}@\{[^}]*\}/', '{}', $path)), '/');
    }

    // ------------------------------------------------------------------ campi

    /**
     * @param array<string, array<string, mixed>> $istantanee
     */
    private function assegnaCampi(array $istantanee): void
    {
        foreach ($this->risorse as $id => $r) {
            $path = self::ALIAS_CAMPI[self::norm($r['path'])] ?? $r['path'];
            $istantanea = $istantanee[$path] ?? null;
            $struttura = $this->strutture[$path] ?? null;

            [$campi, $origine, $forma] = match (true) {
                ($istantanea['origine'] ?? null) === 'info' => [$istantanea['campi'], 'info', 'dati'],
                $struttura !== null && $struttura !== [] => [$struttura, 'manuale', $istantanea['forma'] ?? 'dati'],
                ($istantanea['origine'] ?? null) === 'campione' => [$istantanea['campi'], 'campione', $istantanea['forma']],
                default => [[], 'nessuna', 'dati'],
            };

            $crea = $this->opManuale($r, 'crea');
            $modifica = $this->opManuale($r, 'modifica');

            // Ultima risorsa: i parametri di body che il manuale documenta per crea/modifica.
            if ($campi === []) {
                foreach ([$crea, $modifica] as $op) {
                    foreach ($op['parametri'] ?? [] as $p) {
                        if ($p['posizione'] === 'body' && ! in_array($p['campo'], array_column($campi, 'nome'), true)) {
                            $campi[] = ['nome' => $p['campo'], 'tipo' => $p['tipo'], 'descrizione' => $p['descrizione']];
                        }
                    }
                }
                $origine = $campi === [] ? 'nessuna' : 'manuale';
            }

            $descrManuale = [];
            foreach ([$crea, $modifica] as $op) {
                foreach ($op['parametri'] ?? [] as $p) {
                    $descrManuale[$p['campo']] ??= $p['descrizione'];
                }
            }

            $normalizzati = [];
            foreach ($campi as $c) {
                $n = self::campo($c);
                if (isset($descrManuale[$n['nome']])) {
                    $n['descrizione_manuale'] = $descrManuale[$n['nome']];
                }
                $normalizzati[$n['nome']] = isset($normalizzati[$n['nome']])
                    ? self::unisciDuplicato($normalizzati[$n['nome']], $n)
                    : $n;
            }

            $richiesti = [];
            foreach ($crea['parametri'] ?? [] as $p) {
                if ($p['obbligatorio'] && $p['posizione'] === 'body') {
                    $richiesti[] = $p['campo'];
                }
            }

            $this->risorse[$id]['campi'] = $normalizzati;
            $this->risorse[$id]['origine_campi'] = $origine;
            $this->risorse[$id]['forma'] = $forma;
            $this->risorse[$id]['richiesti_crea'] = $richiesti;
            $this->risorse[$id]['parametri_crea'] = $crea['parametri'] ?? [];
            $this->risorse[$id]['errore_info'] = ($istantanea['origine'] ?? null) === 'errore' ? $istantanea['errore'] : null;
        }
    }

    /**
     * ?info=true dichiara alcuni campi due volte (data_ult_mod nelle testate dei documenti,
     * codice_agente e imp_sps_prof nello scadenzario). Verificato sui dati della versione
     * 88201: la forma scalare/array è quella della prima dichiarazione, mentre fra
     * Alfanumerico e "Data e ora" vale il formato data-ora.
     *
     * @param array<string, mixed> $primo
     * @param array<string, mixed> $altro
     * @return array<string, mixed>
     */
    private static function unisciDuplicato(array $primo, array $altro): array
    {
        if ($altro['tipo'] === 'Data e ora') {
            $primo['tipo'] = 'Data e ora';
        }

        $primo['chiave'] = $primo['chiave'] || $altro['chiave'];
        $primo['obbligatorio_put'] = $primo['obbligatorio_put'] || $altro['obbligatorio_put'];

        if ($altro['descrizione'] !== '' && stripos($primo['descrizione'], $altro['descrizione']) === false) {
            $primo['descrizione'] .= ' (dichiarato anche come: '.$altro['descrizione'].')';
        }

        return $primo;
    }

    /**
     * Operazione del manuale per una risorsa. Ordini fornitori, matrici e preventivi
     * "seguono lo stesso pattern degli ordini clienti": il manuale documenta solo quelli.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null
     */
    private function opManuale(array $r, string $tipo): ?array
    {
        foreach ($r['operazioni'][$tipo] ?? [] as $op) {
            if (isset($op['manuale']) && $op['manuale']['parametri'] !== []) {
                return $op['manuale'];
            }
        }

        $alias = self::ALIAS_MANUALE[self::norm($r['path'])] ?? null;
        if ($alias !== null) {
            return $this->manuale[($tipo === 'crea' ? 'POST ' : 'PUT ').$alias.($tipo === 'crea' ? '' : '/{}')] ?? null;
        }

        if (preg_match('#^documenti/(ordini-fornitori|ordini-matrici|preventivi)$#', $r['id']) === 1) {
            $metodo = $tipo === 'crea' ? 'POST' : 'PUT';
            $path = $tipo === 'crea' ? '/documenti/ordini-clienti' : '/documenti/ordini-clienti/{}+{}+{}';

            return $this->manuale[$metodo.' '.$path] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function campo(array $c): array
    {
        $tipo = (string) ($c['tipo'] ?? 'Alfanumerico');
        $dimensione = isset($c['dimensione_array']) ? (int) $c['dimensione_array'] : null;

        if (preg_match('/^Array(?:\[(\d+)\])?\s*(\w*)/i', $tipo, $m) === 1) {
            $dimensione = $m[1] !== '' ? (int) $m[1] : 0;
            $tipo = strtolower($m[2]) === 'numerico' ? 'Numerico con virgola' : 'Alfanumerico';
        }

        $tipo = match (true) {
            str_starts_with($tipo, 'Data e ora') => 'Data e ora',
            in_array($tipo, ['Numerico', 'integer', 'Integer'], true) => 'Numerico',
            in_array($tipo, ['Numerico con virgola', 'number', 'Number', 'decimal'], true) => 'Numerico con virgola',
            in_array($tipo, ['Booleano', 'boolean', 'Boolean'], true) => 'Booleano',
            in_array($tipo, ['Oggetto', 'object', 'Object'], true) => 'Oggetto',
            default => 'Alfanumerico',
        };

        $out = [
            'nome' => (string) $c['nome'],
            'tipo' => $tipo,
            'descrizione' => trim((string) ($c['descrizione'] ?? '')),
            'dimensione' => $dimensione,
            'chiave' => (bool) ($c['chiave'] ?? false),
            'obbligatorio_put' => (bool) ($c['obbligatorio_put'] ?? false),
        ];

        if (isset($c['campi'])) {
            $out['campi'] = array_map(self::campo(...), $c['campi']);
        }

        return $out;
    }

    private function collegaTestateRighe(): void
    {
        foreach (array_keys($this->risorse) as $id) {
            if (isset($this->risorse[$id.'/righe'])) {
                $this->risorse[$id]['righe'] = $id.'/righe';
                $this->risorse[$id.'/righe']['testata'] = $id;
            }
        }
    }

    /**
     * Campi che il manuale documenta nel body di inserimento ma che ?info=true non elenca:
     * i parametri "in_*" di creazione azienda, codice_abb degli abbinati, tp_riga dei
     * movimenti. Restano separati dai campi di lettura, che descrivono un'altra cosa.
     */
    private function campiSoloInserimento(): void
    {
        foreach ($this->risorse as $id => $r) {
            $noti = $r['campi'] + (isset($r['righe']) ? $this->risorse[$r['righe']]['campi'] : []);
            $extra = [];

            foreach ($r['parametri_crea'] as $p) {
                if ($p['posizione'] === 'body' && ! isset($noti[$p['campo']])) {
                    $extra[$p['campo']] = self::campo(['nome' => $p['campo'], 'tipo' => $p['tipo'], 'descrizione' => $p['descrizione']]);
                }
            }

            $this->risorse[$id]['campi_solo_inserimento'] = $extra;
        }
    }

    // ------------------------------------------------------------------ relazioni

    /**
     * @param array<string, mixed> $relazioni
     */
    private function applicaRelazioni(array $relazioni): void
    {
        $applicabile = static function (array $regola, string $id): bool {
            foreach ($regola['escludi_in'] ?? [] as $p) {
                if (fnmatch($p, $id)) {
                    return false;
                }
            }
            if (! isset($regola['solo_in'])) {
                return true;
            }
            foreach ($regola['solo_in'] as $p) {
                if (fnmatch($p, $id)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($this->risorse as $id => $r) {
            foreach (['campi', 'campi_solo_inserimento'] as $elenco) {
                foreach ($r[$elenco] as $chiave => $campo) {
                    $riferimento = $this->riferimento($relazioni['regole'], $applicabile, $id, $campo['nome']);
                    if ($riferimento !== null) {
                        $this->risorse[$id][$elenco][$chiave]['riferimento'] = $riferimento;
                    }
                }
            }

            foreach ($relazioni['composte'] as $composta) {
                $presenti = array_intersect($composta['campi'], array_keys($r['campi']));
                if ($presenti !== [] && $applicabile($composta, $id)) {
                    $destinazione = $composta['risorsa'] === 'documenti/{tipo}' && isset($r['testata'])
                        ? $r['testata']
                        : $composta['risorsa'];
                    $this->risorse[$id]['relazioni'][] = [
                        'campi' => array_values($presenti),
                        'risorsa' => $destinazione,
                        'nota' => $composta['nota'],
                    ];
                }
            }
        }
    }

    /**
     * La prima regola applicabile vince: in relazioni.json le regole più specifiche stanno prima.
     *
     * @param list<array<string, mixed>> $regole
     * @return array<string, mixed>|null
     */
    private function riferimento(array $regole, callable $applicabile, string $id, string $nome): ?array
    {
        foreach ($regole as $regola) {
            $destinazioni = (array) $regola['risorsa'];

            if (! in_array($nome, $regola['campi'], true) || ! $applicabile($regola, $id) || in_array($id, $destinazioni, true)) {
                continue;
            }

            $mancanti = array_diff($destinazioni, array_keys($this->risorse));
            if ($mancanti !== []) {
                throw new RuntimeException("relazioni.json: risorsa inesistente '".implode(', ', $mancanti)."' per il campo $nome");
            }

            return array_filter([
                'risorsa' => count($destinazioni) === 1 ? $destinazioni[0] : $destinazioni,
                'campo' => $regola['campo'],
                'nota' => $regola['nota'] ?? null,
            ]);
        }

        return null;
    }

    /**
     * @param array<string, string> $descrizioni
     */
    private function applicaDescrizioni(array $descrizioni): void
    {
        foreach ($descrizioni as $id => $testo) {
            if (! isset($this->risorse[$id])) {
                throw new RuntimeException("descrizioni.json: risorsa inesistente '$id'");
            }
            $this->risorse[$id]['descrizione'] = $testo;
        }
    }

    // ------------------------------------------------------------------ servizi

    /**
     * @param list<array<string, mixed>> $servizi
     * @return list<array<string, mixed>>
     */
    private static function servizi(array $servizi): array
    {
        $out = [];

        foreach ($servizi as $s) {
            $dati = [];
            $radice = [];
            $annidati = [];
            $datiObbligatorio = false;
            $vistoDati = false;

            foreach ($s['parametri'] as $p) {
                $campo = (string) $p['campo'];

                // Dopo i dati.* il manuale descrive talvolta la struttura degli elementi di un
                // array (es. le deleghe): non sono parametri accanto a cmd.
                if ($vistoDati && ! str_starts_with($campo, 'dati.')) {
                    $annidati[] = $campo.' ('.strtolower((string) $p['tipo']).'): '.$p['descrizione'];

                    continue;
                }
                $vistoDati = $vistoDati || str_starts_with($campo, 'dati.');

                if (in_array($campo, ['cmd', 'next'], true)) {
                    continue;
                }

                if ($campo === 'dati') {
                    $datiObbligatorio = $p['obbligatorio'];

                    continue;
                }

                $p['schema'] = self::schemaServizio((string) $p['tipo'], (string) $p['descrizione']);

                if (str_starts_with($campo, 'dati.')) {
                    // Un solo livello: i sotto-campi più profondi restano nella descrizione del padre.
                    $nome = substr($campo, 5);
                    if (! str_contains($nome, '.')) {
                        $dati[$nome] = $p;
                    }
                } elseif (! str_contains($campo, '.') && ! str_contains($campo, '[')) {
                    $radice[$campo] = $p;
                }
            }

            $out[] = [
                'cmd' => $s['cmd'],
                'titolo' => $s['titolo'],
                'descrizione' => $s['descrizione'],
                'dati' => $dati,
                'dati_obbligatorio' => $datiObbligatorio,
                'annidati' => $annidati,
                'radice' => $radice,
                'risposta' => $s['risposta'],
                'note' => $s['note'],
                'endpoint' => $s['cmd'] === 'upload_files' ? '/uploads' : '/servizi',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schemaServizio(string $tipo, string $descrizione): array
    {
        $schema = match (strtolower($tipo)) {
            'number', 'decimal' => ['type' => 'number'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            default => ['type' => 'string'],
        };

        if ($descrizione !== '') {
            $schema['description'] = $descrizione;
        }

        return $schema;
    }

    // ------------------------------------------------------------------ JSON Schema dei campi

    /**
     * Schema JSON (2020-12, quindi valido sia in OpenAPI 3.1 sia negli inputSchema MCP) di un
     * campo. Con $comeRiga il campo è una variabile di riga vista dal documento completo,
     * quindi diventa un array indicizzato per riga.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public static function schemaCampo(array $c, bool $comeRiga = false, bool $estensioni = true): array
    {
        $valore = match ($c['tipo']) {
            'Numerico' => ['type' => 'integer'],
            'Numerico con virgola' => ['type' => 'number'],
            'Data e ora' => ['type' => 'string', 'pattern' => '^(\d{8} \d{6})?$'],
            'Booleano' => ['type' => 'boolean'],
            'Oggetto' => self::schemaOggetto($c['campi'] ?? [], $estensioni),
            default => ['type' => 'string'],
        };

        $schema = $valore;
        if ($c['dimensione'] !== null) {
            $schema = self::indicizzato($valore, $c['dimensione'] ?: null);
        }

        if ($comeRiga) {
            $schema = $c['dimensione'] !== null
                ? ['type' => 'array', 'items' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 3, 'prefixItems' => [['type' => 'integer', 'minimum' => 1]]]]
                : self::indicizzato($valore, null);
        }

        $schema['description'] = self::descrizione($c, $comeRiga);

        if (preg_match('/sol[ao] lettura/i', $c['descrizione']) === 1) {
            $schema['readOnly'] = true;
        }

        if ($estensioni) {
            $schema['x-mexal-tipo'] = $c['tipo'];
            if (isset($c['riferimento'])) {
                $schema['x-mexal-riferimento'] = $c['riferimento'];
            }
        }

        return $schema;
    }

    /**
     * @param list<array<string, mixed>> $campi
     * @return array<string, mixed>
     */
    private static function schemaOggetto(array $campi, bool $estensioni): array
    {
        $schema = ['type' => 'object'];

        foreach ($campi as $c) {
            $schema['properties'][$c['nome']] = self::schemaCampo($c, false, $estensioni);
        }

        return $schema;
    }

    /**
     * Gli array delle WebAPI non sono liste semplici ma coppie [indice, valore].
     *
     * @param array<string, mixed> $valore
     * @return array<string, mixed>
     */
    private static function indicizzato(array $valore, ?int $massimo): array
    {
        $indice = ['type' => 'integer', 'minimum' => 1];
        if ($massimo !== null) {
            $indice['maximum'] = $massimo;
        }

        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'array',
                'prefixItems' => [$indice, $valore],
                'minItems' => 2,
                'maxItems' => 2,
            ],
        ];

        if ($massimo !== null) {
            $schema['maxItems'] = $massimo;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $c
     */
    public static function descrizione(array $c, bool $comeRiga = false): string
    {
        $d = $c['descrizione'] !== '' ? $c['descrizione'] : $c['nome'];
        $manuale = $c['descrizione_manuale'] ?? '';

        if ($manuale !== '' && stripos($d, $manuale) === false) {
            $d = stripos($manuale, $d) !== false ? $manuale : $d.' — '.$manuale;
        }

        $d = rtrim($d, '. ').'.';
        $formato = stripos($d, 'AAAAMMGG') !== false || stripos($d, 'YYYYMMDD') !== false;

        if ($c['tipo'] === 'Data e ora' && ! $formato) {
            $d .= ' Formato AAAAMMGG HHMMSS.';
        } elseif ($c['tipo'] === 'Alfanumerico' && ! $formato && self::sembraData($c)) {
            $d .= ' Data in formato AAAAMMGG.';
        }

        if ($c['dimensione'] !== null) {
            $d .= ' Array indicizzato [[indice, valore], ...]'.($c['dimensione'] > 0 ? " con indice da 1 a {$c['dimensione']}" : '').'.';
        }

        if ($comeRiga) {
            $d .= ' Variabile di riga: [[indice_riga, valore], ...], dove indice_riga è la posizione della riga nel documento.';
        }

        if (isset($c['riferimento'])) {
            $r = $c['riferimento'];
            $d .= ' Valori da '.implode(' o ', array_map(static fn ($x) => $x.'.'.$r['campo'], (array) $r['risorsa'])).'.';
            if (isset($r['nota']) && stripos($d, rtrim($r['nota'], '.')) === false) {
                $d .= ' '.rtrim($r['nota'], '.').'.';
            }
        }

        if ($c['chiave']) {
            $d .= ' Campo chiave.';
        }

        return $d;
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function sembraData(array $c): bool
    {
        return preg_match('/^(dt|data)_/', $c['nome']) === 1 || preg_match('/^data\b/i', $c['descrizione']) === 1;
    }
}
