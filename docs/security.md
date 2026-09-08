# Sicurezza

Questo client parla con un gestionale: le credenziali che gli passi danno accesso in lettura e
scrittura a contabilità, anagrafiche e magazzino. Questa pagina spiega cosa fa il pacchetto per
proteggerle e cosa resta in carico a te.

## TLS

### La verifica è attiva per default, anche in locale

Nella versione 0.x le connessioni locali avevano `verify: false` di default, perché le
installazioni on-premise espongono spesso un certificato self-signed. Dalla 1.0 non è più così:
**disattivare la verifica deve essere una decisione esplicita e visibile in configurazione**,
non un default silenzioso. Senza verifica, chiunque sia in mezzo alla rete può leggere le
credenziali Basic e i dati che scorrono.

Le tre opzioni, dalla migliore alla peggiore:

```php
// 1. Il server ha un certificato valido: non fare nulla, è il default.
Connection::local($url, $user, $password);

// 2. Certificato self-signed o CA interna: indica il bundle.
//    Mantieni cifratura E autenticazione del server.
Connection::local($url, $user, $password, verify: '/etc/ssl/certs/mexal-ca.pem');

// 3. Ultima risorsa: nessuna verifica. Il traffico resta cifrato, ma non sai più
//    con chi stai parlando. Accettabile solo su una rete che controlli interamente.
Connection::local($url, $user, $password, verify: false);
```

Il path del CA bundle viene controllato al bootstrap: se non esiste o non è leggibile, la
configurazione fallisce subito invece di produrre un errore cURL oscuro alla prima chiamata.

### `http://` è rifiutato

L'autenticazione WebAPI è **HTTP Basic**: utente e password viaggiano in un header codificato in
base64, che è codifica e non cifratura. Su `http://` sono leggibili da chiunque osservi la rete.

```php
Connection::local('http://192.168.1.10', $user, $password);
// ConfigurationException: ... su http:// le credenziali viaggerebbero in chiaro ...
```

Se sai quel che fai e la connessione resta confinata in una rete fidata:

```php
Connection::local('http://192.168.1.10', $user, $password, allowPlainHttp: true);
```

### I redirect non vengono seguiti

Un 3xx porterebbe l'header `Authorization` su un host diverso da quello configurato — che è
esattamente il modo in cui si esfiltrano credenziali. Il protocollo WebAPI non usa redirect,
quindi seguirli sarebbe solo un rischio senza contropartita.

> Se **inietti** un tuo client PSR-18, questa protezione non si applica: disabilita i redirect
> nella sua configurazione.

## Credenziali

- Sono **private** dentro `Connection` e non escono mai: `toArray()` e `__debugInfo()` riportano
  solo `'api_credentials' => 'set'|'missing'`, mai il valore;
- non compaiono in nessun messaggio di eccezione né in nessuna riga di log;
- un `var_dump($connection)` o un `dd()` finito per sbaglio in produzione non le rivela;
- l'URL non può contenerle (`https://utente:password@host` è rifiutato): finirebbero nei log di
  ogni proxy attraversato, e comunque non servirebbero a autenticarsi.

Quello che resta a te:

- tienile fuori dal codice e dal version control: variabili d'ambiente o un secret manager;
- usa un utente WebAPI **dedicato** all'integrazione, con i soli permessi che le servono;
- ruotale come qualsiasi altra credenziale di servizio.

## Log

Il logger PSR-3 opzionale riceve **metodo, URI, status, durata e numero di tentativo**. Mai
header, mai body:

```
Mexal GET https://erp.example.com/webapi/risorse/clienti?fields=codice -> 200
```

La ragione è semplice: l'`Authorization` contiene le credenziali e il body contiene dati
personali dei clienti. Un log centralizzato è spesso accessibile a più persone di quante
dovrebbero vedere l'anagrafica.

Se ti serve il payload per un debug puntuale, loggalo tu al chiamante, dove sai cosa stai
scrivendo e per quanto tempo resterà lì.

## Iniezione di header e di path

I valori che finiscono negli header e nell'URL sono validati alla costruzione, perché un CR/LF
permetterebbe di iniettare header arbitrari nella richiesta:

| Valore | Regola |
| --- | --- |
| `domain` | solo lettere, cifre, punto, trattino, underscore (max 64) |
| `azienda`, `sotto_azienda` | solo lettere, cifre, punto, trattino, underscore (max 32) |
| `anno`, `magazzino` | interi, in un intervallo plausibile |
| `userAgent` | CR/LF rimossi |
| path della risorsa | nessun carattere di controllo, nessun segmento `..` |

Il divieto di `..` nel path impedisce che un path costruito a mano risalga fuori da `/webapi/`
verso altri endpoint del server. I codici risorsa passati a `get()`, `update()` e `delete()` sono
comunque url-encodati o convertiti in esadecimale, quindi non possono alterare la struttura
dell'URL.

## Scritture e retry

I retry si applicano **solo alle richieste idempotenti**: GET, PUT, DELETE e la POST di
`/ricerca`. Una POST che crea entità non viene mai ripetuta, perché un errore di rete *dopo* la
scrittura genererebbe un documento o un'anagrafica duplicati in contabilità — un danno peggiore
dell'errore che si stava cercando di assorbire.

Per lo stesso motivo `update()` supporta il campo `data_ult_mod`: vedi
[Risorse → Aggiornamenti concorrenti](resources.md#aggiornamenti-concorrenti).

## Disponibilità

Il pool WebAPI è piccolo: **3 utenti**, **5 servizi contemporanei per utente**. Un job che
parallelizza troppo, o che ritenta in modo aggressivo, è di fatto un denial of service sulla tua
stessa installazione — e blocca anche gli altri client legittimi.

Il pacchetto aiuta con l'attesa crescente fra i tentativi e con `retries: 0` di default, ma il
dimensionamento della concorrenza resta una tua scelta.

## Superficie di dipendenze

Il pacchetto richiede solo interfacce PSR (`psr/http-client`, `psr/http-factory`,
`psr/http-message`, `psr/log`) più `php-http/discovery`. Nessun framework, nessun codice di terze
parti che gira a runtime oltre al client HTTP che scegli tu — che è quasi sempre uno che la tua
applicazione ha già e che già aggiorni.

## Segnalare una vulnerabilità

Scrivi a [lanini.simo@gmail.com](mailto:lanini.simo@gmail.com) invece di aprire una issue
pubblica. Vedi [SECURITY.md](../SECURITY.md).
