<?php
require_once 'vendor/autoload.php';

/**
 * WordPress gettext function stubs for testing.
 *
 * Each stub records its call in a global array so tests can verify
 * which function was called and with what arguments. The return value
 * encodes all arguments for easy assertion.
 */

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        global $_wp_translation_calls;
        $_wp_translation_calls[] = ['function' => '__', 'args' => [$text, $domain]];
        return "[$text|$domain]";
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        global $_wp_translation_calls;
        $_wp_translation_calls[] = ['function' => '_x', 'args' => [$text, $context, $domain]];
        return "[$text|$context|$domain]";
    }
}

if (!function_exists('_n')) {
    function _n(string $singular, string $plural, int $count, string $domain = 'default'): string
    {
        global $_wp_translation_calls;
        $_wp_translation_calls[] = ['function' => '_n', 'args' => [$singular, $plural, $count, $domain]];
        $form = $count === 1 ? $singular : $plural;
        return "[$form|$count|$domain]";
    }
}

if (!function_exists('_nx')) {
    function _nx(string $singular, string $plural, int $count, string $context, string $domain = 'default'): string
    {
        global $_wp_translation_calls;
        $_wp_translation_calls[] = ['function' => '_nx', 'args' => [$singular, $plural, $count, $context, $domain]];
        $form = $count === 1 ? $singular : $plural;
        return "[$form|$count|$context|$domain]";
    }
}

/**
 * WordPress chrono function stubs for testing.
 *
 * Each stub honors a global override when set by a test, and otherwise
 * falls back to a sane native-PHP behavior. Tests can also inspect the
 * recorded call arguments to verify delegation.
 */

if (!function_exists('current_datetime')) {
    function current_datetime(): DateTimeImmutable
    {
        global $_wp_current_datetime;
        return $_wp_current_datetime ?? new DateTimeImmutable('now');
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        global $_wp_timezone;
        return $_wp_timezone ?? new DateTimeZone(date_default_timezone_get());
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string
    {
        global $_wp_date_calls;
        $_wp_date_calls[] = ['format' => $format, 'timestamp' => $timestamp, 'timezone' => $timezone];

        $timestamp ??= time();
        $instant = (new DateTimeImmutable('@' . $timestamp))->setTimezone(
            $timezone ?? new DateTimeZone(date_default_timezone_get())
        );

        return $instant->format($format);
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff(int $from, int $to = 0): string
    {
        global $_wp_human_time_diff_calls;
        $_wp_human_time_diff_calls[] = ['from' => $from, 'to' => $to];

        $to = $to ?: time();
        return abs($to - $from) . ' seconds';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale(): string
    {
        global $_wp_determined_locale;
        return $_wp_determined_locale ?? 'en_US';
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = []
        ) {
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $method;

        public function __construct(string $method = 'GET')
        {
            $this->method = $method;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_params(): array
        {
            return [];
        }

        public function get_param(string $key)
        {
            return null;
        }

        public function get_headers(): array
        {
            return [];
        }
    }
}
