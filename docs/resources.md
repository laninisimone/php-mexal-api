# Risorse

Tutto ciò che si fa su un endpoint REST del gestionale: leggere, cercare, scrivere, seguire le
sotto-risorse e scaricare gli allegati.

Un `ResourceClient` si ottiene da `$mexal->resource(...)` ed è **stateless**: crearne uno non
costa una chiamata di rete.

```php
use Simonelanini\PhpMexalApi\Enums\MexalResource;

$clienti = $mexal->resource(MexalResource::CLIENTI);   // consigliato: autocompletamento
$clienti = $mexal->resource('clienti');                // equivalente
```

## Lettura di collezioni

```php
$tutti = $mexal->resource(MexalResource::CLIENTI)->list([
    'fields' => 'codice,ragione_sociale',   // riduce il payload
    'max' => 500,                           // record per pagina
]);
```

`list()` segue la catena dei `next` e restituisce un unico array.

### Lettura lazy

`list()` e `search()` tengono in memoria tutte le pagine insieme. Sugli archivi grossi conviene
`cursor()` / `searchCursor()`, che restituiscono un `Generator` e scaricano una pagina solo
quando il ciclo la raggiunge:

```php
foreach ($mexal->resource(MexalResource::ARTICOLI)->cursor(['fields' => 'codice']) as $articolo) {
    // un record alla volta, memoria costante
}

// Interrompere presto significa non fare le chiamate rimanenti
foreach ($mexal->resource(MexalResource::ARTICOLI)->cursor() as $i => $articolo) {
    if ($i >= 50) {
        break;   // le pagine successive non vengono nemmeno richieste
    }
}
```

### Cosa succede lato server

- una GET legge tutti i record che riesce a processare **entro 30 secondi o entro 50 MB**;
  superata una delle due soglie inizia a paginare;
- `max` è prioritario, ma le due soglie restano vincolanti: chiedendo `max=1000` potresti
  ricevere 700 record e un `next`;
- il valore di `next` è una **stringa opaca** legata a un semaforo di sessione. Va usata subito:
  riutilizzare un `next` vecchio, dopo che l'archivio è stato iterato per intero, produce un
  errore. Il client la usa immediatamente, quindi non devi gestirla tu;
- sugli endpoint REST `next` viaggia in query string, sul canale servizi nel body. Anche di
  questo si occupa il client.

## Lettura di un singolo record

```php
$cliente = $mexal->resource(MexalResource::CLIENTI)->get('501.00001');
```

### Codici con slash o backslash

I codici che contengono `/` o `\` non possono essere url-encodati: il gestionale li rifiuta e
richiede la codifica esadecimale, dichiarata in query string. Il client se ne occupa da solo su
`get()`, `update()`, `delete()`, `sub()` e sugli allegati:

```php
$mexal->resource(MexalResource::ARTICOLI)->get('ARTI/S');
// GET /webapi/risorse/articoli/415254492f53?encoding=hex
```

Gli altri caratteri speciali vengono url-encodati normalmente. Non devi pre-codificare nulla:
passa sempre il codice come lo vedi nel gestionale.

## Ricerca

```php
$risultati = $mexal->resource(MexalResource::ARTICOLI)->search([
    'filtri' => [
        ['campo' => 'tipologia', 'condizione' => '=', 'valore' => 'A'],
        [
            'campo' => 'descrizione',
            'condizione' => 'contiene',
            'case_insensitive' => true,     // default: false
            'valore' => ['BICI', 'MTB', 'BDC'],
        ],
    ],
], ['fields' => 'codice,descrizione']);
```

- la chiave è `condizione`, non `operatore`;
- condizioni ammesse: `=`, `<`, `<=`, `>`, `>=`, `<>`, `contiene`, `inizia_per`;
- i filtri sono in **AND** fra loro, più valori nello stesso filtro sono in **OR**;
- per i campi di tipo array si aggiungono `indice1` e `indice2`.

Le entità master-detail (documenti, liste prelievo, prima nota) tengono testate e righe su
endpoint distinti, ricerca compresa:

```php
$righe = $mexal->resource(MexalResource::DOCUMENTI_ORDINI_CLIENTI_RIGHE)->search([
    'filtri' => [['campo' => 'codice_articolo', 'condizione' => '=', 'valore' => 'ART001']],
]);
```

## Creazione

```php
// Crea e rilegge la risorsa: due chiamate HTTP
$nuovo = $mexal->resource(MexalResource::CLIENTI)->create([
    'ragione_sociale' => 'ACME SPA',
    'tp_nazionalita' => 'I',
]);

// Crea e restituisce il solo codice: una chiamata HTTP
$codice = $mexal->resource(MexalResource::ARTICOLI)->createAndGetId([
    'codice' => 'ARTICOLO1',
    'descrizione' => 'Articolo di prova',
]);
```

Entrambe leggono l'header `Location` restituito dal gestionale. Se la risorsa non lo espone
viene sollevata `ProtocolException`: in quel caso usa il connector direttamente.

## Modifica ed eliminazione

```php
$mexal->resource(MexalResource::CLIENTI)->update('501.00001', [
    'ragione_sociale' => 'ACME SRL',
    'data_ult_mod' => '20250903 101500',
]);

// Query string aggiuntiva (es. modifica della sola testata di un documento)
$mexal->resource(MexalResource::DOCUMENTI_ORDINI_CLIENTI)
    ->update('OC/1/13', $testata, ['solo_testata' => 'true']);

$mexal->resource(MexalResource::CLIENTI)->delete('501.00001');
```

Entrambe restituiscono `true` e sollevano `RequestException` se il gestionale non risponde 204.

### Aggiornamenti concorrenti

In revisione il gestionale può controllare la data di ultima modifica, per evitare che tu
sovrascriva modifiche fatte nel frattempo da altri. È un *optimistic locking*: se la data che
mandi non coincide con quella attuale, la revisione viene rifiutata.

| Campo | Tipo | Quando |
| --- | --- | --- |
| `data_ult_mod` | scalare | revisione (PUT) di una singola risorsa |
| `dt_ult_mod_orig` | array | trasformazione documento via POST, un elemento per testata di origine |

Il valore si legge dalla GET e va ritrasmesso **così com'è**:

```php
$cliente = $mexal->resource(MexalResource::CLIENTI)->get('501.00001');

$mexal->resource(MexalResource::CLIENTI)->update('501.00001', [
    'ragione_sociale' => 'ACME SRL',
    'data_ult_mod' => $cliente['data_ult_mod'],
]);
```

Il client non lo fa per te: leggi la risorsa, conserva il campo e rimandalo nella `update()`.

Il controllo è opzionale e si abilita lato gestionale da *Servizi → Configurazioni →
Configurazione moduli → WebApi*. Quando è disattivo vale il last-write-wins.

## Sotto-risorse

Alcune entità appendono sotto-risorse al codice del record — i progressivi e gli abbinati di un
articolo, per esempio. `sub()` restituisce un `ResourceClient` completo su quel path, con
l'eventuale `encoding=hex` del codice già propagato:

```php
$articoli = $mexal->resource(MexalResource::ARTICOLI);

$progressivi = $articoli->sub('ART001', 'progressivi')->list();
$abbinato    = $articoli->sub('ART001', 'abbinati')->get('ABB001+1');

$articoli->sub('ART001', 'abbinati')->create(['codice' => 'ABB001', 'prog_abbinati' => 1]);
```

## Allegati

```php
$contentType = null;
$immagine = $mexal->resource(MexalResource::ARTICOLI)
    ->allegato('FUELEX97', 'immagine-catalogo', $contentType);
// $contentType: image/jpeg, application/pdf, ...
```

I tipi previsti dal manuale per gli articoli sono `icona`, `immagine-catalogo` e `immagine`.

## Archivi MyDB

Gli archivi MyDB stanno sotto `risorse/mydb/<APP>@<archivio>`, dove `APP` è l'app Passbuilder e
`archivio` il nome dell'archivio. Il secondo argomento è la sigla documento (`sigla_doc`), per
gli archivi collegati a documenti o parcelle:

```php
$records = $mexal->mydb('MIA_APP@ANAGRAFICHE')->list();
$ordini  = $mexal->mydb('MIA_APP@ANAGRAFICHE', 'OC')->list();
$record  = $mexal->mydb('MIA_APP@ANAGRAFICHE', 'OC')->get('42');

// Un valore senza "@" viene completato con "@mydb", il nome usato dagli esempi del manuale
$records = $mexal->mydb('MIA_APP')->list();   // risorse/mydb/MIA_APP@mydb
```

## Metadati dell'endpoint

```php
$info = $mexal->resource(MexalResource::CLIENTI)->info();   // GET ?info=true
```

Restituisce i campi dell'endpoint con tipo e obbligatorietà. È la fonte da consultare quando il
gestionale risponde `6001 - errore gestionale [campo non valido]`.

## Endpoint non coperti

Per tutto ciò che esce dal CRUD standard c'è il connector:

```php
$response = $mexal->connector()->get('risorse/un/endpoint/particolare', ['param' => 'valore']);

if ($response->successful()) {
    $dati = $response->records();
}
```

`Response` espone `status()`, `successful()`, `header()`, `body()`, `json()`, `records()` e la
risposta PSR-7 originale con `psr()`.

## Selezionare connessione e istanza

```php
$clientiCollaudo = $mexal->resource(MexalResource::CLIENTI, 'collaudo', 'archivio')->list();
```

Per coordinate costruite al volo, vedi
[Configurazione → Coordinate costruite al volo](configuration.md#coordinate-costruite-al-volo).

## Limiti del servizio WebAPI

Da tenere presente nel dimensionare integrazioni e job:

- il gruppo *Servizi WebAPI* gestisce al massimo **3 utenti**;
- ogni utente dispone di un pool di **5 servizi contemporanei**, con timeout di 5 minuti per
  servizio. Oltre i 5, le richieste successive vengono **rifiutate** finché non si libera uno
  slot: evita il parallelismo spinto e i retry aggressivi;
- lato gestionale, le procedure ad uso esclusivo (switch-to, anagrafica azienda, configurazione
  moduli) chiedono la chiusura dei processi WebAPI attivi.
