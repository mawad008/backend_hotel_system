<?php

namespace App\Http\Requests\Api\V1\AppContent;

use App\Domain\AppContent\Models\GuestAppContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PATCH /app-content. Every text field is an optional
 * `{"en","ar"}` map; a blank/omitted locale falls back to the app's bundled
 * copy. Settings (brand colour, contacts, legal links) are plain optional
 * strings; blank clears them. Authorization is enforced in the controller (GuestAppContentPolicy).
 */
class UpdateGuestAppContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $locales = (array) config('app.available_locales', ['en']);

        $limits = [
            'app_name_i18n' => 60,
            'onboarding_title_i18n' => 120,
            'onboarding_body_i18n' => 300,
            'onboarding_cta_i18n' => 40,
        ];

        $rules = [];
        foreach ($limits as $field => $max) {
            $rules[$field] = ['sometimes', 'nullable', 'array:'.implode(',', $locales)];
            foreach ($locales as $locale) {
                $rules["{$field}.{$locale}"] = ['nullable', 'string', 'max:'.$max];
            }
        }

        $rules['faq'] = ['sometimes', 'nullable', 'array', 'max:'.GuestAppContent::FAQ_MAX_ITEMS];
        foreach (['question_i18n' => 200, 'answer_i18n' => 1000] as $field => $max) {
            $rules["faq.*.{$field}"] = ['required', 'array:'.implode(',', $locales)];
            foreach ($locales as $locale) {
                $rules["faq.*.{$field}.{$locale}"] = ['nullable', 'string', 'max:'.$max];
            }
        }

        $phone = ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()\-]{4,28}$/'];
        $url = ['sometimes', 'nullable', 'string', 'max:500', 'url:http,https'];
        $rules['primary_color'] = ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $rules['support_phone'] = $phone;
        $rules['support_whatsapp'] = $phone;
        $rules['support_email'] = ['sometimes', 'nullable', 'string', 'max:190', 'email'];
        $rules['website_url'] = $url;
        $rules['terms_url'] = $url;
        $rules['privacy_url'] = $url;

        return $rules;
    }
}
