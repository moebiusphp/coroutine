<?php
namespace Moebius;

use Moebius\Loop;
use Fiber, WeakMap;
use Charm\Event\{StaticEventEmitterInterface, StaticEventEmitterTrait};

/**
 * Run a callback in a resumable way.
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
     * Create a new coroutine
     */
    public static function create(callable $coroutine, mixed ...$args): Promise {
        self::bootstrap();
        $c = new self($coroutine, ...$args);
        $c->run();
        return $c->promise;
    }

    /**
     * Suspend the current coroutine.
     */
    public static function suspend(): void {
        if (null !== ($c = self::getCurrent())) {
            Fiber::suspend($c);
        } else {
            throw new \Exception("No coroutine is running when trying to suspend.");
        }
    }

    /**
     * Call this function in busy loops to automatically suspend once the runtime has expired.
     * Has no effect outside of coroutines.
     */
    public static function interrupt(): void {
        if (
            self::$deadline !== null &&
            self::$deadline < microtime(true)
        ) {
            self::suspend();
        }
    }

    /**
     * Set configuration options for coroutines
     *
     * @param float $maxTime            The time available before Coroutine::interrupt() will yield
     */
    public static function configure(
        float $maxTime = null
    ) {
        self::bootstrap();
        if ($maxTime !== null) {
            self::$maxTime = $maxTime;
        }
    }

    /**
     * Get the current coroutine if one is running
     */
    public static function getCurrent(): ?self {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return null;
        }
        if (!isset(self::$fibers[$fiber])) {
            return null;
        }
        return self::$fibers[$fiber];
    }


    /**
     * The fiber should yield within this time
     */
    private static ?float $deadline = null;

    private Promise $promise;
    private Fiber $fiber;
    private array $args;
    private mixed $lastResult;
    private float $runTime = 0;
    private ?\Throwable $exception = null;

    private function __construct(callable $coroutine, mixed ...$args) {
        $this->promise = new Promise();
        $this->fiber = new Fiber($coroutine);
        $this->args = $args;
        self::$fibers[$this->fiber] = $this;
    }

    /**
     * Start or resume the coroutine.
     *
     * @return bool TRUE if the coroutine has finished
     */
    private function run(): bool {
        if (Fiber::getCurrent() && self::getCurrent()) {
            throw new \Exception("Can't run coroutine from within another coroutine");
        }
        if ($this->fiber->isTerminated()) {
            throw new CoroutineException("Coroutine has terminated");
        }

        $startTime = microtime(true);

        self::$deadline = $startTime + self::$maxTime;

        $this->startContext();

        try {
            if ($this->fiber->isSuspended()) {
                $this->lastResult = $this->fiber->resume($this->lastResult);
            } elseif (!$this->fiber->isStarted()) {
                $this->lastResult = $this->fiber->start(...$this->args);
            } else {
                throw new CoroutineException("Coroutine is in an unexpected state");
            }
        } catch (\Throwable $e) {
            self::logException($e);
            // @TODO Could be useful to see these exceptions in a PSR logger
            $this->exception = $e;
            $this->promise->reject($e);
        }

        switch ($this->promise->status()) {
            case Promise::FULFILLED:
                unset(self::$fibers[$this->fiber]);
                return true;
            case Promise::REJECTED:
                unset(self::$fibers[$this->fiber]);
                return true;
        }
        if ($this->fiber->isTerminated()) {
            unset(self::$fibers[$this->fiber]); // helps garbage collection a little
            $returnValue = $this->fiber->getReturn();
            $this->promise->resolve($returnValue);
            return true;
        } else {
            $this->stopContext();
            $this->runTime += microtime(true) - $startTime;
            Loop::defer($this->run(...));
            return false;
        }
    }

    private static function logException(\Throwable $e): void {
        fwrite(STDERR, date('Y-m-d H:i:s').' error '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n");
    }

    /**
     * Context switching: setup the context for the
     * coroutine.
     */
    private function startContext(): void {
        // TODO
    }

    /**
     * Context switching: save the context for the
     * coroutine.
     */
    private function stopContext(): void {
        // TODO
    }

    /**
     * Configure the max time-slice a coroutine is allowed to run.
     */
    private static float $maxTime = 0.01;

    /**
     * Allow us to get the current Coroutine based on the current
     * Fiber.
     */
    private static WeakMap $fibers;

    /**
     * Map from Fiber instance to Coroutine instances.
     */
    private static bool $bootstrapped = false;

    /**
     * Setup the coroutine class support environment
     */
    private static function bootstrap(): void {
        if (self::$bootstrapped) {
            return;
        }
        self::$fibers = new WeakMap();
        self::$bootstrapped = true;
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }
}
