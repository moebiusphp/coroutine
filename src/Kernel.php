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
use Moebius\Promise\{
    PromiseInterface,
    PromiseTrait
};
use Moebius\Coroutine\Kernel\Compat\{
    React,
    GuzzleHttp,
    Revolt
};
use Fiber, SplMinHeap;

/**
 * The Coroutine Kernel enables support functionality to be implemented outside
 * of the Coroutine class, without resorting to public properties and globals.
 *
 * @internal
 */
abstract class Kernel implements PromiseInterface, StaticEventEmitterInterface {
    use StaticEventEmitterTrait;
    use PromiseTrait;

    /**
     * Handling of active coroutines
     */
    protected static Kernel\Coroutines $coroutines;

    /**
     * Holds deactivated coroutines until an IO event occurs
     */
    protected static Kernel\IO $io;

    /**
     * Holds deactivated coroutines until a promise is resolved
     */
    protected static Kernel\Promises $promises;

    /**
     * Holds deactivated coroutines until a timer expires
     */
    protected static Kernel\Timers $timers;

    /**
     * Holds deactivated coroutines until there is no more work
     * to do and application would terminate.
     */
    protected static Kernel\Zombies $zombies;

    /**
     * Get the current tick time. This time stamp is monotonic and can't be adjusted.
     */
    public static function getTime(): float {
        return self::$currentTime;
    }

    /**
     * Get the current exact time. This time stamp is monotonic and can't be adjusted.
     */
    public static function getRealTime(): float {
        return (hrtime(true) - self::$hrStartTime) / 1000000000;
    }

    /**
     * Set the max time delay before the next tick should be started. This is intended
     * for use by timers and other events so that we avoid running the loop at full CPU
     * speed.
     */
    public static function setMaxDelay(float $seconds): void {
        self::$nextTickTime = min(self::$nextTickTime, self::$currentTime + $seconds);
    }

    public static function getMaxDelay(): float {
        return max(0, self::$nextTickTime - self::getRealTime());
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
     * Time when the next tick must begin. Modules should update this after
     * their tick. It will be reset to default when the tick begins.
     */
    private static float $nextTickTime = 0;

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
     * Hooks for sleeping (e.g. run stream_select() or similar). The remaining sleep time
     * will be divided between all sleep functions and is given as a float with a number
     * of seconds.
     */
    protected static array $hookSleepFunctions = [];

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
     * Closures that should run as soon as possible after the current
     * coroutine or before the next coroutine starts
     */
    protected static array $microtasks = [];

    /**
     * Closures that should run as soon as possible after the cycle
     */
    protected static array $deferred = [];

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
        return self::$io->suspendUntil($resource, Kernel\IO::READABLE, $timeout);
    }

    /**
     * Suspend the current coroutine until writing to a stream resource will
     * not block.
     *
     * @param resource $resource    The stream resource
     * @param ?float $timeout       An optional timeout in seconds
     * @return bool                 false if the stream is no writable (timed out)
     */
    protected static function writable(mixed $resource, float $timeout=null): bool {
        return self::$io->suspendUntil($resource, Kernel\IO::WRITABLE, $timeout);
    }

    /**
     * Suspend the current coroutine for a number of seconds. When called from outside
     * of a coroutine, run coroutines for a number of seconds.
     *
     * @param float $seconds Number of seconds to sleep
     * @throws InterruptedException if something instructs the loop to terminate
     */
    protected static function sleep(float $seconds): void {
        if ($co = self::getCurrent()) {
            self::$timers->schedule($co, $seconds);
            self::suspend();
        } else {
            $expires = self::getRealTime() + $seconds;
            continue_sleeping:
            try {
                self::runLoop(function() use ($expires) {
                    self::setMaxDelay($expires - self::getRealTime());
                    return self::getTime() < $expires;
                });
            } catch (InterruptedException $e) {
                if ($e->getCode() === 2) {
                    // the loop stopped because no other activity is going on
                    // but we should continue to sleep
                    goto continue_sleeping;
                }
                self::logException($e);
                throw $e;
            }
        }
    }

    /**
     * Suspend the current coroutine until the next tick. When called from outside of a
     * coroutine, run one tick of coroutines.
     */
    protected static function suspend(): void {
        if (Fiber::getCurrent() === null) {
            if (self::$running) {
                // this normally is caused features that automatically call suspend via destructors
                // or stream wrappers
                self::logDebug("The loop is running, and suspend was called from outside a fiber");
                return;
            }
            self::runLoop(function() {
                return false;
            });
        } elseif (self::getCurrent()) {
            Fiber::suspend();
        } else {
            throw new UnknownFiberException("Call to Coroutine::suspend() from an unknown Fiber");
        }
    }

    protected static function runMicrotasks(): void {
        foreach (self::$microtasks as $k => $task) {
            try {
                $this->logDebug("Running microtask {key} for coroutine {id}", ['key' => $k, 'id' => $co->id]);
                $task();
            } catch (\Throwable $e) {
                $this->logException($e);
            }
        }
        self::$microtasks = [];
    }

    /**
     * Run the event loop until the provided callback returns false. Generally
     * it is preferable to use Co::await() to run the event loop until a promise is
     * resolved.
     *
     * WARNING!
     * --------
     * DO NOT RUN EXTERNAL LOGIC INSIDE THE RESUME FUNCTION, INSTEAD SPAWN A
     * COROUTINE BEFORE RUNNING THE LOOP. LOGIC INSIDE THE RESUME FUNCTION MUST
     * HANDLE SPECIAL SCENARIOS.
     *
     * @throws InterruptedException if the $resumeFunction wants to continue, but the
     *                              event loop has no activity or was stopped by some other.
     *                              request.
     *
     * @param callable $resumeFunction
     */
    protected static function runLoop(callable $resumeFunction=null): void {
        if (self::getCurrent()) {
            throw new InternalLogicException("Can't call Kernel::runLoop() from within a coroutine");
        }
        if (self::$running) {
            throw new InternalLogicException("Loop is already running");
        }

        /**
         * Run one tick iteration including sleeping and stuff. This is a closure
         * because we don't want it to be invoked by anything except runLoop()
         */
        $tick = function() {
            if (self::$debug) {
                self::dumpStats();
            }

            $currentTime = self::getRealTime();
            $maxDelay = self::getMaxDelay();

            if (self::getActivityLevel() > 0) {
                if (count(self::$hookSleepFunctions) > 0) {
                    $delay = $maxDelay / count(self::$hookSleepFunctions);
                    foreach (self::$hookSleepFunctions as $sleepFunction) {
                        $startTime = self::getRealTime();
                        self::invoke($sleepFunction, $delay);
                        $timeSpent = self::getRealTime() - $startTime;
                        $delay = min($timeSpent, $delay);
                    }
                } else {
                    usleep(floor($maxDelay * 1000000));
                }
            }

            self::$currentTime = self::getRealTime();
            self::$nextTickTime = $currentTime + 0.5;

            /**
             * @see self::$hookBeforeTick
             */
            foreach (self::$hookBeforeTick as $handler) {
                self::invoke($handler);
            }

            /**
             * @see self::$hookTick
             */
            foreach (self::$hookTick as $handler) {
                self::invoke($handler);
            }

            /**
             * @see self::$hookAfterTick
             */
            foreach (self::$hookAfterTick as $handler) {
                self::invoke($handler);
            }

            /**
             * @see self::$deferred
             */
            if (self::$deferred !== []) {
                $deferred = self::$deferred;
                self::$deferred = [];
                foreach ($deferred as $callback) {
                    self::invoke($callback);
                }
            }

            self::$tickCount++;
        };

        self::$running = true;
        $loggedActivityLevelWarning = false;
        for (;;) {
            $tick();

            if (null !== $resumeFunction && !$resumeFunction()) {
                /**
                 * The $resumeFunction wants the loop to stop for now
                 */
                self::$running = false;
                return;
            }

            if (self::$running === false) {
                /**
                 * Something has instructed Moebius to stop
                 */
                if (null !== $resumeFunction) {
                    throw new InterruptedException("Moebius was instructed to stop", 1);
                }
                return;
            }

            if (0 === self::getActivityLevel()) {
                /**
                 * The loop has no work to do, so we stop the loop
                 */
                self::$running = false;
                if (null !== $resumeFunction) {
                    throw new InterruptedException("Coroutine has no work to do", 2);
                }
                return;
            }
        }
    }

    /**
     * Returns the total number of managed coroutines and callbacks
     */
    protected static function getActivityLevel(): int {
        return array_sum(self::$moduleActivity) + count(self::$deferred);
    }

    /**
     * When the application would normally stop, this function is invoked to run
     * the event loop until it is empty.
     */
    protected static function onAppTerminate(): void {
        // Don't run if we're coming from a fatal error (uncaught exception).
        $error = error_get_last();
        if ((isset($error['type']) ? $error['type'] : 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            self::logError('[core] Termination error {type}: {message} in {file}:{line}', $error);
            return;
        }

        // Don't run if we're coming from a coroutine that killed the application
        if (self::$coroutines->getCurrent()) {
            // This happens whenever a die() or exit() or a fatal error
            // occurred inside a coroutine.
            self::logException(new CoroutineException("Coroutine caused the application to stop (by calling die() or stop() or causing a fatal error)"));
            self::cleanup();
            return;
        }

        foreach (self::$hookAppTerminate as $k => $hook) {
            self::invoke($hook);
        }

        self::runLoop();

        self::cleanup();
        return;
    }

    /**
     * Get the current coroutine and check that there is no other
     * Fiber being executed.
     */
    protected static function getCurrent(): ?self {
        assert(
            Fiber::getCurrent() === self::$coroutines->getCurrentCoroutine()?->fiber,
            "Fiber and coroutine ".json_encode(self::$coroutines->getCurrentCoroutine()?->id)." mismatch"
        );
        return self::$coroutines->getCurrentCoroutine();
    }

    protected static function invoke(\Closure $callable, mixed ...$args): mixed {
        try {
            return $callable(...$args);
        } catch (\Throwable $e) {
            self::logException($e);
            throw $e;
        }
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
            // enable zend assertions if possible
            $ini = ini_get('zend.assertions');
            if ($ini == '0' || $ini == '1') {
                ini_set('zend.assertions', 1);
            } else {
                self::logDebug('[core] Unable to enable assertions. Use the PHP command line option -d zend.assertions=0 to enable.');
            }
        }

        // enables us to use hrtime
        self::$hrStartTime = hrtime(true);
        self::$currentTime = (hrtime(true) - self::$hrStartTime) / 1000000000;

        foreach ( [
            Kernel\Coroutines::class,
            Kernel\Timers::class,
            Kernel\Promises::class,
            Kernel\Zombies::class,
            Kernel\IO::class,
        ] as $module) {
            self::$modules[$module::$name] = new $module();
            self::$modules[$module::$name]->start();
        }

        self::$coroutines = self::$modules['core.coroutines'];
        self::$io = self::$modules['core.io'];
        self::$timers = self::$modules['core.timers'];
        self::$promises = self::$modules['core.promises'];
        self::$zombies = self::$modules['core.zombies'];

        // detect any other supported event loops and integrate with them
        if (class_exists(\React\EventLoop\Loop::class)) {
            self::$modules[React::$name] = new React();
            self::$modules[React::$name]->start();
        }

        if (class_exists(\Revolt\EventLoop::class)) {
            self::$modules[Revolt::$name] = new Revolt();
            self::$modules[Revolt::$name]->start();
        }

        if (class_exists(\GuzzleHttp\Promise\PromiseInterface::class)) {
            self::$modules[GuzzleHttp::$name] = new GuzzleHttp();
            self::$modules[GuzzleHttp::$name]->start();
        }

        $bootstrapped = true;

        register_shutdown_function(self::onAppTerminate(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    protected static function cleanup(): void {
        foreach (self::$modules as $key => $instance) {
            $instance->stop();
        }
    }

    protected static function writeLog(string $level, string $message, array $vars=[]): void {
        fwrite(STDERR, gmdate('Y-m-d H:i:s').' '.$level.' '.self::interpolateString(trim($message), $vars)."\n");
    }

    protected static function logInfo(string $message, array $vars=[]): void {
        self::writeLog('info', $message, $vars);
    }

    protected static function logDebug(string $message, array $vars=[]): void {
        self::$debug && self::writeLog('debug', $message, $vars);
    }

    protected static function logWarning(string $message, array $vars=[]): void {
        self::writeLog('warn', $message, $vars);
    }

    protected static function logError(string $message, array $vars=[]): void {
        self::writeLog('error', $message, $vars);
    }

    protected static function logException(\Throwable $e) {
        self::logError("{class}: {message} in {file}:{line}. Trace:\n{trace}", [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

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
        $activityLevel = self::getActivityLevel();
        $sleepTime = floor(max(0, self::$nextTickTime - self::getRealTime()) * 1000);
        fwrite(STDERR, "co: tick=".self::$tickCount." sleepTime=".$sleepTime."ms active/total=$activityLevel/".self::$instanceCount." ".implode(" ", $extra).($nl ? "\n" : "\r"));
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
