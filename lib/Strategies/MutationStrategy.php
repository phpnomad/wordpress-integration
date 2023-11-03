<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Mutator\Interfaces\MutationStrategy as MutationStrategyInterface;

class MutationStrategy implements MutationStrategyInterface
{
    public function attach(callable $mutatorGetter, callable $action): void
    {
        add_filter($action, fn(...$args) => $mutatorGetter()->mutate(...$args));
    }
}