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
        $this->logDebug("Burying coroutine {id}", ['id' => $co->id]);
        self::$coroutines->deactivate($co);
        $this->active[$co->id] = $co;

    }

    public function unbury(Coroutine $co): void {
        assert(isset($this->active[$co->id]), "Coroutine is not buried");
        $this->logDebug("Unburying coroutine {id}", ['id' => $co->id]);
        unset($this->active[$co->id]);
        self::$coroutines->activate($co);
    }

    public function revive(Coroutine $co): void {
        assert(isset($this->active[$co->id]), "Coroutine is not zombified");
        $this->logDebug("Reviving coroutine {id}", ['id' => $co->id]);
        unset($this->active[$co->id]);
        self::$coroutines->activate($co);
    }

    /**
     * If there is no activity going on after the loop,
     * we'll revive all zombie coroutines.
     */
    private function onAfterTick() {
        if ($this->active === []) {
            return;
        }
        if (self::getActivityLevel() > 0) {
            return;
        }
        $this->reviveAll();
    }

    private function reviveAll(): void {
        foreach ($this->active as $id => $co) {
            $this->revive($co);
        }
        $this->active = [];
    }

    public function start(): void {
        $this->logDebug("Start");
        self::$hookAfterTick[self::$name] = $this->onAfterTick(...);
    }

    public function stop(): void {
        $this->logDebug("Stop");
        unset(self::$hookAfterTick[self::$name]);
    }

}
