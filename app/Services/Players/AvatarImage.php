<?php

namespace App\Services\Players;

use GdImage;
use Illuminate\Validation\ValidationException;

/**
 * Turns the picture an administrator gives a player into an avatar
 * (docs/DECISIONS.md D84): whatever its size, weight and shape, a square
 * PNG within the limits of D53. A photo is turned the way its camera held
 * it, cropped to its centre square, then scaled down until it weighs little
 * enough. Never scaled up: a picture whose square would be under 256 pixels
 * is refused. A PNG that is already a fitting square is kept byte for byte.
 */
final class AvatarImage
{
    /** Longest sides tried in turn, until the PNG fits PlayerAvatars::MAX_SIZE. */
    public const SIDES = [512, 384, 256, 192, 128, 96, 64];

    /**
     * Pixels: the square kept must be at least that wide, or the avatar is a
     * blur on a cabinet's screen. Also what the back office's crop tool
     * starts from and stops at (resources/views/filament/avatar-crop.blade.php).
     */
    public const MIN_SIDE = 256;

    /** Megapixels: beyond that a picture is not decoded at all. */
    public const MAX_PIXELS = 50_000_000;

    private const TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    /**
     * @throws ValidationException
     */
    public static function normalize(string $bytes): string
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || ! in_array($size[2], self::TYPES, true)) {
            throw self::refused(__('A PNG, JPEG or WebP image.'));
        }
        [$width, $height, $type] = $size;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw self::refused(__('This image is too large to be read.'));
        }
        // A photo lying down has the same shorter side once turned.
        if (min($width, $height) < self::MIN_SIDE) {
            throw self::refused(__('The square kept must be at least :side pixels wide: this one is :width x :height.', [
                'side' => self::MIN_SIDE, 'width' => $width, 'height' => $height,
            ]));
        }
        if ($type === IMAGETYPE_PNG && $width === $height && $width <= self::SIDES[0] && strlen($bytes) <= PlayerAvatars::MAX_SIZE * 1024) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw self::refused(__('This image could not be read.'));
        }
        $image = self::upright($image, $type === IMAGETYPE_JPEG ? $bytes : null);
        $side = min(imagesx($image), imagesy($image));
        $left = intdiv(imagesx($image) - $side, 2);
        $top = intdiv(imagesy($image) - $side, 2);

        foreach (self::SIDES as $target) {
            $png = self::square($image, $left, $top, $side, min($side, $target));
            if (strlen($png) <= PlayerAvatars::MAX_SIZE * 1024) {
                return $png;
            }
        }

        throw self::refused(__('This image could not be made small enough.'));
    }

    /** The centre square of `$image`, `$side` pixels wide there, as a PNG of `$target` pixels. */
    private static function square(GdImage $image, int $left, int $top, int $side, int $target): string
    {
        $target = max(1, $target);
        $square = imagecreatetruecolor($target, $target);
        assert($square !== false);
        // A transparent picture stays transparent.
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopyresampled($square, $image, 0, 0, $left, $top, $target, $target, $side, $side);

        ob_start();
        imagepng($square, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * A phone stores its photos lying down, with a note of how they were
     * held (EXIF orientation): turned here, since a PNG carries no such note.
     * Mirrored orientations, which no camera writes, are left as they are.
     */
    private static function upright(GdImage $image, ?string $jpeg): GdImage
    {
        if ($jpeg === null || ! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg));
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        $turned = $angle === 0 ? false : imagerotate($image, $angle, 0);

        return $turned === false ? $image : $turned;
    }

    private static function refused(mixed $message): ValidationException
    {
        return ValidationException::withMessages(['avatar' => (string) $message]);
    }
}
