<?php
namespace Moebius\Coroutine\Kernel\Compat;

use Moebius\Coroutine as Co;
use Moebius\Coroutine\{
    KernelModule,
    InternalLogicException,
    RejectedException,
    PromiseResolvedException,
    ThenableExpectedException,
    NoCoroutineContextException
};

/**
 * @internal
 *
 * Implements functionality for suspending a coroutine pending a promise
 * resolution.
 */
class React extends KernelModule {
    public static string $name = 'react';

    private $loop = null;

    /**
     * Measured to take 20 microseconds per tick on a small server. It is
     * possible to optimize performance, but for now it seems not worth it...
     */
    private function getReactActivityLevel(): int {
        $getPropVal = function(string $name, object $source=null) {
            $rp = new \ReflectionProperty($source ?? $this->loop, $name);
            $rp->setAccessible(true);
            return $rp->getValue($source ?? $this->loop);
        };

        $level = 0;

        $ftq = $getPropVal('futureTickQueue');
        if (!$ftq->isEmpty()) {
            ++$level;
        }

        switch (get_class($this->loop)) {
            case \React\EventLoop\ExtEvLoop::class:
            case \React\EventLoop\StreamSelectLoop::class:
            case \React\EventLoop\ExtUvLoop::class:
                $level += count($getPropVal('timers', $getPropVal('timers')));
                $level += $getPropVal('signals')->isEmpty() ? 0 : 1;
                $level += count($getPropVal('readStreams'));
                $level += count($getPropVal('writeStreams'));
                break;
            case \React\EventLoop\ExtEventLoop::class:
            case \React\EventLoop\ExtLibevLoop::class:
            case \React\EventLoop\ExtLibeventLoop::class:
                $level += $getPropVal('readEvents') ? 1 : 0;
                $level += $getPropVal('writeEvents') ? 1 : 0;
                $level += $getPropVal('timerEvents')->count();
                $level += $getPropVal('signals')->isEmpty() ? 0 : 1;
                break;
        }

        return $level;
    }

    private function tick(): void {
        self::$moduleActivity[self::$name] = $activityLevel = $this->getReactActivityLevel();
        if ($activityLevel > 0) {
            $this->loop->futureTick(function() {
                $this->loop->stop();
            });
            $this->loop->run();
            self::$moduleActivity[self::$name] = $this->getReactActivityLevel();
        }
    }

    public function start(): void {
        $this->logDebug("Start");
        $this->loop = \React\EventLoop\Loop::get();
        self::$hookTick[self::$name] = $this->tick(...);
        $this->active = true;
        self::$moduleActivity[self::$name] = 0;
    }

    public function stop(): void {
        $this->logDebug("Stop");
        unset(self::$hookTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
        $this->active = false;
    }
}
