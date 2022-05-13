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
use Moebius\Coroutine\Kernel\IO;
use Moebius\Promise\{
    PromiseInterface,
    PromiseTrait
};
use Fiber, SplMinHeap, Closure, TypeError;

/**
 * A Coroutine API for PHP, managing fibers efficiently and transparently.
 */
final class Coroutine extends Kernel implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;
    use PromiseTrait;

    /**
     * Run a coroutine and wait for it to return or throw an exception.
     *
     * @param callable $coroutine    The function to run
     * @param mixed ...$args        Arguments to pass to the function
     * @return mixed                The value returned from the coroutine
     * @throws \Throwable           Any exception thrown by the coroutine
     */
    public static function run(callable $coroutine, mixed ...$args): mixed {
        return self::await(self::go($coroutine, ...$args));
    }

    /**
     * Run a coroutine in parallel. Returns a Coroutine object.
     *
     * @param callable $coroutine    The function to run
     * @param mixed ...$args        Arguments to pass to the function
     * @return Coroutine            A coroutine
     */
    public static function go(callable $coroutine, mixed ...$args): Coroutine {
/*
        if (!is_callable($coroutine)) {
            throw new TypeError('Argument #1 ($coroutine) must be of type Closure, '.get_debug_type($coroutine).' given');
        }
*/
        $coroutine = Closure::fromCallable($coroutine);
        $co = new self($coroutine, $args);

        // swap in this coroutine temporarily and let it run one iteration
        self::$coroutines->add($co);

        return $co;
    }

    public static function unblock(mixed $resource): mixed {
        return Coroutine\Unblocker::unblock($resource);
    }

    /**
     * Await a return value from a coroutine or a promise, while allowing
     * other coroutines to perform some work.
     *
     * @param object $thenable      A coroutine, closure or a promise
     * @return mixed                The value returned from the coroutine
     * @throws \Throwable           Any exception thrown by the coroutine
     */
    public static function await(object $thenable): mixed {
        if (is_callable($thenable) && $thenable instanceof Closure) {
            return self::$promises->awaitThenable(self::go($thenable));
        }
        return self::$promises->awaitThenable($thenable);
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
        return parent::readable($resource, $timeout);
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
        return parent::writable($resource, $timeout);
    }

    /**
     * Suspend the current coroutine for a number of seconds. When called from outside
     * of a coroutine, run coroutines for a number of seconds.
     *
     * @param float $seconds Number of seconds to sleep
     */
    public static function sleep(float $seconds): void {
        parent::sleep($seconds);
    }

    /**
     * Suspend the current coroutine until the next tick. When called from outside of a
     * coroutine, run one tick of coroutines.
     */
    public static function suspend(): void {
        Kernel::suspend();
    }

    /**
     * Register a function to run as soon as possible.
     *
     * @param $callback The function to invoke
     * @param $maxDelay The maximum number of seconds we can wait
     */
    public static function defer(callable $callback, float $maxDelay=0): void {
        self::$deferred[] = $callback;
        self::setMaxDelay($maxDelay);
    }

    /**
     * Advanced usage.
     *
     * Run coroutines until there is nothing left to do or until {@see Coroutine::stop()}
     * is called.
     */
    public static function drain(): void {
        if ($co = self::getCurrent()) {
            self::$zombies->bury($co);
            self::suspend();
        } else {
            self::runLoop(function() {
                return true;
            });
        }
    }
    protected static array $drainers = [];

    /**
     * Advanced usage.
     *
     * Stop draining the event loop. This essentially causes all calls to the {@see self::drain()} to
     * finish, but it will not actually terminate all coroutines.
     */
    public function stop(): void {
        if (!self::$running) {
            // already stopped
            return;
        }
        self::$running = false;
    }

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

    public readonly int $id;
    public readonly string $name;

    private static int $nextAvailableId = 0;

    /**
     * Create a new coroutine instance and add it to the coroutine loop
     */
    private function __construct(callable $coroutine, array $args) {
        $this->id = self::$nextAvailableId++;
        $this->name = self::describeFunction($coroutine);
        self::$instanceCount++;
        $this->fiber = new Fiber($coroutine);
        $this->args = $args;
        $this->Promise();
    }

    /**
     * Run the coroutine for one iteration.
     */
    protected function stepSignal(): void {
        assert(self::$coroutines->getCurrentCoroutine() === $this, "Mismatch between active coroutine when calling stepSignal()");
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

            self::runMicrotasks();

            $stepTimeNS = hrtime(true) - $this->startTimeNS;
            $this->totalTimeNS += $stepTimeNS;
            $this->longestStepTimeNS = max($stepTimeNS, $this->longestStepTimeNS);
            $this->stepCount++;

            if ($this->fiber->isTerminated()) {
                self::$coroutines->terminated($this);
                $this->fulfill($this->fiber->getReturn());
            }
        } catch (\Throwable $e) {
            self::logException($e);

            self::$coroutines->deactivate($this);
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

}
