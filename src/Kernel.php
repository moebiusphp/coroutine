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
use Moebius\Coroutine\Kernel\IO;
use Fiber, SplMinHeap;

/**
 * The Coroutine Kernel enables support functionality to be implemented outside
 * of the Coroutine class, without resorting to public properties and globals.
 *
 * @internal
 */
abstract class Kernel extends Promise implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;

    protected static Kernel\Coroutines $coroutines;
    protected static Kernel\IO $io;
    protected static Kernel\Promises $promises;
    protected static Kernel\Timers $timers;
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
        return self::$io->wait($resource, IO::READABLE, $timeout);
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
        return self::$io->wait($resource, IO::WRITABLE, $timeout);
    }

    /**
     * Suspend the current coroutine for a number of seconds. When called from outside
     * of a coroutine, run coroutines for a number of seconds.
     *
     * @param float $seconds Number of seconds to sleep
     */
    protected static function sleep(float $seconds): void {
        if ($co = self::getCurrent()) {
            self::$timers->schedule($co, $seconds);
            self::suspend();
        } else {
            $expires = self::getRealTime() + $seconds;
            self::runLoop(function() use ($expires) {
                return self::getTime() < $expires;
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

        if (self::$coroutines->current) {
            // This happens whenever a die() or exit() or a fatal error
            // occurred inside a coroutine.
            self::cleanup();
            return;
        }

        // Don't run if we're coming from a fatal error (uncaught exception).
        $error = error_get_last();
        if ((isset($error['type']) ? $error['type'] : 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            self::writeLog('[core] Termination error {type}: {message} in {file}:{line}', $error);
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
        assert(Fiber::getCurrent() === self::$coroutines->current, "Fiber and coroutine mismatch");
        return self::$coroutines->current;
        $fiber = Fuber::getCurrent();
        if ($fiber === null) {
            return null;
        }
        $current = self::$coroutines->current;
        if ($fiber === null) {
            return null;
        }
        assert($current === $fiber);
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

    protected static function invoke(\Closure $callable, mixed ...$args) {
        try {
            $callable(...$args);
        } catch (\Throwable $e) {
            self::writeLog('[kernel] Closure threw {class}: {message} in {file}:{line}', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
            if (ini_get('zend.assertions') != '-1') {
                ini_set('zend.assertions', 1);
            } else {
                self::writeLog('[core] Debug enabled: Unable to enable assertions. Use the PHP command line option -d zend.assertions=0 to enable.');
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

        $bootstrapped = true;

        register_shutdown_function(self::onAppTerminate(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

    protected static function cleanup(): void {
        foreach (self::$modules as $key => $instance) {
            $instance->stop();
        }
    }

    protected static function writeLog(string $message, array $vars=[]): void {
        fwrite(STDERR, gmdate('Y-m-d H:i:s').' '.self::interpolateString(trim($message), $vars)."\n");
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
