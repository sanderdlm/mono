<?php

declare(strict_types=1);

namespace Mono;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use DI\Container;
use FastRoute;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Relay\Relay;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

final class Mono
{
    /**
     * @var array <array{method: string|string[], path: string, handler: mixed}>
     */
    private array $routes = [];
    /**
     * @var array <MiddlewareInterface|callable>
     */
    private array $middleware = [];

    public function __construct(
        public readonly ?string $templateFolder = null,
        public readonly bool $debug = false,
        public readonly Container $container = new Container(),
        public readonly ?string $routeCacheFile = null
    ) {
        if ($this->templateFolder !== null && file_exists($this->templateFolder)) {
            $loader = new FilesystemLoader($this->templateFolder);
            $twig = new Environment($loader, ['debug' => $this->debug]);

            if ($this->debug) {
                $twig->addExtension(new DebugExtension());
            }

            $this->container->set(Environment::class, $twig);
        }

        if (!$this->container->has(TreeMapper::class)) {
            $mapper = (new MapperBuilder())
                ->allowSuperfluousKeys()
                ->enableFlexibleCasting()
                ->mapper();

            $this->container->set(TreeMapper::class, $mapper);
        }

        $this->container->set(Mono::class, $this);
    }

    /**
     * @param string|string[] $method
     */
    public function addRoute(string|array $method, string $path, mixed $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function addMiddleware(MiddlewareInterface|callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @return T
     */
    public function get(string $className)
    {
        return $this->container->get($className);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        try {
            $twig = $this->get(Environment::class);
        } catch (\Exception $e) {
            throw new \RuntimeException('Twig is not configured. Pass a template folder or your own Twig instance.');
        }

        return $twig->load($template)->render($data);
    }

    public function run(): void
    {
        /*
         * Mono is built as a middleware stack framework.
         * Out of the box, we ship 3 middlewares:
         *      1. Error handling
         *      2. Routing
         *      3. Request handling
         */

        /*
         * Middleware to catch exceptions in production mode and return a generic, 500 response.
         */
        $errorHandlingMiddleware = function (ServerRequestInterface $request, callable $next): ResponseInterface {
            try {
                return $next($request);
            } catch (\Throwable $e) {
                if ($this->debug) {
                    throw $e;
                }

                return new TextResponse('Something went wrong!', 500);
            }
        };

        // Set up our FastRoute dispatcher
        if ($this->routeCacheFile !== null) {
            $dispatcher = FastRoute\cachedDispatcher(function (FastRoute\RouteCollector $r) {
                foreach ($this->routes as $route) {
                    $r->addRoute($route['method'], $route['path'], $route['handler']);
                }
            }, [
                'cacheFile' => $this->routeCacheFile,
                'cacheDisabled' => false,
            ]);
        } else {
            $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
                foreach ($this->routes as $route) {
                    $r->addRoute($route['method'], $route['path'], $route['handler']);
                }
            });
        }

        /*
         * Middleware to match the incoming request to a handler.
         * This can be a callable or an invokable controller.
         * Does not execute the handler yet, only stores it in the request attributes.
         */
        $routingMiddleware = function (
            ServerRequestInterface $request,
            callable $next
        ) use ($dispatcher): ResponseInterface {
            $route = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

            if ($route[0] === FastRoute\Dispatcher::NOT_FOUND) {
                return new TextResponse('Not found', 404);
            }

            if ($route[0] === FastRoute\Dispatcher::METHOD_NOT_ALLOWED) {
                return new TextResponse('Method not allowed', 405, [
                    'Allow', implode(', ', $route[1])
                ]);
            }

            $request = $request
                ->withAttribute('request-handler', $route[1])
                ->withAttribute('request-parameters', $route[2]);

            return $next($request);
        };

        /*
         * Middleware to execute the handler and return a response.
         */
        $requestHandlerMiddleware = function (ServerRequest $request, callable $next): ResponseInterface {
            $requestHandler = $request->getAttribute('request-handler');
            $parameters = $request->getAttribute('request-parameters');

            assert(is_callable($requestHandler), 'Invalid request handler.');
            assert(is_array($parameters), 'Invalid request parameters.');

            // Execute the handler and get the response back
            $response = call_user_func_array($requestHandler, [$request, ...$parameters]);

            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Invalid response received from route ' . $request->getUri()->getPath() .
                    '. Please return a valid PSR-7 response from your handler.');
            }

            return $response;
        };

        /*
         * Set up all the middleware in the correct order.
         * Custom middleware is added after the error handling
         * & route matching, but before the request handler middleware.
         */
        $this->middleware = [
            $errorHandlingMiddleware,
            $routingMiddleware,
            ...$this->middleware,
            $requestHandlerMiddleware
        ];

        // Register & execute the middleware stack
        $requestHandler = new Relay($this->middleware);
        $response = $requestHandler->handle(ServerRequestFactory::fromGlobals());

        // 💨
        (new SapiEmitter())->emit($response);
    }
}
