<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;

/**
 * Maps a nationality as printed on a Saudi Iqama (Arabic adjective, English
 * name/adjective, or an ISO alpha-3 code) to ISO 3166-1 alpha-3.
 *
 * Deliberately a finite, reviewed table of the most common resident
 * nationalities — an unknown value maps to null (never guessed). Extend the
 * table as needed; nationality is only compared when the guest supplies one,
 * and a difference only sends the check to review.
 */
final class CountryNames
{
    private const TABLE = [
        'SAU' => ['سعودي', 'سعودية', 'saudi', 'saudi arabia', 'saudi arabian'],
        'EGY' => ['مصري', 'مصرية', 'egypt', 'egyptian'],
        'IND' => ['هندي', 'هندية', 'india', 'indian'],
        'PAK' => ['باكستاني', 'باكستانية', 'pakistan', 'pakistani'],
        'BGD' => ['بنغلاديشي', 'بنجلاديشي', 'بنغلاديشية', 'bangladesh', 'bangladeshi'],
        'PHL' => ['فلبيني', 'فلبينية', 'philippines', 'filipino', 'filipina'],
        'IDN' => ['اندونيسي', 'إندونيسي', 'اندونيسية', 'indonesia', 'indonesian'],
        'NPL' => ['نيبالي', 'نيبالية', 'nepal', 'nepalese', 'nepali'],
        'LKA' => ['سريلانكي', 'سريلانكية', 'sri lanka', 'sri lankan'],
        'YEM' => ['يمني', 'يمنية', 'yemen', 'yemeni'],
        'SDN' => ['سوداني', 'سودانية', 'sudan', 'sudanese'],
        'SYR' => ['سوري', 'سورية', 'syria', 'syrian'],
        'JOR' => ['اردني', 'أردني', 'اردنية', 'jordan', 'jordanian'],
        'LBN' => ['لبناني', 'لبنانية', 'lebanon', 'lebanese'],
        'PSE' => ['فلسطيني', 'فلسطينية', 'palestine', 'palestinian'],
        'ETH' => ['اثيوبي', 'إثيوبي', 'اثيوبية', 'ethiopia', 'ethiopian'],
        'KEN' => ['كيني', 'كينية', 'kenya', 'kenyan'],
        'NGA' => ['نيجيري', 'نيجيرية', 'nigeria', 'nigerian'],
        'MAR' => ['مغربي', 'مغربية', 'morocco', 'moroccan'],
        'TUN' => ['تونسي', 'تونسية', 'tunisia', 'tunisian'],
        'DZA' => ['جزائري', 'جزائرية', 'algeria', 'algerian'],
        'TUR' => ['تركي', 'تركية', 'turkey', 'turkish', 'türkiye'],
        'AFG' => ['افغاني', 'أفغاني', 'afghanistan', 'afghan'],
        'GBR' => ['بريطاني', 'بريطانية', 'united kingdom', 'british'],
        'USA' => ['امريكي', 'أمريكي', 'امريكية', 'united states', 'american'],
    ];

    private function __construct() {}

    public static function toIso3(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $iso = IdentityTextNormalizer::country($value);

        if ($iso !== null && array_key_exists($iso, self::TABLE)) {
            return $iso;
        }

        $needle = IdentityTextNormalizer::name($value);

        foreach (self::TABLE as $code => $names) {
            foreach ($names as $name) {
                if (IdentityTextNormalizer::name($name) === $needle) {
                    return $code;
                }
            }
        }

        // A bare 3-letter code outside the table is still ISO-shaped: accept it.
        return $iso;
    }
}
