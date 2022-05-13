<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Coroutine;
use Moebius\Coroutine\{
    KernelModule, InternalLogicException
};
use WeakMap;

/**
 * @internal
 *
 * Functionality for running coroutines.
 */
class Coroutines extends KernelModule {
    public static string $name = 'core.coroutines';

    private ?Coroutine $current = null;
    private array $active = [];
    private ?WeakMap $added=null;

    public function getCurrentCoroutine(): ?Coroutine {
        return $this->current;
    }

    public function isActive(Coroutine $co): bool {
        assert(isset($this->added[$co]), "isActive: Coroutine is not added to kernel");
        return isset($this->active[$co->id]);
    }

    public function activate(Coroutine $co): void {
        assert(!$co->fiber->isTerminated(), "Terminated coroutine can't be activated");
        assert(isset($this->added[$co]), "activate: Coroutine is not added to kernel");
        if (isset($this->active[$co->id])) {
            throw new InternalLogicException("Coroutine is already activated");
        }
        $this->active[$co->id] = $co;
        ++self::$moduleActivity[self::$name];
    }

    public function deactivate(Coroutine $co): void {
        assert(isset($this->added[$co]), "Coroutine is not added to kernel");
        if (!isset($this->active[$co->id])) {
            throw new InternalLogicException("Coroutine is already deactivated");
        }
        unset($this->active[$co->id]);
        --self::$moduleActivity[self::$name];
    }

    /**
     * Whenever coroutines are terminated this function must be called.
     */
    public function terminated(Coroutine $co): void {
        if (!isset($this->active[$co->id])) {
            throw new InternalLogicException("Coroutine ".$co->id." is not active");
        }
        if (!$co->fiber->isTerminated()) {
            $this->logWarning("Coroutine {id} is being terminated without having completed");
        }
        self::$debug && $this->logDebug("Coroutine {id} has been terminated", ['id' => $co->id]);
        unset($this->active[$co->id]);
        --self::$moduleActivity[self::$name];
        unset($this->added[$co]);
    }

    public function add(Coroutine $co): void {
        assert(!isset($this->added[$co]), "Coroutine was already added to kernel");
        self::$debug && $this->logDebug("Added coroutine {id}", ['id' => $co->id]);
        $this->added[$co] = true;
        $this->active[$co->id] = $co;
        ++self::$moduleActivity[self::$name];

        $current = $this->current;
        $microtasks = self::$microtasks;
        self::$microtasks = [];
        $this->current = $co;
        $co->stepSignal();
        self::runMicrotasks();
        self::$microtasks = $microtasks;
        $this->current = $current;
    }

    private function tick(): void {
        foreach ($this->active as $co) {
            $this->current = $co;
            $co->stepSignal();
            $this->runMicrotasks();
        }
        $this->current = null;
        if ($this->active !== []) {
            self::setMaxDelay(0);
        }
    }

    public function start(): void {
        $this->added = new WeakMap();
        self::$moduleActivity[self::$name] = 0;
        self::$hookTick[self::$name] = $this->tick(...);
        $this->logDebug("Start");
    }

    public function stop(): void {
        unset(self::$moduleActivity[self::$name]);
        unset(self::$hookTick[self::$name]);
        $this->logDebug("Stop");
    }

}
