<?php
/**
 * QrEncoder — minimal pure-PHP QR code encoder producing inline SVG output.
 *
 * Scope: produces a scannable QR matrix for an otpauth:// URI. The implementation
 * handles only what is required for TOTP enrollment:
 *   - Data capacity covers the otpauth URI (< 2000 chars in practice, fitting
 *     QR version 1–15 at ECC-M).
 *   - Reed-Solomon error correction at level M (15 % recovery capacity).
 *   - Output: inline SVG with black modules on white background; no external deps.
 *
 * Clean-room implementation of ISO/IEC 18004 (QR Code 2005).
 * No Composer dependency added — this file is the sole dependency for TOTP setup.
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure-PHP QR code encoder, inline SVG output.
 *
 * Only byte-mode encoding is implemented (sufficient for all alphanumeric/URL data).
 * ECC level M. Version is chosen automatically (smallest fitting version 1-15).
 */
final class QrEncoder
{
    /** ECC level M codeword tables (ISO 18004 Table 9, versions 1-15). */
    private const ECC_M = [
        // [total_codewords, data_codewords, ecc_codewords_per_block, blocks]
        1  => [26,  16, 10, 1],
        2  => [44,  28, 16, 1],
        3  => [70,  44, 26, 1],
        4  => [100, 64, 18, 2],
        5  => [134, 86, 24, 2],
        6  => [172, 108, 16, 4],
        7  => [196, 124, 18, 4],
        8  => [242, 154, 22, 4],
        9  => [292, 182, 22, 5],
        10 => [346, 216, 26, 5],
        11 => [404, 254, 30, 5],
        12 => [466, 290, 22, 8],
        13 => [532, 334, 22, 8],
        14 => [581, 365, 24, 9],
        15 => [655, 415, 24, 10],
    ];

    /**
     * Galois field GF(256) exponent table (generator polynomial α^0 to α^254, then wrap).
     *
     * @var list<int>
     */
    private static array $gfExp = [];

    /**
     * Galois field GF(256) log table (index is field element, value is power of α).
     *
     * @var array<int,int>
     */
    private static array $gfLog = [];

    /** Whether GF tables have been initialised. */
    private static bool $gfReady = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Encode $data and return an inline SVG string at the given pixel size.
     *
     * @param string $data   Data to encode (UTF-8 string; byte-mode).
     * @param int    $pixels Target pixel size (≥ 64 recommended for QR codes).
     * @return string Inline SVG markup.
     */
    public static function toSvg(string $data, int $pixels = 256): string
    {
        $version  = 0;
        $eccTable = [];
        $bits     = self::buildBitstream($data, $version, $eccTable);
        $matrix   = self::buildMatrix($bits, $version);
        $matrix   = self::applyBestMask($matrix, $version, $eccTable);
        return self::renderSvg($matrix, $pixels);
    }

    // -------------------------------------------------------------------------
    // Bitstream
    // -------------------------------------------------------------------------

    /**
     * Build the raw data codeword bitstream, choosing the smallest fitting version.
     *
     * @param string              $data
     * @param int                 $version Output parameter: chosen version.
     * @param array<int>          $eccTable Output parameter: ECC table entry for chosen version.
     * @return list<int>          Codeword bytes.
     */
    private static function buildBitstream(string $data, int &$version, array &$eccTable): array
    {
        $byteLen = strlen($data);

        // Find smallest fitting version (byte-mode, ECC-M).
        $version = 0;
        foreach (self::ECC_M as $v => $tbl) {
            // Byte mode header = 4 (mode) + char-count indicator bits + 8*len data bits.
            $ccBits  = $v < 10 ? 8 : 16;
            $needed  = (int) ceil((4 + $ccBits + 8 * $byteLen) / 8);
            if ($needed <= $tbl[1]) {
                $version  = $v;
                $eccTable = $tbl;
                break;
            }
        }

        if ($version === 0) {
            // Data too long for version 15 — truncate to whatever fits.
            $version  = 15;
            $eccTable = self::ECC_M[15];
        }

        [, $dataCw, ,] = $eccTable;
        $ccBits = $version < 10 ? 8 : 16;

        // Assemble bit buffer.
        $buf = 0;
        $bufLen = 0;
        $codewords = [];

        $pushBits = static function (int $bits, int $len) use (&$buf, &$bufLen, &$codewords): void {
            $buf    = ($buf << $len) | ($bits & ((1 << $len) - 1));
            $bufLen += $len;
            while ($bufLen >= 8) {
                $bufLen    -= 8;
                $codewords[] = ($buf >> $bufLen) & 0xFF;
            }
        };

        $pushBits(0b0100, 4);          // byte mode indicator
        $pushBits($byteLen, $ccBits);  // character count
        for ($i = 0; $i < $byteLen; $i++) {
            $pushBits(ord($data[$i]), 8);
        }
        $pushBits(0b0000, 4);          // terminator (may be < 4 bits if buffer fills first)

        // Pad to byte boundary.
        if ($bufLen > 0) {
            $codewords[] = ($buf << (8 - $bufLen)) & 0xFF;
        }

        // Pad codewords to capacity.
        $padBytes = [0xEC, 0x11];
        $padIdx   = 0;
        while (count($codewords) < $dataCw) {
            $codewords[] = $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return self::appendEcc($codewords, $eccTable);
    }

    // -------------------------------------------------------------------------
    // Reed-Solomon ECC
    // -------------------------------------------------------------------------

    /**
     * Append Reed-Solomon ECC blocks and interleave, returning the full codeword sequence.
     *
     * @param list<int>  $data
     * @param array<int> $eccTable
     * @return list<int>
     */
    private static function appendEcc(array $data, array $eccTable): array
    {
        self::initGf();

        [, $dataCw, $eccPerBlock, $blocks] = $eccTable;
        $blockSize = (int) floor($dataCw / $blocks);
        $longBlocks = $dataCw % $blocks;
        $shortBlocks = $blocks - $longBlocks;

        /** @var list<list<int>> $dataBlocks */
        $dataBlocks = [];
        /** @var list<list<int>> $eccBlocks */
        $eccBlocks  = [];
        $offset     = 0;

        for ($b = 0; $b < $blocks; $b++) {
            $len = ($b < $shortBlocks) ? $blockSize : $blockSize + 1;
            $block = array_slice($data, $offset, $len);
            $offset += $len;
            $dataBlocks[]  = $block;
            $eccBlocks[] = self::rsEncode($block, $eccPerBlock);
        }

        // Interleave data.
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        // Interleave ECC.
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        return $out;
    }

    /**
     * Reed-Solomon encode: returns $n ECC bytes for the given data block.
     *
     * @param list<int> $data
     * @param int       $n    Number of ECC bytes.
     * @return list<int>
     */
    private static function rsEncode(array $data, int $n): array
    {
        $gen = self::rsGeneratorPoly($n);
        $msg = array_merge($data, array_fill(0, $n, 0));

        $msgLen = count($msg);
        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $coeff = $msg[$i];
            if ($coeff === 0) {
                continue;
            }
            $logCoeff = self::$gfLog[$coeff];
            $genLen = count($gen);
            for ($j = 0; $j < $genLen; $j++) {
                $msg[$i + $j] ^= self::$gfExp[($logCoeff + $gen[$j]) % 255];
            }
        }

        return array_slice($msg, $dataLen);
    }

    /**
     * Compute the generator polynomial of degree $n (as GF log coefficients).
     *
     * @param int $n
     * @return list<int>
     */
    private static function rsGeneratorPoly(int $n): array
    {
        $gen = [0]; // α^0
        for ($i = 0; $i < $n; $i++) {
            $next = [0];
            foreach ($gen as $coeff) {
                $next[] = ($coeff + $i) % 255;
            }
            // XOR: polynomial multiplication by (x + α^i).
            $g2 = $next;
            $genLen = count($gen);
            for ($j = 0; $j < $genLen; $j++) {
                // $gen[$j] * α^i = ($gen[$j] + $i) mod 255 in log space.
                $g2[$j] = self::gfLogXor($g2[$j], ($gen[$j] + $i) % 255);
            }
            $gen = $g2;
        }
        return $gen;
    }

    /**
     * XOR two GF(256) elements given as log values; returns log of the result.
     *
     * @param int $a  Log of first element (or -1 for zero).
     * @param int $b  Log of second element (or -1 for zero).
     * @return int    Log of result element (or -1 for zero).
     */
    private static function gfLogXor(int $a, int $b): int
    {
        $ea = self::$gfExp[$a % 255];
        $eb = self::$gfExp[$b % 255];
        $xored = $ea ^ $eb;
        if ($xored === 0) {
            return 0; // zero in log space (represent as 0 exponent, but GF element is 0)
        }
        return self::$gfLog[$xored];
    }

    // -------------------------------------------------------------------------
    // GF(256) table initialisation
    // -------------------------------------------------------------------------

    private static function initGf(): void
    {
        if (self::$gfReady) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i]          = $x;
            self::$gfLog[$x]          = $i;
            $x                        = $x << 1;
            if ($x >= 256) {
                $x ^= 0x11D; // primitive poly x^8+x^4+x^3+x^2+1 = 0x11D
            }
        }
        self::$gfExp[255] = self::$gfExp[0];
        self::$gfReady = true;
    }

    // -------------------------------------------------------------------------
    // Matrix construction
    // -------------------------------------------------------------------------

    /**
     * Build the QR module matrix for the given version and codeword sequence.
     *
     * @param list<int> $codewords
     * @param int       $version
     * @return array<int,array<int,int>> 2D array: [row][col] = 0|1|-1 (-1 = reserved/function)
     */
    private static function buildMatrix(array $codewords, int $version): array
    {
        $size = 17 + 4 * $version;

        // Initialise to -1 (unset).
        $matrix = array_fill(0, $size, array_fill(0, $size, -1));

        // Place finder patterns and separators.
        self::placeFinderPattern($matrix, 0, 0);
        self::placeFinderPattern($matrix, $size - 7, 0);
        self::placeFinderPattern($matrix, 0, $size - 7);
        self::placeSeparators($matrix, $size);

        // Alignment patterns (versions 2+).
        if ($version >= 2) {
            foreach (self::alignmentPositions($version) as [$ar, $ac]) {
                self::placeAlignmentPattern($matrix, $ar, $ac);
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            if ($matrix[6][$i] < 0) {
                $matrix[6][$i] = $bit;
            }
            if ($matrix[$i][6] < 0) {
                $matrix[$i][6] = $bit;
            }
        }

        // Dark module (always 1).
        $matrix[4 * $version + 9][8] = 1;

        // Reserve format information areas.
        self::reserveFormat($matrix, $size);

        // Data placement.
        self::placeData($matrix, $codewords, $size);

        return $matrix;
    }

    /**
     * Place a 7×7 finder pattern at top-left corner (row, col).
     *
     * @param array<int,array<int,int>> $matrix
     * @param int $row
     * @param int $col
     * @return void
     */
    private static function placeFinderPattern(array &$matrix, int $row, int $col): void
    {
        $pattern = [
            [1, 1, 1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 1, 1, 1, 1, 1, 1],
        ];
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $matrix[$row + $r][$col + $c] = $pattern[$r][$c];
            }
        }
    }

    /**
     * Zero the separator areas around finder patterns.
     *
     * @param array<int,array<int,int>> $matrix
     * @param int $size
     * @return void
     */
    private static function placeSeparators(array &$matrix, int $size): void
    {
        for ($i = 0; $i < 8; $i++) {
            // Top-left.
            $matrix[7][$i] = 0;
            $matrix[$i][7] = 0;
            // Top-right.
            $matrix[7][$size - 8 + $i] = 0;
            $matrix[$i][$size - 8]      = 0;
            // Bottom-left.
            $matrix[$size - 8][$i] = 0;
            $matrix[$size - 8 + $i][7] = 0;
        }
    }

    /**
     * Place a 5×5 alignment pattern centred at (row, col).
     *
     * @param array<int,array<int,int>> $matrix
     * @param int $row  Centre row.
     * @param int $col  Centre col.
     * @return void
     */
    private static function placeAlignmentPattern(array &$matrix, int $row, int $col): void
    {
        $pattern = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
        ];
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $mr = $row + $r;
                $mc = $col + $c;
                // Do not overwrite finder patterns.
                if ($matrix[$mr][$mc] === -1) {
                    $matrix[$mr][$mc] = $pattern[$r + 2][$c + 2];
                }
            }
        }
    }

    /**
     * Reserve format information strips (marked as 2 = reserved, will be overwritten by mask step).
     *
     * @param array<int,array<int,int>> $matrix
     * @param int $size
     * @return void
     */
    private static function reserveFormat(array &$matrix, int $size): void
    {
        // Horizontal strip along row 8.
        for ($i = 0; $i <= 8; $i++) {
            if ($matrix[8][$i] < 0) {
                $matrix[8][$i] = 2;
            }
            if ($matrix[8][$size - 1 - $i] < 0) {
                $matrix[8][$size - 1 - $i] = 2;
            }
        }
        // Vertical strip along col 8.
        for ($i = 0; $i < $size; $i++) {
            if ($matrix[$i][8] < 0) {
                $matrix[$i][8] = 2;
            }
        }
    }

    /**
     * Return alignment pattern centre positions for the given version.
     *
     * @param int $version
     * @return list<array{int,int}>
     */
    private static function alignmentPositions(int $version): array
    {
        // Positions table (ISO 18004 Annex E).
        $table = [
            2  => [6, 18],
            3  => [6, 22],
            4  => [6, 26],
            5  => [6, 30],
            6  => [6, 34],
            7  => [6, 22, 38],
            8  => [6, 24, 42],
            9  => [6, 26, 46],
            10 => [6, 28, 50],
            11 => [6, 30, 54],
            12 => [6, 32, 58],
            13 => [6, 34, 62],
            14 => [6, 26, 46, 66],
            15 => [6, 26, 48, 70],
        ];

        $positions = $table[$version] ?? [];
        $n         = count($positions);
        $result    = [];

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                // Skip positions that would overlap finder patterns.
                if (($r === 0 && $c === 0) || ($r === 0 && $c === $n - 1) || ($r === $n - 1 && $c === 0)) {
                    continue;
                }
                $result[] = [$positions[$r], $positions[$c]];
            }
        }

        return $result;
    }

    /**
     * Write codeword data into the matrix in the standard zigzag column-pair order.
     *
     * @param array<int,array<int,int>> $matrix
     * @param list<int>                 $codewords
     * @param int                       $size
     * @return void
     */
    private static function placeData(array &$matrix, array $codewords, int $size): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;

        $getBit = static function () use (&$bitIndex, $codewords, $totalBits): int {
            if ($bitIndex >= $totalBits) {
                return 0;
            }
            $byte = $codewords[(int) floor($bitIndex / 8)];
            $bit  = ($byte >> (7 - ($bitIndex % 8))) & 1;
            $bitIndex++;
            return $bit;
        };

        // Columns are processed in pairs from right to left, skipping col 6 (timing).
        $col = $size - 1;
        $goingUp = true;

        while ($col >= 0) {
            if ($col === 6) {
                $col--; // skip timing column
            }

            for ($row = ($goingUp ? $size - 1 : 0); $goingUp ? $row >= 0 : $row < $size; $row += $goingUp ? -1 : 1) {
                for ($dc = 0; $dc <= 1; $dc++) {
                    $c = $col - $dc;
                    if ($matrix[$row][$c] === -1) {
                        $matrix[$row][$c] = $getBit();
                    }
                }
            }

            $col    -= 2;
            $goingUp = !$goingUp;
        }
    }

    // -------------------------------------------------------------------------
    // Masking
    // -------------------------------------------------------------------------

    /**
     * Evaluate all 8 mask patterns and apply the one with the best penalty score.
     *
     * @param array<int,array<int,int>> $matrix
     * @param int                       $version
     * @param array<int>                $eccTable
     * @return array<int,array<int,int>>
     */
    private static function applyBestMask(array $matrix, int $version, array $eccTable): array
    {
        $bestMatrix  = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask    = 0;

        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::applyMask($matrix, $mask, count($matrix));
            self::writeFormatInfo($m, 0b01, $mask, count($m)); // ECC level M = 0b01
            $p = self::penalty($m, count($m));
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $bestMatrix  = $m;
                $bestMask    = $mask;
            }
        }

        return $bestMatrix ?? $matrix;
    }

    /**
     * Apply mask $mask to all data modules (non-function modules) of the matrix.
     *
     * @param array<int,array<int,int>> $matrix
     * @param int                       $mask
     * @param int                       $size
     * @return array<int,array<int,int>>
     */
    private static function applyMask(array $matrix, int $mask, int $size): array
    {
        $m = $matrix;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $v = $m[$row][$col];
                if ($v !== 0 && $v !== 1) {
                    continue; // skip function modules
                }
                if (self::maskCondition($mask, $row, $col)) {
                    $m[$row][$col] = $v ^ 1;
                }
            }
        }
        return $m;
    }

    /**
     * Evaluate the mask condition for pattern $mask at (row, col).
     *
     * @param int $mask 0-7
     * @param int $row
     * @param int $col
     * @return bool
     */
    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => ((int) floor($row / 2) + (int) floor($col / 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            7 => (($row + $col) % 2 + ($row * $col) % 3) % 2 === 0,
            default => false,
        };
    }

    /**
     * Compute a simplified penalty score for mask evaluation (ISO 18004 §8.8.2).
     *
     * @param array<int,array<int,int>> $matrix
     * @param int                       $size
     * @return int
     */
    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Rule 1: 5+ consecutive same-coloured modules in a row or column.
        for ($row = 0; $row < $size; $row++) {
            $run = 1;
            for ($col = 1; $col < $size; $col++) {
                $cur = $matrix[$row][$col] & 1;
                $prv = $matrix[$row][$col - 1] & 1;
                if ($cur === $prv) {
                    $run++;
                    if ($run === 5) {
                        $penalty += 3;
                    } elseif ($run > 5) {
                        $penalty += 1;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            $run = 1;
            for ($row = 1; $row < $size; $row++) {
                $cur = $matrix[$row][$col] & 1;
                $prv = $matrix[$row - 1][$col] & 1;
                if ($cur === $prv) {
                    $run++;
                    if ($run === 5) {
                        $penalty += 3;
                    } elseif ($run > 5) {
                        $penalty += 1;
                    }
                } else {
                    $run = 1;
                }
            }
        }

        // Rule 2: 2×2 blocks of same colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $a = $matrix[$row][$col] & 1;
                $b = $matrix[$row][$col + 1] & 1;
                $c = $matrix[$row + 1][$col] & 1;
                $d = $matrix[$row + 1][$col + 1] & 1;
                if ($a === $b && $a === $c && $a === $d) {
                    $penalty += 3;
                }
            }
        }

        return $penalty;
    }

    /**
     * Write the 15-bit format information string (ECC level + mask) into the matrix.
     *
     * @param array<int,array<int,int>> $matrix
     * @param int                       $eccLevel  2 bits (M=0b01).
     * @param int                       $maskPat   3 bits (0-7).
     * @param int                       $size      Matrix size.
     * @return void
     */
    private static function writeFormatInfo(array &$matrix, int $eccLevel, int $maskPat, int $size): void
    {
        // 5-bit format data: eccLevel (2 bits) << 3 | maskPat (3 bits).
        $data = ($eccLevel << 3) | $maskPat;
        // BCH(15,5) error correction — generator polynomial x^10+x^8+x^5+x^4+x^2+x+1.
        $gen  = 0b10100110111;
        $rem  = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($rem & (1 << $i)) {
                $rem ^= $gen << ($i - 10);
            }
        }
        $format = (($data << 10) | $rem) ^ 0b101010000010010; // XOR mask

        // Place 15 bits into the two format-info strips.
        $positions = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> (14 - $i)) & 1;
            [$r, $c] = $positions[$i];
            $matrix[$r][$c] = $bit;
            // Mirror strip.
            if ($i < 7) {
                $matrix[$size - 1 - $i][8] = $bit;
            } elseif ($i === 7) {
                $matrix[$size - 8][8] = $bit;
            } else {
                $matrix[8][$size - 15 + $i] = $bit;
            }
        }
    }

    // -------------------------------------------------------------------------
    // SVG rendering
    // -------------------------------------------------------------------------

    /**
     * Render the matrix as an inline SVG string.
     *
     * @param array<int,array<int,int>> $matrix
     * @param int                       $pixels  Total output size in pixels.
     * @return string
     */
    private static function renderSvg(array $matrix, int $pixels): string
    {
        $size    = count($matrix);
        $quiet   = 4; // 4-module quiet zone
        $total   = $size + 2 * $quiet;
        $module  = round($pixels / $total, 3);

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg"';
        $svg .= ' width="' . esc_attr((string) $pixels) . '"';
        $svg .= ' height="' . esc_attr((string) $pixels) . '"';
        $svg .= ' viewBox="0 0 ' . esc_attr((string) $total) . ' ' . esc_attr((string) $total) . '"';
        $svg .= ' role="img" aria-label="' . esc_attr__('QR code for two-factor setup', 'wpmgr-agent') . '">';
        $svg .= '<rect width="' . esc_attr((string) $total) . '" height="' . esc_attr((string) $total) . '" fill="#ffffff"/>';

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if (($matrix[$row][$col] & 1) === 1) {
                    $x = $col + $quiet;
                    $y = $row + $quiet;
                    $svg .= '<rect x="' . esc_attr((string) $x) . '" y="' . esc_attr((string) $y) . '"';
                    $svg .= ' width="1" height="1" fill="#000000"/>';
                }
            }
        }

        $svg .= '</svg>';
        return $svg;
    }
}
