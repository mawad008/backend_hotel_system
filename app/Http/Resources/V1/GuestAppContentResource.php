<?php

namespace App\Http\Resources\V1;

use App\Domain\AppContent\Models\GuestAppContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuestAppContent
 *
 * The Guest App's branding + entry content. Served both to the dashboard
 * editor and, anonymously, to the guest app (`GET /guest/app-content`).
 *
 * Text is returned as full `{"en","ar"}` maps (not resolved to the request
 * locale) because the guest app shows it on the entry screens *before and
 * while* the guest picks a language, and switches locale client-side. A
 * `null` field / missing locale means "use the app's bundled default".
 * Storage paths/disks are never exposed — only resolved URLs.
 */
class GuestAppContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'app_name_i18n' => $this->app_name_i18n,
            'logo_url' => $this->logoUrl(),
            'onboarding_image_url' => $this->onboardingImageUrl(),
            // Square icon: web favicon / home-screen icon (native launcher
            // icons are build-time and cannot follow it).
            'app_icon_url' => $this->appIconUrl(),
            // `#RRGGBB` — the app's primary (buttons, accents) colour.
            'primary_color' => $this->primary_color,
            'onboarding_title_i18n' => $this->onboarding_title_i18n,
            'onboarding_body_i18n' => $this->onboarding_body_i18n,
            'onboarding_cta_i18n' => $this->onboarding_cta_i18n,
            // PROFILE_Support FAQ — ordered, both locales per entry.
            'faq' => $this->faq ?? [],
            // PROFILE_Support contact actions; null = that action is hidden.
            'support' => [
                'phone' => $this->support_phone,
                'whatsapp' => $this->support_whatsapp,
                'email' => $this->support_email,
                'website' => $this->website_url,
            ],
            // Linked from sign-in ("by continuing you agree…") and Account.
            'legal' => [
                'terms_url' => $this->terms_url,
                'privacy_url' => $this->privacy_url,
            ],
            'updated_at' => $this->updated_at,
        ];
    }
}
