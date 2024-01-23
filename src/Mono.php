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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        if (!$this->twig instanceof Environment) {
            throw new \RuntimeException('Twig is not configured. Please provide a template folder in the constructor.');
        }

        $template = $this->twig->load($template);

        $output = $template->render($data);

        return $this->createResponse($output);
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

    public function createResponse(string $body, int $status = 200): Response
    {
        return (new Response($status))->withBody((new Psr17Factory())->createStream($body));
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

        try {
            $response = match ($route[0]) {
                FastRoute\Dispatcher::NOT_FOUND => $this->createResponse('Not found', 404),
                FastRoute\Dispatcher::METHOD_NOT_ALLOWED => $this->createResponse('Method not allowed', 405),
                FastRoute\Dispatcher::FOUND => call_user_func_array($route[1], [$request, ...$route[2]]),
                default => $this->createResponse('Something went wrong', 500)
            };
        } catch (\Exception $exception) {
            if ($this->debug) {
                throw $exception;
            }

            $response = $this->createResponse('Something went wrong', 500);
        }

        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('Invalid response received from route ' . $request->getUri()->getPath() .
                '. Please return a valid PSR-7 response from your handler.');
        }

        (new SapiEmitter())->emit($response);
    }
}
