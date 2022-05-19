moebius/coroutine
=================

True "green threads" (coroutines) for PHP 8.1. No plugins needed. Coroutines are like multitasking,
but without many of the subtle problems that come from threads.


## Compatability

Moebius runs other event loops cooperatively; if you are using React, Moebius will run the React
Event loop, if you are using Amp, Moebius will run the Amp event loop.

Moebius will be migrating to use the Revolt event loop implementation internally. It will still 
support running legacy applications with Amp or React.


## Essentials

A coroutine is a function which runs in parallel with other code in your application. You can work
with coroutines the same way you work with promises in frameworks like React or Amp. In fact, you
can use most promise based libraries with Moebius.


### Conceals the promises

The main purpose of moebius is to conceal the existence of promises; you should not have to think
about coroutines being a part of your application. The only time you need to think about coroutines,
is when you need to do multiple things in parallell.

Moebius allows your application to handle multiple requests in parallel, but your program flow should
not have to worry about that.


### A Coroutine is a Promise

When you create a coroutine, you get a promise about a future result. That future result can be
accessed via the `then()` method, just like any other promise object you're used to.

```php
<?php
use Moebius\Coroutine as Co;

$coroutine = Co::go(function() {
    Co::sleep(10);
    return true;
});

$coroutine->then(function() {
    echo "Done\n";
});
```

### A Promise is a Coroutine

```php
<?php
use Moebius\Coroutine as Co;
use GuzzleHttp\Client;

function get(string $url) {
    $client = new Client();
    echo "Connecting to '$url'\n";
    $result = $client->getAsync($url);
    echo "Got response from '$url'\n";
}

$google = Co::go(get(...), 'https://www.google.com');
$bing = Co::go(get(...), 'https://www.bing.com');
$ddg = Co::go(get(...), 'https://www.duckduckgo.com');
```

## Examples

You can find complete examples in the `examples/` folder. Here is a trivial example
that reads lines from multiple files concurrently:

```php
<?php
    require "vendor/autoload.php";

    use Moebius\Coroutine as Co;

    foreach (glob(__DIR__.'/*') as $path) {
        if (is_file($path)) {
            Co::go('print_file', $path);                    // <-- Co::go() launches a function as a "green thread"
        }
    }

    /**
     * Watch function which will monitor files for added lines
     */
    function print_file(string $filename) {
        $fp = Co::unblock(fopen($filename, "r"));           // <-- Co::unblock() makes the stream cooperation friendly

        while (!feof($fp)) {
            echo fgets($line, 4096);
        }
    }
```

Deceptivly simple API:

 * Import the functions you want to use: `import function M\{go, await, sleep, unblock, run};`

 * Run tasks in parallel with the `go()` function: `$futureResult = go(someMethod(...), 'argument 1', 'argument 2');`.

 * Whenever you need to access the result, use the `await()` function: `$finalResult = await($futureResult);`.

 * If you are working with files or sockets, use the `unblock()` function to get a special context-switching
   stream: `$fp = unblock(fopen('some-file.txt', 'r'));`. After this you can work with the stream resource
   and Moebius will automatically switch to other coroutines whenever the main thread is blocked.

 * Create a "background task" that runs every N seconds by calling `sleep()`. This special version of `sleep()` will
   let other coroutines do some work while the main thread is blocked.

    ```php
    <?php
    use function M\{go, sleep};
    go(function() {
        while (true) {
            echo date('c')."\n";
            sleep(5);
        }
    });
    ```

---

Function reference
------------------

### M\go and Moebius\Coroutine::go

Creates a coroutine which will run in parallel whenever other scripts are blocked from working.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::go(callable $callback): Moebius\Coroutine
```

Procedural style

```php
M\go(callable $callback, mixed ...$args): Moebius\Coroutine
```

#### Parameters

 * **callback** A function to run as a coroutine.

 * **args** A list of arguments to pass to the function when it runs

#### Return Values

Returns a future value which can be passed to `M\await` or `Moebius\Coroutine::await` whenever
you need the return value. The future value is also a promise-like object with a `then` method.


### M\await and Moebius\Coroutine::await

Await the result from a future value or a promise without blocking other coroutines.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::await(object $thenable): mixed
```

Procedural style

```php
M\await(object $thenable): mixed
```

#### Parameters

 * **thenable** A coroutine created with `M\go` or `Moebius\Coroutine::go`, or any promise-like
   object with a `then(onSuccess, onFailure)` method.

#### Return values

Await will return the value that is returned from the coroutine via the `return` statement. If
the coroutine throws an exception, `M\await` will throw the exception.


### M\run and Moebius\Coroutine::run

Combines the `M\go` and `M\await` functions, to simplify writing coroutine-ready functions.

> A function that is coroutine-ready will work in cooperation with other coroutine-ready functions,
> and will behave like any normal function if the application does not support coroutines.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::run(callable $callback, mixed ...$args): mixed
```

Procedural style

```php
M\go(callable $callback, mixed ...$args): mixed
```

#### Parameters

 * **callback** A function to run as a coroutine.

 * **args** A list of arguments to pass to the function when it runs

#### Return Values

Run will return the value that is returned from the coroutine via the `return` statement. If
the coroutine throws an exception, `M\run` will throw the exception.


### M\readable and Moebius\Coroutine::readable

Allow other coroutines to do work until reading from a stream resource will not block.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::readable(mixed $resource): void
```

Procedural style

```php
M\readable(mixed $resource): void
```

#### Parameters

 * **resource** a stream resource such as those returned by `fopen()`, `fsockopen()` or
   `proc_open()`.

#### Return Values

No value is returned


### M\writable and Moebius\Coroutine::writable

Allow other coroutines to do work until writing to the stream resource will not block.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::writable(mixed $resource): void
```

Procedural style

```php
M\writable(mixed $resource): void
```

#### Parameters

 * **resource** a stream resource such as those returned by `fopen()`, `fsockopen()` or
   `proc_open()`.

#### Return Values

No value is returned


### M\sleep and Moebius\Coroutine::sleep

Pauses the local function execution while allowing other coroutines to proceed with their work.

#### Description

Object-oriented style

```php
public static Moebius\Coroutine::sleep(float $seconds): void
```

Procedural style

```php
M\sleep(float $seconds): void
```

#### Parameters

 * **seconds** Number of seconds to pause execution of a function.

#### Return Values

No value is returned


### M\suspend and Moebius\Coroutine::suspend

Suspend execution and allow other coroutines to perform some work.

> This function is generally not recommended except in special circumstances, such as monitoring
> a variable or some other very light-weight task. If you are performing some long-running
> calculations in PHP - you should avoid invoking the suspend function too much because context
> switching does take a few microseconds.

#### Parameters

None

#### Return Values

No value is returned

---

Examples
--------

### 100 coroutines in parallel

```php
<?php
    use function M\{go, sleep, await);

    // Hold the result from each job
    $jobs = [];

    // Launch the jobs with go()
    for ($i = 0; $i < 100; $i++) {
        $jobs[] = go(function() {
            echo "+";
            sleep(1);
            echo "-";
            return microtime(true);
        });
    }

    // Wait until the jobs are finished with await()
    foreach ($jobs as $job) {
        $result = await($job);
    }

    // One second later, all coroutines have finished in parallel.
```

### Background tick function every 0.5 seconds

```php
<?php
    use function M\{go, sleep};

    $backgroundJob = go(function() {
        while (true) {
            echo ".";
            sleep(0.5);
        }
    });
```

### Schedule a function to run in 10 seconds

```php
<?php
    use function M\{go, sleep};

    go(function() {
        sleep(10;
        echo "10 seconds have passed\n";
    });
```

### Cooperative multitasking

Long running PHP functions will block the entire process from performing work.
To enable cooperative multitasking, you can insert a call to the `M\interrupt()`
function at strategic places.

```php
<?php
    function lots_of_work() {
        $r = mt_rand(0,9);
        for ($i = 0; $i < 1000000; $i++) {
            if ($i % 1000 === 0) {
                M\interrupt();              // busy loops MUST call M\interrupt() from time to time
            }
            echo $r;
        }
        return $r;
    }

    // start the process many times
    $coroutines = [];
    for ($i = 0; $i < 10; $i++) {
        $coroutines[] = go(lots_of_work(...));
    }

    // wait until all coroutines have finished
    foreach ($coroutines as $number => $randomValue) {
        echo "Coroutine number $number printed value ".await($c)."\n";
    }
```


More complex example
--------------------

This library does not require any changes to existing projects. You can use moebius in libraries used in projects
that are typically blocking code. The most important thing to remember, is to use the `M\await()` function to
ensure that your coroutines have completed before you return. If you don't await your coroutines, they will be
completed at a later time in the request cycle.

For example, in a PSR-15 Server Request Handler:

```php
<?php
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use function M\{run, go, sleep};

class MyController {
    /**
     * Example of using moebius/coroutine to perform asynchronouse tasks before returning
     */
    public function handleLoginRequest(ServerRequestInterface $request): ResponseInterface {

        /**
         * This function will behave as if it is blocking, but *if* this function is called
         * from within a coroutine, it will automatically allow other coroutines to run.
         */

        return run(function() {
            $api1Response = go(function() {
                return HttpClient::get('http://www.example.com/some-api-1');
            });

            $api2Response = go(function() {
                return HttpClient::get('http://www.example.com/some-api-2');
            });

            return $this->json((object) [
                'api_1_response' => await($api1Response),       // important to await the responses
                'api_2_response' => await($api2Response),       // important to await the responses
            ]);
        });
    }
}
```


For library authors
-------------------

The single most important integration you can do with `moebius/coroutine` is to make your
stream resources "unblocked".

This should not affect your library in any way, but will enable coroutines to run whenever
your stream resource is blocked.


### Use the `M\unblock()` function

The `M\unblock()` function will take any stream resource and automatically manage it for you
by wrapping it in a PHP Stream Wrapper.

This way you do not have to think about blocking or non-blocking stream operations.

Change:

```php
    $fp = fopen('some-resource.txt', 'r');
```

to

```php
    $fp = M\unblock(fopen('some-resource.txt', 'rn'));
```

Notice that we added a small 'n' argument 2 of the fopen call. This has a very small efffect
on normal files, but with special files such as FIFO-files - it prevents the `fopen()` function
from blocking.


### Use `M\file_get_contents()` and `M\file_put_contents()`

The PHP functions `file_get_contents()` and `file_put_contents()` are very common functions
for reading files. By simply importing these drop-in replacement functions you will automatically
enable concurrency.

```php
<?php
    use M\{file_get_contents, file_put_contents};
```


### With the `M\sleep()` function

If your function is polling for some information, you should replace any calls to `usleep()` or
`sleep()` with the `M\sleep()` function.

The `M\sleep()` function is not interrupted by signals and is often more precise than the native
PHP functions `sleep()` and `usleep()` since it relies on the `hrtime()` function.

`M\sleep()` supports fractional seconds: `usleep(1).` is equivalent to `M\sleep(0.000001);`.


Principles
----------

Moebius is being developed with a set of clear priorities:

 1. It must allow a gradual transition from traditional blocking code bases. Using Moebius 
    in a library must not make it incompatible with classic PHP software.

 2. Moebius must not cause side-effects unless explicitly requested/configured by the application
    developer.

