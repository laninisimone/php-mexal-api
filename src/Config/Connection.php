<?php

declare(strict_types=1);

namespace Simonelanini\PhpMexalApi\Config;

use Simonelanini\PhpMexalApi\Exceptions\ConfigurationException;

/**
 * Server, credenziali e postura TLS di una installazione Mexal.
 *
 * Immutabile e validata alla costruzione: un URL sbagliato o un tipo non supportato falliscono
 * al bootstrap dell'applicazione, non alla prima chiamata di rete in produzione.
 *
 * SICUREZZA — tre scelte deliberate, diverse dalla versione 0.x Laravel:
 *
 *  1. `verify` vale `true` di default anche per le connessioni locali. Le installazioni
 *     on-premise espongono spesso un certificato self-signed, ma disattivare la verifica
 *     deve essere una decisione esplicita e visibile in configurazione, non un default
 *     silenzioso: senza verifica un man-in-the-middle legge le credenziali Basic e i dati
 *     dell'anagrafica. Chi ha davvero un self-signed imposta `verify: false` (o meglio, il
 *     path del CA bundle) e sa di averlo fatto.
 *  2. Il protocollo `http://` è rifiutato salvo `allowPlainHttp: true` esplicito.
 *     L'autenticazione è HTTP Basic: in chiaro, le credenziali viaggiano leggibili.
 *  3. Le credenziali sono private e non compaiono in `toArray()`, in `__debugInfo()` né
 *     in alcun messaggio di eccezione: un var_dump o uno stack trace finiti in un log
 *     centralizzato non devono regalare l'accesso al gestionale.
 */
final class Connection
{
    /** Prefisso di tutti gli endpoint WebAPI, come da manuale. */
    public const BASE_PATH = '/webapi/';

    /** Endpoint del cloud Passepartout. */
    public const LIVE_URL = 'https://services.passepartout.cloud';

    /** Porta di default del servizio WebAPI su installazione locale. */
    public const LOCAL_PORT = 9004;

    private function __construct(
        public readonly string $name,
        public readonly ConnectionType $type,
        /** URI di base completo, comprensivo di /webapi/ e con lo slash finale. */
        public readonly string $baseUri,
        /** base64("utente:password") delle credenziali WebAPI. */
        private readonly string $mexalToken,
        /** base64("utente:password") di sistema operativo, stringa vuota se non usate. */
        private readonly string $soToken,
        /** Dominio Passepartout per le connessioni live, stringa vuota per le locali. */
        public readonly string $domain,
        /** true = verifica il certificato, false = non verificarlo, string = path del CA bundle. */
        public readonly bool|string $verify,
    ) {
    }

    /**
     * Installazione on-premise.
     *
     * @param string $url Es. https://192.168.1.10 — senza il path /webapi/.
     * @param int|null $port Porta del servizio; null usa quella già presente nell'URL.
     * @param string|null $soUser Credenziali di sistema operativo: servono solo se il
     *                            server WebAPI è configurato con login=1.
     * @param bool|string $verify true, false, oppure il path di un CA bundle.
     * @param bool $allowPlainHttp Consente http:// — solo per test in rete fidata.
     */
    public static function local(
        string $url,
        string $apiUser,
        string $apiPassword,
        ?int $port = self::LOCAL_PORT,
        ?string $soUser = null,
        ?string $soPassword = null,
        bool|string $verify = true,
        bool $allowPlainHttp = false,
        string $name = 'local',
    ): self {
        return new self(
            name: $name,
            type: ConnectionType::LOCAL,
            baseUri: self::buildBaseUri($name, $url, $port, $allowPlainHttp),
            mexalToken: self::token($name, $apiUser, $apiPassword, required: true),
            soToken: self::token($name, $soUser ?? '', $soPassword ?? '', required: false),
            domain: '',
            verify: self::normalizeVerify($name, $verify),
        );
    }

    /**
     * Passepartout Cloud. Il dominio è il discriminante rispetto alla connessione locale:
     * viaggia nell'header Authorization e identifica l'installazione dentro il cloud.
     */
    public static function live(
        string $domain,
        string $apiUser,
        string $apiPassword,
        string $url = self::LIVE_URL,
        bool|string $verify = true,
        string $name = 'live',
    ): self {
        return new self(
            name: $name,
            type: ConnectionType::LIVE,
            // Nessun allowPlainHttp: il cloud è raggiungibile solo in HTTPS.
            baseUri: self::buildBaseUri($name, $url, null, allowPlainHttp: false),
            mexalToken: self::token($name, $apiUser, $apiPassword, required: true),
            soToken: '',
            domain: self::assertDomain($name, $domain),
            verify: self::normalizeVerify($name, $verify),
        );
    }

    /**
     * Costruisce la connessione dalla stessa forma usata dal file di configurazione, così
     * che un wrapper di framework possa passare direttamente il proprio array di config.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $name = 'default'): self
    {
        $type = self::string($config, 'type');

        // Un type non riconosciuto lascerebbe la connessione senza URL di base: meglio un
        // errore chiaro qui che una proprietà non inizializzata alla prima chiamata.
        $connectionType = ConnectionType::tryFrom($type)
            ?? throw new ConfigurationException(
                "Mexal connection '$name': type '$type' non supportato (attesi: 'local' o 'live').",
            );

        $verify = $config['verify'] ?? true;
        $allowPlainHttp = self::bool($config, 'allow_plain_http', false);

        return match ($connectionType) {
            ConnectionType::LOCAL => self::local(
                url: self::string($config, 'url'),
                apiUser: self::string($config, 'api_user'),
                apiPassword: self::string($config, 'api_password'),
                port: self::port($config, $name),
                soUser: self::string($config, 'so_user'),
                soPassword: self::string($config, 'so_password'),
                verify: is_bool($verify) || is_string($verify) ? $verify : true,
                allowPlainHttp: $allowPlainHttp,
                name: $name,
            ),
            ConnectionType::LIVE => self::live(
                domain: self::string($config, 'domain'),
                apiUser: self::string($config, 'api_user'),
                apiPassword: self::string($config, 'api_password'),
                url: self::string($config, 'url') ?: self::LIVE_URL,
                verify: is_bool($verify) || is_string($verify) ? $verify : true,
                name: $name,
            ),
        };
    }

    /**
     * Valore dell'header Authorization.
     *
     * Formato del manuale: "Passepartout <token> [<tokenSO>] [Dominio=<dominio>]".
     * I token sono base64, quindi non possono contenere caratteri di controllo; il dominio
     * è già validato da assertDomain().
     */
    public function authorizationHeader(): string
    {
        $parts = ['Passepartout', $this->mexalToken];

        if ($this->soToken !== '') {
            $parts[] = $this->soToken;
        }

        if ($this->domain !== '') {
            $parts[] = 'Dominio='.$this->domain;
        }

        return implode(' ', $parts);
    }

    public function isLive(): bool
    {
        return $this->type === ConnectionType::LIVE;
    }

    /** True quando il certificato del server viene validato (con CA di sistema o bundle custom). */
    public function verifiesTls(): bool
    {
        return $this->verify !== false;
    }

    /** Path del CA bundle, quando la verifica usa un bundle custom invece delle CA di sistema. */
    public function caBundle(): ?string
    {
        return is_string($this->verify) ? $this->verify : null;
    }

    /**
     * Rappresentazione priva di segreti, sicura da loggare o serializzare.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'base_uri' => $this->baseUri,
            'domain' => $this->domain,
            'verify' => $this->verify,
            // Presenza sì, valore mai: serve a diagnosticare "ho dimenticato le credenziali SO?"
            // senza mettere nulla di riservato nel log.
            'api_credentials' => $this->mexalToken !== '' ? 'set' : 'missing',
            'so_credentials' => $this->soToken !== '' ? 'set' : 'not-used',
        ];
    }

    /** Intercetta var_dump()/dd() perché non stampino i token. */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** Chiave stabile per la cache dei connector: distingue le connessioni senza esporre segreti. */
    public function fingerprint(): string
    {
        return $this->name.'|'.$this->type->value.'|'.$this->baseUri;
    }

    /**
     * Compone scheme://host[:porta]/[path-prefisso]/webapi/.
     *
     * Ricostruito dai componenti invece che concatenato a mano: un URL con path (reverse
     * proxy), con porta già presente o con slash finale deve produrre lo stesso risultato.
     */
    private static function buildBaseUri(string $name, string $url, ?int $port, bool $allowPlainHttp): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new ConfigurationException("Mexal connection '$name': chiave 'url' mancante o vuota.");
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(
                "Mexal connection '$name': url non valido, atteso qualcosa come https://host[:porta].",
            );
        }

        // Credenziali nell'URL: finirebbero nei log di ogni proxy attraversato e non
        // vengono comunque usate per autenticarsi (il gestionale vuole l'header Authorization).
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException(
                "Mexal connection '$name': l'url non deve contenere credenziali; usa api_user / api_password.",
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException("Mexal connection '$name': schema '$scheme' non supportato.");
        }

        if ($scheme === 'http' && ! $allowPlainHttp) {
            throw new ConfigurationException(
                "Mexal connection '$name': l'autenticazione WebAPI è HTTP Basic, su http:// le credenziali "
                ."viaggerebbero in chiaro. Usa https://, oppure imposta 'allow_plain_http' => true se la "
                .'connessione resta confinata in una rete fidata.',
            );
        }

        // La porta esplicita ha la precedenza su quella eventualmente presente nell'URL.
        $resolvedPort = $port ?? (isset($parts['port']) ? (int) $parts['port'] : null);

        if ($resolvedPort !== null && ($resolvedPort < 1 || $resolvedPort > 65535)) {
            throw new ConfigurationException("Mexal connection '$name': porta non valida ($resolvedPort).");
        }

        $authority = $parts['host'].($resolvedPort !== null ? ':'.$resolvedPort : '');
        $prefix = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$authority.$prefix.self::BASE_PATH;
    }

    private static function token(string $name, string $user, string $password, bool $required): string
    {
        if ($user === '' && $password === '') {
            if ($required) {
                throw new ConfigurationException(
                    "Mexal connection '$name': api_user e api_password sono obbligatorie.",
                );
            }

            return '';
        }

        // I due punti separano utente e password nello schema Basic: un utente che ne
        // contiene uno renderebbe la password ambigua lato server.
        if (str_contains($user, ':')) {
            throw new ConfigurationException("Mexal connection '$name': il nome utente non può contenere ':'.");
        }

        return base64_encode($user.':'.$password);
    }

    private static function assertDomain(string $name, string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            throw new ConfigurationException("Mexal connection '$name': 'domain' è obbligatorio sulle connessioni live.");
        }

        // Il dominio viene concatenato nell'header Authorization senza codifica: uno spazio
        // spezzerebbe il token, un CR/LF permetterebbe di iniettare header arbitrari.
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $domain) !== 1) {
            throw new ConfigurationException(
                "Mexal connection '$name': 'domain' ammette solo lettere, cifre, punto, trattino e underscore.",
            );
        }

        return $domain;
    }

    /**
     * Le variabili d'ambiente arrivano come stringhe: "false" deve valere false, non true
     * (ogni stringa non vuota è truthy in PHP, e un cast diretto attiverebbe la verifica
     * quando l'utente l'ha esplicitamente disattivata — o viceversa).
     */
    private static function normalizeVerify(string $name, bool|string $verify): bool|string
    {
        if (is_bool($verify)) {
            return $verify;
        }

        $normalized = strtolower(trim($verify));

        if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }

        // Qualsiasi altra stringa è il path di un CA bundle: se non esiste, la verifica
        // fallirebbe a runtime con un errore cURL oscuro. Meglio dirlo subito.
        if (! is_file($verify) || ! is_readable($verify)) {
            throw new ConfigurationException(
                "Mexal connection '$name': CA bundle '$verify' inesistente o non leggibile.",
            );
        }

        return $verify;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function port(array $config, string $name): ?int
    {
        // Tre casi distinti, e servono davvero tutti e tre:
        //  - chiave assente          -> porta di default del servizio WebAPI (9004);
        //  - chiave presente a null  -> nessuna porta, si usa quella dell'url o quella di
        //                               default dello schema (il caso del reverse proxy);
        //  - chiave valorizzata      -> quella porta.
        if (! array_key_exists('port', $config)) {
            return self::LOCAL_PORT;
        }

        $value = $config['port'];

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ConfigurationException("Mexal connection '$name': 'port' deve essere un intero.");
    }
}
