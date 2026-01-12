<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Tests\Traits\WithInaccessibleMethods;

class ConsoleStrategyTest extends TestCase
{
    use WithInaccessibleMethods;

    private ConsoleStrategy $strategy;
    private LoggerStrategy $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = Mockery::mock(LoggerStrategy::class);
        $this->strategy = new ConsoleStrategy($this->logger);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureExtractsCommandName(): void
    {
        $signature = 'mycommand subcommand {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('mycommand subcommand', $result['name']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureCorrectlyTrimsOptionPrefix(): void
    {
        $signature = 'command {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('count', $result['definitions'][0]['name']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsCorrectSynopsisNameForOption(): void
    {
        $signature = 'command {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('count', $result['definitions'][0]['synopsis']['name']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsIsOptionTrueForFlags(): void
    {
        $signature = 'command {--cascade:Enable cascade}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['isOption']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsNullDefaultForFlags(): void
    {
        $signature = 'command {--cascade:Enable cascade}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertNull($result['definitions'][0]['default']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsTypeFlagForOptionsWithoutEquals(): void
    {
        $signature = 'command {--cascade:Enable cascade}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('flag', $result['definitions'][0]['synopsis']['type']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsRequiredTrueForFlags(): void
    {
        $signature = 'command {--cascade:Enable cascade}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['required']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     * @dataProvider optionWithDefaultProvider
     */
    public function testParseSignatureExtractsDefaultValue(string $signature, string $expectedDefault): void
    {
        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals($expectedDefault, $result['definitions'][0]['default']);
    }

    public static function optionWithDefaultProvider(): \Generator
    {
        yield 'numeric default' => ['command {--count=1:Number of items}', '1'];
        yield 'string default' => ['command {--name=default:Name}', 'default'];
        yield 'null string default' => ['command {--seed=null:Seed}', 'null'];
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsTypeAssocForOptionsWithDefaults(): void
    {
        $signature = 'command {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('assoc', $result['definitions'][0]['synopsis']['type']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsRequiredFalseForOptionsWithDefaults(): void
    {
        $signature = 'command {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertFalse($result['definitions'][0]['required']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsOptionalTrueForOptionsWithDefaults(): void
    {
        $signature = 'command {--count=1:Number of items}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['synopsis']['optional']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsEmptyDefaultForRequiredOptions(): void
    {
        $signature = 'command {--apikey=:API key required}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('', $result['definitions'][0]['default']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsRequiredTrueForOptionsWithEmptyDefault(): void
    {
        $signature = 'command {--apikey=:API key required}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['required']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsOptionalFalseForRequiredOptions(): void
    {
        $signature = 'command {--apikey=:API key required}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertFalse($result['definitions'][0]['synopsis']['optional']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     * @dataProvider descriptionProvider
     */
    public function testParseSignatureExtractsDescription(string $signature, string $expectedDescription): void
    {
        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals($expectedDescription, $result['definitions'][0]['description']);
    }

    public static function descriptionProvider(): \Generator
    {
        yield 'option with description' => [
            'command {--count=1:Number of items to process}',
            'Number of items to process'
        ];
        yield 'flag with description' => [
            'command {--cascade:Enable cascade mode}',
            'Enable cascade mode'
        ];
        yield 'positional with description' => [
            'command {filename:The file to process}',
            'The file to process'
        ];
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsDescriptionInSynopsis(): void
    {
        $signature = 'command {--count=1:Number of items to process}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('Number of items to process', $result['definitions'][0]['synopsis']['description']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsIsOptionFalseForPositionalParameters(): void
    {
        $signature = 'command {filename:The file to process}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertFalse($result['definitions'][0]['isOption']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsRequiredTrueForPositionalParameters(): void
    {
        $signature = 'command {filename:The file to process}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['required']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsTypePositionalForPositionalParameters(): void
    {
        $signature = 'command {filename:The file to process}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals('positional', $result['definitions'][0]['synopsis']['type']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsRequiredFalseForOptionalPositionalParameters(): void
    {
        $signature = 'command {filename?:Optional file}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertFalse($result['definitions'][0]['required']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureSetsOptionalTrueForOptionalPositionalParameters(): void
    {
        $signature = 'command {filename?:Optional file}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertTrue($result['definitions'][0]['synopsis']['optional']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureParsesCorrectNumberOfMultipleParameters(): void
    {
        $signature = 'command {file:Input file} {--count=1:Count} {--cascade} {output?:Output file}';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertCount(4, $result['definitions']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     * @dataProvider camelCaseOptionProvider
     */
    public function testParseSignaturePreservesCamelCaseOptionNames(string $signature, string $expectedName): void
    {
        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEquals($expectedName, $result['definitions'][0]['name']);
    }

    public static function camelCaseOptionProvider(): \Generator
    {
        yield 'fullName' => ['command {--fullName=:Full name}', 'fullName'];
        yield 'apiKey' => ['command {--apiKey=:API key}', 'apiKey'];
        yield 'maxRetryCount' => ['command {--maxRetryCount=3:Max retries}', 'maxRetryCount'];
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::parseSignature
     */
    public function testParseSignatureHandlesSignatureWithNoParameters(): void
    {
        $signature = 'simple command';

        $result = $this->callInaccessibleMethod($this->strategy, 'parseSignature', $signature);

        $this->assertEmpty($result['definitions']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsResolvesFirstPositionalArgument(): void
    {
        $args = ['value1', 'value2'];
        $assocArgs = [];
        $definitions = [
            ['name' => 'first', 'isOption' => false, 'default' => null],
            ['name' => 'second', 'isOption' => false, 'default' => null],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals('value1', $result['first']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsResolvesSecondPositionalArgument(): void
    {
        $args = ['value1', 'value2'];
        $assocArgs = [];
        $definitions = [
            ['name' => 'first', 'isOption' => false, 'default' => null],
            ['name' => 'second', 'isOption' => false, 'default' => null],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals('value2', $result['second']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     * @dataProvider associativeOptionsProvider
     */
    public function testResolveInputParamsResolvesAssociativeOptions(
        array $assocArgs,
        array $definitions,
        string $paramName,
        $expectedValue
    ): void {
        $args = [];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals($expectedValue, $result[$paramName]);
    }

    public static function associativeOptionsProvider(): \Generator
    {
        yield 'count option' => [
            ['count' => '5', 'email' => 'test@example.com'],
            [
                ['name' => 'count', 'isOption' => true, 'default' => '1'],
                ['name' => 'email', 'isOption' => true, 'default' => null],
            ],
            'count',
            '5'
        ];
        yield 'email option' => [
            ['count' => '5', 'email' => 'test@example.com'],
            [
                ['name' => 'count', 'isOption' => true, 'default' => '1'],
                ['name' => 'email', 'isOption' => true, 'default' => null],
            ],
            'email',
            'test@example.com'
        ];
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsUsesDefaultValueWhenParamNotProvided(): void
    {
        $args = [];
        $assocArgs = [];
        $definitions = [
            ['name' => 'count', 'isOption' => true, 'default' => '10'],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals('10', $result['count']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsUsesNullDefaultWhenParamNotProvided(): void
    {
        $args = [];
        $assocArgs = [];
        $definitions = [
            ['name' => 'verbose', 'isOption' => true, 'default' => null],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertNull($result['verbose']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsResolvesPositionalWithMixedParameters(): void
    {
        $args = ['file.txt'];
        $assocArgs = ['count' => '3'];
        $definitions = [
            ['name' => 'filename', 'isOption' => false, 'default' => null],
            ['name' => 'count', 'isOption' => true, 'default' => '1'],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals('file.txt', $result['filename']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Strategies\ConsoleStrategy::resolveInputParams
     */
    public function testResolveInputParamsResolvesOptionWithMixedParameters(): void
    {
        $args = ['file.txt'];
        $assocArgs = ['count' => '3'];
        $definitions = [
            ['name' => 'filename', 'isOption' => false, 'default' => null],
            ['name' => 'count', 'isOption' => true, 'default' => '1'],
        ];

        $result = $this->callInaccessibleMethod(
            $this->strategy,
            'resolveInputParams',
            $args, $assocArgs, $definitions
        );

        $this->assertEquals('3', $result['count']);
    }
}
