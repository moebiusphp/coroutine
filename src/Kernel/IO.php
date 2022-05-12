<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Coroutine;
use Moebius\Coroutine\{
    IOException,
    InvalidArgumentException,
    KernelModule
};
use Moebius\Coroutine\Kernel\IO\Event;
use Closure;

class IO extends KernelModule {

    public static string $name = 'core.io';

    const READABLE = 1;
    const WRITABLE = 2;
    const EXCEPTION = 4;

    private array $handlers = [];
    private array $resources = [];

    private function watch($resource, int $eventType, Closure|Coroutine $c, float $seconds=null): EventHandler {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException("Argument 1 expects a stream resource");
        }

        if (
            ($eventType & self::READABLE) === 0 &&
            ($eventType & self::WRITABLE) === 0 &&
            ($eventType & self::EXCEPTION) === 0
        ) {
            throw new InvalidArgumentException("Argument 2 expects one of the ".self::class."::* constants");
        }

        $handler = new EventHandler($c, $seconds, $eventType);

        $this->logDebug("Watcher {watcherId} on resource {id} for event type {eventType} with timeout {seconds} seconds", ['watcherId' => $handler->id, 'id' => get_resource_id($resource), 'eventType' => $eventType, 'seconds' => $seconds ?? 'NULL']);

        if ($seconds !== null) {
            self::$timers->schedule($this->cancel(...), $seconds, $handler->id);
        }

        $this->handlers[$handler->id] = $handler;
        $this->resources[$handler->id] = $resource;

        ++self::$moduleActivity[self::$name];

        return $handler;
    }

    /**
     * Block until a resource becomes readable, writable or an exceptino occurs, or
     * until the timeout passes. Returns false if a timeout occurs.
     */
    public function suspendUntil($resource, int $eventType, float $seconds = null): bool {
        if ($co = self::getCurrent()) {
            self::$coroutines->deactivate($co);
            $eventHandler = $this->watch($resource, $eventType, $co, $seconds);
            self::suspend();
        } else {
            // not a coroutine, so we must watch via runLoop
            $eventHandler = $this->watch($resource, $eventType, function() {}, $seconds);
            self::runLoop(function() use ($eventHandler) {
                return $eventHandler->result() === null;
            });
        }
        return $eventHandler->result();
    }

    public function on($resource, int $eventType, Closure $callback, float $seconds=null): int {
        return $this->watch($resource, $eventType, $callback, $seconds)->id;
    }

    public function cancel(int $id): void {
        if (!isset($this->handlers[$id])) {
            return;
        }
        $this->logDebug("Cancelling IO watcher {id}", ['id' => $id]);
        if ($this->handlers[$id]->value instanceof Coroutine) {
            self::$coroutines->activate($this->handlers[$id]->value);
        }
        $this->handlers[$id]->cancelled();
        unset($this->handlers[$id]);
        unset($this->resources[$id]);
        --self::$moduleActivity[self::$name];
    }

    private function onAfterTick() {
        if (isset(self::$hookSleepFunctions[self::$name]) || $this->handlers !== []) {
            if ($this->handlers === []) {
                unset(self::$hookSleepFunctions[self::$name]);
            } else {
                self::$hookSleepFunctions[self::$name] = $this->performStreamSelect(...);
            }
        }
    }

    private function performStreamSelect(float $seconds): void {
        $readableStreams = [];
        $writableStreams = [];
        $exceptionStreams = [];

        foreach ($this->handlers as $handler) {
            $resource = $this->resources[$handler->id];
            if (!is_resource($resource)) {
                $this->cancel($handler->id);
                continue;
            }
            if (0 !== ($handler->extra & self::READABLE)) {
                $readableStreams[] = $resource;
            }
            if (0 !== ($handler->extra & self::WRITABLE)) {
                $writableStreams[] = $resource;
            }
            if (0 !== ($handler->extra & self::EXCEPTION)) {
                $exceptionStreams[] = $resource;
            }
        }

        $sec = floor($seconds);
        $usec = (1000000 * ($seconds - $sec)) | 0;
        $count = @stream_select($readableStreams, $writableStreams, $exceptionStreams, $sec, $usec);
        if ($count === false) {
            $this->logWarning("stream_select() failed, possibly because of a signal");
            return;
        }
        if ($count === 0) {
            return;
        }

        /**
         * According to documentation for event loop implementations, it is sensible to double
         * check that a resource is actually readable. Not sure if it helps, but it is very
         * cheap to do - so here goes.
         */

        $readableIds = array_map(get_resource_id(...), $readableStreams);
        $writableIds = array_map(get_resource_id(...), $writableStreams);
        $exceptionIds = array_map(get_resource_id(...), $exceptionStreams);

        foreach ($this->handlers as $handler) {
            $resourceId = get_resource_id($this->resources[$handler->id]);

            if (
                (0 !== ($handler->extra & self::READABLE) && in_array($resourceId, $readableIds, true)) ||
                (0 !== ($handler->extra & self::WRITABLE) && in_array($resourceId, $writableIds, true)) ||
                (0 !== ($handler->extra & self::EXCEPTION) && in_array($resourceId, $exceptionIds, true))
            ) {
                $this->logDebug("Activating watcher {id}", ['id' => $handler->id]);
                unset($this->handlers[$handler->id]);
                unset($this->resources[$handler->id]);
                if ($handler->value instanceof Coroutine) {
                    self::$coroutines->activate($handler->value);
                } else {
                    self::invoke($handler->value);
                }
                --self::$moduleActivity[self::$name];
                $handler->activated();
            }
        }
    }

    public function start(): void {
        $this->logDebug("Start");
        self::$moduleActivity[self::$name] = 0;
        self::$hookAfterTick[self::$name] = $this->onAfterTick(...);
        $this->readable = [];
        $this->writable = [];
    }

    public function stop(): void {
        $this->logDebug("Stop");
        unset(self::$moduleActivity[self::$name]);
        unset(self::$hookAfterTick[self::$name]);
        unset(self::$hookSleepFunctions[self::$name]);
        $this->readable = [];
        $this->writable = [];
    }

}
