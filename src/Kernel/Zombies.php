<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Promise;
use Moebius\Coroutine;
use Moebius\Coroutine\{
    KernelModule,
    InternalLogicException,
    RejectedException,
    PromiseResolvedException,
    ThenableExpectedException,
    NoCoroutineContextException
};
use WeakMap;

/**
 * @internal
 *
 * Implements functionality for suspending a coroutine until there are no
 * other living coroutines. While zombified, coroutines does not contribute
 * to the loop activity count.
 */
class Zombies extends KernelModule {
    public static string $name = 'core.zombies';

    private array $active = [];

    public function bury(Coroutine $co): void {
        assert(!isset($this->active[$co->id]), "Coroutine is already a zombie");
        self::$debug && $this->log("Burying coroutine {id}", ['id' => $co->id]);
        self::$coroutines->deactivate($co);
        $this->active[$co->id] = $co;
        if (!isset(self::$hookAfterTick[self::$name])) {
            self::$hookAfterTick[self::$name] = $this->reviveAll(...);
        }
    }

    public function revive(Coroutine $co): void {
        assert(isset($this->active[$co->id]), "Coroutine is not zombified");
        self::$debug && $this->log("Reviving coroutine {id}", ['id' => $co->id]);
        unset($this->active[$co->id]);
        self::$coroutines->activate($co);
    }

    private function reviveAll(): void {
        unset(self::$hookAfterTick[self::$name]);
        foreach ($this->active as $id => $co) {
            self::$coroutines->activate($co);
        }
        $this->active = [];
    }

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

    public function start(): void {
        $this->log("Start");
    }

    public function stop(): void {
        $this->log("Stop");
    }

}
