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

        if ($co) {
            $this->logDebug("Coroutine {id} awaiting promise", ['id' => $co->id]);
        } else {
            $this->logDebug("Global routine awaiting promise");
        }

        /**
         * Watch the result from this promise and store it in the $state and $result variables
         */
        $coroutineDeactivated = false;
        $thenable->then(function($value) use (&$state, &$result, $co, &$coroutineDeactivated) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 1;
            $result = $value;
            if ($co) {
                $this->logDebug("Coroutine {id} has promise fulfilled", ['id' => $co->id]);
                if ($coroutineDeactivated) {
                    --self::$moduleActivity[self::$name];
                    self::$coroutines->activate($co);
                }
            } else {
                $this->logDebug("Global routine has promise fulfilled");
            }
        }, function($reason) use (&$state, &$result, $co, &$coroutineDeactivated) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 2;
            $result = $reason;
            if ($co) {
                $this->logDebug("Coroutine {id} has promise rejected", ['id' => $co->id]);
                if ($coroutineDeactivated) {
                    --self::$moduleActivity[self::$name];
                    self::$coroutines->activate($co);
                }
            } else {
                $this->logDebug("Global routine {id} has promise rejected");
            }
        });

        if ($state === 0) {
            // promise may already have been resolved
            if ($co) {
                ++self::$moduleActivity[self::$name];
                $coroutineDeactivated = true;
                self::$coroutines->deactivate($co);
                self::suspend();
            } else {
                self::runLoop(function() use (&$state) {
                    return $state === 0;
                });
            }
        }
        if ($state === 1) {
            return $result;
        } elseif ($state === 2) {
            if (!($result instanceof \Throwable)) {
                $result = new RejectedException($result);
            }
            throw $result;
        } else {
            throw new InternalLogicException("Promise not resolved but coroutine or loop ended");
        }
    }

    public function start(): void {
        $this->logDebug("Start");
        self::$moduleActivity[self::$name] = 0;
        $this->active = true;
    }

    public function stop(): void {
        $this->logDebug("Stop");
        unset(self::$moduleActivity[self::$name]);
        $this->active = false;
    }
}
