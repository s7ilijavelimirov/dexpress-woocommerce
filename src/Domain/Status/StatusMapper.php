<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Status;

use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;

/**
 * Minimalno mapiranje sID → grupe za email i terminal guard.
 * Zvanični nazivi statusa dolaze isključivo iz {@see \S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository}.
 */
final class StatusMapper
{
    /** sID koji ne šalju „U transportu“ email (kašnjenje). */
    private const DELAYED = [6, 7, 12, 17];

    /**
     * @var array<string, StatusEmailBucket>
     */
    private const EXACT = [
        '0'   => StatusEmailBucket::Other,
        '1'   => StatusEmailBucket::Delivered,
        '3'   => StatusEmailBucket::InTransit,
        '4'   => StatusEmailBucket::OutForDelivery,
        '5'   => StatusEmailBucket::ProblemFailed,
        '-1'  => StatusEmailBucket::Other,
        '-2'  => StatusEmailBucket::Other,
        '-11' => StatusEmailBucket::ProblemFailed,
        '-12' => StatusEmailBucket::ProblemFailed,
        '-13' => StatusEmailBucket::ProblemFailed,
        '18'  => StatusEmailBucket::Other,
        '20'  => StatusEmailBucket::Other,
        '21'  => StatusEmailBucket::Other,
        '23'  => StatusEmailBucket::Other,
        '123' => StatusEmailBucket::Other,
        '22'  => StatusEmailBucket::InTransit,
        '30'  => StatusEmailBucket::InTransit,
        '820' => StatusEmailBucket::InTransit,
        '822' => StatusEmailBucket::ProblemFailed,
        '830' => StatusEmailBucket::Other,
        '831' => StatusEmailBucket::Delivered,
        '840' => StatusEmailBucket::InTransit,
        '841' => StatusEmailBucket::ProblemFailed,
        '842' => StatusEmailBucket::InTransit,
        '843' => StatusEmailBucket::Delivered,
    ];

    /** @var array<int, StatusEmailBucket> */
    private const PROBLEM = [8, 9, 10, 11, 19, 25, 106, 107, 119, 125];

    private const PROBLEM_RANGE_EXTRA = [108, 109, 110, 111, 112];

    public function emailBucketForSid(int $sid): StatusEmailBucket
    {
        if ($this->isDelayedSid($sid)) {
            return StatusEmailBucket::Other;
        }

        $key = (string) $sid;
        if (isset(self::EXACT[$key])) {
            return self::EXACT[$key];
        }

        if (in_array($sid, self::PROBLEM, true) || in_array($sid, self::PROBLEM_RANGE_EXTRA, true)) {
            return StatusEmailBucket::ProblemFailed;
        }

        if ($sid >= 108 && $sid <= 112) {
            return StatusEmailBucket::ProblemFailed;
        }

        return StatusEmailBucket::Other;
    }

    public function isDelayedSid(int $sid): bool
    {
        return in_array($sid, self::DELAYED, true);
    }

    /**
     * Pošiljka više ne prima promene trenutnog „poslovnog“ statusa ( istorija se i dalje upisuje ).
     */
    public function isTerminalSid(int $sid): bool
    {
        return in_array($sid, [1, 831, 843, 20, -1, -2], true);
    }
}
