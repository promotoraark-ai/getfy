<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BrandFavicon
{
    public static function sourcePath(): ?string
    {
        $configured = config('getfy.favicon_url');
        if (! is_string($configured) || $configured === '' || ! str_starts_with($configured, '/')) {
            return null;
        }

        $path = public_path(ltrim($configured, '/'));

        return is_file($path) ? $path : null;
    }

    public static function optimizedPath(): ?string
    {
        $source = self::sourcePath();
        if ($source === null) {
            return null;
        }

        $cacheDir = storage_path('app/cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cache = $cacheDir.DIRECTORY_SEPARATOR.'brand-favicon-32.png';
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        if (! extension_loaded('gd')) {
            return $source;
        }

        $image = @imagecreatefrompng($source);
        if ($image === false) {
            return $source;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);

            return $source;
        }

        $size = 32;
        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            imagedestroy($image);

            return $source;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $size, $size, $width, $height);
        imagepng($canvas, $cache, 6);
        imagedestroy($image);
        imagedestroy($canvas);

        return is_file($cache) ? $cache : $source;
    }

    public static function publicUrl(): string
    {
        if (self::sourcePath() !== null) {
            return route('brand.favicon', ['v' => filemtime(self::sourcePath())]);
        }

        return 'https://cdn.getfy.cloud/collapsed-logo.png';
    }

    public static function serve(): SymfonyResponse
    {
        $path = self::optimizedPath() ?? self::sourcePath();
        if ($path === null) {
            return redirect('https://cdn.getfy.cloud/collapsed-logo.png', 302);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
