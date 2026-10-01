# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il progetto adotta il
[versionamento semantico](https://semver.org/lang/it/).

## [Unreleased]

### Added

- `docs/api/`: specifiche OpenAPI 3.1 per ogni gruppo di risorse e per il canale servizi,
  generate dall'help in linea (`help?extended=true`, `?info=true`) e integrate con il manuale
  WebAPI v3.1 (campi obbligatori in inserimento, parametri speciali, strutture non esposte).
- `docs/api/ai/`: schema per agenti AI con tool in formato MCP, catalogo delle risorse con campi,
  chiavi e relazioni fra archivi, indice compatto e guida d'uso per il modello.
- `tools/api-docs/genera.php` (`composer api-docs`) per rigenerare tutto dalle istantanee o dal
  gestionale. Si ferma al primo errore di autenticazione per non bloccare l'utente WebAPI.

## [1.0.0]

Prima release framework-agnostic. Riscrittura completa: il pacchetto non dipende più da Laravel e
funziona su qualsiasi applicazione PHP 8.1+. Chi arriva dalla 0.x trova la guida alla migrazione in
[UPGRADING.md](UPGRADING.md).

### Added

- Trasporto **PSR-18** con auto-discovery del client (Guzzle, Symfony HttpClient o qualunque
  implementazione installata) e possibilità di iniettare un client già configurato.
- Configurazione come oggetti valore immutabili e validati: `Connection`, `Instance`,
  `HttpOptions`, `MexalConfig`.
- `MexalConfig::fromArray()`, `::fromEnv()` e `::forConnection()` per i tre modi tipici di
  configurare il client.
- Logging **PSR-3** opzionale su metodo, URI, status e durata di ogni chiamata.
- `Mexal::on()` per lavorare su coordinate gestionali costruite al volo, senza registrarle in
  configurazione.
- `RequestException::isRetryable()` e `Response::records()`.
- Suite di test senza rete e analisi statica PHPStan livello 8 sull'intervallo PHP 8.1–8.4.

### Changed

- **La verifica del certificato TLS è attiva per default anche sulle connessioni locali.** Nella
  0.x era disattivata. Le installazioni con certificato self-signed devono ora dichiarare
  `verify: false` (o, meglio, il path del CA bundle).
- **`http://` è rifiutato** salvo `allow_plain_http: true` esplicito: l'autenticazione è HTTP
  Basic e senza TLS le credenziali viaggiano in chiaro.
- I metodi restituiscono `array` e `Generator` al posto di `Collection` e `LazyCollection`.
- Le eccezioni non estendono più quelle di Laravel: `Simonelanini\PhpMexalApi\Exceptions\*`,
  tutte sotto l'interfaccia `MexalException`.
- `MexalManager` è diventato `Mexal`; `MexalConnector` è diventato `Http\Connector`.
- I redirect HTTP non vengono più seguiti, per non portare l'header `Authorization` su un altro host.

### Removed

- `MexalServiceProvider` e la facade `Mexal`: l'integrazione con Laravel vive ora in un pacchetto
  separato.
- Le dipendenze `illuminate/support` e `illuminate/http`.

[Unreleased]: https://github.com/simonelanini/php-mexal-api/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/simonelanini/php-mexal-api/releases/tag/v1.0.0
