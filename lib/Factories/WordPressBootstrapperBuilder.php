<?php

namespace Phoenix\Integrations\WordPress\Factories;

use Phoenix\Core\Bootstrap\Bootstrapper;
use Phoenix\Core\Bootstrap\CoreInitializer;
use Phoenix\Core\Bootstrap\Interfaces\HasClassDefinitions;
use Phoenix\Integrations\WordPress\Strategies\WordPressInitializer;
use Phoenix\Loader\Interfaces\HasLoadCondition;
use Phoenix\Loader\Interfaces\Loadable;
use Phoenix\Utils\Helpers\Arr;

final class WordPressBootstrapperBuilder
{
    protected array $initializers = [];

    /**
     * @param HasClassDefinitions|Loadable|HasLoadCondition $initializer
     * @return $this
     */
    public function addInitializer($initializer): WordPressBootstrapperBuilder
    {
        $this->initializers[] = $initializer;

        return $this;
    }

    /**
     * Builds the bootstrapper, preloading dependencies.
     * @return void
     */
    public function build()
    {
        Bootstrapper::init(...Arr::merge([new CoreInitializer(), new WordPressInitializer()], $this->initializers));
        $this->initializers = [];
    }
}
