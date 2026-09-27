<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer as N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentityTextNormalizerTest extends TestCase
{
    public function test_arabic_indic_and_extended_digits_become_ascii(): void
    {
        $this->assertSame('1974-08-12', N::digits('١٩٧٤-٠٨-١٢'));
        $this->assertSame('0123456789', N::digits('۰۱۲۳۴۵۶۷۸۹'));
    }

    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'case + whitespace' => ['  anna   maria  ERIKSSON ', 'ANNA MARIA ERIKSSON'],
            'latin diacritics' => ['Éric Ødegård-Straße', 'ERIC ODEGARD STRASSE'],
            'apostrophe dropped' => ["Seán O'Neil", 'SEAN ONEIL'],
            'MRZ filler' => ['ANNA<MARIA', 'ANNA MARIA'],
            'punctuation' => ['Smith, John J.', 'SMITH JOHN J'],
            'article joined' => ['Mohammed Al Qahtani', 'MOHAMMED ALQAHTANI'],
            'article hyphen' => ['Mohammed al-Qahtani', 'MOHAMMED ALQAHTANI'],
            'connector dropped' => ['Faisal bin Abdullah', 'FAISAL ABDULLAH'],
            'arabic harakat + alef' => ['أَحْمَد إبراهيم', 'احمد ابراهيم'],
            'arabic taa marbuta + ya' => ['فاطمة مصطفى', 'فاطمه مصطفي'],
            'arabic tatweel' => ['محـــمد', 'محمد'],
            'arabic article joined' => ['محمد ال قحطاني', 'محمد القحطاني'],
            'arabic connector' => ['فيصل بن عبدالله', 'فيصل عبدالله'],
        ];
    }

    #[DataProvider('names')]
    public function test_name_normalization(string $in, string $out): void
    {
        $this->assertSame($out, N::name($in));
    }

    public function test_document_number_normalization(): void
    {
        $this->assertSame('L898902C3', N::documentNumber(' l898-902 c3 '));
        $this->assertSame('L898902C3', N::documentNumber('L898902C3<<'));
        $this->assertSame('1234567890', N::documentNumber('١٢٣٤٥٦٧٨٩٠'));
    }

    public function test_confusable_skeleton_folds_ocr_look_alikes(): void
    {
        $this->assertSame(N::confusableSkeleton('A0B1S5'), N::confusableSkeleton('AO81S5'));
        $this->assertNotSame(N::confusableSkeleton('A1234'), N::confusableSkeleton('A1235'));
    }

    public function test_script_detection(): void
    {
        $this->assertSame('arabic', N::script('محمد'));
        $this->assertSame('latin', N::script('Mohammed'));
        $this->assertSame('mixed', N::script('Mohammed محمد'));
        $this->assertSame('none', N::script('123'));
    }

    public function test_country_codes(): void
    {
        $this->assertSame('SAU', N::country('sau'));
        $this->assertSame('DEU', N::country('D<<'));
        $this->assertNull(N::country('Saudi Arabia'));
        $this->assertNull(N::country(null));
    }
}
