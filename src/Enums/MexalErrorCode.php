<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Enums;

/**
 * Famiglie di errore applicativo del gestionale, così come compaiono in testa al campo
 * "response-detail" delle risposte di errore: "<codice> - <messaggio> [<dettaglio>]".
 *
 * Riferimento: manuale WebAPI 3.1, appendice "Codici di Errore".
 *
 * Uso:
 *     $e->errorFamily() === MexalErrorCode::ERRORE_GESTIONALE;
 */
enum MexalErrorCode: int
{
    /** L'header Coordinate-Gestionale manca su un endpoint che lavora su una azienda. */
    case COORDINATE_MANCANTI = 1001;

    /** Pool dei servizi: credenziali rifiutate, uso esclusivo in corso oppure pool esaurito. */
    case POOL_SERVIZI = 3002;

    /** Dialogo interrotto fra il modulo WebAPI e il processo Mexal/Passcom. */
    case INOLTRO_COMANDO = 5001;

    /** Il gestionale ha rifiutato l'operazione per un vincolo funzionale. */
    case ERRORE_GESTIONALE = 6001;

    /** Rimedio suggerito dal manuale per la famiglia di errore. */
    public function rimedio(): string
    {
        return match ($this) {
            self::COORDINATE_MANCANTI => "Aggiungere l'header Coordinate-Gestionale con almeno Azienda e Anno.",
            self::POOL_SERVIZI => 'Verificare le credenziali, oppure attendere che si liberi un servizio del pool '
                .'o che termini la funzione in uso esclusivo su un altro terminale.',
            self::INOLTRO_COMANDO => 'Leggere il dettaglio fra parentesi quadre: riporta il prerequisito mancante '
                .'lato gestionale.',
            self::ERRORE_GESTIONALE => 'Leggere il dettaglio fra parentesi quadre e correggere i dati inviati; '
                .'i nomi dei campi validi si verificano con ?info=true sull\'endpoint.',
        };
    }
}
