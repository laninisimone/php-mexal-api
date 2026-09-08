<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configurazione di esempio per php-mexal-api
|--------------------------------------------------------------------------
|
| Copia questo file nella cartella di configurazione della tua applicazione e
| passalo a Mexal::fromArray(). La forma dell'array è la stessa attesa da
| MexalConfig::fromArray(), quindi qualunque framework può fornirla nel modo
| che gli è proprio (config/ in Laravel, parameters in Symfony, un semplice
| require in un progetto senza framework).
|
| In alternativa: Mexal::fromEnv() legge direttamente le variabili d'ambiente
| elencate nei commenti qui sotto, senza bisogno di questo file.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Connessione e istanza di default
    |--------------------------------------------------------------------------
    |
    | Usate da tutte le chiamate che non ne indicano una esplicitamente.
    */

    'default' => [
        'connection' => getenv('MEXAL_CONN') ?: 'local',
        'instance' => getenv('MEXAL_INST') ?: 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connessioni: il server e le credenziali
    |--------------------------------------------------------------------------
    |
    | Puoi dichiararne quante ne vuoi (produzione, collaudo, un cliente per
    | connessione) e sceglierle a runtime con il parametro $connection.
    */

    'connections' => [

        'local' => [
            'type' => 'local',
            'url' => getenv('MEXAL_URL') ?: 'https://127.0.0.1',
            'port' => getenv('MEXAL_PORT') ?: 9004,
            'api_user' => getenv('MEXAL_API_USER') ?: null,
            'api_password' => getenv('MEXAL_API_PASSWORD') ?: null,

            // Solo se il server WebAPI è configurato con login=1.
            'so_user' => getenv('MEXAL_SO_USER') ?: null,
            'so_password' => getenv('MEXAL_SO_PASSWORD') ?: null,

            // SICUREZZA: la verifica del certificato è attiva per default, anche in locale.
            // Le installazioni on-premise espongono spesso un certificato self-signed: in quel
            // caso indica il path del CA bundle (mantieni cifratura E autenticazione del server)
            // e usa false solo come ultima risorsa, sapendo che espone a man-in-the-middle.
            'verify' => getenv('MEXAL_VERIFY_SSL') ?: true,

            // Consente http:// — le credenziali Basic viaggerebbero in chiaro.
            // Da attivare solo in una rete fidata e per test.
            'allow_plain_http' => false,
        ],

        'live' => [
            'type' => 'live',
            'url' => 'https://services.passepartout.cloud',
            'domain' => getenv('MEXAL_LIVE_DOMAIN') ?: null,
            'api_user' => getenv('MEXAL_API_USER') ?: null,
            'api_password' => getenv('MEXAL_API_PASSWORD') ?: null,

            // Il cloud Passepartout ha un certificato valido: non disattivare la verifica.
            'verify' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Istanze: le coordinate gestionali
    |--------------------------------------------------------------------------
    |
    | Finiscono nell'header Coordinate-Gestionale. Dichiara un'istanza per ogni
    | combinazione azienda / anno / magazzino su cui lavori abitualmente; per le
    | combinazioni occasionali c'è $mexal->on(new Instance(...)).
    |
    | 'sotto_azienda' è opzionale: se vuota non viene inviata.
    */

    'instances' => [

        'default' => [
            'azienda' => getenv('MEXAL_AZIENDA') ?: 'IMP',
            'sotto_azienda' => getenv('MEXAL_SUB_AZIENDA') ?: null,
            'anno' => getenv('MEXAL_ANNO') ?: null,     // null = anno corrente
            'magazzino' => getenv('MEXAL_MAGAZZINO') ?: 1,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Trasporto HTTP
    |--------------------------------------------------------------------------
    |
    | Il servizio WebAPI elabora una GET fino a 30 secondi prima di interrompersi
    | e restituire un "next" di paginazione: un timeout di lettura sotto quella
    | soglia taglierebbe letture legittime. Il timeout di connessione resta breve
    | perché un server spento non deve tenere appesa l'applicazione.
    |
    | 'retries' conta i tentativi AGGIUNTIVI rispetto al primo e si applica solo
    | alle richieste idempotenti e ai soli errori transitori (rete, 5xx, 408, 429).
    | Una POST che crea entità non viene mai ripetuta.
    */

    'http' => [
        'timeout' => getenv('MEXAL_HTTP_TIMEOUT') ?: 60,
        'connect_timeout' => getenv('MEXAL_HTTP_CONNECT_TIMEOUT') ?: 10,
        'retries' => getenv('MEXAL_HTTP_RETRIES') ?: 0,
        'retry_delay' => getenv('MEXAL_HTTP_RETRY_DELAY') ?: 250,
        'max_pages' => getenv('MEXAL_HTTP_MAX_PAGES') ?: 1000,
    ],

];
