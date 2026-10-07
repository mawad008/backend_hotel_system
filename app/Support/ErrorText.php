<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Localized text for domain exceptions whose message is returned to API
 * clients (bootstrap/app.php renders them with getMessage()).
 *
 * Messages live in lang/{locale}/errors.php. The English strings are the
 * exact historical messages, so English responses are unchanged. Raw
 * machine codes interpolated into a message (statuses, reasons, actions)
 * are translated only where the active locale defines a label for them
 * (lang/ar/errors.php `status` / `reason` / `action`); otherwise the code is
 * kept verbatim — which is what English does.
 *
 * Domain exceptions are also constructed in plain unit tests with no
 * application container, so every lookup falls back to the English file.
 */
final class ErrorText
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function message(string $key, array $replace = []): string
    {
        try {
            return (string) __("errors.{$key}", $replace);
        } catch (Throwable) {
            $line = (require dirname(__DIR__, 2).'/lang/en/errors.php')[$key] ?? $key;

            foreach ($replace as $name => $value) {
                $line = str_replace(':'.$name, (string) $value, $line);
            }

            return $line;
        }
    }

    public static function status(string $code): string
    {
        return self::label('status', $code);
    }

    public static function action(string $code): string
    {
        return self::label('action', $code);
    }

    /**
     * A reason code, optionally `code:detail` where the detail is a status
     * (e.g. `reservation_not_completed:checked_in`).
     */
    public static function reason(string $reason): string
    {
        [$code, $detail] = array_pad(explode(':', $reason, 2), 2, null);

        try {
            $key = "errors.reason.{$code}";
            if (! Lang::has($key, null, false)) {
                return $reason;
            }

            return (string) __($key, ['detail' => $detail !== null ? self::status($detail) : '']);
        } catch (Throwable) {
            return $reason;
        }
    }

    private static function label(string $group, string $code): string
    {
        try {
            $key = "errors.{$group}.{$code}";

            return Lang::has($key, null, false) ? (string) __($key) : $code;
        } catch (Throwable) {
            return $code;
        }
    }
}
