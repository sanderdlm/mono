<?php

namespace Mono\Test;

use Laminas\Diactoros\Response\HtmlResponse;
use Mono\Mono;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class TwigController
{
    public function __construct(
        private readonly Mono $mono
    ) {
    }

    public function __invoke(RequestInterface $request, string $name): ResponseInterface
    {
        return new HtmlResponse($this->mono->render('index.html.twig', [
            'output' => 'Hello autotwig!',
        ]));
    }
}
