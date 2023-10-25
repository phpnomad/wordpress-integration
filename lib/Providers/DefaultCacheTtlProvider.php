<?php

namespace Phoenix\Integrations\WordPress\Providers;

use Phoenix\Cache\Interfaces\HasDefaultTtl;

class DefaultCacheTtlProvider implements HasDefaultTtl
{
    public function getDefaultTtl(): ?int
    {
        return defined('SIREN_CACHE_TTL') ? (int) SIREN_CACHE_TTL : 604800;
    }
}