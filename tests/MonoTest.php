<?php

namespace Mono\Test;

use Mono\Mono;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class MonoTest extends TestCase
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

        $mono = new Mono(__DIR__);

        $mono->addRoute('GET', '/', function (RequestInterface $request) use ($mono) {
            return $mono->createResponse('Hello, world!');
        });

        $output = $this->catchOutput(fn() => $mono->run());

        $this->assertEquals('Hello, world!', $output);
    }

    public function testRoutingWithParameters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/books/123';

        $mono = new Mono(__DIR__);

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

        $mono = new Mono(__DIR__);

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

        $mono = new Mono(__DIR__);

        $mono->addRoute('GET', '/', function () use ($mono) {
            $demoClass = $mono->get(NodeTraverser::class);

            $this->assertInstanceOf(NodeTraverser::class, $demoClass);

            return $mono->createResponse('OK');
        });

        $mono->run();
    }

    public function testInvalidResponseThrowError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono(__DIR__);

        $mono->addRoute('GET', '/', function () {
            return 'OK';
        });

        $this->expectException(\RuntimeException::class);

        $mono->run();
    }
}
