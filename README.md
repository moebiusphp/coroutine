moebius/coroutine
=================

True "green threads" (coroutines) for PHP 8.1. No plugins needed. Bringing the concurrency
style of Go to PHP.

Extremely simple API:

 * `M\run($callback)` will run a coroutine and wait for it to finish. Return value and exceptions
   are thrown.

 * `M\go($callback)` will run a coroutine, but will not wait for it to finish. Use it in combination
   with `M\await(...$threads)` if you want to run multiple coroutines concurrently.

 * `M\await($thread)` will block your function and until the coroutine has finished.

 * `M\unblock($fp)` makes streams non-blocking.

 * `M\sleep(0.1)` pause the coroutine for 0.1 seconds.

To use these functions directly in your code we suggest importing them to your namespace:

```php
<?php
    use function M\{run, go, await, unblock, sleep};
```

TLDR:
-----

Automatically non-blocking file system.

```php
<?php
    use function M\{go, await, unblock, sleep};

    $results = [];
   
    for ($i = 0; $i < 10; $i++) {

        // These will run in parallel
        go(function() use ($i, &$results) {
            $results[$i] = file_get_contents('file_number_'.$i);
        });
    });
```

Other non-blocking stream

```php
<?php
    use function M\{go, await, unblock, sleep};

    $stream = fsockopen('www.google.com', 80, $errno, $errstr, 30);
    if (!$fp) {
        echo "$errstr ($errno)\n";
    } else {
        // IMPORTANT! The `M\unblock()` function makes the stream non-blocking.
        $fp = unblock($fp);

        // IMPORTANT! The `go()` function will allow the stream to continue
        go(function() use ($fp) {
            fwrite($fp, "GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: Close\r\n\r\n");
            while (!feof($fp)) {
                echo fgets($fp, 128);
            }
            fclose($fp);
        });
    }
```

Cooperative multitasking with the `M\interrupt()` function.

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
        return run(function() {
            // use blocking operations
            $body = go(function() {
                return file_get_contents("some_file.txt");
            });

            $apiResponse = go(function() {
                return HttpClient::get('http://www.example.com/some-api');
            });

            return $this->json((object) [
                'file_body' => await($body),            // important to await the responses
                'api_response' => await($apiResponse),  // important to await the responses
            ]);
        });
    }
}
```


For library authors
-------------------

The single most important integration you can do with `moebius/coroutine` is to make your
stream resources "unblocked". This should not affect your library in any way, except it
will ensure that other coroutines can perform some work whenever your library would be
stuck waiting for file operations to complete.

There are three main ways to do this

### With the function wrappers

*These functions have not been implemented yet*

If your library uses functions like `file_get_contents` or `fopen` or `fsockopen`, the only
modification you'll need to do is import alternative implementations of those functions
from the `M\` namespace. After importing this, please run your unit tests and report back to
use if you discover any incompatabilities.

Note that if you're calling these functions with the root namespace prefix, e.g. `\fopen`,
you have to change that back to `fopen`.

```php
<?php
    // import any functions that creates stream resources from the M namespace
    use function M\{fopen, fsockopen, file_get_contents, popen, proc_open};
```

### With the unblock() function

*This function has not been implemented yet, pending some refactoring*

If you can't find a function wrapper for your particular function, you should "unblock" your
stream resource with the `M\unblock()` function. Note that even if you use the unblock function,
the actual process of opening the resource may still block the entire application. One particular
example is trying to open a fifo-file in read-only mode, when there are no other writers connected.

The `M\unblock($fp): mixed;` function will check if the `$fp` resource is unblockable and in
that case it will replace it with a new resource where moebius automatically converts blocking
operations into asynchronous operations.

```php
<?php
    // replace the stream resource with another stream resource

    $fp = M\unblock($fp);
```

### With the interrupt() function

If you have some heavy calculation to perform, you should try to call the `M\interrupt()` function
regularly in your loops. This function allows us to check if your coroutine has exceeded its allowed
time and let other coroutines perform some work.

```php
<?php
    function fib(int $n): int {
        \M\interrupt(); // this function call is important

        if ($n < 2) return $n;
        return fib($n - 1) + fib($n - 2);
    }
```

We try to do as little as possible work inside the interrupt function, but it is a waste of resources
to call it too much. You can reduce the number of invocations by placing it somewhere else in your 
code:

```php
<?php
    function fib(int $n): int {
        // call interrupt only 1/16th of times
        if (0 === ($n % 16)) \M\interrupt();

        if ($n < 2) return $n;

        return fib($n - 1) + fib($n - 2);
    }
```

In the particular case of fibonacci, you can even consider doing this:

```php
<?php
    function fib(int $n): int {
        if ($n < 2) {
            \M\interrupt();
            return $n;
        }

        return fib($n - 1) + fib($n - 2);
    }
```


Principles
----------

Moebius is being developed with a set of clear priorities:

 1. It MUST NOT require developers to make any modifications to their existing code base. 
    Moebius does NOT require that the project is built using async libraries such as React
    or Amp.

 2. It MUST NOT have any noticeable side-effects on the rest of the application.


Legacy Code and migration
-------------------------

We're working hard to make moebius/coroutine able to context switch automatically, even
if there is no built-in support for that elsewhere. We have a working proof of concept
implementation where all filesystem operations will cause context switching, and we're
working on a similar implementation for streams.

While we try to avoid it, these extensions may have side effects - so they are optional
addons which can be installed via composer.

If you are developing a library yourself, you should NOT make them a dependency of your
library!
