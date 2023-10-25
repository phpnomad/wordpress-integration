<?php

namespace Phoenix\Integrations\WordPress\Providers;

use Phoenix\Cache\Interfaces\HasDefaultTtl;
use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;

class DatabaseProvider implements HasGlobalDatabasePrefix, HasCollateProvider, HasCharsetProvider
{
    /** @inheritDoc */
    public function getGlobalDatabasePrefix(): string
    {
        global $wpdb;

        return $wpdb->prefix;
    }

    public function getCharset(): ?string
    {
        return 'utf8mb4';
    }

    public function getCollation(): ?string
    {
        return 'utf8mb4_unicode_ci';
    }
}
