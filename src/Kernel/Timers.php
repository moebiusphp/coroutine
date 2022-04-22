<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Coroutine;
use Moebius\Coroutine\KernelModule;
use SplMinHeap, Fiber;

/**
 * @internal
 *
 * Implements timer scheduling of coroutines and callbacks.
 */
class Timers extends KernelModule {
    public static string $name = 'core.timers';

    private SplMinHeap $scheduledCallbacks;
    private SplMinHeap $scheduledCoroutines;

    /**
     * Remove a coroutine from the loop, and put it back again after a delay
     * given in seconds.
     */
    public function suspendCoroutine(Coroutine $co, float $seconds): void {
        assert(isset(self::$coroutines[$co->id]), "Coroutine is not enabled, can't freeze it");
        unset(self::$coroutines[$co->id]);
        $this->scheduledCoroutines->insert([ self::getTime() + $seconds, $co ]);
        ++self::$moduleActivity[self::$name];
        if (self::getCurrent() === $co) {
            Fiber::suspend();
        }
    }

    public function scheduleCallback(callable $callback, int $seconds): void {
        $this->scheduledCallbacks->insert([ self::getTime() + $seconds, $callback ]);
        ++self::$moduleActivity[self::$name];
    }

    public function start(): void {
        $this->pausedCoroutines = [];
        $this->scheduledCoroutines = new SplMinHeap();
        $this->scheduledCallbacks = new SplMinHeap();
        self::$moduleActivity[self::$name] = 0;

        self::$hookBeforeTick[self::$name] = function() {
            $now = self::getTime();
            while (!$this->scheduledCallbacks->isEmpty() && $this->scheduledCallbacks->top()[0] < $now) {
                $callback = $this->scheduledCallbacks->extract()[1];
                $callback();
                --self::$moduleActivity[self::$name];
            }
            while (!$this->scheduledCoroutines->isEmpty() && $this->scheduledCoroutines->top()[0] < $now) {
                $co = $this->scheduledCoroutines->extract()[1];
                self::$coroutines[$co->id] = $co;
                --self::$moduleActivity[self::$name];
            }
        };
    }

    public function stop(): void {
        unset(self::$hookBeforeTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
    }
}
