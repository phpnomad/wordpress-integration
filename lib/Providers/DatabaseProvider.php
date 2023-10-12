<?php

namespace Phoenix\Integrations\WordPress\Providers;

use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;

class DatabaseProvider implements HasGlobalDatabasePrefix, HasDatabaseDefaultCacheTtl
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
}
