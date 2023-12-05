# Mono

Mono is a tiny, single-class PHP framework for writing single-page PHP apps.
It shines when quickly developing small tools with limited scope.
In +- 100 LOC, you get basic routing (using FastRoute), DI (using PHP-DI),
a PSR-7 implementation and Twig templating.

## Routing
Mono's routing implementation shares 90% of its code with the ['basic usage example'](https://github.com/nikic/FastRoute#usage) from the FastRoute documentation.

You use `$mono->addRoute()` to add all your routes. Same method signature as the FastRoute method. Route handlers are closures by default, since this is mainly intended as a single-page framework, but you can use invokable controllers as well.

Read about the route pattern in the [FastRoute documentation](https://github.com/nikic/FastRoute#defining-routes). The entered path is passed directly to FastRoute.

The first argument to the closure is the always current request, which is a [PSR-7 ServerRequestInterface](https://github.com/php-fig/http-message/blob/master/src/ServerRequestInterface.php) object. After that, the next arguments are the route parameters.

When `$mono->run()` is called, the current request is matched against the routes you added, the closure is invoked and the response is emitted.

### Example with closure

```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/books/{book}', function(RequestInterface $request, string $book) use ($mono) {
    return $mono->createResponse('Book: ' . $book);
});

$mono->run();
```

### Example with controller
```php
<?php

class BookController
{
    public function __construct(
        private readonly Mono $mono
    ) {
    }

    public function __invoke(RequestInterface $request, string $book): ResponseInterface
    {
        return $this->mono->createResponse('Book: ' . $book');
    }
}
```
```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/books/{book}', new BookController($mono));

$mono->run();
```
## DI

When a Mono object is created, it constructs a basic PHP-DI container with default configuration. This means dependencies from your vendor folder are autowired.

You can fetch instances from the container with the `get()` method on your Mono object.

```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/example', function() use ($mono) {
    $result = $mono->get(SomeDependency::class)->doSomething();
    
    return $mono->createResponse(json_encode($result));
});

$mono->run();
```

## Twig

Mono comes with Twig out-of-the-box. If you want to use Twig, you have to pass the path to your templates folder in the Mono constructor.

Afterward, you can use the `render()` method on your Mono object to render a Twig template from that folder.

```php
<?php

$mono = new Mono(__DIR__ . '/templates');

$mono->addRoute('GET', '/example', function() use ($mono) {
    $result = $mono->get(SomeDependency::class)->doSomething();
    
    return $mono->render('example.twig', [
        'result' => $result
    ]);
});

$mono->run();
````

Templates go in the `/templates` folder in the project root.