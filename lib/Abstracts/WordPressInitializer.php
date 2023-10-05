<?php

namespace Phoenix\Core\Abstracts;

use Phoenix\Core\Bootstrap\Interfaces\EventStrategy;
use Phoenix\Core\Bootstrap\Interfaces\Initializer;
use Phoenix\Core\Container;
use Phoenix\Core\Strategies\WordPressEventStrategy;

abstract class WordPressInitializer implements Initializer
{
    /**
     * @return array
     */
    public function getClassDefinitions(): array
    {
        return [
            EventStrategy::class => Container::create(WordPressEventStrategy::class)
        ];
    }

    public function requirementsMet(): bool
    {
        return true;
    }

    public function init(): void
    {
        // TODO: Implement init() method.
    }
}