# Canale servizi

Il canale `/servizi` non è RESTful: l'operazione si sceglie con il campo `cmd` e i parametri
vanno in `dati`. Serve per tutto ciò che non è una risorsa — calcoli, stampe, elaborazioni,
esecuzione di collage.

```php
$output = $mexal->servizi('calcolo_esposizione', [
    'in_codice_conto' => '501.00022',
    'in_alla_data' => '20230101',
]);
```

## Risposte JSON e non JSON

Lo stesso canale restituisce JSON, PDF e multipart. Il tipo si legge dal `Content-Type`, che
viene passato per riferimento:

```php
$contentType = null;
$output = $mexal->servizi('stampa_documento', ['sigla' => 'OC', 'numero' => 13], $contentType);

if (str_contains($contentType, 'application/json')) {
    // $output è un array, con le pagine già aggregate
} else {
    // $output è la stringa raw: il PDF della stampa
    file_put_contents('ordine.pdf', $output);
}
```

Quando la risposta è JSON:

- se contiene `dati`, viene aggregato il contenuto di ogni pagina in un unico array;
- se è un oggetto singolo, lo trovi come unico elemento dell'array. Il tipo di ritorno resta
  quindi sempre lo stesso.

## Parametri al livello di `cmd`

Alcuni servizi vogliono i propri parametri **accanto** a `cmd` invece che dentro `dati`: è il
caso di `esec_collage_server_remoto`, `get_prog_ubicazioni` e dei `campi`/`filtri` di
`lista_docdv`. Si passano con `root`:

```php
$ubicazioni = $mexal->servizi('get_prog_ubicazioni', root: [
    'cod_art_progr' => 'FUELEX97',
    'dettlotti' => true,
]);

$esito = $mexal->servizi('esec_collage_server_remoto', ['parametro' => 'valore'], root: [
    'codice_app' => '924148WEBSHAKER',
    'nome_collage' => 'colws',
    'etichetta_collage' => 'WAPI',
]);
```

`cmd`, `dati` e `next` restano riservati al protocollo del canale e **non sono sovrascrivibili**
da `root`: un parametro con quel nome viene ignorato, non applicato.

Il campo `dati` viene omesso quando è vuoto, perché diversi servizi non lo prevedono affatto e un
array PHP vuoto finirebbe serializzato come `[]` invece che come oggetto JSON.

## Paginazione

> A differenza degli endpoint REST, sul canale servizi il token `next` viaggia **nel body**,
> allo stesso livello di `cmd`. Se ne occupa il client.

Il limite di pagine è quello di `HttpOptions::maxPages`, sovrascrivibile per chiamata:

```php
$righe = $mexal->servizi('lista_docdv', maxPages: 10);
```

Superato il limite viene sollevata `ProtocolException`.

## Connessione e istanza

```php
$output = $mexal->servizi(
    cmd: 'calcolo_esposizione',
    dati: ['in_codice_conto' => '501.00022'],
    connection: 'collaudo',
    instance: 'archivio',
);
```

## Quali servizi esistono

L'elenco dipende dalla versione e dai moduli attivi sulla tua installazione. Il riferimento è il
manuale WebAPI, capitolo "Servizi"; `$mexal->help(extended: true)` elenca invece gli endpoint
REST.
