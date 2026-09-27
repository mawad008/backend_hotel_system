<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Resolves a locale-keyed content map (`{"en": "...", "ar": "..."}`) to a
 * single string for the current (or a given) request locale.
 *
 * Resolution order: requested locale → app fallback locale → the explicit
 * `$fallback` (a legacy plain-string column) → the first non-empty value in
 * the map → null. Never duplicates one language into another — a missing
 * translation falls through, it is not fabricated.
 */
final class LocalizedContent
{
    /**
     * @param  array<string, string|null>|null  $map
     */
    public static function resolve(?array $map, ?string $fallback = null, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');

        if (is_array($map)) {
            foreach ([$locale, $fallbackLocale] as $candidate) {
                $value = $map[$candidate] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        if (is_array($map)) {
            $first = Arr::first($map, static fn ($v) => is_string($v) && $v !== '');
            if (is_string($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * The primary/fallback-locale value of a map, used to keep a legacy
     * plain-string column (e.g. `hotels.name`) in sync with its i18n map.
     *
     * @param  array<string, string|null>|null  $map
     */
    public static function primary(?array $map): ?string
    {
        if (! is_array($map)) {
            return null;
        }

        $fallbackLocale = config('app.fallback_locale', 'en');
        $value = $map[$fallbackLocale] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $first = Arr::first($map, static fn ($v) => is_string($v) && $v !== '');

        return is_string($first) ? $first : null;
    }
}
