<?php

namespace App\Domain\AppContent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The Guest App's dashboard-managed branding + entry content: the app name
 * (app title + splash wordmark), the logo (splash + onboarding avatar), the
 * app icon (web favicon / home-screen icon), the brand colour, the
 * onboarding photo / headline / body / call-to-action, the support contacts
 * and the legal (terms / privacy) links.
 *
 * A singleton row (see GuestAppContentRepository::current()). Every field is
 * nullable: an unset field means "use the app's bundled default", never a
 * fabricated value.
 */
class GuestAppContent extends Model
{
    public const IMAGE_LOGO = 'logo';

    public const IMAGE_ONBOARDING = 'onboarding_image';

    public const IMAGE_APP_ICON = 'app_icon';

    /** @var list<string> */
    public const IMAGE_SLOTS = [self::IMAGE_LOGO, self::IMAGE_ONBOARDING, self::IMAGE_APP_ICON];

    /**
     * Plain (not per-locale) settings: brand colour, support contacts and
     * legal links. Blank = null = hidden / app default.
     *
     * @var list<string>
     */
    public const SETTING_FIELDS = [
        'primary_color',
        'support_phone',
        'support_whatsapp',
        'support_email',
        'website_url',
        'terms_url',
        'privacy_url',
    ];

    /** @var list<string> */
    public const TEXT_FIELDS = [
        'app_name_i18n',
        'onboarding_title_i18n',
        'onboarding_body_i18n',
        'onboarding_cta_i18n',
    ];

    protected $fillable = [
        'app_name_i18n',
        'onboarding_title_i18n',
        'onboarding_body_i18n',
        'onboarding_cta_i18n',
        'logo_disk',
        'logo_path',
        'onboarding_image_disk',
        'onboarding_image_path',
        'app_icon_disk',
        'app_icon_path',
        'primary_color',
        'support_phone',
        'support_whatsapp',
        'support_email',
        'website_url',
        'terms_url',
        'privacy_url',
        'faq',
    ];

    /** Upper bound on FAQ entries (keeps the support screen scannable). */
    public const FAQ_MAX_ITEMS = 20;

    protected function casts(): array
    {
        return [
            'faq' => 'array',
            'app_name_i18n' => 'array',
            'onboarding_title_i18n' => 'array',
            'onboarding_body_i18n' => 'array',
            'onboarding_cta_i18n' => 'array',
        ];
    }

    public function logoUrl(): ?string
    {
        return $this->imageUrl(self::IMAGE_LOGO);
    }

    public function onboardingImageUrl(): ?string
    {
        return $this->imageUrl(self::IMAGE_ONBOARDING);
    }

    public function appIconUrl(): ?string
    {
        return $this->imageUrl(self::IMAGE_APP_ICON);
    }

    /**
     * Public URL for an image slot, derived on read from the disk recorded
     * with it — so a later disk switch never orphans an existing file.
     */
    public function imageUrl(string $slot): ?string
    {
        $path = $this->getAttribute("{$slot}_path");
        $disk = $this->getAttribute("{$slot}_disk");

        if (! is_string($path) || $path === '' || ! is_string($disk) || $disk === '') {
            return null;
        }

        return Storage::disk($disk)->url($path);
    }
}
