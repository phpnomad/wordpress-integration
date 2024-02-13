<?php

namespace PHPNomad\Integrations\WordPress\Adapters;

use PHPNomad\Auth\Interfaces\Action;
use PHPNomad\Auth\Models\Action as ActionModel;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Str;

class ActionToCapabilityAdapter
{
    protected string $prefix = 'siren_action__';

    /**
     * Converts the Action object into a JSON string and prepends with a prefix.
     *
     * @param Action $action The action object.
     *
     * @return string The capability string derived from the given action object.
     */
    public function getCapability(Action $action): string
    {
        return $this->prefix . json_encode(['action' => $action->getAction(), 'targetType' => $action->getTargetType()]);
    }

    /**
     * Extracts the Action object from a capability string.
     *
     * @param string $capability The capability string.
     *
     * @return Action The action object.
     */
    public function getAction(string $capability): Action
    {
        $jsonAction = Str::after($capability, $this->prefix);
        $actionArray = json_decode($jsonAction, true);

        return new ActionModel(
            Arr::get($actionArray, 'action'),
            Arr::get($actionArray, 'targetType')
        );
    }
}