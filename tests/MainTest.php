<?php

namespace Mono\Test;

use CuyZ\Valinor\Mapper\TreeMapper;
use DI\ContainerBuilder;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\TextResponse;
use Mono\Mono;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;

class MainTest extends TestCase
{
    private function catchOutput(callable $run): string
    {
        ob_start();

        $run();

        $output = ob_get_contents();

        ob_end_clean();

        return !$output ? '' : $output;
    }

    public function testWithoutRoutes(): void
    {
        $mono = new Mono();

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Not found', $output);
    }

    public function testRouting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/', function () {
            return new TextResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testRoutingWithParameters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/books/123';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/books/{book}', function (RequestInterface $request, string $book) {
            return new TextResponse('Book: ' . $book);
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Book: 123', $output);
    }

    public function testTwigRendering(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates', true);

        $mono->addRoute('GET', '/', function () use ($mono) {
            return new HtmlResponse($mono->render('index.html.twig', [
                'output' => 'Hello, world!',
            ]));
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testDependencyInjection(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/', function () use ($mono) {
            $demoClass = $mono->get(Mono::class);

            $this->assertInstanceOf(Mono::class, $demoClass);

            return new TextResponse('');
        });

        $mono->run();
    }

    public function testInvalidResponseInDebugThrowError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates', true);

        $mono->addRoute('GET', '/', function () {
            return 'OK';
        });

        $this->expectException(\RuntimeException::class);

        $mono->run();
    }

    public function testDebugFalseReturnsGenericErrorMessage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/', function () {
            throw new \Exception('Developer error');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Something went wrong!', $output);
    }

    public function testTwigRenderWithoutTemplateFolderThrowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(debug: true);

        $mono->addRoute('GET', '/', function () use ($mono) {
            return new HtmlResponse($mono->render('index.html.twig', [
                'output' => 'Hello, world!',
            ]));
        });

        $this->expectException(\RuntimeException::class);

        $mono->run();
    }

    public function testRouteWithController(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test/foobar';

        $mono = new Mono();

        $mono->addRoute('GET', '/test/{name}', new TestController($mono));

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello foobar!', $output);
    }

    public function testRouteWithAutowiredController(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test/autowired';

        $mono = new Mono();

        $mono->addRoute('GET', '/test/{name}', $mono->get(TestController::class));

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello autowired!', $output);
    }

    public function testRouteWithAutowiredControllerAndTwig(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test/autotwig';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/test/{name}', $mono->get(TwigController::class));

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello autotwig!', $output);
    }

    public function testCallableMiddleware(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates', true);

        $mono->addMiddleware(function (ServerRequestInterface $request, callable $next) {
            $next($request);

            return new TextResponse('Some new content');
        });

        $mono->addRoute('GET', '/', function () {
            return new TextResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Some new content', $output);
    }

    public function testRequestHandlerIsAvailableInMiddleware(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates', true);

        $mono->addMiddleware(function (ServerRequestInterface $request, callable $next) {
            $handler = $request->getAttribute('request-handler');

            $this->assertNotNull($handler);

            return $next($request);
        });

        $mono->addRoute('GET', '/', function () {
            return new TextResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testPostRequestMappingToObject(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/book';
        $_POST['title'] = 'Moby dick';
        $_POST['gender'] = 'male';
        $_POST['published'] = (new \DateTimeImmutable('2014/05/12'))->format(DATE_ATOM);

        $mono = new Mono(__DIR__ . '/templates', true);

        $mono->addRoute('POST', '/book', function (
            ServerRequestInterface $request,
        ) use ($mono) {
            $bookDataTransferObject = $mono->get(TreeMapper::class)->map(
                BookDataTransferObject::class,
                $request->getParsedBody()
            );

            $this->assertEquals('Moby dick', $bookDataTransferObject->title);
            $this->assertEquals(Gender::MALE, $bookDataTransferObject->gender);
            $this->assertEquals(new \DateTimeImmutable('2014/05/12'), $bookDataTransferObject->published);

            return new TextResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testCustomContainer(): void
    {
        $someClass = new BookDataTransferObject(
            'Testing with Sander',
            Gender::MALE,
            new \DateTimeImmutable(),
            1
        );

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->useAutowiring(true);
        $containerBuilder->addDefinitions([
            BookDataTransferObject::class => $someClass
        ]);

        $mono = new Mono(
            templateFolder: __DIR__ . '/../templates',
            debug: true,
            container: $containerBuilder->build()
        );

        $theSameClass = $mono->get(BookDataTransferObject::class);
        $this->assertInstanceOf(BookDataTransferObject::class, $theSameClass);
        $this->assertEquals('Testing with Sander', $theSameClass->title);
        $this->assertEquals(Gender::MALE, $theSameClass->gender);
        $this->assertEquals(1, $theSameClass->rating);
    }
}
