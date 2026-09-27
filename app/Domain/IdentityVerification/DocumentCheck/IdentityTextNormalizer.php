<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use Normalizer;
use Transliterator;

/**
 * Normalization of identity values before comparison. Pure and stateless.
 *
 * Names:
 *  - Unicode NFKC; Arabic-Indic / Extended Arabic-Indic digits → ASCII
 *  - Arabic: harakat + tatweel removed; alef variants (أ إ آ ٱ) → ا,
 *    ى → ي, ة → ه, ؤ → و, ئ → ي, Persian ک/ی → ك/ي
 *  - Latin: diacritics folded to ASCII (É→E, ß→SS, Ø→O), upper-cased
 *  - apostrophes dropped (O'NEIL → ONEIL); every other punctuation mark,
 *    hyphen and the MRZ filler `<` becomes a space; whitespace collapsed
 *  - the article particle AL/EL/ال is joined to the next token
 *    (AL QAHTANI → ALQAHTANI) and the connectors BIN/BINT/IBN/BEN/بن/بنت/ابن
 *    are dropped, because documents and guests write them inconsistently.
 *
 * Document numbers: digits → ASCII, upper-cased, spaces / hyphens / `<` /
 * punctuation removed. Comparison on the result is EXACT; OCR-confusable
 * characters are only used to downgrade a mismatch to "needs review"
 * ({@see self::confusableSkeleton()}), never to accept one.
 */
final class IdentityTextNormalizer
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EXTENDED_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_LETTER_MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        'ک' => 'ك', 'ی' => 'ي',
    ];

    private const CONNECTORS = ['BIN', 'BINT', 'IBN', 'BEN', 'بن', 'بنت', 'ابن'];

    private const ARTICLES = ['AL', 'EL', 'ال'];

    /** OCR look-alikes folded together for the confusable skeleton only. */
    private const CONFUSABLES = [
        'O' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'L' => '1',
        'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8',
    ];

    private function __construct() {}

    public static function digits(string $value): string
    {
        return str_replace(
            [...self::ARABIC_INDIC, ...self::EXTENDED_ARABIC_INDIC],
            [...range(0, 9), ...range(0, 9)],
            $value,
        );
    }

    /** @return list<string> */
    public static function nameTokens(string $value): array
    {
        $value = self::digits(Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value);

        // Arabic: strip harakat (U+064B–U+065F, U+0670) and tatweel, fold letters.
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = strtr($value, self::ARABIC_LETTER_MAP);

        $value = self::latinToAscii($value);
        $value = preg_replace("/['’ʼ`´]/u", '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = mb_strtoupper(trim($value));

        if ($value === '') {
            return [];
        }

        $tokens = [];
        $pendingArticle = null;

        foreach (preg_split('/\s+/u', $value) ?: [] as $token) {
            if (in_array($token, self::CONNECTORS, true)) {
                continue;
            }

            if (in_array($token, self::ARTICLES, true)) {
                $pendingArticle = $token;

                continue;
            }

            $tokens[] = $pendingArticle !== null ? $pendingArticle.$token : $token;
            $pendingArticle = null;
        }

        return $tokens;
    }

    public static function name(string $value): string
    {
        return implode(' ', self::nameTokens($value));
    }

    /** 'arabic' | 'latin' | 'mixed' | 'none' */
    public static function script(string $value): string
    {
        $arabic = preg_match('/\p{Arabic}/u', $value) === 1;
        $latin = preg_match('/\p{Latin}/u', $value) === 1;

        return match (true) {
            $arabic && $latin => 'mixed',
            $arabic => 'arabic',
            $latin => 'latin',
            default => 'none',
        };
    }

    public static function documentNumber(string $value): string
    {
        $value = self::digits(Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value);
        $value = mb_strtoupper(self::latinToAscii($value));

        return preg_replace('/[^A-Z0-9\p{Arabic}]/u', '', $value) ?? '';
    }

    public static function confusableSkeleton(string $normalizedDocumentNumber): string
    {
        return strtr($normalizedDocumentNumber, self::CONFUSABLES);
    }

    /** ISO alpha-3 (MRZ `D<<` → DEU), or null when not a 3-letter code. */
    public static function country(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim(str_replace('<', '', $value)));

        if ($value === 'D') {
            return 'DEU';
        }

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    private static function latinToAscii(string $value): string
    {
        static $transliterator = null;
        $transliterator ??= Transliterator::create('[:Latin:] Latin-ASCII');

        return $transliterator?->transliterate($value) ?: $value;
    }
}
