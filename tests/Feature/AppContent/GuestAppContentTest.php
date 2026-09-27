<?php

namespace Tests\Feature\AppContent;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestAppContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_guest_read_is_anonymous_and_empty_until_configured(): void
    {
        $this->getJson('/api/v1/guest/app-content')
            ->assertOk()
            ->assertJsonPath('data.app_name_i18n', null)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.onboarding_image_url', null)
            ->assertJsonPath('data.onboarding_title_i18n', null);
    }

    public function test_owner_updates_text_and_guest_sees_both_locales(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->patchJson('/api/v1/app-content', [
            'app_name_i18n' => ['en' => ' Oasis Hotels ', 'ar' => 'فنادق الواحة'],
            'onboarding_title_i18n' => ['en' => 'Welcome', 'ar' => 'أهلاً بك'],
            'onboarding_body_i18n' => ['en' => '', 'ar' => 'إقامة مريحة'],
        ])->assertOk()->assertJsonPath('data.app_name_i18n.en', 'Oasis Hotels');

        $this->getJson('/api/v1/guest/app-content')
            ->assertOk()
            ->assertJsonPath('data.app_name_i18n.ar', 'فنادق الواحة')
            ->assertJsonPath('data.onboarding_title_i18n.en', 'Welcome')
            // Blank locale is dropped, never stored as an empty string.
            ->assertJsonPath('data.onboarding_body_i18n', ['ar' => 'إقامة مريحة'])
            ->assertJsonPath('data.onboarding_cta_i18n', null);

        $this->assertSame(1, GuestAppContent::count());
        $this->assertTrue(AuditLog::where('action', 'guest-app-content.updated')->exists());
    }

    public function test_all_blank_map_resets_field_to_default(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/app-content', [
            'onboarding_cta_i18n' => ['en' => 'Start', 'ar' => 'ابدأ'],
        ])->assertOk();

        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/app-content', [
            'onboarding_cta_i18n' => ['en' => '', 'ar' => null],
        ])->assertOk()->assertJsonPath('data.onboarding_cta_i18n', null);
    }

    public function test_text_validation_rejects_unknown_locale_and_overlong_values(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->patchJson('/api/v1/app-content', [
            'app_name_i18n' => ['fr' => 'Bonjour'],
            'onboarding_cta_i18n' => ['en' => str_repeat('x', 41)],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['app_name_i18n', 'onboarding_cta_i18n.en']);
    }

    public function test_owner_uploads_and_replaces_images_served_by_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'logo', 'image' => UploadedFile::fake()->image('a.png', 200, 200),
        ])->assertOk()->assertJsonMissingPath('data.logo_path');

        $first = GuestAppContent::firstOrFail()->logo_path;
        Storage::disk('public')->assertExists($first);
        $this->assertStringStartsWith('guest-app/logo/', $first);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'logo', 'image' => UploadedFile::fake()->image('b.png', 200, 200),
        ])->assertOk();

        $second = GuestAppContent::firstOrFail()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'onboarding_image', 'image' => UploadedFile::fake()->image('hero.jpg', 800, 1600),
        ])->assertOk();

        $res = $this->getJson('/api/v1/guest/app-content')->assertOk();
        $this->assertStringContainsString($second, (string) $res->json('data.logo_url'));
        $this->assertNotNull($res->json('data.onboarding_image_url'));
    }

    public function test_owner_removes_an_image(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'onboarding_image', 'image' => UploadedFile::fake()->image('hero.jpg'),
        ])->assertOk();
        $path = GuestAppContent::firstOrFail()->onboarding_image_path;

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/app-content/images/onboarding_image')
            ->assertOk()->assertJsonPath('data.onboarding_image_url', null);
        Storage::disk('public')->assertMissing($path);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/app-content/images/cover')
            ->assertNotFound();
    }

    public function test_upload_rejects_non_images_and_unknown_slots(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'banner', 'image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['slot', 'image']);
    }

    public function test_staff_without_permission_is_forbidden(): void
    {
        $manager = User::factory()->hotelManager()->create();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/app-content')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson('/api/v1/app-content', [
            'app_name_i18n' => ['en' => 'X'],
        ])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/app-content/images', [
            'slot' => 'logo', 'image' => UploadedFile::fake()->image('a.png'),
        ])->assertForbidden();
    }

    public function test_editor_requires_authentication(): void
    {
        $this->getJson('/api/v1/app-content')->assertUnauthorized();
    }

    public function test_owner_manages_the_faq_and_guests_read_it_in_order(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/app-content', [
            'faq' => [
                ['question_i18n' => ['ar' => 'متى الدخول؟', 'en' => 'When is check-in?'], 'answer_i18n' => ['ar' => 'من الساعة ٣', 'en' => 'From 3 pm']],
                // Incomplete — dropped.
                ['question_i18n' => ['en' => 'Only a question'], 'answer_i18n' => ['en' => '']],
                ['question_i18n' => ['en' => 'Parking?'], 'answer_i18n' => ['en' => 'Free on site']],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/guest/app-content')
            ->assertOk()
            ->assertJsonCount(2, 'data.faq')
            ->assertJsonPath('data.faq.0.question_i18n.ar', 'متى الدخول؟')
            ->assertJsonPath('data.faq.1.answer_i18n.en', 'Free on site');

        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/app-content', [
            'faq' => array_fill(0, 21, ['question_i18n' => ['en' => 'q'], 'answer_i18n' => ['en' => 'a']]),
        ])->assertStatus(422)->assertJsonValidationErrors('faq');

        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/app-content', ['faq' => []])->assertOk();
        $this->getJson('/api/v1/guest/app-content')->assertJsonCount(0, 'data.faq');
    }
}
