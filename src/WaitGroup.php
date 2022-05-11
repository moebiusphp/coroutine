<?php
namespace Moebius\Coroutine;

use Moebius\Coroutine as Co;
use Moebius\Promise;

final class WaitGroup extends Kernel {

    private int $value = 0;
    private array $waiting = [];
    private Promise $promise;

    public function __construct() {
        $this->promise = new Promise();
    }

    /**
     * Add a number to the countdown. The total number
     * added indicates how many times the WaitGroup::done()
     * method has to be called to release waiting coroutines.
     */
    public function add(int $delta=1): void {
        if (!$this->promise->isPending()) {
            throw new LogicException("WaitGroup has been resolved");
        }
        if ($delta < 1) {
            throw new LogicException("Expecting positive integer number");
        }
        $this->value += $delta;
        $this->check();
    }

    /**
     * Reduce the WaitGroup counter by one.
     */
    public function done(): void {
        if (!$this->promise->isPending()) {
            // Ignoring if too many calls have been done, the WaitGroup
            // may be used to wait for only the first few results.
            return;
        }
        $this->value--;
        $this->check();
    }

    /**
     * Throw an exception to any coroutines that are waiting for this
     * WaitGroup to become resolved.
     */
    public function throw(\Throwable $e) {
        if (!$this->promise->isPending()) {
            throw new LogicException("WaitGroup has already been resolved");
        }
        $this->promise->reject($e);
    }

    /**
     * Block until the WaitGroup counter goes down to zero.
     */
    public function wait(): void {
        Co::await($this->promise);
    }

    /**
     * Check if the WaitGroup is resolved
     */
    private function check(): void {
        if ($this->value <= 0) {
            $this->promise->resolve(null);
        }
    }
}
