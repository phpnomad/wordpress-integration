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
