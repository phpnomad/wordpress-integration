<?php

namespace PHPNomad\Integrations\WordPress\Tests\Fixtures;

/**
 * A stand-in for the parts of wpdb that ClauseBuilder uses.
 *
 * prepare() follows the steps wpdb::prepare() takes for %s placeholders:
 * it strips quotes around placeholders, quotes each one, escapes each value,
 * fills the placeholders, and swaps every % in the result for an escape
 * token. wpdb removes that token again when it runs the query.
 */
class PreparingWpdb
{
    public const PLACEHOLDER_ESCAPE = '{escape}';

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $query = str_replace(["'%s'", '"%s"'], '%s', $query);
        $query = preg_replace('/(?<!%)%s/', "'%s'", $query);
        $args = array_map(fn ($arg) => addslashes((string) $arg), $args);

        return str_replace('%', self::PLACEHOLDER_ESCAPE, vsprintf($query, $args));
    }
}
