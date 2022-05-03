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

        if (self::$debug) {
            if ($co) {
                $this->log("Coroutine {id} awaiting promise", ['id' => $co->id]);
            } else {
                $this->log("Global routine awaiting promise");
            }
        }


        $thenable->then(function($value) use (&$state, &$result, $co) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 1;
            $result = $value;
            if ($co) {
                self::$debug && $this->log("Coroutine {id} has promise fulfilled", ['id' => $co->id]);
                --self::$moduleActivity[self::$name];
                self::$coroutines[$co->id] = $co;
            } else {
                self::$debug && $this->log("Global routine has promise fulfilled");
            }
        }, function($reason) use (&$state, &$result, $co) {
            if ($state !== 0) {
                throw new PromiseResolvedException("Promise invoked two listeners");
            }
            $state = 2;
            $result = $reason;
            if ($co) {
                self::$debug && $this->log("Coroutine {id} has promise rejected", ['id' => $co->id]);
                --self::$moduleActivity[self::$name];
                self::$coroutines[$co->id] = $co;
            } else {
                self::$debug && $this->log("Global routine {id} has promise rejected");
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

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

    public function start(): void {
        $this->log("Start");
        self::$moduleActivity[self::$name] = 0;
        $this->active = true;
    }

    public function stop(): void {
        $this->log("Stop");
        unset(self::$moduleActivity[self::$name]);
        $this->active = false;
    }
}
