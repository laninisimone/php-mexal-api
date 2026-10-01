# Indice delle risorse Mexal WebAPI

> Generato da `tools/api-docs/genera.php` (gestionale 2.9.17, versione 88201).
> Mappa compatta per un agente: per ogni risorsa le operazioni, la chiave, i campi obbligatori in inserimento
> e da quale archivio prendere i codici. Il dettaglio dei campi è in `mexal-catalogo.json`; le regole d'uso in `AGENTE.md`.

## Banche

### `banche`

Tabella delle banche (ABI, CAB, filiale).

- Operazioni: lista, cerca
- Campi: 28 (origine: info)

## Indirizzi spedizione

### `indirizzi-spedizione`

Indirizzi di spedizione (destinazioni diverse) di clienti e fornitori.

- Operazioni: lista, cerca, leggi, crea, modifica
- Chiave: `{id}`
- Codici da altri archivi: `cod_conto` → clienti.codice / fornitori.codice; `cod_conto_sost` → clienti.codice / fornitori.codice
- Campi: 34 (origine: info)

## Referenti

### `referenti/clienti`

Referenti (persone di contatto) dei clienti.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice_cli_for}+{cod_referente}`
- Obbligatori in inserimento: `codice_cli_for`
- Codici da altri archivi: `codice_cli_for` → clienti.codice; `cod_posizione` → dati-generali/posizione-referenti.id
- Campi: 11 (origine: info)

### `referenti/fornitori`

Referenti (persone di contatto) dei fornitori.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice_cli_for}+{cod_referente}`
- Codici da altri archivi: `codice_cli_for` → fornitori.codice; `cod_posizione` → dati-generali/posizione-referenti.id
- Campi: 11 (origine: info)

## Clienti

### `clienti`

Anagrafica clienti. Il codice è un conto nel formato mastro.conto (es. 501.00001). È la controparte di ordini clienti, preventivi clienti e movimenti di vendita.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Obbligatori in inserimento: `codice`, `ragione_sociale`
- Codici da altri archivi: `id_rubrica_uni` → anagrafica-unica.id_anagrafica; `cod_pg_no_deper` → dati-generali/pagamenti.id; `cod_pg_deper` → dati-generali/pagamenti.id; `id_banca` → banche.codice; `valuta` → dati-generali/valute.id; `cod_cat_sta` → dati-generali/categorie-statistiche-cli-for.id; `cod_zona` → dati-generali/zone-clienti-fornitori.codice; `cod_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice; `cod_lingua` → dati-generali/lingue-straniere.codice; `cod_listino` → dati-generali/listini.id; `cod_cat_sconti` → dati-generali/categorie-sconti.codice; `cod_agente` → conti.codice; `cod_cat_pr` → dati-generali/categorie-provvigioni.codice; `cod_ind_sped` → indirizzi-spedizione.id; `cod_asp_beni` → dati-generali/aspetto-esteriore-beni.codice
- Campi: 180 (origine: info)

## Fornitori

### `fornitori`

Anagrafica fornitori. Il codice è un conto nel formato mastro.conto (es. 601.00001). È la controparte di ordini fornitori e movimenti di acquisto.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Obbligatori in inserimento: `codice`, `ragione_sociale`
- Codici da altri archivi: `id_rubrica_uni` → anagrafica-unica.id_anagrafica; `cod_pg_no_deper` → dati-generali/pagamenti.id; `cod_pg_deper` → dati-generali/pagamenti.id; `id_banca` → banche.codice; `valuta` → dati-generali/valute.id; `cod_cat_sta` → dati-generali/categorie-statistiche-cli-for.id; `cod_zona` → dati-generali/zone-clienti-fornitori.codice; `cod_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice; `cod_lingua` → dati-generali/lingue-straniere.codice; `cod_agente` → conti.codice; `cod_ind_sped` → indirizzi-spedizione.id; `cod_asp_beni` → dati-generali/aspetto-esteriore-beni.codice
- Campi: 165 (origine: info)

## Conti

### `conti`

Piano dei conti: conti generali, clienti, fornitori, banche, agenti. Codice nel formato mastro.conto.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Obbligatori in inserimento: `codice`, `ragione_sociale`
- Codici da altri archivi: `id_banca` → banche.codice
- Campi: 68 (origine: info)

## Articoli

### `articoli`

Anagrafica articoli di magazzino: descrizioni, unità di misura, prezzi, costi, aliquota IVA, gruppo merceologico, dati di magazzino.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Obbligatori in inserimento: `codice`, `descrizione`
- Codici da altri archivi: `cod_struttura` → dati-generali/strutture-articoli.codice; `alq_iva` → dati-generali/esenzioni-iva.codice; `cod_imballo` → dati-generali/imballi.cod_imballo; `id_cat_sconto` → dati-generali/categorie-sconti-articoli.codice; `id_categoria_pr` → dati-generali/categorie-provvigioni-articoli.codice; `id_cat_prezzo` → dati-generali/categorie-prezzi.codice; `cod_ricavo` → conti.codice; `cod_costo` → conti.codice; `cod_ric_sospeso` → conti.codice; `cod_fornitore` → fornitori.codice; `cod_agente` → conti.codice; `id_listino` → dati-generali/listini.id; `id_valuta_extra` → dati-generali/valute.id; `cod_grp_merc` → dati-generali/gruppi-merceologici.codice; `cod_natura` → dati-generali/nature-articoli.codice; `cod_tp_lotto` → dati-generali/tipi-lotti-matricole.tipo_lotto; `id_ubicazione` → ubicazioni.id
- Campi: 194 (origine: info)

### `articoli/{codice}/allegati`

Allegato binario di un articolo (immagine, icona, immagine-catalogo). Restituisce il file, non JSON.

- Operazioni: leggi
- Parametri nel path: `codice`
- Chiave: `{tipo}` (tipo ∈ {immagine, icona, immagine-catalogo})
- Campi: 0 (origine: nessuna)

### `articoli/{cod_articolo}/progressivi`

Progressivi di magazzino (giacenze, carichi, scarichi, ordinato, impegnato) di un singolo articolo.

- Operazioni: lista
- Parametri nel path: `cod_articolo`
- Codici da altri archivi: `cod_articolo` → articoli.codice; `id_magazzino` → dati-generali/magazzini.id; `id_ubicazione` → ubicazioni.id; `id_lotto` → lotti.id
- Campi: 29 (origine: manuale)

### `articoli/{codice_articolo}/abbinati`

Articoli abbinati a un articolo (gestione abbinamenti/colori): inserimento, lettura, modifica e cancellazione.

- Operazioni: leggi, crea, modifica, elimina
- Parametri nel path: `codice_articolo`
- Chiave: `{codice}+{prog_abbinati}`
- Obbligatori in inserimento: `codice_abb`, `prog_abbinati`
- Codici da altri archivi: `codice` → articoli.codice; `codice_articolo` → articoli.codice; `codice_abb` → articoli.codice
- Campi: 11 (origine: info)

## Articoli abbinati

### `articoli-abbinati`

Vista trasversale di tutti gli abbinamenti fra articoli.

- Operazioni: lista, cerca
- Codici da altri archivi: `codice` → articoli.codice; `codice_articolo` → articoli.codice
- Campi: 9 (origine: info)

## Progressivi articoli

### `progressivi-articoli`

Progressivi di magazzino di tutti gli articoli, per articolo + magazzino + ubicazione + lotto. Risposta in formato colonnare: ogni campo è una lista di tuple [indice, valore].

- Operazioni: lista
- Codici da altri archivi: `cod_articolo` → articoli.codice; `id_magazzino` → dati-generali/magazzini.id; `id_ubicazione` → ubicazioni.id; `id_lotto` → lotti.id
- Campi: 29 (origine: manuale)

## Dba

### `dba`

Distinte base automatiche degli articoli (componenti di un articolo composto).

- Operazioni: lista, cerca
- Codici da altri archivi: `codice` → articoli.codice; `cod_articolo_cmp` → articoli.codice
- Campi: 12 (origine: info)

## Documenti

### `documenti/moduli-stampa`

Moduli di stampa disponibili per le sigle documento (da usare in cod_modulo).

- Operazioni: lista, leggi
- Chiave: `{in_sigla_doc}` (in_sigla_doc ~ ^[A-Z]*$)
- Campi: 2 (origine: campione)

### `documenti/ordini-clienti`

Ordini clienti (sigle OC, OX). La lista restituisce le testate; il singolo documento restituisce testata e righe, con le righe come variabili array [[indice_riga, valore], ...].

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{sigla}+{serie}+{numero}` (sigla ∈ {OC, OX})
- Documento con righe: i campi di `documenti/ordini-clienti/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `sigla`, `serie`, `numero`, `cod_conto`, `id_riga`, `tp_riga`, `codice_articolo`, `quantita`, `cod_iva`
- Codici da altri archivi: `cod_conto` → clienti.codice; `cod_modulo` → documenti/moduli-stampa.codice; `id_causale` → dati-generali/causali-movimenti.id; `id_magazzino` → dati-generali/magazzini.id; `id_costo_ricavo` → dati-generali/centri-costo-ricavo.id; `codice_agente` → conti.codice; `id_valuta` → dati-generali/valute.id; `id_ind_sped` → indirizzi-spedizione.id; `cod_anag_sped` → indirizzi-spedizione.id; `id_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice
- Campi: 63 (origine: info)

### `documenti/ordini-clienti/righe`

Vista trasversale delle righe di tutti gli ordini clienti.

- Operazioni: lista, cerca
- Righe di `documenti/ordini-clienti` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_articolo` → articoli.codice; `id_ccr_riga` → dati-generali/centri-costo-ricavo.id; `id_mag_riga` → dati-generali/magazzini.id; `cod_iva` → dati-generali/esenzioni-iva.codice; `id_ubicazione` → ubicazioni.id; `cod_taglia` → dati-generali/taglie.cod_serie; `id_lotto` → lotti.id; `id_lista` → liste-prelievo.id; `cod_art_anag` → articoli.codice
- Campi: 60 (origine: info)

### `documenti/ordini-fornitori`

Ordini fornitori (sigla OF). Stessa struttura degli ordini clienti.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{sigla}+{serie}+{numero}` (sigla ∈ {OF})
- Documento con righe: i campi di `documenti/ordini-fornitori/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `sigla`, `serie`, `numero`, `cod_conto`, `id_riga`, `tp_riga`, `codice_articolo`, `quantita`, `cod_iva`
- Codici da altri archivi: `cod_conto` → fornitori.codice; `cod_modulo` → documenti/moduli-stampa.codice; `id_causale` → dati-generali/causali-movimenti.id; `id_magazzino` → dati-generali/magazzini.id; `id_costo_ricavo` → dati-generali/centri-costo-ricavo.id; `codice_agente` → conti.codice; `id_valuta` → dati-generali/valute.id; `id_ind_sped` → indirizzi-spedizione.id; `cod_anag_sped` → indirizzi-spedizione.id; `id_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice
- Campi: 63 (origine: info)

### `documenti/ordini-fornitori/righe`

Vista trasversale delle righe di tutti gli ordini fornitori.

- Operazioni: lista, cerca
- Righe di `documenti/ordini-fornitori` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_articolo` → articoli.codice; `id_ccr_riga` → dati-generali/centri-costo-ricavo.id; `id_mag_riga` → dati-generali/magazzini.id; `cod_iva` → dati-generali/esenzioni-iva.codice; `id_ubicazione` → ubicazioni.id; `cod_taglia` → dati-generali/taglie.cod_serie; `id_lotto` → lotti.id; `id_lista` → liste-prelievo.id; `cod_art_anag` → articoli.codice
- Campi: 60 (origine: info)

### `documenti/ordini-matrici`

Ordini matrice (sigle MA, MF, MX). Stessa struttura degli ordini clienti.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{sigla}+{serie}+{numero}` (sigla ∈ {MA, MF, MX})
- Documento con righe: i campi di `documenti/ordini-matrici/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `sigla`, `serie`, `numero`, `cod_conto`, `id_riga`, `tp_riga`, `codice_articolo`, `quantita`, `cod_iva`
- Codici da altri archivi: `cod_conto` → clienti.codice / fornitori.codice; `cod_modulo` → documenti/moduli-stampa.codice; `id_causale` → dati-generali/causali-movimenti.id; `id_magazzino` → dati-generali/magazzini.id; `id_costo_ricavo` → dati-generali/centri-costo-ricavo.id; `codice_agente` → conti.codice; `id_valuta` → dati-generali/valute.id; `id_ind_sped` → indirizzi-spedizione.id; `cod_anag_sped` → indirizzi-spedizione.id; `id_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice
- Campi: 63 (origine: info)

### `documenti/ordini-matrici/righe`

Vista trasversale delle righe di tutti gli ordini matrice.

- Operazioni: lista, cerca
- Righe di `documenti/ordini-matrici` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_articolo` → articoli.codice; `id_ccr_riga` → dati-generali/centri-costo-ricavo.id; `id_mag_riga` → dati-generali/magazzini.id; `cod_iva` → dati-generali/esenzioni-iva.codice; `id_ubicazione` → ubicazioni.id; `cod_taglia` → dati-generali/taglie.cod_serie; `id_lotto` → lotti.id; `id_lista` → liste-prelievo.id; `cod_art_anag` → articoli.codice
- Campi: 60 (origine: info)

### `documenti/preventivi`

Preventivi (sigle PC, PF, PR, PX). Stessa struttura degli ordini clienti.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{sigla}+{serie}+{numero}` (sigla ∈ {PC, PF, PR, PX})
- Documento con righe: i campi di `documenti/preventivi/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `sigla`, `serie`, `numero`, `cod_conto`, `id_riga`, `tp_riga`, `codice_articolo`, `quantita`, `cod_iva`
- Codici da altri archivi: `cod_conto` → clienti.codice / fornitori.codice; `cod_modulo` → documenti/moduli-stampa.codice; `id_causale` → dati-generali/causali-movimenti.id; `id_magazzino` → dati-generali/magazzini.id; `id_costo_ricavo` → dati-generali/centri-costo-ricavo.id; `codice_agente` → conti.codice; `id_valuta` → dati-generali/valute.id; `id_ind_sped` → indirizzi-spedizione.id; `cod_anag_sped` → indirizzi-spedizione.id; `id_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice
- Campi: 63 (origine: info)

### `documenti/preventivi/righe`

Vista trasversale delle righe di tutti i preventivi.

- Operazioni: lista, cerca
- Righe di `documenti/preventivi` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_articolo` → articoli.codice; `id_ccr_riga` → dati-generali/centri-costo-ricavo.id; `id_mag_riga` → dati-generali/magazzini.id; `cod_iva` → dati-generali/esenzioni-iva.codice; `id_ubicazione` → ubicazioni.id; `cod_taglia` → dati-generali/taglie.cod_serie; `id_lotto` → lotti.id; `id_lista` → liste-prelievo.id; `cod_art_anag` → articoli.codice
- Campi: 60 (origine: info)

### `documenti/movimenti-magazzino`

Movimenti di magazzino: DDT, fatture, carichi, scarichi (sigla di 2 lettere, es. BC, BF, FT). Supporta la trasformazione da altri documenti con ?sigla_trasformazione.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{sigla}+{serie}+{numero}+{cod_conto}?` (sigla ~ ^[A-Z]{2,2}$)
- Documento con righe: i campi di `documenti/movimenti-magazzino/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `sigla`, `serie`, `numero`, `id_riga`, `tp_riga`, `codice_articolo`, `quantita`, `cod_iva`
- Codici da altri archivi: `cod_conto` → clienti.codice / fornitori.codice; `cod_modulo` → documenti/moduli-stampa.codice; `id_causale` → dati-generali/causali-movimenti.id; `id_magazzino` → dati-generali/magazzini.id; `id_magazzino_a` → dati-generali/magazzini.id; `id_costo_ricavo` → dati-generali/centri-costo-ricavo.id; `id_catsta_conto` → dati-generali/categorie-statistiche-cli-for.id; `id_zona_conto` → dati-generali/zone-clienti-fornitori.codice; `codice_agente` → conti.codice; `id_valuta` → dati-generali/valute.id; `id_ind_sped` → indirizzi-spedizione.id; `cod_anag_sped` → indirizzi-spedizione.id; `id_pagamento` → dati-generali/pagamenti.id; `cod_banca` → conti.codice
- Campi: 79 (origine: info)

### `documenti/movimenti-magazzino/righe`

Vista trasversale delle righe di tutti i movimenti di magazzino.

- Operazioni: lista, cerca
- Righe di `documenti/movimenti-magazzino` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `cod_conto` → clienti.codice / fornitori.codice; `codice_articolo` → articoli.codice; `id_ccr_riga` → dati-generali/centri-costo-ricavo.id; `id_mag_da_riga` → dati-generali/magazzini.id; `id_mag_a_riga` → dati-generali/magazzini.id; `cod_iva` → dati-generali/esenzioni-iva.codice; `id_ubicazione` → ubicazioni.id; `id_ubicazione_a` → ubicazioni.id; `cod_taglia` → dati-generali/taglie.cod_serie; `id_lotto` → lotti.id; `id_lista` → liste-prelievo.id; `cod_art_anag` → articoli.codice
- Campi: 74 (origine: info)

### `documenti/lavorazione/prodotti`

Prodotti in lavorazione (sola lettura). Richiede un'azienda di livello produzione.

- Operazioni: lista, cerca
- Campi: 0 (origine: nessuna)

### `documenti/lavorazione/bolle`

Bolle di lavorazione. Richiede un'azienda di livello produzione.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}+{cod_sottobolla}`
- Campi: 0 (origine: nessuna)

### `documenti/lavorazione/impegni`

Impegni di materiale delle bolle di lavorazione, chiave a 8 segmenti. Richiede un'azienda di livello produzione.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}+{cod_sottobolla}+{nr_rif_pf}+{nr_fase}+{cod_magazzino}+{cod_articolo}+{id_ubicazione}+{progressivo}`
- Obbligatori in inserimento: `codice`, `cod_sottobolla`, `nr_rif_pf`, `nr_fase`, `cod_magazzino`, `cod_articolo`, `id_ubicazione`
- Codici da altri archivi: `cod_magazzino` → dati-generali/magazzini.id; `cod_articolo` → articoli.codice; `id_ubicazione` → ubicazioni.id; `id_lotto` → lotti.id
- Campi: 35 (origine: manuale)

## Impegni

### `impegni`

Endpoint legacy degli impegni delle bolle di lavorazione: deprecato, usare documenti/lavorazione/impegni.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}+{cod_sottobolla}+{nr_rif_pf}+{nr_fase}+{cod_magazzino}+{cod_articolo}+{id_ubicazione}`
- Codici da altri archivi: `cod_magazzino` → dati-generali/magazzini.id; `cod_articolo` → articoli.codice; `id_ubicazione` → ubicazioni.id; `id_lotto` → lotti.id
- Campi: 35 (origine: manuale)

## Prima nota

### `prima-nota`

Registrazioni contabili di prima nota (testata + righe). La lettura del singolo record restituisce le righe come variabili array.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{in_data_registr}+{in_progressivo}+{in_cau_contabile}?+{in_rstro_prot_iva}?+{in_serie_prot}?+{in_nr_protocollo}?+{in_nr_documento}?+{in_data_documento}?` (in_data_registr ~ ^[0-9]{8}$; in_cau_contabile ~ ^[A-Z]{2}$; in_rstro_prot_iva ~ ^[A-Z]+$; in_data_documento ~ ^[0-9]{8}$)
- Documento con righe: i campi di `prima-nota/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `data_registr`, `cau_contabile`, `rstro_prot_iva`, `codice_conto`, `importo_riga`
- Campi: 48 (origine: info)

### `prima-nota/righe`

Vista trasversale delle righe contabili di tutte le registrazioni di prima nota.

- Operazioni: lista, cerca
- Righe di `prima-nota` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_conto` → conti.codice
- Campi: 60 (origine: info)

## Mydb

### `mydb/{nome_archivio}`

Record di un archivio MyDB di una app Passbuilder (nome_archivio nel formato APP@archivio).

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Parametri nel path: `nome_archivio`
- Chiave: `{id}`
- Obbligatori in inserimento: `id`, `numero_campi`, `dati_campi`
- Campi: 3 (origine: manuale)

### `mydb/{nome_archivio}/{sigla_doc}`

Record di un archivio MyDB collegato a una sigla documento.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Parametri nel path: `nome_archivio`, `sigla_doc`
- Chiave: `{id}`
- Obbligatori in inserimento: `id`, `numero_campi`, `dati_campi`
- Campi: 3 (origine: manuale)

## Distinte base

### `distinte-base/fasi`

Fasi delle distinte base di produzione, con i dati di testata della distinta. Richiede un'azienda di livello produzione.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}+{nr_fase}`
- Obbligatori in inserimento: `codice`, `nr_fase`
- Codici da altri archivi: `codice` → articoli.codice; `cod_art_costo` → articoli.codice; `codice_pf_padre` → articoli.codice; `cod_mag_mp` → dati-generali/magazzini.id; `cod_cliente` → clienti.codice; `cod_fornit_ctl` → fornitori.codice; `cod_mag_mp_ctl` → dati-generali/magazzini.id
- Campi: 35 (origine: manuale)

### `distinte-base/componenti`

Componenti (materie prime) delle fasi delle distinte base di produzione. Sola lettura. Richiede un'azienda di livello produzione.

- Operazioni: lista, cerca
- Codici da altri archivi: `codice` → articoli.codice; `codice_mp` → articoli.codice; `cod_mag_impegni` → dati-generali/magazzini.id; `cod_art_cond` → articoli.codice; `cod_cli_cond` → clienti.codice; `cod_art_sost` → articoli.codice; `cod_fornitore` → fornitori.codice
- Campi: 29 (origine: manuale)

## Lotti

### `lotti`

Lotti e matricole di magazzino, con stato, scadenza e campi personalizzati del tipo lotto.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{id}`
- Obbligatori in inserimento: `cod_articolo`, `cod_tipo_lotto`
- Codici da altri archivi: `cod_articolo` → articoli.codice; `cod_tipo_lotto` → dati-generali/tipi-lotti-matricole.tipo_lotto; `cod_fornitore` → fornitori.codice
- Campi: 20 (origine: manuale)

## Ubicazioni

### `ubicazioni`

Ubicazioni di magazzino (scaffali, posizioni) con vincoli su articolo, fornitore, gruppo merceologico e natura.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{id}`
- Obbligatori in inserimento: `id_magazzino`, `codice`, `descrizione`
- Codici da altri archivi: `id_magazzino` → dati-generali/magazzini.id; `cod_art_escl` → articoli.codice; `grp_merc_escl` → dati-generali/gruppi-merceologici.codice; `natura_escl` → dati-generali/nature-articoli.codice; `cod_for_escl` → fornitori.codice
- Campi: 28 (origine: info)

## Scadenzario

### `scadenzario`

Scadenzario: partite aperte, rate ed effetti di clienti e fornitori.

- Operazioni: lista, cerca
- Codici da altri archivi: `codice_agente` → conti.codice; `conto_eff` → conti.codice; `conto_presso` → conti.codice
- Campi: 42 (origine: info)

## Liste prelievo

### `liste-prelievo`

Liste di prelievo (picking) del magazzino: testate. Il singolo record restituisce anche le righe.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{id}`
- Documento con righe: i campi di `liste-prelievo/righe` compaiono come array per riga nella lettura e nella scrittura del singolo documento
- Obbligatori in inserimento: `id`
- Campi: 7 (origine: info)

### `liste-prelievo/righe`

Vista trasversale delle righe delle liste di prelievo.

- Operazioni: lista, cerca
- Righe di `liste-prelievo` (vista trasversale, sola lettura e ricerca)
- Codici da altri archivi: `codice_articolo` → articoli.codice; `id_magazzino` → dati-generali/magazzini.id; `id_ubicazione` → ubicazioni.id; `id_lotto` → lotti.id; `id_lotto_ord` → lotti.id; `id_alias` → alias-articoli.id; `cod_conto` → clienti.codice / fornitori.codice; `cod_agente` → conti.codice
- Campi: 40 (origine: info)

## Alias articoli

### `alias-articoli`

Codici alternativi (alias) degli articoli: codici cliente, fornitore o personalizzati.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{cod_alias}+{prog_alias}+{cod_articolo}`
- Obbligatori in inserimento: `cod_alias`, `prog_alias`, `cod_articolo`
- Codici da altri archivi: `cod_articolo` → articoli.codice
- Campi: 12 (origine: info)

## Mappa articoli

### `mappa-articoli`

Ubicazione degli articoli nei magazzini e parametri di scorta.

- Operazioni: lista, cerca
- Codici da altri archivi: `codice` → articoli.codice; `id_ubicazione` → ubicazioni.id
- Campi: 14 (origine: info)

## Aziende

### `aziende`

Aziende configurate nell'installazione. Non richiede coordinate gestionale.

- Operazioni: lista, crea
- Obbligatori in inserimento: `in_sigla_azienda`, `in_data_inizio_gestione`, `in_gest_impresa_professionista`, `in_gestione_fiscale`, `in_id_anagrafica`, `in_mese_inizio_anno_contabile`
- Campi: 19 (origine: manuale)

## Anagrafica unica

### `anagrafica-unica`

Rubrica unica: anagrafiche condivise fra aziende, con storicizzazione delle variazioni. Non richiede coordinate gestionale.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{id_anagrafica}+{data_fine_valid}?` (data_fine_valid ~ ^[0-9]{8}$)
- Obbligatori in inserimento: `anag_cod_fis`, `anag_rag_soc`, `anag_piva`
- Campi: 98 (origine: info)

### `anagrafica-unica/storico`

Versioni storicizzate delle anagrafiche della rubrica unica. Non richiede coordinate gestionale.

- Operazioni: lista, cerca
- Campi: 98 (origine: info)

## Anagrafica contatti

### `anagrafica-contatti`

Anagrafica contatti (prospect e nominativi non ancora clienti) con condizioni commerciali.

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Obbligatori in inserimento: `codice`, `descrizione`
- Codici da altri archivi: `id_pagamento` → dati-generali/pagamenti.id; `id_listino` → dati-generali/listini.id; `cat_sconto` → dati-generali/categorie-sconti.codice; `cat_provvigione` → dati-generali/categorie-provvigioni.codice; `cod_iva` → dati-generali/esenzioni-iva.codice; `cod_lingua` → dati-generali/lingue-straniere.codice; `cod_agente` → conti.codice; `cat_statistica` → dati-generali/categorie-statistiche-cli-for.id; `zona` → dati-generali/zone-clienti-fornitori.codice
- Campi: 51 (origine: info)

## Dati generali

### `dati-generali/aspetto-esteriore-beni`

Aspetto esteriore beni

- Operazioni: lista
- Campi: 2 (origine: info)

### `dati-generali/centri-costo-ricavo`

Centri di costo e ricavo della contabilità analitica.

- Operazioni: lista, cerca
- Campi: 3 (origine: info)

### `dati-generali/classi-docuvision`

Classi docuvision

- Operazioni: lista
- Campi: 2 (origine: campione)

### `dati-generali/correlazione-unita-misura`

Correlazioni unita misura

- Operazioni: lista
- Campi: 0 (origine: nessuna)

### `dati-generali/installazione`

Versione e dati dell'installazione Passepartout. Non richiede coordinate gestionale.

- Operazioni: lista
- Campi: 5 (origine: manuale)

### `dati-generali/cartella-abbinamenti`

Cartelle abbinamenti

- Operazioni: lista, cerca
- Codici da altri archivi: `codice` → articoli.codice
- Campi: 6 (origine: info)

### `dati-generali/utenti`

Utenti configurati nell'installazione. Non richiede coordinate gestionale.

- Operazioni: lista
- Campi: 5 (origine: manuale)

### `dati-generali/valute`

Tabella delle valute: il campo id è il valore da usare in id_valuta / valuta.

- Operazioni: lista, cerca
- Campi: 9 (origine: info)

### `dati-generali/posizione-referenti`

Posizioni (ruoli) dei referenti.

- Operazioni: lista
- Campi: 2 (origine: campione)

### `dati-generali/gruppi-mastri`

Gruppi e mastri del piano dei conti.

- Operazioni: lista, cerca
- Campi: 17 (origine: info)

### `dati-generali/fasi-lavorazione`

Fasi di lavorazione

- Operazioni: lista, cerca
- Campi: 2 (origine: manuale)

### `dati-generali/categorie-statistiche-cli-for`

Categorie statistiche di clienti e fornitori.

- Operazioni: lista, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Campi: 2 (origine: campione)

### `dati-generali/lingue-straniere`

Lingue straniere

- Operazioni: lista, leggi, crea, modifica, elimina
- Chiave: `{codice}`
- Campi: 2 (origine: manuale)

### `dati-generali/condizioni-generali-pagamenti`

Condizioni generali di pagamento (lista semplice).

- Operazioni: lista
- Campi: 9 (origine: campione)

### `dati-generali/categorie-provvigioni-articoli`

Categorie provvigioni articoli

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/categorie-sconti-articoli`

Categorie sconti articoli

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/magazzini`

Tabella dei magazzini: il campo id è il numero magazzino usato in id_magazzino e nell'header Coordinate-Gestionale.

- Operazioni: lista, cerca
- Campi: 9 (origine: info)

### `dati-generali/sconti-listini`

Sconti listini

- Operazioni: lista, cerca
- Campi: 4 (origine: info)

### `dati-generali/abbinamenti-colori`

Abbinamenti colori

- Operazioni: lista, cerca
- Campi: 0 (origine: nessuna)

### `dati-generali/taglie`

Taglie

- Operazioni: lista, cerca
- Campi: 7 (origine: info)

### `dati-generali/pagamenti`

Tabella delle condizioni di pagamento: il campo id è il valore da usare in cod_pagamento / id_pagamento.

- Operazioni: lista, cerca
- Codici da altri archivi: `conto_ab_pas` → conti.codice; `conto_ab_att` → conti.codice; `conto_pagamento` → conti.codice
- Campi: 22 (origine: info)

### `dati-generali/tipi-lotti-matricole`

Tipi di lotto/matricola, con i campi personalizzati. In creazione tipo_lotto va impostato a "**" (codifica automatica).

- Operazioni: lista, cerca, leggi, crea, modifica, elimina
- Chiave: `{tipo_lotto}`
- Campi: 17 (origine: info)

### `dati-generali/gruppi-merceologici`

Gruppi merceologici degli articoli.

- Operazioni: lista, cerca
- Codici da altri archivi: `cod_ricavo` → conti.codice; `cod_costo` → conti.codice
- Campi: 10 (origine: info)

### `dati-generali/esenzioni-iva`

Codici di esenzione IVA (natura operazione per la fattura elettronica). Le aliquote ordinarie si indicano con il loro valore numerico (es. "22").

- Operazioni: lista, cerca
- Campi: 8 (origine: info)

### `dati-generali/nature-articoli`

Nature degli articoli.

- Operazioni: lista, cerca
- Campi: 5 (origine: info)

### `dati-generali/strutture-articoli`

Strutture di codifica degli articoli.

- Operazioni: lista, cerca
- Campi: 29 (origine: info)

### `dati-generali/categorie-prezzi`

Categorie prezzi articoli

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/categorie-provvigioni`

Categorie proviggioni

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/categorie-sconti`

Categorie sconti clienti fornitori

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/categorie-statistiche-articoli`

Categorie statistiche degli articoli.

- Operazioni: lista, cerca
- Campi: 3 (origine: info)

### `dati-generali/causali-movimenti`

Causali dei movimenti di magazzino: il campo id è il valore di id_causale nei documenti.

- Operazioni: lista, cerca
- Campi: 5 (origine: info)

### `dati-generali/omaggi-abbuoni`

Omaggi e abbuoni

- Operazioni: lista, cerca
- Campi: 5 (origine: info)

### `dati-generali/listini`

Tabella dei listini prezzi: il campo id è il numero listino.

- Operazioni: lista, cerca
- Campi: 18 (origine: info)

### `dati-generali/provvigioni-listini`

Provvigioni listini

- Operazioni: lista, cerca
- Codici da altri archivi: `cod_agente` → conti.codice
- Campi: 7 (origine: info)

### `dati-generali/sconti-quantita`

Sconti quantità

- Operazioni: lista, cerca
- Campi: 6 (origine: info)

### `dati-generali/serie-documenti`

Serie di numerazione disponibili per ciascuna sigla documento.

- Operazioni: lista, cerca
- Campi: 3 (origine: info)

### `dati-generali/particolarita`

Particolarità di prezzo, sconto e provvigione per conto, categoria, articolo o gruppo.

- Operazioni: lista, cerca
- Codici da altri archivi: `id_zona_conto` → dati-generali/zone-clienti-fornitori.codice; `id_catsta_conto` → dati-generali/categorie-statistiche-cli-for.id; `cod_articolo` → articoli.codice; `cod_natura` → dati-generali/nature-articoli.codice; `cod_grp_merc` → dati-generali/gruppi-merceologici.codice; `cod_iva` → dati-generali/esenzioni-iva.codice; `cod_agente_1` → conti.codice; `cod_agente_2` → conti.codice
- Campi: 36 (origine: info)

### `dati-generali/imballi`

Tabella degli imballi.

- Operazioni: lista, cerca
- Codici da altri archivi: `alq_imb_perdere` → dati-generali/esenzioni-iva.codice; `alq_imb_rendere` → dati-generali/esenzioni-iva.codice; `alq_imb_vendita` → dati-generali/esenzioni-iva.codice
- Campi: 6 (origine: info)

### `dati-generali/zone-clienti-fornitori`

Zone dei clienti e fornitori.

- Operazioni: lista
- Campi: 2 (origine: manuale)

### `dati-generali/parametri-aziendali`

Parametri di configurazione dell'azienda (contabili, magazzino, ...), raggruppati per sezione.

- Operazioni: lista
- Campi: 3 (origine: campione)

## Canale servizi

- `esec_collage_server_remoto` — Collage Server Remoto
- `sviluppo_distinta_base` — Sviluppo Distinta Base
- `avanzamento_produzione` — Avanzamento di Produzione
- `spezzariga_bl` — Spezza Riga Bolle di Lavorazione
- `condizioni_documento` — Condizioni Documento
- `calcolo_esposizione` — Calcolo Esposizione
- `get_prog_ubicazioni` — Progressivi totale Ubicazioni
- `totali_documento` — Totali Documento e Totali Riga
- `totali_riga_documento` — Totali Documento e Totali Riga
- `get_tabella_cap` — Località Italiane (CAP e Zone)
- `get_tabella_zone` — Località Italiane (CAP e Zone)
- `totali_nr_operazioni_contabili` — Conteggio Operazioni Contabili
- `leggi_pratica_dr` — Pratiche Dichiarativi
- `get_lista_pratiche_dr` — Pratiche Dichiarativi
- `get_lista_deleghe` — Deleghe Dichiarativi
- `cambia_stato_delega` — Deleghe Dichiarativi
- `get_registro_operazioni_utente` — Registro Cancellati
- `leggi_dichiarazione_iva_annuale` — Dichiarazioni IVA Annuali
- `leggi_comunicazioni_lipe` — Lettura Comunicazioni LIPE
- `stampa_ordine_cliente` — Stampa PDF di Documenti - Ordine cliente
- `stampa_ordine_fornitore` — Stampa PDF di Documenti - Ordine fornitore
- `stampa_preventivo` — Stampa PDF di Documenti - Preventivo
- `stampa_ordine_matrice` — Stampa PDF di Documenti - Ordine matrice
- `stampa_movimento_magazzino` — Stampa PDF di Documenti - Movimento di magazzino
- `ins_righe_ordine_cliente` — Inserimento Righe nei Documenti - Ordine cliente
- `ins_righe_ordine_fornitore` — Inserimento Righe nei Documenti - Ordine fornitore
- `ins_righe_preventivo` — Inserimento Righe nei Documenti - Preventivo
- `ins_righe_ordine_matrice` — Inserimento Righe nei Documenti - Ordine matrice
- `ins_righe_movimento_magazzino` — Inserimento Righe nei Documenti - Movimento di magazzino
- `lista_docdv` — Lista Allegati Docuvision
- `get_allegato_archivio` — Lettura Allegati Docuvision
- `get_assoc_dv` — Associazioni Docuvision
- `lista_strutture_mydb` — Lista Strutture MyDB
- `get_proprieta_numeratori` — Proprietà Numeratori
- `verifica_autenticazione_mydb` — Autenticazione MyDB
- `get_calendari_produzione` — Calendari di Produzione
- `get_multilotto` — Multilotto
- `cambia_iban_tipo_invio_delega` — Modifica IBAN e Tipo Invio
- `inserisci_aggiorna_iban` — Inserimento-Aggiornamento IBAN
- `get_storico_ordine` — Storia Ordine
- `upload_files` — Upload Files (endpoint /uploads)
