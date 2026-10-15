#!/usr/bin/env php
<?php
// Brand asset generator — draws the MLC mark with GD and writes every
// icon/search-engine/browser format into public_html/:
//
//   assets/favicon-16.png  favicon-32.png  favicon-48.png
//   assets/apple-touch-icon.png  (180x180)
//   assets/icon-192.png  icon-512.png      (PWA / manifest)
//   assets/og-cover.png            (1200x630 social/search card)
//   favicon.ico                    (16+32+48, PNG-compressed entries)
//
// The mark mirrors assets/logo.svg: gradient badge, three player nodes
// connected to a central tabbed block — players trigger, the block verifies.
//
//   php bin/make-icons.php     (requires ext-gd)

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "make-icons requires ext-gd\n");
    exit(1);
}

$OUT = dirname(__DIR__) . '/public_html';

// Brand palette (matches the site's purple→blue gradient).
const C_TOP = [0x93, 0x33, 0xea];   // purple-600
const C_BOT = [0x25, 0x63, 0xeb];   // blue-600
const BG_TOP = [0x1e, 0x1e, 0x2d];
const BG_BOT = [0x1e, 0x3a, 0x5f];

/** Draw the badge+mark at canvas origin. Geometry scales from a 512 box. */
function draw_mark(GdImage $im, int $size): void {
    $s = $size / 512;

    // badge: diagonal gradient rounded rect
    $r = 112 * $s;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $t = ($x + $y) / (2 * $size);
            $c = [C_TOP[0] + (C_BOT[0] - C_TOP[0]) * $t,
                  C_TOP[1] + (C_BOT[1] - C_TOP[1]) * $t,
                  C_TOP[2] + (C_BOT[2] - C_TOP[2]) * $t];
            $inside = ($x >= $r && $x < $size - $r)
                || ($y >= $r && $y < $size - $r)
                || (hypot($x - $r, $y - $r) <= $r)                    // TL
                || (hypot($x - ($size - $r - 1), $y - $r) <= $r)      // TR
                || (hypot($x - $r, $y - ($size - $r - 1)) <= $r)      // BL
                || (hypot($x - ($size - $r - 1), $y - ($size - $r - 1)) <= $r); // BR
            if ($inside) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ...array_map('intval', $c)));
            }
        }
    }

    // simplified geometry under 96px: thicker links, no puzzle tab
    $small = $size < 96;
    $w = ($small ? 34 : 26) * $s;
    $node = ($small ? 46 : 38) * $s;

    $link = imagecolorallocatealpha($im, 255, 255, 255, 64); // ~50% white
    imagesetthickness($im, max(1, (int)$w));
    imageline($im, (int)(256 * $s), (int)(256 * $s), (int)(146 * $s), (int)(146 * $s), $link);
    imageline($im, (int)(256 * $s), (int)(256 * $s), (int)(366 * $s), (int)(146 * $s), $link);
    imageline($im, (int)(256 * $s), (int)(256 * $s), (int)(256 * $s), (int)(388 * $s), $link);

    $white = imagecolorallocate($im, 255, 255, 255);

    // centre logic block (+ puzzle tab on top, skipped at favicon sizes)
    if (!$small) {
        rounded_rect($im, 233 * $s, 177 * $s, 46 * $s, 26 * $s, 9 * $s, $white);
    }
    rounded_rect($im, 197 * $s, 197 * $s, 118 * $s, 118 * $s, 28 * $s, $white);

    // player nodes
    foreach ([[146, 146], [366, 146], [256, 388]] as [$cx, $cy]) {
        imagefilledellipse($im, (int)($cx * $s), (int)($cy * $s), (int)(2 * $node), (int)(2 * $node), $white);
    }
}

/** Filled rounded rectangle (GD has no native one). */
function rounded_rect(GdImage $im, float $x, float $y, float $w, float $h, float $r, int $c): void {
    $x = (int)$x; $y = (int)$y; $w = (int)$w; $h = (int)$h; $r = (int)$r;
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $c);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $c);
    imagefilledellipse($im, $x + $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
}

function canvas(int $w, int $h): GdImage {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    return $im;
}

function render_icon(int $size): string {
    $im = canvas($size, $size);
    draw_mark($im, $size);
    ob_start();
    imagepng($im, null, 9);
    imagedestroy($im);
    return ob_get_clean();
}

// ------------------------------ PNG set ------------------------------
$icons = [
    'assets/favicon-16.png' => 16,
    'assets/favicon-32.png' => 32,
    'assets/favicon-48.png' => 48,
    'assets/apple-touch-icon.png' => 180,
    'assets/icon-192.png' => 192,
    'assets/icon-512.png' => 512,
];
$pngs = [];
foreach ($icons as $path => $size) {
    $pngs[$size] = render_icon($size);
    file_put_contents("$OUT/$path", $pngs[$size]);
    echo "wrote $path ({$size}px)\n";
}

// ------------------------------ favicon.ico ------------------------------
// ICO container with PNG-compressed 16/32/48 entries — valid in all
// browsers and much smaller than BMP entries.
$entries = '';
$offset = 6 + 16 * 3;
$dir = '';
foreach ([16, 32, 48] as $size) {
    $data = $pngs[$size];
    $dir .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($data), $offset);
    $offset += strlen($data);
    $entries .= $data;
}
file_put_contents("$OUT/favicon.ico", pack('vvv', 0, 1, 3) . $dir . $entries);
echo "wrote favicon.ico (16+32+48 PNG entries)\n";

// ------------------------------ og cover 1200x630 ------------------------------
$W = 1200; $H = 630;
$og = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H;
    $c = imagecolorallocate($og,
        (int)(BG_TOP[0] + (BG_BOT[0] - BG_TOP[0]) * $t),
        (int)(BG_TOP[1] + (BG_BOT[1] - BG_TOP[1]) * $t),
        (int)(BG_TOP[2] + (BG_BOT[2] - BG_TOP[2]) * $t));
    imageline($og, 0, $y, $W, $y, $c);
}
// badge centered, 380px mark
$badge = canvas(380, 380);
draw_mark($badge, 380);
imagealphablending($og, true);
imagecopy($og, $badge, (int)(($W - 380) / 2), (int)(($H - 380) / 2), 0, 0, 380, 380);
imagepng($og, "$OUT/assets/og-cover.png", 9);
imagedestroy($og);
imagedestroy($badge);
echo "wrote assets/og-cover.png (1200x630)\n";

echo "done — brand assets in public_html/\n";
