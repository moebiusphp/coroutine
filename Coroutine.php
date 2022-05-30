<?php
namespace Moebius;

use Fiber, Closure, Throwable;
use Moebius\{
    Loop,
    Promise\ProtoPromise
};
use Moebius\Loop\{
    Timer,
    Readable,
    Writable
};
use Moebius\Coroutine\Unblocker;

class Coroutine extends ProtoPromise {

    public static function go(Closure $callback, mixed ...$args): PromiseInterface {
        return new self($callback, ...$args);
    }

    public static function await(object $promise): mixed {
        if (Fiber::getCurrent()) {
            return Fiber::suspend($promise);
        } else {
            return Loop::await($promise);
        }
    }

    public static function run(Closure $callback, mixed ...$args): mixed {
        $co = new self($callback, ...$args);
        return self::await($co);
    }

    public static function sleep(float $time): void {
        self::await(new Timer($time));
    }

    public static function suspend(): void {
        if (Fiber::getCurrent()) {
            Fiber::suspend();
        } else {
            Loop::run(function() { return false; });
        }
    }

    public static function readable($fp, float $timeout=null): bool {
        $result = self::await(new Readable($fp, $timeout));
        return true;
    }

    public static function writable($fp, float $timeout=null): bool {
        $result = self::await(new Writable($fp, $timeout));
        return true;
    }

    public static function unblock($fp) {
        return Unblocker::unblock($fp);
    }

    public array $onFulfill = [];
    public array $onReject = [];

    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private Fiber $fiber;
    private int $state = 0;
    private mixed $value;

    private function __construct(Closure $callback, mixed ...$args) {
        try {
            $this->fiber = new Fiber($callback);
            $this->handle($this->fiber->start(...$args));
        } catch (\FiberError $e) {
            // In some cases the task will be launched in an invalid context
            Loop::queueMicrotask(function() use ($args) {
                try {
                    $this->handle($this->fiber->start(...$args));
                } catch (Throwable $e) {
                    $this->reject($e);
                }
            });

        } catch (Throwable $e) {
            $this->reject($e);
        }
    }

    private function handle(mixed $intermediate): void {
        if ($this->fiber->isTerminated()) {
            $this->fulfill($this->fiber->getReturn());
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
        try {
            $this->handle($this->fiber->resume($result));
        } catch (\FiberError $e) {
            // In some cases the task will be launched in an invalid context
            Loop::queueMicrotask(function() use ($result) {
                try {
                    $this->handle($this->fiber->resume($result));
                } catch (Throwable $e) {
                    $this->reject($e);
                }
            });
        } catch (Throwable $e) {
            $this->reject($e);
        }
    }

    private function throwException($reason): void {
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
                    $this->reject($e);
                }
            });
        } catch (Throwable $e) {
            $this->reject($e);
        }
    }

}
