# Security Policy

## Versioni supportate

| Versione | Supportata |
| -------- | ---------- |
| 1.x      | ✅         |
| 0.x      | ❌ (versione Laravel, non più mantenuta) |

## Segnalare una vulnerabilità

**Non aprire una issue pubblica.** Scrivi a [lanini.simo@gmail.com](mailto:lanini.simo@gmail.com)
indicando:

- una descrizione del problema e del suo impatto;
- i passi per riprodurlo;
- la versione del pacchetto e di PHP.

Riceverai una risposta entro 5 giorni lavorativi.

## Note di sicurezza per chi usa il pacchetto

Questo client parla con un gestionale: le credenziali che gli passi danno accesso in lettura e
scrittura a contabilità, anagrafiche e magazzino. Alcune scelte di progetto ne tengono conto, ma
il resto dipende da come lo configuri.

### Cosa fa il pacchetto

- **TLS verificato per default**, anche sulle connessioni locali. Disattivarlo richiede un
  `verify: false` esplicito in configurazione.
- **`http://` rifiutato** salvo `allow_plain_http: true`. L'autenticazione WebAPI è HTTP Basic:
  senza TLS le credenziali viaggiano leggibili sulla rete.
- **Nessun redirect seguito.** Un 3xx porterebbe l'header `Authorization` su un host diverso da
  quello configurato.
- **Credenziali mai serializzate.** Non compaiono in `toArray()`, in `var_dump()` né in alcun
  messaggio di eccezione o riga di log.
- **Log senza segreti.** Il logger PSR-3 riceve metodo, URI, status e durata; mai header né body.
- **Header validati.** Dominio, azienda, sotto-azienda e User-Agent sono controllati alla
  costruzione: un CR/LF permetterebbe di iniettare header arbitrari nella richiesta.
- **Path validati.** Un `..` nel path della risorsa viene rifiutato prima di comporre l'URL.
- **Retry solo su richieste idempotenti**, per non duplicare scritture in contabilità.

### Cosa devi fare tu

- Tieni le credenziali fuori dal codice e fuori dal version control: variabili d'ambiente o un
  secret manager.
- Usa un utente WebAPI dedicato all'integrazione, con i soli permessi che le servono.
- Se il server on-premise ha un certificato self-signed, preferisci indicare il **path del CA
  bundle** invece di disattivare la verifica: ottieni cifratura *e* autenticazione del server.
- Non registrare i body delle risposte in log condivisi: contengono dati personali dei clienti.
- Ricorda che il pool WebAPI è limitato (3 utenti, 5 servizi contemporanei per utente): un job
  che parallelizza troppo è, di fatto, un denial of service sulla tua stessa installazione.
