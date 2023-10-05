<?php

namespace Phoenix\Integrations\WordPress;

use Phoenix\Core\Bootstrap\Interfaces\EventStrategy;
use Phoenix\Core\Bootstrap\Interfaces\Initializer;
use Phoenix\Core\Strategies\WordPressEventStrategy;

class WordPressInitializer implements Initializer
{
    public const REQUIRED_PHP_VERSION = '7.4';

    public const REQUIRED_WORDPRESS_VERSION = '6.3.1';

    /**
     * @return array
     */
    public function getClassDefinitions(): array
    {
        return [
            EventStrategy::class => WordPressEventStrategy::class
        ];
    }

    /**
     * Determines if this site meets the minimum criteria for this plugin to function.
     *
     * @return bool
     */
    public function requirementsMet(): bool
    {
        global $wp_version;
        if (version_compare(phpversion(), static::REQUIRED_PHP_VERSION, '<')) {
            return false;
        }

        if (!isset($wp_version) || version_compare($wp_version, static::REQUIRED_WORDPRESS_VERSION, '<')) {
            return false;
        }

        return true;
    }

    public function init(): void
    {
        // TODO: Implement init() method.
    }
}