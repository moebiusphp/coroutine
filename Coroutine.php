<?php
namespace Moebius;

use Fiber, Closure, Throwable, WeakMap;
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
use Charm\FallbackLogger;
use Psr\Log\LoggerInterface;

use const MOEBIUS_DEBUG;

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

    private static ?LoggerInterface $logger = null;

    public static function go(Closure $callback, mixed ...$args): PromiseInterface {
        return new self($callback, ...$args);
    }

    public static function await(object $promise): mixed {
        self::$awaitPeak = max(++self::$awaitCount, self::$awaitPeak);
        try {
            if ($fiber = self::getCurrent()) {
                if (MOEBIUS_DEBUG) {
                    self::debugLog('Suspended pending promise {id}', ['id' => \spl_object_id($promise)]);
                }
                $result = Fiber::suspend($promise);
                if (MOEBIUS_DEBUG) {
                    self::debugLog('Resumed after promise {id} resolved', ['id' => \spl_object_id($promise)]);
                }
                return $result;
            } else {
                if (MOEBIUS_DEBUG) {
                    self::debugLog('Suspended pending promise {id}', ['id' => \spl_object_id($promise)]);
                }
                $result = Loop::await($promise);
                if (MOEBIUS_DEBUG) {
                    self::debugLog('Resumed after promise {id} resolved', ['id' => \spl_object_id($promise)]);
                }
                return $result;
            }
        } catch (Throwable $e) {
            MOEBIUS_DEBUG and self::debugLog("Exception in Coroutine::await: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        } finally {
            --self::$awaitCount;
        }
    }

    public static function run(Closure $callback, mixed ...$args): mixed {
        return self::await(self::go($callback, ...$args));
    }

    public static function sleep(float $time): void {
        if (MOEBIUS_DEBUG) {
            self::debugLog('Sleep {time} seconds', ['time' => $time]);
        }
        self::$sleepPeak = max(++self::$sleepCount, self::$sleepPeak);
        self::await(Loop::delay($time));
        --self::$sleepCount;
        if (MOEBIUS_DEBUG) {
            self::debugLog('Sleep finished after {time} seconds', ['time' => $time]);
        }
    }

    public static function suspend(): void {
        if (MOEBIUS_DEBUG) {
            self::debugLog("Suspended");
        }
        if (Fiber::getCurrent()) {
            Fiber::suspend();
        } else {
            Loop::defer(static function() { Loop::stop(); });
            Loop::run();
        }
        MOEBIUS_DEBUG and self::debugLog("Resumed");
    }

    public static function readable($fp, float $timeout=null): bool {
        if ($timeout === null) {
            $timeout = 30;
        }
        $t = hrtime(true);
        MOEBIUS_DEBUG and self::debugLog('Awaiting readable stream {streamId} with timeout {timeout}', [
            'streamId' => \get_resource_id($fp),
            'timeout' => ($timeout ?? 'NULL')
        ]);
        $result = true;
        $readPromise = Loop::readable($fp);
        if ($timeout) {
            $delayPromise = Loop::delay($timeout, static function() use ($fp, &$result, $readPromise) {
                MOEBIUS_DEBUG and self::debugLog('Await readable stream {streamId} timed out', ['streamId' => \get_resource_id($fp)]);
                $result = false;
                $readPromise->cancel();
            });
            $readPromise->then(static function() use ($fp, $result, $delayPromise) {
                MOEBIUS_DEBUG and self::debugLog('Await readable stream {streamId} fulfilled, cancelling delay', ['streamId' => \get_resource_id($fp)]);
                $delayPromise->cancel();
            });
            self::await(Promise::any([$readPromise, $delayPromise]));
        } else {
            self::await($readPromise);
        }
        if (MOEBIUS_DEBUG) {
            if ($result) {
                self::debugLog("Stream {streamId} became readable in {time} seconds", [
                    'streamId' => \get_resource_id($fp),
                    'time' => (hrtime(true)-$t)/1_000_000_000
                ]);
            } else {
                self::debugLog("Stream {streamId} timed out in {time} seconds", [
                    'streamId' => \get_resource_id($fp),
                    'time' => (hrtime(true)-$t)/1_000_000_000
                ]);
            }
        }
        return $result;
    }

    public static function writable($fp, float $timeout=null): bool {
        if ($timeout === null) {
            $timeout = 30;
        }
        $t = hrtime(true);
        MOEBIUS_DEBUG and self::debugLog('Awaiting writable stream {streamId} with timeout {timeout}', [
            'streamId' => \get_resource_id($fp),
            'timeout' => ($timeout ?? 'NULL')
        ]);
        $result = true;
        $writePromise = Loop::writable($fp);
        if ($timeout) {
            $delayPromise = Loop::delay($timeout, static function() use ($fp, &$result, $writePromise) {
                MOEBIUS_DEBUG and self::debugLog('Await writable stream {streamId} timed out', ['streamId' => \get_resource_id($fp)]);
                $result = false;
                $writePromise->cancel();
            });
            $writePromise->then(static function() use ($fp, $result, $delayPromise) {
                MOEBIUS_DEBUG and self::debugLog('Await writable stream {streamId} fulfilled, cancelling delay', ['streamId' => \get_resource_id($fp)]);
                $delayPromise->cancel();
            });
            self::await(Promise::any([$writePromise, $delayPromise]));
        } else {
            self::await($writePromise);
        }
        if (MOEBIUS_DEBUG) {
            if ($result) {
                self::debugLog("Stream {streamId} became writable in {time} seconds", [
                    'streamId' => \get_resource_id($fp),
                    'time' => (hrtime(true)-$t)/1_000_000_000
                ]);
            } else {
                self::debugLog("Stream {streamId} timed out in {time} seconds", [
                    'streamId' => \get_resource_id($fp),
                    'time' => (hrtime(true)-$t)/1_000_000_000
                ]);
            }
        }
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
        $result = Unblocker::unblock($fp);
        MOEBIUS_DEBUG and self::debugLog('Unblocking stream {sourceStreamId} as {destinationStreamId}', [
            'sourceStreamId' => \get_resource_id($fp),
            'destinationStreamId' => \get_resource_id($result),
        ]);
        return $result;
    }

    private static bool $focus = false;
    private static ?Fiber $focusFiber = null;
    private static int $nextId = 0;
    private static ?WeakMap $fibers = null;

    public array $onFulfill = [];
    public array $onReject = [];

    private Fiber $fiber;
    private mixed $value;
    private int $id;

    private function __construct(Closure $callback, mixed ...$args) {
        if (self::$fibers === null) {
            self::$fibers = new WeakMap();
        }
        $this->id = self::$nextId++;
        MOEBIUS_DEBUG and self::debugLog("Starting coroutine {id}", ['id' => $this->id]);
        self::$coroutinePeak = max(++self::$coroutineCount, self::$coroutinePeak);
        $this->fiber = new Fiber($callback);
        self::$fibers[$this->fiber] = $this;
        $this->start(...$args);
    }

    private function start(mixed ...$args): void {
        if (self::$focus && self::$focusFiber !== $this->fiber) {
            MOEBIUS_DEBUG and self::debugLog("Start delayed because of focused coroutine");
            Loop::defer(function() use ($args) {
                $this->start(...$args);
            });
            return;
        }
        try {
            $result = $this->fiber->start(...$args);
            $this->handle($result);
        } catch (\FiberError $e) {
            MOEBIUS_DEBUG and self::debugLog("Start exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        } catch (Throwable $e) {
            MOEBIUS_DEBUG and self::debugLog("Start exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            self::unfocus();
            $this->reject($e);
        }
    }

    public function __destruct() {
        --self::$coroutinePeak;
        parent::__destruct();
    }

    private function handle(mixed $intermediate): void {
        MOEBIUS_DEBUG and self::debugLog("Switching to coroutine {id}", ['id' => $this->id]);
        if ($this->fiber->isTerminated()) {
            $returnValue = $this->fiber->getReturn();
            // locking stops here regardless
            self::unfocus();
            $this->fulfill($returnValue);
        } elseif ($intermediate !== null && is_object($intermediate) && Promise::isPromise($intermediate)) {
            // Resume the fiber when the promise is resolved
            $intermediate->then(function($value) {
                Loop::queueMicrotask($this->resume(...), $value);
            }, function($reason) {
                Loop::queueMicrotask($this->throwException(...), $reason);
            });
        } else {
            if ($intermediate !== null) {
                MOEBIUS_DEBUG and self::debugLog("Ignored intermediate value of type {type}", ['type' => \get_debug_type($intermediate)]);
            }
            Loop::defer($this->resume(...));
        }
    }

    private function resume(mixed $result=null): void {
        if (self::$focus && self::$focusFiber !== $this->fiber) {
            MOEBIUS_DEBUG and self::debugLog("Resume delayed because of focused coroutine");
            Loop::defer(function() use ($result) {
                $this->resume($result);
            });
            return;
        }
        ++self::$coroutineResumeCount;
        try {
            $this->handle($this->fiber->resume($result));
        } catch (\FiberError $e) {
            MOEBIUS_DEBUG and self::debugLog("Resume exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            self::unfocus();
            throw $e;
        } catch (Throwable $e) {
            MOEBIUS_DEBUG and self::debugLog("Resume exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
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
            MOEBIUS_DEBUG and self::debugLog("throwException exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
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
            MOEBIUS_DEBUG and self::debugLog("throwException exception: {className}: {message}", ['className' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            self::unfocus();
            $this->reject($e);
        }
    }

    private static function getLogger(): LoggerInterface {
        if (null === self::$logger) {
            self::setLogger(FallbackLogger::get());
        }
        return self::$logger;
    }

    private static function getCurrent(): ?self {
        $fiber = Fiber::getCurrent();
        if ($fiber && isset(self::$fibers[$fiber])) {
            return self::$fibers[$fiber];
        }
        return null;
    }

    private static function debugLog(string $message, array $context=[]): void {
        if (!MOEBIUS_DEBUG) {
            return;
        }

        if ($fiber = self::getCurrent()) {
            $prefix = 'Coroutine #{coroutine-id}: ';
            $context['coroutine-id'] = $fiber->id;
        } else {
            $prefix = 'Coroutine Global Context: ';
        }

        self::getLogger()->debug($prefix.$message, $context);
    }

    public static function setLogger(LoggerInterface $logger): void {
        self::$logger = $logger;
    }

}
