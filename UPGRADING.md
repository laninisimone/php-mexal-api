# Migrazione dalla 0.x alla 1.0

La 1.0 è una riscrittura framework-agnostic: il pacchetto non dipende più da Laravel. Le
modifiche sono numerose ma meccaniche, e i nomi dei metodi di dominio non sono cambiati.

## Prima di tutto: due modifiche di sicurezza

### 1. La verifica TLS è ora attiva anche in locale

Nella 0.x le connessioni locali avevano `verify: false` di default. Dalla 1.0 il default è
`true` per tutte le connessioni.

**Se la tua installazione on-premise ha un certificato self-signed, le chiamate falliranno**
finché non lo dichiari:

```php
// Preferibile: cifratura E autenticazione del server
'verify' => '/etc/ssl/certs/mexal-ca.pem',

// Ripristina il comportamento della 0.x, con i rischi che comporta
'verify' => false,
```

### 2. `http://` è rifiutato

L'autenticazione è HTTP Basic: senza TLS le credenziali sono leggibili sulla rete. Se davvero ti
serve, dichiaralo: `'allow_plain_http' => true`.

## Costruzione del client

```php
// 0.x — facade Laravel
use Simonelanini\PhpMexalApi\Facades\Mexal;
$clienti = Mexal::resource('clienti')->list();

// 1.0 — oggetto iniettato
use Simonelanini\PhpMexalApi\Mexal;
$mexal = Mexal::fromArray(config('mexal-api'));
$clienti = $mexal->resource('clienti')->list();
```

Se vuoi mantenere la facade, la trovi nel pacchetto di integrazione Laravel dedicato — oppure
puoi ricrearla in tre righe nella tua applicazione: vedi
[docs/framework-integration.md](docs/framework-integration.md).

## Tabella di corrispondenza

| 0.x | 1.0 |
| --- | --- |
| `Simonelanini\PhpMexalApi\MexalManager` | `Simonelanini\PhpMexalApi\Mexal` |
| `Simonelanini\PhpMexalApi\MexalConnector` | `Simonelanini\PhpMexalApi\Http\Connector` |
| `Simonelanini\PhpMexalApi\Facades\Mexal` | rimossa (vive nel wrapper Laravel) |
| `Simonelanini\PhpMexalApi\Providers\MexalServiceProvider` | rimosso (idem) |
| `Simonelanini\PhpMexalApi\MexalException` | `...\Exceptions\MexalException` |
| `Simonelanini\PhpMexalApi\MexalApiException` | `...\Exceptions\ProtocolException` |
| `Simonelanini\PhpMexalApi\MexalRequestException` | `...\Exceptions\RequestException` |
| `Illuminate\Http\Client\ConnectionException` | `...\Exceptions\TransportException` |
| `Concerns\HandlesMexalResponses` | `Concerns\InteractsWithResponses` |

## Tipi di ritorno

`Collection` e `LazyCollection` sono sparite: al loro posto ci sono `array` e `Generator`.

```php
// 0.x
$clienti = Mexal::resource('clienti')->list();
$primi = $clienti->take(10);
$codici = $clienti->pluck('codice');

// 1.0 — PHP puro
$clienti = $mexal->resource('clienti')->list();
$primi = array_slice($clienti, 0, 10);
$codici = array_column($clienti, 'codice');

// 1.0 — oppure riavvolgile in una Collection, se sei in Laravel
$clienti = collect($mexal->resource('clienti')->list());
```

`cursor()` e `searchCursor()` restituiscono un `Generator`: `foreach` funziona identico, i metodi
di `LazyCollection` no.

```php
// 0.x
$primi = Mexal::resource('articoli')->cursor()->take(50)->all();

// 1.0
$primi = [];
foreach ($mexal->resource('articoli')->cursor() as $articolo) {
    if (count($primi) >= 50) {
        break;
    }
    $primi[] = $articolo;
}
```

`installazione()` restituisce l'oggetto JSON come array; `utenti()` e `help()` restituiscono
liste.

## Eccezioni

`MexalRequestException` non estende più `Illuminate\Http\Client\RequestException`. Se catturavi
quella, passa a `RequestException` del pacchetto — i metodi diagnostici sono gli stessi, con due
aggiunte (`status()`, `isRetryable()`) e con `response()` che restituisce la `Response` del
pacchetto invece di quella di Laravel.

```php
// 0.x
catch (\Illuminate\Http\Client\ConnectionException $e) { }

// 1.0
catch (\Simonelanini\PhpMexalApi\Exceptions\TransportException $e) { }
```

## Configurazione

La forma dell'array è **invariata**, con tre aggiunte:

- `connections.*.allow_plain_http` (default `false`);
- `http.max_pages` (default 1000), che prima era solo un argomento dei metodi;
- `connections.*.verify` ora vale `true` per default anche sulle connessioni locali.

Quindi `Mexal::fromArray(config('mexal-api'))` funziona con il file che hai già, a patto di
rivedere `verify`.

## Dipendenze

```bash
composer remove illuminate/support illuminate/http    # non più necessarie al pacchetto
composer require simonelanini/php-mexal-api:^1.0
```

Serve un client HTTP PSR-18. Laravel e Symfony ne includono già uno; altrimenti:

```bash
composer require guzzlehttp/guzzle
```

## Novità che vale la pena adottare

- `Mexal::fromEnv()` per container e script;
- `$mexal->on(new Instance(azienda: 'ACM'))` per i job che ciclano su più aziende;
- `logger:` per la tracciabilità delle chiamate;
- `HttpOptions(retries: 2)` per assorbire le indisponibilità transitorie del pool WebAPI.
