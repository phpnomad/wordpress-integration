<?php

namespace PHPNomad\Integrations\WordPress\Auth;

use PHPNomad\Auth\Interfaces\Action;
use PHPNomad\Auth\Interfaces\User as UserInterface;
use PHPNomad\Integrations\WordPress\Adapters\ActionToCapabilityAdapter;
use WP_User;


class User implements UserInterface
{
    protected WP_User $wpUser;

    public function __construct(WP_User $user)
    {
        $this->wpUser = $user;
    }

    public function getId(): int
    {
        return $this->wpUser->ID;
    }

    public function canDoAction(Action $action): bool
    {
        $adapter = new ActionToCapabilityAdapter();

        return $this->wpUser->has_cap($adapter->getCapability($action));
    }
}