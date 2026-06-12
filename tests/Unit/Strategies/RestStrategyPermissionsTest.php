<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use Exception;
use PHPNomad\Auth\Interfaces\CurrentUserResolverStrategy;
use PHPNomad\Http\Interfaces\Request;
use PHPNomad\Http\Interfaces\Response;
use PHPNomad\Integrations\WordPress\Strategies\RestStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Rest\Exceptions\RestException;
use PHPNomad\Rest\Interfaces\Controller;
use PHPNomad\Rest\Interfaces\HasMiddleware;
use PHPNomad\Rest\Interfaces\HasRestNamespace;
use PHPNomad\Rest\Interfaces\Middleware;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;

/**
 * Pins the derived permission-callback contract (#31):
 * - controllers without middleware stay public,
 * - 401/403 middleware rejections become WP_Error at the permission gate,
 * - non-auth middleware failures defer to the route callback (legacy shape),
 * - the middleware chain runs exactly once per request.
 */
class RestStrategyPermissionsTest extends TestCase
{
    private function makeStrategy(): RestStrategy
    {
        return new RestStrategy(
            $this->createMock(HasRestNamespace::class),
            $this->createMock(Response::class),
            $this->createMock(CurrentUserResolverStrategy::class),
            $this->createMock(LoggerStrategy::class)
        );
    }

    private function checkPermissions(RestStrategy $strategy, Controller $controller, WP_REST_Request $request)
    {
        $method = new ReflectionMethod($strategy, 'checkPermissions');

        return $method->invoke($strategy, $controller, $request);
    }

    private function makeControllerWithMiddleware(Middleware $middleware): Controller
    {
        return new class($middleware) implements Controller, HasMiddleware {
            public function __construct(private Middleware $middleware)
            {
            }

            public function getEndpoint(): string
            {
                return '/test';
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getResponse(Request $request): Response
            {
                throw new Exception('not used');
            }

            public function getMiddleware(Request $request): array
            {
                return [$this->middleware];
            }
        };
    }

    public function testControllerWithoutMiddlewareIsPublic(): void
    {
        $controller = $this->createMock(Controller::class);

        $result = $this->checkPermissions($this->makeStrategy(), $controller, new WP_REST_Request());

        $this->assertTrue($result);
    }

    public function testUnauthorizedMiddlewareRejectionBecomesWpError(): void
    {
        $middleware = new class implements Middleware {
            public function process(Request $request): void
            {
                throw new RestException('You are not allowed to do that.', [], 403);
            }
        };

        $result = $this->checkPermissions($this->makeStrategy(), $this->makeControllerWithMiddleware($middleware), new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testNonAuthMiddlewareFailurePassesPermissionGate(): void
    {
        $middleware = new class implements Middleware {
            public function process(Request $request): void
            {
                throw new RestException('Validation failed.', [], 422);
            }
        };

        $result = $this->checkPermissions($this->makeStrategy(), $this->makeControllerWithMiddleware($middleware), new WP_REST_Request());

        $this->assertTrue($result);
    }

    public function testMiddlewareRunsExactlyOnceAcrossPermissionAndCallback(): void
    {
        $middleware = new class implements Middleware {
            public int $runs = 0;

            public function process(Request $request): void
            {
                $this->runs++;
            }
        };

        $controller = $this->makeControllerWithMiddleware($middleware);
        $strategy = $this->makeStrategy();
        $wpRequest = new WP_REST_Request();

        $this->checkPermissions($strategy, $controller, $wpRequest);

        $wrap = new ReflectionMethod($strategy, 'wrapCallback');
        $resolve = new ReflectionMethod($strategy, 'resolveRequest');

        try {
            $wrap->invoke($strategy, $controller, $resolve->invoke($strategy, $wpRequest), $wpRequest);
        } catch (Exception $e) {
            // getResponse() intentionally throws; middleware count is the assertion target.
        }

        $this->assertSame(1, $middleware->runs);
    }

    public function testRepeatedPermissionChecksRunMiddlewareOnlyOnce(): void
    {
        // WordPress invokes permission_callback again per method on the
        // matched route while building the Allow header
        // (rest_send_allow_header), so the chain must be memoized —
        // middleware is not required to be idempotent.
        $middleware = new class implements Middleware {
            public int $runs = 0;

            public function process(Request $request): void
            {
                $this->runs++;

                if ($this->runs > 1) {
                    throw new \TypeError('non-idempotent middleware ran twice');
                }
            }
        };

        $controller = $this->makeControllerWithMiddleware($middleware);
        $strategy = $this->makeStrategy();
        $wpRequest = new WP_REST_Request();

        $first = $this->checkPermissions($strategy, $controller, $wpRequest);
        $second = $this->checkPermissions($strategy, $controller, $wpRequest);

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(1, $middleware->runs);
    }

    public function testRepeatedAuthRejectionReturnsSameWpErrorWithoutRerun(): void
    {
        $middleware = new class implements Middleware {
            public int $runs = 0;

            public function process(Request $request): void
            {
                $this->runs++;
                throw new RestException('Forbidden.', [], 403);
            }
        };

        $controller = $this->makeControllerWithMiddleware($middleware);
        $strategy = $this->makeStrategy();
        $wpRequest = new WP_REST_Request();

        $first = $this->checkPermissions($strategy, $controller, $wpRequest);
        $second = $this->checkPermissions($strategy, $controller, $wpRequest);

        $this->assertInstanceOf(WP_Error::class, $first);
        $this->assertInstanceOf(WP_Error::class, $second);
        $this->assertSame(1, $middleware->runs);
    }

    public function testOutcomeIsKeyedPerControllerNotPerRequest(): void
    {
        // rest_send_allow_header checks EVERY method registered on the
        // route against the same WP_REST_Request; one controller's failed
        // middleware must not clobber another controller's passing outcome.
        $passing = new class implements Middleware {
            public function process(Request $request): void
            {
            }
        };
        $failing = new class implements Middleware {
            public function process(Request $request): void
            {
                throw new RestException('Validation failed.', [], 422);
            }
        };

        $getController = $this->makeControllerWithMiddleware($passing);
        $postController = $this->makeControllerWithMiddleware($failing);
        $strategy = $this->makeStrategy();
        $wpRequest = new WP_REST_Request();

        $this->assertTrue($this->checkPermissions($strategy, $getController, $wpRequest));
        $this->assertTrue($this->checkPermissions($strategy, $postController, $wpRequest));

        // The GET controller's stored outcome must still be a clean pass:
        // wrapCallback must NOT throw the POST controller's 422.
        $wrap = new ReflectionMethod($strategy, 'wrapCallback');
        $resolve = new ReflectionMethod($strategy, 'resolveRequest');

        try {
            $wrap->invoke($strategy, $getController, $resolve->invoke($strategy, $wpRequest), $wpRequest);
            $this->fail('Expected getResponse() sentinel exception.');
        } catch (RestException $e) {
            $this->fail('GET controller inherited the POST controller\'s middleware failure.');
        } catch (Exception $e) {
            $this->assertSame('not used', $e->getMessage());
        }
    }

    public function testMiddlewareTypeErrorBecomesWpErrorInsteadOfFatal(): void
    {
        $middleware = new class implements Middleware {
            public function process(Request $request): void
            {
                throw new \TypeError('explode(): Argument #2 ($string) must be of type string, array given');
            }
        };

        $result = $this->checkPermissions($this->makeStrategy(), $this->makeControllerWithMiddleware($middleware), new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
    }
}
