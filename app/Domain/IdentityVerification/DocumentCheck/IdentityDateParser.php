<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use DateTimeImmutable;
use IntlCalendar;

/**
 * Parses a date as read off an identity document into a Gregorian date.
 *
 * Accepts Arabic-Indic / Extended Arabic-Indic / Western digits and the
 * layouts found on the supported documents: `YYYY-MM-DD`, `YYYY/MM/DD`,
 * `DD/MM/YYYY`, `DD-MM-YYYY` (also with `.`), optionally followed by a
 * Hijri marker (`هـ`, `ه`, `AH`) or a Gregorian one (`م`).
 *
 * Hijri: a year between 1300 and 1500 (or an explicit Hijri marker) is read
 * as the Umm al-Qura calendar — the official Saudi calendar — and converted
 * with ICU. Ambiguous or impossible input returns null; nothing is guessed.
 */
final class IdentityDateParser
{
    private function __construct() {}

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $raw = trim(IdentityTextNormalizer::digits($value));

        if ($raw === '') {
            return null;
        }

        $hijriMarked = preg_match('/(هـ|ه\s*$|\bAH\b)/u', $raw) === 1;
        $gregorianMarked = preg_match('/(م\s*$|\bAD\b)/u', $raw) === 1;
        $clean = trim(preg_replace('/[^\d\/\-.]+/', ' ', $raw) ?? '');

        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $clean, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $clean, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        $isHijri = ! $gregorianMarked && ($hijriMarked || ($y >= 1300 && $y <= 1500));

        return $isHijri ? self::fromHijri($y, $mo, $d) : self::gregorian($y, $mo, $d);
    }

    private static function gregorian(int $y, int $m, int $d): ?DateTimeImmutable
    {
        return checkdate($m, $d, $y) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d)) : null;
    }

    /** Umm al-Qura (islamic-umalqura) → Gregorian, via ICU. */
    public static function fromHijri(int $y, int $m, int $d): ?DateTimeImmutable
    {
        if ($y < 1300 || $y > 1500 || $m < 1 || $m > 12 || $d < 1 || $d > 30) {
            return null;
        }

        $calendar = IntlCalendar::createInstance('UTC', 'en_US@calendar=islamic-umalqura');

        if ($calendar === null) {
            return null;
        }

        $calendar->clear();
        $calendar->setLenient(false);
        $calendar->set($y, $m - 1, $d);
        $millis = $calendar->getTime();

        if ($millis === false || intl_is_failure($calendar->getErrorCode())) {
            return null;
        }

        return (new DateTimeImmutable('@'.intdiv((int) $millis, 1000)))->setTime(0, 0);
    }
}
