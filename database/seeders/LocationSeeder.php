<?php

namespace Database\Seeders;

use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Illuminate\Database\Seeder;

/**
 * A small, deterministic set of Country + City reference data covering the
 * cities used by the Phase 1 demo hotels. Idempotent — every row is looked
 * up by a stable natural key (country code, country_id + name_en).
 *
 * Safe to run in any environment: this is reference data, not business or
 * demo data. Not a large dataset.
 */
class LocationSeeder extends Seeder
{
    /**
     * @var array<string, array{name_en: string, name_ar: string, cities: array<int, array{en: string, ar: string}>}>
     */
    private const DATA = [
        'EG' => [
            'name_en' => 'Egypt',
            'name_ar' => 'مصر',
            'cities' => [
                ['en' => 'Cairo', 'ar' => 'القاهرة'],
                ['en' => 'Hurghada', 'ar' => 'الغردقة'],
                ['en' => 'Luxor', 'ar' => 'الأقصر'],
                ['en' => 'Alexandria', 'ar' => 'الإسكندرية'],
                ['en' => 'Sharm El Sheikh', 'ar' => 'شرم الشيخ'],
            ],
        ],
        'SA' => [
            'name_en' => 'Saudi Arabia',
            'name_ar' => 'المملكة العربية السعودية',
            'cities' => [
                ['en' => 'Riyadh', 'ar' => 'الرياض'],
                ['en' => 'Jeddah', 'ar' => 'جدة'],
                ['en' => 'Dammam', 'ar' => 'الدمام'],
                ['en' => 'Abha', 'ar' => 'أبها'],
                ['en' => 'Mecca', 'ar' => 'مكة المكرمة'],
                ['en' => 'Medina', 'ar' => 'المدينة المنورة'],
            ],
        ],
        'AE' => [
            'name_en' => 'United Arab Emirates',
            'name_ar' => 'الإمارات العربية المتحدة',
            'cities' => [
                ['en' => 'Dubai', 'ar' => 'دبي'],
                ['en' => 'Abu Dhabi', 'ar' => 'أبو ظبي'],
                ['en' => 'Sharjah', 'ar' => 'الشارقة'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::DATA as $code => $country) {
            $countryModel = Country::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name_en' => $country['name_en'],
                    'name_ar' => $country['name_ar'],
                    'is_active' => true,
                ],
            );

            foreach ($country['cities'] as $city) {
                City::query()->firstOrCreate(
                    ['country_id' => $countryModel->id, 'name_en' => $city['en']],
                    ['name_ar' => $city['ar'], 'is_active' => true],
                );
            }
        }
    }
}
