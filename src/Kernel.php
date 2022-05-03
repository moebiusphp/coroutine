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
     * Tick count increases by one for every tick
     *
     * @type int
     */
    protected static int $tickCount = 0;

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
     * Hook for events that must run before the main tick functions.
     *
     * @var array<string, \Closure>
     */
    protected static array $hookBeforeTick = [];

    /**
     * Hook for events that must run on every tick
     *
     * @var array<string, \Closure>
     */
    protected static array $hookTick = [];

    /**
     * Hook for events that must run after the main tick functions.
     *
     * @var array<string, \Closure>
     */
    protected static array $hookAfterTick = [];

    /**
     * Hook for events that run after the PHP application terminates
     */
    protected static array $hookAppTerminate = [];

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
                self::$modules['core.timers']->scheduleCallback(function() use ($co, &$valid) {
                    if (!$valid) {
                        return;
                    }
                    $valid = false;
                    self::$coroutines[$co->id] = $co;
                    unset(self::$frozen[$co->id]);
                    unset(self::$readableStreams[$co->id]);
                }, $timeout);
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
                self::$modules['core.timers']->scheduleCallback(function() use (&$valid) {
                    $valid = false;
                }, $timeout);
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
                self::$modules['core.timers']->scheduleCallback($timeout, function() use ($co, &$valid) {
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
                self::$modules['core.timers']->scheduleCallback($timeout, function() use (&$valid) {
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
        self::bootstrap();
        if (self::getCurrent()) {
            throw new InternalLogicException("Can't call Kernel::runLoop() from within a coroutine");
        }
        if (self::$running) {
            throw new InternalLogicException("Loop is already running");
        }
        self::$running = true;
        for (;;) {
            if (!$resumeFunction()) {
                self::$running = false;
                return;
            }

            if (0 === self::getActivityLevel()) {
                self::$running = false;
                return;
            }

            if (!self::$running) {
                self::$running = false;
                throw new InterruptedException("Moebius\Coroutine::stop() was called");
            }

            self::tick();
        }
    }

    /**
     * Resolve scheduled timers and readable/writable stream resources then run
     * the coroutines
     *
     * @param bool $maySleep    If false, do not sleep.
     * @return int              The number of unfinished coroutines that are being managed
     */
    private static function tick(): int {
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
         * @see self::$hookTick
         */
        foreach (self::$hookTick as $handler) {
            $handler();
        }

        /**
         * @see self::$hookAfterTick
         */
        foreach (self::$hookAfterTick as $handler) {
            $handler();
        }

        self::$tickCount++;

        return self::getActivityLevel();


        /**
         * Run all active coroutines.
         */
/*
        foreach (self::$coroutines as $co) {
            self::$current = $co;
            $co->stepSignal();
            foreach (self::$microTasks as $k => $task) {
                $task();
            }
            self::$microTasks = [];
        }
        self::$current = null;
*/

        /**
         * We'll use streamSelect() to wait if we have streams we need to poll.
         */
/*
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
*/
    }

    /**
     * Returns the total number of managed coroutines and callbacks
     */
    protected static function getActivityLevel(): int {
        return array_sum(self::$moduleActivity);
    }

    /**
     * Run all coroutines until there are no more coroutines to run.
     */
    protected static function onAppTerminate(): void {
        foreach (self::$hookAppTerminate as $k => $hook) {
            $hook();
        }

        if (self::$modules['core.coroutines']->current) {
            // This happens whenever a die() or exit() or a fatal error
            // occurred inside a coroutine.
            self::cleanup();
            return;
        }

        // Don't run if we're coming from a fatal error (uncaught exception).
        $error = error_get_last();
        if ((isset($error['type']) ? $error['type'] : 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            return;
        }

        self::runLoop(function() {
            return true;
        });

        self::cleanup();
        return;
    }

    /**
     * Get the current coroutine and check that there is no other
     * Fiber being executed.
     */
    protected static function getCurrent(): ?self {
        $current = self::$modules['core.coroutines']->current;
        $fiber = Fiber::getCurrent();
        if ($fiber === null && $current === null) {
            return null;
        }
        if ($current && $current->fiber === $fiber) {
            return $current;
        }
        if ($current === null) {
            throw new UnknownFiberException("There is no current coroutine, but we are running inside a fiber");
        }
        if ($fiber === null) {
            throw new UnknownFiberException("There is no current fiber, but a coroutine is marked as active");
        }
        throw new UnknownFiberException("The current fiber does not match the current coroutine");
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
        self::$currentTime = (hrtime(true) - self::$hrStartTime) / 1000000000;

        foreach ( [
            Kernel\Coroutines::class,
            Kernel\Timers::class,
            Kernel\Promises::class,
            Kernel\Zombies::class,
        ] as $module) {
            self::$modules[$module::$name] = new $module();
            self::$modules[$module::$name]->start();
        }

        $bootstrapped = true;
//        self::$timers = new SplMinHeap();
        register_shutdown_function(self::onAppTerminate(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    protected static function cleanup(): void {
        foreach (self::$modules as $key => $instance) {
            $instance->stop();
        }
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

    protected static function writeLog(string $message, array $vars=[]): void {
        fwrite(STDERR, self::interpolateString(gmdate('Y-m-d H:i:s').' '.trim($message)."\n", $vars));
    }

    /**
     * Helper function for debugging memory leaks and whatnot.
     *
     * @internal
     */
    protected static function dumpStats(bool $nl=true): void {
        $extra = [];
        foreach (self::$moduleActivity as $k => $v) {
            $extra[] = "$k=$v";
        }
        fwrite(STDERR, "co: tick=".self::$tickCount." tot=".self::$instanceCount." read=".count(self::$readableStreams)." write=".count(self::$readableStreams)." ".implode(" ", $extra).($nl ? "\n" : "\r"));
    }

    protected static function describeFunction(callable $callable): string {
        $r = new \ReflectionFunction($callable);
        $name = $r->getName();
        $file = $r->getFileName();
        $line = $r->getStartLine();
        return "function $name() in file $file:$line";
    }

    private static function interpolateString(string $message, array $vars=[]): string {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($vars as $key => $val) {
            // check that the value can be cast to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
