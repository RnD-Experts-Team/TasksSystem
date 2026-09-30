<?php

namespace App\Services\Roadmap;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only branding uploads. Every file is decoded and re-encoded with GD (which drops
 * metadata and any polyglot payload), renamed to a random name and stored on the "public"
 * disk under roadmap/. SVG is never accepted.
 *
 *   logo / logo_dark -> WebP, long side <= 512
 *   favicon          -> PNG 64x64
 *   og               -> WebP, fits inside 1200x630
 */
class AssetService
{
    private const DISK = 'public';

    private const DIR = 'roadmap';

    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private SettingsService $settings) {}

    /** @return array{type: string, path: string, url: string} */
    public function store(string $type, UploadedFile $file): array
    {
        if (! array_key_exists($type, SettingsSchema::ASSET_TYPES)) {
            throw ValidationException::withMessages(['type' => 'Unknown asset type.']);
        }

        $binary = $file->get();
        $info = $binary === false ? false : @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info['mime'] ?? '', self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages(['file' => 'The file must be a PNG, JPEG or WebP image.']);
        }

        [$width, $height] = $info;
        $maxSide = $type === 'favicon' ? 512 : 2000;
        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide) {
            throw ValidationException::withMessages(['file' => "The image must be at most {$maxSide}x{$maxSide} pixels."]);
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw ValidationException::withMessages(['file' => 'The image could not be read.']);
        }

        try {
            [$encoded, $extension] = match ($type) {
                'favicon' => [$this->encodeFavicon($source), 'png'],
                default => [$this->encodeFitted($source, 512, 512, 'webp'), 'webp'],
            };
        } finally {
            imagedestroy($source);
        }

        $path = self::DIR.'/'.Str::lower(Str::random(40)).'.'.$extension;
        $disk = Storage::disk(self::DISK);
        $disk->put($path, $encoded);

        $previous = $this->settings->assetPath($type);
        $this->settings->setAsset($type, $path);
        $this->deleteFile($previous);

        return ['type' => $type, 'path' => $path, 'url' => $this->settings->assetUrl($path)];
    }

    public function delete(string $type): void
    {
        if (! array_key_exists($type, SettingsSchema::ASSET_TYPES)) {
            throw ValidationException::withMessages(['type' => 'Unknown asset type.']);
        }

        $previous = $this->settings->assetPath($type);
        $this->settings->setAsset($type, null);
        $this->deleteFile($previous);
    }

    // ─── Encoding ────────────────────────────────────────────────────

    /** Scale down (never up) to fit inside the box, keeping aspect ratio and alpha. */
    private function encodeFitted(\GdImage $source, int $maxW, int $maxH, string $format): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, $maxW / $w, $maxH / $h);
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $canvas = $this->transparentCanvas($newW, $newH);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $w, $h);

        return $this->encode($canvas, $format);
    }

    /** Square 64x64 PNG, contained (transparent padding), never cropped. */
    private function encodeFavicon(\GdImage $source): string
    {
        $size = 64;
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min($size / $w, $size / $h);
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $canvas = $this->transparentCanvas($size, $size);
        imagecopyresampled($canvas, $source, (int) (($size - $newW) / 2), (int) (($size - $newH) / 2), 0, 0, $newW, $newH, $w, $h);

        return $this->encode($canvas, 'png');
    }

    private function transparentCanvas(int $w, int $h): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    private function encode(\GdImage $image, string $format): string
    {
        ob_start();
        try {
            $format === 'webp' ? imagewebp($image, null, 85) : imagepng($image, null, 6);
            $data = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        return $data;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIR.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
