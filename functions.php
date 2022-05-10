<?php
namespace M;

use Moebius\Coroutine as Co;
use Moebius\Coroutine\Unblocker;

Co::bootstrap();

/**
 * Run a coroutine and wait for the return value or an exception
 * to be thrown. This function is intended to be used to convert
 * synchronous functions into asynchronous versions according to
 * this pattern:
 *
 * ```
 * function some_request_handler( $request ) {
 *     // simulate blocking behavior
 *     return run(function() {
 *         // work asynchronously
 *         return new Response(...);
 *     });
 * }
 * ```
 *
 * @param callable $coroutine The function
 * @param mixed ...$args The function arguments
 * @return mixed The return value from the function
 * @throws \Throwable
 */
function run(callable $coroutine, mixed ...$args): mixed {
    return Co::run($coroutine, ...$args);
}

/**
 * Create a coroutine. Returns a promise with the return value.
 *
 * This will immediately run the coroutine. If the coroutine
 * is somehow blocked or interrupted, control will be returned
 * back to you.
 *
 * When you need the return value from your coroutine, you should
 * simply use `M\await()` to get the result.
 *
 * @param callable $coroutine The function
 * @param mixed ...$args The function arguments
 * @return Promise Returns a promise of the return value from the coroutine
 */
function go(callable $coroutine, mixed ...$args): Co {
    return Co::go($coroutine, ...$args);
}

/**
 * Wait for a coroutine or a promise to finish and return the
 * result from it.
 *
 * @param object $thenable A promise that you need the return value from
 * @return mixed The return value from the coroutine/promise
 * @throws \Throwable Any exception cast from the coroutine/promise
 */
function await(object $thenable): mixed {
    return Co::await($thenable);
}

/**
 * Sleep for a number of seconds before waking up. The function will
 * return at the first opportunity after the duration has expired.
 *
 * @param float $duration The duration to sleep, for example 0.05 seconds.
 */
function sleep(float $duration): void {
    if ($duration <= 0) {
        throw new \RangeException("\M\sleep() requires duration greater then 0");
    }
    Co::sleep($duration);
}

/**
 * Make a stream resource transparently non-blocking. If the stream
 * resource is already unblocked, or if the value is not a stream
 * resource - it will simply be returned unmodified.
 *
 * @param mixed $resource The stream resource as returned from fopen, fsockopen etc.
 * @return mixed The replaced stream resource if possible, or it will return the value that was received.
 */
function unblock($resource): mixed {
    return Unblocker::unblock($resource);
}

/**
 * Immediately give other coroutines an opportunity to do some work, then come
 * back here.
 *
 * This is a low level function which can be used to implement functionality such
 * as `sleep()` or if you're monitoring a PHP value or promise.
 */
function suspend(): void {
    Co::suspend();
}

/**
 * Insert an interruption opportunity in a busy loop or algorithm.
 *
 * In general you should avoid using this function. It is intended to be used in
 * code as a safety guard against infinite loops.
 */
function interrupt(): void {
    static $callCount = 0;
    // optimization to reduce the overhead
    if ($callCount++ === 100) {
        $callCount = 0;
        Co::interrupt();
    }
}

/**
 * Block until a stream becomes readable.
 *
 * @param resource $fp      The stream resource
 * @param ?float $timeout   Optional timeout
 * @return bool             False if timed out and stream is not workable yet
 */
function readable($fp, float $timeout=null): bool {
    return Co::readable($fp, $timeout);
}


/**
 * Block until a stream becomes writable.
 *
 * @param resource $fp
 * @param ?float $timeout   Optional timeout
 * @return bool             False if timed out and stream is not workable yet
 */
function writable($fp, float $timeout=null): bool {
    return Co::writable($fp, $timeout);
}
