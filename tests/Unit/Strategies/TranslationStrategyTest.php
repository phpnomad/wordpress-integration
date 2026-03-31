<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Translations\Interfaces\HasTextDomain;

/**
 * WordPress translation function stubs.
 *
 * These stubs record every call so tests can verify that TranslationStrategy
 * delegates to the correct WordPress gettext function with the correct arguments.
 */
function _translation_stub_reset(): void
{
    global $_wp_translation_calls;
    $_wp_translation_calls = [];
}

function _translation_stub_calls(): array
{
    global $_wp_translation_calls;
    return $_wp_translation_calls ?? [];
}

class TranslationStrategyTest extends TestCase
{
    private TranslationStrategy $strategy;
    private HasTextDomain $textDomainProvider;

    protected function setUp(): void
    {
        parent::setUp();
        _translation_stub_reset();
        $this->textDomainProvider = Mockery::mock(HasTextDomain::class);
        $this->textDomainProvider->allows('getTextDomain')->andReturn('my-plugin');
        $this->strategy = new TranslationStrategy($this->textDomainProvider);
    }

    protected function tearDown(): void
    {
        _translation_stub_reset();
        parent::tearDown();
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translate
     */
    public function testTranslateWithoutContextCallsDoubleUnderscore(): void
    {
        $result = $this->strategy->translate('Hello');

        $calls = _translation_stub_calls();
        $this->assertCount(1, $calls);
        $this->assertEquals('__', $calls[0]['function']);
        $this->assertEquals('Hello', $calls[0]['args'][0]);
        $this->assertEquals('my-plugin', $calls[0]['args'][1]);
        $this->assertEquals('[Hello|my-plugin]', $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translate
     */
    public function testTranslateWithContextCallsXWithCorrectArgumentOrder(): void
    {
        $result = $this->strategy->translate('Post', 'noun');

        $calls = _translation_stub_calls();
        $this->assertCount(1, $calls);
        $this->assertEquals('_x', $calls[0]['function']);
        $this->assertEquals('Post', $calls[0]['args'][0]);
        $this->assertEquals('noun', $calls[0]['args'][1]);
        $this->assertEquals('my-plugin', $calls[0]['args'][2]);
        $this->assertEquals('[Post|noun|my-plugin]', $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translatePlural
     */
    public function testTranslatePluralWithoutContextCallsN(): void
    {
        $result = $this->strategy->translatePlural('%d item', '%d items', 5);

        $calls = _translation_stub_calls();
        $this->assertCount(1, $calls);
        $this->assertEquals('_n', $calls[0]['function']);
        $this->assertEquals('%d item', $calls[0]['args'][0]);
        $this->assertEquals('%d items', $calls[0]['args'][1]);
        $this->assertEquals(5, $calls[0]['args'][2]);
        $this->assertEquals('my-plugin', $calls[0]['args'][3]);
        $this->assertEquals('[%d items|5|my-plugin]', $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translatePlural
     */
    public function testTranslatePluralWithContextCallsNxWithCorrectArgumentOrder(): void
    {
        $result = $this->strategy->translatePlural('%d item', '%d items', 1, 'cart');

        $calls = _translation_stub_calls();
        $this->assertCount(1, $calls);
        $this->assertEquals('_nx', $calls[0]['function']);
        $this->assertEquals('%d item', $calls[0]['args'][0]);
        $this->assertEquals('%d items', $calls[0]['args'][1]);
        $this->assertEquals(1, $calls[0]['args'][2]);
        $this->assertEquals('cart', $calls[0]['args'][3]);
        $this->assertEquals('my-plugin', $calls[0]['args'][4]);
        $this->assertEquals('[%d item|1|cart|my-plugin]', $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translatePlural
     */
    public function testTranslatePluralSelectsPluralFormWhenCountIsNotOne(): void
    {
        $result = $this->strategy->translatePlural('one apple', 'many apples', 3);

        $calls = _translation_stub_calls();
        $this->assertEquals('many apples', $calls[0]['args'][1]);
        $this->assertEquals(3, $calls[0]['args'][2]);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\TranslationStrategy::translatePlural
     */
    public function testTranslatePluralSelectsSingularFormWhenCountIsOne(): void
    {
        $result = $this->strategy->translatePlural('one apple', 'many apples', 1);

        $calls = _translation_stub_calls();
        $this->assertEquals('one apple', $calls[0]['args'][0]);
        $this->assertEquals(1, $calls[0]['args'][2]);
    }
}
