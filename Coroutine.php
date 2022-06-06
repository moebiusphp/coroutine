<?php
namespace Moebius;

use Fiber, Closure, Throwable;
use Moebius\{
    Loop
};
use Moebius\Promise\{
    ProtoPromise,
};
use Moebius\Loop\{
    Timer,
    Readable,
    Writable
};
use Moebius\Coroutine\Unblocker;

class Coroutine extends ProtoPromise {

    /**
     * Counts how many coroutines are waiting for a promise at any time
     */
    private static int $awaitCount = 0;
    /**
     * The peak number of awaiting coroutines
     */
    private static int $awaitPeak = 0;
    /**
     * Counts how many coroutines are in memory at any time
     */
    private static int $coroutineCount = 0;
    /**
     * The peak number of coroutines
     */
    private static int $coroutinePeak = 0;
    /**
     * Counts how many coroutines are sleeping at any time
     */
    private static int $sleepCount = 0;
    /**
     * The peak number of simultaneous sleep
     */
    private static int $sleepPeak = 0;
    /**
     * How many times coroutines have resumed (context switches)
     */
    private static int $coroutineResumeCount = 0;
    /**
     * Counts how many times exceptions have been thrown into
     * coroutines
     */
    private static int $coroutineThrowCount = 0;

    public static function go(Closure $callback, mixed ...$args): PromiseInterface {
        return new self($callback, ...$args);
    }

    public static function await(object $promise): mixed {
        self::$awaitPeak = max(++self::$awaitCount, self::$awaitPeak);
        try {
            if (Fiber::getCurrent()) {
                return Fiber::suspend($promise);
            } else {
                return Loop::await($promise);
            }
        } finally {
            --self::$awaitCount;
        }
    }

    public static function run(Closure $callback, mixed ...$args): mixed {
        return self::await(self::go($callback, ...$args));
    }

    public static function sleep(float $time): void {
        self::$sleepPeak = max(++self::$sleepCount, self::$sleepPeak);
        self::await(Loop::delay($time));
        --self::$sleepCount;
    }

    public static function suspend(): void {
        if (Fiber::getCurrent()) {
            Fiber::suspend();
        } else {
            Loop::run(function() { return false; });
        }
    }

    public static function readable($fp, float $timeout=null): bool {
        $result = true;
        $promise = Loop::readable($fp);
        if ($timeout) {
            $promise = Promise::any([$promise, Loop::delay($timeout, function() use ($result) {
                $result = false;
            })]);
        }
        self::await($promise);
        return $result;
    }

    public static function writable($fp, float $timeout=null): bool {
        $result = true;
        $promise = Loop::writable($fp);
        if ($timeout) {
            $promise = Promise::any([$promise, Loop::delay($timeout, function() use ($result) {
                $result = false;
            })]);
        }
        self::await($promise);
        return $result;
    }

    /**
     * Give the current coroutine priority to complete blocking all other
     * coroutines from progressing.
     *
     * @internal
     */
    public static function focus(): void {
        self::$focus = true;
        self::$focusFiber = Fiber::getCurrent();
    }

    /**
     * Allow other coroutines to continue progressing.
     */
    public static function unfocus(): void {
        self::$focus = false;
        self::$focusFiber = null;
    }

    public static function unblock($fp) {
        return Unblocker::unblock($fp);
    }

    private static bool $focus = false;
    private static ?Fiber $focusFiber = null;

    public array $onFulfill = [];
    public array $onReject = [];

    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private Fiber $fiber;
    private int $state = 0;
    private mixed $value;

    private function __construct(Closure $callback, mixed ...$args) {
        self::$coroutinePeak = max(++self::$coroutineCount, self::$coroutinePeak);
        $this->fiber = new Fiber($callback);
        $this->start(...$args);
    }

    private function start(mixed ...$args): void {
        if (self::$focus && self::$focusFiber !== $this->fiber) {
            echo "Delaying fiber start\n";
debug_print_backtrace();
            Loop::defer(function() use ($args) {
                $this->start(...$args);
            });
            return;
        }
        try {
            $result = $this->fiber->start(...$args);
            $this->handle($result);
        } catch (\FiberError $e) {
            throw $e;
        } catch (Throwable $e) {
            self::unfocus();
            $this->reject($e);
        }
    }

    public function __destruct() {
        --self::$coroutinePeak;
        parent::__destruct();
    }

    private function handle(mixed $intermediate): void {
        if ($this->fiber->isTerminated()) {
            $returnValue = $this->fiber->getReturn();
            // locking stops here regardless
            self::unfocus();
            $this->fulfill($returnValue);
        } elseif ($intermediate !== null && is_object($intermediate) && Promise::isPromise($intermediate)) {
            // Resume the fiber when the promise is resolved
            $intermediate->then(
                $this->resume(...),
                $this->throwException(...)
            );
        } else {
            Loop::defer($this->resume(...));
        }
    }

    private function resume(mixed $result=null): void {
        if (self::$focus && self::$focusFiber !== $this->fiber) {
            echo "Delaying fiber resume\n";
            Loop::defer(function() use ($result) {
                $this->resume($result);
            });
            return;
        }
        ++self::$coroutineResumeCount;
        try {
            $this->handle($this->fiber->resume($result));
        } catch (\FiberError $e) {
            self::unfocus();
            throw $e;
        } catch (Throwable $e) {
            self::unfocus();
            $this->reject($e);
        }
    }

    private function throwException($reason): void {
        ++self::$coroutineThrowCount;
        if (!($reason instanceof Throwable)) {
            $reason = new RejectedException($reason);
        }
        try {
            $this->handle($this->fiber->throw($reason));
        } catch (\FiberError $e) {
            // In some cases the task will be launched in an invalid context
            Loop::queueMicrotask(function() use ($reason) {
                try {
                    $this->handle($this->fiber->throw($reason));
                } catch (Throwable $e) {
                    self::$locked = false;
                    $this->reject($e);
                }
            });
        } catch (Throwable $e) {
            self::unfocus();
            $this->reject($e);
        }
    }

}
