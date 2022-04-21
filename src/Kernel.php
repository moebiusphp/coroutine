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
     * A reference to the Fiber instance
     */
    protected Fiber $fiber;

    abstract protected function step(): void;


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
    protected static ?SplMinHeap $timers = null;

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
     * fiber resumes
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

        return count(self::$readableStreams) + count(self::$writableStreams) + count(self::$coroutines) + count(self::$timers);
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

        $bootstrapped = true;
        self::$timers = new SplMinHeap();
        register_shutdown_function(self::finish(...));
        self::events()->emit(self::BOOTSTRAP_EVENT, (object) [], false);
    }

}
