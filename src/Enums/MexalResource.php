<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Enums;

/**
 * Enum nativa PHP 8.1+ contenente i path base delle risorse Mexal.
 * Aggiungi/rimuovi valori qui quando le API cambiano.
 *
 * Elenco allineato al "Riferimento Endpoint" del manuale WebAPI 3.1. Resta comunque un
 * elenco statico: la fonte sempre aggiornata è l'help in linea
 * ($mexal->help(extended: true)) e ResourceClient::info() per i campi.
 *
 * Uso:
 *   MexalResource::CLIENTI->value;                      // 'clienti'
 *   $mexal->resource(MexalResource::CLIENTI);            // client risorsa
 *   MexalResource::fromValueInsensitive('CLIENTI');     // MexalResource|null
 */
enum MexalResource: string
{
    // Dati Generali
    case ASPETTO_ESTERIORE_BENI = 'dati-generali/aspetto-esteriore-beni';
    case CENTRI_COSTO_RICAVO = 'dati-generali/centri-costo-ricavo';
    case CLASSI_DOCUVISION = 'dati-generali/classi-docuvision';
    case CORRELAZIONE_UNITA_MISURA = 'dati-generali/correlazione-unita-misura';
    case INSTALLAZIONE = 'dati-generali/installazione';
    case CARTELLA_ABBINAMENTI = 'dati-generali/cartella-abbinamenti';
    case UTENTI = 'dati-generali/utenti';
    case VALUTE = 'dati-generali/valute';
    case POSIZIONE_REFERENTI = 'dati-generali/posizione-referenti';
    case GRUPPI_MASTRI = 'dati-generali/gruppi-mastri';
    case FASI_LAVORAZIONE = 'dati-generali/fasi-lavorazione';
    case CATEGORIE_STATISTICHE_CLI_FOR = 'dati-generali/categorie-statistiche-cli-for';
    case LINGUE_STRANIERE = 'dati-generali/lingue-straniere';
    case CONDIZIONI_GENERALI_PAGAMENTI = 'dati-generali/condizioni-generali-pagamenti';
    case CATEGORIE_PROVVIGIONI_ARTICOLI = 'dati-generali/categorie-provvigioni-articoli';
    case CATEGORIE_SCONTI_ARTICOLI = 'dati-generali/categorie-sconti-articoli';
    case MAGAZZINI = 'dati-generali/magazzini';
    case SCONTI_LISTINI = 'dati-generali/sconti-listini';
    case ABBINAMENTI_COLORI = 'dati-generali/abbinamenti-colori';
    case TAGLIE = 'dati-generali/taglie';
    case PAGAMENTI = 'dati-generali/pagamenti';
    case TIPI_LOTTI_MATRICOLE = 'dati-generali/tipi-lotti-matricole';
    case GRUPPI_MERCEOLOGICI = 'dati-generali/gruppi-merceologici';
    case ESENZIONI_IVA = 'dati-generali/esenzioni-iva';
    case NATURE_ARTICOLI = 'dati-generali/nature-articoli';
    case STRUTTURE_ARTICOLI = 'dati-generali/strutture-articoli';
    case CATEGORIE_PREZZI = 'dati-generali/categorie-prezzi';
    case CATEGORIE_PROVVIGIONI = 'dati-generali/categorie-provvigioni';
    case CATEGORIE_SCONTI = 'dati-generali/categorie-sconti';
    case CATEGORIE_STATISTICHE_ARTICOLI = 'dati-generali/categorie-statistiche-articoli';
    case CAUSALI_MOVIMENTI = 'dati-generali/causali-movimenti';
    case OMAGGI_ABBUONI = 'dati-generali/omaggi-abbuoni';
    case LISTINI = 'dati-generali/listini';
    case PROVVIGIONI_LISTINI = 'dati-generali/provvigioni-listini';
    case SCONTI_QUANTITA = 'dati-generali/sconti-quantita';
    case SERIE_DOCUMENTI = 'dati-generali/serie-documenti';
    case PARTICOLARITA = 'dati-generali/particolarita';
    case IMBALLI = 'dati-generali/imballi';
    case ZONE_CLIENTI_FORNITORI = 'dati-generali/zone-clienti-fornitori';
    case PARAMETRI_AZIENDALI = 'dati-generali/parametri-aziendali';
    case TAGLIE_ARTICOLI = 'dati-generali/taglie-articoli';
    case SCONTI_QUANTITA_ARTICOLI = 'dati-generali/sconti-quantita-articoli';

    // Altre anagrafiche principali
    case BANCHE = 'banche';
    case INDIRIZZI_SPEDIZIONE = 'indirizzi-spedizione';
    case REFERENTI_CLIENTI = 'referenti/clienti';
    case REFERENTI_FORNITORI = 'referenti/fornitori';
    case CLIENTI = 'clienti';
    case FORNITORI = 'fornitori';
    case CONTI = 'conti';
    case ARTICOLI = 'articoli';
    case ARTICOLI_ABBINATI = 'articoli-abbinati';
    case PROGRESSIVI_ARTICOLI = 'progressivi-articoli';
    case DBA = 'dba';

    // Documenti (solo alcuni principali)
    case DOCUMENTI_ORDINI_CLIENTI = 'documenti/ordini-clienti';
    case DOCUMENTI_ORDINI_FORNITORI = 'documenti/ordini-fornitori';
    case DOCUMENTI_ORDINI_MATRICI = 'documenti/ordini-matrici';
    case DOCUMENTI_PREVENTIVI = 'documenti/preventivi';
    case DOCUMENTI_MOVIMENTI_MAGAZZINO = 'documenti/movimenti-magazzino';
    case DOCUMENTI_LAVORAZIONE_PRODOTTI = 'documenti/lavorazione/prodotti';
    case DOCUMENTI_LAVORAZIONE_BOLLE = 'documenti/lavorazione/bolle';
    case DOCUMENTI_LAVORAZIONE_IMPEGNI = 'documenti/lavorazione/impegni';
    case DOCUMENTI_MODULI_STAMPA = 'documenti/moduli-stampa';

    // Righe dei documenti: le entità master-detail espongono testate e righe su end-point
    // distinti, ricerca compresa (es. documenti/ordini-clienti/righe/ricerca).
    case DOCUMENTI_ORDINI_CLIENTI_RIGHE = 'documenti/ordini-clienti/righe';
    case DOCUMENTI_ORDINI_FORNITORI_RIGHE = 'documenti/ordini-fornitori/righe';
    case DOCUMENTI_ORDINI_MATRICI_RIGHE = 'documenti/ordini-matrici/righe';
    case DOCUMENTI_PREVENTIVI_RIGHE = 'documenti/preventivi/righe';
    case DOCUMENTI_MOVIMENTI_MAGAZZINO_RIGHE = 'documenti/movimenti-magazzino/righe';

    /** @deprecated End-point legacy (v81800): usare DOCUMENTI_LAVORAZIONE_IMPEGNI. */
    case IMPEGNI = 'impegni';
    case PRIMA_NOTA = 'prima-nota';
    case PRIMA_NOTA_RIGHE = 'prima-nota/righe';
    case MYDB = 'mydb';

    // Altre (campione, estendibile)
    case DISTINTE_BASE_FASI = 'distinte-base/fasi';
    case DISTINTE_BASE_COMPONENTI = 'distinte-base/componenti';
    case LOTTI = 'lotti';
    case UBICAZIONI = 'ubicazioni';
    case SCADENZARIO = 'scadenzario';
    case LISTE_PRELIEVO = 'liste-prelievo';
    case LISTE_PRELIEVO_RIGHE = 'liste-prelievo/righe';
    case ALIAS_ARTICOLI = 'alias-articoli';
    case MAPPA_ARTICOLI = 'mappa-articoli';
    case AZIENDE = 'aziende';
    case ANAGRAFICA_UNICA = 'anagrafica-unica';
    case ANAGRAFICA_UNICA_STORICO = 'anagrafica-unica/storico';
    case ANAGRAFICA_CONTATTI = 'anagrafica-contatti';

    /**
     * Trova la risorsa dal suo path, ignorando maiuscole e minuscole (es. 'clienti', 'CLIENTI').
     */
    public static function fromValueInsensitive(string $value): ?self
    {
        $value = strtolower($value);
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Ritorna array semplice dei valori stringa.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Suggerimento grezzo: match substring case-insensitive, utile in CLI e tooling.
     *
     * @return list<self>
     */
    public static function suggest(string $partial, int $limit = 15): array
    {
        $partial = strtolower($partial);
        $out = [];
        foreach (self::cases() as $case) {
            if (str_contains(strtolower($case->value), $partial)) {
                $out[] = $case;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }
}
