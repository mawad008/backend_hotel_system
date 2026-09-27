<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual SEO metadata for hotels, following the same `*_i18n` JSON
 * pattern already used for `tagline_i18n`/`description_i18n`.
 * `seo_indexable` is a robots index/noindex directive so staff can keep an
 * unfinished/unlaunched hotel out of search results before it's ready.
 *
 * Deliberately minimal — no `canonical_url`, dedicated OG image, or
 * structured-data fields: none are justified yet without a public website
 * project defining a URL/domain scheme and OG asset need (see the Hotel
 * module plan's SEO decisions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('meta_title_i18n')->nullable()->after('description_i18n');
            $table->json('meta_description_i18n')->nullable()->after('meta_title_i18n');
            $table->boolean('seo_indexable')->default(true)->after('meta_description_i18n');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['meta_title_i18n', 'meta_description_i18n', 'seo_indexable']);
        });
    }
};
