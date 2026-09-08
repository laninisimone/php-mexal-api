# Integrazione con i framework

Il pacchetto non conosce nessun framework: espone un oggetto `Mexal` e lascia a te decidere dove
viva. La regola è una sola.

> **Registra `Mexal` una volta come singleton e riusalo.** Ogni istanza costruisce un client
> HTTP; ricrearla a ogni chiamata butta via il pool di connessioni keep-alive e rallenta tutto.

---

## Laravel

Un pacchetto wrapper dedicato (service provider, facade, config pubblicabile) è in lavorazione.
Nel frattempo bastano poche righe.

### 1. Il file di configurazione

Copia [`config/mexal-api.example.php`](../config/mexal-api.example.php) in `config/mexal-api.php`
e sostituisci `getenv(...)` con `env(...)`, che è la forma idiomatica in Laravel.

### 2. Il service provider

```php
namespace App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Simonelanini\PhpMexalApi\Mexal;

class MexalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Mexal::class, function ($app): Mexal {
            return Mexal::fromArray(
                config('mexal-api'),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }
}
```

Registralo in `bootstrap/providers.php` (Laravel 11+) o in `config/app.php`.

### 3. Uso

```php
use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Mexal;

class SincronizzaClienti
{
    public function __construct(private Mexal $mexal) {}

    public function handle(): void
    {
        foreach ($this->mexal->resource(MexalResource::CLIENTI)->cursor() as $cliente) {
            Cliente::updateOrCreate(['codice' => $cliente['codice']], $cliente);
        }
    }
}
```

### Collection, se le preferisci

I metodi restituiscono `array` e `Generator`, che Laravel avvolge senza sforzo:

```php
$clienti = collect($this->mexal->resource(MexalResource::CLIENTI)->list());

// La variante lazy resta lazy anche dentro una LazyCollection
$articoli = LazyCollection::make(
    fn () => yield from $this->mexal->resource(MexalResource::ARTICOLI)->cursor()
);
```

### Client HTTP

Laravel include Guzzle, quindi non serve installare nulla: la factory lo trova e lo configura con
i timeout e la postura TLS della connessione.

---

## Symfony

### 1. Configurazione dei servizi

```yaml
# config/services.yaml
services:
    Simonelanini\PhpMexalApi\Mexal:
        factory: ['Simonelanini\PhpMexalApi\Mexal', 'fromArray']
        arguments:
            $config: '%mexal_api%'
            $logger: '@logger'
```

```yaml
# config/packages/mexal_api.yaml
parameters:
    mexal_api:
        default:
            connection: '%env(default:default_conn:MEXAL_CONN)%'
            instance: 'default'
        connections:
            local:
                type: local
                url: '%env(MEXAL_URL)%'
                port: '%env(int:MEXAL_PORT)%'
                api_user: '%env(MEXAL_API_USER)%'
                api_password: '%env(MEXAL_API_PASSWORD)%'
                verify: '%env(MEXAL_VERIFY_SSL)%'
        instances:
            default:
                azienda: '%env(MEXAL_AZIENDA)%'
                anno: '%env(int:MEXAL_ANNO)%'
        http:
            timeout: 60
```

### 2. Uso

```php
use Simonelanini\PhpMexalApi\Mexal;

class ClientiController
{
    public function __construct(private readonly Mexal $mexal) {}

    public function index(): Response
    {
        return $this->json($this->mexal->resource('clienti')->list());
    }
}
```

### Client HTTP

Symfony HttpClient è supportato nativamente. Se preferisci passare il client del framework —
utile per profiler, retry e circuit breaker di Symfony:

```yaml
services:
    Simonelanini\PhpMexalApi\Mexal:
        factory: ['Simonelanini\PhpMexalApi\Mexal', 'fromArray']
        arguments:
            $config: '%mexal_api%'
            $httpClient: '@psr18.http_client'
```

> Passando il tuo client, timeout e verifica TLS diventano responsabilità della sua
> configurazione. Ricordati di disabilitare i redirect (`max_redirects: 0`).

---

## Slim, Mezzio e altri container PSR-11

```php
use Psr\Container\ContainerInterface;
use Simonelanini\PhpMexalApi\Mexal;

return [
    Mexal::class => static fn (ContainerInterface $c): Mexal => Mexal::fromEnv(
        logger: $c->get(LoggerInterface::class),
    ),
];
```

---

## Script CLI e cron, senza framework

```php
#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use Simonelanini\PhpMexalApi\Enums\MexalResource;
use Simonelanini\PhpMexalApi\Exceptions\MexalException;
use Simonelanini\PhpMexalApi\Mexal;

$mexal = Mexal::fromEnv();

try {
    $csv = fopen('articoli.csv', 'w');

    // cursor() tiene la memoria costante anche su archivi da centinaia di migliaia di record
    foreach ($mexal->resource(MexalResource::ARTICOLI)->cursor(['fields' => 'codice,descrizione']) as $articolo) {
        fputcsv($csv, [$articolo['codice'], $articolo['descrizione']]);
    }

    fclose($csv);
} catch (MexalException $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
```

---

## WordPress

```php
add_action('init', function (): void {
    // Le credenziali stanno in wp-config.php, non nel database
    $GLOBALS['mexal'] = Simonelanini\PhpMexalApi\Mexal::make(
        Simonelanini\PhpMexalApi\Config\Connection::live(
            domain: MEXAL_LIVE_DOMAIN,
            apiUser: MEXAL_API_USER,
            apiPassword: MEXAL_API_PASSWORD,
        ),
    );
});
```

WordPress non porta con sé un client PSR-18: installa `guzzlehttp/guzzle` nel tuo plugin.

---

## Scrivere un wrapper per un framework

Se stai costruendo un pacchetto di integrazione, tre indicazioni.

1. **Passa dall'array di configurazione**, non dagli oggetti valore. `MexalConfig::fromArray()`
   accetta la stessa forma del file di config, quindi il wrapper si riduce a
   `Mexal::fromArray(config('mexal-api'))` e resta stabile anche quando aggiungi opzioni.
2. **Registra un singleton**, non una factory. Vedi la regola in testa alla pagina.
3. **Inoltra `$contentType` per riferimento.** `servizi()` e `allegato()` lo restituiscono così:
   una facade o un proxy che inoltra gli argomenti con lo spread operator (`...$args`) rompe il
   riferimento e lascia la variabile del chiamante sempre a `null`. Vanno dichiarati per esteso.
