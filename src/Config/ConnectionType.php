<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Config;

/**
 * Le due topologie di installazione previste dal manuale WebAPI.
 *
 * Cambiano l'autenticazione, non il protocollo: "live" aggiunge il dominio Passepartout
 * all'header Authorization, "local" aggiunge le credenziali di sistema operativo quando
 * il server è configurato con login=1.
 */
enum ConnectionType: string
{
    /** Installazione on-premise, tipicamente su porta 9004 con certificato self-signed. */
    case LOCAL = 'local';

    /** Passepartout Cloud (services.passepartout.cloud), autenticazione con dominio. */
    case LIVE = 'live';
}
