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

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

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

        self::$debug && $this->log("Watcher {watcherId} on resource {id} for event type {eventType} with timeout {seconds} seconds", ['watcherId' => $handler->id, 'id' => get_resource_id($resource), 'eventType' => $eventType, 'seconds' => $seconds ?? 'NULL']);

        if ($seconds !== null) {
            self::$modules['core.timers']->schedule($this->cancel(...), $seconds, $handler->id);
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
    public function wait($resource, int $eventType, float $seconds = null): bool {
        if ($co = self::getCurrent()) {
            self::$modules['core.coroutines']->deactivate($co);
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

    public function cancel(int $id): void {
        if (!isset($this->handlers[$id])) {
            return;
        }
        self::$debug && $this->log("Cancelling IO watcher {id}", ['id' => $id]);
        if ($this->handlers[$id]->value instanceof Coroutine) {
            self::$modules['core.coroutines']->activate($this->handlers[$id]->value);
        }
        $this->handlers[$id]->cancelled();
        unset($this->handlers[$id]);
        unset($this->resources[$id]);
        --self::$moduleActivity[self::$name];
    }

    private function onBeforeTick() {
        if ($this->handlers === []) {
            return;
        }

        /**
         * What is the optimal sleep time for the stream_select() call?
         */
        if (self::$moduleActivity['core.coroutines'] !== 0) {
            // there are coroutines that are not blocked, so no sleeping
            $sleepTime = 0;
        } elseif (self::$moduleActivity['core.timers'] !== 0) {
            // there are pending timers
            $sleepTime = (self::$modules['core.timers']->getNextEventTime() - self::getRealTime()) - 0.001;
            if ($sleepTime < 0) {
                $sleepTime = 0;
            }
        } else {
            // we are only waiting for IO, so we can sleep long long time
            $sleepTime = 0.1;
        }

        $this->performStreamSelect($sleepTime);
    }

    private function performStreamSelect(float $sleepTime): void {
        $readableStreams = [];
        $writableStreams = [];
        $exceptionStreams = [];

        foreach ($this->handlers as $handler) {
            $resource = $this->resources[$handler->id];
            if (!is_resource($resource)) {
                $this->cancel($handler->id);
                continue;
            }
            $resourceId = get_resource_id($resource);
            if (0 !== ($handler->extra & self::READABLE)) {
                $readableStreams[$resourceId] = $resource;
            }
            if (0 !== ($handler->extra & self::WRITABLE)) {
                $writableStreams[$resourceId] = $resource;
            }
            if (0 !== ($handler->extra & self::EXCEPTION)) {
                $exceptionStreams[$resourceId] = $resource;
            }
        }

//$this->log("Stream select readable={rc} writable={wc} exceptions={ec}", ['rc' => count($readableStreams), 'wc' => count($writableStreams), 'ec' => count($exceptionStreams) ]);

        $count = @stream_select($readableStreams, $writableStreams, $exceptionStreams, floor($sleepTime), ($sleepTime - floor($sleepTime)) * 1000000);
        if ($count === false) {
            $this->log("stream_select() failed, possibly because of a signal");
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
        $count2 = @stream_select($readableStreams, $writableStreams, $exceptionStreams, 0, 0);
        if ($count2 === false || $count2 === 0) {
            return;
        }

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
                self::$debug && $this->log("Activating watcher {id}", ['id' => $handler->id]);
                unset($this->handlers[$handler->id]);
                unset($this->resources[$handler->id]);
                if ($handler->value instanceof Coroutine) {
                    self::$modules['core.coroutines']->activate($handler->value);
                } else {
                    self::invoke($handler->value);
                }
                --self::$moduleActivity[self::$name];
                $handler->activated();
            }
        }
    }

    public function start(): void {
        $this->log("Start");
        self::$moduleActivity[self::$name] = 0;
        self::$hookBeforeTick[self::$name] = $this->onBeforeTick(...);
        $this->coroutines = [];
        $this->readable = [];
        $this->writable = [];
    }

    public function stop(): void {
        $this->log("Stop");
        unset(self::$moduleActivity[self::$name]);
        unset(self::$hookBeforeTick[self::$name]);
        $this->coroutines = [];
        $this->readable = [];
        $this->writable = [];
    }

}
