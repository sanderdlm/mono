<?php

declare(strict_types=1);

namespace Mono;

use Attribute;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\Tree\Message\Messages;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use DI\Container;
use FastRoute;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionNamedType;
use Relay\Relay;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

final class Mono
{
    private Container $container;
    private ?Environment $twig = null;
    /**
     * @var array <array{method: string|string[], path: string, handler: callable}>
     */
    private array $routes = [];
    /**
     * @var array <MiddlewareInterface|callable>
     */
    private array $middleware = [];
    private bool $debug;

    public function __construct(
        string $templateFolder = null,
        bool $debug = false,
        Container $container = null
    ) {
        // Set the debug mode on our Mono object
        $this->debug = $debug;
        // Initialize a PHP-DI container with default configuration
        $this->container = $container ?? new Container();

        // If a template folder was passed, initialize Twig
        if ($templateFolder !== null && file_exists($templateFolder)) {
            $loader = new FilesystemLoader($templateFolder);
            $this->twig = new Environment($loader, ['debug' => $this->debug]);
            if ($this->debug) {
                $this->twig->addExtension(new DebugExtension());
            }

            // If a translator is present in the container, pass it along to Twig
            if ($this->container->has(TranslatorInterface::class)) {
                $this->twig->addExtension(new TranslationExtension($this->get(TranslatorInterface::class)));
            }

            // Pass the Mono object to the container.
            // This allows autowired controllers access to it.
            $this->container->set(Mono::class, $this);
        }

        if (!$this->container->has(TreeMapper::class)) {
            $mapper = (new MapperBuilder())
                ->allowSuperfluousKeys()
                ->enableFlexibleCasting()
                ->mapper();

            $this->container->set(TreeMapper::class, $mapper);
        }
    }

    /**
     * @param string|string[] $method
     */
    public function addRoute(string|array $method, string $path, callable $handler): void
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
    public function render(string $template, array $data = []): ResponseInterface
    {
        if (!$this->twig instanceof Environment) {
            throw new \RuntimeException('Twig is not configured. Please provide a template folder in the constructor.');
        }

        $template = $this->twig->load($template);

        return $this->createResponse(200, $template->render($data));
    }

    public function createResponse(int $status, ?string $body = null): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($status);

        if ($body === null) {
            return $response;
        }

        return $response->withBody((new StreamFactory())->createStream($body));
    }

    public function run(): void
    {
        /*
         * Mono is built as a middleware stack framework.
         * Out of the box, we ship 4 middlewares:
         *      1. Error handling
         *      2. Routing
         *      3. Attribute mapping (for our MapTo attribute)
         *      4. Request handling
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

                return $this->createResponse(500, 'Something went wrong!');
            }
        };

        // Set up our FastRoute dispatcher
        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route['handler']);
            }
        });

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
                return $this->createResponse(404);
            }

            if ($route[0] === FastRoute\Dispatcher::METHOD_NOT_ALLOWED) {
                return $this->createResponse(405)->withHeader('Allow', implode(', ', $route[1]));
            }

            $request = $request
                ->withAttribute('request-handler', $route[1])
                ->withAttribute('request-parameters', $route[2]);

            return $next($request);
        };

        $attributeMappingMiddleware = function (ServerRequest $request, callable $next): ResponseInterface {
            $requestHandler = $request->getAttribute('request-handler');
            $parameters = $request->getAttribute('request-parameters');
            $body = $request->getParsedBody();

            assert(is_array($parameters), 'Invalid request parameters.');
            assert(is_array($body), 'Invalid request body.');

            /*
             * We need a Reflection instance of the request handler,
             * either a closure or an invokable class,
             * to check for a MapTo attribute.
             */
            if ($requestHandler instanceof \Closure) {
                $reflector = new \ReflectionFunction($requestHandler);
            } elseif (is_object($requestHandler)) {
                $reflectedClass = new \ReflectionClass($requestHandler);
                $reflector = $reflectedClass->getMethod('__invoke');
            } else {
                throw new \RuntimeException('Invalid request handler passed. Must be a closure or an invokable class.');
            }

            // Loops over the handler's parameters to check for attributes
            foreach ($reflector->getParameters() as $parameter) {
                $name = $parameter->getName();
                $attributes = $parameter->getAttributes();

                // If there are no attributes, just skip
                if (count($attributes) === 0) {
                    continue;
                }

                foreach ($attributes as $attribute) {
                    // If we encounter a different attribute, skip
                    if ($attribute->getName() !== MapTo::class) {
                        continue;
                    }

                    if (!$parameter->getType() instanceof ReflectionNamedType) {
                        throw new \RuntimeException(sprintf(
                            'Missing class/type on parameter with MapTo attribute: %s',
                            $name
                        ));
                    }

                    $class = $parameter->getType()->getName();

                    try {
                        $parameters[$name] = $this->get(TreeMapper::class)->map($class, Source::array($body));
                    } catch (MappingError $exception) {
                        $messages = Messages::flattenFromNode(
                            $exception->node()
                        );

                        $errorMessages = $messages->errors();

                        throw new \RuntimeException(sprintf(
                            'Mapping errors on %s: %s',
                            $class,
                            implode(',', $errorMessages->toArray())
                        ));
                    }

                    // We only map to a single object, no need to go further
                    break;
                }
            }

            $request = $request->withAttribute('request-parameters', $parameters);

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
            $attributeMappingMiddleware,
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

// phpcs:disable
#[Attribute]
class MapTo
{
}
