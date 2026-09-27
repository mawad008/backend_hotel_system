<?php

namespace Tests\Unit\Support;

use App\Support\LocalizedContent;
use Tests\TestCase;

class LocalizedContentTest extends TestCase
{
    public function test_resolves_the_current_locale(): void
    {
        app()->setLocale('ar');

        $this->assertSame('مرحبا', LocalizedContent::resolve(['en' => 'Hello', 'ar' => 'مرحبا']));
    }

    public function test_falls_back_to_the_app_fallback_locale_then_the_legacy_string(): void
    {
        app()->setLocale('ar');
        config(['app.fallback_locale' => 'en']);

        // ar missing -> en
        $this->assertSame('Hello', LocalizedContent::resolve(['en' => 'Hello']));

        // map empty -> legacy plain-string column
        $this->assertSame('Legacy', LocalizedContent::resolve([], 'Legacy'));
        $this->assertSame('Legacy', LocalizedContent::resolve(null, 'Legacy'));
    }

    public function test_never_fabricates_a_missing_translation(): void
    {
        app()->setLocale('ar');
        config(['app.fallback_locale' => 'fr']);

        // Neither ar nor the (odd) fr fallback exist, and no legacy string:
        // it returns the first real value rather than inventing an ar string.
        $this->assertSame('Hello', LocalizedContent::resolve(['en' => 'Hello']));

        // Nothing at all -> null, never a guess.
        $this->assertNull(LocalizedContent::resolve(null));
        $this->assertNull(LocalizedContent::resolve(['en' => '', 'ar' => null]));
    }

    public function test_primary_returns_the_fallback_locale_value_for_legacy_sync(): void
    {
        config(['app.fallback_locale' => 'en']);

        $this->assertSame('Hello', LocalizedContent::primary(['en' => 'Hello', 'ar' => 'مرحبا']));
        $this->assertSame('مرحبا', LocalizedContent::primary(['ar' => 'مرحبا']));
        $this->assertNull(LocalizedContent::primary(null));
    }
}
