<?php
namespace Moebius;

use Charm\Event\{
    StaticEventEmitterInterface,
    StaticEventEmitterTrait
};
use Moebius\Coroutine\{
    Kernel,
    Exception,
    LogicException,
    RuntimeException,
    InternalLogicException,
    CoroutineExpectedException,
    UnknownFiberException
};
use Moebius\Promise\{
    PromiseInterface,
    PromiseTrait
};
use Fiber, SplMinHeap, Closure;

/**
 * A coroutine.
 */
final class Coroutine extends Kernel implements PromiseInterface, StaticEventEmitterInterface {
    use StaticEventEmitterTrait;
    use PromiseTrait;

    /**
     * Get the current tick time.
     */
    public static function getTime(): float {
        if (self::$currentTime === 0) {
            self::bootstrap();
        }
        return self::$currentTime;
    }

    public static function getTickCount(): int {
        return self::$tickCount;
    }

    /**
     * Run a coroutine and await its result.
     */
    public static function run(Closure $coroutine, mixed ...$args): mixed {
        return self::await(self::go($coroutine, ...$args));
    }

    /**
     * Create and run a coroutine
     */
    public static function go(Closure $coroutine, mixed ...$args): Coroutine {
        $co = new self($coroutine, $args);
        self::suspend();
        return $co;
    }

    /**
     * Await a return value from a coroutine or a promise, while allowing
     * other coroutines to perform some work.
     *
     * @param object $thenable A coroutine or promise-like object.
     */
    public static function await(object $thenable) {
        if (!($thenable instanceof self) && !Promise::isThenable($thenable)) {
            throw new CoroutineExpectedException("Coroutine::await() expects a coroutine or a promise-like object, ".get_debug_type($thenable)." received");
        }

        $promiseStatus = null;
        $promiseResult = null;

        $thenable->then(function($result) use (&$promiseStatus, &$promiseResult) {
            if ($promiseStatus !== null) {
                throw new PromiseResolvedException("Promise is already resolved");
            }
            $promiseStatus = true;
            $promiseResult = $result;
        }, function($reason) use (&$promiseStatus, &$promiseResult) {
            if ($promiseStatus !== null) {
                throw new PromiseResolvedException("Promise is already resolved");
            }
            $promiseStatus = false;
            $promiseResult = $reason;
        });

        if (!self::$current) {
            // Not inside a coroutine
            while ($promiseStatus === null && self::tick(false) > 0);
        } elseif (self::$current->fiber === Fiber::getCurrent()) {
            // Inside a coroutine, so remove it from the loop
            $co = self::$current;
            unset(self::$coroutines[self::$current->id]);
            $thenable->then(function($result) use ($co, &$promiseStatus, &$promiseResult) {
                self::$coroutines[$co->id] = $co;
            }, function($reason) use ($co, &$promiseStatus, &$promiseResult) {
                self::$coroutines[$co->id] = $co;
            });
            Fiber::suspend();
        } else {
            throw new UnknownFiberException("Can't use Coroutine::await() from inside an unknown Fiber");
        }

        if ($promiseStatus === true) {
            return $promiseResult;
        } elseif ($promiseStatus === false) {
            if (!($promiseResult instanceof \Throwable)) {
                throw new RejectedException($promiseResult);
            }
            throw $promiseResult;
        } else {
            throw new LogicException("Promise did not resolve");
        }
    }

    /**
     * Suspend the current coroutine until reading from a stream resource will
     * not block.
     *
     * @param resource $resource    The stream resource
     * @param ?float $timeout       An optional timeout in seconds
     * @return bool                 false if the stream is not readable (timed out or closed)
     */
    public static function readable(mixed $resource, float $timeout=null): bool {
        self::bootstrap();
        if ($timeout !== null) {
            $timeout = hrtime(true) + (1000000000 * $timeout) | 0;
        }

        if ($co = self::getCurrent()) {
            $valid = true;
            if ($timeout !== null) {
                self::$timers->insert([$timeout, function() use ($co, &$valid) {
                    if (!$valid) {
                        return;
                    }
                    $valid = false;
                    self::$coroutines[$co->id] = $co;
                    unset(self::$frozen[$co->id]);
                    unset(self::$readableStreams[$co->id]);
                }]);
            };
            self::$readableStreams[$co->id] = $resource;
            self::$frozen[$co->id] = $co;
            unset(self::$coroutines[$co->id]);
            Fiber::suspend();
            if ($valid && !is_resource($resource)) {
                return false;
            }
            return $valid;
        } else {
            $valid = true;
            if ($timeout !== null) {
                self::$timers->insert([$timeout, function() use (&$valid) {
                    $valid = false;
                }]);
            }
            do {
                if (self::tick(false) > 0) {
                    $sleepTime = 0;
                } else {
                    $sleepTime = 50000;
                }
                if (!is_resource($resource)) {
                    return false;
                }
                $readableStreams = [ $resource ];
                $void = [];
                $count = self::streamSelect($readableStreams, $void, $void, 0, $sleepTime);
            } while ($count === 0 && $valid);
            return $valid;
        }
    }

    /**
     * Suspend the current coroutine until writing to a stream resource will
     * not block.
     *
     * @param resource $resource    The stream resource
     * @param ?float $timeout       An optional timeout in seconds
     * @return bool                 false if the stream is no readable (timed out)
     */
    public static function writable(mixed $resource, float $timeout=null): bool {
        self::bootstrap();
        if ($timeout !== null) {
            $timeout = hrtime(true) + (1000000000 * $timeout) | 0;
        }

        if ($co = self::getCurrent()) {
            $valid = true;
            if ($timeout !== null) {
                self::$timers->insert([$timeout, function() use ($co, &$valid) {
                    if (!$valid) {
                        return;
                    }
                    $valid = false;
                    self::$coroutines[$co->id] = $co;
                    unset(self::$frozen[$co->id]);
                    unset(self::$writableStreams[$co->id]);
                }]);
            };
            self::$writableStreams[$co->id] = $resource;
            self::$frozen[$co->id] = $co;
            unset(self::$coroutines[$co->id]);
            Fiber::suspend();
            if ($valid && !is_resource($resource)) {
                return false;
            }
            return $valid;
        } else {
            $valid = true;
            if ($timeout !== null) {
                self::$timers->insert([$timeout, function() use (&$valid) {
                    $valid = false;
                }]);
            }
            do {
                if (self::tick(false) > 0) {
                    $sleepTime = 0;
                } else {
                    $sleepTime = 50000;
                }
                if (!is_resource($resource)) {
                    return false;
                }
                $writableStreams = [ $resource ];
                $void = [];
                $count = self::streamSelect($void, $writableStreams, $void, 0, $sleepTime);
            } while ($count === 0 && $valid);
            return $valid;
        }
    }

    /**
     * Suspend the current coroutine for a number of seconds. When called from outside
     * of a coroutine, run coroutines for a number of seconds.
     *
     * @param float $seconds Number of seconds to sleep
     */
    public static function sleep(float $seconds): void {
        self::bootstrap();
        $expires = hrtime(true) + ($seconds * 1000000000) | 0;
        if ($co = self::getCurrent()) {
            self::$timers->insert([$expires, $co->id]);
            self::$frozen[$co->id] = $co;
            unset(self::$coroutines[$co->id]);
            Fiber::suspend();
        } else {
            while (hrtime(true) < $expires) {
                self::tick();
            }
        }
    }

    /**
     * Provide an opportunity for the current coroutine to be suspended. The coroutine
     * will only be suspended if the interrupt time slice has been exceeded.
     *
     * @see self::suspend()
     */
    public static function interrupt(): void {
        if (self::$current && (hrtime(true) - self::$current->startTimeNS) > self::$interruptTimeNS) {
            self::suspend();
        }
    }

    /**
     * Suspend the current coroutine until the next tick. When called from outside of a
     * coroutine, run one tick of coroutines.
     */
    public static function suspend(): void {
        if (Fiber::getCurrent() === null) {
            self::tick(false);
        } elseif (self::getCurrent()) {
            Fiber::suspend();
        } else {
            throw new UnknownFiberException("Call to Coroutine::suspend() from an unknown Fiber");
        }
    }

    /**
     * Advanced usage.
     *
     * Run coroutines until there is nothing left to do or until {@see Coroutine::stopDraining()}
     * is called.
     */
    public static function drain(): void {
        if ($self = self::getCurrent()) {
            // A coroutine wants to wait until the event loop is finished, so
            // we'll make a zombie out of it.
            unset(self::$coroutines[$self->id]);
            self::$zombies[$self->id] = $self;
            Fiber::suspend();
            return;
        } else {
            assert(!self::$running, "Drain called during event loop. Indicates bug in Moebius\Coroutine");
            // run coroutines until there are no more work to do
            // when there are no more work, if there are zombies
            // we'll raise the dead and continue draining.
            self::$running = true;
            while (self::$running) {
                $activity = self::tick();
                if ($activity === 0) {
                    if (count(self::$zombies) > 0) {
                        self::$coroutines = self::$zombies;
                        self::$zombies = [];
                    } else {
                        self::$running = false;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Advanced usage.
     *
     * Stop draining the event loop. This essentially causes all calls to the {@see self::drain()} to
     * finish, but it will not actually terminate all coroutines.
     */
    public function stopDraining(): void {
        if (!self::$running) {
            // already stopped
            return;
        }
        self::$running = false;
    }

    /**
     * Every fiber instance receives a unique ID number which is used
     * to schedule timers and track IO events.
     */
    private int $id;

    /**
     * Holds the function arguments until the coroutine starts.
     */
    private ?array $args = null;

    /**
     * The value that will be sent to the fiber the next time it
     * is going to run.
     */
    private mixed $last = null;

    /**
     * True if the value for the fiber is an exception that should
     * be thrown.
     */
    private bool $wasError = false;

    /**
     * Tracks the total time spent in the function in nanoseconds.
     * Large numbers indicates that the function is blocking, or using
     * blocking functions.
     */
    private int $totalTimeNS = 0;

    /**
     * Tracks the last time the function was invoked according to the
     * `hrtime(true)` function call.
     */
    private int $startTimeNS = 0;

    /**
     * Tracks the longest step duration for this function.
     */
    private int $longestStepTimeNS = 0;

    /**
     * Tracks the number of iterations this function has performed.
     */
    private int $stepCount = 0;

    /**
     * Create a new coroutine instance and add it to the coroutine loop
     */
    private function __construct(Closure $coroutine, array $args) {
        self::bootstrap();
        self::$instanceCount++;
        $this->id = self::$nextId++;
        self::$coroutines[$this->id] = $this;
        $this->fiber = new Fiber($coroutine);
        $this->args = $args;
    }

    /**
     * Run the coroutine for one iteration.
     */
    protected function step(): void {
        try {
            $this->startTimeNS = hrtime(true);
            if ($this->fiber->isSuspended()) {
                if ($this->wasError) {
                    $this->wasError = false;
                    $this->last = $this->fiber->throw($this->last);
                } else {
                    $this->last = $this->fiber->resume($this->last);
                }
            } elseif (!$this->fiber->isStarted()) {
                // no need to hold on to a reference to these args and possibly
                // cause a memory leak
                $args = $this->args;
                $this->args = null;
                $this->last = $this->fiber->start(...$args);
            } elseif ($this->fiber->isTerminated()) {
                throw new LogicException("Coroutine is terminated");
            } elseif ($this->fiber->isRunning()) {
                throw new LogicException("Coroutine is running");
            } else {
                throw new LogicException("Coroutine in unknown state");
            }

            $stepTimeNS = hrtime(true) - $this->startTimeNS;
            $this->totalTimeNS += $stepTimeNS;
            $this->longestStepTimeNS = max($stepTimeNS, $this->longestStepTimeNS);
            $this->stepCount++;

            if ($this->fiber->isTerminated()) {
                unset(self::$coroutines[$this->id]);
                $this->resolve($this->fiber->getReturn());
            }
        } catch (\Throwable $e) {
            unset(self::$coroutines[$this->id]);
            $this->reject($e);
        }
    }

    /**
     * The coroutine has terminated
     */
    public function __destruct() {
        self::$instanceCount--;
        if (self::$debug) {
            self::dumpStats();
            fwrite(STDERR, "coroutine stats: total-time=".($this->totalTimeNS/1000000000)."\n");
        }
    }

    protected static function streamSelect(array &$readableStreams, array &$writableStreams, array &$exceptStreams, int $seconds, int $useconds): int {

        $errorCode = null;
        $errorMessage = null;
        set_error_handler(function(int $code, string $message, string $file, int $line) use (&$errorCode, &$errorMessage) {
            $errorCode = $code;
            $errorMessage = $message;
        });
        $streams = [ $readableStreams, $writableStreams, $exceptStreams ];
        $count = stream_select($readableStreams, $writableStreams, $void, $seconds, $useconds);
        restore_error_handler();

        if ($errorCode !== null) {
            throw new \ErrorException($errorMessage, $errorCode);
        }

        return $count;
    }


    /**
     * Helper function for debugging memory leaks and whatnot.
     *
     * @internal
     */
    protected static function dumpStats(bool $nl=true): void {
        fwrite(STDERR, "global stats: instances=".self::$instanceCount." active=".count(self::$coroutines)." rs=".count(self::$readableStreams)." ws=".count(self::$readableStreams)." frozen=".count(self::$frozen).($nl ? "\n" : "\r"));
    }

}
