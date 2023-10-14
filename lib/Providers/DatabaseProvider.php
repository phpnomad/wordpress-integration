<?php

namespace Phoenix\Integrations\WordPress\Providers;

use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;

class DatabaseProvider implements HasGlobalDatabasePrefix, HasDatabaseDefaultCacheTtl, HasCollateProvider, HasCharsetProvider
{
    /** @inheritDoc */
    public function getDatabaseDefaultCacheTtl(): int
    {
        return 604800;
    }

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
