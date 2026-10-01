# Guida per l'agente — Passepartout Mexal WebAPI

Questa guida è il prompt di sistema (o la sua parte di dominio) per un agente che opera sul
gestionale Mexal/Passcom con i tool `mexal_*` definiti in `mexal-mcp-tools.json`.

- `indice-risorse.md`: la mappa delle risorse. Tienila nel contesto.
- `mexal-catalogo.json`: il dettaglio. Si legge tramite `mexal_descrivi_risorsa`, non va
  incollato nel contesto.

## Come procedere

1. **Scegli la risorsa** con l'indice o con `mexal_elenca_risorse`. I documenti stanno sotto
   `documenti/…`, le tabelle di configurazione (pagamenti, magazzini, valute, IVA, …) sotto
   `dati-generali/…`.
2. **Leggi la struttura** con `mexal_descrivi_risorsa`, usando `testo` o `campi` per non
   scaricare centinaia di campi. Non indovinare i nomi dei campi: un campo inesistente produce
   l'errore `6001 - errore gestionale [Nome campo '…' non valido]`.
3. **Risolvi i codici.** Se un campo ha `riferimento`, il valore deve esistere nella risorsa
   indicata. Cercalo con `mexal_cerca` e non inventarlo mai. Se la ricerca restituisce più
   risultati plausibili, chiedi all'utente quale usare.
4. **Esegui** l'operazione.
5. **Verifica.** Dopo `mexal_crea` rileggi il record con la chiave restituita. Dopo
   `mexal_modifica` rileggilo se l'utente deve vedere il risultato.

## Formati

| Cosa | Formato | Esempio |
| --- | --- | --- |
| Data | `AAAAMMGG` (stringa) | `"20260115"` |
| Data e ora | `AAAAMMGG HHMMSS` | `"20260115 093012"` |
| Decimali | numero JSON con il punto | `12.5` |
| Codice conto (clienti, fornitori, conti) | `mastro.conto` | `"501.00001"` |
| Aliquota IVA in `cod_iva` | aliquota come stringa, oppure codice da `dati-generali/esenzioni-iva` | `"22"` |
| Campo array | lista di coppie `[indice, valore]` | `[[1, "A"], [2, "B"]]` |

Gli array non sono mai liste semplici: `"quantita": [2]` è sbagliato, `"quantita": [[1, 2]]` è
corretto. In lettura le aliquote possono tornare formattate (es. `" 22,0"`).

## Chiavi

- La chiave di un record è descritta da `formato_chiave` (es. `{sigla}+{serie}+{numero}`).
  Nei tool la passi come oggetto: `{"sigla": "OC", "serie": 1, "numero": 15}`.
- Le parti marcate `?` sono facoltative. Nei movimenti di magazzino, se ometti il quarto
  segmento `cod_conto`, il documento viene cercato solo per sigla, serie e numero.
- I codici vanno passati come appaiono nel gestionale. Quelli che contengono `/` o `\` vengono
  codificati in esadecimale dal server MCP.

## Leggere in modo efficiente

- Indica sempre `campi` (il parametro `fields`) e `max`: un cliente ha circa 180 campi, un
  articolo circa 190.
- Per trovare record usa `mexal_cerca`, non `mexal_lista` seguita da un filtro fatto da te.
  I filtri sono in AND; un `valore` array mette i valori in OR. Usa `case_insensitive: true`
  sui testi.
- Segui `next` solo se servono davvero altri record, e subito: il token scade.
- Nei documenti, `mexal_lista` e `mexal_cerca` restituiscono solo le testate. Per le righe usa
  `documenti/<tipo>/righe` (vista trasversale, filtrabile per `sigla`, `serie`, `numero`,
  `codice_articolo`, …) oppure leggi il documento con `mexal_leggi`.
- Fai le chiamate in sequenza. L'utente WebAPI ha 5 servizi simultanei; oltre si riceve 503.

## Scrivere

- **Conferma.** Prima di `mexal_crea`, `mexal_modifica` o `mexal_servizio` con effetti, riassumi
  all'utente cosa stai per scrivere: archivio, chiave e campi principali. `mexal_elimina` richiede
  sempre una conferma esplicita.
- **`mexal_crea` non è idempotente.** Se l'esito è incerto (timeout, errore di rete), non
  ripetere la chiamata. Prima cerca se il record è stato creato.
- **Campi obbligatori.** Usa `obbligatorio_crea` da `mexal_descrivi_risorsa`. I campi
  `sola_lettura` non vanno inviati.
- **Revisione.** Invia solo i campi da cambiare, più `data_ult_mod` letto con `mexal_leggi`. Se
  il controllo di modifica concorrente è attivo e qualcuno ha modificato il record nel frattempo,
  la revisione viene rifiutata: rileggi il record e riproponi la modifica.

### Documenti (ordini, preventivi, movimenti di magazzino)

- Il documento completo è una testata (campi scalari) più le righe. Ogni campo di riga è un
  array `[[indice_riga, valore], …]`, e `indice_riga` è la posizione della riga nel documento.
  Gli indici devono essere coerenti fra tutti i campi di riga.
- **Nuovo documento:** `numero: 0` per la numerazione automatica, dove ammessa (non per le BF).
  Ogni riga nuova ha `id_riga` = 0. Per le righe articolo `tp_riga` = `"R"`.
- **Revisione (PUT):** vanno indicati **tutti** gli `id_riga` e `tp_riga` esistenti, anche
  quelli delle righe che non cambiano. Le righe omesse vengono **cancellate**. Per cambiare solo
  la testata usa `solo_testata: true`.
- **Aggiungere righe** a un documento esistente: usa il servizio `ins_righe_<tipo>` (es.
  `ins_righe_ordine_cliente`). A differenza della PUT, restituisce gli `id_riga` assegnati.
- **Trasformare** (es. bolle BC in fattura FT): `mexal_crea` sulla risorsa di destinazione con
  `sigla_trasformazione`. Per ogni riga servono `sigla_doc_orig`, `serie_doc_orig`,
  `numero_doc_orig`, `data_doc_orig`, `id_rif_testata` e `dt_ult_mod_orig` (letto dalla GET del
  documento di origine). Per evadere parzialmente una riga si usa `quantita_trs`: la differenza
  resta a residuo.
- `id_riga` identifica la riga ma non la ordina: non usarlo per ordinare le righe.

## Errori

| Risposta | Significato | Cosa fare |
| --- | --- | --- |
| 400 `6001 - errore gestionale […]` | il gestionale ha rifiutato i dati: il dettaglio fra parentesi quadre dice cosa | correggi il campo indicato (nome, formato, codice inesistente); non ripetere identica |
| 400 `… disponibile solo per aziende di livello produzione` | risorsa non abilitata per l'azienda | spiega il limite all'utente |
| 404 | chiave inesistente o path errato | ricontrolla la chiave; per codici con `/` serve l'encoding |
| 401, `2002`, `3002` | credenziali o dominio rifiutati | **fermati e avvisa l'utente.** Non riprovare: pochi tentativi falliti bloccano l'utente WebAPI e serve un amministratore per sbloccarlo |
| 503 | pool di servizi esaurito | attendi e riprova una volta, senza parallelizzare |
| 408 | elaborazione troppo lunga | restringi la richiesta (filtri, `max`, `campi`) |

## Esempi

### Trovare un cliente per nome

```json
{"risorsa": "clienti",
 "filtri": [{"campo": "ragione_sociale", "condizione": "contiene", "valore": "rossi", "case_insensitive": true}],
 "campi": ["codice", "ragione_sociale", "partita_iva", "localita"], "max": 20}
```

### Creare un cliente

1. Se l'utente indica una condizione di pagamento a parole ("30 giorni"), cercala in
   `dati-generali/pagamenti` (`descrizione contiene "30"`) e usa il suo `id` in `cod_pagamento`.
2. Chiama `mexal_crea` su `clienti`. Sono obbligatori `codice` (mastro.conto) e
   `ragione_sociale`:

```json
{"risorsa": "clienti",
 "dati": {"codice": "501.00100", "ragione_sociale": "Cliente Esempio Srl", "indirizzo": "Via Verdi 5",
          "cap": "00100", "localita": "ROMA", "provincia": "RM", "partita_iva": "IT01234567890", "cod_pagamento": 9}}
```

### Creare un ordine cliente

1. Trova il cliente: `cod_conto` → `clienti.codice`.
2. Trova gli articoli: `codice_articolo` → `articoli.codice`. Per prezzo, sconto e provvigione
   applicabili usa il servizio `condizioni_documento`.
3. Chiama `mexal_crea` su `documenti/ordini-clienti`:

```json
{"risorsa": "documenti/ordini-clienti",
 "dati": {"sigla": "OC", "serie": 1, "numero": 0, "cod_conto": "501.00001", "data_documento": "20260616",
          "id_riga": [[1, 0], [2, 0]], "tp_riga": [[1, "R"], [2, "R"]],
          "codice_articolo": [[1, "ATT001"], [2, "ATT002"]], "quantita": [[1, 2], [2, 5]],
          "cod_iva": [[1, "22"], [2, "22"]]}}
```

La chiave restituita (es. `OC+1+541`) identifica l'ordine.

### Modificare solo la testata di un ordine

Per esempio, per intestarlo a un altro cliente lasciando invariate le righe:

```json
{"risorsa": "documenti/ordini-clienti", "chiave": {"sigla": "OC", "serie": 1, "numero": 541},
 "solo_testata": true, "dati": {"cod_conto": "501.00002", "data_ult_mod": "<valore letto con mexal_leggi>"}}
```

### Giacenza di un articolo

`mexal_lista` su `articoli/{cod_articolo}/progressivi` con `parametri_path: {"cod_articolo":
"ATT001"}`. Per la situazione per ubicazione c'è il servizio `get_prog_ubicazioni`.

### Esposizione di un cliente, PDF di un ordine

- `mexal_servizio` con `cmd: "calcolo_esposizione"` e
  `dati: {"in_codice_conto": "501.00001", "in_alla_data": "20260101"}`.
- `mexal_servizio` con `cmd: "stampa_ordine_cliente"` e
  `dati: {"sigla": "OC", "serie": 1, "numero": 541}`. La risposta è un PDF.
