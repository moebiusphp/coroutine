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
 * Functionality for running coroutines.
 */
class Coroutines extends KernelModule {
    public static string $name = 'core.coroutines';

    public ?Coroutine $current = null;
    private array $coroutines = [];
    private ?WeakMap $added=null;

    /**
     * Microtasks that are run after a coroutine is suspended
     */
    private array $microTasks = [];

    private function log(string $message, array $vars=[]): void {
        self::writeLog('['.self::$name.'] '.$message, $vars);
    }

    public function add(Coroutine $co): void {
        assert(!isset($this->added[$co]), "Coroutine was already added to kernel");
        self::$debug && $this->log("Added coroutine {id}", ['id' => $co->id]);
        $this->added[$co] = true;
        $this->coroutines[$co->id] = $co;
        ++self::$moduleActivity[self::$name];
    }

    public function isActive(Coroutine $co): bool {
        assert(isset($this->added[$co]), "Coroutine is not added to kernel");
        return isset($this->coroutines[$co->id]);
    }

    public function activate(Coroutine $co): void {
        assert(isset($this->added[$co]), "Coroutine is not added to kernel");
        self::$debug && $this->log("Activated coroutine {id}", ['id' => $co->id]);
        if (isset($this->coroutines[$co->id])) {
            throw new InternalLogicException("Coroutine is already activated");
        }
        $this->coroutines[$co->id] = $co;
        ++self::$moduleActivity[self::$name];
    }

    public function deactivate(Coroutine $co): void {
        assert(isset($this->added[$co]), "Coroutine is not added to kernel");
        self::$debug && $this->log("Deactivated coroutine {id}", ['id' => $co->id]);
        if (!isset($this->coroutines[$co->id])) {
            throw new InternalLogicException("Coroutine is already deactivated");
        }
        unset($this->coroutines[$co->id]);
        --self::$moduleActivity[self::$name];
    }

    /**
     * Whenever coroutines are terminated this function must be called.
     */
    public function terminated(Coroutine $co): void {
        self::$debug && $this->log("Coroutine {id} has been terminated", ['id' => $co->id]);
        if (!isset($this->coroutines[$co->id])) {
            throw new InternalLogicException("Coroutine ".$co->id." is not active");
        }
        unset($this->coroutines[$co->id]);
        --self::$moduleActivity[self::$name];
        unset($this->added[$co]);
    }

    private function tick(): void {
        foreach ($this->coroutines as $co) {
            $this->current = $co;
            $co->stepSignal();
            foreach ($this->microTasks as $k => $task) {
                try {
                    $this->log("Running microtask {key} for coroutine {id}", ['key' => $k, 'id' => $co->id]);
                    $task();
                } catch (\Throwable $e) {
                    $this->log("Coroutine {id} micro-task threw {class}: {message} in {file}:{line}", [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
            }
            $this->microTasks = [];
        }
        $this->current = null;
    }

    public function start(): void {
        $this->added = new WeakMap();
        self::$moduleActivity[self::$name] = 0;
        self::$hookTick[self::$name] = $this->tick(...);
        $this->log("Start");
    }

    public function stop(): void {
        unset(self::$moduleActivity[self::$name]);
        unset(self::$hookTick[self::$name]);
        $this->log("Stop");
    }

}
