<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Translations\Interfaces\HasTextDomain;
use PHPNomad\Translations\Interfaces\TranslationStrategy as TranslationStrategyInterface;

class TranslationStrategy implements TranslationStrategyInterface
{
    protected HasTextDomain $textDomainProvider;

    public function __construct(HasTextDomain $textDomainProvider)
    {
        $this->textDomainProvider = $textDomainProvider;
    }

    /** @inheritDoc */
    public function translate(string $translate, ?string $language = null, $context = null): string
    {
        if ($language !== null) {
            switch_to_locale($language);
        }

        $domain = $this->textDomainProvider->getTextDomain();
        $translated = $context === null ? __($translate, $domain) : _x($translate, $domain);

        if ($language !== null) {
            restore_previous_locale();
        }

        return $translated;
    }
}