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
            $this->logDebug("Event {eventId}: Coroutine {id} reactivated after cancelled schedule", ['eventId' => $event->id, 'id' => $event->value->id]);
            self::$coroutines->activate($event->value);
        }

        $event->cancelled();
        unset($this->active[$timerId]);
    }

    public function start(): void {
        $this->logDebug("Start");
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
                    self::$coroutines->activate($event->value);
                } else {
                    self::invoke($event->value, $event->extra);
                }
                $event->activated();
            }

        };

        self::$hookAfterTick[self::$name] = function() {
            if (!$this->schedule->isEmpty()) {
                $maxDelay = $this->schedule->top()->timeout - self::getRealTime();
                self::setMaxDelay($maxDelay);
            }
        };
    }

    public function stop(): void {
        $this->logDebug("Stop");
        $this->active = [];
        $this->schedule = new MinHeap();
        unset(self::$hookBeforeTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
        unset(self::$hookAfterTick[self::$name]);
    }
}
