<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use DateTimeImmutable;
use DateTimeZone;
use PHPNomad\Chrono\Interfaces\CanFormatLocalizedDate;
use PHPNomad\Chrono\Interfaces\CanFormatRelativeTime;
use PHPNomad\Chrono\Interfaces\ClockStrategy;
use PHPNomad\Chrono\Interfaces\HasTimezone;

class WordPressClockStrategy implements
    ClockStrategy,
    HasTimezone,
    CanFormatLocalizedDate,
    CanFormatRelativeTime
{
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(current_datetime());
    }

    public function getTimezone(): DateTimeZone
    {
        return wp_timezone();
    }

    public function formatLocalized(DateTimeImmutable $instant, string $format): string
    {
        return (string) wp_date($format, $instant->getTimestamp(), $instant->getTimezone());
    }

    public function relative(DateTimeImmutable $instant): string
    {
        $now = $this->now();
        $diff = human_time_diff($instant->getTimestamp(), $now->getTimestamp());

        if ($instant < $now) {
            return sprintf(__('%s ago'), $diff);
        }

        return sprintf(__('in %s'), $diff);
    }
}
