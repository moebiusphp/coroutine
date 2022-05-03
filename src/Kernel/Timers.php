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
        $time = self::getTime() + $seconds;
        self::$debug && $this->log("Coroutine {id} is being suspended for {seconds} seconds until {time}", ['id' => $co->id, 'seconds' => $seconds, 'time' => $time]);
        self::$modules['core.coroutines']->deactivate($co);
        $this->scheduledCoroutines->insert([ $time, $co ]);
        ++self::$moduleActivity[self::$name];

        if (self::getCurrent() === $co) {
            // If coroutine suspended itself
            Fiber::suspend();
        }
    }

    public function scheduleCallback(callable $callback, float $seconds): void {
        self::$debug && $this->log("Callback scheduled in {seconds} seconds", ['seconds' => $seconds]);
        $this->scheduledCallbacks->insert([ self::getTime() + $seconds, $callback ]);
        ++self::$moduleActivity[self::$name];
    }

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

    public function start(): void {
        $this->log("Start");
        $this->pausedCoroutines = [];
        $this->scheduledCoroutines = new SplMinHeap();
        $this->scheduledCallbacks = new SplMinHeap();
        self::$moduleActivity[self::$name] = 0;

        self::$hookBeforeTick[self::$name] = function() {
            $now = self::getTime();

            /**
             * Run scheduled callbacks
             */
            while (!$this->scheduledCallbacks->isEmpty() && $this->scheduledCallbacks->top()[0] < $now) {
                self::$debug && $this->log("Scheduled callback invoked at {now}", ['now' => $now]);
                $callback = $this->scheduledCallbacks->extract()[1];
                $callback();
                --self::$moduleActivity[self::$name];
            }

            /**
             * Enable scheduled coroutines
             */
            while (!$this->scheduledCoroutines->isEmpty() && $this->scheduledCoroutines->top()[0] < $now) {
                $co = $this->scheduledCoroutines->extract()[1];
                self::$debug && $this->log("Scheduled coroutine {id} invoked at {now}", ['now' => $now, 'id' => $co->id]);
                --self::$moduleActivity[self::$name];
                self::$modules['core.coroutines']->activate($co);
            }
        };
    }

    public function stop(): void {
        $this->log("Stop");
        unset(self::$hookBeforeTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
    }
}
