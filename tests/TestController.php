<?php

namespace Mono\Test;

use Laminas\Diactoros\Response\TextResponse;
use Mono\Mono;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class TestController
{
    public function __construct(
        private readonly Mono $mono
    ) {
    }

    public function __invoke(RequestInterface $request, string $name): ResponseInterface
    {
        if (!$this->mono instanceof Mono) {
            throw new \RuntimeException('Autowiring broken in test controller.');
        }

        return new TextResponse('Hello ' . $name . '!');
    }
}
