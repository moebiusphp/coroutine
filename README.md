moebius/coroutine
=================

True "green threads" (coroutines) for PHP 8.1. No plugins needed. Coroutines are like multitasking,
but without many of the subtle problems that come from threads.


## Compatability

Moebius runs other event loops cooperatively; if you are using React, Moebius will run the React
Event loop, if you are using Amp, Moebius will run the Amp event loop.

> TIP! To make compatible event-loop based applications, you can implement against the moebius/loop
> implementation yourself.


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

```
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

```
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



## Examples

You can find complete examples in the `examples/` folder. Here is a trivial example
that reads lines from multiple files concurrently:

```
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

    ```
    <?php
    use function M\{go, sleep};
    go(function() {
        while (true) {
            echo date('c')."\n";
            sleep(5);
        }
    });
    ```

