<?php
declare(strict_types=1);

final class CardPreview
{
    public static function create(string $source): void
    {
        if (!function_exists('imagewebp')) return;
        $image = @imagecreatefromjpeg($source);
        if (!$image) return;
        try {
            foreach ([480, 800] as $width) {
                $target = $source . '.' . $width . '.webp';
                if (is_file($target)) continue;
                $w = min($width, imagesx($image));
                $h = max(1, (int) round(imagesy($image) * $w / imagesx($image)));
                $preview = imagescale($image, $w, $h, IMG_BICUBIC_FIXED);
                if (!$preview) continue;
                $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (imagewebp($preview, $temporary, 74)) {
                    chmod($temporary, 0644);
                    rename($temporary, $target);
                }
                imagedestroy($preview);
            }
        } finally { imagedestroy($image); }
    }
}
