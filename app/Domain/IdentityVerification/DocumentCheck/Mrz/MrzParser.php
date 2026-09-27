<?php

namespace App\Domain\IdentityVerification\DocumentCheck\Mrz;

use DateTimeImmutable;

/**
 * ICAO Doc 9303 machine-readable-zone parser + validator.
 *
 * Finds an MRZ inside OCR text (the provider's MRZ field, or the whole page
 * text as a fallback), recognises the three layouts —
 *   TD3 (passport book, 2 × 44), TD2 (2 × 36), TD1 (ID card, 3 × 30) —
 * and verifies every check digit (7-3-1 weighting, `<` = 0, A–Z = 10–35),
 * including the composite digit and the TD1 long-document-number extension.
 *
 * OCR correction is deliberately narrow: only positions that ICAO defines as
 * NUMERIC (dates and check digits) get O→0 / I→1 / … folding; alphanumeric
 * fields (document number, names) are taken verbatim, so a correction can
 * never manufacture a passing check digit for a misread document number.
 */
final class MrzParser
{
    public const TD1 = 'TD1';

    public const TD2 = 'TD2';

    public const TD3 = 'TD3';

    private const LAYOUTS = [
        self::TD3 => ['lines' => 2, 'length' => 44],
        self::TD2 => ['lines' => 2, 'length' => 36],
        self::TD1 => ['lines' => 3, 'length' => 30],
    ];

    private const NUMERIC_FIX = ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8'];

    private function __construct() {}

    public static function parse(?string $text): ?ParsedMrz
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $lines = self::candidateLines($text);

        foreach (self::LAYOUTS as $format => $layout) {
            $count = count($lines);

            for ($i = 0; $i + $layout['lines'] <= $count; $i++) {
                $window = [];

                foreach (array_slice($lines, $i, $layout['lines']) as $line) {
                    $fitted = self::fit($line, $layout['length']);

                    if ($fitted === null) {
                        continue 2;
                    }

                    $window[] = $fitted;
                }

                if (! self::plausibleFirstLine($window[0], $format)) {
                    continue;
                }

                return match ($format) {
                    self::TD3 => self::parseTd3($window),
                    self::TD2 => self::parseTd2($window),
                    self::TD1 => self::parseTd1($window),
                };
            }
        }

        return null;
    }

    public static function checkDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($value) as $i => $char) {
            $v = match (true) {
                $char === '<' => 0,
                ctype_digit($char) => (int) $char,
                ctype_upper($char) => ord($char) - 55,
                default => 0,
            };
            $sum += $v * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** @return list<string> */
    private static function candidateLines(string $text): array
    {
        $text = strtoupper(str_replace(['«', '‹', '＜', 'く'], '<', $text));
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = preg_replace('/\s+/', '', $raw) ?? '';

            if (strlen($line) >= 28 && preg_match('/^[A-Z0-9<]+$/', $line) === 1 && str_contains($line, '<')) {
                $lines[] = $line;
            }
        }

        // Some providers return a TD3 MRZ as one 88-char line.
        if ($lines === [] || count($lines) === 1) {
            $compact = preg_replace('/[^A-Z0-9<]/', '', $text) ?? '';

            if (strlen($compact) === 88 || strlen($compact) === 90 || strlen($compact) === 72) {
                $split = strlen($compact) === 90 ? 30 : strlen($compact) / 2;

                return str_split($compact, (int) $split);
            }
        }

        return $lines;
    }

    /** OCR often drops / adds trailing fillers: repair only the filler tail. */
    private static function fit(string $line, int $length): ?string
    {
        $diff = strlen($line) - $length;

        if ($diff === 0) {
            return $line;
        }

        if ($diff > 0 && $diff <= 2 && str_ends_with($line, str_repeat('<', $diff))) {
            return substr($line, 0, $length);
        }

        if ($diff < 0 && $diff >= -2 && str_ends_with($line, '<')) {
            return str_pad($line, $length, '<');
        }

        return null;
    }

    private static function plausibleFirstLine(string $line, string $format): bool
    {
        $code = $line[0];

        return match ($format) {
            self::TD3 => $code === 'P',
            default => in_array($code, ['I', 'A', 'C', 'P', 'V'], true),
        };
    }

    /** @param list<string> $l */
    private static function parseTd3(array $l): ParsedMrz
    {
        [$l1, $l2] = $l;
        $failed = [];

        $docNumber = substr($l2, 0, 9);
        self::verify($docNumber, $l2[9], 'document_number', $failed);
        $dob = self::numeric(substr($l2, 13, 6));
        self::verify($dob, $l2[19], 'birth', $failed);
        $expiry = self::numeric(substr($l2, 21, 6));
        self::verify($expiry, $l2[27], 'expiry', $failed);

        $optional = substr($l2, 28, 14);
        $optionalCheck = $l2[42];

        if (! ($optional === str_repeat('<', 14) && in_array($optionalCheck, ['<', '0'], true))) {
            self::verify($optional, $optionalCheck, 'optional', $failed);
        }

        // ICAO positions 1–10, 14–20, 22–43 (sex and nationality excluded).
        $composite = substr($l2, 0, 10).$dob.self::numeric($l2[19]).$expiry.self::numeric($l2[27]).substr($l2, 28, 15);
        self::verify($composite, $l2[43], 'composite', $failed);

        [$surname, $given, $truncated] = self::names(substr($l1, 5, 39));

        return new ParsedMrz(
            format: self::TD3,
            documentCode: rtrim(substr($l1, 0, 2), '<'),
            issuingState: self::state(substr($l1, 2, 3)),
            surname: $surname,
            givenNames: $given,
            namesTruncated: $truncated,
            documentNumber: rtrim($docNumber, '<'),
            nationality: self::state(substr($l2, 10, 3)),
            dateOfBirth: self::date($dob, isExpiry: false),
            sex: $l2[20],
            dateOfExpiry: self::date($expiry, isExpiry: true),
            failedChecks: $failed,
        );
    }

    /** @param list<string> $l */
    private static function parseTd2(array $l): ParsedMrz
    {
        [$l1, $l2] = $l;
        $failed = [];

        $docNumber = substr($l2, 0, 9);
        self::verify($docNumber, $l2[9], 'document_number', $failed);
        $dob = self::numeric(substr($l2, 13, 6));
        self::verify($dob, $l2[19], 'birth', $failed);
        $expiry = self::numeric(substr($l2, 21, 6));
        self::verify($expiry, $l2[27], 'expiry', $failed);

        $composite = substr($l2, 0, 10).$dob.self::numeric($l2[19]).$expiry.self::numeric($l2[27]).substr($l2, 28, 7);
        self::verify($composite, $l2[35], 'composite', $failed);

        [$surname, $given, $truncated] = self::names(substr($l1, 5, 31));

        return new ParsedMrz(
            format: self::TD2,
            documentCode: rtrim(substr($l1, 0, 2), '<'),
            issuingState: self::state(substr($l1, 2, 3)),
            surname: $surname,
            givenNames: $given,
            namesTruncated: $truncated,
            documentNumber: rtrim($docNumber, '<'),
            nationality: self::state(substr($l2, 10, 3)),
            dateOfBirth: self::date($dob, isExpiry: false),
            sex: $l2[20],
            dateOfExpiry: self::date($expiry, isExpiry: true),
            failedChecks: $failed,
        );
    }

    /** @param list<string> $l */
    private static function parseTd1(array $l): ParsedMrz
    {
        [$l1, $l2, $l3] = $l;
        $failed = [];

        $docNumber = substr($l1, 5, 9);
        $docCheck = $l1[14];
        $optional1 = substr($l1, 15, 15);

        if ($docCheck === '<') {
            // Long document number: continues in the optional field; the
            // last character before the first filler is its check digit.
            $extension = rtrim($optional1, '<');
            $docCheck = substr($extension, -1);
            $docNumber .= substr($extension, 0, -1);
        }

        self::verify($docNumber, $docCheck, 'document_number', $failed);

        $dob = self::numeric(substr($l2, 0, 6));
        self::verify($dob, $l2[6], 'birth', $failed);
        $expiry = self::numeric(substr($l2, 8, 6));
        self::verify($expiry, $l2[14], 'expiry', $failed);

        $composite = substr($l1, 5, 25).$dob.self::numeric($l2[6]).$expiry.self::numeric($l2[14]).substr($l2, 18, 11);
        self::verify($composite, $l2[29], 'composite', $failed);

        [$surname, $given, $truncated] = self::names($l3);

        return new ParsedMrz(
            format: self::TD1,
            documentCode: rtrim(substr($l1, 0, 2), '<'),
            issuingState: self::state(substr($l1, 2, 3)),
            surname: $surname,
            givenNames: $given,
            namesTruncated: $truncated,
            documentNumber: str_replace('<', '', $docNumber),
            nationality: self::state(substr($l2, 15, 3)),
            dateOfBirth: self::date($dob, isExpiry: false),
            sex: $l2[7],
            dateOfExpiry: self::date($expiry, isExpiry: true),
            failedChecks: $failed,
        );
    }

    /** @param list<string> $failed */
    private static function verify(string $value, string $check, string $name, array &$failed): void
    {
        $check = self::numeric($check);

        if (! ctype_digit($check) || self::checkDigit($value) !== (int) $check) {
            $failed[] = $name;
        }
    }

    private static function numeric(string $value): string
    {
        return strtr($value, self::NUMERIC_FIX);
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private static function names(string $field): array
    {
        $truncated = ! str_ends_with($field, '<');
        $parts = explode('<<', rtrim($field, '<'), 2);

        $clean = fn (string $v): string => trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $v)) ?? '');

        return [$clean($parts[0]), $clean($parts[1] ?? ''), $truncated];
    }

    private static function state(string $code): ?string
    {
        $code = str_replace('<', '', $code);

        return $code === '' ? null : ($code === 'D' ? 'DEU' : $code);
    }

    private static function date(string $yymmdd, bool $isExpiry): ?DateTimeImmutable
    {
        if (preg_match('/^\d{6}$/', $yymmdd) !== 1) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);
        $currentYy = (int) date('y');

        // Birth dates are never in the future; expiry dates are at most a
        // few decades out (ICAO leaves the century implicit).
        $year = $isExpiry
            ? ($yy >= $currentYy + 50 ? 1900 + $yy : 2000 + $yy)
            : ($yy > $currentYy ? 1900 + $yy : 2000 + $yy);

        if (! checkdate($mm, $dd, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $mm, $dd));
    }
}
