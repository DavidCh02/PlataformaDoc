<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hora oficial de la plataforma (zona America/Guayaquil).
 *
 * El reloj del equipo donde corre el servidor puede estar adelantado,
 * atrasado o en otra zona horaria (p. ej. alguien cambia la fecha de su
 * PC, que en XAMPP *es* el servidor). Para que los recordatorios no
 * dependan de eso, la hora se calibra contra relojes públicos de internet
 * y se guarda el desfase (offset) en caché:
 *
 *   hora oficial = reloj local + offset calibrado
 *
 * - La calibración se refresca cada 30 min; entre tanto no hay HTTP.
 * - Sin internet se usa el reloj local y source() devuelve 'local'
 *   (el lab lo reporta como advertencia).
 */
class OfficialTime
{
    public const CACHE_OFFSET = 'official_time:offset_seconds';

    public const CACHE_FETCHED = 'official_time:fetched_at';

    /** Cada cuánto se recalibra contra internet. */
    public const CALIBRATION_TTL_MINUTES = 30;

    /** Si la última calibración es más vieja que esto, se considera perdida. */
    public const CALIBRATION_MAX_AGE_HOURS = 24;

    protected static ?int $memoryOffset = null;

    protected static ?string $memorySource = null;

    /** Hora oficial actual (America/Guayaquil). Úsala en vez de now(). */
    public static function now(): Carbon
    {
        $tz = (string) config('app.timezone', 'America/Guayaquil');

        if (static::source() === 'local') {
            return Carbon::now($tz);
        }

        return Carbon::createFromTimestamp(time() + static::offsetSeconds(), $tz);
    }

    /** 'internet' si hay calibración vigente, 'local' si no. */
    public static function source(): string
    {
        if (static::$memorySource !== null) {
            return static::$memorySource;
        }

        $offset = Cache::get(static::CACHE_OFFSET);
        $fetchedAt = Cache::get(static::CACHE_FETCHED);

        if ($offset === null || $fetchedAt === null) {
            return static::refresh() ? 'internet' : 'local';
        }

        try {
            $ageMinutes = Carbon::parse($fetchedAt)->diffInMinutes(Carbon::now());
        } catch (Throwable) {
            return static::refresh() ? 'internet' : 'local';
        }

        if ($ageMinutes > static::CALIBRATION_MAX_AGE_HOURS * 60) {
            return static::refresh() ? 'internet' : 'local';
        }

        if ($ageMinutes >= static::CALIBRATION_TTL_MINUTES) {
            // Recalibración oportunista: si falla, se sigue usando el
            // offset anterior (mejor que volver al reloj local de golpe).
            static::refresh();
        }

        static::$memoryOffset = (int) $offset;
        static::$memorySource = 'internet';

        return 'internet';
    }

    /** Desfase oficial − local en segundos (null si no hay calibración). */
    public static function skewSeconds(): ?int
    {
        return static::source() === 'internet' ? static::offsetSeconds() : null;
    }

    public static function offsetSeconds(): int
    {
        if (static::$memoryOffset !== null) {
            return static::$memoryOffset;
        }

        static::source();

        return static::$memoryOffset ?? 0;
    }

    /**
     * Calibra contra relojes públicos. Devuelve true si algún
     * proveedor respondió con una hora cuerda.
     */
    public static function refresh(): bool
    {
        foreach (static::providers() as $name => $provider) {
            try {
                $remoteUnix = $provider();
                if ($remoteUnix === null) {
                    continue;
                }
                $offset = $remoteUnix - time();
                // Cordura: ignora desfases absurdos (>5 años).
                if (abs($offset) > 5 * 365 * 86400) {
                    continue;
                }
                static::$memoryOffset = $offset;
                static::$memorySource = 'internet';
                Cache::put(static::CACHE_OFFSET, $offset, now()->addDays(2));
                Cache::put(static::CACHE_FETCHED, now()->toDateTimeString(), now()->addDays(2));

                return true;
            } catch (Throwable $exception) {
                Log::info('OfficialTime provider failed.', ['provider' => $name, 'error' => $exception->getMessage()]);
            }
        }

        // Sin internet: si había calibración previa vigente se conserva
        // (source() la reutiliza); si no, reloj local.
        if (static::$memoryOffset === null) {
            $cached = Cache::get(static::CACHE_OFFSET);
            if ($cached !== null) {
                static::$memoryOffset = (int) $cached;
                static::$memorySource = 'internet';

                return true;
            }
            static::$memorySource = 'local';
        }

        return static::$memorySource === 'internet';
    }

    /** Solo para tests: olvida el estado en memoria. */
    public static function flushState(): void
    {
        static::$memoryOffset = null;
        static::$memorySource = null;
    }

    /** @return array<string, callable(): ?int> unixtime remoto o null */
    protected static function providers(): array
    {
        return [
            'worldtimeapi' => function (): ?int {
                $response = Http::timeout(4)->get('https://worldtimeapi.org/api/timezone/America/Guayaquil');
                if (! $response->successful()) {
                    return null;
                }
                $unix = (int) $response->json('unixtime', 0);

                return $unix > 0 ? $unix : null;
            },
            'timeapi' => function (): ?int {
                $response = Http::timeout(4)->get('https://timeapi.io/api/time/current/zone', [
                    'timeZone' => 'America/Guayaquil',
                ]);
                if (! $response->successful()) {
                    return null;
                }
                $iso = (string) $response->json('dateTime', '');
                if ($iso === '') {
                    return null;
                }
                $ts = strtotime($iso);

                return $ts > 0 ? $ts : null;
            },
            'google' => function (): ?int {
                // Cabecera Date (GMT) de un 204: no necesita JSON.
                $response = Http::timeout(4)->get('https://www.google.com/generate_204');
                $date = (string) $response->header('Date', '');
                if ($date === '') {
                    return null;
                }
                $ts = strtotime($date);

                return $ts > 0 ? $ts : null;
            },
        ];
    }
}
