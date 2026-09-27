<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer as N;
use App\Domain\IdentityVerification\DocumentCheck\Matching\NameMatcher as M;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arabic-aware but CONSERVATIVE: spelling variants of the same letters
 * match; different names — even close ones — do not; Arabic is never
 * transliterated to match a Latin reading.
 */
class ArabicNameMatchingTest extends TestCase
{
    public function test_digits_from_every_script_normalize_to_one_form(): void
    {
        $this->assertSame('2900115', N::digits('٢٩٠٠١١٥'));
        $this->assertSame('2900115', N::digits('۲۹۰۰۱۱۵'));
        $this->assertSame('2900115', N::documentNumber('٢٩٠ ٠١١-٥'));
    }

    /** @return array<string, array{string, string, string, string}> claimed, given, family, expected */
    public static function cases(): array
    {
        return [
            'identical' => ['سامي عادل فؤاد منصور', 'سامي', 'عادل فؤاد منصور', M::RESULT_STRONG],
            'hamza on alef' => ['أحمد إبراهيم آل منصور', 'احمد', 'ابراهيم ال منصور', M::RESULT_STRONG],
            'taa marbuta / haa' => ['أسامة حمزة', 'اسامه', 'حمزه', M::RESULT_STRONG],
            'alef maqsura / yaa' => ['مصطفى علي', 'مصطفي', 'علي', M::RESULT_STRONG],
            'tashkeel + tatweel' => ['مُحَمَّـــد عَلِي', 'محمد', 'علي', M::RESULT_STRONG],
            'abd compound spacing' => ['عبد الله محمد', 'عبدالله', 'محمد', M::RESULT_STRONG],
            'connector bin' => ['فهد بن سالم الحربي', 'فهد سالم', 'الحربي', M::RESULT_STRONG],
            'middle names omitted' => ['سامي منصور', 'سامي', 'عادل فؤاد منصور', M::RESULT_STRONG],
            'mohammed vs mahmoud' => ['محمود علي حسن', 'محمد', 'علي حسن', M::RESULT_WEAK],
            'ahmed vs hamed' => ['حامد منصور', 'احمد', 'منصور', M::RESULT_WEAK],
            'different family' => ['سامي عادل فؤاد الشريف', 'سامي', 'عادل فؤاد منصور', M::RESULT_MISMATCH],
            'different person' => ['خالد يوسف', 'سامي', 'عادل فؤاد منصور', M::RESULT_MISMATCH],
            'arabic claim vs latin doc' => ['محمد أحمد', 'MOHAMED', 'AHMED', M::RESULT_UNVERIFIABLE_SCRIPT],
            'latin claim vs arabic doc' => ['Mohamed Ahmed', 'محمد', 'احمد', M::RESULT_UNVERIFIABLE_SCRIPT],
        ];
    }

    #[DataProvider('cases')]
    public function test_arabic_matching(string $claimed, string $given, string $family, string $expected): void
    {
        $this->assertSame($expected, M::compare($claimed, $given, $family)['result']);
    }

    public function test_close_but_different_names_never_reach_strong(): void
    {
        foreach ([['محمود', 'محمد'], ['حامد', 'احمد'], ['سمير', 'سامي'], ['علا', 'علي']] as [$a, $b]) {
            $this->assertNotSame(M::RESULT_STRONG, M::compare("{$a} منصور", $b, 'منصور')['result'], "{$a} vs {$b}");
        }
    }
}
