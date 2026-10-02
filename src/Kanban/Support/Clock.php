<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Clock
{
    public const FORMAT = 'Y-m-d\TH:i:s.vP';

    public static function now(): string
    {
        return self::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    public static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function parse(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp);
    }

    public static function seconds(string $from, ?string $to = null): int
    {
        $to = $to === null ? new DateTimeImmutable : self::parse($to);

        return max(0, $to->getTimestamp() - self::parse($from)->getTimestamp());
    }

    /** Short human duration: 45s, 4m, 3h, 2d. */
    public static function human(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 172800 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }
}
