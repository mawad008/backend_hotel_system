<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off, non-destructive backfill: give every existing hotel that has a
 * legacy free-text country/city a real Country + City reference so the
 * normalized relationship can be enforced going forward. The legacy text
 * columns are left untouched.
 *
 * Runs migration-safe raw queries (no Eloquent models) and is idempotent —
 * re-running matches the rows it already created instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hotels = DB::table('hotels')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->get(['id', 'country', 'city', 'country_id', 'city_id']);

        foreach ($hotels as $hotel) {
            if ($hotel->country_id && $hotel->city_id) {
                continue;
            }

            $countryName = trim((string) ($hotel->country ?: 'Unknown'));
            $cityName = trim((string) $hotel->city);

            $countryId = $this->resolveCountry($countryName);
            $cityId = $this->resolveCity($countryId, $cityName);

            DB::table('hotels')->where('id', $hotel->id)->update([
                'country_id' => $countryId,
                'city_id' => $cityId,
            ]);
        }
    }

    public function down(): void
    {
        // Data-only migration — the schema rollback lives in the previous
        // migration. Nothing to reverse here without guessing which rows
        // were pre-existing.
    }

    private function resolveCountry(string $name): int
    {
        $existing = DB::table('countries')->where('name_en', $name)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('countries')->insertGetId([
            'name_en' => $name,
            'name_ar' => $name,
            'code' => $this->uniqueCountryCode($name),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveCity(int $countryId, string $name): int
    {
        $existing = DB::table('cities')
            ->where('country_id', $countryId)
            ->where('name_en', $name)
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('cities')->insertGetId([
            'country_id' => $countryId,
            'name_en' => $name,
            'name_ar' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniqueCountryCode(string $name): string
    {
        $base = strtoupper(Str::of($name)->ascii()->replaceMatches('/[^A-Za-z]/', '')->substr(0, 2));
        if (strlen($base) < 2) {
            $base = 'XX';
        }

        $code = $base;
        $suffix = 0;
        while (DB::table('countries')->where('code', $code)->exists()) {
            $suffix++;
            $code = substr($base, 0, 1).$suffix;
        }

        return $code;
    }
};
