# Mono

Mono is a tiny, single-class PHP framework for writing single-page PHP apps.
It shines when quickly developing small tools with limited scope.
In +- 70 LOC, you get basic routing (using FastRoute), DI (using PHP-DI),
and Twig templating. Anything else you need, you have to bring yourself.

## Routing
Mono's routing implementation shares 90% of its code with the ['basic usage example'](https://github.com/nikic/FastRoute#usage) from the FastRoute documentation.

You use `addRoute()` to add all your routes. Same method signature as the FastRoute method. Route handlers are closures by default, since this is a single-page framework.

Read about the route pattern in the [FastRoute documentation](https://github.com/nikic/FastRoute#defining-routes). The entered path is passed directly to FastRoute.

When `$mono->run()` is called, the current request is matched against the routes you added, the closure is invoked and the string result is echo'd.

```php
use App\Mono;$mono = new Mono();

$mono->addRoute('GET', '/', function() use ($mono) {
    return 'Hello world!';
});

echo $mono->run();
```

Mono does not implement PSR-7 because it would be overkill for most tools you'd build in a single page .

If you do need status codes and headers, you can use `http_response_code()` and `header()` to do so.

## DI

When a Mono object is created, it constructs a basic PHP-DI container with default configuration. This means dependencies from your vendor folder are autowired.

You can fetch instances from the container with the `get()` method on your Mono object.

```php
use App\Mono;$mono = new Mono();

$mono->addRoute('GET', '/example', function() use ($mono) {
    $result = $mono->get(SomeDependency::class)->doSomething();
    
    return json_encode($result);
});

echo $mono->run();
```

## Twig

Mono comes with Twig out-of-the-box. You can use the `render()` method on your Mono object to render a Twig template.

```php
use App\Mono;$mono = new Mono();

$mono->addRoute('GET', '/example', function() use ($mono) {
    $result = $mono->get(SomeDependency::class)->doSomething();
    
    return $mono->render('example.twig', [
        'result' => $result
    ]);
});

echo $mono->run();
````

Templates go in the `/templates` folder in the project root.