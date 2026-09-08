# Contribuire

Grazie per l'interesse. Poche regole, tutte pensate per tenere il pacchetto affidabile.

## Prima di aprire una PR

```bash
composer install
composer check     # style + analisi statica + test
```

Tutto deve passare. La CI ripete la stessa cosa su PHP 8.1, 8.2, 8.3 e 8.4, con dipendenze
`lowest` e `highest`.

## Requisiti per una modifica

- **Un test per ogni cambio di comportamento.** I test non toccano la rete: usano il
  `MockClient` in `tests/Support/`, che consegna risposte preparate e conserva le richieste.
- **PHPStan livello 8**, senza baseline e senza `@phpstan-ignore`. Se l'analisi segnala qualcosa,
  quasi sempre ha ragione.
- **PHP 8.1 come minimo.** L'analisi statica gira sull'intervallo 8.1–8.4: una funzione
  introdotta dopo la 8.1 viene segnalata.
- **Commenti dove il codice non basta.** Non serve commentare cosa fa una riga, serve spiegare
  *perché* è scritta così — soprattutto dove il comportamento discende da una stranezza del
  protocollo Mexal. Sono le righe che, fra sei mesi, qualcuno sarebbe tentato di "semplificare".

## Sicurezza

Le modifiche che toccano credenziali, TLS, header o log meritano attenzione particolare:

- niente credenziali in messaggi di eccezione, log o `toArray()`;
- ogni valore che finisce in un header va validato contro CR/LF;
- i retry restano confinati alle richieste idempotenti.

Se pensi di aver trovato una vulnerabilità, **non aprire una issue**: vedi [SECURITY.md](SECURITY.md).

## Aggiungere una risorsa all'enum

`MexalResource` è un elenco statico di comodità, non la verità: la fonte allineata è
`$mexal->help(extended: true)`. Aggiungi pure i case che ti servono, in ordine coerente con le
sezioni esistenti, ma non c'è bisogno che l'enum sia esaustiva.

## Stile dei commit

Descrittivi e in italiano o inglese, indifferentemente. Non è richiesto Conventional Commits.

## Changelog

Aggiungi una voce in [CHANGELOG.md](CHANGELOG.md) sotto `[Unreleased]` per ogni modifica visibile
a chi usa il pacchetto.
