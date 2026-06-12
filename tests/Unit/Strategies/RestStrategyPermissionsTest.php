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
}
