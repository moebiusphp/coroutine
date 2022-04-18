<?php
namespace M;

use Moebius\Promise;
use Moebius\Coroutine;
use Moebius\Coroutine\Unblocker;
use Moebius\Coroutine\RejectedException;
use Moebius\Loop;
use Fiber;

/**
 * Run a coroutine and wait for the return value or an exception
 * to be thrown.
 *
 * ```
 * function some_request_handler( $request ) {
 *     return M\run(function() {
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
    return await(go($coroutine, ...$args));
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
function go(callable $coroutine, mixed ...$args): Promise {
    return Coroutine::create($coroutine, ...$args);
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
    if (!Promise::isThenable($thenable)) {
        // This is not a promise, so we'll treat it as a value that can be used
        // immediately
        return $thenable;
    }
    $resolved = null;
    $value = null;
    $thenable->then(function($result) use (&$resolved, &$value) {
        $resolved = true;
        $value = $result;
    }, function($reason) use (&$resolved, &$value) {
        $resolved = false;
        $value = $reason;
    });
    while ($resolved === null) {
        suspend();
    }
    if ($resolved === true) {
        // we have a result
        return $value;
    } elseif ($value instanceof \Throwable) {
        throw $value;
    } else {
        throw new RejectedException($value);
    }
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
    $timeout = microtime(true) + $duration;
    do {
        suspend();
    } while($timeout > microtime(true));
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
    if (Coroutine::getCurrent()) {
        Coroutine::suspend();
    } elseif (Fiber::getCurrent()) {
        Fiber::suspend();
    } elseif (Loop::isDraining()) {
        throw new \Exception("suspend called outside of a coroutine while a loop is draining. fix your code!");
    } else {
        Loop::drain(function() { return true; });
    }
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
        Coroutine::interrupt();
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
    $workable = false;
    if ($timeout !== null) {
        $expires = microtime(true) + $timeout;
    }
    $cancel = Loop::onReadable($fp, function() use (&$workable, &$cancel) {
        $workable = true;
        $cancel();
    });
    do {
        suspend();
        if (!$workable && $timeout !== null) {
            if (microtime(true) > $timeout) {
                // timeout
                $cancel();
                return false;
            }
        }
    } while(!$workable);
    return true;
}


/**
 * Block until a stream becomes writable.
 *
 * @param resource $fp
 * @param ?float $timeout   Optional timeout
 * @return bool             False if timed out and stream is not workable yet
 */
function writable($fp, float $timeout=null): bool {
    $workable = false;
    if ($timeout !== null) {
        $expires = microtime(true) + $timeout;
    }
    $cancel = Loop::onWritable($fp, function() use (&$workable, &$cancel) {
        $workable = true;
        $cancel();
    });
    do {
        suspend();
        if (!$workable && $timeout !== null) {
            if (microtime(true) > $timeout) {
                // timeout
                $cancel();
                return false;
            }
        }
    } while(!$workable);
    return true;
}
