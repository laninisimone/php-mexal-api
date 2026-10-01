<?php

declare(strict_types=1);

namespace MexalApiDocs;

use stdClass;

/**
 * Emitter YAML minimale: copre solo ciò che serve a un documento OpenAPI (mappe, liste,
 * scalari, testo su più righe). Evita di aggiungere symfony/yaml alle dipendenze del
 * pacchetto per uno strumento di sviluppo.
 *
 * Le mappe vuote vanno passate come stdClass: un array PHP vuoto diventa una lista ([]).
 */
final class Yaml
{
    private const RISERVATE = ['y', 'n', 'yes', 'no', 'true', 'false', 'on', 'off', 'null', '~'];

    public static function dump(mixed $dati): string
    {
        return implode("\n", self::righe($dati, 0))."\n";
    }

    /**
     * @return list<string>
     */
    private static function righe(mixed $valore, int $rientro): array
    {
        $pad = str_repeat(' ', $rientro);

        if ($valore instanceof stdClass) {
            $valore = (array) $valore;

            if ($valore === []) {
                return [$pad.'{}'];
            }
        }

        if (! is_array($valore)) {
            return [$pad.self::scalare($valore)];
        }

        if ($valore === []) {
            return [$pad.'[]'];
        }

        $out = [];

        if (array_is_list($valore)) {
            foreach ($valore as $elemento) {
                $figlie = self::righe($elemento, $rientro + 2);
                $out[] = $pad.'- '.substr($figlie[0], $rientro + 2);
                array_push($out, ...array_slice($figlie, 1));
            }

            return $out;
        }

        foreach ($valore as $chiave => $elemento) {
            $k = $pad.self::chiave((string) $chiave).':';

            if (is_string($elemento) && str_contains($elemento, "\n") && self::bloccoAmmesso($elemento)) {
                $out[] = $k.' |-';
                foreach (explode("\n", $elemento) as $riga) {
                    $out[] = $riga === '' ? '' : $pad.'  '.$riga;
                }

                continue;
            }

            $vuoto = $elemento === [] || ($elemento instanceof stdClass && (array) $elemento === []);

            if ($vuoto || ! (is_array($elemento) || $elemento instanceof stdClass)) {
                $out[] = $k.' '.trim(self::righe($elemento, 0)[0]);

                continue;
            }

            $out[] = $k;
            array_push($out, ...self::righe($elemento, $rientro + 2));
        }

        return $out;
    }

    private static function chiave(string $chiave): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$.\-]*$/', $chiave) === 1
            && ! in_array(strtolower($chiave), self::RISERVATE, true)
            ? $chiave
            : self::virgolette($chiave);
    }

    private static function scalare(mixed $valore): string
    {
        return match (true) {
            $valore === null => 'null',
            is_bool($valore) => $valore ? 'true' : 'false',
            is_int($valore) => (string) $valore,
            is_float($valore) => is_finite($valore) && floor($valore) === $valore
                ? number_format($valore, 1, '.', '')
                : (string) $valore,
            default => self::stringa((string) $valore),
        };
    }

    private static function stringa(string $s): string
    {
        $semplice = preg_match("/^\\p{L}[\\p{L}0-9 _.,()\\/'’+\\-]*$/u", $s) === 1
            && ! str_ends_with($s, ' ')
            && ! in_array(strtolower($s), self::RISERVATE, true);

        return $semplice ? $s : self::virgolette($s);
    }

    /** Una stringa JSON è anche uno scalare YAML valido tra doppi apici. */
    private static function virgolette(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Il blocco letterale non regge righe che iniziano con spazi o tab, né spazi finali. */
    private static function bloccoAmmesso(string $s): bool
    {
        foreach (explode("\n", $s) as $riga) {
            if ($riga !== rtrim($riga) || str_starts_with($riga, "\t")) {
                return false;
            }
        }

        return ! str_starts_with($s, ' ');
    }
}
