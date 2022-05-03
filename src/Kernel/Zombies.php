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
    public static string $name = 'core.coroutines.zombies';

    private array $zombies = [];

    public function bury(Coroutine $co): void {
        assert(!isset($this->zombies[$co->id]), "Coroutine is already a zombie");
        self::$debug && $this->log("Burying coroutine {id}", ['id' => $co->id]);
        self::$modules['core.coroutines']->deactivate($co);
        $this->zombies[$co->id] = $co;
    }

    public function revive(Coroutine $co): void {
        assert(isset($this->zombies[$co->id]), "Coroutine is not zombified");
        self::$debug && $this->log("Reviving coroutine {id}", ['id' => $co->id]);
        unset($this->zombies[$co->id]);
        self::$modules['core.coroutines']->activate($co);
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
