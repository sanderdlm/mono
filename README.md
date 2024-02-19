# Mono

Mono is a tiny, single-class PHP framework that combines multiple great projects from the PHP ecosystem to bring you as many features as possible in a small package.

In +-180 LOC, you get:
1. Routing (using [nikic/FastRoute](https://github.com/nikic/FastRoute))
2. Dependency injection (using [php-di/php-di](https://github.com/PHP-DI/PHP-DI))
3. Middlewares (using [relay/relay](https://github.com/relayphp/Relay.Relay))
4. Templating (using [twigphp/wig](https://github.com/twigphp/Twig))

Mono is intended as a proof-of-concept for small, modern PHP apps. Its goal is to show how far you can go by combining battle-tested libraries & PSR implementations.


#### Hello world
```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/hello/{name}', function(ServerRequestInterface $request, string $name) use ($mono) {
    return $mono->createResponse(200, 'Hello, ' . $name . '!');
});

$mono->run();
```

People familiar with [Slim](https://github.com/slimphp/Slim) will definitely notice the similarities.

If you're interested, please take a look at the [source code](https://github.com/sanderdlm/mono/blob/main/src/Mono.php). It's only a single file and has comments explaining everything going on.

> If this exact boilerplate isn't exactly what you need, copy `Mono.php` into your own project, rename it, swap out dependencies, change configuration, etc... to reach your desired stack.

## 1. Routing
You use `$mono->addRoute()` to add all your routes. Same method signature as the underlying FastRoute method. Route handlers are closures by default, since this is mainly intended as a framework for small apps, but you can use invokable controllers as well.

Read about the route pattern in the [FastRoute documentation](https://github.com/nikic/FastRoute#defining-routes). The entered path is passed directly to FastRoute.

The first argument to the closure is the always current request, which is a [PSR-7 ServerRequestInterface](https://github.com/php-fig/http-message/blob/master/src/ServerRequestInterface.php) object. After that, the next arguments are the route parameters.

When `$mono->run()` is called, the current request is matched against the routes you added, the closure is invoked and the response is emitted.

### 1.1 Example with closure

```php
<?php

$mono = new Mono();

$mono->addRoute('GET', '/books/{book}', function(ServerRequestInterface $request, string $book) use ($mono) {
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

    public function __invoke(ServerRequestInterface $request, string $book): ResponseInterface
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

When a Mono object is created, it constructs a basic PHP-DI container with default configuration. This means that any loaded classes (for example through PSR-4) can be autowired or pulled from the container manually.

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

### Custom container
If you need to define custom definitions, you can pass a custom container to the Mono constructor. See [the PHP-DI documentation](https://php-di.org/doc/getting-started.html#2-create-the-container) for more information.

```php
<?php

// Custom container
$builder = new DI\ContainerBuilder();
$builder->... // Add some custom definitions
$container = $builder->build();

$mono = new Mono(container: $container);

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

$mono->addMiddleware(function (ServerRequestInterface $request, callable $next) use ($mono) {
    // Do something before the request is handled
    if ($request->getUri()->getPath() === '/example') {
        return $mono->createResponse(403, 'Forbidden');
    }
    
    return $next($request);
});

$mono->addMiddleware(function (ServerRequestInterface $request, callable $next) {
    $response = $next($request);

    // Do something after the request is handled
    return $response->withHeader('X-Test', 'Hello, world!');
});
````

You can find a bunch of great PSR-15 compatible middlewares already written in the [middlewares/psr15-middlewares](https://github.com/middlewares/psr15-middlewares) project. These can be plugged into Mono and used straight away.
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

## Folder structure & project setup

Getting started with a new project is fast. Follow these steps:

1. Create a new folder for your project.
2. Run `composer require sanderdlm/mono`.
3. Create a `public` folder in the root of your project. Add an `index.php` file. There is a "Hello world" example below.
4. Optionally, create a `templates` folder in the root of your project. Add a `home.twig` file. There is an example below.
5. Run `php -S localhost:8000 -t public` to start the built-in PHP server.
6. Start developing your idea!

`public/index.php`:
```php

<?php

declare(strict_types=1);

use Mono\Mono;

require_once __DIR__ . '/../vendor/autoload.php';

$mono = new Mono(__DIR__.'/../templates');

$mono->addRoute('GET', '/', function() use ($mono) {
    return $mono->render('home.twig', [
        'message' => 'Hello world!',
    ]);
});

$mono->run();
```

`templates/home.twig`:
```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Home</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
    {{ message }}
</body>
</html>
```

If you're planning to keep things simple, you can work straight in your index.php. If you need to define multiple files/classes, you can add a `src` folder and add the following PSR-4 autoloading snippet to your `composer.json`:
```json
"autoload": {
    "psr-4": {
        "App\\": "src/"
    }
},
```
You can now access all of your classes in the `src` folder from your DI container (and autowire them!).