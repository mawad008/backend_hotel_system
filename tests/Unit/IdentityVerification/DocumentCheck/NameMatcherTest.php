<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\Matching\NameMatcher as M;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> claimed, given, surname, expected */
    public static function cases(): array
    {
        return [
            'exact' => ['Anna Maria Eriksson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_STRONG],
            'case/spacing' => ['  anna maria   eriksson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_STRONG],
            'order swapped' => ['Eriksson Anna Maria', 'ANNA MARIA', 'ERIKSSON', M::RESULT_STRONG],
            'middle name omitted' => ['Anna Eriksson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_STRONG],
            'diacritics' => ['Anna Maria Eriksšon', 'ANNA MARIA', 'ERIKSSON', M::RESULT_STRONG],
            'article spacing' => ['Mohammed Al Qahtani', 'MOHAMMED', 'ALQAHTANI', M::RESULT_STRONG],
            'compound merged' => ['Abdul Rahman Alotaibi', 'ABDULRAHMAN', 'ALOTAIBI', M::RESULT_STRONG],
            'connector' => ['Faisal bin Saad Alharbi', 'FAISAL SAAD', 'ALHARBI', M::RESULT_STRONG],
            'arabic same script' => ['محمد عبدالله القحطاني', 'محمد عبدالله', 'القحطاني', M::RESULT_STRONG],
            'arabic hamza/taa' => ['أسامة الشهري', 'اسامه', 'الشهري', M::RESULT_STRONG],
            'doubled-letter variant' => ['Mohamed Alqahtani', 'MOHAMMED', 'ALQAHTANI', M::RESULT_STRONG],
            'transliteration variant' => ['Muhammad Alqahtani', 'MOHAMMED', 'ALQAHTANI', M::RESULT_WEAK],
            'surname only' => ['Eriksson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_WEAK],
            'first name missing, surname ok' => ['Maria Eriksson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_WEAK],
            'extra unknown token' => ['Anna Maria Eriksson Smith', 'ANNA MARIA', 'ERIKSSON', M::RESULT_WEAK],
            'different surname' => ['Anna Maria Johansson', 'ANNA MARIA', 'ERIKSSON', M::RESULT_MISMATCH],
            'different person' => ['John Smith', 'ANNA MARIA', 'ERIKSSON', M::RESULT_MISMATCH],
            'cross script' => ['محمد القحطاني', 'MOHAMMED', 'ALQAHTANI', M::RESULT_UNVERIFIABLE_SCRIPT],
            'empty claim' => ['', 'ANNA', 'ERIKSSON', M::RESULT_MISSING],
            'empty document' => ['Anna', '', '', M::RESULT_MISSING],
        ];
    }

    #[DataProvider('cases')]
    public function test_matching(string $claimed, string $given, string $surname, string $expected): void
    {
        $this->assertSame($expected, M::compare($claimed, $given, $surname)['result']);
    }

    public function test_truncated_mrz_surname_matches_the_full_claimed_surname(): void
    {
        $this->assertSame(M::RESULT_WEAK, M::compare('Anna Wolfeschlegelsteinhausen', 'ANNA', 'WOLFESCHLEGEL')['result']);
        $this->assertSame(M::RESULT_STRONG, M::compare('Anna Wolfeschlegelsteinhausen', 'ANNA', 'WOLFESCHLEGEL', surnameMayBeTruncated: true)['result']);
    }

    public function test_jaro_winkler_reference_values(): void
    {
        $this->assertEqualsWithDelta(0.961, M::jaroWinkler('MARTHA', 'MARHTA'), 0.001);
        $this->assertEqualsWithDelta(0.840, M::jaroWinkler('DWAYNE', 'DUANE'), 0.001);
        $this->assertSame(1.0, M::jaroWinkler('X', 'X'));
        $this->assertSame(0.0, M::jaroWinkler('', 'X'));
    }
}
