# Configurazione

Tre modi per costruire il client, dallo script usa-e-getta all'applicazione strutturata.

## I tre modi

### 1. Oggetti espliciti — leggibile, tipizzato, verificato dall'IDE

```php
use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\HttpOptions;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Mexal;

$mexal = Mexal::make(
    connection: Connection::local('https://192.168.1.10', 'api_user', 'api_password', port: 9004),
    instance: new Instance(azienda: 'DEM', anno: 2025, magazzino: 2),
    http: new HttpOptions(timeout: 90, retries: 2),
);
```

### 2. Variabili d'ambiente — per container e deploy

```php
$mexal = Mexal::fromEnv();
```

Vengono registrate solo le connessioni che l'ambiente descrive: `MEXAL_URL` crea la connessione
`local`, `MEXAL_LIVE_DOMAIN` crea la `live`. Se non ne trova nessuna, fallisce con un messaggio
che dice quali variabili servono. L'elenco completo è in [`.env.example`](../.env.example).

Nei test puoi passare l'ambiente a mano: `Mexal::fromEnv(['MEXAL_URL' => '...'])`.

### 3. Array di configurazione — per i framework

```php
$mexal = Mexal::fromArray(require __DIR__.'/config/mexal-api.php');
```

La forma dell'array è documentata in
[`config/mexal-api.example.php`](../config/mexal-api.example.php). È la via che un wrapper di
framework dovrebbe usare: in Laravel diventa `Mexal::fromArray(config('mexal-api'))`.

---

## Connessioni

Una connessione descrive **il server e le credenziali**.

### Locale (on-premise)

```php
Connection::local(
    url: 'https://192.168.1.10',
    apiUser: 'utente_api',
    apiPassword: 'password_api',
    port: 9004,
    soUser: 'utente_so',        // solo se il server è configurato con login=1
    soPassword: 'password_so',
    verify: true,               // true | false | '/percorso/ca-bundle.pem'
    allowPlainHttp: false,
    name: 'local',
);
```

`url` accetta anche un prefisso di path, per chi espone il servizio dietro un reverse proxy:
`https://erp.example.com/mexal` diventa `https://erp.example.com/mexal/webapi/`. In quel caso
passa `port: null` per non aggiungere la 9004.

### Live (Passepartout Cloud)

```php
Connection::live(
    domain: 'dominio_xyz',
    apiUser: 'utente_api',
    apiPassword: 'password_api',
);
```

Il dominio è il discriminante rispetto alla connessione locale: viaggia nell'header
`Authorization` e identifica l'installazione dentro il cloud.

### Cosa viene validato subito

Un errore di configurazione fallisce al bootstrap, non alla prima chiamata in produzione:

| Controllo | Perché |
| --- | --- |
| `url` presente e ben formato | senza, la prima chiamata fallirebbe con un errore oscuro |
| schema `https` (o `http` con opt-in) | l'autenticazione è HTTP Basic: in chiaro le credenziali sono leggibili |
| nessuna credenziale dentro l'`url` | finirebbe nei log di ogni proxy attraversato |
| `type` fra `local` e `live` | un valore diverso lascerebbe la connessione senza URL di base |
| `domain` senza spazi né CR/LF | finisce grezzo in un header: sarebbe header injection |
| CA bundle esistente e leggibile | altrimenti la verifica fallisce a runtime con un errore cURL |
| `api_user` senza `:` | i due punti separano utente e password nello schema Basic |

### Più connessioni

```php
use Simonelanini\PhpMexalApi\Config\MexalConfig;

$mexal = new Mexal(new MexalConfig(
    connections: [
        'produzione' => Connection::live('dominio_prod', 'api', 'password'),
        'collaudo' => Connection::local('https://10.0.0.5', 'api', 'password'),
    ],
    instances: [
        'principale' => new Instance(azienda: 'DEM', anno: 2025),
        'archivio' => new Instance(azienda: 'DEM', anno: 2024),
    ],
    defaultConnection: 'produzione',
    defaultInstance: 'principale',
));

$clienti = $mexal->resource('clienti', connection: 'collaudo', instance: 'archivio')->list();
```

---

## Istanze

Un'istanza descrive **dove** leggere e scrivere, cioè l'header `Coordinate-Gestionale`:

```
Azienda=DEM SottoAzienda=A Anno=2025 Magazzino=2
```

```php
new Instance(azienda: 'DEM', sottoAzienda: 'A', anno: 2025, magazzino: 2);
```

- `sottoAzienda` è opzionale: se vuota o non indicata, **il token viene omesso dall'header**,
  come previsto dal manuale;
- `anno` non indicato significa anno corrente;
- `magazzino` non indicato significa 1.

Azienda e sotto-azienda ammettono solo lettere, cifre, punto, trattino e underscore: finiscono
in un header HTTP, e uno spazio o un a capo permetterebbero di iniettarne altri.

### Coordinate costruite al volo

Per i job che ciclano su più aziende o più anni non serve registrare un'istanza per ognuna:

```php
foreach (['DEM', 'ACM', 'XYZ'] as $azienda) {
    $clienti = $mexal->on(new Instance(azienda: $azienda, anno: 2025))
        ->resource('clienti')
        ->list();
}
```

`Instance::with()` produce una copia modificata senza toccare l'originale:

```php
$annoScorso = $mexal->connector()->instance()->with(anno: 2024);
```

---

## Trasporto HTTP

```php
new HttpOptions(
    timeout: 60,          // timeout di lettura, secondi (0 = nessun limite)
    connectTimeout: 10,   // timeout di connessione, secondi
    retries: 0,           // tentativi AGGIUNTIVI oltre al primo
    retryDelay: 250,      // attesa base fra i tentativi, ms (crescente)
    maxPages: 1000,       // limite di sicurezza sulla paginazione automatica
    userAgent: '...',     // identifica il client nei log del gestionale
);
```

### Timeout

Il timeout di lettura sta **sopra i 30 secondi** che il servizio WebAPI si prende prima di
interrompersi e restituire un `next` di paginazione: abbassarlo sotto quella soglia interrompe
letture perfettamente legittime. Il timeout di connessione resta invece breve, così un server
spento non tiene appesa l'applicazione.

### Retry

I ritentativi sono deliberatamente selettivi:

- **solo richieste idempotenti** — GET, PUT, DELETE e la POST di `/ricerca`. Una POST che crea
  entità non viene mai ripetuta: un errore *dopo* la scrittura genererebbe documenti o
  anagrafiche duplicate, che è peggio dell'errore stesso;
- **solo errori transitori** — errori di rete, 5xx, 408 e 429. Un 4xx non viene ritentato,
  perché ritentando non cambia esito;
- **con attesa crescente** — il pool WebAPI è piccolo (5 servizi per utente): insistere a raffica
  peggiora la congestione invece di risolverla.

`retries: 0` (default) disattiva del tutto i retry.

### Paginazione

`maxPages` è una rete di sicurezza: un `next` che non si esaurisce mai — bug del gestionale o
filtro che rigenera record — farebbe girare il ciclo all'infinito consumando il pool. Superato il
limite viene sollevata `ProtocolException`.

---

## Client HTTP

Il pacchetto parla PSR-18. Con Guzzle o Symfony HttpClient installati non devi fare nulla: il
client viene costruito con i timeout e la postura TLS della connessione.

### Iniettare il proprio client

Se la tua applicazione ha già un client configurato (proxy aziendale, mTLS, strumentazione,
cache di sviluppo), passalo e il pacchetto lo userà così com'è:

```php
$mexal = Mexal::make($connection, $instance, httpClient: $ilTuoClientPsr18);
```

> Attenzione: iniettando un client, **timeout e verifica TLS diventano responsabilità tua** — il
> pacchetto non li applica, perché non ha modo di configurare un client PSR-18 arbitrario. In
> particolare, disabilita i redirect: un 3xx porterebbe l'header `Authorization` su un altro host.

### Personalizzare la costruzione

Per mantenere la configurazione per-connessione ma cambiare il client, implementa `ClientFactory`:

```php
use Simonelanini\PhpMexalApi\Http\ClientFactory;

final class ProxyAziendaleFactory implements ClientFactory
{
    public function createFor(Connection $connection, HttpOptions $options): ClientInterface
    {
        return new \GuzzleHttp\Client([
            'verify' => $connection->verify,
            'timeout' => $options->timeout,
            'proxy' => 'http://proxy.interno:3128',
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }
}

$mexal = new Mexal($config, clientFactory: new ProxyAziendaleFactory());
```

---

## Logging

Un logger PSR-3 opzionale riceve metodo, URI, status e durata di ogni chiamata, più un warning a
ogni retry:

```php
$mexal = Mexal::make($connection, $instance, logger: $psr3Logger);
```

**Non vengono mai loggati header né body**: l'`Authorization` contiene le credenziali e il body
contiene dati dell'anagrafica. Se ti serve il payload per un debug puntuale, loggalo tu al
chiamante, dove sai cosa stai scrivendo e dove.

---

## Compressione

Il parametro `$compress` abilita `Accept-Encoding: gzip`; il pacchetto decomprime la risposta
anche se il client PSR-18 non lo fa da sé. Utile solo su risposte molto corpose.

```php
$clienti = $mexal->resource('clienti', compress: true)->list();
```

> Nulla a che vedere con il parametro `encoding` delle query string, che riguarda i codici
> risorsa e viene gestito in automatico.
