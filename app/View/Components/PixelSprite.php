<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * Pixel art drawn from text rows: each character is one pixel, `.` is
 * transparent, any other character must name a CSS class in the palette.
 * Rendered as an SVG with one rect per horizontal run of the same colour.
 */
final class PixelSprite extends Component
{
    /** @var list<array{x: int, y: int, width: int, class: string}> */
    public readonly array $runs;

    public readonly int $width;

    public readonly int $height;

    /**
     * @param  list<string>  $rows
     * @param  array<string, string>  $palette  pixel character => CSS class
     */
    public function __construct(array $rows, array $palette)
    {
        $this->width = strlen($rows[0] ?? '');
        $this->height = count($rows);

        $runs = [];
        foreach ($rows as $y => $row) {
            if (strlen($row) !== $this->width) {
                throw new InvalidArgumentException("Sprite row {$y} is not {$this->width} pixels wide.");
            }
            array_push($runs, ...self::runsOf($row, $y, $palette));
        }
        $this->runs = $runs;
    }

    public function render(): View
    {
        return view('components.pixel-sprite');
    }

    /**
     * @param  array<string, string>  $palette
     * @return list<array{x: int, y: int, width: int, class: string}>
     */
    private static function runsOf(string $row, int $y, array $palette): array
    {
        preg_match_all('/(.)\1*/', $row, $matches, PREG_OFFSET_CAPTURE);

        $runs = [];
        foreach ($matches[0] as [$run, $x]) {
            if ($run[0] === '.') {
                continue;
            }
            if (! isset($palette[$run[0]])) {
                throw new InvalidArgumentException("Sprite pixel '{$run[0]}' has no colour in the palette.");
            }
            $runs[] = ['x' => $x, 'y' => $y, 'width' => strlen($run), 'class' => $palette[$run[0]]];
        }

        return $runs;
    }
}
