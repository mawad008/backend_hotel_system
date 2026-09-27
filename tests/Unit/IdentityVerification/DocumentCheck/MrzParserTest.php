<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\Mrz\MrzParser;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use PHPUnit\Framework\TestCase;

/**
 * Specimens are the fictional "Utopia" examples published in ICAO Doc 9303
 * (Parts 4 and 5) — synthetic, never real people.
 */
class MrzParserTest extends TestCase
{
    private const TD3 = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

    private const TD1 = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";

    public function test_check_digit_algorithm(): void
    {
        $this->assertSame(6, MrzParser::checkDigit('L898902C3'));
        $this->assertSame(2, MrzParser::checkDigit('740812'));
        $this->assertSame(0, MrzParser::checkDigit('<<<<<<<<<<<<<<'));
    }

    public function test_td3_passport_specimen_parses_and_validates(): void
    {
        $mrz = MrzParser::parse(self::TD3);

        $this->assertNotNull($mrz);
        $this->assertSame(MrzParser::TD3, $mrz->format);
        $this->assertTrue($mrz->isPassport());
        $this->assertTrue($mrz->isValid());
        $this->assertSame('L898902C3', $mrz->documentNumber);
        $this->assertSame('UTO', $mrz->issuingState);
        $this->assertSame('UTO', $mrz->nationality);
        $this->assertSame('ERIKSSON', $mrz->surname);
        $this->assertSame('ANNA MARIA', $mrz->givenNames);
        $this->assertSame('1974-08-12', $mrz->dateOfBirth->format('Y-m-d'));
        $this->assertSame('2012-04-15', $mrz->dateOfExpiry->format('Y-m-d'));
        $this->assertSame('F', $mrz->sex);
        $this->assertFalse($mrz->namesTruncated);
    }

    public function test_td1_id_card_specimen_parses_and_validates(): void
    {
        $mrz = MrzParser::parse(self::TD1);

        $this->assertSame(MrzParser::TD1, $mrz->format);
        $this->assertTrue($mrz->isValid());
        $this->assertSame('D23145890', $mrz->documentNumber);
        $this->assertSame('ERIKSSON', $mrz->surname);
    }

    public function test_td2_layout(): void
    {
        $line2 = 'D23145890'.MrzParser::checkDigit('D23145890').'UTO'.'740812'.'2'.'F'.'120415'.'9'.'<<<<<<<';
        $composite = MrzParser::checkDigit(substr($line2, 0, 10).substr($line2, 13, 7).substr($line2, 21, 14));
        $mrz = MrzParser::parse("I<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<\n".$line2.$composite);

        $this->assertSame(MrzParser::TD2, $mrz->format);
        $this->assertTrue($mrz->isValid(), implode(',', $mrz->failedChecks));
    }

    public function test_a_tampered_document_number_fails_its_check_digits(): void
    {
        $mrz = MrzParser::parse(str_replace('L898902C36', 'L898902C46', self::TD3));

        $this->assertFalse($mrz->isValid());
        $this->assertContains('document_number', $mrz->failedChecks);
        $this->assertContains('composite', $mrz->failedChecks);
    }

    public function test_a_tampered_birth_date_fails(): void
    {
        $mrz = MrzParser::parse(str_replace('7408122F', '7408132F', self::TD3));

        $this->assertContains('birth', $mrz->failedChecks);
    }

    public function test_ocr_noise_is_tolerated_only_where_icao_allows(): void
    {
        // Spaces inside lines, a guillemet for a filler, and 'O' misread for
        // '0' in the numeric date field are all repaired...
        $noisy = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<«\nL898902C3 6UTO74O8122F1204159ZE184226B<<<<<10";
        $mrz = MrzParser::parse("Page text\n".$noisy."\nmore text");

        $this->assertTrue($mrz->isValid(), implode(',', $mrz->failedChecks));

        // ...but the alphanumeric document number is never "corrected".
        $mrz = MrzParser::parse(str_replace('L898902C3', 'L898902CE', self::TD3));
        $this->assertContains('document_number', $mrz->failedChecks);
    }

    public function test_missing_trailing_fillers_are_padded(): void
    {
        $mrz = MrzParser::parse("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10");

        $this->assertTrue($mrz->isValid());
    }

    public function test_non_mrz_text_returns_null(): void
    {
        $this->assertNull(MrzParser::parse(null));
        $this->assertNull(MrzParser::parse('REPUBLIC OF UTOPIA PASSPORT'));
        $this->assertNull(MrzParser::parse(str_repeat('A', 44)."\n".str_repeat('B', 44)));
    }

    public function test_truncated_names_are_flagged(): void
    {
        $mrz = MrzParser::parse(DummyIdentityDocumentProvider::td3(
            'VERYLONGSURNAMEWITHMANYLETTERS', 'GIVENNAMEEXTREMELYLONG', 'X1234567', 'UTO', '1990-01-01', '2030-01-01',
        ));

        $this->assertTrue($mrz->isValid());
        $this->assertTrue($mrz->namesTruncated);
    }

    public function test_century_inference(): void
    {
        $mrz = MrzParser::parse(DummyIdentityDocumentProvider::td3('DOE', 'JANE', 'X1234567', 'UTO', '2005-02-03', '2031-12-31'));

        $this->assertSame('2005-02-03', $mrz->dateOfBirth->format('Y-m-d'));
        $this->assertSame('2031-12-31', $mrz->dateOfExpiry->format('Y-m-d'));
    }
}
