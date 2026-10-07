<?php

namespace App\Services\Uploads;

use RuntimeException;

/** Сжатие картинок из чата и поддержки: не больше 1600 px по большей стороне, формат WebP. */
class ImageCompressor
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** GIF не пережимаем, чтобы не потерять анимацию. */
    private const COMPRESSIBLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_DIMENSION = 1600;

    private const QUALITY = 80;

    public function isImage(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    public function canCompress(string $mime): bool
    {
        return in_array($mime, self::COMPRESSIBLE_MIMES, true);
    }

    /**
     * @return array{binary: string, ext: string, mime: string, width: int, height: int}
     *
     * @throws RuntimeException картинку не удалось прочитать или сжать
     */
    public function compress(string $binary): array
    {
        // GD cannot decode animated WebP. Preserve it just as we preserve GIF.
        if (strlen($binary) >= 30 && substr($binary, 0, 4) === 'RIFF'
            && substr($binary, 8, 8) === 'WEBPVP8X' && (ord($binary[20]) & 0x02) !== 0) {
            $size = @getimagesizefromstring($binary);
            if ($size === false) {
                throw new RuntimeException('Не удалось прочитать изображение');
            }

            return [
                'binary' => $binary, 'ext' => 'webp', 'mime' => 'image/webp',
                'width' => (int) $size[0], 'height' => (int) $size[1],
            ];
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Не удалось прочитать изображение');
        }

        $scale = min(1, self::MAX_DIMENSION / max(imagesx($image), imagesy($image)));

        if ($scale < 1) {
            $resized = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
            imagedestroy($image);

            if ($resized === false) {
                throw new RuntimeException('Не удалось уменьшить изображение');
            }

            $image = $resized;
        }

        // Прозрачность PNG сохраняется и после перевода палитры в truecolor.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $webp = function_exists('imagewebp');

        ob_start();

        if ($webp) {
            imagewebp($image, null, self::QUALITY);
        } else {
            imagejpeg($image, null, self::QUALITY);
        }

        $output = ob_get_clean();
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        if ($output === false || $output === '') {
            throw new RuntimeException('Не удалось сжать изображение');
        }

        return [
            'binary' => $output,
            'ext' => $webp ? 'webp' : 'jpg',
            'mime' => $webp ? 'image/webp' : 'image/jpeg',
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array{0: int|null, 1: int|null} */
    public function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }
}
