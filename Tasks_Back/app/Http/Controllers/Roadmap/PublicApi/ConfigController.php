<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Services\Roadmap\SettingsService;
use Illuminate\Http\JsonResponse;

class ConfigController extends PublicApiController
{
    public function __construct(private SettingsService $settings) {}

    /** GET /config: the PublicConfig shape, built by SettingsService::publicConfig(). */
    public function show(): JsonResponse
    {
        return $this->ok($this->settings->publicConfig(), 'Config retrieved successfully');
    }
}
