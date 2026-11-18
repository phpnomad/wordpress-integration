<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Rest;

use PHPNomad\Integrations\WordPress\Rest\Request;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use WP_REST_Request;

/**
 * Pins how the WordPress request reads params a client sent.
 *
 * hasParam() answers "did the client send this key", so a key sent as null is
 * present. getParam() returns the value, or null when the key was not sent.
 * A dot in a name walks into nested arrays for both methods.
 */
class RequestTest extends TestCase
{
    private function makeRequest(array $params): Request
    {
        $wpRequest = $this->createMock(WP_REST_Request::class);
        $wpRequest->method('get_params')->willReturn($params);

        return new Request($wpRequest);
    }

    /**
     * @dataProvider paramCases
     */
    public function testHasParamReportsWhetherTheKeyWasSent(array $params, string $name, bool $expectedHas, $expectedValue): void
    {
        $this->assertSame($expectedHas, $this->makeRequest($params)->hasParam($name));
    }

    /**
     * @dataProvider paramCases
     */
    public function testGetParamReturnsTheSentValue(array $params, string $name, bool $expectedHas, $expectedValue): void
    {
        $this->assertSame($expectedValue, $this->makeRequest($params)->getParam($name));
    }

    public static function paramCases(): iterable
    {
        yield 'key sent as null' => [['description' => null], 'description', true, null];
        yield 'key absent' => [['other' => 'value'], 'description', false, null];
        yield 'key sent as false' => [['enabled' => false], 'enabled', true, false];
        yield 'key sent as 0' => [['count' => 0], 'count', true, 0];
        yield 'key sent as an empty string' => [['name' => ''], 'name', true, ''];
        yield 'key sent as an empty array' => [['ids' => []], 'ids', true, []];
        yield 'nested key by dot path' => [['user' => ['name' => 'Ada']], 'user.name', true, 'Ada'];
        yield 'nested key sent as null' => [['user' => ['name' => null]], 'user.name', true, null];
        yield 'nested key absent' => [['user' => ['name' => 'Ada']], 'user.email', false, null];
        yield 'null parent of a nested key' => [['user' => null], 'user.name', false, null];
        yield 'scalar parent of a nested key' => [['user' => 'Ada'], 'user.name', false, null];
        yield 'dot always walks, never matches a literal dotted key' => [['user.name' => 'Ada'], 'user.name', false, null];
    }

    public function testGetParamsKeepsKeysSentAsNull(): void
    {
        $wpRequest = $this->createMock(WP_REST_Request::class);
        $wpRequest->method('get_params')->willReturn(['description' => 'old', 'groupId' => null]);

        $request = new Request($wpRequest);

        $this->assertTrue($request->hasParam('groupId'));
        $this->assertSame(['description' => 'old', 'groupId' => null], $request->getParams());
    }
}
