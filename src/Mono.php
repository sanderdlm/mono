<?php

declare(strict_types=1);

namespace Mono;

use DI\Container;
use FastRoute;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

/*
 * Mono is a tiny, single-class PHP framework for writing single-page PHP apps.
 * It shines when quickly developing small tools with limited scope.
 * In +- 70 LOC, you get basic routing (using FastRoute), DI (using PHP-DI),
 * and Twig templating. Anything else you need, you have to bring yourself.
 */
final class Mono
{
    private ContainerInterface $container;
    private Environment $twig;
    /**
     * @var array <array{method: string, path: string, handler: callable}>
     */
    private array $routes = [];

    public function __construct(string $documentRoot)
    {
        if (!file_exists($documentRoot . '/../templates')) {
            throw new \RuntimeException('Templates directory not found, please create one.');
        }

        $loader = new FilesystemLoader($documentRoot . '/../templates');
        $this->twig = new Environment($loader, ['debug' => true]);
        $this->twig->addExtension(new DebugExtension());

        $this->container = new Container();
    }

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): ResponseInterface
    {
        $template = $this->twig->load($template);

        $output = $template->render($data);

        return $this->createResponse($output);
    }

    public function get(string $className): mixed
    {
        return $this->container->get($className);
    }

    public function createResponse(string $body, int $status = 200): Response
    {
        $psr17Factory = new Psr17Factory();

        return (new Response($status))->withBody($psr17Factory->createStream($body));
    }

    public function run(): void
    {
        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route['handler']);
            }
        });

        $psr17Factory = new Psr17Factory();

        $creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $request = $creator->fromGlobals();

        $route = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        /** @var ?ResponseInterface $response */
        $response = match ($route[0]) {
            FastRoute\Dispatcher::NOT_FOUND => $this->createResponse('404 Not Found', 404),
            FastRoute\Dispatcher::METHOD_NOT_ALLOWED => $this->createResponse('405 Method Not Allowed', 405),
            FastRoute\Dispatcher::FOUND => call_user_func_array($route[1], [$request, ...$route[2]]),
            default => $this->createResponse('500 Internal Server Error', 500)
        };

        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('Invalid response received from route ' . $request->getUri()->getPath() .
                '. Please return a valid PSR-7 response from your handler.');
        }

        (new SapiEmitter())->emit($response);
    }
}
