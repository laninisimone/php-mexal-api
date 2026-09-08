# Documentazione — php-mexal-api

Client PHP framework-agnostic per le WebAPI Passepartout Mexal / Passcom, allineato al manuale
WebAPI v3.1.

## Indice

1. **[Guida rapida](quickstart.md)** — dall'installazione alla prima chiamata
2. **[Configurazione](configuration.md)** — connessioni, istanze, timeout, retry, client HTTP
3. **[Risorse](resources.md)** — CRUD, ricerca, paginazione, sotto-risorse, allegati, MyDB
4. **[Canale servizi](services.md)** — `cmd`, `dati`, parametri root, risposte non JSON
5. **[Errori](errors.md)** — gerarchia delle eccezioni, codici, diagnostica
6. **[Sicurezza](security.md)** — TLS, credenziali, log, superficie di attacco
7. **[Integrazione con i framework](framework-integration.md)** — Laravel, Symfony, Slim, CLI

## Mappa dei concetti

Tre oggetti bastano a capire tutto il resto.

| Oggetto | Risponde a | Esempio |
| --- | --- | --- |
| `Connection` | *a chi* parlo | server, credenziali, TLS |
| `Instance` | *dove* leggo e scrivo | azienda, sotto-azienda, anno, magazzino |
| `Mexal` | *come* li combino | sceglie connessione + istanza e costruisce i client |

```
Mexal ──> MexalClient ──> ResourceClient      (CRUD, ricerca, paginazione)
  │            │
  │            └────────> servizi()           (canale /servizi, non REST)
  │
  └────────> Connector                        (HTTP puro: header, URL, retry)
                 │
                 └──────> client PSR-18       (Guzzle, Symfony, il tuo)
```

Il `Connector` non conosce la semantica del gestionale, e `ResourceClient` non conosce l'HTTP:
la separazione serve a poter correggere il protocollo in un punto solo.

## Un promemoria che vale più di questa documentazione

Le WebAPI non pubblicano uno schema OpenAPI, ma **si documentano da sole** — ed è l'unica fonte
allineata alla versione del gestionale che hai davvero installato:

```php
// Tutti gli endpoint esposti da questa installazione
$endpoint = $mexal->help(extended: true);

// Tutti i campi di un endpoint, con tipo e obbligatorietà
$campi = $mexal->resource('clienti')->info();
```

L'enum `MexalResource` è un elenco statico: comodo per l'autocompletamento dell'IDE, ma va
verificato contro `help()` quando qualcosa non torna.
