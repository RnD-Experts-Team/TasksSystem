<?php

namespace App\Http\Requests\Roadmap\Admin;

use App\Services\Roadmap\ThemeBuilder;
use Illuminate\Contracts\Validation\Validator;

/** POST /settings/theme-preview { branding: {primary, radius, font, default_theme} }. */
class ThemePreviewRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'branding' => 'required|array',
            'branding.primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.radius' => 'sometimes|in:sm,md,lg,xl',
            'branding.font' => 'sometimes|in:outfit,system',
            'branding.default_theme' => 'sometimes|in:system,light,dark',
            'branding.hero_style' => 'sometimes|in:plain,gradient,pattern',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $v->errors()->has('branding.primary')) {
                foreach (app(ThemeBuilder::class)->problems(['primary' => $this->input('branding.primary')]) as $problem) {
                    $v->errors()->add('branding.primary', $problem);
                }
            }
        });
    }
}
