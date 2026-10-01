<?php

declare(strict_types=1);

namespace MexalApiDocs;

/**
 * Artefatti per un agente AI:
 *
 *   mexal-mcp-tools.json   definizioni dei tool nel formato di risposta MCP "tools/list":
 *                          pochi tool generici (elenca, descrivi, lista, cerca, leggi, crea,
 *                          modifica, elimina, servizio) invece di uno per endpoint, così il
 *                          modello non riceve centinaia di tool e scopre i campi su richiesta
 *   mexal-catalogo.json    il catalogo che quei tool consultano: risorse, chiavi, campi,
 *                          obbligatorietà e relazioni ("questo codice viene da quell'archivio")
 *   indice-risorse.md      la mappa compatta da mettere nel contesto del modello
 */
final class Ai
{
    public function __construct(private readonly Modello $m)
    {
    }

    /**
     * @return array<string, string>
     */
    public function file(): array
    {
        $catalogo = $this->catalogo();

        return [
            'mexal-mcp-tools.json' => self::json($this->tools($catalogo)),
            'mexal-catalogo.json' => self::json($catalogo),
            'indice-risorse.md' => $this->indice($catalogo),
        ];
    }

    // ------------------------------------------------------------------ catalogo

    /**
     * @return array<string, mixed>
     */
    private function catalogo(): array
    {
        $risorse = [];

        foreach ($this->m->risorse as $id => $r) {
            $risorse[$id] = $this->risorsa($r);
        }

        $servizi = [];
        foreach ($this->m->servizi as $s) {
            $servizi[$s['cmd']] = [
                'cmd' => $s['cmd'],
                'titolo' => $s['titolo'],
                'descrizione' => $s['descrizione'],
                'endpoint' => $s['endpoint'],
                'input_schema' => self::schemaServizio($s, false),
                'risposta' => array_map(static fn ($c) => ['nome' => $c['campo'], 'tipo' => strtolower($c['tipo']), 'descrizione' => $c['descrizione']], $s['risposta']),
                'note' => $s['note'],
            ];
        }

        return [
            'versione_catalogo' => 1,
            'generato_il' => $this->m->generatoIl,
            'gestionale' => $this->m->prodotto,
            'fonti' => [
                'help' => 'GET /webapi/risorse/help?extended=true',
                'campi' => 'GET /webapi/risorse/<risorsa>?info=true',
                'manuale' => 'Manuale WebAPI Passepartout v3.1',
                'relazioni' => 'docs/api/sorgenti/relazioni.json (curate a mano)',
            ],
            'convenzioni' => [
                'base_url_locale' => 'https://<host>:<porta>/webapi/risorse',
                'base_url_cloud' => 'https://services.passepartout.cloud/webapi/risorse',
                'autenticazione' => 'Authorization: Passepartout <base64(utente:password)> [Dominio=<DOMINIO> sul cloud]',
                'coordinate' => 'Coordinate-Gestionale: Azienda=<sigla> Anno=<AAAA> [SottoAzienda=<x>] [Magazzino=<n>], richiesto dove coordinate=true',
                'date' => 'AAAAMMGG; date-ora AAAAMMGG HHMMSS; decimali con il punto',
                'array' => 'liste di coppie [[indice, valore], ...]; nei documenti i campi di riga sono array per riga con indice = posizione della riga',
                'chiavi' => 'parti unite da + nel path (es. OC+1+15); codici con / o \\ in esadecimale con ?encoding=hex',
                'paginazione' => 'max limita i record; se la risposta contiene next lo si ripassa subito e così com\'è',
                'ricerca' => 'POST <risorsa>/ricerca {"filtri":[{"campo","condizione","valore"}]}: filtri in AND, valori array in OR; condizioni =, <>, >, <, >=, <=, contiene, inizia_per',
                'revisione' => 'PUT con i soli campi da modificare più data_ult_mod letto dalla GET; nei documenti indicare sempre tutti gli id_riga e tp_riga oppure usare ?solo_testata=true',
                'esito' => 'GET 200 con JSON; POST 201 con header Location e nessun body; PUT e DELETE 204',
                'limiti' => 'max 5 richieste simultanee per utente WebAPI (503 oltre); credenziali errate ripetute bloccano l\'utente',
            ],
            'risorse' => $risorse,
            'servizi' => $servizi,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function risorsa(array $r): array
    {
        $operazioni = [];
        foreach (Modello::OPERAZIONI as $tipo) {
            foreach ($r['operazioni'][$tipo] ?? [] as $op) {
                $o = [
                    'metodo' => $op['metodo'],
                    'path' => match ($tipo) {
                        'lista', 'crea' => $r['path'],
                        'cerca' => $r['path'].'/ricerca',
                        default => $op['path'],
                    },
                ];
                if (in_array($tipo, ['lista', 'cerca'], true)) {
                    $o['paginata'] = $op['flag']['next'];
                }
                if ($tipo === 'crea') {
                    $o['obbligatori'] = $r['richiesti_crea'];
                }
                $o['versione_minima'] = $op['versione'];
                $operazioni[$tipo][] = $o;
            }
        }

        $campi = [];
        foreach ($r['campi'] as $c) {
            $campi[] = $this->campo($r, $c, false);
        }
        foreach ($r['campi_solo_inserimento'] as $c) {
            $campi[] = $this->campo($r, $c, false) + ['solo_inserimento' => true];
        }

        $out = [
            'id' => $r['id'],
            'titolo' => $r['titolo'],
            'gruppo' => $r['gruppo'],
            'descrizione' => $r['descrizione'],
            'coordinate' => $r['coordinate'],
        ];

        if ($r['parametri_path'] !== []) {
            $out['parametri_path'] = array_map(self::parteChiave(...), $r['parametri_path']);
        }

        if ($r['chiave'] !== []) {
            $out['chiave'] = array_map(self::parteChiave(...), $r['chiave']);
            $out['formato_chiave'] = implode('+', array_map(static fn ($p) => '{'.$p['nome'].'}'.($p['opzionale'] ? '?' : ''), $r['chiave']));
        }

        $out['operazioni'] = $operazioni;

        if (isset($r['testata'])) {
            $out['testata'] = $r['testata'];
        }

        if (isset($r['righe'])) {
            $out['righe'] = $r['righe'];
            $out['campi_riga_nel_documento'] = array_values(array_diff(
                array_keys($this->m->risorse[$r['righe']]['campi']),
                array_keys($r['campi']),
            ));
        }

        $out['forma_risposta'] = $r['forma'];
        $out['origine_campi'] = $r['origine_campi'];
        $out['campi'] = $campi;

        if ($r['relazioni'] !== []) {
            $out['relazioni'] = $r['relazioni'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function campo(array $r, array $c, bool $comeRiga): array
    {
        $schema = Modello::schemaCampo($c, $comeRiga, false);

        $out = [
            'nome' => $c['nome'],
            'tipo' => $schema['type'] ?? 'any',
            'descrizione' => trim($c['descrizione'].(isset($c['descrizione_manuale']) && stripos($c['descrizione'], $c['descrizione_manuale']) === false ? ' — '.$c['descrizione_manuale'] : '')),
        ];

        if ($c['dimensione'] !== null) {
            $out['tipo_valore'] = Modello::schemaCampo(['dimensione' => null] + $c, false, false)['type'] ?? 'any';
            $out['array'] = $c['dimensione'] > 0 ? ['formato' => '[[indice, valore], ...]', 'dimensione' => $c['dimensione']] : ['formato' => '[[indice, valore], ...]'];
        }

        if ($c['tipo'] === 'Data e ora') {
            $out['formato'] = 'AAAAMMGG HHMMSS';
        } elseif ($c['tipo'] === 'Alfanumerico' && str_contains($schema['description'], 'formato AAAAMMGG')) {
            $out['formato'] = 'AAAAMMGG';
        }

        foreach ([
            'chiave' => $c['chiave'],
            'obbligatorio_crea' => in_array($c['nome'], $r['richiesti_crea'], true),
            'obbligatorio_modifica' => $c['obbligatorio_put'],
            'sola_lettura' => ($schema['readOnly'] ?? false) === true,
        ] as $flag => $attivo) {
            if ($attivo) {
                $out[$flag] = true;
            }
        }

        if (isset($c['riferimento'])) {
            $out['riferimento'] = $c['riferimento'];
        }

        if (isset($c['campi'])) {
            $out['campi'] = array_map(fn ($s) => $this->campo($r, $s, false), $c['campi']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function parteChiave(array $p): array
    {
        $schema = OpenApi::schemaParametro($p);
        $out = ['nome' => $p['nome'], 'tipo' => $schema['type']];

        foreach (['enum', 'pattern'] as $k) {
            if (isset($schema[$k])) {
                $out[$k] = $schema[$k];
            }
        }

        if ($p['encodable'] && $p['tipo'] === 'string') {
            $out['codificabile'] = true;
        }

        if ($p['opzionale'] ?? false) {
            $out['opzionale'] = true;
        }

        return $out;
    }

    /**
     * Schema JSON del body di un servizio: cmd, dati e i parametri che stanno accanto a cmd.
     *
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    public static function schemaServizio(array $s, bool $estensioni): array
    {
        $descrizione = $s['descrizione'];
        if ($s['note'] !== []) {
            $descrizione .= "\n\n".implode("\n", array_map(static fn ($n) => '- '.$n, $s['note']));
        }

        $schema = [
            'type' => 'object',
            'title' => $s['titolo'],
            'description' => $descrizione,
            'required' => ['cmd'],
            'properties' => ['cmd' => ['type' => 'string', 'const' => $s['cmd']]],
        ];

        foreach ($s['radice'] as $nome => $p) {
            $schema['properties'][$nome] = $p['schema'];
            if ($p['obbligatorio']) {
                $schema['required'][] = $nome;
            }
        }

        $dati = ['type' => 'object', 'description' => 'Parametri del servizio.'
            .($s['annidati'] !== [] ? ' Struttura degli elementi annidati: '.implode('; ', $s['annidati']).'.' : '')];
        foreach ($s['dati'] as $nome => $p) {
            $dati['properties'][$nome] = $p['schema'];
            if ($p['obbligatorio']) {
                $dati['required'][] = $nome;
            }
        }
        $schema['properties']['dati'] = $dati;

        if ($s['dati_obbligatorio']) {
            $schema['required'][] = 'dati';
        }

        $schema['properties']['next'] = ['type' => 'string', 'description' => 'Token di paginazione ricevuto nella risposta precedente, accanto a cmd.'];

        if ($estensioni) {
            $schema['x-mexal-endpoint'] = $s['endpoint'];
        }

        return $schema;
    }

    // ------------------------------------------------------------------ tool MCP

    /**
     * @param array<string, mixed> $catalogo
     * @return array<string, mixed>
     */
    private function tools(array $catalogo): array
    {
        $con = static fn (string $tipo): array => array_keys(array_filter(
            $catalogo['risorse'],
            static fn ($r) => isset($r['operazioni'][$tipo]),
        ));

        $risorsa = static fn (string $tipo, string $descrizione): array => [
            'type' => 'string',
            'enum' => $tipo === '*' ? array_keys($catalogo['risorse']) : $con($tipo),
            'description' => $descrizione,
        ];

        $parametriPath = [
            'type' => 'object',
            'description' => 'Solo per le risorse con parametri nel path (es. {"codice": "ART01"} per articoli/{codice}/allegati, {"nome_archivio": "APP@archivio"} per mydb). Vedi parametri_path in mexal_descrivi_risorsa.',
            'additionalProperties' => ['type' => ['string', 'integer']],
        ];

        $chiave = [
            'type' => 'object',
            'description' => 'Parti della chiave per nome, nell\'ordine di formato_chiave restituito da mexal_descrivi_risorsa. Es. {"codice": "501.00001"} per clienti, {"sigla": "OC", "serie": 1, "numero": 15} per documenti/ordini-clienti. Passare i codici come appaiono nel gestionale: la codifica esadecimale dei codici con / o \\ è automatica.',
            'additionalProperties' => ['type' => ['string', 'integer']],
            'minProperties' => 1,
        ];

        $campi = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Campi da restituire (parametro fields). Indicare sempre solo quelli che servono: i record completi hanno centinaia di campi.',
        ];

        $max = ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 50, 'description' => 'Numero massimo di record della pagina.'];
        $next = ['type' => 'string', 'description' => 'Token next restituito dalla chiamata precedente, per la pagina successiva. Va usato subito.'];

        $filtro = [
            'type' => 'object',
            'required' => ['campo', 'condizione', 'valore'],
            'additionalProperties' => false,
            'properties' => [
                'campo' => ['type' => 'string', 'description' => 'Nome di un campo della risorsa.'],
                'condizione' => ['type' => 'string', 'enum' => ['=', '<>', '>', '<', '>=', '<=', 'contiene', 'inizia_per']],
                'valore' => [
                    'description' => 'Valore da confrontare. Un array mette i valori in OR. Date come AAAAMMGG.',
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'number'],
                        ['type' => 'array', 'minItems' => 1, 'items' => ['type' => ['string', 'number']]],
                    ],
                ],
                'case_insensitive' => ['type' => 'boolean', 'default' => false],
                'indice1' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Solo per campi array: indice dell\'elemento.'],
                'indice2' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Solo per array a due livelli.'],
            ],
        ];

        $collezione = [
            'type' => 'object',
            'required' => ['dati'],
            'properties' => [
                'dati' => ['type' => 'array', 'items' => ['type' => 'object']],
                'next' => ['type' => 'string', 'description' => 'Presente se ci sono altre pagine.'],
            ],
        ];

        $tools = [
            [
                'name' => 'mexal_elenca_risorse',
                'title' => 'Elenca le risorse del gestionale',
                'description' => 'Elenca gli archivi esposti dalle WebAPI di Mexal/Passcom (clienti, articoli, ordini, tabelle di dati generali, ...) con descrizione e operazioni disponibili. Usalo per scegliere la risorsa giusta prima di qualunque altra chiamata.',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'gruppo' => ['type' => 'string', 'enum' => array_keys($this->m->gruppi), 'description' => 'Limita a un gruppo.'],
                        'testo' => ['type' => 'string', 'description' => 'Filtra per testo su id e descrizione (es. "pagament", "ordini").'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_descrivi_risorsa',
                'title' => 'Descrive campi e chiavi di una risorsa',
                'description' => 'Restituisce la struttura di una risorsa: chiave (formato_chiave), operazioni, campi con tipo, descrizione, obbligatorietà in inserimento (obbligatorio_crea) e in revisione, formato delle date e degli array, e per i campi codice la risorsa da cui prendere il valore (riferimento). Chiamalo prima di mexal_cerca, mexal_crea o mexal_modifica: i nomi dei campi non vanno indovinati.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('*', 'Id della risorsa.'),
                        'campi' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Restituisce solo questi campi.'],
                        'solo_obbligatori' => ['type' => 'boolean', 'default' => false, 'description' => 'Solo chiave e campi obbligatori in inserimento.'],
                        'testo' => ['type' => 'string', 'description' => 'Solo i campi il cui nome o descrizione contiene il testo (es. "pagamento", "iva").'],
                        'aggiorna' => ['type' => 'boolean', 'default' => false, 'description' => 'Rilegge i campi dal gestionale con ?info=true invece che dal catalogo.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_lista',
                'title' => 'Legge una collezione',
                'description' => 'GET su una collezione: restituisce i record in "dati" e, se ce ne sono altri, il token "next". Senza filtri: per cercare record specifici usa mexal_cerca. Nei documenti restituisce solo le testate (le righe sono nella risorsa .../righe o nel documento letto con mexal_leggi).',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('lista', 'Id della risorsa.'),
                        'campi' => $campi,
                        'max' => $max,
                        'next' => $next,
                        'parametri_path' => $parametriPath,
                    ],
                ],
                'outputSchema' => $collezione,
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_cerca',
                'title' => 'Cerca record con filtri',
                'description' => 'POST <risorsa>/ricerca. I filtri sono in AND; un valore array mette i valori in OR. Esempi: cliente per nome {"campo":"ragione_sociale","condizione":"contiene","valore":"rossi","case_insensitive":true}; documenti di gennaio [{"campo":"data_documento","condizione":">=","valore":"20260101"},{"campo":"data_documento","condizione":"<=","valore":"20260131"}]. È il modo giusto per trovare il codice da usare in un campo con riferimento.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa', 'filtri'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('cerca', 'Id della risorsa.'),
                        'filtri' => ['type' => 'array', 'minItems' => 1, 'items' => $filtro],
                        'campi' => $campi,
                        'max' => $max,
                        'next' => $next,
                        'parametri_path' => $parametriPath,
                    ],
                ],
                'outputSchema' => $collezione,
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_leggi',
                'title' => 'Legge un record per chiave',
                'description' => 'GET su un singolo record. Per i documenti (ordini, preventivi, movimenti, prima nota, liste prelievo) restituisce testata e righe, con i campi di riga come array [[indice_riga, valore], ...]. Conserva data_ult_mod se poi devi modificare il record.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa', 'chiave'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('leggi', 'Id della risorsa.'),
                        'chiave' => $chiave,
                        'campi' => $campi,
                        'parametri_path' => $parametriPath,
                    ],
                ],
                'outputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_crea',
                'title' => 'Crea un record',
                'description' => 'POST di un nuovo record; restituisce la chiave assegnata (letta dall\'header Location). Prima: mexal_descrivi_risorsa per i campi obbligatori, e per ogni campo con riferimento un valore esistente trovato con mexal_cerca (mai inventare codici). Documenti: righe come array per riga, id_riga 0 per le righe nuove, numero 0 per la numerazione automatica (dove ammessa). Date AAAAMMGG. Non è idempotente: non ripetere la chiamata se l\'esito è incerto, verifica prima con mexal_cerca.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa', 'dati'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('crea', 'Id della risorsa.'),
                        'dati' => ['type' => 'object', 'description' => 'Campi del record, con i nomi di mexal_descrivi_risorsa.', 'minProperties' => 1],
                        'parametri_path' => $parametriPath,
                        'sigla_trasformazione' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$', 'description' => 'Solo documenti: trasforma i documenti di origine indicati nelle righe nella sigla data (es. "FT" da bolle BC). Richiede sigla_doc_orig, serie_doc_orig, numero_doc_orig, id_rif_testata e dt_ult_mod_orig per riga.'],
                    ],
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                        'chiave' => ['type' => 'string', 'description' => 'Chiave del record creato, es. OC+1+541.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_modifica',
                'title' => 'Modifica un record',
                'description' => 'PUT su un record esistente con i soli campi da cambiare, più data_ult_mod letto con mexal_leggi (controllo di modifica concorrente). Documenti: vanno indicati tutti gli id_riga e tp_riga esistenti, altrimenti le righe omesse vengono CANCELLATE; per cambiare solo la testata usa solo_testata=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa', 'chiave', 'dati'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('modifica', 'Id della risorsa.'),
                        'chiave' => $chiave,
                        'dati' => ['type' => 'object', 'minProperties' => 1, 'description' => 'Campi da modificare.'],
                        'solo_testata' => ['type' => 'boolean', 'default' => false, 'description' => 'Solo documenti: modifica la testata preservando le righe.'],
                        'parametri_path' => $parametriPath,
                    ],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_elimina',
                'title' => 'Elimina un record',
                'description' => 'DELETE di un record. Irreversibile: chiedi conferma all\'utente prima di usarlo, indicando quale record verrà eliminato.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['risorsa', 'chiave'],
                    'additionalProperties' => false,
                    'properties' => [
                        'risorsa' => $risorsa('elimina', 'Id della risorsa.'),
                        'chiave' => $chiave,
                        'parametri_path' => $parametriPath,
                        'parametri_query' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']], 'description' => 'Parametri aggiuntivi documentati per la risorsa (es. liste-prelievo: sigla, serie, numero, id_riga, prog_riga per cancellare una sola riga).'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'mexal_servizio',
                'title' => 'Esegue un servizio del gestionale',
                'description' => 'Canale /servizi per operazioni che non sono CRUD: calcoli (esposizione cliente, totali documento, condizioni di prezzo/sconto), stampe PDF, progressivi, CAP e zone, Docuvision, ecc. Lo schema di ciascun comando è nel catalogo (servizi.<cmd>.input_schema): i parametri vanno in dati, salvo quelli indicati accanto a cmd.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['cmd'],
                    'additionalProperties' => false,
                    'properties' => [
                        'cmd' => [
                            'type' => 'string',
                            'enum' => array_keys(array_filter($catalogo['servizi'], static fn ($s) => $s['endpoint'] === '/servizi')),
                            'description' => 'Comando del servizio.',
                        ],
                        'dati' => ['type' => 'object', 'description' => 'Parametri del servizio.'],
                        'parametri_radice' => ['type' => 'object', 'description' => 'Parametri da mettere accanto a cmd invece che in dati (es. esec_collage_server_remoto, get_prog_ubicazioni).'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
        ];

        return [
            'tools' => $tools,
            '_meta' => [
                'descrizione' => 'Definizioni dei tool nel formato della risposta MCP tools/list. L\'implementazione di ogni tool legge mexal-catalogo.json per path, chiavi e campi e chiama le WebAPI (es. con simonelanini/php-mexal-api).',
                'catalogo' => 'mexal-catalogo.json',
                'guida' => 'AGENTE.md',
                'gestionale' => $this->m->prodotto,
            ],
        ];
    }

    // ------------------------------------------------------------------ indice

    /**
     * @param array<string, mixed> $catalogo
     */
    private function indice(array $catalogo): string
    {
        $p = $this->m->prodotto;
        $out = [
            '# Indice delle risorse Mexal WebAPI',
            '',
            '> Generato da `tools/api-docs/genera.php` (gestionale '.($p['codice_prodotto'] ?? '').', versione '.($p['versione_prodotto'] ?? 'n/d').').',
            '> Mappa compatta per un agente: per ogni risorsa le operazioni, la chiave, i campi obbligatori in inserimento',
            '> e da quale archivio prendere i codici. Il dettaglio dei campi è in `mexal-catalogo.json`; le regole d\'uso in `AGENTE.md`.',
            '',
        ];

        // Prima le entità, in fondo le tabelle di dati generali: è l'ordine in cui servono.
        $gruppi = $this->m->gruppi;
        $tabelle = ['dati-generali' => $gruppi['dati-generali'] ?? []];
        unset($gruppi['dati-generali']);

        foreach ($gruppi + $tabelle as $gruppo => $ids) {
            $out[] = '## '.ucfirst(str_replace('-', ' ', $gruppo));
            $out[] = '';

            foreach ($ids as $id) {
                $r = $catalogo['risorse'][$id];
                $out[] = '### `'.$id.'`';
                $out[] = '';
                $out[] = $r['descrizione'].($r['coordinate'] || stripos($r['descrizione'], 'coordinate') !== false ? '' : ' Non richiede coordinate gestionale.');
                $out[] = '';
                $out[] = '- Operazioni: '.implode(', ', array_keys($r['operazioni']));

                if (isset($r['parametri_path'])) {
                    $out[] = '- Parametri nel path: '.implode(', ', array_map(static fn ($x) => '`'.$x['nome'].'`', $r['parametri_path']));
                }
                if (isset($r['formato_chiave'])) {
                    $out[] = '- Chiave: `'.$r['formato_chiave'].'`'.self::dettagliChiave($r['chiave']);
                }
                if (isset($r['testata'])) {
                    $out[] = '- Righe di `'.$r['testata'].'` (vista trasversale, sola lettura e ricerca)';
                }
                if (isset($r['righe'])) {
                    $out[] = '- Documento con righe: i campi di `'.$r['righe'].'` compaiono come array per riga nella lettura e nella scrittura del singolo documento';
                }

                $obbligatori = $r['operazioni']['crea'][0]['obbligatori'] ?? [];
                if ($obbligatori !== []) {
                    $out[] = '- Obbligatori in inserimento: '.implode(', ', array_map(static fn ($x) => '`'.$x.'`', $obbligatori));
                }

                $riferimenti = [];
                foreach ($r['campi'] as $c) {
                    if (isset($c['riferimento'])) {
                        $dest = implode(' / ', array_map(static fn ($x) => $x.'.'.$c['riferimento']['campo'], (array) $c['riferimento']['risorsa']));
                        $riferimenti[] = '`'.$c['nome'].'` → '.$dest;
                    }
                }
                if ($riferimenti !== []) {
                    $out[] = '- Codici da altri archivi: '.implode('; ', $riferimenti);
                }

                $out[] = '- Campi: '.count($r['campi']).' (origine: '.$r['origine_campi'].')';
                $out[] = '';
            }
        }

        $out[] = '## Canale servizi';
        $out[] = '';
        foreach ($catalogo['servizi'] as $s) {
            $out[] = '- `'.$s['cmd'].'` — '.$s['titolo'].($s['endpoint'] !== '/servizi' ? ' (endpoint '.$s['endpoint'].')' : '');
        }
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * @param list<array<string, mixed>> $chiave
     */
    private static function dettagliChiave(array $chiave): string
    {
        $dettagli = [];

        foreach ($chiave as $p) {
            if (isset($p['enum'])) {
                $dettagli[] = $p['nome'].' ∈ {'.implode(', ', $p['enum']).'}';
            } elseif (isset($p['pattern'])) {
                $dettagli[] = $p['nome'].' ~ '.$p['pattern'];
            }
        }

        return $dettagli === [] ? '' : ' ('.implode('; ', $dettagli).')';
    }

    private static function json(mixed $dati): string
    {
        return json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
