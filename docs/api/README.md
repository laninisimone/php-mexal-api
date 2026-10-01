# API: OpenAPI e schema per agenti AI

Le WebAPI Passepartout non pubblicano uno schema OpenAPI, ma si descrivono da sole:
`GET /risorse/help?extended=true` elenca gli endpoint e `GET /risorse/<risorsa>?info=true`
elenca i campi di una risorsa. Questa cartella trasforma queste informazioni, integrate con il
manuale WebAPI v3.1, in due prodotti:

- **specifiche OpenAPI 3.1**, una per gruppo di risorse, da usare con Swagger, Postman, i
  generatori di client e gli strumenti di test;
- **uno schema per agenti AI**: tool in formato MCP, il catalogo delle risorse con i campi, le
  chiavi e le relazioni fra archivi, e una guida d'uso per il modello.

Tutto è generato da `tools/api-docs/genera.php`. Non modificare a mano i file in `openapi/` e
in `ai/` (tranne `ai/AGENTE.md`): vengono sovrascritti a ogni generazione.

## Contenuto

| Percorso | Cosa contiene |
| --- | --- |
| `openapi/<gruppo>.yaml` | Un documento OpenAPI 3.1 per ogni gruppo dell'help: `clienti`, `articoli`, `documenti`, `dati-generali`, … (25 file) |
| `openapi/servizi.yaml` | Il canale `/servizi`: un'unica POST con un body per ogni `cmd`, scelto con un discriminatore |
| `ai/mexal-mcp-tools.json` | 9 tool generici nel formato della risposta MCP `tools/list` |
| `ai/mexal-catalogo.json` | Il catalogo letto dai tool: 86 risorse con operazioni, chiavi, campi, obbligatorietà e relazioni; 41 servizi con lo schema del body |
| `ai/indice-risorse.md` | La mappa compatta delle risorse, da mettere nel contesto del modello |
| `ai/AGENTE.md` | La guida per il modello: procedura, formati, regole di scrittura, errori, esempi (scritta a mano) |
| `sorgenti/help.json` | Istantanea di `help?extended=true` |
| `sorgenti/info/*.json` | Istantanee di `?info=true`, una per collezione. Contengono solo metadati: dagli endpoint che rispondono con dati veri vengono salvati i nomi dei campi e i tipi dedotti, mai i valori |
| `sorgenti/manuale.json` | Ciò che il generatore ricava dal manuale v3.1 |
| `sorgenti/relazioni.json` | Da quale archivio prendere i valori di un campo codice (curato a mano) |
| `sorgenti/descrizioni.json` | Le descrizioni delle risorse principali (curate a mano) |

## Da dove viene ogni informazione

| Informazione | Fonte |
| --- | --- |
| Endpoint, metodi, paginazione, `fields`, ricerca, coordinate, chiavi e loro tipi | help in linea |
| Campi, tipi, dimensione degli array, campi chiave, campi da indicare sempre in revisione | `?info=true` |
| Campi obbligatori in inserimento, parametri speciali (`solo_testata`, `sigla_trasformazione`, `storicizza`, …), note operative | manuale v3.1 |
| Campi delle risorse che sull'installazione interrogata non rispondono a `?info=true` | manuale v3.1 |
| Canale servizi | manuale v3.1 |
| Relazioni fra campi e archivi | `sorgenti/relazioni.json` |

Le istantanee attuali vengono da Mexal 2.9.17, versione gestionale 88201, su un'azienda di
livello 2. Su quell'azienda le risorse dei moduli di produzione rispondono "disponibile solo per
aziende di livello produzione"; per lotti, distinte base e impegni i campi vengono quindi dal
manuale. Ogni risorsa dichiara la provenienza dei suoi campi: in OpenAPI con
`x-mexal-origine-campi`, nel catalogo con `origine_campi`. I valori possibili sono `info`,
`manuale`, `campione` (struttura dedotta dalla risposta) e `nessuna`.

Risorse senza campi in questa versione: `dati-generali/correlazione-unita-misura`,
`dati-generali/abbinamenti-colori`, `documenti/lavorazione/prodotti`,
`documenti/lavorazione/bolle`, `articoli/{codice}/allegati` (restituisce un file) e
`mydb/{nome_archivio}/{sigla_doc}`. I campi degli archivi MyDB dipendono dall'app Passbuilder.

## Le specifiche OpenAPI

Ogni file è autonomo, senza `$ref` verso altri file, e supera la validazione di
`openapi-spec-validator`. Si può aprire con Swagger Editor, Redocly, Scalar o Postman, oppure
controllare e pubblicare da riga di comando:

```bash
npx @redocly/cli lint docs/api/openapi/clienti.yaml
npx @redocly/cli build-docs docs/api/openapi/documenti.yaml -o documenti.html
```

Come sono modellate le particolarità delle WebAPI:

- **Array indicizzati.** Un campo con `dimensione_array` diventa un array di coppie
  `[indice, valore]`, descritte con `prefixItems` (JSON Schema 2020-12).
- **Documenti.** Per ogni entità con righe ci sono tre schemi. `<Nome>` è la testata, restituita
  da lista e ricerca. `<Nome>Righe` è la riga della vista trasversale `…/righe`.
  `<Nome>Documento` è il documento completo usato da lettura, inserimento e revisione: i campi
  di testata sono scalari e i campi di riga sono array `[[indice_riga, valore], …]`.
- **Inserimento e revisione.** `<Nome>Inserimento` riusa lo schema del record e aggiunge i campi
  obbligatori del manuale, più i campi che il manuale documenta solo in inserimento
  (`x-mexal-solo-inserimento`). `<Nome>Modifica` non impone campi obbligatori: l'elenco di
  `?info=true` dei campi da indicare sempre è in `x-mexal-obbligatori-put`.
- **Chiavi composte.** Compaiono nel path come `/{sigla}+{serie}+{numero}`. Le chiavi con parti
  facoltative generano un path per ogni variante.
- **Ricerca.** Il campo `campo` dei filtri è un enum con i campi della risorsa, e lo stesso enum
  tipizza il parametro `fields`.
- **Campi dichiarati due volte.** `?info=true` ripete alcuni campi: `data_ult_mod` nelle testate
  dei documenti, `codice_agente` e `imp_sps_prof` nello scadenzario, `intra_val_sta` nelle righe
  di prima nota. Confrontando con i dati reali (versione 88201), la forma scalare o array è quella
  della prima dichiarazione, mentre fra Alfanumerico e "Data e ora" vale il formato data-ora.
  L'altra descrizione resta tra parentesi.
- **Estensioni.** `x-mexal-tipo` (tipo originale del gestionale), `x-mexal-riferimento`
  (archivio da cui prendere il valore), `x-mexal-versione-minima`.

## Lo schema per gli agenti AI

### Perché pochi tool generici

Gli endpoint sono 268. Esporli come altrettanti tool riempirebbe il contesto del modello e
peggiorerebbe la scelta del tool giusto. Il pattern usato è invece "scopri, poi agisci": 9 tool
generici che ricevono il nome della risorsa, un tool che descrive i campi su richiesta, e un
catalogo che il server consulta per tradurre ogni chiamata in HTTP.

| Tool | Cosa fa | Implementazione con `simonelanini/php-mexal-api` |
| --- | --- | --- |
| `mexal_elenca_risorse` | elenco filtrabile delle risorse | lettura di `catalogo.risorse`, nessuna chiamata |
| `mexal_descrivi_risorsa` | campi, chiave, obbligatori, relazioni | voce del catalogo; con `aggiorna` → `$mexal->resource($path)->info()` |
| `mexal_lista` | una pagina di una collezione | `$mexal->connector()->get('risorse'.$path, ['fields' => …, 'max' => …, 'next' => …])` |
| `mexal_cerca` | ricerca con filtri | `$mexal->connector()->post('risorse'.$path.'/ricerca', ['filtri' => …], ['fields' => …, 'max' => …])` |
| `mexal_leggi` | record per chiave | `$mexal->resource($path)->get($chiave)` |
| `mexal_crea` | inserimento | `$mexal->resource($path)->createAndGetId($dati, $query)` |
| `mexal_modifica` | revisione | `$mexal->resource($path)->update($chiave, $dati, ['solo_testata' => 'true'])` |
| `mexal_elimina` | cancellazione | `$mexal->resource($path)->delete($chiave, $parametriQuery)` |
| `mexal_servizio` | canale servizi | `$mexal->servizi($cmd, $dati, root: $parametriRadice)` |

Note per chi implementa il server:

- `$chiave` si compone unendo con `+` le parti ricevute, nell'ordine di `formato_chiave` del
  catalogo. `ResourceClient` applica da solo la codifica esadecimale ai codici con `/` o `\`.
- Per le risorse con parametri nel path (`parametri_path`) si sostituiscono i segnaposto nel
  path del catalogo. Se il codice può contenere `/`, conviene usare `sub()`, che propaga la
  codifica: `$mexal->resource('articoli')->sub($codice, 'abbinati')`.
- `mexal_lista` e `mexal_cerca` restituiscono una pagina sola, con `next`, così il modello
  decide se proseguire. `list()` e `search()` del pacchetto scaricherebbero invece tutto.
- Le annotazioni MCP (`readOnlyHint`, `destructiveHint`, `idempotentHint`) dicono al client
  quali chiamate richiedono conferma. `mexal_elimina` e `mexal_modifica` sono distruttive.
- Il server non deve mai ritentare in automatico dopo un 401: il gestionale blocca l'utente
  WebAPI dopo pochi tentativi falliti.

Nel contesto del modello vanno `ai/AGENTE.md` e `ai/indice-risorse.md`. Il catalogo resta lato
server.

## Rigenerare

```bash
# Solo ricostruzione dalle istantanee: nessuna chiamata di rete
php tools/api-docs/genera.php costruisci

# Nuove istantanee dal gestionale, poi ricostruzione
MEXAL_URL=https://server MEXAL_PORT=9004 MEXAL_API_USER=… MEXAL_API_PASSWORD=… \
MEXAL_AZIENDA=XXX MEXAL_ANNO=2026 php tools/api-docs/genera.php
```

`scarica` legge la connessione con `MexalConfig::fromEnv()`, quindi con le stesse variabili del
pacchetto (`MEXAL_CONN` sceglie fra locale e cloud se sono configurati entrambi). Fa solo GET, in
sequenza: l'help, i dati dell'installazione e `?info=true` su ognuna delle 81 collezioni, 83 richieste
in tutto.

> **Attenzione alle credenziali.** Sul cloud Passepartout bastano pochi tentativi falliti per
> bloccare l'utente WebAPI, e per sbloccarlo serve un amministratore. Il generatore si ferma al
> primo rifiuto di autenticazione. Prima di lanciarlo, verifica quale connessione è attiva
> nell'ambiente. È meglio usare un utente WebAPI dedicato.

Per avere da `?info=true` anche i campi delle risorse di produzione, usa le coordinate di
un'azienda di livello produzione.

Quando esce una nuova versione del manuale:

```bash
pip install pypdf
python3 tools/api-docs/estrai-manuale.py ManWebapi.pdf docs/api/sorgenti/manuale.json
php tools/api-docs/genera.php costruisci
```

Gli intervalli di pagina delle strutture di ripiego, in `STRUTTURE` dentro lo script, vanno
verificati sulla nuova versione.

`sorgenti/relazioni.json` e `sorgenti/descrizioni.json` si modificano a mano. Il generatore si
interrompe se fanno riferimento a una risorsa che non esiste.
