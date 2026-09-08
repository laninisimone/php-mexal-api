# Errori

## La gerarchia

Tutte le eccezioni del pacchetto implementano `MexalException`, quindi si possono catturare
insieme; ognuna estende però l'eccezione SPL che le compete, così un `catch (RuntimeException)`
generico continua a funzionare.

```
MexalException (interfaccia)
├── ConfigurationException  extends InvalidArgumentException
├── TransportException      extends RuntimeException
├── RequestException        extends RuntimeException
└── ProtocolException       extends RuntimeException
```

| Eccezione | Quando | C'è una risposta? |
| --- | --- | --- |
| `ConfigurationException` | configurazione assente o incoerente | no, fallisce al bootstrap |
| `TransportException` | DNS, TCP, TLS, timeout: il server non ha risposto | no |
| `RequestException` | il gestionale ha risposto con uno status inatteso | sì |
| `ProtocolException` | risposta valida ma inutilizzabile: `Location` mancante, limite pagine, JSON malformato | sì, ma non interpretabile |

```php
use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;
use Simonelanini\PhpMexalApi\Exceptions\MexalException;
use Simonelanini\PhpMexalApi\Exceptions\ProtocolException;
use Simonelanini\PhpMexalApi\Exceptions\RequestException;
use Simonelanini\PhpMexalApi\Exceptions\TransportException;

try {
    $cliente = $mexal->resource('clienti')->get('999');
} catch (RequestException $e) {
    // Errore applicativo del gestionale
} catch (TransportException $e) {
    // Server irraggiungibile, DNS, TLS, timeout
} catch (ProtocolException $e) {
    // Risposta inattesa nella forma
} catch (MexalException $e) {
    // Rete di sicurezza: qualsiasi errore del pacchetto
}
```

Gli status attesi sono: **200** in lettura e ricerca, **201** in creazione, **204** su `update()`
e `delete()`. Un 200 al posto di un 201 non è un successo: significa che è successo qualcosa di
diverso da quel che si era chiesto.

## Cosa dice una `RequestException`

Il manuale documenta un oggetto `error` con la diagnostica dell'errore, nella forma
`"<codice> - <messaggio> [<dettaglio>] [<dettaglio>]"`. Il pacchetto lo espone campo per campo:

```php
} catch (RequestException $e) {
    $e->status();          // 400
    $e->detail();          // "6001 - errore gestionale [Aliquota iva obbligatoria]"
    $e->errorCode();       // 6001
    $e->errorFamily();     // MexalErrorCode::ERRORE_GESTIONALE
    $e->reason();          // "errore gestionale"
    $e->hints();           // ["Aliquota iva obbligatoria"]  <- la causa vera
    $e->requestId();       // "955f5722-..."  da citare al supporto Passepartout
    $e->requestUri();      // "/webapi/risorse/articoli"
    $e->requestMethod();   // "POST"
    $e->serverTimestamp(); // "03/09/2025 10:15:00"
    $e->error();           // l'oggetto "error" completo
    $e->response();        // la Response, per body e header
    $e->isRetryable();     // true su 5xx, 408, 429
}
```

Il messaggio dell'eccezione contiene già tutto il necessario:

```
[400] 6001 - errore gestionale [Aliquota iva obbligatoria] · POST /webapi/risorse/articoli · request-id 955f5722-…
```

Loggarlo così com'è è di norma sufficiente. **Non contiene credenziali** né il body della
risposta: il primo è un segreto, il secondo può essere enorme o contenere dati personali.

I 404 non hanno corpo JSON strutturato, come previsto dal manuale: in quel caso `error()` è vuoto
e il messaggio ripiega su status, metodo e URI.

## Famiglie di errore

| Codice | Famiglia | Significato |
| --- | --- | --- |
| 1001 | `COORDINATE_MANCANTI` | manca l'header `Coordinate-Gestionale` |
| 3002 | `POOL_SERVIZI` | credenziali rifiutate, uso esclusivo in corso o pool esaurito |
| 5001 | `INOLTRO_COMANDO` | dialogo interrotto fra WebAPI e il gestionale |
| 6001 | `ERRORE_GESTIONALE` | vincolo funzionale: il dettaglio dice quale |

Ogni famiglia porta con sé il rimedio suggerito dal manuale:

```php
use Simonelanini\PhpMexalApi\Enums\MexalErrorCode;

if ($e->errorFamily() === MexalErrorCode::POOL_SERVIZI) {
    $logger->warning('Pool WebAPI saturo', [
        'rimedio' => $e->errorFamily()->rimedio(),
        'request_id' => $e->requestId(),
    ]);
}
```

Un codice non documentato resta leggibile con `errorCode()`: `errorFamily()` torna semplicemente
`null`.

## Diagnostica

### Il server risponde?

```php
if (! $mexal->isConnected()) {
    // false anche quando il server non risponde affatto: non solleva eccezioni
}
```

### Il campo che sto inviando esiste?

```php
print_r($mexal->resource('articoli')->info());
```

`6001 - errore gestionale [campo non valido]` significa quasi sempre un nome di campo sbagliato:
`info()` elenca quelli veri per la tua versione del gestionale.

### L'endpoint esiste su questa installazione?

```php
print_r($mexal->help(extended: true));
```

L'enum `MexalResource` è un elenco statico: comoda per l'IDE, ma `help()` è la verità.

## Errori ricorrenti

| Sintomo | Causa tipica |
| --- | --- |
| `TransportException` con "SSL certificate problem" | certificato self-signed e `verify: true`. Indica il CA bundle, o in ultima istanza `verify: false` |
| `ConfigurationException` "le credenziali viaggerebbero in chiaro" | URL con `http://`. Passa a `https://` o, in rete fidata, `allow_plain_http: true` |
| `1001 - COORDINATE_MANCANTI` | l'endpoint richiede un'azienda: configura una `Instance` |
| `3002 - POOL_SERVIZI` | pool esaurito (5 servizi per utente) o funzione in uso esclusivo su un altro terminale |
| `ProtocolException` "Limite di N pagine superato" | archivio più grande del previsto: alza `maxPages` o usa `cursor()` |
| `ProtocolException` "header Location assente" | l'endpoint non espone `Location` dopo la create: usa il connector direttamente |
