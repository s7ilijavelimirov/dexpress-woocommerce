<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Email;

/**
 * Public tracking URL on dexpress.rs (first package code when several are comma-separated).
 */
final class TrackingLinkBuilder
{
    private const BASE = 'https://www.dexpress.rs/rs/pracenje-posiljaka/';

    public static function firstPackageCodeFromList(string $codesList): string
    {
        $codesList = trim($codesList);
        if ($codesList === '') {
            return '';
        }

        return trim(explode(',', $codesList, 2)[0]);
    }

    public static function publicTrackingUrl(string $packageCode): string
    {
        $packageCode = trim($packageCode);
        if ($packageCode === '') {
            return '';
        }

        return self::BASE . rawurlencode($packageCode);
    }

    public static function publicTrackingUrlFromCodesList(string $codesList): string
    {
        return self::publicTrackingUrl(self::firstPackageCodeFromList($codesList));
    }
}
