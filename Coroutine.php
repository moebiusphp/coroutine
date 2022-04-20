<?php
namespace Moebius;

use Charm\Event\{
    StaticEventEmitterInterface,
    StaticEventEmitterTrait
};
use Moebius\Coroutine\{
    Exception,
    LogicException,
    RuntimeException,
    CoroutineExpectedException
};
use Fiber, SplMinHeap, Closure;

/**
 * A coroutine.
 */
class Coroutine extends Promise implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;

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
    private static float $currentTime = 0;

    /**
     * Tracks the start time for the loop
     */
    private static int $hrStartTime = 0;

    private static bool $debug = false;

    /**
     * Configurable time window for interrupting coroutines in
     * nanoseconds.
     *
     * @type int
     */
    private static int $interruptTimeNS = 100000000;

    /**
     * Coroutines that are currently active
     *
     * @type array<int, Coroutine>
     */
    private static array $coroutines = [];

    /**
     * Coroutines that are pending an event
     *
     * @type array<int, Coroutine>
     */
    private static array $frozen = [];

    /**
     * Next available coroutine ID
     *
     * @type int
     */
    private static int $nextId = 1;

    /**
     * Tick count increases by one for every tick
     *
     * @type int
     */
    private static int $tickCount = 0;

    /**
     * Microtasks to run immediately after a fiber  suspends, before next 
     * fiber resumes
     *
     * @type array<callable>
     */
    private static array $microTasks = [];

    /**
     * True whenever the coroutine loop is draining. Set this to false
     * to stop the loop.
     *
     * @type bool
     */
    private static bool $running = false;

    /**
     * Fibers that are waiting for a stream resource to become readable.
     *
     * @type array<int, resource>
     */
    private static array $readableStreams = [];

    /**
     * Fibers that are waiting for a stream resource to become writable.
     *
     * @type array<int, resource>
     */
    private static array $writableStreams = [];

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
    private static ?self $current = null;

    /**
     * Counts how many coroutines are currently held in memory
     *
     * @type int
     */
    private static int $instanceCount = 0;

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
        if (!self::$current) {
            self::tick(false);
        } elseif (self::$current->fiber === Fiber::getCurrent()) {
            Fiber::suspend();
        } else {
            throw new UnknownFiberException("Call to Coroutine::suspend() from an unknown Fiber");
        }
    }

    /**
     * Resolve scheduled timers and readable/writable stream resources then run
     * the coroutines
     *
     * @param bool $maySleep    If false, do not sleep.
     * @return int              The number of unfinished coroutines that are being managed
     */
    private static function tick(bool $maySleep=true): int {
        self::bootstrap();
        if (self::$debug) {
            self::dumpStats(false);
        }
        if (Fiber::getCurrent()) {
            throw new LogicException("Can't run Coroutine::tick() from within a Fiber. This is a bug.");
        }

        self::$currentTime = (hrtime(true) - self::$hrStartTime) / 1000000000;

        /**
         * Activate coroutines based on timer.
         */
        $now = hrtime(true);
        while (self::$timers->count() > 0 && self::$timers->top()[0] < $now) {
            list($time, $id) = self::$timers->extract();
            if (is_callable($id)) {
                $id();
            } else {
                self::$coroutines[$id] = self::$frozen[$id];
                unset(self::$frozen[$id]);
            }
        }

        /**
         * Run all active coroutines.
         */
        foreach (self::$coroutines as $co) {
            self::$current = $co;
            $co->step();
            if (self::$microTasks !== []) {
                self::$current = null;
                foreach (self::$microTasks as $task) {
                    $task();
                }
                self::$microTasks = [];
            }
        }
        self::$current = null;

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
            $count = self::streamSelect($readableStreams, $writableStreams, $void, 0, $remainingTime);
            if (!is_int($count)) {
                die("error");

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

        return count(self::$readableStreams) + count(self::$writableStreams) + count(self::$coroutines) + count(self::$timers);
    }

    /**
     * Run all coroutines until there are no more coroutines to run.
     */
    private static function finish(): void {
        if (self::$running) {
            throw new LogicException("Coroutines are already running");
        }
        if (self::$current) {
            // die or a fatal exception inside a coroutine
            self::$current = null;
            self::$coroutines = [];
            self::$frozen = [];
            self::$readableStreams = [];
            self::$writableStreams = [];
            self::$timers = new SplMinHeap();
            return;
        }
        self::$running = true;
        while (self::$running && self::tick() > 0);
        self::$running = false;
    }

    /**
     * Get the current coroutine and check that there is no other
     * Fiber being executed.
     */
    private static function getCurrent(): ?self {
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

        $bootstrapped = true;
        self::$timers = new SplMinHeap();
        register_shutdown_function(self::finish(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    /**
     * Every fiber instance receives a unique ID number which is used
     * to schedule timers and track IO events.
     */
    private int $id;

    /**
     * A reference to the Fiber instance
     */
    private Fiber $fiber;

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
    private function step(): void {
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

    private static function streamSelect(array &$readableStreams, array &$writableStreams, array &$exceptStreams, int $seconds, int $useconds): int {

        $errorCode = null;
        $errorMessage = null;
        set_error_handler(function(int $code, string $message, string $file, int $line) use (&$errorCode, &$errorMessage) {
echo "\$errorMessage $file $line\n";
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
    private static function dumpStats(bool $nl=true): void {
        fwrite(STDERR, "global stats: instances=".self::$instanceCount." active=".count(self::$coroutines)." rs=".count(self::$readableStreams)." ws=".count(self::$readableStreams)." frozen=".count(self::$frozen).($nl ? "\n" : "\r"));
    }

}
