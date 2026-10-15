#!/usr/bin/env php
<?php
// Marketing artwork generator — draws the landing page's genre cards and
// banners with GD. Re-run any time the brand or genres change:
//
//   php bin/make-marketing.php          (requires ext-gd)
//
// Output: public_html/assets/marketing/*.png — committed so deploys carry it.

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "make-marketing requires ext-gd\n");
    exit(1);
}

$OUT = dirname(__DIR__) . '/public_html/assets/marketing';
if (!is_dir($OUT)) {
    mkdir($OUT, 0775, true);
}

const W = 640;
const H = 400;

function canvas(int $w = W, int $h = H): GdImage {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    return $im;
}

function bg(GdImage $im, array $top, array $bot): void {
    for ($y = 0; $y < H; $y++) {
        $t = $y / H;
        $c = imagecolorallocate($im,
            (int)($top[0] + ($bot[0] - $top[0]) * $t),
            (int)($top[1] + ($bot[1] - $top[1]) * $t),
            (int)($top[2] + ($bot[2] - $top[2]) * $t));
        imageline($im, 0, $y, W, $y, $c);
    }
}

function rr(GdImage $im, int $x, int $y, int $w, int $h, int $r, int $c): void {
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $c);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $c);
    imagefilledellipse($im, $x + $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
}

function ring(GdImage $im, int $cx, int $cy, int $r, int $thick, int $c): void {
    for ($i = 0; $i < $thick; $i++) {
        imageellipse($im, $cx, $cy, 2 * ($r - $i), 2 * ($r - $i), $c);
    }
}

function save(GdImage $im, string $name): void {
    global $OUT;
    imagepng($im, "$OUT/$name", 9);
    imagedestroy($im);
    echo "wrote $name\n";
}

$white  = fn($im) => imagecolorallocate($im, 255, 255, 255);
$faint  = fn($im) => imagecolorallocatealpha($im, 255, 255, 255, 90);
$dim    = fn($im) => imagecolorallocatealpha($im, 255, 255, 255, 70);
$accent = fn($im) => imagecolorallocate($im, 192, 132, 252); // purple-400
$gold   = fn($im) => imagecolorallocate($im, 251, 191, 36);  // amber-400
$green  = fn($im) => imagecolorallocate($im, 74, 222, 128);  // green-400
$rose   = fn($im) => imagecolorallocate($im, 251, 113, 133); // rose-400
$sky    = fn($im) => imagecolorallocate($im, 96, 165, 250);  // blue-400

// ============ genre-board.png — turn-based board game ============
$im = canvas(); bg($im, [0x2d,0x1e,0x4a], [0x1e,0x1e,0x2d]);
$gx = 170; $gy = 50; $cell = 100; $w = $white($im);
imagesetthickness($im, 6);
for ($i = 1; $i < 3; $i++) {
    imageline($im, $gx + $i*$cell, $gy, $gx + $i*$cell, $gy + 3*$cell, $dim($im));
    imageline($im, $gx, $gy + $i*$cell, $gx + 3*$cell, $gy + $i*$cell, $dim($im));
}
// X (two strokes) in cells (0,0) and (2,2); O in (1,1) and (0,2)
imagesetthickness($im, 14);
foreach ([[0,0],[2,2]] as [$cx,$cy]) {
    $x0 = $gx + $cx*$cell + 22; $y0 = $gy + $cy*$cell + 22;
    imageline($im, $x0, $y0, $x0+56, $y0+56, $accent($im));
    imageline($im, $x0+56, $y0, $x0, $y0+56, $accent($im));
}
foreach ([[1,1],[0,2]] as [$cx,$cy]) {
    ring($im, $gx + $cx*$cell + 50, $gy + $cy*$cell + 50, 30, 14, $sky($im));
}
// winning diagonal
imagesetthickness($im, 8);
imageline($im, $gx-10, $gy-10, $gx + 3*$cell+10, $gy + 3*$cell+10, $green($im));
save($im, 'genre-board.png');

// ============ genre-racing.png — live positions ============
$im = canvas(); bg($im, [0x1e,0x2d,0x3f], [0x1e,0x1e,0x2d]);
ring($im, 320, 210, 150, 40, $dim($im));          // track
ring($im, 320, 210, 150, 2, $faint($im));
// racers on the track
$dots = [[150, 60, $gold], [-35, 140, $rose], [205, 115, $sky], [-150, -95, $green]];
foreach ($dots as [$dx, $dy, $cf]) {
    imagefilledellipse($im, 320 + $dx, 210 + $dy, 34, 34, $cf($im));
}
save($im, 'genre-racing.png');

// ============ genre-clicker.png — shared economy / idle ============
$im = canvas(); bg($im, [0x2d,0x24,0x1e], [0x1e,0x1e,0x2d]);
imagefilledellipse($im, 320, 195, 170, 170, $gold($im));
ring($im, 320, 195, 85, 4, imagecolorallocatealpha($im, 0,0,0,100));
$orbit = [[120,90,18], [520,100,24], [110,290,14], [530,300,20], [420,340,12], [210,330,16]];
foreach ($orbit as [$cx,$cy,$r]) {
    imagefilledellipse($im, $cx, $cy, 2*$r, 2*$r, $faint($im));
}
save($im, 'genre-clicker.png');

// ============ genre-quiz.png — timed trivia ============
$im = canvas(); bg($im, [0x1e,0x2d,0x2d], [0x1e,0x1e,0x2d]);
rr($im, 120, 40, 400, 56, 14, $dim($im));                       // question
$opts = [[110, $dim], [130, $green], [150, $dim], [170, $rose]]; // answers, 2nd right, 4th wrong
$y = 130;
foreach ($opts as [$x, $cf]) {
    rr($im, $x, $y, 420, 52, 12, $cf($im));
    $y += 64;
}
save($im, 'genre-quiz.png');

// ============ genre-cards.png — card battler ============
$im = canvas(); bg($im, [0x2a,0x1e,0x3f], [0x1e,0x1e,0x2d]);
$card = canvas(110, 160);
imagefill($card, 0, 0, imagecolorallocatealpha($card, 0,0,0,127));
rr($card, 0, 0, 108, 158, 12, imagecolorallocate($card, 245, 245, 250));
rr($card, 8, 8, 92, 70, 8, imagecolorallocate($card, 147, 51, 234));
rr($card, 8, 120, 60, 22, 5, imagecolorallocate($card, 226, 226, 232));
foreach ([-16, -8, 0, 8, 16] as $i => $deg) {
    $rot = imagerotate($card, $deg, imagecolorallocatealpha($card, 0,0,0,127));
    imagesavealpha($rot, true);
    $cw = imagesx($rot); $ch = imagesy($rot);
    imagecopy($im, $rot, (int)(140 + $i * 92 - $cw/2 + 55), (int)(200 - $ch/2 + abs($deg) * 2), 0, 0, $cw, $ch);
    imagedestroy($rot);
}
imagedestroy($card);
save($im, 'genre-cards.png');

// ============ genre-arena.png — battle royale / zones ============
$im = canvas(); bg($im, [0x3f,0x1e,0x2a], [0x1e,0x1e,0x2d]);
ring($im, 320, 200, 170, 3, $dim($im));   // outer zone
ring($im, 320, 200, 110, 4, $rose($im));  // shrinking zone
ring($im, 320, 200, 55, 4, $gold($im));   // final zone
$players = [[200,140],[450,160],[180,290],[470,280],[300,80],[380,330],[240,220],[330,170]];
foreach ($players as $i => [$px,$py]) {
    imagefilledellipse($im, $px, $py, 16, 16, $i < 4 ? $sky($im) : $faint($im));
}
imagefilledellipse($im, 320, 200, 22, 22, $white($im)); // you
save($im, 'genre-arena.png');

echo "done — marketing assets in public_html/assets/marketing/\n";
