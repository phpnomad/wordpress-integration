<?php

namespace PHPNomad\Integrations\WordPress\Traits;

trait LogsDatabaseErrors
{
    /**
     * Record database failure detail server-side.
     *
     * Exception messages must stay stable and free of SQL or MySQL error
     * text — they can surface in REST error payloads or rendered fatals.
     * The diagnostic detail still matters to operators, so it goes to the
     * PHP error log (wp-content/debug.log when WP_DEBUG_LOG is enabled,
     * the server error log otherwise) at the point of failure.
     */
    private function logDatabaseError(string $message, string $detail): void
    {
        if ($detail === '') {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side diagnostics only; the thrown exception carries no detail.
        error_log('[PHPNomad] ' . $message . ': ' . $detail);
    }
}
