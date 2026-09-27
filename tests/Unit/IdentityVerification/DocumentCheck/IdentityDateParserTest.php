<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDateParser as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentityDateParserTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function cases(): array
    {
        return [
            'iso' => ['2031-01-01', '2031-01-01'],
            'slashes ymd' => ['2031/01/01', '2031-01-01'],
            'dmy' => ['15/01/1990', '1990-01-15'],
            'dmy dashes' => ['15-01-1990', '1990-01-15'],
            'dots' => ['1990.1.15', '1990-01-15'],
            'arabic-indic digits' => ['١٥/٠١/١٩٩٠', '1990-01-15'],
            'persian digits' => ['۱۵/۰۱/۱۹۹۰', '1990-01-15'],
            // Umm al-Qura → Gregorian (1 Ramadan 1445 = 11 March 2024).
            'hijri ymd' => ['1445/09/01', '2024-03-11'],
            'hijri marked arabic digits' => ['١٤١٠/٠٣/١٥ هـ', '1989-10-16'],
            'hijri dmy' => ['15/03/1410', '1989-10-16'],
            'invalid gregorian' => ['2023-02-30', null],
            'invalid hijri month' => ['1445/13/01', null],
            'garbage' => ['غير مقروء', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_parsing(string $in, ?string $out): void
    {
        $this->assertSame($out, P::parse($in)?->format('Y-m-d'));
    }
}
