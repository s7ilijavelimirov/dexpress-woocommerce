<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure;

/**
 * Converts Serbian Latin strings to a diacritic-free, lowercase form
 * suitable for searchable index columns (name_searchable).
 *
 * Handles: č→c, ć→c, š→s, ž→z, đ→dj (and uppercase equivalents).
 */
final class StringNormalizer
{
    private const MAP = [
        'č' => 'c', 'Č' => 'c',
        'ć' => 'c', 'Ć' => 'c',
        'š' => 's', 'Š' => 's',
        'ž' => 'z', 'Ž' => 'z',
        'đ' => 'dj', 'Đ' => 'dj',
    ];

    public static function toSearchable(string $value): string
    {
        return mb_strtolower(strtr($value, self::MAP), 'UTF-8');
    }
}
