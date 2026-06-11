<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Template\Interfaces\ScreenResolverStrategy;

class AdminScreenResolver implements ScreenResolverStrategy
{
    protected string $actionKey = 'siren_action';

    private function getContext(array $context = []): string
    {
        if(!empty($context)) {
            $query = http_build_query($context);
            $query = "&$query";
        }

        return $query ?? '';
    }

    /**
     * @inheritDoc
     */
    public function getUrlForSlug(string $slug, array $context = []): string
    {
        return get_admin_url() . 'admin.php?page=' . $slug . $this->getContext($context);
    }

    /**
     * @inheritDoc
     */
    public function getUrlForAction(string $slug, string $action, array $context = []): string
    {
        $url = get_admin_url() . 'admin.php?page=' . $slug . '&' . $this->actionKey . '=' . $action . $this->getContext($context);

        return wp_nonce_url($url, 'siren_' . $slug . $action);
    }

    /**
     * @inheritDoc
     */
    public function isCurrentScreen(string $slug): bool
    {
        return $this->readRequestKey('page') === $slug;
    }

    /**
     * @inheritDoc
     */
    public function isCurrentAction(string $slug, string $action): bool
    {
        return $this->isCurrentScreen($slug) && $this->readRequestKey($this->actionKey) === $action;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentScreen(): ?string
    {
        return $this->readRequestKey('page');
    }

    /**
     * @inheritDoc
     */
    public function getCurrentAction(): ?string
    {
        return $this->readRequestKey($this->actionKey);
    }

    /**
     * Read a request key sanitized for screen/action comparisons.
     *
     * Screen slugs and action names are key-shaped values; sanitize_key()
     * mirrors how WordPress core treats the `page` query arg and strips
     * anything an attacker could smuggle through the raw superglobal.
     */
    protected function readRequestKey(string $key): ?string
    {
        if (!isset($_REQUEST[$key]) || !is_string($_REQUEST[$key])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only comparison helpers; mutation handlers verify nonces at their own boundary.
        return sanitize_key(wp_unslash($_REQUEST[$key]));
    }
}