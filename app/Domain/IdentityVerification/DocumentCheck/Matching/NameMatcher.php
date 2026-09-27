<?php

namespace App\Domain\IdentityVerification\DocumentCheck\Matching;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;

/**
 * Compares the guest-entered full name with the name read off the document.
 *
 * Strategy (documented in mobile/docs/mobile-phase-6-identity-verification.md):
 *
 *  1. Both names are normalized into tokens ({@see IdentityTextNormalizer}).
 *  2. Different scripts (Arabic vs Latin) are NOT transliterated and guessed
 *     at — the result is `unverifiable_script` (→ needs review).
 *  3. Adjacent tokens are merged when their concatenation equals a token on
 *     the other side (ABDUL RAHMAN ↔ ABDULRAHMAN).
 *  4. Each guest token is paired with its best distinct document token by
 *     Jaro-Winkler similarity.
 *
 *  STRONG  — the compact forms are identical, OR: ≥ 2 guest tokens, every
 *            guest token pairs STRONGLY, and the pairs cover the document's
 *            first given name AND its surname. Middle names may be omitted by
 *            the guest; an extra guest token that is not on the document is
 *            not strong. A strong TOKEN pair is:
 *              - Arabic script: identical after normalization (spelling
 *                variants are already folded by the normalizer; similar but
 *                different names — محمد / محمود, احمد / حامد — are NOT strong);
 *              - Latin script: Jaro-Winkler ≥ {@see self::STRONG} AND at most
 *                one edit apart (MOHAMED / MOHAMMED), or an MRZ-truncated
 *                surname prefix.
 *  WEAK    — not strong, but ≥ half of the guest tokens pair at
 *            ≥ {@see self::WEAK} and the surname does → needs review.
 *  MISMATCH — anything else.
 *
 * The score (0..100) is the mean best-pair similarity, kept only as an
 * audit number — the band, not the score, drives the decision.
 */
final class NameMatcher
{
    public const STRONG = 0.94;

    public const WEAK = 0.88;

    public const RESULT_STRONG = 'strong';

    public const RESULT_WEAK = 'weak';

    public const RESULT_MISMATCH = 'mismatch';

    public const RESULT_UNVERIFIABLE_SCRIPT = 'unverifiable_script';

    public const RESULT_MISSING = 'missing';

    /**
     * @param  string  $documentGiven  given (+ middle) names as printed
     * @param  string  $documentSurname  surname as printed
     * @param  bool  $surnameMayBeTruncated  MRZ name field ran out of room
     * @return array{result: string, score: int}
     */
    public static function compare(
        #[\SensitiveParameter] string $claimed,
        #[\SensitiveParameter] string $documentGiven,
        #[\SensitiveParameter] string $documentSurname,
        bool $surnameMayBeTruncated = false,
    ): array {
        $documentFull = trim($documentGiven.' '.$documentSurname);

        if (trim($claimed) === '' || $documentFull === '') {
            return ['result' => self::RESULT_MISSING, 'score' => 0];
        }

        $claimScript = IdentityTextNormalizer::script($claimed);
        $docScript = IdentityTextNormalizer::script($documentFull);

        if ($claimScript !== $docScript && $claimScript !== 'none' && $docScript !== 'none') {
            return ['result' => self::RESULT_UNVERIFIABLE_SCRIPT, 'score' => 0];
        }

        $claimTokens = IdentityTextNormalizer::nameTokens($claimed);
        $givenTokens = IdentityTextNormalizer::nameTokens($documentGiven);
        $surnameTokens = IdentityTextNormalizer::nameTokens($documentSurname);
        $docTokens = [...$givenTokens, ...$surnameTokens];

        if ($claimTokens === [] || $docTokens === []) {
            return ['result' => self::RESULT_MISSING, 'score' => 0];
        }

        if (implode('', $claimTokens) === implode('', $docTokens)) {
            return ['result' => self::RESULT_STRONG, 'score' => 100];
        }

        $claimTokens = self::mergeAdjacent($claimTokens, $docTokens);
        $docTokens = self::mergeAdjacent($docTokens, $claimTokens);

        // Anchors: the first given name and the last surname token (after merging).
        $firstGiven = $givenTokens === [] ? null : self::findMerged($docTokens, $givenTokens[0]);
        $surname = $surnameTokens === [] ? null : self::findMerged($docTokens, $surnameTokens[count($surnameTokens) - 1]);

        $pairs = self::pair($claimTokens, $docTokens, $surnameMayBeTruncated ? $surname : null);
        $scores = array_column($pairs, 'score');
        $mean = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
        $score = (int) round($mean * 100);

        $matchedDoc = fn (float $min): array => array_values(array_map(
            fn (array $p) => $p['doc'],
            array_filter($pairs, fn (array $p) => $p['doc'] !== null && $p['score'] >= $min),
        ));

        $strongDoc = array_values(array_map(
            fn (array $p) => $p['doc'],
            array_filter($pairs, fn (array $p) => $p['doc'] !== null && self::strongPair($p['claim'], $p['doc'], $p['score'])),
        ));
        $allStrong = count($strongDoc) === count($claimTokens);
        $coversAnchors = ($firstGiven === null || in_array($firstGiven, $strongDoc, true))
            && ($surname === null || in_array($surname, $strongDoc, true));

        if (count($claimTokens) >= 2 && $allStrong && $coversAnchors) {
            return ['result' => self::RESULT_STRONG, 'score' => $score];
        }

        $weakDoc = $matchedDoc(self::WEAK);
        $surnameWeak = $surname === null || in_array($surname, $weakDoc, true);

        if ($surnameWeak && count($weakDoc) * 2 >= count($claimTokens)) {
            return ['result' => self::RESULT_WEAK, 'score' => $score];
        }

        return ['result' => self::RESULT_MISMATCH, 'score' => $score];
    }

    /** Conservative token equivalence — see the class docblock. */
    private static function strongPair(string $claim, string $doc, float $score): bool
    {
        if ($claim === $doc || $score === 1.0) {
            return true; // identical, or an MRZ-truncated surname prefix
        }

        if (IdentityTextNormalizer::script($claim) === 'arabic' || IdentityTextNormalizer::script($doc) === 'arabic') {
            return false;
        }

        return $score >= self::STRONG && levenshtein($claim, $doc) <= 1;
    }

    public static function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $s1 = mb_str_split($a);
        $s2 = mb_str_split($b);
        $l1 = count($s1);
        $l2 = count($s2);

        if ($l1 === 0 || $l2 === 0) {
            return 0.0;
        }

        $window = max(0, intdiv(max($l1, $l2), 2) - 1);
        $m1 = array_fill(0, $l1, false);
        $m2 = array_fill(0, $l2, false);
        $matches = 0;

        for ($i = 0; $i < $l1; $i++) {
            $start = max(0, $i - $window);
            $end = min($i + $window + 1, $l2);

            for ($j = $start; $j < $end; $j++) {
                if (! $m2[$j] && $s1[$i] === $s2[$j]) {
                    $m1[$i] = $m2[$j] = true;
                    $matches++;

                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;

        for ($i = 0; $i < $l1; $i++) {
            if (! $m1[$i]) {
                continue;
            }

            while (! $m2[$k]) {
                $k++;
            }

            if ($s1[$i] !== $s2[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $jaro = ($matches / $l1 + $matches / $l2 + ($matches - $transpositions / 2) / $matches) / 3;

        $prefix = 0;

        for ($i = 0; $i < min(4, $l1, $l2) && $s1[$i] === $s2[$i]; $i++) {
            $prefix++;
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }

    /**
     * Greedy best-first pairing of each claim token with a distinct doc token.
     *
     * @param  list<string>  $claim
     * @param  list<string>  $doc
     * @return list<array{claim: string, doc: string|null, score: float}>
     */
    private static function pair(array $claim, array $doc, ?string $truncatedSurname): array
    {
        $candidates = [];

        foreach ($claim as $ci => $c) {
            foreach ($doc as $di => $d) {
                $score = self::jaroWinkler($c, $d);

                // An MRZ surname cut off by the field length is a prefix of the real one.
                if ($d === $truncatedSurname && strlen($d) >= 4 && str_starts_with($c, $d)) {
                    $score = 1.0;
                }

                $candidates[] = [$score, $ci, $di];
            }
        }

        usort($candidates, fn (array $x, array $y) => $y[0] <=> $x[0]);

        $usedClaim = [];
        $usedDoc = [];
        $pairs = [];

        foreach ($candidates as [$score, $ci, $di]) {
            if (isset($usedClaim[$ci]) || isset($usedDoc[$di])) {
                continue;
            }

            $usedClaim[$ci] = $usedDoc[$di] = true;
            $pairs[$ci] = ['claim' => $claim[$ci], 'doc' => $doc[$di], 'score' => $score];
        }

        foreach ($claim as $ci => $c) {
            $pairs[$ci] ??= ['claim' => $c, 'doc' => null, 'score' => 0.0];
        }

        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $other
     * @return list<string>
     */
    private static function mergeAdjacent(array $tokens, array $other): array
    {
        $out = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (isset($tokens[$i + 1]) && in_array($tokens[$i].$tokens[$i + 1], $other, true)) {
                $out[] = $tokens[$i].$tokens[++$i];

                continue;
            }

            $out[] = $tokens[$i];
        }

        return $out;
    }

    /** The (possibly merged) doc token that contains $original. */
    private static function findMerged(array $docTokens, string $original): ?string
    {
        foreach ($docTokens as $t) {
            if ($t === $original) {
                return $t;
            }
        }

        foreach ($docTokens as $t) {
            if (str_contains($t, $original)) {
                return $t;
            }
        }

        return null;
    }
}
