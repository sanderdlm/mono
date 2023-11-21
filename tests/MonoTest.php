<?php

namespace Mono\Test;

use Mono\Mono;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;

class MonoTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = __DIR__;
    }

    public function testRouting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono();

        $mono->addRoute('GET', '/', function () {
            return 'Hello, world!';
        });

        $output = $mono->run();

        $this->assertEquals('Hello, world!', $output);
    }

    public function testTwigRendering(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono();

        $mono->addRoute('GET', '/', function () use ($mono) {
            return $mono->render('index.html.twig', [
                'output' => 'Hello, world!',
            ]);
        });

        $output = $mono->run();

        $this->assertEquals('Hello, world!', $output);
    }

    public function testDependencyInjection(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $mono = new Mono();

        $mono->addRoute('GET', '/', function () use ($mono) {
            $demoClass = $mono->get(NodeTraverser::class);

            $this->assertInstanceOf(NodeTraverser::class, $demoClass);
        });

        $mono->run();
    }
}
