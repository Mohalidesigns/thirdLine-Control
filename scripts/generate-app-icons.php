<?php

/*
 * Renders the PWA / apple-touch icons from the SecondLine brand mark.
 *
 * The mark itself lives in resources/js/Components/ApplicationLogo.jsx as
 * inline SVG; this script re-draws the same geometry with GD so the raster
 * icons never drift from it by hand-editing. Change the mark there, change
 * the numbers here, and re-run:
 *
 *     php scripts/generate-app-icons.php
 *
 * Strokes are STAMPED — a filled disc walked along the path — rather than
 * drawn with imagesetthickness(), whose thick arcs and lines come out
 * crosshatched and have no round caps. Everything is drawn at 4x and
 * resampled down, which is cheaper than special-casing every edge.
 */

const SUPERSAMPLE = 4;

/** The mark occupies this fraction of the icon; the rest is navy padding. */
const MARK_SCALE = 0.72;

$targets = [
    __DIR__.'/../public/icons/icon-192.png' => 192,
    __DIR__.'/../public/icons/icon-512.png' => 512,
];

/** The favicon is read at 16px in a tab, so it gets less padding. */
const FAVICON_SIZE = 32;
const FAVICON_SCALE = 0.88;

/** Colours mirror the --logo-* tokens in resources/css/app.css. */
function colours($im): array
{
    return [
        'bg' => imagecolorallocate($im, 0x1A, 0x36, 0x5D),    // --navy-600
        'outer' => imagecolorallocate($im, 0x5C, 0x75, 0x99), // --logo-ring-outer
        'mid' => imagecolorallocate($im, 0x8F, 0xA6, 0xC4),   // --logo-ring-mid
        'inner' => imagecolorallocate($im, 0xDC, 0xE7, 0xF4), // --logo-ring-inner
        'core' => imagecolorallocate($im, 0x10, 0x22, 0x3A),  // --logo-core
        'check' => imagecolorallocate($im, 0x2F, 0xC4, 0xA8), // --logo-check
    ];
}

/** A round-capped arc stroke, stamped disc by disc. */
function strokeArc($im, float $cx, float $cy, float $r, float $from, float $to, float $width, int $colour): void
{
    $step = max(0.15, rad2deg($width / 4 / max($r, 1)));

    for ($a = $from; $a <= $to; $a += $step) {
        $t = deg2rad($a);
        imagefilledellipse($im, (int) round($cx + $r * cos($t)), (int) round($cy + $r * sin($t)), (int) round($width), (int) round($width), $colour);
    }
}

/** A round-capped straight stroke, stamped the same way. */
function strokeLine($im, float $x1, float $y1, float $x2, float $y2, float $width, int $colour): void
{
    $len = hypot($x2 - $x1, $y2 - $y1);
    $steps = max(1, (int) ceil($len / max($width / 4, 1)));

    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps;
        imagefilledellipse($im, (int) round($x1 + ($x2 - $x1) * $t), (int) round($y1 + ($y2 - $y1) * $t), (int) round($width), (int) round($width), $colour);
    }
}

function render(int $size, float $scale = MARK_SCALE)
{
    $canvas = $size * SUPERSAMPLE;
    $im = imagecreatetruecolor($canvas, $canvas);
    $c = colours($im);

    imagefilledrectangle($im, 0, 0, $canvas, $canvas, $c['bg']);

    // The SVG is authored in a 64-unit box; map that onto the padded canvas.
    $unit = ($canvas * $scale) / 64;
    $origin = ($canvas - $canvas * $scale) / 2;
    $p = fn (float $u) => $origin + $u * $unit;
    $d = fn (float $u) => $u * $unit;

    $cx = $p(32);
    $cy = $p(32);

    // Two broken outer rings. Angles are the SVG dash arcs resolved into
    // absolute degrees: dasharray start (3 o'clock) plus the rotate().
    strokeArc($im, $cx, $cy, $d(29.5), 242, 510, $d(2.2), $c['outer']);
    strokeArc($im, $cx, $cy, $d(25), 28, 294, $d(2.2), $c['mid']);

    // The closed ring as a filled disc; the core then covers its inside.
    imagefilledellipse($im, (int) round($cx), (int) round($cy), (int) round($d(45)), (int) round($d(45)), $c['inner']);
    imagefilledellipse($im, (int) round($cx), (int) round($cy), (int) round($d(34)), (int) round($d(34)), $c['core']);

    $check = [[25, 32.5], [30, 37.5], [40, 26]];
    foreach ([[0, 1], [1, 2]] as [$a, $b]) {
        strokeLine($im, $p($check[$a][0]), $p($check[$a][1]), $p($check[$b][0]), $p($check[$b][1]), $d(4), $c['check']);
    }

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $canvas, $canvas);
    imagedestroy($im);

    return $out;
}

foreach ($targets as $path => $size) {
    $im = render($size);
    imagepng($im, $path);
    imagedestroy($im);
    echo 'wrote '.realpath($path)." ({$size}x{$size})\n";
}

/*
 * The favicon ships as a single PNG inside an ICO container — every browser
 * that matters has read PNG-in-ICO since Vista, and it saves shipping the
 * legacy BMP encoding by hand.
 */
$favicon = __DIR__.'/../public/favicon.ico';
$im = render(FAVICON_SIZE, FAVICON_SCALE);
ob_start();
imagepng($im);
$png = ob_get_clean();
imagedestroy($im);

file_put_contents($favicon, implode('', [
    pack('vvv', 0, 1, 1),                       // reserved, type = icon, one image
    pack('CCCCvvVV',
        FAVICON_SIZE, FAVICON_SIZE,             // width, height
        0, 0,                                   // palette size, reserved
        1, 32,                                  // colour planes, bits per pixel
        strlen($png), 22                        // byte length, offset past the header
    ),
    $png,
]));

echo 'wrote '.realpath($favicon).' ('.FAVICON_SIZE.'x'.FAVICON_SIZE.")\n";
