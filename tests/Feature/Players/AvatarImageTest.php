<?php

use App\Services\Players\AvatarImage;
use App\Services\Players\PlayerAvatars;
use Illuminate\Validation\ValidationException;

// The picture an administrator gives a player, made an avatar (docs/DECISIONS.md D84).

/** A picture of flat colours, one per vertical band, in the format asked. */
function bands(int $width, int $height, array $colors, string $format = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);
    $band = intdiv($width, count($colors));
    foreach ($colors as $index => [$red, $green, $blue]) {
        imagefilledrectangle($image, $index * $band, 0, ($index + 1) * $band - 1, $height - 1, imagecolorallocate($image, $red, $green, $blue));
    }
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image, null, 95),
        'webp' => imagewebp($image, null, 95),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}

/** The red, green and blue of a pixel of a PNG. */
function pixelOf(string $png, int $x, int $y): array
{
    $image = imagecreatefromstring($png);
    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$color['red'], $color['green'], $color['blue']];
}

const RED = [220, 30, 30];
const GREEN = [30, 200, 30];
const BLUE = [30, 30, 220];

it('makes a square PNG of 512 pixels of a large photo', function (string $format) {
    $avatar = AvatarImage::normalize(bands(3000, 2000, [RED, GREEN, BLUE], $format));

    expect(getimagesizefromstring($avatar))->toMatchArray([0 => 512, 1 => 512, 2 => IMAGETYPE_PNG])
        ->and(strlen($avatar))->toBeLessThanOrEqual(PlayerAvatars::MAX_SIZE * 1024);
})->with(['jpeg', 'png', 'webp']);

it('keeps the centre of a picture that is not square', function () {
    // 900 x 300: the middle third is green, and is all that is left.
    $wide = AvatarImage::normalize(bands(900, 300, [RED, GREEN, BLUE]));
    expect(getimagesizefromstring($wide))->toMatchArray([0 => 300, 1 => 300]);
    foreach ([[2, 150], [150, 150], [297, 150]] as [$x, $y]) {
        expect(pixelOf($wide, $x, $y))->toBe(GREEN);
    }

    // 300 x 900, three bands of 100 pixels: the square shows the three of them.
    $tall = AvatarImage::normalize(bands(300, 900, [RED, GREEN, BLUE]));
    expect(getimagesizefromstring($tall))->toMatchArray([0 => 300, 1 => 300])
        ->and(pixelOf($tall, 30, 150))->toBe(RED)
        ->and(pixelOf($tall, 150, 150))->toBe(GREEN);
});

it('never scales a small picture up', function () {
    expect(getimagesizefromstring(AvatarImage::normalize(bands(400, 300, [RED, BLUE]))))->toMatchArray([0 => 300, 1 => 300]);
});

it('refuses a picture whose square would be under 256 pixels', function (int $width, int $height) {
    expect(fn () => AvatarImage::normalize(bands($width, $height, [RED, BLUE])))
        ->toThrow(ValidationException::class, 'at least 256 pixels');
})->with([[255, 255], [2000, 200], [120, 900]]);

it('takes a picture of exactly 256 pixels', function () {
    expect(getimagesizefromstring(AvatarImage::normalize(bands(256, 400, [RED, BLUE]))))->toMatchArray([0 => 256, 1 => 256]);
});

it('keeps a PNG that is already a fitting square byte for byte', function () {
    $png = bands(256, 256, [RED, BLUE]);

    expect(AvatarImage::normalize($png))->toBe($png);
});

it('scales a picture further down until it weighs little enough', function () {
    // Noise does not compress: 512 pixels of it weigh far more than the limit.
    $image = imagecreatetruecolor(1200, 1200);
    mt_srand(7);
    for ($y = 0; $y < 1200; $y++) {
        for ($x = 0; $x < 1200; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($image);
    $avatar = AvatarImage::normalize((string) ob_get_clean());

    $size = getimagesizefromstring($avatar);
    expect(strlen($avatar))->toBeLessThanOrEqual(PlayerAvatars::MAX_SIZE * 1024)
        ->and($size[0])->toBe($size[1])
        ->and($size[0])->toBeLessThan(512);
});

it('turns a photo the way its camera held it', function () {
    // 800 x 400, red on the left and blue on the right, held so that the left is the top
    // (EXIF orientation 6): shown, it is 400 x 800 with the red above the blue.
    $jpeg = bands(800, 400, [RED, BLUE], 'jpeg');
    $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, 6, 0).pack('V', 0);
    $exif = "Exif\0\0".$tiff;
    $turned = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

    $avatar = AvatarImage::normalize($turned);

    expect(getimagesizefromstring($avatar))->toMatchArray([0 => 400, 1 => 400]);
    [$red, , $blue] = pixelOf($avatar, 200, 40);
    expect($red)->toBeGreaterThan(150)->and($blue)->toBeLessThan(100);
    [$red, , $blue] = pixelOf($avatar, 200, 360);
    expect($blue)->toBeGreaterThan(150)->and($red)->toBeLessThan(100);
});

it('keeps the transparency of a picture', function () {
    $image = imagecreatetruecolor(800, 600);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);

    $avatar = imagecreatefromstring(AvatarImage::normalize((string) ob_get_clean()));

    expect(imagecolorsforindex($avatar, imagecolorat($avatar, 100, 100))['alpha'])->toBe(127);
});

it('refuses what is no picture, a format it does not read, and a picture too large to decode', function (Closure $bytes) {
    expect(fn () => AvatarImage::normalize($bytes()))->toThrow(ValidationException::class);
})->with([
    'text' => [fn () => 'not a picture'],
    'empty' => [fn () => ''],
    'a GIF' => [function () {
        ob_start();
        imagegif(imagecreatetruecolor(300, 300));

        return (string) ob_get_clean();
    }],
    // Only its header says so: never decoded.
    'a PNG of 20000 x 20000' => [fn () => "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0).pack('N', 0)],
]);
