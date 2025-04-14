<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Console\Interfaces\OutputStrategy;
use function WP_CLI\Utils\format_items;

/**
 * WP-CLI implementation of OutputStrategy.
 *
 * Uses native WP_CLI methods to print styled messages
 * and supports fluent chaining.
 */
class ConsoleOutputStrategy implements OutputStrategy
{
    /** @inheritDoc */
    public function writeln(string $message): static
    {
        \WP_CLI::log($message);
        return $this;
    }

    /** @inheritDoc */
    public function info(string $message): static
    {
        \WP_CLI::log("%C{$message}%n");
        return $this;
    }

    /** @inheritDoc */
    public function success(string $message): static
    {
        \WP_CLI::success($message);
        return $this;
    }

    /** @inheritDoc */
    public function warning(string $message): static
    {
        \WP_CLI::warning($message);
        return $this;
    }

    /** @inheritDoc */
    public function error(string $message): static
    {
        \WP_CLI::error($message, false); // Don't exit; just log
        return $this;
    }

    /** @inheritDoc */
    public function newline(): static
    {
        \WP_CLI::log('');
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function table(array $rows, array $headers = []): static
    {
        if (empty($rows)) {
            WP_CLI::log('No results found.');
            return $this;
        }

        if (empty($headers)) {
            $headers = array_keys(reset($rows));
        }

        format_items('table', $rows, $headers);
        return $this;
    }
}