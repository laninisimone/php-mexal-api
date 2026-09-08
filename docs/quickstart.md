# Guida rapida

Dall'installazione alla prima chiamata. Servono cinque minuti e un'installazione Mexal con il
modulo WebAPI attivo.

## 1. Installa

```bash
composer require simonelanini/php-mexal-api guzzlehttp/guzzle
```

Se la tua applicazione ha già un client HTTP PSR-18, Guzzle è superfluo: vedi
[Configurazione → Client HTTP](configuration.md#client-http).

## 2. Costruisci il client

```php
require 'vendor/autoload.php';

use Simonelanini\PhpMexalApi\Config\Connection;
use Simonelanini\PhpMexalApi\Config\Instance;
use Simonelanini\PhpMexalApi\Mexal;

$mexal = Mexal::make(
    Connection::local(
        url: 'https://192.168.1.10',
        apiUser: 'utente_api',
        apiPassword: 'password_api',
        port: 9004,
    ),
    new Instance(azienda: 'DEM', anno: 2025),
);
```

Per il cloud Passepartout:

```php
$mexal = Mexal::make(
    Connection::live(domain: 'dominio_xyz', apiUser: 'utente_api', apiPassword: 'password_api'),
    new Instance(azienda: 'DEM'),
);
```

> **Certificato self-signed?** È il caso della maggior parte delle installazioni on-premise. La
> verifica TLS è attiva per default: se il server ha un certificato self-signed la connessione
> fallirà finché non lo dichiari. La soluzione migliore è indicare il CA bundle
> (`verify: '/percorso/ca.pem'`); `verify: false` funziona ma disattiva ogni protezione contro
> il man-in-the-middle. Vedi [Sicurezza](security.md#tls).

## 3. Verifica che risponda

```php
if (! $mexal->isConnected()) {
    exit("Il gestionale non risponde: controlla url, porta e credenziali.\n");
}

print_r($mexal->installazione());
```

`isConnected()` non solleva eccezioni: restituisce `false` anche quando il server è spento.

## 4. Leggi dei dati

```php
use Simonelanini\PhpMexalApi\Enums\MexalResource;

// Tutti i clienti, paginazione seguita in automatico
$clienti = $mexal->resource(MexalResource::CLIENTI)->list([
    'fields' => 'codice,ragione_sociale',   // riduce il payload: chiedi solo ciò che usi
]);

// Un singolo record
$cliente = $mexal->resource(MexalResource::CLIENTI)->get('501.00001');
```

Su archivi grossi usa `cursor()`, che scarica una pagina alla volta e non tiene tutto in memoria:

```php
foreach ($mexal->resource(MexalResource::ARTICOLI)->cursor(['fields' => 'codice,descrizione']) as $articolo) {
    echo $articolo['codice'], PHP_EOL;
}
```

## 5. Cerca con dei filtri

I filtri sono in AND fra loro; più valori nello stesso filtro sono in OR.

```php
$risultati = $mexal->resource(MexalResource::ARTICOLI)->search([
    'filtri' => [
        ['campo' => 'tipologia', 'condizione' => '=', 'valore' => 'A'],
        [
            'campo' => 'descrizione',
            'condizione' => 'contiene',
            'case_insensitive' => true,
            'valore' => ['BICI', 'MTB'],
        ],
    ],
]);
```

Condizioni ammesse: `=`, `<`, `<=`, `>`, `>=`, `<>`, `contiene`, `inizia_per`.

## 6. Scrivi

```php
// Crea e restituisce il solo codice: una chiamata HTTP
$codice = $mexal->resource(MexalResource::ARTICOLI)->createAndGetId([
    'codice' => 'ARTICOLO1',
    'descrizione' => 'Articolo di prova',
    'aliquota_iva' => 22,
]);

// Modifica
$mexal->resource(MexalResource::ARTICOLI)->update($codice, ['descrizione' => 'Nuova descrizione']);

// Elimina
$mexal->resource(MexalResource::ARTICOLI)->delete($codice);
```

> In revisione il gestionale può controllare la data di ultima modifica per evitare che tu
> sovrascriva il lavoro di altri. Leggi il campo `data_ult_mod` con `get()` e rimandalo nella
> `update()`: vedi [Risorse → Aggiornamenti concorrenti](resources.md#aggiornamenti-concorrenti).

## 7. Gestisci gli errori

```php
use Simonelanini\PhpMexalApi\Exceptions\MexalException;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Exceptions\TransportException;

try {
    $mexal->resource(MexalResource::ARTICOLI)->create(['codice' => 'ART001']);
} catch (RequestException $e) {
    // Il gestionale ha risposto, ma ha rifiutato
    echo $e->errorCode();                      // 6001
    echo implode(', ', $e->hints());           // "Aliquota iva obbligatoria"
    echo $e->errorFamily()?->rimedio() ?? '';  // cosa fare, secondo il manuale
} catch (TransportException $e) {
    // Il server non ha risposto affatto: rete, DNS, TLS, timeout
} catch (MexalException $e) {
    // Qualsiasi altro errore del pacchetto
}
```

Il messaggio dell'eccezione contiene già il dettaglio completo del gestionale, il metodo, l'URI
e il `request-id` da citare al supporto: loggarlo così com'è è di norma sufficiente.

## 8. Cosa espone la tua installazione

```php
// Elenco degli endpoint disponibili, con paginazione ed encoding supportati
foreach ($mexal->help(extended: true) as $endpoint) {
    print_r($endpoint);
}

// Campi di un endpoint, con tipo e obbligatorietà
print_r($mexal->resource(MexalResource::CLIENTI)->info());
```

Sono la documentazione allineata alla tua versione del gestionale — più affidabile di qualsiasi
elenco statico, enum `MexalResource` compresa.

## Passi successivi

- [Configurazione](configuration.md): più connessioni, retry, timeout, logging
- [Risorse](resources.md): sotto-risorse, allegati, MyDB, codici con slash
- [Canale servizi](services.md): le operazioni che non sono REST
- [Integrazione con i framework](framework-integration.md): dove registrare l'oggetto `Mexal`
