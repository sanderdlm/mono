<?php

namespace Mono\Test;

use Mono\Mono;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

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

    public function testRouting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/', function () use ($mono) {
            return $mono->createResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testRoutingWithParameters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/books/123';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/books/{book}', function (RequestInterface $request, string $book) use ($mono) {
            return $mono->createResponse('Book: ' . $book);
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Book: 123', $output);
    }

    public function testTwigRendering(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__ . '/templates');

        $mono->addRoute('GET', '/', function () use ($mono) {
            return $mono->render('index.html.twig', [
                'output' => 'Hello, world!',
            ]);
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

            return $mono->createResponse('');
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

        $this->assertEquals('Something went wrong', $output);
    }

    public function testTwigRenderWithoutTemplateFolderThrowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(debug: true);

        $mono->addRoute('GET', '/', function () use ($mono) {
            return $mono->render('index.html.twig', [
                'output' => 'Hello, world!',
            ]);
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

        $mono->addRoute('GET', '/test/{name}', new TestController($mono));

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
}
