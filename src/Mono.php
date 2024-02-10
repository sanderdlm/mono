<?php

declare(strict_types=1);

namespace Mono;

use DI\Container;
use FastRoute;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Relay\Relay;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

final class Mono
{
    private ContainerInterface $container;
    private ?Environment $twig = null;
    /**
     * @var array <array{method: string, path: string, handler: callable}>
     */
    private array $routes = [];
    /**
     * @var array <MiddlewareInterface|callable>
     */
    private array $middlewares = [];
    private bool $debug;

    public function __construct(string $templateFolder = null, bool $debug = false)
    {
        $this->debug = $debug;
        $this->container = new Container();

        if ($templateFolder !== null && file_exists($templateFolder)) {
            $loader = new FilesystemLoader($templateFolder);
            $this->twig = new Environment($loader, ['debug' => $this->debug]);
            if ($this->debug) {
                $this->twig->addExtension(new DebugExtension());
            }

            /*
             * This allows autowired controllers to access the original Mono object.
             */
            $this->container->set(Mono::class, $this);
        }
    }

    private function initMiddleware(): void
    {
        $errorHandlingMiddleware = function (ServerRequestInterface $request, callable $next): ResponseInterface {
            try {
                return $next($request);
            } catch (\Throwable $e) {
                if ($this->debug) {
                    throw $e;
                }

                return $this->createResponse(500, 'An error occurred: ' . $e->getMessage());
            }
        };

        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route['handler']);
            }
        });

        $routingMiddleware = function (
            ServerRequestInterface $request,
            callable $next
        ) use ($dispatcher): ResponseInterface {
            $route = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

            if ($route[0] === FastRoute\Dispatcher::NOT_FOUND) {
                return $this->createResponse(404);
            }

            if ($route[0] === FastRoute\Dispatcher::METHOD_NOT_ALLOWED) {
                return $this->createResponse(405)->withHeader('Allow', implode(', ', $route[1]));
            }

            foreach ($route[2] as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

            $request = $request
                ->withAttribute('request-handler', $route[1])
                ->withAttribute('request-parameters', $route[2]);

            return $next($request);
        };

        $requestHandlerMiddleware = function (ServerRequestInterface $request, callable $next): ResponseInterface {
            $requestHandler = $request->getAttribute('request-handler');
            $parameters = $request->getAttribute('request-parameters');

            assert(is_callable($requestHandler), 'Invalid request handler.');
            assert(is_array($parameters), 'Invalid request parameters.');

            $response = call_user_func_array($requestHandler, [$request, ...$parameters]);

            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Invalid response received from route ' . $request->getUri()->getPath() .
                    '. Please return a valid PSR-7 response from your handler.');
            }

            return $response;
        };

        $this->middlewares = [
            $errorHandlingMiddleware,
            $routingMiddleware,
            ...$this->middlewares,
            $requestHandlerMiddleware
        ];
    }

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function addMiddleware(MiddlewareInterface|callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): ResponseInterface
    {
        if (!$this->twig instanceof Environment) {
            throw new \RuntimeException('Twig is not configured. Please provide a template folder in the constructor.');
        }

        $template = $this->twig->load($template);

        return $this->createResponse(200, $template->render($data));
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

    public function createResponse(int $status, ?string $body = null): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($status);

        if ($body !== null) {
            return $response->withBody((new StreamFactory())->createStream($body));
        } else {
            return $response;
        }
    }

    public function run(): void
    {
        $this->initMiddleware();

        $requestHandler = new Relay($this->middlewares);
        $response = $requestHandler->handle(ServerRequestFactory::fromGlobals());

        (new SapiEmitter())->emit($response);
    }
}
