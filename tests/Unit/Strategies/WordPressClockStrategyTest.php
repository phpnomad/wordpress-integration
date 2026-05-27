<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use DateTimeImmutable;
use DateTimeZone;
use PHPNomad\Chrono\Interfaces\CanFormatLocalizedDate;
use PHPNomad\Chrono\Interfaces\CanFormatRelativeTime;
use PHPNomad\Chrono\Interfaces\ClockStrategy;
use PHPNomad\Chrono\Interfaces\HasLocale;
use PHPNomad\Chrono\Interfaces\HasTimezone;
use PHPNomad\Integrations\WordPress\Strategies\WordPressClockStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

class WordPressClockStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        global $_wp_current_datetime, $_wp_timezone, $_wp_determined_locale, $_wp_date_calls, $_wp_human_time_diff_calls;
        $_wp_current_datetime = null;
        $_wp_timezone = null;
        $_wp_determined_locale = null;
        $_wp_date_calls = [];
        $_wp_human_time_diff_calls = [];
        parent::tearDown();
    }

    public function testImplementsTheAdvertisedChronoInterfaces(): void
    {
        $strategy = new WordPressClockStrategy();

        $this->assertInstanceOf(ClockStrategy::class, $strategy);
        $this->assertInstanceOf(HasTimezone::class, $strategy);
        $this->assertInstanceOf(HasLocale::class, $strategy);
        $this->assertInstanceOf(CanFormatLocalizedDate::class, $strategy);
        $this->assertInstanceOf(CanFormatRelativeTime::class, $strategy);
    }

    public function testNowReturnsValueFromCurrentDatetime(): void
    {
        global $_wp_current_datetime;
        $instant = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('America/Chicago'));
        $_wp_current_datetime = $instant;

        $result = (new WordPressClockStrategy())->now();

        $this->assertEquals($instant->getTimestamp(), $result->getTimestamp());
    }

    public function testNowPreservesTimezoneFromWordPress(): void
    {
        global $_wp_current_datetime;
        $instant = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('America/Chicago'));
        $_wp_current_datetime = $instant;

        $result = (new WordPressClockStrategy())->now();

        $this->assertEquals('America/Chicago', $result->getTimezone()->getName());
    }

    public function testNowReturnsDateTimeImmutable(): void
    {
        $result = (new WordPressClockStrategy())->now();
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
    }

    public function testGetTimezoneDelegatesToWpTimezone(): void
    {
        global $_wp_timezone;
        $_wp_timezone = new DateTimeZone('Europe/Berlin');

        $result = (new WordPressClockStrategy())->getTimezone();

        $this->assertInstanceOf(DateTimeZone::class, $result);
        $this->assertEquals('Europe/Berlin', $result->getName());
    }

    public function testGetLocaleDelegatesToDetermineLocale(): void
    {
        global $_wp_determined_locale;
        $_wp_determined_locale = 'fr_FR';

        $result = (new WordPressClockStrategy())->getLocale();

        $this->assertSame('fr_FR', $result);
    }

    public function testGetLocaleReturnsStub(): void
    {
        // No override set; the bootstrap stub returns 'en_US' as a fallback.
        $result = (new WordPressClockStrategy())->getLocale();

        $this->assertSame('en_US', $result);
    }

    public function testFormatLocalizedDelegatesToWpDateWithInstantTimestampAndTimezone(): void
    {
        global $_wp_date_calls;
        $instant = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('America/Chicago'));

        (new WordPressClockStrategy())->formatLocalized($instant, 'Y-m-d H:i');

        $this->assertCount(1, $_wp_date_calls);
        $this->assertSame('Y-m-d H:i', $_wp_date_calls[0]['format']);
        $this->assertSame($instant->getTimestamp(), $_wp_date_calls[0]['timestamp']);
        $this->assertEquals('America/Chicago', $_wp_date_calls[0]['timezone']->getName());
    }

    public function testFormatLocalizedReturnsFormattedString(): void
    {
        $instant = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('UTC'));

        $result = (new WordPressClockStrategy())->formatLocalized($instant, 'Y-m-d');

        $this->assertSame('2026-05-27', $result);
    }

    public function testRelativeForPastInstantUsesAgoTemplate(): void
    {
        global $_wp_current_datetime;
        $_wp_current_datetime = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('UTC'));
        $instant = new DateTimeImmutable('2026-05-27 14:30:00', new DateTimeZone('UTC'));

        $result = (new WordPressClockStrategy())->relative($instant);

        // The __ stub returns "[%s ago|default]"; sprintf fills the %s.
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('3600 seconds', $result);
    }

    public function testRelativeForFutureInstantUsesInTemplate(): void
    {
        global $_wp_current_datetime;
        $_wp_current_datetime = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('UTC'));
        $instant = new DateTimeImmutable('2026-05-27 16:30:00', new DateTimeZone('UTC'));

        $result = (new WordPressClockStrategy())->relative($instant);

        $this->assertStringStartsWith('[in', $result);
        $this->assertStringContainsString('3600 seconds', $result);
    }

    public function testRelativeCallsHumanTimeDiffWithBothTimestamps(): void
    {
        global $_wp_current_datetime, $_wp_human_time_diff_calls;
        $now = new DateTimeImmutable('2026-05-27 15:30:00', new DateTimeZone('UTC'));
        $_wp_current_datetime = $now;
        $instant = new DateTimeImmutable('2026-05-27 14:30:00', new DateTimeZone('UTC'));

        (new WordPressClockStrategy())->relative($instant);

        $this->assertCount(1, $_wp_human_time_diff_calls);
        $this->assertSame($instant->getTimestamp(), $_wp_human_time_diff_calls[0]['from']);
        $this->assertSame($now->getTimestamp(), $_wp_human_time_diff_calls[0]['to']);
    }
}
