<?php
namespace Moebius;

use Charm\Event\{
    StaticEventEmitterInterface,
    StaticEventEmitterTrait
};
use Fiber, SplMinHeap, Closure;

class Coroutine extends Promise implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;

    /**
     * Hook for integrating with the Coroutine API
     *
     * Coroutine::events()->on(Coroutine::BOOTSTRAP_EVENT, function() {});
     */
    const BOOTSTRAP_EVENT = self::class.'::BOOTSTRAP_EVENT';

    /**
     * @type array<int, Coroutine> Coroutines that are currently active
     */
    private static array $coroutines = [];
    private static array $frozen = [];

    /**
     * @type int Next available coroutine ID
     */
    private static int $nextId = 1;

    /**
     * @type int Tick count increases by one for every tick
     */
    private static int $tickCount = 0;

    /**
     * @type array<callable> Microtasks to run immediately after a fiber suspends, before next fiber resumes
     */
    private static array $microTasks = [];

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
        $result = new self($coroutine, $args);
        return $result;
    }

    /**
     * Await a return value from a coroutine or a promise
     */
    public static function await(object $thenable) {
        $promiseStatus = null;
        $promiseResult = null;

        $thenable->then(function($result) use (&$promiseStatus, &$promiseResult) {
            if ($promiseStatus !== null) {
                throw new \Exception("Promise is resolved");
            }
            $promiseStatus = true;
            $promiseResult = $result;
        }, function($reason) use (&$promiseStatus, &$promiseResult) {
            if ($promiseStatus !== null) {
                throw new \Exception("Promise is resolved");
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
            throw new \Exception("Can't await from inside an unknown Fiber");
        }

        if ($promiseStatus === true) {
            return $promiseResult;
        } elseif ($promiseStatus === false) {
            if (!($promiseResult instanceof \Throwable)) {
                throw new RejectedException($promiseResult);
            }
            throw $promiseResult;
        } else {
            throw new \Exception("Promise did not resolve");
        }
    }

    public static function readable(mixed $resource, float $timeout=null): bool {
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
                $readableStreams = [ $resource ];
                $void = [];
                $count = stream_select($readableStreams, $void, $void, 0, $sleepTime);
            } while ($count === 0 && $valid);
            return $valid;
        }
    }

    public static function writable(mixed $resource, float $timeout=null): bool {
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
                $writableStreams = [ $resource ];
                $void = [];
                $count = stream_select($void, $writableStreams, $void, 0, $sleepTime);
            } while ($count === 0 && $valid);
            return $valid;
        }
    }

    public static function sleep(float $seconds): void {
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

    private static function tick(bool $maySleep=true): int {
        if (Fiber::getCurrent()) {
            throw new \Exception("Can't run Coroutine::tick() from within a Fiber. This is a bug.");
        }

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
         * We'll use stream_select() to wait if we have streams we need to poll.
         */
        if (count(self::$readableStreams) > 0 || count(self::$writableStreams) > 0) {
            $readableStreams = self::$readableStreams;
            $writableStreams = self::$writableStreams;
            $void = [];
            $count = stream_select($readableStreams, $writableStreams, $void, 0, $remainingTime);
            if ($count > 0) {
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

        return count(self::$readableStreams) + count(self::$writableStreams) + count(self::$coroutines) + count(self::$timers);
    }

    public static function suspend(): void {
        if (!self::$current) {
            self::tick(false);
        } elseif (self::$current->fiber === Fiber::getCurrent()) {
            Fiber::suspend();
        } else {
            throw new \Exception("Can't suspend this fiber");
        }
    }

    private static function finish(): void {
        if (self::$running) {
            throw new \Exception("Coroutines are already running");
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

    private static function getCurrent(): ?self {
        if (self::$current === null) {
            return null;
        }
        if (self::$current->fiber !== Fiber::getCurrent()) {
            throw new \Exception("Can't use Coroutine API from external Fiber instances");
        }
        return self::$current;
    }

    public static function bootstrap(): void {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }
        $bootstrapped = true;
        self::$timers = new SplMinHeap();
        register_shutdown_function(self::finish(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    // Is the event loop currently running?
    private static bool $running = false;

    /**
     * @type array<int, resource> Fiber ID that are waiting for a stream resource to become readable.
     */
    private static array $readableStreams = [];

    /**
     * @type array<int, resource> Fiber ID that are waiting for a stream resource to become writable.
     */
    private static array $writableStreams = [];

    /**
     * @type SplMinHeap<array{0: int, 1: int}> Heap of [ nanotime, coroutineId ] scheduled to after a particular hrtime()
     */
    private static ?SplMinHeap $timers = null;

    // Reference to the currently active coroutine
    private static ?self $current = null;

    // Counts the number of Coroutine instances in memory
    private static int $instanceCount = 0;

    private int $id;
    private Fiber $fiber;
    private ?array $args = null;
    private mixed $last = null;
    private bool $wasError = false;

    private function __construct(Closure $coroutine, array $args) {
        self::$instanceCount++;
        $this->id = self::$nextId++;
        self::$coroutines[$this->id] = $this;
        $this->fiber = new Fiber($coroutine);
        $this->args = $args;
    }

    /**
     * Make one step of progress.
     */
    private function step(): void {
        try {
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
                throw new \Exception("Coroutine is terminated");
            } elseif ($this->fiber->isRunning()) {
                throw new \Exception("Coroutine is running");
            } else {
                throw new \Exception("Coroutine in unknown state");
            }
            if ($this->fiber->isTerminated()) {
                unset(self::$coroutines[$this->id]);
                $this->resolve($this->fiber->getReturn());
            }
        } catch (\Throwable $e) {
            $this->reject($e);
        }
    }

    public function __destruct() {
        self::$instanceCount--;
    }

    public static function dumpStats(): void {
        echo "STATS: instances=".self::$instanceCount." active=".count(self::$coroutines)." rs=".count(self::$readableStreams)." ws=".count(self::$readableStreams)." frozen=".count(self::$frozen)."\n";
    }

}

Coroutine::bootstrap();
