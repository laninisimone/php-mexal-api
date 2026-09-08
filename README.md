# PHP Mexal API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simonelanini/php-mexal-api.svg?style=flat-square)](https://packagist.org/packages/simonelanini/php-mexal-api)
[![Tests](https://img.shields.io/github/actions/workflow/status/simonelanini/php-mexal-api/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/simonelanini/php-mexal-api/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/simonelanini/php-mexal-api.svg?style=flat-square)](https://packagist.org/packages/simonelanini/php-mexal-api)
[![License](https://img.shields.io/packagist/l/simonelanini/php-mexal-api.svg?style=flat-square)](LICENSE.md)

Client PHP **framework-agnostic** per le WebAPI di Passepartout Mexal / Passcom.

Nessuna dipendenza da un framework: parla HTTP tramite **PSR-18**, quindi funziona in Laravel,
Symfony, Slim, WordPress o in uno script CLI, riusando il client HTTP che l'applicazione già ha.

Allineato al manuale WebAPI Passepartout v3.1.

---

## Requisiti

| Requisito | Versione | Note |
| --- | --- | --- |
| PHP | `^8.1` | testato su 8.1, 8.2, 8.3 e 8.4 |
| `ext-json` | — | sempre presente nelle installazioni standard |
| Client HTTP PSR-18 | — | `guzzlehttp/guzzle` (consigliato) oppure `symfony/http-client` |
| Implementazione PSR-7/17 | — | inclusa in Guzzle; altrimenti `nyholm/psr7` |

Lato gestionale serve il modulo **WebAPI** attivo e un utente del gruppo *Servizi WebAPI*.

> **Perché serve Guzzle o Symfony HttpClient?** PSR-18 descrive solo "manda una richiesta,
> ricevi una risposta": timeout e verifica del certificato non fanno parte dell'interfaccia.
> Sono però proprietà della singola connessione, e il pacchetto deve poterle garantire. Se
> hai già un client PSR-18 configurato, puoi iniettarlo e non installare nulla d'altro.

## Installazione

```bash
composer require simonelanini/php-mexal-api guzzlehttp/guzzle
```

Se la tua applicazione ha già un client PSR-18 (Laravel, Symfony e API Platform ne includono
uno), basta:

```bash
composer require simonelanini/php-mexal-api
```

## Configurazione minima

```php
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Mexal;

// Installazione on-premise
$mexal = Mexal::make(
    Connection::local(
        url: 'https://192.168.1.10',
        apiUser: 'utente_api',
        apiPassword: 'password_api',
        port: 9004,
    ),
    new Instance(azienda: 'DEM', anno: 2025),
);

// Passepartout Cloud
$mexal = Mexal::make(
    Connection::live(domain: 'dominio_xyz', apiUser: 'utente_api', apiPassword: 'password_api'),
    new Instance(azienda: 'DEM'),
);
```

Oppure da variabili d'ambiente (vedi [`.env.example`](.env.example)) o da un array di
configurazione (vedi [`config/mexal-api.example.php`](config/mexal-api.example.php)):

```php
$mexal = Mexal::fromEnv();
$mexal = Mexal::fromArray(require 'config/mexal-api.php');
```

Registra l'oggetto `Mexal` **una volta** nel container della tua applicazione e riusalo: ogni
istanza costruisce un client HTTP, e ricrearlo a ogni chiamata butta via il pool keep-alive.

## Uso rapido

```php
use Simonelanini\PhpMexalApi\Enums\MexalResource;

// Dati dell'installazione
$installazione = $mexal->installazione();

// Lettura di una collezione, con paginazione automatica
$clienti = $mexal->resource(MexalResource::CLIENTI)->list([
    'fields' => 'codice,ragione_sociale',
]);

// Lettura di un singolo record (i codici con / o \ sono codificati in automatico)
$articolo = $mexal->resource(MexalResource::ARTICOLI)->get('ARTI/S');

// Creazione: restituisce il solo codice, senza rileggere la risorsa
$codice = $mexal->resource(MexalResource::ARTICOLI)->createAndGetId([
    'codice' => 'ARTICOLO1',
    'descrizione' => 'Articolo di prova',
]);

// Lettura lazy: le pagine successive arrivano solo se il ciclo le raggiunge
foreach ($mexal->resource(MexalResource::ARTICOLI)->cursor() as $articolo) {
    // un record alla volta, memoria costante
}

// Ricerca con filtri
$risultati = $mexal->resource(MexalResource::ARTICOLI)->search([
    'filtri' => [
        ['campo' => 'descrizione', 'condizione' => 'contiene', 'valore' => 'BICI'],
    ],
]);

// Canale servizi (non REST: l'operazione si sceglie con "cmd")
$esposizione = $mexal->servizi('calcolo_esposizione', ['in_codice_conto' => '501.00022']);
```

### Errori del gestionale

```php
use Simonelanini\PhpMexalApi\Exceptions\RequestException;

try {
    $mexal->resource(MexalResource::ARTICOLI)->create(['codice' => 'ART001']);
} catch (RequestException $e) {
    $e->errorCode();   // 6001
    $e->hints();       // ['Aliquota iva obbligatoria']
    $e->requestId();   // UUID da citare al supporto Passepartout
}
```

Tutte le eccezioni del pacchetto implementano `MexalException`, quindi si possono catturare
insieme. Dettagli in [docs/errors.md](docs/errors.md).

## Funzionalità

- Connessioni multiple (locale / cloud) e istanze multiple (azienda, sotto-azienda, anno, magazzino)
- CRUD, ricerca con filtri, sotto-risorse, allegati binari, archivi MyDB e canale servizi
- Paginazione automatica, con variante lazy (`cursor()`) per gli archivi che non stanno in memoria
- Codifica automatica dei codici che contengono `/` o `\`
- Errori del gestionale tradotti in eccezioni con codice, causa, rimedio e `request-id`
- Retry selettivi: solo richieste idempotenti, solo errori transitori
- TLS verificato per default, credenziali mai serializzate né loggate
- Logging PSR-3 opzionale, senza header né body

## Documentazione

| Pagina | Contenuto |
| --- | --- |
| [Guida rapida](docs/quickstart.md) | dall'installazione alla prima chiamata, in cinque minuti |
| [Configurazione](docs/configuration.md) | connessioni, istanze, timeout, retry, client HTTP |
| [Risorse](docs/resources.md) | CRUD, ricerca, paginazione, sotto-risorse, allegati, MyDB |
| [Canale servizi](docs/services.md) | `cmd`, `dati`, parametri root, risposte non JSON |
| [Errori](docs/errors.md) | gerarchia delle eccezioni, codici, diagnostica |
| [Sicurezza](docs/security.md) | TLS, credenziali, log, superficie di attacco |
| [Integrazione con i framework](docs/framework-integration.md) | Laravel, Symfony, Slim, script CLI |

Le WebAPI non espongono uno schema OpenAPI: la documentazione sempre aggiornata è quella
integrata nel servizio, raggiungibile con `$mexal->help(extended: true)` per gli endpoint e con
`->info()` per i campi di ciascuno.

## Integrazione con Laravel

Il supporto specifico per Laravel (service provider, facade, config pubblicabile) vive in un
pacchetto separato che avvolge questo. Nel frattempo bastano poche righe in un service provider:
vedi [docs/framework-integration.md](docs/framework-integration.md).

## Sviluppo

```bash
composer install
composer test        # PHPUnit, nessuna chiamata di rete
composer analyse     # PHPStan livello 8, su PHP 8.1-8.4
composer format      # PHP-CS-Fixer
composer check       # tutto insieme
```

## Migrazione dalla 0.x

La 1.0 è una riscrittura framework-agnostic con modifiche di rottura, fra cui **la verifica TLS
ora attiva per default anche in locale**. La guida è in [UPGRADING.md](UPGRADING.md).

## Sicurezza

Per segnalare una vulnerabilità scrivi a [lanini.simo@gmail.com](mailto:lanini.simo@gmail.com)
invece di aprire una issue pubblica. Vedi [SECURITY.md](SECURITY.md).

## Contribuire

Vedi [CONTRIBUTING.md](CONTRIBUTING.md).

## Credits

- [Simone Lanini](https://github.com/simonelanini)

## Licenza

MIT. Vedi [LICENSE.md](LICENSE.md).
