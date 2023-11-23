<?php

declare(strict_types=1);

namespace Mono;

use DI\Container;
use FastRoute;
use Psr\Container\ContainerInterface;
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
    public function render(string $template, array $data = []): string
    {
        $template = $this->twig->load($template);

        return $template->render($data);
    }

    public function get(string $className): mixed
    {
        return $this->container->get($className);
    }

    public function run(): mixed
    {
        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route['handler']);
            }
        });

        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];

        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        $routeInfo = $dispatcher->dispatch($httpMethod, $uri);

        return match ($routeInfo[0]) {
            FastRoute\Dispatcher::NOT_FOUND => '404 Not Found',
            FastRoute\Dispatcher::METHOD_NOT_ALLOWED => '405 Method Not Allowed',
            FastRoute\Dispatcher::FOUND => call_user_func_array($routeInfo[1], $routeInfo[2]),
            default => throw new \RuntimeException('Something went wrong'),
        };
    }
}
