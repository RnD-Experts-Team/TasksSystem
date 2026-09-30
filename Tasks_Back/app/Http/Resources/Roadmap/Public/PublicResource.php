<?php

namespace App\Http\Resources\Roadmap\Public;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base of every public resource. Subclasses list their keys by hand in toArray():
 * a model is NEVER serialised wholesale, so a new column can never leak by accident.
 */
abstract class PublicResource extends JsonResource
{
    /** ISO-8601 UTC with a trailing Z. */
    protected function iso(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601ZuluString();
    }

    /** SPA path of a post: /roadmap/{board}/p/{number}-{slug} */
    public static function postPath(string $boardSlug, int $number, string $slug): string
    {
        return '/roadmap/'.$boardSlug.'/p/'.$number.'-'.$slug;
    }
}
