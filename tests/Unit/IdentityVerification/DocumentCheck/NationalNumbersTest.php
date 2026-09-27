<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\EgyptianNationalIdNumber;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\NationalNumberRules;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\SaudiIdentityNumber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** All numbers are synthetic (structurally valid, not issued to anyone known). */
class NationalNumbersTest extends TestCase
{
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-09-27');
    }

    public function test_egyptian_number_decodes_birth_date_and_gender(): void
    {
        $i = EgyptianNationalIdNumber::inspect('29001150112357', $this->today);

        $this->assertTrue($i->valid);
        $this->assertSame('1990-01-15', $i->birthDate->format('Y-m-d'));
        $this->assertSame('male', $i->gender);

        $female = EgyptianNationalIdNumber::inspect('30512312101248', $this->today);
        $this->assertTrue($female->valid);
        $this->assertSame('2005-12-31', $female->birthDate->format('Y-m-d'));
        $this->assertSame('female', $female->gender);
    }

    public function test_arabic_indic_digits_and_spaces_are_accepted(): void
    {
        $i = EgyptianNationalIdNumber::inspect('٢٩٠٠١١٥ ٠١١٢٣٥٧', $this->today);

        $this->assertTrue($i->valid);
        $this->assertSame('1990-01-15', $i->birthDate->format('Y-m-d'));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidEgyptian(): array
    {
        return [
            'too short' => ['2900115011235', 'national_id_structure_invalid'],
            'too long' => ['290011501123571', 'national_id_structure_invalid'],
            'letters' => ['29001150112A57', 'national_id_structure_invalid'],
            'century 1' => ['19001150112357', 'national_id_century_invalid'],
            'century 4' => ['49001150112357', 'national_id_century_invalid'],
            'month 13' => ['29013150112357', 'national_id_birth_date_invalid'],
            'feb 30' => ['29002300112357', 'national_id_birth_date_invalid'],
            'future birth' => ['32712010112357', 'national_id_birth_date_invalid'],
            'governorate 00' => ['29001150012357', 'national_id_governorate_invalid'],
            'governorate 36' => ['29001153612357', 'national_id_governorate_invalid'],
            'governorate 87' => ['29001158712357', 'national_id_governorate_invalid'],
        ];
    }

    #[DataProvider('invalidEgyptian')]
    public function test_invalid_egyptian_numbers(string $number, string $reason): void
    {
        $i = EgyptianNationalIdNumber::inspect($number, $this->today);

        $this->assertFalse($i->valid);
        $this->assertSame([$reason], $i->reasons);
    }

    public function test_born_abroad_governorate_88_is_valid(): void
    {
        $this->assertTrue(EgyptianNationalIdNumber::inspect('29001158812357', $this->today)->valid);
    }

    public function test_the_undocumented_check_digit_is_not_enforced(): void
    {
        // Same number, every final digit: structure rules only.
        foreach (range(0, 9) as $d) {
            $this->assertTrue(EgyptianNationalIdNumber::inspect('2900115011235'.$d, $this->today)->valid);
        }
    }

    public function test_saudi_prefixes_distinguish_citizen_and_resident(): void
    {
        $this->assertTrue(SaudiIdentityNumber::inspect('1098765432', IdentityDocumentType::SaudiNationalId)->valid);
        $this->assertTrue(SaudiIdentityNumber::inspect('٢٠٩٨٧٦٥٤٣٢', IdentityDocumentType::SaudiIqama)->valid);

        $this->assertSame(['saudi_number_prefix_mismatch'], SaudiIdentityNumber::inspect('2098765432', IdentityDocumentType::SaudiNationalId)->reasons);
        $this->assertSame(['saudi_number_prefix_mismatch'], SaudiIdentityNumber::inspect('1098765432', IdentityDocumentType::SaudiIqama)->reasons);
        $this->assertSame(['saudi_number_structure_invalid'], SaudiIdentityNumber::inspect('109876543', IdentityDocumentType::SaudiNationalId)->reasons);
        $this->assertSame(['saudi_number_structure_invalid'], SaudiIdentityNumber::inspect('10987654321', IdentityDocumentType::SaudiNationalId)->reasons);
    }

    public function test_rules_only_apply_to_their_document_types(): void
    {
        $this->assertNull(NationalNumberRules::inspect(IdentityDocumentType::Passport, 'L898902C3'));
        $this->assertNull(NationalNumberRules::inspect(IdentityDocumentType::OtherIdentityDocument, 'X1'));
        $this->assertNotNull(NationalNumberRules::inspect(IdentityDocumentType::EgyptianNationalId, 'X1'));
    }

    public function test_inspection_never_leaks_its_values_when_dumped(): void
    {
        $dump = print_r(EgyptianNationalIdNumber::inspect('29001150112357', $this->today), true);

        $this->assertStringNotContainsString('1990', $dump);
        $this->assertStringNotContainsString('male', $dump);
    }
}
