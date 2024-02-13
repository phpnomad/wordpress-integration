<?php

namespace PHPNomad\Integrations\WordPress\Bindings;

use PHPNomad\Auth\Enums\SessionContexts;
use PHPNomad\Auth\Interfaces\CurrentContextResolverStrategy;
use PHPNomad\Auth\Interfaces\CurrentUserResolverStrategy;
use PHPNomad\Framework\Events\SiteVisited;
use PHPNomad\Utils\Helpers\Arr;

class SiteVisitedBinding
{
    protected CurrentContextResolverStrategy $contextResolver;
    protected CurrentUserResolverStrategy $userResolver;

    public function __construct(CurrentContextResolverStrategy $contextResolver, CurrentUserResolverStrategy $currentUserResolver)
    {
        $this->contextResolver = $contextResolver;
        $this->userResolver = $currentUserResolver;
    }

    protected function isInvalidContext(): bool
    {
        $context = $this->contextResolver->getCurrentContext();
        $contexts = Arr::filter(SessionContexts::getValues(), fn(string $context) => $context !== SessionContexts::Web);

        return in_array($context, $contexts);
    }

    public function __invoke(): ?SiteVisited
    {
        $context = $this->contextResolver->getCurrentContext();
        $user    = $this->userResolver->getCurrentUser();

        if (is_admin() || $context !== SessionContexts::Web) {
            return null;
        }

        return new SiteVisited($user->getId());
    }
}