<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

/**
 * Reads typed values out of an Azure Document Intelligence `analyzeResult`
 * (prebuilt or custom). Pure helpers; nothing is logged.
 */
final class AzureFields
{
    private function __construct() {}

    /** @return array<string, mixed> fields of the first document, or [] */
    public static function of(?array $analyze): array
    {
        $document = $analyze['documents'][0] ?? null;

        return is_array($document) && is_array($document['fields'] ?? null) ? $document['fields'] : [];
    }

    public static function docType(?array $analyze): string
    {
        return (string) ($analyze['documents'][0]['docType'] ?? '');
    }

    /** First non-empty value among the given field names. */
    public static function first(array $fields, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = self::value($fields[$name] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public static function value(mixed $field): ?string
    {
        if (! is_array($field)) {
            return null;
        }

        $value = match ($field['type'] ?? null) {
            'date' => $field['valueDate'] ?? null,
            'countryRegion' => $field['valueCountryRegion'] ?? null,
            'string' => $field['valueString'] ?? null,
            default => null,
        } ?? ($field['content'] ?? null);

        return is_string($value) && trim($value) !== '' ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : null;
    }

    public static function confidence(array $fields, string ...$names): ?float
    {
        foreach ($names as $name) {
            $field = $fields[$name] ?? null;

            if (is_array($field) && self::value($field) !== null && is_numeric($field['confidence'] ?? null)) {
                return (float) $field['confidence'];
            }
        }

        return null;
    }

    public static function minConfidence(?float ...$values): ?float
    {
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values === [] ? null : min($values);
    }

    /** 'male' | 'female' | null, from Arabic / English / single-letter readings. */
    public static function gender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = mb_strtolower(trim($value));

        return match (true) {
            in_array($v, ['ذكر', 'male', 'm'], true) => 'male',
            in_array($v, ['أنثى', 'انثى', 'انثي', 'أنثي', 'female', 'f'], true) => 'female',
            default => null,
        };
    }

    /**
     * Split a full name into [first token, the rest] — the Egyptian card
     * layout (line 1 = first name, line 2 = father/grandfather/family).
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function splitFirstToken(?string $full): array
    {
        if ($full === null) {
            return [null, null];
        }

        $tokens = preg_split('/\s+/u', trim($full)) ?: [];
        $first = array_shift($tokens);

        return [$first, $tokens === [] ? null : implode(' ', $tokens)];
    }

    /**
     * Split one printed full name into [given, surname] for the matcher's
     * anchors: the surname anchor is the last token (family name).
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function splitFullName(?string $full): array
    {
        if ($full === null) {
            return [null, null];
        }

        $tokens = preg_split('/\s+/u', trim($full)) ?: [];

        if (count($tokens) < 2) {
            return [$full, null];
        }

        $surname = array_pop($tokens);

        return [implode(' ', $tokens), $surname];
    }
}
