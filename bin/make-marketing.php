#!/usr/bin/env php
<?php
// Marketing artwork generator — renders REAL constructor block stacks with
// GD: puzzle tabs/sockets, hat blocks, C-shaped control blocks and the same
// hue palette mlc-blocks.js uses. Regenerate after brand/block changes:
//
//   php bin/make-marketing.php          (requires ext-gd)
//
// Output: public_html/assets/marketing/*.png — committed so deploys carry it.
// The stacks mirror the shipped templates (templates/*.ast.json), so what a
// visitor sees on the landing page is what the editor opens from a template.

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

// ---------- canvas / palette ----------

function canvas(?int $w = null, ?int $h = null): GdImage {
    $w ??= W;
    $h ??= H;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    return $im;
}

/** Vertical gradient; returns per-row colours so sockets can carve matching bg. */
function bg(GdImage $im, array $top, array $bot): array {
    $rows = [];
    for ($y = 0; $y < imagesy($im); $y++) {
        $t = $y / imagesy($im);
        $c = imagecolorallocate($im,
            (int)($top[0] + ($bot[0] - $top[0]) * $t),
            (int)($top[1] + ($bot[1] - $top[1]) * $t),
            (int)($top[2] + ($bot[2] - $top[2]) * $t));
        imageline($im, 0, $y, imagesx($im), $y, $c);
        $rows[$y] = $c;
    }
    return $rows;
}

/** Blockly workspace dot grid — the constructor "graph paper". */
function dotgrid(GdImage $im, int $step = 24, int $x0 = 0): void {
    $dot = imagecolorallocatealpha($im, 255, 255, 255, 112);
    for ($y = $step; $y < imagesy($im); $y += $step) {
        for ($x = $x0 + $step; $x < imagesx($im); $x += $step) {
            imagesetpixel($im, $x, $y, $dot);
        }
    }
}

/** HSV→colour resource; Blockly blocks use muted sat/val. */
function hsv(GdImage $im, float $h, float $s = 0.45, float $v = 0.80): int {
    $hh = fmod($h, 360) / 60;
    $c = $v * $s;
    $x = $c * (1 - abs(fmod($hh, 2) - 1));
    $m = $v - $c;
    if ($hh < 1) { $rgb = [$c, $x, 0]; }
    elseif ($hh < 2) { $rgb = [$x, $c, 0]; }
    elseif ($hh < 3) { $rgb = [0, $c, $x]; }
    elseif ($hh < 4) { $rgb = [0, $x, $c]; }
    elseif ($hh < 5) { $rgb = [$x, 0, $c]; }
    else { $rgb = [$c, 0, $x]; }
    return imagecolorallocate($im,
        (int)(($rgb[0] + $m) * 255),
        (int)(($rgb[1] + $m) * 255),
        (int)(($rgb[2] + $m) * 255));
}

function rr(GdImage $im, int $x, int $y, int $w, int $h, int $r, int $c): void {
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $c);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $c);
    imagefilledellipse($im, $x + $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
    imagefilledellipse($im, $x + $w - $r, $y + $h - $r, 2 * $r, 2 * $r, $c);
}

// ---------- block renderer ----------

const BH = 34;        // row height
const TAB = 7;        // tab/socket depth
const SX = 16;        // socket/tab x offset
const SW = 26;        // socket/tab width
const PAD = 13;       // horizontal text padding
const INDENT = 18;    // C-block mouth indent

$FONT = null;
foreach ([
    getenv('MLC_FONT') ?: null,
    'C:/Windows/Fonts/consola.ttf',
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
    '/usr/share/fonts/dejavu/DejaVuSansMono.ttf',
] as $f) {
    if ($f && is_file($f)) { $FONT = $f; break; }
}

function textw(string $s): int {
    global $FONT;
    if ($FONT) {
        $bb = imagettfbbox(12.5, 0, $FONT, $s);
        return (int)ceil($bb[2] - $bb[0]);
    }
    return imagefontwidth(3) * strlen($s);
}

function draw_text(GdImage $im, int $x, int $y, string $s, int $c): void {
    global $FONT;
    if ($FONT) {
        imagettftext($im, 12.5, 0, $x, $y, $c, $FONT, $s);
    } else {
        imagestring($im, 3, $x, $y - 11, $s, $c);
    }
}

/** Measure a parts row: parts = [['t',label]] | [['i',field]] (input pill). */
function measure(array $parts): int {
    $w = PAD;
    foreach ($parts as $i => $p) {
        [$k, $s] = $p;
        $w += textw($s) + ($k === 'i' ? 14 : 0);
        if ($i < count($parts) - 1) { $w += 7; }
    }
    return $w + PAD;
}

function draw_parts(GdImage $im, int $x, int $y, array $parts, int $light): void {
    $white = imagecolorallocate($im, 255, 255, 255);
    $cx = $x + PAD;
    $mid = $y + (int)(BH / 2) + 4;
    foreach ($parts as $p) {
        [$k, $s] = $p;
        if ($k === 'i') {
            $iw = textw($s) + 14;
            rr($im, $cx, $y + 6, $iw, BH - 12, 9, $light);
            draw_text($im, $cx + 7, $mid, $s, $white);
            $cx += $iw;
        } else {
            draw_text($im, $cx, $mid, $s, $white);
            $cx += textw($s);
        }
        $cx += 7;
    }
}

/** Puzzle socket carved into a top edge (filled with $bgc). */
function socket(GdImage $im, int $x, int $y, int $bgc): void {
    imagefilledpolygon($im, [$x + SX, $y, $x + SX + 6, $y + TAB, $x + SX + SW - 6, $y + TAB, $x + SX + SW, $y], $bgc);
}

/** Puzzle tab protruding below a bottom edge (block colour). */
function tab(GdImage $im, int $x, int $y, $c): void {
    imagefilledpolygon($im, [$x + SX, $y, $x + SX + 6, $y + TAB, $x + SX + SW - 6, $y + TAB, $x + SX + SW, $y], $c);
}

/**
 * Render a stack spec. Each item:
 *   ['hat'|'stmt'|'cap', hue, parts]
 *   ['c', hue, parts, innerSpec]          — control block w/ mouth
 * Returns stack height.
 */
function draw_stack(GdImage $im, array $rows, int $x, int $y, array $bgr, int $w = 0): int {
    if ($w === 0) {
        foreach ($rows as $r) { $w = max($w, measure($r[2] ?? [])); }
        $w += 8;
    }
    $cy = $y;
    $n = count($rows);
    foreach ($rows as $i => $row) {
        [$kind, $hue, $parts] = $row;
        $inner = $row[3] ?? null;
        $fill = hsv($im, $hue);
        $light = hsv($im, $hue, 0.30, 0.92);
        $last = $i === $n - 1;
        $bgc = $bgr[min(max($cy + 3, 0), count($bgr) - 1)];
        $sh = imagecolorallocatealpha($im, 0, 0, 0, 100);

        if ($kind === 'c') {
            $innerH = draw_stack($im, $inner, $x + INDENT, $cy + BH, $bgr);
            $footY = $cy + BH + $innerH;
            rr($im, $x + 3, $cy + 4, $w, BH + $innerH + BH, 5, $sh);
            rr($im, $x, $cy, $w, BH, 5, $fill);                                  // header
            imagefilledrectangle($im, $x, $cy + BH, $x + INDENT, $footY, $fill); // mouth rail
            socket($im, $x, $cy, $bgc);
            rr($im, $x, $footY, $w, BH, 5, $fill);                               // footer
            if (!$last) { tab($im, $x, $footY + BH, $fill); }
            draw_parts($im, $x, $cy, $parts, $light);
            $cy = $footY + BH + ($last ? 0 : TAB);
        } else {
            rr($im, $x + 3, $cy + 4, $w, BH, $kind === 'hat' ? 12 : 5, $sh);
            rr($im, $x, $cy, $w, BH, $kind === 'hat' ? 12 : 5, $fill);
            if ($kind === 'hat') {
                imagefilledrectangle($im, $x, $cy + (int)(BH / 2), $x + $w, $cy + BH, $fill);
            } else {
                socket($im, $x, $cy, $bgc);
            }
            if ($kind !== 'cap' && !$last) {
                tab($im, $x, $cy + BH, $fill);
            }
            draw_parts($im, $x, $cy, $parts, $light);
            $cy += BH + (($kind !== 'cap' && !$last) ? TAB : 0);
        }
    }
    return $cy - $y;
}

function save(GdImage $im, string $name): void {
    global $OUT;
    imagepng($im, "$OUT/$name", 9);
    imagedestroy($im);
    echo "wrote $name\n";
}

// palette mirrors mlc-blocks.js hues: event 45, var 300, control 120,
// action 160, comm 200, timer 260, fn 290, anti-cheat 0
const EV = 45; const VRB = 300; const CTL = 120; const ACT = 160;
const COM = 200; const TMR = 260; const FNC = 290; const AC = 0;

// ============ genre-board.png — tic-tac-toe "move" handler ============
$im = canvas(); $rows = bg($im, [0x2d,0x1e,0x4a], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','move']]],
    ['stmt', AC, [['t','if not my turn '],['i','reject']]],
    ['stmt', VRB, [['t','set '],['i','board'],['t',' cell to my '],['i','symbol']]],
    ['c', CTL, [['t','if '],['i','line wins']], [
        ['stmt', VRB, [['t','set '],['i','winner']]],
    ]],
    ['stmt', COM, [['t','send to room '],['i','board'],['t',' '],['i','turn']]],
    ['cap', ACT, [['t','respond '],['i','new board']]],
], 140, 26, $rows);
save($im, 'genre-board.png');

// ============ genre-clicker.png — claim / economy ============
$im = canvas(); $rows = bg($im, [0x2d,0x24,0x1e], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','claim']]],
    ['stmt', AC, [['t','rate limit '],['i','2'],['t',' calls per '],['i','1'],['t',' s']]],
    ['stmt', VRB, [['t','change '],['i','gold'],['t',' by '],['i','harvest']]],
    ['cap', ACT, [['t','respond '],['i','gold']]],
], 140, 55, $rows);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','upgrade']]],
    ['stmt', AC, [['t','consume '],['i','10 x level'],['t',' of '],['i','gold']]],
    ['cap', VRB, [['t','change '],['i','harvest'],['t',' by '],['i','1']]],
], 140, 230, $rows);
save($im, 'genre-clicker.png');

// ============ genre-quiz.png — trivia answer scoring ============
$im = canvas(); $rows = bg($im, [0x1e,0x2d,0x2d], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','answer']]],
    ['c', CTL, [['t','if '],['i','answer'],['t',' = '],['i','correct']], [
        ['stmt', VRB, [['t','change '],['i','score'],['t',' by '],['i','100 - place']]],
        ['stmt', COM, [['t','send to room '],['i','score']]],
    ]],
    ['cap', ACT, [['t','else reject '],['i','wrong answer']]],
], 120, 60, $rows);
save($im, 'genre-quiz.png');

// ============ genre-racing.png — checkpoints in order ============
$im = canvas(); $rows = bg($im, [0x1e,0x2d,0x3f], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','checkpoint']]],
    ['stmt', AC, [['t','require '],['i','id'],['t',' = '],['i','next_cp']]],
    ['stmt', VRB, [['t','set '],['i','next_cp'],['t',' + wrap '],['i','4']]],
    ['c', CTL, [['t','if '],['i','lap'],['t',' >= '],['i','3']], [
        ['stmt', AC, [['t','match end '],['i','winner']]],
    ]],
    ['cap', ACT, [['t','respond '],['i','lap'],['t',' '],['i','next']]],
], 130, 40, $rows);
save($im, 'genre-racing.png');

// ============ genre-cards.png — play card, hand verified ============
$im = canvas(); $rows = bg($im, [0x2a,0x1e,0x3f], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','when player joins']]],
    ['c', CTL, [['t','repeat '],['i','5'],['t',' times']], [
        ['stmt', VRB, [['t','add '],['i','random 1-52'],['t',' to '],['i','hand']]],
    ]],
    ['cap', COM, [['t','send to player '],['i','hand']]],
], 30, 55, $rows);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','play']]],
    ['stmt', AC, [['t','require '],['i','hand'],['t',' contains '],['i','card']]],
    ['stmt', VRB, [['t','remove '],['i','card'],['t',' from '],['i','hand']]],
    ['cap', COM, [['t','send to others '],['i','card_played']]],
], 330, 60, $rows);
save($im, 'genre-cards.png');

// ============ genre-arena.png — shrinking zone timer ============
$im = canvas(); $rows = bg($im, [0x3f,0x1e,0x2a], [0x1e,0x1e,0x2d]); dotgrid($im);
draw_stack($im, [
    ['hat', EV, [['t','every timer '],['i','shrink'],['t',' fires']]],
    ['stmt', VRB, [['t','set '],['i','zone'],['t',' = '],['i','zone x 0.7']]],
    ['stmt', COM, [['t','send to room '],['i','zone'],['t',' '],['i','round']]],
    ['cap', TMR, [['t','schedule timer '],['i','shrink'],['t',' in '],['i','60'],['t',' s']]],
], 30, 55, $rows);
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','move']]],
    ['stmt', AC, [['t','verify move '],['i','x,y'],['t',' max '],['i','10']]],
    ['cap', COM, [['t','send to others '],['i','moved']]],
], 380, 140, $rows);
save($im, 'genre-arena.png');

// ============ hero-constructor.png — mini editor scene ============
// Landing hero: toolbox rail + workspace with an assembled program.
$im = canvas(1200, 630);
$rows = bg($im, [0x1e,0x1e,0x2d], [0x2d,0x1e,0x4a]);
dotgrid($im, 26, 168);
imagefilledrectangle($im, 0, 0, 168, 630, imagecolorallocatealpha($im, 10, 8, 20, 40));
imageline($im, 168, 0, 168, 630, imagecolorallocatealpha($im, 255, 255, 255, 105));
$cats = [['Events', EV], ['Variables', VRB], ['Control', CTL], ['Functions', FNC],
         ['Actions', ACT], ['Messages', COM], ['Timers', TMR], ['Anti-cheat', AC],
         ['Lists', 260], ['Maps', 30], ['Values', 210], ['Math', 230], ['Logic', 190], ['Text', 160]];
$cy = 22;
$white = imagecolorallocate($im, 235, 235, 245);
foreach ($cats as $cat) {
    rr($im, 14, $cy, 10, 10, 3, hsv($im, $cat[1], 0.55, 0.85));
    draw_text($im, 32, $cy + 12, $cat[0], $white);
    $cy += 42;
}
draw_stack($im, [
    ['hat', EV, [['t','when player action '],['i','move']]],
    ['stmt', AC, [['t','assert it is my turn']]],
    ['stmt', VRB, [['t','set '],['i','board'],['t',' cell to '],['i','symbol']]],
    ['c', CTL, [['t','if '],['i','three in a row']], [
        ['stmt', VRB, [['t','set '],['i','winner'],['t',' to '],['i','symbol']]],
        ['stmt', COM, [['t','send to room '],['i','game_over']]],
    ]],
    ['stmt', VRB, [['t','set '],['i','turn'],['t',' to '],['i','1 - turn']]],
    ['cap', ACT, [['t','respond '],['i','ok'],['t',' '],['i','board']]],
], 220, 40, $rows);
draw_stack($im, [
    ['hat', EV, [['t','every timer '],['i','round_end'],['t',' fires']]],
    ['stmt', COM, [['t','send to room '],['i','round ended']]],
    ['cap', AC, [['t','match end '],['i','scores']]],
], 720, 60, $rows);
draw_stack($im, [
    ['stmt', VRB, [['t','set '],['i','gold'],['t',' = '],['i','0']]],
], 720, 300, $rows);
save($im, 'hero-constructor.png');

echo "done — marketing assets in public_html/assets/marketing/\n";
