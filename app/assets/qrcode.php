<?php
// QR code encoder, loosely based on code found at:
// https://www.nayuki.io/page/qr-code-generator-library

// level-M parameters per version (index = version, 0 = padding)
const QR_ECCW = [-1,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26];
const QR_NBLK = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9,10,10,11,13,14,16];
// byte-mode capacity of version 20 at level M
const QR_MAX_BYTES = 666;

// modules of a version's grid, minus the function patterns
function qr_raw_modules(int $ver): int {
    $r = (16 * $ver + 128) * $ver + 64;
    if($ver >= 2) {
        $na = intdiv($ver, 7) + 2;
        // alignment patterns
        $r -= (25 * $na - 10) * $na - 55;
        // version information
        if($ver >= 7) $r -= 36;
    }
    return $r;
}

function qr_data_codewords(int $ver): int {
    return intdiv(qr_raw_modules($ver), 8) - QR_ECCW[$ver] * QR_NBLK[$ver];
}

// Reed–Solomon over GF(256), modulus 0x11D ("peasant" multiply)
function qr_rs_mul(int $x, int $y): int {
    $z = 0;
    for($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D));
        $z = $z ^ ((($y >> $i) & 1) * $x);
    }
    return $z;
}

function qr_rs_divisor(int $degree): array {
    $r = array_fill(0, $degree - 1, 0);
    $r[] = 1;
    $root = 1;
    for($i = 0; $i < $degree; $i++) {
        for($j = 0; $j < count($r); $j++) {
            $r[$j] = qr_rs_mul($r[$j], $root);
            if($j + 1 < count($r)) $r[$j] = $r[$j] ^ $r[$j + 1];
        }
        $root = qr_rs_mul($root, 2);
    }
    return $r;
}

function qr_rs_remainder(array $data, array $divisor): array {
    $r = array_fill(0, count($divisor), 0);
    foreach($data as $b) {
        $factor = $b ^ array_shift($r);
        $r[] = 0;
        for($i = 0; $i < count($divisor); $i++)
            $r[$i] = $r[$i] ^ qr_rs_mul($divisor[$i], $factor);
    }
    return $r;
}

// applies mask pattern $msk to the data modules (function modules are skipped)
// returns the modified matrix - pass the result back in
function qr_apply_mask(array $mods, array $fn, int $msk): array {
    $size = count($mods);
    for($y = 0; $y < $size; $y++) {
        for($x = 0; $x < $size; $x++) {
            switch($msk) {
                case 0: $inv = ($x + $y) % 2 == 0; break;
                case 1: $inv = $y % 2 == 0; break;
                case 2: $inv = $x % 3 == 0; break;
                case 3: $inv = ($x + $y) % 3 == 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 == 0; break;
                case 5: $inv = (($x * $y) % 2 + ($x * $y) % 3) == 0; break;
                case 6: $inv = ((($x * $y) % 2 + ($x * $y) % 3) % 2) == 0; break;
                default: $inv = ((($x + $y) % 2) + ($x * $y) % 3) % 2 == 0; break;
            }
            if(!$fn[$y][$x] && $inv)
                $mods[$y][$x] = !$mods[$y][$x];
        }
    }
    return $mods;
}

// the spec's four penalty rules: long runs, finder-like patterns, 2×2 blocks, dark/light balance
// lower == better
function qr_penalty(array $mods): int {
    $size = count($mods);
    $result = 0;
    $hist_add = function(int $run, array &$h) use ($size): void {
        // implicit light border before a line
        if($h[0] == 0) $run += $size;
        array_pop($h);
        array_unshift($h, $run);
    };
    $hist_count = function(array $h): int {
        $n = $h[1];
        // 1:1:3:1:1 around the centre
        $core = $n > 0 && $h[2] == $n && $h[3] == $n * 3 && $h[4] == $n && $h[5] == $n;
        return (($core && $h[0] >= $n * 4 && $h[6] >= $n) ? 1 : 0) + (($core && $h[6] >= $n * 4 && $h[0] >= $n) ? 1 : 0);
    };
    $scan_line = function(callable $get) use ($size, $hist_add, $hist_count, &$result): void {
        $run_color = false; $run = 0;
        $h = [0, 0, 0, 0, 0, 0, 0];
        for($i = 0; $i < $size; $i++) {
            if($get($i) == $run_color) {
                $run++;
                // rule 1
                if($run == 5) $result += 3;
                elseif($run > 5) $result += 1;
            } else {
                $hist_add($run, $h);
                // rule 2
                if(!$run_color) $result += $hist_count($h) * 40;
                $run_color = $get($i);
                $run = 1;
            }
        }
        if($run_color) {
            $hist_add($run, $h);
            $run = 0;
        }
        $run += $size;
        $hist_add($run, $h);
        $result += $hist_count($h) * 40;
    };
    for($y = 0; $y < $size; $y++) $scan_line(function($x) use ($mods, $y) { return $mods[$y][$x]; });
    for($x = 0; $x < $size; $x++) $scan_line(function($y) use ($mods, $x) { return $mods[$y][$x]; });
    // rule 3
    for($y = 0; $y < $size - 1; $y++) {
        for($x = 0; $x < $size - 1; $x++) {
            $c = $mods[$y][$x];
            if($c == $mods[$y][$x + 1] && $c == $mods[$y + 1][$x]
               && $c == $mods[$y + 1][$x + 1]) $result += 3;
        }
    }
    // rule 4
    $dark = 0;
    foreach($mods as $row) foreach($row as $m) if($m) $dark++;
    $total = $size * $size;
    $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
    return $result + $k * 10;
}

// encodes $text (as raw bytes) and returns the module matrix: $size rows of $size booleans (true == dark)
// throws when the text does not fit
function qr_encode(string $text): array {

    $bytes = array_values(unpack("C*", $text) ?: []);
    if(count($bytes) > QR_MAX_BYTES)
        throw new ValueError("qr_encode: input longer than " . QR_MAX_BYTES . " bytes");

    // smallest version the data fits into
    $ver = 0;
    for($v = 1; $v <= 20; $v++) {
        // mode+count+data
        $need = 4 + ($v <= 9 ? 8 : 16) + count($bytes) * 8;
        if($need <= qr_data_codewords($v) * 8) { $ver = $v; break; }
    }
    if($ver == 0) {
        // covered by QR_MAX_BYTES
        throw new ValueError("qr_encode: data too long");
    }

    // bitstream: mode 0100 (byte), length, payload, terminator, byte align, then the alternating 0xEC/0x11 padding up to the capacity
    $cap = qr_data_codewords($ver) * 8;
    $bb = [];
    $ab = function(int $val, int $len) use (&$bb): void {
        for($i = $len - 1; $i >= 0; $i--) $bb[] = ($val >> $i) & 1;
    };
    $ab(4, 4);
    $ab(count($bytes), $ver <= 9 ? 8 : 16);
    foreach($bytes as $b) $ab($b, 8);
    $ab(0, min(4, $cap - count($bb)));
    $ab(0, (8 - (count($bb) % 8)) % 8);
    for($pad = 0xEC; count($bb) < $cap; $pad ^= 0xEC ^ 0x11) $ab($pad, 8);
    $data = [];
    while(count($data) * 8 < count($bb)) $data[] = 0;
    for($i = 0; $i < count($bb); $i++)
        $data[$i >> 3] = $data[$i >> 3] | ($bb[$i] << (7 - ($i & 7)));

    // grid + function-pattern bookkeeping
    $size = $ver * 4 + 17;
    $mods = []; $fn = [];
    for($y = 0; $y < $size; $y++) {
        $mods[] = array_fill(0, $size, false);
        $fn[]   = array_fill(0, $size, false);
    }
    $set = function(int $x, int $y, bool $dark) use (&$mods, &$fn): void {
        $mods[$y][$x] = $dark;
        $fn[$y][$x] = true;
    };

    // timing patterns
    for($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 == 0);
        $set($i, 6, $i % 2 == 0);
    }
    foreach([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$fx, $fy]) {
        // finders + separators
        for($dy = -4; $dy <= 4; $dy++) {
            for($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $fx + $dx; $yy = $fy + $dy;
                if($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size)
                    $set($xx, $yy, $dist != 2 && $dist != 4);
            }
        }
    }
    // alignment patterns
    if($ver > 1) {
        $num  = intdiv($ver, 7) + 2;
        $step = intdiv($ver * 8 + $num * 3 + 5, $num * 4 - 4) * 2;
        $ap = [6];
        for($pos = $size - 7; count($ap) < $num; $pos -= $step)
            array_splice($ap, 1, 0, $pos);
        for($i = 0; $i < count($ap); $i++) {
            for($j = 0; $j < count($ap); $j++) {
                // finders
                if(($i == 0 && $j == 0) || ($i == 0 && $j == count($ap) - 1) || ($i == count($ap) - 1 && $j == 0))
                    continue;
                for($dy = -2; $dy <= 2; $dy++) {
                    for($dx = -2; $dx <= 2; $dx++)
                        $set($ap[$i] + $dx, $ap[$j] + $dy, max(abs($dx), abs($dy)) != 1);
                }
            }
        }
    }

    // format information - redrawn for every mask candidate below
    // level M: the two ECC bits are 00, so the 5 data bits are just the mask number
    $draw_format = function(int $msk) use ($set, $size): void {
        $rem = $msk;
        for($i = 0; $i < 10; $i++)
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($msk << 10) | $rem) ^ 0x5412;
        for($i = 0; $i <= 5; $i++) $set(8, $i, (($bits >> $i) & 1) != 0);
        $set(8, 7, (($bits >> 6) & 1) != 0);
        $set(8, 8, (($bits >> 7) & 1) != 0);
        $set(7, 8, (($bits >> 8) & 1) != 0);
        for($i = 9; $i < 15; $i++) $set(14 - $i, 8, (($bits >> $i) & 1) != 0);
        for($i = 0; $i < 8; $i++)  $set($size - 1 - $i, 8, (($bits >> $i) & 1) != 0);
        for($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, (($bits >> $i) & 1) != 0);
        // the always-dark module
        $set(8, $size - 8, true);
    };
    $draw_format(0);

    // version information
    if($ver >= 7) {
        $rem = $ver;
        for($i = 0; $i < 12; $i++)
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($ver << 12) | $rem;
        for($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) != 0;
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $set($a, $b, $dark);
            $set($b, $a, $dark);
        }
    }

    // error correction:
    // split the data codewords into blocks, give each its RS checksum, interleave everything (skipping the padding byte of the short blocks)
    $numblk = QR_NBLK[$ver];
    $ecclen = QR_ECCW[$ver];
    $rawcw  = intdiv(qr_raw_modules($ver), 8);
    $nshort = $numblk - ($rawcw % $numblk);
    $blklen = intdiv($rawcw, $numblk);
    $div    = qr_rs_divisor($ecclen);
    $blocks = [];
    $k = 0;
    for($bi = 0; $bi < $numblk; $bi++) {
        $dl  = $blklen - $ecclen + ($bi < $nshort ? 0 : 1);
        // the packed data codewords
        $dat = array_slice($data, $k, $dl);
        $k += $dl;
        $ecc = qr_rs_remainder($dat, $div);
        if($bi < $nshort) $dat[] = 0;
        $blocks[] = array_merge($dat, $ecc);
    }
    $all = [];
    for($i = 0; $i < count($blocks[0]); $i++) {
        for($j = 0; $j < $numblk; $j++) {
            if($i != $blklen - $ecclen || $j >= $nshort)
                $all[] = $blocks[$j][$i];
        }
    }

    // zigzag placement of the codeword bits (bottom-right, skipping 6)
    $i2 = 0;
    for($right = $size - 1; $right >= 1; $right -= 2) {
        if($right == 6) $right = 5;
        for($vert = 0; $vert < $size; $vert++) {
            for($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $up = (($right + 1) & 2) == 0;
                $y = $up ? $size - 1 - $vert : $vert;
                if(!$fn[$y][$x] && $i2 < count($all) * 8) {
                    $mods[$y][$x] = (($all[$i2 >> 3] >> (7 - ($i2 & 7))) & 1) != 0;
                    $i2++;
                }
            }
        }
    }

    // mask selection: try all eight patterns, keep the lowest penalty
    $best = 0; $best_pen = PHP_INT_MAX;
    for($m = 0; $m < 8; $m++) {
        $mods = qr_apply_mask($mods, $fn, $m);
        $draw_format($m);
        $p = qr_penalty($mods);
        if($p < $best_pen) { $best = $m; $best_pen = $p; }
        // applying twice = undo
        $mods = qr_apply_mask($mods, $fn, $m);
    }
    $mods = qr_apply_mask($mods, $fn, $best);
    $draw_format($best);

    return $mods;
}

// convenience wrapper: QR code for $text as an inline SVG string
// $border is the quiet zone (>= 4 modules per the spec)
function qr_svg(string $text, int $border = 4): string {
    if($border < 4) $border = 4;
    $mods = qr_encode($text);
    $n = count($mods);
    $s = $n + 2 * $border;
    // default pixel size (CSS may override)
    $px = $s * 8;
    $path = "";
    for($y = 0; $y < $n; $y++) {
        for($x = 0; $x < $n; $x++) {
            if($mods[$y][$x])
                $path .= "M" . ($x + $border) . " " . ($y + $border) . "h1v1h-1z";
        }
    }
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$px\" height=\"$px\" "
         . "viewBox=\"0 0 $s $s\" shape-rendering=\"crispEdges\" role=\"img\" "
         . "aria-label=\"QR code\">"
         . "<rect width=\"$s\" height=\"$s\" fill=\"#ffffff\"/>"
         . "<path d=\"$path\" fill=\"#000000\"/></svg>";
}
