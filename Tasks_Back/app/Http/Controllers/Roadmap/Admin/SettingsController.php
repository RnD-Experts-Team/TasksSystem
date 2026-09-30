<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\SettingsShowRequest;
use App\Http\Requests\Roadmap\Admin\ThemePreviewRequest;
use App\Http\Requests\Roadmap\Admin\UpdateSettingsRequest;
use App\Http\Requests\Roadmap\Admin\UploadAssetRequest;
use App\Models\Roadmap\Board;
use App\Services\Roadmap\AssetService;
use App\Services\Roadmap\SettingsSchema;
use App\Services\Roadmap\SettingsService;
use Illuminate\Http\JsonResponse;

class SettingsController extends AdminController
{
    public function __construct(
        private SettingsService $settings,
        private AssetService $assets,
    ) {}

    /** GET /settings?scope=global|board:{id} */
    public function show(SettingsShowRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $scope = $request->validated('scope') ?? SettingsSchema::GLOBAL_SCOPE;

            if (! $this->scopeExists($scope)) {
                return $this->notFound('Board not found');
            }

            return $this->ok($this->settings->adminView($scope), 'Settings retrieved successfully');
        });
    }

    /** PUT /settings { scope, data } */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $scope = $request->validated('scope');
            $this->settings->update($scope, $request->validated('data'));

            return $this->ok($this->settings->adminView($scope), 'Settings saved successfully');
        });
    }

    /** POST /settings/asset (multipart: type, file) */
    public function uploadAsset(UploadAssetRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->assets->store($request->validated('type'), $request->file('file')),
            'Image uploaded successfully',
            201
        ));
    }

    public function deleteAsset(string $type): JsonResponse
    {
        return $this->run(function () use ($type) {
            $this->assets->delete($type);

            return $this->ok(null, 'Image removed successfully');
        });
    }

    /** POST /settings/theme-preview { branding } -> PublicTheme (same code as the public config). */
    public function themePreview(ThemePreviewRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->settings->themePreview($request->validated('branding')),
            'Theme generated successfully'
        ));
    }

    private function scopeExists(string $scope): bool
    {
        return $scope === SettingsSchema::GLOBAL_SCOPE
            || Board::query()->whereKey((int) substr($scope, 6))->exists();
    }
}
