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
class Revolt extends KernelModule {
    public static string $name = 'revolt';

    private $loop = null;

    /**
     * Measured to take 20 microseconds per tick on a small server. It is
     * possible to optimize performance, but for now it seems not worth it...
     */
    private function getRevoltActivityLevel(): int {

        $loop = $this->loop;

        $getPropVal = function(string $name, object $source=null) use (&$loop) {
            $rp = new \ReflectionProperty($source ?? $this->loop, $name);
            $rp->setAccessible(true);
            return $rp->getValue($source ?? $this->loop);
        };

        /**
         * TracingDriver wraps the underlying driver. It is possible to wrap
         * the wrapper, so this is a loop to unwrap.
         */
        while ($loop instanceof \Revolt\EventLoop\Driver\TracingDriver) {
            $loop = $getPropVal('driver');
        }

        $level = 0;

        switch (get_class($loop)) {
            case \Revolt\EventLoop\Driver\EvDriver::class:
                $level += count($getPropVal('events'));
                $level += count($getPropVal('signals'));
                $rp = new \ReflectionProperty(\Revolt\EventLoop\Internal\AbstractDriver::class, 'callbacks');
                $rp->setAccessible(true);
                $level += count($rp->getValue($loop));
                break;
            case \Revolt\EventLoop\Driver\EventDriver::class:
                $level += count($getPropVal('events'));
                $level += count($getPropVal('signals'));
                $rp = new \ReflectionProperty(\Revolt\EventLoop\Internal\AbstractDriver::class, 'callbacks');
                $rp->setAccessible(true);
                $level += count($rp->getValue($loop));
                break;
            case \Revolt\EventLoop\Driver\StreamSelectDriver::class:
                $level += count($getPropVal('readStreams'));
                $level += count($getPropVal('writeStreams'));
                $level += $getPropVal('timerQueue')->peek() ? 1 : 0;
                $level += count($getPropVal('signalCallbacks'));
                $rp = new \ReflectionProperty(\Revolt\EventLoop\Internal\AbstractDriver::class, 'callbacks');
                $rp->setAccessible(true);
                $level += count($rp->getValue($loop));
                break;
            case \Revolt\EventLoop\Driver\UvDriver::class:
                $level += count($getPropVal('events'));
                $level += count($getPropVal('callbacks'));
                break;
        }

        return $level;
    }

    private function tick(): void {
        static $running = 0;
        self::$moduleActivity[self::$name] = $activityLevel = $this->getRevoltActivityLevel();
        if ($activityLevel > 0) {
            if ($running === 0) {
                ++$running;
                $this->loop->defer(function() {
                    $this->loop->stop();
                });
                $this->loop->run();
                --$running;
                self::$moduleActivity[self::$name] = $this->getRevoltActivityLevel();
            } else {
                echo "revolt already running\n";
            }
        }
    }

    public function start(): void {
        $this->logDebug("Start");
        $this->loop = \Revolt\EventLoop::getDriver();
        $this->loop->setErrorHandler(self::logException(...));
        self::$hookTick[self::$name] = $this->tick(...);
        self::$moduleActivity[self::$name] = 0;
    }

    public function stop(): void {
        $this->logDebug("Stop");
        $this->loop->setErrorHandler(null);
        unset(self::$hookTick[self::$name]);
        unset(self::$moduleActivity[self::$name]);
    }
}
