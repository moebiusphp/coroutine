<?php
namespace Moebius\Coroutine;

use Charm\Event\{
    StaticEventEmitterInterface,
    StaticEventEmitterTrait
};
use Moebius\{
    Coroutine,
    Promise
};
use Fiber, SplMinHeap;

/**
 * The Coroutine Kernel enables support functionality to be implemented outside
 * of the Coroutine class, without resorting to public properties and globals.
 *
 * @internal
 */
abstract class Kernel extends Promise implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;

    /**
     * Get the current tick time. This time stamp is monotonic and can't be adjusted.
     */
    public static function getTime(): float {
        if (self::$currentTime === 0) {
            self::bootstrap();
        }
        return self::$currentTime;
    }

    /**
     * Get the current tick number.
     */
    public static function getTickCount(): int {
        return self::$tickCount;
    }

    /**
     * Every fiber instance receives a unique ID number which is used
     * to schedule timers and track IO events.
     */
    protected int $id;

    /**
     * A reference to the Fiber instance
     */
    protected Fiber $fiber;

    /**
     * Implementation detail: Enables the kernel to invoke the step function
     * in coroutine objects. Will be refactored, but works well.
     */
    protected function stepSignal(): void {
    }

    /**
     * Debugging mode?
     */
    protected static bool $debug = false;

    /**
     * Hook for integrating with the Coroutine API
     *
     * Coroutine::events()->on(Coroutine::BOOTSTRAP_EVENT, function() {});
     */
    const BOOTSTRAP_EVENT = self::class.'::BOOTSTRAP_EVENT';

    /**
     * A synchronized time stamp which can be used to coordinate coroutines.
     * Holds the number of seconds with microsecond precision since Coroutine
     * started running.
     */
    protected static float $currentTime = 0;

    /**
     * Tracks the start time for the loop
     */
    protected static int $hrStartTime = 0;

    /**
     * Configurable time window for interrupting coroutines in
     * nanoseconds.
     *
     * @type int
     */
    protected static int $interruptTimeNS = 100000000;

    /**
     * Heap with tasks sorted by the next time they should start. Each
     * task is inserted as an array [ $nanoTime, $coroutineIdOrCallable ]
     *
     * @type SplMinHeap<array{0: int, 1: int|callable}>
     */
    private static ?SplMinHeap $timers = null;

    /**
     * Holds a reference to the currently active coroutine
     *
     * @type self
     */
    protected static ?self $current = null;

    /**
     * Counts how many coroutines are currently held in memory
     *
     * @type int
     */
    protected static int $instanceCount = 0;

    /**
     * Modules provide services for coroutines. Their service
     * provider instance is available in this array with
     * their service name identifier.
     */
    protected static array $modules = [];

    /**
     * Modules can contribute to the activity level which determines if
     * the coroutine loop should continue. Each module adds their count
     * whenever they are managing a coroutine or have pending activities.
     *
     * @var array<string, int>
     */
    protected static array $moduleActivity = [];

    /**
     * Coroutines that are currently active
     *
     * @type array<int, Coroutine>
     */
    protected static array $coroutines = [];

    /**
     * Coroutines that are waiting for the event loop to
     * terminate.
     */
    protected static array $zombies = [];

    /**
     * Coroutines that are pending an event
     *
     * @type array<int, Coroutine>
     */
    protected static array $frozen = [];

    /**
     * Next available coroutine ID
     *
     * @type int
     */
    protected static int $nextId = 1;

    /**
     * Tick count increases by one for every tick
     *
     * @type int
     */
    protected static int $tickCount = 0;

    /**
     * Microtasks to run immediately after a fiber  suspends, before next 
     * fiber resumes.
     *
     * Tasks are added by kernel extensions when needed.
     *
     * @type array<callable>
     */
    protected static array $microTasks = [];

    /**
     * True whenever the coroutine loop is draining. Set this to false
     * to stop the loop.
     *
     * @type bool
     */
    protected static bool $running = false;

    /**
     * Fibers that are waiting for a stream resource to become readable.
     *
     * @type array<int, resource>
     */
    protected static array $readableStreams = [];

    /**
     * Fibers that are waiting for a stream resource to become writable.
     *
     * @type array<int, resource>
     */
    protected static array $writableStreams = [];

    /**
     * Hook for events that must run before all coroutines begin their next
     * iteration.
     */
    protected static array $hookBeforeTick = [];

    /**
     * Hook for events that must run after all coroutines have done completed
     * their iteration.
     *
     * @var array<string, \Closure>
     */
    protected static array $hookAfterTick = [];

    /**
     * Await a return value from a coroutine or a promise, while allowing
     * other coroutines to perform some work.
     *
     * @param object $thenable      A coroutine or a promise
     * @param mixed ...$args        Arguments to pass to the function
     * @return Coroutine            The value returned from the coroutine
     * @throws \Throwable           Any exception thrown by the coroutine
     */
    protected static function await(object $thenable) {
die("Kernel::await deprecated");
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
            self::runLoop(function() use (&$coroutineStatus) {
                return $coroutineStatus === null;
            });
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
    protected static function readable(mixed $resource, float $timeout=null): bool {
        self::bootstrap();
        if ($co = self::getCurrent()) {
            $valid = true;
            if ($timeout !== null) {
                self::schedule($timeout, function() use ($co, &$valid) {
                    if (!$valid) {
                        return;
                    }
                    $valid = false;
                    self::$coroutines[$co->id] = $co;
                    unset(self::$frozen[$co->id]);
                    unset(self::$readableStreams[$co->id]);
                });
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
                self::schedule($timeout, function() use (&$valid) {
                    $valid = false;
                });
            }
            $readableStreams = [ $resource ];
            $void = [];
            self::runLoop(function() use ($readableStreams, $void, &$valid) {
                if (!$valid || !is_resource($readableStreams[0])) {
                    return false;
                }
                return 0 === self::streamSelect($readableStreams, $void, $void, 0, 0);
            });
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
    protected static function writable(mixed $resource, float $timeout=null): bool {
        self::bootstrap();

        if ($co = self::getCurrent()) {
            $valid = true;
            if ($timeout !== null) {
                self::schedule($timeout, function() use ($co, &$valid) {
                    if (!$valid) {
                        return;
                    }
                    $valid = false;
                    self::$coroutines[$co->id] = $co;
                    unset(self::$frozen[$co->id]);
                    unset(self::$writableStreams[$co->id]);
                });
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
                self::schedule($timeout, function() use (&$valid) {
                    $valid = false;
                });
            }
            $writableStreams = [ $resource ];
            $void = [];
            self::runLoop(function() use ($writableStreams, $void, &$valid) {
                if (!$valid || !is_resource($writableStreams[0])) {
                    return false;
                }
                return 0 === self::streamSelect($void, $writableStreams, $void, 0, 0);
            });
            return $valid;
        }
    }

    /**
     * Suspend the current coroutine for a number of seconds. When called from outside
     * of a coroutine, run coroutines for a number of seconds.
     *
     * @param float $seconds Number of seconds to sleep
     */
    protected static function sleep(float $seconds): void {
        self::bootstrap();
        if ($co = self::getCurrent()) {
            self::$modules['core.timers']->suspendCoroutine($co, $seconds);
        } else {
            $expires = hrtime(true) + ($seconds * 1000000000) | 0;
            self::runLoop(function() use ($expires) {
                return hrtime(true) < $expires;
            });
        }
    }

    /**
     * Suspend the current coroutine until the next tick. When called from outside of a
     * coroutine, run one tick of coroutines.
     */
    protected static function suspend(): void {
        if (Fiber::getCurrent() === null) {
            self::tick();
        } elseif (self::getCurrent()) {
            Fiber::suspend();
        } else {
            throw new UnknownFiberException("Call to Coroutine::suspend() from an unknown Fiber");
        }
    }

    /**
     * Run the event loop until the provided callback returns false. If self::$running gets set to
     * false, throw an exception.
     *
     * @param callable $resumeFunction
     */
    protected static function runLoop(callable $resumeFunction): void {
        if (self::$running) {
            if ($co = self::getCurrent()) {
                for (;;) {
                    if (!$resumeFunction()) {
                        return;
                    }
                    if (0 === ($activity = self::getActivityLevel())) {
                        return;
                    }
                    if (!self::$running) {
                        throw new InterruptedException("Moebius\Coroutine::stop() was called");
                    }
                    self::suspend();
                }
            } else {
                throw new InternalLogicException("Kernel::runLoop() must not be called while loop is already running, except for inside a coroutine");
            }
        } else {
            self::$running = true;
            for (;;) {
                if (!$resumeFunction()) {
                    self::$running = false;
                    return;
                }
                if (0 === ($activity = self::getActivityLevel())) {
                    self::$running = false;
                    return;
                }
                if (!self::$running) {
                    self::$running = false;
                    throw new InterruptedException("Moebius\Coroutine::stop() aas called");
                }
                self::tick();
            }
        }
    }

    /**
     * Resolve scheduled timers and readable/writable stream resources then run
     * the coroutines
     *
     * @param bool $maySleep    If false, do not sleep.
     * @return int              The number of unfinished coroutines that are being managed
     */
    protected static function tick(bool $maySleep=true): int {
        self::bootstrap();
        if (self::$debug) {
            self::dumpStats(false);
        }
        if (Fiber::getCurrent()) {
            throw new InternalLogicException("Can't run Coroutine::tick() from within a Fiber. This is a bug.");
        }

        self::$currentTime = (hrtime(true) - self::$hrStartTime) / 1000000000;

        /**
         * @see self::$hookBeforeTick
         */
        foreach (self::$hookBeforeTick as $handler) {
            $handler();
        }

        /**
         * Activate coroutines based on timer.
         */
        $now = hrtime(true);
        while (self::$timers->count() > 0 && self::$timers->top()[0] < $now) {
            list($time, $callback) = self::$timers->extract();
            $callback();
        }

        /**
         * Run all active coroutines.
         */
        foreach (self::$coroutines as $co) {
            self::$current = $co;
            $co->stepSignal();
            foreach (self::$microTasks as $k => $task) {
                $task();
            }
            self::$microTasks = [];
        }
        self::$current = null;

        /**
         * @see self::$hookAfterTick
         */
        foreach (self::$hookAfterTick as $handler) {
            $handler();
        }

        /**
         * How much time can we spend waiting for IO?
         */
        if (!$maySleep) {
            $remainingTime = 0;
        } elseif (self::$coroutines === []) {
            // if there are no busy coroutines, we can afford some sleep time
            $now = hrtime(true);
            $nextEvent = $now + 20000000;
            if (self::$timers->count() > 0) {
                $nextEvent = min($nextEvent, self::$timers->top()[0]);
            }
            if ($nextEvent < $now) {
                $nextEvent = $now;
            }

            // time in microseconds
            $remainingTime = (($nextEvent - $now) / 1000) | 0;
        } else {
            // busy coroutines means no sleep
            $remainingTime = 0;
        }

        /**
         * We'll use streamSelect() to wait if we have streams we need to poll.
         */
        if (count(self::$readableStreams) > 0 || count(self::$writableStreams) > 0) {
            $readableStreams = self::$readableStreams;
            $writableStreams = self::$writableStreams;
            $void = [];
            $retries = 10;
            repeat:
            $count = self::streamSelect($readableStreams, $writableStreams, $void, 0, $remainingTime);
            if (!is_int($count)) {
                if ($retries-- > 0) {
                    // This may be a result of a posix signal, so we'll retry
                    goto repeat;
                }
            } elseif ($count > 0) {
                foreach (self::$readableStreams as $id => $stream) {
                    if (in_array($stream, $readableStreams)) {
                        unset(self::$readableStreams[$id]);
                        self::$coroutines[$id] = self::$frozen[$id];
                        unset(self::$frozen[$id]);
                    }
                }
                foreach (self::$writableStreams as $id => $stream) {
                    if (in_array($stream, $writableStreams)) {
                        unset(self::$writableStreams[$id]);
                        self::$coroutines[$id] = self::$frozen[$id];
                        unset(self::$frozen[$id]);
                    }
                }
            }
        } elseif ($remainingTime > 0) {
            usleep($remainingTime);
        }

        self::$tickCount++;

        return self::getActivityLevel();
    }

    /**
     * Returns the total number of managed coroutines and callbacks
     */
    protected static function getActivityLevel(): int {
        return array_sum(self::$moduleActivity) + count(self::$coroutines) + count(self::$readableStreams) + count(self::$writableStreams) + count(self::$timers);
    }

    /**
     * Run all coroutines until there are no more coroutines to run.
     */
    protected static function finish(): void {

        if (self::$running) {
            throw new LogicException("Coroutines are already running");
        }

        if (self::$current) {
            // This happens whenever a die() or exit() or a fatal error
            // occurred inside a coroutine.
            self::$current = null;
            self::$coroutines = [];
            self::$frozen = [];
            self::$readableStreams = [];
            self::$writableStreams = [];
            self::$timers = new SplMinHeap();
            return;
        }

        Coroutine::drain();
        return;
    }

    /**
     * Get the current coroutine and check that there is no other
     * Fiber being executed.
     */
    protected static function getCurrent(): ?self {
        if (self::$current === null) {
            return null;
        }
        if (self::$current->fiber !== Fiber::getCurrent()) {
            throw new UnknownFiberException("Can't use Coroutine API to manage unknown Fiber instances");
        }
        return self::$current;
    }

    /**
     * Initialize the Coroutine class.
     */
    public static function bootstrap(): void {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }
        if (getenv('DEBUG')) {
            self::$debug = true;
        }
        // enables us to use hrtime
        self::$hrStartTime = hrtime(true);

        foreach ( [
            Kernel\Timers::class,
            Kernel\Promises::class
        ] as $module) {
            self::$modules[$module::$name] = new $module();
            self::$modules[$module::$name]->start();
        }

        $bootstrapped = true;
        self::$timers = new SplMinHeap();
        register_shutdown_function(self::finish(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    /**
     * Perform stream select on these streams. This function is an entrypoint for implementing
     * support for a larger number of streams.
     */
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
/*
    protected static function scheduleCallback(float $timeout, callable $callback): void {
        $timeout = hrtime(true) + (1000000000 * $timeout) | 0;
        self::$timers->insert([ hrtime(true) + (1000000000 * $timeout) | 0, $callbackOrCoroutineId ]);
    }

    protected static function scheduleCoroutine(float $timeout, int $coroutineId): void {
        assert(isset(self::$coroutines[$coroutineId]), "Unknown coroutine id $coroutineId");
        self::$frozen[$coroutineId] = self::$coroutines[$coroutineId];
        unset(self::$coroutines[$coroutineId]);
        self::$timers->insert([ hrtime(true) + (1000000000 * $timeout) | 0, $coroutineId ]);
    }
*/
    /**
     * Helper function for debugging memory leaks and whatnot.
     *
     * @internal
     */
    protected static function dumpStats(bool $nl=true): void {
        fwrite(STDERR, "global stats: instances=".self::$instanceCount." active=".count(self::$coroutines)." timers=".count(self::$timers)." rs=".count(self::$readableStreams)." ws=".count(self::$readableStreams)." frozen=".count(self::$frozen).($nl ? "\n" : "\r"));
    }

    protected static function describeFunction(callable $callable): string {
        $r = new \ReflectionFunction($callable);
        $name = $r->getName();
        $file = $r->getFileName();
        $line = $r->getStartLine();
        return "function $name() in file $file:$line";
    }

}
