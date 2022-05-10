<?php
namespace Moebius\Coroutine\Kernel;

use Closure;
use Moebius\Coroutine;
use Moebius\Coroutine\KernelModule;
use Moebius\Coroutine\Kernel\EventHandler;
use Moebius\Coroutine\Kernel\Timers\MinHeap;

/**
 * @internal
 *
 * Implements timer scheduling of coroutines and callbacks.
 */
class Timers extends KernelModule {
    public static string $name = 'core.timers';

    private MinHeap $schedule;
    private array $active;

    private static int $nextTimerId = 0;

    public function getNextEventTime(): ?float {
        return $this->schedule->isEmpty() ? null : $this->schedule->top()->timeout;
    }

    /**
     * Schedule a future callback or enabling of a coroutine.
     *
     * @param Closure|Coroutine $c  The coroutine to reactivate or callback to run
     * @param float $seconds        Number of seconds to wait before running
     * @param mixed $extra          For callbacks, an optional argument when running the callback
     */
    public function schedule(Closure|Coroutine $c, float $seconds, mixed $extra=null): int {
        $event = new EventHandler($c, $seconds, $extra);

        if (self::$debug) {
            if ($c instanceof Coroutine) {
                $this->log("Event {eventId}: Coroutine {id} is being suspended for {seconds} seconds until {time}", ['eventId' => $event->id, 'id' => $c->id, 'seconds' => $seconds, 'time' => $event->timeout]);
            } else {
                $this->log("Event {eventId}: Closure scheduled in {seconds} seconds at {time}", ['eventId' => $event->id, 'seconds' => $seconds, 'time' => $event->timeout]);
            }
        }

        if ($c instanceof Coroutine) {
            self::$coroutines->deactivate($c);
        }

        $this->schedule->insert($event);
        $this->active[$event->id] = $event;
        ++self::$moduleActivity[self::$name];

        return $event->id;
    }

    public function cancel(int $timerId): void {
        if (!isset($this->active[$timerId])) {
            return;
        }
        --self::$moduleActivity[self::$name];

        $event = $this->active[$timerId];

        if ($event->value instanceof Coroutine) {
            self::$debug && $this->log("Event {eventId}: Coroutine {id} reactivated after cancelled schedule", ['eventId' => $event->id, 'id' => $event->value->id]);
            self::$coroutines->activate($event->value);
        }

        $event->cancelled();
        unset($this->active[$timerId]);
    }

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

    public function start(): void {
        $this->log("Start");
        $this->schedule = new MinHeap();
        $this->active = [];
        self::$moduleActivity[self::$name] = 0;

        self::$hookBeforeTick[self::$name] = function() {
            $now = self::getTime();

            /**
             * Run scheduled events
             */
            while (!$this->schedule->isEmpty() && $this->schedule->top()->timeout <= $now) {
                $event = $this->schedule->extract();
                if (!isset($this->active[$event->id])) {
                    // event has been cancelled
                    continue;
                }
                --self::$moduleActivity[self::$name];
                if ($event->value instanceof Coroutine) {
                    self::$debug && $this->log("Event {eventId}: Scheduled coroutine {id} activated at {now}", ['eventId' => $event->id, 'now' => $now, 'id' => $event->value->id]);
                    self::$coroutines->activate($event->value);
                } else {
                    self::$debug && $this->log("Event {eventId}: Scheduled callback invoked at {now}", ['eventId' => $event->id, 'now' => $now]);
                    self::invoke($event->value, $event->extra);
                }
                $event->activated();
            }
        };
    }

    public function stop(): void {
        $this->log("Stop");
        $this->active = [];
        $this->schedule = new MinHeap();
        unset(self::$hookBeforeTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
    }
}
