<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Translations\Interfaces\HasTextDomain;
use PHPNomad\Translations\Interfaces\TranslationStrategy as TranslationStrategyInterface;

/**
 * WordPress implementation of the translation strategy.
 *
 * Delegates to WordPress's native gettext functions: __(), _x(), _n(), _nx().
 * Domain is resolved from the injected HasTextDomain provider.
 * Locale is managed by WordPress's own globals — not injected.
 */
class TranslationStrategy implements TranslationStrategyInterface
{
    protected HasTextDomain $textDomainProvider;

    public function __construct(HasTextDomain $textDomainProvider)
    {
        $this->textDomainProvider = $textDomainProvider;
    }

    public function translate(string $text, ?string $context = null): string
    {
        $domain = $this->textDomainProvider->getTextDomain();

        return $context === null
            ? __($text, $domain)
            : _x($text, $context, $domain);
    }

    public function translatePlural(string $singular, string $plural, int $count, ?string $context = null): string
    {
        $domain = $this->textDomainProvider->getTextDomain();

        return $context === null
            ? _n($singular, $plural, $count, $domain)
            : _nx($singular, $plural, $count, $context, $domain);
    }
}
