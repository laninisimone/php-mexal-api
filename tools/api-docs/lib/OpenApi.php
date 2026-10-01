<?php

declare(strict_types=1);

namespace MexalApiDocs;

/**
 * Un documento OpenAPI 3.1 per ogni gruppo dell'help (clienti, articoli, documenti, ...)
 * più uno per il canale servizi. Ogni file è autosufficiente: niente $ref fra file, così
 * lo si può dare in pasto da solo a Swagger UI, a un generatore di client o a un modello.
 */
final class OpenApi
{
    public function __construct(private readonly Modello $m)
    {
    }

    /**
     * @return array<string, string> nome file => contenuto YAML
     */
    public function file(): array
    {
        $out = [];

        foreach ($this->m->gruppi as $gruppo => $ids) {
            $out[$gruppo.'.yaml'] = Yaml::dump($this->documento($gruppo, $ids));
        }

        $out['servizi.yaml'] = Yaml::dump($this->servizi());
        ksort($out);

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    private function documento(string $gruppo, array $ids): array
    {
        $paths = [];
        $schemi = [];
        $tag = [];

        foreach ($ids as $id) {
            $r = $this->m->risorse[$id];
            $tag[] = ['name' => $id, 'description' => $this->descrizioneRisorsa($r)];
            $schemi += $this->schemiRisorsa($r);

            foreach ($this->operazioni($r) as $path => $metodi) {
                $paths[$path] = ($paths[$path] ?? []) + $metodi;
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Passepartout WebAPI - '.ucfirst(str_replace('-', ' ', $gruppo)),
                'version' => (string) ($this->m->prodotto['versione_prodotto'] ?? 'n/d'),
                'description' => $this->introduzione(),
            ],
            'servers' => self::server('/webapi/risorse'),
            'security' => [['passepartout' => []]],
            'tags' => $tag,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => self::sicurezza(),
                'parameters' => self::parametriComuni(),
                'responses' => self::risposteErrore(),
                'schemas' => $schemi + self::schemiComuni(),
            ],
        ];
    }

    private function introduzione(): string
    {
        $p = $this->m->prodotto;

        return implode("\n", [
            'Generato da `tools/api-docs/genera.php` a partire dall\'help in linea (`GET /risorse/help?extended=true`)',
            'e da `?info=true` di un\'installazione Mexal/Passcom '.($p['codice_prodotto'] ?? '').' (versione gestionale '
                .($p['versione_prodotto'] ?? 'n/d').'), integrato con il manuale WebAPI v3.1.',
            'Endpoint e campi dipendono da versione e moduli installati: la fonte di verità resta `?info=true`.',
            '',
            '**Convenzioni**',
            '',
            '- Autenticazione: `Authorization: Passepartout <base64(utente:password)>`; sul cloud si aggiunge ` Dominio=<DOMINIO>`.',
            '- Contesto aziendale: header `Coordinate-Gestionale: Azienda=XXX Anno=AAAA` (opzionali `SottoAzienda=`, `Magazzino=`).',
            '- Date `AAAAMMGG`, date-ora `AAAAMMGG HHMMSS`, decimali con il punto.',
            '- Gli array sono liste di coppie `[[indice, valore], ...]`, non liste semplici.',
            '- Chiavi composte unite da `+` nel path (es. `OC+1+15`). Codici con `/` o `\\` vanno in esadecimale con `?encoding=hex`.',
            '- Paginazione: `max` limita i record, il token `next` della risposta va ripassato così com\'è e subito.',
            '- Ricerca: `POST .../ricerca` con `filtri`; i filtri sono in AND, i valori di uno stesso filtro in OR.',
            '- Revisione (PUT): rimandare `data_ult_mod` letto dalla GET per il controllo di modifica concorrente.',
            '- Limiti: 5 richieste simultanee per utente WebAPI (oltre si riceve 503).',
        ]);
    }

    /**
     * @param array<string, mixed> $r
     */
    private function descrizioneRisorsa(array $r): string
    {
        $d = $r['descrizione'];

        if (! $r['coordinate']) {
            $d .= ' Non richiede l\'header Coordinate-Gestionale.';
        }

        return $d.match ($r['origine_campi']) {
            'info' => '',
            'manuale' => ' Campi ricavati dal manuale WebAPI v3.1: l\'installazione interrogata non li espone con ?info=true'
                .($r['errore_info'] ? ' ('.$r['errore_info'].')' : '').'.',
            'campione' => ' L\'endpoint non supporta ?info=true: campi dedotti dalla struttura della risposta.',
            default => ' Campi non disponibili: interrogare ?info=true su un\'installazione che abiliti la risorsa'
                .($r['errore_info'] ? ' ('.$r['errore_info'].')' : '').'.',
        };
    }

    // ------------------------------------------------------------------ operazioni

    /**
     * @param array<string, mixed> $r
     * @return array<string, array<string, mixed>>
     */
    private function operazioni(array $r): array
    {
        $out = [];
        $nome = Modello::nomeSchema($r['id']);
        $base = Modello::nomeOperazione($r['id']);
        $schemaLettura = isset($r['righe']) ? $nome.'Documento' : $nome;

        foreach (Modello::OPERAZIONI as $tipo) {
            foreach ($r['operazioni'][$tipo] ?? [] as $k => $op) {
                $path = match ($tipo) {
                    'lista', 'crea' => $r['path'],
                    'cerca' => $r['path'].'/ricerca',
                    default => $op['path'],
                };

                $o = [
                    'tags' => [$r['id']],
                    'operationId' => $base.'_'.$tipo.($k > 0 ? '_'.($k + 1) : ''),
                    'summary' => ucfirst($op['descrizione']),
                ];

                $descrizione = $this->descrizioneOperazione($r, $tipo, $op);
                if ($descrizione !== '') {
                    $o['description'] = $descrizione;
                }

                $o['parameters'] = $this->parametri($r, $tipo, $op);
                if ($o['parameters'] === []) {
                    unset($o['parameters']);
                }

                $corpo = match ($tipo) {
                    'cerca' => $nome.'Ricerca',
                    'crea' => $nome.'Inserimento',
                    'modifica' => $nome.'Modifica',
                    default => null,
                };
                if ($corpo !== null) {
                    $o['requestBody'] = [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => self::ref($corpo)]],
                    ];
                }

                $o['responses'] = $this->risposte($r, $tipo, $schemaLettura);
                $o['x-mexal-versione-minima'] = $op['versione'];

                $metodo = strtolower($op['metodo']);
                $out[$path][$metodo] = $o;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $op
     */
    private function descrizioneOperazione(array $r, string $tipo, array $op): string
    {
        $righe = [];
        $manuale = $op['manuale'] ?? null;

        if ($manuale !== null && $manuale['descrizione'] !== '' && ! str_starts_with($manuale['descrizione'], 'Esempio')) {
            $righe[] = $manuale['descrizione'];
        }

        $righe = array_merge($righe, match ($tipo) {
            'lista' => $op['flag']['next'] ? ['Paginata: il server pagina oltre 30 secondi o 50 MB anche senza `max`; seguire `next` fino a esaurimento.'] : [],
            'cerca' => ['Filtri in AND fra loro; un `valore` array mette i valori in OR. Condizioni: `=`, `<>`, `>`, `<`, `>=`, `<=`, `contiene`, `inizia_per`.'],
            'crea' => ['Risponde 201 senza body: il codice assegnato è nell\'header `Location`.'],
            'modifica' => isset($r['righe'])
                ? ['Indicare sempre tutti gli `id_riga` e `tp_riga`, anche delle righe non modificate: le righe omesse vengono cancellate. Con `?solo_testata=true` le righe sono preservate.']
                : ['Inviare solo i campi da modificare, più `data_ult_mod` letto dalla GET se il controllo di modifica concorrente è attivo.'],
            default => [],
        });

        foreach ($manuale['note'] ?? [] as $nota) {
            $ripetuta = array_filter($righe, static fn (string $riga): bool => stripos($riga, rtrim($nota, '. ')) !== false);
            if ($ripetuta === [] && ! preg_match('/^(Versione minima|Richiede coordinate)/i', $nota)) {
                $righe[] = $nota;
            }
        }

        return implode("\n\n", $righe);
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function parametri(array $r, string $tipo, array $op): array
    {
        $out = [];
        $chiave = in_array($tipo, ['leggi', 'modifica', 'elimina'], true) ? $op['chiave'] : [];

        foreach (array_merge($r['parametri_path'], $chiave) as $p) {
            $out[] = [
                'name' => $p['nome'],
                'in' => 'path',
                'required' => true,
                'description' => self::descrizioneParametro($p),
                'schema' => self::schemaParametro($p),
            ];
        }

        if ($op['flag']['coordinate']) {
            $out[] = self::ref('CoordinateGestionale', 'parameters');
        }

        $nome = Modello::nomeSchema($r['id']);

        if ($op['flag']['fields'] && in_array($tipo, ['lista', 'cerca', 'leggi'], true)) {
            $out[] = [
                'name' => 'fields',
                'in' => 'query',
                'description' => 'Campi da restituire, separati da virgola. Riduce il payload: usarlo sempre sulle liste.',
                'style' => 'form',
                'explode' => false,
                'schema' => $r['campi'] !== []
                    ? ['type' => 'array', 'items' => self::ref($nome.'Campo')]
                    : ['type' => 'array', 'items' => ['type' => 'string']],
            ];
        }

        if ($op['flag']['max']) {
            $out[] = self::ref('Max', 'parameters');
        }
        if ($op['flag']['next']) {
            $out[] = self::ref('Next', 'parameters');
        }

        $codificabile = array_filter(array_merge($r['parametri_path'], $chiave), static fn ($p) => $p['encodable'] && $p['tipo'] === 'string');
        if ($codificabile !== []) {
            $out[] = self::ref('Encoding', 'parameters');
        }

        if ($tipo === 'crea' && str_starts_with($r['id'], 'documenti/') && isset($r['righe'])) {
            $out[] = [
                'name' => 'sigla_trasformazione',
                'in' => 'query',
                'description' => 'Trasforma i documenti di origine indicati nelle righe (sigla_doc_orig, serie_doc_orig, numero_doc_orig, id_rif_testata, dt_ult_mod_orig) nella sigla indicata, gestendo evasione e residui. Prevale sulla sigla del body.',
                'schema' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
            ];
        }

        if ($tipo === 'modifica' && isset($r['righe'])) {
            $out[] = [
                'name' => 'solo_testata',
                'in' => 'query',
                'description' => 'Se true modifica solo la testata e preserva le righe anche se omesse dal body.',
                'schema' => ['type' => 'boolean', 'default' => false],
            ];
        }

        foreach ($op['manuale']['parametri'] ?? [] as $p) {
            if ($p['posizione'] === 'query' && ! in_array($p['campo'], ['max', 'next', 'fields', 'encoding', 'solo_testata', 'sigla_trasformazione'], true)) {
                $out[] = [
                    'name' => $p['campo'],
                    'in' => 'query',
                    'description' => $p['descrizione'],
                    'schema' => Modello::schemaServizio($p['tipo'], ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $p
     */
    public static function descrizioneParametro(array $p): string
    {
        $d = 'Parte di chiave `'.$p['nome'].'`';

        if (preg_match('/^\[\\\\x20-/', $p['pattern']) === 1) {
            $d .= '. Caratteri ASCII stampabili: se il valore contiene `/` o `\\` va inviato in esadecimale con `encoding=hex`';
        }

        return $d.'.';
    }

    /**
     * Dalla regexp del gruppo al tipo JSON più preciso possibile.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function schemaParametro(array $p): array
    {
        $pattern = (string) $p['pattern'];

        if ($pattern === '\d+') {
            return ['type' => 'integer', 'minimum' => 0];
        }

        // Alternative di letterali, o un letterale solo (la sigla "OF" degli ordini fornitori).
        if (preg_match('/^[A-Za-z0-9_\-]+(\|[A-Za-z0-9_\-]+)*$/', $pattern) === 1) {
            return ['type' => 'string', 'enum' => explode('|', $pattern)];
        }

        if ($pattern === '[0-9]{8}') {
            return ['type' => 'string', 'pattern' => '^[0-9]{8}$', 'description' => 'Data AAAAMMGG'];
        }

        if (str_starts_with($pattern, '[\x20-')) {
            return ['type' => 'string', 'minLength' => 1];
        }

        return ['type' => $p['tipo'] === 'integer' ? 'integer' : 'string', 'pattern' => '^'.$pattern.'$'];
    }

    /**
     * Le chiavi sono codici HTTP: PHP converte '200' in intero.
     *
     * @param array<string, mixed> $r
     * @return array<int, mixed>
     */
    private function risposte(array $r, string $tipo, string $schemaLettura): array
    {
        $json = static fn (array $schema, string $descrizione): array => [
            'description' => $descrizione,
            'content' => ['application/json' => ['schema' => $schema]],
        ];

        $ok = match ($tipo) {
            'lista', 'cerca' => ['200' => $json($this->schemaCollezione($r), 'Record trovati')],
            'leggi' => $r['id'] === 'articoli/{codice}/allegati'
                ? ['200' => ['description' => 'Contenuto binario dell\'allegato', 'content' => ['*/*' => ['schema' => ['type' => 'string', 'contentMediaType' => 'application/octet-stream']]]]]
                : ['200' => $json(self::ref($schemaLettura), 'Record richiesto')],
            'crea' => ['201' => [
                'description' => 'Creato. Nessun body: il path della nuova risorsa è nell\'header Location.',
                'headers' => ['Location' => [
                    'description' => 'Path della risorsa creata (es. /webapi/risorse/'.$r['id'].'/...). Può contenere ?encoding=hex.',
                    'schema' => ['type' => 'string'],
                ]],
            ]],
            default => ['204' => ['description' => 'Operazione eseguita, nessun body.']],
        };

        $errori = [
            '400' => self::ref('ErroreRichiesta', 'responses'),
            '401' => self::ref('NonAutorizzato', 'responses'),
        ];
        if (! in_array($tipo, ['lista', 'cerca', 'crea'], true)) {
            $errori['404'] = self::ref('NonTrovato', 'responses');
        }
        $errori['503'] = self::ref('PoolEsaurito', 'responses');

        return $ok + $errori;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function schemaCollezione(array $r): array
    {
        $nome = Modello::nomeSchema($r['id']);
        $forma = (string) $r['forma'];

        if (str_starts_with($forma, 'radice:')) {
            $radice = substr($forma, 7);

            return ['type' => 'object', 'properties' => [$radice => ['type' => 'array', 'items' => self::ref($nome)]]];
        }

        if (str_starts_with($forma, 'radice-oggetto:')) {
            return ['type' => 'object', 'properties' => [substr($forma, 15) => self::ref($nome)]];
        }

        return match ($forma) {
            'oggetto' => self::ref($nome),
            'colonnare' => self::ref($nome.'Colonnare'),
            default => self::ref($nome.'Lista'),
        };
    }

    // ------------------------------------------------------------------ schemi

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function schemiRisorsa(array $r): array
    {
        $nome = Modello::nomeSchema($r['id']);
        $schemi = [];

        $schemi[$nome] = $this->schemaRecord($r);

        if ($r['campi'] !== []) {
            $schemi[$nome.'Campo'] = ['type' => 'string', 'enum' => array_keys($r['campi']), 'description' => 'Campi di '.$r['id'].'.'];
        }

        if ($r['forma'] === 'dati' && (isset($r['operazioni']['lista']) || isset($r['operazioni']['cerca']))) {
            $schemi[$nome.'Lista'] = [
                'type' => 'object',
                'required' => ['dati'],
                'properties' => [
                    'dati' => ['type' => 'array', 'items' => self::ref($nome)],
                    'data_ric_elab' => ['type' => 'string', 'description' => 'Timestamp di elaborazione, AAAAMMGG HHMMSS.'],
                    'next' => ['type' => 'string', 'description' => 'Presente solo se ci sono altre pagine: da passare così com\'è nel parametro next.'],
                ],
            ];
        }

        if ($r['forma'] === 'colonnare') {
            $schemi[$nome.'Colonnare'] = $this->schemaColonnare($r);
        }

        if (isset($r['operazioni']['cerca'])) {
            $filtro = ['allOf' => [self::ref('Filtro')]];
            if ($r['campi'] !== []) {
                $filtro['allOf'][] = ['properties' => ['campo' => self::ref($nome.'Campo')]];
            }

            $schemi[$nome.'Ricerca'] = [
                'type' => 'object',
                'required' => ['filtri'],
                'properties' => ['filtri' => ['type' => 'array', 'minItems' => 1, 'items' => $filtro]],
            ];
        }

        $base = $nome;
        if (isset($r['righe'])) {
            $schemi[$nome.'Documento'] = $this->schemaDocumento($r);
            $base = $nome.'Documento';
        }

        if (isset($r['operazioni']['crea'])) {
            $inserimento = ['allOf' => [self::ref($base)]];
            foreach ($r['campi_solo_inserimento'] as $nomeCampo => $c) {
                $inserimento['properties'][$nomeCampo] = Modello::schemaCampo($c) + ['x-mexal-solo-inserimento' => true];
            }
            if ($r['richiesti_crea'] !== []) {
                $inserimento['required'] = $r['richiesti_crea'];
            }
            $inserimento['description'] = 'Body di inserimento. Obbligatori secondo il manuale: '
                .($r['richiesti_crea'] === [] ? 'non indicati' : implode(', ', $r['richiesti_crea'])).'.';
            $schemi[$nome.'Inserimento'] = $inserimento;
        }

        if (isset($r['operazioni']['modifica'])) {
            $put = array_keys(array_filter($r['campi'], static fn ($c) => $c['obbligatorio_put']));
            $schemi[$nome.'Modifica'] = [
                'allOf' => [self::ref($base)],
                'description' => 'Body di revisione: solo i campi da modificare.'
                    .($put !== [] ? ' Campi da indicare sempre secondo ?info=true: '.implode(', ', $put).'.' : ''),
                'x-mexal-obbligatori-put' => $put,
            ];
        }

        return $schemi;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function schemaRecord(array $r): array
    {
        if ($r['campi'] === []) {
            return [
                'type' => 'object',
                'description' => 'Struttura non disponibile per questa installazione: interrogare GET '.$r['path'].'?info=true.',
                'additionalProperties' => true,
            ];
        }

        $schema = ['type' => 'object', 'properties' => []];
        foreach ($r['campi'] as $nome => $c) {
            $schema['properties'][$nome] = Modello::schemaCampo($c);
        }

        $schema['x-mexal-origine-campi'] = $r['origine_campi'];

        return $schema;
    }

    /**
     * Il documento completo: campi di testata scalari, campi di riga come array per riga.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function schemaDocumento(array $r): array
    {
        $schema = $this->schemaRecord($r);
        $righe = $this->m->risorse[$r['righe']]['campi'];

        foreach ($righe as $nome => $c) {
            if (! isset($schema['properties'][$nome])) {
                $schema['properties'][$nome] = Modello::schemaCampo($c, true);
            }
        }

        $schema['description'] = 'Documento completo (GET con chiave, POST, PUT): testata più righe. Ogni campo di riga è un array '
            .'[[indice_riga, valore], ...] dove indice_riga è la posizione a video della riga; gli indici devono essere coerenti fra '
            .'tutti i campi di riga. id_riga = 0 indica una riga nuova.';

        return $schema;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function schemaColonnare(array $r): array
    {
        $schema = [
            'type' => 'object',
            'description' => 'Formato colonnare: ogni campo è una lista di tuple [indice_record, valore] (o [indice_record, taglia, valore] '
                .'per i campi per taglia). Sono presenti solo i valori diversi da zero/vuoto; lo stesso indice_record lega i campi di un record.',
            'properties' => [],
        ];

        foreach ($r['campi'] as $nome => $c) {
            $schema['properties'][$nome] = $c['dimensione'] === null
                ? Modello::schemaCampo($c)
                : ['type' => 'array', 'items' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 3], 'description' => Modello::descrizione(['dimensione' => null] + $c)];
        }

        $schema['properties']['next'] = ['type' => 'string'];
        $schema['properties']['data_ric_elab'] = ['type' => 'string'];

        return $schema;
    }

    // ------------------------------------------------------------------ servizi

    /**
     * @return array<string, mixed>
     */
    private function servizi(): array
    {
        $schemi = [];
        $mapping = [];
        $risposte = [];
        $elenco = [];

        foreach ($this->m->servizi as $s) {
            if ($s['endpoint'] !== '/servizi') {
                continue;
            }

            $nome = 'Servizio'.Modello::nomeSchema($s['cmd']);
            $schemi[$nome] = Ai::schemaServizio($s, true);
            $mapping[$s['cmd']] = '#/components/schemas/'.$nome;
            $elenco[] = '- `'.$s['cmd'].'`: '.$s['titolo'];

            if ($s['risposta'] !== []) {
                $risposta = ['type' => 'object', 'title' => 'Risposta '.$s['cmd'], 'properties' => []];
                foreach ($s['risposta'] as $campo) {
                    if (! str_contains($campo['campo'], '.') && ! str_contains($campo['campo'], '[')) {
                        $risposta['properties'][$campo['campo']] = Modello::schemaServizio($campo['tipo'], $campo['descrizione']);
                    }
                }
                $schemi['Risposta'.Modello::nomeSchema($s['cmd'])] = $risposta;
                $risposte[] = self::ref('Risposta'.Modello::nomeSchema($s['cmd']));
            }
        }

        $risposte[] = ['type' => 'object', 'description' => 'Risposta di un servizio senza schema documentato.'];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Passepartout WebAPI - Canale servizi',
                'version' => (string) ($this->m->prodotto['versione_prodotto'] ?? 'n/d'),
                'description' => implode("\n", [
                    'Il canale `/servizi` non è RESTful: si invia sempre una POST con il comando in `cmd` e i parametri in `dati`',
                    '(alcuni servizi vogliono parametri allo stesso livello di `cmd`). Il token `next` di paginazione viaggia nel body,',
                    'accanto a `cmd`. Schemi ricavati dal manuale WebAPI v3.1: i servizi disponibili dipendono da versione e moduli.',
                    '',
                    'L\'upload di file usa un endpoint diverso (`POST /webapi/uploads`, multipart/form-data) e non è descritto qui.',
                    '',
                    '**Servizi**',
                    '',
                    ...$elenco,
                ]),
            ],
            'servers' => self::server('/webapi'),
            'security' => [['passepartout' => []]],
            'paths' => [
                '/servizi' => [
                    'post' => [
                        'operationId' => 'servizi',
                        'summary' => 'Esegue un servizio del gestionale',
                        'parameters' => [self::ref('CoordinateGestionale', 'parameters')],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'oneOf' => array_map(static fn ($ref) => ['$ref' => $ref], array_values($mapping)),
                                'discriminator' => ['propertyName' => 'cmd', 'mapping' => $mapping],
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Esito del servizio. JSON per la maggior parte dei servizi, PDF per le stampe.',
                                'content' => [
                                    'application/json' => ['schema' => ['anyOf' => $risposte]],
                                    'application/pdf' => ['schema' => ['type' => 'string', 'contentMediaType' => 'application/pdf']],
                                ],
                            ],
                            '400' => self::ref('ErroreRichiesta', 'responses'),
                            '401' => self::ref('NonAutorizzato', 'responses'),
                            '503' => self::ref('PoolEsaurito', 'responses'),
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => self::sicurezza(),
                'parameters' => ['CoordinateGestionale' => self::parametriComuni()['CoordinateGestionale']],
                'responses' => self::risposteErrore(),
                'schemas' => $schemi + ['Errore' => self::schemiComuni()['Errore']],
            ],
        ];
    }

    // ------------------------------------------------------------------ componenti comuni

    /**
     * @return list<array<string, mixed>>
     */
    private static function server(string $base): array
    {
        return [
            [
                'url' => 'https://{host}:{porta}'.$base,
                'description' => 'Installazione locale (certificato spesso self-signed)',
                'variables' => [
                    'host' => ['default' => 'localhost'],
                    'porta' => ['default' => '9004'],
                ],
            ],
            [
                'url' => 'https://services.passepartout.cloud'.$base,
                'description' => 'Passepartout Live (cloud): aggiungere Dominio=<DOMINIO> all\'header Authorization',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sicurezza(): array
    {
        return [
            'passepartout' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'Authorization',
                'description' => 'Formato `Passepartout <base64(utente:password)>`. Sul cloud: `Passepartout <base64> Dominio=<DOMINIO>`. '
                    .'Con login di sistema operativo attivo (login=1) si aggiunge un secondo blocco `<base64(utente_so:password_so)>`.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parametriComuni(): array
    {
        return [
            'CoordinateGestionale' => [
                'name' => 'Coordinate-Gestionale',
                'in' => 'header',
                'required' => true,
                'description' => 'Azienda e anno di lavoro, più sotto-azienda e magazzino di default facoltativi (magazzino 1 se omesso).',
                'schema' => [
                    'type' => 'string',
                    'pattern' => '^Azienda=\S+( SottoAzienda=\S+)? Anno=\d{4}( Magazzino=\d+)?$',
                    'examples' => ['Azienda=DEM Anno=2026 Magazzino=1'],
                ],
            ],
            'Max' => [
                'name' => 'max',
                'in' => 'query',
                'description' => 'Numero massimo di record per pagina. Il server può restituirne meno (limiti di 30 s e 50 MB).',
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
            'Next' => [
                'name' => 'next',
                'in' => 'query',
                'description' => 'Token di pagina restituito dalla risposta precedente: opaco, da usare subito e così com\'è.',
                'schema' => ['type' => 'string'],
            ],
            'Encoding' => [
                'name' => 'encoding',
                'in' => 'query',
                'description' => 'Codifica delle parti di chiave nel path. Obbligatoria quando un codice contiene `/` o `\\`: il codice va inviato in esadecimale (es. `ARTI/S` -> `415254492f53`).',
                'schema' => ['type' => 'string', 'enum' => ['hex', 'base32']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function risposteErrore(): array
    {
        $errore = static fn (string $d): array => [
            'description' => $d,
            'content' => ['application/json' => ['schema' => self::ref('Errore')]],
        ];

        return [
            'ErroreRichiesta' => $errore('Richiesta non valida: parametri o campi errati, errore gestionale (6001), coordinate mancanti.'),
            'NonAutorizzato' => $errore('Credenziali assenti o errate. Attenzione: tentativi ripetuti bloccano l\'utente WebAPI.'),
            'NonTrovato' => ['description' => 'Risorsa inesistente o path non valido (nessun body).'],
            'PoolEsaurito' => $errore('Pool di 5 servizi dell\'utente WebAPI esaurito: riprovare più tardi, senza retry aggressivi.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemiComuni(): array
    {
        return [
            'Filtro' => [
                'type' => 'object',
                'required' => ['campo', 'condizione', 'valore'],
                'properties' => [
                    'campo' => ['type' => 'string', 'description' => 'Nome del campo su cui filtrare.'],
                    'indice1' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Indice del primo livello, per i campi array.'],
                    'indice2' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Indice del secondo livello, per gli array a due livelli.'],
                    'condizione' => ['type' => 'string', 'enum' => ['=', '<>', '>', '<', '>=', '<=', 'contiene', 'inizia_per']],
                    'case_insensitive' => ['type' => 'boolean', 'default' => false, 'description' => 'Solo per i confronti fra stringhe.'],
                    'valore' => [
                        'description' => 'Valore da confrontare; un array mette i valori in OR. Date come AAAAMMGG.',
                        'anyOf' => [
                            ['type' => 'string'],
                            ['type' => 'number'],
                            ['type' => 'array', 'minItems' => 1, 'items' => ['type' => ['string', 'number']]],
                        ],
                    ],
                ],
            ],
            'Errore' => [
                'type' => 'object',
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'response-code' => ['type' => 'integer'],
                            'response-detail' => ['type' => 'string', 'description' => 'Codice numerico + messaggio: "<codice> - <messaggio> [<dettaglio>]". 6001 = errore gestionale.'],
                            'response-message' => ['type' => 'string'],
                            'request-uri' => ['type' => 'string'],
                            'request-method' => ['type' => 'string'],
                            'request-id' => ['type' => 'string', 'description' => 'UUID da citare al supporto.'],
                            'warsion' => ['type' => 'string'],
                            'remote' => ['type' => 'string'],
                            'timestamp' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{'$ref': string}
     */
    private static function ref(string $nome, string $sezione = 'schemas'): array
    {
        return ['$ref' => '#/components/'.$sezione.'/'.$nome];
    }
}
