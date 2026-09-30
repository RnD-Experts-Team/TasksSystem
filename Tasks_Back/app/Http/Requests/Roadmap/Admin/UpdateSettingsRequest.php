<?php

namespace App\Http\Requests\Roadmap\Admin;

use App\Models\Roadmap\Board;
use App\Services\Roadmap\SettingsSchema;
use App\Services\Roadmap\ThemeBuilder;
use Illuminate\Contracts\Validation\Validator;

/**
 * PUT /settings { scope, data }. Rules come from SettingsSchema; unknown keys are dropped by
 * the service. A brand colour that cannot be turned into an accessible theme is rejected here.
 */
class UpdateSettingsRequest extends AdminRequest
{
    public function rules(): array
    {
        $scope = (string) $this->input('scope', SettingsSchema::GLOBAL_SCOPE);

        return [
            'scope' => ['required', 'regex:/^(global|board:[0-9]+)$/'],
        ] + app(SettingsSchema::class)->rules($scope === SettingsSchema::GLOBAL_SCOPE ? $scope : 'board');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $scope = (string) $this->input('scope', SettingsSchema::GLOBAL_SCOPE);

            if (str_starts_with($scope, 'board:')) {
                $exists = Board::query()->whereKey((int) substr($scope, 6))->exists();
                if (! $exists) {
                    $v->errors()->add('scope', 'The selected board does not exist.');
                }
            }

            $primary = $this->input('data.branding.primary');
            if ($primary !== null && ! $v->errors()->has('data.branding.primary')) {
                foreach (app(ThemeBuilder::class)->problems(['primary' => $primary]) as $problem) {
                    $v->errors()->add('data.branding.primary', $problem);
                }
            }
        });
    }
}
