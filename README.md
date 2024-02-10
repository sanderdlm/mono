# Mono

Mono is a tiny, single-class PHP framework that combines multiple great projects from the PHP ecosystem to bring you as many features as possible in a small package.

In +-180 LOC, you get:
1. Routing (using FastRoute)
2. Dependency injection (using PHP-DI)
3. Middlewares (using relay/relay)
4. Templating (using Twig)

## 1. Routing
You use `$mono->addRoute()` to add all your routes. Same method signature as the underlying FastRoute method. Route handlers are closures by default, since this is mainly intended as a framework for small apps, but you can use invokable controllers as well.

Read about the route pattern in the [FastRoute documentation](https://github.com/nikic/FastRoute#defining-routes). The entered path is passed directly to FastRoute.

The first argument to the closure is the always current request, which is a [PSR-7 ServerRequestInterface](https://github.com/php-fig/http-message/blob/master/src/ServerRequestInterface.php) object. After that, the next arguments are the route parameters.

When `$mono->run()` is called, the current request is matched against the routes you added, the closure is invoked and the response is emitted.

### 1.1 Example with closure

```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/books/{book}', function(RequestInterface $request, string $book) use ($mono) {
    return $mono->createResponse(200, 'Book: ' . $book);
});

$mono->run();
```

### 1.2 Example with controller
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
        return $this->mono->createResponse(200, 'Book: ' . $book');
    }
}
```
```php
<?php

$mono = new Mono();

// By fetching the controller from the container, it will autowire all constructor parameters.
$mono->addRoute('GET', '/books/{book}', $mono->get(BookController::class));

$mono->run();
```
## 2. Dependency injection

When a Mono object is created, it constructs a basic PHP-DI container with default configuration. This means dependencies from your vendor folder are autowired.

You can fetch instances from the container with the `get()` method on your Mono object.

```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/example', function() use ($mono) {
    $result = $mono->get(SomeDependency::class)->doSomething();
    
    return $mono->createResponse(200, json_encode($result));
});

$mono->run();
```

## 3. Middleware
Mono is built as a middleware stack application. The default flow is:

- Error handling
- Routing (route is matched to a handler)
- *Your custom middlewares*
- Request handling (the route handler is invoked)

You can add middleware to the stack with the `addMiddleware()` method. Middleware are either a callable or a class implementing the `MiddlewareInterface` interface. The middleware are executed in the order they are added.

```php
<?php

$mono = new Mono();

$mono->addMiddleware(function (ServerRequestInterface $request, callable $next) {
    // Do something before the request is handled
    
    return $next($request);
});

$mono->addMiddleware(function (ServerRequestInterface $request, callable $next) {
    $response = $next($request);

    // Do something after the request is handled

    return $response->withHeader('X-Test', 'Hello, world!');
});
````

## 4. Templating

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

## Debug mode
Mono has a debug mode that will catch all errors by default and show a generic 500 response.

When developing, you can disable this mode by passing `false` as the second argument to the Mono constructor. This will show the actual error messages and allow you to use `dump` inside your Twig templates.