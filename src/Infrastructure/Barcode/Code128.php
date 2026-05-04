<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Barcode;

/**
 * Code 128 barcode as inline SVG.
 *
 * Tracking codes (prefix letters + digits): START Code A, encode letters, switch to Code C for digit pairs (dense).
 * Other payloads: START Code B (ASCII 32–126).
 */
final class Code128
{
    // Each value is an 11-bit bar pattern (1=bar, 0=space), MSB first. Index = symbol value.
    private const PATTERNS = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
        '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
        '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
        '10111001100', '10011101100', '10011100110', '11001110010', '11001011100',
        '11001001110', '11011100100', '11001110100', '11101101110', '11101001100',
        '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000',
        '10001000110', '10110001000', '10001101000', '10001100010', '11010001000',
        '11000101000', '11000100010', '10110111000', '10110001110', '10001101110',
        '10111011000', '10111000110', '10001110110', '11101110110', '11010001110',
        '11000101110', '11011101000', '11011100010', '11011101110', '11101011000',
        '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100',
        '10010110000', '10010000110', '10000101100', '10000100110', '10110010000',
        '10110000100', '10011010000', '10011000010', '10000110100', '10000110010',
        '11000010010', '11001010000', '11110111010', '11000010100', '10001111010',
        '10100111100', '10010111100', '10010011110', '10111100100', '10011110100',
        '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110',
        '10111101000', '10111100010', '11110101000', '11110100010', '10111011110',
        '10111101110', '11101011110', '11110101110', '11010000100', '11010010000',
        '11010011100', '1100011101011',
    ];

    private const START_A     = 103;
    private const START_B     = 104;
    private const START_C     = 105;
    private const SWITCH_TO_C = 99;
    private const STOP        = 106;
    private const QUIET_BAR   = 10;

    /**
     * Returns a self-contained SVG string (no external dependencies).
     *
     * @param int $barWidth Width of a single module unit in pixels
     * @param int $height   Bar height in pixels
     */
    public function svg(string $data, int $barWidth = 2, int $height = 60): string
    {
        $codes      = $this->encode($data);
        $pattern    = $this->toPattern($codes);
        $totalUnits = self::QUIET_BAR + strlen($pattern) + self::QUIET_BAR;
        $width      = $totalUnits * $barWidth;

        $bars = '';
        $x    = self::QUIET_BAR * $barWidth;
        $bits = str_split($pattern);
        $i    = 0;

        while ($i < count($bits)) {
            $bit   = $bits[$i];
            $count = 0;
            while ($i < count($bits) && $bits[$i] === $bit) {
                $count++;
                $i++;
            }
            if ($bit === '1') {
                $bars .= sprintf(
                    '<rect x="%d" y="0" width="%d" height="%d"/>',
                    $x,
                    $count * $barWidth,
                    $height,
                );
            }
            $x += $count * $barWidth;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">' .
            '<rect width="%d" height="%d" fill="white"/>' .
            '<g fill="black">%s</g>' .
            '</svg>',
            $width,
            $height,
            $width,
            $height,
            $width,
            $height,
            $bars,
        );
    }

    /**
     * @return int[]
     */
    private function encode(string $data): array
    {
        $data = trim($data);
        if ($data === '') {
            return [self::START_B, self::STOP];
        }

        if (preg_match('/^([A-Za-z]+)(\d+)$/', $data, $m) === 1) {
            return $this->encodeStartAThenCodeC(strtoupper($m[1]), $m[2]);
        }

        return $this->encodeCodeB($data);
    }

    /**
     * START A → letters (same symbol values as Code B for ASCII 32–95) → SWITCH TO C → digit pairs.
     *
     * @return int[]
     */
    private function encodeStartAThenCodeC(string $letters, string $digits): array
    {
        $codes = [self::START_A];
        $sum   = self::START_A;
        $pos   = 1;

        $letterChars = str_split($letters);
        foreach ($letterChars as $char) {
            $o = ord($char);
            if ($o < 32 || $o > 126) {
                return $this->encodeCodeB($letters . $digits);
            }
            $val     = $o - 32;
            $codes[] = $val;
            $sum    += $val * $pos;
            $pos++;
        }

        if ($digits !== '') {
            $codes[] = self::SWITCH_TO_C;
            $sum    += self::SWITCH_TO_C * $pos;
            $pos++;

            if (strlen($digits) % 2 === 1) {
                $digits = '0' . $digits;
            }

            for ($i = 0; $i < strlen($digits); $i += 2) {
                $pair    = (int) substr($digits, $i, 2);
                $codes[] = $pair;
                $sum    += $pair * $pos;
                $pos++;
            }
        }

        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return $codes;
    }

    /**
     * @return int[]
     */
    private function encodeCodeB(string $data): array
    {
        $codes = [self::START_B];
        $sum   = self::START_B;

        foreach (str_split($data) as $i => $char) {
            $o = ord($char);
            if ($o < 32 || $o > 126) {
                throw new \InvalidArgumentException('Code 128B: unsupported character in barcode payload.');
            }
            $val     = $o - 32;
            $codes[] = $val;
            $sum    += $val * ($i + 1);
        }

        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return $codes;
    }

    /**
     * @param int[] $codes
     */
    private function toPattern(array $codes): string
    {
        $pattern = '';

        foreach ($codes as $code) {
            $pattern .= self::PATTERNS[$code];
        }

        return $pattern;
    }
}
