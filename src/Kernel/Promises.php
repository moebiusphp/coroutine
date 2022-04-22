<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Promise;
use Moebius\Coroutine\{
    KernelModule,
    InternalLogicException,
    RejectedException,
    PromiseResolvedException,
    ThenableExpectedException,
    NoCoroutineContextException
};
use SplMinHeap;

/**
 * @internal
 *
 * Implements functionality for suspending a coroutine pending a promise
 * resolution.
 */
class Promises extends KernelModule {
    public static string $name = 'core.promises';

    private bool $active = false;

    /**
     * Await a promise-like object and suspend the current coroutine
     * while waiting.
     *
     * @param object $thenable  Coroutine or promise-like object
     * @return mixed
     * @throws \Throwable
     */
    public function awaitThenable(object $thenable): mixed {
        if (!Promise::isThenable($thenable)) {
            throw new ThenableExpectedException("Can only await coroutines and promise-like objects");
        }
        $state = 0;
        $result = null;

        $co = self::getCurrent();

        $thenable->then(function($value) use (&$state, &$result, $co) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 1;
            $result = $value;
            if ($co) {
                --self::$moduleActivity[self::$name];
                self::$coroutines[$co->id] = $co;
            }
        }, function($reason) use (&$state, &$result, $co) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 2;
            $result = $reason;
            if ($co) {
                --self::$moduleActivity[self::$name];
                self::$coroutines[$co->id] = $co;
            }
        });

        if ($co) {
            ++self::$moduleActivity[self::$name];
            self::suspend();
        } else {
            self::runLoop(function() use (&$state) {
                return $state === 0;
            });
        }
        if ($state === 1) {
            return $result;
        } elseif ($state === 2) {
            if (!($result instanceof \Throwable)) {
                $result = new RejectedException($result);
            }
            throw $result;
        } else {
            throw new InternalLogicException("Loop stopped without promise being resolved");
        }
    }

    public function start(): void {
        self::$moduleActivity[self::$name] = 0;
        $this->active = true;
    }

    public function stop(): void {
        unset(self::$moduleActivity[self::$name]);
        $this->active = false;
    }
}
